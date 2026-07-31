<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Listener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Symfony\Component\Clock\ClockInterface;

/**
 * Hydrates entities with the autowired Clock via ClockAwareTrait::setClock().
 *
 * Without this listener, entities hydrated from the database have an uninitialized
 * `$clock` field, and the trait's lazy `??=` fallback inside `now()` would
 * instantiate a fresh static facade per entity — defeating any test override
 * that relied on `Clock::set()`.
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
