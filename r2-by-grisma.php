<?php
/**
 * Plugin Name: R2 by Grisma
 * Plugin URI: https://grisma.com.np
 * Description: Enterprise Cloudflare R2 sync with client-side Browser Edge compression, on-site WebP conversion, custom CDN delivery, and zero vendor bloat.
 * Version: 1.0.19
 * Author: Grisma
 * Author URI: https://grisma.com.np
 * License: GPL v2 or later
 * Text Domain: r2-by-grisma
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package R2_By_Grisma
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define Constants
define('R2G_VERSION', '1.0.19');
define('R2G_FILE', __FILE__);
define('R2G_PATH', plugin_dir_path(__FILE__));
define('R2G_URL', plugin_dir_url(__FILE__));

// Load Modules
require_once R2G_PATH . 'includes/class-encryption.php';
require_once R2G_PATH . 'includes/class-database.php';
require_once R2G_PATH . 'includes/class-r2-client.php';
require_once R2G_PATH . 'includes/class-optimizer.php';
require_once R2G_PATH . 'includes/class-media-handler.php';
require_once R2G_PATH . 'includes/class-url-rewriter.php';
require_once R2G_PATH . 'includes/class-media-library.php';
require_once R2G_PATH . 'includes/class-sync.php';
require_once R2G_PATH . 'includes/class-admin.php';
require_once R2G_PATH . 'includes/class-updater.php';

class R2_By_Grisma {
    /**
     * Singleton instance
     *
     * @var R2_By_Grisma|null
     */
    private static $instance = null;

    /**
     * R2 Client instance
     *
     * @var R2G_Client|null
     */
    private $client = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Ensure database table exists (handles upgrades)
        add_action('admin_init', array('R2G_Database', 'maybe_upgrade'));

        // Auto-migrate postmeta data from v1.0.0 on first admin load after upgrade
        add_action('admin_init', array($this, 'maybe_migrate_postmeta'));

        // Auto-migrate to streamlined presets and upload-time workflow in v1.0.9
        add_action('admin_init', array($this, 'maybe_migrate_v109'));

        // Initialize sub-modules
        R2G_Media_Handler::instance();
        R2G_URL_Rewriter::instance();
        R2G_Media_Library::instance();
        R2G_Sync::instance();
        R2G_Admin::instance();
        R2G_Updater::instance();

        // Settings link in Plugins list
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_action_links'));
    }

    /**
     * Auto-migrate to streamlined presets and upload-time workflow
     */
    public function maybe_migrate_v109() {
        if (!get_option('r2g_v109_migrated')) {
            if (get_option('r2g_compress_enabled') === false) {
                update_option('r2g_compress_enabled', 1);
            }
            if (!get_option('r2g_compress_format')) {
                update_option('r2g_compress_format', 'webp');
            }
            if (!get_option('r2g_compress_quality')) {
                update_option('r2g_compress_quality', 82);
            }
            if (!get_option('r2g_max_width')) {
                update_option('r2g_max_width', 1920);
            }
            if (!get_option('r2g_active_preset')) {
                update_option('r2g_active_preset', 'webp_balanced');
            }
            if (!get_option('r2g_upload_workflow')) {
                update_option('r2g_upload_workflow', 'bar');
            }
            update_option('r2g_v109_migrated', 1);
        }
    }

    /**
     * Get initialized R2 Client
     *
     * @return R2G_Client
     */
    public function get_client() {
        if (is_null($this->client)) {
            $account_id = get_option('r2g_account_id', '');
            $access_key = get_option('r2g_access_key', '');
            $secret_enc = get_option('r2g_secret_key', '');
            $secret_key = R2G_Encryption::decrypt($secret_enc);
            $bucket     = get_option('r2g_bucket', '');

            $this->client = new R2G_Client($account_id, $access_key, $secret_key, $bucket);
        }
        return $this->client;
    }

    /**
     * One-time migration of v1.0.0 postmeta sync data into the database table
     */
    public function maybe_migrate_postmeta() {
        if (get_option('r2g_postmeta_migrated')) {
            return;
        }
        if (class_exists('R2G_Database') && method_exists('R2G_Database', 'migrate_from_postmeta')) {
            R2G_Database::migrate_from_postmeta();
        }
        update_option('r2g_postmeta_migrated', '1');
    }

    /**
     * Add settings link on WordPress Plugins management page
     *
     * @param array $links
     * @return array
     */
    public function add_plugin_action_links($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('options-general.php?page=r2-by-grisma'),
            esc_html__('Settings', 'r2-by-grisma')
        );
        array_unshift($links, $settings_link);
        return $links;
    }
}

/**
 * Global accessor
 *
 * @return R2_By_Grisma
 */
function r2_by_grisma() {
    return R2_By_Grisma::instance();
}

// Bootstrap
r2_by_grisma();

// Activation: Create database table and set defaults
register_activation_hook(__FILE__, function() {
    R2G_Database::create_table();

    if (!get_option('r2g_storage_mode')) {
        add_option('r2g_storage_mode', 'both');
    }
    if (!get_option('r2g_compress_engine')) {
        add_option('r2g_compress_engine', 'server');
    }
    if (!get_option('r2g_compress_format')) {
        add_option('r2g_compress_format', 'webp');
    }
    if (!get_option('r2g_compress_quality')) {
        add_option('r2g_compress_quality', 82);
    }
    if (!get_option('r2g_max_width')) {
        add_option('r2g_max_width', 1920);
    }
    if (!get_option('r2g_active_preset')) {
        add_option('r2g_active_preset', 'webp_balanced');
    }
    if (!get_option('r2g_upload_workflow')) {
        add_option('r2g_upload_workflow', 'bar');
    }
    if (get_option('r2g_auto_upload') === false) {
        add_option('r2g_auto_upload', 1);
    }
    if (get_option('r2g_rewrite_urls') === false) {
        add_option('r2g_rewrite_urls', 1);
    }
    if (get_option('r2g_delete_from_r2') === false) {
        add_option('r2g_delete_from_r2', 1);
    }
});
