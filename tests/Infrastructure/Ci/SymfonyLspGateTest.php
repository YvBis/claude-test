<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Guards the symfony-lsp gate converter (task 5.14, PR-A).
 *
 * `scripts/symfony-lsp-gate.php` is the blocking mechanism: one `--format=json`
 * run is the single source of truth, and the converter turns it into GitHub
 * annotations plus a pass/fail verdict. The checker's own exit code is not the
 * gate (its 0.x exit semantics are not a contract), so every condition is
 * re-derived from the artifact — and these tests pin that contract against
 * committed fixtures in `tests/Fixtures/SymfonyLsp/` (shapes measured on
 * 0.23.0, see PRD/5.14-blocking-diagnostics.md).
 *
 * The converter runs as a child PHP process: it is a standalone script with no
 * framework dependency, and a child run costs milliseconds, so unlike the
 * deprecation-format probe (5.31) it fits inside the suite budget.
 *
 * Fail-closed by design: the incomplete-index fixture carries `blocking: 0`
 * and an empty diagnostic list — gating on `blocking` alone would be green
 * exactly when blind, so the converter must fail on `complete`/`runtime.state`.
 */
final class SymfonyLspGateTest extends TestCase
{
    private string $projectRoot;
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = \dirname(__DIR__, 3);
        $this->script = $this->projectRoot.'/scripts/symfony-lsp-gate.php';
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
            \escapeshellarg($this->projectRoot.'/tests/Fixtures/SymfonyLsp/'.$fixture)
        );
        $output = [];
        $exit = 0;
        \exec($command, $output, $exit);

        return [$exit, \implode("\n", $output)];
    }

    public function testCleanReportPasses(): void
    {
        [$exit, $output] = $this->runGate('gate-clean.json');

        self::assertSame(0, $exit, 'A complete, clean report must pass the gate.');
        self::assertStringContainsString('clean.', $output);
    }

    public function testDiagnosticsFailWithFileAnnotation(): void
    {
        [$exit, $output] = $this->runGate('gate-diagnostics.json');

        self::assertSame(1, $exit, 'A report with a blocking diagnostic must fail the gate.');
        self::assertStringContainsString(
            '::error file=templates/gate_probe_514.html.twig,line=2,col=10,',
            $output,
            'The converter must emit a 1-based file annotation (JSON coordinates are 0-based).'
        );
        self::assertStringContainsString('1 blocking diagnostic(s) reported', $output);
    }

    public function testIncompleteIndexFailsDespiteZeroBlocking(): void
    {
        [$exit, $output] = $this->runGate('gate-incomplete.json');

        self::assertSame(1, $exit, 'An incomplete index must fail even with blocking: 0 and no diagnostics.');
        self::assertStringContainsString('not complete', $output);
        self::assertStringContainsString('runtime.state is "stale"', $output);
        self::assertStringNotContainsString('::error file=', $output, 'No diagnostics exist to annotate.');
    }

    public function testMissingReportFailsLoudly(): void
    {
        $command = \sprintf(
            '%s %s %s 2>&1',
            \escapeshellarg(PHP_BINARY),
            \escapeshellarg($this->script),
            \escapeshellarg($this->projectRoot.'/tests/Fixtures/SymfonyLsp/does-not-exist.json')
        );
        $output = [];
        $exit = 0;
        \exec($command, $output, $exit);

        self::assertSame(1, $exit, 'A missing report must fail loudly, never silently green.');
        self::assertStringContainsString('::error::symfony-lsp gate: report not found', \implode("\n", $output));
    }

    public function testTruncatedReportFailsDespiteCleanFlags(): void
    {
        [$exit, $output] = $this->runGate('gate-truncated.json');

        self::assertSame(1, $exit, 'A parseable report whose counts disagree with its diagnostics list must fail.');
        self::assertStringContainsString('disagrees with the diagnostics list length', $output);
    }

    public function testInvalidJsonFailsLoudly(): void
    {
        [$exit, $output] = $this->runGate('gate-invalid.json');

        self::assertSame(1, $exit, 'An unparseable report must fail loudly, never silently green.');
        self::assertStringContainsString('::error::symfony-lsp gate: report unreadable', $output);
    }
}
