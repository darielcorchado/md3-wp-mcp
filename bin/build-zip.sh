#!/usr/bin/env bash
# Builds an installable release zip for md3-wp-mcp.
#
# Stages the plugin (rsync against .distignore) in a temp directory outside the
# repo, installs production-only Composer dependencies there, then zips it.
# Usage: bin/build-zip.sh [--stage-only <dir>]
#   --stage-only <dir>  leave the staged plugin in <dir>/md3-wp-mcp, no zip.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

STAGE_ONLY=""
if [ "${1:-}" = "--stage-only" ]; then
	STAGE_ONLY="${2:-}"
	if [ -z "$STAGE_ONLY" ]; then
		echo "--stage-only needs a directory" >&2
		exit 1
	fi
	mkdir -p "$STAGE_ONLY"
	STAGE_ONLY="$(cd "$STAGE_ONLY" && pwd)"
fi

VERSION=$(grep -m1 "Version:" md3-wp-mcp.php | sed 's/.*Version: *//' | tr -d '[:space:]')
if [ -z "$VERSION" ]; then
	echo "Could not read Version from md3-wp-mcp.php" >&2
	exit 1
fi

if [ -n "$STAGE_ONLY" ]; then
	BUILD_DIR="$STAGE_ONLY"
else
	BUILD_DIR="$(mktemp -d)"
	trap 'rm -rf "$BUILD_DIR"' EXIT
fi

STAGE_DIR="${BUILD_DIR}/md3-wp-mcp"
rm -rf "$STAGE_DIR"
mkdir -p "$STAGE_DIR"

rsync -a --exclude-from="${REPO_ROOT}/.distignore" "${REPO_ROOT}/" "$STAGE_DIR/"

cp composer.json composer.lock "$STAGE_DIR/"
( cd "$STAGE_DIR" && composer install --no-dev --optimize-autoloader --no-interaction --quiet )
rm -f "$STAGE_DIR/composer.json" "$STAGE_DIR/composer.lock"

if [ -n "$STAGE_ONLY" ]; then
	echo "Staged md3-wp-mcp ${VERSION} in ${STAGE_DIR}"
	exit 0
fi

ZIP_NAME="md3-wp-mcp-${VERSION}.zip"
ZIP_PATH="${REPO_ROOT}/${ZIP_NAME}"
rm -f "$ZIP_PATH"
( cd "$BUILD_DIR" && zip -qr "$ZIP_PATH" md3-wp-mcp )

echo "Built ${ZIP_NAME}"
