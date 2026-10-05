<?php
/**
 * Plugin Name: GenAI MCP x WordPress
 * Plugin URI:  https://github.com/william0348/GenAI-MCP-x-WordPress
 * Description: A secure REST bridge that lets AI agents (MCP servers, Claude, scripts) publish and manage WordPress content — posts with hierarchical categories, SEO meta and Polylang translations, media sideload/upload, category CRUD and term meta.
 * Version: 1.0.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: william0348
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: genai-mcp-x-wordpress
 *
 * All endpoints live under /wp-json/article-publisher/v1/ (the namespace is kept for
 * backwards compatibility) and authenticate with an X-API-Key header. Set the key
 * under Settings → GenAI MCP x WordPress, or define ARTICLE_PUBLISHER_API_KEY in
 * wp-config.php (the constant wins).
 *
 * Optional switches (define them in wp-config.php):
 *   define('GENAI_MCP_ENABLE_MAINTENANCE', true);        // register the destructive media-maintenance endpoints
 *   define('GENAI_MCP_ALLOW_UNFILTERED_HTML', true);     // optional: skip kses so raw HTML/inline styles are kept (key = admin power)
 *   define('GENAI_MCP_DISABLE_INTERMEDIATE_SIZES', true); // stop WordPress generating thumbnail/medium/large sizes
 */

if (!defined('ABSPATH')) exit;

