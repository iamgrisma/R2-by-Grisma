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
     * Compress and optionally convert an image file on the local server
     *
     * @param string $file_path Absolute path to the image
     * @param array $options [ 'format' => 'webp'|'original'|'none', 'quality' => 82, 'max_width' => 1920, 'compress' => bool ]
     * @return array [ 'success' => bool, 'file_path' => string, 'mime' => string, 'width' => int, 'height' => int, 'bytes_saved' => int ]
     */
    public static function optimize_local_file($file_path, $options = array()) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return array('success' => false, 'file_path' => $file_path, 'mime' => '', 'width' => 0, 'height' => 0, 'bytes_saved' => 0);
        }

        $format = $options['format'] ?? 'webp';
        $compress = isset($options['compress']) ? (bool)$options['compress'] : true;
        $quality = (int)($options['quality'] ?? 82);
        $max_width = (int)($options['max_width'] ?? 1920);

        // If compression is disabled and format is not webp, nothing to do
        if (!$compress && $format !== 'webp') {
            return array('success' => true, 'file_path' => $file_path, 'mime' => '', 'width' => 0, 'height' => 0, 'bytes_saved' => 0);
        }

        $original_size = filesize($file_path);

        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor)) {
            return array('success' => false, 'file_path' => $file_path, 'mime' => '', 'width' => 0, 'height' => 0, 'bytes_saved' => 0);
        }

        // Set compression quality
        if ($compress) {
            $editor->set_quality($quality);
        }

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
            $target_name = $clean_filename . '.webp';
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
        $new_size = file_exists($final_path) ? filesize($final_path) : $original_size;

        // If converted to webp and distinct from original, delete original file
        if ($final_path !== $file_path && file_exists($file_path)) {
            @unlink($file_path);
        }

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
}
