# Development

How to change MD3 WP MCP, test the change and ship it. For what the plugin does and why, see [design.md](design.md).

## Setup (once)

You need Docker Desktop, PHP 8.2+ and Composer on your machine.

```bash
composer install
```

This installs the runtime dependencies in `vendor/` and, for the tests, WordPress core in `tests/wp/`. Both folders are ignored by Git and left out of the release zip. Delete them and `composer install` brings them back.

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
| `test-oauth-core.php`, `test-oauth-endpoints.php` | Keys, tokens, discovery, registration, authorize, token, revoke |
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

`bin/test.sh` starts MySQL 8 and PHP 8.2 from [docker-compose.test.yml](../docker-compose.test.yml), refreshes the autoloader (new classes are found without extra steps) and runs PHPUnit. Notices, warnings and deprecations fail a test.

If the first run after starting Docker fails with `db_connect_fail`, MySQL wasn't ready yet. Run it again.

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

Before you merge:

- [ ] `bin/test.sh` passes.
- [ ] The version is increased in [md3-wp-mcp.php](../md3-wp-mcp.php), in both the `Version:` header and `MD3_WP_MCP_VERSION`.
- [ ] [CHANGELOG.md](../CHANGELOG.md) has an entry.
- [ ] If you changed text people see, the translations are updated (below).
- [ ] If you changed tools, settings or the security model, [README.md](../README.md) and [design.md](design.md) say so.
- [ ] Run `/security-review` on the branch when the change touches OAuth, the bearer guard or permissions.

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

1. Merge the pull request into `master` and tag the version: `git tag v0.2.0 && git push --tags`.
2. Build it into the site repo with `bin/sync-to-site.sh <site-repo>` and commit the copy there, `vendor/` included. The site's deploy has no build step.
3. Deploy to staging, run Diagnostics and the end-to-end checks with each client (design.md section 10), then deploy to live.
