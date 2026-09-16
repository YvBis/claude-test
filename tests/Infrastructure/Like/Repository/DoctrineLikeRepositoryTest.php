<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Like\Repository;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Collection\ValueObject\Theme;
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
            owner: $owner,
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
        $like = Like::create($owner, $item);
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
        $this->assertSame($owner->getId()->toString(), $found->getOwner()->getId()->toString());
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
        $this->assertSame($second->getId()->toString(), $page[0]->getOwner()->getId()->toString());
        $this->assertSame($third->getId()->toString(), $page[1]->getOwner()->getId()->toString());
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
}
