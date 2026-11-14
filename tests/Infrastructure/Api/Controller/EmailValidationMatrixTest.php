<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\Role;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Addresses that pass `Assert\Email(mode: 'html5')` but fail the domain
 * `filter_var` used to reach the service and end as a bare 500 (10.2).
 * One kernel boot per test keeps this file from dominating mutant runs.
 */
final class EmailValidationMatrixTest extends WebTestCase
{
    private const array DIVERGENT_ADDRESSES = ['a.@example.com', '.a@example.com'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testDivergentAddressesAreRejectedOnEveryEntryPoint(): void
    {
        $adminToken = $this->loginAsAdmin();

        foreach (self::DIVERGENT_ADDRESSES as $email) {
            $this->postJson('/api/register', [
                'name' => 'John Doe',
                'email' => $email,
                'password' => 'securePassword123',
            ]);
            $this->assertValidationRejected('register', $email);

            $this->postJson('/api/login', ['email' => $email, 'password' => 'securePassword123']);
            $this->assertValidationRejected('login', $email);

            $this->postJson('/api/admin/users', [
                'name' => 'John Doe',
                'email' => $email,
                'password' => 'securePassword123',
            ], $adminToken);
            $this->assertValidationRejected('admin create', $email);
        }
    }

    public function testTrimmedAddressStillRegisters(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Trimmed User',
            'email' => '  trimmed@example.com  ',
            'password' => 'securePassword123',
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    private function loginAsAdmin(): string
    {
        UserFactory::createOne([
            'email' => Email::fromString('admin@example.com'),
            'role' => Role::admin(),
        ]);

        $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => UserFactory::DEFAULT_PASSWORD]);
        self::assertResponseStatusCodeSame(200);

        return (string) ($this->payload()['access_token'] ?? '');
    }

    /**
     * @param array<string, string> $body
     */
    private function postJson(string $uri, array $body, ?string $token = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $this->client->request('POST', $uri, [], [], $server, (string) \json_encode($body));
    }

    private function assertValidationRejected(string $entryPoint, string $email): void
    {
        self::assertResponseStatusCodeSame(422, $entryPoint.' must reject '.$email);

        $payload = $this->payload();
        self::assertSame('Unprocessable Entity', $payload['error'] ?? null, $entryPoint);
        self::assertSame('Validation failed', $payload['message'] ?? null, $entryPoint);
        self::assertContains('Invalid email format', $payload['details'] ?? [], $entryPoint);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return (array) \json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
