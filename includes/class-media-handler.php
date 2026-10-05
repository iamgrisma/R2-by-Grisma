<?php
/**
 * Media Attachment Lifecycle Handler
 * Intercepts uploads, applies compression and format conversion,
 * syncs final optimized files to Cloudflare R2, cleans local files if configured,
 * and handles R2 deletion on attachment trash.
 *
 * @package R2_By_Grisma
 */

if (!defined('ABSPATH')) {
    exit;
}

class R2G_Media_Handler {
    /**
     * Singleton instance
     */
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Disable WordPress 5.3+ big image scaling suffix "-scaled"
        add_filter('big_image_size_threshold', '__return_false');

        // Clean EXIF software junk (like "Intel(R) JPEG Library") from attachment metadata, captions, and titles
        add_filter('wp_read_image_metadata', array($this, 'clean_image_metadata'), 10, 3);
        add_filter('wp_insert_attachment_data', array($this, 'clean_attachment_title'), 10, 2);

        // Core upload interception: convert / compress BEFORE attachment is created and thumbnails cut
        add_filter('wp_handle_upload', array($this, 'on_handle_upload'), 10, 2);

        // Apply compression quality to all WordPress-generated thumbnail cuts
        add_filter('wp_editor_set_quality', array($this, 'filter_editor_quality'), 10, 2);

        // Intercept attachment metadata generation (sync final image and thumbnails to R2)
        add_filter('wp_generate_attachment_metadata', array($this, 'on_generate_metadata'), 20, 2);

