<?php

declare(strict_types=1);

namespace App\Application\Common\Validation;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class StrictEmail extends Constraint
{
    public string $message = 'Invalid email format';

    /**
     * @param string[]|null $groups
     */
    public function __construct(?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);

        if (null !== $message) {
            $this->message = $message;
        }
    }
}
