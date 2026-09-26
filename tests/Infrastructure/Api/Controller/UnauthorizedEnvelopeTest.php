<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTManager;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the unified 401 envelope at the HTTP boundary: missing, malformed and
 * expired tokens all answer 401 with `error: Unauthorized` plus the Lexik
 * text under `message`, regardless of which firewall path rejected them.
 */
final class UnauthorizedEnvelopeTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testMissingTokenGivesUnauthorizedEnvelope(): void
    {
        // /api/tags is auth-only (unlike GET /api/collections, which is
        // PUBLIC_ACCESS and answers from the controller guard instead).
        $this->client->request('GET', '/api/tags');

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame(
            ['error' => 'Unauthorized', 'message' => 'JWT Token not found'],
            \json_decode((string) $this->client->getResponse()->getContent(), true),
        );
    }

    public function testMalformedTokenGivesUnauthorizedEnvelope(): void
    {
        $this->client->request('GET', '/api/tags', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer invalid.token.here',
        ]);

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame(
            ['error' => 'Unauthorized', 'message' => 'Invalid JWT Token'],
            \json_decode((string) $this->client->getResponse()->getContent(), true),
        );
    }

    public function testExpiredTokenGivesUnauthorizedEnvelope(): void
    {
        $user = $this->createUser('Expired Token User', 'expired-token@example.com');

        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);
        $this->assertInstanceOf(JWTManager::class, $jwtManager);
        $expiredToken = $jwtManager->createFromPayload($user, ['exp' => \time() - 3600]);

        $this->client->request('GET', '/api/tags', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$expiredToken,
        ]);

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame(
            ['error' => 'Unauthorized', 'message' => 'Expired JWT Token'],
            \json_decode((string) $this->client->getResponse()->getContent(), true),
        );
    }

    private function createUser(string $name, string $email): User
    {
        $user = new User(
            id: UserId::generate()->toBytes(),
            name: $name,
            email: Email::fromString($email),
            passwordHash: PasswordHash::createFromPlain('password123'),
            role: Role::user(),
            isActive: true,
        );
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
