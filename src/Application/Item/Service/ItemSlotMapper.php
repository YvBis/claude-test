<?php

declare(strict_types=1);

namespace App\Application\Item\Service;

use App\Application\Item\DTO\ItemSlotDTO;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Item\Entity\Item;

final readonly class ItemSlotMapper
{
    /**
     * @param array<int, ItemSlotDTO> $slots
     */
    public function applySlots(Item $item, array $slots): void
    {
        foreach ($slots as $slot) {
            $this->applySlot($item, $slot);
        }
    }

    private function applySlot(Item $item, ItemSlotDTO $slot): void
    {
        $type = FieldType::fromString($slot->type);
        $item->setSlotValue($type, $slot->slot, $this->coerce($type, $slot->value));
    }

    private function coerce(FieldType $type, string|int|float|bool|null $value): string|float|\DateTimeImmutable|bool|null
    {
        if (null === $value) {
            return null;
        }

        return match ($type->value()) {
            'text' => $this->asString($value),
            'number' => $this->asNumber($value),
            'date' => $this->asDate($value),
            'bool' => $this->asBool($value),
            default => throw new \InvalidArgumentException(\sprintf('Unknown field type "%s"', $type->value())),
        };
    }

    private function asString(string|int|float|bool $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        throw new \InvalidArgumentException(\sprintf('Text slot expects string, got %s', \get_debug_type($value)));
    }

    private function asNumber(string|int|float|bool $value): float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        throw new \InvalidArgumentException(\sprintf('Number slot expects numeric value, got %s', \get_debug_type($value)));
    }

    private function asDate(string|int|float|bool $value): \DateTimeImmutable
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf('Date slot expects an ISO-8601 string, got %s', \get_debug_type($value)));
        }

        if (\str_ends_with($value, 'Z')) {
            $value = \substr($value, 0, -1).'+00:00';
        }

        $formats = [
            'Y-m-d\TH:i:s.uP',
            'Y-m-d\TH:i:s.u',
            'Y-m-d\TH:i:s.vP',
            'Y-m-d\TH:i:s.v',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s',
            'Y-m-d',
        ];
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date instanceof \DateTimeImmutable && $date->format($format) === $value) {
                return $date->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        throw new \InvalidArgumentException(\sprintf('Date slot expects a valid ISO-8601 string, got "%s"', $value));
    }

    private function asBool(string|int|float|bool $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        throw new \InvalidArgumentException(\sprintf('Bool slot expects boolean, got %s', \get_debug_type($value)));
    }
}
