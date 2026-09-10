<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use App\Application\Exception\ValidationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController as BaseAbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Abstract base controller for API endpoints providing common validation utilities.
 */
abstract class AbstractApiController extends BaseAbstractController
{
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
}
