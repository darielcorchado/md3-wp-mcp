# MD3 WP MCP

A content-only MCP server for WordPress, with its own OAuth 2.1 sign-in. It lets AI assistants (Claude, ChatGPT, Gemini, Muse) list, read, draft, edit, publish and trash posts and pages, set featured images, fill in Rank Math SEO fields and link Polylang translations. It can never touch code, plugins, themes, users or settings.

## Requirements

- WordPress 6.9+ (it needs the Abilities API that ships in core).
- PHP 8.2+.
- HTTPS. Plain HTTP only works when `wp_get_environment_type()` is `local`.
- The `Authorization` header must reach PHP. Some server setups strip it (see [Server notes](#server-notes)).
- `/.well-known/…`, `/md3-mcp/authorize` and `/wp-json/…` must not be page-cached, redirected or challenged by the web server or a CDN.

Polylang and Rank Math are optional. The translation tool only appears when Polylang is active.

## Installation

1. Upload the release zip (see [Building a release zip](#building-a-release-zip)) through **Plugins → Add New → Upload Plugin**, or run `bin/sync-to-site.sh <site-repo>` to copy a production build into `<site-repo>/wp-content/plugins/md3-wp-mcp/`.
2. Activate **MD3 WP MCP** under **Plugins**.
3. Go to **Settings → MD3 WP MCP** and follow [Setup](#setup).

## Setup

### 1. Create the MCP user

The plugin acts as one dedicated WordPress account, never as a person.

1. In **Users → Add New**, create a user such as `md3-mcp` ("MD3 MCP") with the **Editor** role. Administrators are refused.
2. In **Settings → MD3 WP MCP → Security**, choose it as the **MCP user**. The list shows Editors only.

Until a user is chosen, the MCP endpoint and the consent page refuse every request and say why.

Only the MCP user can approve a connection. When an AI app sends you to WordPress, sign in as the MCP user. Any other account, administrators included, is refused with a message that names the MCP user. A token is also refused later if the MCP setting points to someone else, the account is deleted, or it gains administrator rights.

### 2. Choose the author for new content

In **Content → Author for new content**, pick who new content is credited to. The default is the MCP user. You can pick any user who can write posts.

- The MCP user still does the work. WordPress records it as the author of every revision, and the audit log names it and the AI client.
- If the chosen author is deleted or can no longer write posts, new content falls back to the MCP user and the Content tab shows a warning.
- The AI cannot choose an author, and editing never changes one. Reassigning existing content stays a wp-admin task.

### 3. Choose the post types

Also in **Content**, tick the public post types AI clients may use. Posts and pages are on by default. Media is handled by the upload and featured image tools.

### 4. Other Security settings

| Field | What it does |
|---|---|
| MCP user | The only account AI clients can act as (see above). |
| Automatic registration | Lets clients register themselves (Dynamic Client Registration). On by default. Clients that publish a metadata URL still work with it off. |
| MCP rate limit | Requests per minute per access token. Default 120. |
| Rotate keys | Replaces the signing and encryption keys and signs every client out. |
| Revoke all connections | Ends every connection at once. |
| Diagnostics | Checks that the web server and page cache let the sign-in flow through. Run it after install and after server changes. |

The signing and encryption keys are created on first use and kept in the database. To keep them out of it, define `MD3_WP_MCP_PRIVATE_KEY` (a PEM string or a file path) and `MD3_WP_MCP_ENCRYPTION_KEY` in `wp-config.php`.

## Connecting a client

The MCP URL is `https://<your-site>/wp-json/md3-wp-mcp/mcp`. The **Connect** tab shows it with copy buttons. Each client sends you to a WordPress sign-in and a consent screen. Sign in as the MCP user.

| Client | Steps |
|---|---|
| Claude (web, desktop, mobile) | **Settings → Connectors → Add custom connector**, paste the URL, **Connect**, sign in to WordPress and approve. *To verify on staging.* |
| Claude Code | `claude mcp add --transport http md3 <URL>`, then `/mcp` to sign in. *To verify on staging.* |
| ChatGPT | **Settings → Apps & Connectors**, turn on developer mode, create a connector with the URL and OAuth. *To verify on staging.* |
| Gemini CLI | Add `"md3": { "httpUrl": "<URL>" }` under `mcpServers` in `settings.json`, then run `/mcp auth md3`. *To verify on staging.* |
| Muse | Muse Code CLI: add a remote HTTP server with the URL and sign in with OAuth. *To verify on staging.* The consumer Muse app still needs checking. |

These client screens change often. Each one is checked on staging before a release, and the Connect tab is updated to match.

Connected apps are listed in **Settings → MD3 WP MCP → Connections**, where you can revoke them. The MCP user also sees its own connections on its profile page.

## Scopes and the consent screen

A client asks for scopes, and the consent screen lets you grant fewer.

| Scope | Allows | Default on the consent screen |
|---|---|---|
| `content:read` | List and read content, terms, media and post types | Always granted |
| `content:write` | Create and edit drafts and pending content, upload media, featured images, SEO fields, terms, translations | Checked |
| `content:publish` | Publish, schedule and unpublish. Editing content that is already live also needs it. | Unchecked |
| `content:delete` | Move to the trash and restore | Unchecked |

A client that asks for no scope gets `content:read content:write`. A tool whose scope was not granted answers with a 403 that tells the client which scope to ask for.

## Tools

Every tool is named `md3-wp-mcp/<tool>` and checks the scope, the allowed post types and the MCP user's normal WordPress capabilities.

| Tool | Scope | What it does |
|---|---|---|
| `list-post-types` | read | The allowed content types, with the features each supports. |
| `list-content` | read | Lists content with filters (type, status, search, language, term, author, dates) and pagination (up to 50 per page). |
| `get-content` | read | One item in full: block content, terms, featured image, SEO fields, language and translations, edit and preview links. |
| `create-content` | write | Creates a draft or pending post or page. Never published. |
| `update-content` | write | Changes only the fields sent. Cannot publish. |
| `publish-content` | publish | Publishes now, or schedules with a future date. |
| `unpublish-content` | publish | Moves content back to draft. |
| `trash-content` | delete | Moves content to the trash. |
| `restore-content` | delete | Brings content back from the trash, as a draft. |
| `list-terms` | read | Categories, tags and other terms. |
| `create-term` | write | Creates a term. |
| `upload-media` | write | Adds a JPEG, PNG, WebP or GIF (up to 10 MB) from a URL or base64. |
| `set-featured-image` | write | Sets the featured image of an existing item. |
| `link-translations` | write | Sets a post's language and links its translations. Only with Polylang active. |

That is 14 tools, or 13 without Polylang. Content and excerpts always pass through `wp_kses_post`, so no scripts or event attributes get in. Post meta is limited to the Rank Math title, description and focus keyword. Content published over MCP never sends the Brevo web push.

## Security model

- **OAuth 2.1** with PKCE `S256` only, public clients only, and exact redirect URI matching. Authorization codes last 60 seconds and work once. Access tokens last 1 hour. Refresh tokens last 30 days and are replaced on every use. If an old refresh token is presented again, every token of that connection is revoked.
- **Hashed storage.** Codes and tokens are stored as hashes (refresh payloads are encrypted), never as plain values.
- **Token only works on the MCP endpoint.** The audience is the MCP URL, and the route check refuses the token on `/wp/v2/*` and every other route. A login cookie or Application Password is also refused on the MCP endpoint.
- **One account.** Every connection acts as the MCP user, an Editor. It cannot hold administrator rights.
- **Tools are content-only.** The plugin never offers: running PHP or WP-CLI, SQL, reading or writing files, plugins or themes, users, roles or Application Passwords, site options or settings, arbitrary post meta, or permanent delete. The tools cannot change this plugin's own settings.
- **Limits and audit.** Rate limits apply to registration, sign-in, token and MCP calls. Every tool call is written to the audit log (kept 90 days), with the MCP user, client and result.
- **Uninstalling** the plugin drops its tables and options.

## Server notes

- **Authorization header.** The sign-in flow and every tool call need it. If the Diagnostics check says it is not received, pass it on to PHP in the web server configuration. On Nginx with PHP-FPM that is `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`. On Apache, `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`.
- **`/.well-known/` and `/wp-json/`.** Exclude `/.well-known/oauth-*`, `/md3-mcp/authorize` and `/wp-json/*` from the page cache, and make sure nothing redirects or challenges them. The plugin sends `no-store` headers, but a full-page cache can still ignore them. On GridPane, check the Nginx page cache first. Let's Encrypt only uses `/.well-known/acme-challenge/`, so it is not affected.
- **Polylang.** `/md3-mcp/authorize` and `/.well-known/*` must not get a language prefix.
- **Other MCP plugins.** The plugin keeps its own issuer path (`/md3-mcp`) and does not touch the adapter's default server, so it can sit beside plugins that bundle the same adapter. Note that a plugin that gives administrators code access over MCP (such as Novamira) defeats the "content only" promise. Deactivate it once this plugin works.

## Development

```bash
bin/test.sh                      # PHPUnit in Docker (PHP 8.2 + MySQL 8)
bin/test.sh --filter Test_I18n   # one test class
```

Run `composer install` once first. `bin/test.sh` does it when `vendor/` is missing.

### Building a release zip

```bash
bin/build-zip.sh                 # writes md3-wp-mcp-<version>.zip in the repo root
bin/sync-to-site.sh <site-repo>  # copies the same build into <site-repo>/wp-content/plugins/md3-wp-mcp/
```

The build keeps only what `.distignore` allows and runs `composer install --no-dev --optimize-autoloader` in a staged copy. The site repo gets the built copy, `vendor/` included.

### Translations

The text domain is `md3-wp-mcp`. Spanish (Spain), es_ES, is in `languages/`. After changing strings, regenerate the template and compile the translation:

```bash
docker compose -f docker-compose.test.yml run --rm php wp i18n make-pot . languages/md3-wp-mcp.pot --exclude=vendor,tests --allow-root
docker compose -f docker-compose.test.yml run --rm php wp i18n make-mo languages --allow-root
```

See `docs/design.md` for the full design.

## License

GPL-2.0-or-later.
