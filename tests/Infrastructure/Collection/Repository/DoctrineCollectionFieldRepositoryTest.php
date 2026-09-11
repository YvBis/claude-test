<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Collection\Repository;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\Entity\CollectionField;
use App\Domain\Collection\Repository\CollectionFieldRepositoryInterface;
use App\Domain\Collection\ValueObject\CollectionFieldId;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\FieldName;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineCollectionFieldRepositoryTest extends KernelTestCase
{
    private CollectionFieldRepositoryInterface $repo;
    private User $user;
    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = self::getContainer()->get(CollectionFieldRepositoryInterface::class);

        $user = User::register(
            name: 'Field Repo Test',
            email: Email::fromString('field_repo_test@example.com'),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );

        // Override id to fixed binary UUID (register() generates its own)
        $reflection = new \ReflectionClass($user);
        $prop = $reflection->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($user, \Ramsey\Uuid\Uuid::uuid7()->getBytes());
        $this->user = $user;

        $em = self::getContainer()->get('doctrine')->getManager();
        $em->persist($this->user);
        $em->flush();

        $this->collection = Collection::create(
            owner: $this->user,
            name: CollectionName::fromString('Repo Test Collection'),
            theme: \App\Domain\Collection\ValueObject\Theme::books(),
        );

        $em->persist($this->collection);
        $em->flush();
    }

    public function testSaveAndFindById(): void
    {
        $field = CollectionField::create(
            collection: $this->collection,
            name: FieldName::fromString('Title'),
            type: FieldType::text(),
            slotIndex: 1,
        );

        $this->repo->save($field);
        $this->flush();

        $found = $this->repo->findById($field->getId());
        $this->assertNotNull($found);
        $this->assertSame('Title', $found->getName()->value());
        $this->assertSame(1, $found->getSlotIndex());
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $missingId = CollectionFieldId::generate();
        $this->assertNull($this->repo->findById($missingId));
    }

    public function testFindByIdHydratesCollectionAssociation(): void
    {
        $field = CollectionField::create($this->collection, FieldName::fromString('Detail'), FieldType::text(), 1);
        $this->repo->save($field);
        $this->flush();

        self::getContainer()->get('doctrine')->getManager()->clear();

        $found = $this->repo->findById($field->getId());
        $this->assertNotNull($found);
        $this->assertTrue($found->getCollection()->getId()->equals($this->collection->getId()));
    }

    public function testFindByCollectionReturnsEmptyArrayWhenNoFields(): void
    {
        $result = $this->repo->findByCollection($this->collection);
        $this->assertSame([], $result);
    }

    public function testFindByCollectionReturnsFieldsOrderedBySlotIndexAsc(): void
    {
        $field3 = CollectionField::create($this->collection, FieldName::fromString('Z-Third'), FieldType::text(), 3);
        $field1 = CollectionField::create($this->collection, FieldName::fromString('A-First'), FieldType::text(), 1);
        $field2 = CollectionField::create($this->collection, FieldName::fromString('M-Second'), FieldType::text(), 2);

        $this->repo->save($field3);
        $this->repo->save($field1);
        $this->repo->save($field2);
        $this->flush();

        $result = $this->repo->findByCollection($this->collection);

        $this->assertCount(3, $result);
        $this->assertSame(1, $result[0]->getSlotIndex());
        $this->assertSame(2, $result[1]->getSlotIndex());
        $this->assertSame(3, $result[2]->getSlotIndex());
    }

    public function testFindByCollectionAndSlotReturnsFieldWhenExists(): void
    {
        $field = CollectionField::create($this->collection, FieldName::fromString('Author'), FieldType::text(), 3);
        $this->repo->save($field);
        $this->flush();

        $found = $this->repo->findByCollectionAndTypeAndSlot($this->collection->getId(), FieldType::text(), 3);
        $this->assertNotNull($found);
        $this->assertSame('Author', $found->getName()->value());
    }

    public function testFindByCollectionAndSlotReturnsNullForUnassignedSlot(): void
    {
        // valid-but-unassigned: distinguishes "no field" from out-of-range
        $this->repo->save(CollectionField::create($this->collection, FieldName::fromString('Title'), FieldType::text(), 1));
        $this->flush();

        $this->assertNull($this->repo->findByCollectionAndTypeAndSlot($this->collection->getId(), FieldType::text(), 2));
        $this->assertNull($this->repo->findByCollectionAndTypeAndSlot($this->collection->getId(), FieldType::text(), 99));
    }

    public function testNextSlotIndexForReturnsOneOnEmptyCollection(): void
    {
        $this->assertSame(1, $this->repo->nextSlotIndexFor($this->collection));
    }

    public function testNextSlotIndexForReturnsMaxPlusOne(): void
    {
        $this->repo->save(CollectionField::create($this->collection, FieldName::fromString('Aa'), FieldType::text(), 1));
        $this->repo->save(CollectionField::create($this->collection, FieldName::fromString('Bb'), FieldType::text(), 2));
        $this->repo->save(CollectionField::create($this->collection, FieldName::fromString('Cc'), FieldType::text(), 3));
        $this->flush();

        $this->assertSame(4, $this->repo->nextSlotIndexFor($this->collection));
    }

    public function testCountByCollectionReturnsZeroForEmptyCollection(): void
    {
        $this->assertSame(0, $this->repo->countByCollection($this->collection));
    }

    public function testCountByCollectionReturnsCorrectCount(): void
    {
        $this->repo->save(CollectionField::create($this->collection, FieldName::fromString('Aa'), FieldType::text(), 1));
        $this->repo->save(CollectionField::create($this->collection, FieldName::fromString('Bb'), FieldType::text(), 2));
        $this->repo->save(CollectionField::create($this->collection, FieldName::fromString('Cc'), FieldType::text(), 3));
        $this->flush();

        $this->assertSame(3, $this->repo->countByCollection($this->collection));
    }

    public function testRemoveDeletesField(): void
    {
        $field = CollectionField::create($this->collection, FieldName::fromString('Temp'), FieldType::text(), 1);
        $this->repo->save($field);
        $this->flush();
        $id = $field->getId();

        $this->repo->remove($field);
        $this->flush();

        $this->assertNull($this->repo->findById($id));
        $this->assertSame(0, $this->repo->countByCollection($this->collection));
    }

    private function flush(): void
    {
        self::getContainer()->get('doctrine')->getManager()->flush();
    }
}
