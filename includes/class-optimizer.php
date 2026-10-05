<?php
/**
 * Image Optimizer Engine
 * Handles On-Site WebP Conversion and Compression via PHP GD / Imagick.
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
     * Check if server has AVIF generation support
     *
     * @return bool
     */
    public static function can_generate_avif() {
        if (function_exists('wp_image_editor_supports')) {
            return wp_image_editor_supports(array('methods' => array('rotate', 'resize', 'save'), 'mime_type' => 'image/avif'));
        }
        if (function_exists('imagecreatefromavif') && function_exists('imageavif')) {
            return true;
        }
        return false;
    }

    /**
     * Get list of supported conversion formats
     *
     * @return array
     */
    public static function get_supported_formats() {
        $formats = array(
            'webp'     => 'WebP (' . (self::can_generate_webp() ? __('Supported', 'r2-by-grisma') : __('Unsupported', 'r2-by-grisma')) . ')',
            'jpg'      => 'JPEG / JPG',
            'png'      => 'PNG',
            'original' => __('Preserve Original', 'r2-by-grisma'),
        );
        if (self::can_generate_avif()) {
            $formats['avif'] = 'AVIF (' . __('Supported', 'r2-by-grisma') . ')';
        }
        return $formats;
    }

    /**
     * Compress and optionally convert an image file on the local server
     *
     * @param string $file_path Absolute path to the image
     * @param array $options [ 'format' => 'webp'|'jpg'|'jpeg'|'png'|'original', 'quality' => 82, 'max_width' => 1920, 'compress' => bool ]
     * @return array [ 'success' => bool, 'file_path' => string, 'mime' => string, 'width' => int, 'height' => int, 'bytes_saved' => int ]
     */
    public static function optimize_local_file($file_path, $options = array()) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return array('success' => false, 'file_path' => $file_path, 'mime' => '', 'width' => 0, 'height' => 0, 'bytes_saved' => 0);
        }

        $engine = $options['engine'] ?? get_option('r2g_compress_engine', 'server');
        $format = strtolower($options['format'] ?? 'webp');
        if ($format === 'jpeg') {
            $format = 'jpg';
        }
        $compress = isset($options['compress']) ? (bool)$options['compress'] : true;
        if ($engine === 'none') {
            $compress = false;
        }
        $quality = (int)($options['quality'] ?? 82);
        $max_width = (int)($options['max_width'] ?? 1920);

        $path_info = pathinfo($file_path);
        $current_ext = strtolower($path_info['extension'] ?? '');
        if ($current_ext === 'jpeg') {
            $current_ext = 'jpg';
        }

        // Determine if format conversion is requested and differs from current
        $needs_format_change = false;
        if ($format === 'webp' && $current_ext !== 'webp' && self::can_generate_webp()) {
            $needs_format_change = true;
        } elseif ($format === 'jpg' && $current_ext !== 'jpg') {
            $needs_format_change = true;
        } elseif ($format === 'png' && $current_ext !== 'png') {
            $needs_format_change = true;
        } elseif ($format === 'avif' && $current_ext !== 'avif' && self::can_generate_avif()) {
            $needs_format_change = true;
        }

        // If no format change, no compression, and no resize, nothing to do
        if (!$needs_format_change && !$compress && $max_width <= 0) {
            return array('success' => true, 'file_path' => $file_path, 'mime' => '', 'width' => 0, 'height' => 0, 'bytes_saved' => 0);
        }

        // If browser already pre-compressed and no format conversion needed
        if ($engine === 'browser' && !empty($options['client_compressed']) && !$needs_format_change) {
            return array('success' => true, 'file_path' => $file_path, 'mime' => '', 'width' => 0, 'height' => 0, 'bytes_saved' => 0);
        }

        $original_size = filesize($file_path);

        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return array('success' => false, 'file_path' => $file_path, 'mime' => '', 'width' => 0, 'height' => 0, 'bytes_saved' => 0);
        }

        // Set compression quality for server / fallback
        if ($compress) {
            $editor->set_quality($quality);
        }

        // Resize if larger than max width
        $size = $editor->get_size();
        if ($max_width > 0 && !empty($size['width']) && $size['width'] > $max_width) {
            $editor->resize($max_width, null, false);
        }

        // Determine target mime and filename
        $dirname = $path_info['dirname'];
        $clean_filename = preg_replace('/-scaled$/i', '', $path_info['filename']);

        $target_mime = null;
        $target_file = $file_path;

        if ($format === 'webp' && self::can_generate_webp()) {
            $target_mime = 'image/webp';
            $target_name = $clean_filename . '.webp';
            if (file_exists($dirname . '/' . $target_name) && ($dirname . '/' . $target_name) !== $file_path) {
                $target_name = wp_unique_filename($dirname, $target_name);
            }
            $target_file = $dirname . '/' . $target_name;
        } elseif ($format === 'jpg') {
            $target_mime = 'image/jpeg';
            $target_name = $clean_filename . '.jpg';
            if (file_exists($dirname . '/' . $target_name) && ($dirname . '/' . $target_name) !== $file_path) {
                $target_name = wp_unique_filename($dirname, $target_name);
            }
            $target_file = $dirname . '/' . $target_name;
        } elseif ($format === 'png') {
            $target_mime = 'image/png';
            $target_name = $clean_filename . '.png';
            if (file_exists($dirname . '/' . $target_name) && ($dirname . '/' . $target_name) !== $file_path) {
                $target_name = wp_unique_filename($dirname, $target_name);
            }
            $target_file = $dirname . '/' . $target_name;
        } elseif ($format === 'avif' && self::can_generate_avif()) {
            $target_mime = 'image/avif';
            $target_name = $clean_filename . '.avif';
            if (file_exists($dirname . '/' . $target_name) && ($dirname . '/' . $target_name) !== $file_path) {
                $target_name = wp_unique_filename($dirname, $target_name);
            }
            $target_file = $dirname . '/' . $target_name;
        }

        $saved = $editor->save($target_file, $target_mime);
        if (is_wp_error($saved)) {
            return array('success' => false, 'file_path' => $file_path, 'mime' => '', 'width' => 0, 'height' => 0, 'bytes_saved' => 0);
        }

        $final_path = $saved['path'] ?? $target_file;

        // If converted to a new format/file and distinct from original, delete old original file
        if ($final_path !== $file_path && file_exists($file_path)) {
            @unlink($file_path);
        }

        // If reSmush.it engine is selected, attempt reSmush compression with automatic fallback
        if ($engine === 'resmush' && $compress && file_exists($final_path)) {
            $current_file_size = filesize($final_path);
            // reSmush.it limit: max 5MB (5242880 bytes)
            if ($current_file_size <= 5242880 && $current_file_size > 0) {
                self::resmush_file($final_path, $quality);
            }
            // If > 5MB, GD/Imagick set_quality has already processed it safely as fallback!
        }

        $new_size = file_exists($final_path) ? filesize($final_path) : $original_size;
        $final_size = $editor->get_size();
        $bytes_saved = max(0, $original_size - $new_size);

        return array(
            'success'     => true,
            'file_path'   => $final_path,
            'mime'        => $saved['mime-type'] ?? ($target_mime ?: ''),
            'width'       => $final_size['width'] ?? ($size['width'] ?? 0),
            'height'      => $final_size['height'] ?? ($size['height'] ?? 0),
            'bytes_saved' => $bytes_saved,
        );
    }

    /**
     * Compress an image using reSmush.it Free Web Service API
     * Enforces a strict 15s timeout and 5MB max size.
     * Returns true if successfully compressed to a smaller file, false on failure or fallback.
     *
     * @param string $file_path Absolute path to the file
     * @param int $quality Target quality 50-100
     * @return bool
     */
    public static function resmush_file($file_path, $quality = 82) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        $size = filesize($file_path);
        if ($size > 5242880 || $size === 0) {
            return false; // Exceeds 5MB reSmush limit; fallback to GD/Imagick
        }

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'), true)) {
            return false;
        }

        $boundary = wp_generate_password(24, false);
        $file_content = @file_get_contents($file_path);
        if ($file_content === false) {
            return false;
        }

        $payload = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"files\"; filename=\"" . basename($file_path) . "\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $file_content . "\r\n"
            . "--{$boundary}--\r\n";

        $url = 'http://api.resmush.it/ws.php?qlty=' . max(50, min(100, $quality));
        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => "multipart/form-data; boundary={$boundary}",
            ),
            'body'    => $payload,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false; // Network error or API down; fallback to GD/Imagick
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['dest'])) {
            return false;
        }

        $compressed_img = wp_remote_get($data['dest'], array('timeout' => 15));
        if (is_wp_error($compressed_img) || wp_remote_retrieve_response_code($compressed_img) !== 200) {
            return false;
        }

        $new_bytes = wp_remote_retrieve_body($compressed_img);
        if (strlen($new_bytes) > 0 && strlen($new_bytes) < $size) {
            @file_put_contents($file_path, $new_bytes);
            return true;
        }

        return false;
    }
}
