<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use App\Application\Exception\ValidationException;
use App\Application\User\DTO\LoginUserDTO;
use App\Application\User\Service\AuthenticationService;
use App\Domain\User\Exception\InvalidCredentialsException;
use App\Domain\User\Exception\UserDeactivatedException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class LoginController extends AbstractApiController
{
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    #[OA\Post(
        path: '/api/login',
        security: [],
        summary: 'User login',
        description: 'Authenticate user and return JWT access token',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'john@example.com'),
                    new OA\Property(property: 'password', type: 'string', minLength: 8, maxLength: 255, example: 'securePassword123'),
                ],
                type: 'object'
            )
        ),
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login successful',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'access_token', type: 'string', example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...'),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                        new OA\Property(property: 'expires_in', type: 'integer', example: 3600),
                        new OA\Property(
                            property: 'user',
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
                        ),
                    ],
                    required: ['access_token', 'token_type', 'expires_in', 'user']
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Validation error',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Validation failed'),
                        new OA\Property(property: 'details', type: 'array', items: new OA\Items(type: 'string'), example: ['Invalid email format', 'Password is required']),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Invalid credentials',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Unauthorized'),
                        new OA\Property(property: 'message', type: 'string', example: 'Invalid credentials'),
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'User account deactivated',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Forbidden'),
                        new OA\Property(property: 'message', type: 'string', example: 'User account is deactivated'),
                    ]
                )
            ),
        ]
    )]
    public function login(
        Request $request,
        AuthenticationService $authenticationService,
        SerializerInterface $serializer,
        ValidatorInterface $validator
    ): JsonResponse {
        try {
            $dto = $this->deserializeAndValidate($request->getContent(), LoginUserDTO::class, $serializer, $validator);
        } catch (ValidationException $validationException) {
            return $this->createValidationErrorResponse($validationException->getDetails());
        }

        try {
            $result = $authenticationService->authenticate($dto);
        } catch (InvalidCredentialsException) {
            return new JsonResponse([
                'error' => 'Unauthorized',
                'message' => 'Invalid credentials',
            ], Response::HTTP_UNAUTHORIZED);
        } catch (UserDeactivatedException) {
            return new JsonResponse([
                'error' => 'Forbidden',
                'message' => 'User account is deactivated',
            ], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'access_token' => $result->accessToken,
            'token_type' => $result->tokenType,
            'expires_in' => $result->expiresIn,
            'user' => [
                'id' => $result->user->getId()->toString(),
                'name' => $result->user->getName(),
                'email' => $result->user->getEmail()->value(),
                'role' => $result->user->getRole()->value(),
                'is_active' => $result->user->isActive(),
                'created_at' => $result->user->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updated_at' => $result->user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
        ], Response::HTTP_OK);
    }
}
