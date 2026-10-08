<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use App\Application\Search\CollectionSearchHit;
use App\Application\Search\ItemSearchHit;
use App\Application\Search\SearchService;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\User\Entity\User;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractApiController
{
    #[Route('/api/search/items', name: 'api_search_items', methods: ['GET'])]
    #[OA\Get(
        path: '/api/search/items',
        security: [['Bearer' => []], []],
        summary: 'Full-text search over items',
        description: 'Public. Searches item names, tags and collection names. Optional filters: owner (uuid or "me"), collection_id, tags[] (AND). Returns engine documents as-is — no slots, no timestamps.',
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: true, description: 'Non-empty search query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'owner', in: 'query', required: false, description: 'Owner uuid or "me" for the authenticated user; omitted = global', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'collection_id', in: 'query', required: false, description: 'Restrict to one collection', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'tags[]', in: 'query', required: false, description: 'Item must have all given tags (AND)', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, minimum: self::MIN_LIMIT, maximum: self::MAX_LIMIT)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_OFFSET, minimum: self::DEFAULT_OFFSET)),
        ],
        tags: ['Search'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Search hits',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ItemSearchHit')),
            ),
            new OA\Response(response: 400, description: 'Bad request (missing/blank q, invalid limit/offset/owner/collection_id/tags, or a non-scalar value)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 401, description: 'Unauthorized (owner=me without authentication)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ],
    )]
    public function searchItems(Request $request, SearchService $searchService): JsonResponse
    {
        try {
            [$limit, $offset] = $this->parsePagination($request);
            $params = $request->query->all();

            $query = $params['q'] ?? null;
            if (!\is_string($query) || '' === \trim($query)) {
                throw new \InvalidArgumentException('Invalid q: must be a non-empty string.');
            }

            $ownerId = $this->resolveOwnerFilter($params['owner'] ?? null);
            $collectionId = $this->resolveUuidFilter($params['collection_id'] ?? null, 'collection_id');
            $tagNames = $this->resolveTagsFilter($params['tags'] ?? null);

            if (null === $ownerId && \array_key_exists('owner', $params)) {
                // resolveOwnerFilter returns null for guest + owner=me only
                // (any other string either resolves or throws above).
                return $this->unauthorized();
            }

            $hits = $searchService->searchItems(\trim($query), $ownerId, $collectionId, $tagNames, $limit, $offset);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->badRequest('Invalid query parameters', [$invalidArgumentException->getMessage()]);
        }

        return new JsonResponse(
            \array_map(static fn (ItemSearchHit $hit): array => $hit->toArray(), $hits),
            Response::HTTP_OK,
        );
    }

    #[Route('/api/search/collections', name: 'api_search_collections', methods: ['GET'])]
    #[OA\Get(
        path: '/api/search/collections',
        security: [['Bearer' => []], []],
        summary: 'Full-text search over collections',
        description: 'Public. Searches collection names and descriptions. Optional filters: owner (uuid or "me"), theme.',
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: true, description: 'Non-empty search query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'owner', in: 'query', required: false, description: 'Owner uuid or "me" for the authenticated user; omitted = global', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'theme', in: 'query', required: false, description: 'Restrict to one theme (Books, Games, Movies, Drinks)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, minimum: self::MIN_LIMIT, maximum: self::MAX_LIMIT)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: self::DEFAULT_OFFSET, minimum: self::DEFAULT_OFFSET)),
        ],
        tags: ['Search'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Search hits',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/CollectionSearchHit')),
            ),
            new OA\Response(response: 400, description: 'Bad request (missing/blank q, invalid limit/offset/owner/theme, or a non-scalar value)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 401, description: 'Unauthorized (owner=me without authentication)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ],
    )]
    public function searchCollections(Request $request, SearchService $searchService): JsonResponse
    {
        try {
            [$limit, $offset] = $this->parsePagination($request);
            $params = $request->query->all();

            $query = $params['q'] ?? null;
            if (!\is_string($query) || '' === \trim($query)) {
                throw new \InvalidArgumentException('Invalid q: must be a non-empty string.');
            }

            $ownerId = $this->resolveOwnerFilter($params['owner'] ?? null);
            $theme = $this->resolveThemeFilter($params['theme'] ?? null);

            if (null === $ownerId && \array_key_exists('owner', $params)) {
                return $this->unauthorized();
            }

            $hits = $searchService->searchCollections(\trim($query), $ownerId, $theme, $limit, $offset);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->badRequest('Invalid query parameters', [$invalidArgumentException->getMessage()]);
        }

        return new JsonResponse(
            \array_map(static fn (CollectionSearchHit $hit): array => $hit->toArray(), $hits),
            Response::HTTP_OK,
        );
    }

    /**
     * Resolves the `owner` filter: null stays null (global), "me" becomes the
     * authenticated user's id string, a uuid passes through validated. Returns
     * null for "me" when guest — the caller turns that into 401, because only
     * the controller knows whether "me" was even asked.
     */
    private function resolveOwnerFilter(mixed $owner): ?string
    {
        if (null === $owner) {
            return null;
        }

        if (!\is_string($owner) || '' === $owner) {
            throw new \InvalidArgumentException('Invalid owner: must be a uuid or "me".');
        }

        if ('me' === $owner) {
            $user = $this->getUser();

            return $user instanceof User ? $user->getId()->toString() : null;
        }

        return OwnerId::fromString($owner)->toString();
    }

    private function resolveUuidFilter(mixed $value, string $name): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(\sprintf('Invalid %s: must be a uuid.', $name));
        }

        return CollectionId::fromString($value)->toString();
    }

    /**
     * @return array<string>
     */
    private function resolveTagsFilter(mixed $tags): array
    {
        if (null === $tags) {
            return [];
        }

        if (!\is_array($tags)) {
            throw new \InvalidArgumentException('Invalid tags: must be an array (?tags[]=a&tags[]=b).');
        }

        foreach ($tags as $tag) {
            if (!\is_string($tag)) {
                throw new \InvalidArgumentException('Invalid tags: every value must be a string.');
            }
        }

        return \array_values($tags);
    }

    private function resolveThemeFilter(mixed $theme): ?string
    {
        if (null === $theme) {
            return null;
        }

        if (!\is_string($theme) || '' === $theme) {
            throw new \InvalidArgumentException('Invalid theme: must be a string.');
        }

        return Theme::fromString($theme)->value();
    }
}
