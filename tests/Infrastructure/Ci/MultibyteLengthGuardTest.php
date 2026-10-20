<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Guards the review-17 decision (ADR/0003-text-length-unit.md): text length is
 * measured in Unicode code points, and the measurement must be written so that
 * it cannot silently drift back to bytes.
 *
 * Two invariants, both over `src` (not only `src/Domain`: the contract these
 * bounds must mirror lives in Application DTOs and Infrastructure OA):
 *
 * 1. No `strlen(` on text. The defect this prevents was reachable, not
 *    theoretical: a 60-character Cyrillic collection name is 60 code points —
 *    accepted by the DTO's `Assert\Length` and by the published `maxLength` —
 *    but 120 UTF-8 bytes, so a byte-based bound threw an exception no layer
 *    translated and the client got a 500 envelope instead of 201.
 * 2. Every `mb_strlen(` states `'UTF-8'` explicitly. Without the charset the
 *    unit depends on `mb_internal_encoding`, which is UTF-8 by default today
 *    and is therefore a silent trap rather than a bug.
 *
 * Both allowlists are exhaustive rather than pattern-based: adding an entry
 * requires editing this file with a reason, which is the moment a reviewer
 * should look at it. Removing a legitimate site also turns the guard red, so
 * the lists cannot drift into describing code that no longer exists.
 */
final class MultibyteLengthGuardTest extends TestCase
{
    /**
     * Matches `strlen(` however it is written: `\strlen(`, bare `strlen(` (which
     * php-cs-fixer forbids but a generated file could contain) and `\STRLEN(`.
     * The lookbehind rejects the `_` in `mb_strlen(`, so that call never matches.
     */
    private const BYTE_CALL_PATTERN = '~(?<![A-Za-z0-9_])strlen\s*\(~i';

    /**
     * @var array<string, array{count: int, reason: string}> path => allowance and why
     */
    private const ALLOWED_BYTE_CHECKS = [
        'src/Domain/Common/ValueObject/UuidBinaryValue.php' => [
            'count' => 1,
            'reason' => 'a UUID is 16 binary bytes by type, not text',
        ],
        'src/Domain/User/ValueObject/PasswordHash.php' => [
            'count' => 1,
            'reason' => 'plain-password minimum; code-point parity is owned by review-15',
        ],
    ];

    /**
     * @var array<string, array{count: int, reason: string}> path => allowance and why
     */
    private const ALLOWED_IMPLICIT_CHARSETS = [
        'src/Domain/Item/Entity/Item.php' => [
            'count' => 2,
            'reason' => 'deferred to review-19: aligning these lines widens the mutation surface of a defect fix (measured 60s timeouts)',
        ],
    ];

    public function testSourceDoesNotMeasureTextLengthInBytes(): void
    {
        self::assertSame(
            [],
            self::violations(self::byteSitesByPath(), self::ALLOWED_BYTE_CHECKS),
            'src must measure text in code points, like Assert\\Length does. '
            .'If the check measures text, use mb_strlen($value, \'UTF-8\'); '
            .'if bytes are genuinely correct, add it to ALLOWED_BYTE_CHECKS with a reason.',
        );
    }

    public function testEveryMbStrlenStatesItsCharset(): void
    {
        self::assertSame(
            [],
            self::violations(self::implicitCharsetSitesByPath(), self::ALLOWED_IMPLICIT_CHARSETS),
            'Every mb_strlen must pass \'UTF-8\' explicitly (ADR/0003): without it the unit '
            .'depends on mb_internal_encoding. If the site is knowingly deferred, add it to '
            .'ALLOWED_IMPLICIT_CHARSETS with the reason.',
        );
    }

    public function testViolationsFlagASecondSiteInAnAllowlistedFile(): void
    {
        $sites = [
            'src/Domain/Common/ValueObject/UuidBinaryValue.php' => [
                'src/Domain/Common/ValueObject/UuidBinaryValue.php:21',
                'src/Domain/Common/ValueObject/UuidBinaryValue.php:33',
            ],
        ];

        self::assertSame(
            [
                'src/Domain/Common/ValueObject/UuidBinaryValue.php: 2 byte checks (allowance is 1)',
                'src/Domain/User/ValueObject/PasswordHash.php: allowlisted but no longer measures bytes',
            ],
            self::violations($sites, self::ALLOWED_BYTE_CHECKS),
        );
    }

    public function testViolationsFlagEveryStaleAllowlistEntry(): void
    {
        self::assertSame(
            [
                'src/Domain/Common/ValueObject/UuidBinaryValue.php: allowlisted but no longer measures bytes',
                'src/Domain/User/ValueObject/PasswordHash.php: allowlisted but no longer measures bytes',
            ],
            self::violations([], self::ALLOWED_BYTE_CHECKS),
        );
    }

    public function testMatcherFlagsAByteCheck(): void
    {
        // negative probe: a guard that cannot fail is decoration
        self::assertSame(
            ['src/Domain/Fake/Fixture.php' => ['src/Domain/Fake/Fixture.php:3']],
            self::byteSites(['src/Domain/Fake/Fixture.php' => "<?php\n\nif (\\strlen(\$value) > 3) {\n}"]),
        );
    }

