<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Count;

/**
 * Mechanical cover for the review-14 rule: every array property in an
 * Application DTO is a request collection and must carry `Assert\Count` with a
 * custom message.
 *
 * Rationale mirrors ValidationUpperBoundTest: `Count` without `maxMessage`
 * leaks Symfony's default wording into the API `details`, and an unbounded list
 * is a write amplifier (`TagService::resolveByNames` upserts per name). Both are
 * contract violations, not style nits, so the rule is enforced by a scanner
 * rather than by review attention.
 *
 * Response DTOs are exempt by name: their arrays are output shapes, and a
 * count bound there would document a limit the code does not enforce.
 */
#[CoversNothing]
final class ValidationCardinalityTest extends TestCase
{
    /**
     * @var list<string> non-request shapes: output documents, exception payload
     */
    private const array RESPONSE_DTO_EXEMPT = [
        'App\Application\Exception\ValidationException',
        'App\Application\Item\DTO\ItemDTO',
        'App\Application\Item\DTO\ItemDetailDTO',
        'App\Application\Search\ItemDocument',
        'App\Application\Search\ItemSearchHit',
    ];

    public function testEveryArrayPropertyCarriesAMessagedCount(): void
    {
        $violations = [];

        foreach ($this->applicationClasses() as $class) {
            $reflection = new \ReflectionClass($class);

            foreach ($reflection->getProperties() as $property) {
                $type = $property->getType();
                if (!$type instanceof \ReflectionNamedType || 'array' !== $type->getName()) {
                    continue;
                }

                if (\in_array($class, self::RESPONSE_DTO_EXEMPT, true)) {
                    continue;
                }

                $counts = $property->getAttributes(Count::class, \ReflectionAttribute::IS_INSTANCEOF);
                if ([] === $counts) {
                    $violations[] = $class.'::$'.$property->getName().' is an array without Assert\Count';
                    continue;
                }

                foreach ($counts as $attribute) {
                    /** @var Count $count */
                    $count = $attribute->newInstance();
                    foreach (self::missingMessages($count) as $missing) {
                        $violations[] = $class.'::$'.$property->getName().' sets '.$missing.' without a custom message';
                    }
                }
            }
        }

        self::assertSame([], $violations, "Array properties must be bounded (review-14):\n".\implode("\n", $violations));
    }

    public function testCardinalityCheckRejectsDrift(): void
    {
        // Negative probe: the checker must actually distinguish a bare bound
        // from a messaged one, otherwise the guard proves nothing.
        self::assertSame([], self::missingMessages(new Count(max: 5, maxMessage: 'At most {{ limit }}')));
        self::assertSame([], self::missingMessages(new Count(min: 1, minMessage: 'At least {{ limit }}', max: 5, maxMessage: 'At most {{ limit }}')));
        self::assertNotSame([], self::missingMessages(new Count(max: 5)));
        self::assertNotSame([], self::missingMessages(new Count(min: 1, max: 5, maxMessage: 'At most {{ limit }}')));
    }

    /**
     * @return list<string> which bound still carries Symfony's default wording; empty means "messaged"
     */
    private static function missingMessages(Count $count): array
    {
        static $defaults = null;
        if (null === $defaults) {
            $defaults = new Count(min: 1, max: 1);
        }

        $missing = [];

        if (null !== $count->max && $count->maxMessage === $defaults->maxMessage) {
            $missing[] = 'max';
        }

        if (null !== $count->min && $count->minMessage === $defaults->minMessage) {
            $missing[] = 'min';
        }

        return $missing;
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

            $relative = \substr($file->getPathname(), \strlen($base) + 1, -4);
            $class = 'App\\Application\\'.\str_replace('/', '\\', $relative);

            if (\class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
