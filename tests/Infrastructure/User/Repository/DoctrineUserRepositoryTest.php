<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\User\Repository;

use App\Domain\User\Entity\User;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use App\Infrastructure\User\Repository\DoctrineUserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class DoctrineUserRepositoryTest extends KernelTestCase
{
    private UserRepositoryInterface $repository;

    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(DoctrineUserRepository::class);

        $clock = new MockClock('2026-01-01 00:00:00');
        $this->clock = $clock;
        Clock::set($clock);
    }

    protected function tearDown(): void
    {
        // Clear entity manager to avoid stale references between tests
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->clear();

        Clock::set(new \Symfony\Component\Clock\NativeClock());

        parent::tearDown();
    }

    public function testSaveAndFindById(): void
    {
        $user = $this->createUser();
        $this->repository->save($user);
        $this->entityManager()->flush();

        $found = $this->repository->findById($user->getId());

        $this->assertNotNull($found);
        $this->assertEquals($user->getName(), $found->getName());
        $this->assertEquals($user->getEmail()->value(), $found->getEmail()->value());
        $this->assertTrue($found->getRole()->isUser());
        $this->assertTrue($found->isActive());
    }

    public function testFindByEmail(): void
    {
        $user = $this->createUser('findme@example.com');
        $this->repository->save($user);
        $this->entityManager()->flush();

        $found = $this->repository->findByEmail(Email::fromString('findme@example.com'));

        $this->assertNotNull($found);
        $this->assertEquals($user->getId()->toString(), $found->getId()->toString());
    }

    public function testFindByEmailReturnsNullForNonExistent(): void
    {
        $found = $this->repository->findByEmail(Email::fromString('nothere@example.com'));
        $this->assertNull($found);
    }

    public function testExistsByEmail(): void
    {
        $user = $this->createUser('exists@example.com');
        $this->repository->save($user);
        $this->entityManager()->flush();

        $this->assertTrue($this->repository->existsByEmail(Email::fromString('exists@example.com')));
        $this->assertFalse($this->repository->existsByEmail(Email::fromString('notexists@example.com')));
    }

    public function testFindAll(): void
    {
        $this->clock->modify('2026-01-01 10:00:00');
        $this->repository->save($this->createUser('user1@example.com'));
        $this->clock->modify('+1 second');
        $this->repository->save($this->createUser('user2@example.com'));
        $this->clock->modify('+1 second');
        $this->repository->save($this->createUser('user3@example.com'));
        $this->entityManager()->flush();

        $all = $this->repository->findAll();

        $this->assertCount(3, $all);
        // Repository orders by createdAt DESC (newest first)
        $emails = \array_map(fn (User $u) => $u->getEmail()->value(), $all);
        $this->assertSame(['user3@example.com', 'user2@example.com', 'user1@example.com'], $emails);
    }

    public function testRemove(): void
    {
        $user = $this->createUser('toremove@example.com');
        $this->repository->save($user);
        $this->entityManager()->flush();

        $this->assertNotNull($this->repository->findById($user->getId()));

        $this->repository->remove($user);
        $this->entityManager()->flush();

        $this->assertNull($this->repository->findById($user->getId()));
    }

    /**
     * fwd-5: `findNamesByIds` is how `LikeService`/`CommentService` print the
     * owner's display name. Its `IN (:ids)` binds raw 16-byte values through
     * `ArrayParameterType::BINARY`, and a mismatch there fails *silently* — the
     * query returns no rows and every `owner_name` comes back null rather than
     * raising. The service tests mock this method, so nothing above them would
     * notice. This test is therefore about the binding matching, not the shape.
     */
    public function testFindNamesByIdsMatchesRawUuidBytes(): void
    {
        $first = $this->createUser('batch1@example.com', 'Batch One');
        $second = $this->createUser('batch2@example.com', 'Batch Two');
        $this->repository->save($first);
        $this->repository->save($second);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $names = $this->repository->findNamesByIds([$first->getId(), $second->getId()]);

        $this->assertSame([
            $first->getId()->toString() => 'Batch One',
            $second->getId()->toString() => 'Batch Two',
        ], $names);
    }

    public function testFindNamesByIdsReturnsOnlyTheRequestedIds(): void
    {
        $wanted = $this->createUser('wanted@example.com', 'Wanted');
        $this->createUser('ignored@example.com', 'Ignored');
        $this->repository->save($wanted);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $names = $this->repository->findNamesByIds([$wanted->getId()]);

        $this->assertSame([$wanted->getId()->toString() => 'Wanted'], $names);
    }

    public function testFindNamesByIdsSkipsIdsWithNoUserRow(): void
    {
        $present = $this->createUser('present@example.com', 'Present');
        $this->repository->save($present);
        $this->entityManager()->flush();
        $this->entityManager()->clear();

        $names = $this->repository->findNamesByIds([$present->getId(), UserId::generate()]);

        // An id with no row is absent from the map, not mapped to null — the
        // service reads that as a null owner_name.
        $this->assertSame([$present->getId()->toString() => 'Present'], $names);
    }

    public function testFindNamesByIdsShortCircuitsOnAnEmptyBatch(): void
    {
        // Guards the early return: an empty `IN ()` is a syntax error in MySQL, so
        // this path has to exist for a page that came back empty.
        $this->assertSame([], $this->repository->findNamesByIds([]));
    }

    private function entityManager(): \Doctrine\ORM\EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function createUser(string $email = 'test@example.com', string $name = 'Test User'): User
    {
        return User::register(
            name: $name,
            email: Email::fromString($email),
            passwordHash: PasswordHash::createFromPlain('password123'),
            role: Role::user(),
        );
    }
}