// ─────────────────────────────────────────────────────────────────────────────
// Optional: disable intermediate image sizes (off by default)
// ─────────────────────────────────────────────────────────────────────────────
// Useful for headless setups that resize images on demand from the original file.
// Enable with: define('GENAI_MCP_DISABLE_INTERMEDIATE_SIZES', true);
// Only affects NEW uploads; already-generated files are untouched.
if (defined('GENAI_MCP_DISABLE_INTERMEDIATE_SIZES') && GENAI_MCP_DISABLE_INTERMEDIATE_SIZES) {
    add_filter('intermediate_image_sizes_advanced', function ($sizes) {
        foreach (['thumbnail', 'medium', 'medium_large', 'large'] as $key) {
            unset($sizes[$key]);
        }
        return $sizes;
    });
    add_filter('intermediate_image_sizes', function ($sizes) {
        return array_diff($sizes, ['thumbnail', 'medium', 'medium_large', 'large']);
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// API key storage & auth
// ─────────────────────────────────────────────────────────────────────────────

function apl_get_api_key() {
    if (defined('ARTICLE_PUBLISHER_API_KEY') && ARTICLE_PUBLISHER_API_KEY) {
        return (string) ARTICLE_PUBLISHER_API_KEY;
    }
    return (string) get_option('apl_api_key', '');
}

/** On activation, create a random API key if none exists (shown in Settings → GenAI MCP x WordPress). */
register_activation_hook(__FILE__, function () {
    if (get_option('apl_api_key', '') === '') {
        update_option('apl_api_key', wp_generate_password(48, false), false);
    }
});

/** Permission callback for every route. 401 on bad/missing key. */
function apl_check_api_key(WP_REST_Request $request) {
    $provided = (string) $request->get_header('x-api-key');
    $expected = apl_get_api_key();
    if ($expected === '') {
        return new WP_Error('no_api_key_configured', 'API key is not configured on this site (Settings → GenAI MCP x WordPress).', ['status' => 401]);
    }
    if ($provided === '' || !hash_equals($expected, $provided)) {
        return new WP_Error('invalid_api_key', 'Invalid API key.', ['status' => 401]);
    }
    return true;
}

// Settings page: Settings → Article Publisher
add_action('admin_menu', function () {
    add_options_page('GenAI MCP x WordPress', 'GenAI MCP x WordPress', 'manage_options', 'article-publisher', 'apl_render_settings_page');
});
add_action('admin_init', function () {
    register_setting('apl_settings', 'apl_api_key', ['sanitize_callback' => 'sanitize_text_field']);
});
function apl_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $constant_set = defined('ARTICLE_PUBLISHER_API_KEY') && ARTICLE_PUBLISHER_API_KEY;
    ?>
    <div class="wrap">
      <h1>GenAI MCP x WordPress</h1>
      <?php if ($constant_set): ?>
        <p><strong>ARTICLE_PUBLISHER_API_KEY</strong> is defined in wp-config.php and overrides the field below.</p>
      <?php endif; ?>
      <form method="post" action="options.php">
        <?php settings_fields('apl_settings'); ?>
        <table class="form-table">
          <tr>
            <th scope="row"><label for="apl_api_key">API Key</label></th>
            <td>
              <input type="text" id="apl_api_key" name="apl_api_key" class="regular-text code"
                     value="<?php echo esc_attr(get_option('apl_api_key', '')); ?>" autocomplete="off" />
              <button type="button" class="button" id="apl_gen_key">Generate new key</button>
              <script>
              document.getElementById('apl_gen_key').addEventListener('click', function () {
                var a = new Uint8Array(24); crypto.getRandomValues(a);
                document.getElementById('apl_api_key').value = Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
              });
              </script>
              <p class="description">Created automatically when the plugin was activated. Click "Generate new key", then Save, to rotate it. Use a long random string (32+ characters). Your AI agent / MCP server must send it in the X-API-Key header.</p>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
      <p>Endpoint base: <code><?php echo esc_html(rest_url('article-publisher/v1/')); ?></code></p>
    </div>
    <?php
}

// ─────────────────────────────────────────────────────────────────────────────
// Route registration
// ─────────────────────────────────────────────────────────────────────────────

/** Maintenance endpoints delete/rewrite files on disk. They are OFF unless explicitly enabled in wp-config.php. */
function apl_maintenance_enabled() {
    return defined('GENAI_MCP_ENABLE_MAINTENANCE') && GENAI_MCP_ENABLE_MAINTENANCE === true;
}

/** Skipping kses lets the key holder store <script>/inline JS. OFF by default; opt in only if you accept that the key equals an admin account. */
function apl_unfiltered_html_enabled() {
    return defined('GENAI_MCP_ALLOW_UNFILTERED_HTML') && GENAI_MCP_ALLOW_UNFILTERED_HTML === true;
}

/** Make a plugin-created folder inside uploads non-browsable (zip backups etc.). */
function apl_protect_dir($dir) {
    if (!is_dir($dir)) wp_mkdir_p($dir);
    if (!file_exists($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    if (!file_exists($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
}

/** Reject URLs that resolve to private, loopback, link-local or reserved addresses (SSRF guard). */
function apl_url_is_public($url) {
    $parts = wp_parse_url($url);
    if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) return false;
    $host = trim($parts['host'], '[]');
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $r) {
            if (!empty($r['ip'])) $ips[] = $r['ip'];
            if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
    }
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

add_action('rest_api_init', function () {
    $ns = 'article-publisher/v1';
    $auth = ['permission_callback' => 'apl_check_api_key'];

    // ── Core: always available ──────────────────────────────────────────────
    register_rest_route($ns, '/validate', ['methods' => ['GET', 'POST'], 'callback' => 'apl_route_validate'] + $auth);

    register_rest_route($ns, '/publish', ['methods' => 'POST', 'callback' => 'apl_route_publish'] + $auth);
    register_rest_route($ns, '/delete',  ['methods' => 'POST', 'callback' => 'apl_route_delete_post'] + $auth);

    register_rest_route($ns, '/media/sideload',      ['methods' => 'POST', 'callback' => 'apl_route_media_sideload'] + $auth);
    register_rest_route($ns, '/media/upload-binary', ['methods' => 'POST', 'callback' => 'apl_route_media_upload_binary'] + $auth);

    register_rest_route($ns, '/media/stats', ['methods' => ['GET', 'POST'], 'callback' => 'apl_route_media_stats'] + $auth);
    register_rest_route($ns, '/media/list',  ['methods' => ['GET', 'POST'], 'callback' => 'apl_route_media_list'] + $auth);
    register_rest_route($ns, '/media/delete', ['methods' => 'POST', 'callback' => 'apl_route_media_delete'] + $auth);
    register_rest_route($ns, '/media/rebind', ['methods' => 'POST', 'callback' => 'apl_route_media_rebind'] + $auth);
    register_rest_route($ns, '/media/disk-usage', ['methods' => ['GET', 'POST'], 'callback' => 'apl_route_media_disk_usage'] + $auth);
    register_rest_route($ns, '/media/db-size',    ['methods' => ['GET', 'POST'], 'callback' => 'apl_route_media_db_size'] + $auth);

    register_rest_route($ns, '/categories', [
        ['methods' => 'GET',  'callback' => 'apl_route_categories_list'] + $auth,
        ['methods' => 'POST', 'callback' => 'apl_route_categories_create'] + $auth,
    ]);
    // Updates arrive as POST (not PUT/PATCH) so simple HTTP clients can call them.
    register_rest_route($ns, '/categories/(?P<id>\d+)', [
        ['methods' => 'POST',   'callback' => 'apl_route_categories_update'] + $auth,
        ['methods' => 'DELETE', 'callback' => 'apl_route_categories_delete'] + $auth,
    ]);

    register_rest_route($ns, '/term-meta', ['methods' => 'POST', 'callback' => 'apl_route_term_meta'] + $auth);
    register_rest_route($ns, '/authors',   ['methods' => 'GET',  'callback' => 'apl_route_authors'] + $auth);

    // ── Maintenance: opt-in (define('GENAI_MCP_ENABLE_MAINTENANCE', true)) ─────
    if (!apl_maintenance_enabled()) return;
    $post = ['methods' => 'POST'];
    $both = ['methods' => ['GET', 'POST']];
    register_rest_route($ns, '/media/delete-all', $post + ['callback' => 'apl_route_media_delete_all'] + $auth);
    register_rest_route($ns, '/media/cleanup-nextgen', $post + ['callback' => 'apl_route_media_cleanup_nextgen'] + $auth);
    register_rest_route($ns, '/media/package-nextgen', $post + ['callback' => 'apl_route_media_package_nextgen'] + $auth);
    register_rest_route($ns, '/media/package-nextgen-cleanup', $post + ['callback' => 'apl_route_media_package_nextgen_cleanup'] + $auth);
    register_rest_route($ns, '/media/cleanup-unused-sizes', $post + ['callback' => 'apl_route_media_cleanup_unused_sizes'] + $auth);
    register_rest_route($ns, '/media/package-unused-sizes', $post + ['callback' => 'apl_route_media_package_unused_sizes'] + $auth);
    register_rest_route($ns, '/media/package-unused-sizes-cleanup', $post + ['callback' => 'apl_route_media_package_unused_sizes_cleanup'] + $auth);
    register_rest_route($ns, '/media/scan-large-originals', $post + ['callback' => 'apl_route_media_scan_large_originals'] + $auth);
    register_rest_route($ns, '/media/regenerate-large', $post + ['callback' => 'apl_route_media_regenerate_large'] + $auth);
    register_rest_route($ns, '/media/compress-originals', $post + ['callback' => 'apl_route_media_compress_originals'] + $auth);
    register_rest_route($ns, '/media/restore-file', $post + ['callback' => 'apl_route_media_restore_file'] + $auth);
    register_rest_route($ns, '/media/wp-content-usage', $both + ['callback' => 'apl_route_media_wp_content_usage'] + $auth);
    register_rest_route($ns, '/media/list-updraft-move', $both + ['callback' => 'apl_route_media_list_updraft_move'] + $auth);
    register_rest_route($ns, '/media/cleanup-orphan-webp', $post + ['callback' => 'apl_route_media_cleanup_orphan_webp'] + $auth);
    register_rest_route($ns, '/media/package-old-sizes', $post + ['callback' => 'apl_route_media_package_old_sizes'] + $auth);
    register_rest_route($ns, '/media/cleanup-old-sizes', $post + ['callback' => 'apl_route_media_cleanup_old_sizes'] + $auth);
    register_rest_route($ns, '/media/package-explicit-sizes', $post + ['callback' => 'apl_route_media_package_explicit_sizes'] + $auth);
    register_rest_route($ns, '/media/cleanup-explicit-sizes', $post + ['callback' => 'apl_route_media_cleanup_explicit_sizes'] + $auth);
    register_rest_route($ns, '/media/delete-updraft-move', $post + ['callback' => 'apl_route_media_delete_updraft_move'] + $auth);
});

// ─────────────────────────────────────────────────────────────────────────────
// /validate
// ─────────────────────────────────────────────────────────────────────────────

function apl_route_validate() {
    return ['valid' => true, 'version' => '1.0.1'];
}

// ─────────────────────────────────────────────────────────────────────────────
// /publish — create or update a post
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resolve one hierarchical category path ("日本 > 京都 > 河原町") to the leaf
 * term id, creating any missing levels along the way.
 */
function apl_resolve_category_path($path) {
    $parts = array_filter(array_map('trim', explode(' > ', (string) $path)), 'strlen');
    $parent_id = 0;
    $leaf_id = 0;
    foreach ($parts as $name) {
        $existing = term_exists($name, 'category', $parent_id ?: null);
        if ($existing && !is_wp_error($existing)) {
            $leaf_id = (int) (is_array($existing) ? $existing['term_id'] : $existing);
        } else {
            $created = wp_insert_term($name, 'category', ['parent' => $parent_id]);
            if (is_wp_error($created)) {
                // Race/duplicate: try to look it up once more before giving up.
                $retry = term_exists($name, 'category', $parent_id ?: null);
                if (!$retry || is_wp_error($retry)) return 0;
                $leaf_id = (int) (is_array($retry) ? $retry['term_id'] : $retry);
            } else {
                $leaf_id = (int) $created['term_id'];
            }
        }
        $parent_id = $leaf_id;
    }
    return $leaf_id;
}

/** Write flat SEO fields to Yoast post meta (harmless no-ops if Yoast absent). */
function apl_write_post_seo($post_id, $p) {
    $map = [
        'seo_title'       => '_yoast_wpseo_title',
        'seo_description' => '_yoast_wpseo_metadesc',
        'seo_keywords'    => '_yoast_wpseo_focuskw',
        'canonical_url'   => '_yoast_wpseo_canonical',
        'og_title'        => '_yoast_wpseo_opengraph-title',
        'og_description'  => '_yoast_wpseo_opengraph-description',
        'og_image_url'    => '_yoast_wpseo_opengraph-image',
    ];
    foreach ($map as $field => $meta_key) {
        if (isset($p[$field]) && $p[$field] !== '') {
            update_post_meta($post_id, $meta_key, wp_slash((string) $p[$field]));
        }
    }
    if (!empty($p['schema_type'])) {
        update_post_meta($post_id, '_apl_schema_type', sanitize_text_field((string) $p['schema_type']));
    }
}

function apl_route_publish(WP_REST_Request $request) {
    $p = $request->get_json_params();
    if (!is_array($p)) {
        return new WP_Error('invalid_body', 'Expected JSON body', ['status' => 400]);
    }

    $post_id = isset($p['post_id']) ? (int) $p['post_id'] : 0;
    $is_update = $post_id > 0;

    if ($is_update && (!get_post($post_id) || get_post_type($post_id) !== 'post')) {
        return new WP_Error('post_not_found', "Post {$post_id} not found", ['status' => 404]);
    }
    if (!$is_update && (!isset($p['title']) || !isset($p['content']))) {
        return new WP_Error('missing_fields', 'title and content are required to create a post', ['status' => 400]);
    }

    $postarr = ['post_type' => 'post'];
    if ($is_update) $postarr['ID'] = $post_id;
    if (isset($p['title']))   $postarr['post_title']   = (string) $p['title'];
    if (isset($p['content'])) $postarr['post_content'] = (string) $p['content'];
    if (isset($p['excerpt'])) $postarr['post_excerpt'] = (string) $p['excerpt'];
    if (!empty($p['slug']))   $postarr['post_name']    = sanitize_title((string) $p['slug']);
    if (isset($p['status']) && in_array($p['status'], ['publish', 'draft', 'pending', 'private'], true)) {
        $postarr['post_status'] = $p['status'];
    } elseif (!$is_update) {
        $postarr['post_status'] = 'draft';
    }
    if (!empty($p['author']) && get_userdata((int) $p['author'])) {
        $postarr['post_author'] = (int) $p['author'];
    }

    // kses stays ON by default so the key cannot store <script>. Sites that
    // need raw Gutenberg HTML can opt in with GENAI_MCP_ALLOW_UNFILTERED_HTML.
    $unfiltered = apl_unfiltered_html_enabled();
    if ($unfiltered) kses_remove_filters();
    $postarr = wp_slash($postarr);
    try {
        $result = $is_update ? wp_update_post($postarr, true) : wp_insert_post($postarr, true);
    } finally {
        if ($unfiltered) kses_init_filters();
    }

    if (is_wp_error($result)) {
        return new WP_Error('publish_failed', $result->get_error_message(), ['status' => 500]);
    }
    $post_id = (int) $result;

    // Categories: array of hierarchical " > " name paths → leaf term ids.
    if (isset($p['categories']) && is_array($p['categories'])) {
        $leaf_ids = [];
        foreach ($p['categories'] as $path) {
            $leaf = apl_resolve_category_path($path);
            if ($leaf) $leaf_ids[] = $leaf;
        }
        if ($leaf_ids) {
            wp_set_object_terms($post_id, array_unique($leaf_ids), 'category');
        }
    }

    if (isset($p['tags']) && is_array($p['tags'])) {
        $tags = array_filter(array_map('strval', $p['tags']), 'strlen');
        wp_set_post_terms($post_id, $tags, 'post_tag');
    }

    if (!empty($p['featured_media'])) {
        set_post_thumbnail($post_id, (int) $p['featured_media']);
    }

    // Polylang (optional, only when the request carries `lang`): set the post language and link it into the
    // translation group of `translation_of` (the source post id). The group is read, merged and saved back because
    // pll_save_post_translations() replaces the whole group.
    $pll_info = null;
    if (!empty($p['lang']) && function_exists('pll_set_post_language') && function_exists('pll_languages_list')) {
        $lang = sanitize_key((string) $p['lang']);
        if (in_array($lang, pll_languages_list(['fields' => 'slug']), true)) {
            pll_set_post_language($post_id, $lang);
            $pll_info = ['lang' => $lang];
            if (!empty($p['translation_of']) && function_exists('pll_get_post_translations') && function_exists('pll_save_post_translations')) {
                $src = (int) $p['translation_of'];
                if ($src > 0 && $src !== $post_id && get_post($src)) {
                    $src_lang = function_exists('pll_get_post_language') ? pll_get_post_language($src) : false;
                    if (!$src_lang) {
                        $src_lang = pll_default_language();
                        pll_set_post_language($src, $src_lang);
                    }
                    $group = pll_get_post_translations($src);
                    if (empty($group)) $group = [$src_lang => $src];
                    $group[$lang] = $post_id;
                    pll_save_post_translations($group);
                    $pll_info['translations'] = pll_get_post_translations($post_id);
                }
            }
        } else {
            $pll_info = ['error' => 'unknown language ' . $lang];
        }
    }

    apl_write_post_seo($post_id, $p);

    return [
        'post_id'  => $post_id,
        'id'       => $post_id,
        'post_url' => get_permalink($post_id),
        'link'     => get_permalink($post_id),
        'status'   => get_post_status($post_id),
        'polylang' => $pll_info,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// /delete — delete a post
// ─────────────────────────────────────────────────────────────────────────────

function apl_route_delete_post(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $post_id = isset($p['post_id']) ? (int) $p['post_id'] : 0;
    if (!$post_id) return new WP_Error('missing_post_id', 'post_id is required', ['status' => 400]);
    if (!get_post($post_id) || get_post_type($post_id) !== 'post') return new WP_Error('post_not_found', "Post {$post_id} not found", ['status' => 404]);

    $force = !isset($p['force']) || (bool) $p['force'];
    $deleted = wp_delete_post($post_id, $force);
    if (!$deleted) return new WP_Error('delete_failed', 'wp_delete_post failed', ['status' => 500]);
    return ['deleted' => true, 'post_id' => $post_id];
}

// ─────────────────────────────────────────────────────────────────────────────
// /media/sideload — import image by URL
// ─────────────────────────────────────────────────────────────────────────────

function apl_require_media_includes() {
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
}

/** Build a filesystem-safe filename with a real image extension. */
function apl_sideload_filename($url, $tmp_file) {
    $name = 'image';
    $path = parse_url($url, PHP_URL_PATH);
    if ($path) {
        $base = basename($path);
        $base = preg_replace('/[^A-Za-z0-9._-]/', '', urldecode($base));
        if ($base !== '' && $base !== '.') $name = $base;
    }
    if (!preg_match('/\.(jpe?g|png|gif|webp|avif)$/i', $name)) {
        $ext = 'jpg';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmp_file);
            finfo_close($finfo);
            if (strpos((string) $mime, 'png') !== false)  $ext = 'png';
            if (strpos((string) $mime, 'webp') !== false) $ext = 'webp';
            if (strpos((string) $mime, 'gif') !== false)  $ext = 'gif';
            if (strpos((string) $mime, 'avif') !== false) $ext = 'avif';
        }
        $name = rtrim($name, '.') . '.' . $ext;
    }
    return $name;
}

function apl_media_response($attachment_id) {
    $url = wp_get_attachment_url($attachment_id);
    return [
        'id'         => (int) $attachment_id,
        'media_id'   => (int) $attachment_id,
        'source_url' => $url,
        'url'        => $url,
    ];
}

function apl_route_media_sideload(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $url = isset($p['url']) ? esc_url_raw((string) $p['url']) : '';
    if (!$url) return new WP_Error('missing_url', 'url is required', ['status' => 400]);
    $alt     = isset($p['alt']) ? sanitize_text_field((string) $p['alt']) : '';
    $post_id = isset($p['post_id']) ? (int) $p['post_id'] : 0;

    apl_require_media_includes();

    if (!apl_url_is_public($url)) {
        return new WP_Error('invalid_url', 'url must be a public http(s) address', ['status' => 400]);
    }

    $tmp = download_url($url, 60);
    if (is_wp_error($tmp)) {
        // Error code "download_failed" is load-bearing: the backend matches on
        // it to trigger a proxy retry. Keep it.
        return new WP_Error('download_failed', 'Failed to download image', ['status' => 500]);
    }

    $file_array = ['name' => apl_sideload_filename($url, $tmp), 'tmp_name' => $tmp];
    $attachment_id = media_handle_sideload($file_array, $post_id ?: 0);
    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        return new WP_Error('sideload_failed', 'media_handle_sideload failed (unsupported or invalid image)', ['status' => 500]);
    }
    if ($alt !== '') {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    }
    return apl_media_response($attachment_id);
}

// ─────────────────────────────────────────────────────────────────────────────
// /media/upload-binary — multipart upload (hotlink-protected sources)
// ─────────────────────────────────────────────────────────────────────────────

function apl_route_media_upload_binary(WP_REST_Request $request) {
    $files = $request->get_file_params();
    if (empty($files['file'])) {
        return new WP_Error('no_file', 'No file uploaded (expected field name "file")', ['status' => 400]);
    }
    apl_require_media_includes();
    $_FILES['file'] = $files['file'];

    $alt     = sanitize_text_field((string) $request->get_param('alt'));
    $post_id = (int) $request->get_param('post_id');

    $attachment_id = media_handle_upload('file', $post_id);
    if (is_wp_error($attachment_id)) {
        return new WP_Error('upload_failed', 'media_handle_upload failed (unsupported or invalid image)', ['status' => 500]);
    }
    if ($alt !== '') {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    }
    return apl_media_response($attachment_id);
}

// ─────────────────────────────────────────────────────────────────────────────
// /media/stats /media/list /media/delete /media/delete-all
// ─────────────────────────────────────────────────────────────────────────────

function apl_route_media_stats() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT post_mime_type AS mime, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'attachment' GROUP BY post_mime_type"
    );
    $total = 0;
    $by_type = [];
    foreach ($rows as $r) {
        $total += (int) $r->n;
        $by_type[$r->mime] = (int) $r->n;
    }
    // Sum file sizes from attachment metadata (cheap; avoids stat-ing files).
    $size_rows = $wpdb->get_col(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'"
    );
    $total_bytes = 0;
    foreach ($size_rows as $serialized) {
        $meta = maybe_unserialize($serialized);
        if (is_array($meta) && isset($meta['filesize'])) $total_bytes += (int) $meta['filesize'];
    }
    return [
        'total'         => $total,
        'by_type'       => $by_type,
        'total_size_mb' => round($total_bytes / 1048576, 2),
    ];
}

function apl_route_media_list(WP_REST_Request $request) {
    $page      = max(1, (int) ($request->get_param('page') ?: 1));
    $per_page  = min(200, max(1, (int) ($request->get_param('per_page') ?: 50)));
    $search    = (string) ($request->get_param('search') ?: '');
    $mime_type = (string) ($request->get_param('mime_type') ?: '');

    $args = [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    if ($search !== '')    $args['s'] = $search;
    if ($mime_type !== '') $args['post_mime_type'] = $mime_type;

    $query = new WP_Query($args);
    $items = [];
    foreach ($query->posts as $att) {
        $items[] = [
            'id'         => $att->ID,
            'media_id'   => $att->ID,
            'title'      => $att->post_title,
            'url'        => wp_get_attachment_url($att->ID),
            'source_url' => wp_get_attachment_url($att->ID),
            'thumbnail'  => wp_get_attachment_image_url($att->ID, 'thumbnail'),
            'mime_type'  => $att->post_mime_type,
            'date'       => $att->post_date,
            'alt'        => (string) get_post_meta($att->ID, '_wp_attachment_image_alt', true),
        ];
    }
    return [
        'items'    => $items,
        'total'    => (int) $query->found_posts,
        'page'     => $page,
        'per_page' => $per_page,
    ];
}

function apl_route_media_delete(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $ids = isset($p['ids']) && is_array($p['ids']) ? array_map('intval', $p['ids']) : [];
    if (!$ids) return new WP_Error('missing_ids', 'ids array is required', ['status' => 400]);
    $force = !isset($p['force']) || (bool) $p['force'];

    $deleted = [];
    $failed = [];
    foreach ($ids as $id) {
        $ok = wp_delete_attachment($id, $force);
        if ($ok) $deleted[] = $id; else $failed[] = $id;
    }
    return ['deleted' => $deleted, 'failed' => $failed, 'deleted_count' => count($deleted)];
}

// /media/rebind — fix attachment post_parent linkage (does NOT delete anything)
// Body: { items: [ { id: <attachment_id>, post_id: <target_post_id> }, ... ] }
function apl_route_media_rebind(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $items = isset($p['items']) && is_array($p['items']) ? $p['items'] : [];
    if (!$items) return new WP_Error('missing_items', 'items array is required', ['status' => 400]);

    $updated = [];
    $failed = [];
    foreach ($items as $item) {
        $media_id = isset($item['id']) ? (int) $item['id'] : 0;
        $post_id  = isset($item['post_id']) ? (int) $item['post_id'] : 0;
        if (!$media_id || !$post_id) {
            $failed[] = ['id' => $media_id, 'post_id' => $post_id, 'reason' => 'invalid_id'];
            continue;
        }
        $attachment = get_post($media_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            $failed[] = ['id' => $media_id, 'post_id' => $post_id, 'reason' => 'not_an_attachment'];
            continue;
        }
        if (!get_post($post_id)) {
            $failed[] = ['id' => $media_id, 'post_id' => $post_id, 'reason' => 'target_post_not_found'];
            continue;
        }
        $result = wp_update_post(['ID' => $media_id, 'post_parent' => $post_id], true);
        if (is_wp_error($result)) {
            $failed[] = ['id' => $media_id, 'post_id' => $post_id, 'reason' => $result->get_error_message()];
        } else {
            $updated[] = $media_id;
        }
    }
    return ['updated' => $updated, 'failed' => $failed, 'updated_count' => count($updated), 'failed_count' => count($failed)];
}

// /media/cleanup-nextgen — remove Imagify-generated shadow WebP/AVIF duplicates
// (files named e.g. "photo.jpg.webp" / "photo.png.avif" sitting alongside their
// original in wp-content/uploads). These are NOT WP Media Library attachments —
// Imagify writes them straight to disk with a double extension so the browser
// can fall back to the original — so a genuine standalone upload like
// "photo.webp" (single extension) is never touched by this route.
// Body: { confirm?: bool } — without confirm:true, runs a dry-run and only
// reports what WOULD be deleted (count + total bytes), deletes nothing.
// Shared scan used by cleanup-nextgen and package-nextgen. Returns a list
// sorted by path (stable ordering across calls, required for offset/limit
// paging in package-nextgen to work correctly).
function apl_scan_nextgen_shadow_files($base) {
    $pattern = '/\.(jpe?g|png|gif)\.(webp|avif)$/i';
    $matches = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $name = $file->getFilename();
        if (!preg_match($pattern, $name)) continue;
        $matches[] = ['path' => str_replace($base, '', $file->getPathname()), 'size' => $file->getSize()];
    }
    usort($matches, function ($a, $b) { return strcmp($a['path'], $b['path']); });
    return $matches;
}

// /media/package-nextgen — zip up a slice of the shadow webp/avif files
// (folder structure preserved inside the zip) so they can be downloaded as a
// local backup BEFORE cleanup-nextgen deletes them. Paged via offset/limit
// since 78k+ files in one PHP request would hit memory/time limits.
// Body: { offset?: number, limit?: number (max 3000, default 2000) }
function apl_route_media_package_nextgen(WP_REST_Request $request) {
    if (!class_exists('ZipArchive')) {
        return new WP_Error('no_ziparchive', 'PHP ZipArchive extension not available', ['status' => 500]);
    }
    $p = $request->get_json_params();
    $offset = isset($p['offset']) ? max(0, (int) $p['offset']) : 0;
    $limit = isset($p['limit']) ? min(3000, max(1, (int) $p['limit'])) : 2000;

    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    if (!$base || !is_dir($base)) {
        return new WP_Error('no_upload_dir', 'Could not resolve uploads base dir', ['status' => 500]);
    }

    $matches = apl_scan_nextgen_shadow_files($base);
    $total = count($matches);
    $slice = array_slice($matches, $offset, $limit);

    if (empty($slice)) {
        return ['done' => true, 'total' => $total, 'offset' => $offset];
    }

    $zip_dir = $base . '/_nextgen_backup';
    apl_protect_dir($zip_dir);
    $zip_name = 'batch_' . $offset . '_' . $limit . '_' . wp_generate_password(16, false) . '.zip';
    $zip_path = $zip_dir . '/' . $zip_name;

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return new WP_Error('zip_failed', 'Could not create zip file', ['status' => 500]);
    }
    $bytes = 0;
    $packed = 0;
    foreach ($slice as $m) {
        $full = $base . $m['path'];
        if (file_exists($full)) {
            // preserve the folder path inside the zip, e.g. "2026/07/xxx.jpg.webp"
            $zip->addFile($full, ltrim($m['path'], '/'));
            $bytes += $m['size'];
            $packed++;
        }
    }
    $zip->close();

    return [
        'done' => false,
        'total' => $total,
        'offset' => $offset,
        'next_offset' => $offset + $limit,
        'packed_count' => $packed,
        'packed_bytes' => $bytes,
        'url' => $upload_dir['baseurl'] . '/_nextgen_backup/' . $zip_name,
    ];
}

// /media/package-nextgen-cleanup — remove the temp _nextgen_backup zip folder
// from the server once all batches have been downloaded locally.
function apl_route_media_package_nextgen_cleanup() {
    $upload_dir = wp_upload_dir();
    $zip_dir = $upload_dir['basedir'] . '/_nextgen_backup';
    if (!is_dir($zip_dir)) return ['removed' => false, 'reason' => 'not_found'];
    $files = glob($zip_dir . '/*.zip');
    $removed = 0;
    foreach ($files as $f) {
        if (@unlink($f)) $removed++;
    }
    @rmdir($zip_dir);
    return ['removed' => true, 'files_removed' => $removed];
}

// Sizes disabled by the intermediate_image_sizes_advanced/intermediate_image_sizes
// filters above. Uses each attachment's OWN recorded _wp_attachment_metadata to
// find the exact derivative files WordPress generated for these keys — not a
// filename pattern guess — so a naturally-named upload (e.g. a stock photo
// already named "...-1280x960.jpg" before it ever reached WP) can never be
// mistaken for a generated derivative.
$GLOBALS['apl_unused_size_keys'] = ['thumbnail', 'medium', 'medium_large', 'large', 'mobile-hero-webp'];

// Returns a slice of attachment IDs (post_type=attachment, image mimes) ordered
// by ID, for paged processing — mirrors the offset/limit pattern used elsewhere
// in this file since scanning all attachments in one request can hit PHP limits.
function apl_get_image_attachment_ids($offset, $limit) {
    return get_posts([
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'post_status' => 'inherit',
        'orderby' => 'ID',
        'order' => 'ASC',
        'posts_per_page' => $limit,
        'offset' => $offset,
        'fields' => 'ids',
    ]);
}

// For one attachment, returns [{size_key, path (relative to uploads base), full_path, bytes}, ...]
// for whichever of the disabled size keys it actually has a recorded file for.
function apl_find_unused_size_files_for_attachment($attachment_id, $base) {
    $meta = wp_get_attachment_metadata($attachment_id);
    if (!$meta || empty($meta['file']) || empty($meta['sizes']) || !is_array($meta['sizes'])) return [];
    $dir = trailingslashit(dirname($meta['file']));
    $out = [];
    foreach ($GLOBALS['apl_unused_size_keys'] as $key) {
        if (empty($meta['sizes'][$key]['file'])) continue;
        $rel = $dir . $meta['sizes'][$key]['file'];
        $full = $base . '/' . $rel;
        if (file_exists($full)) {
            $out[] = ['size_key' => $key, 'path' => '/' . $rel, 'full_path' => $full, 'bytes' => filesize($full)];
        }
    }
    return $out;
}

// /media/cleanup-unused-sizes — delete the thumbnail/medium/medium_large/large/
// mobile-hero-webp derivative files for a page of attachments, and strip those
// entries from each attachment's _wp_attachment_metadata so WP/REST stop
// reporting sizes that no longer exist on disk.
// Body: { offset?: number, limit?: number (default 500, max 1000), confirm?: bool }
function apl_route_media_cleanup_unused_sizes(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $offset = isset($p['offset']) ? max(0, (int) $p['offset']) : 0;
    $limit = isset($p['limit']) ? min(1000, max(1, (int) $p['limit'])) : 500;
    $confirm = isset($p['confirm']) && $p['confirm'] === true;

    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    if (!$base || !is_dir($base)) {
        return new WP_Error('no_upload_dir', 'Could not resolve uploads base dir', ['status' => 500]);
    }

    $ids = apl_get_image_attachment_ids($offset, $limit);
    if (empty($ids)) {
        return ['done' => true, 'offset' => $offset];
    }

    $found_count = 0;
    $found_bytes = 0;
    $deleted_count = 0;
    $failed = [];
    $sample = [];

    foreach ($ids as $attachment_id) {
        $files = apl_find_unused_size_files_for_attachment($attachment_id, $base);
        if (empty($files)) continue;

        $meta = wp_get_attachment_metadata($attachment_id);
        $changed = false;
        foreach ($files as $f) {
            $found_count++;
            $found_bytes += $f['bytes'];
            if (count($sample) < 20) $sample[] = $f['path'];
            if ($confirm) {
                if (@unlink($f['full_path'])) {
                    $deleted_count++;
                    unset($meta['sizes'][$f['size_key']]);
                    $changed = true;
                } else {
                    $failed[] = $f['path'];
                }
            }
        }
        if ($confirm && $changed) {
            wp_update_attachment_metadata($attachment_id, $meta);
        }
    }

    return [
        'dry_run' => !$confirm,
        'offset' => $offset,
        'next_offset' => $offset + $limit,
        'attachments_scanned' => count($ids),
        'found_count' => $found_count,
        'found_bytes' => $found_bytes,
        'deleted_count' => $deleted_count,
        'failed_count' => count($failed),
        'failed' => $failed,
        'sample' => $sample,
    ];
}

// /media/package-unused-sizes — zip up the disabled-size derivative files for a
// page of attachments (folder structure preserved), for a local backup before
// cleanup-unused-sizes deletes them. Same offset/limit paging as above — MUST
// use the same offset/limit values as the cleanup call for the pages to match.
function apl_route_media_package_unused_sizes(WP_REST_Request $request) {
    if (!class_exists('ZipArchive')) {
        return new WP_Error('no_ziparchive', 'PHP ZipArchive extension not available', ['status' => 500]);
    }
    $p = $request->get_json_params();
    $offset = isset($p['offset']) ? max(0, (int) $p['offset']) : 0;
    $limit = isset($p['limit']) ? min(1000, max(1, (int) $p['limit'])) : 500;

    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    if (!$base || !is_dir($base)) {
        return new WP_Error('no_upload_dir', 'Could not resolve uploads base dir', ['status' => 500]);
    }

    $ids = apl_get_image_attachment_ids($offset, $limit);
    if (empty($ids)) {
        return ['done' => true, 'offset' => $offset];
    }

    $zip_dir = $base . '/_unused_sizes_backup';
    apl_protect_dir($zip_dir);
    $zip_name = 'batch_' . $offset . '_' . $limit . '_' . wp_generate_password(16, false) . '.zip';
    $zip_path = $zip_dir . '/' . $zip_name;

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return new WP_Error('zip_failed', 'Could not create zip file', ['status' => 500]);
    }
    $packed = 0;
    $bytes = 0;
    foreach ($ids as $attachment_id) {
        foreach (apl_find_unused_size_files_for_attachment($attachment_id, $base) as $f) {
            $zip->addFile($f['full_path'], ltrim($f['path'], '/'));
            $packed++;
            $bytes += $f['bytes'];
        }
    }
    $zip->close();

    if ($packed === 0) {
        @unlink($zip_path);
        return ['done' => false, 'offset' => $offset, 'next_offset' => $offset + $limit, 'packed_count' => 0, 'packed_bytes' => 0, 'url' => null];
    }

    return [
        'done' => false,
        'offset' => $offset,
        'next_offset' => $offset + $limit,
        'packed_count' => $packed,
        'packed_bytes' => $bytes,
        'url' => $upload_dir['baseurl'] . '/_unused_sizes_backup/' . $zip_name,
    ];
}

