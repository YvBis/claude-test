<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the mutation-testing wiring (task 8.4, PRD/8.4-infection.md).
 *
 * Three things can silently rot here, and each has bitten a neighbouring task:
 *
 * 1. Source drift. `infection.json` mutates `src/Domain` + `src/Application`
 *    because that is exactly what `phpunit.xml.dist` measures; if someone adds a
 *    layer to one side only, mutation coverage and coverage gates disagree and
 *    nobody notices until a threshold fires for the wrong reason.
 * 2. Report collision. The mutation PHPUnit config must not write a clover
 *    report: `coverage:check` owns `var/coverage/clover.xml`, and the 8.3 gate
 *    reads it.
 * 3. Threshold drift. `minMsi` lives in `infection.json` only; a second copy on
 *    the command line would let CI and local runs disagree silently.
 *
 * The comparison helpers are shared with a negative probe (see
 * `testDirectoryComparisonRejectsDrift`): a guard that cannot fail is decoration,
 * so the same helper is fed a drifted pair and must report it.
 */
final class InfectionConfigTest extends TestCase
{
    private const EXPECTED_SOURCE_LAYERS = ['src/Domain', 'src/Application'];
    private const EXPECTED_TEST_DIRS = ['tests/Domain', 'tests/Application'];

    public function testConfigIsValidJson(): void
    {
        self::assertIsArray(self::infectionConfig(), 'infection.json must be valid JSON (not JSON5: PHP cannot parse it).');
    }

    public function testSourceDirectoriesMirrorTheCoverageSource(): void
    {
        $config = self::infectionConfig();
        $declared = $config['source']['directories'] ?? null;
        self::assertIsArray($declared, 'infection.json must declare source.directories.');

        $mismatch = self::directoryMismatch(self::EXPECTED_SOURCE_LAYERS, $declared);
        self::assertSame(
            [],
            $mismatch,
            'Mutation source must be exactly the layers phpunit.xml.dist measures.',
        );

        // Belt and braces: compare against phpunit.xml.dist itself, so the two
        // files cannot drift apart without this test noticing.
        $coverageLayers = self::phpunitSourceDirectories();
        self::assertSame(
            [],
            self::directoryMismatch($coverageLayers, $declared),
            'infection.json source must mirror phpunit.xml.dist <source><include>.',
        );
    }

    public function testMutationPhpunitConfigRunsOnlyTheUnitLayers(): void
    {
        $directories = self::mutationTestsuiteDirectories();

        self::assertSame(
            [],
            self::directoryMismatch(self::EXPECTED_TEST_DIRS, $directories),
            'The mutation run must execute the unit layers only: it is the reason the job needs no services.',
        );
    }

    public function testMutationPhpunitConfigNeverWritesTheCoverageGateReport(): void
    {
        $xml = self::mutationPhpunitConfig();
        $clover = $xml->xpath('//coverage/report/clover');
        self::assertNotFalse($clover, 'Reading the clover report node must succeed.');
        self::assertCount(
            0,
            \is_array($clover) ? $clover : [],
            'The mutation config must not emit clover: coverage:check owns var/coverage/clover.xml.',
        );
    }

    public function testTheMutationConfigIsDiscoverableByInfection(): void
    {
        $config = self::infectionConfig();
        $configDir = $config['phpUnit']['configDir'] ?? null;
        self::assertIsString($configDir, 'infection.json must point phpUnit.configDir at the mutation config.');

        // Infection locates the test-framework config by scanning that directory
        // for `phpunit.<ext>`; a custom file name would not be found at all.
        self::assertFileExists(
            \dirname(__DIR__, 3).'/'.$configDir.'/phpunit.xml',
            'phpUnit.configDir must contain a phpunit.xml - that is the only name Infection looks for.',
        );
    }

    public function testArtifactsStayInsideVar(): void
    {
        $config = self::infectionConfig();
        $tmpDir = $config['tmpDir'] ?? null;
        self::assertIsString($tmpDir, 'infection.json must declare tmpDir.');
        self::assertStringStartsWith('var/', $tmpDir, 'tmpDir must stay under the ignored var/ tree.');

        foreach ($config['logs'] ?? [] as $name => $path) {
            self::assertIsString($path, \sprintf('Log "%s" must be a path.', $name));
            self::assertStringStartsWith(
                'var/',
                $path,
                \sprintf('Log "%s" must stay under var/ (Infection defaults to .infection/ in the repo root).', $name),
            );
        }
    }

    public function testTimeoutsCountAsEscaped(): void
    {
        // Measured 2026-10-16: the first full local run reported 647 of 962
        // mutants as timeouts, and Infection counts timeouts as killed by
        // default - MSI came out at 97% while only 295 mutants were actually
        // killed. Honest metrics require this flag to stay on.
        self::assertTrue(
            self::infectionConfig()['timeoutsAsEscaped'] ?? false,
            'timeoutsAsEscaped must stay true, otherwise timeouts inflate MSI.',
        );
    }

