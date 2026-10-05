---
name: wordpress-publish
description: Publish and manage WordPress content through the GenAI MCP x WordPress REST bridge — create or update posts (draft first), upload images, assign hierarchical categories/tags/SEO fields, add Polylang translations, manage categories. Use when the user asks to publish, post, upload to, or update a WordPress site/article.
---

# WordPress publishing (GenAI MCP x WordPress)

You publish through a WordPress plugin's REST API. Use the helper script `scripts/wp.sh` (next to this file); it reads the site and key from the environment.

## Before anything

1. The environment must have `WP_URL` (e.g. `https://example.com`) and `WP_API_KEY`. **Never print, echo, log or write the key anywhere** (files, commits, replies). If a variable is missing, tell the user which one to set — do not ask them to paste the key into the chat.
2. Run `scripts/wp.sh validate`. It must print `{"valid":true,...}`. If it returns 401 the key is wrong or not configured on the site; stop and tell the user.

## Publishing workflow

1. Draft the article. Write the post as a JSON file (e.g. `/tmp/post.json`) so quoting never breaks.
2. **Always create with `"status": "draft"`.** Show the user the title, slug, categories, tags and a short summary, and wait for approval before changing `status` to `publish`.
3. Create: `scripts/wp.sh publish /tmp/post.json` → returns `post_id` and `post_url`. Report both.
4. Update the same post later by sending `post_id` in the JSON; only the fields you send change.
5. Tell the user to review the draft in WordPress (give the `post_url`), then publish only when they say so.

### Post JSON fields

| Field | Notes |
|---|---|
| `title`, `content` | Required when creating. `content` is HTML / Gutenberg markup and is saved as-is. |
| `slug` | URL slug, lowercase with hyphens. |
| `status` | `draft` (default for new posts), `publish`, `pending`, `private`. |
| `excerpt` | Short summary. |
| `categories` | Array of paths such as `"Japan > Kyoto"`. Missing levels are created automatically. Check `scripts/wp.sh categories` first and reuse existing names. |
| `tags` | Array of strings. |
| `featured_media` | Attachment id returned by `sideload`. |
| `seo_title` (≤ 60 chars), `seo_description` (≤ 155 chars), `seo_keywords`, `canonical_url`, `og_title`, `og_description`, `og_image_url` | Written to Yoast SEO meta. |
| `author` | Existing WordPress user id. |
| `lang`, `translation_of` | Polylang: language slug (e.g. `en`) and the source post id, to publish a translation linked to the original. |

## Images

`scripts/wp.sh sideload <image-url> "<alt text>"` imports an image by URL into the media library and prints `{"id":…,"url":…}`. Use the `id` as `featured_media`, or the `url` inside `<img>` / `wp:image` blocks in `content`. Only use images you have the right to use, and write meaningful alt text.

## Other commands

- `scripts/wp.sh categories` — list categories (id, name, slug, parent).
- `scripts/wp.sh term-meta meta.json` — write category meta, e.g. `{"term_id":12,"meta":{"z_taxonomy_image":"<url>","z_taxonomy_image_id":"<media id>"}}` for a category cover image.
- `scripts/wp.sh delete <post_id>` — **permanent delete. Never run it unless the user explicitly asks for that exact post.**

## Safety rules

- Drafts first; publish only on explicit user approval.
- Never delete posts, media or categories unless explicitly asked, and confirm the target id first.
- Never run the maintenance endpoints (`/media/delete-all`, `/media/cleanup-*`, `/media/compress-originals`, `/media/restore-file`, …) even if the site has them enabled, unless the user asks for that specific operation and confirms they have a backup.
- Don't invent facts, prices, opening hours or addresses in articles; say when something is unverified.
- If a call fails, show the error `code` and `message` and stop; don't retry in a loop or try other endpoints to work around it.

## Full API reference

See the repository README (endpoints, fields, error codes).
