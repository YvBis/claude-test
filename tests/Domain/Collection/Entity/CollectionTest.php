<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection\Entity;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use PHPUnit\Framework\TestCase;

final class CollectionTest extends TestCase
{
    public function testCreateWithDefaults(): void
    {
        $owner = $this->createUser();
        $collection = Collection::create(
            owner: $owner,
            name: CollectionName::fromString('My Books'),
            theme: Theme::books(),
        );

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame($owner, $collection->getOwner());
        $this->assertSame('My Books', $collection->getName()->value());
        $this->assertTrue($collection->getTheme()->isBooks());
        $this->assertNull($collection->getImage());
        $this->assertNull($collection->getDescription());
        $this->assertNotNull($collection->getCreatedAt());
        $this->assertNotNull($collection->getUpdatedAt());
        $this->assertEquals(
            $collection->getCreatedAt()->getTimestamp(),
            $collection->getUpdatedAt()->getTimestamp(),
        );
    }

    public function testCreateWithDescriptionAndImage(): void
    {
        $owner = $this->createUser();
        $collection = Collection::create(
            owner: $owner,
            name: CollectionName::fromString('My Books'),
            theme: Theme::books(),
            description: 'A reading list.',
            image: 'https://example.com/books.png',
        );

        $this->assertSame('A reading list.', $collection->getDescription());
        $this->assertSame('https://example.com/books.png', $collection->getImage());
    }

    public function testChangeNameUpdatesNameAndTimestamp(): void
    {
        $collection = $this->createCollection();
        $originalUpdatedAt = $collection->getUpdatedAt();

        \usleep(1000);
        $collection->changeName(CollectionName::fromString('Renamed'));

        $this->assertSame('Renamed', $collection->getName()->value());
        $this->assertGreaterThan($originalUpdatedAt, $collection->getUpdatedAt());
    }

    public function testChangeThemeUpdatesThemeAndTimestamp(): void
    {
        $collection = $this->createCollection();
        $originalUpdatedAt = $collection->getUpdatedAt();

        \usleep(1000);
        $collection->changeTheme(Theme::games());

        $this->assertTrue($collection->getTheme()->isGames());
        $this->assertGreaterThan($originalUpdatedAt, $collection->getUpdatedAt());
    }

    public function testChangeDescriptionUpdatesText(): void
    {
        $collection = $this->createCollection();

        $collection->changeDescription('new description');

        $this->assertSame('new description', $collection->getDescription());
    }

    public function testChangeDescriptionNullClearsField(): void
    {
        $collection = $this->createCollection(description: 'old');

        $collection->changeDescription(null);

        $this->assertNull($collection->getDescription());
    }

    public function testChangeDescriptionEmptyStringClearsField(): void
    {
        $collection = $this->createCollection(description: 'old');

        $collection->changeDescription('   ');

        $this->assertNull($collection->getDescription());
    }

    public function testChangeImageUpdatesImage(): void
    {
        $collection = $this->createCollection();

        $collection->changeImage('https://example.com/new.png');

        $this->assertSame('https://example.com/new.png', $collection->getImage());
    }

    public function testChangeImageNullClearsField(): void
    {
        $collection = $this->createCollection(image: 'https://example.com/old.png');

        $collection->changeImage(null);

        $this->assertNull($collection->getImage());
    }

    public function testReassignOwnerSwitchesOwnership(): void
    {
        $collection = $this->createCollection();
        $newOwner = $this->createUser('new-owner@example.com');

        $collection->reassignOwner($newOwner);

        $this->assertSame($newOwner, $collection->getOwner());
    }

    public function testGetIdReturnsCollectionId(): void
    {
        $collection = $this->createCollection();

        $id = $collection->getId();

        $this->assertSame(16, \strlen($id->toBytes()));
    }

    private function createUser(string $emailAddress = 'owner@example.com'): User
    {
        return User::register(
            name: 'Owner',
            email: Email::fromString($emailAddress),
            passwordHash: PasswordHash::createFromPlain('password123'),
        );
    }

    private function createCollection(?string $description = null, ?string $image = null): Collection
    {
        return Collection::create(
            owner: $this->createUser(),
            name: CollectionName::fromString('My Books'),
            theme: Theme::books(),
            description: $description,
            image: $image,
        );
    }
}
