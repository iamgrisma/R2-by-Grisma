<?php
/**
 * Image Optimizer Engine
 *
 * @package R2_By_Grisma
 */

if (!defined('ABSPATH')) {
    exit;
}

class R2G_Optimizer {
    const RESMUSH_ENDPOINT  = 'https://api.resmush.it/';
    const RESMUSH_MAX_BYTES = 5242880;
    const RESMUSH_TIMEOUT   = 60;

    /**
     * Normalize engine slug
     *
     * @param string $engine
     * @return string
     */
    public static function normalize_engine($engine) {
        $engine = sanitize_key((string) $engine);
        if ($engine === 'browser' || $engine === '') {
            return 'resmush';
        }
        if (!in_array($engine, array('resmush', 'server', 'none'), true)) {
            return 'resmush';
        }
        return $engine;
    }

    /**
     * Site default engine
     *
     * @return string
     */
    public static function get_default_engine() {
        return self::normalize_engine(get_option('r2g_compress_engine', 'resmush'));
    }

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
     * Build a failure array
     */
    private static function fail($file_path, $message = '', $extra = array()) {
        return array_merge(array(
            'success'     => false,
            'file_path'   => $file_path,
            'mime'        => '',
            'width'       => 0,
            'height'      => 0,
            'bytes_saved' => 0,
            'message'     => $message,
        ), $extra);
    }

    /**
     * Get engine execution summary
     *
     * @param string     $engine
     * @param array|null $resmush_result
     * @param bool       $gd_ran
     * @return array
     */
    private static function describe_engine($engine, $resmush_result, $gd_ran) {
        if ($engine === 'none') {
            return array('engine_used' => 'none', 'engine_note' => __('Raw offload (no processing).', 'r2-by-grisma'));
        }
        if ($engine !== 'resmush') {
            return array('engine_used' => 'server', 'engine_note' => __('Optimized with Server GD/Imagick.', 'r2-by-grisma'));
        }
        if (is_array($resmush_result) && !empty($resmush_result['success'])) {
            $note = !empty($resmush_result['message']) ? $resmush_result['message'] : __('Optimized via reSmush.it.', 'r2-by-grisma');
            if ($gd_ran) {
                $note .= ' ' . __('Final format conversion by Server GD/Imagick.', 'r2-by-grisma');
            }
            return array('engine_used' => 'resmush', 'engine_note' => $note);
        }
        $reason = (is_array($resmush_result) && !empty($resmush_result['message'])) ? $resmush_result['message'] : __('reSmush.it was not used.', 'r2-by-grisma');
        return array(
            'engine_used' => 'server',
            'engine_note' => sprintf(__('Fallback to Server GD/Imagick. %s', 'r2-by-grisma'), $reason),
        );
    }

