<?php

declare(strict_types=1);

namespace App\Infrastructure\Search;

use App\Application\Search\CollectionDocument;
use App\Application\Search\ItemDocument;
use App\Application\Search\SearchIndexerInterface;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Item\ValueObject\ItemId;
use Meilisearch\Client;
use Meilisearch\Exceptions\ExceptionInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Meilisearch implementation of the indexing port.
 *
 * Fail-open lives here, not in the interface: an engine outage must not break
 * item or collection writes, but that is a Meilisearch-deployment property, and
 * putting it in the port would silently impose it on any future implementation.
 * The failure is logged — fail-open must not be silent.
 *
 * What is caught, precisely: `Meilisearch\Exceptions\ExceptionInterface` covers
 * API-level errors, and PSR-18 `ClientExceptionInterface` covers transport
 * failures (Psr18Client wraps its own transport errors into a PSR-18 network
 * exception). `\Throwable` is deliberately NOT caught — a programming error
 * (a bad argument, a type error) must surface, or 6.5's bulk command would
 * report success while doing nothing.
 *
 * `addDocuments`/`deleteDocument` enqueue an engine task and return
 * immediately. "Synchronous" in ADR-0001 means "from the PHP process, with no
 * broker", not "awaits task completion": the engine serializes tasks, so a
 * delete followed by a re-add is ordered without `waitForTask`. Waiting here
 * would put engine latency in the request path for no benefit.
 *
 * The constructor performs no I/O, so building this service at container
 * compile time is safe (see 6.1: the diagnostics gate boots without the engine).
 */
final readonly class MeilisearchSearchAdapter implements SearchIndexerInterface
{
    /**
     * Provisioning waits for an engine task, so the budget is generous: it runs
     * from the console and from CI, never in a request path.
     */
    private const int PROVISION_TIMEOUT_MS = 30000;

    public function __construct(
        private Client $client,
        private LoggerInterface $logger,
        private string $itemsIndex = 'items',
        private string $collectionsIndex = 'collections',
    ) {
    }

    public function indexItem(ItemDocument $document): void
    {
        $this->write(fn () => $this->client->index($this->itemsIndex)->addDocuments([$document->toArray()], 'id'));
    }

    public function removeItem(ItemId $id): void
    {
        $this->write(fn () => $this->client->index($this->itemsIndex)->deleteDocument($id->toString()));
    }

    public function indexCollection(CollectionDocument $document): void
    {
        $this->write(fn () => $this->client->index($this->collectionsIndex)->addDocuments([$document->toArray()], 'id'));
    }

    public function removeCollection(CollectionId $id): void
    {
        $this->write(fn () => $this->client->index($this->collectionsIndex)->deleteDocument($id->toString()));
    }

    /**
     * Creates both indexes and pushes their settings. Not part of the port:
     * this is provisioning, called by the console command and by CI in 6.3.
     *
     * Deliberately NOT fail-open: provisioning must fail loudly so a broken
     * index setup stops the job instead of leaving search silently degraded.
     * Re-running is safe — an already-existing index is tolerated, and settings
     * are idempotent.
     *
     * `index_already_exists` is NOT a synchronous HTTP error. Measured against
     * v1.54.3: a duplicate `POST /indexes` answers 202 and enqueues an
     * `indexCreation` task that later fails with that code — so the create call
     * must be waited on, or the tolerance would never fire and provisioning
     * would silently report success. Waiting also removes the race between
     * index creation and the settings PATCH that follows it.
     */
    public function ensureIndexes(): void
    {
        foreach ([$this->itemsIndex => IndexSettings::ITEMS, $this->collectionsIndex => IndexSettings::COLLECTIONS] as $uid => $settings) {
            $task = $this->client->createIndex($uid, ['primaryKey' => 'id']);
            $this->tolerateExistingIndex($this->client->waitForTask($task['taskUid'], self::PROVISION_TIMEOUT_MS));

            $this->client->index($uid)->updateSettings($settings);
        }
    }

    /**
     * @param array{status: string, error?: array{message?: string, code?: string}|null} $task
     */
    private function tolerateExistingIndex(array $task): void
    {
        if ('failed' !== $task['status']) {
            return;
        }

        $code = $task['error']['code'] ?? null;
        if ('index_already_exists' !== $code) {
            throw new \RuntimeException(\sprintf(
                'Index creation failed: %s (code: %s)',
                $task['error']['message'] ?? 'unknown error',
                $code ?? 'none',
            ));
        }
    }

    private function write(\Closure $operation): void
    {
        try {
            $operation();
        } catch (ExceptionInterface|ClientExceptionInterface $e) {
            $this->logger->warning('Search index write failed', ['exception' => $e]);
        }
    }
}
