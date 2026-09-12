<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Tag\Repository;

use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\Repository\TagRepositoryInterface;
use App\Domain\Tag\ValueObject\TagName;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineTagRepositoryTest extends KernelTestCase
{
    private TagRepositoryInterface $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = self::getContainer()->get(TagRepositoryInterface::class);
        $this->em = self::getContainer()->get('doctrine')->getManager();
    }

    private function countByName(string $name): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM tags WHERE name = ?',
            [$name],
        );
    }

    public function testSaveAndFindById(): void
    {
        $tag = Tag::create(TagName::fromString('Books'));
        $this->repo->save($tag);
        $this->em->flush();

        $found = $this->repo->findById($tag->getId());

        $this->assertNotNull($found);
        $this->assertSame('Books', $found->getName()->value());
        $this->assertTrue($found->getId()->equals($tag->getId()));
    }

    public function testFindByNameIsCaseInsensitive(): void
    {
        $tag = Tag::create(TagName::fromString('Books'));
        $this->repo->save($tag);
        $this->em->flush();

        $found = $this->repo->findByName(TagName::fromString('books'));

        $this->assertNotNull($found);
        $this->assertSame('Books', $found->getName()->value());
        $this->assertTrue($found->getId()->equals($tag->getId()));
    }

    public function testFindByNameReturnsNullWhenNotFound(): void
    {
        $found = $this->repo->findByName(TagName::fromString('Nonexistent'));

        $this->assertNull($found);
    }

    public function testRemoveDeletesTag(): void
    {
        $tag = Tag::create(TagName::fromString('ToRemove'));
        $this->repo->save($tag);
        $this->em->flush();

        $this->repo->remove($tag);
        $this->em->flush();

        $this->assertNull($this->repo->findById($tag->getId()));
    }

    public function testGetOrCreateCreatesNewTag(): void
    {
        $tag = $this->repo->getOrCreate(TagName::fromString('NewTag'));

        $this->assertSame('NewTag', $tag->getName()->value());
        $this->assertSame(1, $this->countByName('NewTag'));

        $found = $this->repo->findByName(TagName::fromString('newtag'));
        $this->assertNotNull($found);
        $this->assertTrue($found->getId()->equals($tag->getId()));
        $this->assertSame('NewTag', $found->getName()->value());
    }

    public function testGetOrCreateReturnsExistingTagCaseInsensitive(): void
    {
        $existing = Tag::create(TagName::fromString('Books'));
        $this->repo->save($existing);
        $this->em->flush();

        $resolved = $this->repo->getOrCreate(TagName::fromString('books'));

        $this->assertTrue($resolved->getId()->equals($existing->getId()));
        $this->assertSame('Books', $resolved->getName()->value());
        $this->assertSame(1, $this->countByName('Books'));
    }

    public function testGetOrCreateTwiceReturnsSameTagWithoutDuplicate(): void
    {
        $first = $this->repo->getOrCreate(TagName::fromString('RaceTag'));
        $second = $this->repo->getOrCreate(TagName::fromString('RaceTag'));

        $this->assertTrue($first->getId()->equals($second->getId()));
        $this->assertSame(1, $this->countByName('RaceTag'));
    }
}
