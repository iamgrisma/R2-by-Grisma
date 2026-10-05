<?php
/**
 * Universal URL & Srcset Rewriter
 * Seamlessly replaces local WordPress upload URLs with Cloudflare R2 Custom CDN Domain.
 * Ensures image preview URLs and thumbnails in WordPress Admin and Frontend load from CDN.
 *
 * @package R2_By_Grisma
 */

if (!defined('ABSPATH')) {
    exit;
}

class R2G_URL_Rewriter {
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
        // Only hook if custom domain is configured
        $domain = get_option('r2g_custom_domain', '');
        if (empty($domain)) {
            return;
        }

        // 1. Core attachment URL filter
        add_filter('wp_get_attachment_url', array($this, 'filter_attachment_url'), 20, 2);

        // 2. Downsized image / thumbnail generation filter
        add_filter('image_downsize', array($this, 'filter_image_downsize'), 20, 3);

        // 3. Image src array filter (fallback for wp_get_attachment_image_src)
        add_filter('wp_get_attachment_image_src', array($this, 'filter_attachment_image_src'), 20, 4);

        // 4. Thumb URL filter
        add_filter('wp_get_attachment_thumb_url', array($this, 'filter_thumb_url'), 20, 2);

        // 5. Responsive srcset filter
        add_filter('wp_calculate_image_srcset', array($this, 'filter_srcset'), 20, 5);

        // 6. Media Library Grid view, Edit modal, and Gutenberg attachment preparation
        add_filter('wp_prepare_attachment_for_js', array($this, 'filter_attachment_for_js'), 20, 3);

        // 7. Headless REST API attachment preparation
        add_filter('rest_prepare_attachment', array($this, 'filter_rest_attachment'), 20, 3);

        // 8. Admin Post Thumbnail preview (Featured Image metabox)
        add_filter('admin_post_thumbnail_html', array($this, 'filter_admin_thumbnail_html'), 20, 3);

