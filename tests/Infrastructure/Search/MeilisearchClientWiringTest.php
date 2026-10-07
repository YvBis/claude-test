<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Search;

use App\Application\Search\SearchIndexerInterface;
use App\Infrastructure\Search\MeilisearchSearchAdapter;
use Meilisearch\Client;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asserts the container builds the search stack in the test environment.
 *
 * This is the assertion that would have caught a discovery-based constructor:
 * a container that throws while building fails here, not at the first search.
 * It also proves the test environment resolves MEILISEARCH_* — without it the
 * `%env()%` placeholders would resolve to nothing.
 *
 * Since 6.4 the adapter injects the client, so the private client survives
 * pruning and is reachable through the test container's private locator; the
 * 6.1–6.3 bridging alias is gone.
 *
 * No assertion is made on the configured host: it is the compose service name
 * inside Docker and 127.0.0.1 on a host runner.
 */
final class MeilisearchClientWiringTest extends KernelTestCase
{
    public function testContainerBuildsTheClient(): void
    {
        self::bootKernel();

        self::assertInstanceOf(Client::class, self::getContainer()->get(Client::class));
    }

    public function testPortResolvesToTheMeilisearchAdapter(): void
    {
        self::bootKernel();

        self::assertInstanceOf(
            MeilisearchSearchAdapter::class,
            self::getContainer()->get(SearchIndexerInterface::class),
            'Without the alias in services.yaml the port is not autowirable and ItemService cannot be built.',
        );
    }

    public function testAdapterUsesTheForcedTestIndexNames(): void
    {
        self::bootKernel();

        $adapter = self::getContainer()->get(MeilisearchSearchAdapter::class);
        \assert($adapter instanceof MeilisearchSearchAdapter);

        // The adapter's arguments must be the ones from services.yaml, not its
        // constructor defaults: a definition clobbered by the resource
        // prototype would silently index into production-named indexes.
        $itemsIndex = (new \ReflectionProperty($adapter, 'itemsIndex'))->getValue($adapter);
        $collectionsIndex = (new \ReflectionProperty($adapter, 'collectionsIndex'))->getValue($adapter);

        self::assertSame('items_test', $itemsIndex);
        self::assertSame('collections_test', $collectionsIndex);
    }

    public function testTestEnvironmentResolvesTheMeilisearchVariables(): void
    {
        self::assertNotFalse(
            \getenv('MEILISEARCH_URL'),
            'MEILISEARCH_URL must reach the test environment (phpunit.xml.dist supplies it when nothing else did).',
        );
        self::assertNotFalse(\getenv('MEILISEARCH_KEY'));
    }
}
