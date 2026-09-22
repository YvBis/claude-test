<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use App\Application\Item\Service\ItemService;
use App\Application\Like\Service\LikeService;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\Entity\Like;
use App\Domain\User\Entity\User;
use App\Infrastructure\Security\Voter\SocialContentVoter;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LikeController extends AbstractApiController
{
    #[Route('/api/items/{itemId}/likes', name: 'api_like_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/items/{itemId}/likes',
        security: [['Bearer' => []]],
        summary: 'Like an item',
        description: 'Likes an item on behalf of the authenticated user. Idempotent: liking twice keeps a single like. Any authenticated user may like any item; an unknown item yields 404.',
        parameters: [
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        tags: ['Likes'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Item liked (or already liked)',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'likes_count', type: 'integer', example: 3),
                    ],
                    required: ['likes_count'],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Item not found'),
        ],
    )]
    public function create(string $itemId, ItemService $itemService, LikeService $likeService): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $item = $this->findItemOrNull($itemId, $itemService);

        if (!$item instanceof Item) {
            return $this->notFound('Item not found');
        }

        $likeService->like($user, $item);

        return new JsonResponse(['likes_count' => $likeService->countByItem($item->getId())], Response::HTTP_OK);
    }

    #[Route('/api/items/{itemId}/likes', name: 'api_like_delete_own', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/items/{itemId}/likes',
        security: [['Bearer' => []]],
        summary: 'Remove own like from an item',
        description: "Removes the authenticated user's like from an item. Idempotent: removing a missing like is a no-op. An unknown item yields 404.",
        parameters: [
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        tags: ['Likes'],
        responses: [
            new OA\Response(response: 204, description: 'Like removed (no content)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Item not found'),
        ],
    )]
    public function deleteOwn(string $itemId, ItemService $itemService, LikeService $likeService): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $item = $this->findItemOrNull($itemId, $itemService);

        if (!$item instanceof Item) {
            return $this->notFound('Item not found');
        }

        $likeService->unlike($user, $item);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/items/{itemId}/likes', name: 'api_like_list_by_item', methods: ['GET'])]
    #[OA\Get(
        path: '/api/items/{itemId}/likes',
        security: [['Bearer' => []]],
        summary: 'List likes of an item',
        description: 'Returns a paginated list of likes of an item. Any authenticated user may read it.',
        parameters: [
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, minimum: self::MIN_LIMIT, maximum: self::MAX_LIMIT)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_OFFSET, minimum: self::DEFAULT_OFFSET)),
        ],
        tags: ['Likes'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Likes retrieved successfully',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Like')),
            ),
            new OA\Response(response: 400, description: 'Bad request (invalid pagination)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Item not found'),
        ],
    )]
    public function listByItem(string $itemId, Request $request, ItemService $itemService, LikeService $likeService): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $item = $this->findItemOrNull($itemId, $itemService);

        if (!$item instanceof Item) {
            return $this->notFound('Item not found');
        }

        try {
            [$limit, $offset] = $this->parsePagination($request);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->badRequest('Invalid query parameters', [$invalidArgumentException->getMessage()]);
        }

        $likes = $likeService->listByItem($item->getId(), $limit, $offset);

        return new JsonResponse($this->toArrayPayload($likeService->toDTOList($likes)), Response::HTTP_OK);
    }

    #[Route('/api/likes', name: 'api_like_list_own', methods: ['GET'])]
    #[OA\Get(
        path: '/api/likes',
        security: [['Bearer' => []]],
        summary: 'List own likes',
        description: 'Returns a paginated list of likes given by the authenticated user, oldest first.',
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, minimum: self::MIN_LIMIT, maximum: self::MAX_LIMIT)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_OFFSET, minimum: self::DEFAULT_OFFSET)),
        ],
        tags: ['Likes'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Likes retrieved successfully',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Like')),
            ),
            new OA\Response(response: 400, description: 'Bad request (invalid pagination)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    public function listOwn(Request $request, LikeService $likeService): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        try {
            [$limit, $offset] = $this->parsePagination($request);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->badRequest('Invalid query parameters', [$invalidArgumentException->getMessage()]);
        }

        $likes = $likeService->listByOwner(
            OwnerId::fromBytes($user->getId()->toBytes()),
            $limit,
            $offset,
        );

        return new JsonResponse($this->toArrayPayload($likeService->toDTOList($likes)), Response::HTTP_OK);
    }

    #[Route('/api/likes/{id}', name: 'api_like_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/likes/{id}',
        security: [['Bearer' => []]],
        summary: 'Delete a like by ID',
        description: 'Deletes a like. Allowed for its author or an administrator.',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        tags: ['Likes'],
        responses: [
            new OA\Response(response: 204, description: 'Like deleted successfully (no content)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Like not found'),
        ],
    )]
    public function delete(string $id, LikeService $likeService): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $like = $this->findLikeOrNull($id, $likeService);

        if (!$like instanceof Like) {
            return $this->notFound('Like not found');
        }

        if (!$this->isGranted(SocialContentVoter::SOCIAL_DELETE, $like)) {
            return $this->forbidden('Forbidden');
        }

        $likeService->removeLike($like);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function findLikeOrNull(string $id, LikeService $likeService): ?Like
    {
        try {
            return $likeService->getById($id);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
