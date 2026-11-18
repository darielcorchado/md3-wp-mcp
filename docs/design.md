# MD3 WP MCP: design

- **Ticket:** MD3-30
- **Repository:** `md3-wp-mcp`, a standalone repo set up like `md3-google-reviews`. Local folder: `PROJECTS/Wordpress Custom Plugins/md3-wp-mcp`.
- **Site branch:** `feat/MD3-30-md3-wp-mcp` in `md3digitalsolutions`. It receives the release copy of the plugin.
- **Date:** 2026-11-14
- **Status:** approved 2026-11-14

## 1. Goal

AI clients (Claude, ChatGPT, Gemini and Muse) get a way to create, edit, publish and manage **content** on md3digitalsolutions.com through the Model Context Protocol (MCP). They can never touch the site's code or configuration.

The plugin is called **MD3 WP MCP**. It is developed in its own Git repository, `md3-wp-mcp`, the same way as `md3-google-reviews`. Each site gets a built release copy at `wp-content/plugins/md3-wp-mcp/`. The plugin is built on two pieces:

- the official [WordPress MCP adapter](https://github.com/WordPress/mcp-adapter) (`wordpress/mcp-adapter`);
- the WordPress Abilities API.

Novamira was used only as a reference for how an MCP plugin can be put together. No Novamira code, functions or services are used. Novamira is also AGPL-3.0 licensed, while this plugin is GPL-2.0-or-later.

### In scope (v1)

- Posts and pages can be managed by default. A settings page lets an administrator add other **public** post types.
- Supported content actions:
  - list, read, create and update content;
  - publish, schedule and unpublish;
  - move to trash;
  - categories and tags;
  - featured images and media uploads;
  - Rank Math SEO fields;
  - Polylang language and translation links.
- OAuth 2.1 sign-in that works with all four AI clients.
- A dedicated **MCP user** with the Editor role. Every connection acts as this user.
- An **"Author for new content"** setting. Content created over MCP is credited to a chosen user, while the MCP user still does the work and shows up in revisions and the audit log.
- A Connections screen that lists connected apps and revokes them.
- An audit log of every tool call.

### Out of scope, and never allowed

The plugin must never offer any of the following:

- running PHP or WP-CLI;
- SQL;
- reading or writing files;
- installing, activating or editing plugins and themes;
- managing users, roles or Application Passwords;
- changing site options or settings;
- writing arbitrary post meta;
- deleting anything permanently (trash only).

The MCP tools cannot change the plugin's own settings either.

## 2. Requirements

| | Value | To check before building |
|---|---|---|
| WordPress | 6.9 or later (Abilities API in core) | `scripts/wp.sh --live core version` |
| PHP | 8.1 or later (`league/oauth2-server` 9.x) | `scripts/wp.sh --live eval 'echo PHP_VERSION;'` |
| HTTPS | Required. HTTP is only allowed when `wp_get_environment_type()` is `local`. | Live and staging already use HTTPS |
| Server | The `Authorization` header must reach PHP, and `/.well-known/…` plus `/wp-json/…` must not be page-cached | Test on GridPane staging in the first milestone |

## 3. Architecture

```
AI client (Claude / ChatGPT / Gemini / Muse)
   │ 1. POST /wp-json/md3-wp-mcp/mcp            → 401 + WWW-Authenticate (resource_metadata=…)
   │ 2. GET  /.well-known/oauth-protected-resource/wp-json/md3-wp-mcp/mcp
   │ 3. GET  /.well-known/oauth-authorization-server/md3-mcp
   │ 4. register (DCR) or use its metadata URL (CIMD)
   │ 5. browser → /md3-mcp/authorize → WordPress login → consent screen
   │ 6. POST /wp-json/md3-wp-mcp/v1/oauth/token  (code + PKCE verifier)
   │ 7. POST /wp-json/md3-wp-mcp/mcp  Authorization: Bearer <access token>
   ▼
WordPress
 ├─ MD3 WP MCP
 │   ├─ OAuth server (league/oauth2-server): discovery, registration, authorize, token, revoke
 │   ├─ Bearer check: sets the WordPress user, only on the MCP route
 │   ├─ MCP server "md3-wp-mcp" (official adapter, HTTP transport)
 │   ├─ Abilities = the tools (content only)
 │   ├─ Settings: post types, security, connections, log
 │   └─ Audit log
 └─ WordPress core / Polylang / Rank Math (the abilities call their PHP APIs)
```

### 3.1 Plugin layout

```
md3-wp-mcp/                     (repository root = plugin root)
├── md3-wp-mcp.php              Plugin header, constants, requirement checks, bootstrap
├── composer.json / composer.lock
├── vendor/                     Not committed; built into the release zip (see 3.2)
├── bin/build-zip.sh            Release zip (rsync with .distignore + composer install --no-dev)
├── bin/sync-to-site.sh         Copies the built plugin into a site repo's wp-content/plugins/
├── bin/test.sh                 Runs PHPUnit in docker-compose.test.yml
├── docker-compose.test.yml     PHP 8.2 + MySQL 8 for the test suite
├── .distignore / .gitignore
├── docs/                       design.md, implementation-plan.md
├── README.md / CHANGELOG.md
├── uninstall.php               Drops tables and options
├── includes/
│   ├── class-plugin.php        Wiring: hooks, activation and schema
│   ├── class-settings.php      Options access with defaults (post types, security flags)
│   ├── admin/
│   │   ├── class-settings-page.php     Settings > MD3 WP MCP (tabs)
│   │   ├── class-connections-page.php
│   │   └── class-log-page.php
│   ├── mcp/
│   │   ├── class-server.php            Creates the MCP server, transport permission
│   │   └── class-abilities.php         Registers category and abilities
│   ├── abilities/                      One file per tool (see section 5)
│   ├── oauth/
│   │   ├── class-oauth.php             Wires the endpoints, keys and tables
│   │   ├── class-discovery.php         /.well-known documents
│   │   ├── class-registration.php      DCR (RFC 7591) and CIMD lookup
│   │   ├── class-authorize.php         /md3-mcp/authorize and consent screen
│   │   ├── class-token-endpoint.php    token and revoke
│   │   ├── class-bearer-guard.php      determine_current_user and route checks
│   │   ├── class-keys.php
│   │   ├── repositories/               League repository interfaces, backed by $wpdb
│   │   └── entities/
│   ├── class-content-sanitizer.php     kses and block checks used by every write
│   ├── class-rate-limiter.php
│   └── class-audit-log.php
├── languages/                  md3-wp-mcp.pot, es_ES
└── tests/
```

The code is namespaced (`MD3\WPMCP`) and follows the WordPress coding standards. The plugin header follows `md3-ai-connector`. Text domain: `md3-wp-mcp`.

### 3.2 Dependencies

| Package | Why | License |
|---|---|---|
| `wordpress/mcp-adapter` ^0.7 | Official MCP adapter: server, HTTP transport and sessions | GPL-2.0+ |
| `league/oauth2-server` ^9 | OAuth 2.1 authorization and resource server, so we don't write the protocol ourselves | MIT |
| `nyholm/psr7` | PSR-7 request and response objects that league needs | MIT |
| `automattic/jetpack-autoloader` | Lets this plugin load alongside other plugins that bundle the adapter (Novamira does); the newest copy wins | GPL-2.0+ |

**Packaging.** The plugin repo doesn't commit `vendor/`.

- `bin/build-zip.sh` stages the files allowed by `.distignore`, runs `composer install --no-dev --optimize-autoloader` in the staged copy, and zips it as `md3-wp-mcp-<version>.zip`.
- `bin/sync-to-site.sh <site-repo>` puts the same staged build into `<site-repo>/wp-content/plugins/md3-wp-mcp/`.
- In the site repo that copy, with its `vendor/`, is committed. The site's deploy workflow just rsyncs `wp-content/plugins` and has no build step.

This is how `md3-google-reviews` reaches the Elite Dental Touch site.

### 3.3 The MCP server

- It is created on `mcp_adapter_init` with `$adapter->create_server()`:
  - server ID `md3-wp-mcp`;
  - endpoint **`/wp-json/md3-wp-mcp/mcp`**;
  - transport `HttpTransport`;
  - the tool list from section 5.
- Every tool is listed directly, not hidden behind the adapter's discover-and-execute wrapper tools. Each tool's name, description and input schema reach the AI as is, and each one can carry its own scope.
- Every ability is registered with `meta.mcp.public = false`. They only appear on our server, never on the adapter's default server or on another plugin's server. **We don't switch off the adapter's default server**, because other plugins that use the adapter (Novamira on live) depend on it.
- Our MCP server passes its own permission check to the transport (`transport_permission_callback`). It allows the request only when **all** of these are true:
  1. the current user was identified by **our** access token on this request (a login cookie or Application Password is rejected here);
  2. the user is the configured **MCP user** (4.5);
  3. the MCP user is not an administrator (no `manage_options`).

  Otherwise it returns a `WP_Error` with status 401 or 403.

## 4. Authentication: OAuth 2.1

### 4.1 Why OAuth

| Option | Stolen credential reaches | Lifetime | Clients |
|---|---|---|---|
| **OAuth 2.1** (chosen) | MCP endpoint only, granted scopes only | 1 h access token | All four. ChatGPT accepts only OAuth. |
| Personal access tokens | MCP endpoint only | Long-lived | Claude, Gemini CLI. Not ChatGPT. |
| Self-issued JWT | MCP endpoint | Can't be revoked without a database lookup | Same as tokens |
| Application Passwords | **The whole REST API** as that user | Until deleted | Through a local proxy |

The official adapter has no sign-in of its own; it relies on WordPress authentication. The only official OAuth work is on an unreleased adapter branch. So the plugin provides its own OAuth 2.1 server, built on `league/oauth2-server`. Application Passwords and personal tokens are **not** offered. Tokens can be added later only if a client turns up that can't do OAuth.

### 4.2 Endpoints

| Purpose | URL | Standard |
|---|---|---|
| Protected resource metadata | `/.well-known/oauth-protected-resource/wp-json/md3-wp-mcp/mcp`, plus the `/.well-known/oauth-protected-resource` append form pointing to the same document | RFC 9728 |
| Authorization server metadata | `/.well-known/oauth-authorization-server/md3-mcp` and `/.well-known/openid-configuration/md3-mcp` | RFC 8414 |
| Registration | `POST /wp-json/md3-wp-mcp/v1/oauth/register` | RFC 7591 |
| Authorize | `GET /md3-mcp/authorize` (rewrite rule) | OAuth 2.1 |
| Token | `POST /wp-json/md3-wp-mcp/v1/oauth/token` | OAuth 2.1 |
| Revoke | `POST /wp-json/md3-wp-mcp/v1/oauth/revoke` | RFC 7009 |

Design notes:

- **Issuer `https://<site>/md3-mcp`** has its own path, so our authorization server metadata lives at the path-insert URL. It doesn't fight Novamira, or any other plugin, for the bare `/.well-known/oauth-authorization-server`.
  - Clients find it through the `authorization_servers` list in the protected resource metadata.
  - The bare protected-resource append form is served only if no other plugin answers it first. Clients reach the path-insert form from the `WWW-Authenticate` header anyway.
- `/.well-known/*` and `/md3-mcp/authorize` are handled early on `parse_request`, before any theme output. They are sent with `Cache-Control: no-store`.
- **The authorize page is a front-end URL, not `admin.php?page=…`.**
  - Some clients build the URL by adding `?…` to the advertised endpoint, and that breaks an endpoint that already has a query string.
  - The handler calls `auth_redirect()`, so WordPress's normal login (cookie) identifies the user. It then shows a standalone consent page.
- Metadata advertises only what the server supports:
  - grants `authorization_code` and `refresh_token`;
  - PKCE `S256` only;
  - token endpoint authentication `none` (public clients);
  - `client_id_metadata_document_supported: true`;
  - the scopes from 4.4.

### 4.3 Client registration

There are two ways in:

1. **Client ID Metadata Documents (CIMD).** The `client_id` is an HTTPS URL that the client publishes. On first use we fetch it with `wp_safe_remote_get()`:
   - 5-second timeout, 64 KB maximum;
   - hosts that resolve to private IPs are refused;
   - the result is cached for 24 h.

   We check that the document's `client_id` equals the URL and that the `redirect_uri` in the request is listed in it. Nothing anonymous is written to the database until the user approves.
2. **Dynamic Client Registration (DCR).** This is for clients without CIMD.
   - It is on by default, and Settings > Security has a toggle to turn it off.
   - Limits:
     - 10 registrations per IP per hour;
     - 50 clients per site;
     - no more than 5 redirect URIs each;
     - clients that never finish sign-in are deleted after 24 h.
   - Allowed redirect URIs:
     - `https://` on a public host;
     - `http://localhost` and `http://127.0.0.1` with any port, for desktop and CLI apps (RFC 8252);
     - custom app schemes from a filterable allowlist (`claude`, `cursor`, `chatgpt`, …).

     Fragments and wildcards are refused.

Every client is public, so authentication is PKCE plus an exact redirect URI match. Clients created by an administrator with a client secret (some clients allow it) are a possible phase 2.

### 4.4 Scopes

| Scope | Allows | On the consent screen |
|---|---|---|
| `content:read` | List and read content, terms, media and post types | Always granted |
| `content:write` | Create and edit **drafts and pending** content, upload media, set the featured image, SEO fields, terms, translations | Checked by default |
| `content:publish` | Publish, schedule and unpublish | **Unchecked** by default |
| `content:delete` | Move to trash, restore from trash | **Unchecked** by default |

If a client asks for no scope, it gets `content:read content:write`. On the consent screen the user can grant less than the client asked for. A tool whose scope isn't granted returns a 403 with `WWW-Authenticate: Bearer error="insufficient_scope", scope="…"`, so the client can ask for more.

### 4.5 Who can connect

**The MCP user**
- The MCP has its own WordPress account, for example `md3-mcp` ("MD3 MCP"), with the **Editor** role. An administrator creates it in Users > Add New.
- Settings > Security > **MCP user** lists only users whose role is Editor. Until one is chosen, the MCP endpoint and the authorize page refuse every request and say why.
- **Only the MCP user can approve a connection.** On the consent screen a person signs in to WordPress *as the MCP user*. Any other account, administrators included, gets a 403 that names the MCP user.
- Every connection (Claude, ChatGPT, Gemini, Muse) acts as the MCP user. Tools also check that user's normal WordPress capabilities (`edit_post`, `publish_posts`, `upload_files`, …).
- At request time a token is refused if:
  - the MCP user setting has changed to someone else;
  - the account was deleted;
  - the account has gained `manage_options`.

  A token can never carry administrator rights.
- There's no custom capability and no role change; the setting is the only gate.

**Author for new content (attribution)**
- Settings > Content > **Author for new content** offers "The MCP user" (default) or any user who can write posts (has `edit_posts`).
- `create-content` sets `post_author` to that user. The MCP user still does the work:
  - WordPress records the MCP user as the author of every revision;
  - the audit log records the MCP user and the AI client.
- The MCP user is an Editor, and Editors have `edit_others_posts` and `edit_others_pages`, so it can keep editing and publishing content credited to someone else.
- If the chosen author is deleted, or can no longer write posts, new content falls back to the MCP user. The Content tab shows a warning.
- The AI can't choose the author, and `update-content` never changes the author. Changing the author of existing content stays a wp-admin task.

### 4.6 Tokens

| | Lifetime | Stored |
|---|---|---|
| Authorization code | 60 s, single use | SHA-256 hash of its ID |
| Access token | 1 h | JWT signed RS256 (league's format); SHA-256 of its `jti` plus a `revoked` flag |
| Refresh token | 30 days, **replaced on every use** | Encrypted payload; SHA-256 of its ID plus `revoked` |

- **Refresh token reuse:** if a refresh token that has already been replaced is presented again, every token of that connection (client and user) is revoked. A copied token then shows up as a theft and cuts both parties off.
- **Audience binding (RFC 8707):** every access token carries the MCP endpoint URL as its audience, and the bearer check rejects any other audience.
- **Keys:**
  - The RSA 2048 private key and the encryption key are read from wp-config constants (`MD3_WP_MCP_PRIVATE_KEY`, `MD3_WP_MCP_ENCRYPTION_KEY`) when they are defined.
  - Otherwise they are generated on first use and kept in non-autoloaded options.
  - Settings > Security has a "Rotate keys" button, which also revokes every connection.
- A token is also refused when its user is no longer the configured MCP user, has gained `manage_options`, or has been deleted.

### 4.7 How a request is checked

1. **`determine_current_user` (priority 30).**
   - It acts only if WordPress found no user yet, the request path is the MCP route, and the header is `Authorization: Bearer …`.
   - It validates the signature, expiry, revocation and audience, then calls `wp_set_current_user()`.
   - It records the token's scopes and client for this request only.
2. **`rest_authentication_errors` (priority 5).** A bearer token that was sent but is invalid becomes a 401. Another authentication method can't turn it into a success.
3. **`rest_request_before_callbacks`.**
   - This is the authoritative route check. An identity from our token is accepted **only** on `/md3-wp-mcp/mcp`, and is refused on `/wp/v2/*` and every other route.
   - A request to the MCP route with no credentials gets a 401 with `WWW-Authenticate: Bearer resource_metadata="…"`.
4. **The transport permission check** (3.3), then **each ability's own permission check** (section 5).

### 4.8 Abuse protection

- Rate limits per IP: token and register 30 per minute, authorize 30 per minute.
- MCP calls: 120 per minute per access token, by default.
- Limits are fixed windows kept in transients (Redis object cache on live). The settings let you change the MCP limit.

## 5. Tools (abilities)

The ability category is `md3-wp-mcp`, and every ability is named `md3-wp-mcp/<tool>`.

Each tool has:

- an input schema and an output schema (JSON Schema);
- a description written for the AI;
- annotations (`readOnlyHint`, `destructiveHint`, `idempotentHint`).

The **permission check** for every ability requires:

1. the scope;
2. the post type is allowed in the settings;
3. the user's WordPress capability for that object.

| Tool | Scope | Notes |
|---|---|---|
| `list-post-types` | read | Only the allowed types, with their supported features (title, editor, excerpt, thumbnail, hierarchy). |
| `list-content` | read | Filters: type, status (`publish`, `draft`, `pending`, `future`, `private`, `trash`), search, language, category or tag, author, date range, `page` / `per_page` (≤ 50). Returns summaries. |
| `get-content` | read | Full item:<br>• title and raw block content, excerpt, slug, status and dates;<br>• parent and template (pages);<br>• terms and featured image;<br>• Rank Math title, description and focus keyword;<br>• language and translations;<br>• edit and preview links. |
| `create-content` | write | Status is only `draft` (default) or `pending`. Accepts title, content, excerpt, slug, terms, featured image ID, SEO fields, language, parent and menu order (pages), and an **existing** page template. The author is the "Author for new content" setting (4.5). |
| `update-content` | write | Same fields; only the fields sent change. **Can't change the status to published.** Editing already-published content needs `content:publish` as well. WordPress keeps a revision. |
| `publish-content` | publish | Publish now, or schedule with a future `date`. |
| `unpublish-content` | publish | Back to draft. |
| `trash-content` / `restore-content` | delete | Trash only, never permanent delete. |
| `list-terms` / `create-term` | read / write | Categories and tags, and any taxonomy of an allowed post type. Create takes name, slug, parent and language. |
| `upload-media` | write | From a URL (with `wp_safe_remote_get()`, ≤ 10 MB, image types only: JPEG, PNG, WebP, GIF) or base64. Accepts alt text, title and caption. Converted to WebP like other uploads (MD3-25). |
| `create-upload-link` | write | A one-time upload page for an image the user has in the chat (section 5.1). Accepts alt text, title, caption and `attach_to`; attaching to live content needs `content:publish`. |
| `get-upload` | read | Status of a link from the same connection: `waiting`, `uploaded` or `expired`. Once uploaded, the image's ID, URL, size and alt text. |
| `list-media` | read | Images in the media library, newest first, searched by title, caption, description and file name. `page` / `per_page` (≤ 50). Needs `upload_files`. |
| `set-featured-image` | write | On an existing post with a media ID. |
| `link-translations` | write | Only when Polylang is active: set a post's language and link it to its translation (`pll_set_post_language`, `pll_save_post_translations`). |

**The same safety rules apply to every write:**

- Content and excerpt always go through **`wp_kses_post`**, even when the user has `unfiltered_html`, so no `<script>`, `<iframe>` or event attributes. Block comments are kept.
- Content is checked to parse as blocks (`parse_blocks`). Unknown block names are allowed, because they come from installed plugins.
- Post meta is limited to an allowlist (the three Rank Math fields). There is no general meta input.
- The author comes only from the "Author for new content" setting. Tools have no `author` input.
- `comment_status`, `post_password` and sticky are **not** offered in v1.
- Brevo web push is turned off for content created or published over MCP, the same way `md3 post-import-es` does for translations. Turning it on is not exposed.

The tool output also includes the ID and an edit link, so the user can check the work in wp-admin.

### 5.1 Upload links

A chat attachment has no public URL, and the model doesn't get its bytes (a 500 KB photo is about 700K characters of base64, far too large for a tool call). So `upload-media` can't take it. `create-upload-link` gives the AI a page on the site where the user uploads the image from the browser:

1. The AI calls `create-upload-link` and shows the URL, `/md3-mcp/upload/<token>`.
2. The user opens it, picks or drops the image, and submits.
3. The AI calls `get-upload` with the `upload_id`, gets the media ID, and uses it.

**The link is the permission (a capability URL).** Requiring the user to sign in as the MCP user would be safer in theory, but in practice the browser is signed in as an administrator, and the user would have to sign out first. A link with 256 bits of randomness, that works once, for 10 minutes, and only while its connection is live, is a smaller risk than that friction. Whoever is signed in in the browser doesn't matter: the upload always runs as the MCP user (`wp_set_current_user()` for the upload only, after the nonce is checked against the browser's own session).

The rules:

- The token is 43 random letters and digits, shown once. Only its SHA-256 hash is stored, as with OAuth tokens. The `upload_id` that the AI uses with `get-upload` is a separate value and does not open the page.
- **Single use.** The link is taken with one atomic `UPDATE … WHERE status = 'waiting' AND expires_at > now`, so two requests can't both upload. A refused file (wrong type, too large, no file, a bad nonce) does not use up the link, and the form shown with the error takes the next try. The link records the attachment on `add_attachment`, before the image sizes are made, so it still points to the image if PHP dies after that. A successful upload redirects (303) to `?uploaded=1`, so a reload never posts again.
- **Size:** 10 MB, or PHP's upload limit (`wp_max_upload_size()`) when that is lower; the page states the smaller one. A POST over `post_max_size` arrives with no body and gets the size message, not a nonce error.
- **Tied to the connection.** `get-upload` only finds links of the calling client and MCP user. The page refuses the link once the connection has no live token or the MCP user setting changed.
- **The file rules of `upload-media`**, shared in `Upload_Media::prepare()`: up to 10 MB; JPEG, PNG, WebP or GIF, from the content (`wp_check_filetype_and_ext`), and the image must decode in the image editor, so a GIF header followed by PHP is refused. `upload_mimes` is limited to those four types during the upload.
- `attach_to` live content needs `content:publish` when the link is made. If the content goes live afterwards, a link made without that scope can't attach to it.
- **The page** is a standalone form with no theme and no scripts: `X-Frame-Options: DENY`, a CSP with `frame-ancestors 'none'` and `form-action 'self'`, `noindex`, `no-store` and `no-referrer`. The form has a WordPress nonce. The page is matched on the request path in `parse_request`, so no rewrite rule or flush is needed.
- **Limits:** 10 links per connection every 10 minutes; 30 page requests per minute per IP.
- **Audit log:** `create-upload-link` calls are logged like every tool. The page logs `upload-link` with `ok` / `used`, `denied` / `used`, `expired` or `connection`, and `error`. The daily cleanup logs each unused link that expired (`expired`), giving an upload in progress an hour, then deletes links a day after they expire.

## 6. Admin screens

There is one page, **Settings > MD3 WP MCP**, with these tabs. It needs `manage_options`, except where noted.

1. **Content**
   - Checkboxes for every public post type from `get_post_types(['public' => true])`, except `attachment`. Posts and pages are on by default.
   - Saved in the option `md3_wp_mcp_post_types`.
   - **Author for new content**: a user dropdown, saved in `md3_wp_mcp_default_author` (0 = the MCP user).
2. **Security**:
   - **MCP user** (Editors only);
   - automatic registration (DCR) on or off;
   - MCP rate limit;
   - rotate keys;
   - revoke all connections.
3. **Connections**
   - A table of client name, granted scopes, connected on, last used, and a Revoke button.
   - The MCP user also sees its connections on its own profile page.
4. **Log**: the audit log (section 7) with filters.
5. **Connect**: copy-ready setup steps for each client (section 9) with the MCP URL.

## 7. Data

| Table (`{$wpdb->prefix}md3_mcp_…`) | Columns |
|---|---|
| `clients` | `client_id`, name, redirect URIs (JSON), type (`dcr` / `cimd`), CIMD URL, created by IP hash, created, last used |
| `auth_codes` | ID hash, client, user, scopes, PKCE challenge, redirect URI, expires, used |
| `access_tokens` | ID hash, client, user, scopes, expires, revoked, last used |
| `refresh_tokens` | ID hash, access token hash, client, user, scopes, expires, revoked, replaced by |
| `log` | time, user, client, tool, object type and ID, result (`ok` / `denied` / `error` / `expired`), short message, IP hash |
| `uploads` | public ID, token hash, client, user, publish scope held, `attach_to`, alt / title / caption (JSON), status (`waiting` / `uploading` / `uploaded` / `expired`), attachment ID, created, expires, used |

- **Options:**
  - `md3_wp_mcp_post_types`;
  - `md3_wp_mcp_default_author` (user ID; 0 = the MCP user);
  - `md3_wp_mcp_security` (array: `mcp_user_id`, `dcr`, `mcp_rate_limit`);
  - `md3_wp_mcp_db_version`;
  - the key options when no wp-config constants are set.
- **Cleanup:** a daily WP-Cron job deletes expired codes and tokens, unfinished clients, upload links a day after they expire, and log rows older than 90 days.
- Tables are created with `dbDelta` on activation and when `md3_wp_mcp_db_version` changes.

## 8. Coexisting with the rest of the site

- **Novamira:** both plugins bundle the adapter, and Jetpack Autoloader loads the newest copy. We don't touch the default server. Our issuer path keeps the OAuth discovery URLs separate.
  - Novamira gives administrators full code access over MCP, so the ticket's "no code changes" promise only holds if **Novamira is deactivated on live** once MD3 WP MCP is working.
  - That decision belongs to the user and is outside this plugin.
- **GridPane / Nginx:**
  - Check that `Authorization` reaches PHP, and that `/.well-known/oauth-*`, `/md3-mcp/authorize`, `/md3-mcp/upload/*` and `/wp-json/*` aren't cached by the Nginx page cache, redirected or challenged. Let's Encrypt only uses `/.well-known/acme-challenge/`.
  - The plugin sends `no-store` headers. A diagnostics box in the Security tab runs these checks against the site itself.
- **Polylang:** `/md3-mcp/authorize` and `/.well-known/*` must not get a language prefix. Check that on staging.
- **Rank Math:** fields are written with `update_post_meta` on the three allowlisted keys.

## 9. Connecting each client

The MCP URL is `https://md3digitalsolutions.com/wp-json/md3-wp-mcp/mcp`.

| Client | How |
|---|---|
| Claude (web, desktop, mobile) | Settings > Connectors > Add custom connector > paste the URL > Connect > sign in to WordPress and approve. |
| Claude Code | `claude mcp add --transport http md3 <URL>`, then `/mcp` to sign in. |
| ChatGPT | Settings > Apps & Connectors > developer mode > create a connector with the URL, OAuth. |
| Gemini CLI | Add `"md3": { "httpUrl": "<URL>" }` under `mcpServers` in `settings.json`, then `/mcp auth md3`. |
| Muse | Muse Code CLI: add a remote HTTP server with the URL and sign in with OAuth. The consumer Muse app still needs checking. |

These screens change often. The exact steps are checked against each client during testing, and the Connect tab is updated.

## 10. Testing

- **Unit and integration tests:** PHPUnit with the WordPress test suite. They run in the repo's own Docker stack (`docker-compose.test.yml`: PHP 8.2, the same as live, plus MySQL 8) through `bin/test.sh`. They cover:
  - PKCE and redirect URI checks;
  - token validation (expired, revoked, wrong audience, wrong route);
  - refresh token reuse;
  - the scope and capability rules for every tool;
  - post type allowlist;
  - kses on writes;
  - forbidden fields;
  - rate limits.
- **Protocol check:** MCP Inspector against local, then staging. Covers discovery, registration, sign-in, `tools/list` and tool calls.
- **End-to-end on staging:** Claude (web connector and Claude Code), ChatGPT developer mode, Gemini CLI, Muse Code. Each one connects, creates a draft, uploads a featured image, edits SEO fields, links a translation, publishes, unpublishes and trashes.
- **Negative tests:**
  - the token sent to `/wp-json/wp/v2/posts` must fail;
  - a cookie or Application Password on the MCP route must fail;
  - any account other than the MCP user, administrators included, must be refused;
  - content must be credited to the chosen author, and fall back to the MCP user when that author is gone;
  - a revoked connection must stop working within one request.
- **Security review** of the branch (`/security-review`) before anything goes to live.

## 11. Rollout

1. Build and test in the plugin repo with `bin/test.sh`.
2. Try it on the local site with `bin/sync-to-site.sh` (`http` is allowed because the environment is `local`).
3. Tag a version in the plugin repo and build the zip. Run `bin/sync-to-site.sh` into `md3digitalsolutions` on `feat/MD3-30-md3-wp-mcp`, then commit the copy there.
4. Deploy to staging through the `stage` branch. Create the "MD3 MCP" Editor user there, pick it as the MCP user, and run the server checks (section 8) and the end-to-end tests.
5. Create the "MD3 MCP" Editor user on live, merge to `master`, activate the plugin, choose the MCP user and the author for new content, and connect Claude first.
6. Once MD3 WP MCP is verified on live, deactivate Novamira there (decision 2).

## 12. Decisions (2026-11-14)

The user approved the recommendations:

1. **The MCP has its own user with the Editor role.** Only that user can approve connections, and an administrator never can (4.5).
2. **Novamira gets deactivated on live** once MD3 WP MCP is verified there (rollout step 4).
3. **Refresh tokens last 30 days**, and each one is replaced when it's used.
4. **Content published over MCP never sends the Brevo web push.** If a push is wanted, it's sent by hand from wp-admin.
5. **The plugin lives in its own repository** (`md3-wp-mcp`), like `md3-google-reviews`. Sites get a built copy.
6. **New content can be credited to a chosen author** through the "Author for new content" setting (4.5).
7. **Upload links work without signing in** (MD3-31, section 5.1): the link is the permission, and the upload runs as the MCP user.
