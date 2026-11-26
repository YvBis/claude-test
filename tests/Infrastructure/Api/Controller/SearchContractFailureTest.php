<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use Meilisearch\Client;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A broken index document must surface as a bare 500, not as a hit full of
 * empty strings: reads are not fail-open (6.6), so the HTTP contract is
 * pinned here rather than only at the hit level.
 *
 * The engine client is replaced, not the reader, so the real adapter, the real
 * hit construction and the real service chain run. The client is a real
 * Meilisearch client over a mocked transport, which keeps the document shape
 * under our control without a live engine.
 */
final class SearchContractFailureTest extends WebTestCase
{
    public function testBrokenItemDocumentReturnsABare500(): void
    {
        $client = $this->clientServingHits([['name' => 'Dune']]);

        $client->request('GET', '/api/search/items?q=dune');

        self::assertResponseStatusCodeSame(500);
        self::assertSame(
            ['error' => 'Internal Server Error'],
            \json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testBrokenCollectionDocumentReturnsABare500(): void
    {
        $client = $this->clientServingHits([['id' => 'c1', 'name' => '', 'theme' => 'books']]);

        $client->request('GET', '/api/search/collections?q=books');

        self::assertResponseStatusCodeSame(500);
        self::assertSame(
            ['error' => 'Internal Server Error'],
            \json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testBrokenItemDocumentIsLoggedAsACriticalWithTheException(): void
    {
        $client = $this->clientServingHits([['id' => '', 'name' => 'Dune']]);

        // The framework logs uncaught throwables to the `request` channel; the
        // handler goes on the live instance because ErrorListener keeps the
        // logger it received at construction time.
        $logger = static::getContainer()->get('monolog.logger.request');
        self::assertInstanceOf(Logger::class, $logger);
        $handler = new TestHandler();
        $logger->pushHandler($handler);

        $client->request('GET', '/api/search/items?q=dune');

        self::assertResponseStatusCodeSame(500);

        $criticals = \array_filter(
            $handler->getRecords(),
            static fn ($record): bool => Level::Critical === $record->level,
        );
        self::assertNotEmpty($criticals);

        $logged = \implode("\n", \array_map(
            static fn ($record): string => (string) ($record->context['exception'] ?? ''),
            $criticals,
        ));
        self::assertStringContainsString('received a document without a valid "id" field', $logged);
    }

    /**
     * @param list<array<string, mixed>> $hits
     */
    private function clientServingHits(array $hits): KernelBrowser
    {
        $client = static::createClient();

        $mock = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            \json_encode(['hits' => $hits, 'offset' => 0, 'limit' => 20, 'estimatedTotalHits' => \count($hits), 'processingTimeMs' => 0, 'query' => 'dune'], JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']],
        ));
        $psr17 = new Psr17Factory();
        $engineClient = new Client('http://127.0.0.1:7700', 'test-key', new Psr18Client($mock), $psr17, [], $psr17);

        static::getContainer()->set(Client::class, $engineClient);

        return $client;
    }
}
