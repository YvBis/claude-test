<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Common\Transaction;

use App\Application\Exception\ConflictException;
use App\Infrastructure\Common\Transaction\DoctrineUnitOfWork;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DoctrineUnitOfWorkTest extends TestCase
{
    public function testTransactionalDelegatesToEntityManagerAndPassesThroughResult(): void
    {
        $callback = static fn (): string => 'result';
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('wrapInTransaction')
            ->with($this->identicalTo($callback))
            ->willReturn('result');

        $uow = new DoctrineUnitOfWork($em);

        $this->assertSame('result', $uow->transactional($callback));
    }

    public function testTransactionalExecutesCallback(): void
    {
        $executed = false;
        $callback = static function () use (&$executed): int {
            $executed = true;

            return 42;
        };
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $cb) => $cb());

        $uow = new DoctrineUnitOfWork($em);

        $this->assertSame(42, $uow->transactional($callback));
        $this->assertTrue($executed);
    }

    public function testTransactionalPropagatesException(): void
    {
        $callback = static function (): void {
            throw new \RuntimeException('boom');
        };
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $cb) => $cb());

        $uow = new DoctrineUnitOfWork($em);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $uow->transactional($callback);
    }

    public function testFlushTranslatesUniqueConstraintViolationIntoConflict(): void
    {
        $violation = $this->uniqueViolation();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException($violation);

        $uow = new DoctrineUnitOfWork($em);

        try {
            $uow->flush();
            $this->fail('Expected ConflictException');
        } catch (ConflictException $conflictException) {
            $this->assertSame($violation, $conflictException->getPrevious());
            $this->assertSame('Duplicate entry', $conflictException->getMessage());
        }
    }

    public function testFlushPassesOtherDatabaseErrorsThrough(): void
    {
        $foreignKeyViolation = $this->foreignKeyViolation();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException($foreignKeyViolation);

        $uow = new DoctrineUnitOfWork($em);

        try {
            $uow->flush();
            $this->fail('Expected the DBAL exception to pass through');
        } catch (ForeignKeyConstraintViolationException $caught) {
            $this->assertSame($foreignKeyViolation, $caught);
        }
    }

    public function testTransactionalPassesOtherDatabaseErrorsThrough(): void
    {
        $foreignKeyViolation = $this->foreignKeyViolation();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException($foreignKeyViolation);

        $uow = new DoctrineUnitOfWork($em);

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $uow->transactional(static fn (): string => 'unreachable');
    }

    public function testTransactionalTranslatesUniqueConstraintViolationIntoConflict(): void
    {
        $violation = $this->uniqueViolation();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException($violation);

        $uow = new DoctrineUnitOfWork($em);

        try {
            $uow->transactional(static fn (): string => 'unreachable');
            $this->fail('Expected ConflictException');
        } catch (ConflictException $conflictException) {
            $this->assertSame($violation, $conflictException->getPrevious());
        }
    }

    public function testTransactionalTranslatesLockWaitTimeoutIntoConflict(): void
    {
        $timeout = new LockWaitTimeoutException($this->driverException('Lock wait timeout exceeded'), null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException($timeout);

        $uow = new DoctrineUnitOfWork($em);

        try {
            $uow->transactional(static fn (): string => 'unreachable');
            $this->fail('Expected ConflictException');
        } catch (ConflictException $conflictException) {
            $this->assertSame('Concurrent modification, retry', $conflictException->getMessage());
            $this->assertSame($timeout, $conflictException->getPrevious());
        }
    }

    public function testFlushTranslatesLockWaitTimeoutIntoConflict(): void
    {
        $timeout = new LockWaitTimeoutException($this->driverException('Lock wait timeout exceeded'), null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException($timeout);

        $uow = new DoctrineUnitOfWork($em);

        $this->expectException(ConflictException::class);

        $uow->flush();
    }

    public function testTransactionalTranslatesDeadlockIntoConflict(): void
    {
        $deadlock = new DeadlockException($this->driverException('Deadlock found'), null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException($deadlock);

        $uow = new DoctrineUnitOfWork($em);

        try {
            $uow->transactional(static fn (): string => 'unreachable');
            $this->fail('Expected ConflictException');
        } catch (ConflictException $conflictException) {
            $this->assertSame('Concurrent modification, retry', $conflictException->getMessage());
            $this->assertSame($deadlock, $conflictException->getPrevious());
        }
    }

    private function foreignKeyViolation(): ForeignKeyConstraintViolationException
    {
        return new ForeignKeyConstraintViolationException($this->driverException('Foreign key violation'), null);
    }

    private function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException($this->driverException('Duplicate entry'), null);
    }

    private function driverException(string $message): DriverException
    {
        return new class ($message) extends \Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return '23000';
            }
        };
    }
}
