#!/usr/bin/env bash
# Copies a production build of the plugin into a site repo's wp-content/plugins.
# Usage: bin/sync-to-site.sh <site-repo>
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

SITE_REPO="${1:-}"
if [ -z "$SITE_REPO" ]; then
	echo "Usage: bin/sync-to-site.sh <site-repo>" >&2
	exit 1
fi
if [ ! -d "${SITE_REPO}/wp-content/plugins" ]; then
	echo "${SITE_REPO}/wp-content/plugins does not exist" >&2
	exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

"${REPO_ROOT}/bin/build-zip.sh" --stage-only "$TMP"

TARGET="${SITE_REPO}/wp-content/plugins/md3-wp-mcp/"
rsync -a --delete "$TMP/md3-wp-mcp/" "$TARGET"

VERSION=$(grep -m1 "Version:" "${REPO_ROOT}/md3-wp-mcp.php" | sed 's/.*Version: *//' | tr -d '[:space:]')
echo "Synced md3-wp-mcp ${VERSION} to ${TARGET}"
