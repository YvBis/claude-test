<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Factory;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Tag\Entity\Tag;
use App\Domain\User\Entity\User;
use App\Tests\Factory\CollectionFactory;
use App\Tests\Factory\CommentFactory;
use App\Tests\Factory\ItemFactory;
use App\Tests\Factory\LikeFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves each factory produces a row a fresh query can read back.
 *
 * `clear()` between the write and the read is the point: without it the
 * identity map would return the very object the factory just handed over, and
 * the test would prove that PHP objects can be referenced - not that anything
 * reached the database. That distinction is exactly what stage 9 depends on.
 *
 * Associations are asserted with scalar SQL rather than by walking the entity:
 * every domain entity is `final`, and ORM 3 cannot build the lazy ghost proxies
 * it needs for a lazy association after `clear()` (the same constraint that
 * forces the repositories to JOIN FETCH). Reading the foreign key directly
 * proves the row without fighting the mapping.
 *
 * These tests stay in the suite (PRD 9.2): they are the regression net for the
 * factories when the domain constructors change.
 */
final class FactoryRoundTripTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
    }

    public function testUserFactoryPersistsAReadableUser(): void
    {
        $user = UserFactory::createOne();
        $id = $user->getId()->toBytes();

        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(User::class, $id);

        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame($user->getEmail()->value(), $reloaded->getEmail()->value());
        self::assertTrue($reloaded->isActive());
    }

    public function testCollectionFactoryPersistsWithItsOwner(): void
    {
        $collection = CollectionFactory::createOne();
        $id = $collection->getId()->toBytes();

        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Collection::class, $id);

        self::assertInstanceOf(Collection::class, $reloaded);
        self::assertSame($collection->getOwnerId()->toString(), $reloaded->getOwnerId()->toString());
        self::assertSame($collection->getName()->value(), $reloaded->getName()->value());

        // The owner's row must exist too, otherwise the hand-written FK would
        // reject the collection (and this assertion is what would catch it).
        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM users WHERE id = :id',
                ['id' => $reloaded->getOwnerId()->toBytes()],
            ),
        );
    }

    public function testItemFactoryPersistsInsideItsCollection(): void
    {
        $item = ItemFactory::createOne();
        $id = $item->getId()->toBytes();
        $collectionId = $item->getCollection()->getId()->toBytes();

        $this->entityManager->clear();

        $row = $this->connection->fetchAssociative(
            'SELECT HEX(collection_id) AS collection_id, name FROM items WHERE id = :id',
            ['id' => $id],
        );

        self::assertIsArray($row);
        self::assertSame(\strtoupper(\bin2hex($collectionId)), $row['collection_id']);
        self::assertSame($item->getName(), $row['name']);
    }

    public function testTagFactoryPersistsItsName(): void
    {
        $tag = TagFactory::createOne();
        $id = $tag->getId()->toBytes();

        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Tag::class, $id);

        self::assertInstanceOf(Tag::class, $reloaded);
        self::assertSame($tag->getName()->value(), $reloaded->getName()->value());
    }

    public function testLikeFactoryPersistsAndKeepsItsPair(): void
    {
        $like = LikeFactory::createOne();
        $id = $like->getId()->toBytes();

        $this->entityManager->clear();

        $row = $this->connection->fetchAssociative(
            'SELECT HEX(owner_id) AS owner_id, HEX(item_id) AS item_id FROM likes WHERE id = :id',
            ['id' => $id],
        );

        self::assertIsArray($row);
        self::assertSame(\strtoupper(\bin2hex($like->getOwnerId()->toBytes())), $row['owner_id']);
        self::assertSame(
            \strtoupper(\bin2hex($like->getItem()->getId()->toBytes())),
            $row['item_id'],
        );
    }

    public function testCommentFactoryPersistsItsContent(): void
    {
        $comment = CommentFactory::createOne();
        $id = $comment->getId()->toBytes();

        $this->entityManager->clear();

        $content = $this->connection->fetchOne(
            'SELECT content FROM comments WHERE id = :id',
            ['id' => $id],
        );

        self::assertSame($comment->getContent()->value(), $content);
    }

    public function testCreateManyPersistsAllEntities(): void
    {
        $users = UserFactory::createMany(3);

        self::assertCount(3, $users);
        self::assertSame(
            3,
            (int) $this->entityManager
                ->createQuery('SELECT COUNT(u.id) FROM '.User::class.' u')
                ->getSingleScalarResult(),
        );
    }
}
