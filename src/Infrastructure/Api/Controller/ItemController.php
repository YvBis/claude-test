<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use App\Application\Collection\Service\CollectionService;
use App\Application\Comment\Service\CommentService;
use App\Application\Exception\ValidationException;
use App\Application\Item\DTO\CreateItemDTO;
use App\Application\Item\DTO\ItemDetailDTO;
use App\Application\Item\DTO\UpdateItemDTO;
use App\Application\Item\Service\ItemService;
use App\Application\Like\Service\LikeService;
use App\Domain\Collection\Exception\CollectionNotFoundException;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Item\Exception\ItemNotFoundException;
use App\Domain\User\Entity\User;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ItemController extends AbstractApiController
{
    #[Route('/api/collections/{collectionId}/items', name: 'api_item_list_by_collection', methods: ['GET'])]
    #[OA\Get(
        path: '/api/collections/{collectionId}/items',
        security: [['Bearer' => []]],
        summary: 'List items of a collection',
        description: 'Returns a paginated list of items of a collection. Any authenticated user may read it. Optional filters: name (substring) and tags (AND semantics).',
        parameters: [
            new OA\Parameter(name: 'collectionId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, minimum: self::MIN_LIMIT, maximum: self::MAX_LIMIT)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_OFFSET, minimum: self::DEFAULT_OFFSET)),
            new OA\Parameter(name: 'name', in: 'query', required: false, description: 'Substring match on item name (case-insensitive)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tags[]', in: 'query', required: false, description: 'Item must have all given tags (AND)', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
        ],
        tags: ['Items'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Items retrieved successfully',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Item')),
            ),
            new OA\Response(response: 400, description: 'Bad request (invalid filter)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Collection not found'),
        ],
    )]
    public function listByCollection(string $collectionId, Request $request, CollectionService $collectionService, ItemService $itemService): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $collection = $collectionService->getById($collectionId);
        } catch (CollectionNotFoundException $collectionNotFoundException) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => $collectionNotFoundException->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => $invalidArgumentException->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }

        [$name, $tagNames] = $this->parseFilters($request);

        try {
            [$limit, $offset] = $this->parsePagination($request);
            $items = $itemService->listByCollection($collection->getId(), $limit, $offset, $name, $tagNames);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse([
                'error' => 'Bad Request',
                'message' => $invalidArgumentException->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->toArrayPayload($itemService->toDTOList($items)), Response::HTTP_OK);
    }

    #[Route('/api/items', name: 'api_item_list_own', methods: ['GET'])]
    #[OA\Get(
        path: '/api/items',
        security: [['Bearer' => []]],
        summary: 'List own items',
        description: 'Returns a paginated list of items owned by the authenticated user. Optional filters: name (substring) and tags (AND semantics).',
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, minimum: self::MIN_LIMIT, maximum: self::MAX_LIMIT)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_OFFSET, minimum: self::DEFAULT_OFFSET)),
            new OA\Parameter(name: 'name', in: 'query', required: false, description: 'Substring match on item name (case-insensitive)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tags[]', in: 'query', required: false, description: 'Item must have all given tags (AND)', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
        ],
        tags: ['Items'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Items retrieved successfully',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Item')),
            ),
            new OA\Response(response: 400, description: 'Bad request (invalid filter)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    public function listOwn(Request $request, ItemService $itemService): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        [$name, $tagNames] = $this->parseFilters($request);

        try {
            [$limit, $offset] = $this->parsePagination($request);
            $items = $itemService->listByOwner(
                OwnerId::fromBytes($user->getId()->toBytes()),
                $limit,
                $offset,
                $name,
                $tagNames,
            );
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse([
                'error' => 'Bad Request',
                'message' => $invalidArgumentException->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->toArrayPayload($itemService->toDTOList($items)), Response::HTTP_OK);
    }

    #[Route('/api/items/{id}', name: 'api_item_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/items/{id}',
        security: [['Bearer' => []]],
        summary: 'Get an item by ID',
        description: 'Returns a single item. Any authenticated user may read it.',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        tags: ['Items'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Item retrieved successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/ItemDetail'),
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Item not found'),
        ],
    )]
    public function get(
        string $id,
        ItemService $itemService,
        LikeService $likeService,
        CommentService $commentService,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $item = $itemService->getById($id);
        } catch (ItemNotFoundException $itemNotFoundException) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => $itemNotFoundException->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => $invalidArgumentException->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }

        $detail = ItemDetailDTO::fromItem(
            $item,
            $likeService->countByItem($item->getId()),
            $commentService->countByItem($item->getId()),
            $likeService->isLikedBy($user, $item),
        );

        return new JsonResponse($detail->toArray(), Response::HTTP_OK);
    }

    #[Route('/api/collections/{collectionId}/items', name: 'api_item_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/collections/{collectionId}/items',
        security: [['Bearer' => []]],
        summary: 'Create an item in a collection',
        description: 'Creates an item in a collection. Only the collection owner or an admin may create. Tags are found-or-created.',
        parameters: [
            new OA\Parameter(name: 'collectionId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 100, example: '1984'),
                    new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'), example: ['scifi', 'dystopia']),
                    new OA\Property(
                        property: 'slots',
                        type: 'array',
                        description: 'Filled slots as {type, slot, value}',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'type', type: 'string', enum: ['text', 'number', 'date', 'bool']),
                                new OA\Property(property: 'slot', type: 'integer', minimum: 1, maximum: 3),
                                new OA\Property(property: 'value', type: 'string'),
                            ],
                        ),
                    ),
                ],
                type: 'object',
            ),
        ),
        tags: ['Items'],
        responses: [
            new OA\Response(response: 201, description: 'Item created', content: new OA\JsonContent(ref: '#/components/schemas/Item')),
            new OA\Response(response: 400, description: 'Validation error or invalid slot value'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Collection not found'),
        ],
    )]
    public function create(
        string $collectionId,
        Request $request,
        CollectionService $collectionService,
        ItemService $itemService,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $dto = $this->deserializeAndValidate($request->getContent(), CreateItemDTO::class, $serializer, $validator);
        } catch (ValidationException $validationException) {
            return $this->createValidationErrorResponse($validationException->getDetails());
        }

        try {
            $collection = $collectionService->getById($collectionId);
        } catch (CollectionNotFoundException $collectionNotFoundException) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => $collectionNotFoundException->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => $invalidArgumentException->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$this->canManage($user, $collection->getOwner())) {
            return new JsonResponse([
                'error' => 'Forbidden',
                'message' => 'You do not have permission to create items in this collection',
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $item = $itemService->create($dto, $collection);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse([
                'error' => 'Bad Request',
                'message' => $invalidArgumentException->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($itemService->toDTO($item)->toArray(), Response::HTTP_CREATED);
    }

    #[Route('/api/items/{id}', name: 'api_item_update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/items/{id}',
        security: [['Bearer' => []]],
        summary: 'Update an item',
        description: 'Partially updates an item (name, tags — replaced by list, slots — merged; null clears a slot). Only the item owner or an admin may update.',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Nineteen Eighty-Four'),
                    new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'slots', type: 'array', items: new OA\Items(type: 'object')),
                ],
                type: 'object',
            ),
        ),
        tags: ['Items'],
        responses: [
            new OA\Response(response: 200, description: 'Item updated', content: new OA\JsonContent(ref: '#/components/schemas/Item')),
            new OA\Response(response: 400, description: 'Empty body or invalid slot value'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Item not found'),
        ],
    )]
    public function update(
        string $id,
        Request $request,
        ItemService $itemService,
        SerializerInterface $serializer,
        ValidatorInterface $validator,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $dto = $this->deserializeAndValidate($request->getContent(), UpdateItemDTO::class, $serializer, $validator);
        } catch (ValidationException $validationException) {
            return $this->createValidationErrorResponse($validationException->getDetails());
        }

        try {
            $item = $itemService->getById($id);
        } catch (ItemNotFoundException $itemNotFoundException) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => $itemNotFoundException->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => $invalidArgumentException->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$this->canManage($user, $item->getCollection()->getOwner())) {
            return new JsonResponse([
                'error' => 'Forbidden',
                'message' => 'You do not have permission to update this item',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$dto->hasChanges()) {
            return new JsonResponse([
                'error' => 'Bad Request',
                'message' => 'At least one field must be provided for update',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $updatedItem = $itemService->update($dto, $item);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse([
                'error' => 'Bad Request',
                'message' => $invalidArgumentException->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($itemService->toDTO($updatedItem)->toArray(), Response::HTTP_OK);
    }

    #[Route('/api/items/{id}', name: 'api_item_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/items/{id}',
        security: [['Bearer' => []]],
        summary: 'Delete an item',
        description: 'Deletes an item if it belongs to a collection of the authenticated user, or the user is an admin.',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        tags: ['Items'],
        responses: [
            new OA\Response(response: 204, description: 'Item deleted successfully (no content)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Item not found'),
        ],
    )]
    public function delete(string $id, ItemService $itemService): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $item = $itemService->getById($id);
        } catch (ItemNotFoundException $itemNotFoundException) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => $itemNotFoundException->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse([
                'error' => 'Not Found',
                'message' => $invalidArgumentException->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$this->canManage($user, $item->getCollection()->getOwner())) {
            return new JsonResponse([
                'error' => 'Forbidden',
                'message' => 'You do not have permission to delete this item',
            ], Response::HTTP_FORBIDDEN);
        }

        $itemService->delete($item);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{0: ?string, 1: array<string>}
     */
    private function parseFilters(Request $request): array
    {
        $name = $request->query->get('name');
        if (!\is_string($name)) {
            $name = null;
        }

        $tagNames = [];
        foreach ($request->query->all('tags') as $tag) {
            if (\is_string($tag)) {
                $tagNames[] = $tag;
            }
        }

        return [$name, $tagNames];
    }
}
