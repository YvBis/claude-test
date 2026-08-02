<?php

declare(strict_types=1);

namespace App\Domain\Collection\ValueObject;

enum FieldTypeEnum: string
{
    case TEXT = 'text';
    case NUMBER = 'number';
    case DATE = 'date';
    case BOOL = 'bool';
}
