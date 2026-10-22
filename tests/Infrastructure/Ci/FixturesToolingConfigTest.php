<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Guards the fixture tooling wired for stage 9 (task 9.1).
 *
 * Foundry exists for demo/frontend data, not for the product: it must stay a
 * dev dependency, must be registered only in dev/test, and must never be
 * referenced from `src/`. Those three facts are what keeps the production
 * container free of seed tooling, and each of them can silently drift - a
 * well-meaning `composer require zenstruck/foundry` drops it into the runtime
 * dependencies and nothing fails until someone inspects an image.
 *
 * The PHPUnit extension is pinned too: without the `bootstrap` entry Foundry's
 * attributes degrade to no-ops, so a suite that uses them would stay green
 * while asserting nothing.
 */
final class FixturesToolingConfigTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
    }

    public function testFoundryIsADevDependency(): void
    {
        $composer = $this->composerJson();

        self::assertArrayHasKey(
            'zenstruck/foundry',
            $composer['require-dev'] ?? [],
            'Fixture tooling belongs to require-dev: the runtime image must not carry seed code or its dependencies (stage 9, task 9.1).',
        );
        self::assertArrayNotHasKey(
            'zenstruck/foundry',
            $composer['require'] ?? [],
            'zenstruck/foundry moved into require: that ships fixture tooling to every runtime install.',
        );
    }

    public function testFoundryBundleIsRegisteredForDevAndTestOnly(): void
    {
        /** @var array<class-string, array<string, bool>> $bundles */
        $bundles = require $this->projectRoot.'/config/bundles.php';

        self::assertArrayHasKey(
            'Zenstruck\Foundry\ZenstruckFoundryBundle',
            $bundles,
            'The Foundry bundle must be registered in config/bundles.php for stage 9 to work in dev/test.',
        );
        self::assertSame(
            ['dev' => true, 'test' => true],
            $bundles['Zenstruck\Foundry\ZenstruckFoundryBundle'],
            'The Foundry bundle must be enabled in exactly dev and test; any other environment (prod) seeds its database by accident.',
        );
    }

    public function testNoProductionCodeReferencesFoundry(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot.'/src', \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            if (\str_contains((string) \file_get_contents($file->getPathname()), 'Zenstruck\\Foundry')) {
                $offenders[] = \str_replace($this->projectRoot.'/', '', $file->getPathname());
            }
        }

        self::assertSame(
            [],
            $offenders,
            'src/ must not depend on fixture tooling: seed code belongs to App\Tests\, so the dependency rule stays intact and production cannot call it.',
        );
    }

    public function testFoundryIsNotInTheRuntimePackageSet(): void
    {
        $lock = $this->composerLock();

        $runtime = [];
        foreach ($lock['packages'] ?? [] as $package) {
            $runtime[] = $package['name'] ?? '';
        }

        self::assertNotContains(
            'zenstruck/foundry',
            $runtime,
            'composer.lock lists zenstruck/foundry among runtime packages: a transitive pull can do this without touching composer.json, and then every install carries seed tooling.',
        );
        self::assertNotContains(
            'fakerphp/faker',
            $runtime,
            'faker is a fixture dependency; landing it in the runtime package set means demo-data tooling reached production.',
        );
    }

    public function testPhpunitRegistersTheFoundryExtensionBeforeDama(): void
    {
        $document = new \DOMDocument();
        self::assertTrue(
            $document->load($this->projectRoot.'/phpunit.xml.dist'),
            'phpunit.xml.dist must stay well-formed XML.',
        );

        $xpath = new \DOMXPath($document);
        $classes = [];
        /** @var \DOMElement $node */
        foreach ($xpath->query('//extensions/bootstrap') as $node) {
            $classes[] = $node->getAttribute('class');
        }

        self::assertContains(
            'Zenstruck\Foundry\PHPUnit\FoundryExtension',
            $classes,
            'FoundryExtension must be registered: without it Foundry attributes are silently inert and tests asserting persistence stay green while checking nothing. A commented-out line does not count, which is why this parses the XML instead of searching the file.',
        );

        $foundry = \array_search('Zenstruck\Foundry\PHPUnit\FoundryExtension', $classes, true);
        $dama = \array_search('DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension', $classes, true);
        self::assertIsInt($foundry);
        self::assertIsInt($dama);
        self::assertLessThan(
            $dama,
            $foundry,
            'Foundry must be registered before DAMA: reversed, Foundry\'s database reset would run inside DAMA\'s rollback transaction and issue DDL that implicitly commits it.',
        );
    }

    /**
     * @return array{require?: array<string, string>, require-dev?: array<string, string>}
     */
    private function composerJson(): array
    {
        $decoded = \json_decode((string) \file_get_contents($this->projectRoot.'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array{packages?: list<array{name?: string}>, 'packages-dev'?: list<array{name?: string}>}
     */
    private function composerLock(): array
    {
        $decoded = \json_decode((string) \file_get_contents($this->projectRoot.'/composer.lock'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
