# Troubleshooting: firewall and server blocks

For site owners and developers whose AI client can't connect to MD3 WP MCP, or connects and then fails, because a firewall or the web server blocks requests before they reach WordPress.

These blocks are hard to spot:

- **Diagnostics can't see them.** Its self-check sends WordPress's own user agent, which firewalls allow, so every check can pass while real clients are blocked.
- **The AI client shows a generic error.** For example, claude.ai says "Couldn't check the server … 403 Not found".

Sections 1–6 apply to any host. Issues specific to a hosting provider, and their fixes, are under [Hosting providers](#hosting-providers).

Contents:

- [Which requests each client sends](#which-requests-each-client-sends)
1. [Find where a request was blocked](#1-find-where-a-request-was-blocked)
2. [The discovery documents return 404](#2-the-discovery-documents-return-404)
3. [Writing firewall exceptions](#3-writing-firewall-exceptions)
4. [Why these exceptions are safe](#4-why-these-exceptions-are-safe)
5. [Firewalls](#5-firewalls)
6. [Other server issues](#6-other-server-issues)
- [Hosting providers](#hosting-providers)
  - [GridPane](#gridpane)

## Which requests each client sends

The plugin answers these addresses on your domain:

| Step | Request |
|---|---|
| First contact | `POST /wp-json/md3-wp-mcp/mcp` without a token, answered with 401 and a pointer to the discovery documents |
| Discovery | `GET /.well-known/oauth-protected-resource/wp-json/md3-wp-mcp/mcp` and `GET /.well-known/oauth-authorization-server/md3-mcp` |
| Registration | `POST /wp-json/md3-wp-mcp/v1/oauth/register` (only clients that register themselves; others send a metadata URL as their `client_id`) |
| Sign-in | `GET /md3-mcp/authorize?…` (opened in the user's browser) |
| Token | `POST /wp-json/md3-wp-mcp/v1/oauth/token` (and `/revoke`) |
| Tool calls | `POST /wp-json/md3-wp-mcp/mcp` |
| Upload page | `GET` and `POST /md3-mcp/upload/<token>` (opened in the user's browser; a `multipart/form-data` POST of up to 10 MB) |

User agents each client sends:

| Client | Discovery | Registration / token | Tool calls |
|---|---|---|---|
| claude.ai (also Claude Desktop and mobile) | `python-httpx/0.28.1` (including the first `POST` to the MCP endpoint) | `python-httpx/0.28.1` | `Claude-User` |
| Muse | `curl/8.5.0` | none (empty) | a Chrome browser string |
| ChatGPT | not tested yet | | |
| Gemini CLI | not tested yet | | |

The sign-in and upload pages are always opened by the user's own browser, so their user agent is a normal browser's. Firewalls that block Python, `curl` or empty user agents therefore stop clients before sign-in, at the first contact, discovery, registration or the token request.

## 1. Find where a request was blocked

Work from the outside in.

- **The plugin's Log tab** (Settings → MD3 WP MCP → Log) records every tool call that reaches a tool. If a tool call isn't there, it either never reached WordPress or was refused before the tool ran (a bad or expired token, a token for the wrong user, the per-token rate limit). The access log or `curl` tells you which: a firewall block is a 403 that never reaches WordPress, while the plugin's refusals are 401, 403 or 429 with a JSON body. The log doesn't record discovery, registration or token requests either; for those, use the server logs or `curl`.
- **The web server's access log** shows each client's user agent and the status it got.
- **The firewall's log** names the rule that fired. Where it lives depends on the firewall and the host; see [Firewalls](#5-firewalls) and [Hosting providers](#hosting-providers).
- **Reproduce with `curl`**, sending the client's user agent:

  ```bash
  S=https://example.com   # your site
  curl -s -o /dev/null -w '%{http_code}\n' -A "python-httpx/0.28.1" \
    -X POST -H 'Content-Type: application/json' -d '{}' \
    "$S/wp-json/md3-wp-mcp/mcp"
  ```

  **401** is correct: the request reached the plugin, which asks for a token. **403** means something blocked it. Look at the response body or the logs to see what.

## 2. The discovery documents return 404

**Symptom.** No client can connect. In Diagnostics, both metadata checks fail with "HTTP 404: the web server doesn't pass this address to WordPress".

**Confirm** (with `S` set to your site, as in section 1):

```bash
curl -si "$S/.well-known/oauth-authorization-server/md3-mcp" | head -20
```

A 404 from the web server, not a WordPress page: the server's short error page, and no `Link: <…/wp-json/>; rel="https://api.w.org/"` header, which WordPress pages carry. Some web servers serve `/.well-known/` only from files on disk and never pass it to WordPress.

**Fix.** Upload the two documents as static files. When Diagnostics sees the 404, it shows a **Discovery files** box under the checks, with a ZIP that already holds the folder structure, each file on its own, and the exact URLs each one must open at. Extract the ZIP in the folder your domain's root address serves (the web root, such as `htdocs` or `public_html`), then click **Check again**. The README's [server notes](../README.md#server-notes) cover the details.

The web server may send the files as `application/octet-stream`, or with caching headers. That's fine: Diagnostics compares the content, and claude.ai and Muse connect with them. Download them again after changing the site address or Automatic registration, or after updating the plugin. Diagnostics tells you when they're out of date.

**Why it's safe.** The files hold only public metadata (issuer, endpoints, scopes, PKCE `S256`), the same JSON WordPress would serve. No keys or tokens. The plugin never writes them itself; you upload them.

## 3. Writing firewall exceptions

When a firewall rule blocks a client, skip that one rule on the plugin's paths instead of turning the rule, or the firewall, off.

- **Match exact paths, not prefixes.** A prefix such as `/wp-json/md3-wp-mcp` lets `..`, `//` or `%2F` tricks borrow the exception, for example `/wp-json/md3-wp-mcp/../../wp-login.php`.
- **Match the address the client sent**, not the one after the server rewrites it to `/index.php`. Otherwise the exception works for static files and silently stops working for everything WordPress serves.
- **Skip only the rule that fired.** Every other rule should still run on these paths.
- **The paths below assume WordPress at the domain root** and the default `wp-json` prefix. With a subfolder install or a custom REST prefix, take the paths from your own discovery documents.
- **Verify after every change** with the commands below.

The plugin's paths, as exact paths:

- `/.well-known/oauth-protected-resource/wp-json/md3-wp-mcp/mcp`
- `/.well-known/oauth-authorization-server/md3-mcp`
- `/wp-json/md3-wp-mcp/v1/oauth/register`, `/token` and `/revoke`
- `/wp-json/md3-wp-mcp/mcp`
- `/md3-mcp/authorize` (browser; allow `localhost` and `127.0.0.1` in its query string)
- `/md3-mcp/upload/<token>` (browser; a `multipart/form-data` POST of up to 10 MB)

Expected results. The 403 rows show that the exception didn't leak; they apply when your firewall blocks that user agent, or `localhost` in query strings, elsewhere.

| Request | Expected |
|---|---|
| `POST /wp-json/md3-wp-mcp/mcp`, user agent `python-httpx/0.28.1` | 401 |
| Same, user agent `curl/8.5.0`, and with no user agent | 401 |
| `GET` both `.well-known` documents, `python-httpx` | 200 |
| `POST /wp-json/md3-wp-mcp/v1/oauth/token` and `/register`, `python-httpx`, invalid body | 400 |
| `GET /wp-json/md3-wp-mcp/../../wp-login.php` (with `--path-as-is`), `python-httpx` | 403 |
| `POST //wp-json/md3-wp-mcp/mcp` (double slash), `python-httpx` | 403 |
| `GET /wp-json/wp/v2/posts`, `python-httpx` | 403 |
| `GET /`, `python-httpx` | 403 |
| `GET /md3-mcp/authorize?…redirect_uri=http://localhost:…` | 400 |
| `GET /?redirect_uri=http://localhost:…` (outside the sign-in page) | 403 |

The commands:

```bash
S=https://example.com   # your site
UA="python-httpx/0.28.1"
http_status() { curl -s -o /dev/null -w '%{http_code}\n' "$@"; }

http_status -A "$UA" -X POST -H 'Content-Type: application/json' -d '{}' "$S/wp-json/md3-wp-mcp/mcp"     # 401
http_status -A "curl/8.5.0" -X POST -H 'Content-Type: application/json' -d '{}' "$S/wp-json/md3-wp-mcp/mcp" # 401
http_status -A "" -X POST -H 'Content-Type: application/json' -d '{}' "$S/wp-json/md3-wp-mcp/mcp"         # 401
http_status -A "$UA" "$S/.well-known/oauth-protected-resource/wp-json/md3-wp-mcp/mcp"                    # 200
http_status -A "$UA" "$S/.well-known/oauth-authorization-server/md3-mcp"                                 # 200
http_status -A "$UA" -X POST -d 'grant_type=x' "$S/wp-json/md3-wp-mcp/v1/oauth/token"                    # 400
http_status -A "$UA" -X POST -H 'Content-Type: application/json' -d '{}' "$S/wp-json/md3-wp-mcp/v1/oauth/register" # 400
http_status -A "$UA" --path-as-is "$S/wp-json/md3-wp-mcp/../../wp-login.php"                             # 403
http_status -A "$UA" --path-as-is -X POST -d '{}' "$S//wp-json/md3-wp-mcp/mcp"                           # 403
http_status -A "$UA" "$S/wp-json/wp/v2/posts"                                                            # 403
http_status -A "$UA" "$S/"                                                                               # 403
http_status "$S/md3-mcp/authorize?response_type=code&client_id=x&redirect_uri=http%3A%2F%2Flocalhost%3A33418%2Fcallback" # 400
http_status "$S/?redirect_uri=http%3A%2F%2Flocalhost%3A33418%2Fcallback"                                 # 403
```

## 4. Why these exceptions are safe

- **Only one rule is skipped, only on exact paths.** Every other rule still runs on these paths, and the skipped rule still runs everywhere else.
- **User-agent rules are easy to get around.** A rule that blocks requests saying they come from Python or `curl` only stops tools that don't bother to change their user agent; anyone can send any user agent. Skipping it on the plugin's paths gives an attacker nothing they couldn't get by changing theirs.
- **`localhost` in the sign-in address isn't SSRF.** Rules that block `localhost` in query strings guard against SSRF (making the server fetch an internal address). The sign-in page never fetches the redirect address; it only sends the user's browser there after consent. It does fetch one URL from its query string: a `client_id` that is a client metadata document. That fetch has its own guards: HTTPS only, `wp_safe_remote_get()` (which refuses hosts that resolve to private addresses), no redirects, a size limit and the per-IP sign-in rate limit.
- **A `localhost` redirect only reaches the user's own machine.** The plugin accepts one only for a client that registered a loopback address (any port, per RFC 8252), and every sign-in needs PKCE `S256`. With Automatic registration on, anyone can register such a client, but the authorization code still goes to the computer of the person who signed in, and is useless without the PKCE verifier.
- **The plugin protects every one of these paths itself.** That means OAuth 2.1 with PKCE, tokens that work only on the MCP endpoint, and per-IP and per-token rate limits. The MCP user is an Editor, never an administrator.
- **The `rest_route=` leftover.** WordPress routes REST requests by the `rest_route` query parameter even on a pretty path. So with a Python user agent, `/wp-json/md3-wp-mcp/mcp?rest_route=/wp/v2/types` matches a path exception and is answered by another REST route (HTTP 200). That's acceptable: the request gets exactly what it would get with any other user agent, and every route keeps its own authentication. The exception only skips a user-agent check.

## 5. Firewalls

These are starting points, not tested with this plugin: check each against your firewall's own documentation and logs.

Whatever the firewall, the client's requests must reach WordPress without a block, a challenge page (JavaScript or CAPTCHA) or a redirect. AI clients aren't browsers and can't solve challenges. The exceptions are the sign-in and upload pages, which the user's browser opens.

Allow the paths in [section 3](#3-writing-firewall-exceptions), and expect these user agents: `python-httpx/…`, `Claude-User`, `curl/…`, an empty user agent, and browser strings. Some firewalls block empty and `curl` user agents by default. If the host runs its own firewall that you can't configure (Imunify360, host-level ModSecurity), send the host the path list.

- **Cloudflare.** Check Security → Events first.
  - Bot Fight Mode (Free plan) can't be skipped per path; if it challenges these requests, turn it off.
  - On paid plans, add a WAF custom rule with the **Skip** action for the paths, skipping Super Bot Fight Mode and the managed rules that fire.
  - Tool calls from claude.ai use the `Claude-User` user agent. Check that AI-bot blocking (Security → Bots, or AI Crawl Control) doesn't block it.
  - Don't use "I'm Under Attack" mode while clients connect. Bypass the cache for `/.well-known/oauth-*` and `/wp-json/*`.
- **Wordfence.** Its blocks show in Wordfence's live traffic and blocking log, with the rule that fired.
  - For a firewall rule hit, use Wordfence's allowlisting for that rule and path rather than turning the rule off.
  - Check that its rate limiting doesn't throttle the token and MCP requests.
- **ModSecurity / OWASP CRS.** Find the rule ID in the audit log (missing or empty user agents usually show as CRS rules 920320 or 920330), then add an exclusion scoped to these exact paths with `ctl:ruleRemoveById`, matching `REQUEST_FILENAME` with `@streq` (for example `@streq /wp-json/md3-wp-mcp/mcp`). `REQUEST_URI` includes the query string, so an exact match on it misses and a prefix match brings back the prefix problem from section 3. Don't switch the engine off.
- **Sucuri.** Allowlist the paths in the firewall's access control settings, and check its blocked-request log for the rule that fired.

After any change, run the commands in section 3.

## 6. Other server issues

These don't block the request at a firewall, but they break the flow just as surely. The README's [server notes](../README.md#server-notes) have the fixes:

- **The Authorization header doesn't reach PHP.** Diagnostics shows "The Authorization header reaches PHP" as failed. Every tool call then gets a 401 even with a valid token.
- **A page cache serves `/.well-known/`, `/md3-mcp/authorize` or `/wp-json/`.** Sign-in loops, or Diagnostics reports a document out of date when you never uploaded files (section 2). Exclude those paths from the page cache and the CDN cache.
- **Polylang adds a language prefix** to `/md3-mcp/authorize`, `/md3-mcp/upload/*` or `/.well-known/*`. The authorize page then returns 404 or redirects.
- **The upload page refuses large images.** A 413 or a firewall block on `POST /md3-mcp/upload/<token>`. Raise `client_max_body_size`, `upload_max_filesize` and `post_max_size` to at least 10 MB, and allow the `multipart/form-data` POST in the firewall.
- **Everyone shares one rate limit behind a proxy or CDN.** Behind Cloudflare, the per-IP limits on registration, sign-in and tokens see Cloudflare's address, so busy periods show as 429. The README explains the `md3_wp_mcp_client_ip` filter.
- **The site can't make outbound requests.** Clients that send a metadata URL as their `client_id` need the site to fetch it over HTTPS. `WP_HTTP_BLOCK_EXTERNAL` or a firewall that blocks outbound traffic makes the authorize page answer 400.

## Hosting providers

Issues found on a specific host, and the host-specific way to fix them. Each provider has its own section.

### GridPane

GridPane's Nginx configuration and its 7G firewall can block AI clients in three ways. All three show up before WordPress, so Diagnostics passes while clients fail.

#### Where to look

- **7G log:** `/var/www/<site>/logs/7g.log`. A blocked request looks like this:

  ```
  [":bad_bot_5:"] … "POST /wp-json/md3-wp-mcp/mcp" 403 "python-httpx/0.28.1"
  ```

  The name in brackets is the rule that fired (`bad_bot_5`, `bad_querystring_10`, …). That tells you which exception you need.
- **Nginx access log:** in the same `logs/` folder.

#### `/.well-known/` returns 404

GridPane's `/etc/nginx/common/security.conf` has `location ~ /\.well-known { allow all; }`, which only serves files from disk and never passes the request to WordPress. Both discovery documents return 404, and so does the plugin's fallback `/.well-known/openid-configuration/md3-mcp`.

**Fix.** The static discovery files from [section 2](#2-the-discovery-documents-return-404). Extract the ZIP in `/var/www/<site>/htdocs`. Nginx serves the files as `application/octet-stream` with `Cache-Control: public, no-cache`, which Diagnostics accepts.

#### 7G bad-bot rule 5 blocks Claude's servers

**Symptom.** claude.ai (and Claude Desktop and mobile) says "Couldn't check the server … 403", or the sign-in finishes in the browser but the connection then fails.

**Confirm.** The 7G log shows `[":bad_bot_5:"]` with `python-httpx/…`, or:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -A "python-httpx/0.28.1" \
  "$S/.well-known/oauth-authorization-server/md3-mcp"
```

returns 403. Rule 5 blocks any user agent containing "python". Claude's servers send `python-httpx/x.y` for the first contact, discovery, registration and the token request. Of the clients tested, only Claude trips this rule.

**Fix.** Block 1 of the [exceptions file](#the-exceptions-file).

#### 7G query-string rule 10 blocks local clients

**Symptom.** Claude Code or Gemini CLI opens the browser to sign in, and the browser shows a 403 instead of the WordPress login or consent page. claude.ai isn't affected.

**Confirm.** The 7G log shows `bad_querystring_10` on `/md3-mcp/authorize`. Local clients sign in with a redirect address like `http://localhost:33418/callback` or `http://127.0.0.1:…`, and rule 10 blocks `localhost` in query strings:

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  "$S/md3-mcp/authorize?response_type=code&client_id=x&redirect_uri=http%3A%2F%2Flocalhost%3A33418%2Fcallback"
```

returns 403. With the fix it returns 400: the plugin itself answers and refuses the made-up client.

**Fix.** Block 2 of the [exceptions file](#the-exceptions-file). It's optional: add it only if you use a local client.

#### The exceptions file

Put the exceptions in a file in the site's Nginx folder whose name ends in `-context.conf`, such as `/var/www/<site>/nginx/whitelist-md3-wp-mcp-7g-context.conf`. Then test and reload:

```bash
sudo nginx -t && sudo gp ngx reload
```

(Drop `sudo` if you're signed in as root.)

Use this file as an example: start from it, and adapt it to what your 7G log shows. Keep the two `.well-known` paths in block 1 even with the static discovery files, because static files go through 7G too.

```nginx
# MD3 WP MCP: 7G exceptions, exact paths only.
#
# 1. Claude's servers send "python-httpx/x.y" for discovery, registration and
#    the token request, which trips bad-bot rule 5. Skip only rule 5, only on
#    the plugin's OAuth and MCP paths. The plugin's OAuth, PKCE and rate limits
#    still apply.
set $exclusion_rule_match "";
if ( $request_uri ~ ^/(wp-json/md3-wp-mcp/(mcp|v1/oauth/(register|token|revoke))|\.well-known/oauth-protected-resource/wp-json/md3-wp-mcp/mcp|\.well-known/oauth-authorization-server/md3-mcp)/?(\?|$) ) {
    set $exclusion_rule_match 5;
}
if ( $bad_bot_7g = $exclusion_rule_match ) {
    set $7g_drop_bad_bot 0;
}

# 2. Optional (Claude Code, Gemini CLI): their localhost redirect address trips
#    query-string rule 10. Skip only rule 10, only on the sign-in page. The plugin
#    accepts localhost redirects only from apps that registered them, with PKCE.
set $exclusion_rule_match "";
if ( $request_uri ~ ^/md3-mcp/authorize/?\? ) {
    set $exclusion_rule_match 10;
}
if ( $bad_querystring_7g = $exclusion_rule_match ) {
    set $7g_drop_bad_query_string 0;
}
```

How it works: 7G stores the number of the rule that matched in `$bad_bot_7g` (or `$bad_querystring_7g`). Each block sets `$exclusion_rule_match` to the one rule number to forgive, only on the listed paths. When the two are equal, it clears the drop flag. Any other rule still blocks the request, and on any other path `$exclusion_rule_match` stays empty, so nothing is forgiven. [Section 4](#4-why-these-exceptions-are-safe) explains why skipping these two rules is safe.

Pitfalls specific to 7G:

- **Match `$request_uri`, not `$uri`.** 7G runs again after Nginx's internal redirect to `/index.php`, and by then `$uri` is `/index.php`. `$request_uri` keeps the address the client sent.
- **Anchor the paths** with `^/` and end them with `/?(\?|$)`, as above.
- **Reset `$exclusion_rule_match` before each block.** Otherwise block 2 inherits the value from block 1.
- **Run `nginx -t` before reloading.** A syntax error in a context file stops the reload.
- **This pattern is GridPane's.** It relies on the `$bad_bot_7g` / `$7g_drop_bad_bot` variables GridPane's 7G sets. The standard 7G for Nginx or Apache blocks directly, so there you edit or remove the matching rule instead.

#### Verify

Run the commands in [section 3](#3-writing-firewall-exceptions). With both blocks in place, every result in its table applies on GridPane, the 403 rows included.
