<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use App\Application\Tag\DTO\TagDTO;
use App\Application\Tag\Service\TagService;
use App\Domain\Tag\Entity\Tag;
use App\Domain\User\Entity\User;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TagController extends AbstractApiController
{
    #[Route('/api/tags', name: 'api_tag_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/tags',
        security: [['Bearer' => []]],
        summary: 'List/search tags',
        description: 'Returns a paginated list of global tags ordered by name. Optional `search` (case-insensitive substring) supports selecting existing tags when tagging an item. Authenticated users only.',
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Case-insensitive substring match on tag name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, minimum: self::MIN_LIMIT, maximum: self::MAX_LIMIT)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_OFFSET, minimum: self::DEFAULT_OFFSET)),
        ],
        tags: ['Tags'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tags retrieved successfully',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Tag')),
            ),
            new OA\Response(response: 400, description: 'Bad request (invalid limit/offset)'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    public function list(Request $request, TagService $tagService): JsonResponse
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

        $search = $request->query->get('search');
        $search = \is_string($search) ? $search : null;

        $tags = $tagService->listTags($search, $limit, $offset);

        return new JsonResponse(
            \array_map(static fn (Tag $tag): array => TagDTO::fromEntity($tag)->toArray(), $tags),
            Response::HTTP_OK,
        );
    }
}
