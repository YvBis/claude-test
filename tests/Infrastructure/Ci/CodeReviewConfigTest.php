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
 * verdict, both provider steps with their model, endpoint, key and timeouts, the
 * fallback condition, the draft skip, and the concurrency policy that decides
 * when a missing verdict is expected (a newer push cancels the run) and when it
 * is a real loss (the job or a provider step timed out).
 *
 * The workflow is parsed as YAML rather than pattern-matched, so reformatting or
 * key reordering cannot break the guard, and a missing key fails as a clean
 * assertion instead of a PHP warning plus null. Provider steps are looked up by
 * name rather than by position, so adding an unrelated step does not break the
 * guard on a count.
 *
 * The detector step is pinned as well, and asymmetrically: it must run with
 * `if: always()` so it is not blind on the failure path, and it must not carry
 * `continue-on-error`, so a silent edit cannot turn the detector itself into a
 * no-op. What it does is deliberately advisory - a green job that published no
 * verdict is recorded, never failed - because the required status check is where
 * a missing verdict must bite, and a hard failure would block a merge forever on
 * a wording change in a third-party action (task 5.19A).
 *
 * Why the primary model is pinned as a literal instead of a "free tier" pattern:
 * OpenRouter rotates between free models *inside the provider* (AssumptionLog.md:680),
 * so the value in this file stays `openrouter/free`. Accepting any `:free` suffix
 * would let a silent swap to an unreviewed model pass - exactly what this pin
 * exists to prevent. The primary step must also not carry an explicit
 * `llm_api_url`: the action default is the OpenRouter endpoint, and an explicit
 * URL - even one equal to that default - makes the endpoint part of this file and
 * therefore something this guard has to pin. The `llm_api_key` expressions are
 * pinned for the same reason: a silent switch to a paid key must fail loudly.
 *
 * Probed regressions (the workflow was edited transiently and restored
 * byte-for-byte; each edit failed the guard for the right reason): a paid literal
 * as the primary model, a foreign endpoint for the fallback, the job budget
 * raised to 45, the fallback step renamed, and an explicit `llm_api_url` added to
 * the primary step.
 */
final class CodeReviewConfigTest extends TestCase
{
    private const string PRIMARY_STEP = 'Run AI Code Review (OpenRouter free)';
    private const string FALLBACK_STEP = 'Run AI Code Review (NVIDIA NIM fallback)';
    private const string VERDICT_STEP = 'Verify a verdict was published for this head';

    public function testReviewJobKeepsItsVerdictContract(): void
    {
        $job = $this->reviewJob();

        self::assertArrayHasKey('timeout-minutes', $job);
        self::assertSame(
            30,
            $job['timeout-minutes'],
            'The job budget is pinned: a drifted budget silently loses the verdict when the free-tier LLM is slow.',
        );
        self::assertArrayHasKey('if', $job);
        self::assertSame(
            'github.event.pull_request.draft == false',
            $job['if'],
            'Draft pull requests must stay unreviewed.',
        );
    }

    public function testBothProvidersAreWiredWithTimeoutsThatFitTheJobBudget(): void
    {
        $job = $this->reviewJob();
        $steps = $this->reviewSteps();
        self::assertGreaterThanOrEqual(
            2,
            \count($steps),
            'The job must keep at least the two review providers; steps are looked up by name below, not by position.',
        );

        $primary = $this->stepByName(self::PRIMARY_STEP);
        $fallback = $this->stepByName(self::FALLBACK_STEP);

        self::assertArrayHasKey('uses', $primary);
        self::assertSame('aryanbrite/openrabbit@v0.8.7', $primary['uses']);
        self::assertArrayHasKey('id', $primary);
        self::assertSame(
            'openrouter',
            $primary['id'],
            'The fallback references this step id: renaming it silently disables the fallback.',
        );
        self::assertArrayHasKey('continue-on-error', $primary);
        self::assertTrue(
            $primary['continue-on-error'],
            'The primary step must not abort the job: the fallback is triggered by its failure outcome.',
        );

        $primaryWith = $this->withBlock($primary);
        self::assertSame('openrouter', $primaryWith['llm_provider']);
        self::assertSame(
            'openrouter/free',
            $primaryWith['llm_model'],
            'Pin the free-pool identifier: OpenRouter rotates models inside the provider, and any other id means an unreviewed (possibly paid) escalation.',
        );
        self::assertArrayNotHasKey(
            'llm_api_url',
            $primaryWith,
            'The primary relies on the action default endpoint; an explicit URL - even one equal to that default - must be pinned here instead.',
        );
        self::assertSame('${{ secrets.LLM_API_KEY }}', $primaryWith['llm_api_key']);
        self::assertSame('both', $primaryWith['review_mode']);

        self::assertArrayHasKey('uses', $fallback);
        self::assertSame('aryanbrite/openrabbit@v0.8.7', $fallback['uses']);
        self::assertArrayHasKey('if', $fallback);
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

        $fallbackWith = $this->withBlock($fallback);
        self::assertSame(
            'groq',
            $fallbackWith['llm_provider'],
            'OpenRabbit has no native NVIDIA provider: the Groq client is only the OpenAI-compatible transport.',
        );
        self::assertArrayHasKey(
            'llm_api_url',
            $fallbackWith,
            'The fallback is the only step that names an endpoint explicitly: keep that asymmetry deliberate.',
        );
        self::assertSame(
            'https://integrate.api.nvidia.com/v1',
            $fallbackWith['llm_api_url'],
            'The fallback endpoint is pinned: pointing it elsewhere silently switches providers.',
        );
        self::assertSame('openai/gpt-oss-20b', $fallbackWith['llm_model']);
        self::assertSame('${{ secrets.NVIDIA_API_KEY }}', $fallbackWith['llm_api_key']);
        self::assertSame('both', $fallbackWith['review_mode']);

        self::assertArrayHasKey('timeout-minutes', $primary);
        self::assertArrayHasKey('timeout-minutes', $fallback);
        self::assertSame(12, $primary['timeout-minutes']);
        self::assertSame(15, $fallback['timeout-minutes']);
        self::assertLessThan(
            $job['timeout-minutes'],
            $primary['timeout-minutes'] + $fallback['timeout-minutes'],
            'Per-provider timeouts must leave room inside the job bound for checkout, otherwise a slow run loses the fallback.',
        );
    }

