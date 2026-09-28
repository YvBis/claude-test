<?php

declare(strict_types=1);

/**
 * Symfony Language Tools gate: converts one `--format=json` report into GitHub
 * annotations and fails the job unless the report proves a complete, clean run.
 *
 * A single JSON run is the only source of truth (two runs — one for
 * annotations, one for the verdict — can diverge in index state). The checker's
 * own exit code is not the gate: it is loud today (0 clean, 10 blocking, 12
 * failed section) but its 0.x exit semantics are not a contract, so every
 * condition below is re-derived from the artifact.
 *
 * Fail-closed: a missing file, unparseable JSON, or a drifted schema fails the
 * job with a job-level `::error::` instead of a silent green. In particular an
 * incomplete index (`complete: false`, `runtime.state: stale`, empty
 * diagnostics, `blocking: 0` — measured on 0.23.0 with an aborted section)
 * must fail: gating on `blocking` alone would be green exactly when blind. A
 * parseable-but-truncated report (summary counts disagreeing with the
 * diagnostics list) fails for the same reason.
 *
 * Coordinates in the report are 0-based (`coordinates.lineBase: 0`); GitHub
 * annotations are 1-based, so the converter adds 1 to lines and columns.
 * Annotation text is escaped per the workflow-commands format
 * (`%` → `%25`, `\r` → `%0D`, `\n` → `%0A`).
 *
 * Usage:
 *   php scripts/symfony-lsp-gate.php <report.json>
 */
$file = $argv[1] ?? null;

if (!\is_string($file) || '' === $file) {
    \fwrite(STDERR, "symfony-lsp-gate: usage: php scripts/symfony-lsp-gate.php <report.json>\n");
    exit(1);
}

if (!\is_file($file)) {
    \printf("::error::symfony-lsp gate: report not found: %s\n", $file);
    exit(1);
}

try {
    $raw = \file_get_contents($file);
    if (false === $raw) {
        throw new RuntimeException('cannot read report');
    }
    $report = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    \printf("::error::symfony-lsp gate: report unreadable (%s): %s\n", $e::class, $e->getMessage());
    exit(1);
}

if (!\is_array($report)) {
    \printf("::error::symfony-lsp gate: report is not a JSON object\n");
    exit(1);
}

$failures = [];

$fail = static function (string $reason) use (&$failures): void {
    $failures[] = $reason;
    \printf("::error::symfony-lsp gate: %s\n", \escape((string) $reason));
};

if (true !== ($report['complete'] ?? null)) {
    $fail('report is not complete (complete !== true): the index may be incomplete and the diagnostic list empty');
}

$projects = $report['projects'] ?? null;
if (!\is_array($projects) || [] === $projects) {
    $fail('report carries no projects: schema drift or empty run');
} else {
    foreach ($projects as $project) {
        if (!\is_array($project)) {
            $fail('a project entry is not an object: schema drift');
            continue;
        }
        $id = $project['id'] ?? '?';
        if (true !== ($project['complete'] ?? null)) {
            $fail(\sprintf('project "%s" is not complete', (string) $id));
        }
        $state = $project['runtime']['state'] ?? null;
        if ('ready' !== $state) {
            $fail(\sprintf('project "%s" runtime.state is "%s", expected "ready"', (string) $id, (string) $state));
        }
    }
}

$blocking = $report['summary']['blocking'] ?? null;
if (!\is_int($blocking)) {
    $fail('report carries no summary.blocking integer: schema drift');
} elseif ($blocking > 0) {
    $fail(\sprintf('%d blocking diagnostic(s) reported', $blocking));
}

$diagnostics = $report['diagnostics'] ?? null;
if (!\is_array($diagnostics)) {
    $fail('report diagnostics are not a list: schema drift');
    $diagnostics = [];
} else {
    $declared = $report['summary']['diagnostics'] ?? null;
    if (!\is_int($declared) || $declared !== \count($diagnostics)) {
        $fail('summary.diagnostics disagrees with the diagnostics list length: truncated or drifted report');
    }
    foreach ($diagnostics as $diagnostic) {
        if (!\is_array($diagnostic)) {
            continue;
        }
        $command = 'error' === ($diagnostic['severity'] ?? null) ? 'error' : 'warning';
        $path = (string) ($diagnostic['path'] ?? '');
        $start = $diagnostic['range']['start'] ?? [];
        $end = $diagnostic['range']['end'] ?? [];
        $line = (int) ($start['line'] ?? 0) + 1;
        $col = (int) ($start['character'] ?? 0) + 1;
        $endLine = (int) ($end['line'] ?? 0) + 1;
        $endCol = (int) ($end['character'] ?? 0) + 1;
        $title = (string) ($diagnostic['code'] ?? 'symfony-lsp');
        $message = (string) ($diagnostic['message'] ?? '');
        \printf(
            "::%s file=%s,line=%d,col=%d,endLine=%d,endColumn=%d,title=%s::%s\n",
            $command,
            \escape($path),
            $line,
            $col,
            $endLine,
            $endCol,
            \escape($title),
            \escape($message)
        );
    }
}

if ([] !== $failures) {
    exit(1);
}

\printf(
    "symfony-lsp gate: complete report, %d diagnostic(s), %d blocking — clean.\n",
    \count($diagnostics),
    (int) $blocking
);
exit(0);

function escape(string $value): string
{
    return \str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
}
