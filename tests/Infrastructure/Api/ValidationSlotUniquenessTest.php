<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api;

use App\Application\Item\DTO\CreateItemDTO;
use App\Application\Item\DTO\UpdateItemDTO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Callback;

/**
 * 10.8: a DTO that accepts a list of ItemSlotDTO must refuse repeated
 * (type, slot) pairs. The rule lives in a class-level Assert\Callback, so a new
 * item-shaped DTO that forgets it would silently fall back to "last value
 * wins" in ItemSlotMapper.
 */
#[CoversNothing]
final class ValidationSlotUniquenessTest extends TestCase
{
    public function testEveryDtoAcceptingSlotsCarriesTheUniquenessCallback(): void
    {
        $checked = [];
        $violations = [];

        foreach ($this->applicationClasses() as $class) {
            $reflection = new \ReflectionClass($class);

            if (!$this->acceptsSlots($reflection)) {
                continue;
            }

            $checked[] = $class;

            if (!$this->hasClassCallback($reflection)) {
                $violations[] = $class.' accepts ItemSlotDTO entries but carries no Assert\Callback';
            }

            foreach ($this->nonPublicCallbacks($reflection) as $method) {
                $violations[] = $class.'::'.$method.' must be public: the validator calls it from outside the DTO';
            }
        }

        self::assertSame([], $violations, "Slot lists must validate uniqueness (10.8):\n".\implode("\n", $violations));
        self::assertSame([CreateItemDTO::class, UpdateItemDTO::class], $checked);
    }

    private function acceptsSlots(\ReflectionClass $reflection): bool
    {
        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            if (\str_contains((string) $property->getDocComment(), 'ItemSlotDTO')) {
                return true;
            }
        }

        $constructor = $reflection->getConstructor();

        return null !== $constructor && \str_contains((string) $constructor->getDocComment(), 'ItemSlotDTO');
    }

    private function hasClassCallback(\ReflectionClass $reflection): bool
    {
        foreach ($reflection->getMethods() as $method) {
            if ([] !== $method->getAttributes(Callback::class, \ReflectionAttribute::IS_INSTANCEOF)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function nonPublicCallbacks(\ReflectionClass $reflection): array
    {
        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            if ([] === $method->getAttributes(Callback::class, \ReflectionAttribute::IS_INSTANCEOF)) {
                continue;
            }

            if (!$method->isPublic()) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }

    /**
     * @return list<class-string>
     */
    private function applicationClasses(): array
    {
        $base = \dirname(__DIR__, 3).'/src/Application';
        $classes = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            if (!\str_ends_with($file->getFilename(), 'DTO.php')) {
                continue;
            }

            $relative = \substr($file->getPathname(), \strlen($base) + 1, -4);
            $class = 'App\\Application\\'.\str_replace('/', '\\', $relative);

            if (\class_exists($class)) {
                $classes[] = $class;
            }
        }

        \sort($classes);

        return $classes;
    }
}