    public function testVerdictPublicationIsCheckedOnTheFailurePathToo(): void
    {
        $step = $this->stepByName(self::VERDICT_STEP);

        self::assertArrayHasKey('if', $step);
        self::assertSame(
            'always()',
            $step['if'],
            'The detector must also run after a failed provider: with if: success() it would be blind '
            .'exactly on the path that loses the verdict.',
        );
        // Deliberately no `continue-on-error`: the advisory guarantee lives in the
        // script's own EXIT trap, which turns any non-zero status into a warning.
        // The step is therefore a real step - a silent `if: false`, a rename or a
        // deleted script shows up here - while still being unable to fail the job.
        self::assertArrayNotHasKey(
            'continue-on-error',
            $step,
            'The detector is wired in, not merely tolerated: tolerating its failure would let the check be disabled without the guard noticing.',
        );
        self::assertArrayHasKey('run', $step);
        self::assertIsString($step['run']);
        self::assertStringContainsString(
            'scripts/verify-review-verdict.sh',
            $step['run'],
            'The detector stays in the repository script, where its advisory contract is reviewable in one place.',
        );
        $script = \dirname(__DIR__, 3).'/scripts/verify-review-verdict.sh';
        self::assertFileExists(
            $script,
            'The step runs this script: a rename or deletion must fail the guard instead of silently skipping the check.',
        );

        // The script's own EXIT trap makes a runtime failure advisory, but a
        // syntax error never reaches it: bash refuses to run the file, the step
        // fails, and under branch protection that blocks every merge until
        // somebody notices. Parsing it here moves that failure into `ci:all`.
        // `bash` is always present in the test container (all commands run in Docker).
        $output = [];
        $status = 0;
        \exec('bash -n '.\escapeshellarg($script).' 2>&1', $output, $status);
        self::assertSame(
            0,
            $status,
            "The detector script must parse.\n".\implode("\n", $output),
        );
    }

    public function testWorkflowKeepsLeastPrivilegePermissions(): void
    {
        $permissions = $this->workflow()['permissions'];

        self::assertSame(
            ['contents' => 'read', 'pull-requests' => 'write'],
            $permissions,
            'The review reads the code and writes its comment - nothing more. A silent widening '
            .'(e.g. id-token: write) or a narrowing that would break commenting must fail here. Note that the '
            .'verdict detector writes through the issues endpoint (POST /issues/{n}/comments), which the '
            .'`pull-requests: write` scope is expected to cover - the empirical check is the workflow run itself.',
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

    /**
     * @return array<string, mixed>
     */
    private function stepByName(string $name): array
    {
        foreach ($this->reviewSteps() as $step) {
            if (($step['name'] ?? null) === $name) {
                return $step;
            }
        }

        self::fail(\sprintf('Step "%s" was not found in the review job.', $name));
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private function withBlock(array $step): array
    {
        self::assertArrayHasKey('with', $step);
        self::assertIsArray($step['with']);

        /** @var array<string, mixed> $with */
        $with = $step['with'];

        foreach (['llm_provider', 'llm_model', 'llm_api_key', 'review_mode'] as $key) {
            self::assertArrayHasKey($key, $with, \sprintf('The review step must declare "%s".', $key));
        }

        return $with;
    }
}