    /**
     * Compress and optionally convert an image file.
     *
     * @param string $file_path Absolute path to the image
     * @param array $options
     * @return array
     */
    public static function optimize_local_file($file_path, $options = array()) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return self::fail($file_path);
        }

        $engine = self::normalize_engine($options['engine'] ?? get_option('r2g_compress_engine', 'resmush'));
        $format = strtolower($options['format'] ?? 'webp');
        if ($format === 'jpeg') {
            $format = 'jpg';
        }
        $compress = isset($options['compress']) ? (bool) $options['compress'] : true;
        $max_width = (int) ($options['max_width'] ?? 1920);
        if ($engine === 'none') {
            $compress = false;
            $format = 'original';
            $max_width = 0;
        }
        $quality = max(50, min(100, (int) ($options['quality'] ?? 82)));

        $path_info = pathinfo($file_path);
        $current_ext = strtolower($path_info['extension'] ?? '');
        if ($current_ext === 'jpeg') {
            $current_ext = 'jpg';
        }

        if ($format === 'webp' && $current_ext !== 'webp' && !self::can_generate_webp()) {
            return self::fail($file_path, __('WebP conversion is required but this server cannot create WebP images.', 'r2-by-grisma'));
        }

        $needs_format_change = false;
        if ($format === 'webp' && $current_ext !== 'webp') {
            $needs_format_change = true;
        } elseif ($format === 'jpg' && $current_ext !== 'jpg') {
            $needs_format_change = true;
        } elseif ($format === 'png' && $current_ext !== 'png') {
            $needs_format_change = true;
        } elseif ($format === 'avif' && $current_ext !== 'avif' && self::can_generate_avif()) {
            $needs_format_change = true;
        }

        if (!$needs_format_change && !$compress && $max_width <= 0) {
            $d = self::describe_engine($engine, null, false);
            return array('success' => true, 'file_path' => $file_path, 'mime' => '', 'width' => 0, 'height' => 0, 'bytes_saved' => 0, 'engine_used' => $d['engine_used'], 'engine_note' => $d['engine_note'], 'resmush_result' => null);
        }

        $original_size = filesize($file_path);
        $resmush_result = null;
        $resmush_succeeded = false;

        if ($engine === 'resmush' && $compress) {
            $resmush_result = self::resmush_stage($file_path, $quality, $max_width, $needs_format_change);
            $resmush_succeeded = !empty($resmush_result['success']);
        }

        clearstatcache(true, $file_path);
        $source_info = @getimagesize($file_path);
        $resize_required = $max_width > 0 && !empty($source_info[0]) && $source_info[0] > $max_width;
        if ($resmush_succeeded && !$needs_format_change && !$resize_required) {
            $d = self::describe_engine($engine, $resmush_result, false);
            return array(
                'success'        => true,
                'file_path'      => $file_path,
                'mime'           => !empty($source_info['mime']) ? $source_info['mime'] : '',
                'width'          => (int) ($source_info[0] ?? 0),
                'height'         => (int) ($source_info[1] ?? 0),
                'bytes_saved'    => max(0, $original_size - filesize($file_path)),
                'engine_used'    => $d['engine_used'],
                'engine_note'    => $d['engine_note'],
                'resmush_result' => $resmush_result,
            );
        }

        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return self::fail($file_path, $editor->get_error_message(), array('resmush_result' => $resmush_result));
        }

        if ($current_ext === 'jpg' && method_exists($editor, 'maybe_exif_rotate')) {
            $editor->maybe_exif_rotate();
        }

        $size = $editor->get_size();
        $resize_required = $max_width > 0 && !empty($size['width']) && $size['width'] > $max_width;
        if ($compress) {
            $editor->set_quality($quality);
        }
        if ($resize_required) {
            $editor->resize($max_width, null, false);
        }

        // Determine target mime and filename
        $dirname = $path_info['dirname'];
        $clean_filename = preg_replace('/-scaled$/i', '', $path_info['filename']);

        $target_mime = null;
        $target_ext = null;
        if ($format === 'webp') {
            $target_mime = 'image/webp';
            $target_ext = 'webp';
        } elseif ($format === 'jpg') {
            $target_mime = 'image/jpeg';
            $target_ext = 'jpg';
        } elseif ($format === 'png') {
            $target_mime = 'image/png';
            $target_ext = 'png';
        } elseif ($format === 'avif' && self::can_generate_avif()) {
            $target_mime = 'image/avif';
            $target_ext = 'avif';
        }

        $target_file = $file_path;
        if ($target_ext) {
            $target_name = $clean_filename . '.' . $target_ext;
            if (file_exists($dirname . '/' . $target_name) && ($dirname . '/' . $target_name) !== $file_path) {
                $target_name = wp_unique_filename($dirname, $target_name);
            }
            $target_file = $dirname . '/' . $target_name;
        }

        $saved = $editor->save($target_file, $target_mime);
        if (is_wp_error($saved)) {
            return self::fail($file_path, $saved->get_error_message(), array('resmush_result' => $resmush_result));
        }

        $final_path = $saved['path'] ?? $target_file;

        // If converted to a new format/file and distinct from original, delete old original file
        if ($final_path !== $file_path && file_exists($file_path)) {
            @unlink($file_path);
        }

        $new_size = file_exists($final_path) ? filesize($final_path) : $original_size;
        $final_size = $editor->get_size();
        $d = self::describe_engine($engine, $resmush_result, true);

        return array(
            'success'        => true,
            'file_path'      => $final_path,
            'mime'           => $saved['mime-type'] ?? ($target_mime ?: ''),
            'width'          => $final_size['width'] ?? ($size['width'] ?? 0),
            'height'         => $final_size['height'] ?? ($size['height'] ?? 0),
            'bytes_saved'    => max(0, $original_size - $new_size),
            'engine_used'    => $d['engine_used'],
            'engine_note'    => $d['engine_note'],
            'resmush_result' => $resmush_result,
        );
    }

    /**
     * Read EXIF orientation of a JPEG (1 when unknown).
     *
     * @param string $file_path
     * @return int
     */
    private static function get_exif_orientation($file_path) {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($file_path);
        return (is_array($exif) && !empty($exif['Orientation'])) ? (int) $exif['Orientation'] : 1;
    }

    /**
     * Process image via reSmush.it
     *
     * @param string $file_path
     * @param int    $quality
     * @param int    $max_width
     * @param bool   $will_convert
     * @return array
     */
    private static function resmush_stage($file_path, $quality, $max_width, $will_convert) {
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff'), true)) {
            return array(
                'success' => false,
                'status'  => 'unsupported_format',
                'message' => sprintf(__('%s files cannot be sent to reSmush.it (it accepts JPG, PNG, GIF, BMP, TIFF only).', 'r2-by-grisma'), strtoupper($ext)),
            );
        }

        $size = filesize($file_path);
        $info = @getimagesize($file_path);
        $too_wide = $max_width > 0 && !empty($info[0]) && $info[0] > $max_width;
        $is_jpeg = in_array($ext, array('jpg', 'jpeg'), true);
        $needs_rotate = $is_jpeg && self::get_exif_orientation($file_path) > 1;
        $too_big = $size > self::RESMUSH_MAX_BYTES;

        if (($too_wide || $needs_rotate || $too_big) && $ext !== 'gif') {
            self::prepare_for_resmush($file_path, $max_width, $too_wide, $is_jpeg, $too_wide || $needs_rotate);
            clearstatcache(true, $file_path);
            $size = filesize($file_path);
        }

        if ($size > self::RESMUSH_MAX_BYTES || $size === 0) {
            return array(
                'success' => false,
                'status'  => 'exceeds_size',
                'message' => __('Image is still above the 5 MB reSmush.it limit after resizing.', 'r2-by-grisma'),
            );
        }

        $api_quality = $will_convert ? max($quality, 92) : $quality;
        return self::resmush_file($file_path, $api_quality);
    }

    /**
     * Downscale or orient image prior to API upload
     */
    private static function prepare_for_resmush($file_path, $max_width, $too_wide, $is_jpeg, $force = false) {
        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return false;
        }
        if ($is_jpeg && method_exists($editor, 'maybe_exif_rotate')) {
            $editor->maybe_exif_rotate();
        }
        if ($too_wide) {
            $editor->resize($max_width, null, false);
        }
        $editor->set_quality(92);

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $tmp = dirname($file_path) . '/r2g-prep-' . wp_generate_password(8, false, false) . '.' . $ext;
        $saved = $editor->save($tmp);
        if (is_wp_error($saved) || empty($saved['path']) || !file_exists($saved['path'])) {
            return false;
        }
        if (!$force && filesize($saved['path']) >= filesize($file_path)) {
            @unlink($saved['path']);
            return false;
        }
        if (@rename($saved['path'], $file_path)) {
            return true;
        }
        @unlink($saved['path']);
        return false;
    }

    /**
     * Send file to reSmush.it API
     *
     * @param string $file_path
     * @param int    $quality
     * @return array
     */
    public static function resmush_file($file_path, $quality = 82) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return array('success' => false, 'status' => 'missing_file', 'message' => __('Local file not found.', 'r2-by-grisma'));
        }

        $size = filesize($file_path);
        if ($size > self::RESMUSH_MAX_BYTES || $size === 0) {
            return array('success' => false, 'status' => 'exceeds_size', 'message' => __('File exceeds the 5 MB reSmush.it limit.', 'r2-by-grisma'));
        }

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $mimes = array(
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'bmp'  => 'image/bmp',
            'tif'  => 'image/tiff',
            'tiff' => 'image/tiff',
        );
        if (!isset($mimes[$ext])) {
            return array(
                'success' => false,
                'status'  => 'unsupported_format',
                'message' => sprintf(__('%s files cannot be sent to reSmush.it (it accepts JPG, PNG, GIF, BMP, TIFF only).', 'r2-by-grisma'), strtoupper($ext)),
            );
        }

        $file_content = @file_get_contents($file_path);
        if ($file_content === false) {
            return array('success' => false, 'status' => 'read_error', 'message' => __('Could not read image file.', 'r2-by-grisma'));
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $boundary = wp_generate_password(24, false);
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file_path));
        $payload = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"files\"; filename=\"{$filename}\"\r\n"
            . "Content-Type: {$mimes[$ext]}\r\n\r\n"
            . $file_content . "\r\n"
            . "--{$boundary}--\r\n";
        unset($file_content);

        $url = add_query_arg('qlty', max(50, min(100, (int) $quality)), self::RESMUSH_ENDPOINT);
        $site_url = home_url();
        $user_agent = 'WordPress/' . get_bloginfo('version') . '; ' . $site_url;

        $data = null;
        $last_error = '';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = wp_remote_post($url, array(
                'timeout'    => self::RESMUSH_TIMEOUT,
                'user-agent' => $user_agent,
                'headers'    => array(
                    'Referer'      => $site_url,
                    'Content-Type' => "multipart/form-data; boundary={$boundary}",
                ),
                'body'       => $payload,
            ));

            if (is_wp_error($response)) {
                $last_error = sprintf(__('network error (%s)', 'r2-by-grisma'), $response->get_error_message());
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $decoded = json_decode(wp_remote_retrieve_body($response), true);

            if ($code >= 500 || !is_array($decoded)) {
                $last_error = sprintf(__('HTTP %d', 'r2-by-grisma'), $code);
                continue;
            }

            if (!empty($decoded['error'])) {
                $err_code = is_numeric($decoded['error']) ? (int) $decoded['error'] : 0;
                $err_msg = $decoded['error_long'] ?? ($decoded['message'] ?? __('unknown error', 'r2-by-grisma'));
                $last_error = $err_msg;
                if (in_array($err_code, array(501, 503, 504), true)) {
                    continue;
                }
                return array(
                    'success' => false,
                    'status'  => 'api_error',
                    'message' => sprintf(__('reSmush.it refused the image: %s.', 'r2-by-grisma'), $err_msg),
                );
            }

            $data = $decoded;
            break;
        }

        if ($data === null) {
            return array(
                'success' => false,
                'status'  => 'network_error',
                'message' => sprintf(__('reSmush.it is unreachable or too slow: %s.', 'r2-by-grisma'), $last_error),
            );
        }

        if (empty($data['dest'])) {
            return array('success' => false, 'status' => 'no_dest', 'message' => __('reSmush.it returned no optimized file.', 'r2-by-grisma'));
        }

        // Validate reSmush.it destination domain to prevent SSRF
        $parsed_dest = wp_parse_url($data['dest']);
        if (empty($parsed_dest['host']) || !preg_match('/(^|\.)resmush\.it$/i', $parsed_dest['host'])) {
            return array(
                'success' => false,
                'status'  => 'untrusted_host',
                'message' => __('Untrusted file source returned by optimization API.', 'r2-by-grisma'),
            );
        }

        $new_bytes = '';
        $download_urls = array($data['dest']);
        if (strpos($data['dest'], 'http://') === 0) {
            $download_urls[] = 'https://' . substr($data['dest'], 7);
        }
        foreach ($download_urls as $dl_url) {
            $compressed_img = wp_remote_get($dl_url, array(
                'timeout'            => 30,
                'user-agent'         => $user_agent,
                'reject_unsafe_urls' => true,
                'headers'            => array('Referer' => $site_url),
            ));
            if (!is_wp_error($compressed_img) && (int) wp_remote_retrieve_response_code($compressed_img) === 200) {
                $new_bytes = wp_remote_retrieve_body($compressed_img);
                if ($new_bytes !== '' && @getimagesizefromstring($new_bytes) !== false) {
                    break;
                }
                $new_bytes = '';
            }
        }

        if ($new_bytes === '') {
            return array('success' => false, 'status' => 'download_error', 'message' => __('Could not download the optimized image from reSmush.it.', 'r2-by-grisma'));
        }

        $new_len = strlen($new_bytes);
        if ($new_len < $size) {
            if (@file_put_contents($file_path, $new_bytes) === false) {
                return array('success' => false, 'status' => 'write_error', 'message' => __('Could not save the reSmush.it result to disk.', 'r2-by-grisma'));
            }
            $percent = (int) round((($size - $new_len) / $size) * 100);
            return array(
                'success' => true,
                'status'  => 'success',
                'message' => sprintf(__('Optimized via reSmush.it (-%d%%).', 'r2-by-grisma'), $percent),
                'percent' => $percent,
            );
        }

        return array(
            'success' => true,
            'status'  => 'already_optimal',
            'message' => __('reSmush.it: image was already optimal.', 'r2-by-grisma'),
            'percent' => 0,
        );
    }
}

