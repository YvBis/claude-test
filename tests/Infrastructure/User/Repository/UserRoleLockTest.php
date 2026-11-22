<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\User\Repository;

use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Role;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * 10.6: the last-admin guard must read admins under a locking read, otherwise
 * two concurrent demotions both see "two admins" and leave none.
 *
 * Proof is a second, committed connection: while the kernel connection holds
 * the locking read, that connection's own `FOR UPDATE` has to wait. The rows
 * are seeded through the committed connection because the DAMA transaction is
 * invisible to it.
 */
final class UserRoleLockTest extends KernelTestCase
{
    private Connection $seed;

    /** @var list<string> */
    private array $emails = [];

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $kernelConnection */
        $kernelConnection = self::getContainer()->get('doctrine')->getConnection();
        $this->seed = DriverManager::getConnection($kernelConnection->getParams());

        foreach (['a', 'b'] as $suffix) {
            $email = 'lock-test-'.$suffix.'-'.\bin2hex(\random_bytes(4)).'@example.com';
            $this->emails[] = $email;

            $this->seed->executeStatement(
                'INSERT INTO users (id, is_active, name, created_at, updated_at, email, password_hash, role)'
                .' VALUES (?, 1, ?, NOW(6), NOW(6), ?, ?, ?)',
                [\random_bytes(16), 'Lock '.$suffix, $email, 'hash', Role::admin()->value()],
                [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
            );
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (!isset($this->seed)) {
            return;
        }

        try {
            foreach ($this->emails as $email) {
                $this->seed->executeStatement('DELETE FROM users WHERE email = ?', [$email]);
            }
        } finally {
            $this->seed->close();
        }
    }

    public function testCountingAdminsExclusivelyBlocksAConcurrentLockingRead(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine')->getConnection();
        $repository = self::getContainer()->get(UserRepositoryInterface::class);

        $connection->beginTransaction();

        try {
            self::assertGreaterThanOrEqual(2, $repository->countByRoleExclusively(Role::admin()));

            $this->seed->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

            $this->expectException(LockWaitTimeoutException::class);

            $this->seed->executeQuery("SELECT COUNT(*) FROM users WHERE role = 'admin' FOR UPDATE");
        } finally {
            $connection->rollBack();
        }
    }
}