    public function testMsiThresholdIsSingleSourcedInTheConfig(): void
    {
        $config = self::infectionConfig();
        $minMsi = $config['minMsi'] ?? null;
        self::assertIsInt($minMsi, 'minMsi must be a number in infection.json.');
        self::assertGreaterThanOrEqual(1, $minMsi);
        self::assertLessThanOrEqual(100, $minMsi);

        $ci = Yaml::parseFile(self::projectRoot().'/.github/workflows/ci.yml');
        $steps = $ci['jobs']['infection']['steps'] ?? [];
        self::assertIsArray($steps, 'ci.yml must define the infection job.');

        foreach ($steps as $step) {
            self::assertStringNotContainsString(
                '--min-msi',
                (string) ($step['run'] ?? ''),
                'minMsi must come from infection.json only; a second copy on the CLI drifts silently.',
            );
        }
    }

    public function testCiWiresTheMutationJobIntoTheRequiredSummary(): void
    {
        $ci = Yaml::parseFile(self::projectRoot().'/.github/workflows/ci.yml');

        self::assertArrayHasKey('infection', $ci['jobs'] ?? [], 'ci.yml must define the infection job.');

        $needs = $ci['jobs']['ci-summary']['needs'] ?? [];
        self::assertContains(
            'infection',
            $needs,
            'The mutation job must be part of ci-summary, which the ruleset already requires.',
        );
    }

    public function testMutationConfigKeepsPhpunitAttributeParity(): void
    {
        // The mutation config is phpunit.xml.dist minus Infrastructure, so every
        // strictness attribute must stay identical - a future `failOn*` flag added
        // to one file only would make the same test behave differently inside the
        // mutation run. Only these two may differ, for the reason named in the file.
        $allowedDiffering = ['cacheResult', 'cacheDirectory'];

        $base = self::phpunitAttributes(self::projectRoot().'/phpunit.xml.dist');
        $mutation = self::phpunitAttributes(self::projectRoot().'/tests/infection/phpunit.xml');

        foreach ($base as $name => $value) {
            if (\in_array($name, $allowedDiffering, true)) {
                continue;
            }
            self::assertArrayHasKey(
                $name,
                $mutation,
                \sprintf('phpunit attribute "%s" is missing from the mutation config.', $name),
            );
            self::assertSame(
                $value,
                $mutation[$name],
                \sprintf('phpunit attribute "%s" drifted between phpunit.xml.dist and the mutation config.', $name),
            );
        }

        $extra = \array_diff(\array_keys($mutation), \array_keys($base), $allowedDiffering);
        self::assertSame([], \array_values($extra), 'The mutation config must not invent phpunit attributes.');
    }

    public function testMutationConfigDropsOnlyTheSearchEnvironment(): void
    {
        $base = self::envNames(self::projectRoot().'/phpunit.xml.dist');
        $mutation = self::envNames(self::projectRoot().'/tests/infection/phpunit.xml');

        self::assertSame(
            [],
            \array_values(\array_diff($mutation, $base)),
            'The mutation config must not declare env vars the main config does not know.',
        );

        // Nothing on the unit layer touches the engine, so the MEILISEARCH_* block
        // is the documented delta and must stay exactly that.
        $dropped = \array_values(\array_diff($base, $mutation));
        \sort($dropped);
        self::assertSame(
            ['MEILISEARCH_COLLECTIONS_INDEX', 'MEILISEARCH_ITEMS_INDEX', 'MEILISEARCH_KEY', 'MEILISEARCH_URL'],
            $dropped,
            'Only the Meilisearch env vars may be dropped from the mutation config.',
        );
    }

