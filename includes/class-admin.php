<?php
/**
 * Admin Settings & Dashboard
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

        // Ajax Test Connection
        add_action('wp_ajax_r2g_test_connection', array($this, 'ajax_test_connection'));
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
        $allowed = array('settings_page_r2-by-grisma', 'upload.php', 'post.php', 'post-new.php');
        if (!in_array($hook, $allowed)) {
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

        // Enqueue Browser Edge Compressor on post editors & upload pages
        if (in_array($hook, array('upload.php', 'post.php', 'post-new.php'))) {
            wp_enqueue_script(
                'r2g-browser-compress-js',
                R2G_URL . 'assets/js/browser-compress.js',
                array('jquery'),
                R2G_VERSION,
                true
            );

            $engine = get_option('r2g_compress_engine', 'browser');
            $format = get_option('r2g_compress_format', 'webp');
            $quality = ((int)get_option('r2g_compress_quality', 82)) / 100;
            $max_width = (int)get_option('r2g_max_width', 1920);
            $prompt = (bool)get_option('r2g_prompt_confirm', true);

            wp_localize_script('r2g-browser-compress-js', 'r2g_compress_config', array(
                'enabled'       => ($engine === 'browser' && $format !== 'none'),
                'engine'        => $engine,
                'format'        => $format,
                'quality'       => $quality,
                'maxWidth'      => $max_width,
                'promptConfirm' => $prompt,
            ));
        }
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

        // Credentials
        update_option('r2g_account_id', sanitize_text_field(wp_unslash($_POST['r2g_account_id'] ?? '')));
        update_option('r2g_access_key', sanitize_text_field(wp_unslash($_POST['r2g_access_key'] ?? '')));
        update_option('r2g_bucket', sanitize_text_field(wp_unslash($_POST['r2g_bucket'] ?? '')));

        // Secret key (only update if non-empty and changed from masked string)
        $posted_secret = trim($_POST['r2g_secret_key'] ?? '');
        if (!empty($posted_secret) && strpos($posted_secret, '••••') === false) {
            $encrypted = R2G_Encryption::encrypt($posted_secret);
            update_option('r2g_secret_key', $encrypted);
        }

        // Custom CDN Domain (ensure no trailing slash, add https:// if missing)
        $domain = trim(sanitize_text_field(wp_unslash($_POST['r2g_custom_domain'] ?? '')));
        if (!empty($domain) && strpos($domain, 'http') !== 0) {
            $domain = 'https://' . $domain;
        }
        update_option('r2g_custom_domain', rtrim($domain, '/'));

        // Storage & Compression Options
        update_option('r2g_storage_mode', sanitize_text_field(wp_unslash($_POST['r2g_storage_mode'] ?? 'both')));
        update_option('r2g_compress_engine', sanitize_text_field(wp_unslash($_POST['r2g_compress_engine'] ?? 'browser')));
        update_option('r2g_compress_format', sanitize_text_field(wp_unslash($_POST['r2g_compress_format'] ?? 'webp')));
        update_option('r2g_compress_quality', max(60, min(100, (int)($_POST['r2g_compress_quality'] ?? 82))));
        update_option('r2g_max_width', max(0, (int)($_POST['r2g_max_width'] ?? 1920)));
        update_option('r2g_prompt_confirm', !empty($_POST['r2g_prompt_confirm']) ? 1 : 0);

        add_settings_error('r2g_messages', 'r2g_saved', esc_html__('Settings saved successfully.', 'r2-by-grisma'), 'updated');
    }

    /**
     * Ajax: Test R2 Connection and Custom Domain verification
     */
    public function ajax_test_connection() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $client = r2_by_grisma()->get_client();
        if (!$client || !$client->is_configured()) {
            wp_send_json_error(array('message' => 'Cloudflare R2 credentials are incomplete. Please fill and save all fields.'));
        }

        $custom_domain = get_option('r2g_custom_domain', '');
        $res = $client->test_connection($custom_domain);

        if ($res['success']) {
            wp_send_json_success($res);
        } else {
            wp_send_json_error($res);
        }
    }

    /**
     * Render Settings Page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $account_id    = get_option('r2g_account_id', '');
        $access_key    = get_option('r2g_access_key', '');
        $raw_secret    = R2G_Encryption::decrypt(get_option('r2g_secret_key', ''));
        $masked_secret = R2G_Encryption::mask($raw_secret);
        $bucket        = get_option('r2g_bucket', '');
        $custom_domain = get_option('r2g_custom_domain', '');

        $storage_mode  = get_option('r2g_storage_mode', 'both');
        $engine        = get_option('r2g_compress_engine', 'browser');
        $format        = get_option('r2g_compress_format', 'webp');
        $quality       = (int)get_option('r2g_compress_quality', 82);
        $max_width     = (int)get_option('r2g_max_width', 1920);
        $prompt        = (bool)get_option('r2g_prompt_confirm', true);
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

            <form method="post" action="">
                <?php wp_nonce_field('r2g_save_settings_nonce'); ?>

                <!-- SECTION 1: Cloudflare R2 Credentials -->
                <div class="r2g-card">
                    <h2><?php esc_html_e('Cloudflare R2 Connection', 'r2-by-grisma'); ?></h2>
                    <p class="description"><?php esc_html_e('Configure your S3-compatible Cloudflare R2 API credentials and Public Custom CDN domain.', 'r2-by-grisma'); ?></p>

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
                                <p class="description"><?php esc_html_e('Securely stored using AES-256 encryption.', 'r2-by-grisma'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="r2g_bucket"><?php esc_html_e('R2 Bucket Name', 'r2-by-grisma'); ?></label></th>
                            <td>
                                <input type="text" id="r2g_bucket" name="r2g_bucket" value="<?php echo esc_attr($bucket); ?>" class="r2g-input" placeholder="e.g. topnepali" required />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="r2g_custom_domain"><?php esc_html_e('Custom CDN Domain', 'r2-by-grisma'); ?></label></th>
                            <td>
                                <input type="url" id="r2g_custom_domain" name="r2g_custom_domain" value="<?php echo esc_attr($custom_domain); ?>" class="r2g-input" placeholder="https://objects.topnepali.com" />
                                <p class="description"><?php esc_html_e('Your public custom domain or R2.dev public URL connected to this bucket.', 'r2-by-grisma'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top: 18px;">
                        <button type="button" id="r2g-btn-test-connection" class="button button-secondary">
                            <?php esc_html_e('Test Connection & Verify CDN', 'r2-by-grisma'); ?>
                        </button>
                        <div id="r2g-test-status" style="display:none;" class="r2g-test-box"></div>
                    </div>
                </div>

                <!-- SECTION 2: Image Compression Pipeline -->
                <div class="r2g-card">
                    <h2><?php esc_html_e('Image Compression Pipeline', 'r2-by-grisma'); ?></h2>
                    <p class="description"><?php esc_html_e('Choose where and how image compression and WebP conversion take place.', 'r2-by-grisma'); ?></p>

                    <table class="r2g-form-table">
                        <tr>
                            <th><?php esc_html_e('Compression Engine', 'r2-by-grisma'); ?></th>
                            <td>
                                <div class="r2g-radio-group">
                                    <label class="r2g-radio-pill">
                                        <input type="radio" name="r2g_compress_engine" value="browser" <?php checked($engine, 'browser'); ?> />
                                        <span><?php esc_html_e('Browser Edge (Client-Side)', 'r2-by-grisma'); ?></span>
                                    </label>
                                    <label class="r2g-radio-pill">
                                        <input type="radio" name="r2g_compress_engine" value="server" <?php checked($engine, 'server'); ?> />
                                        <span><?php esc_html_e('Local Server (GD / Imagick)', 'r2-by-grisma'); ?></span>
                                    </label>
                                    <label class="r2g-radio-pill">
                                        <input type="radio" name="r2g_compress_engine" value="resmush_async" <?php checked($engine, 'resmush_async'); ?> />
                                        <span><?php esc_html_e('Async Background (reSmush.it API)', 'r2-by-grisma'); ?></span>
                                    </label>
                                    <label class="r2g-radio-pill">
                                        <input type="radio" name="r2g_compress_engine" value="none" <?php checked($engine, 'none'); ?> />
                                        <span><?php esc_html_e('Disabled', 'r2-by-grisma'); ?></span>
                                    </label>
                                </div>
                                <p class="description"><?php esc_html_e('Browser Edge compresses on your computer before uploading, saving upload bandwidth. Async Background allows instant upload while compression updates in background.', 'r2-by-grisma'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Target Format', 'r2-by-grisma'); ?></th>
                            <td>
                                <select name="r2g_compress_format" class="r2g-input" style="max-width:280px;">
                                    <option value="webp" <?php selected($format, 'webp'); ?>><?php esc_html_e('WebP (Recommended: 60-80% smaller)', 'r2-by-grisma'); ?></option>
                                    <option value="original" <?php selected($format, 'original'); ?>><?php esc_html_e('Keep Original Format (Compressed JPEG/PNG)', 'r2-by-grisma'); ?></option>
                                    <option value="none" <?php selected($format, 'none'); ?>><?php esc_html_e('Do Not Convert', 'r2-by-grisma'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="r2g_compress_quality"><?php esc_html_e('Compression Quality', 'r2-by-grisma'); ?></label></th>
                            <td>
                                <input type="number" id="r2g_compress_quality" name="r2g_compress_quality" value="<?php echo esc_attr($quality); ?>" min="60" max="100" class="r2g-input" style="max-width:120px;" /> %
                                <p class="description"><?php esc_html_e('82% gives optimal balance of visual crispness and microscopic file size.', 'r2-by-grisma'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="r2g_max_width"><?php esc_html_e('Max Image Width', 'r2-by-grisma'); ?></label></th>
                            <td>
                                <input type="number" id="r2g_max_width" name="r2g_max_width" value="<?php echo esc_attr($max_width); ?>" min="0" step="100" class="r2g-input" style="max-width:140px;" /> px
                                <p class="description"><?php esc_html_e('Resizes oversized camera photos (e.g. 4000px) down to standard web resolution (1920px). Set 0 to disable.', 'r2-by-grisma'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Pre-Upload Confirmation', 'r2-by-grisma'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="r2g_prompt_confirm" value="1" <?php checked($prompt, true); ?> />
                                    <?php esc_html_e('Prompt confirmation popup during upload allowing per-image format/quality changes', 'r2-by-grisma'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- SECTION 3: Storage Policy -->
                <div class="r2g-card">
                    <h2><?php esc_html_e('Server Storage Policy', 'r2-by-grisma'); ?></h2>
                    <p class="description"><?php esc_html_e('Decide whether local copies stay on your web hosting server or get deleted once confirmed on Cloudflare R2.', 'r2-by-grisma'); ?></p>

                    <div class="r2g-radio-group">
                        <label class="r2g-radio-pill">
                            <input type="radio" name="r2g_storage_mode" value="both" <?php checked($storage_mode, 'both'); ?> />
                            <span><?php esc_html_e('Both (Keep Local Copy + R2 Cloud Backup)', 'r2-by-grisma'); ?></span>
                        </label>
                        <label class="r2g-radio-pill">
                            <input type="radio" name="r2g_storage_mode" value="r2_only" <?php checked($storage_mode, 'r2_only'); ?> />
                            <span><?php esc_html_e('R2 Cloud Only (Delete Local Files to Save Server Disk Space)', 'r2-by-grisma'); ?></span>
                        </label>
                    </div>
                </div>

                <p class="submit">
                    <input type="submit" name="r2g_save_settings" id="submit" class="button button-primary button-large" value="<?php esc_attr_e('Save All Changes', 'r2-by-grisma'); ?>" />
                </p>
            </form>

            <!-- SECTION 4: GitHub Core Updates & Rollback -->
            <?php
            $updater     = R2G_Updater::instance();
            $latest      = $updater->get_latest_release();
            $releases    = $updater->get_all_releases();
            $plugin_file = $updater->get_plugin_basename();
            $has_update  = !empty($latest['version']) && version_compare(R2G_VERSION, $latest['version'], '<');
            $check_url   = wp_nonce_url(add_query_arg('r2g_check_updates', '1'), 'r2g_manual_check_nonce');
            ?>
            <div class="r2g-card" id="r2g-updates-card" style="margin-top:28px;">
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
        </div>
        <?php
    }
}
