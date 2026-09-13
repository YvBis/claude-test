<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Item\Repository;

use App\Application\Item\DTO\ItemSlotDTO;
use App\Application\Item\Service\ItemSlotMapper;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\Repository\ItemRepositoryInterface;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\UserId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineItemRepositoryTest extends KernelTestCase
{
    private ItemRepositoryInterface $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = self::getContainer()->get(ItemRepositoryInterface::class);
        $this->em = self::getContainer()->get('doctrine')->getManager();
    }

    private function createUser(string $label): User
    {
        $user = User::register(
            name: $label,
            email: Email::fromString(\sprintf('item_repo_%s_%s@example.com', $label, \uniqid())),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );
        $this->em->persist($user);

        return $user;
    }

    private function createCollection(User $user, string $name): Collection
    {
        $collection = Collection::create(
            owner: $user,
            name: CollectionName::fromString($name),
            theme: Theme::books(),
        );
        $this->em->persist($collection);

        return $collection;
    }

    public function testSaveAndFindById(): void
    {
        $collection = $this->createCollection($this->createUser('roundtrip'), 'Roundtrip');
        $item = Item::create($collection, '1984');
        $this->repo->save($item);
        $this->em->flush();
        $itemId = $item->getId();

        $found = $this->repo->findById($itemId);

        $this->assertNotNull($found);
        $this->assertTrue($found->getId()->equals($itemId));
        $this->assertSame('1984', $found->getName());
    }

    public function testFindByIdHydratesCollectionAssociation(): void
    {
        $collection = $this->createCollection($this->createUser('ghost'), 'Ghost');
        $item = Item::create($collection, 'Ghost Item');
        $this->em->persist($item);
        $this->em->flush();
        $itemId = $item->getId();
        $collectionId = $collection->getId();

        $this->em->clear();

        // Regression: Item.collection is a LAZY ManyToOne on the final Collection.
        // Without JOIN FETCH, reading getCollection() throws
        // "Cannot generate lazy ghost: class "Collection" is final".
        // See: https://www.doctrine-project.org/projects/doctrine-orm/en/3.0/reference/architecture.html#final-classes
        $found = $this->repo->findById($itemId);

        $this->assertNotNull($found);
        $this->assertTrue($found->getCollection()->getId()->equals($collectionId));
    }

    public function testFindByCollectionIdReturnsAllItems(): void
    {
        $collection = $this->createCollection($this->createUser('bycoll'), 'By Coll');
        $this->em->persist(Item::create($collection, 'One'));
        $this->em->persist(Item::create($collection, 'Two'));
        $this->em->flush();

        $items = $this->repo->findByCollectionId($collection->getId());

        $this->assertCount(2, $items);
        $this->assertTrue($items[0]->getCollection()->getId()->equals($collection->getId()));
    }

    public function testFindByCollectionIdRespectsPagination(): void
    {
        $collection = $this->createCollection($this->createUser('page'), 'Page');
        $this->em->persist(Item::create($collection, 'One'));
        $this->em->persist(Item::create($collection, 'Two'));
        $this->em->flush();

        $this->assertCount(1, $this->repo->findByCollectionId($collection->getId(), 1, 0));
        $this->assertCount(0, $this->repo->findByCollectionId($collection->getId(), 1, 2));
    }

    public function testFindByOwnerIdReturnsOnlyThatOwnersItems(): void
    {
        $ownerA = $this->createUser('ownerA');
        $collectionA = $this->createCollection($ownerA, 'A Collection');
        $itemA = Item::create($collectionA, 'A Item');
        $this->em->persist($itemA);

        $ownerB = $this->createUser('ownerB');
        $collectionB = $this->createCollection($ownerB, 'B Collection');
        $itemB = Item::create($collectionB, 'B Item');
        $this->em->persist($itemB);

        $this->em->flush();

        $items = $this->repo->findByOwnerId($ownerA->getId());

        $this->assertCount(1, $items);
        $this->assertTrue($items[0]->getId()->equals($itemA->getId()));
        $this->assertTrue($items[0]->getCollection()->getId()->equals($collectionA->getId()));

        $itemsB = $this->repo->findByOwnerId($ownerB->getId());

        $this->assertCount(1, $itemsB);
        $this->assertTrue($itemsB[0]->getId()->equals($itemB->getId()));
        $this->assertTrue($itemsB[0]->getCollection()->getId()->equals($collectionB->getId()));
    }

    public function testFindByOwnerIdAcceptsUserIdValueObject(): void
    {
        $owner = $this->createUser('vo');
        $collection = $this->createCollection($owner, 'VO Coll');
        $this->em->persist(Item::create($collection, 'VO Item'));
        $this->em->flush();

        $this->assertCount(1, $this->repo->findByOwnerId(UserId::fromString($owner->getId()->toString())));
    }

    public function testRemoveDeletesItem(): void
    {
        $collection = $this->createCollection($this->createUser('rm'), 'Remove');
        $item = Item::create($collection, 'To Remove');
        $this->em->persist($item);
        $this->em->flush();
        $itemId = $item->getId();

        $this->repo->remove($item);
        $this->em->flush();

        $this->assertNull($this->repo->findById($itemId));
    }

    public function testDateSlotRoundTripPreservesUtc(): void
    {
        $collection = $this->createCollection($this->createUser('tz'), 'TZ Coll');
        $item = Item::create($collection, 'TZ Item');
        (new ItemSlotMapper())->applySlots($item, [new ItemSlotDTO('date', 1, '2026-09-12T10:00:00+02:00')]);
        $this->em->persist($item);
        $this->em->flush();
        $itemId = $item->getId();

        $this->em->clear();

        $found = $this->repo->findById($itemId);
        $value = $found->getSlotValue(FieldType::date(), 1);
        $this->assertInstanceOf(\DateTimeImmutable::class, $value);
        $this->assertSame('2026-09-12T08:00:00.000000+00:00', $value->format('Y-m-d\TH:i:s.uP'));
    }
}
