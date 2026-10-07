<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Item\Repository;

use App\Application\Item\DTO\ItemSlotDTO;
use App\Application\Item\Service\ItemSlotMapper;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\Repository\ItemRepositoryInterface;
use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\ValueObject\TagName;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

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
            ownerId: OwnerId::fromBytes($user->getId()->toBytes()),
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

    public function testFindByIdHydratesTagsCollection(): void
    {
        $collection = $this->createCollection($this->createUser('singletags'), 'Single Tags');
        $item = Item::create($collection, 'Tagged One');
        $item->addTag($this->createTag('solo'));
        $this->em->persist($item);
        $this->em->flush();
        $itemId = $item->getId();

        $this->em->clear();

        // fwd-6: getTags() must not lazy-load per item. Item.tags is a LAZY
        // ManyToMany, so an uninitialized collection turns every DTO mapping
        // into its own SELECT.
        $found = $this->repo->findById($itemId);

        $this->assertNotNull($found);
        $this->assertTrue(
            $this->tagsAreInitialized($found),
            'findById must leave the tags collection initialized',
        );
    }

    public function testFindByCollectionIdHydratesTagsCollections(): void
    {
        $collection = $this->createCollection($this->createUser('batchtags'), 'Batch Tags');
        $shared = $this->createTag('shared');
        foreach (['One', 'Two', 'Three'] as $name) {
            $item = Item::create($collection, $name);
            $item->addTag($shared);
            $item->addTag($this->createTag(\strtolower($name)));
            $this->em->persist($item);
        }
        $untagged = Item::create($collection, 'Untagged');
        $this->em->persist($untagged);
        $this->em->flush();
        $collectionId = $collection->getId();

        // Without clear() the query returns the very instances the fixtures
        // created, whose tags were populated by addTag() — the lazy path would
        // never be exercised and this test could not tell a fix from the bug.
        $this->em->clear();

        $items = $this->repo->findByCollectionId($collectionId);

        $this->assertCount(4, $items);
        foreach ($items as $item) {
            $this->assertTrue(
                $this->tagsAreInitialized($item),
                'findByCollectionId must leave every tags collection initialized',
            );
        }

        // A tag-less item must come back initialized-EMPTY, not uninitialized:
        // an INNER JOIN would leave it out of the batch query and the next
        // getTags() would go to the database for it. Asserting the tag list is
        // empty would prove nothing — getTags() returns [] either way, it just
        // lazy-loads when the collection is uninitialized. What distinguishes
        // the two is isInitialized(), so that is what is asserted, and the
        // emptiness is read through the already-initialized collection.
        $names = \array_map(static fn (Item $item): string => $item->getName(), $items);
        $untaggedItem = $items[\array_search('Untagged', $names, true)];
        $this->assertTrue(
            $this->tagsAreInitialized($untaggedItem),
            'an item without tags must be initialized by the batch query too',
        );
        $this->assertCount(0, $this->reflectTags($untaggedItem));

        // The tags themselves must have arrived, not just an initialized
        // collection. Without this, a batch that hydrates every item as
        // initialized-EMPTY would satisfy every assertion above.
        $tagged = $items[\array_search('One', $names, true)];
        $tagNames = \array_map(
            static fn (Tag $tag): string => $tag->getName()->value(),
            $this->reflectTags($tagged)->toArray(),
        );
        \sort($tagNames);
        $this->assertSame(['one', 'shared'], $tagNames);
    }

    /**
     * Proves the LEFT JOIN is what makes the tag-less item above initialized.
     * Mutation-only check: swap the batch's LEFT JOIN for an INNER JOIN, run
     * this test, and the assertion above turns red. Not committed in that
     * state — it exists so the reason for LEFT JOIN is verifiable, not argued.
     */
    public function testTaglessItemIsInitializedNotLazyLoaded(): void
    {
        $collection = $this->createCollection($this->createUser('empty'), 'Empty');
        $this->em->persist(Item::create($collection, 'No Tags'));
        $this->em->flush();
        $collectionId = $collection->getId();

        $this->em->clear();

        $items = $this->repo->findByCollectionId($collectionId);

        $this->assertCount(1, $items);
        $this->assertTrue(
            $this->tagsAreInitialized($items[0]),
            'LEFT JOIN must initialize a tag-less item; under INNER JOIN it would lazy-load',
        );
    }

    /**
     * Reads the tags collection without going through getTags(), so touching the
     * entity in an assertion cannot hide the lazy load under test.
     */
    private function tagsAreInitialized(Item $item): bool
    {
        return $this->reflectTags($item)->isInitialized();
    }

    private function reflectTags(Item $item): PersistentCollection
    {
        $property = (new \ReflectionClass(Item::class))->getProperty('tags');
        $tags = $property->getValue($item);
        \assert($tags instanceof PersistentCollection);

        return $tags;
    }

    public function testFindByOwnerIdHydratesTagsCollections(): void
    {
        $user = $this->createUser('ownertags');
        $collection = $this->createCollection($user, 'Owner Tags');
        $item = Item::create($collection, 'Owned');
        $item->addTag($this->createTag('owned'));
        $this->em->persist($item);
        $this->em->flush();
        $ownerId = OwnerId::fromBytes($user->getId()->toBytes());

        $this->em->clear();

        $items = $this->repo->findByOwnerId($ownerId);

        $this->assertCount(1, $items);
        $this->assertTrue(
            $this->tagsAreInitialized($items[0]),
            'findByOwnerId must leave the tags collection initialized',
        );
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

        $items = $this->repo->findByOwnerId(OwnerId::fromBytes($ownerA->getId()->toBytes()));

        $this->assertCount(1, $items);
        $this->assertTrue($items[0]->getId()->equals($itemA->getId()));
        $this->assertTrue($items[0]->getCollection()->getId()->equals($collectionA->getId()));

        $itemsB = $this->repo->findByOwnerId(OwnerId::fromBytes($ownerB->getId()->toBytes()));

        $this->assertCount(1, $itemsB);
        $this->assertTrue($itemsB[0]->getId()->equals($itemB->getId()));
        $this->assertTrue($itemsB[0]->getCollection()->getId()->equals($collectionB->getId()));
    }

    public function testFindByOwnerIdAcceptsOwnerIdValueObject(): void
    {
        $owner = $this->createUser('vo');
        $collection = $this->createCollection($owner, 'VO Coll');
        $this->em->persist(Item::create($collection, 'VO Item'));
        $this->em->flush();

        $this->assertCount(1, $this->repo->findByOwnerId(OwnerId::fromString($owner->getId()->toString())));
    }

    private function createTag(string $name): Tag
    {
        $tag = Tag::create(TagName::fromString($name));
        $this->em->persist($tag);

        return $tag;
    }

    public function testFindByCollectionIdFiltersByNameCaseInsensitive(): void
    {
        $collection = $this->createCollection($this->createUser('namef'), 'Name Filter');
        $this->em->persist(Item::create($collection, 'Brave New World'));
        $this->em->persist(Item::create($collection, 'The Lord of the Rings'));
        $this->em->flush();

        $items = $this->repo->findByCollectionId($collection->getId(), 50, 0, 'brave');

        $this->assertCount(1, $items);
        $this->assertSame('Brave New World', $items[0]->getName());
    }

    public function testFindByCollectionIdFiltersByTagsWithAndSemantics(): void
    {
        $collection = $this->createCollection($this->createUser('tagf'), 'Tag Filter');
        $scifi = $this->createTag('Sci-fi');
        $drama = $this->createTag('Drama');

        $both = Item::create($collection, 'Both');
        $both->addTag($scifi);
        $both->addTag($drama);
        $this->em->persist($both);

        $scifiOnly = Item::create($collection, 'Sci-fi Only');
        $scifiOnly->addTag($scifi);
        $this->em->persist($scifiOnly);

        $this->em->flush();

        $items = $this->repo->findByCollectionId($collection->getId(), 50, 0, null, ['sci-fi', 'drama']);

        $this->assertCount(1, $items);
        $this->assertSame('Both', $items[0]->getName());
    }

    public function testFindByOwnerIdFiltersByNameAndTagsWithPagination(): void
    {
        $owner = $this->createUser('combo');
        $collection = $this->createCollection($owner, 'Combo');
        $scifi = $this->createTag('Sci-fi');

        $match1 = Item::create($collection, 'Match One');
        $match1->addTag($scifi);
        $this->em->persist($match1);

        $match2 = Item::create($collection, 'Match Two');
        $match2->addTag($scifi);
        $this->em->persist($match2);

        $other = Item::create($collection, 'Other Item');
        $this->em->persist($other);

        $this->em->flush();

        $items = $this->repo->findByOwnerId(OwnerId::fromBytes($owner->getId()->toBytes()), 1, 0, 'match', ['sci-fi']);

        $this->assertCount(1, $items);
        $this->assertSame('Match One', $items[0]->getName());
    }

    public function testFindByCollectionIdOrdersEqualCreatedAtById(): void
    {
        // fwd-27: rows with equal sort keys must not reshuffle between pages.
        // No $clock->sleep() between constructions: all four items share one
        // frozen createdAt. PKs are uuid7 (time-ordered with a random tail),
        // so id order is not insertion order — the expected order is
        // id-ascending, computed, not assumed.
        Clock::set(new MockClock('2026-09-27 10:00:00'));
        try {
            $collection = $this->createCollection($this->createUser('tiebreak'), 'Tiebreak');
            foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $name) {
                $this->em->persist(Item::create($collection, $name));
            }
            $this->em->flush();

            $expected = $this->idsOf($this->repo->findByCollectionId($collection->getId()));
            \sort($expected);

            $pageOne = $this->idsOf($this->repo->findByCollectionId($collection->getId(), 2, 0));
            $pageTwo = $this->idsOf($this->repo->findByCollectionId($collection->getId(), 2, 2));
            $again = $this->idsOf($this->repo->findByCollectionId($collection->getId()));

            $this->assertSame(\array_slice($expected, 0, 2), $pageOne);
            $this->assertSame(\array_slice($expected, 2), $pageTwo);
            $this->assertSame($expected, $again);
        } finally {
            Clock::set(new NativeClock());
        }
    }

    /**
     * @param list<Item> $items
     *
     * @return list<string>
     */
    private function idsOf(array $items): array
    {
        return \array_map(static fn (Item $item): string => $item->getId()->toString(), $items);
    }

    public function testFindAllWalksEveryItemInStablePagesWithTagsInitialized(): void
    {
        // 6.4: the reindex walk. Frozen clock so createdAt ties and the id
        // tie-breaker alone decides the order — pages must not reshuffle, or the
        // reindex would skip rows. Filtered to this test's ids: findAll spans
        // the whole table.
        Clock::set(new MockClock('2026-10-07 10:00:00'));
        try {
            $collection = $this->createCollection($this->createUser('walk'), 'Walk');
            $tag = Tag::create(TagName::fromString('Walk_'.\uniqid()));
            $this->em->persist($tag);
            foreach (['One', 'Two', 'Three'] as $name) {
                $item = Item::create($collection, $name);
                $item->addTag($tag);
                $this->em->persist($item);
            }
            $this->em->flush();
            $this->em->clear();

            $mine = static fn (array $items): array => \array_values(\array_filter(
                $items,
                static fn (Item $item): bool => 'Walk' === $item->getCollection()->getName()->value(),
            ));

            $all = $mine($this->repo->findAll(1000));
            $expected = $this->idsOf($all);
            $sorted = $expected;
            \sort($sorted);

            $this->assertCount(3, $all);
            $this->assertSame($sorted, $expected, 'findAll must order equal createdAt by id.');

            foreach ($all as $item) {
                // Checked through reflection before getTags(): calling the
                // getter would trigger the very lazy load under test.
                $this->assertTrue($this->tagsAreInitialized($item), 'Tags must be pre-initialized, not lazy (fwd-6).');
                $this->assertCount(1, $item->getTags());
            }
        } finally {
            Clock::set(new NativeClock());
        }
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
