<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller\Admin;

use App\Infrastructure\Search\MeilisearchSearchAdapter;
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

    public function testGuestGets401OnMutations(): void
    {
        $this->client->request('PATCH', '/api/admin/users/018f0a1b-2c3d-4e5f-6789-0123456789ab', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode(['is_active' => false], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(401);

        $this->client->request('DELETE', '/api/admin/users/018f0a1b-2c3d-4e5f-6789-0123456789ab');
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

    public function testAdminCanBlockAndUnblock(): void
    {
        $admin = $this->registerAdmin();
        $target = $this->registerUser('victim');

        $this->client->request('PATCH', '/api/admin/users/'.$target['id'], [], [], $this->authHeaders($admin['token']), \json_encode(['is_active' => false], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($data['is_active']);

        $this->client->request('PATCH', '/api/admin/users/'.$target['id'], [], [], $this->authHeaders($admin['token']), \json_encode(['is_active' => true], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($data['is_active']);
    }

    public function testBlockIsIdempotent(): void
    {
        $admin = $this->registerAdmin();
        $target = $this->registerUser('repeat');

        foreach ([false, false] as $flag) {
            $this->client->request('PATCH', '/api/admin/users/'.$target['id'], [], [], $this->authHeaders($admin['token']), \json_encode(['is_active' => $flag], \JSON_THROW_ON_ERROR));
            $this->assertResponseStatusCodeSame(200);
        }
    }

    public function testEmptyPatchIsA422(): void
    {
        $admin = $this->registerAdmin();
        $target = $this->registerUser('empty');

        $this->client->request('PATCH', '/api/admin/users/'.$target['id'], [], [], $this->authHeaders($admin['token']), \json_encode([], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(422);
    }

    public function testUnknownUserIsA404(): void
    {
        $admin = $this->registerAdmin();

        $this->client->request('PATCH', '/api/admin/users/018f0a1b-2c3d-4e5f-6789-0123456789ab', [], [], $this->authHeaders($admin['token']), \json_encode(['is_active' => false], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('DELETE', '/api/admin/users/018f0a1b-2c3d-4e5f-6789-0123456789ab', [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testMalformedIdIsA404(): void
    {
        $admin = $this->registerAdmin();

        $this->client->request('PATCH', '/api/admin/users/not-a-uuid', [], [], $this->authHeaders($admin['token']), \json_encode(['is_active' => false], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(404);
    }

    public function testSelfBlockIsForbidden(): void
    {
        $admin = $this->registerAdmin();

        $this->client->request('PATCH', '/api/admin/users/'.$admin['id'], [], [], $this->authHeaders($admin['token']), \json_encode(['is_active' => false], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSelfDeleteIsForbidden(): void
    {
        $admin = $this->registerAdmin();

        $this->client->request('DELETE', '/api/admin/users/'.$admin['id'], [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSelfBlockTakesPrecedenceOverLastAdmin(): void
    {
        // Single admin self-blocking: the self-guard fires first (403), so
        // the last-admin guard is unreachable over HTTP while the self-guard
        // stands — it stays covered at unit level as a safety invariant.
        $admin = $this->registerAdmin();

        $this->client->request('PATCH', '/api/admin/users/'.$admin['id'], [], [], $this->authHeaders($admin['token']), \json_encode(['is_active' => false], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeleteSecondAdminThenSelfIsStillForbidden(): void
    {
        $admin = $this->registerAdmin();

        $second = $this->registerUser('second-admin');
        $this->promoteToAdmin($second['id']);

        $this->client->request('DELETE', '/api/admin/users/'.$second['id'], [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', '/api/admin/users/'.$admin['id'], [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanDeleteUser(): void
    {
        $admin = $this->registerAdmin();
        $target = $this->registerUser('gone');

        $this->client->request('DELETE', '/api/admin/users/'.$target['id'], [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/admin/users', [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(200);
        $data = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], \array_filter($data, static fn (array $row): bool => $row['id'] === $target['id']));
    }

    public function testDeleteCascadesDbAndCleansTheIndex(): void
    {
        // The search index must be provisioned: a fresh engine answers 500
        // on filtered search (6.6 lesson), and this class does not provision.
        self::getContainer()->get(MeilisearchSearchAdapter::class)->ensureIndexes();

        $admin = $this->registerAdmin();
        $target = $this->registerUser('doomed');

        $collectionId = $this->createCollection($target['token'], 'Doomed Books');
        $itemName = 'Doomed Dune '.\str_replace('.', '', \uniqid('', true));
        $this->createItem($target['token'], $collectionId, $itemName);

        $this->client->request('DELETE', '/api/admin/users/'.$target['id'], [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(204);

        // DB cascade: the collection is gone.
        $this->client->request('GET', '/api/collections/'.$collectionId, [], [], $this->authHeaders($admin['token']));
        $this->assertResponseStatusCodeSame(404);

        // Index fan-out: the document disappears (engine tasks are async,
        // so poll until gone).
        $deadline = \microtime(true) + 10;
        do {
            $this->client->request('GET', '/api/search/items?'.\http_build_query(['q' => $itemName]));
            $this->assertResponseStatusCodeSame(200);
            $hits = \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            if ([] === $hits) {
                break;
            }
            \usleep(100_000);
        } while (\microtime(true) < $deadline);

        self::assertSame([], $hits, 'deleted user documents must leave the index');
    }

    public function testRegularUserCannotMutate(): void
    {
        $admin = $this->registerAdmin();
        $user = $this->registerUser('plain2');

        $this->client->request('PATCH', '/api/admin/users/'.$admin['id'], [], [], $this->authHeaders($user['token']), \json_encode(['is_active' => false], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('DELETE', '/api/admin/users/'.$admin['id'], [], [], $this->authHeaders($user['token']));
        $this->assertResponseStatusCodeSame(403);
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

    private function createCollection(string $token, string $name): string
    {
        $this->client->request('POST', '/api/collections', [], [], $this->authHeaders($token), \json_encode([
            'name' => $name,
            'theme' => 'books',
        ], \JSON_THROW_ON_ERROR));
        $this->assertResponseStatusCodeSame(201);

        return \json_decode($this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
    }

    private function createItem(string $token, string $collectionId, string $name): void
    {
        $this->client->request(
            'POST',
            '/api/collections/'.$collectionId.'/items',
            [],
            [],
            $this->authHeaders($token),
            \json_encode(['name' => $name, 'tags' => ['doomed-tag']], \JSON_THROW_ON_ERROR)
        );
        $this->assertResponseStatusCodeSame(201);
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
