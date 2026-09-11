<?php

declare(strict_types=1);

namespace App\Domain\Common\Constant;

/**
 * Shared slot-limit constant. A CollectionField maps 1:1 to an Item slot:
 * field (type, slotIndex) writes to Item.{$type}_{$slotIndex}. Both sides must
 * agree on the limit or the mapping silently loses fields.
 */
final class SlotLimits
{
    public const int MAX_SLOTS_PER_TYPE = 3;
}
