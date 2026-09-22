#!/usr/bin/env bash
#
# Symfony Language Tools checker (symfony-lsp) installer and runner.
#
# Downloads the pinned standalone release, verifies its SHA256 checksum and runs
# `symfony-lsp check`, forwarding every argument.
#
# The release archive and its checksum list are cached in var/bin/ (gitignored)
# so repeated runs do not re-download 4.7 MB from GitHub. The invariant is
# "checksum verified before execution", not "download every time": the executed
# binary is always extracted from an archive that was verified in this run,
# including when the archive comes from the CI cache.
#
# Usage:
#   scripts/symfony-lsp-check.sh [check options...]
#
# Defaults to a runtime check (boots the application to read routes, services,
# container and other metadata). CI runs both modes, pinning the environment
# explicitly because the checker defaults to "dev" and ignores .env:
#   scripts/symfony-lsp-check.sh --source-only --environment=test --format=github
#   scripts/symfony-lsp-check.sh --environment=test --format=github
#
# Environment:
#   SYMFONY_LSP_VERSION   release to install (default: 0.21.0)
#   SYMFONY_LSP_BIN_DIR   install/cache directory (default: var/bin)
#   SYMFONY_LSP_BASE_URL  release base URL (default: the official GitHub release;
#                         overridable so the failure paths stay testable offline)
#
set -euo pipefail

VERSION="${SYMFONY_LSP_VERSION:-0.21.0}"
BASE_URL="${SYMFONY_LSP_BASE_URL:-https://github.com/symfony/language-tools/releases/download/v${VERSION}}"
BIN_DIR="${SYMFONY_LSP_BIN_DIR:-var/bin}"
BIN="${BIN_DIR}/symfony-lsp"
SUMS="${BIN_DIR}/SHA256SUMS.${VERSION}"

# Transient GitHub/CDN failures (504 while fetching a release asset) must not
# fail the job: retry with backoff. --retry-all-errors covers non-connection
# statuses, and the timeouts bound a stalled TLS handshake that would otherwise
# eat the whole job budget.
CURL_OPTS=(
    -fsSL
    --retry 4
    --retry-delay 2
    --retry-all-errors
    --connect-timeout 10
    --max-time 60
)

asset_name() {
    case "$(uname -s)-$(uname -m)" in
        Linux-x86_64) echo "linux-x64" ;;
        Linux-aarch64 | Linux-arm64) echo "linux-arm64" ;;
        Darwin-arm64) echo "macos-arm64" ;;
        *)
            echo "symfony-lsp: unsupported platform $(uname -s)-$(uname -m)" >&2
            exit 11
            ;;
    esac
}

download() {
    curl "${CURL_OPTS[@]}" -o "$2" "$1"
}

# Refresh the checksum list for this version, falling back to the cached copy
# when GitHub is unavailable. Both sources are the same release asset, so the
# trust level is unchanged; what matters is that the archive is always verified
# against a list that covers it. The list is per-version, so a version bump can
# never verify an archive against a foreign list.
ensure_checksums() {
    if download "${BASE_URL}/SHA256SUMS" "${SUMS}.tmp"; then
        mv "${SUMS}.tmp" "${SUMS}"
    elif [ -f "${SUMS}" ]; then
        echo "symfony-lsp: SHA256SUMS download failed, reusing cached ${SUMS} (mtime $(date -r "${SUMS}" '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || echo unknown))" >&2
    else
        rm -f "${SUMS}.tmp"
        echo "symfony-lsp: cannot obtain SHA256SUMS for v${VERSION}" >&2
        exit 11
    fi
}

# Exact entry match: a substring match could verify the wrong artifact if the
# release ever ships a similarly named asset.
verify_archive() {
    local archive="$1" line
    line="$(awk -v archive="${archive}" '$2 == archive { print; found = 1 } END { if (!found) exit 1 }' "${SUMS}")" || {
        echo "symfony-lsp: ${archive} is not listed in ${SUMS}" >&2
        return 1
    }

    if ! (cd "${BIN_DIR}" && printf '%s\n' "${line}" | sha256sum -c -); then
        echo "symfony-lsp: checksum mismatch for ${archive}" >&2
        return 1
    fi
}

install_binary() {
    local asset archive
    asset="$(asset_name)"
    archive="symfony-lsp-v${VERSION}-${asset}.tar.gz"

    mkdir -p "${BIN_DIR}"
    ensure_checksums

    if [ -f "${BIN_DIR}/${archive}" ] && verify_archive "${archive}" >/dev/null 2>&1; then
        echo "symfony-lsp: using cached ${archive} (checksum verified)" >&2
    else
        echo "symfony-lsp: installing v${VERSION} (${asset}) into ${BIN_DIR}" >&2
        download "${BASE_URL}/${archive}" "${BIN_DIR}/${archive}.tmp"
        mv "${BIN_DIR}/${archive}.tmp" "${BIN_DIR}/${archive}"

        if ! verify_archive "${archive}"; then
            echo "symfony-lsp: checksum verification failed for ${archive}" >&2
            rm -f "${BIN_DIR}/${archive}"
            exit 11
        fi
    fi

    tar -xzf "${BIN_DIR}/${archive}" -C "${BIN_DIR}"
    mv "${BIN_DIR}/symfony-lsp-v${VERSION}-${asset}/symfony-lsp" "${BIN}"
    chmod +x "${BIN}"
    rm -rf "${BIN_DIR}/symfony-lsp-v${VERSION}-${asset}"
}

# Always reinstall from a verified archive. A binary restored from the CI cache
# has never been checksum-verified (SHA256SUMS covers the archive, not the
# extracted file), and extraction costs milliseconds, so the verified archive is
# the single source of truth for the executed binary.
install_binary

exec "${BIN}" check "$@"
