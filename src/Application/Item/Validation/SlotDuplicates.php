<?php

declare(strict_types=1);

namespace App\Application\Item\Validation;

use App\Application\Item\DTO\ItemSlotDTO;
use App\Domain\Collection\ValueObject\FieldType;

/**
 * Detects repeated (type, slot) pairs in a request slot list. ItemSlotMapper
 * writes entries in order, so a duplicate would silently keep the last value.
 *
 * Types outside the allowed set are skipped: they already carry their own
 * Choice violation, and echoing them here would put raw client input into
 * `details`.
 */
final class SlotDuplicates
{
    /**
     * @param array<int, ItemSlotDTO> $slots
     *
     * @return list<array{type: string, slot: int}>
     */
    public static function in(array $slots): array
    {
        $allowed = FieldType::values();
        $counts = [];
        $duplicates = [];

        foreach ($slots as $slot) {
            $type = $slot->type;

            if (\in_array($type, $allowed, true)) {
                $counts[$type][$slot->slot] = ($counts[$type][$slot->slot] ?? 0) + 1;
            }
        }

        foreach ($counts as $type => $bySlot) {
            foreach ($bySlot as $slot => $count) {
                if ($count > 1) {
                    $duplicates[] = ['type' => $type, 'slot' => $slot];
                }
            }
        }

        return $duplicates;
    }
}
