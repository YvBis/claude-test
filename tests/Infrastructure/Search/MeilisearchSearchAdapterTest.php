<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Search;

use App\Application\Search\CollectionDocument;
use App\Application\Search\ItemDocument;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Item\ValueObject\ItemId;
use App\Infrastructure\Search\IndexSettings;
use App\Infrastructure\Search\MeilisearchSearchAdapter;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Contract tests for the Meilisearch adapter, with the engine mocked out.
 *
 * These prove two things and no more: that the adapter swallows engine
 * failures (fail-open) and that it sends the right request to the right
 * endpoint with the right payload. They do NOT prove that Meilisearch v1.54
 * accepts those payloads or settings — that check needs the real engine and
 * belongs to 6.3. Mock-passing is a contract scaffold, not engine proof.
 *
 * The mock models the engine's *asynchronous* shape, which is load-bearing for
 * `ensureIndexes()`: creating an index answers 202 and enqueues a task, and a
 * duplicate fails that task (not the HTTP call) with `index_already_exists`.
 * This was measured against v1.54.3, not assumed.
 *
 * The adapter is private and unreferenced until 6.4, so it is pruned from the
 * container; it is constructed by hand here rather than fetched, and there is
 * no wiring test for it.
 */
final class MeilisearchSearchAdapterTest extends TestCase
{
    private const URL = 'http://127.0.0.1:7700';

    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    private AbstractLogger $logger;

    /**
     * @param 'succeeded'|'failed'                                              $taskStatus outcome of the indexCreation task
     * @param \Closure(string, string, array<string, mixed>): MockResponse|null $override   bypasses the default routing
     */
    private function createAdapter(string $taskStatus = 'succeeded', ?string $taskErrorCode = null, ?\Closure $override = null): MeilisearchSearchAdapter
    {
        $taskCounter = 0;

        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$taskCounter, $taskStatus, $taskErrorCode, $override): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $this->bodyOf($options)];

            if ($override instanceof \Closure) {
                return $override($method, $url, $options);
            }

            if ('POST' === $method && \str_ends_with($url, '/indexes')) {
                return $this->jsonResponse(['taskUid' => ++$taskCounter, 'status' => 'enqueued'], 202);
            }

            if ('GET' === $method && \str_contains($url, '/tasks/')) {
                return $this->jsonResponse([
                    'uid' => $taskCounter,
                    'status' => $taskStatus,
                    'type' => 'indexCreation',
                    'error' => 'failed' === $taskStatus
                        ? ['message' => 'Index already exists.', 'code' => $taskErrorCode]
                        : null,
                ], 200);
            }

            return $this->jsonResponse(['taskUid' => 1, 'status' => 'enqueued'], 202);
        });

        $psr17 = new Psr17Factory();
        $client = new Client(self::URL, 'test-key', new Psr18Client($mock), $psr17, [], $psr17);

        $this->logger = new class () extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $logs = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        return new MeilisearchSearchAdapter($client, $this->logger);
    }

    private function itemDocument(): ItemDocument
    {
        return new ItemDocument(
            id: 'item-id',
            name: 'Halo 3',
            tags: ['Games'],
            collectionId: 'collection-id',
            collectionName: 'Backlog',
            ownerId: 'owner-id',
        );
    }

    public function testIndexItemPostsTheDocument(): void
    {
        $document = $this->itemDocument();
        $this->createAdapter()->indexItem($document);

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertStringContainsString('/indexes/items/documents', $this->requests[0]['url']);
        // addDocuments takes a batch, so the payload is a one-element list.
        self::assertSame([$document->toArray()], \json_decode($this->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testRemoveItemDeletesByDocumentId(): void
    {
        $id = ItemId::generate();

        $this->createAdapter()->removeItem($id);

        self::assertSame('DELETE', $this->requests[0]['method']);
        self::assertStringContainsString('/indexes/items/documents/'.$id->toString(), $this->requests[0]['url']);
    }

    public function testIndexCollectionPostsToTheCollectionsIndex(): void
    {
        $document = new CollectionDocument(
            id: 'collection-id',
            name: 'Backlog',
            theme: 'games',
            description: '',
            ownerId: 'owner-id',
        );
        $this->createAdapter()->indexCollection($document);

        self::assertStringContainsString('/indexes/collections/documents', $this->requests[0]['url']);
        self::assertSame([$document->toArray()], \json_decode($this->requests[0]['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testRemoveCollectionDeletesFromTheCollectionsIndex(): void
    {
        $this->createAdapter()->removeCollection(CollectionId::generate());

        self::assertSame('DELETE', $this->requests[0]['method']);
        self::assertStringContainsString('/indexes/collections/documents/', $this->requests[0]['url']);
    }

    public function testEnsureIndexesCreatesBothIndexesAndPushesSettings(): void
    {
        $this->createAdapter()->ensureIndexes();

        $settingsRequests = \array_values(\array_filter(
            $this->requests,
            static fn (array $r): bool => \str_contains($r['url'], '/settings'),
        ));
        self::assertCount(2, $settingsRequests, 'Settings must be pushed for both indexes.');

        $payloads = [];
        foreach ($settingsRequests as $request) {
            $payloads[] = \json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
        }

        self::assertContains(IndexSettings::ITEMS, $payloads);
        self::assertContains(IndexSettings::COLLECTIONS, $payloads);
    }

    public function testEnsureIndexesToleratesAnAlreadyExistingIndex(): void
    {
        $this->createAdapter('failed', 'index_already_exists')->ensureIndexes();

        $settingsRequests = \array_filter(
            $this->requests,
            static fn (array $r): bool => \str_contains($r['url'], '/settings'),
        );
        self::assertCount(2, $settingsRequests, 'Settings must still be pushed after a tolerated already-exists.');
    }

    public function testEnsureIndexesIsLoudOnARealTaskFailure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid_index_uid/');

        $this->createAdapter('failed', 'invalid_index_uid')->ensureIndexes();
    }

    public function testIndexItemSwallowsAnEngineError(): void
    {
        $this->createAdapter(override: static fn (): MockResponse => new MockResponse(
            '{"message":"boom","code":"internal","type":"internal"}',
            ['http_code' => 500, 'response_headers' => ['Content-Type: application/json']],
        ))->indexItem($this->itemDocument());

        self::assertCount(1, $this->requests);
        self::assertCount(1, $this->logger->logs, 'The swallowed failure must be logged, not silent.');
        self::assertInstanceOf(
            ApiException::class,
            $this->logger->logs[0]['context']['exception'] ?? null,
            'The logged exception must be the API error, not something else that also logs once.',
        );
    }

    public function testIndexItemSwallowsATransportFailure(): void
    {
        $this->createAdapter(override: static function (): never {
            throw new TransportException('connection refused');
        })->indexItem($this->itemDocument());

        self::assertCount(
            1,
            $this->logger->logs,
            'A transport failure must be swallowed and logged.',
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonResponse(array $body, int $status): MockResponse
    {
        return new MockResponse(\json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['Content-Type: application/json'],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function bodyOf(array $options): string
    {
        $body = $options['body'] ?? '';

        if (\is_resource($body)) {
            return (string) \stream_get_contents($body);
        }

        return (string) $body;
    }
}
