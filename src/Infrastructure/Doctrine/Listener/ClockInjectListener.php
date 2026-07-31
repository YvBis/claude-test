<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Listener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Symfony\Component\Clock\ClockInterface;

/**
 * Hydrates entities with the autowired Clock via ClockAwareTrait::setClock().
 *
 * Why this listener is injected with the `Clock::class` facade (delegating
 * through `ClockInterface`) rather than a frozen MockClock:
 *
 * - Kernel tests advance time via `Clock::set(new MockClock(...))`. If the
 *   listener were bound to a container-provided `ClockInterface` singleton,
 *   postLoaded entities would inherit a *different* MockClock instance from
 *   the test's facade clock — a latent dual-clock drift documented in
 *   `AssumptionLog.md` (2026-07-31).
 * - The `Clock` facade class implements `ClockInterface`, but its `now()`
 *   delegates to `Clock::get()` (the static singleton). A single
 *   `Clock::set(...)` from a kernel test setUp thus synchronises both
 *   freshly-constructed entities (via the trait's lazy `??=` fallback
 *   against `Clock::get()`) and postLoaded entities (via this listener).
 *
 * The listener is idempotent: only injects on entities exposing `setClock()`.
 */
#[AsEntityListener(event: 'postLoad', method: 'postLoad')]
final readonly class ClockInjectListener
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();

        if (\method_exists($entity, 'setClock')) {
            $entity->setClock($this->clock);
        }
    }
}
