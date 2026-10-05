# GenAI MCP x WordPress

[English](README.md) · [繁體中文](README.zh-TW.md)

A small, secure **REST bridge** that lets AI agents — MCP servers, Claude, scripts, automation pipelines — publish and manage WordPress content without a logged-in user or the full WordPress REST API permission model.

It was extracted from a production content pipeline that has published several hundred long-form articles (with translations) to WordPress. Everything an AI content workflow needs lives behind **one API key** and **one namespace**: `/wp-json/article-publisher/v1/`.

> The REST namespace is intentionally still `article-publisher/v1` so existing clients keep working.

## Features

**Publishing**
- Create or update posts in a single call (`/publish`) — title, content, excerpt, slug, status (`publish` / `draft` / `pending` / `private`), author, featured image.
- **Hierarchical categories by path** — send `"Japan > Kyoto > Gion"` and missing levels are created automatically.
- Tags, and **Yoast SEO** fields (title, description, focus keyword, canonical, Open Graph title / description / image). These are harmless no-ops when Yoast is not installed.
- Content goes through WordPress's `kses` filter by default (no `<script>`). Opt in to raw Gutenberg / custom HTML with `GENAI_MCP_ALLOW_UNFILTERED_HTML` (the key then equals admin power).
- **Polylang translations** — pass `lang` and `translation_of` and the post is assigned a language and linked into the source post's translation group.
- Delete posts (`/delete`).

**Media**
- **Sideload by URL** (`/media/sideload`) and **binary upload** (`/media/upload-binary`, for hotlink-protected images), both with alt text.
- List / search media, delete media, rebind attachments to a post, media / disk / database statistics.

**Taxonomy**
- Category list / create (idempotent) / update / delete.
- Write term meta (`/term-meta`) — e.g. category cover images for Categories Images plugins, or Yoast term SEO.
- List authors (`/authors`, includes every role).

