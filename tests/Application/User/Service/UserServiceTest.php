<?php

declare(strict_types=1);

namespace App\Tests\Application\User\Service;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Search\SearchIndexerInterface;
use App\Application\User\Service\UserService;
use App\Domain\Collection\Repository\CollectionRepositoryInterface;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Item\Repository\ItemRepositoryInterface;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\User\Entity\User;
use App\Domain\User\Exception\LastAdminException;
use App\Domain\User\Exception\SelfActionForbiddenException;
use App\Domain\User\Exception\UserNotFoundException;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
    public function testListUsersDelegatesWithPagination(): void
    {
        $users = [
            User::register('Ada', Email::fromString('ada@example.com'), PasswordHash::createFromPlain('secret123'), Role::admin()),
        ];

        $service = new UserService(
            $this->userRepository($users),
            $this->unitOfWork(),
            $this->items(),
            $this->collections(),
            $this->indexer(),
        );

        self::assertSame($users, $service->listUsers(10, 5));
    }

    public function testBlockDeactivates(): void
    {
        $admin = $this->admin('admin@example.com');
        $target = $this->user('target@example.com');

        $uow = $this->unitOfWork();

        $indexer = $this->createMock(SearchIndexerInterface::class);
        foreach (['removeItem', 'removeCollection', 'indexItem', 'indexCollection'] as $method) {
            $indexer->expects($this->never())->method($method);
        }

        $service = new UserService($this->userRepository([$admin, $target], 2), $uow, $this->items(), $this->collections(), $indexer);
        $service->update($target, $admin, false, null);

        self::assertFalse($target->isActive());
    }

    public function testBlockIsIdempotent(): void
    {
        $admin = $this->admin('admin@example.com');
        $target = $this->user('target@example.com');
        $target->deactivate();

        $service = new UserService(
            $this->userRepository([$admin, $target], 2),
            $this->unitOfWork(),
            $this->items(),
            $this->collections(),
            $this->indexer(),
        );

        // No exception: PATCH state-setting, not a transition.
        $service->update($target, $admin, false, null);

        self::assertFalse($target->isActive());
    }

    public function testBlockSelfIsForbidden(): void
    {
        $admin = $this->admin('admin@example.com');

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByIdForUpdate')->willReturn($admin);
        $users->expects($this->never())->method('save');
        $service = new UserService($users, $this->unitOfWork(), $this->items(), $this->collections(), $this->indexer());

        $this->expectException(SelfActionForbiddenException::class);

        $service->update($admin, $admin, false, null);
    }

    public function testBlockLastAdminIsRefused(): void
    {
        $admin = $this->admin('admin@example.com');
        $target = $this->admin('target@example.com');

        $service = new UserService(
            $this->userRepository([$admin, $target], 1),
            $this->unitOfWork(),
            $this->items(),
            $this->collections(),
            $this->indexer(),
        );

        $this->expectException(LastAdminException::class);

        $service->update($target, $admin, false, null);
    }

    public function testUnblockActivates(): void
    {
        $admin = $this->admin('admin@example.com');
        $target = $this->user('target@example.com');
        $target->deactivate();

        $uow = $this->unitOfWork();
        $service = new UserService($this->userRepository([$admin, $target], 1), $uow, $this->items(), $this->collections(), $this->indexer());

        $service->update($target, $admin, true, null);

        self::assertTrue($target->isActive());
    }

    public function testDeleteCollectsIdsBeforeRemoveAndCleansTheIndex(): void
    {
        $admin = $this->admin('admin@example.com');
        $target = $this->user('target@example.com');

        $itemIds = [ItemId::generate(), ItemId::generate()];
        $collectionIds = [CollectionId::generate()];

        $calls = [];
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('countByRoleExclusively')->willReturn(1);
        $users->method('findByIdForUpdate')->willReturn($target);
        $users->expects($this->once())->method('remove')->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'remove';
            },
        );

        $items = $this->createStub(ItemRepositoryInterface::class);
        $items->method('findIdsByOwnerId')->willReturn($itemIds);

        $collections = $this->createStub(CollectionRepositoryInterface::class);
        $collections->method('findIdsByOwnerId')->willReturn($collectionIds);

        $uow = $this->unitOfWork();

        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->expects($this->exactly(2))->method('removeItem')->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'index';
            },
        );
        $indexer->expects($this->once())->method('removeCollection')->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'index';
            },
        );

        $service = new UserService($users, $uow, $items, $collections, $indexer);
        $service->deleteUser($target, $admin);

        self::assertSame(['remove', 'index', 'index', 'index'], $calls);
    }

    public function testDeleteSelfIsForbidden(): void
    {
        $admin = $this->admin('admin@example.com');

        $service = new UserService(
            $this->userRepository([$admin], 1),
            $this->unitOfWork(),
            $this->items(),
            $this->collections(),
            $this->indexer(),
        );

        $this->expectException(SelfActionForbiddenException::class);

        $service->deleteUser($admin, $admin);
    }

    public function testDeleteLastAdminIsRefused(): void
    {
        $admin = $this->admin('admin@example.com');
        $target = $this->admin('target@example.com');

        $service = new UserService(
            $this->userRepository([$admin, $target], 1),
            $this->unitOfWork(),
            $this->items(),
            $this->collections(),
            $this->indexer(),
        );

        $this->expectException(LastAdminException::class);

        $service->deleteUser($target, $admin);
    }

    private function admin(string $email): User
    {
        return User::register('Admin', Email::fromString($email), PasswordHash::createFromPlain('secret123'), Role::admin());
    }

    public function testUpdatePromotes(): void
    {
        $admin = $this->admin('admin@example.com');
        $target = $this->user('target@example.com');

        $uow = $this->unitOfWork();
        $service = $this->service($this->userRepository([$admin, $target], 2), $uow);

        $updated = $service->update($target, $admin, null, Role::admin());

        self::assertTrue($updated->getRole()->isAdmin());
    }

    public function testUpdateDemotes(): void
    {
        $admin = $this->admin('admin@example.com');
        $target = $this->admin('target@example.com');

        $uow = $this->unitOfWork();
        $service = $this->service($this->userRepository([$admin, $target], 2), $uow);

        $updated = $service->update($target, $admin, null, Role::user());

        self::assertTrue($updated->getRole()->isUser());
    }

    public function testUpdateNoopsOnSameRoleWithoutSave(): void
    {
        $admin = $this->admin('admin@example.com');
        $target = $this->admin('target@example.com');

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findByIdForUpdate')->willReturn($target);
        $users->expects($this->never())->method('save');

        $service = $this->service($users);

        $updated = $service->update($target, $admin, null, Role::admin());

        self::assertTrue($updated->getRole()->isAdmin());
    }

    public function testUpdateAppliesBothFields(): void
    {
        $admin = $this->admin('admin@example.com');
        $target = $this->user('target@example.com');

        $uow = $this->unitOfWork();
        $service = $this->service($this->userRepository([$admin, $target], 1), $uow);

        $updated = $service->update($target, $admin, false, Role::admin());

        self::assertFalse($updated->isActive());
        self::assertTrue($updated->getRole()->isAdmin());
    }

    public function testUpdateSelfRoleChangeIsForbidden(): void
    {
        $admin = $this->admin('admin@example.com');

        $service = $this->service($this->userRepository([$admin], 1));

        $this->expectException(SelfActionForbiddenException::class);

        $service->update($admin, $admin, null, Role::user());
    }

    public function testUpdateDemoteLastAdminIsRefused(): void
    {
        $admin = $this->admin('admin@example.com');
        $target = $this->admin('target@example.com');

        $service = $this->service($this->userRepository([$admin, $target], 1));

        $this->expectException(LastAdminException::class);

        $service->update($target, $admin, null, Role::user());
    }

    public function testUpdateBlockSelfKeepsBlockMessage(): void
    {
        $admin = $this->admin('admin@example.com');

        $service = $this->service($this->userRepository([$admin], 1));

        try {
            $service->update($admin, $admin, false, null);
            self::fail('self block must be refused');
        } catch (SelfActionForbiddenException $e) {
            self::assertSame('An admin cannot block their own account.', $e->getMessage());
        }
    }

    public function testUpdateFailsWithNotFoundWhenTheTargetVanishesBeforeTheLock(): void
    {
        $admin = $this->admin('admin@example.com');
        $target = $this->user('target@example.com');

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByIdForUpdate')->willReturn(null);

        $service = $this->service($users);

        $this->expectException(UserNotFoundException::class);

        $service->update($target, $admin, false, null);
    }

    private function service(
        UserRepositoryInterface $users,
        ?UnitOfWorkInterface $uow = null,
    ): UserService {
        return new UserService(
            $users,
            $uow ?? $this->unitOfWork(),
            $this->items(),
            $this->collections(),
            $this->indexer(),
        );
    }

    private function user(string $email): User
    {
        return User::register('User', Email::fromString($email), PasswordHash::createFromPlain('secret123'));
    }

    /** @param list<User> $users */
    private function userRepository(array $users, int $adminCount = 0): UserRepositoryInterface
    {
        $repository = $this->createStub(UserRepositoryInterface::class);
        $repository->method('findAll')->willReturn($users);
        $repository->method('countByRoleExclusively')->willReturn($adminCount);
        $repository->method('findByIdForUpdate')->willReturnCallback(static function (UserId $id) use ($users): ?User {
            foreach ($users as $user) {
                if ($user->getId()->toString() === $id->toString()) {
                    return $user;
                }
            }

            return null;
        });

        return $repository;
    }

    private function unitOfWork(): UnitOfWorkInterface
    {
        $uow = $this->createStub(UnitOfWorkInterface::class);
        $uow->method('transactional')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $uow;
    }

    private function items(): ItemRepositoryInterface
    {
        return $this->createStub(ItemRepositoryInterface::class);
    }

    private function collections(): CollectionRepositoryInterface
    {
        return $this->createStub(CollectionRepositoryInterface::class);
    }

    private function indexer(): SearchIndexerInterface
    {
        return $this->createStub(SearchIndexerInterface::class);
    }
}
