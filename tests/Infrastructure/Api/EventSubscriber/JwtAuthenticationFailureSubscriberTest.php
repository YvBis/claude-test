<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\EventSubscriber;

use App\Infrastructure\Api\EventSubscriber\JwtAuthenticationFailureSubscriber;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Response\JWTAuthenticationFailureResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the unified 401 envelope: every Lexik JWT failure (missing, invalid,
 * expired token) answers 401 with `error: Unauthorized` and the Lexik text
 * under `message`, instead of the bundle default `{code, message}` body.
 */
final class JwtAuthenticationFailureSubscriberTest extends TestCase
{
    private JwtAuthenticationFailureSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->subscriber = new JwtAuthenticationFailureSubscriber();
    }

    public function testSubscribesToAllThreeJwtFailureEvents(): void
    {
        $subscribed = JwtAuthenticationFailureSubscriber::getSubscribedEvents();

        $this->assertSame(
            [
                Events::JWT_NOT_FOUND => 'onAuthenticationFailure',
                Events::JWT_INVALID => 'onAuthenticationFailure',
                Events::JWT_EXPIRED => 'onAuthenticationFailure',
            ],
            $subscribed,
        );
    }

    /**
     * @param class-string $eventClass
     */
    #[DataProvider('jwtFailureProvider')]
    public function testJwtFailureBecomesUnauthorizedEnvelope(string $eventClass, string $lexikMessage): void
    {
        $event = new $eventClass(null, new JWTAuthenticationFailureResponse($lexikMessage));

        $this->subscriber->onAuthenticationFailure($event);

        $response = $event->getResponse();
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        $this->assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        $this->assertSame(
            ['error' => 'Unauthorized', 'message' => $lexikMessage],
            \json_decode((string) $response->getContent(), true),
        );
    }

    /**
     * @return iterable<string, array{0: class-string, 1: string}>
     */
    public static function jwtFailureProvider(): iterable
    {
        yield 'missing token' => [JWTNotFoundEvent::class, 'JWT Token not found'];
        yield 'invalid token' => [JWTInvalidEvent::class, 'Invalid JWT Token'];
        yield 'expired token' => [JWTExpiredEvent::class, 'Expired JWT Token'];
    }
}
