<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Single envelope for `/api` failures reaching `kernel.exception`
 * (except `/api/doc*`, which keeps the framework behavior).
 *
 * Priority -10: after the security `ExceptionListener` (1), which rewrites
 * `AccessDeniedException` to `AccessDeniedHttpException` or answers 401
 * itself via the entry point, and after `logKernelException` (0), which
 * already logs uncaught throwables — so this listener logs nothing. The
 * response stops propagation, shielding it from `onKernelException` (-128).
 *
 * Guard order matters: an already-set response (security, Lexik) wins;
 * `AccessDeniedHttpException` becomes the generic 403 envelope (the message
 * is never leaked); any other `HttpExceptionInterface` keeps the framework
 * rendering; only a non-HTTP throwable becomes the bare 500 envelope.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', -10]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (!\str_starts_with($path, '/api') || \str_starts_with($path, '/api/doc')) {
            return;
        }

        if ($event->getResponse() instanceof Response) {
            return;
        }

        $throwable = $event->getThrowable();

        // Specific before general: AccessDeniedHttpException IS-A
        // HttpExceptionInterface, so this branch must stay first — the guard
        // below would otherwise swallow the 403 envelope into a no-op.
        if ($throwable instanceof AccessDeniedHttpException) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Forbidden', 'message' => 'Forbidden'],
                Response::HTTP_FORBIDDEN,
            ));

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => 'Internal Server Error'],
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ));
    }
}
