<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Search;

use App\Infrastructure\Search\MeilisearchSearchAdapter;
use Meilisearch\Client;
use Meilisearch\Exceptions\ExceptionInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class for tests that run against a real Meilisearch engine.
 *
 * The DB half of the suite is isolated by DAMA (transactions + rollback); the
 * search half has no equivalent, so every engine-backed test starts from empty
 * indexes: `setUp()` provisions both test indexes idempotently and wipes their
 * documents, awaiting each task. The indexes themselves survive between test
 * classes — only their contents are reset.
 *
 * The adapter is constructed by hand, not fetched: it is private and
 * unreferenced until 6.4 wires the port, so the container prunes it. The
 * engine client comes through the `test.meilisearch_client` alias (kept for
 * exactly this use until 6.4 removes it).
 *
 * Index names come from the forced `MEILISEARCH_*_INDEX` phpunit vars
 * (`items_test`/`collections_test`): engine-backed tests never touch the
 * production-named indexes. The engine must be up — locally `docker compose
 * up -d`, in CI the job service. With the engine stopped these tests fail;
 * that is the documented dependency, not a bootstrap problem.
 */
abstract class EngineBackedSearchTestCase extends KernelTestCase
{
    protected Client $client;

    protected MeilisearchSearchAdapter $adapter;

    protected string $itemsIndex;

    protected string $collectionsIndex;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->itemsIndex = (string) \getenv('MEILISEARCH_ITEMS_INDEX');
        $this->collectionsIndex = (string) \getenv('MEILISEARCH_COLLECTIONS_INDEX');

        // A missing var would surface as createIndex('') deep in the engine
        // client; fail here with a message that names the var instead.
        self::assertNotEmpty($this->itemsIndex, 'MEILISEARCH_ITEMS_INDEX must be set (forced in phpunit.xml.dist).');
        self::assertNotEmpty($this->collectionsIndex, 'MEILISEARCH_COLLECTIONS_INDEX must be set (forced in phpunit.xml.dist).');

        $this->client = self::getContainer()->get('test.meilisearch_client');
        \assert($this->client instanceof Client);

        $this->adapter = new MeilisearchSearchAdapter(
            $this->client,
            new NullLogger(),
            $this->itemsIndex,
            $this->collectionsIndex,
        );

        $this->adapter->ensureIndexes();
        $this->wipeIndex($this->itemsIndex);
        $this->wipeIndex($this->collectionsIndex);
    }

    private function wipeIndex(string $index): void
    {
        $task = $this->client->index($index)->deleteAllDocuments();
        $this->client->waitForTask($task['taskUid']);
    }

    /**
     * Reads a document, waiting for the engine to apply the enqueued task.
     * The adapter returns void and discards the task uid, so an immediate read
     * would race the engine; polling with a deadline is the honest wait here.
     *
     * @return array<string, mixed>
     */
    protected function waitForDocument(string $index, string $id): array
    {
        $deadline = \microtime(true) + 5.0;

        do {
            try {
                /** @var array<string, mixed> $document */
                $document = $this->client->index($index)->getDocument($id);

                return $document;
            } catch (ExceptionInterface) {
                // Missing document (ApiException) and transport blips
                // (CommunicationException) both retry until the deadline; a
                // dead engine then fails with the message below, not with a
                // transport stack trace.
                \usleep(50_000);
            }
        } while (\microtime(true) < $deadline);

        self::fail(\sprintf('Document %s did not appear in index %s within 5 s.', $id, $index));
    }

    /**
     * Reads index settings, waiting for the settings task to land. Same
     * reasoning as waitForDocument: ensureIndexes() awaits creation, not the
     * settings push that follows it.
     *
     * @param array<string, mixed> $expected
     */
    protected function waitForSettings(string $index, array $expected): void
    {
        $deadline = \microtime(true) + 5.0;

        do {
            $settings = $this->client->index($index)->getSettings();
            $matches = true;
            foreach ($expected as $key => $value) {
                if (($settings[$key] ?? null) !== $value) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return;
            }

            \usleep(50_000);
        } while (\microtime(true) < $deadline);

        self::fail(\sprintf('Settings of index %s did not converge within 5 s.', $index));
    }
}
