<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Search;

use Meilisearch\Client;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asserts the container can build the Meilisearch client in the test
 * environment.
 *
 * This is the assertion that would have caught a discovery-based constructor:
 * a container that throws while building fails here, not at the first search.
 * It also proves the test environment resolves MEILISEARCH_* — without it the
 * `%env()%` placeholders would resolve to nothing and the service would be
 * built against an empty URL.
 *
 * The client is private in every environment. A public alias under a distinct id
 * in `when@test` makes it reachable here: the alias marks the target as
 * connected, so the removal pass keeps a service that nothing consumes yet,
 * without a production-public vendor service. Drop the alias in 6.4, when the
 * adapter is wired to the port and becomes a real consumer.
 *
 * No assertion is made on the configured host: it is the compose service name
 * inside Docker and 127.0.0.1 on a host runner.
 */
final class MeilisearchClientWiringTest extends KernelTestCase
{
    public function testContainerBuildsTheClient(): void
    {
        self::bootKernel();

        // Fetched through the test-only alias declared in
        // config/packages/meilisearch.yaml: the client itself stays private, and
        // the alias is what keeps it from being removed while nothing consumes
        // it yet.
        $client = self::getContainer()->get('test.meilisearch_client');

        self::assertInstanceOf(Client::class, $client);
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
