<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Like;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\Entity\Like;
use App\Domain\Like\Repository\LikeRepositoryInterface;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LikePersistenceTest extends KernelTestCase
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
            name: 'Like Persistence Test',
            email: Email::fromString(\sprintf('like_persist_%s@example.com', \uniqid())),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );
        $this->em->persist($user);

        return $user;
    }

    private function createItem(User $owner): Item
    {
        $collection = Collection::create(
            owner: $owner,
            name: CollectionName::fromString('Like Test Collection'),
            theme: Theme::books(),
        );
        $this->em->persist($collection);

        $item = Item::create($collection, '1984');
        $this->em->persist($item);

        return $item;
    }

    private function countRows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM likes');
    }

    public function testPersistLikeAndReloadById(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $like = Like::create($owner, $item);

        $this->em->persist($like);
        $this->em->flush();

        $repo = self::getContainer()->get(LikeRepositoryInterface::class);
        $found = $repo->findById($like->getId());

        $this->assertNotNull($found);
        $this->assertSame($like->getId()->toString(), $found->getId()->toString());
        $this->assertSame($owner->getId()->toString(), $found->getOwner()->getId()->toString());
        $this->assertSame($item->getId()->toString(), $found->getItem()->getId()->toString());
        $this->assertSame($item->getCollection()->getId()->toString(), $found->getItem()->getCollection()->getId()->toString());
    }

    public function testDuplicateLikeViolatesUniqueConstraint(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);

        $this->em->persist(Like::create($owner, $item));
        $this->em->persist(Like::create($owner, $item));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testCascadeOnItemDelete(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $this->em->persist(Like::create($owner, $item));
        $this->em->flush();

        $this->assertSame(1, $this->countRows());

        $this->em->remove($item);
        $this->em->flush();

        $this->assertSame(0, $this->countRows());
    }
}
