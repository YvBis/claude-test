<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Guards the wiring of the Symfony Language Tools checker (symfony-lsp).
 *
 * The checker itself is an external binary, so these tests protect the
 * integration contract that a silent edit could break: the composer entry
 * point, the pinned version with checksum verification, the download-retry
 * policy, the non-blocking pilot CI job (runtime analysis only since the 5.17
 * parity probe) and the project configuration that keeps runtime analysis from
 * failing on the generated config/reference.php file.
 */
final class SymfonyLspConfigTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = \dirname(__DIR__, 3);
    }

    public function testComposerExposesSymfonyLspScript(): void
    {
        /** @var array{scripts: array<string, mixed>} $composer */
        $composer = \json_decode(
            (string) \file_get_contents($this->projectRoot.'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('ci:symfony-lsp', $composer['scripts']);
        self::assertStringContainsString(
            'scripts/symfony-lsp-check.sh',
            (string) $composer['scripts']['ci:symfony-lsp'],
            'The composer script must delegate to the shared runner script so CI and local runs stay identical.',
        );
    }

    public function testCiJobRunsRuntimeCheckAsNonBlockingPilot(): void
    {
        $workflow = (string) \file_get_contents($this->projectRoot.'/.github/workflows/ci.yml');
        $job = $this->jobBlock($workflow, 'symfony-diagnostics');

        self::assertSame(
            1,
            \preg_match_all('/^\s*run:.*scripts\/symfony-lsp-check\.sh/m', $job),
            'CI must run the checker once: the 5.17 parity probe showed the source-only pass reports nothing on symfony-lsp 0.21.x.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/^\s*run:.*--source-only/m',
            $job,
            'No executed command may pass --source-only with symfony-lsp 0.21.x: the 5.17 probe showed it reports no diagnostics at all on this project. '
            .'A checker bump must re-run the probe (task 5.26) before this can change - see PRD/5.17-parity-probe.md.',
        );
        self::assertMatchesRegularExpression(
            '/scripts\/symfony-lsp-check\.sh --environment=test --format=github/',
            $job,
            'The single invocation must pin --environment=test: the checker defaults to "dev" and ignores .env, and dev needs a Redis service.',
        );
        self::assertStringContainsString(
            'continue-on-error: true',
            $job,
            'The symfony-lsp job is a pilot: it must stay non-blocking (continue-on-error: true).',
        );
        self::assertStringContainsString(
            'SYMFONY_LSP_VERSION',
            $job,
            'The job must pin the checker version: the index cache key must not serve foreign-version trees.',
        );
        self::assertStringContainsString(
            'SYMFONY_LSP_BIN_DIR',
            $job,
            'The binary must live inside the cached directory, otherwise every run re-downloads the release.',
        );
        self::assertStringNotContainsString(
            'symfony-diagnostics',
            $this->jobBlock($workflow, 'ci-summary'),
            'ci-summary must not depend on the pilot job, otherwise the checker would block merges.',
        );
    }

    public function testRunnerScriptPinsVersionAndVerifiesChecksum(): void
    {
        $script = (string) \file_get_contents($this->projectRoot.'/scripts/symfony-lsp-check.sh');

        self::assertMatchesRegularExpression(
            '/VERSION="\$\{SYMFONY_LSP_VERSION:-\d+\.\d+\.\d+\}"/',
            $script,
            'The runner must install a pinned, overridable symfony-lsp version.',
        );
        self::assertStringContainsString('SHA256SUMS', $script);
        self::assertStringContainsString(
            'sha256sum -c -',
            $script,
            'Downloads must be verified against the release checksum before execution.',
        );
        self::assertGreaterThanOrEqual(
            3,
            \substr_count($script, 'verify_archive'),
            'The cached archive must be checksum-verified before extraction, exactly like a fresh download.',
        );
        self::assertStringContainsString(
            'SHA256SUMS.${VERSION}',
            $script,
            'The checksum list must be per-version, so a bump can never verify an archive against a foreign list.',
        );
        self::assertStringNotContainsString(
            'if [ ! -x "${BIN}" ]',
            $script,
            'The binary must always be extracted from an archive verified in this run: a cache-restored binary was never checksum-verified.',
        );
    }

    public function testRunnerScriptRetriesTransientDownloadFailures(): void
    {
        $script = (string) \file_get_contents($this->projectRoot.'/scripts/symfony-lsp-check.sh');

        self::assertStringContainsString(
            '--retry-all-errors',
            $script,
            'A single transient 504 while fetching the release must not fail the job: downloads need retries.',
        );
        self::assertStringContainsString(
            '--connect-timeout',
            $script,
            'Retries bound the number of attempts, not the duration of a stalled connection.',
        );
        self::assertStringContainsString(
            '--max-time',
            $script,
            'A stalled download must be cut off instead of eating the whole job budget.',
        );
    }

    public function testProjectConfigExcludesGeneratedReferenceFile(): void
    {
        /** @var array{version: int, excludePaths: list<string>} $config */
        $config = \json_decode(
            (string) \file_get_contents($this->projectRoot.'/.symfony-lsp.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(1, $config['version']);
        self::assertContains(
            'config/reference.php',
            $config['excludePaths'],
            'Runtime analysis exits with status 12 when the generated config/reference.php changes mid-check; '
            .'the file must stay excluded.',
        );
    }

    public function testRunnerScriptFailsLoudlyWithoutNetworkAndCachedChecksums(): void
    {
        $binDir = \sys_get_temp_dir().'/symfony-lsp-'.\bin2hex(\random_bytes(6));

        $command = \sprintf(
            'SYMFONY_LSP_BASE_URL=http://127.0.0.1:1 SYMFONY_LSP_BIN_DIR=%s SYMFONY_LSP_VERSION=9.9.9 bash %s --source-only 2>&1',
            \escapeshellarg($binDir),
            \escapeshellarg($this->projectRoot.'/scripts/symfony-lsp-check.sh'),
        );

        \exec($command, $output, $exitCode);

        self::assertSame(
            11,
            $exitCode,
            'With no reachable release host and no cached checksum list the runner must fail loudly instead of continuing.',
        );
        self::assertStringContainsString('cannot obtain SHA256SUMS', \implode("\n", $output));
        self::assertFileDoesNotExist(
            $binDir.'/symfony-lsp',
            'Nothing may be installed or executed when the checksum list cannot be obtained.',
        );

        @\unlink($binDir.'/SHA256SUMS.9.9.9');
        @\rmdir($binDir);
    }

    /**
     * Extracts a top-level job body from a GitHub Actions workflow so that
     * assertions stay scoped to that job instead of the whole file.
     */
    private function jobBlock(string $workflow, string $job): string
    {
        // Normalize line endings: Windows checkouts use CRLF, CI uses LF.
        $workflow = \str_replace("\r\n", "\n", $workflow);
        $pattern = '/^ {2}'.\preg_quote($job, '/').':\n(.*?)(?=^ {2}[a-z][a-z-]*:|\z)/ms';

        self::assertSame(
            1,
            \preg_match($pattern, $workflow, $matches),
            \sprintf('Job "%s" was not found in .github/workflows/ci.yml.', $job),
        );

        return $matches[1];
    }
}
