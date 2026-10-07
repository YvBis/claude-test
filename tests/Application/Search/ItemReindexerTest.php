<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Search\ItemDocument;
use App\Application\Search\ItemReindexer;
use App\Application\Search\SearchIndexerInterface;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\Repository\ItemRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class ItemReindexerTest extends TestCase
{
    private function createItem(string $name): Item
    {
        $collection = Collection::create(
            ownerId: OwnerId::generate(),
            name: CollectionName::fromString('Reindex Collection'),
            theme: Theme::books(),
        );

        return Item::create($collection, $name);
    }

    public function testWalksEveryPageIndexesEachItemAndClearsBetweenPages(): void
    {
        $pages = [
            [$this->createItem('a'), $this->createItem('b')],
            [$this->createItem('c')],
        ];

        $repository = $this->createMock(ItemRepositoryInterface::class);
        $repository->expects($this->exactly(2))
            ->method('findAll')
            ->willReturnCallback(static function (int $limit, int $offset) use ($pages): array {
                self::assertSame(2, $limit);

                return $pages[$offset / 2] ?? [];
            });

        $events = [];
        $unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $unitOfWork->expects($this->exactly(2))
            ->method('clear')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'clear';
            });

        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->expects($this->exactly(3))
            ->method('indexItem')
            ->willReturnCallback(static function (ItemDocument $document) use (&$events): void {
                $events[] = $document->name;
            });

        $count = (new ItemReindexer($repository, $unitOfWork, $indexer))->reindexAll(2);

        self::assertSame(3, $count);
        // Documents are built before clear(): reading an entity after the
        // identity map is cleared would hit a detached lazy proxy.
        self::assertSame(['a', 'b', 'clear', 'c', 'clear'], $events);
    }

    public function testEmptyRepositoryIndexesNothing(): void
    {
        $repository = $this->createMock(ItemRepositoryInterface::class);
        $repository->expects($this->once())->method('findAll')->willReturn([]);
        $unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $unitOfWork->expects($this->never())->method('clear');
        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->expects($this->never())->method('indexItem');

        self::assertSame(0, (new ItemReindexer($repository, $unitOfWork, $indexer))->reindexAll());
    }

    public function testRejectsANonPositiveBatchSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ItemReindexer(
            $this->createStub(ItemRepositoryInterface::class),
            $this->createStub(UnitOfWorkInterface::class),
            $this->createStub(SearchIndexerInterface::class),
        ))->reindexAll(0);
    }
}
