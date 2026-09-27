<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Guards the wiring of the OpenAPI freshness check (fwd-11(b)).
 *
 * The tracked public/api/openapi.json can only stay honest if something
 * fails the build when it goes stale. These tests protect the integration
 * contract that a silent edit could break: the composer entry point, its
 * first position in ci:static:quality (so a stale spec is reported before
 * phpstan noise), and the script's compare-without-mutating shape.
 */
final class OpenApiFreshnessTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
    }

    /**
     * @return array{scripts: array<string, mixed>}
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

    public function testComposerExposesOpenApiFreshScript(): void
    {
        $scripts = $this->composerScripts();

        self::assertArrayHasKey('openapi:fresh', $scripts);
        self::assertStringContainsString(
            'scripts/check-openapi-fresh.sh',
            (string) $scripts['openapi:fresh'],
            'The composer script must delegate to the shared runner script so CI and local runs stay identical.',
        );
    }

    public function testStaticQualityRunsFreshnessCheckFirst(): void
    {
        $scripts = $this->composerScripts();

        self::assertArrayHasKey('ci:static:quality', $scripts);
        $quality = $scripts['ci:static:quality'];
        self::assertIsArray($quality);
        self::assertSame(
            '@openapi:fresh',
            $quality[0] ?? null,
            'The freshness check must run first in ci:static:quality: a stale spec is reported before phpstan/phpcs/rector noise.',
        );
    }

    public function testFreshnessScriptComparesWithoutMutatingTree(): void
    {
        $script = (string) \file_get_contents($this->projectRoot.'/scripts/check-openapi-fresh.sh');

        self::assertStringContainsString(
            'mktemp',
            $script,
            'The dump must go to a temp file: overwriting the tracked spec and git-diffing mutates the working tree.',
        );
        self::assertStringContainsString(
            'cmp -s "$TMP" "$SPEC"',
            $script,
            'The comparison must be byte equality of the temp dump against the committed file (the dump is deterministic).',
        );
        self::assertStringContainsString(
            '--env=dev',
            $script,
            'The dump must pin --env=dev: all component schemas live in config/packages/dev/, and CI boots with APP_ENV=test.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/(>>?|tee)\s+["\']?[^"\']*openapi\.json/',
            $script,
            'No redirect or tee may target the tracked spec: regeneration is the developer\'s explicit act.',
        );
    }
}
