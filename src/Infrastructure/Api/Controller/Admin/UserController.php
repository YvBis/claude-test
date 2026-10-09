<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller\Admin;

use App\Application\User\DTO\UserDTO;
use App\Application\User\Service\UserService;
use App\Domain\User\Entity\User;
use App\Infrastructure\Api\Controller\AbstractApiController;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class UserController extends AbstractApiController
{
    #[Route('/api/admin/users', name: 'api_admin_user_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(
        path: '/api/admin/users',
        security: [['Bearer' => []]],
        summary: 'List users (admin)',
        description: 'Returns every user with all fields except the password hash. Admin only.',
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 50, minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'offset', in: 'query', schema: new OA\Schema(type: 'integer', default: 0, minimum: 0)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Users retrieved successfully',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/User')),
            ),
            new OA\Response(response: 400, description: 'Bad request (invalid limit/offset, or a non-scalar value)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 403, description: 'Forbidden (non-admin)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ],
    )]
    public function list(Request $request, UserService $userService): JsonResponse
    {
        try {
            [$limit, $offset] = $this->parsePagination($request);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->badRequest('Invalid query parameters', [$invalidArgumentException->getMessage()]);
        }

        $users = $userService->listUsers($limit, $offset);

        return new JsonResponse(
            \array_map(static fn (User $user): array => UserDTO::fromEntity($user)->toArray(), $users),
            Response::HTTP_OK,
        );
    }
}
