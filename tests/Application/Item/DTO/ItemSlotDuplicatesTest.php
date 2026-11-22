<?php

declare(strict_types=1);

namespace App\Tests\Application\Item\DTO;

use App\Application\Item\DTO\CreateItemDTO;
use App\Application\Item\DTO\ItemSlotDTO;
use App\Application\Item\DTO\UpdateItemDTO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Context\ExecutionContext;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * 10.8: a repeated (type, slot) pair used to be applied in order, so the last
 * value won silently. The DTO now refuses the list before ItemSlotMapper runs.
 */
final class ItemSlotDuplicatesTest extends TestCase
{
    public function testDuplicatePairIsRejectedWithTheRenderedMessage(): void
    {
        $violations = $this->validator()->validate(new CreateItemDTO('Item', slots: [
            new ItemSlotDTO('text', 1, 'first'),
            new ItemSlotDTO('text', 1, 'second'),
        ]));

        self::assertCount(1, $violations);
        self::assertSame('Duplicate slot: type "text", slot 1.', $violations[0]->getMessage());
        self::assertSame('slots', $violations[0]->getPropertyPath());
    }

    public function testSameSlotIndexOnDifferentTypesIsAllowed(): void
    {
        self::assertCount(0, $this->validator()->validate(new CreateItemDTO('Item', slots: [
            new ItemSlotDTO('text', 1, 'a'),
            new ItemSlotDTO('number', 1, 1),
            new ItemSlotDTO('date', 1, '2026-01-01'),
            new ItemSlotDTO('bool', 1, true),
        ])));
    }

    public function testDifferentSlotIndexesOfTheSameTypeAreAllowed(): void
    {
        self::assertCount(0, $this->validator()->validate(new CreateItemDTO('Item', slots: [
            new ItemSlotDTO('text', 1, 'a'),
            new ItemSlotDTO('text', 2, 'b'),
        ])));
    }

    public function testDuplicateIsDetectedAfterNormalisation(): void
    {
        $violations = $this->validator()->validate(new CreateItemDTO('Item', slots: [
            new ItemSlotDTO('TEXT', 1, 'a'),
            new ItemSlotDTO(' text ', 1, 'b'),
        ]));

        self::assertCount(1, $violations);
        self::assertSame('Duplicate slot: type "text", slot 1.', $violations[0]->getMessage());
    }

    public function testUnknownTypeIsLeftToItsOwnChoiceViolation(): void
    {
        $violations = $this->validator()->validate(new CreateItemDTO('Item', slots: [
            new ItemSlotDTO('bogus', 1, 'a'),
            new ItemSlotDTO('bogus', 1, 'b'),
        ]));

        self::assertSame(
            ['Invalid slot type: "bogus". Allowed: text, number, date, bool', 'Invalid slot type: "bogus". Allowed: text, number, date, bool'],
            $this->messages($violations),
        );
    }

    public function testUnknownTypeDoesNotHideALaterDuplicate(): void
    {
        $violations = $this->validator()->validate(new CreateItemDTO('Item', slots: [
            new ItemSlotDTO('text', 1, 'a'),
            new ItemSlotDTO('bogus', 2, 'b'),
            new ItemSlotDTO('text', 1, 'c'),
        ]));

        self::assertContains('Duplicate slot: type "text", slot 1.', $this->messages($violations));
    }

    public function testEveryDuplicatedPairIsReported(): void
    {
        $violations = $this->validator()->validate(new CreateItemDTO('Item', slots: [
            new ItemSlotDTO('text', 1, 'a'),
            new ItemSlotDTO('text', 1, 'b'),
            new ItemSlotDTO('number', 2, 1),
            new ItemSlotDTO('number', 2, 2),
        ]));

        self::assertSame([
            'Duplicate slot: type "text", slot 1.',
            'Duplicate slot: type "number", slot 2.',
        ], $this->messages($violations));
    }

    public function testOneViolationPerDuplicatedPairEvenWhenRepeatedThrice(): void
    {
        $violations = $this->validator()->validate(new CreateItemDTO('Item', slots: [
            new ItemSlotDTO('text', 1, 'a'),
            new ItemSlotDTO('text', 1, 'b'),
            new ItemSlotDTO('text', 1, 'c'),
        ]));

        self::assertSame(['Duplicate slot: type "text", slot 1.'], $this->messages($violations));
    }

    public function testUpdateWithoutSlotsIsNotChecked(): void
    {
        self::assertCount(0, $this->validator()->validate(new UpdateItemDTO(name: 'Renamed')));
        self::assertCount(0, $this->validator()->validate(new UpdateItemDTO(slots: [])));
    }

    public function testUpdateRejectsDuplicates(): void
    {
        $violations = $this->validator()->validate(new UpdateItemDTO(slots: [
            new ItemSlotDTO('bool', 2, true),
            new ItemSlotDTO('bool', 2, false),
        ]));

        self::assertCount(1, $violations);
        self::assertSame('Duplicate slot: type "bool", slot 2.', $violations[0]->getMessage());
    }

    /**
     * The validator invokes the callback from outside the DTO, so the method is
     * part of the public validation surface. The direct call also keeps the
     * mutated signature line covered, which is what makes this test kill the
     * public→protected mutant (the unit suite is the mutation scope).
     */
    public function testCallbackMethodIsPublicOnBothDtos(): void
    {
        foreach ([CreateItemDTO::class, UpdateItemDTO::class] as $class) {
            $method = new \ReflectionMethod($class, 'validateUniqueSlots');

            self::assertTrue($method->isPublic(), $class.'::validateUniqueSlots must be public');
            self::assertNotEmpty($method->getAttributes(Callback::class), $class.'::validateUniqueSlots must carry Assert\Callback');
        }

        $dto = new CreateItemDTO('Item', slots: [
            new ItemSlotDTO('text', 1, 'a'),
            new ItemSlotDTO('text', 1, 'b'),
        ]);
        $context = new ExecutionContext($this->validator(), $dto, $this->translator());

        $dto->validateUniqueSlots($context);

        self::assertCount(1, $context->getViolations());

        $update = new UpdateItemDTO(slots: [
            new ItemSlotDTO('text', 1, 'a'),
            new ItemSlotDTO('text', 1, 'b'),
        ]);
        $updateContext = new ExecutionContext($this->validator(), $update, $this->translator());

        $update->validateUniqueSlots($updateContext);

        self::assertCount(1, $updateContext->getViolations());
    }

    private function translator(): TranslatorInterface
    {
        return new class () implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return \strtr($id, $parameters);
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }

    /**
     * @param iterable<ConstraintViolationInterface> $violations
     *
     * @return list<string>
     */
    private function messages(iterable $violations): array
    {
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }

    private function validator(): ValidatorInterface
    {
        static $validator = null;

        return $validator ??= Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
