<?php

declare(strict_types=1);

namespace App\Infrastructure\Api\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Item',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '018f0a1b-2c3d-4e5f-6789-0123456789ab'),
        new OA\Property(property: 'name', type: 'string', example: '1984'),
        new OA\Property(property: 'collection_id', type: 'string', format: 'uuid', example: '018f0a1b-2c3d-4e5f-6789-0123456789ab'),
        new OA\Property(property: 'slots', type: 'array', description: 'Filled slots only', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-07-17T12:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-07-17T12:00:00Z'),
    ],
    required: ['id', 'name', 'collection_id', 'slots', 'tags', 'created_at', 'updated_at'],
)]
final readonly class ItemSchema
{
}
