<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Doctrine;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Comment\Entity\Comment;
use App\Domain\Comment\ValueObject\CommentContent;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\Entity\Like;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\UserId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Deleting a user must take their collections, items, likes and comments with
 * them. That behaviour lives in the database, not in the ORM: fwd-5 removed the
 * `ManyToOne` associations to `User`, so Doctrine neither performs the cleanup in
 * PHP nor knows the constraints exist. The three `owner_id` foreign keys are
 * therefore written by hand into the squashed baseline migration.
 *
 * This test is the only thing keeping that hand-written part honest. The test
 * database is built from the migrations, so dropping one of those constraints
 * from the migration — directly, or later by a `doctrine:schema:update` run that
 * does not know about them — leaves the rows behind and turns this red.
 *
 * The constraints have a second job, which the cascade case alone does not cover:
 * they must *reject* a row pointing at a user that does not exist. Without them the
 * database would happily accept an orphan `owner_id`, and since fwd-5 gave the
 * entities no association, nothing above the schema would notice. That is the
 * direction {@see self::testTheOwnerForeignKeysRejectAnUnknownUser()} covers.
 */
final class OwnerCascadeDeletionTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = self::getContainer()->get('doctrine')->getManager();
    }

    public function testDeletingAUserRemovesTheirCollectionsLikesAndComments(): void
    {
        $user = User::register(
            name: 'Cascade',
            email: Email::fromString(\sprintf('cascade_%s@example.com', \uniqid())),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );
        $this->em->persist($user);

        $collection = Collection::create(
            OwnerId::fromBytes($user->getId()->toBytes()),
            name: CollectionName::fromString('Cascade '.\uniqid()),
            theme: Theme::books(),
        );
        $this->em->persist($collection);

        $item = Item::create($collection, 'Item '.\uniqid());
        $this->em->persist($item);

        $like = Like::create(OwnerId::fromBytes($user->getId()->toBytes()), $item);
        $this->em->persist($like);

        $comment = Comment::create(
            OwnerId::fromBytes($user->getId()->toBytes()),
            $item,
            CommentContent::fromString('A comment'),
        );
        $this->em->persist($comment);

        $this->em->flush();
        $this->em->clear();

        $userId = $user->getId()->toBytes();
        $connection = $this->em->getConnection();

        $this->assertSame(1, $this->countFor($connection, 'collections', $userId));
        $this->assertSame(1, $this->countFor($connection, 'likes', $userId));
        $this->assertSame(1, $this->countFor($connection, 'comments', $userId));

        // Raw SQL on purpose: it isolates the database cascade from anything the
        // ORM might do on its own. If a PHP-level cascade were still in place,
        // this statement would leave the orphans behind and the test would pass
        // for the wrong reason.
        $connection->executeStatement('DELETE FROM users WHERE id = ?', [$userId]);

        $this->assertSame(0, $this->countFor($connection, 'collections', $userId));
        $this->assertSame(0, $this->countFor($connection, 'likes', $userId));
        $this->assertSame(0, $this->countFor($connection, 'comments', $userId));

        // The item goes with its collection through items.collection_id, so the
        // whole owned subgraph must be empty once the user is gone.
        $this->assertSame(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM items WHERE collection_id NOT IN (SELECT id FROM collections)'),
        );
    }

    /**
     * The other half of the hand-written foreign keys: they must refuse an orphan.
     *
     * Raw inserts of each table, because a `persist()` would fail for unrelated
     * reasons (a missing entity in the graph) and prove nothing about the
     * constraint. An id that no `users` row carries is generated on the spot.
     */
    public function testTheOwnerForeignKeysRejectAnUnknownUser(): void
    {
        $user = User::register(
            name: 'Referential integrity',
            email: Email::fromString(\sprintf('fk_%s@example.com', \uniqid())),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );
        $this->em->persist($user);

        $collection = Collection::create(
            OwnerId::fromBytes($user->getId()->toBytes()),
            CollectionName::fromString('Referential integrity'),
            Theme::books(),
        );
        $item = Item::create($collection, 'An item');
        $connection = $this->em->getConnection();
        $this->em->persist($collection);
        $this->em->persist($item);
        $this->em->flush();
        $this->em->clear();

        $orphanId = UserId::generate()->toBytes();
        $itemId = $item->getId()->toBytes();

        $this->expectConstraintViolation(
            static fn (): int => $connection->executeStatement(
                'INSERT INTO collections (id, name, theme, owner_id, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(6), NOW(6))',
                [UserId::generate()->toBytes(), 'Orphan', 'books', $orphanId],
            ),
        );
        $this->expectConstraintViolation(
            static fn (): int => $connection->executeStatement(
                'INSERT INTO likes (id, owner_id, item_id, created_at) VALUES (?, ?, ?, NOW(6))',
                [UserId::generate()->toBytes(), $orphanId, $itemId],
            ),
        );
        $this->expectConstraintViolation(
            static fn (): int => $connection->executeStatement(
                'INSERT INTO comments (id, owner_id, item_id, content, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(6), NOW(6))',
                [UserId::generate()->toBytes(), $orphanId, $itemId, 'orphan comment'],
            ),
        );

        // The pre-existing rows survived the three rejected statements.
        $this->assertSame(1, $this->countFor($connection, 'collections', $user->getId()->toBytes()));
    }

    private function expectConstraintViolation(callable $insert): void
    {
        try {
            $insert();
        } catch (\Throwable $e) {
            $this->assertStringContainsString(
                'CONSTRAINT',
                \mb_strtoupper($e->getMessage()),
                'expected a foreign key violation, got: '.$e->getMessage(),
            );

            return;
        }

        $this->fail('the owner_id foreign key accepted a row pointing at a user that does not exist');
    }

    private function countFor(\Doctrine\DBAL\Connection $connection, string $table, string $userId): int
    {
        return (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE owner_id = ?', $table), [$userId]);
    }
}
