<?php

declare(strict_types=1);

/**
 * Coverage gate: fails with exit code 1 when line coverage is below the minimum.
 *
 * Reads var/coverage/clover.xml (written by phpunit --coverage-clover) and parses
 * the project-level metrics. Expected clover schema (stable across php-code-coverage):
 *
 *     <coverage><project><metrics statements="356" coveredstatements="353" .../></project></coverage>
 *
 * COVERAGE_MIN is read from the environment and defaults to 80.
 */
$file = __DIR__.'/../var/coverage/clover.xml';

if (!\is_file($file)) {
    \fwrite(STDERR, "coverage-gate: $file not found — run tests with coverage first.\n");
    exit(1);
}

$xml = @\simplexml_load_file($file);
if (false === $xml) {
    \fwrite(STDERR, "coverage-gate: cannot parse $file.\n");
    exit(1);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];

if (0 === $statements) {
    \fwrite(STDERR, "coverage-gate: no statements recorded in $file.\n");
    exit(1);
}

$coverage = $covered / $statements * 100.0;
$min = (float) (\getenv('COVERAGE_MIN') ?: 80);

\printf("Line coverage: %.2f%% (%d/%d), gate >= %.2f%%\n", $coverage, $covered, $statements, $min);

if ($coverage < $min) {
    \fwrite(STDERR, \sprintf("coverage-gate: line coverage %.2f%% is below the minimum %.2f%%\n", $coverage, $min));
    exit(1);
}

echo "coverage-gate: passed\n";
