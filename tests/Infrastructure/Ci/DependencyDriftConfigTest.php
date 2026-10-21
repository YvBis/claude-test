<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the version-drift cron for pins that no dependency manager can see
 * (task 5.14 PR-B for the symfony-lsp checker, review-9 for the Meilisearch
 * image).
 *
 * The checker is downloaded and the image is pulled, so Dependabot cannot see
 * either — the weekly `dependency-drift` workflow is the only drift
 * visibility. It is advisory-only by design: an upstream release cadence is
 * not a contract, so a drift must print `::warning::`, never fail, and never
 * enter the merge gate (no `needs:` edit, no ruleset edit, no new required
 * check).
 *
 * The symfony-lsp pin lives in two places — the runner default
 * (`scripts/symfony-lsp-check.sh`) and the ci.yml env (which overrides it).
 * The Meilisearch image pin also lives in two places — `docker-compose.yml`
 * (dev stack) and the ci.yml service container — and is compared by MINOR tag,
 * as ADR/0001 requires. The drift script reads the producers, never this
 * test's regex.
 *
 * Honesty label: these assertions pin the wiring (schedule exists, both
 * triggers present, the step runs the script, the script carries one EXIT trap,
 * reads both producers, and actually invokes both checks). They cannot observe
 * whether the schedule fires or what the upstream API returns — GitHub pauses
 * crons on idle repos, and the branch matrix is proven by the harness recorded
 * in PRD/review-9-meilisearch-image-drift.md, not here.
 */
final class DependencyDriftConfigTest extends TestCase
{
    public function testWorkflowDeclaresScheduleAndManualDispatch(): void
    {
        $workflow = $this->workflow();

        $on = $workflow['on'] ?? null;
        self::assertIsArray($on, 'The drift workflow must declare its triggers under "on:".');

        $schedule = $on['schedule'] ?? null;
        self::assertIsArray($schedule, 'The drift workflow must run on a schedule.');
        self::assertNotEmpty($schedule, 'The schedule must carry at least one cron entry.');
        foreach ($schedule as $entry) {
            self::assertArrayHasKey('cron', $entry, 'Each schedule entry must be a cron expression.');
        }

        self::assertArrayHasKey(
            'workflow_dispatch',
            $on,
            'The drift workflow needs a manual trigger: rerunning the two-sided proof, debugging, and recovering after 60 days of repo inactivity (GitHub pauses schedules on idle repos).',
        );
    }

    public function testDriftStepRunsTheScriptWithoutTheProofHook(): void
    {
        $steps = $this->jobSteps();
        $matches = \array_values(\array_filter(
            $steps,
            static fn (array $step): bool => 'Check version drift' === ($step['name'] ?? null),
        ));
        self::assertCount(1, $matches, 'Exactly one step must run the drift checks, found by name (5.22: never by position).');

        $run = (string) ($matches[0]['run'] ?? '');
        self::assertStringContainsString(
            'scripts/check-tool-version-drift.sh',
            $run,
            'The drift step must run the drift script.',
        );

        $raw = (string) \file_get_contents($this->projectRoot().'/.github/workflows/dependency-drift.yml');
        foreach ([
            'DRIFT_PINNED_VERSION' => 'the symfony-lsp pin',
            'DRIFT_REPO' => 'the symfony-lsp upstream repository',
            'DRIFT_IMAGE_PINNED' => 'the Meilisearch image pin',
            'DRIFT_IMAGE_UPSTREAM' => 'the Meilisearch releases repository',
        ] as $hook => $what) {
            self::assertStringNotContainsString(
                $hook,
                $raw,
                \sprintf(
                    'The workflow must never set %s anywhere (step run, step env, job env, top env): it replaces or redirects %s and would blind the check.',
                    $hook,
                    $what,
                ),
            );
        }
    }

    public function testScriptGuaranteesExitZeroAndReadsBothAnchors(): void
    {
        $script = (string) \file_get_contents($this->projectRoot().'/scripts/check-tool-version-drift.sh');

        self::assertStringContainsString(
            'trap on_exit EXIT',
            $script,
            'The script runs under `set -euo pipefail` with an always-exit-0 contract: without the EXIT trap the first failed gh/jq/grep/curl would break the advisory guarantee.',
        );
        self::assertStringContainsString(
            'SYMFONY_LSP_VERSION:-',
            $script,
            'The script must read the runner default anchor (producer, not a test regex).',
        );
        self::assertStringContainsString(
            "SYMFONY_LSP_VERSION: '",
            $script,
            'The script must read the ci.yml env anchor (the effective CI version overrides the runner default).',
        );
        self::assertStringContainsString(
            'releases/latest',
            $script,
            'The script must compare against the upstream latest release.',
        );
        self::assertSame(
            1,
            \preg_match_all('/^[[:space:]]*exit\b/m', $script),
            'The advisory contract is a guess unless the script has exactly ONE exit statement (inside on_exit): a second terminal exit would silently dead-code whatever follows it — the defect review-9 removed.',
        );
    }

