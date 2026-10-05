<?php
/**
 * Image Optimizer Engine
 * Handles On-Site WebP Conversion, JPEG/PNG Compression (GD/Imagick),
 * and Async Background reSmush.it Free API Processing.
 *
 * @package R2_By_Grisma
 */

if (!defined('ABSPATH')) {
    exit;
}

class R2G_Optimizer {
    /**
     * Check if server has WebP generation support via GD or Imagick
     *
     * @return bool
     */
    public static function can_generate_webp() {
        if (function_exists('imagick_supports_format') && imagick_supports_format('WEBP')) {
            return true;
        }
        if (function_exists('imagecreatefromwebp') && function_exists('imagewebp')) {
            return true;
        }
        // WordPress 5.8+ core check
        if (function_exists('wp_image_editor_supports')) {
            return wp_image_editor_supports(array('methods' => array('rotate', 'resize', 'save'), 'mime_type' => 'image/webp'));
        }
        return false;
    }

    /**
     * Compress and optionally convert an image file on the local server
     *
     * @param string $file_path Absolute path to the image
     * @param array $options [ 'format' => 'webp'|'original'|'none', 'quality' => 82, 'max_width' => 1920 ]
     * @return array [ 'success' => bool, 'file_path' => string, 'mime' => string, 'bytes_saved' => int ]
     */
    public static function optimize_local_file($file_path, $options = array()) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return array('success' => false, 'file_path' => $file_path, 'mime' => '', 'bytes_saved' => 0);
        }

        $format = $options['format'] ?? 'webp';
        if ($format === 'none') {
            return array('success' => true, 'file_path' => $file_path, 'mime' => '', 'bytes_saved' => 0);
        }

        $quality = (int)($options['quality'] ?? 82);
        $max_width = (int)($options['max_width'] ?? 1920);
        $original_size = filesize($file_path);

        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return array('success' => false, 'file_path' => $file_path, 'mime' => '', 'bytes_saved' => 0);
        }

        // Set compression quality
        $editor->set_quality($quality);

        // Resize if larger than max width
        $size = $editor->get_size();
        if ($max_width > 0 && !empty($size['width']) && $size['width'] > $max_width) {
            $editor->resize($max_width, null, false);
        }

        // Determine target mime and file path
        $path_info = pathinfo($file_path);
        $dirname = $path_info['dirname'];
        $clean_filename = preg_replace('/-scaled$/i', '', $path_info['filename']);

        $target_mime = null;
        $target_file = $file_path;

        if ($format === 'webp' && self::can_generate_webp()) {
            $target_mime = 'image/webp';
            $target_file = $dirname . '/' . $clean_filename . '.webp';
        }

        $saved = $editor->save($target_file, $target_mime);
        if (is_wp_error($saved)) {
            return array('success' => false, 'file_path' => $file_path, 'mime' => '', 'bytes_saved' => 0);
        }

        $final_path = $saved['path'] ?? $target_file;
        $new_size = file_exists($final_path) ? filesize($final_path) : $original_size;

        // If converted to webp and distinct from original, optionally remove original file
        if ($final_path !== $file_path && file_exists($file_path)) {
            @unlink($file_path);
        }

        $bytes_saved = max(0, $original_size - $new_size);

        return array(
            'success'     => true,
            'file_path'   => $final_path,
            'mime'        => $saved['mime-type'] ?? ($target_mime ?: ''),
            'bytes_saved' => $bytes_saved,
        );
    }

    /**
     * Background reSmush.it Free API compression job
     * Dispatches image to reSmush.it web service, replaces local file, and updates R2 object in-place.
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function process_resmush_async($attachment_id) {
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return false;
        }

        // Check if supported format (JPEG, PNG, GIF, BMP, WebP)
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'))) {
            return false;
        }

        $boundary = wp_generate_password(24, false);
        $file_content = file_get_contents($file);
        if ($file_content === false) {
            return false;
        }

        // Prepare multipart payload for reSmush.it API
        $payload = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"files\"; filename=\"" . basename($file) . "\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $file_content . "\r\n"
            . "--{$boundary}--\r\n";

        $response = wp_remote_post('http://api.resmush.it/ws.php', array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => "multipart/form-data; boundary={$boundary}",
            ),
            'body'    => $payload,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['dest'])) {
            return false;
        }

        // Fetch compressed image from reSmush.it destination URL
        $compressed_img = wp_remote_get($data['dest'], array('timeout' => 30));
        if (is_wp_error($compressed_img) || wp_remote_retrieve_response_code($compressed_img) !== 200) {
            return false;
        }

        $new_bytes = wp_remote_retrieve_body($compressed_img);
        if (strlen($new_bytes) < filesize($file)) {
            // Write compressed bytes over original file
            file_put_contents($file, $new_bytes);

            // Re-upload to Cloudflare R2 to replace object in-place (URL remains 100% identical!)
            if (class_exists('R2G_Media_Handler')) {
                R2G_Media_Handler::sync_attachment_to_r2($attachment_id, true);
            }
            return true;
        }

        return false;
    }
}
