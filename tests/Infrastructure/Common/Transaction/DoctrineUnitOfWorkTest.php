<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Common\Transaction;

use App\Infrastructure\Common\Transaction\DoctrineUnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

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
}