<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Doctrine\Listener;

use App\Infrastructure\Doctrine\Listener\ClockInjectListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Construction contract: listener MUST be bound to the Clock facade class so
 * that kernel-test fixtures via Clock::set() reach postLoaded entities.
 * Binding to a concrete NativeClock/MockClock would silently regress to the
 * pre-fix dual-clock drift (review finding F3, 2026-07-31).
 */
final class ClockInjectListenerTest extends TestCase
{
    public function testConstructorAcceptsClockFacade(): void
    {
        $listener = new ClockInjectListener(new Clock());

        self::assertInstanceOf(ClockInjectListener::class, $listener);
    }

    public function testConstructorRejectsNativeClock(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be bound to Symfony\\Component\\Clock\\Clock');

        new ClockInjectListener(new NativeClock());
    }

    public function testConstructorRejectsMockClock(): void
    {
        $this->expectException(\LogicException::class);

        new ClockInjectListener(new MockClock('2026-01-01 00:00:00'));
    }
}
