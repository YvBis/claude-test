<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Mechanical cover for the D11 message rule (task 8.6, D1): every
 * `Assert\Length` in `src/Application` carries a custom message.
 *
 * Default Symfony Validator wordings must never reach an API response
 * (`details` is a human diagnosis, not a machine enum — PR-3), so a bare
 * `#[Assert\Length(max: 100)]` is a contract violation, not a style nit.
 * The rule lives here rather than in review eyeballs: a new DTO that forgets
 * `maxMessage` turns this red at commit time.
 *
 * Scope is deliberately `Assert\Length` only. Comment content bounds, for
 * example, live in the domain (`CommentContent`) behind an empty
 * `CommentRequestDTO` — that is a separate, documented decision, and this
 * test must not pretend every string field is Length-guarded.
 */
#[CoversNothing]
final class ValidationUpperBoundTest extends TestCase
{
    public function testEveryLengthConstraintCarriesCustomMessages(): void
    {
        $violations = [];

        foreach ($this->applicationClasses() as $class) {
            $reflection = new \ReflectionClass($class);

            foreach ($reflection->getProperties() as $property) {
                foreach ($property->getAttributes(Length::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    /** @var Length $length */
                    $length = $attribute->newInstance();

                    foreach (self::missingMessages($length) as $missing) {
                        $violations[] = $class.'::$'.$property->getName().' sets '.$missing.' without a custom message';
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "A Length constraint without a custom message would leak Symfony's default wording into the API envelope:\n".\implode("\n", $violations),
        );
    }

    public function testLengthMessageCheckRejectsDrift(): void
    {
        // Negative probe: the checker behind the guard above must actually
        // distinguish a bare constraint from a messaged one.
        self::assertSame([], self::missingMessages(new Length(max: 5, maxMessage: 'No more than {{ limit }}')));
        self::assertSame([], self::missingMessages(new Length(min: 2, minMessage: 'At least {{ limit }}', max: 5, maxMessage: 'No more than {{ limit }}')));
        self::assertNotSame([], self::missingMessages(new Length(max: 5)));
        self::assertNotSame([], self::missingMessages(new Length(min: 2, maxMessage: 'No more than {{ limit }}')));
    }

    /**
     * @return list<string> which bound still carries Symfony's default wording; empty means "messaged"
     */
    private static function missingMessages(Length $length): array
    {
        // Length ships with English default messages (not null), so "custom"
        // can only mean "different from the default". The reference instance
        // below carries exactly those defaults.
        static $defaults = null;
        if (null === $defaults) {
            $defaults = new Length(min: 1, max: 1);
        }

        $missing = [];

        if (null !== $length->max && $length->maxMessage === $defaults->maxMessage) {
            $missing[] = 'max';
        }

        if (null !== $length->min && $length->minMessage === $defaults->minMessage) {
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
