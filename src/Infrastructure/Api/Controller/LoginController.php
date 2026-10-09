<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use App\Application\Exception\ValidationException;
use App\Application\User\DTO\LoginUserDTO;
use App\Application\User\DTO\UserDTO;
use App\Application\User\Service\AuthenticationService;
use App\Domain\User\Exception\InvalidCredentialsException;
use App\Domain\User\Exception\UserDeactivatedException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
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
                            ref: '#/components/schemas/User'
                        ),
                    ],
                    required: ['access_token', 'token_type', 'expires_in', 'user']
                )
            ),
            new OA\Response(response: 400, description: 'Bad request (malformed body)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 401, description: 'Invalid credentials', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 403, description: 'User account deactivated', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
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
        } catch (NotEncodableValueException|NotNormalizableValueException) {
            return $this->badRequest('Malformed request body');
        }

        try {
            $result = $authenticationService->authenticate($dto);
        } catch (InvalidCredentialsException) {
            return $this->unauthorized('Invalid credentials');
        } catch (UserDeactivatedException) {
            return $this->forbidden('User account is deactivated');
        }

        return new JsonResponse([
            'access_token' => $result->accessToken,
            'token_type' => $result->tokenType,
            'expires_in' => $result->expiresIn,
            'user' => UserDTO::fromEntity($result->user)->toArray(),
        ], Response::HTTP_OK);
    }
}