// /media/regenerate-large — rebuild ONLY the "large" derivative for a page of
// attachments, from their still-intact "full" original. Fixes the frontend
// listing-card regression: those cards query WPGraphQL sourceUrl(size: LARGE)
// with no fallback, and every attachment that already lost its "large" file
// (deleted during the earlier unused-sizes cleanup, before "large" was
// restored to the keep-list) needs it rebuilt. Skips attachments that already
// have a "large" entry, or whose original is too small to need one (WP's own
// image_resize_dimensions() correctly returns false/no-op for those — matches
// normal WP behavior, not a bug).
// Body: { offset?: number, limit?: number (default 200, max 500) }
function apl_route_media_regenerate_large(WP_REST_Request $request) {
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $p = $request->get_json_params();
    $offset = isset($p['offset']) ? max(0, (int) $p['offset']) : 0;
    $limit = isset($p['limit']) ? min(500, max(1, (int) $p['limit'])) : 200;

    $ids = apl_get_image_attachment_ids($offset, $limit);
    if (empty($ids)) {
        return ['done' => true, 'offset' => $offset];
    }

    $regenerated = [];
    $skipped_already_has = 0;
    $skipped_too_small = 0;
    $failed = [];

    foreach ($ids as $attachment_id) {
        $meta = wp_get_attachment_metadata($attachment_id);
        if (!$meta) { $failed[] = ['id' => $attachment_id, 'reason' => 'no_metadata']; continue; }
        if (!empty($meta['sizes']['large']['file'])) { $skipped_already_has++; continue; }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) { $failed[] = ['id' => $attachment_id, 'reason' => 'file_missing']; continue; }

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) { $failed[] = ['id' => $attachment_id, 'reason' => 'editor_error']; continue; }

        $current_size = $editor->get_size();
        $large_w = 1280; $large_h = 1280;
        if ($current_size['width'] <= $large_w && $current_size['height'] <= $large_h) {
            $skipped_too_small++;
            continue;
        }

        $resized = $editor->multi_resize(['large' => ['width' => $large_w, 'height' => $large_h, 'crop' => false]]);
        if (empty($resized['large'])) { $failed[] = ['id' => $attachment_id, 'reason' => 'resize_failed']; continue; }

        $meta['sizes']['large'] = $resized['large'];
        wp_update_attachment_metadata($attachment_id, $meta);
        $regenerated[] = $attachment_id;
    }

    return [
        'done' => false,
        'offset' => $offset,
        'next_offset' => $offset + $limit,
        'attachments_scanned' => count($ids),
        'regenerated_count' => count($regenerated),
        'skipped_already_has' => $skipped_already_has,
        'skipped_too_small' => $skipped_too_small,
        'failed_count' => count($failed),
        'failed' => $failed,
    ];
}

