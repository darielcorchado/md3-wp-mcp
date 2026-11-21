# Static discovery files when the server blocks `/.well-known/`

MD3-32. Follows MD3-30. The firewall material (GridPane 7G rules 5 and 10) moved to MD3-34, the troubleshooting guide, which comes next and links back here.

## Why

AI clients find the sign-in server through two discovery documents the plugin serves from WordPress:

- `/.well-known/oauth-protected-resource/wp-json/md3-wp-mcp/mcp`
- `/.well-known/oauth-authorization-server/md3-mcp`

On some hosts the web server never passes `/.well-known/` to WordPress. On GridPane, `location ~ /\.well-known { allow all; }` only serves files from disk, so both documents return 404 and no client can connect. On staging (2026-11-16), the same two documents uploaded by hand as static files fixed it: claude.ai connected and the whole flow passed, even though Nginx serves them as `application/octet-stream` with `Cache-Control: public, no-cache`.

## Goals

- When Diagnostics gets a 404 on a discovery document, the Diagnostics section of the Security tab offers the two files (a ZIP holding the folder structure, and each file on its own) with instructions.
- Diagnostics accepts a static file whose content matches, whatever its headers, and reports a file whose content differs as out of date, with fresh files.
- After the files are uploaded, every Diagnostics check passes and an AI client connects.

## Non-goals

- The `openid-configuration` fallback and the bare `/.well-known/oauth-protected-resource` aren't offered as files. Clients get the protected resource URL from the `WWW-Authenticate` challenge, and try `oauth-authorization-server` first; staging connected with the two files.
- Firewall notes (GridPane 7G, Cloudflare and others): MD3-34.
- RFC 8414 placement for a home URL with a path (`example.com/blog`): the files mirror the URLs the plugin already serves and checks, unchanged.

## Anti-goals

- **The plugin never writes, moves or deletes a file**, not even a temporary one. It only generates the files for an administrator to upload.
- The download never holds anything secret (keys, tokens) or anything taken from the request.
- No download offered on a host where the dynamic routes work.

## Approaches

- **A. One source of content, ZIP built in memory, the discovery checks compare content (chosen).** `Discovery::static_files()` builds the files with the same functions as the live routes, so they can't drift. A small writer builds a stored (uncompressed) ZIP in memory: no temporary file, and no need for PHP's `zip` extension, which some hosts lack. "Check again" re-runs Diagnostics.
- B. `ZipArchive`. Less code, but it needs a temporary file on disk (against the anti-goal) and `ext-zip`.
- C. A separate "Check again" that only fetches the two URLs. Two code paths that judge the same documents; they would disagree sooner or later.

## Design (decided)

### Content

- `Discovery::static_files(): array<string,string>`: the path of each file relative to the domain's root (no leading slash, with the home path if WordPress's home URL has one) mapped to its JSON. Paths are the paths of the two URLs Diagnostics checks: `Discovery::protected_resource_url()` and the authorization server path from `Discovery::paths()`.
- JSON is `wp_json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )` of `protected_resource_metadata()` and `authorization_server_metadata()`, plus a trailing newline. Public metadata only.
- The authorization server document lists `registration_endpoint` only while Automatic registration (DCR) is on, so changing that setting, like changing the site address, makes an uploaded file out of date.

### Download

- `Admin\Discovery_Files`, wired on `admin_post_md3_mcp_download_discovery`. A GET link built with `wp_nonce_url()`; the handler requires `manage_options` (403 otherwise) and the nonce `md3_mcp_download_discovery`.
- `file=zip` sends `md3-wp-mcp-discovery-files.zip` (`application/zip`). `file=resource` and `file=server` send one file, named after the last segment of its path (`mcp`, `md3-mcp`), as `application/octet-stream`, so the browser doesn't add `.json`. Any other value: 400.
- Headers: `Content-Disposition: attachment`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`.
- The ZIP holds a directory entry for each folder and the two files, stored, with CRC-32. Built by `Discovery_Files::zip( array $files ): string`.

### Diagnostics

The two discovery checks become one function that judges a document:

| Response | Result | Offer files |
|---|---|---|
| Request error (timeout, refused) | Failed, with the error | No |
| HTTP 404 | Failed: "the web server doesn't pass this address to WordPress" | Yes, as missing (only while OAuth is active: HTTPS or a local site) |
| HTTP 200, JSON equal to the expected document | OK. Detail says whether WordPress served it (JSON and `no-store`) or it's a static file or cached copy | No |
| HTTP 200, JSON that differs | Failed: out of date | Yes, as out of date |
| HTTP 200, not JSON | Failed: something else answered (a challenge page, a cached page) | No |
| Any other status | Failed, with the status | No |

Equality compares the decoded JSON with object keys sorted, so key order and whitespace don't matter but list order does. Each row gets a `fix` key: `''`, `missing` or `stale`.

### Security tab

- When a row has a `fix`, or the URL has `md3_static=1`, a "Discovery files" box follows the Diagnostics table. Otherwise a "Show the discovery files anyway" link, for hosts where the self-check can't run.
- The box: why it's shown (missing, out of date, or asked for), the ZIP button, the two single files, and the instructions:
  1. Extract the ZIP in the folder that your domain's root address serves (the web root, such as `htdocs` or `public_html`; `web` on Bedrock). That isn't always the WordPress folder. Or upload the two files by FTP to the same paths.
  2. `.well-known` is a hidden folder and the files have no extension. That's expected; don't rename them.
  3. Each file must open at its exact URL (both listed).
  4. Click "Check again" (runs Diagnostics again).
  5. Download them again after changing the site address or Automatic registration. When you uninstall the plugin, delete these two files, not the whole `.well-known` folder (Let's Encrypt uses it).
- Saving the Security tab with a different Automatic registration value shows a warning notice: "Settings saved. If you serve the discovery files from disk, run the checks and download them again."

### Docs, translations, release

README (a "When `/.well-known/` is blocked" part in the server notes), design.md (§4.2, §8), es_ES strings, version 0.3.0 with a CHANGELOG entry.

## Tests

- Offered only on 404 (and not when OAuth is off); not offered on 200 or on other failures.
- Matching static content passes whatever its headers; differing content fails as `stale`; non-JSON fails without an offer.
- `static_files()` paths and content; ZIP opened with `ZipArchive` in the test: entry names and content.
- The download handler: refused without `manage_options` and without a valid nonce; unknown `file` refused.
- DCR change notice.

## Builder's calls

- File and method names beyond the ones above; how the view gets its data.
- Exact wording of strings.
