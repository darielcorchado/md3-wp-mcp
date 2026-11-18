#!/usr/bin/env bash
# Reports where CLAUDE.md (and the docs it relies on) no longer match the code.
# Usage: bash .claude/skills/sync-claude-md/check.sh
# Exit 0 = nothing found, 1 = drift listed.
set -uo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT" || exit 2

DRIFT=0
drift() { echo "DRIFT: $*"; DRIFT=1; }
mentions() { grep -qF -- "$2" "$1"; }

# --- What changed since the last sync -------------------------------------
SYNCED="$(sed -nE 's/.*synced-with: ([0-9a-f]+).*/\1/p' CLAUDE.md | head -1)"
if [ -z "$SYNCED" ] || ! git cat-file -e "${SYNCED}^{commit}" 2>/dev/null; then
	echo "MARKER: no valid synced-with commit in CLAUDE.md; review every section."
else
	echo "MARKER: synced with $SYNCED; HEAD is $(git rev-parse --short HEAD)."
	echo "Changed since then (committed):"
	git diff --stat "$SYNCED"..HEAD -- . ':(exclude)CLAUDE.md' | sed 's/^/  /'
fi
if [ -n "$(git status --porcelain -- . ':(exclude)CLAUDE.md')" ]; then
	echo "Uncommitted changes:"
	git status --short -- . ':(exclude)CLAUDE.md' | sed 's/^/  /'
fi
echo

# --- Filters and actions the plugin defines -------------------------------
for hook in $(grep -rhoE "(apply_filters|do_action)\(\s*'md3_[a-z0-9_]+'" includes md3-wp-mcp.php | grep -oE "md3_[a-z0-9_]+" | sort -u); do
	mentions CLAUDE.md "$hook" || drift "hook '$hook' is not in CLAUDE.md Conventions"
done
for hook in $(grep -oE "md3_[a-z0-9_]+" CLAUDE.md | sort -u); do
	case "$hook" in md3_mcp_|md3_wp_mcp_) continue ;; esac
	grep -rqE "'$hook'" includes md3-wp-mcp.php uninstall.php || drift "CLAUDE.md names '$hook', which the code no longer uses"
done

# --- Tables ----------------------------------------------------------------
for table in $(grep -oE "self::table\( '[a-z_]+' \)" includes/class-schema.php | grep -oE "'[a-z_]+'" | tr -d "'"); do
	mentions CLAUDE.md "$table" || drift "table '$table' is not in CLAUDE.md Layout"
	mentions uninstall.php "'$table'" || drift "table '$table' is not dropped in uninstall.php"
done

# --- Options -----------------------------------------------------------------
for opt in $(grep -oE "OPT_[A-Z_]+ *= *'md3_wp_mcp_[a-z_]+'" includes/class-settings.php | grep -oE "md3_wp_mcp_[a-z_]+"); do
	mentions CLAUDE.md "${opt#md3_wp_mcp_}" || drift "option '$opt' is not in CLAUDE.md Layout"
done
for opt in $(grep -rhoE "'md3_wp_mcp_[a-z_]+'" includes | tr -d "'" | sort -u); do
	case "$opt" in md3_wp_mcp_cleanup|md3_wp_mcp_client_ip|md3_wp_mcp_redirect_schemes) continue ;; esac
	grep -qE "(get|update|add|delete)_option\( *'$opt'|= *'$opt'" -r includes || continue
	mentions uninstall.php "'$opt'" || drift "option '$opt' is not deleted in uninstall.php"
done

# --- Test helpers -------------------------------------------------------------
for helper in $(grep -oE "protected function [a-z_]+" tests/helpers/class-test-case.php | awk '{print $3}'); do
	mentions CLAUDE.md "$helper()" || drift "Test_Case helper '$helper()' is not in CLAUDE.md Conventions"
	mentions docs/development.md "$helper(" || drift "Test_Case helper '$helper()' is not in docs/development.md"
done

# --- Scripts, folders, top-level classes ------------------------------------
for script in bin/*.sh; do
	mentions CLAUDE.md "$script" || drift "script '$script' is not in CLAUDE.md Commands"
done
for dir in includes/*/; do
	name="$(basename "$dir")/"
	mentions CLAUDE.md "$name" || drift "folder 'includes/$name' is not in CLAUDE.md Layout"
done
for file in includes/class-*.php; do
	mentions CLAUDE.md "$(basename "$file")" || drift "'$file' is not in CLAUDE.md Layout"
done

# --- Tools: registered, and documented in README and design.md -------------
for file in includes/abilities/class-*.php; do
	grep -q "extends Ability" "$file" || continue
	tool="$(basename "$file" .php)"; tool="${tool#class-}"
	class="$(grep -oE "class [A-Za-z_]+ extends Ability" "$file" | awk '{print $2}')"
	grep -q "new $class()" includes/abilities/class-registry.php || drift "$class is not in Registry::abilities()"
	mentions README.md "\`$tool\`" || drift "tool '$tool' is not in the README tools table"
	mentions docs/design.md "\`$tool\`" || drift "tool '$tool' is not in design.md section 5"
done

# --- Test files listed in docs/development.md -------------------------------
for file in tests/integration/test-*.php; do
	mentions docs/development.md "$(basename "$file")" || drift "'$file' is not in the docs/development.md test table"
done

# --- Version in both places -------------------------------------------------
header="$(sed -nE 's/^ \* Version: +([0-9.]+).*/\1/p' md3-wp-mcp.php)"
const="$(sed -nE "s/.*MD3_WP_MCP_VERSION', '([0-9.]+)'.*/\1/p" md3-wp-mcp.php)"
[ "$header" = "$const" ] || drift "Version header ($header) and MD3_WP_MCP_VERSION ($const) differ"

[ "$DRIFT" -eq 0 ] && echo "CHECK: no mechanical drift found."
exit "$DRIFT"
