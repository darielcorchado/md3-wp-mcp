# CLAUDE.md

<!-- synced-with: 4f3b592 -->
<!-- Keep in sync with the code: run the sync-claude-md skill (.claude/skills/sync-claude-md). -->

MD3 WP MCP is a WordPress plugin: a content-only MCP server with its own OAuth 2.1 server. AI clients can list, read, draft, edit, publish and trash posts and pages, and nothing else. Author: Dariel Corchado (sole author).

- What it does and how to set it up: [README.md](README.md)
- Why it is built this way, and the security model: [docs/design.md](docs/design.md)
- How to change, test and release it: [docs/development.md](docs/development.md)

## Commands

```bash
composer install                       # once; also installs WordPress core into tests/wp/ (ignored)
bin/test.sh                            # all tests: PHPUnit in Docker, PHP 8.2 + MySQL 8
bin/test.sh --filter <test or class>   # one test or class
bin/build-zip.sh                       # release zip
bin/sync-to-site.sh <site-repo>        # production build into a site's wp-content/plugins/
```

If the first `bin/test.sh` after starting Docker fails with `db_connect_fail`, run it again (MySQL wasn't ready).

## Layout

```
md3-wp-mcp.php          Header, constants, requirement checks, boots Plugin on plugins_loaded
uninstall.php           Drops the tables, options and transients
includes/
  class-plugin.php      boot(): schema upgrade, abilities, MCP server, OAuth, upload page, cleanup cron, admin
  class-schema.php      Tables {prefix}md3_mcp_{clients,auth_codes,access_tokens,refresh_tokens,log,uploads}; DB_VERSION
  class-settings.php    Options md3_wp_mcp_{post_types,default_author,security} with defaults
  class-content-sanitizer.php  kses + block checks for every write
  class-rate-limiter.php, class-audit-log.php, class-cleanup.php
  abilities/            One class per MCP tool, extends Ability; Registry lists them.
                        Content_Writer (shared create/update, Brevo opt-out) and Content_Formatter (output);
                        Upload_Media::prepare()/insert() hold the file rules for every upload
  auth/                 Identity, Request_Context (per-request token identity), Scopes
  mcp/                  Server (creates the adapter server, transport permission), Error_Handler
  oauth/                league/oauth2-server wiring: Discovery, Registration (DCR), Cimd, Authorize
                        (consent), Token_Endpoint, Bearer_Guard, Keys, Tokens; entities/, repositories/
  admin/                Settings_Page (tabs in views/), Diagnostics, Profile_Connections
  uploads/              Upload_Links (one-time links, hashed tokens), Upload_Page (/md3-mcp/upload/<token>)
languages/              .pot, es_ES .po/.mo
tests/integration/      PHPUnit tests, one file per area; helpers in tests/helpers/class-test-case.php
docs/                   design.md, implementation-plan.md, development.md
```

## How a tool call is checked

1. `Bearer_Guard` on `determine_current_user` sets the user from our access token, **only** on the `/md3-wp-mcp/mcp` route, and stores the identity in `Request_Context`.
2. `rest_request_before_callbacks` refuses that identity on every other route.
3. `Mcp\Server::transport_permission()`: an identity from our token, the current user is `Settings::mcp_user_id()` (which returns 0 for a user with `manage_options`), and the per-token rate limit.
4. `Ability::check_permission()`: the token's scope, then `object_permission()` (post type enabled, WordPress capability).

## Rules that must not break

- Content only. No tool touches code, plugins, themes, users, settings or arbitrary post meta.
- Every ability is registered with `meta.mcp.public = false`, so it shows only on our server. Never switch off the adapter's default server; other plugins use it.
- Tokens work only on the MCP route. A cookie or Application Password is never accepted on it.
- The MCP user is an Editor and never an administrator (`manage_options` is refused, checked at read time).
- Every write goes through `Content_Sanitizer` (`wp_kses_post` even with `unfiltered_html`).
- Tools have no `author` input; the author comes from the "Author for new content" setting.
- Upload links work once, for 10 minutes, only while their connection is live, and the upload always runs as the MCP user, whoever is signed in in the browser.
- Trash only, never a permanent delete. `trash-content` refuses when the site's trash is disabled.
- Changing live content (published, scheduled, private) also needs `content:publish`.
- Content created or published over MCP never sends the Brevo web push.

## Conventions

- PHP 8.2, namespace `MD3\WPMCP\…`, WordPress coding standards (tabs). Files are `class-<name>.php`, loaded by Composer's classmap over `includes/`.
- Text domain `md3-wp-mcp` on every string people see. Error codes are `md3_mcp_*`. Options start with `md3_wp_mcp_`, transients with `md3_mcp_`.
- Filters: `md3_wp_mcp_client_ip`, `md3_wp_mcp_redirect_schemes`, `md3_mcp_trash_enabled`.
- Write the failing test first. Tests use `Test_Case` helpers: `as_mcp_user()`, `run_ability()`, `issue_token()`, `serve()`, `mcp_call()`.
- New tool: class in `includes/abilities/`, add to `Registry::abilities()`, test it, add it to the README and design.md tool tables.
- Schema change: edit `Schema`, increase `Schema::DB_VERSION`. New table or option: add it to `uninstall.php`.
- Changed strings: regenerate the `.pot`, translate `es_ES.po`, compile the `.mo` (commands in docs/development.md).
- Release: increase the version in both the header and `MD3_WP_MCP_VERSION`, and add a CHANGELOG entry.

## Building a feature

Every new feature is built with the superpowers skills, in this order:

1. `superpowers:brainstorming`: read the Jira ticket, settle the design and every "decision needed" with the user before writing code.
2. `superpowers:writing-plans`: a step-by-step plan from that design.
3. `superpowers:test-driven-development` while you build it (with `superpowers:executing-plans` or `superpowers:subagent-driven-development`): a failing test first, every time.
4. `superpowers:verification-before-completion`: run `bin/test.sh` and read the output before saying anything works.
5. `superpowers:requesting-code-review`, plus `/code-review` and `/security-review` on the branch. Fix what they confirm, with a test for each fix.
6. `superpowers:finishing-a-development-branch`: run the `sync-claude-md` skill, then ask Dariel to approve the commit (see Git). Open the pull request to `master` only after he approves.

These skills commit after every task. In this repo they don't: the Git rules below override them.

## Testing a change

1. **Tests:** `bin/test.sh` must pass with no `[ERROR]` lines. Tests can't see the web server, the page cache or the AI clients, so also:
2. **Local site:** `bin/sync-to-site.sh <path to the md3digitalsolutions repo>`, then open any wp-admin page at http://localhost:8080 (this runs the schema upgrade). `WP_ENVIRONMENT_TYPE` is `local` there, so plain HTTP is allowed. Connect Claude Code (`claude mcp add --transport http md3 http://localhost:8080/wp-json/md3-wp-mcp/mcp`, then `/mcp`) or the MCP Inspector and call the tools you changed. Check wp-admin and the **Log** tab.
3. **Staging (GridPane):** sync into the site repo, commit and deploy. Run Diagnostics, then the same calls from claude.ai.

The upload link end to end: ask the AI to set an image attached in the chat as the featured image of a draft. Open the link, upload the image and say it's done. The AI calls `get-upload`, then `set-featured-image`. Then check:
- opening the link again says it was already used;
- an unused link expires after 10 minutes;
- an SVG or a non-image is refused, and the same page then takes a real image;
- the Log tab shows `create-upload-link` and `upload-link` entries.

On a server, also check that `/md3-mcp/upload/*` is not page-cached and that a 10 MB multipart POST to it gets through the firewall and PHP's limits.

## Git

- Branches `feat/MD3-XX-short-name` from `master`. Commit messages `MD3-XX Imperative sentence`.
- **One commit per feature.** The design doc, plan, code, tests, docs and translations all go in that one commit, and review fixes are folded into it. If the work needs a checkpoint while building, squash it before asking for approval.
- **Never commit or push without Dariel's approval.** When the feature is done and `bin/test.sh` passes, show him what changed (files and a short summary) and the commit message, then wait. Commit, push and open the pull request only after he says yes. Approval covers that one commit; ask again for the next one.
- Dariel Corchado is the only author. Don't add `Co-Authored-By` trailers to commits.
- `vendor/` and `tests/wp/` are not committed. Sites get a built copy through `bin/sync-to-site.sh`.
