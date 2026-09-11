<?php

declare(strict_types=1);

namespace App\Tests\Domain\Tag\Entity;

use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\ValueObject\TagId;
use App\Domain\Tag\ValueObject\TagName;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class TagTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-01-01 10:00:00');
        Clock::set($this->clock);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
    }

    public function testCreateFactoryCreatesEntity(): void
    {
        $tag = Tag::create(TagName::fromString('Books'));

        $this->assertInstanceOf(Tag::class, $tag);
        $this->assertInstanceOf(TagId::class, $tag->getId());
        $this->assertSame('Books', $tag->getName()->value());
    }

    public function testCreateSetsCreatedAtAndUpdatedAt(): void
    {
        $tag = Tag::create(TagName::fromString('Books'));

        $this->assertInstanceOf(\DateTimeImmutable::class, $tag->getCreatedAt());
        $this->assertSame($tag->getCreatedAt(), $tag->getUpdatedAt());
    }

    public function testCreateGeneratesUniqueIds(): void
    {
        $a = Tag::create(TagName::fromString('Books'));
        $b = Tag::create(TagName::fromString('Games'));

        $this->assertFalse($a->getId()->equals($b->getId()));
    }

    public function testConstructorAcceptsExplicitIdAndName(): void
    {
        $id = TagId::generate();

        $tag = new Tag($id->toBytes(), TagName::fromString('Books'));

        $this->assertTrue($id->equals($tag->getId()));
        $this->assertSame('Books', $tag->getName()->value());
    }

    public function testGetIdRoundTripsGeneratedId(): void
    {
        $tag = Tag::create(TagName::fromString('Books'));

        $this->assertSame(16, \strlen($tag->getId()->toBytes()));
    }
}
