<?php
/**
 * Media Attachment Lifecycle Handler
 * Intercepts uploads, applies compression pipeline, uploads to R2,
 * cleans local files if configured, and handles R2 deletion on attachment trash.
 * Gives user complete control over automated vs manual actions.
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

        // Intercept attachment metadata generation (after thumbnails are cut)
        add_filter('wp_generate_attachment_metadata', array($this, 'on_generate_metadata'), 20, 2);

        // Delete from R2 when deleted from WordPress Media Library
        add_action('delete_attachment', array($this, 'on_delete_attachment'));

        // Background async compression event
        add_action('r2g_async_resmush_job', array('R2G_Optimizer', 'process_resmush_async'));
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
     * Process attachment upon upload
     *
     * @param array $metadata
     * @param int $attachment_id
     * @return array
     */
    public function on_generate_metadata($metadata, $attachment_id) {
        // Check if user enabled automatic upload (Default: 1, user can disable for manual control)
        $auto_upload = (int) get_option('r2g_auto_upload', 1);

        if (!wp_attachment_is_image($attachment_id)) {
            // Upload non-image files directly to R2 if auto-upload is on
            if ($auto_upload) {
                self::sync_attachment_to_r2($attachment_id);
            }
            return $metadata;
        }

        $engine = get_option('r2g_compress_engine', 'browser'); // 'browser', 'server', 'resmush_async', 'none'
        $format = get_option('r2g_compress_format', 'webp');
        $quality = (int)get_option('r2g_compress_quality', 82);
        $max_width = (int)get_option('r2g_max_width', 1920);

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return $metadata;
        }

        $current_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        // 1. Mandatory Format & Compression Enforcement before R2 Upload:
        // Converts raw PNG/JPG to WebP even if browser canvas was bypassed (e.g. built-in browser uploader on media-new.php)
        if ($format !== 'none' && ($engine === 'server' || ($format === 'webp' && $current_ext !== 'webp'))) {
            $opt_res = R2G_Optimizer::optimize_local_file($file_path, array(
                'format'    => $format,
                'quality'   => $quality,
                'max_width' => $max_width,
            ));

            if ($opt_res['success'] && $opt_res['file_path'] !== $file_path) {
                update_attached_file($attachment_id, $opt_res['file_path']);
                $file_path = $opt_res['file_path'];
                $metadata['file'] = _wp_relative_upload_path($file_path);
                wp_update_post(array(
                    'ID'             => $attachment_id,
                    'post_mime_type' => !empty($opt_res['mime']) ? $opt_res['mime'] : 'image/webp',
                ));
            }

            // Purge Intel EXIF Software string from post_excerpt & metadata caption if present
            $post_obj = get_post($attachment_id);
            if ($post_obj && !empty($post_obj->post_excerpt) && stripos($post_obj->post_excerpt, 'Intel') !== false) {
                wp_update_post(array(
                    'ID'           => $attachment_id,
                    'post_excerpt' => '',
                ));
            }
            if (!empty($metadata['image_meta']['caption']) && stripos($metadata['image_meta']['caption'], 'Intel') !== false) {
                $metadata['image_meta']['caption'] = '';
            }

            // Optimize thumbnail sizes too
            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                $dir = dirname($file_path);
                foreach ($metadata['sizes'] as $size_key => &$size_info) {
                    $thumb_path = $dir . '/' . $size_info['file'];
                    if (file_exists($thumb_path)) {
                        $thumb_res = R2G_Optimizer::optimize_local_file($thumb_path, array(
                            'format'    => $format,
                            'quality'   => $quality,
                            'max_width' => 0,
                        ));
                        if ($thumb_res['success'] && $thumb_res['file_path'] !== $thumb_path) {
                            $size_info['file'] = basename($thumb_res['file_path']);
                            if (!empty($thumb_res['mime'])) {
                                $size_info['mime-type'] = $thumb_res['mime'];
                            }
                        }
                    }
                }
            }
        }

        // 2. Strict Safeguard: If format conversion is required but file is not formatted, DO NOT upload to R2
        $final_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if ($format === 'webp' && $final_ext !== 'webp') {
            return $metadata;
        }

        // 3. Upload to Cloudflare R2 if auto-upload is enabled
        if ($auto_upload) {
            self::sync_attachment_to_r2($attachment_id, false, $metadata);
        }

        // 4. If async reSmush is enabled, enqueue background optimization job
        if ($engine === 'resmush_async') {
            wp_schedule_single_event(time() + 5, 'r2g_async_resmush_job', array($attachment_id));
        }

        return $metadata;
    }

    /**
     * Upload an attachment and its generated thumbnail sizes to Cloudflare R2
     *
     * @param int $attachment_id
     * @param bool $force_reupload
     * @param array|null $metadata
     * @return bool
     */
    public static function sync_attachment_to_r2($attachment_id, $force_reupload = false, $metadata = null) {
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

        // Handle "R2 Only" storage mode: remove local copies to save disk space
        $storage_mode = get_option('r2g_storage_mode', 'both');
        if ($storage_mode === 'r2_only') {
            if (empty($metadata)) {
                $metadata = wp_get_attachment_metadata($attachment_id);
            }
            self::delete_local_files($attachment_id, $file_path, $metadata);
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
