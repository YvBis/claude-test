<?php

declare(strict_types=1);

namespace App\Tests\PHPStan;

use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * review-16: a bound written as a literal is a second source of truth. It can
 * keep the right number while losing the link to the domain constant, and no
 * value-pinning test can see the difference.
 *
 * @implements Rule<Attribute>
 */
final class NoLiteralBoundRule implements Rule
{
    private const array BOUND_ARGUMENTS = [
        'max',
        'maxLength',
        'maxItems',
        'maximum',
        'min',
        'minLength',
        'minItems',
        'minimum',
        'count',
    ];

    private const array POSITIONAL_BOUND_INDEXES = [
        'Symfony\Component\Validator\Constraints\Length' => [0, 1],
        'Symfony\Component\Validator\Constraints\Range' => [0, 1],
        'Symfony\Component\Validator\Constraints\Count' => [0],
    ];

    public function getNodeType(): string
    {
        return Attribute::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(\PhpParser\Node $node, Scope $scope): array
    {
        $file = \str_replace('\\', '/', $scope->getFile());
        if (!\str_contains($file, '/src/Application/') && !\str_contains($file, '/src/Infrastructure/')) {
            return [];
        }

        \assert($node instanceof Attribute);

        $errors = [];
        foreach (self::boundArguments($node, $scope) as [$argument, $owner]) {
            if (!self::isLiteralBound($argument->value)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Bound "%s" of %s must reference a domain constant, not a literal.',
                $argument->name?->toString() ?? '#positional',
                $owner,
            ))
                ->identifier('validation.literalBound')
                ->line($argument->getStartLine())
                ->build();
        }

        return $errors;
    }

    /**
     * Attribute arguments may be plain values or nested `new` expressions
     * (OA\Property inside OA\Response), so both levels are inspected.
     *
     * @return list<array{Arg, string}>
     */
    private static function boundArguments(Attribute $attribute, Scope $scope): array
    {
        $found = [];
        $attributeClass = $scope->resolveName($attribute->name);
        self::collectFromArgs($attribute->args, $attributeClass, $found);

        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($attribute, New_::class) as $new) {
            if (!$new->class instanceof Name) {
                continue;
            }

            self::collectFromArgs($new->args, $scope->resolveName($new->class), $found);
        }

        return $found;
    }

    /**
     * @param array<Arg>               $args
     * @param list<array{Arg, string}> $found
     */
    private static function collectFromArgs(array $args, string $owner, array &$found): void
    {
        foreach ($args as $index => $argument) {
            if (self::isBoundArgument($owner, $index, $argument)) {
                $found[] = [$argument, $owner];
            }
        }
    }

    public static function isBoundArgument(string $owner, int $index, Arg $argument): bool
    {
        if (null !== $argument->name) {
            return \in_array($argument->name->toString(), self::BOUND_ARGUMENTS, true);
        }

        return \in_array($index, self::POSITIONAL_BOUND_INDEXES[$owner] ?? [], true);
    }

    public static function isLiteralBound(Expr $value): bool
    {
        if ($value instanceof UnaryMinus) {
            return self::isLiteralBound($value->expr);
        }

        return $value instanceof \PhpParser\Node\Scalar;
    }
}
