<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use App\Infrastructure\User\Repository\DoctrineUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins fwd-31 at the HTTP boundary, on the path that actually runs: the `api`
 * firewall is stateless, so Lexik re-reads the user through
 * `UserProvider::loadUserByIdentifier()` on every request. A token issued
 * before the account was deactivated or deleted must stop working on the very
 * next request, not when `token_ttl` (3600s) runs out.
 */
final class BlockedTokenTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

        parent::tearDown();
    }

    public function testTokenStopsWorkingAfterDeactivation(): void
    {
        $this->createUser('Deactivated Later', 'deactivated-later@example.com');
        $token = $this->login('deactivated-later@example.com');

        $this->assertRequestSucceeds($token, 'Precondition: the fresh token must work.');

        $this->mutateUser('deactivated-later@example.com', static fn (User $user) => $user->deactivate());

        $this->client->request('GET', '/api/tags', [], [], $this->bearer($token));

        $this->assertBlockedEnvelope();
    }

    public function testTokenStopsWorkingAfterTheAccountIsDeleted(): void
    {
        $this->createUser('Deleted Later', 'deleted-later@example.com');
        $token = $this->login('deleted-later@example.com');

        $this->assertRequestSucceeds($token, 'Precondition: the fresh token must work.');

        static::getContainer()->get('doctrine.orm.entity_manager')->remove(
            $this->reloadUser('deleted-later@example.com'),
        );
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $this->client->request('GET', '/api/tags', [], [], $this->bearer($token));

        $this->assertBlockedEnvelope();
    }

    public function testReactivatingTheAccountRestoresAccess(): void
    {
        $this->createUser('Reactivated Later', 'reactivated-later@example.com');
        $token = $this->login('reactivated-later@example.com');

        $this->mutateUser('reactivated-later@example.com', static fn (User $user) => $user->deactivate());

        $this->client->request('GET', '/api/tags', [], [], $this->bearer($token));
        $this->assertResponseStatusCodeSame(401);

        $this->mutateUser('reactivated-later@example.com', static fn (User $user) => $user->activate());

        $this->assertRequestSucceeds($token, 'A reactivated account must get its token back.');
    }

    /**
     * The envelope shape itself is pinned by UnauthorizedEnvelopeTest; what
     * matters here is that the answer is 401 and that it says nothing about
     * *why* — blocked and deleted must be indistinguishable, otherwise the 401
     * becomes an account-enumeration oracle for token holders.
     */
    private function assertBlockedEnvelope(): void
    {
        $this->assertResponseStatusCodeSame(401);

        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Unauthorized', $response['error']);
        self::assertStringNotContainsStringIgnoringCase('deactivat', (string) ($response['message'] ?? ''));
        self::assertStringNotContainsString('User not found', (string) ($response['message'] ?? ''));
    }

    /**
     * Re-reads through the repository rather than reusing an instance from
     * before: every request reboots the kernel, so a previously fetched entity
     * is detached and a flush on it is a silent no-op — which turns these
     * tests green for the wrong reason. Everything is therefore resolved from
     * the container at the moment of use, and `flush()` is explicit because
     * `DoctrineUserRepository::save()` only persists.
     *
     * @param callable(User): void $mutate
     */
    private function mutateUser(string $email, callable $mutate): void
    {
        $repository = static::getContainer()->get(DoctrineUserRepository::class);
        $user = $repository->findByEmail(Email::fromString($email));

        self::assertInstanceOf(User::class, $user);

        $mutate($user);

        $repository->getEntityManager()->flush();
    }

    /**
     * @return array<string, string>
     */
    private function reloadUser(string $email): User
    {
        $repository = static::getContainer()->get(DoctrineUserRepository::class);
        $user = $repository->findByEmail(Email::fromString($email));

        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function assertRequestSucceeds(string $token, string $message): void
    {
        $this->client->request('GET', '/api/tags', [], [], $this->bearer($token));
        $this->assertResponseStatusCodeSame(200, $message);
    }

    private function login(string $email): string
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], \sprintf(
            '{"email": "%s", "password": "securePassword123"}',
            $email,
        ));

        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertArrayHasKey('access_token', $response);

        return (string) $response['access_token'];
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    private function createUser(string $name, string $email): User
    {
        $user = new User(
            id: UserId::generate()->toBytes(),
            name: $name,
            email: Email::fromString($email),
            passwordHash: PasswordHash::createFromPlain('securePassword123'),
            role: Role::user(),
            isActive: true,
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
