# Changelog

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
