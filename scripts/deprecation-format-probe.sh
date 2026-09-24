#!/usr/bin/env bash
# Probe for task 5.31: verifies the deprecation-line format PHPUnit prints
# still matches the pattern the CI step greps for.
#
# It extracts the pattern from the suite step's own `grep -qE '<pattern>'`
# (so the probe checks what the pipeline relies on, not a handwritten copy),
# then runs PHPUnit detached on two committed fixtures: the one with a
# deliberate E_USER_DEPRECATED must produce the line, the clean one must not.
#
# Both fixtures live outside every testsuite and carry no `Test` suffix, and
# each child runs --no-configuration with an explicit file path, so a child
# can never recurse into the main suite. This script runs as its own CI step
# (not inside the suite): one child boot costs ~6 s, and two of them inside
# every local `composer ci:all` was over the 5 s budget the probe was given.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

MATCHES="$(grep -oE "grep -qE '[^']+'" "$ROOT/.github/workflows/ci.yml" || true)"
COUNT="$(printf '%s\n' "$MATCHES" | grep -c . || true)"
if [ "$COUNT" -ne 1 ]; then
  echo "probe: expected exactly one grep -qE '<pattern>' in .github/workflows/ci.yml, found $COUNT" >&2
  exit 1
fi
PATTERN="${MATCHES#grep -qE \'}"
PATTERN="${PATTERN%\'}"
if [ -z "${PATTERN:-}" ]; then
  echo "probe: extracted an empty pattern from .github/workflows/ci.yml" >&2
  exit 1
fi
echo "probe: pattern is '$PATTERN'"

run_fixture() {
  XDEBUG_MODE=off php "$ROOT/bin/phpunit" --no-configuration --colors=never --display-deprecations "$ROOT/tests/Fixtures/Deprecation/$1" 2>&1
}

if ! TRIGGER_OUT="$(run_fixture DeprecationTriggerFixture.php)"; then
  echo "probe: child PHPUnit run on the triggering fixture failed - --display-deprecations must stay display-only, a non-zero exit means PHPUnit turned deprecations into a gate. Child output was:" >&2
  printf '%s\n' "$TRIGGER_OUT" >&2
  exit 1
fi
if ! printf '%s\n' "$TRIGGER_OUT" | grep -qE -- "$PATTERN"; then
  echo "probe: triggering fixture did NOT produce the line - PHPUnit reworded its deprecation summary and the CI warning is blind. Child output was:" >&2
  printf '%s\n' "$TRIGGER_OUT" >&2
  exit 1
fi
echo "probe: triggering fixture matches"

if ! CLEAN_OUT="$(run_fixture DeprecationCleanFixture.php)"; then
  echo "probe: child PHPUnit run on the clean fixture failed. Child output was:" >&2
  printf '%s\n' "$CLEAN_OUT" >&2
  exit 1
fi
if printf '%s\n' "$CLEAN_OUT" | grep -qE -- "$PATTERN"; then
  echo "probe: clean fixture matched - the workflow pattern is too loose and would warn on every run. Child output was:" >&2
  printf '%s\n' "$CLEAN_OUT" >&2
  exit 1
fi
echo "probe: clean fixture does not match"
echo "probe: OK"
