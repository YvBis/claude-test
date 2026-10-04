<?php

declare(strict_types=1);

namespace App\Domain\Common\ValueObject;

/**
 * The id of the user a resource belongs to.
 *
 * fwd-5 lives here, in `Domain\Common`, and not in `Domain\Collection` where it
 * started. The task removes the `Domain\Collection/Like/Comment → Domain\User`
 * edges; keeping this primitive in the Collection domain would have deleted one
 * cross-domain arrow and created five (`Like → Collection`, `Comment → Collection`,
 * `Item → Collection`, plus every repository and controller). That is relocation,
 * not decoupling. `Common` already holds `UuidBinaryValue`, which this uses.
 *
 * Deliberately NOT an `#[ORM\Embeddable]`. Every owner column in the mapping is a
 * plain `#[ORM\Column(name: 'owner_id', type: 'binary', length: 16)]` string that
 * the entity converts on the way in (`getOwnerId(): OwnerId`) and out
 * (`$ownerId->toBytes()`). Embedding it would need a `columnPrefix` to avoid
 * colliding with the entity's own `id`, and would require verified hydration of a
 * `readonly` class through a private constructor — Doctrine cannot do that without
 * a bypass. Plain bytes in the entity, the value object at the boundaries, is the
 * cheaper half of that trade and is what the repositories already assumed.
 */
final readonly class OwnerId
{
    use UuidBinaryValue;

    private string $uuid;

    private function __construct(string $uuid)
    {
        $this->uuid = $uuid;
    }
}
