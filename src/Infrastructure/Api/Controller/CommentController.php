<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use App\Application\Comment\DTO\CommentRequestDTO;
use App\Application\Comment\Service\CommentService;
use App\Application\Item\Service\ItemService;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Comment\Entity\Comment;
use App\Domain\Item\Entity\Item;
use App\Domain\User\Entity\User;
use App\Infrastructure\Security\Voter\SocialContentVoter;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CommentController extends AbstractApiController
{
    public function __construct(
        #[Autowire(service: 'serializer')]
        private readonly SerializerInterface $serializer,
        #[Autowire(service: 'validator')]
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/items/{itemId}/comments', name: 'api_comment_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/items/{itemId}/comments',
        security: [['Bearer' => []]],
        summary: 'Add a comment to an item',
        description: 'Creates a comment on any item (the social feature is not limited to the item owner). The content is Markdown, 1..3000 characters; invalid content yields 422, a malformed request body 400 and an unknown item 404.',
        parameters: [
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['content'],
                properties: [
                    new OA\Property(property: 'content', type: 'string', maxLength: 3000, example: 'Great read! ## Notes'),
                ],
            ),
        ),
        tags: ['Comments'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Comment created successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Comment'),
            ),
            new OA\Response(response: 400, description: 'Bad request (malformed body)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Item not found'),
            new OA\Response(
                response: 422,
                description: 'Invalid comment content',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Unprocessable Entity'),
                        new OA\Property(property: 'message', type: 'string', example: 'Invalid content'),
                        new OA\Property(property: 'details', type: 'array', items: new OA\Items(type: 'string'), example: ['Comment content cannot be empty']),
                    ]
                )
            ),
        ],
    )]
    public function create(string $itemId, Request $request, ItemService $itemService, CommentService $commentService): JsonResponse
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
            $content = $this->parseContent($request);
            $comment = $commentService->create($user, $item, $content);
        } catch (NotEncodableValueException|NotNormalizableValueException $exception) {
            return $this->badRequest('Malformed request body');
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->unprocessable('Invalid content', [$invalidArgumentException->getMessage()]);
        }

        return new JsonResponse($commentService->toDTO($comment)->toArray(), Response::HTTP_CREATED);
    }

    #[Route('/api/items/{itemId}/comments', name: 'api_comment_list_by_item', methods: ['GET'])]
    #[OA\Get(
        path: '/api/items/{itemId}/comments',
        security: [['Bearer' => []]],
        summary: 'List comments of an item',
        description: 'Returns a paginated list of comments of an item, oldest first. Any authenticated user may read it.',
        parameters: [
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, minimum: self::MIN_LIMIT, maximum: self::MAX_LIMIT)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_OFFSET, minimum: self::DEFAULT_OFFSET)),
        ],
        tags: ['Comments'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Comments retrieved successfully',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Comment')),
            ),
            new OA\Response(response: 400, description: 'Bad request (invalid pagination)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Item not found'),
        ],
    )]
    public function listByItem(string $itemId, Request $request, ItemService $itemService, CommentService $commentService): JsonResponse
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

        $comments = $commentService->listByItem($item->getId(), $limit, $offset);

        return new JsonResponse($this->toArrayPayload($commentService->toDTOList($comments)), Response::HTTP_OK);
    }

    #[Route('/api/comments', name: 'api_comment_list_own', methods: ['GET'])]
    #[OA\Get(
        path: '/api/comments',
        security: [['Bearer' => []]],
        summary: 'List own comments',
        description: 'Returns a paginated list of comments written by the authenticated user, oldest first.',
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, minimum: self::MIN_LIMIT, maximum: self::MAX_LIMIT)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_OFFSET, minimum: self::DEFAULT_OFFSET)),
        ],
        tags: ['Comments'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Comments retrieved successfully',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Comment')),
            ),
            new OA\Response(response: 400, description: 'Bad request (invalid pagination)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    public function listOwn(Request $request, CommentService $commentService): JsonResponse
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

        $comments = $commentService->listByOwner(
            OwnerId::fromBytes($user->getId()->toBytes()),
            $limit,
            $offset,
        );

        return new JsonResponse($this->toArrayPayload($commentService->toDTOList($comments)), Response::HTTP_OK);
    }

    #[Route('/api/comments/{id}', name: 'api_comment_update', methods: ['PATCH'])]
    #[OA\Patch(
        path: '/api/comments/{id}',
        security: [['Bearer' => []]],
        summary: 'Edit a comment',
        description: 'Edits a comment. Allowed for its author or an administrator. The content is Markdown, 1..3000 characters; invalid content yields 422 and a malformed body 400.',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['content'],
                properties: [
                    new OA\Property(property: 'content', type: 'string', maxLength: 3000, example: 'Updated note'),
                ],
            ),
        ),
        tags: ['Comments'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Comment updated successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/Comment'),
            ),
            new OA\Response(response: 400, description: 'Bad request (malformed body)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Comment not found'),
            new OA\Response(
                response: 422,
                description: 'Invalid comment content',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Unprocessable Entity'),
                        new OA\Property(property: 'message', type: 'string', example: 'Invalid content'),
                        new OA\Property(property: 'details', type: 'array', items: new OA\Items(type: 'string'), example: ['Comment content cannot be empty']),
                    ]
                )
            ),
        ],
    )]
    public function update(string $id, Request $request, CommentService $commentService): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $comment = $this->findCommentOrNull($id, $commentService);

        if (!$comment instanceof Comment) {
            return $this->notFound('Comment not found');
        }

        if (!$this->isGranted(SocialContentVoter::SOCIAL_EDIT, $comment)) {
            return $this->forbidden('Forbidden');
        }

        try {
            $commentService->changeContent($comment, $this->parseContent($request));
        } catch (NotEncodableValueException|NotNormalizableValueException $exception) {
            return $this->badRequest('Malformed request body');
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->unprocessable('Invalid content', [$invalidArgumentException->getMessage()]);
        }

        return new JsonResponse($commentService->toDTO($comment)->toArray(), Response::HTTP_OK);
    }

    #[Route('/api/comments/{id}', name: 'api_comment_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/comments/{id}',
        security: [['Bearer' => []]],
        summary: 'Delete a comment',
        description: 'Deletes a comment. Allowed for its author or an administrator.',
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        tags: ['Comments'],
        responses: [
            new OA\Response(response: 204, description: 'Comment deleted successfully (no content)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Comment not found'),
        ],
    )]
    public function delete(string $id, CommentService $commentService): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $comment = $this->findCommentOrNull($id, $commentService);

        if (!$comment instanceof Comment) {
            return $this->notFound('Comment not found');
        }

        if (!$this->isGranted(SocialContentVoter::SOCIAL_DELETE, $comment)) {
            return $this->forbidden('Forbidden');
        }

        $commentService->delete($comment);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/items/{itemId}/comments/{id}', name: 'api_comment_delete_in_item', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/items/{itemId}/comments/{id}',
        security: [['Bearer' => []]],
        summary: 'Delete a comment within an item',
        description: 'Deletes a comment addressed through its item. Same authorization as DELETE /api/comments/{id} (author or administrator). The comment must belong to the given item, otherwise 404.',
        parameters: [
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        tags: ['Comments'],
        responses: [
            new OA\Response(response: 204, description: 'Comment deleted successfully (no content)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Comment not found'),
        ],
    )]
    public function deleteInItem(string $itemId, string $id, CommentService $commentService): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->unauthorized();
        }

        $comment = $this->findCommentOrNull($id, $commentService);

        if (!$comment instanceof Comment) {
            return $this->notFound('Comment not found');
        }

        // Path consistency: the comment must belong to the item named in the
        // URL. Compared as strings, case-insensitively: UUIDs are
        // case-insensitive per RFC 4122 while the canonical form is lowercase,
        // and a malformed itemId then simply fails to match (404) instead of
        // throwing from the ItemId value object.
        if (0 !== \strcasecmp($comment->getItem()->getId()->toString(), $itemId)) {
            return $this->notFound('Comment not found');
        }

        if (!$this->isGranted(SocialContentVoter::SOCIAL_DELETE, $comment)) {
            return $this->forbidden('Forbidden');
        }

        $commentService->delete($comment);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function findCommentOrNull(string $id, CommentService $commentService): ?Comment
    {
        try {
            return $commentService->getById($id);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @throws NotEncodableValueException
     * @throws NotNormalizableValueException
     */
    private function parseContent(Request $request): string
    {
        $dto = $this->deserializeAndValidate($request->getContent(), CommentRequestDTO::class, $this->serializer, $this->validator);

        if (!$dto instanceof CommentRequestDTO) {
            throw new \LogicException('Unexpected DTO type');
        }

        return $dto->content;
    }
}
