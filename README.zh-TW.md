# GenAI MCP x WordPress

[English](README.md) · [繁體中文](README.zh-TW.md)

一個小巧、安全的 **REST 橋接外掛**，讓 AI 代理（MCP 伺服器、Claude、腳本、自動化流程）不需要登入 WordPress，就能發布與管理網站內容。

它從一條實際運作中的內容產線抽出來，這條產線已經把數百篇長文（含多語翻譯）發布到 WordPress。AI 內容流程需要的功能都集中在**一把 API 金鑰**、**一個命名空間**之下：`/wp-json/article-publisher/v1/`。

> 為了讓既有的客戶端不用改，REST 命名空間刻意維持 `article-publisher/v1`。

## 功能

**發布文章**
- 一次呼叫（`/publish`）建立或更新文章：標題、內容、摘要、網址代稱（slug）、狀態（`publish`／`draft`／`pending`／`private`）、作者、精選圖片。
- **用路徑指定階層分類**：傳 `"日本 > 京都 > 祇園"`，缺少的層級會自動建立。
- 標籤，以及 **Yoast SEO** 欄位（標題、描述、焦點關鍵字、canonical、Open Graph 標題／描述／圖片）。沒安裝 Yoast 時這些欄位只是不起作用，不會出錯。
- Gutenberg／自訂 HTML **原樣儲存**（API 金鑰就是信任邊界，所以不會被 kses 濾掉行內樣式或自訂區塊）。
- **Polylang 多語翻譯**：傳入 `lang` 與 `translation_of`，文章會被指定語言並連結到來源文章的翻譯群組。
- 刪除文章（`/delete`）。

**媒體**
- **用網址匯入圖片**（`/media/sideload`）與**二進位上傳**（`/media/upload-binary`，給有防盜連的圖片來源），都可以設定替代文字。
- 列出／搜尋媒體、刪除媒體、把附件重新綁定到文章、媒體／磁碟／資料庫用量統計。

**分類與其他**
- 分類列表／新增（重複呼叫不會重複建立）／更新／刪除。
- 寫入分類 meta（`/term-meta`），例如給 Categories Images 類外掛用的分類封面圖，或 Yoast 的分類 SEO。
- 列出作者（`/authors`，包含所有角色）。

