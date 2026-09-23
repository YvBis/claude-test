<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the deprecation-reporting step of the CI pipeline (task 5.24).
 *
 * The project runs the main suite with `SYMFONY_DEPRECATIONS_HELPER=disabled=1`,
 * and the symfony/phpunit-bridge handler never registers under PHPUnit 11 (its
 * bootstrap early-returns as soon as `PHPUnit\Metadata\Metadata` exists), so the
 * bridge env var is inert here. PHPUnit 11's own `--display-deprecations` flag is
 * what surfaces new deprecations before the major sweep.
 *
 * The workflow is parsed as YAML rather than matched as text, so the assertions
 * stay scoped to the deprecation step and survive reformatting.
 */
final class DeprecationConfigTest extends TestCase
{
    private const string STEP_NAME = 'Run PHPUnit with deprecation reporting (non-blocking)';

    public function testCiJobRunsDeprecationReportingAsNonBlockingStep(): void
    {
        $step = $this->stepByName(self::STEP_NAME);
        $run = (string) ($step['run'] ?? '');

        self::assertStringContainsString(
            '--display-deprecations',
            $run,
            'The deprecation pass must use PHPUnit 11\'s native flag; the bridge env var is inert (see the class docblock).',
        );
        self::assertStringContainsString(
            '--no-coverage',
            $run,
            'The second PHPUnit run must skip coverage: the coverage gate already ran and the job has a 15 minute budget.',
        );
        self::assertTrue(
            (bool) ($step['continue-on-error'] ?? false),
            'The deprecation pass is advisory: it must not fail the job while the baseline is not clean yet.',
        );
        self::assertStringNotContainsString(
            'SYMFONY_DEPRECATIONS_HELPER',
            $run,
            'The bridge env var does nothing on PHPUnit 11; relying on it would silently turn this step into a no-op.',
        );

        $env = $step['env'] ?? null;
        self::assertIsArray($env, 'The deprecation pass needs the test database.');
        self::assertArrayHasKey('DATABASE_URL', $env);
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

    public function testPhpunitConfigKeepsDeprecationsDisabledForTheMainRun(): void
    {
        $config = (string) \file_get_contents($this->projectRoot().'/phpunit.xml.dist');

        self::assertMatchesRegularExpression(
            '/<server name="SYMFONY_DEPRECATIONS_HELPER" value="disabled=1"\s*\/>/',
            $config,
            'The main suite keeps deprecations disabled on purpose; the CI step is where they are surfaced.',
        );
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

    /**
     * @return array<string, mixed>
     */
    private function stepByName(string $name): array
    {
        foreach ($this->unitTestsSteps() as $step) {
            if (($step['name'] ?? null) === $name) {
                return $step;
            }
        }

        self::fail(\sprintf('Step "%s" was not found in the "unit-tests" job.', $name));
    }

    private function projectRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
