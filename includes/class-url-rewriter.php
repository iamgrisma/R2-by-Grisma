<?php
/**
 * Universal URL & Srcset Rewriter
 * Seamlessly replaces local WordPress upload URLs with Cloudflare R2 Custom CDN Domain.
 * Works identically for Headless REST API (Astro) and Monolithic themes alike.
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

        // Core attachment URL filter
        add_filter('wp_get_attachment_url', array($this, 'filter_attachment_url'), 20, 2);

        // Responsive srcset filter
        add_filter('wp_calculate_image_srcset', array($this, 'filter_srcset'), 20, 5);

        // Downsized image filter
        add_filter('image_downsize', array($this, 'filter_image_downsize'), 20, 3);

        // Headless REST API attachment preparation
        add_filter('rest_prepare_attachment', array($this, 'filter_rest_attachment'), 20, 3);

        // Traditional frontend theme post content rewriter
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
        $is_synced = get_post_meta($post_id, '_r2g_synced', true);
        if ($is_synced) {
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
        if (empty($sources) || !is_array($sources)) {
            return $sources;
        }

        foreach ($sources as $width => &$data) {
            if (!empty($data['url'])) {
                $data['url'] = $this->rewrite_url($data['url']);
            }
        }

        return $sources;
    }

    /**
     * Filter image downsize
     *
     * @param bool|array $downsize
     * @param int $id
     * @param string|array $size
     * @return bool|array
     */
    public function filter_image_downsize($downsize, $id, $size) {
        if (is_array($downsize) && !empty($downsize[0])) {
            $downsize[0] = $this->rewrite_url($downsize[0]);
        }
        return $downsize;
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

        if (!empty($data['media_details']['sizes']) && is_array($data['media_details']['sizes'])) {
            foreach ($data['media_details']['sizes'] as $s => &$info) {
                if (!empty($info['source_url'])) {
                    $info['source_url'] = $this->rewrite_url($info['source_url']);
                }
            }
        }

        $response->set_data($data);
        return $response;
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
