<?php

declare(strict_types=1);

namespace App\Tests\Application\Tag\Service;

use App\Application\Tag\Service\TagService;
use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\Repository\TagRepositoryInterface;
use App\Domain\Tag\ValueObject\TagName;
use PHPUnit\Framework\TestCase;

final class TagServiceTest extends TestCase
{
    private TagRepositoryInterface $repo;
    private TagService $service;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(TagRepositoryInterface::class);
        $this->service = new TagService($this->repo);
    }

    public function testResolveByNamesReusesExistingTags(): void
    {
        $existing = Tag::create(TagName::fromString('Books'));

        $this->repo->expects($this->once())
            ->method('getOrCreate')
            ->with($this->callback(static fn (TagName $name): bool => 'Books' === $name->value()))
            ->willReturn($existing);

        $this->assertSame([$existing], $this->service->resolveByNames(['Books']));
    }

    public function testResolveByNamesCreatesNewTags(): void
    {
        $created = Tag::create(TagName::fromString('NewTag'));

        $this->repo->expects($this->once())->method('getOrCreate')->willReturn($created);

        $this->assertSame([$created], $this->service->resolveByNames(['NewTag']));
    }

    public function testResolveByNamesDeduplicatesExactDuplicates(): void
    {
        $tag = Tag::create(TagName::fromString('Dup'));

        $this->repo->expects($this->once())->method('getOrCreate')->willReturn($tag);

        $this->assertSame([$tag], $this->service->resolveByNames(['Dup', 'Dup']));
    }

    public function testResolveByNamesDeduplicatesCaseInsensitiveIdentity(): void
    {
        $tag = Tag::create(TagName::fromString('Books'));
        $requested = [];

        // "Books" and "books" are distinct TagName values (different keys), so the
        // repository is asked twice; it returns the same tag for both, and the
        // service deduplicates by identity.
        $this->repo->expects($this->exactly(2))
            ->method('getOrCreate')
            ->willReturnCallback(function (TagName $name) use (&$requested, $tag): Tag {
                $requested[] = $name->value();

                return $tag;
            });

        $this->assertSame([$tag], $this->service->resolveByNames(['Books', 'books']));
        $this->assertSame(['Books', 'books'], $requested);
    }

    public function testResolveByNamesPreservesFirstOccurrenceOrder(): void
    {
        $beta = Tag::create(TagName::fromString('Beta'));
        $alpha = Tag::create(TagName::fromString('Alpha'));

        $this->repo->expects($this->exactly(2))
            ->method('getOrCreate')
            ->willReturnOnConsecutiveCalls($beta, $alpha);

        $this->assertSame([$beta, $alpha], $this->service->resolveByNames(['Beta', 'Alpha']));
    }

    public function testResolveByNamesValidatesAllNamesBeforeResolving(): void
    {
        $this->repo->expects($this->never())->method('getOrCreate');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->resolveByNames(['Valid', 'x']);
    }

    public function testResolveByNamesReturnsEmptyForEmptyInput(): void
    {
        $this->repo->expects($this->never())->method('getOrCreate');

        $this->assertSame([], $this->service->resolveByNames([]));
    }

    public function testListTagsPassesThroughNullTerm(): void
    {
        $this->repo->expects($this->once())
            ->method('search')
            ->with(null, 50, 0)
            ->willReturn([]);

        $this->assertSame([], $this->service->listTags(null));
    }

    public function testListTagsNormalizesAndCollapsesWhitespace(): void
    {
        $this->repo->expects($this->once())
            ->method('search')
            ->with('Bo ok', 25, 5)
            ->willReturn([]);

        $this->assertSame([], $this->service->listTags("  Bo\t ok  ", 25, 5));
    }

    public function testListTagsStripsControlCharacters(): void
    {
        $this->repo->expects($this->once())
            ->method('search')
            ->with('ab', 50, 0)
            ->willReturn([]);

        $this->assertSame([], $this->service->listTags("a\x00b"));
    }

    public function testListTagsBlankTermBecomesNull(): void
    {
        $this->repo->expects($this->once())
            ->method('search')
            ->with(null, 50, 0)
            ->willReturn([]);

        $this->assertSame([], $this->service->listTags('   '));
    }
}
