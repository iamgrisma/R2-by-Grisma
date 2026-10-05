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
        $max_width = (int)($options['max_width'] ?? 1920);
        if ($engine === 'none') {
            $compress = false;
            $format = 'original';
            $max_width = 0;
        }
        $quality = (int)($options['quality'] ?? 82);

        $path_info = pathinfo($file_path);
        $current_ext = strtolower($path_info['extension'] ?? '');
        if ($current_ext === 'jpeg') {
            $current_ext = 'jpg';
        }

        if ($format === 'webp' && $current_ext !== 'webp' && !self::can_generate_webp()) {
            return array(
                'success'     => false,
                'file_path'   => $file_path,
                'mime'        => '',
                'width'       => 0,
                'height'      => 0,
                'bytes_saved' => 0,
                'message'     => __('WebP conversion is required but this server cannot create WebP images.', 'r2-by-grisma'),
            );
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

        $original_size = filesize($file_path);
        $resmush_result = null;
        $resmush_succeeded = false;

        // Send the source format to reSmush before server conversion. The API
        // does not accept WebP, so converting to WebP first forced every such
        // upload down the GD/Imagick fallback path.
        if ($engine === 'resmush' && $compress) {
            if ($original_size <= 5242880 && $original_size > 0) {
                $resmush_result = self::resmush_file($file_path, $quality);
            } else {
                $resmush_result = array(
                    'success' => false,
                    'status'  => 'exceeds_size',
                    'message' => __('Image exceeds 5MB reSmush.it limit. Processed via Server GD/Imagick fallback.', 'r2-by-grisma'),
                );
            }
            $resmush_succeeded = !empty($resmush_result['success']);
        }

        // Browser uploads already contain the requested format and dimensions.
        // Return without running a second server encode when that is true.
        if ($engine === 'browser' && !empty($options['client_compressed']) && !$needs_format_change) {
            return array('success' => true, 'file_path' => $file_path, 'mime' => '', 'width' => 0, 'height' => 0, 'bytes_saved' => 0);
        }

        // A successful API response is the complete operation when the source
        // already has the requested format and dimensions.
        $source_info = @getimagesize($file_path);
        $resize_required = $max_width > 0 && !empty($source_info[0]) && $source_info[0] > $max_width;
        if ($resmush_succeeded && !$needs_format_change && !$resize_required) {
            $mime = !empty($source_info['mime']) ? $source_info['mime'] : '';
            return array(
                'success'        => true,
                'file_path'      => $file_path,
                'mime'           => $mime,
                'width'          => (int)($source_info[0] ?? 0),
                'height'         => (int)($source_info[1] ?? 0),
                'bytes_saved'    => max(0, $original_size - filesize($file_path)),
                'resmush_result' => $resmush_result,
            );
        }

        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return array('success' => false, 'file_path' => $file_path, 'mime' => '', 'width' => 0, 'height' => 0, 'bytes_saved' => 0);
        }

        // Resize if larger than max width
        $size = $editor->get_size();
        $resize_required = $max_width > 0 && !empty($size['width']) && $size['width'] > $max_width;
        if ($compress && (!$resmush_succeeded || $needs_format_change || $resize_required)) {
            $editor->set_quality($quality);
        }
        if ($resize_required) {
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

        $new_size = file_exists($final_path) ? filesize($final_path) : $original_size;
        $final_size = $editor->get_size();
        $bytes_saved = max(0, $original_size - $new_size);

        return array(
            'success'        => true,
            'file_path'      => $final_path,
            'mime'           => $saved['mime-type'] ?? ($target_mime ?: ''),
            'width'          => $final_size['width'] ?? ($size['width'] ?? 0),
            'height'         => $final_size['height'] ?? ($size['height'] ?? 0),
            'bytes_saved'    => $bytes_saved,
            'resmush_result' => $resmush_result,
        );
    }

    /**
     * Compress an image using reSmush.it Free Web Service API
     * Enforces a strict 15s timeout, 5MB max size, and required Referer/User-Agent headers.
     * Returns detailed status array.
     *
     * @param string $file_path Absolute path to the file
     * @param int $quality Target quality 50-100
     * @return array
     */
    public static function resmush_file($file_path, $quality = 82) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return array('success' => false, 'status' => 'missing_file', 'message' => __('Local file not found.', 'r2-by-grisma'));
        }

        $size = filesize($file_path);
        if ($size > 5242880 || $size === 0) {
            return array('success' => false, 'status' => 'exceeds_size', 'message' => __('File exceeds 5MB reSmush limit.', 'r2-by-grisma'));
        }

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if ($ext === 'webp') {
            return array(
                'success' => false,
                'status'  => 'unsupported_format',
                'message' => __('reSmush.it API only supports JPG, PNG, GIF, BMP (not WebP). Processed via Server GD.', 'r2-by-grisma'),
            );
        }

        if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff'), true)) {
            return array('success' => false, 'status' => 'unsupported_format', 'message' => __('Format unsupported by reSmush.', 'r2-by-grisma'));
        }

        $boundary = wp_generate_password(24, false);
        $file_content = @file_get_contents($file_path);
        if ($file_content === false) {
            return array('success' => false, 'status' => 'read_error', 'message' => __('Could not read image file.', 'r2-by-grisma'));
        }

        $payload = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"files\"; filename=\"" . basename($file_path) . "\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $file_content . "\r\n"
            . "--{$boundary}--\r\n";

        $url = 'https://api.resmush.it/ws.php?qlty=' . max(50, min(100, $quality));
        $site_url = home_url();
        $response = wp_remote_post($url, array(
            'timeout'    => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . $site_url,
            'headers'    => array(
                'Referer'      => $site_url,
                'Content-Type' => "multipart/form-data; boundary={$boundary}",
            ),
            'body'       => $payload,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'status'  => 'network_error',
                'message' => sprintf(__('reSmush.it network error: %s. Using Server GD fallback.', 'r2-by-grisma'), $response->get_error_message()),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);

        if ($code !== 200 || !empty($data['error'])) {
            $err_msg = $data['error_long'] ?? ($data['message'] ?? 'API error ' . $code);
            return array(
                'success' => false,
                'status'  => 'api_error',
                'message' => sprintf(__('reSmush.it returned: %s. Using Server GD fallback.', 'r2-by-grisma'), $err_msg),
            );
        }

        if (empty($data['dest'])) {
            return array('success' => false, 'status' => 'no_dest', 'message' => __('No destination returned by reSmush.', 'r2-by-grisma'));
        }

        $compressed_img = wp_remote_get($data['dest'], array(
            'timeout'    => 15,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . $site_url,
            'headers'    => array('Referer' => $site_url),
        ));

        if (is_wp_error($compressed_img) || wp_remote_retrieve_response_code($compressed_img) !== 200) {
            return array('success' => false, 'status' => 'download_error', 'message' => __('Failed to download reSmush optimized image.', 'r2-by-grisma'));
        }

        $new_bytes = wp_remote_retrieve_body($compressed_img);
        if (strlen($new_bytes) > 0 && strlen($new_bytes) < $size) {
            if (@file_put_contents($file_path, $new_bytes) === false) {
                return array('success' => false, 'status' => 'write_error', 'message' => __('Could not save reSmush optimized image; using the server processed image.', 'r2-by-grisma'));
            }
            return array(
                'success' => true,
                'status'  => 'success',
                'message' => sprintf(__('Optimized via reSmush.it API (-%d%% savings).', 'r2-by-grisma'), $data['percent'] ?? 0),
                'percent' => (int)($data['percent'] ?? 0),
            );
        }

        return array(
            'success' => false,
            'status'  => 'already_optimal',
            'message' => __('reSmush reported image is already optimal; Server GD copy preserved.', 'r2-by-grisma'),
        );
    }
}