**Opt-in extras** (both **off by default**)
- `GENAI_MCP_DISABLE_INTERMEDIATE_SIZES` — stop WordPress generating thumbnail / medium / large sizes (useful for headless sites that resize on demand).
- `GENAI_MCP_ENABLE_MAINTENANCE` — registers destructive media-maintenance endpoints (bulk cleanup, compress originals, zip packaging, restore file, UpdraftPlus / Imagify leftovers). See [Maintenance endpoints](#maintenance-endpoints-opt-in).

## Requirements

- WordPress 5.8+, PHP 7.4+
- Optional: [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) (SEO fields), [Polylang](https://wordpress.org/plugins/polylang/) (translations)

## Installation

1. Download this repo (or the release zip) and copy the `genai-mcp-x-wordpress/` folder to `wp-content/plugins/`, or zip that folder and use **Plugins → Add New → Upload Plugin**.
2. Activate **GenAI MCP x WordPress**.
3. Copy your API key: it was **generated automatically on activation**. Open **Settings → GenAI MCP x WordPress** and copy it (click "Generate new key" and Save to rotate it).
   *(Advanced, optional: define `ARTICLE_PUBLISHER_API_KEY` in `wp-config.php` to keep the key out of the database; it overrides the settings field.)*
4. Verify:
   ```bash
   curl -s -H "X-API-Key: YOUR_KEY" https://your-site.example/wp-json/article-publisher/v1/validate
   # {"valid":true,"version":"1.0.1"}
   ```

> **Use HTTPS.** The API key travels in a header; anyone who has it can publish and delete content.

## Authentication

Every endpoint requires the header `X-API-Key: <key>`. A missing or wrong key returns `401`. A random key is **generated automatically when you activate the plugin**: open *Settings → GenAI MCP x WordPress* to copy it (or click "Generate new key" and save to rotate it). You can instead define `ARTICLE_PUBLISHER_API_KEY` in `wp-config.php`, which overrides it. If no key is configured, every call returns `401` (the plugin is closed by default).

## Quick start (with Claude)

Claude does not know this API by itself — you give it a **Skill** that teaches it how to publish. After that you just talk to it.

1. **Install the plugin on WordPress and set the API key** — see [Installation](#installation).
2. **Install the Skill for Claude Code** (once per computer):
   ```bash
   git clone https://github.com/william0348/GenAI-MCP-x-WordPress.git
   mkdir -p ~/.claude/skills
   cp -r GenAI-MCP-x-WordPress/skills/wordpress-publish ~/.claude/skills/
   chmod +x ~/.claude/skills/wordpress-publish/scripts/wp.sh
   ```
   (To use it only in one project, copy the folder to `<your-project>/.claude/skills/` instead.)
3. **Give Claude the site and key** (never paste the key into a chat). Either put them in a `.env` file in your project folder:
   ```bash
   WP_URL=https://your-site.example
   WP_API_KEY=your-long-random-key
   ```
   (add `.env` to `.gitignore`), or export them in the terminal where you start Claude Code:
   ```bash
   export WP_URL="https://your-site.example"
   export WP_API_KEY="your-long-random-key"
   claude
   ```
   The helper script only reads those two lines from `.env`; it never executes the file. Variables already exported win over `.env`.
4. **Ask in plain language**, for example:
   > Write a draft article "Kyoto in Autumn" in the category Japan > Kyoto and publish it to my WordPress as a draft.

   Claude loads the `wordpress-publish` skill, checks the connection, creates the draft and gives you the link. Review it in WordPress, then tell Claude to publish.

What the Skill makes Claude do: always create **drafts first** and ask before publishing, never print the API key, never delete or run maintenance endpoints unless you explicitly ask, and show you the error instead of retrying blindly. Read [`skills/wordpress-publish/SKILL.md`](skills/wordpress-publish/SKILL.md) to see (or change) the exact rules.

> **Anyone who wants to use this with Claude must install the Skill** (step 2). Without it Claude has no instructions for the API. The Skill targets **Claude Code** because it runs a local shell script with your environment variables.

### Manual API examples (for developers and other tools)

You do not need these when using Claude with the Skill. They are for scripts, other AI frameworks or debugging.


Publish a draft with hierarchical categories, tags and SEO:

```bash
curl -s -X POST https://your-site.example/wp-json/article-publisher/v1/publish \
  -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d '{
    "title": "Kyoto in Autumn: A Practical Guide",
    "content": "<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->",
    "slug": "kyoto-autumn-guide",
    "status": "draft",
    "categories": ["Japan > Kyoto > Autumn"],
    "tags": ["kyoto", "autumn"],
    "seo_title": "Kyoto in Autumn | Practical Guide",
    "seo_description": "When to go, where to stay and how to get around."
  }'
```

Response:

```json
{"post_id":123,"id":123,"post_url":"https://…/kyoto-autumn-guide/","link":"https://…","status":"draft","polylang":null}
```

Update the same post later by sending `"post_id": 123` (only the fields you send are changed).

Add a Polylang translation of post 123 into English:

```bash
curl -s -X POST https://your-site.example/wp-json/article-publisher/v1/publish \
  -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d '{"title":"Kyoto in Autumn","content":"<p>…</p>","slug":"kyoto-autumn-guide-en","status":"publish","lang":"en","translation_of":123}'
```

Upload an image from a URL and use it as the featured image:

```bash
curl -s -X POST https://your-site.example/wp-json/article-publisher/v1/media/sideload \
  -H "X-API-Key: YOUR_KEY" -H "Content-Type: application/json" \
  -d '{"url":"https://example.com/photo.jpg","alt":"Kinkaku-ji in autumn"}'
# {"id":456,"media_id":456,"source_url":"https://…/photo.jpg","url":"https://…/photo.jpg"}
# then: POST /publish with {"post_id":123,"featured_media":456}
```

## API reference

Base URL: `https://your-site.example/wp-json/article-publisher/v1`

| Method | Path | Purpose |
|---|---|---|
| GET / POST | `/validate` | Check the key and get the plugin version |
| POST | `/publish` | Create or update a post |
| POST | `/delete` | Delete a post |
| POST | `/media/sideload` | Import an image from a URL |
| POST | `/media/upload-binary` | Upload an image (multipart, field name `file`) |
| GET / POST | `/media/list` | List media (`page`, `per_page` ≤ 200, `search`, `mime_type`) |
| GET / POST | `/media/stats` | Attachment counts per mime type |
| POST | `/media/delete` | Delete attachments by `ids` |
| POST | `/media/rebind` | Set attachments' parent post (`items: [{id, post_id}]`) |
| GET / POST | `/media/disk-usage`, `/media/db-size` | Disk / database size |
| GET | `/categories` | List (`page`, `per_page` ≤ 200, `hide_empty`) |
| POST | `/categories` | Create (idempotent for the same name + parent) |
| POST | `/categories/{id}` | Update name / slug / description / parent |
| DELETE | `/categories/{id}` | Delete |
| POST | `/term-meta` | Write term meta: `{term_id, meta: {key: value}}` |
| GET | `/authors` | List users (id, name, slug, avatars) |

### `POST /publish` fields

| Field | Type | Notes |
|---|---|---|
| `post_id` | int | Present → update this post. Absent → create (needs `title` and `content`). |
| `title`, `content`, `excerpt` | string | `content` is kses-filtered by default (see `GENAI_MCP_ALLOW_UNFILTERED_HTML`). |
| `slug` | string | Sanitized. |
| `status` | string | `publish`, `draft`, `pending`, `private`. New posts default to `draft`. |
| `author` | int | Must be an existing user id. |
| `categories` | string[] | Each item is a path like `"Japan > Kyoto"`. Leaf terms are assigned. |
| `tags` | string[] | |
| `featured_media` | int | Attachment id. |
| `seo_title`, `seo_description`, `seo_keywords`, `canonical_url`, `og_title`, `og_description`, `og_image_url` | string | Written to Yoast post meta. |
| `schema_type` | string | Stored in `_apl_schema_type`. |
| `lang` | string | Polylang language slug (e.g. `en`). Ignored when Polylang is not active. |
| `translation_of` | int | Source post id; the post joins that translation group. |

Responses include both field-name variants (`post_id`+`id`, `post_url`+`link`, `source_url`+`url`, `id`+`media_id`) so different clients can parse them.

### Errors

Errors use the standard WordPress `WP_Error` JSON shape (`code`, `message`, `data.status`). Notable codes: `invalid_api_key`, `no_api_key_configured`, `missing_fields`, `post_not_found`, `download_failed` (sideload could not fetch the URL), `sideload_failed`.

## Using it with MCP / AI agents

The plugin is the WordPress-side half of an AI publishing workflow. An MCP server (or any agent framework) wraps the endpoints above as tools. A minimal tool set:

| Tool | Calls |
|---|---|
| `wp_publish_post` | `POST /publish` |
| `wp_upload_image` | `POST /media/sideload` |
| `wp_list_categories` / `wp_create_category` | `GET` / `POST /categories` |
| `wp_set_category_cover` | `POST /media/sideload` then `POST /term-meta` (`z_taxonomy_image`, `z_taxonomy_image_id`) |
| `wp_delete_post` | `POST /delete` |

Example tool input schema for `wp_publish_post`:

```json
{
  "type": "object",
  "properties": {
    "title": {"type": "string"},
    "content": {"type": "string", "description": "HTML / Gutenberg markup"},
    "slug": {"type": "string"},
    "status": {"enum": ["draft", "publish", "pending", "private"]},
    "categories": {"type": "array", "items": {"type": "string"}, "description": "paths like 'Japan > Kyoto'"},
    "tags": {"type": "array", "items": {"type": "string"}},
    "seo_title": {"type": "string"},
    "seo_description": {"type": "string"},
    "post_id": {"type": "integer", "description": "set to update an existing post"},
    "lang": {"type": "string"},
    "translation_of": {"type": "integer"}
  },
  "required": ["title", "content"]
}
```

Keep the API key in the MCP server's environment (never in prompts or in the model's context), and prefer creating `draft` posts so a human reviews before publishing.

## Claude setup

### Option A — Claude Code + the Skill (works today, no MCP server needed)

Follow the [Quick start](#quick-start-with-claude): install the plugin, install the `wordpress-publish` Skill, set `WP_URL` and `WP_API_KEY`, then ask Claude to publish.

Optional hardening: in Claude Code allow only the helper script, for example the permission rule `Bash(~/.claude/skills/wordpress-publish/scripts/wp.sh:*)`, so every other command still asks first. The helper can also be used on its own:

```bash
~/.claude/skills/wordpress-publish/scripts/wp.sh validate        # → {"valid":true,"version":"1.0.1"}
```

Commands: `validate`, `publish post.json`, `sideload <image-url> [alt]`, `categories`, `term-meta meta.json`, `delete <post_id>`.

### Option B — MCP server (Claude Desktop, Claude Code, other MCP clients)

This repository does **not** ship an MCP server yet. If you build one (or use a generic REST-to-MCP bridge), wrap the endpoints as tools (see [Using it with MCP / AI agents](#using-it-with-mcp--ai-agents)) and register it in the client, for example in `claude_desktop_config.json` or via `claude mcp add`:

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": ["/path/to/your/wordpress-mcp-server.js"],
      "env": { "WP_URL": "https://your-site.example", "WP_API_KEY": "your-long-random-key" }
    }
  }
}
```

## Configuration constants

Define in `wp-config.php`:

| Constant | Default | Effect |
|---|---|---|
| `ARTICLE_PUBLISHER_API_KEY` | — | API key. Overrides the value saved in Settings. |
| `GENAI_MCP_ENABLE_MAINTENANCE` | off | Registers the maintenance endpoints below. |
| `GENAI_MCP_ALLOW_UNFILTERED_HTML` | off | Skips kses on `/publish` so raw HTML / inline styles are stored. The key can then store scripts: treat it as admin. |
| `GENAI_MCP_DISABLE_INTERMEDIATE_SIZES` | off | Stops generating `thumbnail`, `medium`, `medium_large`, `large` for new uploads. |

## Maintenance endpoints (opt-in)

Available only when `GENAI_MCP_ENABLE_MAINTENANCE` is `true`. These delete or rewrite files on disk — **back up first**, and turn the constant off again when you are done.

`/media/delete-all`, `/media/cleanup-unused-sizes`, `/media/cleanup-old-sizes`, `/media/cleanup-explicit-sizes`, `/media/cleanup-nextgen`, `/media/cleanup-orphan-webp`, `/media/compress-originals`, `/media/regenerate-large`, `/media/scan-large-originals`, `/media/restore-file` (refuses executable / config file types), `/media/package-*` (zip a set of files before deleting them), `/media/wp-content-usage`, `/media/list-updraft-move`, `/media/delete-updraft-move`.

## Security notes

- The API key is the only credential. Treat it like an admin password: long random value, HTTPS only, stored in `wp-config.php` or an environment variable, rotated if leaked.
- `/publish` keeps WordPress's kses filtering by default. If you need raw HTML (inline styles, custom blocks), set `define('GENAI_MCP_ALLOW_UNFILTERED_HTML', true);` — then the key can store scripts, so it is effectively an admin account. `/delete` deletes permanently by default (`"force": false` moves to trash) and only works on posts. Only give the key to systems you trust.
- Restrict the endpoints at your web server / WAF (IP allow-list) if the caller has a fixed address.
- Maintenance endpoints are disabled unless you opt in.

## Development

Single-file plugin, no build step: `genai-mcp-x-wordpress/genai-mcp-x-wordpress.php`. To build an installable zip:

```bash
./build-zip.sh   # → dist/genai-mcp-x-wordpress.zip
```

Contributions are welcome — please open an issue first for larger changes.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
