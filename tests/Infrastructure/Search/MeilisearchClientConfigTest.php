<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Search;

use Meilisearch\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the Meilisearch integration contract of task 6.1.
 *
 * The client is constructed by the container at boot, so two properties are
 * load-bearing and neither is visible in normal use:
 *
 * 1. construction performs no HTTP call — the `symfony-diagnostics` job builds
 *    the container in the test environment with no external services and no
 *    proxying, so a boot-time probe (isHealthy and friends) would fail the
 *    gate; a health check may exist but must be called explicitly.
 * 2. the PSR-18 client and PSR-17 factories are injected explicitly — left to
 *    discovery, the constructor can throw while *building* the service when it
 *    cannot resolve the installed combination, which would break the
 *    container rather than the first search.
 *
 * The configured host is deliberately not asserted: it is the compose service
 * name inside Docker and 127.0.0.1 on a host runner (CI, or PHPUnit with host
 * PHP). One value serves two network namespaces, so asserting it here would
 * bake one of them into the suite.
 */
final class MeilisearchClientConfigTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
    }

    public function testClientConstructionIssuesNoHttpRequest(): void
    {
        $mockHttpClient = new MockHttpClient();
        $psr17Factory = new Psr17Factory();

        new Client(
            'http://127.0.0.1:7700',
            'test-key',
            new Psr18Client($mockHttpClient),
            $psr17Factory,
            [],
            $psr17Factory,
        );

        self::assertSame(
            0,
            $mockHttpClient->getRequestsCount(),
            'Constructing the Meilisearch client must not perform any HTTP request.',
        );
    }

    public function testComposerRequiresThePsrClientStack(): void
    {
        /** @var array{require: array<string, string>} $composer */
        $composer = \json_decode(
            (string) \file_get_contents($this->projectRoot.'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('meilisearch/meilisearch-php', $composer['require']);
        self::assertArrayHasKey('symfony/http-client', $composer['require']);
        self::assertArrayHasKey('nyholm/psr7', $composer['require']);
    }

    public function testContainerImageIsPinnedToASupportedMinor(): void
    {
        /** @var array{services: array<string, array<string, mixed>>} $compose */
        $compose = Yaml::parseFile($this->projectRoot.'/docker-compose.yml');

        self::assertSame(
            'getmeili/meilisearch:v1.54',
            $compose['services']['meilisearch']['image'],
            'The Meilisearch image must stay on the minor locked by ADR-0001; v1.6 is outside the security support window.',
        );
    }

    public function testClientServiceInjectsPsrFactoriesExplicitly(): void
    {
        /** @var array{services: array<string, array<string, mixed>>} $config */
        $config = Yaml::parseFile($this->projectRoot.'/config/packages/meilisearch.yaml');

        $arguments = $config['services'][Client::class]['arguments'];

        self::assertSame('%env(MEILISEARCH_URL)%', $arguments['$url']);
        self::assertSame('%env(MEILISEARCH_KEY)%', $arguments['$apiKey']);
        self::assertSame(
            '@Symfony\Component\HttpClient\Psr18Client',
            $arguments['$httpClient'],
            'Passing null would let the constructor run discovery, which can throw while building the service.',
        );
        self::assertSame('@Nyholm\Psr7\Factory\Psr17Factory', $arguments['$requestFactory']);
        self::assertSame('@Nyholm\Psr7\Factory\Psr17Factory', $arguments['$streamFactory']);
    }

    public function testClientServiceCarriesNoBootTimeProbe(): void
    {
        /** @var array{services: array<string, array<string, mixed>>} $config */
        $config = Yaml::parseFile($this->projectRoot.'/config/packages/meilisearch.yaml');

        $definition = $config['services'][Client::class];

        // A constructor-time probe could arrive as an extra argument, or as
        // `factory:` / `decorator:` indirection — the second is not in
        // `arguments`, so the definition keys are pinned alongside them.
        self::assertSame(
            ['arguments'],
            \array_keys($definition),
            'Only arguments may be declared: a factory or a decorator could reach the engine while the service is being built.',
        );
        self::assertSame(
            ['$url', '$apiKey', '$httpClient', '$requestFactory', '$streamFactory'],
            \array_keys($definition['arguments']),
            'Only the five constructor arguments may be passed.',
        );
    }

    public function testClientIsPrivateAndCarriesNoTestOnlyAlias(): void
    {
        /** @var array{services: array<string, array<string, mixed>>, when@test?: array<string, mixed>} $config */
        $config = Yaml::parseFile($this->projectRoot.'/config/packages/meilisearch.yaml');

        self::assertArrayNotHasKey(
            'public',
            $config['services'][Client::class],
            'A vendor service must not be public in production.',
        );
        // Since 6.4 the adapter injects the client, so it survives pruning and
        // tests reach it through the test container's private locator. A
        // when@test block here would either be dead weight (a distinct-id
        // alias) or destructive (redefining the client id replaces its
        // definition and drops the constructor arguments).
        self::assertArrayNotHasKey(
            'when@test',
            $config,
            'No test-only service overrides: the client is reachable without them since the adapter consumes it.',
        );
    }

    public function testPsr18ClientReceivesTheResponseFactoryExplicitly(): void
    {
        /** @var array{services: array<string, array<string, mixed>>} $config */
        $config = Yaml::parseFile($this->projectRoot.'/config/packages/meilisearch.yaml');

        // Files under config/packages do not inherit _defaults from
        // services.yaml, so an argument-less Psr18Client is not autowired and
        // its constructor resolves the PSR-17 factories itself — deterministically
        // only while php-http/discovery happens to be installed transitively.
        self::assertSame(
            '@Nyholm\Psr7\Factory\Psr17Factory',
            $config['services'][Psr18Client::class]['arguments']['$responseFactory'] ?? null,
        );
    }

    public function testEngineTransportFailsFast(): void
    {
        /** @var array{services: array<string, array<string, mixed>>} $config */
        $config = Yaml::parseFile($this->projectRoot.'/config/packages/meilisearch.yaml');

        // Since 6.4 every item mutation calls the engine. Measured against an
        // unreachable host: 21.4 s per request with the default client, 2.0 s
        // with timeout: 2. Without a bound, fail-open becomes slow-open.
        self::assertSame(
            '@meilisearch.http_client',
            $config['services'][Psr18Client::class]['arguments']['$client'] ?? null,
            'The PSR-18 client must wrap the dedicated, time-bounded HTTP client.',
        );

        /** @var array{arguments: array<int, array<string, int>>} $httpClient */
        $httpClient = $config['services']['meilisearch.http_client'] ?? [];
        $options = $httpClient['arguments'][0] ?? [];

        self::assertArrayHasKey('timeout', $options);
        self::assertLessThanOrEqual(5, $options['timeout'], 'A dead engine must not hold a request for long.');
        self::assertArrayHasKey('max_duration', $options);
    }

    public function testTestEnvironmentSuppliesMeilisearchVariables(): void
    {
        $phpunit = (string) \file_get_contents($this->projectRoot.'/phpunit.xml.dist');

        self::assertMatchesRegularExpression(
            '#<env name="MEILISEARCH_URL" value="http://127\.0\.0\.1:7700"/>#',
            $phpunit,
            'Tests must resolve Meilisearch at the host runner address; without force="true" the Docker value wins locally.',
        );
        self::assertMatchesRegularExpression(
            '#<env name="MEILISEARCH_KEY" value="masterKey123"/>#',
            $phpunit,
        );
    }

    public function testMeilisearchEnvironmentIsNotForced(): void
    {
        $phpunit = (string) \file_get_contents($this->projectRoot.'/phpunit.xml.dist');

        // Scoped to the Meilisearch lines: an unrelated force="true" on some
        // future variable is not this guard's business, and failing on it would
        // make the test wrong for an unrelated reason.
        foreach (['MEILISEARCH_URL', 'MEILISEARCH_KEY'] as $variable) {
            self::assertDoesNotMatchRegularExpression(
                '#<env name="'.\preg_quote($variable, '#').'"[^>]*force="true"#',
                $phpunit,
                \sprintf(
                    'A forced %s would override the Docker env_file value, and the compose service name does not resolve on a host runner.',
                    $variable,
                ),
            );
        }
    }

    public function testDotenvTestFileNoLongerDeclaresTheSearchHost(): void
    {
        $envTest = (string) \file_get_contents($this->projectRoot.'/.env.test');

        self::assertStringNotContainsString(
            'MEILISEARCH_URL=',
            $envTest,
            'The value lives in phpunit.xml.dist now; a second copy in .env.test would be a second source of truth.',
        );
        self::assertStringContainsString('DATABASE_URL=', $envTest);
    }
}
