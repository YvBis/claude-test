<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LoginControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private array $createdUserEmails = [];

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

    protected function tearDown(): void
    {
        // Clean up created users using the repository
        $repository = static::getContainer()->get(\App\Infrastructure\User\Repository\DoctrineUserRepository::class);
        foreach ($this->createdUserEmails as $emailString) {
            $email = Email::fromString($emailString);
            $user = $repository->findByEmail($email);
            if ($user) {
                $repository->remove($user);
            }
        }
        $this->createdUserEmails = [];

        // Clear entity manager to avoid stale references between tests
        $this->entityManager->clear();

        parent::tearDown();
    }

    public function testLoginReturns200AndToken(): void
    {
        $this->createUser('John Doe', 'john@example.com', 'securePassword123');

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "email": "john@example.com",
            "password": "securePassword123"
        }');

        $this->assertResponseStatusCodeSame(200);
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $response = \json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('access_token', $response);
        $this->assertSame('Bearer', $response['token_type']);
        $this->assertSame(3600, $response['expires_in']);
        $this->assertArrayHasKey('user', $response);
        $this->assertSame('John Doe', $response['user']['name']);
        $this->assertSame('john@example.com', $response['user']['email']);
        $this->assertSame('user', $response['user']['role']);
        $this->assertTrue($response['user']['is_active']);
        $this->assertArrayHasKey('created_at', $response['user']);
        $this->assertArrayHasKey('updated_at', $response['user']);
        $this->assertArrayNotHasKey('password', $response['user']);
    }

    public function testLoginReturns401ForInvalidEmail(): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "email": "notfound@example.com",
            "password": "securePassword123"
        }');

        $this->assertResponseStatusCodeSame(401);
        $response = \json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Unauthorized', $response['error']);
        $this->assertSame('Invalid credentials', $response['message']);
    }

    public function testLoginReturns401ForInvalidPassword(): void
    {
        $this->createUser('John Doe', 'john-invalid@example.com', 'securePassword123');

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "email": "john-invalid@example.com",
            "password": "wrongPassword123"
        }');

        $this->assertResponseStatusCodeSame(401);
        $response = \json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Unauthorized', $response['error']);
        $this->assertSame('Invalid credentials', $response['message']);
    }

    public function testLoginReturns403ForDeactivatedUser(): void
    {
        $this->createUser('John Doe', 'john-deactivated@example.com', 'securePassword123');

        // Deactivate the user
        $repository = static::getContainer()->get(\App\Infrastructure\User\Repository\DoctrineUserRepository::class);
        $user = $repository->findByEmail(Email::fromString('john-deactivated@example.com'));
        $user->deactivate();
        $repository->save($user);

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "email": "john-deactivated@example.com",
            "password": "securePassword123"
        }');

        $this->assertResponseStatusCodeSame(403);
        $response = \json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Forbidden', $response['error']);
        $this->assertStringContainsString('deactivated', $response['message']);
    }

    public function testLoginValidatesEmailRequired(): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "email": "",
            "password": "securePassword123"
        }');

        $this->assertResponseStatusCodeSame(400);
        $response = \json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Validation failed', $response['error']);
        // Check actual validation message from serializer/validator
        $this->assertArrayHasKey('details', $response);
    }

    public function testLoginValidatesEmailFormat(): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "email": "not-an-email",
            "password": "securePassword123"
        }');

        $this->assertResponseStatusCodeSame(400);
        $response = \json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Validation failed', $response['error']);
        // Check that details contains validation errors
        $this->assertArrayHasKey('details', $response);
        $this->assertIsArray($response['details']);
    }

    public function testLoginValidatesPasswordMinLength(): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "email": "john@example.com",
            "password": "short"
        }');

        $this->assertResponseStatusCodeSame(400);
        $response = \json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Validation failed', $response['error']);
        $this->assertArrayHasKey('details', $response);
        $this->assertIsArray($response['details']);
    }

    public function testLoginTrimsAndLowercasesEmail(): void
    {
        $this->createUser('John Doe', 'john@trim.example.com', 'securePassword123');

        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "email": "  JOHN@TRIM.EXAMPLE.COM  ",
            "password": "securePassword123"
        }');

        $this->assertResponseStatusCodeSame(200);
        $response = \json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('access_token', $response);
    }

    private function createUser(string $name, string $email, string $password): void
    {
        $user = new User(
            id: UserId::generate()->toBytes(),
            name: $name,
            email: Email::fromString($email),
            passwordHash: PasswordHash::createFromPlain($password),
            role: Role::user(),
            isActive: true
        );
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->createdUserEmails[] = $email;
    }
}
