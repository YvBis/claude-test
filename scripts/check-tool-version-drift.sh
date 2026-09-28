#!/usr/bin/env bash
# Version-drift check for the symfony-lsp checker binary (Roadmap 5.14, PR-B).
#
# The checker version is pinned in two places, and the effective CI version is
# the ci.yml one (its env overrides the runner default):
#   1. scripts/symfony-lsp-check.sh — VERSION default ("${SYMFONY_LSP_VERSION:-X.Y.Z}")
#   2. .github/workflows/ci.yml — SYMFONY_LSP_VERSION env ('X.Y.Z')
# This script reads BOTH (the producers, never the guard test's regex — the
# test would go blind exactly when it drifts from the producers), warns when
# the anchors disagree, and warns when the effective pin lags the newest
# non-draft/non-prerelease upstream release.
#
# Advisory-only by design: an upstream release cadence is not a contract, so a
# drift must never fail a job or block a merge. The script always exits 0. An
# unreachable API is a third, distinct outcome ("not checked", not "stale"):
# with `set -euo pipefail` that guarantee needs the EXIT trap below plus
# `|| true` on every fallible substitution — without the trap the first failed
# gh/jq/grep would break the advisory contract.
#
# Proof hook: DRIFT_PINNED_VERSION, when set, replaces the extracted pin so the
# stale branch is provable without committing a stale pin. DRIFT_REPO, when
# set, replaces the upstream repository so the unreachable-API branch is
# provable offline. Both are proof-only: the CI workflow must never set either
# (the guard test scans the workflow file for both literals).
#
# Bash 3.2 compatible (stock macOS): no associative arrays.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNNER="$ROOT/scripts/symfony-lsp-check.sh"
WORKFLOW="$ROOT/.github/workflows/ci.yml"
UPSTREAM_REPO="${DRIFT_REPO:-symfony/language-tools}"

# The advisory contract outranks any internal error: any non-zero status is
# reported as "not checked" and swallowed. The trap also covers the `set -e`
# exits below, which is the point of having it.
on_exit() {
    local status=$?
    if [ "${status}" -ne 0 ]; then
        printf '::warning::drift: the symfony-lsp version check aborted with status %s; the pin is not checked (neither fresh nor stale).\n' "${status}" || true
        summarize "drift: the symfony-lsp version check aborted; the pin is not checked."
    fi
    exit 0
}
trap on_exit EXIT

# Advisory visibility beyond the log: scheduled-run logs are rarely opened, so
# every outcome also lands in the run summary (free, no new permissions). Local
# runs have no GITHUB_STEP_SUMMARY and skip this silently.
summarize() {
    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        printf '%s\n' "$1" >> "$GITHUB_STEP_SUMMARY" || true
    fi
}

if [ ! -f "$RUNNER" ]; then
    printf '::warning::drift: runner script not found at %s; the pin is not checked.\n' "$RUNNER" || true
    summarize "drift: runner script not found; the pin is not checked."
    exit 0
fi
if [ ! -f "$WORKFLOW" ]; then
    printf '::warning::drift: workflow not found at %s; the pin is not checked.\n' "$WORKFLOW" || true
    summarize "drift: CI workflow not found; the pin is not checked."
    exit 0
fi

# Anchor 1: the runner default. Exactly one VERSION default line is expected.
runner_matches="$(grep -oE 'VERSION="\$\{SYMFONY_LSP_VERSION:-[^}]+\}' "$RUNNER" || true)"
runner_count="$(printf '%s\n' "$runner_matches" | grep -c . || true)"
if [ "$runner_count" -ne 1 ]; then
    printf '::warning::drift: expected exactly one SYMFONY_LSP_VERSION default in scripts/symfony-lsp-check.sh, found %s; the pin is not checked.\n' "$runner_count" || true
    summarize "drift: runner anchor unreadable; the pin is not checked."
    exit 0
fi
runner_version="${runner_matches##*\:-}"
runner_version="${runner_version%\}}"

# Anchor 2: the ci.yml env literal (exactly one; the ${{ env.* }} references
# in the cache key carry no literal and must not match).
ci_matches="$(grep -oE "SYMFONY_LSP_VERSION: '[^']+'" "$WORKFLOW" || true)"
ci_count="$(printf '%s\n' "$ci_matches" | grep -c . || true)"
if [ "$ci_count" -ne 1 ]; then
    printf '::warning::drift: expected exactly one SYMFONY_LSP_VERSION literal in .github/workflows/ci.yml, found %s; the pin is not checked.\n' "$ci_count" || true
    summarize "drift: ci.yml anchor unreadable; the pin is not checked."
    exit 0
fi
ci_version="${ci_matches##*SYMFONY_LSP_VERSION: \'}"
ci_version="${ci_version%\'}"

anchors_agree='yes'
if [ "$runner_version" != "$ci_version" ]; then
    anchors_agree='no'
    printf '::warning::drift: SYMFONY_LSP_VERSION anchors disagree — runner default %s vs ci.yml env %s. The effective CI version is the ci.yml one; align the runner default.\n' "$runner_version" "$ci_version" || true
    summarize "drift: anchors disagree (runner $runner_version vs ci.yml $ci_version)."
fi

# The effective pin is the ci.yml env (it overrides the runner default), unless
# the proof hook replaces it.
pinned="${DRIFT_PINNED_VERSION:-$ci_version}"

latest_json="$(gh api "repos/${UPSTREAM_REPO}/releases/latest" || true)"
tag="$(printf '%s' "$latest_json" | jq -r '.tag_name // empty' || true)"
if [ -z "${tag:-}" ]; then
    printf '::warning::drift: could not read the latest %s release; the pin %s is not checked (neither fresh nor stale).\n' "$UPSTREAM_REPO" "$pinned" || true
    summarize "drift: upstream release unreadable; the pin $pinned is not checked."
    exit 0
fi
# Tags are v-prefixed (v0.23.0), the pin is bare (0.23.0).
latest="${tag#v}"
digest="$(printf '%s' "$latest_json" | jq -r '.assets[] | select(.name | endswith("linux-x64.tar.gz")) | .digest // empty' || true)"
# Upstream may one day ship a second matching tarball: keep the warning on one
# line by taking the first digest only.
digest="$(printf '%s' "$digest" | head -n 1)"
digest="${digest#sha256:}"

if [ "$pinned" = "$latest" ]; then
    if [ "$anchors_agree" = 'yes' ]; then
        printf 'drift: symfony-lsp pin %s matches the latest upstream release; anchors agree.\n' "$pinned"
        summarize "drift: symfony-lsp pin $pinned matches the latest upstream release."
    else
        printf 'drift: symfony-lsp pin %s matches the latest upstream release, but the anchors still disagree — align them.\n' "$pinned"
        summarize "drift: symfony-lsp pin $pinned matches upstream, but the anchors still disagree."
    fi
    exit 0
fi

printf '::warning::drift: symfony-lsp pin %s lags the latest upstream release %s. Bump recipe: set SYMFONY_LSP_VERSION to %s in .github/workflows/ci.yml and scripts/symfony-lsp-check.sh, add the measured archive hash to pinned_sha256() in the runner script (upstream asset digest: %s).\n' \
    "$pinned" "$latest" "$latest" "${digest:-unknown}" || true
summarize "drift: symfony-lsp pin $pinned lags the latest upstream release $latest."
exit 0
