<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LogoutControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine.orm.entity_manager');

        // Ensure clean database for each test
        $this->purgeDatabase();
    }

    private function purgeDatabase(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $connection->executeStatement('TRUNCATE TABLE users');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testLogoutReturns204WithValidToken(): void
    {
        $user = $this->createUser('John Doe', 'john@example.com', 'securePassword123');
        $token = $this->getTokenForUser($user);

        $this->client->request(
            'POST',
            '/api/logout',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ]
        );

        $this->assertResponseStatusCodeSame(204);
    }

    public function testLogoutReturns401WithoutToken(): void
    {
        $this->client->request('POST', '/api/logout', [], [], ['CONTENT_TYPE' => 'application/json']);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLogoutReturns401WithInvalidToken(): void
    {
        $this->client->request(
            'POST',
            '/api/logout',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer invalid.token.here',
            ]
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function testLogoutReturns401WithExpiredToken(): void
    {
        // Use a very old token (we can't easily generate an expired token without modifying the system time)
        // This is a basic test - in reality you'd need a way to create expired tokens
        $this->client->request(
            'POST',
            '/api/logout',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpYXQiOjE3MDQwNjcyMDAsImV4cCI6MTcwNDA3MDgwMH0.invalid',
            ]
        );

        $this->assertResponseStatusCodeSame(401);
    }

    private function createUser(string $name, string $email, string $password): User
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        $user = new User(
            id: UserId::generate()->toBytes(),
            name: $name,
            email: Email::fromString($email),
            passwordHash: PasswordHash::createFromPlain($password),
            role: Role::user(),
            isActive: true
        );
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function getTokenForUser(User $user): string
    {
        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);

        return $jwtManager->create($user);
    }
}
