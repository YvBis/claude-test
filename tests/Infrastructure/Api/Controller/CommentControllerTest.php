<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CommentControllerTest extends WebTestCase
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
        $email = 'comment_'.\str_replace('.', '', $unique).'_x@example.com';

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Comment Tester',
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

    private function createCollection(string $token): string
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders($token), \json_encode([
            'name' => 'Comment Collection',
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

    private function createComment(string $itemId, string $token, string $content): string
    {
        $this->client->request('POST', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($token), \json_encode([
            'content' => $content,
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        return \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
    }

    public function testCreateReturns201AndData(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('POST', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($owner['token']), \json_encode([
            'content' => 'Great **read**!',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(201);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Great **read**!', $data['content']);
        $this->assertSame('Comment Tester', $data['owner_name']);
        $this->assertSame($itemId, $data['item_id']);
        $this->assertArrayHasKey('created_at', $data);
        $this->assertArrayHasKey('updated_at', $data);
    }

    public function testCreatePreservesNewlines(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('POST', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($owner['token']), \json_encode([
            'content' => "line 1\nline 2",
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(201);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame("line 1\nline 2", $data['content']);
    }

    public function testCreateWithoutAuthReturns401(): void
    {
        $this->client->request('POST', '/api/items/018f0a1b-2c3d-4e5f-6789-0123456789ab/comments', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'content' => 'hi',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testCreateOnNonExistentItemReturns404(): void
    {
        $owner = $this->registerUser();

        $this->client->request('POST', '/api/items/018f0a1b-2c3d-4e5f-6789-0123456789ab/comments', [], [], $this->authHeaders($owner['token']), \json_encode([
            'content' => 'hi',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreateWithEmptyContentReturns422(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('POST', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($owner['token']), \json_encode([
            'content' => '   ',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(422);
    }

    public function testCreateWithMissingContentReturns422(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('POST', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($owner['token']), \json_encode([], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(422);
    }

    public function testCreateWithMalformedJsonReturns400(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('POST', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($owner['token']), '{"content": ');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreateWithNonStringContentReturns400(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('POST', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($owner['token']), \json_encode([
            'content' => 123,
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testForeignUserCanComment(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $foreign = $this->registerUser();

        $this->client->request('POST', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($foreign['token']), \json_encode([
            'content' => 'Nice one',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(201);
    }

    public function testListByItemReturnsOldestFirst(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->createComment($itemId, $owner['token'], 'first');
        $this->createComment($itemId, $owner['token'], 'second');

        $this->client->request('GET', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(2, $data);
        $this->assertSame('first', $data[0]['content']);
        $this->assertSame('second', $data[1]['content']);
        $this->assertArrayHasKey('owner_name', $data[0]);
    }

    public function testListByItemForeignUserCanRead(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $foreign = $this->registerUser();

        $this->createComment($itemId, $owner['token'], 'hello');

        $this->client->request('GET', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(200);
        $this->assertCount(1, \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testListOwnReturnsOnlyOwnComments(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $foreign = $this->registerUser();

        $this->createComment($itemId, $owner['token'], 'mine');
        $this->createComment($itemId, $foreign['token'], 'theirs');

        $this->client->request('GET', '/api/comments', [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $data);
        $this->assertSame('theirs', $data[0]['content']);
    }

    public function testUpdateOwnCommentReturns200(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'before');

        $this->client->request('PATCH', '/api/comments/'.$commentId, [], [], $this->authHeaders($owner['token']), \json_encode([
            'content' => 'after',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('after', $data['content']);
    }

    public function testUpdateForeignCommentReturns403(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'before');
        $foreign = $this->registerUser();

        $this->client->request('PATCH', '/api/comments/'.$commentId, [], [], $this->authHeaders($foreign['token']), \json_encode([
            'content' => 'hacked',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(
            ['error' => 'Forbidden', 'message' => 'Forbidden'],
            \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)
        );
    }

    public function testAdminCanUpdateForeignComment(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'before');
        $admin = $this->registerUser();
        $this->promoteToAdmin($admin['id']);

        $this->client->request('PATCH', '/api/comments/'.$commentId, [], [], $this->authHeaders($admin['token']), \json_encode([
            'content' => 'moderated',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('moderated', $data['content']);
    }

    public function testUpdateWithInvalidContentReturns422(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'before');

        $this->client->request('PATCH', '/api/comments/'.$commentId, [], [], $this->authHeaders($owner['token']), \json_encode([
            'content' => '',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(422);
    }

    public function testUpdateNonExistentCommentReturns404(): void
    {
        $owner = $this->registerUser();

        $this->client->request('PATCH', '/api/comments/018f0a1b-2c3d-4e5f-6789-0123456789ab', [], [], $this->authHeaders($owner['token']), \json_encode([
            'content' => 'x',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteOwnCommentReturns204(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'to delete');

        $this->client->request('DELETE', '/api/comments/'.$commentId, [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($owner['token']));
        $this->assertSame([], \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testAdminCanDeleteForeignComment(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'moderate me');
        $admin = $this->registerUser();
        $this->promoteToAdmin($admin['id']);

        $this->client->request('DELETE', '/api/comments/'.$commentId, [], [], $this->authHeaders($admin['token']));

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeleteForeignCommentReturns403(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'protected');
        $foreign = $this->registerUser();

        $this->client->request('DELETE', '/api/comments/'.$commentId, [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(
            ['error' => 'Forbidden', 'message' => 'Forbidden'],
            \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)
        );
    }

    public function testDeleteNonExistentCommentReturns404(): void
    {
        $owner = $this->registerUser();

        $this->client->request('DELETE', '/api/comments/018f0a1b-2c3d-4e5f-6789-0123456789ab', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testListWithInvalidLimitReturns400(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('GET', '/api/items/'.$itemId.'/comments?limit=abc', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testUpdateMalformedCommentIdReturns404(): void
    {
        $owner = $this->registerUser();

        $this->client->request('PATCH', '/api/comments/not-a-uuid', [], [], $this->authHeaders($owner['token']), \json_encode([
            'content' => 'x',
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteMalformedCommentIdReturns404(): void
    {
        $owner = $this->registerUser();

        $this->client->request('DELETE', '/api/comments/not-a-uuid', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteInItemOwnReturns204(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'to delete in item');

        $this->client->request('DELETE', '/api/items/'.$itemId.'/comments/'.$commentId, [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($owner['token']));
        $this->assertSame([], \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testAdminCanDeleteForeignCommentInItem(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'moderate me in item');
        $admin = $this->registerUser();
        $this->promoteToAdmin($admin['id']);

        $this->client->request('DELETE', '/api/items/'.$itemId.'/comments/'.$commentId, [], [], $this->authHeaders($admin['token']));

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeleteForeignCommentInItemReturns403(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'protected in item');
        $foreign = $this->registerUser();

        $this->client->request('DELETE', '/api/items/'.$itemId.'/comments/'.$commentId, [], [], $this->authHeaders($foreign['token']));

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(
            ['error' => 'Forbidden', 'message' => 'Forbidden'],
            \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)
        );
    }

    public function testDeleteCommentFromAnotherItemReturns404(): void
    {
        $owner = $this->registerUser();
        $collectionId = $this->createCollection($owner['token']);
        $itemId = $this->createItem($collectionId, $owner['token']);
        $otherItemId = $this->createItem($collectionId, $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'belongs to the first item');

        $this->client->request('DELETE', '/api/items/'.$otherItemId.'/comments/'.$commentId, [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(
            ['error' => 'Not Found', 'message' => 'Comment not found'],
            \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)
        );

        $this->client->request('GET', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($owner['token']));
        $remaining = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(1, $remaining);
        $this->assertSame($commentId, $remaining[0]['id']);
    }

    public function testDeleteNonExistentCommentInItemReturns404(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('DELETE', '/api/items/'.$itemId.'/comments/018f0a1b-2c3d-4e5f-6789-0123456789ab', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(
            ['error' => 'Not Found', 'message' => 'Comment not found'],
            \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)
        );
    }

    public function testDeleteMalformedCommentIdInItemReturns404(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('DELETE', '/api/items/'.$itemId.'/comments/not-a-uuid', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(
            ['error' => 'Not Found', 'message' => 'Comment not found'],
            \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)
        );
    }

    public function testDeleteInItemNonExistentItemReturns404(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'reachable only through its own item');

        $this->client->request('DELETE', '/api/items/018f0a1b-2c3d-4e5f-6789-0123456789ab/comments/'.$commentId, [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(
            ['error' => 'Not Found', 'message' => 'Comment not found'],
            \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)
        );
    }

    public function testDeleteInItemWithoutAuthReturns401(): void
    {
        $this->client->request('DELETE', '/api/items/018f0a1b-2c3d-4e5f-6789-0123456789ab/comments/018f0a1b-2c3d-4e5f-6789-0123456789ac');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testDeleteInItemMalformedItemIdReturns404(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'addressed through a malformed item');

        $this->client->request('DELETE', '/api/items/not-a-uuid/comments/'.$commentId, [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(
            ['error' => 'Not Found', 'message' => 'Comment not found'],
            \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)
        );
    }

    public function testDeleteInItemAcceptsUppercaseItemId(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);
        $commentId = $this->createComment($itemId, $owner['token'], 'deleted through an uppercase item id');

        $this->client->request('DELETE', '/api/items/'.\strtoupper($itemId).'/comments/'.$commentId, [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(204);
    }

    public function testCreateAtMaxLengthReturns201(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('POST', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($owner['token']), \json_encode([
            'content' => \str_repeat('a', 3000),
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(201);
    }

    public function testCreateOverMaxLengthReturns422(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->client->request('POST', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($owner['token']), \json_encode([
            'content' => \str_repeat('a', 3001),
        ], \JSON_THROW_ON_ERROR));

        $this->assertResponseStatusCodeSame(422);
    }

    public function testListByItemPaginates(): void
    {
        $owner = $this->registerUser();
        $itemId = $this->createItem($this->createCollection($owner['token']), $owner['token']);

        $this->createComment($itemId, $owner['token'], 'one');
        $this->createComment($itemId, $owner['token'], 'two');
        $this->createComment($itemId, $owner['token'], 'three');

        $this->client->request('GET', '/api/items/'.$itemId.'/comments?limit=2&offset=1', [], [], $this->authHeaders($owner['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(2, $data);
        $this->assertSame('two', $data[0]['content']);
        $this->assertSame('three', $data[1]['content']);
    }

    private function promoteToAdmin(string $userId): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            'UPDATE users SET role = "admin" WHERE id = UNHEX(REPLACE(?, "-", ""))',
            [\preg_replace('/-/', '', $userId)]
        );
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
