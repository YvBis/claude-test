<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use App\Application\Exception\ValidationException;
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
     * Create a standardized validation error response.
     *
     * @param string[] $errors
     */
    protected function createValidationErrorResponse(array $errors): JsonResponse
    {
        return new JsonResponse([
            'error' => 'Validation failed',
            'details' => $errors,
        ], Response::HTTP_BAD_REQUEST);
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
