<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\EventSubscriber;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTFailureEventInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Response\JWTAuthenticationFailureResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unifies the 401 body for all three Lexik JWT failure paths (missing,
 * invalid, expired token) to the API envelope `{error, message}`.
 *
 * Two of the three paths never reach `kernel.exception` (the authenticator
 * returns a ready `Response`), so this is the only chokepoint covering all
 * of them. The Lexik text is kept under `message`; the bundle default
 * `{code, message}` body is dropped.
 */
final class JwtAuthenticationFailureSubscriber implements EventSubscriberInterface
{
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_NOT_FOUND => 'onAuthenticationFailure',
            Events::JWT_INVALID => 'onAuthenticationFailure',
            Events::JWT_EXPIRED => 'onAuthenticationFailure',
        ];
    }

    public function onAuthenticationFailure(JWTFailureEventInterface $event): void
    {
        $message = null;
        $response = $event->getResponse();
        if ($response instanceof JWTAuthenticationFailureResponse) {
            $message = $response->getMessage();
        }

        $payload = ['error' => 'Unauthorized'];
        if (null !== $message && '' !== $message) {
            $payload['message'] = $message;
        }

        $event->setResponse(new JsonResponse(
            $payload,
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer'],
        ));
    }
}
