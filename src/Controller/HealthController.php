<?php

namespace App\Controller;

use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    #[OA\Get(
        path: '/health',
        summary: 'Health check endpoint',
        description: 'Returns service health status. Used for liveness/readiness probes.',
        tags: ['System'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service is healthy',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time', example: '2026-07-16T12:00:00Z'),
                    ],
                    required: ['status', 'timestamp']
                )
            ),
        ]
    )]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
