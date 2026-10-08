<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use App\Infrastructure\Search\MeilisearchSearchAdapter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP surface of search. Items are created through the API so the 6.4
 * write-path indexes them; indexing is asynchronous (tasks enqueue), so
 * assertions poll the endpoint until the hit surfaces or the deadline passes.
 */
final class SearchControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Self-provisioning (6.3 convention): a fresh or auto-created index
        // carries no filterableAttributes, and any filtered search on it is an
        // engine 400 surfaced as 500. The write path auto-creates indexes but
        // never pushes settings — only ensureIndexes does.
        $adapter = self::getContainer()->get(MeilisearchSearchAdapter::class);
        \assert($adapter instanceof MeilisearchSearchAdapter);
        $adapter->ensureIndexes();

        $unique = \uniqid('', true);
        $email = 'search_'.\str_replace('.', '', $unique).'@example.com';
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Search Tester',
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(200);
        $this->token = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['access_token'];
    }

    /**
     * @param array<string, string> $extra
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function waitForItemHit(string $query, array $extra = [], array $headers = []): array
    {
        $deadline = \microtime(true) + 10;

        do {
            $this->client->request('GET', '/api/search/items?'.\http_build_query(['q' => $query] + $extra), [], [], $headers);
            self::assertResponseStatusCodeSame(200);
            /** @var list<array<string, mixed>> $hits */
            $hits = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

            foreach ($hits as $hit) {
                if (($hit['name'] ?? null) === $query) {
                    return $hit;
                }
            }
            \usleep(100000);
        } while (\microtime(true) < $deadline);

        self::fail(\sprintf('Item "%s" never surfaced in search results.', $query));
    }

    private function createItem(string $name): void
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json'];

        $this->client->request('POST', '/api/collections', [], [], $headers, \json_encode([
            'name' => 'Search Coll '.\uniqid(),
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);
        $collectionId = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $this->client->request('POST', "/api/collections/{$collectionId}/items", [], [], $headers, \json_encode([
            'name' => $name,
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);
    }

    public function testGuestCanSearchItems(): void
    {
        $name = 'Guest Searchable '.\uniqid();
        $this->createItem($name);

        // No auth header on the same client: reads are public (fwd-7), and a
        // second createClient() in one test is forbidden by WebTestCase.
        $deadline = \microtime(true) + 10;

        do {
            $this->client->request('GET', '/api/search/items?'.\http_build_query(['q' => $name]));
            self::assertResponseStatusCodeSame(200);
            /** @var list<array<string, mixed>> $hits */
            $hits = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $found = false;
            foreach ($hits as $hit) {
                if (($hit['name'] ?? null) === $name) {
                    $found = true;
                    self::assertArrayHasKey('collection_name', $hit);
                    self::assertArrayHasKey('owner_id', $hit);
                    break;
                }
            }
            if ($found) {
                return;
            }
            \usleep(100000);
        } while (\microtime(true) < $deadline);

        self::fail('Guest search never returned the item.');
    }

    public function testMissingQueryIsA400(): void
    {
        $this->client->request('GET', '/api/search/items');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/search/items?'.\http_build_query(['q' => '   ']));
        self::assertResponseStatusCodeSame(400);
    }

    public function testNonScalarQueryParamIsA400(): void
    {
        $this->client->request('GET', '/api/search/items?q[]=x');
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/search/items?'.\http_build_query(['q' => 'x', 'limit' => ['a']]));
        self::assertResponseStatusCodeSame(400);
    }

    public function testOwnerMeWithoutAuthIsA401(): void
    {
        $this->client->request('GET', '/api/search/items?'.\http_build_query(['q' => 'x', 'owner' => 'me']));
        self::assertResponseStatusCodeSame(401);
    }

    public function testOwnerMeFindsOwnItems(): void
    {
        $name = 'Own Searchable '.\uniqid();
        $this->createItem($name);

        $hit = $this->waitForItemHit($name, ['owner' => 'me'], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);

        self::assertSame($name, $hit['name']);
    }

    public function testHeadOnSearchIsAllowedForGuests(): void
    {
        $this->client->request('HEAD', '/api/search/items?'.\http_build_query(['q' => 'anything']));
        self::assertResponseStatusCodeSame(200);
    }

    public function testGuestCanSearchCollections(): void
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json'];
        $name = 'Searchable Collection '.\uniqid();
        $this->client->request('POST', '/api/collections', [], [], $headers, \json_encode([
            'name' => $name,
            'theme' => 'games',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $deadline = \microtime(true) + 10;

        do {
            $this->client->request('GET', '/api/search/collections?'.\http_build_query(['q' => $name, 'theme' => 'games']));
            self::assertResponseStatusCodeSame(200);
            /** @var list<array<string, mixed>> $hits */
            $hits = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            foreach ($hits as $hit) {
                if (($hit['name'] ?? null) === $name) {
                    self::assertSame('games', $hit['theme']);

                    return;
                }
            }
            \usleep(100000);
        } while (\microtime(true) < $deadline);

        self::fail('Guest collection search never returned the collection.');
    }

    public function testInvalidThemeIsA400(): void
    {
        $this->client->request('GET', '/api/search/collections?'.\http_build_query(['q' => 'x', 'theme' => 'nope']));
        self::assertResponseStatusCodeSame(400);
    }
}
