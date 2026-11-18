---
name: sync-claude-md
description: Use when CLAUDE.md in the md3-wp-mcp plugin may no longer match the code - after adding or renaming a tool, class, folder, table, option, filter, test helper or bin script, after changing OAuth, the bearer guard, permissions or the release steps, before opening a pull request, or when asked to update, refresh or sync CLAUDE.md.
---

# Sync CLAUDE.md

CLAUDE.md is a map of the plugin for the next session. A wrong map is worse than none, so every line must be true of the code as it is now. Facts that can be checked mechanically are checked by `check.sh`; the rest you verify by reading the code the change touched.

## Steps

1. **Run the check** from the repo root:

   ```bash
   bash .claude/skills/sync-claude-md/check.sh
   ```

   It prints the commit CLAUDE.md was last synced with (`<!-- synced-with: … -->`), what changed since, and one `DRIFT:` line per mismatch (hooks, tables, options, test helpers, scripts, folders, classes, tools in README and design.md, test files in docs/development.md, version).

2. **Read the diff of what changed**: `git diff <synced-with>..HEAD -- <paths>` plus any uncommitted changes. Use the table below to find the CLAUDE.md sections each change can make wrong, and re-read those sections against the code.

3. **Edit CLAUDE.md** so every affected line is true. Fix `DRIFT:` lines in the file they name: uninstall.php, README.md, docs/design.md or docs/development.md drift is fixed there, not hidden in CLAUDE.md.

4. **Set the marker** to the commit the text now describes: `<!-- synced-with: <git rev-parse --short HEAD> -->`.

5. **Run the check again.** It must print `CHECK: no mechanical drift found.` Then show the user the CLAUDE.md diff.

## Where a change shows up

| Changed | Re-read in CLAUDE.md |
|---|---|
| `includes/abilities/` | Layout; Conventions "New tool"; Rules (author, trash, live content, sanitizer) |
| `includes/oauth/`, `includes/auth/`, `includes/mcp/` | How a tool call is checked (hook names, order, who refuses what); Rules (tokens, MCP user) |
| `includes/class-settings.php`, `class-schema.php`, `uninstall.php` | Layout (options, tables); Conventions (schema, uninstall) |
| `includes/admin/` | Layout |
| `tests/helpers/`, `phpunit.xml.dist`, `bin/`, `docker-compose.test.yml` | Commands; Conventions (test helpers) |
| `composer.json` | Commands; Conventions (PHP version, autoload) |
| `md3-wp-mcp.php` | Layout; Conventions (release); intro (author) |
| `docs/design.md` decisions | Rules that must not break |

## What CLAUDE.md is

- Short: about 100 lines. It points to README.md, docs/design.md and docs/development.md for detail; it doesn't copy them.
- What a newcomer can't see quickly by reading the code: the request flow, the rules that must not break, naming conventions, the commands and the checklists for common changes.
- Names (classes, hooks, options, files) exactly as they appear in the code, so they can be searched.
- No code beyond the command block. No history ("we used to…") and no dates except in the marker.

## Common mistakes

| Mistake | Instead |
|---|---|
| Updating the marker without reading the diff | The marker says "true as of this commit". Read every changed area first. |
| Trusting check.sh alone | It can't see a changed hook order, a new refusal or a rule that no longer holds. Step 2 is for those. |
| Fixing README or uninstall drift by editing CLAUDE.md | Fix the file the `DRIFT:` line names. |
| Adding every new detail | Add only what the next session needs to work safely. Detail goes in docs/. |
| Removing a rule because the code changed | A rule that no longer holds is a design change. Ask the user before you drop or soften it. |
