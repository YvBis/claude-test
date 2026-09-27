#!/usr/bin/env bash
# fwd-11(c): run bin/console against the TEST database inside the container.
#
# Why this wrapper exists: the app service declares `env_file: - .env`
# (docker-compose.yml), so DATABASE_URL from .env lands in the container's
# REAL environment — and Symfony Dotenv never overrides real variables.
# Therefore .env.test is powerless inside the container, and a bare
# `bin/console --env=test` silently hits the DEV database. This wrapper
# forces the test URL explicitly.
#
# Local-dev tool only: CI provisions its own test-database URL (ci.yml)
# and never goes through docker compose.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_TEST="$ROOT/.env.test"

fail() {
    echo "ERROR: $1" >&2
    exit 1
}

# Local-dev tool only: CI provisions its own test database (ci.yml), so running
# this wrapper under CI would pick the wrong URL source. Self-enforcing.
[ -z "${CI:-}" ] || fail "console-test.sh is a local-dev tool (CI provisions its own test DB)"

[ -f "$ENV_TEST" ] || fail ".env.test not found at $ENV_TEST"
command -v docker >/dev/null 2>&1 || fail "docker is not on PATH"

if [ -z "$(docker compose -f "$ROOT/docker-compose.yml" ps -q app 2>/dev/null)" ]; then
    fail "app container is not running (start it with: docker compose up -d)"
fi

# The `|| true` keeps `set -o pipefail` from exiting here when the key is
# absent, so the actionable error below runs instead of a bare grep failure.
# `tr` strips quoting and CR: a quoted or CRLF .env.test line would otherwise
# smuggle the quote/CR into -e "DATABASE_URL=...".
URL="$( { grep -E '^(export )?DATABASE_URL=' "$ENV_TEST" || true; } | tail -n 1 | sed -E 's/^(export )?DATABASE_URL=//' | tr -d '\r"')"
[ -n "$URL" ] || fail "DATABASE_URL not found in $ENV_TEST"

exec docker compose -f "$ROOT/docker-compose.yml" exec -T -e "DATABASE_URL=$URL" app php bin/console --env=test "$@"