// /media/compress-originals — for a page of attachments, convert PNG "full"
// originals to JPEG and/or resize anything wider than WP_IMAGE_MAX_WIDTH,
// in place on disk, for any original that is PNG or exceeds 200KB. Mirrors
// a client-defined threshold (compressImageBuffer in
// server/helpers/compressImage.ts) so existing media matches what all new
// uploads now get automatically.
// If the extension changes (png -> jpg), the old file is removed and the
// attachment's _wp_attached_file / post_mime_type / guid are updated so
// nothing else on the site keeps pointing at a now-deleted path. Metadata is
// regenerated afterward via wp_generate_attachment_metadata() so any size
// still enabled by the intermediate_image_sizes filters above gets rebuilt
// against the new file (thumbnail/medium/medium_large/large/mobile-hero-webp
// are all currently disabled, so this mainly just refreshes "full"'s recorded
// dimensions/filesize).
// Body: { offset?: number, limit?: number (default 50, max 200), confirm?: bool }
// Small default limit/max — this is real CPU-bound image processing per file,
// not a cheap filesystem op like the other routes.
function apl_route_media_compress_originals(WP_REST_Request $request) {
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $p = $request->get_json_params();
    $offset = isset($p['offset']) ? max(0, (int) $p['offset']) : 0;
    $limit = isset($p['limit']) ? min(200, max(1, (int) $p['limit'])) : 50;
    $confirm = isset($p['confirm']) && $p['confirm'] === true;
    $max_width = 1280;
    $quality = 80;
    $min_quality = 40;
    $threshold = 200 * 1024;

    $ids = apl_get_image_attachment_ids($offset, $limit);
    if (empty($ids)) {
        return ['done' => true, 'offset' => $offset];
    }

    $results = [];

    foreach ($ids as $attachment_id) {
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            $results[] = ['id' => $attachment_id, 'status' => 'skipped', 'reason' => 'file_missing'];
            continue;
        }
        $mime = get_post_mime_type($attachment_id);
        $before_bytes = filesize($file);
        // PNG and WebP always get converted to JPEG regardless of size — Kinsta's
        // CDN Image Optimization generates WebP on the fly at the edge for free,
        // so there's no reason to keep a native WebP original taking up disk.
        $is_png = ($mime === 'image/png' || $mime === 'image/webp');
        if (!$is_png && $before_bytes <= $threshold) {
            $results[] = ['id' => $attachment_id, 'status' => 'skipped', 'reason' => 'already_small_non_png'];
            continue;
        }

        if (!$confirm) {
            $results[] = ['id' => $attachment_id, 'status' => 'would_compress', 'before_bytes' => $before_bytes, 'mime' => $mime];
            continue;
        }

        // ALWAYS save to a staging path first, even when the extension isn't
        // changing (jpg -> jpg) — save() overwrites in place when the target
        // equals the source, which would destroy the original before we can
        // compare sizes. Staging lets us discard a re-compress that came out
        // bigger (found in testing: quality-80 re-encoding an already-decent
        // JPEG can grow it) without ever touching the original until we know
        // the new version is actually smaller.
        $pathinfo = pathinfo($file);
        $staging_file = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '-staging' . $attachment_id . '.jpg';

        // Step quality down until the result is under the target size or the
        // quality floor is hit — a single fixed quality often isn't enough
        // for busy/detailed photos to actually land under $threshold.
        $saved = null;
        $staged_bytes = 0;
        for ($q = $quality; $q >= $min_quality; $q -= 15) {
            $editor = wp_get_image_editor($file);
            if (is_wp_error($editor)) {
                $results[] = ['id' => $attachment_id, 'status' => 'failed', 'reason' => 'editor_error'];
                continue 2;
            }
            $size = $editor->get_size();
            if ($size['width'] > $max_width) {
                $editor->resize($max_width, null, false);
            }
            $editor->set_quality($q);
            $attempt = $editor->save($staging_file, 'image/jpeg');
            if (is_wp_error($attempt)) {
                $results[] = ['id' => $attachment_id, 'status' => 'failed', 'reason' => $attempt->get_error_message()];
                continue 2;
            }
            $saved = $attempt;
            $staged_bytes = file_exists($saved['path']) ? filesize($saved['path']) : 0;
            if ($staged_bytes <= $threshold) break;
        }
        $would_change_ext = (strtolower($pathinfo['extension']) !== 'jpg' && strtolower($pathinfo['extension']) !== 'jpeg');

        // PNGs always adopt the JPEG conversion (format normalization is the
        // point, not just size) even on the rare case it isn't smaller.
        // Same-format re-compressions only adopt it if it actually shrank.
        if (!$would_change_ext && $staged_bytes >= $before_bytes) {
            @unlink($saved['path']);
            $results[] = ['id' => $attachment_id, 'status' => 'skipped', 'reason' => 'recompress_not_smaller', 'before_bytes' => $before_bytes, 'staged_bytes' => $staged_bytes];
            continue;
        }

        $target_file = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '.jpg';
        if ($target_file !== $file && file_exists($target_file)) {
            $target_file = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '-c' . $attachment_id . '.jpg';
        }
        @rename($saved['path'], $target_file);
        $final_path = $target_file;

        $ext_changed = ($final_path !== $file);
        if ($ext_changed) {
            @unlink($file);
            update_attached_file($attachment_id, $final_path);
            $new_guid = str_replace($pathinfo['basename'], basename($final_path), get_the_guid($attachment_id));
            wp_update_post(['ID' => $attachment_id, 'post_mime_type' => 'image/jpeg', 'guid' => $new_guid]);
        }

        $new_metadata = wp_generate_attachment_metadata($attachment_id, $final_path);
        wp_update_attachment_metadata($attachment_id, $new_metadata);

        $after_bytes = file_exists($final_path) ? filesize($final_path) : 0;
        $results[] = [
            'id' => $attachment_id,
            'status' => 'compressed',
            'before_bytes' => $before_bytes,
            'after_bytes' => $after_bytes,
            'ext_changed' => $ext_changed,
            'new_url' => wp_get_attachment_url($attachment_id),
        ];
    }

    return [
        'done' => false,
        'offset' => $offset,
        'next_offset' => $offset + $limit,
        'attachments_scanned' => count($ids),
        'results' => $results,
    ];
}

