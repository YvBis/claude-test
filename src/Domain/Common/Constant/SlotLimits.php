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

    /**
     * Structural, not a product choice (review-14): every (type, slot) pair is
     * one column, so a request cannot legitimately carry more entries than
     * that. Pinned to count(FieldType::values()) * MAX_SLOTS_PER_TYPE by test.
     */
    public const int MAX_SLOTS_PER_ITEM = 12;
}
