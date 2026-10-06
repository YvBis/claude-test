<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Application\Search\CollectionDocument;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use PHPUnit\Framework\TestCase;

final class CollectionDocumentTest extends TestCase
{
    private function createCollection(?string $description = null): Collection
    {
        return Collection::create(
            ownerId: OwnerId::generate(),
            name: CollectionName::fromString('My Games'),
            theme: Theme::games(),
            description: $description,
        );
    }

    public function testFromEntityMapsAllFields(): void
    {
        $collection = $this->createCollection('Backlog of games');
        $document = CollectionDocument::fromEntity($collection);

        self::assertSame($collection->getId()->toString(), $document->id);
        self::assertSame('My Games', $document->name);
        self::assertSame('games', $document->theme);
        self::assertSame('Backlog of games', $document->description);
        self::assertSame($collection->getOwnerId()->toString(), $document->ownerId);
    }

    public function testNullDescriptionBecomesEmptyString(): void
    {
        $document = CollectionDocument::fromEntity($this->createCollection());

        self::assertSame('', $document->description);
    }

    public function testToArrayShape(): void
    {
        $array = CollectionDocument::fromEntity($this->createCollection('x'))->toArray();

        self::assertSame(
            ['id', 'name', 'theme', 'description', 'owner_id'],
            \array_keys($array),
        );
    }
}
