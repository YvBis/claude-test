<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Tag;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Item\Entity\Item;
use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\ValueObject\TagName;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TagPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = self::getContainer()->get('doctrine')->getManager();
    }

    private function createCollection(): Collection
    {
        $user = User::register(
            name: 'Tag Persistence Test',
            email: Email::fromString(\sprintf('tag_persist_%s@example.com', \uniqid())),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );

        $collection = Collection::create(
            owner: $user,
            name: CollectionName::fromString('Tag Test Collection'),
            theme: Theme::books(),
        );

        $this->em->persist($user);
        $this->em->persist($collection);

        return $collection;
    }

    public function testPersistItemTagJoinRowAndReload(): void
    {
        $collection = $this->createCollection();
        $item = Item::create($collection, '1984');
        $tag = Tag::create(TagName::fromString('Books'));
        $item->addTag($tag);

        $this->em->persist($item);
        $this->em->persist($tag);
        $this->em->flush();
        $itemId = $item->getId();

        $this->em->clear();

        // Join and hydrate the final Collection -> User association chain inline —
        // Doctrine ORM 3 cannot lazy-ghost-proxy final entities.
        $reloaded = $this->em->createQuery(
            'SELECT i, c, o FROM App\Domain\Item\Entity\Item i JOIN i.collection c JOIN c.owner o WHERE i.id = :id'
        )
            ->setParameter('id', $itemId->toBytes())
            ->getOneOrNullResult();

        $this->assertNotNull($reloaded);
        // getTags() intentionally triggers a LAZY load of the many-to-many
        // collection. Unlike to-one associations, collection hydration does NOT
        // create a ghost proxy for the final Tag entity.
        $this->assertCount(1, $reloaded->getTags());
        $this->assertTrue($reloaded->getTags()[0]->getId()->equals($tag->getId()));
    }

    public function testTagNameUniquenessIsCaseInsensitive(): void
    {
        $this->em->persist(Tag::create(TagName::fromString('Books')));
        $this->em->flush();

        $this->em->persist(Tag::create(TagName::fromString('books')));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testTagNameLookupIsCaseInsensitive(): void
    {
        $this->em->persist(Tag::create(TagName::fromString('Books')));
        $this->em->flush();

        $found = $this->em->createQuery(
            'SELECT t FROM App\Domain\Tag\Entity\Tag t WHERE t.name.value = :name'
        )
            ->setParameter('name', 'books')
            ->getOneOrNullResult();

        $this->assertNotNull($found);
        $this->assertSame('Books', $found->getName()->value());
    }

    public function testRemovingItemCascadesJoinRows(): void
    {
        $collection = $this->createCollection();
        $item = Item::create($collection, '1984');
        $tag = Tag::create(TagName::fromString('Books'));
        $item->addTag($tag);

        $this->em->persist($item);
        $this->em->persist($tag);
        $this->em->flush();

        $connection = $this->em->getConnection();
        $itemId = $item->getId()->toBytes();
        $countRows = static fn (): int => (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM item_tags WHERE item_id = ?',
            [$itemId],
        );

        $this->assertSame(1, $countRows());

        $this->em->remove($item);
        $this->em->flush();

        $this->assertSame(0, $countRows());
    }
}