        // Delete from R2 when deleted from WordPress Media Library
        add_action('delete_attachment', array($this, 'on_delete_attachment'));
    }

    /**
     * Filter thumbnail generation quality in WordPress image editor
     *
     * @param int $quality
     * @param string $mime_type
     * @return int
     */
    public function filter_editor_quality($quality, $mime_type = '') {
        $compress_enabled = (int) self::get_upload_pref('r2g_compress', get_option('r2g_compress_enabled', 1));
        if (!$compress_enabled) {
            return 100;
        }
        $pref_quality = (int) self::get_upload_pref('r2g_quality', get_option('r2g_compress_quality', 82));
        return max(60, min(100, $pref_quality));
    }

    /**
     * Prevent Intel JPEG Library and EXIF junk from polluting image metadata
     *
     * @param array $meta
     * @param string $file
     * @param int $source_image_type
     * @return array
     */
    public function clean_image_metadata($meta, $file, $source_image_type) {
        $junk_patterns = array('Intel(R) JPEG Library', 'Intel JPEG Library', 'Intel(R)');
        foreach (array('software', 'title', 'caption', 'credit', 'copyright') as $field) {
            if (!empty($meta[$field])) {
                foreach ($junk_patterns as $junk) {
                    if (stripos($meta[$field], $junk) !== false) {
                        $meta[$field] = '';
                        break;
                    }
                }
            }
        }
        return $meta;
    }

    /**
     * Prevent Intel JPEG Library from becoming attachment post title, caption, or description
     *
     * @param array $data
     * @param array $postarr
     * @return array
     */
    public function clean_attachment_title($data, $postarr) {
        $junk_patterns = array('Intel(R) JPEG Library', 'Intel JPEG Library', 'Intel(R)');

        foreach (array('post_title', 'post_excerpt', 'post_content') as $field) {
            if (!empty($data[$field])) {
                foreach ($junk_patterns as $junk) {
                    if (stripos($data[$field], $junk) !== false) {
                        $data[$field] = '';
                        break;
                    }
                }
            }
        }

        if (empty($data['post_title'])) {
            $file = isset($postarr['file']) ? $postarr['file'] : (isset($_FILES['async-upload']['name']) ? $_FILES['async-upload']['name'] : '');
            if (empty($file) && !empty($data['guid'])) {
                $file = basename($data['guid']);
            }
            $clean_name = sanitize_text_field(pathinfo($file, PATHINFO_FILENAME));
            $clean_name = preg_replace('/-scaled$/i', '', $clean_name);
            $clean_name = str_replace(array('-', '_'), ' ', $clean_name);
            $data['post_title'] = !empty($clean_name) ? ucwords($clean_name) : 'Image';
        }

        return $data;
    }

    /**
     * Get R2 Key for a given absolute file path
     * Respects user-configured directory structure (Defaults to standard WordPress wp-content/uploads/ structure)
     *
     * @param string $file_path
     * @return string
     */
    public static function get_r2_key_from_path($file_path) {
        $uploads = wp_upload_dir();
        $basedir = wp_normalize_path($uploads['basedir']);
        $normalized_file = wp_normalize_path($file_path);

        $structure = get_option('r2g_path_structure', 'wp_content');
        $custom_prefix = trim(get_option('r2g_path_prefix', ''), '/');

        // Calculate relative path from basedir (e.g. 2026/10/file.webp)
        $rel = '';
        if (strpos($normalized_file, $basedir) === 0) {
            $rel = ltrim(substr($normalized_file, strlen($basedir)), '/');
        } else {
            $content_dir = wp_normalize_path(WP_CONTENT_DIR);
            if (strpos($normalized_file, $content_dir) === 0) {
                $rel = ltrim(substr($normalized_file, strlen($content_dir)), '/');
                if (strpos($rel, 'uploads/') === 0) {
                    $rel = substr($rel, 8);
                }
            } else {
                $rel = basename($file_path);
            }
        }

        switch ($structure) {
            case 'uploads_only':
                return 'uploads/' . $rel;
            case 'date_only':
                return $rel;
            case 'custom':
                return (!empty($custom_prefix) ? $custom_prefix . '/' : '') . $rel;
            case 'wp_content':
            default:
                return 'wp-content/uploads/' . $rel;
        }
    }

    /**
     * Get available presets for image conversion and compression
     *
     * @return array
     */
    public static function get_presets() {
        return array(
            'webp_balanced' => array(
                'id'          => 'webp_balanced',
                'name'        => __('WebP Balanced (Default Recommended)', 'r2-by-grisma'),
                'description' => __('Convert to WebP, 82% quality, max 1920px. 60-80% smaller than JPG with crisp detail.', 'r2-by-grisma'),
                'format'      => 'webp',
                'compress'    => 1,
                'quality'     => 82,
                'max_width'   => 1920,
            ),
            'webp_high' => array(
                'id'          => 'webp_high',
                'name'        => __('WebP High Quality', 'r2-by-grisma'),
                'description' => __('Convert to WebP, 90% quality, max 2560px. High-DPI and photography fidelity.', 'r2-by-grisma'),
                'format'      => 'webp',
                'compress'    => 1,
                'quality'     => 90,
                'max_width'   => 2560,
            ),
            'jpeg_balanced' => array(
                'id'          => 'jpeg_balanced',
                'name'        => __('JPEG Balanced', 'r2-by-grisma'),
                'description' => __('Convert to JPG, 82% quality, max 1920px. Universal compatibility.', 'r2-by-grisma'),
                'format'      => 'jpg',
                'compress'    => 1,
                'quality'     => 82,
                'max_width'   => 1920,
            ),
            'jpeg_high' => array(
                'id'          => 'jpeg_high',
                'name'        => __('JPEG High Quality', 'r2-by-grisma'),
                'description' => __('Convert to JPG, 90% quality, max 2560px. Studio photography quality.', 'r2-by-grisma'),
                'format'      => 'jpg',
                'compress'    => 1,
                'quality'     => 90,
                'max_width'   => 2560,
            ),
            'original_compressed' => array(
                'id'          => 'original_compressed',
                'name'        => __('Keep Original Format (Optimized)', 'r2-by-grisma'),
                'description' => __('Retain PNG/JPG format, 82% quality, max 1920px.', 'r2-by-grisma'),
                'format'      => 'original',
                'compress'    => 1,
                'quality'     => 82,
                'max_width'   => 1920,
            ),
            'raw_lossless' => array(
                'id'          => 'raw_lossless',
                'name'        => __('Raw Original (No compression / Pure Offload)', 'r2-by-grisma'),
                'description' => __('No conversion, no compression, no resizing. Exact binary offloaded to R2.', 'r2-by-grisma'),
                'format'      => 'original',
                'compress'    => 0,
                'quality'     => 100,
                'max_width'   => 0,
            ),
            'custom' => array(
                'id'          => 'custom',
                'name'        => __('Custom Preset', 'r2-by-grisma'),
                'description' => __('Custom format, quality, and max width.', 'r2-by-grisma'),
                'format'      => get_option('r2g_compress_format', 'webp'),
                'compress'    => (int) get_option('r2g_compress_enabled', 1),
                'quality'     => (int) get_option('r2g_compress_quality', 82),
                'max_width'   => (int) get_option('r2g_max_width', 1920),
            ),
        );
    }

    /**
     * Cache of files already processed during current upload request
     *
     * @var array
     */
    private static $processed_files = array();

    /**
     * Resolve effective upload preferences with strict hierarchy:
     * 1. Explicit POST parameters (e.g. from Plupload / FormData / AJAX)
     * 2. HTTP headers (e.g. from REST API / apiFetch)
     * 3. Preset defaults (e.g. jpeg_high -> format: 'jpg', quality: 90)
     * 4. Session cookie ONLY if preset is explicitly 'custom'
     *
     * This guarantees a user selecting 'JPEG High Quality' or clicking 'JPG'
     * is NEVER overridden by a stale cookie set to 'webp'.
     *
     * @return array
     */
    public static function get_effective_upload_options() {
        $presets = self::get_presets();

        // 1. Format resolution: POST -> HTTP Header -> Cookie -> Preset fallback -> Site Default Option
        $format = '';
        if (!empty($_POST['r2g_format'])) {
            $format = sanitize_text_field(wp_unslash($_POST['r2g_format']));
        } elseif (!empty($_SERVER['HTTP_X_R2G_FORMAT'])) {
            $format = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_R2G_FORMAT']));
        } elseif (!empty($_COOKIE['r2g_format'])) {
            $format = sanitize_text_field(wp_unslash($_COOKIE['r2g_format']));
        } elseif (!empty($_POST['r2g_preset']) && isset($presets[$_POST['r2g_preset']])) {
            $format = $presets[$_POST['r2g_preset']]['format'];
        } else {
            $format = get_option('r2g_compress_format', 'webp');
        }

        $format = strtolower($format);
        if ($format === 'jpeg') {
            $format = 'jpg';
        }

        // 2. Quality resolution: POST -> HTTP Header -> Cookie -> Preset fallback -> Site Default Option
        $quality = 0;
        if (isset($_POST['r2g_quality']) && $_POST['r2g_quality'] !== '') {
            $quality = (int)$_POST['r2g_quality'];
        } elseif (isset($_SERVER['HTTP_X_R2G_QUALITY']) && $_SERVER['HTTP_X_R2G_QUALITY'] !== '') {
            $quality = (int)$_SERVER['HTTP_X_R2G_QUALITY'];
        } elseif (isset($_COOKIE['r2g_quality']) && $_COOKIE['r2g_quality'] !== '') {
            $quality = (int)$_COOKIE['r2g_quality'];
        } elseif (!empty($_POST['r2g_preset']) && isset($presets[$_POST['r2g_preset']])) {
            $quality = (int)$presets[$_POST['r2g_preset']]['quality'];
        } else {
            $quality = (int) get_option('r2g_compress_quality', 82);
        }
        $quality = max(50, min(100, $quality));

        // 3. Compress resolution:
        if (isset($_POST['r2g_compress']) && $_POST['r2g_compress'] !== '') {
            $compress = (int)$_POST['r2g_compress'];
        } elseif (isset($_SERVER['HTTP_X_R2G_COMPRESS']) && $_SERVER['HTTP_X_R2G_COMPRESS'] !== '') {
            $compress = (int)$_SERVER['HTTP_X_R2G_COMPRESS'];
        } elseif (isset($_COOKIE['r2g_compress']) && $_COOKIE['r2g_compress'] !== '') {
            $compress = (int)$_COOKIE['r2g_compress'];
        } else {
            $compress = (int) get_option('r2g_compress_enabled', 1);
        }

        // 4. Max width resolution:
        if (isset($_POST['r2g_max_width']) && $_POST['r2g_max_width'] !== '') {
            $max_width = (int)$_POST['r2g_max_width'];
        } elseif (isset($_SERVER['HTTP_X_R2G_MAX_WIDTH']) && $_SERVER['HTTP_X_R2G_MAX_WIDTH'] !== '') {
            $max_width = (int)$_SERVER['HTTP_X_R2G_MAX_WIDTH'];
        } elseif (isset($_COOKIE['r2g_max_width']) && $_COOKIE['r2g_max_width'] !== '') {
            $max_width = (int)$_COOKIE['r2g_max_width'];
        } else {
            $max_width = (int) get_option('r2g_max_width', 1920);
        }

        // 5. Engine resolution:
        $engine = '';
        if (!empty($_POST['r2g_engine'])) {
            $engine = sanitize_text_field(wp_unslash($_POST['r2g_engine']));
        } elseif (!empty($_SERVER['HTTP_X_R2G_ENGINE'])) {
            $engine = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_R2G_ENGINE']));
        } elseif (!empty($_COOKIE['r2g_engine'])) {
            $engine = sanitize_text_field(wp_unslash($_COOKIE['r2g_engine']));
        } else {
            $engine = get_option('r2g_compress_engine', 'server');
        }
        if (!in_array($engine, array('server', 'resmush', 'browser', 'none'), true)) {
            $engine = 'server';
        }

        // If raw lossless engine selected, disable lossy transforms
        if ($engine === 'none') {
            $compress = 0;
            $format = 'original';
        }

        // 6. Storage Mode resolution:
        $storage_mode = '';
        if (!empty($_POST['r2g_storage_mode'])) {
            $storage_mode = sanitize_text_field(wp_unslash($_POST['r2g_storage_mode']));
        } elseif (!empty($_SERVER['HTTP_X_R2G_STORAGE_MODE'])) {
            $storage_mode = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_R2G_STORAGE_MODE']));
        } elseif (!empty($_COOKIE['r2g_storage_mode'])) {
            $storage_mode = sanitize_text_field(wp_unslash($_COOKIE['r2g_storage_mode']));
        } else {
            $storage_mode = get_option('r2g_storage_mode', 'both');
        }
        if (!in_array($storage_mode, array('both', 'r2_only', 'local_only'), true)) {
            $storage_mode = 'both';
        }

        // Client pre-compressed flag from browser canvas
        $client_compressed = !empty($_POST['r2g_client_compressed']) || !empty($_SERVER['HTTP_X_R2G_CLIENT_COMPRESSED']);

        return array(
            'format'            => $format,
            'quality'           => $quality,
            'compress'          => (bool)$compress,
            'max_width'         => $max_width,
            'storage_mode'      => $storage_mode,
            'engine'            => $engine,
            'client_compressed' => $client_compressed,
        );
    }

    /**
     * Helper to read upload preference with fallback
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get_upload_pref($key, $default = null) {
        if (isset($_POST[$key]) && $_POST[$key] !== '') {
            return sanitize_text_field(wp_unslash($_POST[$key]));
        }
        $header_key = 'HTTP_X_' . strtoupper(str_replace('-', '_', $key));
        if (isset($_SERVER[$header_key]) && $_SERVER[$header_key] !== '') {
            return sanitize_text_field(wp_unslash($_SERVER[$header_key]));
        }
        if (isset($_COOKIE[$key]) && $_COOKIE[$key] !== '') {
            return sanitize_text_field(wp_unslash($_COOKIE[$key]));
        }
        return $default;
    }

    /**
     * Intercept uploaded file immediately after move_uploaded_file succeeds
     * Performs format conversion and compression BEFORE attachment creation and thumbnail cuts.
     *
     * @param array $upload ['file' => ..., 'url' => ..., 'type' => ...]
     * @param string $action 'upload' or 'sideload'
     * @return array
     */
    public function on_handle_upload($upload, $action = 'upload') {
        if (isset($upload['error']) || empty($upload['file'])) {
            return $upload;
        }

        $mime = $upload['type'] ?? '';
        // Only process raster images (JPEG, PNG, WebP) - skip SVG, PDF, video, audio
        if (strpos($mime, 'image/') !== 0 || strpos($mime, 'svg') !== false) {
            return $upload;
        }

        $file_path = $upload['file'];
        if (!file_exists($file_path)) {
            return $upload;
        }

        $opts = self::get_effective_upload_options();

        $opt_res = R2G_Optimizer::optimize_local_file($file_path, array(
            'format'            => $opts['format'],
            'quality'           => $opts['quality'],
            'max_width'         => $opts['max_width'],
            'compress'          => $opts['compress'],
            'engine'            => $opts['engine'],
            'client_compressed' => $opts['client_compressed'],
        ));

        if ($opt_res['success'] && !empty($opt_res['file_path'])) {
            $new_file = $opt_res['file_path'];
            $upload['file'] = $new_file;

            self::$processed_files[$new_file]  = $opts;
            self::$processed_files[$file_path] = $opts;

            if (!empty($opt_res['mime'])) {
                $upload['type'] = $opt_res['mime'];
            }

            if ($new_file !== $file_path) {
                $upload['url'] = dirname($upload['url']) . '/' . basename($new_file);
            }
        }

        return $upload;
    }

    /**
     * Process attachment upon metadata generation
     * Uploads the final processed image and thumbnails to Cloudflare R2
     *
     * @param array $metadata
     * @param int $attachment_id
     * @return array
     */
    public function on_generate_metadata($metadata, $attachment_id) {
        $auto_upload = (int) get_option('r2g_auto_upload', 1);
        $opts = self::get_effective_upload_options();
        $storage_mode = $opts['storage_mode'] ?? get_option('r2g_storage_mode', 'both');

        if (!wp_attachment_is_image($attachment_id)) {
            if ($auto_upload && $storage_mode !== 'local_only') {
                self::sync_attachment_to_r2($attachment_id);
            }
            return $metadata;
        }

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return $metadata;
        }

        // Clean Intel EXIF software metadata junk from post
        $post_obj = get_post($attachment_id);
        if ($post_obj && !empty($post_obj->post_excerpt) && stripos($post_obj->post_excerpt, 'Intel') !== false) {
            wp_update_post(array('ID' => $attachment_id, 'post_excerpt' => ''));
        }
        if (!empty($metadata['image_meta']['caption']) && stripos($metadata['image_meta']['caption'], 'Intel') !== false) {
            $metadata['image_meta']['caption'] = '';
        }

        // Check if master file was already optimized and converted in on_handle_upload
        $already_processed = isset(self::$processed_files[$file_path]);

        if (!$already_processed) {
            // Sideloaded or direct upload that bypassed on_handle_upload
            $opt_res = R2G_Optimizer::optimize_local_file($file_path, array(
                'format'            => $opts['format'],
                'quality'           => $opts['quality'],
                'max_width'         => $opts['max_width'],
                'compress'          => $opts['compress'],
                'engine'            => $opts['engine'],
                'client_compressed' => $opts['client_compressed'],
            ));

            if ($opt_res['success'] && !empty($opt_res['file_path']) && $opt_res['file_path'] !== $file_path) {
                update_attached_file($attachment_id, $opt_res['file_path']);
                $file_path = $opt_res['file_path'];
                $metadata['file'] = _wp_relative_upload_path($file_path);
                $target_mime = !empty($opt_res['mime']) ? $opt_res['mime'] : 'image/' . ($opts['format'] === 'jpg' ? 'jpeg' : $opts['format']);
                wp_update_post(array(
                    'ID'             => $attachment_id,
                    'post_mime_type' => $target_mime,
                ));
            }
        }

        // Upload final processed image to Cloudflare R2 unless user explicitly selected local_only
        if ($auto_upload && $storage_mode !== 'local_only') {
            self::sync_attachment_to_r2($attachment_id, false, $metadata, false);
        }

        return $metadata;
    }

    /**
     * Upload an attachment and its generated thumbnail sizes to Cloudflare R2
     *
     * @param int $attachment_id
     * @param bool $force_reupload
     * @param array|null $metadata
     * @param bool $is_bulk_sync When true, local files are strictly preserved as backup!
     * @param array $batch_options Optional batch wildcard optimization options
     * @return bool
     */
    public static function sync_attachment_to_r2($attachment_id, $force_reupload = false, $metadata = null, $is_bulk_sync = false, $batch_options = array()) {
        $client = r2_by_grisma()->get_client();
        if (!$client || !$client->is_configured()) {
            return false;
        }

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            // Local file doesn't exist. Check if it's already on R2!
            $record = R2G_Database::get($attachment_id);
            if ($record && $record->status === 'synced') {
                return true;
            }
            return false;
        }

        // If batch options are provided during bulk sync (wildcard conversion), apply before upload:
        if (!empty($batch_options) && is_array($batch_options) && !empty($batch_options['format'])) {
            $opt_res = R2G_Optimizer::optimize_local_file($file_path, $batch_options);
            if ($opt_res['success'] && !empty($opt_res['file_path']) && $opt_res['file_path'] !== $file_path) {
                update_attached_file($attachment_id, $opt_res['file_path']);
                $file_path = $opt_res['file_path'];
                if (empty($metadata)) {
                    $metadata = wp_get_attachment_metadata($attachment_id);
                }
                $metadata['file'] = _wp_relative_upload_path($file_path);
                $target_fmt = $batch_options['format'];
                $target_mime = !empty($opt_res['mime']) ? $opt_res['mime'] : 'image/' . ($target_fmt === 'jpg' ? 'jpeg' : $target_fmt);
                wp_update_post(array(
                    'ID'             => $attachment_id,
                    'post_mime_type' => $target_mime,
                ));
                wp_update_attachment_metadata($attachment_id, $metadata);
            }
        }

        $main_r2_key = self::get_r2_key_from_path($file_path);
        $original_size = file_exists($file_path) ? filesize($file_path) : 0;

        // Upload main file
        $put_main = $client->put_object($file_path, $main_r2_key);
        if (!$put_main['success']) {
            R2G_Database::mark_failed($attachment_id);
            return false;
        }

        $uploaded_keys = array($main_r2_key);
        $thumb_count = 0;

        // Check thumbnail upload policy ('all' vs 'original_only')
        $upload_sizes_mode = get_option('r2g_upload_sizes', 'all');

        if ($upload_sizes_mode === 'all') {
            if (empty($metadata)) {
                $metadata = wp_get_attachment_metadata($attachment_id);
            }
            $dir = dirname($file_path);

            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size_info) {
                    $thumb_path = $dir . '/' . $size_info['file'];
                    if (file_exists($thumb_path)) {
                        $thumb_r2_key = self::get_r2_key_from_path($thumb_path);
                        $mime = isset($size_info['mime-type']) ? $size_info['mime-type'] : null;
                        $put_thumb = $client->put_object($thumb_path, $thumb_r2_key, $mime);
                        if ($put_thumb['success']) {
                            $uploaded_keys[] = $thumb_r2_key;
                            $thumb_count++;
                        }
                    }
                }
            }
        }

        $compressed_size = file_exists($file_path) ? filesize($file_path) : $original_size;

        // Record sync status in postmeta
        update_post_meta($attachment_id, '_r2g_synced', 1);
        update_post_meta($attachment_id, '_r2g_key', $main_r2_key);
        update_post_meta($attachment_id, '_r2g_keys', $uploaded_keys);
        update_post_meta($attachment_id, '_r2g_synced_at', current_time('mysql'));
        delete_post_meta($attachment_id, '_r2g_local_deleted');

        // Record in database index
        R2G_Database::mark_synced($attachment_id, $main_r2_key, $compressed_size, $original_size, $thumb_count);

        // Handle "R2 Only" storage mode:
        // CRITICAL DATA SAFETY RULE: NEVER delete local files during bulk sync!
        // Local files are only deleted on single uploads if user/policy explicitly requested R2 Only.
        if (!$is_bulk_sync) {
            $opts = self::get_effective_upload_options();
            $storage_mode = $opts['storage_mode'] ?? get_option('r2g_storage_mode', 'both');
            if ($storage_mode === 'r2_only') {
                if (empty($metadata)) {
                    $metadata = wp_get_attachment_metadata($attachment_id);
                }
                // Defer local deletion to PHP shutdown hook so REST API (Gutenberg) and Plupload can finalize response without missing-file errors
                add_action('shutdown', function() use ($attachment_id, $file_path, $metadata) {
                    self::delete_local_files($attachment_id, $file_path, $metadata);
                });
            }
        }

        return true;
    }

    /**
     * Delete local files for an attachment while keeping R2 cloud copy intact
     *
     * @param int $attachment_id
     * @param string $file_path
     * @param array $metadata
     * @return bool
     */
    public static function delete_local_files($attachment_id, $file_path = null, $metadata = null) {
        if (!$file_path) {
            $file_path = get_attached_file($attachment_id);
        }
        if (!$metadata) {
            $metadata = wp_get_attachment_metadata($attachment_id);
        }

        // Check cleanup scope: 'all' vs 'original_only'
        $cleanup_scope = get_option('r2g_cleanup_scope', 'all');

        if ($file_path && file_exists($file_path)) {
            @unlink($file_path);
        }

        if ($cleanup_scope === 'all' && !empty($metadata['sizes']) && is_array($metadata['sizes']) && $file_path) {
            $dir = dirname($file_path);
            foreach ($metadata['sizes'] as $size_info) {
                $thumb_path = $dir . '/' . $size_info['file'];
                if (file_exists($thumb_path)) {
                    @unlink($thumb_path);
                }
            }
        }

        update_post_meta($attachment_id, '_r2g_local_deleted', 1);

        // Update database index
        R2G_Database::set_has_local($attachment_id, false);
        return true;
    }

    /**
     * Download files from R2 back to local server
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function download_from_r2_to_local($attachment_id) {
        $custom_domain = get_option('r2g_custom_domain', '');
        if (empty($custom_domain)) {
            return false;
        }

        $file_path = get_attached_file($attachment_id);
        $metadata = wp_get_attachment_metadata($attachment_id);
        $main_key = get_post_meta($attachment_id, '_r2g_key', true);

        if (empty($main_key)) {
            $main_key = self::get_r2_key_from_path($file_path);
        }

        $cdn_base = rtrim($custom_domain, '/');

        // Download main file
        $remote_url = $cdn_base . '/' . ltrim($main_key, '/');
        $res = wp_remote_get($remote_url, array('timeout' => 30));

        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
            $body = wp_remote_retrieve_body($res);
            $dir = dirname($file_path);
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
            file_put_contents($file_path, $body);

            // Download thumbnails if available
            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size_info) {
                    $thumb_path = $dir . '/' . $size_info['file'];
                    $thumb_key = self::get_r2_key_from_path($thumb_path);
                    $t_url = $cdn_base . '/' . ltrim($thumb_key, '/');
                    $t_res = wp_remote_get($t_url, array('timeout' => 30));
                    if (!is_wp_error($t_res) && wp_remote_retrieve_response_code($t_res) === 200) {
                        file_put_contents($thumb_path, wp_remote_retrieve_body($t_res));
                    }
                }
            }

            delete_post_meta($attachment_id, '_r2g_local_deleted');
            R2G_Database::set_has_local($attachment_id, true);
            return true;
        }

        return false;
    }

    /**
     * Delete an attachment and all its thumbnails from Cloudflare R2 bucket
     * Keeps the local file intact if it exists.
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function delete_from_r2($attachment_id) {
        $client = r2_by_grisma()->get_client();
        if (!$client || !$client->is_configured()) {
            return false;
        }

        $keys = get_post_meta($attachment_id, '_r2g_keys', true);
        if (empty($keys) || !is_array($keys)) {
            $main_key = get_post_meta($attachment_id, '_r2g_key', true);
            if (empty($main_key)) {
                $file_path = get_attached_file($attachment_id);
                $main_key = self::get_r2_key_from_path($file_path);
            }
            $keys = $main_key ? array($main_key) : array();
        }

        // Delete each key from R2
        foreach ($keys as $k) {
            $client->delete_object($k);
        }

        // Remove R2 postmeta
        delete_post_meta($attachment_id, '_r2g_synced');
        delete_post_meta($attachment_id, '_r2g_key');
        delete_post_meta($attachment_id, '_r2g_keys');
        delete_post_meta($attachment_id, '_r2g_synced_at');

        // Check if local file still exists
        $file_path = get_attached_file($attachment_id);
        $has_local = !empty($file_path) && file_exists($file_path) && (filesize($file_path) > 300);

        if ($has_local) {
            // Revert status to pending/local in database
            R2G_Database::upsert($attachment_id, array(
                'status'    => 'pending',
                'has_local' => 1,
                'r2_key'    => '',
            ));
        } else {
            // No local copy and deleted from R2
            R2G_Database::mark_deleted($attachment_id);
        }

        return true;
    }

    /**
     * Purge attachment and thumbnails from Cloudflare R2 when deleted from WordPress
     *
     * @param int $attachment_id
     */
    public function on_delete_attachment($attachment_id) {
        $delete_from_r2 = (int) get_option('r2g_delete_from_r2', 1);

        if ($delete_from_r2) {
            self::delete_from_r2($attachment_id);
        }

        // Remove from database index
        R2G_Database::remove($attachment_id);
    }
}
