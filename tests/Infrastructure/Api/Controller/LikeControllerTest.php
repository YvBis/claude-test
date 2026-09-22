<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LikeControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var array<string> */
    private array $extraUserIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @return array{token: string, id: string}
     */
    private function registerUser(): array
    {
        $unique = \uniqid('', true);
        $email = 'like_'.\str_replace('.', '', $unique).'_x@example.com';

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Like Tester',
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $id = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
        $this->extraUserIds[] = $id;

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(200);

        $token = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['access_token'];

        return ['token' => $token, 'id' => $id];
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(?string $token = null): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ];
    }

    private function createCollection(?string $token = null): string
    {
        $token ??= $this->registerUser()['token'];

        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders($token), \json_encode([
            'name' => 'Like Collection',
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        return \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
    }

    private function createItem(string $collectionId, string $token): string
    {
        $this->client->request('POST', '/api/collections/'.$collectionId.'/items', [], [], $this->authHeaders($token), \json_encode([
            'name' => '1984',
            'tags' => [],
            'slots' => [],
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        return \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
    }

    public function testLikeReturns200AndCount(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['likes_count']);
    }

    public function testLikeIsIdempotent(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));
        $this->assertResponseStatusCodeSame(200);

        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));
        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['likes_count']);
    }

    public function testForeignUserCanLikeItem(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $foreign = $this->registerUser();

        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(1, $data['likes_count']);
    }

    public function testLikeWithoutAuthReturns401(): void
    {
        $this->client->request('POST', '/api/items/018f0a1b-2c3d-4e5f-6789-0123456789ab/likes');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLikeNonExistentItemReturns404(): void
    {
        $user = $this->registerUser();

        $this->client->request('POST', '/api/items/018f0a1b-2c3d-4e5f-6789-0123456789ab/likes', [], [], $this->authHeaders($user['token']));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnlikeReturns204(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));
        $this->assertResponseStatusCodeSame(200);

        $this->client->request('DELETE', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(204);
    }

    public function testUnlikeMissingLikeReturns204(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('DELETE', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(204);
    }

    public function testListByItemReturnsLikeWithOwnerName(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));
        $this->assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data);
        $this->assertSame('Like Tester', $data[0]['owner_name']);
    }

    public function testListByItemForeignUserCanRead(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $foreign = $this->registerUser();

        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));
        $this->assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data);
    }

    public function testListByItemEmptyReturnsEmptyArray(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('GET', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame([], \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testDeleteOwnLikeByIdReturns204(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));
        $this->assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));
        $likeId = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)[0]['id'];

        $this->client->request('DELETE', '/api/likes/'.$likeId, [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', '/api/likes/'.$likeId, [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteForeignLikeReturns403(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $foreign = $this->registerUser();

        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));
        $this->assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));
        $likeId = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)[0]['id'];

        $this->client->request('DELETE', '/api/likes/'.$likeId, [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(
            ['error' => 'Forbidden', 'message' => 'Forbidden'],
            \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)
        );
    }

    public function testAdminCanDeleteForeignLike(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $admin = $this->registerUser();

        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));
        $this->assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));
        $likeId = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)[0]['id'];

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            'UPDATE users SET role = "admin" WHERE id = UNHEX(REPLACE(?, "-", ""))',
            [\preg_replace('/-/', '', $admin['id'])]
        );

        $this->client->request('DELETE', '/api/likes/'.$likeId, [], [], $this->authHeaders($admin['token']));

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeleteNonExistentLikeReturns404(): void
    {
        $user = $this->registerUser();

        $this->client->request('DELETE', '/api/likes/018f0a1b-2c3d-4e5f-6789-0123456789ab', [], [], $this->authHeaders($user['token']));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteMalformedLikeIdReturns404(): void
    {
        $user = $this->registerUser();

        $this->client->request('DELETE', '/api/likes/not-a-uuid', [], [], $this->authHeaders($user['token']));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testLikeMalformedItemIdReturns404(): void
    {
        $user = $this->registerUser();

        $this->client->request('POST', '/api/items/not-a-uuid/likes', [], [], $this->authHeaders($user['token']));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testListByItemPaginates(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $first = $this->registerUser();
        $second = $this->registerUser();
        $third = $this->registerUser();

        foreach ([$owner, $first, $second, $third] as $user) {
            $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($user['token']));
            $this->assertResponseStatusCodeSame(200);
        }

        $this->client->request('GET', '/api/items/'.$itemId.'/likes?limit=2&offset=1', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(2, $data);
    }

    public function testListOwnReturnsOnlyOwnLikes(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $foreign = $this->registerUser();

        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));
        $this->assertResponseStatusCodeSame(200);
        $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($foreign['token']));
        $this->assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/likes', [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data);
        $this->assertSame($foreign['id'], $data[0]['owner_id']);
    }

    public function testListOwnWithoutAuthReturns401(): void
    {
        $this->client->request('GET', '/api/likes');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListOwnEmptyReturnsEmptyArray(): void
    {
        $owner = $this->registerUser();

        $this->client->request('GET', '/api/likes', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame([], \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testListOwnPaginates(): void
    {
        $owner = $this->registerUser();
        $collectionId = $this->createCollection($owner['token']);
        $firstItemId = $this->createItem($collectionId, $owner['token']);
        $secondItemId = $this->createItem($collectionId, $owner['token']);
        $thirdItemId = $this->createItem($collectionId, $owner['token']);

        foreach ([$firstItemId, $secondItemId, $thirdItemId] as $itemId) {
            $this->client->request('POST', '/api/items/'.$itemId.'/likes', [], [], $this->authHeaders($owner['token']));
            $this->assertResponseStatusCodeSame(200);
        }

        $this->client->request('GET', '/api/likes?limit=2&offset=1', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(2, $data);
        $this->assertSame($secondItemId, $data[0]['item_id']);
        $this->assertSame($thirdItemId, $data[1]['item_id']);
    }

    public function testListOwnWithInvalidLimitReturns400(): void
    {
        $owner = $this->registerUser();

        $this->client->request('GET', '/api/likes?limit=0', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(400);
    }

    protected function tearDown(): void
    {
        if ([] !== $this->extraUserIds) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach ($this->extraUserIds as $id) {
                $em->getConnection()->executeStatement(
                    'DELETE FROM users WHERE id = UNHEX(REPLACE(?, "-", ""))',
                    [\preg_replace('/-/', '', $id)]
                );
            }
        }

        parent::tearDown();
    }
}
