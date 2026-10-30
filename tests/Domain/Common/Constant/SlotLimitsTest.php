<?php

declare(strict_types=1);

namespace App\Tests\Domain\Common\Constant;

use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Common\Constant\SlotLimits;
use PHPUnit\Framework\TestCase;

/**
 * MAX_SLOTS_PER_ITEM is a structural bound: one column per (type, slot) pair.
 * The literal lives in Domain\Common to keep that package free of a
 * Domain\Collection import, so this test is the link between the number and
 * the field types it is derived from (review-14).
 */
final class SlotLimitsTest extends TestCase
{
    public function testPerItemLimitCoversEveryTypeAndSlotPair(): void
    {
        self::assertSame(
            \count(FieldType::values()) * SlotLimits::MAX_SLOTS_PER_TYPE,
            SlotLimits::MAX_SLOTS_PER_ITEM,
            'Adding a field type must raise the per-item slot limit, or a valid request becomes unreachable.',
        );
    }
}
