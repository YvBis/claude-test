<?php

declare(strict_types=1);

/**
 * Coverage gate: fails with exit code 1 when line coverage is below the minimum.
 *
 * Reads a clover.xml report (written by phpunit --coverage-clover) and enforces
 * TWO levels (task 8.3):
 *   1. project level — the `<project><metrics>` totals, as before;
 *   2. layer level — per-file metrics aggregated by path prefix for each of
 *      `src/Domain/` and `src/Application/` (phpunit.xml.dist `<source><include>`
 *      covers exactly those two layers, so Infrastructure never appears here).
 *
 * Expected clover schema:
 *
 *     <coverage><project><metrics statements="356" coveredstatements="353" .../>
 *       <package><file name="/var/www/html/src/Domain/..."><metrics .../>...
 *
 * Files nest under `<package>` elements (plus a few direct `<file>` children),
 * and names are absolute container paths — matching is by `/src/<Layer>/`
 * substring, never by position. The <project>/<metrics> and per-file shapes may
 * shift across php-code-coverage majors, so absence is a gate failure with a
 * clear message — never a PHP warning.
 *
 * COVERAGE_MIN is read from the environment and defaults to 80. The report path
 * is argv[1] when given (the unit test feeds it committed fixtures), otherwise
 * var/coverage/clover.xml.
 */
$file = $argv[1] ?? __DIR__.'/../var/coverage/clover.xml';

if (!\is_file($file)) {
    \fwrite(STDERR, "coverage-gate: $file not found — run tests with coverage first.\n");
    exit(1);
}

$xml = @\simplexml_load_file($file);
if (false === $xml) {
    \fwrite(STDERR, "coverage-gate: cannot parse $file.\n");
    exit(1);
}

$project = $xml->project ?? null;
$metrics = $project?->metrics ?? null;
if (null === $metrics || !isset($metrics['statements'], $metrics['coveredstatements'])) {
    \fwrite(STDERR, "coverage-gate: no project metrics in $file (expected <coverage><project><metrics …/>).\n");
    exit(1);
}

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

// Layer level (task 8.3): aggregate per-file metrics by path prefix. Files nest
// under <package> with a few direct <file> children; names are absolute, so the
// match is a substring, never an index.
$layers = ['Domain' => '/src/Domain/', 'Application' => '/src/Application/'];
$totals = [];
foreach (\array_keys($layers) as $layer) {
    $totals[$layer] = [0, 0];
}

$collect = static function (SimpleXMLElement $file) use ($layers, &$totals): void {
    $metrics = $file->metrics ?? null;
    if (null === $metrics || !isset($metrics['statements'], $metrics['coveredstatements'])) {
        return;
    }
    // Clover paths use forward slashes, but a report generated on a Windows host
    // would carry backslashes — normalize so the layer match is separator-blind.
    $name = \str_replace('\\', '/', (string) ($file['name'] ?? ''));
    foreach ($layers as $layer => $needle) {
        if (\str_contains($name, $needle)) {
            $totals[$layer][0] += (int) $metrics['statements'];
            $totals[$layer][1] += (int) $metrics['coveredstatements'];
        }
    }
};

foreach ($xml->project->file ?? [] as $file) {
    $collect($file);
}
foreach ($xml->project->package ?? [] as $package) {
    foreach ($package->file ?? [] as $file) {
        $collect($file);
    }
}

$failed = false;
foreach ($totals as $layer => [$layerStatements, $layerCovered]) {
    if (0 === $layerStatements) {
        \fwrite(STDERR, \sprintf("coverage-gate: no statements recorded for layer %s.\n", $layer));
        $failed = true;
        continue;
    }
    $layerCoverage = $layerCovered / $layerStatements * 100.0;
    \printf("Layer %s: %.2f%% (%d/%d), gate >= %.2f%%\n", $layer, $layerCoverage, $layerCovered, $layerStatements, $min);
    if ($layerCoverage < $min) {
        \fwrite(STDERR, \sprintf("coverage-gate: layer %s coverage %.2f%% is below the minimum %.2f%%\n", $layer, $layerCoverage, $min));
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

echo "coverage-gate: passed\n";
