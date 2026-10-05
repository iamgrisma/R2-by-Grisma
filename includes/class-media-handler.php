<?php
/**
 * Media Attachment Lifecycle Handler
 * Intercepts uploads, applies compression pipeline, uploads to R2,
 * cleans local files if configured, and handles R2 deletion on attachment trash.
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
        // Intercept attachment metadata generation (after thumbnails are cut)
        add_filter('wp_generate_attachment_metadata', array($this, 'on_generate_metadata'), 20, 2);

        // Delete from R2 when deleted from WordPress Media Library
        add_action('delete_attachment', array($this, 'on_delete_attachment'));

        // Background async compression event
        add_action('r2g_async_resmush_job', array('R2G_Optimizer', 'process_resmush_async'));
    }

    /**
     * Get R2 Key for a given absolute file path
     * Preserves standard wp-content/uploads/YYYY/MM/filename.ext structure without random hash folders.
     *
     * @param string $file_path
     * @return string
     */
    public static function get_r2_key_from_path($file_path) {
        $uploads = wp_upload_dir();
        $basedir = wp_normalize_path($uploads['basedir']);
        $normalized_file = wp_normalize_path($file_path);

        if (strpos($normalized_file, $basedir) === 0) {
            $rel = ltrim(substr($normalized_file, strlen($basedir)), '/');
            // Standard WordPress structure: wp-content/uploads/YYYY/MM/file.ext
            return 'wp-content/uploads/' . $rel;
        }

        // Fallback relative path
        $content_dir = wp_normalize_path(WP_CONTENT_DIR);
        if (strpos($normalized_file, $content_dir) === 0) {
            return 'wp-content/' . ltrim(substr($normalized_file, strlen($content_dir)), '/');
        }

        return 'wp-content/uploads/' . basename($file_path);
    }

    /**
     * Process attachment upon upload
     *
     * @param array $metadata
     * @param int $attachment_id
     * @return array
     */
    public function on_generate_metadata($metadata, $attachment_id) {
        if (!wp_attachment_is_image($attachment_id)) {
            // Upload non-image files directly to R2 if enabled
            self::sync_attachment_to_r2($attachment_id);
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

        // 1. If server compression is enabled, optimize local file and thumbnails now
        if ($engine === 'server' && $format !== 'none') {
            $opt_res = R2G_Optimizer::optimize_local_file($file_path, array(
                'format'    => $format,
                'quality'   => $quality,
                'max_width' => $max_width,
            ));

            if ($opt_res['success'] && $opt_res['file_path'] !== $file_path) {
                // Update attached file path if format changed (e.g. to webp)
                update_attached_file($attachment_id, $opt_res['file_path']);
                $file_path = $opt_res['file_path'];
            }

            // Optimize thumbnail sizes
            if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
                $dir = dirname($file_path);
                foreach ($metadata['sizes'] as $size_key => $size_info) {
                    $thumb_path = $dir . '/' . $size_info['file'];
                    if (file_exists($thumb_path)) {
                        R2G_Optimizer::optimize_local_file($thumb_path, array(
                            'format'    => $format,
                            'quality'   => $quality,
                            'max_width' => 0,
                        ));
                    }
                }
            }
        }

        // 2. Upload to Cloudflare R2
        self::sync_attachment_to_r2($attachment_id);

        // 3. If async reSmush is enabled, enqueue background optimization job
        if ($engine === 'resmush_async') {
            wp_schedule_single_event(time() + 5, 'r2g_async_resmush_job', array($attachment_id));
        }

        return $metadata;
    }

    /**
     * Upload an attachment and all its generated thumbnail sizes to Cloudflare R2
     *
     * @param int $attachment_id
     * @param bool $force_reupload
     * @return bool
     */
    public static function sync_attachment_to_r2($attachment_id, $force_reupload = false) {
        $client = r2_by_grisma()->get_client();
        if (!$client || !$client->is_configured()) {
            return false;
        }

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }

        $main_r2_key = self::get_r2_key_from_path($file_path);

        // Upload main file
        $put_main = $client->put_object($file_path, $main_r2_key);
        if (!$put_main['success']) {
            return false;
        }

        $uploaded_keys = array($main_r2_key);

        // Upload thumbnail sizes
        $metadata = wp_get_attachment_metadata($attachment_id);
        $dir = dirname($file_path);

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_info) {
                $thumb_path = $dir . '/' . $size_info['file'];
                if (file_exists($thumb_path)) {
                    $thumb_r2_key = self::get_r2_key_from_path($thumb_path);
                    $put_thumb = $client->put_object($thumb_path, $thumb_r2_key, $size_info['mime-type'] ?? null);
                    if ($put_thumb['success']) {
                        $uploaded_keys[] = $thumb_r2_key;
                    }
                }
            }
        }

        // Record sync status in postmeta
        update_post_meta($attachment_id, '_r2g_synced', 1);
        update_post_meta($attachment_id, '_r2g_key', $main_r2_key);
        update_post_meta($attachment_id, '_r2g_keys', $uploaded_keys);
        update_post_meta($attachment_id, '_r2g_synced_at', current_time('mysql'));

        // Handle "R2 Only" storage mode: remove local copies to save disk space
        $storage_mode = get_option('r2g_storage_mode', 'both');
        if ($storage_mode === 'r2_only') {
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
     */
    public static function delete_local_files($attachment_id, $file_path = null, $metadata = null) {
        if (!$file_path) {
            $file_path = get_attached_file($attachment_id);
        }
        if (!$metadata) {
            $metadata = wp_get_attachment_metadata($attachment_id);
        }

        if ($file_path && file_exists($file_path)) {
            @unlink($file_path);
        }

        if (!empty($metadata['sizes']) && is_array($metadata['sizes']) && $file_path) {
            $dir = dirname($file_path);
            foreach ($metadata['sizes'] as $size_info) {
                $thumb_path = $dir . '/' . $size_info['file'];
                if (file_exists($thumb_path)) {
                    @unlink($thumb_path);
                }
            }
        }

        update_post_meta($attachment_id, '_r2g_local_deleted', 1);
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

            // Download thumbnails
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
            return true;
        }

        return false;
    }

    /**
     * Purge attachment and thumbnails from Cloudflare R2 on deletion
     *
     * @param int $attachment_id
     */
    public function on_delete_attachment($attachment_id) {
        $client = r2_by_grisma()->get_client();
        if (!$client || !$client->is_configured()) {
            return;
        }

        $keys = get_post_meta($attachment_id, '_r2g_keys', true);
        if (empty($keys) || !is_array($keys)) {
            $main_key = get_post_meta($attachment_id, '_r2g_key', true);
            $keys = $main_key ? array($main_key) : array();
        }

        foreach ($keys as $k) {
            $client->delete_object($k);
        }
    }
}
