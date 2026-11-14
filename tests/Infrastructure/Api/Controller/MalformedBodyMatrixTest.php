<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\Role;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A body that parses as JSON but omits required DTO arguments used to escape as
 * a bare 500 (`MissingConstructorArgumentsException` is a serializer
 * RuntimeException, which no controller caught). It is a validation failure:
 * 422 with the missing field names in `details` (10.4).
 */
final class MalformedBodyMatrixTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testEmptyObjectOnCreationEndpointsIsAValidationFailure(): void
    {
        $token = $this->loginAsAdmin();

        $this->send('POST', '/api/register', '{}');
        $this->assertMissingFields(['name', 'email', 'password']);

        $this->send('POST', '/api/login', '{}');
        $this->assertMissingFields(['email', 'password']);

        $this->send('POST', '/api/collections', '{}', $token);
        $this->assertMissingFields(['name', 'theme']);

        $this->send('POST', '/api/admin/users', '{}', $token);
        $this->assertMissingFields(['name', 'email', 'password']);
    }

    public function testEmptyObjectOnItemCreateIsAValidationFailure(): void
    {
        $token = $this->loginAsAdmin();

        [$collectionId] = $this->createCollection($token);

        $this->send('POST', '/api/collections/'.$collectionId.'/items', '{}', $token);
        $this->assertMissingFields(['name']);
    }

    public function testBrokenJsonStaysBadRequest(): void
    {
        $this->send('POST', '/api/login', '{"email":');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Bad Request', $this->payload()['error'] ?? null);
        self::assertSame('Malformed request body', $this->payload()['message'] ?? null);
    }

    public function testEmptyObjectOnPatchStaysValidationFailure(): void
    {
        $token = $this->loginAsAdmin();
        [$collectionId] = $this->createCollection($token);

        $this->send('PATCH', '/api/collections/'.$collectionId, '{}', $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Unprocessable Entity', $this->payload()['error'] ?? null);
    }

    public function testMissingFieldInsideNestedSlotIsReported(): void
    {
        $token = $this->loginAsAdmin();
        [$collectionId] = $this->createCollection($token);

        $this->send('POST', '/api/collections/'.$collectionId.'/items', '{"name":"x","slots":[{}]}', $token);

        $this->assertMissingFields(['type', 'slot']);
    }

    /**
     * @param list<string> $fields
     */
    private function assertMissingFields(array $fields): void
    {
        $payload = $this->payload();

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Unprocessable Entity', $payload['error'] ?? null);
        self::assertSame('Validation failed', $payload['message'] ?? null);

        $details = (array) ($payload['details'] ?? []);

        foreach ($fields as $field) {
            self::assertContains('Missing required field: '.$field, $details);
        }
    }

    /**
     * @return array{string, string}
     */
    private function createCollection(string $token): array
    {
        $name = 'Matrix '.\bin2hex(\random_bytes(4));

        $this->send('POST', '/api/collections', (string) \json_encode([
            'name' => $name,
            'theme' => 'Books',
        ]), $token);

        self::assertResponseStatusCodeSame(201);
        $payload = $this->payload();

        return [(string) $payload['id'], $name];
    }

    private function loginAsAdmin(): string
    {
        UserFactory::createOne([
            'email' => Email::fromString('admin@example.com'),
            'role' => Role::admin(),
        ]);

        $this->send('POST', '/api/login', (string) \json_encode([
            'email' => 'admin@example.com',
            'password' => UserFactory::DEFAULT_PASSWORD,
        ]));

        self::assertResponseStatusCodeSame(200);

        return (string) ($this->payload()['access_token'] ?? '');
    }

    private function send(string $method, string $uri, string $body, ?string $token = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $this->client->request($method, $uri, [], [], $server, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
