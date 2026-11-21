# Development

How to change MD3 WP MCP, test the change and ship it. For what the plugin does and why, see [design.md](design.md).

## Setup (once)

You need Docker Desktop, PHP 8.2+ and Composer on your machine.

```bash
composer install
```

This installs the runtime dependencies in `vendor/` and, for the tests, WordPress core in `tests/wp/`. Both folders are ignored by Git and left out of the release zip. Delete them and `composer install` brings them back.

## Git flow

| Branch | Holds | Merges into | Deployed to |
|---|---|---|---|
| `master` | Released code. Every merge into it is a release, tagged `vX.Y.Z`. | | Live sites |
| `stage` | Finished work waiting for the next release. | `master` | The staging site |
| `feat/MD3-XX-short-name` | One Jira ticket, in one commit. Branched from `master`. | `stage` | |
| `hotfix/MD3-XX-short-name` | An urgent fix to the released version. Branched from `master`. | `master`, then `master` into `stage` | |
| `release/vX.Y.Z` | The version number and CHANGELOG heading for a release. Branched from `stage`. | `stage` | |

```
master ──branch──▶ feat/MD3-XX ──PR──▶ stage ──PR "Release vX.Y.Z"──▶ master ──▶ tag vX.Y.Z
                                         ▲                                │
                                         └────── merge back (hotfix) ─────┘
```

Every merge goes through a pull request, and merged branches are deleted. Commit messages start with the ticket: `MD3-XX Imperative sentence`.

**Checks and rules.** Every pull request into `stage` or `master` runs the tests on GitHub ([.github/workflows/tests.yml](../.github/workflows/tests.yml), the same `bin/test.sh`). It can also be started by hand from the Actions tab. Two rulesets protect both branches (rulesets need a public repo, or GitHub Pro):

| Ruleset | Rules | Who can bypass |
|---|---|---|
| Tests and history | A pull request is required (no direct pushes). The `tests` check must pass. No force pushes, no deletion. | Nobody |
| Owner approval | One approval from the code owner ([.github/CODEOWNERS](../.github/CODEOWNERS)). A new push dismisses it, and the last push must be approved by someone other than its author. | The repository admin, and only when merging a pull request |

GitHub doesn't let you approve your own pull request, so the owner merges his own with **Merge without waiting for requirements**. That skips only the approval: the tests must still pass. Pull requests from outside contributors only run the workflow after the owner approves the run.

Claude Code can't merge at all, and asks before every push and new pull request ([.claude/settings.json](../.claude/settings.json)).

