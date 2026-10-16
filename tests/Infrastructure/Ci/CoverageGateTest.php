<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Guards the coverage gate script (task 8.3).
 *
 * `scripts/coverage-gate.php` enforces two levels over a clover.xml report: the
 * project totals plus a per-layer minimum for each of `src/Domain/` and
 * `src/Application/` (phpunit.xml.dist `<source><include>` covers exactly those
 * two, so Infrastructure never appears). The clover files nest under `<package>`
 * with a few direct `<file>` children and absolute container paths — the script
 * must aggregate both shapes by `/src/<Layer>/` substring, and these tests pin
 * that contract against committed fixtures in `tests/Fixtures/Coverage/`.
 *
 * The script runs as a child PHP process: standalone, no framework, milliseconds —
 * the same convention as SymfonyLspGateTest. The report path is argv[1], so the
 * fixtures never touch the real `var/coverage/clover.xml`.
 *
 * Fail-closed by design: a green project total must not mask a red layer
 * (`gate-layer-fail.xml` is 88% project-wide but 70% Application), and a missing
 * report is a failure, never a silent pass.
 */
final class CoverageGateTest extends TestCase
{
    private string $projectRoot;
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->script = $this->projectRoot.'/scripts/coverage-gate.php';
    }

    /**
     * @return array{int, string}
     */
    private function runGate(string $fixture): array
    {
        $command = \sprintf(
            '%s %s %s 2>&1',
            \escapeshellarg(PHP_BINARY),
            \escapeshellarg($this->script),
            \escapeshellarg($this->projectRoot.'/tests/Fixtures/Coverage/'.$fixture)
        );
        $output = [];
        $exit = 0;
        \exec($command, $output, $exit);

        return [$exit, \implode("\n", $output)];
    }

    public function testPassingReportPassesBothLevels(): void
    {
        [$exit, $output] = $this->runGate('gate-pass.xml');

        self::assertSame(0, $exit, 'A report green on both levels must pass the gate.');
        // Exact percentages pin BOTH aggregation shapes: Domain is 9/10 (packaged)
        // + 5/5 (direct <file> child) = 14/15. Break the direct-child loop and this
        // becomes 90.00% (9/10) — the test goes red.
        self::assertStringContainsString('Layer Domain: 93.33% (14/15)', $output);
        self::assertStringContainsString('Layer Application: 100.00% (10/10)', $output);
        self::assertStringContainsString('coverage-gate: passed', $output);
    }

    public function testGreenProjectWithRedLayerFails(): void
    {
        [$exit, $output] = $this->runGate('gate-layer-fail.xml');

        self::assertSame(1, $exit, 'A green project total must not mask a red layer.');
        self::assertStringContainsString('layer Application', $output);
    }

    public function testReportWithoutALayerFailsClosed(): void
    {
        [$exit, $output] = $this->runGate('gate-no-domain.xml');

        self::assertSame(1, $exit, 'A layer with no statements recorded must fail the gate (source-drift guard).');
        self::assertStringContainsString('no statements recorded for layer Domain', $output);
    }

    public function testMissingReportFailsClosed(): void
    {
        [$exit, $output] = $this->runGate('gate-does-not-exist.xml');

        self::assertSame(1, $exit, 'A missing report is a gate failure, never a silent pass.');
        self::assertStringContainsString('not found', $output);
    }

    public function testRaisedMinimumFailsAPassingReport(): void
    {
        // putenv (not a POSIX `VAR=val` command prefix) so the child inherits it
        // on Windows hosts too; restored afterwards to not leak into other tests.
        \putenv('COVERAGE_MIN=100');
        try {
            [$exit, $output] = $this->runGate('gate-pass.xml');
        } finally {
            \putenv('COVERAGE_MIN');
        }

        self::assertSame(1, $exit, 'COVERAGE_MIN=100 must fail a 96% report.');
        self::assertStringContainsString('below the minimum', $output);
    }
}
