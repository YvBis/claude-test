<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller\Admin;

use App\Application\Like\Service\LikeService;
use App\Infrastructure\Api\Controller\AbstractApiController;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class LikeController extends AbstractApiController
{
    #[Route('/api/admin/likes', name: 'api_admin_like_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(
        path: '/api/admin/likes',
        security: [['Bearer' => []]],
        summary: 'List all likes (admin)',
        description: 'Audit listing: every like across all users, newest first. Admin only.',
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: self::DEFAULT_LIMIT, minimum: self::MIN_LIMIT, maximum: self::MAX_LIMIT)),
            new OA\Parameter(name: 'offset', in: 'query', schema: new OA\Schema(type: 'integer', default: self::DEFAULT_OFFSET, minimum: self::DEFAULT_OFFSET)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Likes retrieved successfully',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Like')),
            ),
            new OA\Response(response: 400, description: 'Bad request (invalid limit/offset, or a non-scalar value)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 403, description: 'Forbidden (non-admin)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ],
    )]
    public function list(Request $request, LikeService $likeService): JsonResponse
    {
        try {
            [$limit, $offset] = $this->parsePagination($request);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->badRequest('Invalid query parameters', [$invalidArgumentException->getMessage()]);
        }

        $likes = $likeService->listAll($limit, $offset);

        return new JsonResponse($this->toArrayPayload($likeService->toDTOList($likes)), Response::HTTP_OK);
    }
}
