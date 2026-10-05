# Changelog

## Unreleased

- `wp.sh` also reads `WP_URL` / `WP_API_KEY` from a `.env` file in the current directory (parsed, never executed).

## 1.0.1 — security hardening

Found in a security review of 1.0.0. Upgrade recommended.

- `/publish`: kses filtering is now **on by default** (a key holder could previously store `<script>` that runs in an administrator's session). Opt back in to raw HTML with `define('GENAI_MCP_ALLOW_UNFILTERED_HTML', true);`. Filters are always restored (`try/finally`).
- `/publish` update and `/delete` only touch posts of type `post` (no pages, products, orders or menu items).
- `/media/sideload`: URLs must resolve to public addresses (blocks 169.254.169.254 cloud metadata, IPv6 loopback/private, private ranges); download/upload errors no longer leak server paths or HTTP details.
- `/media/restore-file` (maintenance): extension **allowlist** (jpg/png/gif/webp/avif), executable names rejected anywhere in the filename, content must be a real image.
- Maintenance zip folders are now protected (`.htaccess`, `index.html`) and zip names carry a random token.
- `/authors`: only users who can edit posts, no avatar (Gravatar hash) URLs.
- `/term-meta`: only `category`/`post_tag` terms and allowlisted keys (`z_taxonomy_image`, `z_taxonomy_image_id`, `wpseo_*`; extend with the `genai_mcp_term_meta_keys` filter).
- `/media/db-size` (maintenance): only this site's table prefix, no database name.
- An API key is generated automatically on activation; the settings page has a "Generate new key" button.
- Opt-in constants must be exactly `true` (the string `'false'` no longer enables them).

## 1.0.0
First public release, extracted from a production content pipeline.

- Publish / update posts with hierarchical category paths, tags, Yoast SEO fields, featured media.
- Polylang support (`lang`, `translation_of`).
- Media sideload by URL and binary upload; list / delete / rebind; usage statistics.
- Category CRUD, term meta, authors.
- API-key authentication via `X-API-Key` (`ARTICLE_PUBLISHER_API_KEY` constant or Settings page).
- Destructive media-maintenance endpoints are **opt-in** (`GENAI_MCP_ENABLE_MAINTENANCE`).
- Disabling WordPress intermediate image sizes is **opt-in** (`GENAI_MCP_DISABLE_INTERMEDIATE_SIZES`).
- `/media/restore-file` now refuses executable and config file types.
