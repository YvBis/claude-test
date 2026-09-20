<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;

/**
 * Guards the wiring of the Symfony Language Tools checker (symfony-lsp).
 *
 * The checker itself is an external binary, so these tests protect the
 * integration contract that a silent edit could break: the composer entry
 * point, the pinned version with checksum verification, the non-blocking
 * pilot CI job and the project configuration that keeps runtime analysis
 * from failing on the generated config/reference.php file.
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

    public function testCiJobRunsCheckerInSourceOnlyNonBlockingPilot(): void
    {
        $workflow = (string) \file_get_contents($this->projectRoot.'/.github/workflows/ci.yml');
        $job = $this->jobBlock($workflow, 'symfony-diagnostics');

        self::assertStringContainsString('scripts/symfony-lsp-check.sh', $job);
        self::assertStringContainsString(
            '--source-only',
            $job,
            'CI must run the checker with --source-only: the pilot gate must not execute the application.',
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

    /**
     * Extracts a top-level job body from a GitHub Actions workflow so that
     * assertions stay scoped to that job instead of the whole file.
     */
    private function jobBlock(string $workflow, string $job): string
    {
        $pattern = '/^ {2}'.\preg_quote($job, '/').':\n(.*?)(?=^ {2}[a-z][a-z-]*:|\z)/ms';

        self::assertSame(
            1,
            \preg_match($pattern, $workflow, $matches),
            \sprintf('Job "%s" was not found in .github/workflows/ci.yml.', $job),
        );

        return $matches[1];
    }
}
