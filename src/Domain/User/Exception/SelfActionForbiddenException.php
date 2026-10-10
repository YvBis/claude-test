<?php

declare(strict_types=1);

namespace App\Domain\User\Exception;

final class SelfActionForbiddenException extends \RuntimeException
{
    public static function block(): self
    {
        return new self('An admin cannot block their own account.');
    }

    public static function unblock(): self
    {
        return new self('An admin cannot unblock their own account.');
    }

    public static function delete(): self
    {
        return new self('An admin cannot delete their own account.');
    }
}
