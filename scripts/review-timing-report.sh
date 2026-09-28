#!/usr/bin/env bash
#
# Timing report for one Code Review workflow run (task 5.20).
#
# Splits the headline "run duration" from `gh run list` into runner queue wait
# and actual job work, and breaks the job into per-step durations. The split
# matters: over 30 runs (2026-09-25…27) the max run was 10.3 min, of which
# 9.6 min was queue wait and 36 s was work — so "slow reviews" was never the
# LLM path (~30 s) but the GitHub-hosted runner queue, plus the ~6 min NVIDIA
# NIM fallback on the rare path where the primary publishes no verdict.
#
# The jobs API already exposes started_at/completed_at per step, so this stays
# a local reporting tool, not CI wiring: nothing here runs in the pipeline.
#
# Usage:
#   bash scripts/review-timing-report.sh <run-id> [owner/repo]
#
# Requires: gh (authenticated), GNU date (Git Bash, or gdate from coreutils).
set -euo pipefail

usage='usage: review-timing-report.sh <run-id> [owner/repo]'
run_id="${1:?${usage}}"
# Default to the checkout's own repository so the hardcoded fallback below is
# only a last resort (e.g. running outside a git checkout).
default_repo="$(gh repo view --json nameWithOwner --jq '.nameWithOwner' 2>/dev/null || printf 'YvBis/claude-test')"
repository="${2:-${default_repo}}"

command -v gh >/dev/null 2>&1 || { echo "ERROR: gh is not on PATH" >&2; exit 1; }
if command -v gdate >/dev/null 2>&1; then
    date_bin='gdate'
elif date -u -d '2026-01-01T00:00:00Z' '+%s' >/dev/null 2>&1; then
    date_bin='date'
else
    echo 'ERROR: GNU date required (Git Bash, or gdate from coreutils)' >&2
    exit 1
fi

epoch() { # ISO-8601 UTC -> seconds, or empty when the field is absent.
    if [ -z "${1:-}" ]; then
        printf ''
    else
        "${date_bin}" -u -d "$1" '+%s' 2>/dev/null || printf ''
    fi
}

dur() { # Seconds between two ISO timestamps, or 'n/a' when either is missing.
    local from to
    from="$(epoch "$1")"
    to="$(epoch "$2")"
    if [ -n "${from}" ] && [ -n "${to}" ]; then
        printf '%s' "$((to - from))"
    else
        printf 'n/a'
    fi
}

run_fields="$(gh api "repos/${repository}/actions/runs/${run_id}" \
    --jq '[.created_at, .run_started_at, .updated_at, .status, .conclusion] | @tsv')"
# The workflow has a single job (`review`, displayed as "OpenRabbit Review"):
# select it by name rather than by position, so a future second job cannot
# silently shift the report onto the wrong job. An empty match means the job
# was renamed or the run has no jobs yet — fail loudly instead of printing
# blank values.
job_selector='.jobs[] | select(.name == "OpenRabbit Review")'
job_fields="$(gh api "repos/${repository}/actions/runs/${run_id}/jobs" \
    --jq "${job_selector} | [.name, .started_at, .completed_at] | @tsv")"
[ -n "${job_fields}" ] || { echo "ERROR: no 'OpenRabbit Review' job in run ${run_id} (${repository})" >&2; exit 1; }
steps_table="$(gh api "repos/${repository}/actions/runs/${run_id}/jobs" \
    --jq "(${job_selector}.steps // [])[] | [.name, (.conclusion // \"n/a\"), (.started_at // \"\"), (.completed_at // \"\")] | @tsv")"

IFS=$'\t' read -r created started updated status conclusion <<< "${run_fields}"
IFS=$'\t' read -r job_name job_start job_end <<< "${job_fields}"

printf 'run %s (%s): %s/%s\n' "${run_id}" "${repository}" "${status}" "${conclusion}"
printf 'queue wait: %s s (created %s -> started %s)\n' \
    "$(dur "${created}" "${started}")" "${created:-n/a}" "${started:-n/a}"
printf 'job %s: %s s\n' "${job_name}" "$(dur "${job_start}" "${job_end}")"
printf 'step | outcome | s\n'
while IFS=$'\t' read -r name outcome step_start step_end; do
    printf '%s | %s | %s\n' "${name}" "${outcome}" "$(dur "${step_start}" "${step_end}")"
done <<< "${steps_table}"
