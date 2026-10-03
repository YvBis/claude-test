<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Doctrine;

use App\Domain\Common\Constant\SlotLimits;
use App\Domain\Item\Entity\Item;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ItemSlotColumnsTest extends KernelTestCase
{
    /**
     * The slot columns are the only place the stored shape is decided, and they
     * are hardcoded as text/num/date/bool x 1..3 in the Item mapping. Every other
     * consumer of the limit (validation, the DTO serializer loop, the field cap)
     * reads the constant, so raising the constant alone leaves the schema behind:
     * validation would accept slot 4 and `Item::getSlotValue` would throw from
     * its `default =>` arm. Counting columns here is what makes that drift a red
     * test instead of a runtime 422.
     */
    public function testItemStoresOneColumnPerSlotAndType(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();
        $metadata = $em->getClassMetadata(Item::class);

        self::assertInstanceOf(ClassMetadata::class, $metadata);

        $slotColumns = [];
        foreach ($metadata->getFieldNames() as $field) {
            foreach (['text', 'num', 'date', 'bool'] as $prefix) {
                if (1 === \preg_match('/^'.\preg_quote($prefix, '/').'(?<slot>\d+)$/', $field, $m)) {
                    $slotColumns[$prefix][(int) $m['slot']] = true;
                }
            }
        }

        \ksort($slotColumns);

        $actual = [];
        foreach ($slotColumns as $prefix => $slots) {
            $actual[$prefix] = \array_keys($slots);
        }

        $expected = [
            'text' => \range(1, SlotLimits::MAX_SLOTS_PER_TYPE),
            'num' => \range(1, SlotLimits::MAX_SLOTS_PER_TYPE),
            'date' => \range(1, SlotLimits::MAX_SLOTS_PER_TYPE),
            'bool' => \range(1, SlotLimits::MAX_SLOTS_PER_TYPE),
        ];
        \ksort($expected);

        self::assertSame(
            $expected,
            $actual,
            \sprintf(
                'The Item mapping must hold exactly one column per (type, slot) up to %d. '
                .'Raise the constant and the columns in the same change, or the limit no longer '
                .'controls the stored shape.',
                SlotLimits::MAX_SLOTS_PER_TYPE,
            ),
        );
    }
}