        // 9. Traditional frontend theme post content rewriter
        if (!is_admin()) {
            add_filter('the_content', array($this, 'filter_content_urls'), 20);
        }
    }

    /**
     * Get configured CDN Domain base URL
     *
     * @return string
     */
    public function get_cdn_base() {
        $domain = get_option('r2g_custom_domain', '');
        return rtrim($domain, '/');
    }

    /**
     * Determine if an attachment should be served from Cloudflare R2 CDN
     * Multi-layer check: postmeta, database index, missing local file, or legacy offload plugins.
     *
     * @param int $post_id
     * @return bool
     */
    public function should_rewrite_attachment($post_id) {
        $cdn_base = $this->get_cdn_base();
        if (empty($cdn_base)) {
            return false;
        }

        // Global rewrite toggle (default: true)
        if (get_option('r2g_rewrite_urls', 1) == 0) {
            return false;
        }

        // 1. Native R2 by Grisma postmeta
        if ((bool) get_post_meta($post_id, '_r2g_synced', true)) {
            return true;
        }

        // 2. Custom Database Index table
        if (class_exists('R2G_Database')) {
            $record = R2G_Database::get($post_id);
            if ($record && $record->status === 'synced') {
                return true;
            }
        }

        // 3. Local file missing on server disk (Must be served from CDN!)
        $file_path = get_attached_file($post_id);
        if (!empty($file_path) && !file_exists($file_path)) {
            return true;
        }

        // 4. Legacy cloud sync plugins (e.g. Media Cloud Sync, WP Offload Media)
        if (
            get_post_meta($post_id, '_mcs_synced', true) ||
            get_post_meta($post_id, '_media_cloud_sync_synced', true) ||
            get_post_meta($post_id, '_cloud_url', true) ||
            get_post_meta($post_id, '_amazonS3_info', true)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Rewrite any WordPress uploads URL to Custom R2 CDN URL
     *
     * @param string $url
     * @return string
     */
    public function rewrite_url($url) {
        if (empty($url) || !is_string($url)) {
            return $url;
        }

        $cdn_base = $this->get_cdn_base();
        if (empty($cdn_base)) {
            return $url;
        }

        // Already pointing to custom CDN
        if (strpos($url, $cdn_base) === 0) {
            return $url;
        }

        $uploads = wp_upload_dir();
        $baseurl = $uploads['baseurl'];

        // Standard uploads replace
        if (strpos($url, $baseurl) === 0) {
            $rel = substr($url, strlen($baseurl));
            return $cdn_base . '/wp-content/uploads' . $rel;
        }

        // Host replacement fallback for wp-content/uploads
        $parsed = parse_url($url);
        if (!empty($parsed['path']) && strpos($parsed['path'], '/wp-content/uploads/') !== false) {
            return $cdn_base . $parsed['path'];
        }

        return $url;
    }

    /**
     * Filter attachment URL
     *
     * @param string $url
     * @param int $post_id
     * @return string
     */
    public function filter_attachment_url($url, $post_id) {
        if ($this->should_rewrite_attachment($post_id)) {
            return $this->rewrite_url($url);
        }
        return $url;
    }

    /**
     * Filter image downsize (Core hook for all thumbnails and responsive image tags)
     * Intercepts and returns CDN URL with proper dimensions so admin preview never breaks.
     *
     * @param bool|array $downsize
     * @param int $id
     * @param string|array $size
     * @return bool|array
     */
    public function filter_image_downsize($downsize, $id, $size) {
        if (!$this->should_rewrite_attachment($id)) {
            return $downsize;
        }

        // If another filter already produced an array, rewrite its URL to CDN
        if (is_array($downsize) && !empty($downsize[0])) {
            $downsize[0] = $this->rewrite_url($downsize[0]);
            return $downsize;
        }

        // WordPress core passed false — compute downsized CDN URL directly
        $img_url = wp_get_attachment_url($id);
        if (empty($img_url)) {
            return $downsize;
        }

        $meta = wp_get_attachment_metadata($id);
        $width = 0;
        $height = 0;
        $is_intermediate = false;

        $upload_sizes_mode = get_option('r2g_upload_sizes', 'all');
        $uploaded_keys = get_post_meta($id, '_r2g_keys', true);
        $has_uploaded_thumbs = is_array($uploaded_keys) && count($uploaded_keys) > 1;

        if ($upload_sizes_mode === 'all' && $has_uploaded_thumbs && is_string($size) && !empty($meta['sizes'][$size]['file'])) {
            $data = $meta['sizes'][$size];
            $img_url = path_join(dirname($img_url), $data['file']);
            $width = isset($data['width']) ? $data['width'] : 0;
            $height = isset($data['height']) ? $data['height'] : 0;
            $is_intermediate = true;
        } elseif (is_string($size) && !empty($meta['sizes'][$size])) {
            // Thumbnails were not uploaded to R2 (or original-only mode): serve main CDN image with requested dimensions so it never 404s
            $data = $meta['sizes'][$size];
            $width = isset($data['width']) ? $data['width'] : 0;
            $height = isset($data['height']) ? $data['height'] : 0;
            $is_intermediate = true;
        } elseif (is_array($size)) {
            $width = isset($size[0]) ? $size[0] : 0;
            $height = isset($size[1]) ? $size[1] : 0;
            $is_intermediate = true;
        } else {
            $width = isset($meta['width']) ? $meta['width'] : 0;
            $height = isset($meta['height']) ? $meta['height'] : 0;
        }

        $cdn_url = $this->rewrite_url($img_url);
        return array($cdn_url, $width, $height, $is_intermediate);
    }

    /**
     * Filter wp_get_attachment_image_src output
     *
     * @param array|false $image
     * @param int $attachment_id
     * @param string|int[] $size
     * @param bool $icon
     * @return array|false
     */
    public function filter_attachment_image_src($image, $attachment_id, $size, $icon) {
        if (is_array($image) && !empty($image[0]) && $this->should_rewrite_attachment($attachment_id)) {
            $image[0] = $this->rewrite_url($image[0]);
        }
        return $image;
    }

    /**
     * Filter wp_get_attachment_thumb_url
     *
     * @param string $url
     * @param int $post_id
     * @return string
     */
    public function filter_thumb_url($url, $post_id) {
        if ($this->should_rewrite_attachment($post_id)) {
            return $this->rewrite_url($url);
        }
        return $url;
    }

    /**
     * Filter responsive image srcset array
     *
     * @param array $sources
     * @param array $size_array
     * @param string $image_src
     * @param array $image_meta
     * @param int $attachment_id
     * @return array
     */
    public function filter_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (empty($sources) || !is_array($sources) || !$this->should_rewrite_attachment($attachment_id)) {
            return $sources;
        }

        $uploaded_keys = get_post_meta($attachment_id, '_r2g_keys', true);
        $has_uploaded_thumbs = is_array($uploaded_keys) && count($uploaded_keys) > 1;

        if (!$has_uploaded_thumbs) {
            // Do not output srcset pointing to non-existent thumb sizes on R2
            return array();
        }

        foreach ($sources as $width => &$data) {
            if (!empty($data['url'])) {
                $data['url'] = $this->rewrite_url($data['url']);
            }
        }

        return $sources;
    }

    /**
     * Filter WordPress attachment payload for JS (Media Library Grid view, modal, Gutenberg)
     *
     * @param array $response
     * @param WP_Post $attachment
     * @param array|bool $meta
     * @return array
     */
    public function filter_attachment_for_js($response, $attachment, $meta) {
        if (!$this->should_rewrite_attachment($attachment->ID)) {
            return $response;
        }

        if (!empty($response['url'])) {
            $response['url'] = $this->rewrite_url($response['url']);
        }

        if (!empty($response['icon'])) {
            $response['icon'] = $this->rewrite_url($response['icon']);
        }

        $upload_sizes_mode = get_option('r2g_upload_sizes', 'all');
        $uploaded_keys = get_post_meta($attachment->ID, '_r2g_keys', true);
        $has_uploaded_thumbs = is_array($uploaded_keys) && count($uploaded_keys) > 1;

        if (!empty($response['sizes']) && is_array($response['sizes'])) {
            foreach ($response['sizes'] as &$s) {
                if (!empty($s['url'])) {
                    if ($upload_sizes_mode === 'all' && $has_uploaded_thumbs) {
                        $s['url'] = $this->rewrite_url($s['url']);
                    } else {
                        // Point to the known full CDN URL so Gutenberg and modal thumbnails never 404
                        $s['url'] = $response['url'];
                    }
                }
            }
        }

        return $response;
    }

    /**
     * Filter REST API attachment payload for Astro Headless
     *
     * @param WP_REST_Response $response
     * @param WP_Post $post
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function filter_rest_attachment($response, $post, $request) {
        $data = $response->get_data();

        if (!empty($data['source_url'])) {
            $data['source_url'] = $this->rewrite_url($data['source_url']);
        }

        $upload_sizes_mode = get_option('r2g_upload_sizes', 'all');
        $uploaded_keys = get_post_meta($post->ID, '_r2g_keys', true);
        $has_uploaded_thumbs = is_array($uploaded_keys) && count($uploaded_keys) > 1;

        if (!empty($data['media_details']['sizes']) && is_array($data['media_details']['sizes'])) {
            foreach ($data['media_details']['sizes'] as $s => &$info) {
                if (!empty($info['source_url'])) {
                    if ($upload_sizes_mode === 'all' && $has_uploaded_thumbs) {
                        $info['source_url'] = $this->rewrite_url($info['source_url']);
                    } else {
                        $info['source_url'] = $data['source_url'];
                    }
                }
            }
        }

        $response->set_data($data);
        return $response;
    }

    /**
     * Filter Admin Featured Image HTML
     *
     * @param string $content
     * @param int $post_id
     * @param int $thumbnail_id
     * @return string
     */
    public function filter_admin_thumbnail_html($content, $post_id, $thumbnail_id) {
        if (empty($content) || !$thumbnail_id || !$this->should_rewrite_attachment($thumbnail_id)) {
            return $content;
        }

        $uploads = wp_upload_dir();
        $baseurl = $uploads['baseurl'];
        $cdn_base = $this->get_cdn_base();

        if (empty($cdn_base) || empty($baseurl)) {
            return $content;
        }

        return str_replace($baseurl, $cdn_base . '/wp-content/uploads', $content);
    }

    /**
     * Filter HTML content in post body for non-headless WordPress blogs
     *
     * @param string $content
     * @return string
     */
    public function filter_content_urls($content) {
        if (empty($content)) {
            return $content;
        }

        $uploads = wp_upload_dir();
        $baseurl = $uploads['baseurl'];
        $cdn_base = $this->get_cdn_base();

        if (empty($cdn_base) || empty($baseurl)) {
            return $content;
        }

        // Replace http and https instances of local upload URLs
        $target_url = $cdn_base . '/wp-content/uploads';
        $content = str_replace($baseurl, $target_url, $content);

        return $content;
    }
}
