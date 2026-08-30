<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use App\Application\Collection\DTO\CreateCollectionDTO;
use App\Application\Collection\DTO\UpdateCollectionDTO;
use App\Application\Collection\Service\CollectionService;
use App\Application\Exception\ValidationException;
use App\Domain\Collection\Exception\CollectionNotFoundException;
use App\Domain\User\Entity\User;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CollectionController extends AbstractApiController
{
    #[Route('/api/collections', name: 'api_collection_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/collections',
        security: [['Bearer' => []]],
        summary: 'Create a new collection',
        description: 'Creates a new collection for the authenticated user.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'theme'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', minLength: 3, maxLength: 100, example: 'My Book Collection'),
                    new OA\Property(property: 'theme', type: 'string', enum: ['books', 'games', 'movies', 'drinks'], example: 'books'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 500, example: 'A collection of my favorite books'),
                    new OA\Property(property: 'image', type: 'string', maxLength: 500, example: 'https://example.com/cover.jpg'),
                ],
                type: 'object'
            )
        ),
        tags: ['Collections'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Collection created successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '018f0a1b-2c3d-4e5f-6789-0123456789ab'),
                        new OA\Property(property: 'name', type: 'string', example: 'My Book Collection'),
                        new OA\Property(property: 'theme', type: 'string', example: 'books'),
                        new OA\Property(property: 'description', type: 'string', example: 'A collection of my favorite books'),
                        new OA\Property(property: 'image', type: 'string', example: 'https://example.com/cover.jpg'),
                        new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', example: '018f0a1b-2c3d-4e5f-6789-0123456789ab'),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-07-17T12:00:00Z'),
                        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-07-17T12:00:00Z'),
                    ],
                    required: ['id', 'name', 'theme', 'owner_id', 'created_at', 'updated_at']
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Validation error',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Validation failed'),
                        new OA\Property(property: 'details', type: 'array', items: new OA\Items(type: 'string'), example: ['Collection name must be at least 3 characters', 'Invalid theme: foo. Allowed: books, games, movies, drinks']),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Unauthorized'),
                        new OA\Property(property: 'message', type: 'string', example: 'JWT Token not found'),
                    ]
                )
            ),
        ]
    )]
    public function create(
        Request $request,
        CollectionService $collectionService,
        SerializerInterface $serializer,
        ValidatorInterface $validator
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $dto = $this->deserializeAndValidate($request->getContent(), CreateCollectionDTO::class, $serializer, $validator);
        } catch (ValidationException $validationException) {
            return $this->createValidationErrorResponse($validationException->getDetails());
        }

        try {
            $collection = $collectionService->create($dto, $user);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Internal Server Error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(
            $collectionService->toDTO($collection)->toArray(),
            Response::HTTP_CREATED
        );
    }

    #[Route('/api/collections/{id}', name: 'api_collection_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/collections/{id}',
        security: [['Bearer' => []]],
        summary: 'Get a collection by ID',
        description: 'Returns a single collection if it belongs to the authenticated user.',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        tags: ['Collections'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Collection retrieved successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '018f0a1b-2c3d-4e5f-6789-0123456789ab'),
                        new OA\Property(property: 'name', type: 'string', example: 'My Book Collection'),
                        new OA\Property(property: 'theme', type: 'string', example: 'books'),
                        new OA\Property(property: 'description', type: 'string', example: 'A collection of my favorite books'),
                        new OA\Property(property: 'image', type: 'string', example: 'https://example.com/cover.jpg'),
                        new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', example: '018f0a1b-2c3d-4e5f-6789-0123456789ab'),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-07-17T12:00:00Z'),
                        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-07-17T12:00:00Z'),
                    ],
                    required: ['id', 'name', 'theme', 'owner_id', 'created_at', 'updated_at']
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Unauthorized'),
                        new OA\Property(property: 'message', type: 'string', example: 'JWT Token not found'),
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'Forbidden',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Forbidden'),
                        new OA\Property(property: 'message', type: 'string', example: 'You do not have permission to access this collection'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Collection not found',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Not Found'),
                        new OA\Property(property: 'message', type: 'string', example: 'Collection with id "018f0a1b-2c3d-4e5f-6789-0123456789ab" not found'),
                    ]
                )
            ),
        ]
    )]
    public function get(string $id, CollectionService $collectionService): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $collection = $collectionService->getById($id);
        } catch (CollectionNotFoundException $e) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }

        // Authorization: only owner can view
        if ($collection->getOwner()->getId()->toString() !== $user->getId()->toString()) {
            return new JsonResponse([
                'error' => 'Forbidden',
                'message' => 'You do not have permission to access this collection',
            ], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(
            $collectionService->toDTO($collection)->toArray(),
            Response::HTTP_OK
        );
    }

    #[Route('/api/collections', name: 'api_collection_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/collections',
        security: [['Bearer' => []]],
        summary: 'List all collections for the authenticated user',
        description: 'Returns a paginated list of collections owned by the authenticated user.',
        parameters: [
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 50)
            ),
            new OA\Parameter(
                name: 'offset',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 0)
            ),
        ],
        tags: ['Collections'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Collections retrieved successfully',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '018f0a1b-2c3d-4e5f-6789-0123456789ab'),
                            new OA\Property(property: 'name', type: 'string', example: 'My Book Collection'),
                            new OA\Property(property: 'theme', type: 'string', example: 'books'),
                            new OA\Property(property: 'description', type: 'string', example: 'A collection of my favorite books'),
                            new OA\Property(property: 'image', type: 'string', example: 'https://example.com/cover.jpg'),
                            new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', example: '018f0a1b-2c3d-4e5f-6789-0123456789ab'),
                            new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-07-17T12:00:00Z'),
                            new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-07-17T12:00:00Z'),
                        ]
                    )
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Unauthorized'),
                        new OA\Property(property: 'message', type: 'string', example: 'JWT Token not found'),
                    ]
                )
            ),
        ]
    )]
    public function list(Request $request, CollectionService $collectionService): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $limit = (int) ($request->query->get('limit') ?? 50);
        $offset = (int) ($request->query->get('offset') ?? 0);

        $collections = $collectionService->listByOwner($user, $limit, $offset);
        $dtos = $collectionService->toDTOList($collections);

        return new JsonResponse($dtos, Response::HTTP_OK);
    }

    #[Route('/api/collections/{id}', name: 'api_collection_update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/collections/{id}',
        security: [['Bearer' => []]],
        summary: 'Update a collection',
        description: 'Updates an existing collection (only name, description, image can be changed).',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', minLength: 3, maxLength: 100, example: 'My Updated Book Collection'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 500, example: 'An updated description'),
                    new OA\Property(property: 'image', type: 'string', maxLength: 500, example: 'https://example.com/new-cover.jpg'),
                ],
                type: 'object'
            )
        ),
        tags: ['Collections'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Collection updated successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '018f0a1b-2c3d-4e5f-6789-0123456789ab'),
                        new OA\Property(property: 'name', type: 'string', example: 'My Updated Book Collection'),
                        new OA\Property(property: 'theme', type: 'string', example: 'books'),
                        new OA\Property(property: 'description', type: 'string', example: 'An updated description'),
                        new OA\Property(property: 'image', type: 'string', example: 'https://example.com/new-cover.jpg'),
                        new OA\Property(property: 'owner_id', type: 'string', format: 'uuid', example: '018f0a1b-2c3d-4e5f-6789-0123456789ab'),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-07-17T12:00:00Z'),
                        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-07-17T12:00:00Z'),
                    ],
                    required: ['id', 'name', 'theme', 'owner_id', 'created_at', 'updated_at']
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Validation error',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Validation failed'),
                        new OA\Property(property: 'details', type: 'array', items: new OA\Items(type: 'string'), example: ['Collection name must be at least 3 characters']),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Unauthorized'),
                        new OA\Property(property: 'message', type: 'string', example: 'JWT Token not found'),
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'Forbidden',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Forbidden'),
                        new OA\Property(property: 'message', type: 'string', example: 'You do not have permission to update this collection'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Collection not found',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Not Found'),
                        new OA\Property(property: 'message', type: 'string', example: 'Collection with id "018f0a1b-2c3d-4e5f-6789-0123456789ab" not found'),
                    ]
                )
            ),
        ]
    )]
    public function update(string $id, Request $request, CollectionService $collectionService, SerializerInterface $serializer, ValidatorInterface $validator): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $dto = $this->deserializeAndValidate($request->getContent(), UpdateCollectionDTO::class, $serializer, $validator);
        } catch (ValidationException $validationException) {
            return $this->createValidationErrorResponse($validationException->getDetails());
        }

        try {
            $collection = $collectionService->getById($id);
        } catch (CollectionNotFoundException $e) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }

        // Authorization: only owner can update
        if ($collection->getOwner()->getId()->toString() !== $user->getId()->toString()) {
            return new JsonResponse([
                'error' => 'Forbidden',
                'message' => 'You do not have permission to update this collection',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$dto->hasChanges()) {
            return new JsonResponse([
                'error' => 'Bad Request',
                'message' => 'At least one field must be provided for update',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $updatedCollection = $collectionService->update($dto, $collection);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Internal Server Error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(
            $collectionService->toDTO($updatedCollection)->toArray(),
            Response::HTTP_OK
        );
    }

    #[Route('/api/collections/{id}', name: 'api_collection_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/collections/{id}',
        security: [['Bearer' => []]],
        summary: 'Delete a collection',
        description: 'Deletes a collection if it belongs to the authenticated user.',
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        tags: ['Collections'],
        responses: [
            new OA\Response(
                response: 204,
                description: 'Collection deleted successfully (no content)'
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Unauthorized'),
                        new OA\Property(property: 'message', type: 'string', example: 'JWT Token not found'),
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'Forbidden',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Forbidden'),
                        new OA\Property(property: 'message', type: 'string', example: 'You do not have permission to delete this collection'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Collection not found',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Not Found'),
                        new OA\Property(property: 'message', type: 'string', example: 'Collection with id "018f0a1b-2c3d-4e5f-6789-0123456789ab" not found'),
                    ]
                )
            ),
        ]
    )]
    public function delete(string $id, CollectionService $collectionService): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $collection = $collectionService->getById($id);
        } catch (CollectionNotFoundException $e) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }

        // Authorization: only owner can delete
        if ($collection->getOwner()->getId()->toString() !== $user->getId()->toString()) {
            return new JsonResponse([
                'error' => 'Forbidden',
                'message' => 'You do not have permission to delete this collection',
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $collectionService->delete($collection);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Internal Server Error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
