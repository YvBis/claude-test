<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var list<string> */
    private array $extraUserIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAdminSeesEveryFieldExceptTheHash(): void
    {
        $admin = $this->registerAdmin();

        $this->client->request('GET', '/api/admin/users', [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(200);

        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($data);

        $row = $data[0];
        foreach (['id', 'name', 'email', 'role', 'is_active', 'created_at', 'updated_at'] as $key) {
            self::assertArrayHasKey($key, $row, \sprintf('admin list must carry %s', $key));
        }
        self::assertArrayNotHasKey('password_hash', $row);
        self::assertArrayNotHasKey('passwordHash', $row);
    }

    public function testRegularUserGets403(): void
    {
        $user = $this->registerUser('plain');

        $this->client->request('GET', '/api/admin/users', [], [], $this->authHeaders($user['token']));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testGuestGets401(): void
    {
        $this->client->request('GET', '/api/admin/users');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListIsPaginated(): void
    {
        $admin = $this->registerAdmin();
        $this->registerUser('paged1');
        $this->registerUser('paged2');

        $this->client->request('GET', '/api/admin/users?limit=2&offset=0', [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(200);
        $page1 = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(2, $page1);

        $this->client->request('GET', '/api/admin/users?limit=100&offset=2', [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(200);
        $page2 = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($page2);

        $ids1 = \array_column($page1, 'id');
        $ids2 = \array_column($page2, 'id');
        self::assertSame([], \array_intersect($ids1, $ids2), 'pages must not overlap');
    }

    public function testBadPaginationIsA400(): void
    {
        $admin = $this->registerAdmin();

        $this->client->request('GET', '/api/admin/users?limit=abc', [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(400);
    }

    /** @return array{token: string, id: string} */
    private function registerUser(string $tag): array
    {
        $unique = \str_replace('.', '', \uniqid('', true));
        $email = \sprintf('adminlist_%s_%s@example.com', $tag, $unique);

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Admin List Tester',
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

    protected function tearDown(): void
    {
        if ([] !== $this->extraUserIds) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            foreach ($this->extraUserIds as $id) {
                $em->getConnection()->executeStatement(
                    'DELETE FROM users WHERE id = UNHEX(REPLACE(?, "-", ""))',
                    [\preg_replace('/-/', '', $id)],
                );
            }
        }

        parent::tearDown();
    }
}
