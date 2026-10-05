<?php
/**
 * Admin Settings, Tabs & Dashboard
 *
 * Provides a clean, modern tabbed interface separating Connection,
 * Settings/Compression, Storage Dashboard & Bulk Sync, and Updates/Rollback.
 * Includes live bucket discovery, pre-save connection testing, and granular controls.
 *
 * @package R2_By_Grisma
 */

if (!defined('ABSPATH')) {
    exit;
}

class R2G_Admin {
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
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_init', array($this, 'handle_save_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_enqueue_media', array($this, 'on_wp_enqueue_media'));

        // Ajax Handlers
        add_action('wp_ajax_r2g_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_r2g_fetch_buckets', array($this, 'ajax_fetch_buckets'));
    }

    /**
     * Trigger asset enqueuing when wp_enqueue_media is invoked anywhere
     */
    public function on_wp_enqueue_media() {
        $this->enqueue_admin_assets('wp_enqueue_media');
    }

    /**
     * Register Admin Menu under Settings
     */
    public function register_admin_menu() {
        add_options_page(
            esc_html__('R2 Cloud & Image Optimizer', 'r2-by-grisma'),
            esc_html__('R2 by Grisma', 'r2-by-grisma'),
            'manage_options',
            'r2-by-grisma',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Enqueue Admin Assets
     *
     * @param string $hook
     */
    public function enqueue_admin_assets($hook) {
        $allowed = array('settings_page_r2-by-grisma', 'upload.php', 'media-new.php', 'post.php', 'post-new.php', 'page.php', 'page-new.php', 'wp_enqueue_media');
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_editor_or_media = in_array($hook, $allowed) || ($screen && in_array($screen->base, array('post', 'upload', 'media', 'edit')));

        if (!$is_editor_or_media && !did_action('wp_enqueue_media')) {
            return;
        }

        wp_enqueue_style(
            'r2g-admin-css',
            R2G_URL . 'assets/css/admin.css',
            array(),
            R2G_VERSION
        );

        wp_enqueue_script(
            'r2g-admin-js',
            R2G_URL . 'assets/js/admin.js',
            array('jquery'),
            R2G_VERSION,
            true
        );

        wp_localize_script('r2g-admin-js', 'r2g_admin', array(
            'nonce'    => wp_create_nonce('r2g_admin_nonce'),
            'ajax_url' => admin_url('admin-ajax.php'),
        ));

        // Enqueue Upload-Time Interceptor & Bar
        $compress_deps = array('jquery');
        if (wp_script_is('wp-media-utils', 'registered')) {
            $compress_deps[] = 'wp-media-utils';
        }

        wp_enqueue_script(
            'r2g-browser-compress-js',
            R2G_URL . 'assets/js/browser-compress.js',
            $compress_deps,
            R2G_VERSION,
            true
        );

        wp_localize_script('r2g-browser-compress-js', 'r2g_compress_config', array(
            'workflow'  => get_option('r2g_upload_workflow', 'prompt'),
            'format'    => get_option('r2g_compress_format', 'webp'),
            'compress'  => (int) get_option('r2g_compress_enabled', 1),
            'quality'   => (int) get_option('r2g_compress_quality', 82),
            'maxWidth'  => (int) get_option('r2g_max_width', 1920),
        ));
    }

    /**
     * Handle Settings Form Submission
     */
    public function handle_save_settings() {
        if (!isset($_POST['r2g_save_settings']) || !check_admin_referer('r2g_save_settings_nonce')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = sanitize_text_field($_POST['r2g_active_tab'] ?? 'setup');

        if ($active_tab === 'setup') {
            // Credentials
            update_option('r2g_account_id', sanitize_text_field(wp_unslash($_POST['r2g_account_id'] ?? '')));
            update_option('r2g_access_key', sanitize_text_field(wp_unslash($_POST['r2g_access_key'] ?? '')));

            $bucket = sanitize_text_field(wp_unslash($_POST['r2g_bucket'] ?? ''));
            if (!empty($_POST['r2g_bucket_select'])) {
                $bucket = sanitize_text_field(wp_unslash($_POST['r2g_bucket_select']));
            }
            update_option('r2g_bucket', $bucket);

            // Secret key
            $posted_secret = trim($_POST['r2g_secret_key'] ?? '');
            if (!empty($posted_secret) && strpos($posted_secret, '••••') === false) {
                $encrypted = R2G_Encryption::encrypt($posted_secret);
                update_option('r2g_secret_key', $encrypted);
            }

            // Custom CDN Domain
            $domain = trim(sanitize_text_field(wp_unslash($_POST['r2g_custom_domain'] ?? '')));
            if (!empty($domain) && strpos($domain, 'http') !== 0) {
                $domain = 'https://' . $domain;
            }
            update_option('r2g_custom_domain', rtrim($domain, '/'));
        }

        if ($active_tab === 'settings') {
            // Storage Path Structure
            update_option('r2g_path_structure', sanitize_text_field(wp_unslash($_POST['r2g_path_structure'] ?? 'wp_content')));
            update_option('r2g_path_prefix', sanitize_text_field(wp_unslash($_POST['r2g_path_prefix'] ?? '')));

            // Thumbnail Offload Policy & Scope
            update_option('r2g_upload_sizes', sanitize_text_field(wp_unslash($_POST['r2g_upload_sizes'] ?? 'all')));
            update_option('r2g_cleanup_scope', sanitize_text_field(wp_unslash($_POST['r2g_cleanup_scope'] ?? 'all')));

            // Automation & Policies
            update_option('r2g_auto_upload', !empty($_POST['r2g_auto_upload']) ? 1 : 0);
            update_option('r2g_rewrite_urls', !empty($_POST['r2g_rewrite_urls']) ? 1 : 0);
            update_option('r2g_delete_from_r2', !empty($_POST['r2g_delete_from_r2']) ? 1 : 0);
            update_option('r2g_storage_mode', sanitize_text_field(wp_unslash($_POST['r2g_storage_mode'] ?? 'both')));

            // Compression & Format Presets
            update_option('r2g_compress_enabled', !empty($_POST['r2g_compress_enabled']) ? 1 : 0);
            update_option('r2g_compress_format', sanitize_text_field(wp_unslash($_POST['r2g_compress_format'] ?? 'webp')));
            update_option('r2g_compress_quality', max(60, min(100, (int)($_POST['r2g_compress_quality'] ?? 82))));
            update_option('r2g_max_width', max(0, (int)($_POST['r2g_max_width'] ?? 1920)));
            update_option('r2g_upload_workflow', sanitize_text_field(wp_unslash($_POST['r2g_upload_workflow'] ?? 'prompt')));
        }

        add_settings_error('r2g_messages', 'r2g_saved', esc_html__('Settings saved successfully.', 'r2-by-grisma'), 'updated');
    }

    /**
     * Ajax: Test R2 Connection and Custom Domain verification
     * Works LIVE from form inputs even before saving!
     */
    public function ajax_test_connection() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        // Read credentials from POST first, fallback to DB
        $account_id = sanitize_text_field($_POST['account_id'] ?? '') ?: get_option('r2g_account_id', '');
        $access_key = sanitize_text_field($_POST['access_key'] ?? '') ?: get_option('r2g_access_key', '');
        $posted_secret = trim($_POST['secret_key'] ?? '');

        if (!empty($posted_secret) && strpos($posted_secret, '••••') === false) {
            $secret_key = $posted_secret;
        } else {
            $secret_key = R2G_Encryption::decrypt(get_option('r2g_secret_key', ''));
        }

        $bucket = sanitize_text_field($_POST['bucket'] ?? '') ?: get_option('r2g_bucket', '');
        $custom_domain = sanitize_text_field($_POST['custom_domain'] ?? '') ?: get_option('r2g_custom_domain', '');

        if (empty($account_id) || empty($access_key) || empty($secret_key)) {
            wp_send_json_error(array('message' => 'Please enter your Cloudflare Account ID, Access Key ID, and Secret Access Key.'));
        }
        if (empty($bucket)) {
            wp_send_json_error(array('message' => 'Please select or enter an R2 Bucket name.'));
        }

        $client = new R2G_Client($account_id, $access_key, $secret_key, $bucket);
        $res = $client->test_connection($custom_domain);

        if ($res['success']) {
            wp_send_json_success($res);
        } else {
            wp_send_json_error($res);
        }
    }

    /**
     * Ajax: Fetch/Discover Buckets under Account
     * Works LIVE from form inputs even before saving!
     */
    public function ajax_fetch_buckets() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $account_id = sanitize_text_field($_POST['account_id'] ?? '') ?: get_option('r2g_account_id', '');
        $access_key = sanitize_text_field($_POST['access_key'] ?? '') ?: get_option('r2g_access_key', '');
        $posted_secret = trim($_POST['secret_key'] ?? '');

        if (!empty($posted_secret) && strpos($posted_secret, '••••') === false) {
            $secret_key = $posted_secret;
        } else {
            $secret_key = R2G_Encryption::decrypt(get_option('r2g_secret_key', ''));
        }

        if (empty($account_id) || empty($access_key) || empty($secret_key)) {
            wp_send_json_error(array('message' => 'Please enter Account ID, Access Key ID, and Secret Access Key first.'));
        }

        $client = new R2G_Client($account_id, $access_key, $secret_key, '');
        $res = $client->list_buckets();

        if ($res['success']) {
            wp_send_json_success($res);
        } else {
            wp_send_json_error($res);
        }
    }

    /**
     * Render Settings Page with Tabs
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'setup';
        if (!in_array($active_tab, array('setup', 'settings', 'index', 'updates'))) {
            $active_tab = 'setup';
        }

        // Credentials
        $account_id    = get_option('r2g_account_id', '');
        $access_key    = get_option('r2g_access_key', '');
        $raw_secret    = R2G_Encryption::decrypt(get_option('r2g_secret_key', ''));
        $masked_secret = R2G_Encryption::mask($raw_secret);
        $bucket        = get_option('r2g_bucket', '');
        $custom_domain = get_option('r2g_custom_domain', '');

        // Client configured check
        $client = r2_by_grisma()->get_client();
        $is_configured = $client && $client->is_configured();

        // Policies & Path
        $path_structure = get_option('r2g_path_structure', 'wp_content');
        $path_prefix    = get_option('r2g_path_prefix', '');
        $upload_sizes   = get_option('r2g_upload_sizes', 'all');
        $cleanup_scope  = get_option('r2g_cleanup_scope', 'all');

        $auto_upload   = (int) get_option('r2g_auto_upload', 1);
        $rewrite_urls  = (int) get_option('r2g_rewrite_urls', 1);
        $del_from_r2   = (int) get_option('r2g_delete_from_r2', 1);
        $storage_mode      = get_option('r2g_storage_mode', 'both');
        $compress_enabled  = (int) get_option('r2g_compress_enabled', 1);
        $format            = get_option('r2g_compress_format', 'webp');
        $quality           = (int) get_option('r2g_compress_quality', 82);
        $max_width         = (int) get_option('r2g_max_width', 1920);
        $workflow          = get_option('r2g_upload_workflow', 'prompt');

        // Stats
        $stats = class_exists('R2G_Database') ? R2G_Database::get_stats() : array();
        ?>
        <div class="wrap r2g-wrap">
            <div class="r2g-header">
                <h1>
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                    <?php esc_html_e('R2 Cloud & Image Optimizer by Grisma', 'r2-by-grisma'); ?>
                </h1>
                <span class="r2g-version">v<?php echo esc_html(R2G_VERSION); ?></span>
            </div>

            <?php settings_errors('r2g_messages'); ?>

            <!-- NAVIGATION TABS -->
            <nav class="nav-tab-wrapper r2g-nav-tabs" style="margin-bottom: 22px;">
                <a href="<?php echo esc_url(add_query_arg('tab', 'setup')); ?>" class="nav-tab <?php echo $active_tab === 'setup' ? 'nav-tab-active' : ''; ?>">
                    <svg class="r2g-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    <?php esc_html_e('Setup & API Keys', 'r2-by-grisma'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'settings')); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <svg class="r2g-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <?php esc_html_e('Settings & Policies', 'r2-by-grisma'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'index')); ?>" class="nav-tab <?php echo $active_tab === 'index' ? 'nav-tab-active' : ''; ?>">
                    <svg class="r2g-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    <?php esc_html_e('Storage & Media Sync', 'r2-by-grisma'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'updates')); ?>" class="nav-tab <?php echo $active_tab === 'updates' ? 'nav-tab-active' : ''; ?>">
                    <svg class="r2g-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                    <?php esc_html_e('Updates & Rollback', 'r2-by-grisma'); ?>
                </a>
            </nav>

            <!-- TAB 1: SETUP & API KEYS -->
            <?php if ($active_tab === 'setup'): ?>
                <form method="post" action="">
                    <?php wp_nonce_field('r2g_save_settings_nonce'); ?>
                    <input type="hidden" name="r2g_active_tab" value="setup" />

                    <div class="r2g-card">
                        <h2><?php esc_html_e('Cloudflare R2 Connection Credentials', 'r2-by-grisma'); ?></h2>
                        <p class="description"><?php esc_html_e('Enter your S3-compatible Cloudflare R2 API credentials, discover your buckets, and verify your custom CDN domain.', 'r2-by-grisma'); ?></p>

                        <table class="r2g-form-table">
                            <tr>
                                <th><label for="r2g_account_id"><?php esc_html_e('Cloudflare Account ID', 'r2-by-grisma'); ?></label></th>
                                <td>
                                    <input type="text" id="r2g_account_id" name="r2g_account_id" value="<?php echo esc_attr($account_id); ?>" class="r2g-input" placeholder="e.g. 5d8e7a1b4c3f2..." required />
                                    <p class="description"><?php esc_html_e('Found on Cloudflare Dashboard right sidebar under "Account ID".', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="r2g_access_key"><?php esc_html_e('R2 Access Key ID', 'r2-by-grisma'); ?></label></th>
                                <td>
                                    <input type="text" id="r2g_access_key" name="r2g_access_key" value="<?php echo esc_attr($access_key); ?>" class="r2g-input" placeholder="e.g. a8b9c0d1e2f3..." required />
                                </td>
                            </tr>
                            <tr>
                                <th><label for="r2g_secret_key"><?php esc_html_e('R2 Secret Access Key', 'r2-by-grisma'); ?></label></th>
                                <td>
                                    <?php if (!empty($raw_secret)): ?>
                                        <div id="r2g-secret-display" class="r2g-secret-wrap">
                                            <input type="text" value="<?php echo esc_attr($masked_secret); ?>" class="r2g-input" disabled style="background:#f8fafc;" />
                                            <button type="button" id="r2g-btn-change-secret" class="button"><?php esc_html_e('Change Key', 'r2-by-grisma'); ?></button>
                                        </div>
                                        <div id="r2g-secret-input-wrap" style="display:none;">
                                            <input type="password" id="r2g_secret_key" name="r2g_secret_key" class="r2g-input" placeholder="<?php esc_attr_e('Enter new Secret Access Key', 'r2-by-grisma'); ?>" />
                                        </div>
                                    <?php else: ?>
                                        <input type="password" id="r2g_secret_key" name="r2g_secret_key" class="r2g-input" placeholder="<?php esc_attr_e('Enter Secret Access Key', 'r2-by-grisma'); ?>" required />
                                    <?php endif; ?>
                                    <p class="description"><?php esc_html_e('Stored securely on your server using AES-256 encryption.', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="r2g_bucket"><?php esc_html_e('R2 Bucket Name', 'r2-by-grisma'); ?></label></th>
                                <td>
                                    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; max-width:550px;">
                                        <input type="text" id="r2g_bucket" name="r2g_bucket" value="<?php echo esc_attr($bucket); ?>" class="r2g-input" style="flex:1; min-width:200px;" placeholder="e.g. topnepali" required />
                                        <select id="r2g_bucket_select" name="r2g_bucket_select" class="r2g-input" style="display:none; flex:1; min-width:200px;">
                                            <option value=""><?php esc_html_e('-- Select Discovered Bucket --', 'r2-by-grisma'); ?></option>
                                        </select>
                                        <button type="button" id="r2g-btn-fetch-buckets" class="button button-secondary">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px; margin-right:3px;"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                                            <?php esc_html_e('Discover Buckets', 'r2-by-grisma'); ?>
                                        </button>
                                    </div>
                                    <div id="r2g-fetch-status" style="margin-top:6px; font-size:12px;"></div>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="r2g_custom_domain"><?php esc_html_e('Custom CDN Domain', 'r2-by-grisma'); ?></label></th>
                                <td>
                                    <input type="url" id="r2g_custom_domain" name="r2g_custom_domain" value="<?php echo esc_attr($custom_domain); ?>" class="r2g-input" placeholder="https://objects.topnepali.com" />
                                    <p class="description" id="r2g-domain-hint"><?php esc_html_e('Your public custom domain (e.g. https://objects.topnepali.com) or R2.dev public URL connected to this bucket.', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                            <button type="button" id="r2g-btn-test-connection" class="button button-secondary">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px; margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php esc_html_e('Test Connection & Verify CDN (Live)', 'r2-by-grisma'); ?>
                            </button>
                            <span style="font-size:12px; color:#64748b; margin-left:10px;"><?php esc_html_e('Works immediately from the fields above without saving first.', 'r2-by-grisma'); ?></span>
                            <div id="r2g-test-status" style="display:none;" class="r2g-test-box"></div>
                        </div>
                    </div>

                    <p class="submit">
                        <input type="submit" name="r2g_save_settings" class="button button-primary button-large" value="<?php esc_attr_e('Save Connection Settings', 'r2-by-grisma'); ?>" />
                    </p>
                </form>

            <!-- TAB 2: SETTINGS & POLICIES -->
            <?php elseif ($active_tab === 'settings'): ?>
                <form method="post" action="">
                    <?php wp_nonce_field('r2g_save_settings_nonce'); ?>
                    <input type="hidden" name="r2g_active_tab" value="settings" />

                    <!-- Section: Storage Path & Directory Setup -->
                    <div class="r2g-card">
                        <h2><?php esc_html_e('Storage Directory Path & Structure', 'r2-by-grisma'); ?></h2>
                        <p class="description"><?php esc_html_e('Configure how files and directories are organized in your Cloudflare R2 bucket.', 'r2-by-grisma'); ?></p>

                        <table class="r2g-form-table">
                            <tr>
                                <th><?php esc_html_e('R2 Directory Structure', 'r2-by-grisma'); ?></th>
                                <td>
                                    <div class="r2g-radio-group">
                                        <label class="r2g-radio-pill">
                                            <input type="radio" name="r2g_path_structure" value="wp_content" <?php checked($path_structure, 'wp_content'); ?> />
                                            <span><strong><?php esc_html_e('Standard WordPress (Recommended)', 'r2-by-grisma'); ?></strong>: <code>wp-content/uploads/YYYY/MM/file.webp</code></span>
                                        </label>
                                        <label class="r2g-radio-pill">
                                            <input type="radio" name="r2g_path_structure" value="uploads_only" <?php checked($path_structure, 'uploads_only'); ?> />
                                            <span><strong><?php esc_html_e('Short Uploads', 'r2-by-grisma'); ?></strong>: <code>uploads/YYYY/MM/file.webp</code></span>
                                        </label>
                                        <label class="r2g-radio-pill">
                                            <input type="radio" name="r2g_path_structure" value="date_only" <?php checked($path_structure, 'date_only'); ?> />
                                            <span><strong><?php esc_html_e('Date Hierarchy Only', 'r2-by-grisma'); ?></strong>: <code>YYYY/MM/file.webp</code></span>
                                        </label>
                                    </div>
                                    <div style="margin-top:8px; padding:8px 12px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; font-size:12px; color:#166534;">
                                        <strong><?php esc_html_e('Existing Offload Setup Detected:', 'r2-by-grisma'); ?></strong>
                                        <?php esc_html_e('Your existing offloaded files on objects.topnepali.com use Standard WordPress path (wp-content/uploads/). Selecting this maintains 100% path parity.', 'r2-by-grisma'); ?>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Thumbnail Sizes to Offload', 'r2-by-grisma'); ?></th>
                                <td>
                                    <div class="r2g-radio-group">
                                        <label class="r2g-radio-pill">
                                            <input type="radio" name="r2g_upload_sizes" value="all" <?php checked($upload_sizes, 'all'); ?> />
                                            <span><?php esc_html_e('Offload All Generated Thumbnail Sizes (thumbnail, medium, large, full)', 'r2-by-grisma'); ?></span>
                                        </label>
                                        <label class="r2g-radio-pill">
                                            <input type="radio" name="r2g_upload_sizes" value="original_only" <?php checked($upload_sizes, 'original_only'); ?> />
                                            <span><?php esc_html_e('Offload Original Full-Resolution Image Only', 'r2-by-grisma'); ?></span>
                                        </label>
                                    </div>
                                    <p class="description"><?php esc_html_e('Offloading all sizes ensures every thumbnail preview in WordPress and mobile devices loads from CDN.', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Local Disk Cleanup Scope', 'r2-by-grisma'); ?></th>
                                <td>
                                    <div class="r2g-radio-group">
                                        <label class="r2g-radio-pill">
                                            <input type="radio" name="r2g_cleanup_scope" value="all" <?php checked($cleanup_scope, 'all'); ?> />
                                            <span><?php esc_html_e('Delete all local files (Original + all generated thumbnail sizes)', 'r2-by-grisma'); ?></span>
                                        </label>
                                        <label class="r2g-radio-pill">
                                            <input type="radio" name="r2g_cleanup_scope" value="original_only" <?php checked($cleanup_scope, 'original_only'); ?> />
                                            <span><?php esc_html_e('Delete only original full-size image (Keep local thumbnail files on disk)', 'r2-by-grisma'); ?></span>
                                        </label>
                                    </div>
                                    <p class="description"><?php esc_html_e('Active only when Server Storage Policy is set to "Cloud Only".', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Section: Automation Controls -->
                    <div class="r2g-card">
                        <h2><?php esc_html_e('Automation & User Control Policies', 'r2-by-grisma'); ?></h2>
                        <p class="description"><?php esc_html_e('Choose how much automated vs manual control you want over uploads, URL rewriting, and server storage.', 'r2-by-grisma'); ?></p>

                        <table class="r2g-form-table">
                            <tr>
                                <th><?php esc_html_e('Automatic Upload', 'r2-by-grisma'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="r2g_auto_upload" value="1" <?php checked($auto_upload, 1); ?> />
                                        <strong><?php esc_html_e('Automatically upload newly added media to Cloudflare R2', 'r2-by-grisma'); ?></strong>
                                    </label>
                                    <p class="description"><?php esc_html_e('If unchecked, new uploads will remain local until you manually click "Push to R2" or run Bulk Sync.', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('CDN URL Rewriting', 'r2-by-grisma'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="r2g_rewrite_urls" value="1" <?php checked($rewrite_urls, 1); ?> />
                                        <strong><?php esc_html_e('Serve all synced media from Custom CDN Domain', 'r2-by-grisma'); ?></strong>
                                    </label>
                                    <p class="description"><?php esc_html_e('Rewrites image source URLs, responsive srcsets, and admin previews to your fast Cloudflare CDN URL.', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('WordPress Trash Sync', 'r2-by-grisma'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="r2g_delete_from_r2" value="1" <?php checked($del_from_r2, 1); ?> />
                                        <strong><?php esc_html_e('Delete from Cloudflare R2 when media is deleted from WordPress', 'r2-by-grisma'); ?></strong>
                                    </label>
                                    <p class="description"><?php esc_html_e('Keeps your R2 storage synchronized and frees bucket space automatically.', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Server Storage Policy', 'r2-by-grisma'); ?></th>
                                <td>
                                    <div class="r2g-radio-group">
                                        <label class="r2g-radio-pill">
                                            <input type="radio" name="r2g_storage_mode" value="both" <?php checked($storage_mode, 'both'); ?> />
                                            <span><?php esc_html_e('Dual Storage (Keep Local Copy + R2 Cloud Backup)', 'r2-by-grisma'); ?></span>
                                        </label>
                                        <label class="r2g-radio-pill">
                                            <input type="radio" name="r2g_storage_mode" value="r2_only" <?php checked($storage_mode, 'r2_only'); ?> />
                                            <span><?php esc_html_e('Cloud Only (Delete Local Files to Save Server Disk)', 'r2-by-grisma'); ?></span>
                                        </label>
                                    </div>
                                    <p class="description"><?php esc_html_e('Cloud Only frees web hosting disk space. You can restore local files at any time via the Media Library.', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Section: Compression Pipeline -->
                    <div class="r2g-card">
                        <h2><?php esc_html_e('Optimization & Format Presets', 'r2-by-grisma'); ?></h2>
                        <p class="description"><?php esc_html_e('Define default presets for compression and formats. You can also override these per upload.', 'r2-by-grisma'); ?></p>

                        <table class="r2g-form-table">
                            <tr>
                                <th><?php esc_html_e('Format Conversion Preset', 'r2-by-grisma'); ?></th>
                                <td>
                                    <div class="r2g-radio-group">
                                        <label class="r2g-radio-pill">
                                            <input type="radio" name="r2g_compress_format" value="webp" <?php checked($format, 'webp'); ?> />
                                            <span><strong><?php esc_html_e('Convert to WebP (Recommended — 60-80% smaller)', 'r2-by-grisma'); ?></strong></span>
                                        </label>
                                        <label class="r2g-radio-pill">
                                            <input type="radio" name="r2g_compress_format" value="original" <?php checked($format, 'original'); ?> />
                                            <span><?php esc_html_e('Preserve Original Format (Keep JPG / PNG)', 'r2-by-grisma'); ?></span>
                                        </label>
                                    </div>
                                    <p class="description"><?php esc_html_e('WebP images load faster and reduce bandwidth on Cloudflare R2.', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Image Compression', 'r2-by-grisma'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="r2g_compress_enabled" value="1" <?php checked($compress_enabled, 1); ?> />
                                        <strong><?php esc_html_e('Enable Image Compression & Resizing', 'r2-by-grisma'); ?></strong>
                                    </label>
                                    <p class="description"><?php esc_html_e('Uses PHP GD / Imagick to compress images before offloading to Cloudflare R2.', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="r2g_compress_quality"><?php esc_html_e('Compression Quality', 'r2-by-grisma'); ?></label></th>
                                <td>
                                    <input type="number" id="r2g_compress_quality" name="r2g_compress_quality" value="<?php echo esc_attr($quality); ?>" min="60" max="100" class="r2g-input" style="max-width:120px;" /> %
                                    <p class="description"><?php esc_html_e('82% offers the optimal sweet spot between crisp visual detail and lightweight byte size.', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="r2g_max_width"><?php esc_html_e('Max Image Width', 'r2-by-grisma'); ?></label></th>
                                <td>
                                    <input type="number" id="r2g_max_width" name="r2g_max_width" value="<?php echo esc_attr($max_width); ?>" min="0" step="100" class="r2g-input" style="max-width:140px;" /> px
                                    <p class="description"><?php esc_html_e('Downsizes oversized camera photos (e.g. 4000px) down to web standards (e.g. 1920px). Set 0 to disable.', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Upload-Time Workflow', 'r2-by-grisma'); ?></th>
                                <td>
                                    <div class="r2g-radio-group">
                                        <label class="r2g-radio-pill">
                                            <input type="radio" name="r2g_upload_workflow" value="prompt" <?php checked($workflow, 'prompt'); ?> />
                                            <span><strong><?php esc_html_e('Prompt on Upload (Allow choosing format & compression per upload)', 'r2-by-grisma'); ?></strong></span>
                                        </label>
                                        <label class="r2g-radio-pill">
                                            <input type="radio" name="r2g_upload_workflow" value="automatic" <?php checked($workflow, 'automatic'); ?> />
                                            <span><?php esc_html_e('Automatic (Silently apply preset settings without prompts)', 'r2-by-grisma'); ?></span>
                                        </label>
                                    </div>
                                    <p class="description"><?php esc_html_e('Prompt mode lets you decide whether to convert to WebP or preserve original format whenever you upload.', 'r2-by-grisma'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <p class="submit">
                        <input type="submit" name="r2g_save_settings" class="button button-primary button-large" value="<?php esc_attr_e('Save Policies & Compression', 'r2-by-grisma'); ?>" />
                    </p>
                </form>

            <!-- TAB 3: STORAGE & MEDIA SYNC DASHBOARD -->
            <?php elseif ($active_tab === 'index'): ?>
                <?php if (!$is_configured): ?>
                    <div class="notice notice-warning inline" style="margin: 0 0 20px 0; padding: 14px 18px; border-left-color: #d97706;">
                        <h3 style="margin: 0 0 6px 0; font-size: 15px; color: #92400e;">
                            <?php esc_html_e('Cloudflare R2 Credentials Incomplete', 'r2-by-grisma'); ?>
                        </h3>
                        <p style="margin: 0; color: #78350f; font-size: 13px;">
                            <?php esc_html_e('Please enter your Cloudflare Account ID, Access Key ID, Secret Key, and Bucket Name in the Setup & API Keys tab before starting bulk sync.', 'r2-by-grisma'); ?>
                            <a href="<?php echo esc_url(add_query_arg('tab', 'setup')); ?>" class="button button-small" style="margin-left: 8px;">
                                <?php esc_html_e('Go to Setup & API Keys ↗', 'r2-by-grisma'); ?>
                            </a>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Stat Cards -->
                <div class="r2g-stats-grid">
                    <div class="r2g-stat-card">
                        <span class="r2g-stat-label"><?php esc_html_e('Total Media', 'r2-by-grisma'); ?></span>
                        <div class="r2g-stat-value" id="r2g-stat-total"><?php echo esc_html($stats['total_wp'] ?? 0); ?></div>
                        <span class="r2g-stat-sub"><?php esc_html_e('Attachments in Library', 'r2-by-grisma'); ?></span>
                    </div>

                    <div class="r2g-stat-card r2g-card-success">
                        <span class="r2g-stat-label"><?php esc_html_e('Synced to R2', 'r2-by-grisma'); ?></span>
                        <div class="r2g-stat-value" id="r2g-stat-synced"><?php echo esc_html($stats['synced'] ?? 0); ?></div>
                        <span class="r2g-stat-sub"><?php printf(esc_html__('%d dual, %d cloud-only', 'r2-by-grisma'), $stats['synced_both'] ?? 0, $stats['cloud_only'] ?? 0); ?></span>
                    </div>

                    <div class="r2g-stat-card r2g-card-info">
                        <span class="r2g-stat-label"><?php esc_html_e('Cloud Only', 'r2-by-grisma'); ?></span>
                        <div class="r2g-stat-value" id="r2g-stat-cloud"><?php echo esc_html($stats['cloud_only'] ?? 0); ?></div>
                        <span class="r2g-stat-sub"><?php esc_html_e('Server disk space freed', 'r2-by-grisma'); ?></span>
                    </div>

                    <div class="r2g-stat-card r2g-card-warning">
                        <span class="r2g-stat-label"><?php esc_html_e('Local Only', 'r2-by-grisma'); ?></span>
                        <div class="r2g-stat-value" id="r2g-stat-local"><?php echo esc_html($stats['local_only'] ?? 0); ?></div>
                        <span class="r2g-stat-sub"><?php esc_html_e('Awaiting push to R2', 'r2-by-grisma'); ?></span>
                    </div>
                </div>

                <!-- Bulk Sync Tool -->
                <div class="r2g-card" style="margin-top: 24px;">
                    <h2><?php esc_html_e('Bulk Media Sync Engine', 'r2-by-grisma'); ?></h2>
                    <p class="description"><?php esc_html_e('Push all unsynced media attachments to Cloudflare R2 in safe batches without server timeouts.', 'r2-by-grisma'); ?></p>

                    <div class="r2g-sync-container">
                        <div class="r2g-progress-wrap" style="display:none;" id="r2g-sync-progress-box">
                            <div class="r2g-progress-bar-bg">
                                <div class="r2g-progress-bar-fill" id="r2g-sync-progress-fill" style="width: 0%;"></div>
                            </div>
                            <div class="r2g-progress-meta">
                                <span id="r2g-sync-status-text"><?php esc_html_e('Syncing media...', 'r2-by-grisma'); ?></span>
                                <span id="r2g-sync-percentage">0%</span>
                            </div>
                        </div>

                        <div class="r2g-sync-actions" style="display:flex; gap:10px; align-items:center;">
                            <button type="button" id="r2g-btn-start-sync" class="button button-primary button-large" <?php echo !$is_configured ? 'disabled title="' . esc_attr__('Please complete Setup & API Keys first', 'r2-by-grisma') . '"' : ''; ?>>
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px; margin-right:4px;"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                                <?php esc_html_e('Sync All Unsynced to R2', 'r2-by-grisma'); ?>
                            </button>
                            <button type="button" id="r2g-btn-pause-sync" class="button" style="display:none;">
                                <?php esc_html_e('Pause', 'r2-by-grisma'); ?>
                            </button>
                            <button type="button" id="r2g-btn-cancel-sync" class="button button-link-delete" style="display:none;">
                                <?php esc_html_e('Cancel', 'r2-by-grisma'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Index Maintenance & Legacy Migration -->
                <div class="r2g-card" style="margin-top: 24px;">
                    <h2><?php esc_html_e('Database Index Maintenance & Migration', 'r2-by-grisma'); ?></h2>
                    <p class="description"><?php esc_html_e('Tools to synchronize WordPress with existing offloaded media and rebuild the local index without costly Cloudflare R2 Class B list operations.', 'r2-by-grisma'); ?></p>

                    <div style="display:flex; gap:16px; flex-wrap:wrap;">
                        <div style="flex:1; min-width:280px; padding:16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                            <h3 style="margin-top:0; font-size:14px; font-weight:600; color:#0f172a;"><?php esc_html_e('Import from Media Cloud Sync / Existing Offload', 'r2-by-grisma'); ?></h3>
                            <p style="font-size:12px; color:#64748b; margin-bottom:14px;"><?php esc_html_e('Scans Media Cloud Sync table (wpmcs_items) and offloaded attachments, then registers them in R2 by Grisma so previews, CDN URLs, and controls immediately work.', 'r2-by-grisma'); ?></p>
                            <button type="button" id="r2g-btn-import-legacy" class="button button-secondary">
                                <?php esc_html_e('Import Existing Offloaded Media', 'r2-by-grisma'); ?>
                            </button>
                            <span id="r2g-import-status" style="margin-left:8px; font-size:12px;"></span>
                        </div>

                        <div style="flex:1; min-width:280px; padding:16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                            <h3 style="margin-top:0; font-size:14px; font-weight:600; color:#0f172a;"><?php esc_html_e('Scan & Refresh Media Index', 'r2-by-grisma'); ?></h3>
                            <p style="font-size:12px; color:#64748b; margin-bottom:14px;"><?php esc_html_e('Verifies local server files against the database table and repairs any missing records or desynced attachment states.', 'r2-by-grisma'); ?></p>
                            <button type="button" id="r2g-btn-reindex" class="button button-secondary">
                                <?php esc_html_e('Scan & Refresh Index', 'r2-by-grisma'); ?>
                            </button>
                            <span id="r2g-reindex-status" style="margin-left:8px; font-size:12px;"></span>
                        </div>
                    </div>
                </div>

            <!-- TAB 4: UPDATES & ROLLBACK -->
            <?php elseif ($active_tab === 'updates'): ?>
                <?php
                $updater     = R2G_Updater::instance();
                $latest      = $updater->get_latest_release();
                $releases    = $updater->get_all_releases();
                $plugin_file = $updater->get_plugin_basename();
                $has_update  = !empty($latest['version']) && version_compare(R2G_VERSION, $latest['version'], '<');
                $check_url   = wp_nonce_url(add_query_arg(array('page' => 'r2-by-grisma', 'tab' => 'updates', 'r2g_check_updates' => '1'), admin_url('options-general.php')), 'r2g_manual_check_nonce');
                ?>
                <div class="r2g-card" id="r2g-updates-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
                        <div>
                            <h2 style="margin:0 0 4px 0;"><?php esc_html_e('Updates & Rollback Pipeline', 'r2-by-grisma'); ?></h2>
                            <p class="description" style="margin:0;"><?php esc_html_e('Native WordPress Core updates synced with GitHub Releases. Zero risky script execution.', 'r2-by-grisma'); ?></p>
                        </div>
                        <a href="<?php echo esc_url($check_url); ?>" id="r2g-btn-check-updates" class="button button-secondary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px; margin-right:4px;"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                            <?php esc_html_e('Check for Updates Now', 'r2-by-grisma'); ?>
                        </a>
                    </div>

                    <div class="r2g-update-summary" style="display:flex; gap:20px; align-items:center; padding:14px 18px; border-radius:8px; background:#f8fafc; border:1px solid #e2e8f0; margin-bottom:18px;">
                        <div>
                            <span style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:#64748b; font-weight:600;"><?php esc_html_e('Installed Version', 'r2-by-grisma'); ?></span>
                            <div style="font-size:16px; font-weight:700; color:#0f172a;">v<?php echo esc_html(R2G_VERSION); ?></div>
                        </div>
                        <div style="height:32px; width:1px; background:#cbd5e1;"></div>
                        <div>
                            <span style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:#64748b; font-weight:600;"><?php esc_html_e('Latest Release on GitHub', 'r2-by-grisma'); ?></span>
                            <div style="font-size:16px; font-weight:700; color:<?php echo $has_update ? '#0284c7' : '#059669'; ?>;">
                                <?php echo !empty($latest['version']) ? 'v' . esc_html($latest['version']) : esc_html__('Checking...', 'r2-by-grisma'); ?>
                            </div>
                        </div>
                        <div style="height:32px; width:1px; background:#cbd5e1;"></div>
                        <div style="flex:1;">
                            <?php if ($has_update): ?>
                                <?php
                                $upgrade_url = wp_nonce_url(
                                    admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($plugin_file)),
                                    'upgrade-plugin_' . $plugin_file
                                );
                                ?>
                                <span class="r2g-badge r2g-badge-cloud" style="font-size:12px; padding:4px 10px; margin-bottom:4px;">
                                    <?php esc_html_e('Update Available', 'r2-by-grisma'); ?>
                                </span>
                                <div style="margin-top:6px;">
                                    <a href="<?php echo esc_url($upgrade_url); ?>" class="button button-primary" style="background:#0284c7; border-color:#0284c7;">
                                        <?php printf(esc_html__('Upgrade to v%s via WP Core', 'r2-by-grisma'), esc_html($latest['version'])); ?>
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="r2g-badge r2g-badge-both" style="font-size:12px; padding:4px 10px;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>
                                    <?php esc_html_e('Plugin is Up to Date', 'r2-by-grisma'); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- GitHub Release & Rollback Table -->
                    <h3 style="font-size:14px; font-weight:600; color:#1e293b; margin:16px 0 10px 0;"><?php esc_html_e('Available Releases & Rollback History', 'r2-by-grisma'); ?></h3>
                    <?php if (!empty($releases) && is_array($releases)): ?>
                        <table class="wp-list-table widefat fixed striped" style="border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;">
                            <thead>
                                <tr>
                                    <th style="width:130px; font-weight:600;"><?php esc_html_e('Release', 'r2-by-grisma'); ?></th>
                                    <th style="width:140px; font-weight:600;"><?php esc_html_e('Published Date', 'r2-by-grisma'); ?></th>
                                    <th style="font-weight:600;"><?php esc_html_e('Release Notes', 'r2-by-grisma'); ?></th>
                                    <th style="width:170px; text-align:right; font-weight:600;"><?php esc_html_e('Action', 'r2-by-grisma'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($releases as $rel): ?>
                                    <?php
                                    $rel_ver = $rel['version'];
                                    $is_current = ($rel_ver === R2G_VERSION);
                                    $is_newer   = version_compare(R2G_VERSION, $rel_ver, '<');
                                    $is_older   = version_compare(R2G_VERSION, $rel_ver, '>');
                                    $rollback_nonce = wp_create_nonce('r2g_rollback_' . $rel_ver);
                                    $target_action_url = add_query_arg(array(
                                        'action'             => 'upgrade-plugin',
                                        'plugin'             => urlencode($plugin_file),
                                        'r2g_target_version' => urlencode($rel_ver),
                                        '_wpnonce'           => $rollback_nonce,
                                    ), admin_url('update.php'));
                                    ?>
                                    <tr>
                                        <td>
                                            <strong>v<?php echo esc_html($rel_ver); ?></strong>
                                            <?php if (!empty($rel['prerelease'])): ?>
                                                <span style="font-size:10px; background:#fef3c7; color:#92400e; padding:1px 5px; border-radius:4px; margin-left:4px;">pre</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color:#64748b; font-size:12px;">
                                            <?php echo !empty($rel['published']) ? esc_html(date_i18n('M j, Y', strtotime($rel['published']))) : '—'; ?>
                                        </td>
                                        <td style="font-size:12px; color:#475569;">
                                            <?php
                                            $notes = wp_strip_all_tags($rel['changelog'] ?? '');
                                            echo esc_html(mb_strimwidth($notes, 0, 100, '...'));
                                            ?>
                                            <?php if (!empty($rel['url'])): ?>
                                                <a href="<?php echo esc_url($rel['url']); ?>" target="_blank" rel="noopener noreferrer" style="font-size:11px; margin-left:6px; color:#2271b1;"><?php esc_html_e('View on GitHub ↗', 'r2-by-grisma'); ?></a>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <?php if ($is_current): ?>
                                                <span class="r2g-badge r2g-badge-both"><?php esc_html_e('Current Active', 'r2-by-grisma'); ?></span>
                                            <?php elseif ($is_newer): ?>
                                                <a href="<?php echo esc_url($target_action_url); ?>" class="button button-small button-primary" style="background:#0284c7; border-color:#0284c7;">
                                                    <?php esc_html_e('Update to this', 'r2-by-grisma'); ?>
                                                </a>
                                            <?php elseif ($is_older): ?>
                                                <a href="<?php echo esc_url($target_action_url); ?>" class="button button-small" onclick="return confirm('<?php echo esc_js(sprintf(__('Are you sure you want to rollback R2 by Grisma to v%s?', 'r2-by-grisma'), $rel_ver)); ?>');" style="color:#b32d2e; border-color:#dcdcde;">
                                                    <?php esc_html_e('Rollback', 'r2-by-grisma'); ?>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="font-size:13px; color:#64748b; font-style:italic;">
                            <?php esc_html_e('No releases discovered yet. Once tagged on GitHub, releases will appear here automatically.', 'r2-by-grisma'); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
