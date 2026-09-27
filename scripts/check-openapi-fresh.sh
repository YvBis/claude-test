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
set -euo pipefail

SPEC="public/api/openapi.json"
TMP="$(mktemp)"

cleanup() {
    rm -f "$TMP"
}
trap cleanup EXIT

php bin/console nelmio:apidoc:dump --env=dev --format=json --no-pretty > "$TMP"

if cmp -s "$TMP" "$SPEC"; then
    echo "openapi.json is fresh"
    exit 0
fi

echo "ERROR: $SPEC is stale (differs from a fresh dump)." >&2
echo "Run: composer openapi:generate" >&2
exit 1