- **A feature** follows [the loop](#the-loop) below: branch from `master`, one commit, pull request into `stage`, then test it on the staging site.
- **A release** merges `stage` into `master` and tags it: see [Release](#release).
- **A hotfix** is for a bug in the released version that can't wait for the next release:
  1. `git checkout master && git pull && git checkout -b hotfix/MD3-XX-short-name`
  2. Fix it with a test, increase the patch version and add a CHANGELOG entry for it.
  3. Open a pull request into `master`, merge it and tag it as in a release.
  4. Bring the fix into `stage` with a pull request from `master` into `stage`: `gh pr create --base stage --head master --title "Merge vX.Y.Z into stage"`.

## The loop

### 1. Branch

```bash
git checkout master && git pull
git checkout -b feat/MD3-XX-short-name
```

Commit messages start with the ticket: `MD3-XX Imperative sentence`.

### 2. Write the test first

The tests are PHPUnit integration tests against a real WordPress and a real MySQL database, in [tests/integration/](../tests/integration/), one file per area:

| File | Covers |
|---|---|
| `test-read-abilities.php` | `list-post-types`, `list-content`, `get-content`, `list-terms` |
| `test-write-abilities.php` | `create-content`, `update-content`, `create-term` |
| `test-lifecycle-abilities.php` | publish, schedule, unpublish, trash, restore |
| `test-media-translation-abilities.php` | `upload-media`, `set-featured-image`, `link-translations` |
| `test-upload-links.php` | `create-upload-link`, the upload page, `get-upload`, `list-media` |
| `test-update-media.php` | `update-media` |
| `test-oauth-core.php`, `test-oauth-endpoints.php` | Keys, tokens, discovery, registration, authorize, token, revoke |
| `test-discovery-files.php` | The static discovery files: ZIP and admin-only download |
| `test-bearer-guard.php` | Which routes accept the token |
| `test-mcp-server.php` | The MCP server and its transport permission |
| `test-settings.php`, `test-admin.php`, `test-activation.php` | Settings, admin screens, activation and schema |
| `test-shared-services.php` | Scopes, request context, sanitizer, rate limiter, client IP |
| `test-i18n.php` | The Spanish translation loads |

Each class extends `Test_Case` ([tests/helpers/class-test-case.php](../tests/helpers/class-test-case.php)), which gives you the WordPress factories plus:

| Helper | Use it to |
|---|---|
| `as_mcp_user( $scopes, $role )` | Act as the MCP user holding some scopes, without the OAuth flow. The quickest way to test a tool. |
| `run_ability( 'trash-content', $input )` | Call a tool through the Abilities API, as an MCP call would. |
| `issue_token( $scopes )` | Run the real OAuth flow and get an access and refresh token. |
| `serve( $request )` | Serve a REST request the way a real HTTP request is served (user detection, auth errors, dispatch). Use it with a token in `$_SERVER['HTTP_AUTHORIZATION']`. |
| `mcp_call( 'tools/call', $params )` | Send a JSON-RPC request to the MCP server, with the session handled for you. |

A typical tool test:

```php
public function test_editor_cannot_trash_without_delete_scope() {
	$this->as_mcp_user( [ 'content:read', 'content:write' ] );
	$post = self::factory()->post->create();

	$result = wp_get_ability( 'md3-wp-mcp/trash-content' )->check_permissions( [ 'id' => $post ] );

	$this->assertWPError( $result );
	$this->assertSame( 'md3_mcp_insufficient_scope', $result->get_error_code() );
}
```

### 3. Run it, make it pass

```bash
bin/test.sh --filter test_editor_cannot_trash_without_delete_scope   # one test
bin/test.sh --filter Test_Lifecycle_Abilities                        # one class
bin/test.sh                                                          # everything
```

`bin/test.sh` starts MySQL 8 and PHP 8.2 from [docker-compose.test.yml](../docker-compose.test.yml), waits until PHP can connect to the test database, refreshes the autoloader (new classes are found without extra steps) and runs PHPUnit. Notices, warnings and deprecations fail a test.

### 4. Try it on a real site

The tests can't check the web server, the cache or the AI clients. A local or staging site can.

```bash
bin/sync-to-site.sh <path-to-site-repo>   # copies the production build into wp-content/plugins/md3-wp-mcp/
bin/build-zip.sh                          # or: md3-wp-mcp-<version>.zip to upload in wp-admin
```

Both build the same thing: the files that [.distignore](../.distignore) allows, with `composer install --no-dev --optimize-autoloader` run in a staged copy, so the site gets `vendor/` without the test tools.

On the site:

1. Open **Settings → MD3 WP MCP** and run **Diagnostics**.
2. Connect a client with the URL from the **Connect** tab:
   - MCP Inspector, to call one tool at a time: `npx @modelcontextprotocol/inspector`, transport Streamable HTTP.
   - Claude Code: `claude mcp add --transport http md3 <URL>`, then `/mcp` to sign in.
3. Call the tools you changed. Check the result in wp-admin and the entry in the **Log** tab.
4. Try the refusals too: a token without the scope, a post type that isn't enabled, a token sent to `/wp-json/wp/v2/posts`.

**Test the update, not only a fresh install.** Install the current release, connect a client, then put your build on top. The connection and the settings must keep working. `Plugin::boot()` re-runs the schema when `Schema::DB_VERSION` differs from the stored one, because activation hooks don't run when files are copied over.

### 5. Open the pull request

The pull request goes into `stage` (a hotfix goes into `master`). Before you merge:

- [ ] `bin/test.sh` passes locally, and the `tests` check is green on the pull request.
- [ ] [CHANGELOG.md](../CHANGELOG.md) has a line under `## Unreleased`. The version is only increased at release (a hotfix increases the patch version itself).
- [ ] If you changed text people see, the translations are updated (below).
- [ ] If you changed tools, settings or the security model, [README.md](../README.md) and [design.md](design.md) say so.
- [ ] Run `/security-review` on the branch when the change touches OAuth, the bearer guard or permissions.

After it is merged, build `stage` into the staging site with `bin/sync-to-site.sh <site-repo>`, deploy, and test it there: Diagnostics and the client checks.

## Common changes

### Add a tool

1. Create `includes/abilities/class-<tool-name>.php` with a `final` class that extends `Ability`. Implement `name()`, `label()`, `description()` and `execute()`. Override `scope()`, `annotations()`, `input_schema()`, `output_schema()` and `object_permission()` as needed. [class-trash-content.php](../includes/abilities/class-trash-content.php) is a short example.
2. Load posts with `$this->load_post()`, which refuses attachments, revisions and post types that aren't enabled. Check the WordPress capability for the object. Send every write through `Content_Sanitizer`.
3. Add it to `Registry::abilities()` in [class-registry.php](../includes/abilities/class-registry.php).
4. Test the scope, the capability, the post type allowlist and the happy path.
5. Add it to the tools table in the README and in design.md section 5.

### Change the database

1. Edit the `CREATE TABLE` statements in [class-schema.php](../includes/class-schema.php). `dbDelta()` adds columns and indexes, but never drops them.
2. Increase `Schema::DB_VERSION`.
3. A new table also goes in [uninstall.php](../uninstall.php).

### Add an option

Add the name as a constant in [class-settings.php](../includes/class-settings.php), with an accessor that applies the default, and add it to the list in [uninstall.php](../uninstall.php). Options start with `md3_wp_mcp_`, transients with `md3_mcp_`.

### Change text people see

All strings use the `md3-wp-mcp` text domain. After changing them:

```bash
docker compose -f docker-compose.test.yml run --rm php wp i18n make-pot . languages/md3-wp-mcp.pot --exclude=vendor,tests --allow-root
# translate the new strings in languages/md3-wp-mcp-es_ES.po
docker compose -f docker-compose.test.yml run --rm php wp i18n make-mo languages --allow-root
```

## Release

Before you release, `stage` has passed Diagnostics and the end-to-end checks with each client (design.md section 10) on the staging site.

1. Branch from `stage`: `git checkout stage && git pull && git checkout -b release/vX.Y.Z`. Set the new version in both the `Version:` header and `MD3_WP_MCP_VERSION` in [md3-wp-mcp.php](../md3-wp-mcp.php), and rename `## Unreleased` in the [CHANGELOG](../CHANGELOG.md) to `## X.Y.Z - <date>`. Commit it as `Release vX.Y.Z`, open a pull request into `stage` and merge it.
2. Open a pull request from `stage` into `master` titled `Release vX.Y.Z`, and merge it.
3. Tag the merge and push the tag:

   ```bash
   git checkout master && git pull
   git tag -a vX.Y.Z -m "X.Y.Z"
   git push origin vX.Y.Z
   ```

4. Build the tagged `master` into the live site's repo with `bin/sync-to-site.sh <site-repo>` and commit the copy there, `vendor/` included. The site's deploy has no build step.
5. Deploy to live and run Diagnostics there.

Versions follow [Semantic Versioning](https://semver.org/): a patch (`0.3.1`) for fixes only, a minor (`0.4.0`) for new features, and a major for changes that break existing connections or settings.