// /media/disk-usage — REAL total disk usage under wp-content/uploads, via a
// recursive filesystem walk summing every file's actual filesize(). Unlike
// /media/stats (which only sums each attachment's top-level "full" filesize
// from _wp_attachment_metadata and has NEVER included derivative-size files
// or anything not tracked in that metadata), this reflects what's actually
// on disk — the number that matters for the host's storage quota.
function apl_route_media_disk_usage() {
    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    if (!$base || !is_dir($base)) {
        return new WP_Error('no_upload_dir', 'Could not resolve uploads base dir', ['status' => 500]);
    }

    $total_bytes = 0;
    $file_count = 0;
    $by_ext = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $size = $file->getSize();
        $total_bytes += $size;
        $file_count++;
        $ext = strtolower($file->getExtension());
        $by_ext[$ext] = ($by_ext[$ext] ?? 0) + $size;
    }

    arsort($by_ext);
    $by_ext_mb = [];
    foreach ($by_ext as $ext => $bytes) {
        $by_ext_mb[$ext] = round($bytes / 1048576, 2);
    }

    return [
        'total_bytes' => $total_bytes,
        'total_mb' => round($total_bytes / 1048576, 2),
        'total_gb' => round($total_bytes / 1073741824, 3),
        'file_count' => $file_count,
        'by_extension_mb' => $by_ext_mb,
    ];
}

// /media/wp-content-usage — full breakdown of wp-content/ disk usage, one
// level deep (uploads/, cache/, backups/, plugins/, themes/, etc.), and if
// wp-content/uploads/sites/ exists (Multisite), a breakdown PER SITE — since
// /media/disk-usage only sees the current site's own uploads dir
// (wp_upload_dir() is site-scoped on Multisite), a large gap between Kinsta's
// reported total and that number likely means either other sites' uploads,
// or a non-uploads directory (backups/cache), account for the difference.
function apl_route_media_wp_content_usage() {
    if (!defined('WP_CONTENT_DIR') || !is_dir(WP_CONTENT_DIR)) {
        return new WP_Error('no_wp_content', 'WP_CONTENT_DIR not resolvable', ['status' => 500]);
    }

    $dir_size = function ($path) {
        if (!is_dir($path)) return 0;
        $total = 0;
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) { if ($f->isFile()) $total += $f->getSize(); }
        } catch (Exception $e) { /* permission-denied subdirs etc — skip */ }
        return $total;
    };

    $top_level = [];
    foreach (scandir(WP_CONTENT_DIR) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = WP_CONTENT_DIR . '/' . $entry;
        if (is_dir($full)) {
            $top_level[$entry] = round($dir_size($full) / 1048576, 1);
        }
    }
    arsort($top_level);

    $per_site = null;
    $sites_dir = WP_CONTENT_DIR . '/uploads/sites';
    if (is_dir($sites_dir)) {
        $per_site = [];
        foreach (scandir($sites_dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $sites_dir . '/' . $entry;
            if (is_dir($full)) {
                $per_site[$entry] = round($dir_size($full) / 1048576, 1);
            }
        }
        arsort($per_site);
    }

    return [
        'wp_content_top_level_mb' => $top_level,
        'wp_content_total_mb' => round(array_sum($top_level), 1),
        'uploads_per_site_mb' => $per_site,
        'is_multisite' => is_multisite(),
        'current_blog_id' => get_current_blog_id(),
    ];
}

// /media/db-size — actual WordPress MySQL database size (this site's DB
// only, via $wpdb — Multisite installs share one DB across all sites, so
// this reflects the whole network's DB, not just this blog), broken down by
// the largest tables. Kinsta's disk-usage total includes the database, and
// wp-content/uploads/plugins/themes alone came up ~32GB short of Kinsta's
// reported total, so this checks whether the DB itself (post revisions,
// transients, logs, etc.) accounts for the gap.
function apl_route_media_db_size() {
    global $wpdb;
    $db_name = DB_NAME;
    $like = $wpdb->esc_like($wpdb->prefix) . '%';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT table_name AS tbl, ROUND((data_length + index_length) / 1048576, 2) AS size_mb, table_rows AS row_count
         FROM information_schema.tables
         WHERE table_schema = %s AND table_name LIKE %s
         ORDER BY (data_length + index_length) DESC
         LIMIT 30",
        $db_name, $like
    ));
    $total = $wpdb->get_var($wpdb->prepare(
        "SELECT ROUND(SUM(data_length + index_length) / 1048576, 2)
         FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s",
        $db_name, $like
    ));
    return [
        'total_mb' => (float) $total,
        'total_gb' => round(((float) $total) / 1024, 3),
        'largest_tables' => $rows,
    ];
}

