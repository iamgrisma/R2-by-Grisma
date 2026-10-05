<?php
/**
 * Bulk Sync Engine
 *
 * Handles background batch sync of existing unsynced media to R2,
 * provides AJAX endpoints for progressive sync from the dashboard.
 *
 * @package R2_By_Grisma
 */

if (!defined('ABSPATH')) {
    exit;
}

class R2G_Sync {
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
        // Ajax handlers
        add_action('wp_ajax_r2g_bulk_sync_batch', array($this, 'ajax_bulk_sync_batch'));
        add_action('wp_ajax_r2g_bulk_clean_verified_local', array($this, 'ajax_bulk_clean_verified_local'));
        add_action('wp_ajax_r2g_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_r2g_reindex_media', array($this, 'ajax_reindex_media'));
        add_action('wp_ajax_r2g_import_legacy', array($this, 'ajax_import_legacy'));
    }

    /**
     * Ajax: Sync a batch of unsynced attachments to R2
     * NEVER auto-deletes local files during batch sync to ensure zero risk of data loss.
     */
    public function ajax_bulk_sync_batch() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $client = r2_by_grisma()->get_client();
        if (!$client || !$client->is_configured()) {
            wp_send_json_error(array(
                'message' => 'Cloudflare R2 is not configured. Please enter your Account ID, Access Key, Secret Key, and Bucket in the Setup & API Keys tab first.',
            ));
        }

        $batch_size = (int) ($_POST['batch_size'] ?? 5);
        $batch_size = max(1, min(20, $batch_size));

        // Read batch wildcard conversion options
        $preset  = sanitize_text_field($_POST['preset'] ?? '');
        $format  = sanitize_text_field($_POST['format'] ?? '');
        $quality = isset($_POST['quality']) ? (int)$_POST['quality'] : 0;
        $engine  = sanitize_text_field($_POST['engine'] ?? 'server');

        $batch_options = array();
        if (!empty($preset) && $preset !== 'keep_current' && $preset !== 'none') {
            $presets = R2G_Media_Handler::get_presets();
            if (isset($presets[$preset])) {
                $batch_options = $presets[$preset];
            } else {
                $batch_options = array(
                    'format'    => $format ?: 'webp',
                    'quality'   => $quality ?: 82,
                    'max_width' => 1920,
                    'compress'  => 1,
                );
            }
            if ($quality > 0) {
                $batch_options['quality'] = $quality;
            }
            $batch_options['engine'] = $engine;
        }

        $unsynced_ids = R2G_Database::get_unsynced_ids($batch_size);

        if (empty($unsynced_ids)) {
            wp_send_json_success(array(
                'synced_count' => 0,
                'remaining'    => 0,
                'done'         => true,
                'stats'        => R2G_Database::get_stats(),
            ));
        }

        $synced = 0;
        $failed = 0;
        $results = array();

        foreach ($unsynced_ids as $id) {
            // is_bulk_sync = true strictly guarantees local files are preserved as backup!
            $ok = R2G_Media_Handler::sync_attachment_to_r2((int) $id, true, null, true, $batch_options);
            if ($ok) {
                $synced++;
                $results[] = array('id' => $id, 'status' => 'synced');
            } else {
                $failed++;
                R2G_Database::mark_failed((int) $id);
                $results[] = array('id' => $id, 'status' => 'failed');
            }
        }

        // Check how many are still remaining
        $remaining_ids = R2G_Database::get_unsynced_ids(1);
        $remaining = !empty($remaining_ids);

        wp_send_json_success(array(
            'synced_count' => $synced,
            'failed_count' => $failed,
            'remaining'    => $remaining ? true : false,
            'done'         => !$remaining,
            'results'      => $results,
            'stats'        => R2G_Database::get_stats(),
        ));
    }

    /**
     * Ajax: Safely delete local copies of attachments that have been verified on R2.
     * Provides deterministic, reversible space reclamation without risking data loss.
     */
    public function ajax_bulk_clean_verified_local() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $batch_size = (int) ($_POST['batch_size'] ?? 15);
        $batch_size = max(1, min(50, $batch_size));

        $ids = R2G_Database::get_verified_synced_with_local_ids($batch_size);

        if (empty($ids)) {
            wp_send_json_success(array(
                'cleaned_count'       => 0,
                'remaining'           => 0,
                'done'                => true,
                'stats'               => R2G_Database::get_stats(),
                'verified_with_local' => 0,
            ));
        }

        $cleaned = 0;
        foreach ($ids as $id) {
            $attachment_id = (int) $id;
            $record = R2G_Database::get($attachment_id);
            // Verify item has valid R2 key and synced status before cleaning local file
            if ($record && $record->status === 'synced' && !empty($record->r2_key)) {
                R2G_Media_Handler::delete_local_files($attachment_id);
                $cleaned++;
            }
        }

        $remaining_count = R2G_Database::count_verified_synced_with_local();

        wp_send_json_success(array(
            'cleaned_count'       => $cleaned,
            'remaining'           => $remaining_count,
            'done'                => ($remaining_count === 0),
            'stats'               => R2G_Database::get_stats(),
            'verified_with_local' => $remaining_count,
        ));
    }

    /**
     * Ajax: Get current sync stats
     */
    public function ajax_get_stats() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        wp_send_json_success(array(
            'stats'    => R2G_Database::get_stats(),
            'activity' => R2G_Database::get_recent_activity(10),
        ));
    }

    /**
     * Ajax: Re-index all WordPress media into the database
     */
    public function ajax_reindex_media() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $res = R2G_Database::import_existing_offloaded();

        wp_send_json_success(array(
            'migrated' => $res['imported'],
            'stats'    => R2G_Database::get_stats(),
            'message'  => sprintf(
                esc_html__('Scanned library: Indexed %d media attachments (%d from Media Cloud Sync, %d offloaded).', 'r2-by-grisma'),
                $res['imported'],
                $res['found_wpmcs'],
                $res['found_offloaded']
            ),
        ));
    }

    /**
     * Ajax: Import existing offloaded media from Media Cloud Sync / previous offloaders
     */
    public function ajax_import_legacy() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $res = R2G_Database::import_existing_offloaded();

        wp_send_json_success(array(
            'imported' => $res['imported'],
            'stats'    => R2G_Database::get_stats(),
            'message'  => sprintf(
                esc_html__('Successfully imported %d media items (%d from Media Cloud Sync table, %d offloaded). Previews and CDN links active!', 'r2-by-grisma'),
                $res['imported'],
                $res['found_wpmcs'],
                $res['found_offloaded']
            ),
        ));
    }
}
