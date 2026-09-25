<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards deprecation reporting in the CI pipeline (tasks 5.24 and 5.30).
 *
 * The project runs the suite with `SYMFONY_DEPRECATIONS_HELPER=disabled=1`, and
 * the symfony/phpunit-bridge handler never registers under PHPUnit 11 (its
 * bootstrap early-returns as soon as `PHPUnit\Metadata\Metadata` exists), so the
 * bridge env var is inert here. PHPUnit 11's own `--display-deprecations` flag is
 * what surfaces new deprecations before the major sweep.
 *
 * Task 5.30 merged the former second, non-blocking PHPUnit pass into the coverage
 * gate. The suite costs about the same with and without coverage collection
 * (272 s vs 269 s measured on the runner), so the extra pass doubled the job's
 * test time for no extra signal. The flag now lives in the `phpunit` composer
 * script that `coverage:gate` runs, and the workflow step greps the run output
 * for the deprecation line. This test pins that shape, because the failure mode
 * it guards against is silent: dropping the flag or re-adding a second pass
 * leaves a green pipeline that proves nothing.
 *
 * The suite is located by the shape of the command that runs it rather than by
 * the step's name, so renaming a step does not break the guard and a step that
 * merely mentions `var/phpunit-ci.log` is not mistaken for a second run.
 *
 * Task 5.31 added a runtime probe for the output format itself
 * (`scripts/deprecation-format-probe.sh`, run as its own CI step after the
 * gate): the workflow greps for PHPUnit's own summary line, so a PHPUnit
 * upgrade that rewords it would blind the warning while the pipeline stays
 * green. The probe replays both fixtures against the extracted pattern. This
 * class pins the probe step's wiring; the format check itself lives in the
 * script because two child PHPUnit boots (~6 s each) were over budget for
 * every local suite run.
 */
final class DeprecationConfigTest extends TestCase
{
    /**
     * A PHPUnit invocation, however it is spelled: `php bin/phpunit`, a
     * `vendor/bin/phpunit`, a `@phpunit` composer alias, or a composer script
     * that runs the suite.
     */
    private const string INVOCATION_PATTERN = '/(?:php\s+bin\/phpunit\b|vendor\/bin\/phpunit\b|@phpunit\b|composer\s+(?:coverage:gate|ci:test|ci:all|test)\b)/';

    public function testTheSinglePhpunitRunReportsDeprecations(): void
    {
        $step = $this->suiteStep();
        $run = (string) ($step['run'] ?? '');

        self::assertStringContainsString(
            'coverage:gate',
            $run,
            'The merged step must still run the coverage gate: it is the blocking check.',
        );
        self::assertStringContainsString(
            '--display-deprecations',
            $this->phpunitScript(),
            'The flag belongs in the `phpunit` composer script that coverage:gate runs. Passing it as `composer coverage:gate -- --display-deprecations` does not work either: composer appends extra arguments to EVERY command of a script array (measured: `composer probe -- EXTRA` ran `echo FIRST EXTRA` and `echo SECOND EXTRA`), so the gate script would receive the flag as well.',
        );
        self::assertStringContainsString(
            'triggered [0-9]+ deprecation',
            $run,
            'The deprecation line must still be detected and turned into an annotation.',
        );
        self::assertStringContainsString(
            'GITHUB_STEP_SUMMARY',
            $run,
            'The run log must still be published to the job summary.',
        );
        self::assertArrayNotHasKey(
            'continue-on-error',
            $step,
            'The merged step is the coverage gate: a real test failure must fail the job. Only the deprecation line is advisory.',
        );
        self::assertStringNotContainsString(
            'SYMFONY_DEPRECATIONS_HELPER',
            $run,
            'The bridge env var does nothing on PHPUnit 11; relying on it would silently turn this step into a no-op.',
        );

        $env = $step['env'] ?? null;
        self::assertIsArray($env, 'The test run needs the test database.');
        self::assertArrayHasKey('DATABASE_URL', $env);
    }

