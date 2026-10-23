<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Factory;

use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Role;
use App\Tests\Factory\CollectionFactory;
use App\Tests\Factory\CommentFactory;
use App\Tests\Factory\ItemFactory;
use App\Tests\Factory\LikeFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Covers the factory surface that 9.3 (story) and 9.4 (seed command) consume:
 * the trait helpers and the "pin an existing owner" path.
 *
 * The owner test is the important one: `defaults()` runs before attribute
 * overrides are merged, so a factory that computes its owner eagerly would mint
 * a throwaway user for every pinned collection. `LazyValue` exists to prevent
 * exactly that, and nothing else would catch its loss.
 */
final class FactoryHelpersTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
    }

    public function testAdminHelperSetsTheAdminRole(): void
    {
        $user = UserFactory::new()->admin()->createOne();

        self::assertTrue($user->getRole()->isAdmin());
        self::assertInstanceOf(Role::class, $user->getRole());
    }

    public function testInactiveHelperDeactivatesTheUser(): void
    {
        $user = UserFactory::new()->inactive()->createOne();

        self::assertFalse($user->isActive());
    }

    public function testInCollectionHelperPinsTheCollection(): void
    {
        $collection = CollectionFactory::createOne();
        $item = ItemFactory::new()->inCollection($collection)->createOne();

        self::assertSame($collection->getId()->toBytes(), $item->getCollection()->getId()->toBytes());
    }

    public function testLikeHelpersPinItemAndOwner(): void
    {
        $item = ItemFactory::createOne();
        $owner = OwnerId::fromBytes(UserFactory::createOne()->getId()->toBytes());

        $like = LikeFactory::new()->forItem($item)->byOwner($owner)->createOne();

        self::assertSame($item->getId()->toBytes(), $like->getItem()->getId()->toBytes());
        self::assertTrue($owner->equals($like->getOwnerId()));
    }

    public function testCommentForItemPinsTheItem(): void
    {
        $item = ItemFactory::createOne();

        $comment = CommentFactory::new()->forItem($item)->createOne();

        self::assertSame($item->getId()->toBytes(), $comment->getItem()->getId()->toBytes());
    }

    public function testPinnedOwnerDoesNotMintAThrowawayUser(): void
    {
        $owner = OwnerId::fromBytes(UserFactory::createOne()->getId()->toBytes());
        $usersBefore = $this->countUsers();

        CollectionFactory::createMany(3, ['ownerId' => $owner]);

        self::assertSame(
            $usersBefore,
            $this->countUsers(),
            'Pinned owners must not create throwaway users: the owner default has to stay lazy.',
        );
    }

    private function countUsers(): int
    {
        return (int) $this->entityManager
            ->createQuery('SELECT COUNT(u.id) FROM '.User::class.' u')
            ->getSingleScalarResult();
    }
}