    public function testComposerScriptsDoNotCarryTheirOwnThreshold(): void
    {
        $composer = \json_decode(
            (string) \file_get_contents(self::projectRoot().'/composer.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        foreach (['mutation', 'mutation:ci'] as $script) {
            $command = $composer['scripts'][$script] ?? null;
            self::assertIsString($command, \sprintf('composer script "%s" must exist.', $script));
            self::assertStringNotContainsString(
                '--min-msi',
                $command,
                'minMsi lives in infection.json; a flag here would drift from the config.',
            );
            // Measured twice (CI run 38939205749 and a local reproduction): printing
            // escaped-mutant diffs crashes Symfony Console with "Incorrectly nested
            // style tag found." on CreateCollectionDTO. The reports keep the diffs,
            // the console does not need them.
            self::assertStringContainsString(
                '--show-mutations=0',
                $command,
                'The console formatter cannot render some mutant diffs; the scripts must keep it off.',
            );
        }
    }

    public function testCiRunsTheSharedComposerScriptInDiffMode(): void
    {
        $ci = Yaml::parseFile(self::projectRoot().'/.github/workflows/ci.yml');
        $steps = $ci['jobs']['infection']['steps'] ?? [];
        self::assertIsArray($steps);

        $runs = [];
        foreach ($steps as $step) {
            if (isset($step['run']) && \is_string($step['run'])) {
                $runs[] = $step['run'];
            }
        }

        $diffRuns = \array_values(\array_filter($runs, static fn (string $run): bool => \str_contains($run, '--git-diff-lines')));
        self::assertCount(2, $diffRuns, 'The PR and push steps must both mutate the diff only.');

        foreach ($runs as $run) {
            self::assertStringContainsString(
                'mutation:ci',
                $run,
                'Every mutation step must go through the composer script, so local and CI flags cannot drift.',
            );
        }
    }

    public function testDirectoryComparisonRejectsDrift(): void
    {
        // Negative probe: the helper used by the source/testsuite guards above
        // must actually report a difference, otherwise those guards prove nothing.
        self::assertNotSame(
            [],
            self::directoryMismatch(self::EXPECTED_SOURCE_LAYERS, ['src/Domain']),
            'Removing a layer must be reported as drift.',
        );
        self::assertNotSame(
            [],
            self::directoryMismatch(self::EXPECTED_SOURCE_LAYERS, ['src/Application', 'src/Domain', 'src/Infrastructure']),
            'Adding an extra layer must be reported as drift.',
        );
        self::assertSame(
            [],
            self::directoryMismatch(self::EXPECTED_SOURCE_LAYERS, ['src/Domain/', './src/Application']),
            'Trailing separators and dot prefixes are not drift.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function infectionConfig(): array
    {
        $path = self::projectRoot().'/infection.json';
        self::assertFileExists($path);

        $decoded = \json_decode((string) \file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function mutationPhpunitConfig(): \SimpleXMLElement
    {
        $path = self::projectRoot().'/tests/infection/phpunit.xml';
        self::assertFileExists($path);

        $xml = \simplexml_load_file($path);
        self::assertNotFalse($xml);

        return $xml;
    }

    /**
     * @return list<string>
     */
    private static function mutationTestsuiteDirectories(): array
    {
        $directories = [];
        foreach (self::mutationPhpunitConfig()->testsuites->testsuite as $testsuite) {
            foreach ($testsuite->directory as $directory) {
                $directories[] = self::normalize((string) $directory);
            }
        }

        return \array_values(\array_unique($directories));
    }

    /**
     * @return list<string>
     */
    private static function phpunitSourceDirectories(): array
    {
        $xml = \simplexml_load_file(self::projectRoot().'/phpunit.xml.dist');
        self::assertNotFalse($xml);

        $directories = [];
        foreach ($xml->source->include->directory as $directory) {
            $directories[] = self::normalize((string) $directory);
        }

        return \array_values(\array_unique($directories));
    }

    /**
     * @param list<string>            $expected
     * @param array<array-key, mixed> $actual
     *
     * @return list<string> human-readable differences; empty means "same set"
     */
    private static function directoryMismatch(array $expected, array $actual): array
    {
        $normalized = [];
        foreach ($actual as $entry) {
            if (\is_string($entry)) {
                $normalized[] = self::normalize($entry);
            }
        }

        $expected = \array_map(self::normalize(...), $expected);
        \sort($expected);
        \sort($normalized);

        $diff = \array_merge(
            \array_diff($expected, $normalized),
            \array_diff($normalized, $expected),
        );

        return \array_values(\array_unique($diff));
    }

    /**
     * Directories arrive with either separator, with or without a leading `./`
     * or parent hops (tests/infection/phpunit.xml points at ../../src/...), and
     * with or without a trailing separator; those are not differences.
     */
    private static function normalize(string $path): string
    {
        return \rtrim(self::stripRelativePrefix(\str_replace('\\', '/', \trim($path))), '/');
    }

    /**
     * Drops leading `./` and `../` hops: a path written from tests/infection/ is
     * the same directory as the one written from the project root.
     */
    private static function stripRelativePrefix(string $path): string
    {
        while (\str_starts_with($path, './')) {
            $path = \substr($path, 2);
        }

        while (\str_starts_with($path, '../')) {
            $path = \substr($path, 3);
        }

        return $path;
    }

    /**
     * @return array<string, string> attribute name => value, with path-like values
     *                               normalized so the two configs (different
     *                               directory depths) compare equal
     */
    private static function phpunitAttributes(string $path): array
    {
        self::assertFileExists($path);
        $xml = \simplexml_load_file($path);
        self::assertNotFalse($xml);

        $attributes = [];
        foreach ($xml->attributes() as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;
            if (\str_contains($value, '/')) {
                $value = self::stripRelativePrefix(\str_replace('\\', '/', $value));
            }
            $attributes[$name] = $value;
        }

        return $attributes;
    }

    /**
     * @return list<string>
     */
    private static function envNames(string $path): array
    {
        self::assertFileExists($path);
        $xml = \simplexml_load_file($path);
        self::assertNotFalse($xml);

        $names = [];
        foreach ($xml->php->env ?? [] as $env) {
            $names[] = (string) $env['name'];
        }

        return $names;
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
