<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Guards the `.gitignore` log rules (task 5.33).
 *
 * The file used to carry dead negation rules (`!/var/log/dev.log`,
 * `!/var/log/prod.log`): they were overridden by the framework-bundle
 * recipe's `/var/` block, so the negations excluded nothing — and nothing
 * noticed for months. The single definition for ignored logs is now `/var/`
 * (recipe) plus `*.log` for stray logs outside `var/`.
 *
 * Parsing instead of running `git check-ignore` is deliberate: the invariant
 * we protect is structural ("no negation re-includes a log"), and it must
 * hold in environments without git. The behavioural half (`git check-ignore`
 * on a probe list) was verified by hand and lives in the PRD, not here.
 *
 * The log tests walk the whole file (that is a project-wide invariant), while
 * the duplicate test scopes down to the owned region above the first `###>`
 * marker — duplicates inside recipe blocks are Flex's concern and get
 * re-added on `composer recipes:update`.
 */
final class GitignoreConfigTest extends TestCase
{
    public function testNoNegationReincludesLogs(): void
    {
        foreach (self::patterns() as $pattern) {
            if (!\str_starts_with($pattern, '!')) {
                continue;
            }

            self::assertFalse(
                self::isLogRelated($pattern),
                \sprintf('Negation rule "%s" touches logs and would be dead or surprising.', $pattern),
            );
        }
    }

    public function testLogsHaveExactlyOneDefinition(): void
    {
        $patterns = self::patterns();

        self::assertContains(
            '/var/',
            $patterns,
            'The recipe block must keep ignoring the whole var/ directory (5.33).',
        );
        self::assertContains(
            '*.log',
            $patterns,
            '"*.log" is the single definition for stray logs outside var/ (5.33).',
        );

        $logRules = \array_values(\array_filter(
            $patterns,
            self::isLogRelated(...),
        ));

        self::assertSame(
            ['*.log'],
            $logRules,
            'Exactly one rule may mention logs; dead siblings kept reappearing here.',
        );
    }

    public function testNoDuplicatePatterns(): void
    {
        // Only the part we own: everything from the first `###>` recipe marker
        // on is Flex's property and gets re-added on `composer recipes:update`,
        // so duplicates that live there (e.g. `/vendor/`) are not ours to fix.
        $patterns = self::ownedPatterns();
        $duplicates = \array_diff_assoc($patterns, \array_unique($patterns));

        self::assertSame(
            [],
            \array_values($duplicates),
            'Duplicate patterns add reading cost and hide intent.',
        );
    }

    /**
     * @return list<string>
     */
    private static function ownedPatterns(): array
    {
        $path = \dirname(__DIR__, 3).'/.gitignore';
        self::assertFileExists($path);

        $patterns = [];
        $lines = \file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertNotFalse($lines);
        foreach ($lines as $line) {
            $line = \trim($line);
            if (\str_starts_with($line, '###>')) {
                break;
            }
            if ('' === $line || \str_starts_with($line, '#')) {
                continue;
            }
            $patterns[] = $line;
        }

        return $patterns;
    }

    /**
     * @return list<string>
     */
    private static function patterns(): array
    {
        $path = \dirname(__DIR__, 3).'/.gitignore';
        self::assertFileExists($path);

        $lines = \file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertNotFalse($lines);

        return \array_values(\array_filter(
            \array_map(trim(...), $lines),
            static fn (string $line): bool => '' !== $line && !\str_starts_with($line, '#'),
        ));
    }

    /**
     * A pattern is log-related when it names the log extension or the log
     * path segment: `*.log`, `var/log/*`, `!/var/log/dev.log`. Segment
     * matching (not a plain substring) keeps `catalog`/`blog` out.
     */
    private static function isLogRelated(string $pattern): bool
    {
        $lower = \strtolower($pattern);

        return \str_ends_with($lower, '.log')
            || 1 === \preg_match('~(^|[\/])log([\/]|$)~', $lower);
    }
}
