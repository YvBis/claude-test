<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\EventSubscriber;

use App\Infrastructure\Api\EventSubscriber\ApiExceptionSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pins the `kernel.exception` envelope for `/api` paths: access-denied
 * becomes the 403 envelope, unexpected throwables become the bare 500
 * envelope, and everything else (already-answered events, framework HTTP
 * errors, non-API paths) is left untouched.
 */
#[AllowMockObjectsWithoutExpectations]
final class ApiExceptionSubscriberTest extends TestCase
{
    private ApiExceptionSubscriber $subscriber;
    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        $this->subscriber = new ApiExceptionSubscriber();
        $this->kernel = $this->createMock(HttpKernelInterface::class);
    }

    public function testSubscribesToKernelExceptionBeforeErrorRenderer(): void
    {
        $this->assertSame(
            [KernelEvents::EXCEPTION => ['onKernelException', -10]],
            ApiExceptionSubscriber::getSubscribedEvents(),
        );
    }

    public function testAccessDeniedBecomesForbiddenEnvelope(): void
    {
        $event = $this->apiEvent(new AccessDeniedHttpException('Sensitive internals'));

        $this->subscriber->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Forbidden', 'message' => 'Forbidden'],
            \json_decode((string) $response->getContent(), true),
        );
    }

    public function testUnexpectedThrowableBecomesBareInternalServerErrorEnvelope(): void
    {
        $event = $this->apiEvent(new \RuntimeException('DB exploded'));

        $this->subscriber->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Internal Server Error'],
            \json_decode((string) $response->getContent(), true),
        );
    }

    public function testAlreadyAnsweredEventIsUntouched(): void
    {
        $event = $this->apiEvent(new \RuntimeException('DB exploded'));
        $original = new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        $event->setResponse($original);

        $this->subscriber->onKernelException($event);

        $this->assertSame($original, $event->getResponse());
    }

    public function testFrameworkHttpErrorIsUntouched(): void
    {
        $event = $this->apiEvent(new HttpException(Response::HTTP_CONFLICT, 'Conflict here'));

        $this->subscriber->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    public function testNonApiPathIsUntouched(): void
    {
        $event = new ExceptionEvent(
            $this->kernel,
            Request::create('/health', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('DB exploded'),
        );

        $this->subscriber->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    public function testApiDocKeepsFrameworkBehavior(): void
    {
        $event = new ExceptionEvent(
            $this->kernel,
            Request::create('/api/doc.json', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('DB exploded'),
        );

        $this->subscriber->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    private function apiEvent(\Throwable $throwable): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->kernel,
            Request::create('/api/items/1', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }
}
