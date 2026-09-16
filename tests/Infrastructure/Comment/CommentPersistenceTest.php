<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Comment;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Comment\Entity\Comment;
use App\Domain\Comment\Repository\CommentRepositoryInterface;
use App\Domain\Comment\ValueObject\CommentContent;
use App\Domain\Item\Entity\Item;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class CommentPersistenceTest extends KernelTestCase
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
            name: 'Comment Persistence Test',
            email: Email::fromString(\sprintf('comment_persist_%s@example.com', \uniqid())),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );
        $this->em->persist($user);

        return $user;
    }

    private function createItem(User $owner): Item
    {
        $collection = Collection::create(
            owner: $owner,
            name: CollectionName::fromString('Comment Test Collection'),
            theme: Theme::books(),
        );
        $this->em->persist($collection);

        $item = Item::create($collection, '1984');
        $this->em->persist($item);

        return $item;
    }

    private function countRows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM comments');
    }

    public function testPersistCommentAndReloadById(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $comment = Comment::create($owner, $item, CommentContent::fromString("Great **book**\nI liked it"));

        $this->em->persist($comment);
        $this->em->flush();

        $this->em->clear();

        $found = self::getContainer()->get(CommentRepositoryInterface::class)->findById($comment->getId());

        $this->assertNotNull($found);
        $this->assertSame($comment->getId()->toString(), $found->getId()->toString());
        $this->assertSame("Great **book**\nI liked it", $found->getContent()->value());
        $this->assertSame($owner->getId()->toString(), $found->getOwner()->getId()->toString());
        $this->assertSame($item->getId()->toString(), $found->getItem()->getId()->toString());
        $this->assertSame($owner->getId()->toString(), $found->getItem()->getCollection()->getOwner()->getId()->toString());
        $this->assertSame(
            $found->getCreatedAt()->format('Y-m-d H:i:s.u'),
            $found->getUpdatedAt()->format('Y-m-d H:i:s.u'),
        );
    }

    public function testSameUserCanCommentAnItemMultipleTimes(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);

        $this->em->persist(Comment::create($owner, $item, CommentContent::fromString('first')));
        $this->em->persist(Comment::create($owner, $item, CommentContent::fromString('second')));
        $this->em->flush();

        $this->assertSame(2, $this->countRows());
    }

    public function testCascadeOnItemDelete(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $this->em->persist(Comment::create($owner, $item, CommentContent::fromString('to be cascaded')));
        $this->em->flush();

        $this->assertSame(1, $this->countRows());

        $this->em->remove($item);
        $this->em->flush();

        $this->assertSame(0, $this->countRows());
    }

    public function testCascadeOnUserDelete(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $this->em->persist(Comment::create($owner, $item, CommentContent::fromString('to be cascaded')));
        $this->em->flush();

        $this->assertSame(1, $this->countRows());

        $this->em->remove($owner);
        $this->em->flush();

        $this->assertSame(0, $this->countRows());
    }

    public function testEditPersistsContentAndUpdatedAt(): void
    {
        $clock = new MockClock('2026-01-01 10:00:00');
        Clock::set($clock);

        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $comment = Comment::create($owner, $item, CommentContent::fromString('original'));
        $this->em->persist($comment);
        $this->em->flush();

        $createdAt = $comment->getCreatedAt();
        $id = $comment->getId();

        $clock->sleep(1);
        $comment->changeContent(CommentContent::fromString('edited'));
        $this->em->flush();

        $this->em->clear();

        $found = self::getContainer()->get(CommentRepositoryInterface::class)->findById($id);

        $this->assertNotNull($found);
        $this->assertSame('edited', $found->getContent()->value());
        $this->assertSame($createdAt->format('Y-m-d H:i:s'), $found->getCreatedAt()->format('Y-m-d H:i:s'));
        $this->assertGreaterThan($createdAt, $found->getUpdatedAt());
    }
}
