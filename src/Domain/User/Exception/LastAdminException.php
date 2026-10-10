<?php

declare(strict_types=1);

namespace App\Domain\User\Exception;

final class LastAdminException extends \RuntimeException
{
    public static function block(): self
    {
        return new self('Cannot block the last active admin: the system would be left unmanageable.');
    }

    public static function delete(): self
    {
        return new self('Cannot delete the last active admin: the system would be left unmanageable.');
    }
}
