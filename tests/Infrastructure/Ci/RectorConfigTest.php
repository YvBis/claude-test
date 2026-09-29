<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Guards the wiring of the Rector config gate (5.25 sweep).
 *
 * `rector validate-config` checks rector.php without processing files and
 * fails on deprecated rules/config (proven exit 1 on a transiently
 * registered deprecated rule, exit 0 on the clean config). These tests
 * protect the integration contract a silent edit could break: the composer
 * entry point, and its position in ci:static:quality right after
 *
 * @openapi:fresh (position 0 is pinned by OpenApiFreshnessTest) so a broken
 * config is reported before phpstan/phpcs/rector noise.
 */
final class RectorConfigTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function composerScripts(): array
    {
        /** @var array{scripts: array<string, mixed>} $composer */
        $composer = \json_decode(
            (string) \file_get_contents($this->projectRoot.'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $composer['scripts'];
    }

    public function testComposerExposesRectorValidateConfigScript(): void
    {
        $scripts = $this->composerScripts();

        self::assertArrayHasKey('rector:validate-config', $scripts);
        self::assertStringContainsString(
            'rector validate-config',
            (string) $scripts['rector:validate-config'],
            'The composer script must invoke rector validate-config directly so CI and local runs stay identical.',
        );
    }

    public function testStaticQualityRunsValidateConfigAfterFreshness(): void
    {
        $scripts = $this->composerScripts();

        self::assertArrayHasKey('ci:static:quality', $scripts);
        $quality = $scripts['ci:static:quality'];
        self::assertIsArray($quality);

        $position = \array_search('@rector:validate-config', $quality, true);

        self::assertNotFalse(
            $position,
            'ci:static:quality must run the rector config gate (located by name, not position).',
        );
        self::assertGreaterThan(
            0,
            $position,
            'The config gate must run after @openapi:fresh (position 0): a stale spec is reported first.',
        );
    }
}
