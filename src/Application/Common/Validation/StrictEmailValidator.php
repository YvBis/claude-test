<?php

declare(strict_types=1);

namespace App\Application\Common\Validation;

use App\Domain\User\ValueObject\Email;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class StrictEmailValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof StrictEmail) {
            throw new UnexpectedValueException($constraint, StrictEmail::class);
        }

        if (null !== $value && '' !== $value) {
            $this->assertDomainAccepts($value, $constraint);
        }
    }

    private function assertDomainAccepts(string $value, StrictEmail $constraint): void
    {
        try {
            Email::fromString($value);
        } catch (\InvalidArgumentException) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
