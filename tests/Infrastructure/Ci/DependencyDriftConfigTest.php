<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the version-drift cron for the symfony-lsp checker binary (task 5.14,
 * PR-B).
 *
 * The checker is downloaded, not vendored, so Dependabot cannot see it — the
 * weekly `dependency-drift` workflow is the only drift visibility. It is
 * advisory-only by design: an upstream release cadence is not a contract, so
 * a drift must print `::warning::`, never fail, and never enter the merge
 * gate (no `needs:` edit, no ruleset edit, no new required check).
 *
 * The pin lives in two places — the runner default
 * (`scripts/symfony-lsp-check.sh`) and the ci.yml env (which overrides it) —
 * and the drift script reads both producers, never this test's regex.
 *
 * Honesty label: these assertions pin the wiring (schedule exists, both
 * triggers present, the step runs the script, the script carries the EXIT
 * trap and reads both anchors). They cannot observe whether the schedule
 * actually fires — GitHub pauses crons on idle repos and runs can lag.
 * Liveness is proven by run history, not by this test.
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
            static fn (array $step): bool => 'Check symfony-lsp version drift' === ($step['name'] ?? null),
        ));
        self::assertCount(1, $matches, 'Exactly one step must run the drift check, found by name (5.22: never by position).');

        $run = (string) ($matches[0]['run'] ?? '');
        self::assertStringContainsString(
            'scripts/check-tool-version-drift.sh',
            $run,
            'The drift step must run the drift script.',
        );

        $raw = (string) \file_get_contents($this->projectRoot().'/.github/workflows/dependency-drift.yml');
        self::assertStringNotContainsString(
            'DRIFT_PINNED_VERSION',
            $raw,
            'The workflow must never set the proof hook anywhere (step run, step env, job env, top env): it replaces the extracted pin and would blind the check.',
        );
        self::assertStringNotContainsString(
            'DRIFT_REPO',
            $raw,
            'The workflow must never redirect the upstream repository either: comparing against the wrong upstream blinds the check the same way.',
        );
    }

    public function testScriptGuaranteesExitZeroAndReadsBothAnchors(): void
    {
        $script = (string) \file_get_contents($this->projectRoot().'/scripts/check-tool-version-drift.sh');

        self::assertStringContainsString(
            'trap on_exit EXIT',
            $script,
            'The script runs under `set -euo pipefail` with an always-exit-0 contract: without the EXIT trap the first failed gh/jq/grep would break the advisory guarantee.',
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
        self::assertStringContainsString(
            'exit 0',
            $script,
            'The advisory contract needs the literal guarantee in the script, not just the trap: without an explicit exit 0 the guarantee is structural and invisible.',
        );
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
