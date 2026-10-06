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

    /**
     * Prevent cloning of the singleton instance
     */
    private function __clone() {}

    private function __construct() {
        if (get_option('r2g_compress_enabled', 1) && (int) get_option('r2g_max_width', 1920) > 0) {
            add_filter('big_image_size_threshold', '__return_false');
        }
        add_filter('wp_read_image_metadata', array($this, 'clean_image_metadata'), 10, 3);
        add_filter('wp_insert_attachment_data', array($this, 'clean_attachment_title'), 10, 2);
        add_filter('wp_handle_upload', array($this, 'on_handle_upload'), 10, 2);
        add_filter('wp_editor_set_quality', array($this, 'filter_editor_quality'), 10, 2);
        add_filter('wp_generate_attachment_metadata', array($this, 'on_generate_metadata'), 20, 2);
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
        $junk_patterns = array('Intel(R) JPEG Library', 'Intel JPEG Library');
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
        $junk_patterns = array('Intel(R) JPEG Library', 'Intel JPEG Library');

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
     * What the optimizer really did for files processed in this request, keyed by final path.
     * Persisted to the attachment (_r2g_opt_info) once its ID exists.
     *
     * @var array
     */
    private static $opt_info = array();

    /**
     * Mark a file as already optimized so on_generate_metadata does not run the engine a second time.
     *
     * @param string $file_path
     */
    public static function mark_processed($file_path) {
        self::$processed_files[$file_path] = true;
    }

    /**
     * Build the compact optimization record stored on the attachment.
     *
     * @param array $opt_res   Result of R2G_Optimizer::optimize_local_file()
     * @param array $opts      Effective upload options
     * @param int   $orig_size Bytes before optimization
     * @param string $final_path Final file path
     * @return array
     */
    public static function build_opt_info($opt_res, $opts, $orig_size, $final_path) {
        clearstatcache(true, $final_path);
        $final_size = file_exists($final_path) ? (int) filesize($final_path) : 0;
        return array(
            'engine_requested' => $opts['engine'] ?? '',
            'engine_used'      => $opt_res['engine_used'] ?? '',
            'note'             => $opt_res['engine_note'] ?? '',
            'orig_bytes'       => (int) $orig_size,
            'final_bytes'      => $final_size,
            'time'             => current_time('mysql'),
        );
    }

    /**
     * Resolve effective upload preferences
     *
     * @return array
     */
    public static function get_effective_upload_options() {
        $presets = self::get_presets();
        $preset_id = isset($_POST['r2g_preset']) ? sanitize_key(wp_unslash($_POST['r2g_preset'])) : '';
        $preset = isset($presets[$preset_id]) ? $presets[$preset_id] : null;

        $format = '';
        if (!empty($_POST['r2g_format'])) {
            $format = sanitize_text_field(wp_unslash($_POST['r2g_format']));
        } elseif (!empty($_SERVER['HTTP_X_R2G_FORMAT'])) {
            $format = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_R2G_FORMAT']));
        } elseif ($preset) {
            $format = $preset['format'];
        } elseif (!empty($_COOKIE['r2g_format'])) {
            $format = sanitize_text_field(wp_unslash($_COOKIE['r2g_format']));
        } else {
            $format = get_option('r2g_compress_format', 'webp');
        }

        $format = strtolower($format);
        if ($format === 'jpeg') {
            $format = 'jpg';
        }

        $quality = 0;
        if (isset($_POST['r2g_quality']) && $_POST['r2g_quality'] !== '') {
            $quality = (int)$_POST['r2g_quality'];
        } elseif (isset($_SERVER['HTTP_X_R2G_QUALITY']) && $_SERVER['HTTP_X_R2G_QUALITY'] !== '') {
            $quality = (int)$_SERVER['HTTP_X_R2G_QUALITY'];
        } elseif ($preset) {
            $quality = (int)$preset['quality'];
        } elseif (isset($_COOKIE['r2g_quality']) && $_COOKIE['r2g_quality'] !== '') {
            $quality = (int)$_COOKIE['r2g_quality'];
        } else {
            $quality = (int) get_option('r2g_compress_quality', 82);
        }
        $quality = max(50, min(100, $quality));

        if (isset($_POST['r2g_compress']) && $_POST['r2g_compress'] !== '') {
            $compress = (int)$_POST['r2g_compress'];
        } elseif (isset($_SERVER['HTTP_X_R2G_COMPRESS']) && $_SERVER['HTTP_X_R2G_COMPRESS'] !== '') {
            $compress = (int)$_SERVER['HTTP_X_R2G_COMPRESS'];
        } elseif ($preset) {
            $compress = (int)$preset['compress'];
        } elseif (isset($_COOKIE['r2g_compress']) && $_COOKIE['r2g_compress'] !== '') {
            $compress = (int)$_COOKIE['r2g_compress'];
        } else {
            $compress = (int) get_option('r2g_compress_enabled', 1);
        }

        if (isset($_POST['r2g_max_width']) && $_POST['r2g_max_width'] !== '') {
            $max_width = (int)$_POST['r2g_max_width'];
        } elseif (isset($_SERVER['HTTP_X_R2G_MAX_WIDTH']) && $_SERVER['HTTP_X_R2G_MAX_WIDTH'] !== '') {
            $max_width = (int)$_SERVER['HTTP_X_R2G_MAX_WIDTH'];
        } elseif ($preset) {
            $max_width = (int)$preset['max_width'];
        } elseif (isset($_COOKIE['r2g_max_width']) && $_COOKIE['r2g_max_width'] !== '') {
            $max_width = (int)$_COOKIE['r2g_max_width'];
        } else {
            $max_width = (int) get_option('r2g_max_width', 1920);
        }

        $engine = '';
        if (!empty($_POST['r2g_engine'])) {
            $engine = sanitize_text_field(wp_unslash($_POST['r2g_engine']));
        } elseif (!empty($_SERVER['HTTP_X_R2G_ENGINE'])) {
            $engine = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_R2G_ENGINE']));
        } elseif (!empty($_COOKIE['r2g_engine'])) {
            $engine = sanitize_text_field(wp_unslash($_COOKIE['r2g_engine']));
        } else {
            $engine = get_option('r2g_compress_engine', 'resmush');
        }
        $engine = R2G_Optimizer::normalize_engine($engine);

        if ($engine === 'none') {
            $compress = 0;
            $format = 'original';
            $max_width = 0;
        }

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

        return array(
            'format'       => $format,
            'quality'      => $quality,
            'compress'     => (bool)$compress,
            'max_width'    => $max_width,
            'storage_mode' => $storage_mode,
            'engine'       => $engine,
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
        $orig_size = (int) filesize($file_path);

        $opt_res = R2G_Optimizer::optimize_local_file($file_path, array(
            'format'    => $opts['format'],
            'quality'   => $opts['quality'],
            'max_width' => $opts['max_width'],
            'compress'  => $opts['compress'],
            'engine'    => $opts['engine'],
        ));

        if (empty($opt_res['success']) && $opts['format'] === 'webp' && strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) !== 'webp') {
            $upload['error'] = !empty($opt_res['message'])
                ? $opt_res['message']
                : __('WebP conversion failed. The image was not accepted; check server image support and retry.', 'r2-by-grisma');
            return $upload;
        }

        if (!empty($opt_res['success']) && !empty($opt_res['file_path'])) {
            $new_file = $opt_res['file_path'];
            $upload['file'] = $new_file;

            self::$processed_files[$new_file]  = $opts;
            self::$processed_files[$file_path] = $opts;
            self::$opt_info[$new_file] = self::build_opt_info($opt_res, $opts, $orig_size, $new_file);

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

        $post_obj = get_post($attachment_id);
        if ($post_obj && !empty($post_obj->post_excerpt) && preg_match('/Intel(\(R\))?\s*JPEG\s*Library/i', $post_obj->post_excerpt)) {
            wp_update_post(array('ID' => $attachment_id, 'post_excerpt' => ''));
        }
        if (!empty($metadata['image_meta']['caption']) && preg_match('/Intel(\(R\))?\s*JPEG\s*Library/i', $metadata['image_meta']['caption'])) {
            $metadata['image_meta']['caption'] = '';
        }

        $already_processed = isset(self::$processed_files[$file_path]);

        if (!$already_processed) {
            $orig_size = (int) filesize($file_path);
            $opt_res = R2G_Optimizer::optimize_local_file($file_path, array(
                'format'    => $opts['format'],
                'quality'   => $opts['quality'],
                'max_width' => $opts['max_width'],
                'compress'  => $opts['compress'],
                'engine'    => $opts['engine'],
            ));

            if (!empty($opt_res['success']) && !empty($opt_res['file_path'])) {
                if ($opt_res['file_path'] !== $file_path) {
                    update_attached_file($attachment_id, $opt_res['file_path']);
                    $file_path = $opt_res['file_path'];
                    $metadata['file'] = _wp_relative_upload_path($file_path);
                    $target_mime = !empty($opt_res['mime']) ? $opt_res['mime'] : 'image/' . ($opts['format'] === 'jpg' ? 'jpeg' : $opts['format']);
                    wp_update_post(array(
                        'ID'             => $attachment_id,
                        'post_mime_type' => $target_mime,
                    ));
                }
                self::$opt_info[$file_path] = self::build_opt_info($opt_res, $opts, $orig_size, $file_path);
            }
        }

        if (isset(self::$opt_info[$file_path])) {
            update_post_meta($attachment_id, '_r2g_opt_info', self::$opt_info[$file_path]);
            if ((self::$opt_info[$file_path]['engine_used'] ?? '') !== 'none') {
                update_post_meta($attachment_id, '_r2g_optimized', 1);
            }
        }

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
     * @param bool $is_bulk_sync
     * @param array $batch_options
     * @return bool
     */
    public static function sync_attachment_to_r2($attachment_id, $force_reupload = false, $metadata = null, $is_bulk_sync = false, $batch_options = array()) {
        $client = r2_by_grisma()->get_client();
        if (!$client || !$client->is_configured()) {
            return false;
        }

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            $record = R2G_Database::get($attachment_id);
            if ($record && $record->status === 'synced') {
                return true;
            }
            return false;
        }

        if (!empty($batch_options) && is_array($batch_options) && !empty($batch_options['format'])) {
            $opt_res = R2G_Optimizer::optimize_local_file($file_path, $batch_options);
            if (empty($opt_res['success'])) {
                return false;
            }
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

        $put_main = $client->put_object($file_path, $main_r2_key);
        if (!$put_main['success']) {
            R2G_Database::mark_failed($attachment_id);
            return false;
        }

        $uploaded_keys = array($main_r2_key);
        $thumb_count = 0;

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

        update_post_meta($attachment_id, '_r2g_synced', 1);
        update_post_meta($attachment_id, '_r2g_key', $main_r2_key);
        update_post_meta($attachment_id, '_r2g_keys', $uploaded_keys);
        update_post_meta($attachment_id, '_r2g_synced_at', current_time('mysql'));
        delete_post_meta($attachment_id, '_r2g_local_deleted');

        R2G_Database::mark_synced($attachment_id, $main_r2_key, $compressed_size, $original_size, $thumb_count);

        if (!$is_bulk_sync) {
            $opts = self::get_effective_upload_options();
            $storage_mode = $opts['storage_mode'] ?? get_option('r2g_storage_mode', 'both');
            if ($storage_mode === 'r2_only') {
                if (empty($metadata)) {
                    $metadata = wp_get_attachment_metadata($attachment_id);
                }
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
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return false;
        }

        if (!$file_path) {
            $file_path = get_attached_file($attachment_id);
        }
        if (!$metadata) {
            $metadata = wp_get_attachment_metadata($attachment_id);
        }

        if (!$file_path || !file_exists($file_path)) {
            return false;
        }

        $cleanup_scope = get_option('r2g_cleanup_scope', 'all');
        $paths = array($file_path);
        $upload_sizes_mode = get_option('r2g_upload_sizes', 'all');
        if ($cleanup_scope === 'all' && $upload_sizes_mode === 'all' && !empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $dir = dirname($file_path);
            foreach ($metadata['sizes'] as $size_info) {
                if (empty($size_info['file'])) {
                    continue;
                }
                $thumb_path = $dir . '/' . $size_info['file'];
                if (file_exists($thumb_path)) {
                    $paths[] = $thumb_path;
                }
            }
        }

        $client = r2_by_grisma()->get_client();
        if (!$client || !$client->is_configured()) {
            return false;
        }
        foreach ($paths as $path) {
            $key = self::get_r2_key_from_path($path);
            if (!$key || !$client->object_exists($key)) {
                return false;
            }
        }

        foreach ($paths as $path) {
            if (file_exists($path) && !@unlink($path)) {
                return false;
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
        if (empty($file_path)) {
            return false;
        }
        $metadata = wp_get_attachment_metadata($attachment_id);
        $main_key = get_post_meta($attachment_id, '_r2g_key', true);

        if (empty($main_key)) {
            $main_key = self::get_r2_key_from_path($file_path);
        }
        if (empty($main_key)) {
            return false;
        }

        $cdn_base = rtrim($custom_domain, '/');
        $expected_mime = (string) get_post_mime_type($attachment_id);

        // Download main file (validated before it is written into uploads)
        $remote_url = $cdn_base . '/' . ltrim($main_key, '/');
        if (!self::fetch_validated_remote_file($remote_url, $file_path, $expected_mime)) {
            return false;
        }

        // Download thumbnails if available
        $dir = dirname($file_path);
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_info) {
                if (empty($size_info['file']) || !is_string($size_info['file'])) {
                    continue;
                }
                // Thumbnail entries must be bare file names in the same directory
                $thumb_file = wp_basename($size_info['file']);
                if ($thumb_file !== $size_info['file'] || validate_file($thumb_file) !== 0) {
                    continue;
                }
                $thumb_path = $dir . '/' . $thumb_file;
                $thumb_key = self::get_r2_key_from_path($thumb_path);
                if (empty($thumb_key)) {
                    continue;
                }
                $t_url = $cdn_base . '/' . ltrim($thumb_key, '/');
                $t_mime = !empty($size_info['mime-type']) ? (string) $size_info['mime-type'] : $expected_mime;
                self::fetch_validated_remote_file($t_url, $thumb_path, $t_mime);
            }
        }

        delete_post_meta($attachment_id, '_r2g_local_deleted');
        R2G_Database::set_has_local($attachment_id, true);
        return true;
    }

    /**
     * Download a remote object to a temp file, validate it, then move it into place.
     *
     * Validation:
     *  - destination must resolve inside the uploads base directory
     *  - HTTP 200, non-empty, below the size cap (response is size-limited while streaming)
     *  - Content-Type must not contradict the expected MIME family (text/html error pages etc.)
     *  - extension/MIME must be an allowed upload type and match the real file contents
     *  - image attachments must be decodable images
     *
     * @param string $url
     * @param string $dest_path
     * @param string $expected_mime
     * @return bool
     */
    protected static function fetch_validated_remote_file($url, $dest_path, $expected_mime = '') {
        if (!wp_http_validate_url($url)) {
            return false;
        }

        // Ensure destination is inside the uploads directory
        $uploads = wp_upload_dir(null, false);
        $base_dir = wp_normalize_path(untrailingslashit($uploads['basedir']));
        $dest_path = wp_normalize_path($dest_path);
        $dest_dir = dirname($dest_path);
        if (preg_match('#(^|/)\.\.(/|$)#', $dest_path) || strpos($dest_dir . '/', $base_dir . '/') !== 0) {
            return false;
        }
        if (!file_exists($dest_dir) && !wp_mkdir_p($dest_dir)) {
            return false;
        }

        $max_bytes = (int) apply_filters('r2g_download_max_bytes', 512 * 1048576, $url, $dest_path);

        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp = wp_tempnam(wp_basename($dest_path));
        if (!$tmp) {
            return false;
        }

        $res = wp_remote_get($url, array(
            'timeout'             => 60,
            'redirection'         => 2,
            'stream'              => true,
            'filename'            => $tmp,
            'limit_response_size' => $max_bytes,
        ));

        $cleanup = function () use ($tmp) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        };

        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            $cleanup();
            return false;
        }

        clearstatcache(true, $tmp);
        $size = file_exists($tmp) ? (int) filesize($tmp) : 0;
        if ($size <= 0 || $size >= $max_bytes) {
            // Empty, or truncated at the size limit
            $cleanup();
            return false;
        }

        // Content-Type header must not contradict the expected MIME family
        $content_type = strtolower(trim(explode(';', (string) wp_remote_retrieve_header($res, 'content-type'))[0]));
        $expected_family = $expected_mime !== '' ? strtolower(strtok($expected_mime, '/')) : '';
        if ($content_type !== '' && $content_type !== 'application/octet-stream' && $content_type !== 'binary/octet-stream') {
            if ($expected_family !== '' && strpos($content_type, $expected_family . '/') !== 0) {
                $cleanup();
                return false;
            }
            if (in_array($content_type, array('text/html', 'application/xhtml+xml'), true) && $expected_family !== 'text') {
                $cleanup();
                return false;
            }
        }

        // Real file contents must match an allowed upload type (type reflects the detected
        // contents when it differs from the extension, so the family check below still applies)
        $check = wp_check_filetype_and_ext($tmp, wp_basename($dest_path));
        if (empty($check['ext']) || empty($check['type'])) {
            $cleanup();
            return false;
        }
        if ($expected_family !== '' && strpos($check['type'], $expected_family . '/') !== 0) {
            $cleanup();
            return false;
        }

        if ($expected_family === 'image' || strpos($check['type'], 'image/') === 0) {
            if ($check['type'] !== 'image/svg+xml' && @getimagesize($tmp) === false) {
                $cleanup();
                return false;
            }
        }

        // Move into place
        if (!@rename($tmp, $dest_path)) {
            if (!@copy($tmp, $dest_path)) {
                $cleanup();
                return false;
            }
            $cleanup();
        }

        // Match WordPress default upload permissions
        $stat = @stat($dest_dir);
        if ($stat) {
            @chmod($dest_path, $stat['mode'] & 0000666);
        }

        return true;
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

        foreach ($keys as $k) {
            $client->delete_object($k);
        }

        delete_post_meta($attachment_id, '_r2g_synced');
        delete_post_meta($attachment_id, '_r2g_key');
        delete_post_meta($attachment_id, '_r2g_keys');
        delete_post_meta($attachment_id, '_r2g_synced_at');

        $file_path = get_attached_file($attachment_id);
        $has_local = !empty($file_path) && file_exists($file_path) && (filesize($file_path) > 300);

        if ($has_local) {
            R2G_Database::upsert($attachment_id, array(
                'status'    => 'pending',
                'has_local' => 1,
                'r2_key'    => '',
            ));
        } else {
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

        R2G_Database::remove($attachment_id);
    }
}