// Finds, for a page of attachments, every registered size entry whose key
// matches a known-legacy naming pattern: "_old_" (not produced by WP core,
// left behind by a past theme-switch/regenerate-thumbnails run), or one of a
// handful of prefixes/names identified by cross-referencing this site's live
// content — sizes that appear on only a tiny fraction of attachments (2-43
// out of 1700+) compared to the current theme's own sizes (dima-* appear on
// 700-1200+, clearly still active and NOT touched here): rima-* (an older
// theme's own prefix), twentyfourteen-* (default WP theme from the site's
// 2014 launch year), sow-carousel-* (SiteOrigin Widgets), wp_review_* (a
// long-removed review plugin), and a few one-off theme size names
// (portfolio-thumb, portfolio-large, post-thumb, slider-featured).
function apl_is_legacy_size_key($key) {
    if (strpos($key, '_old_') !== false) return true;
    foreach (['rima-', 'twentyfourteen-', 'sow-carousel', 'wp_review_'] as $prefix) {
        if (strpos($key, $prefix) === 0) return true;
    }
    return in_array($key, ['portfolio-thumb', 'portfolio-large', 'post-thumb', 'slider-featured'], true);
}

function apl_find_old_suffixed_files_for_attachment($attachment_id, $base) {
    $meta = wp_get_attachment_metadata($attachment_id);
    if (!$meta || empty($meta['file']) || empty($meta['sizes']) || !is_array($meta['sizes'])) return [];
    $dir = trailingslashit(dirname($meta['file']));
    $out = [];
    foreach ($meta['sizes'] as $key => $info) {
        if (!apl_is_legacy_size_key($key)) continue;
        if (empty($info['file'])) continue;
        $rel = $dir . $info['file'];
        $full = $base . '/' . $rel;
        if (file_exists($full)) {
            $out[] = ['size_key' => $key, 'path' => '/' . $rel, 'full_path' => $full, 'bytes' => filesize($full)];
        }
    }
    return $out;
}

// /media/package-old-sizes — zip up the "_old_"-suffixed derivative files for
// a page of attachments (folder structure preserved), for a local backup
// before cleanup-old-sizes deletes them.
function apl_route_media_package_old_sizes(WP_REST_Request $request) {
    if (!class_exists('ZipArchive')) {
        return new WP_Error('no_ziparchive', 'PHP ZipArchive extension not available', ['status' => 500]);
    }
    $p = $request->get_json_params();
    $offset = isset($p['offset']) ? max(0, (int) $p['offset']) : 0;
    $limit = isset($p['limit']) ? min(1000, max(1, (int) $p['limit'])) : 500;

    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    if (!$base || !is_dir($base)) {
        return new WP_Error('no_upload_dir', 'Could not resolve uploads base dir', ['status' => 500]);
    }

    $ids = apl_get_image_attachment_ids($offset, $limit);
    if (empty($ids)) return ['done' => true, 'offset' => $offset];

    $zip_dir = $base . '/_old_sizes_backup';
    apl_protect_dir($zip_dir);
    $zip_name = 'batch_' . $offset . '_' . $limit . '_' . wp_generate_password(16, false) . '.zip';
    $zip_path = $zip_dir . '/' . $zip_name;

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return new WP_Error('zip_failed', 'Could not create zip file', ['status' => 500]);
    }
    $packed = 0;
    $bytes = 0;
    foreach ($ids as $attachment_id) {
        foreach (apl_find_old_suffixed_files_for_attachment($attachment_id, $base) as $f) {
            $zip->addFile($f['full_path'], ltrim($f['path'], '/'));
            $packed++;
            $bytes += $f['bytes'];
        }
    }
    $zip->close();

    if ($packed === 0) {
        @unlink($zip_path);
        return ['done' => false, 'offset' => $offset, 'next_offset' => $offset + $limit, 'packed_count' => 0, 'packed_bytes' => 0, 'url' => null];
    }

    return [
        'done' => false,
        'offset' => $offset,
        'next_offset' => $offset + $limit,
        'packed_count' => $packed,
        'packed_bytes' => $bytes,
        'url' => $upload_dir['baseurl'] . '/_old_sizes_backup/' . $zip_name,
    ];
}

// /media/cleanup-old-sizes — delete the "_old_"-suffixed derivative files for
// a page of attachments and strip those entries from each attachment's
// _wp_attachment_metadata. Body: { offset?, limit?, confirm? }
function apl_route_media_cleanup_old_sizes(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $offset = isset($p['offset']) ? max(0, (int) $p['offset']) : 0;
    $limit = isset($p['limit']) ? min(1000, max(1, (int) $p['limit'])) : 500;
    $confirm = isset($p['confirm']) && $p['confirm'] === true;

    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    if (!$base || !is_dir($base)) {
        return new WP_Error('no_upload_dir', 'Could not resolve uploads base dir', ['status' => 500]);
    }

    $ids = apl_get_image_attachment_ids($offset, $limit);
    if (empty($ids)) return ['done' => true, 'offset' => $offset];

    $found_count = 0;
    $found_bytes = 0;
    $deleted_count = 0;
    $failed = [];
    $sample = [];

    foreach ($ids as $attachment_id) {
        $files = apl_find_old_suffixed_files_for_attachment($attachment_id, $base);
        if (empty($files)) continue;
        $meta = wp_get_attachment_metadata($attachment_id);
        $changed = false;
        foreach ($files as $f) {
            $found_count++;
            $found_bytes += $f['bytes'];
            if (count($sample) < 20) $sample[] = $f['path'];
            if ($confirm) {
                if (@unlink($f['full_path'])) {
                    $deleted_count++;
                    unset($meta['sizes'][$f['size_key']]);
                    $changed = true;
                } else {
                    $failed[] = $f['path'];
                }
            }
        }
        if ($confirm && $changed) wp_update_attachment_metadata($attachment_id, $meta);
    }

    return [
        'dry_run' => !$confirm,
        'offset' => $offset,
        'next_offset' => $offset + $limit,
        'attachments_scanned' => count($ids),
        'found_count' => $found_count,
        'found_bytes' => $found_bytes,
        'deleted_count' => $deleted_count,
        'failed_count' => count($failed),
        'failed' => $failed,
        'sample' => $sample,
    ];
}

// Resolves a single {id, size_key} pair to its file, given each attachment's
// current metadata (source of truth — never trust a client-supplied path).
function apl_resolve_explicit_size_file($attachment_id, $size_key, $base) {
    $meta = wp_get_attachment_metadata($attachment_id);
    if (!$meta || empty($meta['file'])) return null;
    if ($size_key === 'full') return null; // never touch the main file via this route
    if (empty($meta['sizes'][$size_key]['file'])) return null;
    $dir = trailingslashit(dirname($meta['file']));
    $rel = $dir . $meta['sizes'][$size_key]['file'];
    $full = $base . '/' . $rel;
    if (!file_exists($full)) return null;
    return ['path' => '/' . $rel, 'full_path' => $full, 'bytes' => filesize($full)];
}

// /media/package-explicit-sizes — zip up a client-supplied list of
// {id, size_key} derivative files (determined externally, e.g. by matching
// each size's URL against the site's actual rendered post/page content) for
// local backup before cleanup-explicit-sizes deletes them.
// Body: { items: [{id, size_key}, ...], offset?, limit? (default 500, max 1000) }
function apl_route_media_package_explicit_sizes(WP_REST_Request $request) {
    if (!class_exists('ZipArchive')) {
        return new WP_Error('no_ziparchive', 'PHP ZipArchive extension not available', ['status' => 500]);
    }
    $p = $request->get_json_params();
    $items = isset($p['items']) && is_array($p['items']) ? $p['items'] : [];
    if (!$items) return new WP_Error('missing_items', 'items array is required', ['status' => 400]);
    $offset = isset($p['offset']) ? max(0, (int) $p['offset']) : 0;
    $limit = isset($p['limit']) ? min(1000, max(1, (int) $p['limit'])) : 500;

    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    if (!$base || !is_dir($base)) {
        return new WP_Error('no_upload_dir', 'Could not resolve uploads base dir', ['status' => 500]);
    }

    $slice = array_slice($items, $offset, $limit);
    if (empty($slice)) return ['done' => true, 'offset' => $offset];

    $zip_dir = $base . '/_explicit_sizes_backup';
    apl_protect_dir($zip_dir);
    $zip_name = 'batch_' . $offset . '_' . $limit . '_' . wp_generate_password(16, false) . '.zip';
    $zip_path = $zip_dir . '/' . $zip_name;

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return new WP_Error('zip_failed', 'Could not create zip file', ['status' => 500]);
    }
    $packed = 0;
    $bytes = 0;
    foreach ($slice as $item) {
        $id = isset($item['id']) ? (int) $item['id'] : 0;
        $key = isset($item['size_key']) ? (string) $item['size_key'] : '';
        if (!$id || !$key) continue;
        $f = apl_resolve_explicit_size_file($id, $key, $base);
        if (!$f) continue;
        $zip->addFile($f['full_path'], ltrim($f['path'], '/'));
        $packed++;
        $bytes += $f['bytes'];
    }
    $zip->close();

    if ($packed === 0) {
        @unlink($zip_path);
        return ['done' => false, 'offset' => $offset, 'next_offset' => $offset + $limit, 'packed_count' => 0, 'packed_bytes' => 0, 'url' => null];
    }

    return [
        'done' => false,
        'offset' => $offset,
        'next_offset' => $offset + $limit,
        'packed_count' => $packed,
        'packed_bytes' => $bytes,
        'url' => $upload_dir['baseurl'] . '/_explicit_sizes_backup/' . $zip_name,
    ];
}

