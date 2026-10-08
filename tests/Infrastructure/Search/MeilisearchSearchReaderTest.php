<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Search;

use App\Application\Search\CollectionSearchHit;
use App\Application\Search\ItemSearchHit;
use App\Infrastructure\Search\MeilisearchSearchAdapter;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MeilisearchSearchReaderTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: array<string, mixed>}> */
    private array $requests = [];

    private function createAdapter(?callable $responder = null): MeilisearchSearchAdapter
    {
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use ($responder): MockResponse {
            $body = $options['body'] ?? '';
            if (\is_resource($body)) {
                $body = (string) \stream_get_contents($body);
            }

            /** @var array<string, mixed> $decoded */
            $decoded = \json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $decoded];

            if (null !== $responder) {
                return $responder($method, $url);
            }

            return new MockResponse(
                \json_encode(['hits' => [], 'offset' => 0, 'limit' => 20, 'estimatedTotalHits' => 0, 'processingTimeMs' => 0, 'query' => ''], JSON_THROW_ON_ERROR),
                ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']],
            );
        });

        return new MeilisearchSearchAdapter(
            self::clientFor($mock),
            new NullLogger(),
            'items_test',
            'collections_test',
        );
    }

    private static function clientFor(MockHttpClient $mock): Client
    {
        $psr17 = new Psr17Factory();

        return new Client('http://127.0.0.1:7700', 'test-key', new Psr18Client($mock), $psr17, [], $psr17);
    }

    public function testSearchItemsPostsQueryFilterAndPagination(): void
    {
        $this->createAdapter()->searchItems(
            'dune',
            ['owner_id' => 'o1', 'collection_id' => 'c1', 'tags' => ['a', 'b']],
            20,
            5,
        );

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertStringContainsString('/indexes/items_test/search', $this->requests[0]['url']);
        self::assertSame('dune', $this->requests[0]['body']['q']);
        self::assertSame(20, $this->requests[0]['body']['limit']);
        self::assertSame(5, $this->requests[0]['body']['offset']);
        self::assertSame(
            'owner_id = "o1" AND collection_id = "c1" AND tags = "a" AND tags = "b"',
            $this->requests[0]['body']['filter'],
        );
    }

    public function testSearchItemsMapsHitsToDtos(): void
    {
        $doc = ['id' => 'i1', 'name' => 'Dune', 'tags' => ['sci-fi'], 'collection_id' => 'c1', 'collection_name' => 'Books', 'owner_id' => 'o1'];
        $adapter = $this->createAdapter(
            static fn (): MockResponse => new MockResponse(
                \json_encode(['hits' => [$doc], 'offset' => 0, 'limit' => 20, 'estimatedTotalHits' => 1, 'processingTimeMs' => 0, 'query' => 'dune'], JSON_THROW_ON_ERROR),
                ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']],
            ),
        );

        $hits = $adapter->searchItems('dune', [], 20, 0);

        self::assertCount(1, $hits);
        self::assertInstanceOf(ItemSearchHit::class, $hits[0]);
        self::assertSame($doc, $hits[0]->toArray());
    }

    public function testSearchCollectionsPostsToTheCollectionsIndex(): void
    {
        $this->createAdapter()->searchCollections('books', ['owner_id' => 'o1', 'theme' => 'books'], 10, 0);

        self::assertStringContainsString('/indexes/collections_test/search', $this->requests[0]['url']);
        self::assertSame('owner_id = "o1" AND theme = "books"', $this->requests[0]['body']['filter']);
    }

    public function testUnknownFilterKeysAreDropped(): void
    {
        $this->createAdapter()->searchItems('x', ['owner_id' => 'o1', 'injected' => 'y'], 20, 0);

        self::assertSame('owner_id = "o1"', $this->requests[0]['body']['filter']);
    }

    public function testBackslashAndQuoteInTagAreEscapedNotStripped(): void
    {
        // A tag ending in a backslash used to swallow the closing quote and
        // turn a valid-string tag into an engine 400 (surfaced as 500).
        $this->createAdapter()->searchItems('x', ['tags' => ['a"b', 'c\\']], 20, 0);

        self::assertSame('tags = "a\\"b" AND tags = "c\\\\"', $this->requests[0]['body']['filter']);
    }

    public function testEngineFailurePropagatesInsteadOfFailingOpen(): void
    {
        $adapter = $this->createAdapter(
            static fn (): MockResponse => new MockResponse(
                '{"message":"boom","code":"internal","type":"internal"}',
                ['http_code' => 500, 'response_headers' => ['Content-Type: application/json']],
            ),
        );

        // Reads cannot fail open: there is nothing to return. The adapter must
        // not catch, so the error reaches kernel.exception as a 500.
        $this->expectException(ApiException::class);

        $adapter->searchItems('dune', [], 20, 0);
    }

    public function testHitDtosRoundTrip(): void
    {
        $item = ItemSearchHit::fromArray(['id' => 'i', 'name' => 'n', 'tags' => ['t'], 'collection_id' => 'c', 'collection_name' => 'cn', 'owner_id' => 'o']);
        self::assertSame(
            ['id' => 'i', 'name' => 'n', 'tags' => ['t'], 'collection_id' => 'c', 'collection_name' => 'cn', 'owner_id' => 'o'],
            $item->toArray(),
        );

        $collection = CollectionSearchHit::fromArray(['id' => 'c', 'name' => 'n', 'theme' => 'books', 'description' => 'd', 'owner_id' => 'o']);
        self::assertSame(
            ['id' => 'c', 'name' => 'n', 'theme' => 'books', 'description' => 'd', 'owner_id' => 'o'],
            $collection->toArray(),
        );
    }
}
