<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\User\Repository;

use App\Domain\User\Entity\User;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Infrastructure\User\Repository\DoctrineUserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineUserRepositoryTest extends KernelTestCase
{
    private UserRepositoryInterface $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(DoctrineUserRepository::class);
    }

    protected function tearDown(): void
    {
        // Clear entity manager to avoid stale references between tests
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->clear();

        parent::tearDown();
    }

    public function testSaveAndFindById(): void
    {
        $user = $this->createUser();
        $this->repository->save($user);

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

        $this->assertTrue($this->repository->existsByEmail(Email::fromString('exists@example.com')));
        $this->assertFalse($this->repository->existsByEmail(Email::fromString('notexists@example.com')));
    }

    public function testFindAll(): void
    {
        $this->repository->save($this->createUser('user1@example.com'));
        \sleep(1); // Ensure different microsecond timestamps
        $this->repository->save($this->createUser('user2@example.com'));
        \sleep(1);
        $this->repository->save($this->createUser('user3@example.com'));

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

        $this->assertNotNull($this->repository->findById($user->getId()));

        $this->repository->remove($user);

        $this->assertNull($this->repository->findById($user->getId()));
    }

    private function createUser(string $email = 'test@example.com'): User
    {
        return User::register(
            'Test User',
            Email::fromString($email),
            PasswordHash::createFromPlain('password123'),
            Role::user()
        );
    }
}