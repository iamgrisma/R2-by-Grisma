<?php
/**
 * Native WordPress Core Plugin Update & Rollback Pipeline via GitHub Releases
 *
 * Designed specifically to avoid hosting false-positive malware blocks by relying
 * 100% on WordPress Core's native Plugin_Upgrader sandbox and WP_Filesystem API.
 *
 * @package R2_By_Grisma
 */

if (!defined('ABSPATH')) {
    exit;
}

class R2G_Updater {
    /**
     * GitHub Repository Information
     */
    const GITHUB_OWNER = 'iamgrisma';
    const GITHUB_REPO  = 'R2-by-Grisma';
    const PLUGIN_SLUG  = 'r2-by-grisma';

    /**
     * Singleton instance
     *
     * @var R2G_Updater|null
     */
    private static $instance = null;

    /**
     * Cache keys
     */
    const TRANSIENT_LATEST   = 'r2g_github_latest_release';
    const TRANSIENT_RELEASES = 'r2g_github_all_releases';

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Native WordPress Update Pipeline Filters
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_popup_info'), 20, 3);
        add_filter('auto_update_plugin', array($this, 'filter_auto_update_plugin'), 10, 2);
        add_filter('upgrader_source_selection', array($this, 'fix_unzipped_folder_name'), 10, 4);
        add_filter('upgrader_package_options', array($this, 'filter_upgrader_package_options'), 10, 1);

