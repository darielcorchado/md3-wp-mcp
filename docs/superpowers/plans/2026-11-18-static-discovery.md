# Static Discovery Files Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When the web server blocks `/.well-known/`, Diagnostics offers the two discovery documents as downloadable static files (ZIP and single files) with instructions, and accepts uploaded copies whose content matches.

**Architecture:** `Discovery::static_files()` is the one source of the file content. `Admin\Discovery_Files` builds an in-memory stored ZIP and serves admin-only, nonce-protected downloads. `Diagnostics` judges each discovery document by its decoded content and marks rows with a `fix` (`missing`/`stale`); the Security tab shows the files box from that.

**Tech Stack:** PHP 8.2, WordPress, PHPUnit in Docker (`bin/test.sh`).

**Spec:** `docs/superpowers/specs/2026-11-18-static-discovery-design.md`

## Global Constraints

- The plugin never writes, moves or deletes a file, not even a temporary one.
- Downloads: `manage_options` and nonce `md3_mcp_download_discovery`; content from `wp_json_encode()` of the plugin's own metadata, never from request data.
- Text domain `md3-wp-mcp` on every string people see; WordPress coding standards (tabs); files `class-<name>.php`.
- No commit until Dariel approves (CLAUDE.md, Git). One commit for the whole feature.

## Review Focus

- A static file uploaded with Windows line endings or reformatted by an FTP editor: still a match (compare decoded JSON, not bytes).
- A 404 while OAuth is off (plain HTTP, not local): no download offered, since the files would advertise endpoints that don't run.
- A 200 HTML page (cache, challenge) on the discovery URL: failure that says so, no offer.
- Single-file download in Safari: `application/octet-stream` so no `.json` is appended.
- A home URL with a path (`/blog`): file paths include `blog/`, and the box URLs match the checked URLs.

---

### Task 1: `Discovery::static_files()`

**Files:**
- Modify: `includes/oauth/class-discovery.php`
- Test: `tests/integration/test-oauth-core.php` (or wherever Discovery is tested; add there)

**Interfaces:**
- Produces: `Discovery::static_files(): array<string,string>` — relative path (no leading slash) => JSON with trailing newline. Keys in order: protected resource, authorization server. `Discovery::static_file_urls(): array<string,string>` — same keys => absolute URL.

- [ ] **Step 1: Failing test** `test_static_files_mirror_the_discovery_documents`: keys are `['.well-known/oauth-protected-resource/wp-json/md3-wp-mcp/mcp', '.well-known/oauth-authorization-server/md3-mcp']`; `json_decode` of each equals `protected_resource_metadata()` / `authorization_server_metadata()`; each ends with `"\n"`; with DCR off, the second has no `registration_endpoint`; `static_file_urls()` values are `home_url( '/' . $path )`.
- [ ] **Step 2:** `bin/test.sh --filter test_static_files_mirror` → FAIL (undefined method).
- [ ] **Step 3:** Implement. Paths: `ltrim( path of protected_resource_url(), '/' )` and `ltrim( array_search( AUTHORIZATION_SERVER, paths() ), '/' )` (both include the home path). JSON flags `JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT`. URLs: origin + '/' + path.
- [ ] **Step 4:** Run → PASS.

### Task 2: `Admin\Discovery_Files` (ZIP and download)

**Files:**
- Create: `includes/admin/class-discovery-files.php`
- Modify: `includes/class-plugin.php` (call `Discovery_Files::register()` next to `Settings_Page::register()`)
- Test: `tests/integration/test-discovery-files.php`

