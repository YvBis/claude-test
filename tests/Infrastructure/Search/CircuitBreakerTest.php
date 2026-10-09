<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Search;

use App\Application\Search\ItemDocument;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Infrastructure\Search\MeilisearchSearchAdapter;
use App\Infrastructure\Search\WriteCircuitBreaker;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CircuitBreakerTest extends TestCase
{
    private const TTL = 30;

    private const ORIGIN = '2026-10-08 12:00:00';

    private int $requests = 0;

    private ?MockClock $clock = null;

    public function testWriteFailureOpensTheCircuitForTheTtl(): void
    {
        $adapter = $this->adapter($this->deadEngine(), $this->breaker($this->clockAt(self::ORIGIN)));

        $adapter->indexItem($this->itemDocument());

        self::assertSame(1, $this->requests, 'the failing write is attempted once');

        $this->clock()->modify('+1 second');
        $adapter->indexItem($this->itemDocument());

        self::assertSame(
            1,
            $this->requests,
            'writes inside the TTL are skipped without touching the engine',
        );
    }

    public function testWriteIsAttemptedAgainAfterTheTtlElapsed(): void
    {
        $adapter = $this->adapter($this->deadEngine(), $this->breaker($this->clockAt(self::ORIGIN)));

        $adapter->indexItem($this->itemDocument());
        self::assertSame(1, $this->requests);

        $this->clock()->modify('+30 seconds');

        $adapter->indexItem($this->itemDocument());

        self::assertSame(
            2,
            $this->requests,
            'once the TTL elapsed the next write probes the engine again',
        );
    }

    public function testSuccessfulWriteGoesStraightThrough(): void
    {
        $adapter = $this->adapter($this->healthyEngine(), $this->breaker($this->clockAt(self::ORIGIN)));

        $adapter->indexItem($this->itemDocument());
        $adapter->indexItem($this->itemDocument());

        self::assertSame(
            2,
            $this->requests,
            'with no error there is no cooldown at all',
        );
    }

    public function testReadsAreNotBlockedByTheOpenCircuit(): void
    {
        $adapter = $this->adapter($this->deadEngine(), $this->breaker($this->clockAt(self::ORIGIN)));

        $adapter->indexItem($this->itemDocument());

        try {
            $adapter->searchItems('x', [], 20, 0);
            self::fail('a dead engine must surface the error on reads');
        } catch (ApiException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(2, $this->requests, 'the read is still attempted while writes are skipped');
    }

    public function testDeleteIsAlsoGuarded(): void
    {
        $adapter = $this->adapter($this->deadEngine(), $this->breaker($this->clockAt(self::ORIGIN)));

        $adapter->removeCollection(CollectionId::generate());
        self::assertSame(1, $this->requests);

        $adapter->removeCollection(CollectionId::generate());
        self::assertSame(1, $this->requests, 'delete is a write: guarded too');
    }

    private function clockAt(string $time): MockClock
    {
        return $this->clock = new MockClock($time);
    }

    private function clock(): MockClock
    {
        if (!$this->clock instanceof MockClock) {
            throw new \LogicException('clock not set: a test must create it first');
        }

        return $this->clock;
    }

    private function deadEngine(): MockHttpClient
    {
        return new MockHttpClient(
            function (): ?MockResponse {
                ++$this->requests;

                return new MockResponse(
                    '{"message":"boom","code":"internal"}',
                    ['http_code' => 500, 'response_headers' => ['Content-Type: application/json']],
                );
            },
        );
    }

    private function healthyEngine(): MockHttpClient
    {
        return new MockHttpClient(
            function (): ?MockResponse {
                ++$this->requests;

                return new MockResponse(
                    '{"taskUid":1}',
                    ['http_code' => 202, 'response_headers' => ['Content-Type: application/json']],
                );
            },
        );
    }

    private function adapter(MockHttpClient $http, WriteCircuitBreaker $breaker): MeilisearchSearchAdapter
    {
        $psr17 = new Psr17Factory();

        return new MeilisearchSearchAdapter(
            new Client('http://127.0.0.1:7700', 'test-key', new Psr18Client($http), $psr17, [], $psr17),
            new NullLogger(),
            'items_test',
            'collections_test',
            $breaker,
        );
    }

    private function breaker(MockClock $clock): WriteCircuitBreaker
    {
        return new WriteCircuitBreaker(self::TTL, $clock);
    }

    private function itemDocument(): ItemDocument
    {
        return new ItemDocument('item-1', 'Dune', ['epic'], 'col-1', 'Books', 'owner-1');
    }
}
