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
        add_action('wp_ajax_r2g_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_r2g_reindex_media', array($this, 'ajax_reindex_media'));
        add_action('wp_ajax_r2g_import_legacy', array($this, 'ajax_import_legacy'));
    }

    /**
     * Ajax: Sync a batch of unsynced attachments to R2
     * Called repeatedly from the dashboard until all media is synced
     */
    public function ajax_bulk_sync_batch() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $batch_size = (int) ($_POST['batch_size'] ?? 5);
        $batch_size = max(1, min(20, $batch_size));

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
            $ok = R2G_Media_Handler::sync_attachment_to_r2((int) $id, true);
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
     * Scans all attachments and records their current state
     */
    public function ajax_reindex_media() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        // Migrate postmeta data and scan attachments
        $migrated = R2G_Database::import_existing_offloaded();

        wp_send_json_success(array(
            'migrated' => $migrated,
            'stats'    => R2G_Database::get_stats(),
            'message'  => sprintf(esc_html__('Successfully scanned and indexed %d media attachments.', 'r2-by-grisma'), $migrated),
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

        $imported = R2G_Database::import_existing_offloaded();

        wp_send_json_success(array(
            'imported' => $imported,
            'stats'    => R2G_Database::get_stats(),
            'message'  => sprintf(esc_html__('Imported %d existing offloaded media items into R2 by Grisma.', 'r2-by-grisma'), $imported),
        ));
    }
}
