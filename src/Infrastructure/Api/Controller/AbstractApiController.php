<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use App\Application\Common\DTO\ArrayableInterface;
use App\Application\Exception\ValidationException;
use App\Application\Item\Service\ItemService;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\Exception\ItemNotFoundException;
use App\Domain\User\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController as BaseAbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Abstract base controller for API endpoints providing common validation utilities.
 */
abstract class AbstractApiController extends BaseAbstractController
{
    protected const int DEFAULT_LIMIT = 50;

    protected const int MIN_LIMIT = 1;

    protected const int MAX_LIMIT = 100;

    protected const int DEFAULT_OFFSET = 0;

    /**
     * Deserialize and validate a DTO from request content.
     *
     * @param class-string<object> $dtoClass
     *
     * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface
     * @throws ValidationException
     */
    protected function deserializeAndValidate(string $content, string $dtoClass, SerializerInterface $serializer, ValidatorInterface $validator): object
    {
        $dto = $serializer->deserialize($content, $dtoClass, 'json');

        $errors = $validator->validate($dto);
        if (\count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[] = $error->getMessage();
            }

            throw new ValidationException('Validation failed', $messages);
        }

        return $dto;
    }

    /**
     * Build a standardized error envelope.
     *
     * `message` is omitted when null, preserving the 401/500 contract which
     * answers with the error label only. `details` carries the per-field list
     * for validation errors.
     *
     * @param array<int, string>|null $details
     */
    protected function errorResponse(int $status, string $error, ?string $message = null, ?array $details = null): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = ['error' => $error];

        if (null !== $message) {
            $payload['message'] = $message;
        }

        if (null !== $details) {
            $payload['details'] = $details;
        }

        return new JsonResponse($payload, $status);
    }

    protected function unauthorized(?string $message = null): JsonResponse
    {
        return $this->errorResponse(Response::HTTP_UNAUTHORIZED, 'Unauthorized', $message);
    }

    protected function forbidden(string $message): JsonResponse
    {
        return $this->errorResponse(Response::HTTP_FORBIDDEN, 'Forbidden', $message);
    }

    protected function notFound(string $message): JsonResponse
    {
        return $this->errorResponse(Response::HTTP_NOT_FOUND, 'Not Found', $message);
    }

    /**
     * Client-input errors carry a stable, generic `message`; the specifics of
     * why the input was rejected go under `details`, so the public contract
     * never depends on internal exception wording.
     *
     * @param array<int, string>|null $details
     */
    protected function badRequest(string $message, ?array $details = null): JsonResponse
    {
        return $this->errorResponse(Response::HTTP_BAD_REQUEST, 'Bad Request', $message, $details);
    }

    /**
     * @param array<int, string>|null $details
     */
    protected function unprocessable(string $message, ?array $details = null): JsonResponse
    {
        return $this->errorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, 'Unprocessable Entity', $message, $details);
    }

    protected function conflict(string $message): JsonResponse
    {
        return $this->errorResponse(Response::HTTP_CONFLICT, 'Conflict', $message);
    }

    protected function internalError(): JsonResponse
    {
        return $this->errorResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'Internal Server Error');
    }

    /**
     * Create a standardized validation error response: 422 with the list of
     * per-field messages under `details`.
     *
     * @param string[] $errors
     */
    protected function createValidationErrorResponse(array $errors): JsonResponse
    {
        return $this->errorResponse(Response::HTTP_UNPROCESSABLE_ENTITY, 'Validation failed', null, $errors);
    }

    /**
     * Serialize a list of ArrayableInterface DTOs to plain snake_case arrays.
     *
     * JsonResponse encodes objects with json_encode, which would leak the DTO
     * property names (camelCase) and raw \DateTimeImmutable objects instead of
     * the documented snake_case/ATOM contract. Routing the list through
     * toArray() keeps list payloads identical in shape to single-resource ones.
     *
     * @param array<ArrayableInterface> $dtos
     *
     * @return array<array<string, mixed>>
     */
    protected function toArrayPayload(array $dtos): array
    {
        return \array_map(
            static fn (ArrayableInterface $dto): array => $dto->toArray(),
            $dtos,
        );
    }

    /**
     * Authorization rule for item and collection mutations: the owner of the
     * resource or an administrator may manage it. Social content moderation is
     * handled by `App\Infrastructure\Security\Voter\SocialContentVoter` instead.
     */
    protected function canManage(User $user, User $owner): bool
    {
        return $user->getRole()->isAdmin() || $user->getId()->toString() === $owner->getId()->toString();
    }

    /**
     * Load an item by raw id, mapping both a malformed UUID and a missing item
     * to null so the caller can answer 404.
     */
    protected function findItemOrNull(string $itemId, ItemService $itemService): ?Item
    {
        try {
            return $itemService->getById($itemId);
        } catch (ItemNotFoundException|\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Parse and validate the ?limit/?offset pagination query parameters.
     *
     * @throws \InvalidArgumentException
     *
     * @return array{int, int} [limit, offset]; limit defaults to self::DEFAULT_LIMIT (self::MIN_LIMIT..self::MAX_LIMIT), offset to self::DEFAULT_OFFSET (>= 0)
     */
    protected function parsePagination(Request $request): array
    {
        $limit = $request->query->get('limit');
        $offset = $request->query->get('offset');

        if (null !== $limit && '' !== $limit && (!\is_string($limit) || !\ctype_digit($limit))) {
            throw new \InvalidArgumentException('Invalid limit: must be a non-negative integer');
        }

        if (null !== $offset && '' !== $offset && (!\is_string($offset) || !\ctype_digit($offset))) {
            throw new \InvalidArgumentException('Invalid offset: must be a non-negative integer');
        }

        $limit = (int) ($limit ?? self::DEFAULT_LIMIT);
        $offset = (int) ($offset ?? self::DEFAULT_OFFSET);

        if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(\sprintf('Invalid limit: must be between %d and %d', self::MIN_LIMIT, self::MAX_LIMIT));
        }

        if ($offset < 0) {
            throw new \InvalidArgumentException('Invalid offset: must be a non-negative integer');
        }

        return [$limit, $offset];
    }
}
