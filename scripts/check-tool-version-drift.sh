#!/usr/bin/env bash
# Version-drift checks for pins that no dependency manager can see.
#
# Two checks, one advisory surface (Roadmap 5.14 PR-B, review-9):
#
# 1. symfony-lsp checker binary. Pinned in two producers, effective CI version
#    is the ci.yml one (its env overrides the runner default):
#      - scripts/symfony-lsp-check.sh — VERSION default
#      - .github/workflows/ci.yml — SYMFONY_LSP_VERSION env
#    Warns when the anchors disagree and when the effective pin lags the newest
#    non-draft/non-prerelease upstream release.
#
# 2. Meilisearch Docker image. Pinned by MINOR tag (ADR/0001 pin policy) in two
#    producers: docker-compose.yml (dev stack) and .github/workflows/ci.yml (CI
#    service container). Warns when the anchors disagree, when the pin lags the
#    newest upstream minor, and when upstream crosses a MAJOR (that is an ADR
#    review trigger, not a tag bump). Patch releases are ignored on purpose: a
#    `vX.Y` tag floats to `X.Y.N`, so a patch comparison would warn forever.
#    An upstream release can exist before its Docker image is pullable, so a
#    lag is only reported after the Docker Hub tags API confirms the new minor
#    is published; an unpublished or unverifiable tag is NOT a lag. Accepted
#    under-reporting window: if two releases land in one week, the newest one's
#    unpublished image hides a real lag one minor behind it — the next run
#    reports it.
#
# Advisory-only by design: an upstream release cadence is not a contract, so a
# drift must never fail a job or block a merge. The script always exits 0. An
# unreachable API is a third, distinct outcome ("not checked", not "stale"):
# with `set -euo pipefail` that guarantee needs the EXIT trap below plus
# `|| true` on every fallible substitution — without the trap the first failed
# gh/jq/grep/curl would break the advisory contract. The trap also covers the
# `set -e` exits below, which is the point of having it.
#
# `gh` needs a token in Actions: the workflow passes `GH_TOKEN` from
# `secrets.GITHUB_TOKEN`. Without it `gh` fails and every outcome degrades to
# "not checked" — a silent no-op, which is why the guard test pins the env var.
#
# Proof hooks (proof-only; the CI workflow must never set any of them — the
# guard test scans the workflow file for all four literals):
#   DRIFT_PINNED_VERSION  replaces the extracted symfony-lsp pin
#   DRIFT_REPO            redirects the symfony-lsp upstream repository
#   DRIFT_IMAGE_PINNED    replaces the extracted Meilisearch image pin
#   DRIFT_IMAGE_UPSTREAM  redirects the Meilisearch releases repository
#
# Bash 3.2 compatible (stock macOS): no associative arrays.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNNER="$ROOT/scripts/symfony-lsp-check.sh"
WORKFLOW="$ROOT/.github/workflows/ci.yml"
COMPOSE="$ROOT/docker-compose.yml"
UPSTREAM_REPO="${DRIFT_REPO:-symfony/language-tools}"
UPSTREAM_IMAGE_REPO="${DRIFT_IMAGE_UPSTREAM:-meilisearch/meilisearch}"
IMAGE_NAME="getmeili/meilisearch"
# A real `image:` line, not a prose mention of the image name: comments in both
# files already carry version history ("was v1.54"), and counting those would
# manufacture a false anchor.
IMAGE_ANCHOR_RE="^[[:space:]]*image:[[:space:]]*[\"']?${IMAGE_NAME}:v[0-9]+\\.[0-9]+(\\.[0-9]+)?[\"']?[[:space:]]*(#.*)?\$"

