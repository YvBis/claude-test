<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Search\CollectionDocument;
use App\Application\Search\CollectionReindexer;
use App\Application\Search\SearchIndexerInterface;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\Repository\CollectionRepositoryInterface;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use PHPUnit\Framework\TestCase;

final class CollectionReindexerTest extends TestCase
{
    private function createCollection(string $name): Collection
    {
        return Collection::create(
            ownerId: OwnerId::generate(),
            name: CollectionName::fromString($name),
            theme: Theme::books(),
        );
    }

    public function testWalksEveryPageIndexesEachCollectionAndClearsBetweenPages(): void
    {
        $pages = [
            [$this->createCollection('aaa'), $this->createCollection('bbb')],
            [$this->createCollection('ccc')],
        ];

        $repository = $this->createMock(CollectionRepositoryInterface::class);
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
            ->method('indexCollection')
            ->willReturnCallback(static function (CollectionDocument $document) use (&$events): void {
                $events[] = $document->name;
            });

        $count = (new CollectionReindexer($repository, $unitOfWork, $indexer))->reindexAll(2);

        self::assertSame(3, $count);
        self::assertSame(['aaa', 'bbb', 'clear', 'ccc', 'clear'], $events);
    }

    public function testEmptyRepositoryIndexesNothing(): void
    {
        $repository = $this->createMock(CollectionRepositoryInterface::class);
        $repository->expects($this->once())->method('findAll')->willReturn([]);
        $unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $unitOfWork->expects($this->never())->method('clear');
        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->expects($this->never())->method('indexCollection');

        self::assertSame(0, (new CollectionReindexer($repository, $unitOfWork, $indexer))->reindexAll());
    }

    public function testRejectsANonPositiveBatchSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CollectionReindexer(
            $this->createStub(CollectionRepositoryInterface::class),
            $this->createStub(UnitOfWorkInterface::class),
            $this->createStub(SearchIndexerInterface::class),
        ))->reindexAll(0);
    }
}
