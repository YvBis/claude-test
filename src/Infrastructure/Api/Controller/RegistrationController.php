<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use App\Application\DTO\RegisterUserDTO;
use App\Application\User\Service\RegistrationService;
use App\Domain\User\Exception\UserAlreadyExistsException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RegistrationController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    #[OA\Post(
        path: '/api/register',
        summary: 'Register a new user',
        description: 'Creates a new user account with name, email, and password. Returns the created user data.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', minLength: 2, maxLength: 100, example: 'John Doe'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'john@example.com'),
                    new OA\Property(property: 'password', type: 'string', minLength: 8, maxLength: 255, example: 'securePassword123'),
                ],
                type: 'object'
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'User registered successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '018f0a1b-2c3d-4e5f-6789-0123456789ab'),
                        new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                        new OA\Property(property: 'role', type: 'string', enum: ['user', 'admin'], example: 'user'),
                        new OA\Property(property: 'is_active', type: 'boolean', example: true),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-07-17T12:00:00Z'),
                        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-07-17T12:00:00Z'),
                    ],
                    required: ['id', 'name', 'email', 'role', 'is_active', 'created_at', 'updated_at']
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Validation error',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Validation failed'),
                        new OA\Property(property: 'details', type: 'array', items: new OA\Items(type: 'string'), example: ['Name cannot be empty', 'Invalid email format', 'Password must be at least 8 characters']),
                    ]
                )
            ),
            new OA\Response(
                response: 409,
                description: 'User with this email already exists',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Conflict'),
                        new OA\Property(property: 'message', type: 'string', example: 'User with email "john@example.com" already exists'),
                    ]
                )
            ),
        ]
    )]
    public function register(
        Request $request,
        RegistrationService $registrationService,
        SerializerInterface $serializer,
        ValidatorInterface $validator
    ): JsonResponse {
        $dto = $serializer->deserialize($request->getContent(), RegisterUserDTO::class, 'json');

        $errors = $validator->validate($dto);
        if (\count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[] = $error->getMessage();
            }

            return new JsonResponse([
                'error' => 'Validation failed',
                'details' => $messages,
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $user = $registrationService->register($dto);
        } catch (UserAlreadyExistsException $userAlreadyExistsException) {
            return new JsonResponse([
                'error' => 'Conflict',
                'message' => $userAlreadyExistsException->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        return new JsonResponse([
            'id' => $user->getId()->toString(),
            'name' => $user->getName(),
            'email' => $user->getEmail()->value(),
            'role' => $user->getRole()->value(),
            'is_active' => $user->isActive(),
            'created_at' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }
}