# Advisory contract outranks any internal error: any non-zero status is reported
# as "not checked" and swallowed. The wording stays generic because two checks
# share this trap — naming one of them would misreport which check aborted.
on_exit() {
    local status=$?
    if [ "${status}" -ne 0 ]; then
        printf '::warning::drift: a version check aborted with status %s; its pin is not checked (neither fresh nor stale).\n' "${status}" || true
        summarize "drift: a version check aborted; its pin is not checked."
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

check_symfony_lsp() {
    if [ ! -f "$RUNNER" ]; then
        printf '::warning::drift: runner script not found at %s; the symfony-lsp pin is not checked.\n' "$RUNNER" || true
        summarize "drift: runner script not found; the symfony-lsp pin is not checked."
        return 0
    fi
    if [ ! -f "$WORKFLOW" ]; then
        printf '::warning::drift: workflow not found at %s; the symfony-lsp pin is not checked.\n' "$WORKFLOW" || true
        summarize "drift: CI workflow not found; the symfony-lsp pin is not checked."
        return 0
    fi

    # Anchor 1: the runner default. Exactly one VERSION default line is expected.
    local runner_matches runner_count runner_version ci_matches ci_count ci_version
    runner_matches="$(grep -oE 'VERSION="\$\{SYMFONY_LSP_VERSION:-[^}]+\}' "$RUNNER" || true)"
    runner_count="$(printf '%s\n' "$runner_matches" | grep -c . || true)"
    if [ "$runner_count" -ne 1 ]; then
        printf '::warning::drift: expected exactly one SYMFONY_LSP_VERSION default in scripts/symfony-lsp-check.sh, found %s; the pin is not checked.\n' "$runner_count" || true
        summarize "drift: runner anchor unreadable; the pin is not checked."
        return 0
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
        return 0
    fi
    ci_version="${ci_matches##*SYMFONY_LSP_VERSION: \'}"
    ci_version="${ci_version%\'}"

    local anchors_agree='yes'
    if [ "$runner_version" != "$ci_version" ]; then
        anchors_agree='no'
        printf '::warning::drift: SYMFONY_LSP_VERSION anchors disagree — runner default %s vs ci.yml env %s. The effective CI version is the ci.yml one; align the runner default.\n' "$runner_version" "$ci_version" || true
        summarize "drift: anchors disagree (runner $runner_version vs ci.yml $ci_version)."
    fi

    # The effective pin is the ci.yml env (it overrides the runner default),
    # unless the proof hook replaces it.
    local pinned latest_json tag latest digest
    pinned="${DRIFT_PINNED_VERSION:-$ci_version}"

    latest_json="$(gh api "repos/${UPSTREAM_REPO}/releases/latest" || true)"
    tag="$(printf '%s' "$latest_json" | jq -r '.tag_name // empty' || true)"
    if [ -z "${tag:-}" ]; then
        printf '::warning::drift: could not read the latest %s release; the pin %s is not checked (neither fresh nor stale).\n' "$UPSTREAM_REPO" "$pinned" || true
        summarize "drift: upstream release unreadable; the pin $pinned is not checked."
        return 0
    fi
    # Tags are v-prefixed (v0.23.0), the pin is bare (0.23.0).
    latest="${tag#v}"
    digest="$(printf '%s' "$latest_json" | jq -r '.assets[] | select(.name | endswith("linux-x64.tar.gz")) | .digest // empty' || true)"
    # Upstream may one day ship a second matching tarball: keep the warning on
    # one line by taking the first digest only.
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
        return 0
    fi

    printf '::warning::drift: symfony-lsp pin %s lags the latest upstream release %s. Bump recipe: set SYMFONY_LSP_VERSION to %s in .github/workflows/ci.yml and scripts/symfony-lsp-check.sh, add the measured archive hash to pinned_sha256() in the runner script (upstream asset digest: %s).\n' \
        "$pinned" "$latest" "$latest" "${digest:-unknown}" || true
    summarize "drift: symfony-lsp pin $pinned lags the latest upstream release $latest."
    return 0
}

