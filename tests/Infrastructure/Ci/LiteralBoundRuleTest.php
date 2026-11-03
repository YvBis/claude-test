<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use App\Tests\PHPStan\NoLiteralBoundRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Negative probe for the PHPStan rule (review-16): the rule only helps if the
 * literal detection behind it actually distinguishes a literal from a constant
 * reference. Cheap here, because the helper is pure.
 */
#[CoversNothing]
final class LiteralBoundRuleTest extends TestCase
{
    public function testDetectsScalarLiterals(): void
    {
        self::assertTrue(NoLiteralBoundRule::isLiteralBound(new Int_(255)));
        self::assertTrue(NoLiteralBoundRule::isLiteralBound(new Float_(1.5)));
        self::assertTrue(NoLiteralBoundRule::isLiteralBound(new String_('255')));
        self::assertTrue(NoLiteralBoundRule::isLiteralBound(new UnaryMinus(new Int_(1))));
    }

    public function testAcceptsConstantReferencesAndExpressions(): void
    {
        $constant = new ClassConstFetch(new Name('PasswordHash'), new Identifier('MAX_PLAIN_LENGTH'));

        self::assertFalse(NoLiteralBoundRule::isLiteralBound($constant));
        self::assertFalse(NoLiteralBoundRule::isLiteralBound(new Variable('max')));
        self::assertFalse(NoLiteralBoundRule::isLiteralBound(new Plus($constant, new Int_(1))));
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('boundArgumentProvider')]
    public function testRecognisesBoundArguments(string $attribute, int $index, ?string $name, bool $expected): void
    {
        $argument = new Arg(new Int_(1), false, false, [], $name ? new Identifier($name) : null);

        self::assertSame($expected, NoLiteralBoundRule::isBoundArgument($attribute, $index, $argument));
    }

    /**
     * @return iterable<string, array{string, int, ?string, bool}>
     */
    public static function boundArgumentProvider(): iterable
    {
        yield 'named max' => ['Symfony\Component\Validator\Constraints\Length', 0, 'max', true];
        yield 'named minimum' => ['Symfony\Component\Validator\Constraints\Range', 0, 'minimum', true];
        yield 'named count' => ['Symfony\Component\Validator\Constraints\Count', 0, 'count', true];
        yield 'named message is not a bound' => ['Symfony\Component\Validator\Constraints\Length', 0, 'maxMessage', false];
        yield 'positional second Length arg' => ['Symfony\Component\Validator\Constraints\Length', 1, null, true];
        yield 'positional first Length arg' => ['Symfony\Component\Validator\Constraints\Length', 0, null, true];
        yield 'positional Range arg' => ['Symfony\Component\Validator\Constraints\Range', 1, null, true];
        yield 'positional Count arg' => ['Symfony\Component\Validator\Constraints\Count', 0, null, true];
        yield 'positional unknown attribute' => ['Symfony\Component\Validator\Constraints\NotBlank', 0, null, false];
        yield 'positional message index is not a bound' => ['Symfony\Component\Validator\Constraints\Length', 5, null, false];
    }
}