    public function testMatcherFlagsUnqualifiedAndUppercaseCalls(): void
    {
        self::assertSame(
            [
                'src/Domain/Fake/Fixture.php' => [
                    'src/Domain/Fake/Fixture.php:2',
                    'src/Domain/Fake/Fixture.php:3',
                ],
            ],
            self::byteSites(['src/Domain/Fake/Fixture.php' => "<?php\nstrlen(\$a);\n\\STRLEN(\$b);"]),
        );
    }

    public function testMatcherIgnoresMbStrlen(): void
    {
        self::assertSame(
            [],
            self::byteSites(['src/Domain/Fake/Fixture.php' => "<?php\n\nif (\\mb_strlen(\$value, 'UTF-8') > 3) {\n}"]),
        );
    }

    public function testCharsetMatcherFlagsSingleArgumentCall(): void
    {
        self::assertSame(
            ['src/Domain/Fake/Fixture.php' => ['src/Domain/Fake/Fixture.php:3']],
            self::implicitCharsetSites([
                'src/Domain/Fake/Fixture.php' => "<?php\n\nif (\\mb_strlen(\$value) > 3) {\n}",
            ]),
        );
    }

    public function testCharsetMatcherFlagsAWrongCharset(): void
    {
        self::assertSame(
            ['src/Domain/Fake/Fixture.php' => ['src/Domain/Fake/Fixture.php:3']],
            self::implicitCharsetSites([
                'src/Domain/Fake/Fixture.php' => "<?php\n\nif (\\mb_strlen(\$value, 'Windows-1251') > 3) {\n}",
            ]),
        );
    }

    public function testCharsetMatcherAcceptsExplicitUtf8(): void
    {
        self::assertSame(
            [],
            self::implicitCharsetSites([
                'src/Domain/Fake/Fixture.php' => "<?php\n\nif (\\mb_strlen(\$value, 'UTF-8') > 3) {\n}",
            ]),
        );
    }

    /**
     * @param array<string, list<string>>                      $sites   path => hits
     * @param array<string, array{count: int, reason: string}> $allowed path => allowance
     *
     * @return list<string>
     */
    private static function violations(array $sites, array $allowed): array
    {
        $violations = [];

        foreach ($sites as $path => $hits) {
            if (!\array_key_exists($path, $allowed)) {
                $violations = [...$violations, ...$hits];
                continue;
            }

            if ($allowed[$path]['count'] !== \count($hits)) {
                $violations[] = \sprintf(
                    '%s: %d byte checks (allowance is %d)',
                    $path,
                    \count($hits),
                    $allowed[$path]['count'],
                );
            }
        }

        foreach (\array_keys($allowed) as $path) {
            if (!\array_key_exists($path, $sites)) {
                $violations[] = \sprintf('%s: allowlisted but no longer measures bytes', $path);
            }
        }

        return $violations;
    }

    /**
     * @return array<string, list<string>> path => list of "path:line" hits
     */
    private static function byteSitesByPath(): array
    {
        return self::byteSites(self::sources());
    }

    /**
     * @return array<string, list<string>> path => list of "path:line" hits
     */
    private static function implicitCharsetSitesByPath(): array
    {
        return self::implicitCharsetSites(self::sources());
    }

    /**
     * @param array<string, string> $contents path => source
     *
     * @return array<string, list<string>> path => list of "path:line" hits
     */
    private static function byteSites(array $contents): array
    {
        $sites = [];

        foreach ($contents as $path => $source) {
            $line = 0;
            foreach (\preg_split('/\R/', $source) ?: [] as $current) {
                ++$line;
                if (1 === \preg_match(self::BYTE_CALL_PATTERN, $current)) {
                    $sites[$path][] = \sprintf('%s:%d', $path, $line);
                }
            }
        }

        \ksort($sites);

        return $sites;
    }

    /**
     * Walks balanced parentheses so a call split over several lines is judged by
     * its whole argument list, not by one line.
     *
     * @param array<string, string> $contents path => source
     *
     * @return array<string, list<string>> path => list of "path:line" hits
     */
    private static function implicitCharsetSites(array $contents): array
    {
        $sites = [];

        foreach ($contents as $path => $source) {
            $offset = 0;
            $length = \strlen($source);

            while (false !== ($position = \stripos($source, 'mb_strlen', $offset))) {
                $open = \strpos($source, '(', $position);
                if (false === $open) {
                    break;
                }

                $depth = 0;
                $closing = $open;
                for (; $closing < $length; ++$closing) {
                    if ('(' === $source[$closing]) {
                        ++$depth;
                    } elseif (')' === $source[$closing]) {
                        --$depth;
                        if (0 === $depth) {
                            break;
                        }
                    }
                }

                $arguments = \substr($source, $open + 1, $closing - $open - 1);
                // single quotes only: php-cs-fixer's single_quote rule normalizes string literals
                if (!\str_contains($arguments, "'UTF-8'")) {
                    $line = \substr_count(\substr($source, 0, $position), "\n") + 1;
                    $sites[$path][] = \sprintf('%s:%d', $path, $line);
                }

                $offset = $closing + 1;
            }
        }

        \ksort($sites);

        return $sites;
    }

    /**
     * @return array<string, string> project-relative path => source
     */
    private static function sources(): array
    {
        $base = self::projectRoot().'/src';
        $contents = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $path = \str_replace('\\', '/', \substr($file->getPathname(), \strlen(self::projectRoot()) + 1));
            $contents[$path] = (string) \file_get_contents($file->getPathname());
        }

        return $contents;
    }

    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
