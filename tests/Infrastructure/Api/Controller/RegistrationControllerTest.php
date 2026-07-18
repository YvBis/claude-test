<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use App\Domain\User\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RegistrationControllerTest extends KernelTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRegisterReturns201AndUserData(): void
    {
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "name": "John Doe",
            "email": "john@example.com",
            "password": "securePassword123"
        }');

        $this->assertResponseStatusCodeSame(201);
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $response = \json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('id', $response);
        $this->assertSame('John Doe', $response['name']);
        $this->assertSame('john@example.com', $response['email']);
        $this->assertSame('user', $response['role']);
        $this->assertTrue($response['is_active']);
        $this->assertArrayHasKey('created_at', $response);
        $this->assertArrayHasKey('updated_at', $response);
        $this->assertArrayNotHasKey('password', $response);
    }

    public function testRegisterValidatesNameRequired(): void
    {
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "name": "",
            "email": "john@example.com",
            "password": "securePassword123"
        }');

        $this->assertResponseStatusCodeSame(400);
        $response = \json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Validation failed', $response['error']);
        $this->assertContains('Name cannot be empty', $response['details']);
    }

    public function testRegisterValidatesEmailFormat(): void
    {
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "name": "John Doe",
            "email": "not-an-email",
            "password": "securePassword123"
        }');

        $this->assertResponseStatusCodeSame(400);
        $response = \json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Validation failed', $response['error']);
        $this->assertContains('Invalid email format', $response['details']);
    }

    public function testRegisterValidatesPasswordMinLength(): void
    {
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "name": "John Doe",
            "email": "john@example.com",
            "password": "short"
        }');

        $this->assertResponseStatusCodeSame(400);
        $response = \json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Validation failed', $response['error']);
        $this->assertContains('Password must be at least 8 characters', $response['details']);
    }

    public function testRegisterReturns409WhenEmailExists(): void
    {
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "name": "John Doe",
            "email": "john@example.com",
            "password": "securePassword123"
        }');
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "name": "Jane Doe",
            "email": "john@example.com",
            "password": "anotherPassword456"
        }');

        $this->assertResponseStatusCodeSame(409);
        $response = \json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Conflict', $response['error']);
        $this->assertStringContainsString('john@example.com', $response['message']);
    }

    public function testRegisterTrimsNameAndLowercasesEmail(): void
    {
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "name": "  John Doe  ",
            "email": "  JOHN@EXAMPLE.COM  ",
            "password": "securePassword123"
        }');

        $this->assertResponseStatusCodeSame(201);
        $response = \json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('John Doe', $response['name']);
        $this->assertSame('john@example.com', $response['email']);
    }

    public function testUserPersistedInDatabase(): void
    {
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], '{
            "name": "John Doe",
            "email": "john@example.com",
            "password": "securePassword123"
        }');

        $this->assertResponseStatusCodeSame(201);
        $response = \json_decode($this->client->getResponse()->getContent(), true);
        $userId = $response['id'];

        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        $repository = $entityManager->getRepository(User::class);
        $user = $repository->find($userId);

        $this->assertNotNull($user);
        $this->assertSame('John Doe', $user->getName());
        $this->assertSame('john@example.com', $user->getEmail()->value());
        $this->assertTrue($user->getPasswordHash()->verify('securePassword123'));
    }
}
