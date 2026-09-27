<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\Controller;

use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class LogoutController extends AbstractController
{
    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[OA\Post(
        path: '/api/logout',
        summary: 'User logout',
        description: 'Logout user (client-side token removal for stateless JWT)',
        security: [['Bearer' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 204,
                description: 'Logged out successfully (no content)'
            ),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    public function logout(): JsonResponse
    {
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
