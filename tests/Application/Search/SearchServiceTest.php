<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Application\Search\SearchReaderInterface;
use App\Application\Search\SearchService;
use PHPUnit\Framework\TestCase;

final class SearchServiceTest extends TestCase
{
    private SearchReaderInterface $reader;
    private SearchService $service;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(SearchReaderInterface::class);
        $this->service = new SearchService($this->reader);
    }

    public function testSearchItemsBuildsTheExactFilterMap(): void
    {
        $this->reader->expects($this->once())
            ->method('searchItems')
            ->with('dune', ['owner_id' => 'o1', 'collection_id' => 'c1', 'tags' => ['a', 'b']], 20, 5)
            ->willReturn([]);

        self::assertSame([], $this->service->searchItems('dune', 'o1', 'c1', ['a', 'b'], 20, 5));
    }

    public function testSearchItemsOmitsAbsentFilters(): void
    {
        $this->reader->expects($this->once())
            ->method('searchItems')
            ->with('dune', [], 20, 0)
            ->willReturn([]);

        $this->service->searchItems('dune', null, null, [], 20, 0);
    }

    public function testSearchCollectionsBuildsTheExactFilterMap(): void
    {
        $this->reader->expects($this->once())
            ->method('searchCollections')
            ->with('books', ['owner_id' => 'o1', 'theme' => 'games'], 10, 0)
            ->willReturn([]);

        $this->service->searchCollections('books', 'o1', 'games', 10, 0);
    }

    public function testSearchCollectionsOmitsAbsentFilters(): void
    {
        $this->reader->expects($this->once())
            ->method('searchCollections')
            ->with('books', [], 10, 0)
            ->willReturn([]);

        $this->service->searchCollections('books', null, null, 10, 0);
    }
}