    /**
     * `gh` has no ambient credential inside Actions. Without an explicit token
     * both checks fail every API call and degrade to "not checked", so the cron
     * runs forever and reports nothing — the failure mode is silence, which is
     * exactly what a guard must prevent.
     */
    public function testWorkflowProvidesTheGitHubTokenForGh(): void
    {
        $workflow = $this->workflow();
        $env = $workflow['jobs']['tool-version-drift']['env'] ?? null;
        self::assertIsArray($env, 'The drift job must declare env: without GH_TOKEN the gh API calls fail and every check degrades to "not checked".');
        self::assertSame(
            '${{ secrets.GITHUB_TOKEN }}',
            $env['GH_TOKEN'] ?? null,
            'The drift job must pass GH_TOKEN from secrets.GITHUB_TOKEN: this is how gh authenticates in Actions.',
        );
    }

    /**
     * review-9: the image pin lives in two producers and the check must read
     * both. The invocation assertions guard the bug this task removed: the
     * script used to end in terminal `exit 0` branches, so any block appended
     * after them was dead code on every path — including the success path.
     */
    public function testImageCheckReadsBothProducersAndIsActuallyInvoked(): void
    {
        $script = (string) \file_get_contents($this->projectRoot().'/scripts/check-tool-version-drift.sh');

        self::assertStringContainsString('docker-compose.yml', $script, 'The dev-stack producer must be an anchor: the image is pinned there too.');
        self::assertStringContainsString('getmeili/meilisearch', $script, 'The check must pin the actual image name, not a generic pattern.');
        self::assertStringContainsString('hub.docker.com', $script, 'An upstream release can precede its image: the published-tag probe prevents a weekly false alarm.');
        // The hooks must be READ, not merely mentioned in the header comment:
        // assert the parameter-expansion form, so deleting the code while
        // keeping the docs cannot stay green.
        self::assertStringContainsString('${DRIFT_IMAGE_PINNED:-', $script, 'The image check must actually consume its pin override hook.');
        self::assertStringContainsString('${DRIFT_IMAGE_UPSTREAM:-', $script, 'The image check must actually consume its upstream override hook.');

        self::assertSame(
            1,
            \preg_match_all('/^trap on_exit EXIT$/m', $script),
            'Exactly one EXIT trap must exist: a second trap would silently replace the advisory guarantee.',
        );
        foreach (['check_symfony_lsp', 'check_meilisearch_image'] as $function) {
            self::assertMatchesRegularExpression(
                '/^'.$function.'$/m',
                $script,
                \sprintf('%s() must be CALLED at top level, not merely defined: a definition without an invocation is the dead-code regression this task fixed.', $function),
            );
        }
    }

    public function testDriftJobStaysOutOfTheMergeGate(): void
    {
        $ci = Yaml::parseFile($this->projectRoot().'/.github/workflows/ci.yml');
        self::assertIsArray($ci);
        self::assertArrayHasKey('jobs', $ci);
        self::assertIsArray($ci['jobs']);
        self::assertArrayHasKey('ci-summary', $ci['jobs']);
        self::assertIsArray($ci['jobs']['ci-summary']);
        $needs = $ci['jobs']['ci-summary']['needs'] ?? null;
        self::assertIsArray($needs);

        self::assertNotContains(
            'tool-version-drift',
            $needs,
            'The advisory drift job must never enter ci-summary needs:: its always-exit-0 would join the merge-gate UI by accident via toJSON(needs). The rule lives in prose too, but only the test is mechanical.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function workflow(): array
    {
        $parsed = Yaml::parseFile($this->projectRoot().'/.github/workflows/dependency-drift.yml');
        self::assertIsArray($parsed);

        return $parsed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jobSteps(): array
    {
        $workflow = $this->workflow();
        $steps = $workflow['jobs']['tool-version-drift']['steps'] ?? null;
        self::assertIsArray($steps);

        return \array_values($steps);
    }

    private function projectRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
