<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Comment\Repository;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Comment\Entity\Comment;
use App\Domain\Comment\Repository\CommentRepositoryInterface;
use App\Domain\Comment\ValueObject\CommentContent;
use App\Domain\Comment\ValueObject\CommentId;
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
            owner: $owner,
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
        $comment = Comment::create($owner, $item, CommentContent::fromString($content));
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
            $this->assertSame($owner->getId()->toString(), $comment->getOwner()->getId()->toString());
        }
        $this->assertSame(['mine 1', 'mine 2'], \array_map(
            static fn (Comment $comment): string => $comment->getContent()->value(),
            $comments,
        ));
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