**可選功能**（兩者**預設都關閉**）
- `GENAI_MCP_DISABLE_INTERMEDIATE_SIZES`：停止產生縮圖／medium／large 等中間尺寸（適合用無頭架構、由前端即時縮圖的網站）。
- `GENAI_MCP_ENABLE_MAINTENANCE`：註冊會刪除或改寫檔案的媒體維護端點（批次清理、壓縮原圖、打包備份、還原檔案、UpdraftPlus／Imagify 殘留）。詳見[維護端點](#維護端點需手動開啟)。

## 需求

- WordPress 5.8+、PHP 7.4+
- 選用：[Yoast SEO](https://wordpress.org/plugins/wordpress-seo/)（SEO 欄位）、[Polylang](https://wordpress.org/plugins/polylang/)（多語翻譯）

## 安裝

1. 下載此專案，把 `genai-mcp-x-wordpress/` 資料夾複製到 `wp-content/plugins/`；或把該資料夾壓成 zip，從 **外掛 → 安裝外掛 → 上傳外掛** 安裝。
2. 啟用 **GenAI MCP x WordPress**。
3. 設定 API 金鑰，二選一：
   - **設定 → GenAI MCP x WordPress**，或
   - 寫在 `wp-config.php`（建議；會覆蓋設定頁的值）：
     ```php
     define('ARTICLE_PUBLISHER_API_KEY', '換成一長串隨機字串');
     ```
   可以用 `openssl rand -hex 32` 產生。
4. 驗證：
   ```bash
   curl -s -H "X-API-Key: 你的金鑰" https://你的網站/wp-json/article-publisher/v1/validate
   # {"valid":true,"version":"1.0.0"}
   ```

> **請使用 HTTPS。** API 金鑰放在標頭裡傳送；拿到金鑰的人就能發布與刪除內容。

## 驗證方式

每個端點都要帶標頭 `X-API-Key: <金鑰>`。金鑰缺少或錯誤回 `401`。如果網站還沒設定金鑰，所有呼叫也都回 `401`（外掛預設是關閉的）。

## 快速開始

發布一篇草稿（含階層分類、標籤、SEO）：

```bash
curl -s -X POST https://你的網站/wp-json/article-publisher/v1/publish \
  -H "X-API-Key: 你的金鑰" -H "Content-Type: application/json" \
  -d '{
    "title": "京都秋季旅遊實用攻略",
    "content": "<!-- wp:paragraph --><p>你好</p><!-- /wp:paragraph -->",
    "slug": "kyoto-autumn-guide",
    "status": "draft",
    "categories": ["日本 > 京都 > 賞楓"],
    "tags": ["京都", "賞楓"],
    "seo_title": "京都秋季旅遊攻略｜實用指南",
    "seo_description": "什麼時候去、住哪裡、怎麼移動。"
  }'
```

回應：

```json
{"post_id":123,"id":123,"post_url":"https://…/kyoto-autumn-guide/","link":"https://…","status":"draft","polylang":null}
```

之後要更新同一篇，帶上 `"post_id": 123` 即可（只會改你有傳的欄位）。

替文章 123 新增英文版（Polylang）：

```bash
curl -s -X POST https://你的網站/wp-json/article-publisher/v1/publish \
  -H "X-API-Key: 你的金鑰" -H "Content-Type: application/json" \
  -d '{"title":"Kyoto in Autumn","content":"<p>…</p>","slug":"kyoto-autumn-guide-en","status":"publish","lang":"en","translation_of":123}'
```

用網址匯入圖片，再設成精選圖片：

```bash
curl -s -X POST https://你的網站/wp-json/article-publisher/v1/media/sideload \
  -H "X-API-Key: 你的金鑰" -H "Content-Type: application/json" \
  -d '{"url":"https://example.com/photo.jpg","alt":"秋天的金閣寺"}'
# {"id":456,"media_id":456,"source_url":"https://…/photo.jpg","url":"https://…/photo.jpg"}
# 接著：POST /publish，帶 {"post_id":123,"featured_media":456}
```

## API 參考

基底網址：`https://你的網站/wp-json/article-publisher/v1`

| 方法 | 路徑 | 用途 |
|---|---|---|
| GET / POST | `/validate` | 檢查金鑰並取得外掛版本 |
| POST | `/publish` | 建立或更新文章 |
| POST | `/delete` | 刪除文章 |
| POST | `/media/sideload` | 用網址匯入圖片 |
| POST | `/media/upload-binary` | 上傳圖片（multipart，欄位名稱 `file`） |
| GET / POST | `/media/list` | 列出媒體（`page`、`per_page` ≤ 200、`search`、`mime_type`） |
| GET / POST | `/media/stats` | 各 mime type 的附件數量 |
| POST | `/media/delete` | 依 `ids` 刪除附件 |
| POST | `/media/rebind` | 重設附件的父文章（`items: [{id, post_id}]`） |
| GET / POST | `/media/disk-usage`、`/media/db-size` | 磁碟／資料庫用量 |
| GET | `/categories` | 列表（`page`、`per_page` ≤ 200、`hide_empty`） |
| POST | `/categories` | 新增（同名同父層重複呼叫不會重複建立） |
| POST | `/categories/{id}` | 更新名稱／slug／描述／父層 |
| DELETE | `/categories/{id}` | 刪除 |
| POST | `/term-meta` | 寫入分類 meta：`{term_id, meta: {key: value}}` |
| GET | `/authors` | 列出使用者（id、名稱、slug、頭像） |

### `POST /publish` 欄位

| 欄位 | 型別 | 說明 |
|---|---|---|
| `post_id` | int | 有帶 → 更新該篇；沒帶 → 建立（需要 `title` 與 `content`）。 |
| `title`、`content`、`excerpt` | string | `content` 儲存時不經 kses 過濾。 |
| `slug` | string | 會經過清理。 |
| `status` | string | `publish`、`draft`、`pending`、`private`。新文章預設 `draft`。 |
| `author` | int | 必須是存在的使用者 id。 |
| `categories` | string[] | 每項是像 `"日本 > 京都"` 的路徑，會指派最末層的分類。 |
| `tags` | string[] | |
| `featured_media` | int | 附件 id。 |
| `seo_title`、`seo_description`、`seo_keywords`、`canonical_url`、`og_title`、`og_description`、`og_image_url` | string | 寫入 Yoast 文章 meta。 |
| `schema_type` | string | 存在 `_apl_schema_type`。 |
| `lang` | string | Polylang 語言代碼（例如 `en`）。Polylang 沒啟用時會被忽略。 |
| `translation_of` | int | 來源文章 id；這篇會加入該翻譯群組。 |

回應同時包含多種欄位名稱（`post_id`＋`id`、`post_url`＋`link`、`source_url`＋`url`、`id`＋`media_id`），方便不同客戶端解析。

### 錯誤

錯誤採用標準 WordPress `WP_Error` JSON 格式（`code`、`message`、`data.status`）。常見代碼：`invalid_api_key`、`no_api_key_configured`、`missing_fields`、`post_not_found`、`download_failed`（匯入圖片時抓不到網址）、`sideload_failed`。

## 搭配 MCP／AI 代理使用

這個外掛是 AI 發布流程中 WordPress 這一側。MCP 伺服器（或任何代理框架）把上面的端點包成工具即可。最小工具組：

| 工具 | 呼叫 |
|---|---|
| `wp_publish_post` | `POST /publish` |
| `wp_upload_image` | `POST /media/sideload` |
| `wp_list_categories`／`wp_create_category` | `GET`／`POST /categories` |
| `wp_set_category_cover` | `POST /media/sideload`，再 `POST /term-meta`（`z_taxonomy_image`、`z_taxonomy_image_id`） |
| `wp_delete_post` | `POST /delete` |

`wp_publish_post` 的輸入 schema 範例：

```json
{
  "type": "object",
  "properties": {
    "title": {"type": "string"},
    "content": {"type": "string", "description": "HTML／Gutenberg 標記"},
    "slug": {"type": "string"},
    "status": {"enum": ["draft", "publish", "pending", "private"]},
    "categories": {"type": "array", "items": {"type": "string"}, "description": "路徑，例如 '日本 > 京都'"},
    "tags": {"type": "array", "items": {"type": "string"}},
    "seo_title": {"type": "string"},
    "seo_description": {"type": "string"},
    "post_id": {"type": "integer", "description": "有帶就是更新既有文章"},
    "lang": {"type": "string"},
    "translation_of": {"type": "integer"}
  },
  "required": ["title", "content"]
}
```

API 金鑰請放在 MCP 伺服器的環境變數中（不要放進提示詞或模型能看到的內容），並且建議先建立 `draft` 草稿，由人工確認後再發布。

## Claude 端設定

### 做法 A：Claude Code（現在就能用，不需要 MCP 伺服器）

1. **在 WordPress 安裝外掛並設定 API 金鑰**（見[安裝](#安裝)）。
2. **用環境變數把網址和金鑰交給 Claude**（不要把金鑰貼進對話或提示詞）。在啟動 Claude Code 的終端機裡：
   ```bash
   export WP_URL="https://你的網站"
   export WP_API_KEY="你的長隨機金鑰"
   ```
   想要永久生效，就把這兩行寫進 `~/.zshrc`／`~/.bashrc`（或用已加入 `.gitignore` 的 `.env` 檔）。
3. **把輔助腳本複製到你的專案**：
   ```bash
   mkdir -p tools && cp examples/claude-code/wp.sh tools/wp.sh && chmod +x tools/wp.sh
   tools/wp.sh validate        # → {"valid":true,"version":"1.0.0"}
   ```
   指令：`validate`、`publish post.json`、`sideload <圖片網址> [替代文字]`、`categories`、`term-meta meta.json`、`delete <post_id>`。
4. **教 Claude 遵守規則**：把 [`examples/claude-code/CLAUDE.md.snippet`](examples/claude-code/CLAUDE.md.snippet) 的內容附加到專案的 `CLAUDE.md`。它會要求 Claude 使用這支腳本、不要印出金鑰、一律先建草稿、發布或刪除前先問你。（如果腳本放在別的路徑，記得修改範本裡的路徑。）
5. **試一次。** 在專案裡啟動 `claude`，例如說：
   > 幫我寫一篇「京都秋季旅遊」草稿，分類「日本 > 京都」，用 `tools/wp.sh` 建成草稿，並把結果給我看。

   Claude 會寫出 JSON 檔、執行 `tools/wp.sh publish`，並回報回傳的 `post_url`。你到 WordPress 檢查草稿，確認後再叫 Claude 發布。

> 小技巧：在 Claude Code 裡只允許這支腳本（例如權限規則 `Bash(tools/wp.sh:*)`），其他指令仍然會先詢問你。

### 做法 B：MCP 伺服器（Claude Desktop、Claude Code、其他 MCP 用戶端）

這個專案**目前沒有附 MCP 伺服器**。如果你自己做一個（或用通用的 REST 轉 MCP 橋接工具），把端點包成工具（見[搭配 MCP／AI 代理使用](#搭配-mcpai-代理使用)），再到用戶端註冊，例如寫在 `claude_desktop_config.json`，或用 `claude mcp add`：

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": ["/路徑/你的-wordpress-mcp-server.js"],
      "env": { "WP_URL": "https://你的網站", "WP_API_KEY": "你的長隨機金鑰" }
    }
  }
}
```

## 設定常數

寫在 `wp-config.php`：

| 常數 | 預設 | 作用 |
|---|---|---|
| `ARTICLE_PUBLISHER_API_KEY` | — | API 金鑰，會覆蓋設定頁儲存的值。 |
| `GENAI_MCP_ENABLE_MAINTENANCE` | 關 | 註冊下方的維護端點。 |
| `GENAI_MCP_DISABLE_INTERMEDIATE_SIZES` | 關 | 新上傳的圖片不再產生 `thumbnail`、`medium`、`medium_large`、`large`。 |

## 維護端點（需手動開啟）

只有在 `GENAI_MCP_ENABLE_MAINTENANCE` 為 `true` 時才會註冊。這些端點會刪除或改寫磁碟上的檔案，**使用前請先備份**，用完後把常數關掉。

`/media/delete-all`、`/media/cleanup-unused-sizes`、`/media/cleanup-old-sizes`、`/media/cleanup-explicit-sizes`、`/media/cleanup-nextgen`、`/media/cleanup-orphan-webp`、`/media/compress-originals`、`/media/regenerate-large`、`/media/scan-large-originals`、`/media/restore-file`（會拒絕可執行檔與設定檔類型）、`/media/package-*`（刪除前先把檔案打包成 zip）、`/media/wp-content-usage`、`/media/list-updraft-move`、`/media/delete-updraft-move`。

## 安全注意事項

- API 金鑰是唯一的憑證，請當成管理員密碼對待：使用長隨機值、只走 HTTPS、放在 `wp-config.php` 或環境變數、外洩就換新。
- `/publish` 儲存 HTML 時不經 kses 過濾，`/delete` 預設是永久刪除（帶 `"force": false` 才會進垃圾桶）。只把金鑰交給你信任的系統。
- 如果呼叫端的 IP 固定，建議在網頁伺服器或 WAF 加上 IP 白名單。
- 維護端點預設關閉，需要你主動開啟。

## 開發

單檔外掛、不需要建置：`genai-mcp-x-wordpress/genai-mcp-x-wordpress.php`。要產生可安裝的 zip：

```bash
./build-zip.sh   # → dist/genai-mcp-x-wordpress.zip
```

歡迎貢獻；較大的修改請先開 issue 討論。

## 授權

GPL-2.0-or-later，詳見 [LICENSE](LICENSE)。