// /media/cleanup-explicit-sizes — delete a client-supplied list of
// {id, size_key} derivative files and strip those entries from each
// attachment's _wp_attachment_metadata. Re-resolves the file from the
// attachment's own current metadata (never trusts a client-supplied path),
// same safety property as every other cleanup route in this file.
// Body: { items: [{id, size_key}, ...], offset?, limit?, confirm? }
function apl_route_media_cleanup_explicit_sizes(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $items = isset($p['items']) && is_array($p['items']) ? $p['items'] : [];
    if (!$items) return new WP_Error('missing_items', 'items array is required', ['status' => 400]);
    $offset = isset($p['offset']) ? max(0, (int) $p['offset']) : 0;
    $limit = isset($p['limit']) ? min(1000, max(1, (int) $p['limit'])) : 500;
    $confirm = isset($p['confirm']) && $p['confirm'] === true;

    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    if (!$base || !is_dir($base)) {
        return new WP_Error('no_upload_dir', 'Could not resolve uploads base dir', ['status' => 500]);
    }

    $slice = array_slice($items, $offset, $limit);
    if (empty($slice)) return ['done' => true, 'offset' => $offset];

    $found_count = 0;
    $found_bytes = 0;
    $deleted_count = 0;
    $failed = [];

    // group by attachment so we only load/save metadata once per attachment per batch
    $by_attachment = [];
    foreach ($slice as $item) {
        $id = isset($item['id']) ? (int) $item['id'] : 0;
        $key = isset($item['size_key']) ? (string) $item['size_key'] : '';
        if ($id && $key) $by_attachment[$id][] = $key;
    }

    foreach ($by_attachment as $attachment_id => $keys) {
        $meta = $confirm ? wp_get_attachment_metadata($attachment_id) : null;
        $changed = false;
        foreach ($keys as $key) {
            $f = apl_resolve_explicit_size_file($attachment_id, $key, $base);
            if (!$f) continue;
            $found_count++;
            $found_bytes += $f['bytes'];
            if ($confirm) {
                if (@unlink($f['full_path'])) {
                    $deleted_count++;
                    unset($meta['sizes'][$key]);
                    $changed = true;
                } else {
                    $failed[] = $f['path'];
                }
            }
        }
        if ($confirm && $changed) wp_update_attachment_metadata($attachment_id, $meta);
    }

    return [
        'dry_run' => !$confirm,
        'offset' => $offset,
        'next_offset' => $offset + $limit,
        'found_count' => $found_count,
        'found_bytes' => $found_bytes,
        'deleted_count' => $deleted_count,
        'failed_count' => count($failed),
        'failed' => $failed,
    ];
}

// /media/cleanup-orphan-webp — find/remove .webp files on disk that are NOT
// the current registered file of any attachment. After converting nearly all
// WebP originals to JPEG, disk-usage still showed ~980MB of .webp files
// despite only 3 WebP attachment records remaining — these are stale
// derivative-size files (e.g. "photo-1024x682.webp") left behind from before
// derivative-size generation was disabled; compress-originals only ever
// touched each attachment's "full" file, never cleaned up its old sub-sizes.
// Cross-references the live set of every attachment's _wp_attached_file
// (the only legitimate reason a file should exist) against every .webp file
// found on disk — same offset/limit paging as the other bulk routes.
// Body: { offset?: number, limit?: number (default 2000), confirm?: bool }
function apl_route_media_cleanup_orphan_webp(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $offset = isset($p['offset']) ? max(0, (int) $p['offset']) : 0;
    $limit = isset($p['limit']) ? min(5000, max(1, (int) $p['limit'])) : 2000;
    $confirm = isset($p['confirm']) && $p['confirm'] === true;

    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    if (!$base || !is_dir($base)) {
        return new WP_Error('no_upload_dir', 'Could not resolve uploads base dir', ['status' => 500]);
    }

    // Build the set of every currently-legitimate attached file path (once
    // per call — cheap relative to the filesystem walk below).
    global $wpdb;
    $attached = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'");
    $legit = array_flip(array_map(fn($f) => $base . '/' . $f, $attached));

    $webp_files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        if (strtolower($file->getExtension()) !== 'webp') continue;
        $full = $file->getPathname();
        if (isset($legit[$full])) continue; // it's a real attachment's own file — keep
        $webp_files[] = ['path' => str_replace($base, '', $full), 'full' => $full, 'size' => $file->getSize()];
    }
    usort($webp_files, fn($a, $b) => strcmp($a['path'], $b['path']));

    $total_found = count($webp_files);
    $slice = array_slice($webp_files, $offset, $limit);

    $deleted = [];
    $failed = [];
    $found_bytes = 0;
    foreach ($slice as $f) $found_bytes += $f['size'];

    if ($confirm) {
        foreach ($slice as $f) {
            if (@unlink($f['full'])) $deleted[] = $f['path'];
            else $failed[] = $f['path'];
        }
    }

    return [
        'dry_run' => !$confirm,
        'total_orphan_webp_found' => $total_found,
        'offset' => $offset,
        'next_offset' => $offset + $limit,
        'page_count' => count($slice),
        'page_bytes' => $found_bytes,
        'deleted_count' => count($deleted),
        'failed_count' => count($failed),
        'failed' => $failed,
        'sample' => array_slice($slice, 0, 20),
    ];
}

// /media/list-updraft-move — inspect wp-content/updraft_move (UpdraftPlus's
// internal staging dir used during backup restore/migration) before deciding
// whether it's safe to remove — lists top-level entries with sizes/mtimes so
// nothing is deleted blind.
function apl_route_media_list_updraft_move() {
    $dir = WP_CONTENT_DIR . '/updraft_move';
    if (!is_dir($dir)) return ['exists' => false];

    $dir_size = function ($path) {
        $total = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { if ($f->isFile()) $total += $f->getSize(); }
        return $total;
    };

    $entries = [];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dir . '/' . $entry;
        $entries[] = [
            'name' => $entry,
            'is_dir' => is_dir($full),
            'size_mb' => round((is_dir($full) ? $dir_size($full) : filesize($full)) / 1048576, 2),
            'mtime' => date('Y-m-d H:i:s', filemtime($full)),
        ];
    }
    usort($entries, fn($a, $b) => $b['size_mb'] <=> $a['size_mb']);

    return ['exists' => true, 'entry_count' => count($entries), 'total_mb' => round(array_sum(array_column($entries, 'size_mb')), 2), 'entries' => array_slice($entries, 0, 50)];
}

// /media/delete-updraft-move — remove wp-content/updraft_move entirely.
// Body: { confirm: "DELETE_UPDRAFT_MOVE" }
function apl_route_media_delete_updraft_move(WP_REST_Request $request) {
    $p = $request->get_json_params();
    if (!isset($p['confirm']) || $p['confirm'] !== 'DELETE_UPDRAFT_MOVE') {
        return new WP_Error('confirmation_required', 'Pass confirm: "DELETE_UPDRAFT_MOVE" to proceed', ['status' => 400]);
    }
    $dir = WP_CONTENT_DIR . '/updraft_move';
    if (!is_dir($dir)) return ['removed' => false, 'reason' => 'not_found'];

    $rrmdir = function ($path) use (&$rrmdir) {
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $path . '/' . $entry;
            if (is_dir($full)) { $rrmdir($full); } else { @unlink($full); }
        }
        @rmdir($path);
    };
    $rrmdir($dir);

    return ['removed' => true, 'still_exists' => is_dir($dir)];
}

// /media/restore-file — write a file's bytes back to an exact relative path
// under the uploads dir. Recovery tool: an earlier blanket filesystem-pattern
// cleanup (removing Imagify's "photo.jpg.webp" shadow duplicates) deleted a
// small number of files that turned out to ALSO be a real attachment's own
// registered file (not a Imagify-generated duplicate) — this restores those
// from a local backup without needing to touch WP's postmeta at all, since
// the attachment record already points at this exact path.
// Body: { relative_path: string (e.g. "2026/07/photo.jpg.webp"), content_base64: string }
function apl_route_media_restore_file(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $rel = isset($p['relative_path']) ? (string) $p['relative_path'] : '';
    $content_b64 = isset($p['content_base64']) ? (string) $p['content_base64'] : '';
    if (!$rel || !$content_b64) {
        return new WP_Error('missing_params', 'relative_path and content_base64 are required', ['status' => 400]);
    }
    // Guard against path traversal — must resolve to somewhere under uploads basedir.
    if (strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
        return new WP_Error('invalid_path', 'relative_path contains illegal segments', ['status' => 400]);
    }

    // Never write server-side executables or server config into the uploads directory.
    // Allowlist: only image files, and never a name containing an executable extension anywhere.
    $bn = basename($rel);
    if (!preg_match('/\.(jpe?g|png|gif|webp|avif)$/i', $bn) || preg_match('/\.(php|phtml|phar|pht|phps|shtml|cgi|pl|py|sh|asp|aspx|jsp|inc|htaccess|htpasswd|ini|conf)(\.|$)/i', $bn) || strtolower($bn) === 'web.config') {
        return new WP_Error('invalid_path', 'only image files (jpg, png, gif, webp, avif) can be restored', ['status' => 400]);
    }

    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    $target = $base . '/' . ltrim($rel, '/');
    $real_base = realpath($base);
    $target_dir = dirname($target);
    if (!is_dir($target_dir)) wp_mkdir_p($target_dir);
    $real_target_dir = realpath($target_dir);
    if (!$real_target_dir || strpos($real_target_dir, $real_base) !== 0) {
        return new WP_Error('invalid_path', 'resolved path escapes uploads dir', ['status' => 400]);
    }

    $bytes = base64_decode($content_b64, true);
    if ($bytes === false) {
        return new WP_Error('bad_base64', 'content_base64 could not be decoded', ['status' => 400]);
    }
    if (!@getimagesizefromstring($bytes)) {
        return new WP_Error('invalid_content', 'content is not a valid image', ['status' => 400]);
    }
    $written = file_put_contents($target, $bytes);
    if ($written === false) {
        return new WP_Error('write_failed', 'Could not write file', ['status' => 500]);
    }

    return ['restored' => true, 'path' => $rel, 'bytes' => $written];
}

