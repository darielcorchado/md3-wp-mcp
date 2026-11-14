# MD3 WP MCP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A standalone WordPress plugin, MD3 WP MCP, in its own repository `md3-wp-mcp`, set up like `md3-google-reviews`. It exposes content-only MCP tools (posts, pages and allowed public post types) on `/wp-json/md3-wp-mcp/mcp`, protected by a built-in OAuth 2.1 server. Claude, ChatGPT, Gemini and Muse can then manage content without any access to code or configuration.

**Architecture:**
- Tools are WordPress Abilities, registered with `meta.mcp.public = false`. They are served only by our own server, which the official `wordpress/mcp-adapter` creates.
- OAuth 2.1 is built on `league/oauth2-server`:
  - discovery, registration (DCR and CIMD), consent on the front end, then token and revoke endpoints;
  - a bearer guard sets the WordPress user only on the MCP route;
  - the transport and each ability enforce scope plus WordPress capabilities.
- Every connection acts as one dedicated **MCP user** (Editor role). New content is credited to the **"Author for new content"** setting.
- Admin screens cover post types and author, security and MCP user, connections, the log and the connect help.

**Tech stack:**
- WordPress 7.x (Abilities API), PHP 8.2
- `wordpress/mcp-adapter` 0.7.1, `league/oauth2-server` 9.4.x, `nyholm/psr7`, `automattic/jetpack-autoloader`
- PHPUnit 9.6 with `wp-phpunit/wp-phpunit` 7.1.x and `roots/wordpress-no-content` 7.1.x (the same version as live)
- Tests run in the repo's own `docker-compose.test.yml` stack: PHP 8.2 and MySQL 8

**Spec:** [docs/design.md](design.md). Read it before any task. Section numbers below (§) refer to it.

## Global Constraints

**Repositories**
- **Plugin repo:** `PROJECTS/Wordpress Custom Plugins/md3-wp-mcp/`, branch `master`, remote `darielcorchado/md3-wp-mcp` (private). The repo root is the plugin root, and every path in this plan is relative to it.
- **Site repo:** `PROJECTS/md3digitalsolutions/`, branch `feat/MD3-30-md3-wp-mcp`. It receives only the built copy, through `bin/sync-to-site.sh` (Tasks 12–13).

**Commits**
- Never run `git commit`, `git push`, `git tag` or `gh` write commands in either repo until the user explicitly confirms. Every "Commit" step means: show `git status --short`, ask, and commit only on a yes.
- Use the attribution trailer from the session.

**Plugin identity**
- Main file: `md3-wp-mcp.php`.
- Namespace `MD3\WPMCP`. Text domain `md3-wp-mcp`. License GPL-2.0-or-later.
- Header fields: `Requires at least: 6.9`, `Requires PHP: 8.2`. Author `Dariel Corchado, Jenrry Cordero`, same as `md3-ai-connector`.

**Dependencies**
- `composer.json` sets `config.platform.php = "8.2.0"`. Runtime and dev dependencies live in one `composer.json`.
- `vendor/` and `tests/wp/` are gitignored in the plugin repo.
- `bin/build-zip.sh` creates the shipped `vendor/` with `composer install --no-dev --optimize-autoloader` in a staged copy.
- No Novamira code, functions or services may be used.

**Never expose any of these** (§1): PHP or WP-CLI execution, SQL, files, plugins or themes, users, roles, Application Passwords, options or settings, arbitrary post meta, or permanent delete.

**OAuth values**

| Item | Value |
|---|---|
| Authorization code | 60 s (`PT1M`), single use |
| Access token | 1 h (`PT1H`), JWT RS256 |
| Refresh token | 30 days (`P30D`), rotated on every use |
| PKCE | `S256` only |
| Clients | Public only (`token_endpoint_auth_method: none`) |
| HTTPS | Required unless `wp_get_environment_type() === 'local'` |

**Names and URLs**

| Item | Value |
|---|---|
| Scopes | `content:read`, `content:write`, `content:publish`, `content:delete` |
| Default scopes when none are requested | `content:read content:write` |
| Resource URL | `rest_url('md3-wp-mcp/mcp')` |
| Issuer | `home_url('/md3-mcp')` |
| Authorize URL | `home_url('/md3-mcp/authorize')` |
| REST namespace for OAuth | `md3-wp-mcp/v1` |
| MCP user | `Settings::mcp_user_id()`. It is chosen among Editor-role users and becomes 0 when unset, deleted, or the user has `manage_options`. No custom capability, no role changes. |
| Author for new content | `Settings::default_author_id()`: the configured user if they exist and have `edit_posts`, otherwise the MCP user |
| Tables | `{$wpdb->prefix}md3_mcp_{clients,auth_codes,access_tokens,refresh_tokens,log}` |
| Options | `md3_wp_mcp_post_types`, `md3_wp_mcp_default_author`, `md3_wp_mcp_security`, `md3_wp_mcp_db_version`, `md3_wp_mcp_private_key`, `md3_wp_mcp_encryption_key` (the last two with autoload off) |
| wp-config constants | `MD3_WP_MCP_PRIVATE_KEY` (PEM string or file path), `MD3_WP_MCP_ENCRYPTION_KEY` |

**Security defaults** (`md3_wp_mcp_security`):

```php
[ 'mcp_user_id' => 0, 'dcr' => true, 'mcp_rate_limit' => 120 ]
```

`md3_wp_mcp_default_author` defaults to `0`, meaning the MCP user.

**Rate limits**

| Bucket | Limit |
|---|---|
| `register` | 10 per IP per 3600 s |
| `token` | 30 per IP per 60 s |
| `authorize` | 30 per IP per 60 s |
| `revoke` | 30 per IP per 60 s |
| `mcp` | `mcp_rate_limit` per access token per 60 s |

**Other limits**
- DCR: at most 50 clients per site and 5 redirect URIs per client. Unfinished clients are pruned after 24 h.
- CIMD fetch: 5 s timeout, 65536 bytes, cached 24 h.
- Media upload: at most 10485760 bytes, and only `image/jpeg`, `image/png`, `image/webp` and `image/gif`.
- Lists: `per_page` at most 50.
- Audit log retention: 90 days.

**Content rules**
- All content and excerpts pass through `wp_kses_post`, regardless of `unfiltered_html`.
- Post meta writes are limited to `rank_math_title`, `rank_math_description` and `rank_math_focus_keyword`, plus the Brevo opt-out:

  ```php
  update_post_meta( $id, 'sib_push_meta_box_present', 1 );
  update_post_meta( $id, 'sib_push_send_notification', '0' );
  ```

  The opt-out runs on every create, update and publish (decision 4).
- `post_author` is set only by `create-content`, to `Settings::default_author_id()`. No tool accepts an `author` input, and updates never change the author.

**Running the tests.** Each task's verification step runs this from the plugin repo root:

```bash
bin/test.sh [--filter <name>]
```

Expected: PHPUnit summary `OK`.

## Review Focus

