<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CommentControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testAdminSeesForeignCommentsWithItemName(): void
    {
        $admin = $this->registerAdmin();
        $author = $this->registerUser('author');
        $collectionId = $this->createCollection($author['token'], 'Author Coll');
        $itemId = $this->createItem($author['token'], $collectionId, 'Authored Item');
        $this->createComment($author['token'], $itemId, 'Foreign comment body');

        $this->client->request('GET', '/api/admin/comments', [], [], $this->authHeaders($admin['token']));

        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($data);
        $row = $data[0];
        foreach (['id', 'owner_id', 'owner_name', 'item_id', 'item_name', 'content', 'created_at', 'updated_at'] as $key) {
            self::assertArrayHasKey($key, $row, \sprintf('admin audit must carry %s', $key));
        }
        self::assertSame('Authored Item', $row['item_name']);
        self::assertSame('Foreign comment body', $row['content']);
    }

    public function testGuestGets401(): void
    {
        $this->client->request('GET', '/api/admin/comments');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testRegularUserGets403(): void
    {
        $user = $this->registerUser('plain');

        $this->client->request('GET', '/api/admin/comments', [], [], $this->authHeaders($user['token']));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testPaginationSlicesNewestFirst(): void
    {
        $admin = $this->registerAdmin();
        $author = $this->registerUser('pager');
        $collectionId = $this->createCollection($author['token'], 'Pager Coll');
        $itemId = $this->createItem($author['token'], $collectionId, 'Pager Item');
        $this->createComment($author['token'], $itemId, 'First comment');
        $this->createComment($author['token'], $itemId, 'Second comment');

        $this->client->request('GET', '/api/admin/comments?limit=1&offset=0', [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(200);
        $page0 = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $page0);
        self::assertSame('Second comment', $page0[0]['content']);

        $this->client->request('GET', '/api/admin/comments?limit=1&offset=1', [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(200);
        $page1 = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $page1);
        self::assertSame('First comment', $page1[0]['content']);
    }

    public function testBadPaginationIsA400(): void
    {
        $admin = $this->registerAdmin();

        $this->client->request('GET', '/api/admin/comments?limit=abc', [], [], $this->authHeaders($admin['token']));

        $this->assertResponseStatusCodeSame(400);
    }

    /** @return array{token: string, id: string} */
    private function registerUser(string $tag): array
    {
        $unique = \str_replace('.', '', \uniqid('', true));
        $email = \sprintf('adminaudit_%s_%s@example.com', $tag, $unique);

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Audit Tester',
            'email' => $email,
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        $id = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        return ['token' => $this->login($email), 'id' => $id];
    }

    /** @return array{token: string, id: string} */
    private function registerAdmin(): array
    {
        $user = $this->registerUser('admin');
        $this->promoteToAdmin($user['id']);

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'email' => $this->emailOf($user['id']),
            'password' => 'password123',
        ], \JSON_THROW_ON_ERROR));

        // The role is reloaded from the DB on every stateless request, so a
        // fresh login after the raw-SQL promotion carries ROLE_ADMIN.
        $token = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['access_token'];

        return ['token' => $token, 'id' => $user['id']];
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

    private function emailOf(string $userId): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $row = $em->getConnection()->fetchAssociative(
            'SELECT email FROM users WHERE id = UNHEX(REPLACE(?, "-", ""))',
            [$userId],
        );
        \assert(\is_array($row) && isset($row['email']) && \is_string($row['email']));

        return $row['email'];
    }

    private function promoteToAdmin(string $userId): void
    {
        // No EntityManager::clear() needed: the next request reboots the
        // kernel and authenticates by token, so UserProvider reloads the role.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            'UPDATE users SET role = "admin" WHERE id = UNHEX(REPLACE(?, "-", ""))',
            [\preg_replace('/-/', '', $userId)],
        );
    }

    /** @return array<string, string> */
    private function authHeaders(?string $token = null): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ];
    }

    private function createCollection(string $token, string $name): string
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders($token), \json_encode([
            'name' => $name,
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        return \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
    }

    private function createItem(string $token, string $collectionId, string $name): string
    {
        $this->client->request('POST', '/api/collections/'.$collectionId.'/items', [], [], $this->authHeaders($token), \json_encode([
            'name' => $name,
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        return \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
    }

    private function createComment(string $token, string $itemId, string $content): void
    {
        $this->client->request('POST', '/api/items/'.$itemId.'/comments', [], [], $this->authHeaders($token), \json_encode([
            'content' => $content,
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);
    }
}
