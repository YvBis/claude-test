<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the Meilisearch CI wiring of task 6.3.
 *
 * The engine arrives in CI as a third stateful service, and two files with no
 * shared source must agree on the image tag: `docker-compose.yml` (local) and
 * `.github/workflows/ci.yml` (runner). The ADR pins the tag by minor, so the
 * guard pins equality plus the exact minor — a bump in one file without the
 * other must redden the suite, not the nightly search run.
 *
 * The test index namespace is forced in `phpunit.xml.dist` on purpose: these
 * are engine-visible names, and the Docker `.env` value must not win when
 * `docker compose exec app` runs the suite.
 */
final class MeilisearchCiConfigTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
    }

    public function testCiServiceTagMatchesComposeTag(): void
    {
        /** @var array{jobs: array<string, array{services?: array<string, array<string, mixed>>}>} $ci */
        $ci = Yaml::parseFile($this->projectRoot.'/.github/workflows/ci.yml');
        /** @var array{services: array<string, array<string, mixed>>} $compose */
        $compose = Yaml::parseFile($this->projectRoot.'/docker-compose.yml');

        $ciImage = $ci['jobs']['unit-tests']['services']['meilisearch']['image'] ?? null;
        $composeServices = $compose['services'] ?? [];
        $composeImage = null;
        foreach ($composeServices as $service) {
            $image = $service['image'] ?? '';
            if (\str_starts_with((string) $image, 'getmeili/meilisearch:')) {
                $composeImage = $image;
            }
        }

        self::assertSame(
            'getmeili/meilisearch:v1.54',
            $composeImage,
            'docker-compose.yml must pin the engine by minor tag.',
        );
        self::assertSame(
            $composeImage,
            $ciImage,
            'The CI service must use the same engine image as docker-compose: two files, one tag, no shared source.',
        );
    }

    public function testCiServiceIsPresentOnlyWhereTestsRun(): void
    {
        /** @var array{jobs: array<string, array{services?: array<string, mixed>}>} $ci */
        $ci = Yaml::parseFile($this->projectRoot.'/.github/workflows/ci.yml');

        self::assertArrayHasKey(
            'meilisearch',
            $ci['jobs']['unit-tests']['services'] ?? [],
            'The engine must be a service of the unit-tests job.',
        );
        self::assertArrayNotHasKey(
            'meilisearch',
            $ci['jobs']['static-analysis']['services'] ?? [],
            'Static analysis runs no engine-backed tests; the service would be dead weight there.',
        );
    }

    public function testCiServiceUsesTheDevMasterKeyAndNoProductionEnv(): void
    {
        /** @var array{jobs: array<string, array{services?: array<string, array{env?: array<string, mixed>}}}>} $ci */
        $ci = Yaml::parseFile($this->projectRoot.'/.github/workflows/ci.yml');

        $env = $ci['jobs']['unit-tests']['services']['meilisearch']['env'] ?? [];

        self::assertSame(
            'masterKey123',
            $env['MEILI_MASTER_KEY'] ?? null,
            'Hardcoded like the mysql credentials: no production exists yet, and a secret would break fork PRs.',
        );
        self::assertArrayNotHasKey(
            'MEILI_ENV',
            $env,
            'Production env would reject the dev-length key and is not what CI runs.',
        );
    }

    public function testCiHealthCheckAddressesIpv4Literally(): void
    {
        /** @var array{jobs: array<string, array{services?: array<string, array{options?: string}}}>} $ci */
        $ci = Yaml::parseFile($this->projectRoot.'/.github/workflows/ci.yml');

        $options = (string) ($ci['jobs']['unit-tests']['services']['meilisearch']['options'] ?? '');

        // Measured 2026-10-06: inside the v1.54 container `localhost` resolves
        // to ::1 while the engine listens on IPv4, so a localhost health check
        // fails 10 retries and the job dies at "Initialize containers".
        self::assertStringContainsString(
            '127.0.0.1:7700',
            $options,
            'The health check must use the IPv4 literal: localhost resolves to ::1 inside the image.',
        );
        self::assertStringNotContainsString(
            'localhost:7700',
            $options,
            'localhost is refused inside the container (IPv6 first) — this exact form killed a CI run.',
        );
    }

    public function testPhpunitForcesTheTestIndexNamespace(): void
    {
        $xml = new \DOMDocument();
        self::assertTrue($xml->load($this->projectRoot.'/phpunit.xml.dist'));

        $xpath = new \DOMXPath($xml);
        $vars = [];
        foreach ($xpath->query('/phpunit/php/env[@name][starts-with(@name, "MEILISEARCH_")]') ?: [] as $node) {
            \assert($node instanceof \DOMElement);
            $vars[$node->getAttribute('name')] = [
                'value' => $node->getAttribute('value'),
                'force' => $node->getAttribute('force'),
            ];
        }

        self::assertSame(
            ['value' => 'items_test', 'force' => 'true'],
            $vars['MEILISEARCH_ITEMS_INDEX'] ?? null,
            'The items index name must be forced: the Docker .env value must not win inside `docker compose exec`.',
        );
        self::assertSame(
            ['value' => 'collections_test', 'force' => 'true'],
            $vars['MEILISEARCH_COLLECTIONS_INDEX'] ?? null,
            'The collections index name must be forced for the same reason.',
        );
        self::assertSame(
            '',
            $vars['MEILISEARCH_URL']['force'] ?? '',
            'URL/KEY stay non-forced: the Docker env_file value is correct locally and the CI fallback is correct on the runner.',
        );
    }
}