// /media/scan-large-originals — for a page of attachments, stat the actual
// "full" (original) file on disk and flag it if it's PNG or exceeds 200KB.
// Uses filesize() directly rather than the REST API's media_details.filesize
// field, which isn't reliably populated for every attachment.
// Body: { offset?: number, limit?: number (default 500, max 1000) }
function apl_route_media_scan_large_originals(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $offset = isset($p['offset']) ? max(0, (int) $p['offset']) : 0;
    $limit = isset($p['limit']) ? min(1000, max(1, (int) $p['limit'])) : 500;
    $threshold = 200 * 1024;

    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    if (!$base || !is_dir($base)) {
        return new WP_Error('no_upload_dir', 'Could not resolve uploads base dir', ['status' => 500]);
    }

    $ids = apl_get_image_attachment_ids($offset, $limit);
    if (empty($ids)) {
        return ['done' => true, 'offset' => $offset];
    }

    $flagged = [];
    foreach ($ids as $attachment_id) {
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) continue;
        $mime = get_post_mime_type($attachment_id);
        $bytes = filesize($file);
        $is_png = ($mime === 'image/png');
        $is_large = $bytes > $threshold;
        if ($is_png || $is_large) {
            $flagged[] = [
                'id' => $attachment_id,
                'mime' => $mime,
                'bytes' => $bytes,
                'is_png' => $is_png,
                'is_large' => $is_large,
                'url' => wp_get_attachment_url($attachment_id),
            ];
        }
    }

    return [
        'done' => false,
        'offset' => $offset,
        'next_offset' => $offset + $limit,
        'attachments_scanned' => count($ids),
        'flagged' => $flagged,
    ];
}

// /media/package-unused-sizes-cleanup — remove the temp _unused_sizes_backup
// zip folder from the server once all batches have been downloaded locally.
function apl_route_media_package_unused_sizes_cleanup() {
    $upload_dir = wp_upload_dir();
    $zip_dir = $upload_dir['basedir'] . '/_unused_sizes_backup';
    if (!is_dir($zip_dir)) return ['removed' => false, 'reason' => 'not_found'];
    $files = glob($zip_dir . '/*.zip');
    $removed = 0;
    foreach ($files as $f) {
        if (@unlink($f)) $removed++;
    }
    @rmdir($zip_dir);
    return ['removed' => true, 'files_removed' => $removed];
}

function apl_route_media_cleanup_nextgen(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $confirm = isset($p['confirm']) && $p['confirm'] === true;

    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'];
    if (!$base || !is_dir($base)) {
        return new WP_Error('no_upload_dir', 'Could not resolve uploads base dir', ['status' => 500]);
    }

    $matches = apl_scan_nextgen_shadow_files($base);
    $total_bytes = 0;
    foreach ($matches as $m) $total_bytes += $m['size'];

    $deleted = [];
    $failed = [];
    if ($confirm) {
        foreach ($matches as $m) {
            $full = $base . $m['path'];
            if (@unlink($full)) $deleted[] = $m['path'];
            else $failed[] = $m['path'];
        }
    }

    return [
        'dry_run' => !$confirm,
        'found_count' => count($matches),
        'found_total_bytes' => $total_bytes,
        'deleted_count' => count($deleted),
        'failed_count' => count($failed),
        'failed' => $failed,
        'sample' => array_slice($matches, 0, 20),
    ];
}

function apl_route_media_delete_all(WP_REST_Request $request) {
    $p = $request->get_json_params();
    if (!isset($p['confirm']) || $p['confirm'] !== 'DELETE_ALL_MEDIA') {
        return new WP_Error('confirmation_required', 'Pass confirm: "DELETE_ALL_MEDIA" to proceed', ['status' => 400]);
    }
    $batch_size = min(200, max(1, (int) ($p['batch_size'] ?? 50)));

    $ids = get_posts([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => $batch_size,
        'fields'         => 'ids',
    ]);
    $deleted_count = 0;
    foreach ($ids as $id) {
        if (wp_delete_attachment($id, true)) $deleted_count++;
    }
    $remaining = (int) (new WP_Query([
        'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids',
    ]))->found_posts;

    return [
        'deleted_count' => $deleted_count,
        'remaining'     => $remaining,
        'done'          => $remaining === 0,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// /categories — list / create / update / delete
// ─────────────────────────────────────────────────────────────────────────────

function apl_category_to_array($term) {
    return [
        'id'          => (int) $term->term_id,
        'name'        => $term->name,
        'slug'        => $term->slug,
        'parent'      => (int) $term->parent,
        'description' => (string) $term->description,
        'count'       => (int) $term->count,
    ];
}

function apl_route_categories_list(WP_REST_Request $request) {
    $page     = max(1, (int) ($request->get_param('page') ?: 1));
    $per_page = min(200, max(1, (int) ($request->get_param('per_page') ?: 100)));
    // hide_empty arrives as the string "false"/"true".
    $hide_empty_param = $request->get_param('hide_empty');
    $hide_empty = ($hide_empty_param === 'true' || $hide_empty_param === '1' || $hide_empty_param === true);

    $terms = get_terms([
        'taxonomy'   => 'category',
        'hide_empty' => $hide_empty,
        'number'     => $per_page,
        'offset'     => ($page - 1) * $per_page,
        'orderby'    => 'id',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($terms)) {
        return new WP_Error('list_failed', $terms->get_error_message(), ['status' => 500]);
    }
    return array_map('apl_category_to_array', $terms);
}

function apl_route_categories_create(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $name = isset($p['name']) ? trim((string) $p['name']) : '';
    if ($name === '') return new WP_Error('missing_name', 'name is required', ['status' => 400]);
    $parent = isset($p['parent']) ? (int) $p['parent'] : 0;

    // Idempotent: same name under same parent returns the existing term.
    $existing = term_exists($name, 'category', $parent ?: null);
    if ($existing && !is_wp_error($existing)) {
        $term_id = (int) (is_array($existing) ? $existing['term_id'] : $existing);
        $updates = [];
        if (!empty($p['slug']))              $updates['slug'] = sanitize_title((string) $p['slug']);
        if (isset($p['description']))        $updates['description'] = (string) $p['description'];
        if ($updates) wp_update_term($term_id, 'category', $updates);
        $term = get_term($term_id, 'category');
        return apl_category_to_array($term);
    }

    $args = ['parent' => $parent];
    if (!empty($p['slug']))       $args['slug'] = sanitize_title((string) $p['slug']);
    if (isset($p['description'])) $args['description'] = (string) $p['description'];

    $created = wp_insert_term($name, 'category', $args);
    if (is_wp_error($created)) {
        return new WP_Error('create_failed', $created->get_error_message(), ['status' => 500]);
    }
    $term = get_term((int) $created['term_id'], 'category');
    return apl_category_to_array($term);
}

function apl_route_categories_update(WP_REST_Request $request) {
    $term_id = (int) $request['id'];
    $term = get_term($term_id, 'category');
    if (!$term || is_wp_error($term)) {
        return new WP_Error('category_not_found', "Category {$term_id} not found", ['status' => 404]);
    }
    $p = $request->get_json_params();
    $updates = [];
    if (isset($p['name']) && $p['name'] !== '')  $updates['name'] = (string) $p['name'];
    if (isset($p['slug']) && $p['slug'] !== '')  $updates['slug'] = sanitize_title((string) $p['slug']);
    if (isset($p['description']))                $updates['description'] = (string) $p['description'];
    if (isset($p['parent']))                     $updates['parent'] = (int) $p['parent'];
    if (!$updates) {
        return apl_category_to_array($term);
    }
    $result = wp_update_term($term_id, 'category', $updates);
    if (is_wp_error($result)) {
        return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
    }
    return apl_category_to_array(get_term($term_id, 'category'));
}

function apl_route_categories_delete(WP_REST_Request $request) {
    $term_id = (int) $request['id'];
    $term = get_term($term_id, 'category');
    if (!$term || is_wp_error($term)) {
        return new WP_Error('category_not_found', "Category {$term_id} not found", ['status' => 404]);
    }
    $result = wp_delete_term($term_id, 'category');
    if (is_wp_error($result) || $result === false) {
        $msg = is_wp_error($result) ? $result->get_error_message() : 'wp_delete_term failed (default category?)';
        return new WP_Error('delete_failed', $msg, ['status' => 500]);
    }
    return ['deleted' => true, 'id' => $term_id];
}

// ─────────────────────────────────────────────────────────────────────────────
// /term-meta — write term meta (category cover images, Yoast term SEO)
// ─────────────────────────────────────────────────────────────────────────────

function apl_route_term_meta(WP_REST_Request $request) {
    $p = $request->get_json_params();
    $term_id = isset($p['term_id']) ? (int) $p['term_id'] : 0;
    $meta = isset($p['meta']) && is_array($p['meta']) ? $p['meta'] : null;
    if (!$term_id || !$meta) {
        return new WP_Error('missing_fields', 'term_id and meta object are required', ['status' => 400]);
    }
    $term = get_term($term_id);
    if ($term && !is_wp_error($term) && !in_array($term->taxonomy, ['category', 'post_tag'], true)) {
        return new WP_Error('taxonomy_not_allowed', 'only category and post_tag terms can be changed', ['status' => 400]);
    }
    if (!$term || is_wp_error($term)) {
        return new WP_Error('term_not_found', "Term {$term_id} not found", ['status' => 404]);
    }
    $written = [];
    foreach ($meta as $key => $value) {
        $key = sanitize_key($key);
        // Allowlist: cover-image keys and Yoast keys. Extend with the genai_mcp_term_meta_keys filter.
        $allowed = apply_filters('genai_mcp_term_meta_keys', ['z_taxonomy_image', 'z_taxonomy_image_id']);
        if (!in_array($key, $allowed, true) && strpos($key, 'wpseo_') !== 0) {
            return new WP_Error('meta_key_not_allowed', "meta key '{$key}' is not allowed", ['status' => 400]);
        }
        update_term_meta($term_id, $key, (string) $value);
        $written[] = $key;
    }
    return ['updated' => true, 'term_id' => $term_id, 'keys' => $written];
}

// ─────────────────────────────────────────────────────────────────────────────
// /authors — list users (users who can edit posts)
// ─────────────────────────────────────────────────────────────────────────────

function apl_route_authors() {
    $users = get_users(['number' => 200, 'orderby' => 'ID', 'order' => 'ASC', 'capability' => ['edit_posts']]);
    $out = [];
    foreach ($users as $u) {
        $out[] = [
            'id'           => (int) $u->ID,
            'name'         => $u->display_name,
            'display_name' => $u->display_name,
            'slug'         => $u->user_nicename,
        ];
    }
    return $out;
}
