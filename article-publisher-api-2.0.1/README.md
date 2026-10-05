# Article Publisher API 2.0.1

The production plugin ("Article Publisher API", folder `article-publisher`) with the 1.0.1 security fixes ported, **without changing its behaviour**. Upload `article-publisher-api-2.0.1.zip` over the existing plugin (same folder name, so WordPress replaces it in place); the existing API key is kept.

Compared with 2.0.0:

- API key is generated automatically on first activation (an existing key is never replaced); Settings has a "Generate new key" button.
- `/publish` update and `/delete` only touch posts of type `post`. kses bypass is unchanged but restored in `try/finally`; `define('ARTICLE_PUBLISHER_KEEP_KSES', true)` turns kses filtering back on.
- `/media/sideload` rejects URLs that resolve to private/loopback/link-local addresses; errors no longer leak server paths.
- `/media/restore-file` is an image-only allowlist and validates the content.
- Backup zip folders are protected and zip names are random.
- `/authors` lists only users who can edit posts, without avatar URLs.
- `/term-meta` is limited to category/post_tag and allowlisted keys (`z_taxonomy_image`, `z_taxonomy_image_id`, `wpseo_*`; extend with the `article_publisher_term_meta_keys` filter).
- `/media/db-size` only reports this site's table prefix.

Unlike the `genai-mcp-x-wordpress` plugin in this repo, the maintenance endpoints stay registered here (as in 2.0.0).
