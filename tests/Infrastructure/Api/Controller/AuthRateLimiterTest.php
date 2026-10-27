<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * review-13: enforcement has to be observed on the real HTTP path, but the
 * counters must not leak into other tests. A cache pool cannot do this: every
 * pool carries kernel.reset, so counters are wiped between requests even when
 * the kernel is not rebooted. The limiters therefore use InMemoryStorage in
 * `test`, which lives exactly as long as the container - and `disableReboot()`
 * keeps one container for the whole test, the same way a user's browser keeps
 * one session across five attempts.
 */
final class AuthRateLimiterTest extends WebTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

        parent::tearDown();
    }

    public function testSixthLoginAttemptForOneAccountIsRejectedWithEnvelopeAndRetryAfter(): void
    {
        $this->createUser('John Doe', 'john@example.com', 'securePassword123');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login('john@example.com', 'wrong-password');
            self::assertResponseStatusCodeSame(401, 'Attempt '.$attempt.' must reach the authenticator.');
        }

        $this->login('john@example.com', 'securePassword123');

        self::assertResponseStatusCodeSame(429);
        $payload = \json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Too Many Requests', $payload['error'] ?? null);
        self::assertArrayHasKey('message', $payload);
        self::assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testTheAccountBucketIgnoresEmailCase(): void
    {
        $this->createUser('John Doe', 'john@example.com', 'securePassword123');

        // Case variants must exhaust one bucket, not five.
        foreach (['john@example.com', 'John@Example.com', 'JOHN@EXAMPLE.COM', 'john@example.com', 'John@example.COM'] as $email) {
            $this->login($email, 'wrong-password');
            self::assertResponseStatusCodeSame(401);
        }

        $this->login('JOHN@EXAMPLE.COM', 'securePassword123');

        self::assertResponseStatusCodeSame(429);
    }

    public function testRegistrationIsLimitedPerIpAndOtherEndpointsAreUnaffected(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
                'name' => 'User '.$attempt,
                'email' => 'rate-limit-'.$attempt.'@example.com',
                'password' => 'securePassword123',
            ], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(201, 'Attempt '.$attempt.' must register.');
        }

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'User 6',
            'email' => 'rate-limit-6@example.com',
            'password' => 'securePassword123',
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(429);
        $payload = \json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Too Many Requests', $payload['error'] ?? null);

        // Other endpoints keep their own contract (guest without ?owner is 400), never 429.
        $this->client->request('GET', '/api/collections');
        self::assertResponseStatusCodeSame(400);
        self::assertNotSame(429, $this->client->getResponse()->getStatusCode());
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'email' => $email,
            'password' => $password,
        ], \JSON_THROW_ON_ERROR));
    }

    private function createUser(string $name, string $email, string $plainPassword): void
    {
        $user = User::register(
            $name,
            Email::fromString($email),
            PasswordHash::createFromPlain($plainPassword),
            Role::user(),
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
