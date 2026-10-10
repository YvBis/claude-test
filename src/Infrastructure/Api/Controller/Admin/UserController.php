<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller\Admin;

use App\Application\Exception\ValidationException;
use App\Application\User\DTO\UpdateUserDTO;
use App\Application\User\DTO\UserDTO;
use App\Application\User\Service\UserService;
use App\Domain\User\Entity\User;
use App\Domain\User\Exception\LastAdminException;
use App\Domain\User\Exception\SelfActionForbiddenException;
use App\Domain\User\Exception\UserNotFoundException;
use App\Infrastructure\Api\Controller\AbstractApiController;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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

    #[Route('/api/admin/users/{id}', name: 'api_admin_user_update', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Patch(
        path: '/api/admin/users/{id}',
        security: [['Bearer' => []]],
        summary: 'Block or unblock a user (admin)',
        description: 'Sets the active flag. Blocking is idempotent: re-blocking an already blocked user answers 200 with the same state.',
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'is_active', type: 'boolean', example: false),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'User updated successfully',
                content: new OA\JsonContent(ref: '#/components/schemas/User'),
            ),
            new OA\Response(response: 400, description: 'Bad request (malformed body)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 422, description: 'Validation error (empty body) or last admin', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 403, description: 'Forbidden (non-admin, or own account)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ],
    )]
    public function update(string $id, Request $request, UserService $userService, SerializerInterface $serializer, ValidatorInterface $validator): JsonResponse
    {
        try {
            $dto = $this->deserializeAndValidate($request->getContent(), UpdateUserDTO::class, $serializer, $validator);
        } catch (ValidationException $validationException) {
            return $this->createValidationErrorResponse($validationException->getDetails());
        } catch (NotEncodableValueException|NotNormalizableValueException) {
            return $this->badRequest('Malformed request body');
        }

        try {
            $target = $userService->getById($id);
        } catch (UserNotFoundException|\InvalidArgumentException) {
            return $this->notFound('User not found');
        }

        if (!$dto->hasChanges()) {
            return $this->unprocessable('At least one field must be provided for update');
        }

        $actor = $this->getUser();
        \assert($actor instanceof User);

        try {
            $updated = $dto->isActive
                ? $userService->unblockUser($target, $actor)
                : $userService->blockUser($target, $actor);
        } catch (SelfActionForbiddenException $selfActionForbiddenException) {
            return $this->forbidden($selfActionForbiddenException->getMessage());
        } catch (LastAdminException $lastAdminException) {
            return $this->unprocessable($lastAdminException->getMessage());
        }

        return new JsonResponse(UserDTO::fromEntity($updated)->toArray(), Response::HTTP_OK);
    }

    #[Route('/api/admin/users/{id}', name: 'api_admin_user_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Delete(
        path: '/api/admin/users/{id}',
        security: [['Bearer' => []]],
        summary: 'Delete a user (admin)',
        description: 'Hard-deletes the user; their collections, items, likes and comments cascade in the database, and the search documents are removed. Cannot delete your own account or the last admin.',
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'User deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 403, description: 'Forbidden (non-admin, or own account)', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 404, description: 'User not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 422, description: 'Last admin cannot be deleted', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ],
    )]
    public function delete(string $id, UserService $userService): JsonResponse
    {
        try {
            $target = $userService->getById($id);
        } catch (UserNotFoundException|\InvalidArgumentException) {
            return $this->notFound('User not found');
        }

        $actor = $this->getUser();
        \assert($actor instanceof User);

        try {
            $userService->deleteUser($target, $actor);
        } catch (SelfActionForbiddenException $selfActionForbiddenException) {
            return $this->forbidden($selfActionForbiddenException->getMessage());
        } catch (LastAdminException $lastAdminException) {
            return $this->unprocessable($lastAdminException->getMessage());
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