1. **The `Authorization` header gets stripped or renamed by Nginx or PHP-FPM.** The guard must read `HTTP_AUTHORIZATION`, then `REDIRECT_HTTP_AUTHORIZATION`, then `getallheaders()`. A missing header must produce a 401 with the discovery header, never a 500. Test owner: Task 10.
2. **The AI passes an ID that is valid but the wrong kind** (an attachment, a revision, a post type that isn't allowed, a missing ID). The result must be a clean `WP_Error`: 404 for missing, 403 for a type that isn't allowed. It must never be a PHP fatal or a write to that object. Test owner: Task 3.
3. **A client retries a refresh right after a network error** with the refresh token that was just rotated. Within 10 s of rotation, return `invalid_grant` *without* revoking the connection. After 10 s, treat it as reuse and revoke the whole connection (§4.6). This grace window is a small addition to the spec, so call it out at review. Test owner: Task 8.
4. **Schedule dates without a UTC offset** (`2026-12-01 09:00`) must be read in the site time zone (America/New_York). A past date publishes immediately and keeps that date. Test owner: Task 5.
5. **A `resource` parameter that differs only by a trailing slash** from the resource URL must be accepted. Any other `resource` value is rejected with `invalid_target`. Test owner: Task 9.

---

## File Structure

```
md3-wp-mcp/                             plugin repo root = plugin root
  md3-wp-mcp.php                        bootstrap, constants, requirement notice, hooks into Plugin
  composer.json, composer.lock          runtime + dev deps; vendor/ gitignored
  README.md, CHANGELOG.md, .gitignore, .distignore
  docker-compose.test.yml               php (wordpress:cli-php8.2) + db (mysql:8.0)
  bin/test.sh, bin/build-zip.sh, bin/sync-to-site.sh
  docs/design.md, docs/implementation-plan.md
  phpunit.xml.dist
  uninstall.php
  includes/
    class-plugin.php                    boot(), activate(), deactivate(); wires every component
    class-schema.php                    tables and db version
    class-settings.php                  options with defaults and validation
    class-content-sanitizer.php         kses and slug/text helpers
    class-rate-limiter.php              fixed-window transient limiter
    class-audit-log.php                 log writes, queries, pruning
    class-cleanup.php                   daily cron
    auth/class-identity.php             value object for the current OAuth identity
    auth/class-request-context.php      per-request identity holder
    auth/class-scopes.php               scope constants and normalization
    abilities/class-ability.php         abstract base + registration wrapper
    abilities/class-registry.php        category, the list of abilities, names()
    abilities/class-content-formatter.php  post to array
    abilities/class-content-writer.php  shared create/update field writer
    abilities/class-*.php               one class per tool (Tasks 3–6)
    mcp/class-server.php                create_server and transport permission
    oauth/class-oauth.php               resource_url(), issuer(), transport_allowed(), boot
    oauth/class-keys.php
    oauth/class-redirect-uri-policy.php
    oauth/class-cimd.php
    oauth/class-psr7-bridge.php
    oauth/class-server-factory.php
    oauth/class-tokens.php              connections, revocation, pruning
    oauth/entities/class-*.php          league entity implementations
    oauth/repositories/class-*.php      league repository implementations
    oauth/class-discovery.php
    oauth/class-registration.php
    oauth/class-token-endpoint.php      token + revoke
    oauth/class-authorize.php           /md3-mcp/authorize, consent POST
    oauth/class-bearer-guard.php
    admin/class-settings-page.php       tabs: content, security, connections, log, connect
    admin/class-profile-connections.php
    admin/class-diagnostics.php
    admin/views/*.php                   templates, including consent.php
  languages/md3-wp-mcp.pot, md3-wp-mcp-es_ES.po/.mo
  tests/ wp-tests-config.php, bootstrap.php, helpers/class-test-case.php,
         helpers/polylang-fakes.php, integration/test-*.php, wp/ (WordPress core, gitignored)
```

Classes are autoloaded through the Composer `classmap` of `includes/`. They are loaded with `vendor/autoload_packages.php` (Jetpack Autoloader).

---

### Task 1: Repository, packaging, schema, settings and test harness

The plugin repo already exists with `docs/design.md` and `docs/implementation-plan.md`, and its GitHub remote is set up during planning.

**Files:**
- Create:
  - `md3-wp-mcp.php`, `composer.json`, `README.md` (stub; finished in Task 12), `CHANGELOG.md`
  - `includes/class-plugin.php`, `includes/class-schema.php`, `includes/class-settings.php`
  - `.gitignore`: `vendor/`, `tests/wp/`, `*.zip`, `.phpunit.result.cache`, `.DS_Store`
  - `.distignore`: `/tests /bin /docs /.git /.github .gitignore .distignore composer.json composer.lock phpunit.xml.dist docker-compose.test.yml .phpunit.result.cache .DS_Store vendor *.zip`
  - `docker-compose.test.yml`, `bin/test.sh`, `bin/build-zip.sh`, `bin/sync-to-site.sh`
  - `phpunit.xml.dist`, `tests/wp-tests-config.php`, `tests/bootstrap.php`, `tests/helpers/class-test-case.php`
- Test: `tests/integration/test-activation.php`, `tests/integration/test-settings.php`

**Interfaces:**
- Produces:
  - `MD3\WPMCP\Plugin::boot(): void` (on `plugins_loaded`), `::activate(): void`, `::deactivate(): void`.
  - `Schema::install(): void`, `Schema::table(string $name): string`, `Schema::DB_VERSION = '1'`.
  - `Settings::post_types(): string[]`, `Settings::is_allowed_post_type(string $type): bool`.
  - `Settings::update_post_types(array $types): void`. It keeps only public, non-`attachment`, registered types.
  - `Settings::security(): array{mcp_user_id: int, dcr: bool, mcp_rate_limit: int}`.
  - `Settings::update_security(array $values): void`. It stores `mcp_user_id` only if that user's roles include `editor` and they lack `manage_options`; otherwise it stores `0`.
  - `Settings::mcp_user_id(): int` returns the stored ID, or `0` when unset, when the user no longer exists, or when the user has `manage_options`. This is checked on every call.
  - `Settings::default_author_id(): int`, `Settings::update_default_author(int $user_id): void`. The update accepts `0` or a user with `edit_posts`, and stores `0` for anything else.
  - Test base `MD3\WPMCP\Tests\Test_Case extends WP_UnitTestCase`, with helper `as_mcp_user(array $scopes, string $role = 'editor'): int` (completed in Task 3).
  - Constants `MD3_WP_MCP_VERSION = '0.1.0'`, `MD3_WP_MCP_FILE`, `MD3_WP_MCP_DIR`.

- [ ] **Step 1: Add the dependencies**

```bash
composer init --name=md3/md3-wp-mcp --type=wordpress-plugin --license=GPL-2.0-or-later -n
composer config platform.php 8.2.0
composer config allow-plugins.automattic/jetpack-autoloader true
composer config allow-plugins.roots/wordpress-core-installer true
composer config extra.wordpress-install-dir tests/wp
composer require wordpress/mcp-adapter:^0.7.1 league/oauth2-server:^9.4 nyholm/psr7:^1.8 automattic/jetpack-autoloader:^5
composer require --dev phpunit/phpunit:^9.6 yoast/phpunit-polyfills:^4 wp-phpunit/wp-phpunit:7.1.* roots/wordpress-no-content:7.1.*
```

Add `"autoload": {"classmap": ["includes/"]}`.

Expected:
- `vendor/autoload_packages.php` exists;
- `tests/wp/wp-includes/version.php` exists;
- `composer show` lists `wordpress/mcp-adapter v0.7.1`.

- [ ] **Step 2: Create the test stack and the scripts**

`docker-compose.test.yml`:
- `db`: `mysql:8.0` with `MYSQL_ROOT_PASSWORD=root`, `MYSQL_DATABASE=wordpress_test`, and `tmpfs: /var/lib/mysql`.
- `php`: `wordpress:cli-php8.2` with `user: "0"`, `entrypoint: []`, `working_dir: /app`, volume `.:/app`, and `depends_on: [db]`.

`tests/wp-tests-config.php`:
- `ABSPATH` = `__DIR__ . '/wp/'`
- `DB_NAME` `wordpress_test`, `DB_USER` `root`, `DB_PASSWORD` `root`, `DB_HOST` `db`
- `$table_prefix = 'wptests_'`
- `WP_TESTS_DOMAIN` `example.org`, `WP_TESTS_EMAIL` `admin@example.org`, `WP_TESTS_TITLE` `Test`
- `WP_PHP_BINARY` `php`
- `WP_ENVIRONMENT_TYPE` `local`

`tests/bootstrap.php`:
- loads `vendor/autoload.php` and the polyfills;
- reads `WP_PHPUNIT__DIR`;
- on `muplugins_loaded`, requires `md3-wp-mcp.php`, plus `helpers/polylang-fakes.php` when `pll_set_post_language` doesn't exist;
- then calls `Plugin::activate()`.

`bin/test.sh` (bash, `set -euo pipefail`, works from any directory):
1. `composer install` on the host when `vendor/` is missing.
2. `docker compose -f docker-compose.test.yml up -d db`.
3. Wait until `docker compose -f docker-compose.test.yml exec -T db mysqladmin ping -uroot -proot --silent` succeeds, trying up to 30 times, 2 s apart.
4. `docker compose -f docker-compose.test.yml run --rm -e WP_PHPUNIT__TESTS_CONFIG=tests/wp-tests-config.php php php vendor/bin/phpunit "$@"`

`bin/build-zip.sh`:
- Same structure as `md3-google-reviews/bin/build-zip.sh`, with one addition. After the rsync with `.distignore`, it copies `composer.json` and `composer.lock` into the staged dir, runs `composer install --no-dev --optimize-autoloader --no-interaction` there, then deletes both files.
- It produces `md3-wp-mcp-<Version>.zip` in the repo root.
- `--stage-only <dir>` leaves the staged plugin in `<dir>` without zipping.

`bin/sync-to-site.sh <site-repo>`:
- refuses unless `<site-repo>/wp-content/plugins` exists;
- runs `bin/build-zip.sh --stage-only "$TMP"`;
- runs `rsync -a --delete "$TMP/md3-wp-mcp/" "<site-repo>/wp-content/plugins/md3-wp-mcp/"`;
- prints the target and version.

- [ ] **Step 3: Write the failing tests**

```php
// test-activation.php
public function test_activation_creates_all_tables() {
    global $wpdb;
    foreach ( [ 'clients', 'auth_codes', 'access_tokens', 'refresh_tokens', 'log' ] as $t ) {
        $this->assertSame( Schema::table( $t ), $wpdb->get_var( "SHOW TABLES LIKE '" . Schema::table( $t ) . "'" ) );
    }
    $this->assertSame( '1', get_option( 'md3_wp_mcp_db_version' ) );
}
public function test_activation_changes_no_roles() {
    $this->assertFalse( get_role( 'editor' )->has_cap( 'md3_mcp_connect' ) );
}
// test-settings.php
public function test_defaults() {
    $this->assertSame( [ 'post', 'page' ], Settings::post_types() );
    $this->assertSame( [ 'mcp_user_id' => 0, 'dcr' => true, 'mcp_rate_limit' => 120 ], Settings::security() );
    $this->assertSame( 0, Settings::mcp_user_id() );
}
public function test_mcp_user_must_be_a_non_admin_editor() {
    $editor = self::factory()->user->create( [ 'role' => 'editor' ] );
    $admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
    Settings::update_security( [ 'mcp_user_id' => $admin ] );
    $this->assertSame( 0, Settings::mcp_user_id() );
    Settings::update_security( [ 'mcp_user_id' => $editor ] );
    $this->assertSame( $editor, Settings::mcp_user_id() );
    get_user_by( 'id', $editor )->add_cap( 'manage_options' );
    $this->assertSame( 0, Settings::mcp_user_id() );           // checked at read time
}
public function test_default_author_falls_back_to_mcp_user() {
    $mcp = self::factory()->user->create( [ 'role' => 'editor' ] );
    Settings::update_security( [ 'mcp_user_id' => $mcp ] );
    $this->assertSame( $mcp, Settings::default_author_id() );
    $author = self::factory()->user->create( [ 'role' => 'author' ] );
    Settings::update_default_author( $author );
    $this->assertSame( $author, Settings::default_author_id() );
    wp_delete_user( $author );
    $this->assertSame( $mcp, Settings::default_author_id() );
    Settings::update_default_author( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
    $this->assertSame( 0, (int) get_option( 'md3_wp_mcp_default_author' ) );
}
public function test_update_post_types_drops_attachment_private_and_unknown_types() {
    register_post_type( 'secret', [ 'public' => false ] );
    Settings::update_post_types( [ 'post', 'attachment', 'secret', 'nope' ] );
    $this->assertSame( [ 'post' ], Settings::post_types() );
}
public function test_is_allowed_post_type_false_when_type_unregistered_later() { /* option holds 'book', type not registered → false */ }
```

- [ ] **Step 4: Run the tests and confirm they fail**

Run: `bin/test.sh`

Expected: FAIL. The `Schema` and `Settings` classes aren't found.

- [ ] **Step 5: Implement the plugin**

- **Plugin header** (Global Constraints). On `plugins_loaded`, if the PHP version is below 8.2 or `wp_register_ability` doesn't exist, show an admin notice and stop.
- **Schema columns** (all `dbDelta`; `DATETIME` values in UTC):

| Table | Columns | Keys |
|---|---|---|
| `clients` | `id` BIGINT AUTO_INCREMENT, `client_id` VARCHAR(191), `name` VARCHAR(191), `redirect_uris` TEXT, `type` VARCHAR(8) (`dcr`/`cimd`), `created_ip_hash` CHAR(64), `completed` TINYINT(1) DEFAULT 0, `created_at` DATETIME, `last_used_at` DATETIME NULL | PK `id`, UNIQUE `client_id` |
| `auth_codes` | `id_hash` CHAR(64), `client_id`, `user_id` BIGINT, `expires_at`, `revoked` TINYINT(1) | PK `id_hash` |
| `access_tokens` | `id_hash` CHAR(64), `client_id`, `user_id`, `scopes` VARCHAR(191), `created_at`, `expires_at`, `last_used_at` NULL, `revoked` | PK `id_hash`; KEY `user_id`; KEY `expires_at` |
| `refresh_tokens` | `id_hash` CHAR(64), `access_token_hash` CHAR(64), `client_id`, `user_id`, `expires_at`, `revoked`, `rotated_at` DATETIME NULL | PK `id_hash` |
| `log` | `id` BIGINT AUTO_INCREMENT, `created_at`, `user_id`, `client_id` VARCHAR(191), `tool` VARCHAR(64), `object_type` VARCHAR(32), `object_id` BIGINT NULL, `result` VARCHAR(8), `message` VARCHAR(255), `ip_hash` CHAR(64) | PK `id`; KEY `created_at` |

- **Activation:**
  - `Schema::install()`;
  - schedule `md3_wp_mcp_cleanup` daily;
  - `flush_rewrite_rules()` (Task 9 adds the rule).
- **Deactivation:** unschedule the cron and flush rewrites.
- **Database upgrades:** `boot()` runs `Schema::install()` when `md3_wp_mcp_db_version` ≠ `DB_VERSION`.

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `bin/test.sh`

Expected: OK (7 tests).

- [ ] **Step 7: Check the release build**

Run: `bin/build-zip.sh && unzip -l md3-wp-mcp-0.1.0.zip | grep -cE 'md3-wp-mcp/(tests|bin|docs)/'`

Expected:
- "Built md3-wp-mcp-0.1.0.zip";
- a count of `0`;
- the listing contains `md3-wp-mcp/vendor/autoload_packages.php` and no `phpunit` folder.

- [ ] **Step 8: Commit, after the user confirms**

Message: `MD3-30 Scaffold the plugin, packaging scripts, schema, settings and tests`.

---

### Task 2: Shared services (identity, scopes, sanitizer, rate limiter, audit log)

**Files:**
- Create:
  - `includes/auth/class-identity.php`, `class-request-context.php`, `class-scopes.php`
  - `includes/class-content-sanitizer.php`, `class-rate-limiter.php`, `class-audit-log.php`
- Test: `tests/integration/test-shared-services.php`

**Interfaces:**
- Produces:
  - `final readonly class Auth\Identity { __construct(public int $user_id, public string $client_id, public array $scopes, public string $token_hash) }`
  - `Auth\Request_Context::set(Identity $i): void`, `::get(): ?Identity`, `::clear(): void`, `::has_scope(string $scope): bool`
  - `Auth\Scopes::READ|WRITE|PUBLISH|DELETE`, `::all(): string[]`, `::descriptions(): array<string,string>` (translatable)
  - `Auth\Scopes::normalize(string $requested): ?string[]`:
    - an empty string gives `[read, write]`;
    - an unknown scope gives `null`;
    - `read` is always included;
    - the result is de-duplicated and in canonical order.
  - `Content_Sanitizer::html(string $html): string`, `::plain(string $text): string` (`wp_strip_all_tags` + trim), `::slug(string $s): string` (`sanitize_title`)
  - `Rate_Limiter::hit(string $bucket, string $key, int $limit, int $window = 60): bool`. It returns `true` while the request is within the limit.
  - `Audit_Log::record(string $tool, string $result, string $object_type = '', ?int $object_id = null, string $message = ''): void`
  - `Audit_Log::query(array $filters, int $page = 1, int $per_page = 50): array{rows: array, total: int}`, with filters `tool`, `result`, `user_id`, `client_id`
  - `Audit_Log::prune(int $days = 90): int`
  - `Plugin::client_ip_hash(): string` = `hash('sha256', wp_salt('auth') . ($_SERVER['REMOTE_ADDR'] ?? ''))`

- [ ] **Step 1: Write the failing tests**

```php
public function test_normalize_scopes() {
    $this->assertSame( [ 'content:read', 'content:write' ], Scopes::normalize( '' ) );
    $this->assertSame( [ 'content:read', 'content:publish' ], Scopes::normalize( 'content:publish' ) );
    $this->assertNull( Scopes::normalize( 'content:read admin' ) );
}
public function test_html_strips_scripts_handlers_and_iframes_but_keeps_block_comments() {
    $in  = "<!-- wp:paragraph --><p onclick=\"x()\">Hi<script>alert(1)</script></p><!-- /wp:paragraph -->"
         . '<!-- wp:md3/content-by-claude {"prompt":"x"} /--><iframe src="https://e.com"></iframe>';
    $out = Content_Sanitizer::html( $in );
    $this->assertStringContainsString( '<!-- wp:paragraph -->', $out );
    $this->assertStringContainsString( '<!-- wp:md3/content-by-claude {"prompt":"x"} /-->', $out );
    $this->assertStringNotContainsString( 'script', $out );
    $this->assertStringNotContainsString( 'onclick', $out );
    $this->assertStringNotContainsString( 'iframe', $out );
}
public function test_rate_limiter_blocks_after_limit_per_key() {
    for ( $i = 0; $i < 3; $i++ ) { $this->assertTrue( Rate_Limiter::hit( 't', 'a', 3 ) ); }
    $this->assertFalse( Rate_Limiter::hit( 't', 'a', 3 ) );
    $this->assertTrue( Rate_Limiter::hit( 't', 'b', 3 ) );
}
public function test_audit_log_records_identity_and_prunes_old_rows() { /* set context; record; query → user_id & client_id match; backdate row 91 days; prune → 1 */ }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `bin/test.sh --filter Shared`

Expected: FAIL, because the classes are missing.

- [ ] **Step 3: Implement the classes from the Interfaces block**

- Rate limiter transient key: `md3_mcp_rl_{$bucket}_` + md5 of key + the window start.
- `Content_Sanitizer::html()` calls `wp_kses_post()` directly, so it doesn't depend on the user's `unfiltered_html`.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `bin/test.sh --filter Shared`

Expected: OK.

- [ ] **Step 5: Commit, after the user confirms**

Message: `MD3-30 Shared auth context, scopes, sanitizer, rate limiter, audit log`.

---

### Task 3: Ability framework and read tools

**Files:**
- Create:
  - `includes/abilities/class-ability.php`, `class-registry.php`, `class-content-formatter.php`
  - `class-list-post-types.php`, `class-list-content.php`, `class-get-content.php`, `class-list-terms.php`
- Modify: `tests/helpers/class-test-case.php`
- Test: `tests/integration/test-read-abilities.php`

**Interfaces:**
- Consumes: `Request_Context`, `Scopes`, `Settings`, `Audit_Log` (Task 2).
- Produces:
  - `abstract class Abilities\Ability` with:
    - `abstract name(): string` (the part after `md3-wp-mcp/`), `label(): string`, `description(): string`
    - `input_schema(): array`, `output_schema(): array`, `scope(): string`, `annotations(): array`
    - `abstract execute(array $input): array|\WP_Error`
    - `check_permission(?array $input): bool|\WP_Error`. The base checks the scope (403 `md3_mcp_insufficient_scope`), then calls `object_permission($input)`.
    - `protected object_permission(array $input): bool|\WP_Error` (default `true`)
    - `protected load_post(int $id): \WP_Post|\WP_Error`: 404 `md3_mcp_not_found` when the post is missing, or is an attachment or revision; 403 `md3_mcp_type_not_allowed` when the type isn't allowed.
    - `register(): void`. It calls `wp_register_ability('md3-wp-mcp/' . name(), …)`:
      - `category => 'md3-wp-mcp'`;
      - `execute_callback` wraps `execute()` and calls `Audit_Log::record` with `ok` or `error`;
      - `permission_callback` wraps `check_permission()` and logs `denied` on failure;
      - `meta => ['mcp' => ['public' => false, 'type' => 'tool'], 'annotations' => annotations(), 'show_in_rest' => false]`.
  - `Abilities\Registry::abilities(): Ability[]`, `::names(): string[]` (full names), `::register(): void` (category on `wp_abilities_api_categories_init`, abilities on `wp_abilities_api_init`)
  - `Content_Formatter::summary(\WP_Post $p): array{id, type, title, status, slug, date, modified, link, language}`
  - `Content_Formatter::full(\WP_Post $p): array`. It is the summary plus:
    - `content` (raw), `excerpt`, `parent`, `menu_order`, `template`;
    - `terms` (taxonomy → `{id, name, slug}[]`) and `featured_image` (`{id, url, alt}` or null);
    - `seo` (`{title, description, focus_keyword}`), `translations` (lang → id);
    - `edit_link`, `preview_link`.
  - Tools:

| Tool | Input | Output |
|---|---|---|
| `list-post-types` | none | allowed types with `{name, label, hierarchical, supports[], taxonomies[]}` |
| `list-content` | `type` (default `post`), `status` (enum `publish draft pending future private trash any`, default `any`, where `any` excludes trash), `search`, `language`, `taxonomy`+`term_id`, `author`, `after`/`before` (Y-m-d), `page` ≥ 1, `per_page` 1–50 (default 20) | `{items: summary[], total, pages}` |
| `get-content` | `id` | `full()`, requires `current_user_can('edit_post', id)` |
| `list-terms` | `taxonomy` (must belong to an allowed type), `search`, `language` | `{id, name, slug, parent, count}[]` |

  - `Test_Case::as_mcp_user(array $scopes, string $role = 'editor'): int`:
    - creates the user with `$role`;
    - writes its ID straight into `md3_wp_mcp_security['mcp_user_id']`, skipping `update_security()`'s Editor-only check so tests can try other roles;
    - calls `wp_set_current_user`;
    - sets `Request_Context` with client `test-client` and token hash `str_repeat('a', 64)`.
  - `Test_Case::run_ability(string $short_name, array $input): mixed`. It calls `wp_get_ability('md3-wp-mcp/' . $short_name)->execute($input)`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_abilities_are_not_public_on_default_mcp_server() {
    foreach ( Registry::names() as $n ) { $this->assertFalse( wp_get_ability( $n )->get_meta()['mcp']['public'] ); }
}
public function test_list_content_requires_read_scope() {
    $this->as_mcp_user( [] );
    $this->assertWPError( $this->run_ability( 'list-content', [] ) );
}
public function test_list_content_returns_drafts_and_respects_per_page_cap() {
    self::factory()->post->create_many( 3, [ 'post_status' => 'draft' ] );
    $this->as_mcp_user( [ 'content:read' ] );
    $r = $this->run_ability( 'list-content', [ 'status' => 'draft', 'per_page' => 2 ] );
    $this->assertCount( 2, $r['items'] ); $this->assertSame( 3, $r['total'] );
}
public function test_get_content_on_attachment_is_not_found() {           // Review Focus 2
    $id = self::factory()->attachment->create();
    $this->as_mcp_user( [ 'content:read' ] );
    $this->assertSame( 'md3_mcp_not_found', $this->run_ability( 'get-content', [ 'id' => $id ] )->get_error_code() );
}
public function test_get_content_on_disallowed_type_is_forbidden() {      // Review Focus 2
    register_post_type( 'book', [ 'public' => true ] ); $id = self::factory()->post->create( [ 'post_type' => 'book' ] );
    $this->as_mcp_user( [ 'content:read' ] );
    $this->assertSame( 'md3_mcp_type_not_allowed', $this->run_ability( 'get-content', [ 'id' => $id ] )->get_error_code() );
}
public function test_get_content_includes_seo_fields_and_raw_blocks() { /* set the 3 rank_math metas; assert seo[] and content */ }
public function test_list_post_types_only_returns_allowed_types() { /* default → post, page */ }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `bin/test.sh --filter Read`

Expected: FAIL.

- [ ] **Step 3: Implement the ability base, registry, formatter and the four read tools**

`language` filtering, and the `language` and `translations` fields, use `pll_get_post_language` and `pll_get_post_translations`, or `lang` in `WP_Query`, only when those functions exist. Otherwise they are `null` or `{}`.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `bin/test.sh --filter Read`

Expected: OK.

- [ ] **Step 5: Commit, after the user confirms**

Message: `MD3-30 Ability framework and read tools`.

---

### Task 4: Create and update content, and create terms

**Files:**
- Create:
  - `includes/abilities/class-content-writer.php`
  - `class-create-content.php`, `class-update-content.php`, `class-create-term.php`
- Test: `tests/integration/test-write-abilities.php`

**Interfaces:**
- Consumes: `Ability`, `load_post()`, `Content_Formatter`, `Content_Sanitizer`.
- Produces:
  - `Content_Writer::save(array $input, ?\WP_Post $existing): int|\WP_Error`. It accepts:
    - `title`, `content`, `excerpt`, `slug`;
    - `terms` (`{taxonomy: int[]}`; only taxonomies of the post's type; unknown term IDs give 400 `md3_mcp_bad_term`);
    - `featured_media` (an attachment ID);
    - `seo` (`{title?, description?, focus_keyword?}`);
    - `parent`, `menu_order`, `template` (pages and hierarchical types only; `template` must be `default` or a key of `wp_get_theme()->get_page_templates(null, $type)`).

    It always writes the Brevo opt-out meta. Any other key gives 400 `md3_mcp_unknown_field`. The schemas set `additionalProperties: false`, and the writer double-checks.
  - `create-content` (scope `write`):
    - input `type` (default `post`, must be allowed) and `status` (enum `draft|pending`, default `draft`), plus the writer fields;
    - requires `edit_posts` for that type;
    - `post_author` = `Settings::default_author_id()` (the "Author for new content", falling back to the MCP user);
    - output `full()`.
  - `update-content` (scope `write`):
    - input `id` plus the writer fields, and optional `status` (enum `draft|pending`);
    - requires `edit_post`;
    - if the post is `publish`, `future` or `private`, `content:publish` is also required (403 `md3_mcp_insufficient_scope`);
    - output `full()`.
  - `create-term` (scope `write`):
    - input `taxonomy`, `name`, `slug?`, `parent?`, `language?`;
    - requires the taxonomy's `edit_terms` (or `manage_terms`) capability;
    - output `{id, name, slug}`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_create_content_saves_sanitized_draft_with_seo_and_brevo_off() {
    $this->as_mcp_user( [ 'content:read', 'content:write' ] );
    $r = $this->run_ability( 'create-content', [ 'title' => 'T', 'content' => '<p>x<script>1</script></p>', 'seo' => [ 'title' => 'S' ] ] );
    $p = get_post( $r['id'] );
    $this->assertSame( 'draft', $p->post_status );
    $this->assertStringNotContainsString( 'script', $p->post_content );
    $this->assertSame( 'S', get_post_meta( $p->ID, 'rank_math_title', true ) );
    $this->assertSame( '0', get_post_meta( $p->ID, 'sib_push_send_notification', true ) );
}
public function test_create_content_rejects_publish_status_and_unknown_fields() { /* status=publish → WP_Error; meta=[..] → md3_mcp_unknown_field */ }
public function test_update_published_post_requires_publish_scope() {
    $id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
    $this->as_mcp_user( [ 'content:read', 'content:write' ] );
    $this->assertSame( 'md3_mcp_insufficient_scope', $this->run_ability( 'update-content', [ 'id' => $id, 'title' => 'N' ] )->get_error_code() );
}
public function test_update_only_changes_given_fields_and_keeps_a_revision() { /* title only; content unchanged; wp_get_post_revisions count ≥ 1 */ }
public function test_page_template_must_exist() { /* template 'nope.php' → WP_Error */ }
public function test_author_role_cannot_create_page() { /* role author lacks edit_pages → WP_Error */ }
public function test_create_content_credits_configured_author_and_mcp_user_does_the_work() {
    $mcp    = $this->as_mcp_user( [ 'content:read', 'content:write' ] );
    $author = self::factory()->user->create( [ 'role' => 'author' ] );
    Settings::update_default_author( $author );
    $r = $this->run_ability( 'create-content', [ 'title' => 'T', 'content' => '<p>x</p>' ] );
    $this->assertSame( $author, (int) get_post( $r['id'] )->post_author );
    $this->run_ability( 'update-content', [ 'id' => $r['id'], 'title' => 'T2' ] );   // Editor: edit_others_posts
    $this->assertSame( $author, (int) get_post( $r['id'] )->post_author );          // updates never change the author
    $revisions = wp_get_post_revisions( $r['id'] );
    $this->assertSame( $mcp, (int) reset( $revisions )->post_author );               // the revision is by the MCP user
}
public function test_create_content_falls_back_to_mcp_user_when_author_deleted() { /* set author, wp_delete_user → post_author === MCP user */ }
public function test_author_input_is_rejected() { /* create-content with 'author' => 1 → md3_mcp_unknown_field */ }
public function test_create_term_in_post_category() { /* returns id; term exists */ }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `bin/test.sh --filter Write`

Expected: FAIL.

- [ ] **Step 3: Implement the writer and the three tools**

- Use `wp_insert_post` / `wp_update_post` with `true` for `$wp_error`.
- Use `wp_set_object_terms`, `set_post_thumbnail` and `update_post_meta` for the allowlisted keys only.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `bin/test.sh --filter Write`

Expected: OK.

- [ ] **Step 5: Commit, after the user confirms**

Message: `MD3-30 Create/update content and terms tools`.

---

### Task 5: Publish, schedule, unpublish, trash and restore

**Files:**
- Create in `includes/abilities/`:
  - `class-publish-content.php`
  - `class-unpublish-content.php`
  - `class-trash-content.php`
  - `class-restore-content.php`
- Test: `tests/integration/test-lifecycle-abilities.php`

**Interfaces:**
- Consumes: `Ability`, `load_post()`, `Content_Formatter`.
- Produces:

| Tool | Scope | Input | Capability | Behavior | Annotations |
|---|---|---|---|---|---|
| `publish-content` | publish | `id`, `date?` | `publish_posts` of the type and `edit_post` | Without `date`: status `publish`. With `date` (ISO 8601, or `Y-m-d H:i`; no offset means site time zone): status `future` if later than now, else `publish` with that `post_date`. Writes the Brevo opt-out. | |
| `unpublish-content` | publish | `id` | | Status `draft`; `future` also goes to `draft`. | |
| `trash-content` | delete | `id` | `delete_post` | `wp_trash_post` | `destructiveHint: true` |
| `restore-content` | delete | `id` | | `wp_untrash_post`, which leaves the post as `draft` | |

All four return `summary()`. Nothing calls `wp_delete_post`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_publish_requires_publish_scope() { /* scopes read+write → md3_mcp_insufficient_scope */ }
public function test_publish_now() { /* draft → publish */ }
public function test_schedule_without_offset_uses_site_timezone() {                    // Review Focus 4
    update_option( 'timezone_string', 'America/New_York' );
    $id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
    $this->as_mcp_user( [ 'content:read', 'content:publish' ] );
    $this->run_ability( 'publish-content', [ 'id' => $id, 'date' => '2099-12-01 09:00' ] );
    $p = get_post( $id );
    $this->assertSame( 'future', $p->post_status );
    $this->assertSame( '2099-12-01 09:00:00', $p->post_date );
    $this->assertSame( '2099-12-01 14:00:00', $p->post_date_gmt );
}
public function test_past_date_publishes_immediately_with_that_date() { /* '2020-01-02 10:00' → publish, post_date kept */ }
public function test_trash_and_restore_never_delete() { /* trash → 'trash'; restore → 'draft'; get_post not null */ }
public function test_contributor_cannot_publish_even_with_scope() { /* role contributor → WP_Error */ }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `bin/test.sh --filter Lifecycle`

Expected: FAIL.

- [ ] **Step 3: Implement the four tools**

Parse dates with `date_create_immutable($date, wp_timezone())`. Invalid dates give 400 `md3_mcp_bad_date`.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `bin/test.sh --filter Lifecycle`

Expected: OK.

- [ ] **Step 5: Commit, after the user confirms**

Message: `MD3-30 Publish, schedule, unpublish, trash, restore tools`.

---

### Task 6: Media, featured image and Polylang translations

**Files:**
- Create in `includes/abilities/`:
  - `class-upload-media.php`
  - `class-set-featured-image.php`
  - `class-link-translations.php`
- Create `tests/helpers/polylang-fakes.php`.
- Test: `tests/integration/test-media-translation-abilities.php`

**Interfaces:**
- Produces:
  - `upload-media` (scope `write`, requires `upload_files`):
    - input: exactly one of `url` or `base64` (with `filename`), plus `alt?`, `title?`, `caption?`, `attach_to?` (a post ID the user can edit);
    - output `{id, url, mime, width, height, alt}`.
    - A URL goes through `download_url($url, 30)`, which uses `wp_safe_remote_get` and refuses local or private hosts.
    - Base64 is decoded into `wp_tempnam()`.
    - Files over the size limit give 413 `md3_mcp_too_large`.
    - The MIME type is checked with `wp_check_filetype_and_ext`, and anything outside the four types gives 415 `md3_mcp_bad_type`.
    - The file is then passed to `media_handle_sideload`.
  - `set-featured-image` (scope `write`, `edit_post`): input `id`, `media_id` (must be an image attachment); output `summary()`.
  - `link-translations` (scope `write`):
    - registered only when `function_exists('pll_set_post_language')` at `wp_abilities_api_init`;
    - input `id`, `language` (must be in `pll_languages_list()`), and `translations?` (`{lang: id}`; every ID must be editable and of the same type);
    - calls `pll_set_post_language` and `pll_save_post_translations` (merged with existing translations);
    - output `{id, language, translations}`.
  - The fake functions in `polylang-fakes.php` (`pll_set_post_language`, `pll_get_post_language`, `pll_save_post_translations`, `pll_get_post_translations`, `pll_languages_list` → `['en','es']`) store state in post meta `_fake_pll_lang` and the option `_fake_pll_tr`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_upload_base64_png_and_set_alt() { /* 1x1 png → id; alt meta set; mime image/png */ }
public function test_upload_rejects_non_image() { /* base64 of "hello" as a.txt → md3_mcp_bad_type */ }
public function test_upload_rejects_oversize() { /* 10485761 bytes → md3_mcp_too_large */ }
public function test_upload_url_to_loopback_is_refused() { /* url http://127.0.0.1/x.png → WP_Error, no attachment created */ }
public function test_set_featured_image_rejects_non_image_attachment() { }
public function test_link_translations_sets_language_and_pairs() { /* en post + es post → translations ['en'=>a,'es'=>b] */ }
public function test_link_translations_rejects_unknown_language() { }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `bin/test.sh --filter Media`

Expected: FAIL.

- [ ] **Step 3: Implement the three tools**

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `bin/test.sh --filter Media`

Expected: OK.

- [ ] **Step 5: Commit, after the user confirms**

Message: `MD3-30 Media, featured image, translation tools`.

---

### Task 7: MCP server and transport permission

**Files:**
- Create `includes/mcp/class-server.php`.
- Modify `includes/class-plugin.php`. It calls `\WP\MCP\Core\McpAdapter::instance()` and hooks `Server::register` on `mcp_adapter_init`.
- Test: `tests/integration/test-mcp-server.php`

**Interfaces:**
- Consumes: `Registry::names()`, `Request_Context`, `Rate_Limiter`, `Settings::security()`.
- Produces:
  - `Mcp\Server::register(\WP\MCP\Core\McpAdapter $adapter): void`. It calls `create_server` with these arguments:

    ```php
    'md3-wp-mcp', 'md3-wp-mcp', 'mcp', 'MD3 WP MCP', <description>, MD3_WP_MCP_VERSION,
    [ HttpTransport::class ], ErrorLogMcpErrorHandler::class, NullMcpObservabilityHandler::class,
    Registry::names(), [], [], [ Server::class, 'transport_permission' ]
    ```

  - `Mcp\Server::transport_permission(\WP_REST_Request $r): bool|\WP_Error` checks, in order:
    1. no `Request_Context` identity: 401 `md3_mcp_unauthorized`;
    2. `Settings::mcp_user_id()` is 0, or isn't the current user: 403 `md3_mcp_not_mcp_user`. This also covers an MCP user who is unset, deleted or an administrator.
    3. `Rate_Limiter::hit('mcp', $identity->token_hash, mcp_rate_limit)` fails: 429 `md3_mcp_rate_limited`.
  - `Test_Case::mcp_call(string $method, array $params = []): \WP_REST_Response`:
    - runs `initialize` once, keeping the `Mcp-Session-Id` header;
    - then POSTs JSON-RPC 2.0 to `/md3-wp-mcp/mcp` through `rest_get_server()->dispatch()`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_tools_list_contains_exactly_our_tools() {
    $this->as_mcp_user( Scopes::all() );
    $names = wp_list_pluck( $this->mcp_call( 'tools/list' )->get_data()['result']['tools'], 'name' );
    $this->assertEqualsCanonicalizing( array_map( fn( $n ) => str_replace( '/', '-', $n ), Registry::names() ), $names );
    // Exact tool-name mapping is the adapter's; assert against its sanitizer if it differs.
}
public function test_cookie_user_without_oauth_identity_is_401() { /* wp_set_current_user(editor) only → status 401 */ }
public function test_administrator_is_refused() { /* as_mcp_user(all,'administrator') → 403 md3_mcp_not_mcp_user */ }
public function test_other_editor_is_refused() { /* as_mcp_user(all) then switch Request_Context/current user to a second editor → 403 */ }
public function test_no_mcp_user_configured_is_refused() { /* option mcp_user_id = 0 → 403 md3_mcp_not_mcp_user */ }
public function test_rate_limit_returns_429() { /* mcp_rate_limit=2 → third call 429 */ }
public function test_tools_call_create_content_end_to_end() { /* tools/call create-content → draft exists */ }
public function test_default_server_does_not_list_our_tools() { /* if mcp-adapter-default-server exists, its discover-abilities omits md3-wp-mcp/* */ }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `bin/test.sh --filter Mcp_Server`

Expected: FAIL.

- [ ] **Step 3: Implement**

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `bin/test.sh --filter Mcp_Server`

Expected: OK.

- [ ] **Step 5: Commit, after the user confirms**

Message: `MD3-30 MCP server with transport permission`.

---

### Task 8: OAuth core (keys, entities, repositories, CIMD, server factory, tokens)

**Files:**
- Create:
  - `includes/oauth/class-oauth.php`, `class-keys.php`, `class-redirect-uri-policy.php`, `class-cimd.php`
  - `includes/oauth/class-psr7-bridge.php`, `class-server-factory.php`, `class-tokens.php`
  - `includes/oauth/entities/`: `class-client-entity.php`, `class-scope-entity.php`, `class-access-token-entity.php`, `class-auth-code-entity.php`, `class-refresh-token-entity.php`, `class-user-entity.php`
  - `includes/oauth/repositories/`: `class-client-repository.php`, `class-scope-repository.php`, `class-access-token-repository.php`, `class-auth-code-repository.php`, `class-refresh-token-repository.php`
- Test: `tests/integration/test-oauth-core.php`

**Interfaces:**
- Produces:
  - `OAuth\OAuth::resource_url(): string`, `::issuer(): string`, `::authorize_url(): string`, `::transport_allowed(): bool`, `::boot(): void` (wires Tasks 9–10 only when `transport_allowed()`).
  - `OAuth\Keys::get(): array{private: CryptKey, public: CryptKey, encryption: string}`. Constants take priority. Otherwise it generates RSA 2048 with `openssl_pkey_new` and stores it with `add_option(..., '', false)`.
  - `OAuth\Keys::rotate(): void` deletes both options (when no constants are set) and calls `Tokens::revoke_all()`.
  - `OAuth\Redirect_Uri_Policy::allowed(string $uri): bool`, per §4.3:
    - `https` on a public host;
    - `http` only for `localhost`, `127.0.0.1` and `[::1]`;
    - schemes from `apply_filters('md3_wp_mcp_redirect_schemes', ['claude','cursor','chatgpt','vscode'])`;
    - no fragment and no `*`.
  - `OAuth\Cimd::is_cimd(string $client_id): bool` (an https URL with a non-empty path).
  - `OAuth\Cimd::resolve(string $url): ?array{client_id, client_name, redirect_uris}`. It uses `wp_safe_remote_get` with `timeout 5` and `limit_response_size 65536`, and caches in transient `md3_mcp_cimd_` . md5 for `DAY_IN_SECONDS`. It returns `null` when the doc's `client_id` ≠ the URL or `redirect_uris` is invalid.
  - `Access_Token_Entity::toString()` overrides the JWT build to set:
    - `aud` = `OAuth::resource_url()`;
    - `sub` = user ID;
    - `jti`, `iat`, `nbf`, `exp`;
    - `scopes`;
    - claim `cid` = client ID.
  - Repositories store `hash('sha256', $identifier)`, never raw IDs.
  - `Client_Repository::getClientEntity($id)` checks the DB row first, then CIMD. `validateClient()` passes for public clients using `authorization_code` or `refresh_token`.
  - `Refresh_Token_Repository::revokeRefreshToken($id)` sets `revoked=1, rotated_at=now`.
  - `Refresh_Token_Repository::isRefreshTokenRevoked($id)`: when the token was rotated more than 10 s ago, it calls `Tokens::revoke_connection($client_id, $user_id)`. Either way it returns `true`. This is Review Focus 3.
  - `Access_Token_Repository::isAccessTokenRevoked($id)` also returns `true` when:
    - the token's user is no longer `Settings::mcp_user_id()`. This covers deletion, a different MCP user, and gaining `manage_options`.
  - `Server_Factory::authorization_server(): AuthorizationServer`:
    - `AuthCodeGrant` (code `PT1M`, refresh `P30D`) and `RefreshTokenGrant` (`P30D`);
    - access token TTL `PT1H`;
    - default scope `content:read content:write`.
  - `Server_Factory::resource_server(): ResourceServer`
  - `Psr7_Bridge::from_rest(\WP_REST_Request): ServerRequestInterface`, `::to_rest(ResponseInterface): \WP_REST_Response`, `::from_query(array $query): ServerRequestInterface`
  - `Tokens::revoke_connection(string $client_id, int $user_id): int`, `::revoke_all(): int`, `::connections(?int $user_id = null): array<array{client_id, client_name, user_id, scopes, created_at, last_used_at}>`, `::prune(): int` (expired codes and tokens, plus incomplete clients older than 24 h)

- [ ] **Step 1: Write the failing tests**

```php
public function test_redirect_policy() {
    $this->assertTrue( Redirect_Uri_Policy::allowed( 'https://claude.ai/api/mcp/auth_callback' ) );
    $this->assertTrue( Redirect_Uri_Policy::allowed( 'http://127.0.0.1:33418/callback' ) );
    $this->assertFalse( Redirect_Uri_Policy::allowed( 'http://evil.com/cb' ) );
    $this->assertFalse( Redirect_Uri_Policy::allowed( 'https://10.0.0.5/cb' ) );
    $this->assertFalse( Redirect_Uri_Policy::allowed( 'https://a.com/cb#x' ) );
    $this->assertFalse( Redirect_Uri_Policy::allowed( 'javascript:alert(1)' ) );
}
public function test_keys_are_generated_once_and_not_autoloaded() { }
public function test_cimd_rejects_doc_whose_client_id_differs() { /* pre_http_request returns doc with other client_id → null */ }
public function test_access_token_jwt_has_resource_audience_and_client_claim() { /* issue via repository + entity; parse with lcobucci → aud, cid */ }
public function test_rotated_refresh_token_within_grace_does_not_revoke_connection() { /* rotated_at = now-5s → isRevoked true, access tokens still valid */ }
public function test_rotated_refresh_token_after_grace_revokes_connection() {             // Review Focus 3
    /* rotated_at = now-60s → isRevoked true AND all access_tokens for client+user revoked */
}
public function test_access_token_revoked_when_mcp_user_changes_or_becomes_admin() { }
public function test_tokens_are_stored_hashed() { /* no table row contains the raw identifier */ }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `bin/test.sh --filter Oauth_Core`

Expected: FAIL.

- [ ] **Step 3: Implement the classes from the Interfaces block**

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `bin/test.sh --filter Oauth_Core`

Expected: OK.

- [ ] **Step 5: Commit, after the user confirms**

Message: `MD3-30 OAuth core on league/oauth2-server`.

---

### Task 9: OAuth endpoints (discovery, registration, authorize and consent, token, revoke)

**Files:**
- Create:
  - `includes/oauth/class-discovery.php`, `class-registration.php`, `class-token-endpoint.php`, `class-authorize.php`
  - `includes/admin/views/consent.php`
- Test: `tests/integration/test-oauth-endpoints.php`

**Interfaces:**
- Consumes: everything from Task 8, plus `Rate_Limiter`, `Settings::security()`, `Scopes`.
- Produces:
  - `Discovery::protected_resource_metadata(): array`:

    ```
    { resource, authorization_servers: [issuer], scopes_supported, bearer_methods_supported: ['header'], resource_name: 'MD3 WP MCP' }
    ```

  - `Discovery::authorization_server_metadata(): array` (§4.2). It includes `registration_endpoint` only when `dcr` is on, `client_id_metadata_document_supported: true`, and `revocation_endpoint`.
  - `Discovery::paths(): array<string,string>`, a map from request path to document. The paths:
    - `/.well-known/oauth-protected-resource` + the resource path;
    - `/.well-known/oauth-authorization-server/md3-mcp`;
    - `/.well-known/openid-configuration/md3-mcp`.

    All are prefixed with the home path.
  - `Discovery::maybe_serve(): void` runs on `parse_request` priority 0. It serves the bare `/.well-known/oauth-protected-resource` at priority 99. It sends JSON with `Cache-Control: no-store` and `Access-Control-Allow-Origin: *`, then exits.
  - REST routes under `md3-wp-mcp/v1` (all `permission_callback => '__return_true'`; access control happens in the handlers):
    - `POST /oauth/register` → `Registration::handle()`:
      - 403 `md3_mcp_dcr_disabled` when DCR is off;
      - 429 when rate limited or over 50 clients;
      - 400 `invalid_redirect_uri` or `invalid_client_metadata`;
      - 201 RFC 7591 body (`client_id` = `'md3_' . wp_generate_password(32, false)`).
    - `POST /oauth/token` → `Token_Endpoint::token()`: rate limit, the league response, `Cache-Control: no-store`.
    - `POST /oauth/revoke` → `Token_Endpoint::revoke()`: RFC 7009, always 200. `client_id` must match the token's client. It accepts a JWT access token (jti) or a refresh token (decrypted with the encryption key), and revokes that token plus its pair.
  - `Authorize::register(): void`:
    - adds the rewrite rule `^md3-mcp/authorize/?$` to `index.php?md3_mcp_authorize=1`, plus the query var;
    - handles it on `parse_request` priority 1.
  - `Authorize::validate(array $query): AuthorizationRequest|\WP_Error`. On top of league's checks:
    - `code_challenge_method` must be `S256`;
    - `resource` must be empty or equal `resource_url()`, ignoring a trailing slash; otherwise `invalid_target`.
  - `Authorize::complete(array $query, int $user_id, array $approved_scopes, bool $approved): string` returns the redirect URL:
    - approved scopes are intersected with the requested ones, and `content:read` is always added;
    - DCR clients are marked `completed=1`;
    - CIMD clients get a `clients` row inserted or updated.
  - `Authorize` request handling:
    - **GET:** check the transport, rate limit and `validate()` (on error, `wp_die` with status 400), then `auth_redirect()`. If no MCP user is configured, `wp_die` with 503 and a "choose the MCP user in Settings > MD3 WP MCP" message. If the signed-in user isn't `Settings::mcp_user_id()`, `wp_die` with 403 naming the MCP user's login ("Sign in as <login> to connect"), plus a log-out link back to this URL. Otherwise store the query in transient `md3_mcp_consent_{token}` (600 s, bound to the user ID) and render `consent.php`.
    - **POST:** nonce `md3_mcp_consent_{token}`, user match, then `complete()` and `wp_redirect`.
  - `consent.php` is a standalone HTML page, translatable, with no theme. It shows:
    - the client name and redirect host;
    - the scope checkboxes, with `read` disabled and checked, `write` checked, `publish` and `delete` unchecked;
    - Approve and Deny buttons.

- [ ] **Step 1: Write the failing tests**

```php
public function test_as_metadata_matches_spec() {
    $m = Discovery::authorization_server_metadata();
    $this->assertSame( home_url( '/md3-mcp' ), $m['issuer'] );
    $this->assertSame( [ 'S256' ], $m['code_challenge_methods_supported'] );
    $this->assertSame( [ 'none' ], $m['token_endpoint_auth_methods_supported'] );
    $this->assertTrue( $m['client_id_metadata_document_supported'] );
}
public function test_registration_endpoint_hidden_and_refused_when_dcr_off() { }
public function test_register_rejects_private_redirect_and_more_than_five() { }
public function test_register_rate_limited_after_ten_per_ip() { }
public function test_validate_rejects_plain_pkce() { }
public function test_validate_resource_trailing_slash_ok_other_resource_rejected() {     // Review Focus 5
    /* resource_url().'/' → AuthorizationRequest ; 'https://other/' → WP_Error invalid_target */
}
public function test_full_code_flow_issues_tokens_with_approved_scopes_only() {
    /* register → validate → complete(user, ['content:write']) → parse code from redirect
       → POST /oauth/token with code_verifier → 200, access_token, refresh_token, scope 'content:read content:write' */
}
public function test_deny_redirects_with_access_denied() { }
public function test_refresh_rotates_and_old_token_fails() { }
public function test_revoke_access_token_then_it_is_rejected() { }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `bin/test.sh --filter Oauth_Endpoints`

Expected: FAIL.

- [ ] **Step 3: Implement the endpoints, the consent view, and the rewrite flush on activation**

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `bin/test.sh --filter Oauth_Endpoints`

Expected: OK.

- [ ] **Step 5: Commit, after the user confirms**

Message: `MD3-30 OAuth discovery, registration, consent, token, revoke`.

---

### Task 10: Bearer guard and end-to-end request flow

**Files:**
- Create `includes/oauth/class-bearer-guard.php`.
- Test: `tests/integration/test-bearer-guard.php`

**Interfaces:**
- Consumes: `Server_Factory::resource_server()`, `OAuth::resource_url()`, `Request_Context`, `Identity`.
- Produces:
  - `Bearer_Guard::register(): void`
  - `Bearer_Guard::authorization_header(): string`. It checks `HTTP_AUTHORIZATION`, then `REDIRECT_HTTP_AUTHORIZATION`, then `getallheaders()['Authorization']`, case-insensitive.
  - `Bearer_Guard::is_mcp_path(string $request_uri, array $get): bool`. It matches the path of `rest_url('md3-wp-mcp/mcp')`, or `?rest_route=/md3-wp-mcp/mcp`.
  - `Bearer_Guard::determine_current_user(mixed $user): mixed` (priority 30). Only when there is no user yet, the path is the MCP path and the scheme is Bearer, it:
    1. validates the token;
    2. checks that `aud` equals `resource_url()`;
    3. sets `Request_Context` with the client from the `access_tokens` row, the scopes and the jti hash;
    4. updates `last_used_at` at most once per 60 s;
    5. returns the user ID.

    Failures are recorded for `authentication_errors`.
  - `Bearer_Guard::authentication_errors(mixed $result): mixed` (priority 5) returns 401 `md3_mcp_invalid_token` for a bearer that was sent but is invalid.
  - `Bearer_Guard::before_callbacks($response, $handler, \WP_REST_Request $r)` returns 403 `md3_mcp_route_forbidden` when there is an identity and the route isn't `/md3-wp-mcp/mcp`.
  - `Bearer_Guard::after_callbacks($response, $handler, \WP_REST_Request $r)` adds the `WWW-Authenticate` header on 401 and 403 from the MCP route:
    - `Bearer resource_metadata="<pr url>"`, plus `error="invalid_token"` when the token was invalid;
    - for scope denials: `Bearer error="insufficient_scope", scope="<scope>"`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_valid_token_on_mcp_route_sets_identity() { /* issue token via Task 9 flow helper; $_SERVER['HTTP_AUTHORIZATION']; dispatch initialize → 200 */ }
public function test_header_via_redirect_http_authorization_is_read() {                  // Review Focus 1
    $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $token; unset( $_SERVER['HTTP_AUTHORIZATION'] );
    /* → identity set */
}
public function test_missing_header_returns_401_with_resource_metadata_challenge() {     // Review Focus 1
    /* no header → 401, header WWW-Authenticate contains resource_metadata="…/.well-known/oauth-protected-resource/wp-json/md3-wp-mcp/mcp" */
}
public function test_token_is_useless_on_wp_v2_routes() { /* bearer on POST /wp/v2/posts → 401 and no post created */ }
public function test_application_password_on_mcp_route_is_rejected() { /* basic auth user → transport 401 */ }
public function test_expired_and_revoked_tokens_return_invalid_token() { }
public function test_insufficient_scope_challenge_names_scope() { /* tools/call publish-content with write only → WWW-Authenticate insufficient_scope, scope="content:publish" */ }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `bin/test.sh --filter Bearer`

Expected: FAIL.

- [ ] **Step 3: Implement the guard**

Wire `Bearer_Guard::register()` from `OAuth::boot()`.

- [ ] **Step 4: Run the full suite**

Run: `bin/test.sh`

Expected: OK. All tasks so far are green.

- [ ] **Step 5: Commit, after the user confirms**

Message: `MD3-30 Bearer guard limited to the MCP route`.

---

### Task 11: Admin screens, diagnostics, cleanup cron and uninstall

**Files:**
- Create:
  - `includes/admin/class-settings-page.php`, `class-profile-connections.php`, `class-diagnostics.php`
  - views `admin/views/tab-content.php`, `tab-security.php`, `tab-connections.php`, `tab-log.php`, `tab-connect.php`
  - `includes/class-cleanup.php`, `uninstall.php`
- Test: `tests/integration/test-admin.php`

**Interfaces:**
- Produces:
  - Settings > MD3 WP MCP: `add_options_page('MD3 WP MCP', 'MD3 WP MCP', 'manage_options', 'md3-wp-mcp', …)`, with tabs `content|security|connections|log|connect` (§6).
  - Each form posts to `admin-post.php` with its own nonce:

| Action | Data | Calls |
|---|---|---|
| `md3_mcp_save_content` | `post_types[]`, `default_author` | `Settings::update_post_types`, `Settings::update_default_author` |
| `md3_mcp_save_security` | `mcp_user_id`, `dcr`, `mcp_rate_limit` (1–1000) | `Settings::update_security` |
| `md3_mcp_revoke` | `client_id`, `user_id` | `Tokens::revoke_connection` |
| `md3_mcp_revoke_all` | | `Tokens::revoke_all` |
| `md3_mcp_rotate_keys` | | `Keys::rotate` |

    Each handler checks `manage_options` and the nonce. `md3_mcp_revoke` also accepts the request when the current user is `Settings::mcp_user_id()` and `user_id` is that user; the profile screen relies on this.
  - Dropdowns:
    - **MCP user** (Security tab): `get_users(['role' => 'editor'])`, minus anyone with `manage_options`, plus "— Not set —". A notice appears at the top of every tab while it isn't set.
    - **Author for new content** (Content tab): "The MCP user" (0), followed by `get_users(['capability' => 'edit_posts'])`. If the stored author is no longer valid, a warning says new content falls back to the MCP user.
  - `Profile_Connections` on `show_user_profile`: shown only to the MCP user, listing its connections with Revoke.
  - `Diagnostics::run(): array<array{check: string, ok: bool, detail: string}>`. It runs loopback `wp_remote_*` checks:
    1. the protected-resource URL returns 200 JSON with no-store;
    2. the AS metadata returns 200;
    3. a POST to the MCP URL without auth returns 401 with `WWW-Authenticate`;
    4. the `Authorization` header reaches PHP. This uses REST route `GET md3-wp-mcp/v1/diagnostics/auth-echo`, whose permission checks that `Bearer <nonce>` equals transient `md3_mcp_diag`, set for 60 s by `run()`. It returns `{received: bool}`.
    5. `/md3-mcp/authorize` without parameters returns 400 (not 404, a redirect or a cached page).
  - `Cleanup::run(): void` on `md3_wp_mcp_cleanup`: `Tokens::prune()` + `Audit_Log::prune(90)`.
  - `uninstall.php`:
    - drops the 5 tables;
    - deletes the `md3_wp_mcp_*` options and `md3_mcp_*` transients;
    - clears the cron.
  - The Connect tab shows §9's table with the live MCP URL and a copy button.

- [ ] **Step 1: Write the failing tests**

```php
public function test_save_content_requires_manage_options_and_nonce() { /* editor → wp_die; admin + nonce → saved */ }
public function test_rotate_keys_revokes_every_connection() { }
public function test_cleanup_prunes_expired_tokens_and_old_log() { }
public function test_uninstall_removes_tables_and_options() { /* include uninstall.php with WP_UNINSTALL_PLUGIN defined */ }
public function test_save_security_ignores_non_editor_mcp_user() { /* POST mcp_user_id = administrator → stored 0 */ }
public function test_save_content_stores_default_author() { /* author user → stored; subscriber → 0 */ }
public function test_diagnostics_reports_each_check() { /* pre_http_request stubs → 5 rows, each with ok bool */ }
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `bin/test.sh --filter Admin`

Expected: FAIL.

- [ ] **Step 3: Implement**

Escape all output (`esc_html`, `esc_attr`, `esc_url`) and wrap strings in `__()` with `md3-wp-mcp`.

- [ ] **Step 4: Run the full suite**

Run: `bin/test.sh`

Expected: OK.

- [ ] **Step 5: Manual check on the local site**

1. Run `bin/sync-to-site.sh "../../md3digitalsolutions"`. The site's Docker `web` container sees the copy immediately.
2. From the site repo, run `scripts/wp.sh plugin activate md3-wp-mcp`.
3. Create a local Editor user `md3-mcp` with `scripts/wp.sh user create md3-mcp md3-mcp@example.test --role=editor`.

Open `http://localhost:8080/wp-admin/options-general.php?page=md3-wp-mcp`. Expected:
- the five tabs render;
- the "MCP user not set" notice shows until `md3-mcp` is chosen;
- Diagnostics shows check 4 as OK locally.

Don't commit the synced copy in the site repo yet; that happens in Task 13.

- [ ] **Step 6: Commit, after the user confirms**

Message: `MD3-30 Settings, connections, log, diagnostics, uninstall`.

---

### Task 12: Spanish translation, readme and protocol check with MCP Inspector

**Files:**
- Create:
  - `languages/md3-wp-mcp.pot`, `languages/md3-wp-mcp-es_ES.po`, `languages/md3-wp-mcp-es_ES.mo`
- Modify:
  - `README.md`: description, requirements, installation (release zip or `bin/sync-to-site.sh`), MCP user and author setup, per-client setup (§9), security model, development (`bin/test.sh`, `bin/build-zip.sh`). Written in the style of `md3-google-reviews/README.md`.
  - `CHANGELOG.md`: a `0.1.0` entry.
  - `md3-wp-mcp.php`: `load_plugin_textdomain` on `init`.

- [ ] **Step 1: Generate the template**

Run: `docker compose -f docker-compose.test.yml run --rm php wp i18n make-pot . languages/md3-wp-mcp.pot --exclude=vendor,tests --allow-root`

Expected: "Success: POT file successfully generated."

- [ ] **Step 2: Translate**

Write `es_ES.po` in US Spanish with formal "usted", matching `md3-theme/languages/es_ES.po`. Compile it with `docker compose -f docker-compose.test.yml run --rm php wp i18n make-mo languages --allow-root`.

Expected: the `.mo` file exists, and the consent page shows Spanish for a user whose locale is `es_ES`.

- [ ] **Step 3: Run the protocol check against local**

First run `bin/sync-to-site.sh "../../md3digitalsolutions"`. Set the local MCP user to `md3-mcp` and "Author for new content" to an administrator account.

Run: `npx @modelcontextprotocol/inspector`. Connect over Streamable HTTP to `http://localhost:8080/wp-json/md3-wp-mcp/mcp`, sign in through the OAuth flow as `md3-mcp`, then run `tools/list` and `create-content`.

Expected:
- signing in as the administrator is refused, with the "Sign in as md3-mcp" message;
- signing in as `md3-mcp` completes;
- 14 tools are listed (13 without Polylang active);
- the draft appears in wp-admin with the chosen author, and its revision is by `md3-mcp`.

- [ ] **Step 4: Commit, after the user confirms**

Message: `MD3-30 Spanish translation and readme`.

---

### Task 13: Staging verification, security review and live rollout

Every step that pushes, tags, or touches `stage`, `master` or the live site needs the user's explicit yes.

- [ ] **Step 1: Security review of the plugin repo**

Run `/security-review` in the plugin repo. Fix every finding, then confirm `bin/test.sh` is OK.

- [ ] **Step 2: Release 0.1.0, after the user confirms**

In the plugin repo:
1. Run `bin/build-zip.sh`.
2. Commit.
3. `git tag v0.1.0`.
4. `git push origin master --tags`.

- [ ] **Step 3: Put the release into the site repo, after the user confirms**

1. Run `bin/sync-to-site.sh "../../md3digitalsolutions"`.
2. In `md3digitalsolutions` on `feat/MD3-30-md3-wp-mcp`, commit `wp-content/plugins/md3-wp-mcp/` (with its `vendor/`) as `feat(mcp): MD3-30 add MD3 WP MCP 0.1.0`.

- [ ] **Step 4: Deploy to staging, after the user confirms**

1. Merge the site branch to `stage` and push.
2. `scripts/wp.sh --staging plugin activate md3-wp-mcp`.
3. Create the "MD3 MCP" Editor user on staging.
4. Choose it as the MCP user, and set "Author for new content".

- [ ] **Step 5: Server checks on staging**

Run Settings > MD3 WP MCP > Security > Diagnostics. Expected: all 5 checks OK. If check 4 fails, the GridPane Nginx config needs to pass `Authorization` through; tell the user and don't work around it. Also confirm that `/md3-mcp/authorize` and `/.well-known/…` have no `/es/` Polylang redirect.

- [ ] **Step 6: End-to-end with each client on staging**

Test with:
- Claude (web connector and Claude Code);
- ChatGPT developer mode;
- Gemini CLI;
- Muse Code.

Each one, signed in as the staging MCP user, goes through these steps:
1. connect;
2. create a draft, and check that it's credited to the chosen author;
3. upload and set the featured image;
4. set the SEO fields;
5. link an ES translation;
6. publish with the `publish` scope granted;
7. unpublish;
8. trash.

Also run the negative tests:
- the token sent to `/wp-json/wp/v2/posts` gets 401;
- signing in as any account other than the MCP user, an administrator included, is refused at the consent page;
- revoking from the Connections tab stops the client on its next call.

If the observed client steps differ from §9, update the Connect tab and `README.md` in the plugin repo, release `0.1.1`, and repeat Step 3.

- [ ] **Step 7: Live, after the user confirms**

1. Create the "MD3 MCP" Editor user on live.
2. Merge the site branch to `master`.
3. `scripts/wp.sh --live plugin activate md3-wp-mcp`.
4. Choose the MCP user and "Author for new content".
5. Run Diagnostics.
6. Connect Claude.
7. After any test publish, run `scripts/wp.sh --live nginx-helper purge-all`.

- [ ] **Step 8: Deactivate Novamira on live (decision 2), after the user confirms**

Run `scripts/wp.sh --live plugin deactivate novamira`. Re-run Diagnostics, and connect Claude again to confirm MD3 WP MCP still works.

- [ ] **Step 9: Update the ticket**

Comment on MD3-30 with what shipped and the client setup steps.
