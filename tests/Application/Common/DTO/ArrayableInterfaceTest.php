<?php

declare(strict_types=1);

namespace App\Tests\Application\Common\DTO;

use App\Application\Collection\DTO\CollectionDTO;
use App\Application\Common\DTO\ArrayableInterface;
use App\Application\Item\DTO\ItemDTO;
use App\Application\Tag\DTO\TagDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArrayableInterfaceTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function responseDtoProvider(): iterable
    {
        yield 'collection' => [CollectionDTO::class];
        yield 'item' => [ItemDTO::class];
        yield 'tag' => [TagDTO::class];
    }

    #[DataProvider('responseDtoProvider')]
    public function testImplementsArrayableInterface(string $class): void
    {
        $this->assertTrue(\is_subclass_of($class, ArrayableInterface::class));
        $this->assertTrue(\method_exists($class, 'toArray'));
    }
}
