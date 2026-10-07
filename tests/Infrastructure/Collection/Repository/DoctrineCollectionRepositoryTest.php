<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Collection\Repository;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\Repository\CollectionRepositoryInterface;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class DoctrineCollectionRepositoryTest extends KernelTestCase
{
    private CollectionRepositoryInterface $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = self::getContainer()->get(CollectionRepositoryInterface::class);
        $this->em = self::getContainer()->get('doctrine')->getManager();
    }

    private function createUser(string $label): User
    {
        $user = User::register(
            name: $label,
            email: Email::fromString(\sprintf('coll_repo_%s_%s@example.com', $label, \uniqid())),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );
        $this->em->persist($user);

        return $user;
    }

    public function testFindByOwnerIdOrdersEqualCreatedAtByIdDescending(): void
    {
        // fwd-27: the primary sort is createdAt DESC, so the tie-breaker must
        // follow the same direction (id DESC) for the ASC composite index to
        // serve the order with a backward scan. PKs are uuid7 (time-ordered
        // with a random tail) — expected order is computed, not assumed.
        Clock::set(new MockClock('2026-09-27 10:00:00'));
        try {
            $owner = $this->createUser('tiebreak');
            foreach (['First', 'Second', 'Third', 'Fourth'] as $name) {
                $collection = Collection::create(
                    ownerId: OwnerId::fromBytes($owner->getId()->toBytes()),
                    name: CollectionName::fromString($name.' '.\uniqid()),
                    theme: Theme::books(),
                );
                $this->repo->save($collection);
            }
            $this->em->flush();

            $ownerId = OwnerId::fromBytes($owner->getId()->toBytes());
            $expected = $this->idsOf($this->repo->findByOwnerId($ownerId));
            \rsort($expected);

            $pageOne = $this->idsOf($this->repo->findByOwnerId($ownerId, 2, 0));
            $pageTwo = $this->idsOf($this->repo->findByOwnerId($ownerId, 2, 2));
            $again = $this->idsOf($this->repo->findByOwnerId($ownerId));

            $this->assertSame(\array_slice($expected, 0, 2), $pageOne);
            $this->assertSame(\array_slice($expected, 2), $pageTwo);
            $this->assertSame($expected, $again);
        } finally {
            Clock::set(new NativeClock());
        }
    }

    /**
     * @param list<Collection> $collections
     *
     * @return list<string>
     */
    private function idsOf(array $collections): array
    {
        return \array_map(static fn (Collection $c): string => $c->getId()->toString(), $collections);
    }

    public function testFindAllOrdersEqualCreatedAtByIdDescending(): void
    {
        // 6.5: CollectionReindexer pages over findAll, so its order is a
        // contract, not an implementation detail. Frozen clock so createdAt
        // ties and the id tie-breaker alone decides — pages must not reshuffle.
        Clock::set(new MockClock('2026-10-07 10:00:00'));
        try {
            $owner = $this->createUser('findall');
            $mine = [];
            foreach (['First', 'Second', 'Third'] as $name) {
                $collection = Collection::create(
                    ownerId: OwnerId::fromBytes($owner->getId()->toBytes()),
                    name: CollectionName::fromString($name.' '.\uniqid()),
                    theme: Theme::books(),
                );
                $this->repo->save($collection);
                $mine[] = $collection->getId()->toString();
            }
            $this->em->flush();

            $expected = \array_values(\array_intersect($this->idsOf($this->repo->findAll(1000)), $mine));
            \rsort($expected);

            $pageOne = \array_values(\array_intersect($this->idsOf($this->repo->findAll(2, 0)), $mine));
            $pageTwo = \array_values(\array_intersect($this->idsOf($this->repo->findAll(2, 2)), $mine));

            $this->assertSame(\array_slice($expected, 0, 2), $pageOne);
            $this->assertSame(\array_slice($expected, 2), $pageTwo);
        } finally {
            Clock::set(new NativeClock());
        }
    }
}
