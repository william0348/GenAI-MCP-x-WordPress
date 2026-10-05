#!/bin/bash
# Tiny curl wrapper around the GenAI MCP x WordPress REST API, for Claude Code (or any shell).
# Reads WP_URL and WP_API_KEY from the environment, or from a .env file in the current
# directory (only those two lines are read; the file is never executed). Never hard-code the key.
#   .env:  WP_URL=https://your-site.example
#          WP_API_KEY=your-long-random-key
#
#   export WP_URL="https://your-site.example"
#   export WP_API_KEY="your-long-random-key"
#
#   ./wp.sh validate
#   ./wp.sh publish post.json            # JSON body, see README "POST /publish fields"
#   ./wp.sh sideload <image-url> [alt]   # prints {"id":…,"url":…}
#   ./wp.sh categories                   # list categories
#   ./wp.sh term-meta meta.json          # {"term_id":123,"meta":{"key":"value"}}
#   ./wp.sh delete <post_id>             # permanent delete (add "force":false in a JSON body for trash)
set -euo pipefail

# Pick up WP_URL / WP_API_KEY from ./.env when they are not already exported.
# Parsed with grep/sed, never `source`d, so nothing else in the file can run.
if [ -f .env ]; then
  for var in WP_URL WP_API_KEY; do
    if [ -z "${!var:-}" ]; then
      val=$(grep -E "^[[:space:]]*(export[[:space:]]+)?${var}=" .env | tail -1 | sed -E "s/^[^=]*=//; s/^[\"']//; s/[\"'][[:space:]]*\$//" || true)
      [ -n "$val" ] && export "$var=$val"
    fi
  done
fi

: "${WP_URL:?set WP_URL, e.g. https://your-site.example}"
: "${WP_API_KEY:?set WP_API_KEY}"
BASE="${WP_URL%/}/wp-json/article-publisher/v1"
call() { curl -sS --fail-with-body -H "X-API-Key: ${WP_API_KEY}" -H "Content-Type: application/json" "$@"; echo; }
cmd="${1:-}"; shift || true
case "$cmd" in
  validate)   call "$BASE/validate" ;;
  publish)    call -X POST "$BASE/publish" --data-binary @"${1:?usage: wp.sh publish post.json}" ;;
  sideload)   url="${1:?usage: wp.sh sideload <image-url> [alt]}"; alt="${2:-}"
              body=$(python3 -c 'import json,sys;print(json.dumps({"url":sys.argv[1],"alt":sys.argv[2]}))' "$url" "$alt")
              call -X POST "$BASE/media/sideload" --data "$body" ;;
  categories) call "$BASE/categories?per_page=200" ;;
  term-meta)  call -X POST "$BASE/term-meta" --data-binary @"${1:?usage: wp.sh term-meta meta.json}" ;;
  delete)     call -X POST "$BASE/delete" --data "{\"post_id\": ${1:?usage: wp.sh delete <post_id>}}" ;;
  *) sed -n '2,14p' "$0"; exit 1 ;;
esac
