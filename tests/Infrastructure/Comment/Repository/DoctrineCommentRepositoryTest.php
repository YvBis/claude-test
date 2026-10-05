<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Comment\Repository;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Comment\Entity\Comment;
use App\Domain\Comment\Repository\CommentRepositoryInterface;
use App\Domain\Comment\ValueObject\CommentContent;
use App\Domain\Comment\ValueObject\CommentId;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class DoctrineCommentRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = self::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());

        parent::tearDown();
    }

    private function createUser(): User
    {
        $user = User::register(
            name: 'Comment Repo Test',
            email: Email::fromString(\sprintf('comment_repo_%s@example.com', \uniqid())),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );
        $this->em->persist($user);

        return $user;
    }

    private function createItem(User $owner): Item
    {
        $collection = Collection::create(
            ownerId: OwnerId::fromBytes($owner->getId()->toBytes()),
            name: CollectionName::fromString('Comment Repo Collection'),
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

    private function comment(User $owner, Item $item, string $content): Comment
    {
        $comment = Comment::create(OwnerId::fromBytes($owner->getId()->toBytes()), $item, CommentContent::fromString($content));
        $this->em->persist($comment);

        return $comment;
    }

    private function repository(): CommentRepositoryInterface
    {
        return self::getContainer()->get(CommentRepositoryInterface::class);
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repository()->findById(CommentId::generate()));
    }

    public function testCountByItemId(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $secondUser = $this->createUser();
        $this->comment($owner, $item, 'first');
        $this->comment($secondUser, $item, 'second');
        $this->em->flush();

        $this->assertSame(2, $this->repository()->countByItemId($item->getId()));
    }

    public function testCountByOwnerId(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $firstItem = $this->createItem($owner);
        $secondItem = $this->createItem($owner);
        $this->comment($owner, $firstItem, 'mine 1');
        $this->comment($owner, $secondItem, 'mine 2');
        $this->comment($other, $firstItem, 'theirs');
        $this->em->flush();

        $this->assertSame(2, $this->repository()->countByOwnerId($this->ownerId($owner)));
        $this->assertSame(1, $this->repository()->countByOwnerId($this->ownerId($other)));
    }

    public function testFindByItemIdPaginatedOrdered(): void
    {
        $clock = new MockClock('2026-01-01 10:00:00');
        Clock::set($clock);

        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $first = $this->createUser();
        $second = $this->createUser();
        $third = $this->createUser();
        $this->comment($first, $item, 'first');
        $clock->sleep(1);
        $this->comment($second, $item, 'second');
        $clock->sleep(1);
        $this->comment($third, $item, 'third');
        $this->em->flush();

        $page = $this->repository()->findByItemId($item->getId(), limit: 2, offset: 1);

        $this->assertCount(2, $page);
        $this->assertSame('second', $page[0]->getContent()->value());
        $this->assertSame('third', $page[1]->getContent()->value());
    }

    public function testFindByOwnerIdReturnsOnlyOwnComments(): void
    {
        $clock = new MockClock('2026-01-01 10:00:00');
        Clock::set($clock);

        $owner = $this->createUser();
        $other = $this->createUser();
        $firstItem = $this->createItem($owner);
        $secondItem = $this->createItem($owner);
        $this->comment($owner, $firstItem, 'mine 1');
        $clock->sleep(1);
        $this->comment($owner, $secondItem, 'mine 2');
        $this->comment($other, $firstItem, 'theirs');
        $this->em->flush();

        $comments = $this->repository()->findByOwnerId($this->ownerId($owner));

        $this->assertCount(2, $comments);
        foreach ($comments as $comment) {
            $this->assertSame($owner->getId()->toString(), $comment->getOwnerId()->toString());
        }
        $this->assertSame(['mine 1', 'mine 2'], \array_map(
            static fn (Comment $comment): string => $comment->getContent()->value(),
            $comments,
        ));
    }

    /**
     * Locks the hydration invariant that `SocialContentVoter::voteOnAttribute`
     * and `CommentDTO::fromEntity` both depend on: the returned comment must
     * carry a hydrated *item*.
     *
     * fwd-5 rewrote the ownership half of this test. It used to assert a hydrated
     * owner, because the comment held a `ManyToOne` to `User` that the voter read.
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
     * Scope: this locks BOTH joins of the shared `withAll()` helper — `c.item`
     * and `item.collection`. The collection join earns its keep through
     * hydration rather than through a reader: when the object hydrator loads an
     * item it must resolve `Item.collection`, and `Collection` is `final` (the
     * review-6 decision), so a lazy ghost cannot be generated and Doctrine
     * throws `Cannot generate lazy ghost` instead of deferring the fetch.
     *
     * What guards the join today is not this assertion: it is that the query
     * throws inside hydration when the join is missing. `Collection` is final,
     * so today it can never be an uninitialized ghost and this assert cannot
     * fail on its own — the lock gains teeth only if `nativeLazyObjects` is ever
     * enabled, which is why it is here rather than left to that migration.
     *
     * fwd-6b measured the join: removing it produced 4 hydration errors plus 21
     * cascading failures across `CommentControllerTest`. This side does have a
     * genuine cold reader: `CommentPersistenceTest` clears the EM and then reads
     * `getItem()->getCollection()`, so it would fail without the join. The
     * Like-side equivalent omits the clear and would not. The honest scope of
     * this lock is therefore "the query must succeed", plus the item assertion
     * above. What it cannot tell you is the reverse — that the join became pure
     * over-fetch while still being present.
     */
    public function testFindByIdReturnsTheOwnerIdAndAHydratedItem(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $comment = $this->comment($owner, $item, 'to be moderated');
        $this->em->flush();
        $commentId = $comment->getId();
        $this->em->clear();

        $found = $this->repository()->findById($commentId);

        $this->assertNotNull($found);

        // fwd-5: ownership is a column, so there is no owner association left to
        // hydrate — the id must survive the round trip on its own, which is what
        // SocialContentVoter and CommentDTO now read.
        $this->assertTrue($found->getOwnerId()->equals(OwnerId::fromBytes($owner->getId()->toBytes())));
        $unitOfWork = $this->em->getUnitOfWork();

        $this->assertFalse(
            $unitOfWork->isUninitializedObject($found->getItem()),
            'findById must return a hydrated item: CommentDTO reads getItem()->getId()',
        );
        $this->assertFalse(
            $unitOfWork->isUninitializedObject($found->getItem()->getCollection()),
            'findById must return a hydrated collection: ORM 3 cannot proxy the final '
            .'Collection class, so the object hydrator needs the join to resolve it while '
            .'loading the item (fwd-6b). This assert only matters if nativeLazyObjects is '
            .'ever enabled; today the query succeeding is what guards the join.',
        );
    }

    public function testRemoveComment(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $comment = $this->comment($owner, $item, 'to remove');
        $this->em->flush();

        $found = $this->repository()->findById($comment->getId());
        $this->assertNotNull($found);

        $this->repository()->remove($found);
        $this->em->flush();

        $this->assertNull($this->repository()->findById($comment->getId()));
    }
}
