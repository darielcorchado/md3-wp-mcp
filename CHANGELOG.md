# Changelog

## 0.2.0 - 2026-11-18

- New tool `create-upload-link`: a one-time page where the user uploads an image from the browser, so the AI can use images attached in the chat. The link works once, expires after 10 minutes, belongs to the connection that made it, and is stored only as a hash.
- New tool `get-upload`: whether the link was used, and the uploaded image's ID and URL.
- New tool `list-media`: finds images in the media library by title, caption or file name, or those still without alt text (`missing_alt`).
- New tool `update-media`: changes the alt text, title, caption or description of an image in the media library. Changing an image used by live content needs `content:publish`.
- `upload-media` now also refuses files whose image header doesn't decode as an image, and returns the image's title, file name, caption, description and date.
- New `uploads` table (database version 2), removed on uninstall.

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
