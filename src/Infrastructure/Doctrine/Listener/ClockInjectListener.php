<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Listener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Symfony\Component\Clock\Clock;
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
 * **Binding contract**: the service container MUST resolve `ClockInterface`
 * to `Symfony\Component\Clock\Clock` (the facade class). Binding it to a
 * concrete `NativeClock`/`MockClock` instead would make the listener inject
 * that concrete clock directly into entities, bypassing `Clock::set()` and
 * silently regressing to the pre-fix dual-clock drift. The constructor
 * asserts this contract at container boot.
 *
 * The listener is idempotent: only injects on entities exposing `setClock()`.
 *
 * The trait's `$clock` field is `private readonly`. Doctrine dispatches
 * `postLoad` at most once per hydration today, but a reflection guard
 * defends against double-fire without throwing a
 * "Cannot modify readonly property" error.
 */
#[AsEntityListener(event: 'postLoad', method: 'postLoad')]
final readonly class ClockInjectListener
{
    public function __construct(private ClockInterface $clock)
    {
        // Binding contract: must be the facade class so that test fixtures
        // via Clock::set() reach postLoaded entities. A concrete
        // NativeClock/MockClock bypasses the static global entirely — see
        // review finding F3 (2026-07-31).
        if (!$clock instanceof Clock) {
            throw new \LogicException(\sprintf(
                'ClockInjectListener must be bound to Symfony\\Component\\Clock\\Clock '
                .'(the facade class), so test fixtures via Clock::set() reach '
                .'postLoaded entities. Currently bound to: %s. Update '
                .'config/services.yaml.',
                $clock::class,
            ));
        }
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!\method_exists($entity, 'setClock')) {
            return;
        }

        // ClockAwareTrait::$clock is private readonly. A second setClock()
        // throws "Cannot modify readonly property". Doctrine dispatches
        // postLoad at most once per hydration today (identity map short-circuits
        // repeated finds), but guard cheaply in case a future doctrine version
        // ever re-fires the event on the same instance.
        $rc = new \ReflectionClass($entity);
        if ($rc->hasProperty('clock')) {
            $prop = $rc->getProperty('clock');
            if ($prop->isInitialized($entity) && $prop->isReadOnly()) {
                return;
            }
        }

        $entity->setClock($this->clock);
    }
}
