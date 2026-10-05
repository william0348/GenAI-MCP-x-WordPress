#!/bin/bash
# Builds dist/genai-mcp-x-wordpress.zip (installable via Plugins → Add New → Upload Plugin)
set -e
cd "$(dirname "$0")"
mkdir -p dist
rm -f dist/genai-mcp-x-wordpress.zip
zip -r dist/genai-mcp-x-wordpress.zip genai-mcp-x-wordpress -x "*.DS_Store"
echo "built dist/genai-mcp-x-wordpress.zip"
