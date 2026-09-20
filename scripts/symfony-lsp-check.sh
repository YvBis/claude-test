#!/usr/bin/env bash
#
# Symfony Language Tools checker (symfony-lsp) installer and runner.
#
# Downloads the pinned standalone release, verifies its SHA256 checksum and runs
# `symfony-lsp check`, forwarding every argument. The binary is cached in
# var/bin/ (gitignored) so repeated runs do not hit the network.
#
# Usage:
#   scripts/symfony-lsp-check.sh [check options...]
#
# Defaults to a runtime check (boots the application to read routes, services,
# container and other metadata). CI runs it with --source-only so the pilot gate
# never executes the application:
#   scripts/symfony-lsp-check.sh --source-only --format=github
#
# Environment:
#   SYMFONY_LSP_VERSION  release to install (default: 0.21.0)
#   SYMFONY_LSP_BIN_DIR  install directory (default: var/bin)
#
set -euo pipefail

VERSION="${SYMFONY_LSP_VERSION:-0.21.0}"
BASE_URL="https://github.com/symfony/language-tools/releases/download/v${VERSION}"
BIN_DIR="${SYMFONY_LSP_BIN_DIR:-var/bin}"
BIN="${BIN_DIR}/symfony-lsp"

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

install_binary() {
    local asset archive
    asset="$(asset_name)"
    archive="symfony-lsp-v${VERSION}-${asset}.tar.gz"

    mkdir -p "${BIN_DIR}"
    echo "symfony-lsp: installing v${VERSION} (${asset}) into ${BIN_DIR}" >&2
    curl -fsSL -o "${BIN_DIR}/${archive}" "${BASE_URL}/${archive}"
    curl -fsSL -o "${BIN_DIR}/SHA256SUMS" "${BASE_URL}/SHA256SUMS"

    if ! (cd "${BIN_DIR}" && grep -F " ${archive}" SHA256SUMS | sha256sum -c -); then
        echo "symfony-lsp: checksum verification failed for ${archive}" >&2
        exit 11
    fi

    tar -xzf "${BIN_DIR}/${archive}" -C "${BIN_DIR}"
    mv "${BIN_DIR}/symfony-lsp-v${VERSION}-${asset}/symfony-lsp" "${BIN}"
    chmod +x "${BIN}"
    rm -rf "${BIN_DIR}/${archive}" "${BIN_DIR}/symfony-lsp-v${VERSION}-${asset}"
}

if [ ! -x "${BIN}" ] || [ "$("${BIN}" --version 2>/dev/null | awk '{print $NF}')" != "${VERSION}" ]; then
    install_binary
fi

exec "${BIN}" check "$@"
