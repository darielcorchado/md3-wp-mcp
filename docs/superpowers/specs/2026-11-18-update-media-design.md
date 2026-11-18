# update-media: edit an image's alt text, title, caption and description

Small change. Follows MD3-31 (upload links, `get-upload`, `list-media`).

## Why

Dariel wants to tell Claude "add alt text to the images" and have it done, and when he asks Claude to set an image on a post, Claude fills in that image's alt text too. Today alt text can only be set at upload time (`Upload_Media::insert()`); no tool edits an image already in the media library.

## Goals

- A new tool, `update-media`, that changes the alt text, title, caption and description of one image in the media library.
- `list-media` gets a `missing_alt` filter, so Claude can find the images that still need alt text.

## Non-goals

- Image blocks already inside posts keep their own copy of the alt text and caption in the post's content. This tool doesn't touch them; `update-content` does, as today.
- No file changes: no rename, replace, crop or delete. No other attachment types (PDF, video), and no other attachment fields (slug, date, parent, author).

## Anti-goals

- Weakening the live-content rule: changing what visitors see must still need `content:publish`.
- Opening the post tools to attachments: `load_post()` keeps refusing them.

## Approaches

- **A. New `update-media` tool and a `missing_alt` filter on `list-media` (chosen).** Covers both of Dariel's cases and keeps media separate from the post tools.
- B. Alt and caption fields on `set-featured-image` only. Less work, but it can't fix an image that isn't a featured image.
- C. Let `update-content` edit attachments. One tool fewer, but it mixes media into checks built for posts and loosens a simple rule.

## Design (decided)

- **Scope:** `content:write`. It also needs `content:publish` when the image is the featured image of, or attached to (`post_parent`), content that is published, scheduled or private (option A of the scope question). This is how `set-featured-image` and `upload-media` work.
- **Input:** `id` (required), plus any of `alt`, `title`, `caption`, `description`, at least one of them. Only the fields sent change. An empty string clears a field.
- **Checks:** the ID must be an image attachment (`wp_attachment_is_image`), or the tool returns `md3_mcp_not_found`. The MCP user must have `edit_post` on it.
- **Writes:** every field goes through `Content_Sanitizer::plain()` (no HTML). Alt goes to `_wp_attachment_image_alt`. Title, caption and description go to `post_title`, `post_excerpt` and `post_content` with `wp_update_post()`.
- **Output:** the image as `Content_Formatter::media()` returns it. `media()` gains `caption` and `description`, so `list-media`, `get-upload` and `upload-media` return them too.
- **`list-media`:** `missing_alt: true` returns only images whose alt text is missing or empty.
- **Log:** like every tool, with the image ID.

## Builder's calls

- The exact SQL for "featured image or attached to live content".
- Test layout, in `tests/integration/test-upload-links.php` next to the other media tools, or in a file of its own.

## Delivery (Claude's call, correct if wrong)

Added to the MD3-31 branch and PR #3, since that PR isn't merged and this is the same media work. Version stays 0.2.0, with a line added to its CHANGELOG entry. README, design.md, the es_ES translation and CLAUDE.md are updated as usual.