**Interfaces:**
- Consumes: `Discovery::static_files()`.
- Produces: `const ACTION = 'md3_mcp_download_discovery'`; `register(): void` (hooks `admin_post_` . ACTION); `zip( array $files ): string`; `download( string $which ): ?array{name: string, type: string, body: string}` for `zip|resource|server`, null otherwise; `url( string $which ): string` (nonce'd admin-post URL); `handle(): void` (cap → 403 `wp_die`, `check_admin_referer( ACTION )`, unknown → 400 `wp_die`, else send headers + body + exit).

- [ ] **Step 1: Failing tests:**
  - `test_zip_holds_the_folders_and_files`: write `zip( Discovery::static_files() )` to a temp file in the test, open with `ZipArchive`; names include `.well-known/`, `.well-known/oauth-protected-resource/`, …, the two file paths; `getFromName()` of each equals `static_files()[ $path ]`; `ZipArchive::open` returns true (CRC valid: `statName()['crc'] === crc32(content)`).
  - `test_download_returns_each_file`: `download('zip')` name `md3-wp-mcp-discovery-files.zip`, type `application/zip`; `download('resource')` name `mcp`, type `application/octet-stream`, body = first file; `download('server')` name `md3-mcp`; `download('other')` null.
  - `test_download_handler_requires_manage_options_and_nonce`: editor + valid nonce → `WPDieException`; admin + bad nonce → `WPDieException`; admin + valid nonce + `file=nope` → `WPDieException` with 400. (`$_GET`/`$_REQUEST` set like the existing `submit()` helper.)
- [ ] **Step 2:** Run `bin/test.sh --filter Test_Discovery_Files` → FAIL.
- [ ] **Step 3:** Implement. ZIP writer: for each directory prefix (once, in order) and each file, a local file header (`PK\x03\x04`, version 20, flags 0, method 0, DOS time/date, CRC-32 via `crc32()`, sizes, name), then data; then central directory entries (`PK\x01\x02`, external attrs `0x10` for directories) and the end record (`PK\x05\x06`). Use `pack( 'V'/'v' )`. Headers on send: `Content-Type`, `Content-Disposition: attachment; filename="<name>"`, `Content-Length`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`.
- [ ] **Step 4:** Run → PASS.

### Task 3: Diagnostics judges discovery documents by content

**Files:**
- Modify: `includes/admin/class-diagnostics.php`
- Test: `tests/integration/test-admin.php`

**Interfaces:**
- Consumes: `Discovery::static_files()`, `Discovery::static_file_urls()`, `OAuth::transport_allowed()`.
- Produces: every row is `array{check: string, ok: bool, detail: string, fix: string}`; `fix` is `''`, `'missing'` or `'stale'` (only the two discovery rows set it). `Diagnostics::fix( array $rows ): string` — `'stale'` if any row is stale, else `'missing'` if any is missing, else `''`.

- [ ] **Step 1: Failing tests** (stub with `pre_http_request` returning per-URL responses; helper `stub_discovery( int $code, array $headers, string $body )` for both discovery URLs, the rest as in the existing test):
  - Update `test_diagnostics_reports_each_check`: discovery bodies are the real `static_files()` content; still 6 rows, same `ok` pattern; every row has `fix` `''`.
  - `test_discovery_404_offers_the_files`: 404 → both rows `ok` false, `fix` `'missing'`; `Diagnostics::fix()` `'missing'`.
  - `test_discovery_404_without_oauth_offers_nothing`: the test environment is `local`, so `transport_allowed()` can't be made false there. `Diagnostics::run()` reads `OAuth::transport_allowed()` once and passes it on; the public `Diagnostics::judge_document( string $check, array|string $response, string $expected, bool $oauth_on ): array` is tested directly with `$oauth_on = false` and a 404 → `fix` `''`.
  - `test_static_file_with_other_headers_passes`: 200, `content-type: application/octet-stream`, `cache-control: public, no-cache`, body = expected with `\r\n` line endings → ok, detail mentions static file.
  - `test_differing_content_is_stale`: 200 JSON with `issuer` changed → ok false, `fix` `'stale'`.
  - `test_non_json_200_and_other_errors_offer_nothing`: 200 `<html>` → false, `fix` `''`; 403 → false, `''`; `WP_Error` → false, `''`.
- [ ] **Step 2:** Run `bin/test.sh --filter Test_Admin` → FAIL.
- [ ] **Step 3:** Implement `judge_document()`; both discovery checks call it with their URL from `static_file_urls()` and content from `static_files()`. Equality: decode both with `json_decode( …, true )`, recursively `ksort` associative arrays (not lists, `array_is_list`), then `===`. Detail strings: 404 "HTTP 404: the web server doesn't pass this address to WordPress."; match served by WordPress (JSON + `no-store`) "HTTP 200, served by WordPress."; match otherwise "HTTP 200, a static file or cached copy with the expected content (Content-Type "%1$s", Cache-Control "%2$s")."; stale "HTTP 200, but the content is out of date (site address or Automatic registration changed, or the file was edited)."; non-JSON "HTTP 200, but not a JSON document. Something else answers this address (a cache or a firewall challenge page)."; other "HTTP %d.".
- [ ] **Step 4:** Run → PASS.

### Task 4: Security tab box, "Show anyway", DCR notice

**Files:**
- Modify: `includes/admin/class-settings-page.php`, `includes/admin/views/tab-security.php`
- Create: `includes/admin/views/discovery-files.php` (the box, included by `tab-security.php`)
- Test: `tests/integration/test-admin.php`

**Interfaces:**
- Consumes: `Diagnostics::fix()`, `Discovery_Files::url()`, `Discovery::static_file_urls()`.
- Produces: security view data gains `discovery_files: array{show: bool, reason: string, zip: string, files: array<array{path: string, url: string, download: string}>}` where `reason` is `missing|stale|asked`; notice `saved_dcr` (warning class).

- [ ] **Step 1: Failing tests:**
  - `test_files_box_shown_only_when_needed`: no results → output has the "Show the discovery files anyway" link (`md3_static=1`), no ZIP link; transient results with a `missing` row → output has the ZIP download URL (`action=md3_mcp_download_discovery`, `file=zip`, `_wpnonce`), both file URLs and `.well-known`; `$_GET['md3_static']='1'` with passing results → box shown; passing results → no box.
  - `test_stale_files_say_so`: transient with a `stale` row → output contains the "out of date" sentence.
  - `test_changing_dcr_warns_about_static_files`: `submit( md3_mcp_save_security, dcr on→off )` redirect has `md3_notice=saved_dcr`; same value again → `saved`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement. `save_security()` compares old/new `dcr`. Notice text "Settings saved. If you serve the discovery files from disk, run the checks and download them again." Box copy per the spec's five instruction steps; "Check again" is a second `md3_mcp_run_diagnostics` form button inside the box. Every URL through `esc_url()`, every text through `esc_html()`.
- [ ] **Step 4:** Run → PASS.

### Task 5: Docs, translations, release

**Files:**
- Modify: `README.md`, `docs/design.md` (§4.2 discovery, §8 diagnostics), `CHANGELOG.md`, `md3-wp-mcp.php` (0.3.0 in header and `MD3_WP_MCP_VERSION`), `languages/md3-wp-mcp.pot`, `languages/md3-wp-mcp-es_ES.po`, `.mo`; `CLAUDE.md` via the `sync-claude-md` skill.

- [ ] **Step 1:** README: in the server notes, "When `/.well-known/` is blocked" (symptom, Diagnostics offers the files, where to extract, delete on uninstall); Diagnostics row in the settings table mentions it.
- [ ] **Step 2:** Regenerate `.pot`, translate every new string in `es_ES.po`, compile `.mo` (commands in `docs/development.md`). `test-i18n.php` passes.
- [ ] **Step 3:** `bin/test.sh` → all pass, no `[ERROR]` lines.
- [ ] **Step 4:** Local site check (`bin/sync-to-site.sh`), reviews (`/code-review`, `/security-review`), then ask Dariel to approve the commit `MD3-32 Offer static discovery files when /.well-known/ is blocked`.