    public function testTheJobRunsTheSuiteExactlyOnce(): void
    {
        self::assertCount(
            1,
            $this->suiteSteps(),
            'Exactly one step may run the suite; the second, coverage-less pass was dropped in task 5.30 because it cost the same as the coverage run and reported nothing extra.',
        );

        foreach ($this->unitTestsSteps() as $step) {
            $run = (string) ($step['run'] ?? '');
            if ('' === $run) {
                continue;
            }

            self::assertStringNotContainsString(
                '--no-coverage',
                $run,
                'A coverage-less PHPUnit pass doubles the job time without adding signal (task 5.30).',
            );
        }
    }

    public function testNoCiStepReliesOnTheInertBridgeEnvVar(): void
    {
        foreach ($this->unitTestsSteps() as $step) {
            self::assertStringNotContainsString(
                'SYMFONY_DEPRECATIONS_HELPER',
                (string) ($step['run'] ?? ''),
                'No executed command may rely on SYMFONY_DEPRECATIONS_HELPER: on PHPUnit 11 it does nothing.',
            );

            $env = $step['env'] ?? [];
            if (\is_array($env)) {
                self::assertArrayNotHasKey(
                    'SYMFONY_DEPRECATIONS_HELPER',
                    $env,
                    'Declaring the bridge env var in a step env: is inert on PHPUnit 11 and only adds noise.',
                );
            }
        }
    }

    public function testDeprecationFormatProbeStepRunsTheProbeScript(): void
    {
        $probes = \array_values(\array_filter(
            $this->unitTestsSteps(),
            static fn (array $step): bool => \str_contains((string) ($step['run'] ?? ''), 'scripts/deprecation-format-probe.sh'),
        ));

        self::assertCount(
            1,
            $probes,
            'Exactly one step must run the deprecation format probe: without it a PHPUnit upgrade can silently blind the deprecation warning.',
        );

        self::assertArrayNotHasKey(
            'continue-on-error',
            $probes[0],
            'The probe step is a guard: a format drift must fail the job, not warn past it.',
        );

        self::assertFileExists(
            $this->projectRoot().'/scripts/deprecation-format-probe.sh',
            'The probe step must point at the committed script.',
        );
        self::assertFileExists(
            $this->projectRoot().'/tests/Fixtures/Deprecation/DeprecationTriggerFixture.php',
            'The probe needs its triggering fixture.',
        );
        self::assertFileExists(
            $this->projectRoot().'/tests/Fixtures/Deprecation/DeprecationCleanFixture.php',
            'The probe needs its clean fixture.',
        );
    }

    public function testPhpunitConfigKeepsDeprecationsDisabledForTheMainRun(): void
    {
        $config = (string) \file_get_contents($this->projectRoot().'/phpunit.xml.dist');

        self::assertMatchesRegularExpression(
            '/<server name="SYMFONY_DEPRECATIONS_HELPER" value="disabled=1"\s*\/>/',
            $config,
            'The main suite keeps deprecations disabled on purpose; the CI step is where they are surfaced.',
        );
    }

    private function phpunitScript(): string
    {
        $composer = \json_decode((string) \file_get_contents($this->projectRoot().'/composer.json'), true);
        self::assertIsArray($composer);
        $script = $composer['scripts']['phpunit'] ?? null;
        self::assertIsString($script, 'The `phpunit` composer script was not found in composer.json.');

        return $script;
    }

    /**
     * @return array<string, mixed>
     */
    private function suiteStep(): array
    {
        $steps = $this->suiteSteps();
        self::assertCount(
            1,
            $steps,
            'Exactly one step may run the suite; a second pass doubles the job time without adding signal.',
        );

        return $steps[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suiteSteps(): array
    {
        return \array_values(\array_filter(
            $this->unitTestsSteps(),
            static fn (array $step): bool => 1 === \preg_match(self::INVOCATION_PATTERN, (string) ($step['run'] ?? '')),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unitTestsSteps(): array
    {
        $workflow = Yaml::parseFile($this->projectRoot().'/.github/workflows/ci.yml');
        self::assertIsArray($workflow);
        $jobs = $workflow['jobs'] ?? null;
        self::assertIsArray($jobs);
        $job = $jobs['unit-tests'] ?? null;
        self::assertIsArray($job, 'The "unit-tests" job was not found in .github/workflows/ci.yml.');
        $steps = $job['steps'] ?? null;
        self::assertIsArray($steps);

        /* @var list<array<string, mixed>> $steps */
        return \array_values($steps);
    }

    private function projectRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
