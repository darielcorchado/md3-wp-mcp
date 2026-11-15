# Changelog

## 0.1.0 - 2026-11-14

First release.

- A content-only MCP server at `/wp-json/md3-wp-mcp/mcp`, built on the official WordPress MCP adapter and the Abilities API.
- Fourteen tools: list, read, create and update content; publish, schedule, unpublish, trash and restore; terms; media upload and featured images; Rank Math SEO fields; Polylang languages and translation links (the last one only when Polylang is active).
- Its own OAuth 2.1 server (authorization code with PKCE `S256`, refresh token rotation, token revocation), with client registration by Client ID Metadata Documents or Dynamic Client Registration.
- Scopes `content:read`, `content:write`, `content:publish` and `content:delete`, chosen on a consent screen.
- A dedicated Editor "MCP user" that approves every connection and does the work, and an "Author for new content" setting that credits new content to someone else.
- Tokens work only on the MCP endpoint, are stored hashed, and are refused if the MCP user changes or gains administrator rights.
- Settings page with Content, Security, Connections, Log and Connect tabs, a diagnostics check for the web server, an audit log, rate limits and a "Rotate keys" action.
- Spanish (US) translation.
