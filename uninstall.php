<?php
/**
 * Uninstall R2 by Grisma
 *
 * Cleans up options, transients, temporary working files, and optionally the custom
 * database table and postmeta when the plugin is deleted via WordPress Admin.
 *
 * @package R2_By_Grisma
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Execute cleanup tasks for a single blog/site
 */
function r2g_uninstall_site() {
    global $wpdb;

    $delete_data = (int) get_option('r2g_delete_data_on_uninstall', 0);

    // 1. Delete all plugin options
    $options = array(
        'r2g_account_id',
        'r2g_access_key',
        'r2g_secret_key',
        'r2g_bucket',
        'r2g_custom_domain',
        'r2g_path_structure',
        'r2g_path_prefix',
        'r2g_upload_sizes',
        'r2g_cleanup_scope',
        'r2g_auto_upload',
        'r2g_rewrite_urls',
        'r2g_delete_from_r2',
        'r2g_storage_mode',
        'r2g_compress_engine',
        'r2g_compress_enabled',
        'r2g_compress_format',
        'r2g_compress_quality',
        'r2g_max_width',
        'r2g_interceptor_enabled',
        'r2g_active_preset',
        'r2g_upload_workflow',
        'r2g_db_version',
        'r2g_v109_migrated',
        'r2g_v122_migrated',
        'r2g_postmeta_migrated',
        'r2g_delete_data_on_uninstall',
    );

    foreach ($options as $opt) {
        delete_option($opt);
    }

    // 2. Delete transients
    delete_transient('r2g_github_latest_release');
    delete_transient('r2g_github_all_releases');
    delete_transient('r2g_resmush_last_call');
    delete_transient('r2g_resmush_backoff');
    delete_site_transient('update_plugins');

    // 3. Optional deep cleanup: drop custom table and remove postmeta if opted in
    if ($delete_data === 1) {
        // Drop media sync table
        $table_name = $wpdb->prefix . 'r2g_media';
        $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");

        // Delete postmeta
        $meta_keys = array(
            '_r2g_synced',
            '_r2g_synced_at',
            '_r2g_key',
            '_r2g_keys',
            '_r2g_local_deleted',
            '_r2g_optimized',
            '_r2g_opt_info',
        );

        foreach ($meta_keys as $mk) {
            $wpdb->delete($wpdb->postmeta, array('meta_key' => $mk), array('%s'));
        }
    }

    // 4. Clean up temporary working directory in uploads
    $upload_dir = wp_upload_dir();
    $temp_dir   = trailingslashit($upload_dir['basedir']) . 'r2g-temp';
    if (is_dir($temp_dir)) {
        $files = glob($temp_dir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        @rmdir($temp_dir);
    }
}

if (is_multisite()) {
    $sites = get_sites(array('fields' => 'ids'));
    if (!empty($sites) && is_array($sites)) {
        foreach ($sites as $blog_id) {
            switch_to_blog($blog_id);
            r2g_uninstall_site();
            restore_current_blog();
        }
    } else {
        r2g_uninstall_site();
    }
} else {
    r2g_uninstall_site();
}
