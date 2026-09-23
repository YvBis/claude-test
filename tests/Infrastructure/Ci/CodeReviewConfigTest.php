<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the wiring of the AI code review workflow.
 *
 * The review itself is an external action, so these tests protect the contract
 * that a silent edit could break: the triggers that decide which heads get a
 * verdict, both provider steps with their timeouts and the fallback condition,
 * the draft skip, and the concurrency policy that decides when a missing verdict
 * is expected (a newer push cancels the run) and when it is a real loss (the job
 * or a provider step timed out).
 *
 * The workflow is parsed as YAML rather than pattern-matched, so reformatting or
 * key reordering cannot break the guard, and a missing key fails loudly.
 */
final class CodeReviewConfigTest extends TestCase
{
    public function testReviewJobKeepsItsVerdictContract(): void
    {
        $job = $this->reviewJob();

        self::assertIsInt($job['timeout-minutes'], 'The review job must bound its runtime.');
        self::assertGreaterThanOrEqual(
            30,
            $job['timeout-minutes'],
            'The free-tier LLM needs headroom: a job killed by the timeout produces no verdict at all.',
        );
        self::assertSame(
            'github.event.pull_request.draft == false',
            $job['if'],
            'Draft pull requests must stay unreviewed.',
        );
    }

    public function testBothProvidersAreWiredWithTimeoutsThatFitTheJobBudget(): void
    {
        $steps = $this->reviewSteps();
        self::assertCount(3, $steps, 'The job runs checkout plus two review providers.');

        $primary = $steps[1];
        $fallback = $steps[2];

        self::assertSame('aryanbrite/openrabbit@v0.8.7', $primary['uses']);
        self::assertSame('openrouter', $primary['with']['llm_provider']);
        self::assertSame(
            'openrouter',
            $primary['id'],
            'The fallback references this step id: renaming it silently disables the fallback.',
        );
        self::assertTrue(
            $primary['continue-on-error'],
            'The primary step must not abort the job: the fallback is triggered by its failure outcome.',
        );

        self::assertSame('aryanbrite/openrabbit@v0.8.7', $fallback['uses']);
        self::assertSame('groq', $fallback['with']['llm_provider']);
        self::assertSame(
            "steps.openrouter.outcome == 'failure'",
            $fallback['if'],
            'The fallback must run only when the primary failed — a cancelled run is "no verdict yet", not a failure.',
        );
        self::assertArrayNotHasKey(
            'continue-on-error',
            $fallback,
            'Tolerating a fallback failure would let both providers fail into a green job with no verdict at all.',
        );

        self::assertIsInt($primary['timeout-minutes']);
        self::assertIsInt($fallback['timeout-minutes']);
        self::assertLessThan(
            $this->reviewJob()['timeout-minutes'],
            $primary['timeout-minutes'] + $fallback['timeout-minutes'],
            'Per-provider timeouts must leave room inside the job bound for checkout, otherwise a slow run loses the fallback.',
        );
    }

    public function testTriggersCoverEveryHeadThatNeedsAVerdict(): void
    {
        $on = $this->workflow()['on'];

        self::assertSame(
            ['pull_request'],
            \array_keys($on),
            'Adding pull_request_target would run the review with secrets on fork pull requests — a security regression.',
        );
        self::assertSame(
            ['types'],
            \array_keys($on['pull_request']),
            'Adding branches/paths filters to the trigger would silently skip whole classes of pull requests.',
        );
        self::assertSame(
            ['opened', 'synchronize', 'ready_for_review'],
            $on['pull_request']['types'],
            'Dropping synchronize or ready_for_review silently loses the verdict for a pushed head.',
        );
    }

    public function testNewerPushCancelsTheInFlightReview(): void
    {
        $concurrency = $this->workflow()['concurrency'];

        self::assertSame('ai-review-${{ github.ref }}', $concurrency['group']);
        self::assertTrue(
            $concurrency['cancel-in-progress'],
            'A newer push must cancel the in-flight review: a queued run keeps its trigger-time commit '
            .'and spends the daily OpenRouter free quota on an outdated head.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(): array
    {
        /** @var array<string, mixed> $workflow */
        $workflow = Yaml::parseFile(\dirname(__DIR__, 3).'/.github/workflows/code-review.yml');

        return $workflow;
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewJob(): array
    {
        /** @var array<string, mixed> $job */
        $job = $this->workflow()['jobs']['review'];

        return $job;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewSteps(): array
    {
        /** @var list<array<string, mixed>> $steps */
        $steps = $this->reviewJob()['steps'];

        return $steps;
    }
}
