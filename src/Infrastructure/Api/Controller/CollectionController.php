<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use App\Application\Collection\DTO\CreateCollectionDTO;
use App\Application\Collection\DTO\UpdateCollectionDTO;
use App\Application\Collection\Service\CollectionService;
use App\Application\Exception\ValidationException;
use App\Domain\Collection\Exception\CollectionNotFoundException;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\User\Entity\User;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
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
            new OA\Response(response: 400, description: 'Bad request (malformed body)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    public function create(
        Request $request,
        CollectionService $collectionService,
        SerializerInterface $serializer,
        ValidatorInterface $validator
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        try {
            $dto = $this->deserializeAndValidate($request->getContent(), CreateCollectionDTO::class, $serializer, $validator);
        } catch (ValidationException $validationException) {
            return $this->createValidationErrorResponse($validationException->getDetails());
        } catch (NotEncodableValueException|NotNormalizableValueException) {
            return $this->badRequest('Malformed request body');
        }

        $collection = $collectionService->create($dto, $user);

        return new JsonResponse(
            $collectionService->toDTO($collection)->toArray(),
            Response::HTTP_CREATED
        );
    }

    #[Route('/api/collections/{id}', name: 'api_collection_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/collections/{id}',
        security: [['Bearer' => []], []],
        summary: 'Get a collection by ID',
        description: 'Returns a single collection. Guests may read it.',
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
            new OA\Response(response: 404, description: 'Collection not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    public function get(string $id, CollectionService $collectionService): JsonResponse
    {
        try {
            $collection = $collectionService->getById($id);
        } catch (CollectionNotFoundException|\InvalidArgumentException) {
            return $this->notFound('Collection not found');
        }

        return new JsonResponse(
            $collectionService->toDTO($collection)->toArray(),
            Response::HTTP_OK
        );
    }

    #[Route('/api/collections', name: 'api_collection_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/collections',
        security: [['Bearer' => []], []],
        summary: 'List collections',
        description: 'Returns a paginated list of collections. Without an "owner" parameter — the authenticated user\'s own collections; with "owner" — that user\'s collections. Guests may read with "owner"; without it they get 400.',
        parameters: [
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, minimum: self::MIN_LIMIT, maximum: self::MAX_LIMIT)
            ),
            new OA\Parameter(
                name: 'offset',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: self::DEFAULT_OFFSET, minimum: self::DEFAULT_OFFSET)
            ),
            new OA\Parameter(
                name: 'owner',
                in: 'query',
                required: false,
                description: "User ID whose collections to list. Omit to list the authenticated user's own collections.",
                schema: new OA\Schema(type: 'string', format: 'uuid')
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
            new OA\Response(response: 400, description: 'Bad request (invalid limit/offset/owner id, a non-scalar value for any of them e.g. ?owner[]=x, or "owner" omitted on a guest request)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    public function list(Request $request, CollectionService $collectionService): JsonResponse
    {
        try {
            [$limit, $offset] = $this->parsePagination($request);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->badRequest('Invalid query parameters', [$invalidArgumentException->getMessage()]);
        }

        // Read the whole query bag: InputBag::get() throws BadRequestException on a
        // non-scalar value (e.g. ?owner[]=x), which is an UnexpectedValueException and
        // therefore escapes the InvalidArgumentException catch below, leaving the
        // framework to render an HTML error page instead of the API envelope.
        $owner = $request->query->all()['owner'] ?? null;

        if (null !== $owner) {
            try {
                if (!\is_string($owner)) {
                    throw new \InvalidArgumentException('Invalid owner id');
                }

                $ownerId = OwnerId::fromString($owner);
            } catch (\InvalidArgumentException) {
                return $this->badRequest('Invalid query parameters', ['Invalid owner id']);
            }

            $collections = $collectionService->listByOwnerId($ownerId, $limit, $offset);
        } else {
            // fwd-7: without ?owner= the listing means "my collections", which a
            // guest has none of. 400 rather than an empty list — an empty list is
            // indistinguishable from "this owner has nothing", so it would lie.
            $user = $this->getUser();

            if (!$user instanceof User) {
                return $this->badRequest('Invalid query parameters', ['The owner parameter is required for guest access']);
            }

            $ownerId = OwnerId::fromBytes($user->getId()->toBytes());
            $collections = $collectionService->listByOwnerId($ownerId, $limit, $offset);
        }

        $dtos = $collectionService->toDTOList($collections);

        return new JsonResponse($this->toArrayPayload($dtos), Response::HTTP_OK);
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
            new OA\Response(response: 400, description: 'Bad request (malformed body)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 404, description: 'Collection not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    public function update(string $id, Request $request, CollectionService $collectionService, SerializerInterface $serializer, ValidatorInterface $validator): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        try {
            $dto = $this->deserializeAndValidate($request->getContent(), UpdateCollectionDTO::class, $serializer, $validator);
        } catch (ValidationException $validationException) {
            return $this->createValidationErrorResponse($validationException->getDetails());
        } catch (NotEncodableValueException|NotNormalizableValueException) {
            return $this->badRequest('Malformed request body');
        }

        try {
            $collection = $collectionService->getById($id);
        } catch (CollectionNotFoundException|\InvalidArgumentException) {
            return $this->notFound('Collection not found');
        }

        // Authorization: only owner can update
        if ($collection->getOwner()->getId()->toString() !== $user->getId()->toString()) {
            throw new AccessDeniedException('Forbidden');
        }

        if (!$dto->hasChanges()) {
            return $this->unprocessable('At least one field must be provided for update');
        }

        $updatedCollection = $collectionService->update($dto, $collection);

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
        description: 'Deletes a collection if it belongs to the authenticated user, or if the authenticated user is an administrator.',
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
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 404, description: 'Collection not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    public function delete(string $id, CollectionService $collectionService): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        try {
            $collection = $collectionService->getById($id);
        } catch (CollectionNotFoundException|\InvalidArgumentException) {
            return $this->notFound('Collection not found');
        }

        $this->denyUnlessCanManage($user, $collection->getOwner());

        $collectionService->delete($collection);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