check_meilisearch_image() {
    if [ ! -f "$COMPOSE" ]; then
        printf '::warning::drift: docker-compose.yml not found at %s; the Meilisearch image pin is not checked.\n' "$COMPOSE" || true
        summarize "drift: docker-compose.yml not found; the Meilisearch image pin is not checked."
        return 0
    fi
    if [ ! -f "$WORKFLOW" ]; then
        printf '::warning::drift: workflow not found at %s; the Meilisearch image pin is not checked.\n' "$WORKFLOW" || true
        summarize "drift: CI workflow not found; the Meilisearch image pin is not checked."
        return 0
    fi

    # Count and extract from the SAME anchored `image:` lines. Extraction used
    # to grep the whole file, which a version-history comment could poison.
    local compose_count ci_count compose_tag ci_tag
    compose_count="$(grep -cE "$IMAGE_ANCHOR_RE" "$COMPOSE" || true)"
    ci_count="$(grep -cE "$IMAGE_ANCHOR_RE" "$WORKFLOW" || true)"

    if [ "$compose_count" -ne 1 ] || [ "$ci_count" -ne 1 ]; then
        printf '::warning::drift: expected exactly one %s image line in docker-compose.yml and in ci.yml, found %s and %s; the Meilisearch pin is not checked.\n' "$IMAGE_NAME" "$compose_count" "$ci_count" || true
        summarize "drift: Meilisearch anchors unreadable (compose $compose_count, ci $ci_count); the pin is not checked."
        return 0
    fi

    compose_tag="$(grep -E "$IMAGE_ANCHOR_RE" "$COMPOSE" | grep -oE "${IMAGE_NAME}:v[0-9]+(\\.[0-9]+)+" | head -n 1 || true)"
    ci_tag="$(grep -E "$IMAGE_ANCHOR_RE" "$WORKFLOW" | grep -oE "${IMAGE_NAME}:v[0-9]+(\\.[0-9]+)+" | head -n 1 || true)"
    compose_tag="${compose_tag##*:}"
    ci_tag="${ci_tag##*:}"

    local anchors_agree='yes'
    if [ "$compose_tag" != "$ci_tag" ]; then
        anchors_agree='no'
        printf '::warning::drift: Meilisearch image anchors disagree — docker-compose.yml %s vs ci.yml %s. The CI service container is the effective CI pin; the pin policy requires the same MINOR tag in both.\n' "$compose_tag" "$ci_tag" || true
        summarize "drift: Meilisearch anchors disagree (compose $compose_tag vs ci $ci_tag)."
    fi

    # ci.yml is the CI-effective pin, unless the proof hook replaces it.
    local pinned pin pin_major pin_rest pin_minor
    pinned="${DRIFT_IMAGE_PINNED:-$ci_tag}"
    pin="${pinned#v}"
    pin_major="${pin%%.*}"
    pin_rest="${pin#*.}"
    pin_minor="${pin_rest%%.*}"
    case "${pin_major}" in
        ''|*[!0-9]*)
            printf '::warning::drift: could not parse the Meilisearch pin %s; the pin is not checked.\n' "$pinned" || true
            summarize "drift: Meilisearch pin unparseable; the pin is not checked."
            return 0
            ;;
    esac
    case "${pin_minor}" in
        ''|*[!0-9]*)
            printf '::warning::drift: could not parse the Meilisearch pin %s; the pin is not checked.\n' "$pinned" || true
            summarize "drift: Meilisearch pin unparseable; the pin is not checked."
            return 0
            ;;
    esac

    local latest_json tag up up_major up_rest up_minor
    latest_json="$(gh api "repos/${UPSTREAM_IMAGE_REPO}/releases/latest" || true)"
    tag="$(printf '%s' "$latest_json" | jq -r '.tag_name // empty' || true)"
    if [ -z "${tag:-}" ]; then
        printf '::warning::drift: could not read the latest %s release; the Meilisearch pin %s is not checked (neither fresh nor stale).\n' "$UPSTREAM_IMAGE_REPO" "$pinned" || true
        summarize "drift: Meilisearch upstream release unreadable; the pin $pinned is not checked."
        return 0
    fi
    up="${tag#v}"
    up_major="${up%%.*}"
    up_rest="${up#*.}"
    up_minor="${up_rest%%.*}"

    case "${up_major}" in
        ''|*[!0-9]*)
            printf '::warning::drift: could not parse the upstream Meilisearch release tag %s; the pin %s is not checked.\n' "$tag" "$pinned" || true
            summarize "drift: Meilisearch upstream tag unparseable; the pin $pinned is not checked."
            return 0
            ;;
    esac
    case "${up_minor}" in
        ''|*[!0-9]*)
            printf '::warning::drift: could not parse the upstream Meilisearch release tag %s; the pin %s is not checked.\n' "$tag" "$pinned" || true
            summarize "drift: Meilisearch upstream tag unparseable; the pin $pinned is not checked."
            return 0
            ;;
    esac

    local pin_major_num pin_minor_num up_major_num up_minor_num
    pin_major_num=$((10#$pin_major))
    pin_minor_num=$((10#$pin_minor))
    up_major_num=$((10#$up_major))
    up_minor_num=$((10#$up_minor))

    # Steady state and "ahead" are decided WITHOUT the network probe: a flaky
    # Docker Hub must not turn a conclusive verdict into "not checked".
    if [ "$pin_major_num" -eq "$up_major_num" ] && [ "$pin_minor_num" -eq "$up_minor_num" ]; then
        if [ "$anchors_agree" = 'yes' ]; then
            printf 'drift: Meilisearch image pin %s matches the latest upstream minor (v%s.%s); anchors agree.\n' "$pinned" "$up_major" "$up_minor"
            summarize "drift: Meilisearch image pin $pinned matches the latest upstream minor v${up_major}.${up_minor}."
        else
            printf 'drift: Meilisearch image pin %s matches the latest upstream minor, but the anchors still disagree — align them.\n' "$pinned"
            summarize "drift: Meilisearch image pin $pinned matches upstream, but the anchors still disagree."
        fi
        return 0
    fi

    if [ "$pin_major_num" -gt "$up_major_num" ]; then
        printf 'drift: Meilisearch image pin %s is AHEAD of the latest upstream minor (v%s.%s) — no action, the pin outruns the stable release.\n' "$pinned" "$up_major" "$up_minor"
        summarize "drift: Meilisearch image pin $pinned is ahead of upstream v${up_major}.${up_minor}."
        return 0
    fi

    if [ "$up_major_num" -gt "$pin_major_num" ]; then
        printf '::warning::drift: Meilisearch upstream crossed a MAJOR boundary — pinned %s, latest v%s.%s. This is an ADR-0001 review trigger, not a tag bump: re-evaluate the engine before changing the pin.\n' "$pinned" "$up_major" "$up_minor" || true
        summarize "drift: Meilisearch upstream major v${up_major} vs pinned $pinned — ADR-0001 review trigger."
        return 0
    fi

    if [ "$pin_minor_num" -gt "$up_minor_num" ]; then
        printf 'drift: Meilisearch image pin %s is AHEAD of the latest upstream minor (v%s.%s) — no action, the pin outruns the stable release.\n' "$pinned" "$up_major" "$up_minor"
        summarize "drift: Meilisearch image pin $pinned is ahead of upstream v${up_major}.${up_minor}."
        return 0
    fi

    # Only here is the pin behind the same major. A release precedes its image,
    # so confirm the tag is pullable before calling the pin stale. 404 means
    # "not published yet"; anything else (429, 5xx, timeout) is "unverifiable",
    # never a lag claim.
    local hub_status
    hub_status="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' "https://hub.docker.com/v2/repositories/${IMAGE_NAME}/tags/v${up_major}.${up_minor}" 2>/dev/null || true)"
    if [ "${hub_status:-}" = '404' ]; then
        printf 'drift: Meilisearch upstream %s exists but image tag v%s.%s is not published yet (Docker Hub 404); the pin %s is not checked.\n' "$tag" "$up_major" "$up_minor" "$pinned" || true
        summarize "drift: Meilisearch image v${up_major}.${up_minor} not published yet; the pin $pinned is not checked."
        return 0
    fi
    if [ "${hub_status:-}" != '200' ]; then
        printf '::warning::drift: cannot verify that Meilisearch image v%s.%s is published (Docker Hub status: %s); the pin %s is not checked.\n' "$up_major" "$up_minor" "${hub_status:-unreachable}" "$pinned" || true
        summarize "drift: Meilisearch image v${up_major}.${up_minor} unverifiable (${hub_status:-unreachable}); the pin $pinned is not checked."
        return 0
    fi

    printf '::warning::drift: Meilisearch image pin %s lags the latest upstream minor v%s.%s. Bump recipe: set %s:v%s.%s in docker-compose.yml and .github/workflows/ci.yml, update the pinned literals in tests/Infrastructure/Ci/MeilisearchCiConfigTest.php and tests/Infrastructure/Search/MeilisearchClientConfigTest.php, then reindex (search:reindex) and run the engine-backed suites. An existing volume needs a one-time `meilisearch --upgrade-db`.\n' \
        "$pinned" "$up_major" "$up_minor" "$IMAGE_NAME" "$up_major" "$up_minor" || true
    summarize "drift: Meilisearch image pin $pinned lags the latest upstream minor v${up_major}.${up_minor}."
    return 0
}

# Both checks run on every invocation: the trap above guarantees exit 0 for
# either one, and a function that bails out early must never hide the other.
check_symfony_lsp
check_meilisearch_image
