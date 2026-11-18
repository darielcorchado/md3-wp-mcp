# update-media Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A tool that edits the alt text, title, caption and description of an image in the media library, and a `missing_alt` filter on `list-media`.

**Architecture:** A new `Update_Media` ability next to `Upload_Media`, using the same permission pattern (`require_scope`, publish when live). `Content_Formatter::media()` gains `caption` and `description`. `List_Media` adds a meta query.

**Tech Stack:** PHP 8.2, WordPress Abilities API, PHPUnit via `bin/test.sh` (Docker).

**Spec:** [docs/superpowers/specs/2026-11-18-update-media-design.md](../specs/2026-11-18-update-media-design.md)

## Global Constraints

- Branch `feat/MD3-31-upload-link` (PR #3). Commit messages `MD3-31 Imperative sentence`, no `Co-Authored-By` trailer.
- Version stays 0.2.0; add a line to the existing 0.2.0 CHANGELOG entry.
- Every string people see uses the `md3-wp-mcp` text domain; error codes are `md3_mcp_*`.
- Every field is written through `Content_Sanitizer::plain()`.
- Changing an image used by live content (`Ability::LIVE_STATUSES`: publish, future, private) needs `content:publish`.
- `load_post()` keeps refusing attachments; don't change it.

## Review Focus

1. Alt text containing HTML or a script (`<script>alert(1)</script>Dog`): saved as plain text (`Dog`), never as markup.
2. An image that is the featured image of a **scheduled** (`future`) or **private** post: needs `content:publish`, the same as published.
3. An image used only by a **trashed** or draft post: `content:write` is enough.
4. The ID of a post, a PDF, or nothing (`999999`): `md3_mcp_not_found`, nothing changes.
5. Clearing alt (`alt: ""`): the meta becomes empty, and `list-media` with `missing_alt` then finds the image.

---

### Task 1: Caption and description in the media output

**Files:**
- Modify: `includes/abilities/class-content-formatter.php` (`media()`)
- Test: `tests/integration/test-upload-links.php`

**Interfaces:**
- Produces: `Content_Formatter::media( int $id ): array` with the existing keys plus `caption` (`post_excerpt`) and `description` (`post_content`), both strings.

- [ ] **Step 1: Write the failing test** `test_media_output_includes_caption_and_description`: create an image attachment with `post_excerpt => 'A caption'`, `post_content => 'A description'`; `list-media` returns `media[0]['caption'] === 'A caption'` and `media[0]['description'] === 'A description'`.
- [ ] **Step 2: Run it:** `bin/test.sh --filter test_media_output_includes_caption_and_description`. Expected: FAIL, undefined index `caption`.
- [ ] **Step 3: Add the two keys to `media()`.**
- [ ] **Step 4: Run `bin/test.sh --filter Test_Upload_Links`.** Expected: OK.
- [ ] **Step 5: Commit** `MD3-31 Return caption and description with images`.

### Task 2: The update-media tool

**Files:**
- Create: `includes/abilities/class-update-media.php`
- Modify: `includes/abilities/class-registry.php` (after `List_Media`)
- Test: `tests/integration/test-update-media.php` (class `Test_Update_Media`, extends `Test_Case`)

**Interfaces:**
- Consumes: `Content_Formatter::media()` (Task 1), `Ability::require_scope()`, `Ability::LIVE_STATUSES`, `Content_Sanitizer::plain()`.
- Produces: `final class Update_Media extends Ability`, `name()` `update-media`, `scope()` `Scopes::WRITE`. Input schema: `id` (integer, minimum 1, required), `alt`, `title`, `caption`, `description` (strings), `minProperties` not used: the "at least one field" check is in `execute()`. `public static function used_by_live_content( int $id ): bool`.

Error codes: `md3_mcp_not_found` (404) for anything that isn't an image attachment; `md3_mcp_forbidden` (403) without `edit_post` on it; `md3_mcp_no_changes` (400) when none of the four fields is sent.

- [ ] **Step 1: Write the failing tests** in `test-update-media.php` (writer = `as_mcp_user( [ 'content:read', 'content:write' ] )`, image = `self::factory()->attachment->create( [ 'post_mime_type' => 'image/png', 'post_title' => 'Old' ] )`):
  - `test_updates_only_the_fields_sent`: `alt => 'A dog'`, `caption => 'In the park'`; alt meta is `A dog`, `post_excerpt` is `In the park`, `post_title` still `Old`; the result's `alt` is `A dog` and `id` is the image.
  - `test_updates_title_and_description`: `post_title` and `post_content` change.
  - `test_fields_are_saved_as_plain_text`: `alt => '<script>alert(1)</script>Dog'`, `title => '<b>Hi</b>'`; alt meta is `Dog` (`wp_strip_all_tags` drops the script and its content) and the title is `Hi`.
  - `test_an_empty_alt_clears_it`: alt set to `x`, then `alt => ''`; alt meta is `''`.
  - `test_needs_at_least_one_field`: only `id`; `md3_mcp_no_changes`.
  - `test_only_images_can_be_updated`: a post ID, a `application/pdf` attachment, and `999999` each give `md3_mcp_not_found`; nothing changes.
  - `test_needs_the_write_scope`: `content:read` only; `check_permission( [ 'id' => $img, 'alt' => 'x' ] )` is `md3_mcp_insufficient_scope`.
  - `test_an_image_on_live_content_needs_the_publish_scope`: for each status `publish`, `future`, `private`: a post of that status with the image as featured image (`set_post_thumbnail`) → `insufficient_scope` with write only, `true` with write + publish. Also an image whose `post_parent` is a published post → `insufficient_scope`.
  - `test_an_image_on_draft_or_trashed_content_needs_only_write`: featured image of a `draft` post and of a `trash` post → `true` with write only.
  - `test_needs_edit_rights_on_the_image`: role `contributor` (with write scope), image owned by an admin → `md3_mcp_forbidden`.
  - `test_is_registered_and_logged`: `wp_get_ability( 'md3-wp-mcp/update-media' )` exists; after a successful run, the log has a row with tool `update-media`, result `ok`, `object_id` = image ID.
- [ ] **Step 2: Run** `bin/test.sh --filter Test_Update_Media`. Expected: FAIL, class not found.
- [ ] **Step 3: Implement `Update_Media`.** `object_permission()` returns `require_scope( Scopes::PUBLISH )` when `used_by_live_content( id )`, else true. `used_by_live_content()`: true when the attachment's `post_parent` is live, or any post with `_thumbnail_id = id` is live (one prepared query joining `postmeta` and `posts` on status IN LIVE_STATUSES). `execute()`: check image (`wp_attachment_is_image`), `current_user_can( 'edit_post', $id )`, at least one field; alt with `update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) )`; others in one `wp_update_post( wp_slash( [ 'ID' => …, 'post_title' => …, 'post_excerpt' => …, 'post_content' => … ] ), true )` with only the fields sent; return `Content_Formatter::media( $id )`. Description text: "Changes the alt text, title, caption or description of an image in the media library. Only the fields sent change; an empty string clears one. Images already placed in post content keep their own alt text and caption: change those with update-content. Changing an image used by live content needs the publish scope." Annotations: not readonly, not destructive, idempotent.
- [ ] **Step 4: Add `new Update_Media()` to `Registry::abilities()`** after `new List_Media()`.
- [ ] **Step 5: Run** `bin/test.sh --filter Test_Update_Media`. Expected: OK.
- [ ] **Step 6: Commit** `MD3-31 Add update-media`.

### Task 3: missing_alt on list-media

**Files:**
- Modify: `includes/abilities/class-list-media.php`
- Test: `tests/integration/test-upload-links.php`

**Interfaces:**
- Consumes: `update-media` (Task 2) in the test.
- Produces: `list-media` input `missing_alt` (boolean, default false).

- [ ] **Step 1: Write the failing test** `test_list_media_finds_images_without_alt_text`: three images: alt `A`, no alt meta, alt meta `''`. `list-media` with `missing_alt => true` returns exactly the last two IDs; after `update-media` sets alt on one of them, only one remains.
- [ ] **Step 2: Run it.** Expected: FAIL (all three returned, or a schema error for the unknown property).
- [ ] **Step 3: Implement:** add the property to the schema and, when true, `meta_query` `relation OR`: `_wp_attachment_image_alt` `NOT EXISTS`, or `= ''`. Update the description: "Use missing_alt to find images that still need alt text."
- [ ] **Step 4: Run** `bin/test.sh --filter Test_Upload_Links`. Expected: OK.
- [ ] **Step 5: Commit** `MD3-31 Let list-media find images without alt text`.

### Task 4: Docs, translations and the full run

**Files:**
- Modify: `README.md` (tools table, "That is 18 tools, or 17 without Polylang", a line under "Images attached in the chat" that `update-media` fixes alt text later), `docs/design.md` (§5 table row for `update-media`, `list-media` row mentions `missing_alt`), `docs/development.md` (test table row for `test-update-media.php`), `CHANGELOG.md` (0.2.0: `update-media` and `missing_alt` lines), `languages/*`, `CLAUDE.md` (via `sync-claude-md`).

- [ ] **Step 1: Update the docs** listed above.
- [ ] **Step 2: Translations:** `make-pot`, translate the new strings in `md3-wp-mcp-es_ES.po` (formal "usted", Latin American Spanish as in the file), drop obsolete entries, `make-mo` (commands in docs/development.md). `msgattrib --untranslated` prints nothing.
- [ ] **Step 3: Run the `sync-claude-md` skill.** `check.sh` prints `CHECK: no mechanical drift found.`
- [ ] **Step 4: Full run:** `bin/test.sh`. Expected: `OK`, and `grep -c "\[ERROR\]"` on the output is 0.
- [ ] **Step 5: Commit** `MD3-31 Document update-media`, then push.
