#!/usr/bin/env bash
# fwd-11(b): blocking freshness check for the tracked OpenAPI dump.
#
# Regenerates the spec into a temp file (never touches the working tree) and
# byte-compares it with the committed public/api/openapi.json. Byte comparison
# is valid because the dump is deterministic (proven 2026-09-27: two consecutive
# dumps are byte-identical); any difference means the committed file is stale.
#
# Flags and environment must stay identical to the `openapi:generate` composer
# script (composer.json). `--env=dev` is load-bearing, not cosmetic: all
# component schemas live in config/packages/dev/api_doc.yaml, and CI boots
# with APP_ENV=test (setup-ci copies .env.test over .env) — without the flag
# every $ref is unresolvable and the dump fails. On mismatch the message tells
# exactly what to run.
#
# The guards below close the fwd-11 trap: a dump that fails while still
# writing JSON used to leave exit code 0 and an empty-ish file, so a plain
# `cmp` could report "fresh" for a broken spec. A failing dump must be
# indistinguishable from a passing one for the gate to mean anything.
#
# Deprecations are the one stderr class ignored: they are tracked separately
# and advisory (PHPUnit's `--display-deprecations` step emits `::warning::`),
# and letting one turn this gate red would couple contract freshness to an
# unrelated concern. Everything else on stderr is fatal.
set -euo pipefail

SPEC="public/api/openapi.json"
TMP="$(mktemp)"
ERR="$(mktemp)"

cleanup() {
    rm -f "$TMP" "$ERR"
}
trap cleanup EXIT

if [ ! -f "$SPEC" ]; then
    echo "ERROR: $SPEC is missing. Run: composer openapi:generate" >&2
    exit 1
fi

php bin/console nelmio:apidoc:dump --env=dev --format=json --no-pretty >"$TMP" 2>"$ERR" || {
    echo "ERROR: the OpenAPI dump failed (see below); $SPEC was not regenerated." >&2
    sed -n '1,10p' "$ERR" >&2
    exit 1
}

if [ -n "$(grep -vE 'Deprecated:|deprecat' "$ERR")" ]; then
    echo "ERROR: the OpenAPI dump wrote to stderr; treating it as a failure." >&2
    sed -n '1,10p' "$ERR" >&2
    exit 1
fi

if [ ! -s "$TMP" ]; then
    echo "ERROR: the OpenAPI dump produced an empty file." >&2
    exit 1
fi

if cmp -s "$TMP" "$SPEC"; then
    echo "openapi.json is fresh"
    exit 0
fi

echo "ERROR: $SPEC is stale (differs from a fresh dump)." >&2
echo "Run: composer openapi:generate" >&2
exit 1
