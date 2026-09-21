<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ItemControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $token;
    private string $userId;
    /** @var list<string> */
    private array $extraUserIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->registerAndLogin();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $conn = $em->getConnection();

        $ids = \array_merge([$this->userId], $this->extraUserIds);
        foreach ($ids as $id) {
            $conn->executeStatement(
                'DELETE FROM users WHERE id = UNHEX(REPLACE(?, "-", ""))',
                [\preg_replace('/-/', '', $id)]
            );
        }

        $em->clear();
        parent::tearDown();
    }

    private function registerAndLogin(): void
    {
        $unique = \uniqid('', true);
        $email = 'item_'.\str_replace('.', '', $unique).'@example.com';

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Item Tester',
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $this->userId = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $this->token = $this->login($email);
    }

    private function login(string $email): string
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(200);

        return \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['access_token'];
    }

    private function registerUser(): array
    {
        $unique = \uniqid('', true);
        $email = 'item_'.\str_replace('.', '', $unique).'_x@example.com';

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Item Tester 2',
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $id = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
        $this->extraUserIds[] = $id;

        return ['token' => $this->login($email), 'id' => $id];
    }

    private function authHeaders(?string $token = null): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->token),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function createCollection(?string $token = null): string
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders($token), \json_encode([
            'name' => 'Item Coll',
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        return \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createItem(string $collectionId, array $overrides = [], ?string $token = null): string
    {
        $payload = \array_merge([
            'name' => '1984',
            'tags' => ['scifi', 'dystopia'],
            'slots' => [['type' => 'text', 'slot' => 1, 'value' => 'Author Name']],
        ], $overrides);

        $this->client->request('POST', '/api/collections/'.$collectionId.'/items', [], [], $this->authHeaders($token), \json_encode($payload, \JSON_THROW_ON_ERROR));

        return \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
    }

    public function testCreateReturns201(): void
    {
        $collectionId = $this->createCollection();

        $this->client->request('POST', '/api/collections/'.$collectionId.'/items', [], [], $this->authHeaders(), \json_encode([
            'name' => '1984',
            'tags' => ['Sci-fi', 'Dystopia'],
            'slots' => [
                ['type' => 'text', 'slot' => 1, 'value' => 'George Orwell'],
                ['type' => 'number', 'slot' => 1, 'value' => 99.5],
                ['type' => 'date', 'slot' => 1, 'value' => '1949-06-08T00:00:00Z'],
                ['type' => 'bool', 'slot' => 1, 'value' => true],
            ],
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(201);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('1984', $data['name']);
        $this->assertSame($collectionId, $data['collection_id']);
        $this->assertCount(2, $data['tags']);
        $this->assertCount(4, $data['slots']);
        $this->assertSame(['type' => 'text', 'slot' => 1, 'value' => 'George Orwell'], $data['slots'][0]);
    }

    public function testCreateWithoutAuthReturns401(): void
    {
        $this->client->request('POST', '/api/collections/018f0a1b-2c3d-4e5f-6789-0123456789ab/items', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => '1984',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateInForeignCollectionReturns403(): void
    {
        $ownerCollectionId = $this->createCollection();
        $foreign = $this->registerUser();

        $this->client->request('POST', '/api/collections/'.$ownerCollectionId.'/items', [], [], $this->authHeaders($foreign['token']), \json_encode([
            'name' => '1984',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateInNonExistentCollectionReturns404(): void
    {
        $this->client->request('POST', '/api/collections/018f0a1b-2c3d-4e5f-6789-0123456789ab/items', [], [], $this->authHeaders(), \json_encode([
            'name' => '1984',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateWithInvalidSlotReturns422(): void
    {
        $collectionId = $this->createCollection();

        $this->client->request('POST', '/api/collections/'.$collectionId.'/items', [], [], $this->authHeaders(), \json_encode([
            'name' => '1984',
            'slots' => [['type' => 'date', 'slot' => 1, 'value' => 'not-a-date']],
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(422);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Unprocessable Entity', $data['error']);
    }

    public function testCreateWithValidationErrorReturns422(): void
    {
        $collectionId = $this->createCollection();

        $this->client->request('POST', '/api/collections/'.$collectionId.'/items', [], [], $this->authHeaders(), \json_encode([
            'name' => '',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(422);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Validation failed', $data['error']);
    }

    public function testListByCollectionReturns200(): void
    {
        $collectionId = $this->createCollection();
        $this->createItem($collectionId);

        $this->client->request('GET', '/api/collections/'.$collectionId.'/items', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data);
        $this->assertSame('1984', $data[0]['name']);
    }

    public function testListByCollectionWithoutAuthReturns401(): void
    {
        $this->client->request('GET', '/api/collections/018f0a1b-2c3d-4e5f-6789-0123456789ab/items');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListByCollectionForeignReturns200(): void
    {
        $collectionId = $this->createCollection();
        $this->createItem($collectionId, ['name' => 'Brave New World']);
        $foreign = $this->registerUser();

        $this->client->request('GET', '/api/collections/'.$collectionId.'/items', [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data);
        $this->assertSame('Brave New World', $data[0]['name']);
    }

    public function testListByCollectionFiltersByName(): void
    {
        $collectionId = $this->createCollection();
        $this->createItem($collectionId, ['name' => 'Brave New World']);
        $this->createItem($collectionId, ['name' => 'Don Quixote']);

        $this->client->request('GET', '/api/collections/'.$collectionId.'/items?name=brave', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data);
        $this->assertSame('Brave New World', $data[0]['name']);
    }

    public function testListByCollectionFiltersByTagsWithAndSemantics(): void
    {
        $collectionId = $this->createCollection();
        $this->createItem($collectionId, ['name' => 'Brave New World', 'tags' => ['scifi', 'classic']]);
        $this->createItem($collectionId, ['name' => '1984', 'tags' => ['scifi', 'dystopia']]);
        $this->createItem($collectionId, ['name' => 'Don Quixote', 'tags' => ['classic']]);

        $this->client->request('GET', '/api/collections/'.$collectionId.'/items?tags[]=scifi&tags[]=dystopia', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data);
        $this->assertSame('1984', $data[0]['name']);
    }

    public function testListOwnReturnsOnlyOwnItems(): void
    {
        $myCollection = $this->createCollection();
        $this->createItem($myCollection);

        $foreign = $this->registerUser();
        $foreignCollection = $this->createCollection($foreign['token']);
        $this->createItem($foreignCollection, [], $foreign['token']);

        $this->client->request('GET', '/api/items', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data);
    }

    public function testListOwnWithoutAuthReturns401(): void
    {
        $this->client->request('GET', '/api/items');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListOwnFiltersByNameAndTags(): void
    {
        $collection = $this->createCollection();
        $this->createItem($collection, ['name' => 'Brave New World', 'tags' => ['scifi', 'classic']]);
        $this->createItem($collection, ['name' => 'Don Quixote', 'tags' => ['classic']]);

        $this->client->request('GET', '/api/items?name=brave&tags[]=scifi', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data);
        $this->assertSame('Brave New World', $data[0]['name']);
    }

    public function testListOwnWithInvalidLimitReturns400(): void
    {
        $this->client->request('GET', '/api/items?limit=abc', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Bad Request', $data['error']);
        $this->assertSame('Invalid query parameters', $data['message']);
        $this->assertNotEmpty($data['details']);
    }

    public function testListByCollectionWithNegativeOffsetReturns400(): void
    {
        $collectionId = $this->createCollection();

        $this->client->request('GET', '/api/collections/'.$collectionId.'/items?offset=-1', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(400);
    }

    public function testGetInvalidUuidReturns404(): void
    {
        $this->client->request('GET', '/api/items/not-a-uuid', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(404);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(['error' => 'Not Found', 'message' => 'Item not found'], $data);
    }

    public function testGetReturns200(): void
    {
        $collectionId = $this->createCollection();
        $itemId = $this->createItem($collectionId);

        $this->client->request('GET', '/api/items/'.$itemId, [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('1984', $data['name']);
        $this->assertSame(0, $data['likes_count']);
        $this->assertSame(0, $data['comments_count']);
        $this->assertFalse($data['liked_by_me']);
    }

    public function testGetReturnsCounters(): void
    {
        $collectionId = $this->createCollection();
        $itemId = $this->createItem($collectionId);

        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders());
        $this->assertResponseStatusCodeSame(200);

        $this->client->request('POST', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders(), \json_encode([
            'content' => 'note',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/items/'.$itemId, [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['likes_count']);
        $this->assertSame(1, $data['comments_count']);
        $this->assertTrue($data['liked_by_me']);
    }

    public function testGetForeignItemReportsNotLikedByMe(): void
    {
        $collectionId = $this->createCollection();
        $itemId = $this->createItem($collectionId);

        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders());
        $this->assertResponseStatusCodeSame(200);

        $foreign = $this->registerUser();

        $this->client->request('GET', '/api/items/'.$itemId, [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['likes_count']);
        $this->assertFalse($data['liked_by_me']);
    }

    public function testGetForeignReturns200(): void
    {
        $collectionId = $this->createCollection();
        $itemId = $this->createItem($collectionId);
        $foreign = $this->registerUser();

        $this->client->request('GET', '/api/items/'.$itemId, [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('1984', $data['name']);
    }

    public function testGetNonExistentReturns404(): void
    {
        $this->client->request('GET', '/api/items/018f0a1b-2c3d-4e5f-6789-0123456789ab', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateReturns200(): void
    {
        $collectionId = $this->createCollection();
        $itemId = $this->createItem($collectionId);

        $this->client->request('PATCH', '/api/items/'.$itemId, [], [], $this->authHeaders(), \json_encode([
            'name' => 'Nineteen Eighty-Four',
            'slots' => [['type' => 'text', 'slot' => 1, 'value' => null]],
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Nineteen Eighty-Four', $data['name']);
        $this->assertCount(0, $data['slots']);
    }

    public function testUpdateEmptyBodyReturns422(): void
    {
        $collectionId = $this->createCollection();
        $itemId = $this->createItem($collectionId);

        $this->client->request('PATCH', '/api/items/'.$itemId, [], [], $this->authHeaders(), \json_encode([], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(422);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Unprocessable Entity', $data['error']);
        $this->assertSame('At least one field must be provided for update', $data['message']);
    }

    public function testUpdateInvalidSlotReturns422(): void
    {
        $collectionId = $this->createCollection();
        $itemId = $this->createItem($collectionId);

        $this->client->request('PATCH', '/api/items/'.$itemId, [], [], $this->authHeaders(), \json_encode([
            'slots' => [['type' => 'number', 'slot' => 1, 'value' => 'not-a-number']],
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(422);
    }

    public function testCreateWithMalformedBodyReturns400(): void
    {
        $collectionId = $this->createCollection();

        $this->client->request('POST', '/api/collections/'.$collectionId.'/items', [], [], $this->authHeaders(), '{not-json');

        $this->assertResponseStatusCodeSame(400);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Bad Request', $data['error']);
    }

    public function testUpdateWithMalformedBodyReturns400(): void
    {
        $collectionId = $this->createCollection();
        $itemId = $this->createItem($collectionId);

        $this->client->request('PATCH', '/api/items/'.$itemId, [], [], $this->authHeaders(), '{not-json');

        $this->assertResponseStatusCodeSame(400);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Bad Request', $data['error']);
    }

    public function testUpdateForeignReturns403(): void
    {
        $collectionId = $this->createCollection();
        $itemId = $this->createItem($collectionId);
        $foreign = $this->registerUser();

        $this->client->request('PATCH', '/api/items/'.$itemId, [], [], $this->authHeaders($foreign['token']), \json_encode([
            'name' => 'Hacked',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUpdateNonExistentReturns404(): void
    {
        $this->client->request('PATCH', '/api/items/018f0a1b-2c3d-4e5f-6789-0123456789ab', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Ghost',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUpdateWithMalformedIdReturns404(): void
    {
        $this->client->request('PATCH', '/api/items/not-a-uuid', [], [], $this->authHeaders(), \json_encode([
            'name' => 'Ghost',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteWithMalformedIdReturns404(): void
    {
        $this->client->request('DELETE', '/api/items/not-a-uuid', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteNonExistentReturns404(): void
    {
        $this->client->request('DELETE', '/api/items/018f0a1b-2c3d-4e5f-6789-0123456789ab', [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteReturns204(): void
    {
        $collectionId = $this->createCollection();
        $itemId = $this->createItem($collectionId);

        $this->client->request('DELETE', '/api/items/'.$itemId, [], [], $this->authHeaders());

        $this->assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/items/'.$itemId, [], [], $this->authHeaders());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteForeignReturns403(): void
    {
        $collectionId = $this->createCollection();
        $itemId = $this->createItem($collectionId);
        $foreign = $this->registerUser();

        $this->client->request('DELETE', '/api/items/'.$itemId, [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesForeignItem(): void
    {
        $collectionId = $this->createCollection();
        $itemId = $this->createItem($collectionId);
        $foreign = $this->registerUser();

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->getConnection()->executeStatement(
            'UPDATE users SET role = "admin" WHERE id = UNHEX(REPLACE(?, "-", ""))',
            [\preg_replace('/-/', '', $foreign['id'])]
        );

        $this->client->request('GET', '/api/items/'.$itemId, [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(200);
    }
}
