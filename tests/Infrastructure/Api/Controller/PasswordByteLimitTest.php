<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use App\Domain\User\ValueObject\PasswordHash;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * review-15: bcrypt reads at most 72 bytes, so a longer password would be
 * verifiable by its 72-byte prefix. Creation rejects it; login deliberately
 * does not (legacy hashes keep working), and the null-byte rejection is shared
 * because password_hash() throws ValueError, which would surface as a 500.
 */
final class PasswordByteLimitTest extends WebTestCase
{
    private KernelBrowser $client;

    private string $userId = '';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        if ('' !== $this->userId) {
            $em = static::getContainer()->get('doctrine.orm.entity_manager');
            $em->getConnection()->executeStatement(
                'DELETE FROM users WHERE id = UNHEX(REPLACE(?, "-", ""))',
                [\preg_replace('/-/', '', $this->userId)]
            );
            $em->clear();
        }

        parent::tearDown();
    }

    public function testRegistrationRejectsPasswordLongerThan72Bytes(): void
    {
        $this->register(\str_repeat('a', PasswordHash::BCRYPT_MAX_BYTES + 1));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Validation failed', $this->payload()['message'] ?? null);
        self::assertStringContainsString('72 bytes', \implode(' ', $this->payload()['details'] ?? []));
    }

    public function testRegistrationAcceptsPasswordOfExactly72Bytes(): void
    {
        $this->register(\str_repeat('a', PasswordHash::BCRYPT_MAX_BYTES));

        self::assertResponseStatusCodeSame(201);
        $this->userId = (string) $this->payload()['id'];
    }

    public function testRegistrationMeasuresBytesNotCharacters(): void
    {
        // 25 emoji are 25 characters but 100 bytes: a character-based bound
        // would accept them, and bcrypt would verify only the first 18.
        $this->register(\str_repeat("\u{1F600}", 25));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('72 bytes', \implode(' ', $this->payload()['details'] ?? []));
    }

    public function testRegistrationRejectsNullByte(): void
    {
        $this->register("valid-password\0tail");

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('null bytes', \implode(' ', $this->payload()['details'] ?? []));
    }

    public function testLoginRejectsNullByteInsteadOfFailingWith500(): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'email' => 'nobody@example.com',
            'password' => "valid-password\0tail",
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('null bytes', \implode(' ', $this->payload()['details'] ?? []));
    }

    public function testLegacyHashFromLongPasswordStillLogsInWithFullAndTruncatedPassword(): void
    {
        // A hash created before the cap: full plaintext is 100 chars, bcrypt
        // used only its first 72 bytes. Both forms must authenticate, which is
        // exactly why login stays uncapped.
        $plain = \str_repeat('b', 100);
        $hash = \password_hash($plain, PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertIsString($hash);

        $email = 'legacy_'.\str_replace('.', '', \uniqid('', true)).'@example.com';
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->getConnection()->executeStatement(
            'INSERT INTO users (id, name, email, password_hash, role, is_active, created_at, updated_at)
             VALUES (UNHEX(REPLACE(UUID(), "-", "")), ?, ?, ?, ?, 1, NOW(), NOW())',
            ['Legacy User', $email, $hash, 'user'],
        );

        $this->login($email, $plain);
        self::assertResponseStatusCodeSame(200);

        $this->login($email, \substr($plain, 0, PasswordHash::BCRYPT_MAX_BYTES));
        self::assertResponseStatusCodeSame(200);
    }

    private function register(string $password): void
    {
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'name' => 'Byte Limit',
            'email' => 'bytes_'.\str_replace('.', '', \uniqid('', true)).'@example.com',
            'password' => $password,
        ], \JSON_THROW_ON_ERROR));
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], \json_encode([
            'email' => $email,
            'password' => $password,
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        /** @var array<string, mixed> $payload */
        $payload = \json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
