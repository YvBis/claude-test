<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Like\Repository;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\Entity\Like;
use App\Domain\Like\Repository\LikeRepositoryInterface;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineLikeRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = self::getContainer()->get('doctrine')->getManager();
    }

    private function createUser(): User
    {
        $user = User::register(
            name: 'Like Repo Test',
            email: Email::fromString(\sprintf('like_repo_%s@example.com', \uniqid())),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );
        $this->em->persist($user);

        return $user;
    }

    private function createItem(User $owner): Item
    {
        $collection = Collection::create(
            ownerId: OwnerId::fromBytes($owner->getId()->toBytes()),
            name: CollectionName::fromString('Like Repo Collection'),
            theme: Theme::books(),
        );
        $this->em->persist($collection);

        $item = Item::create($collection, '1984');
        $this->em->persist($item);

        return $item;
    }

    private function ownerId(User $user): OwnerId
    {
        return OwnerId::fromString($user->getId()->toString());
    }

    private function like(User $owner, Item $item): Like
    {
        $like = Like::create(OwnerId::fromBytes($owner->getId()->toBytes()), $item);
        $this->em->persist($like);

        return $like;
    }

    public function testFindByOwnerAndItem(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $this->like($owner, $item);
        $this->em->flush();

        $repo = self::getContainer()->get(LikeRepositoryInterface::class);
        $found = $repo->findByOwnerAndItem($this->ownerId($owner), $item->getId());

        $this->assertNotNull($found);
        $this->assertSame($owner->getId()->toString(), $found->getOwnerId()->toString());
        $this->assertSame($item->getId()->toString(), $found->getItem()->getId()->toString());
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $repo = self::getContainer()->get(LikeRepositoryInterface::class);

        $this->assertNull($repo->findById(\App\Domain\Like\ValueObject\LikeId::generate()));
    }

    public function testCountByItemId(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $secondUser = $this->createUser();
        $this->like($owner, $item);
        $this->like($secondUser, $item);
        $this->em->flush();

        $repo = self::getContainer()->get(LikeRepositoryInterface::class);

        $this->assertSame(2, $repo->countByItemId($item->getId()));
    }

    public function testFindByItemIdPaginatedOrdered(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $first = $this->createUser();
        $second = $this->createUser();
        $third = $this->createUser();
        $this->like($first, $item);
        $this->like($second, $item);
        $this->like($third, $item);
        $this->em->flush();

        $repo = self::getContainer()->get(LikeRepositoryInterface::class);
        $page = $repo->findByItemId($item->getId(), limit: 2, offset: 1);

        $this->assertCount(2, $page);
        $this->assertSame($second->getId()->toString(), $page[0]->getOwnerId()->toString());
        $this->assertSame($third->getId()->toString(), $page[1]->getOwnerId()->toString());
    }

    public function testRemoveLike(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $this->like($owner, $item);
        $this->em->flush();

        $repo = self::getContainer()->get(LikeRepositoryInterface::class);
        $found = $repo->findByOwnerAndItem($this->ownerId($owner), $item->getId());
        $this->assertNotNull($found);

        $repo->remove($found);
        $this->em->flush();

        $this->assertNull($repo->findByOwnerAndItem($this->ownerId($owner), $item->getId()));
    }

    public function testFindByOwnerIdReturnsOnlyOwnLikesOldestFirst(): void
    {
        $owner = $this->createUser();
        $firstItem = $this->createItem($owner);
        $secondItem = $this->createItem($owner);
        $other = $this->createUser();
        $this->like($owner, $firstItem);
        $this->like($owner, $secondItem);
        $this->like($other, $firstItem);
        $this->em->flush();

        $repo = self::getContainer()->get(LikeRepositoryInterface::class);
        $found = $repo->findByOwnerId($this->ownerId($owner));

        $this->assertCount(2, $found);
        $this->assertSame($firstItem->getId()->toString(), $found[0]->getItem()->getId()->toString());
        $this->assertSame($secondItem->getId()->toString(), $found[1]->getItem()->getId()->toString());
    }

    public function testFindByOwnerIdPaginates(): void
    {
        $owner = $this->createUser();
        $firstItem = $this->createItem($owner);
        $secondItem = $this->createItem($owner);
        $thirdItem = $this->createItem($owner);
        $this->like($owner, $firstItem);
        $this->like($owner, $secondItem);
        $this->like($owner, $thirdItem);
        $this->em->flush();

        $repo = self::getContainer()->get(LikeRepositoryInterface::class);
        $page = $repo->findByOwnerId($this->ownerId($owner), limit: 2, offset: 1);

        $this->assertCount(2, $page);
        $this->assertSame($secondItem->getId()->toString(), $page[0]->getItem()->getId()->toString());
        $this->assertSame($thirdItem->getId()->toString(), $page[1]->getItem()->getId()->toString());
    }

    /**
     * Locks the hydration invariant that `SocialContentVoter::voteOnAttribute`
     * and `LikeDTO::fromEntity` both depend on: the returned like must carry a
     * hydrated *item*.
     *
     * fwd-5 rewrote the ownership half of this test. It used to assert a hydrated
     * owner, because the like held a `ManyToOne` to `User` that the voter read.
     * Ownership is now an `owner_id` column read straight off the entity, so the
     * remaining guarantee is that the id survives the round trip on its own and
     * that no owner association can go stale behind it.
     *
     * `$em->clear()` forces the cold path, because `UnitOfWork::createEntity`
     * reuses an already-managed association target instead of proxying it — a
     * join-less query would then pass only when the item happens to be in the
     * identity map, and fail on the next request.
     *
     * `isUninitializedObject()` is native-lazy-aware (`UnitOfWork.php:3303`), so
     * this lock keeps working if `nativeLazyObjects` is ever enabled and a ghost
     * stops throwing.
     *
     * Scope: this locks the `l.item` join of the shared `withAll()` helper. It
     * does NOT lock `item.collection`, which no consumer reads today, and it
     * cannot tell you that this one has become pure over-fetch while still
     * present.
     */
    public function testFindByIdReturnsTheOwnerIdAndAHydratedItem(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $like = $this->like($owner, $item);
        $this->em->flush();
        $likeId = $like->getId();
        $this->em->clear();

        $repo = self::getContainer()->get(LikeRepositoryInterface::class);
        $found = $repo->findById($likeId);

        $this->assertNotNull($found);

        // fwd-5: ownership is a column, so there is no owner association left to
        // hydrate — the id must survive the round trip on its own, which is what
        // SocialContentVoter and LikeDTO now read.
        $this->assertTrue($found->getOwnerId()->equals(OwnerId::fromBytes($owner->getId()->toBytes())));
        $unitOfWork = $this->em->getUnitOfWork();

        $this->assertFalse(
            $unitOfWork->isUninitializedObject($found->getItem()),
            'findById must return a hydrated item: LikeDTO reads getItem()->getId()',
        );
    }
}