        // Admin actions & AJAX
        if (is_admin()) {
            add_action('admin_init', array($this, 'handle_manual_check'));
            add_action('wp_ajax_r2g_check_updates', array($this, 'ajax_check_updates'));
            add_filter('plugin_auto_update_setting_html', array($this, 'filter_auto_update_html'), 10, 3);
        }
    }

    /**
     * Get plugin basename (e.g., r2-by-grisma/r2-by-grisma.php)
     *
     * @return string
     */
    public function get_plugin_basename() {
        return plugin_basename(R2G_FILE);
    }

    /**
     * Hook into WordPress update_plugins transient
     *
     * @param object $transient
     * @return object
     */
    public function check_for_update($transient) {
        if (empty($transient) || !is_object($transient)) {
            return $transient;
        }

        $plugin_file = $this->get_plugin_basename();
        $latest = $this->get_latest_release();

        if (empty($latest) || empty($latest['version'])) {
            return $transient;
        }

        $item = new stdClass();
        $item->id           = self::PLUGIN_SLUG;
        $item->slug         = self::PLUGIN_SLUG;
        $item->plugin       = $plugin_file;
        $item->new_version  = $latest['version'];
        $item->url          = $latest['url'];
        $item->package      = $latest['package'];
        $item->tested       = '6.7';
        $item->requires     = '5.8';
        $item->requires_php = '7.4';
        $item->icons        = array();
        $item->banners      = array();

        // Check if remote version is strictly higher than current
        if (version_compare(R2G_VERSION, $latest['version'], '<')) {
            $transient->response[$plugin_file] = $item;
            if (isset($transient->no_update[$plugin_file])) {
                unset($transient->no_update[$plugin_file]);
            }
        } else {
            $transient->no_update[$plugin_file] = $item;
            if (isset($transient->response[$plugin_file])) {
                unset($transient->response[$plugin_file]);
            }
        }

        return $transient;
    }

    /**
     * Fetch latest release from GitHub API with caching
     *
     * @param bool $force
     * @return array|null
     */
    public function get_latest_release($force = false) {
        if (!$force) {
            $cached = get_transient(self::TRANSIENT_LATEST);
            if ($cached !== false && is_array($cached)) {
                return $cached;
            }
        }

        $url = sprintf('https://api.github.com/repos/%s/%s/releases/latest', self::GITHUB_OWNER, self::GITHUB_REPO);

        $response = wp_remote_get($url, array(
            'timeout'   => 10,
            'sslverify' => true,
            'headers'   => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'R2-by-Grisma-WP/' . R2G_VERSION . '; ' . home_url(),
            ),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['tag_name'])) {
            return null;
        }

        $tag = ltrim($data['tag_name'], 'vV');
        $package_url = $data['zipball_url'] ?? '';

        // Prioritize custom built r2-by-grisma.zip in release assets
        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (isset($asset['name']) && strpos(strtolower($asset['name']), 'r2-by-grisma') !== false && substr($asset['name'], -4) === '.zip') {
                    $package_url = $asset['browser_download_url'];
                    break;
                }
            }
            // If no exact match, take any attached zip asset
            if (empty($package_url) || strpos($package_url, 'zipball') !== false) {
                foreach ($data['assets'] as $asset) {
                    if (isset($asset['browser_download_url']) && substr($asset['browser_download_url'], -4) === '.zip') {
                        $package_url = $asset['browser_download_url'];
                        break;
                    }
                }
            }
        }

        $result = array(
            'version'     => $tag,
            'tag_name'    => $data['tag_name'],
            'url'         => $data['html_url'] ?? sprintf('https://github.com/%s/%s', self::GITHUB_OWNER, self::GITHUB_REPO),
            'package'     => $package_url,
            'published'   => $data['published_at'] ?? '',
            'changelog'   => $data['body'] ?? '',
            'author'      => $data['author']['login'] ?? 'iamgrisma',
        );

        // Cache for 6 hours
        set_transient(self::TRANSIENT_LATEST, $result, 6 * HOUR_IN_SECONDS);

        return $result;
    }

    /**
     * Fetch all recent releases for Rollback and Version Inspection
     *
     * @param bool $force
     * @return array
     */
    public function get_all_releases($force = false) {
        if (!$force) {
            $cached = get_transient(self::TRANSIENT_RELEASES);
            if ($cached !== false && is_array($cached)) {
                return $cached;
            }
        }

        $url = sprintf('https://api.github.com/repos/%s/%s/releases?per_page=10', self::GITHUB_OWNER, self::GITHUB_REPO);

        $response = wp_remote_get($url, array(
            'timeout'   => 12,
            'sslverify' => true,
            'headers'   => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'R2-by-Grisma-WP/' . R2G_VERSION . '; ' . home_url(),
            ),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return array();
        }

        $releases = array();
        foreach ($data as $rel) {
            if (empty($rel['tag_name'])) {
                continue;
            }

            $tag = ltrim($rel['tag_name'], 'vV');
            $package_url = $rel['zipball_url'] ?? '';

            if (!empty($rel['assets']) && is_array($rel['assets'])) {
                foreach ($rel['assets'] as $asset) {
                    if (isset($asset['name']) && strpos(strtolower($asset['name']), 'r2-by-grisma') !== false && substr($asset['name'], -4) === '.zip') {
                        $package_url = $asset['browser_download_url'];
                        break;
                    }
                }
            }

            $releases[] = array(
                'version'     => $tag,
                'tag_name'    => $rel['tag_name'],
                'url'         => $rel['html_url'] ?? '',
                'package'     => $package_url,
                'published'   => $rel['published_at'] ?? '',
                'changelog'   => $rel['body'] ?? '',
                'prerelease'  => !empty($rel['prerelease']),
            );
        }

        // Cache for 6 hours
        set_transient(self::TRANSIENT_RELEASES, $releases, 6 * HOUR_IN_SECONDS);

        return $releases;
    }

    /**
     * Native WordPress Plugin Details Popup (View details)
     *
     * @param false|object|array $result
     * @param string $action
     * @param object $args
     * @return false|object
     */
    public function plugin_popup_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::PLUGIN_SLUG) {
            return $result;
        }

        $latest = $this->get_latest_release();
        if (empty($latest)) {
            return $result;
        }

        $res = new stdClass();
        $res->name          = 'R2 by Grisma';
        $res->slug          = self::PLUGIN_SLUG;
        $res->version       = $latest['version'];
        $res->author        = '<a href="https://github.com/' . esc_attr(self::GITHUB_OWNER) . '">' . esc_html($latest['author']) . '</a>';
        $res->homepage      = $latest['url'];
        $res->download_link = $latest['package'];
        $res->tested        = '6.7';
        $res->requires      = '5.8';
        $res->requires_php  = '7.4';
        $res->last_updated  = !empty($latest['published']) ? date_i18n('Y-m-d H:i', strtotime($latest['published'])) : '';
        $res->sections      = array(
            'description' => '<p>Pure, high-performance Cloudflare R2 media sync with Browser Edge image compression, WebP conversion, and custom CDN delivery.</p>',
            'changelog'   => !empty($latest['changelog']) ? nl2br(esc_html($latest['changelog'])) : '<p>Maintenance and feature updates.</p>',
        );

        return $res;
    }

    /**
     * Fix extracted folder name to strictly 'r2-by-grisma'
     *
     * When GitHub zips are extracted, they often expand to 'iamgrisma-R2-by-Grisma-xyz'
     * or 'R2-by-Grisma-1.0.0'. This hook renames the folder to 'r2-by-grisma' so
     * WordPress does not duplicate or break the plugin path.
     *
     * @param string $source
     * @param string $remote_source
     * @param WP_Upgrader $upgrader
     * @param array|null $hook_extra
     * @return string|WP_Error
     */
    public function fix_unzipped_folder_name($source, $remote_source, $upgrader, $hook_extra = null) {
        global $wp_filesystem;

        // Verify this update is for our plugin
        $is_target = false;
        if (!empty($hook_extra['plugin']) && strpos($hook_extra['plugin'], self::PLUGIN_SLUG) !== false) {
            $is_target = true;
        } elseif ($wp_filesystem->exists($source . '/r2-by-grisma.php')) {
            $is_target = true;
        }

        if (!$is_target) {
            return $source;
        }

        $proper_folder_name = self::PLUGIN_SLUG;
        $new_source = trailingslashit($remote_source) . $proper_folder_name . '/';

        if (untrailingslashit($source) !== untrailingslashit($new_source)) {
            $move_result = $wp_filesystem->move($source, $new_source);
            if ($move_result) {
                return $new_source;
            }
        }

        return $source;
    }

    /**
     * Handle custom rollback or specific version package selection in native upgrader
     *
     * @param array $options
     * @return array
     */
    public function filter_upgrader_package_options($options) {
        if (!empty($_GET['r2g_target_version']) && !empty($_GET['plugin']) && strpos($_GET['plugin'], self::PLUGIN_SLUG) !== false) {
            check_admin_referer('r2g_rollback_' . sanitize_text_field($_GET['r2g_target_version']));

            $target_version = sanitize_text_field($_GET['r2g_target_version']);
            $releases = $this->get_all_releases();

            foreach ($releases as $rel) {
                if ($rel['version'] === $target_version && !empty($rel['package'])) {
                    $options['package'] = $rel['package'];
                    break;
                }
            }
        }

        return $options;
    }

    /**
     * Allow automatic background updates
     *
     * @param bool $update
     * @param object $item
     * @return bool
     */
    public function filter_auto_update_plugin($update, $item) {
        $plugin_file = $this->get_plugin_basename();
        if (!empty($item->plugin) && $item->plugin === $plugin_file) {
            return true;
        }
        return $update;
    }

    /**
     * Auto-update column label in Plugins list
     *
     * @param string $html
     * @param string $plugin_file
     * @param array $plugin_data
     * @return string
     */
    public function filter_auto_update_html($html, $plugin_file, $plugin_data) {
        if ($plugin_file === $this->get_plugin_basename()) {
            return '<span class="label" style="color:#007017;font-weight:600;">' . esc_html__('Managed via GitHub Releases', 'r2-by-grisma') . '</span>';
        }
        return $html;
    }

    /**
     * Handle manual force check from admin button
     */
    public function handle_manual_check() {
        if (isset($_GET['r2g_check_updates']) && check_admin_referer('r2g_manual_check_nonce')) {
            delete_transient(self::TRANSIENT_LATEST);
            delete_transient(self::TRANSIENT_RELEASES);
            delete_site_transient('update_plugins');

            $this->get_latest_release(true);
            $this->get_all_releases(true);

            // Re-trigger core update check
            if (function_exists('wp_update_plugins')) {
                wp_update_plugins();
            }

            wp_safe_redirect(remove_query_arg(array('r2g_check_updates', '_wpnonce')));
            exit;
        }
    }

    /**
     * AJAX action to check for updates and return release list
     */
    public function ajax_check_updates() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        delete_transient(self::TRANSIENT_LATEST);
        delete_transient(self::TRANSIENT_RELEASES);
        delete_site_transient('update_plugins');

        $latest = $this->get_latest_release(true);
        $all = $this->get_all_releases(true);

        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }

        $has_update = false;
        if (!empty($latest['version'])) {
            $has_update = version_compare(R2G_VERSION, $latest['version'], '<');
        }

        wp_send_json_success(array(
            'current_version' => R2G_VERSION,
            'latest_version'  => $latest['version'] ?? R2G_VERSION,
            'has_update'      => $has_update,
            'latest'          => $latest,
            'releases'        => $all,
        ));
    }
}
