<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api\Controller;

use App\Infrastructure\Api\Controller\AbstractApiController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Pins the error-envelope contract of the shared controller helpers: the
 * status/label pairs, that only 401 and 500 omit `message`, and that
 * validation errors carry `details` instead of `message`.
 */
final class AbstractApiControllerTest extends TestCase
{
    private AbstractApiController $controller;

    protected function setUp(): void
    {
        $this->controller = new class () extends AbstractApiController {
            public function callErrorResponse(int $status, string $error, ?string $message = null, ?array $details = null): JsonResponse
            {
                return $this->errorResponse($status, $error, $message, $details);
            }

            public function callUnauthorized(?string $message = null): JsonResponse
            {
                return $this->unauthorized($message);
            }

            public function callForbidden(string $message): JsonResponse
            {
                return $this->forbidden($message);
            }

            public function callNotFound(string $message): JsonResponse
            {
                return $this->notFound($message);
            }

            public function callBadRequest(string $message, ?array $details = null): JsonResponse
            {
                return $this->badRequest($message, $details);
            }

            public function callUnprocessable(string $message, ?array $details = null): JsonResponse
            {
                return $this->unprocessable($message, $details);
            }

            public function callConflict(string $message): JsonResponse
            {
                return $this->conflict($message);
            }

            public function callInternalError(): JsonResponse
            {
                return $this->internalError();
            }

            public function callCreateValidationErrorResponse(array $errors): JsonResponse
            {
                return $this->createValidationErrorResponse($errors);
            }
        };
    }

    public function testErrorResponseOmitsOptionalFieldsWhenNull(): void
    {
        $response = $this->controller->callErrorResponse(418, 'Teapot');

        $this->assertSame(418, $response->getStatusCode());
        $this->assertSame(['error' => 'Teapot'], $this->payload($response));
    }

    public function testErrorResponseCarriesMessageAndDetails(): void
    {
        $response = $this->controller->callErrorResponse(400, 'Bad Request', 'boom', ['field is required']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Bad Request', 'message' => 'boom', 'details' => ['field is required']],
            $this->payload($response),
        );
    }

    public function testUnauthorizedOmitsMessageByDefault(): void
    {
        $response = $this->controller->callUnauthorized();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['error' => 'Unauthorized'], $this->payload($response));
    }

    public function testInternalErrorOmitsMessage(): void
    {
        $response = $this->controller->callInternalError();

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['error' => 'Internal Server Error'], $this->payload($response));
    }

    public function testCreateValidationErrorResponseIs422WithDetailsOnly(): void
    {
        $errors = ['Name cannot be empty', 'Password must be at least 8 characters'];
        $response = $this->controller->callCreateValidationErrorResponse($errors);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Validation failed', 'details' => $errors],
            $this->payload($response),
        );
    }

    public function testBadRequestAndUnprocessableCarryClientInputReasonsInDetails(): void
    {
        $badRequest = $this->controller->callBadRequest('Invalid query parameters', ['Invalid limit']);
        $this->assertSame(400, $badRequest->getStatusCode());
        $this->assertSame(
            ['error' => 'Bad Request', 'message' => 'Invalid query parameters', 'details' => ['Invalid limit']],
            $this->payload($badRequest),
        );

        $unprocessable = $this->controller->callUnprocessable('Invalid content', ['Comment content cannot be empty']);
        $this->assertSame(422, $unprocessable->getStatusCode());
        $this->assertSame(
            ['error' => 'Unprocessable Entity', 'message' => 'Invalid content', 'details' => ['Comment content cannot be empty']],
            $this->payload($unprocessable),
        );
    }

    /**
     * @param array{int, string, string|null} $expected
     */
    #[DataProvider('helperProvider')]
    public function testStatusHelpers(string $helper, string $message, array $expected): void
    {
        $response = match ($helper) {
            'unauthorized' => $this->controller->callUnauthorized($message),
            'forbidden' => $this->controller->callForbidden($message),
            'notFound' => $this->controller->callNotFound($message),
            'badRequest' => $this->controller->callBadRequest($message),
            'unprocessable' => $this->controller->callUnprocessable($message),
            'conflict' => $this->controller->callConflict($message),
            default => self::fail('Unknown helper: '.$helper),
        };

        [$status, $label, $expectedMessage] = $expected;

        $this->assertSame($status, $response->getStatusCode());

        $payload = ['error' => $label];
        if (null !== $expectedMessage) {
            $payload['message'] = $expectedMessage;
        }

        $this->assertSame($payload, $this->payload($response));
    }

    /**
     * @return array<string, array{string, string, array{int, string, string|null}}>
     */
    public static function helperProvider(): array
    {
        return [
            'unauthorized with message' => ['unauthorized', 'Invalid credentials', [401, 'Unauthorized', 'Invalid credentials']],
            'forbidden' => ['forbidden', 'nope', [403, 'Forbidden', 'nope']],
            'notFound' => ['notFound', 'missing', [404, 'Not Found', 'missing']],
            'badRequest' => ['badRequest', 'bad', [400, 'Bad Request', 'bad']],
            'unprocessable' => ['unprocessable', 'invalid', [422, 'Unprocessable Entity', 'invalid']],
            'conflict' => ['conflict', 'exists', [409, 'Conflict', 'exists']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(JsonResponse $response): array
    {
        return \json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
