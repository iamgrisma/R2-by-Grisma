<?php
/**
 * Database Layer — Custom R2 Sync Index Table
 *
 * Maintains a local database index of every media attachment's R2 sync state.
 * This eliminates costly Cloudflare R2 Class B (read/list) API operations by
 * keeping sync status, file sizes, and keys indexed locally in MySQL.
 *
 * @package R2_By_Grisma
 */

if (!defined('ABSPATH')) {
    exit;
}

class R2G_Database {
    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Table name (without prefix)
     */
    const TABLE_NAME = 'r2g_media';

    /**
     * Current DB schema version
     */
    const DB_VERSION = '1.0.1';

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get full table name with prefix
     *
     * @return string
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create or upgrade the database table
     * Called on plugin activation and admin_init version check
     */
    public static function create_table() {
        global $wpdb;
        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL,
            r2_key VARCHAR(500) NOT NULL DEFAULT '',
            file_name VARCHAR(255) NOT NULL DEFAULT '',
            mime_type VARCHAR(100) NOT NULL DEFAULT '',
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            original_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            has_local TINYINT(1) NOT NULL DEFAULT 1,
            thumb_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            synced_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY attachment_id (attachment_id),
            KEY status (status),
            KEY r2_key (r2_key(191))
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('r2g_db_version', self::DB_VERSION);
    }

    /**
     * Check if table exists and is current version
     */
    public static function maybe_upgrade() {
        $installed_version = get_option('r2g_db_version', '0');
        if (version_compare($installed_version, self::DB_VERSION, '<')) {
            self::create_table();
        }
    }

    /**
     * Record or update a media attachment's R2 sync status
     *
     * @param int    $attachment_id
     * @param array  $data Keys: r2_key, file_name, mime_type, file_size, original_size, status, has_local, thumb_count
     * @return bool
     */
    public static function upsert($attachment_id, $data = array()) {
        global $wpdb;
        $table = self::table();

        $defaults = array(
            'r2_key'        => '',
            'file_name'     => '',
            'mime_type'     => '',
            'file_size'     => 0,
            'original_size' => 0,
            'status'        => 'pending',
            'has_local'     => 1,
            'thumb_count'   => 0,
            'synced_at'     => null,
        );
        $data = wp_parse_args($data, $defaults);

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE attachment_id = %d",
            $attachment_id
        ));

        if ($existing) {
            $update_data = array(
                'r2_key'        => $data['r2_key'],
                'file_name'     => $data['file_name'],
                'mime_type'     => $data['mime_type'],
                'file_size'     => $data['file_size'],
                'original_size' => $data['original_size'],
                'status'        => $data['status'],
                'has_local'     => $data['has_local'],
                'thumb_count'   => $data['thumb_count'],
            );
            if (!empty($data['synced_at'])) {
                $update_data['synced_at'] = $data['synced_at'];
            }
            return $wpdb->update($table, $update_data, array('attachment_id' => $attachment_id)) !== false;
        }

        return $wpdb->insert($table, array(
            'attachment_id' => $attachment_id,
            'r2_key'        => $data['r2_key'],
            'file_name'     => $data['file_name'],
            'mime_type'     => $data['mime_type'],
            'file_size'     => $data['file_size'],
            'original_size' => $data['original_size'],
            'status'        => $data['status'],
            'has_local'     => $data['has_local'],
            'thumb_count'   => $data['thumb_count'],
            'synced_at'     => $data['synced_at'],
        )) !== false;
    }

    /**
     * Mark attachment as synced
     *
     * @param int    $attachment_id
     * @param string $r2_key
     * @param int    $file_size
     * @param int    $original_size
     * @param int    $thumb_count
     * @return bool
     */
    public static function mark_synced($attachment_id, $r2_key, $file_size = 0, $original_size = 0, $thumb_count = 0) {
        $post = get_post($attachment_id);
        $file_path = get_attached_file($attachment_id);
        $has_local = !empty($file_path) && file_exists($file_path) && (filesize($file_path) > 300);

        return self::upsert($attachment_id, array(
            'r2_key'        => $r2_key,
            'file_name'     => $post ? basename($file_path ?: '') : '',
            'mime_type'     => $post ? $post->post_mime_type : '',
            'file_size'     => $file_size,
            'original_size' => $original_size ?: $file_size,
            'status'        => 'synced',
            'has_local'     => $has_local ? 1 : 0,
            'thumb_count'   => $thumb_count,
            'synced_at'     => current_time('mysql'),
        ));
    }

    /**
     * Mark attachment sync as failed
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function mark_failed($attachment_id) {
        global $wpdb;
        $table = self::table();

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE attachment_id = %d",
            $attachment_id
        ));

        if ($existing) {
            return $wpdb->update($table, array('status' => 'failed'), array('attachment_id' => $attachment_id)) !== false;
        }

        $post = get_post($attachment_id);
        return self::upsert($attachment_id, array(
            'file_name' => $post ? basename(get_attached_file($attachment_id)) : '',
            'mime_type' => $post ? $post->post_mime_type : '',
            'status'    => 'failed',
        ));
    }

    /**
     * Mark attachment as deleted (from R2 or entirely)
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function mark_deleted($attachment_id) {
        global $wpdb;
        return $wpdb->update(
            self::table(),
            array('status' => 'deleted'),
            array('attachment_id' => $attachment_id)
        ) !== false;
    }

    /**
     * Remove record entirely (when attachment is permanently deleted from WP)
     *
     * @param int $attachment_id
     * @return bool
     */
    public static function remove($attachment_id) {
        global $wpdb;
        return $wpdb->delete(self::table(), array('attachment_id' => $attachment_id)) !== false;
    }

    /**
     * Update local file existence flag
     *
     * @param int  $attachment_id
     * @param bool $has_local
     * @return bool
     */
    public static function set_has_local($attachment_id, $has_local) {
        global $wpdb;
        return $wpdb->update(
            self::table(),
            array('has_local' => $has_local ? 1 : 0),
            array('attachment_id' => $attachment_id)
        ) !== false;
    }

    /**
     * Get single record by attachment ID
     *
     * @param int $attachment_id
     * @return object|null
     */
    public static function get($attachment_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE attachment_id = %d",
            $attachment_id
        ));
    }

    /**
     * Get aggregated stats for the dashboard
     *
     * @return array
     */
    public static function get_stats() {
        global $wpdb;
        $table = self::table();

        // Total media in WordPress
        $total_wp = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash'"
        );

        // Stats from our index table
        $synced = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'synced'");
        $synced_both = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'synced' AND has_local = 1");
        $cloud_only = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'synced' AND has_local = 0");
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
        $failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'failed'");

        // Not indexed (in WP but not tracked in our table)
        $indexed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status != 'deleted'");
        $not_indexed = max(0, $total_wp - $indexed);
        $local_only = $not_indexed + (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status != 'synced' AND has_local = 1");

        // Size stats
        $total_original = (int) $wpdb->get_var("SELECT COALESCE(SUM(original_size), 0) FROM {$table} WHERE status = 'synced'");
        $total_compressed = (int) $wpdb->get_var("SELECT COALESCE(SUM(file_size), 0) FROM {$table} WHERE status = 'synced'");
        $total_thumbs = (int) $wpdb->get_var("SELECT COALESCE(SUM(thumb_count), 0) FROM {$table} WHERE status = 'synced'");

        // Verified synced attachments that still exist locally and can be cleaned
        $verified_with_local = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'synced' AND has_local = 1 AND r2_key != ''");

        return array(
            'total_wp'            => $total_wp,
            'synced'              => $synced,
            'synced_both'         => $synced_both,
            'cloud_only'          => $cloud_only,
            'local_only'          => $local_only,
            'pending'             => $pending,
            'failed'              => $failed,
            'not_indexed'         => $not_indexed,
            'total_original'      => $total_original,
            'total_compressed'    => $total_compressed,
            'savings_bytes'       => max(0, $total_original - $total_compressed),
            'total_thumbs'        => $total_thumbs,
            'verified_with_local' => $verified_with_local,
        );
    }

    /**
     * Get attachment IDs that are verified synced to R2 and still have local files
     *
     * @param int $limit
     * @return array
     */
    public static function get_verified_synced_with_local_ids($limit = 50) {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_col($wpdb->prepare(
            "SELECT attachment_id FROM {$table}
             WHERE status = 'synced' AND has_local = 1 AND r2_key != ''
             ORDER BY attachment_id DESC
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Get count of verified synced attachments that still have local files
     *
     * @return int
     */
    public static function count_verified_synced_with_local() {
        global $wpdb;
        $table = self::table();

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE status = 'synced' AND has_local = 1 AND r2_key != ''"
        );
    }

    /**
     * Get count of attachments not yet synced to R2
     *
     * @return int
     */
    public static function count_unsynced() {
        global $wpdb;
        $table = self::table();

        return (int) $wpdb->get_var(
            "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$table} r ON p.ID = r.attachment_id AND r.status = 'synced'
             WHERE p.post_type = 'attachment' AND p.post_status != 'trash' AND r.id IS NULL"
        );
    }

    /**
     * Get attachment IDs not yet synced to R2
     *
     * @param int $limit
     * @return array
     */
    public static function get_unsynced_ids($limit = 50) {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$table} r ON p.ID = r.attachment_id AND r.status = 'synced'
             WHERE p.post_type = 'attachment' AND p.post_status != 'trash' AND r.id IS NULL
             ORDER BY p.ID DESC
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Get recently synced records for activity log
     *
     * @param int $limit
     * @return array
     */
    public static function get_recent_activity($limit = 10) {
        global $wpdb;
        $table = self::table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.post_title FROM {$table} r
             LEFT JOIN {$wpdb->posts} p ON r.attachment_id = p.ID
             WHERE r.status IN ('synced', 'failed')
             ORDER BY r.updated_at DESC
             LIMIT %d",
            $limit
        ));
    }

    /**
     * One-time migration of legacy postmeta sync records into database index
     *
     * @return int Number of records migrated
     */
    public static function migrate_from_postmeta() {
        global $wpdb;
        $table = self::table();

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return 0;
        }

        $migrated = 0;
        $items = $wpdb->get_results(
            "SELECT p.ID, p.post_mime_type, pm.meta_value as r2_key
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_s ON p.ID = pm_s.post_id AND pm_s.meta_key = '_r2g_synced'
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_r2g_key'
             LEFT JOIN {$table} db ON p.ID = db.attachment_id
             WHERE p.post_type = 'attachment' AND db.id IS NULL
             LIMIT 200"
        );

        if (!empty($items)) {
            foreach ($items as $item) {
                $id = (int) $item->ID;
                $file = get_attached_file($id);
                $has_local = !empty($file) && file_exists($file);
                $meta = wp_get_attachment_metadata($id);
                $file_size = $has_local ? filesize($file) : (isset($meta['filesize']) ? $meta['filesize'] : 0);
                $thumb_count = !empty($meta['sizes']) ? count($meta['sizes']) : 0;
                $key = !empty($item->r2_key) ? $item->r2_key : (class_exists('R2G_Media_Handler') ? R2G_Media_Handler::get_r2_key_from_path($file) : basename($file ?: ''));

                self::upsert($id, array(
                    'r2_key'        => $key,
                    'file_name'     => basename($file ?: ''),
                    'mime_type'     => $item->post_mime_type,
                    'file_size'     => (int) $file_size,
                    'original_size' => (int) $file_size,
                    'status'        => 'synced',
                    'has_local'     => $has_local ? 1 : 0,
                    'thumb_count'   => $thumb_count,
                    'synced_at'     => current_time('mysql'),
                ));
                $migrated++;
            }
        }

        update_option('r2g_postmeta_migrated', '1');
        return $migrated;
    }

    /**
     * Comprehensive Import of Existing Offloaded Media
     * Scans Media Cloud Sync's native table (wpmcs_items), missing local files,
     * dummy placeholder files, and legacy postmeta.
     *
     * @return array [ 'imported' => int, 'found_wpmcs' => int, 'found_offloaded' => int ]
     */
    public static function import_existing_offloaded() {
        global $wpdb;
        $table = self::table();

        // 1. Check Media Cloud Sync (Dudlewebs) MySQL table
        $wpmcs_table = class_exists('Dudlewebs\\WPMCS\\Db')
            ? \Dudlewebs\WPMCS\Db::get_table_name()
            : $wpdb->prefix . 'wpmcs_items';

        $wpmcs_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpmcs_table));
        $wpmcs_synced_ids = array();

        if ($wpmcs_exists) {
            $wpmcs_synced_ids = $wpdb->get_col(
                "SELECT DISTINCT source_id FROM {$wpmcs_table} WHERE source_type = 'media_library'"
            );
        }

        // Also check any other table matching wpmcs or media_cloud
        $extra_tables = $wpdb->get_col("SHOW TABLES LIKE '%wpmcs%'");
        foreach ($extra_tables as $ext_tbl) {
            if ($ext_tbl !== $wpmcs_table) {
                $cols = $wpdb->get_col("DESCRIBE {$ext_tbl}");
                if (in_array('source_id', $cols)) {
                    $extra_ids = $wpdb->get_col("SELECT DISTINCT source_id FROM {$ext_tbl}");
                    $wpmcs_synced_ids = array_merge($wpmcs_synced_ids, $extra_ids);
                }
            }
        }
        $wpmcs_synced_ids = array_unique(array_map('intval', $wpmcs_synced_ids));

        // 2. Fetch all WordPress attachments
        $attachments = $wpdb->get_results(
            "SELECT ID, post_mime_type FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash'"
        );

        $imported = 0;
        $found_wpmcs = 0;
        $found_offloaded = 0;

        foreach ($attachments as $att) {
            $id = (int) $att->ID;
            $file_path = get_attached_file($id);
            $has_local_file = !empty($file_path) && file_exists($file_path);
            $local_size = $has_local_file ? filesize($file_path) : 0;
            // Dummy placeholder detection: Media Cloud Sync leaves 111-byte placeholders when offloading
            $is_dummy_placeholder = $has_local_file && ($local_size > 0 && $local_size < 300);

            $is_wpmcs = in_array($id, $wpmcs_synced_ids);
            $has_legacy_meta = (
                get_post_meta($id, '_mcs_synced', true) ||
                get_post_meta($id, '_media_cloud_sync_synced', true) ||
                get_post_meta($id, '_cloud_url', true) ||
                get_post_meta($id, '_amazonS3_info', true) ||
                get_post_meta($id, '_r2g_synced', true)
            );

            // Is offloaded if: in WPMCS table, or local file missing, or dummy placeholder, or legacy meta
            $is_synced = $is_wpmcs || $has_legacy_meta || (!$has_local_file && !empty($file_path)) || $is_dummy_placeholder;

            if ($is_synced) {
                if ($is_wpmcs) $found_wpmcs++;
                if (!$has_local_file || $is_dummy_placeholder) $found_offloaded++;

                $main_r2_key = class_exists('R2G_Media_Handler')
                    ? R2G_Media_Handler::get_r2_key_from_path($file_path)
                    : 'wp-content/uploads/' . basename($file_path ?: '');

                $metadata = wp_get_attachment_metadata($id);
                $thumb_count = !empty($metadata['sizes']) ? count($metadata['sizes']) : 0;
                $file_size = ($has_local_file && !$is_dummy_placeholder) ? $local_size : ($metadata['filesize'] ?? 0);

                $real_has_local = $has_local_file && !$is_dummy_placeholder;

                self::upsert($id, array(
                    'r2_key'        => $main_r2_key,
                    'file_name'     => basename($file_path ?: ''),
                    'mime_type'     => $att->post_mime_type,
                    'file_size'     => $file_size,
                    'original_size' => $file_size,
                    'status'        => 'synced',
                    'has_local'     => $real_has_local ? 1 : 0,
                    'thumb_count'   => $thumb_count,
                    'synced_at'     => current_time('mysql'),
                ));

                // Also update native postmeta for full WordPress compatibility
                update_post_meta($id, '_r2g_synced', 1);
                update_post_meta($id, '_r2g_key', $main_r2_key);
                if (!$real_has_local) {
                    update_post_meta($id, '_r2g_local_deleted', 1);
                }

                $imported++;
            } else {
                // Register as local/pending
                self::upsert($id, array(
                    'file_name' => basename($file_path ?: ''),
                    'mime_type' => $att->post_mime_type,
                    'status'    => 'pending',
                    'has_local' => 1,
                ));
            }
        }

        return array(
            'imported'        => $imported,
            'found_wpmcs'     => $found_wpmcs,
            'found_offloaded' => $found_offloaded,
        );
    }
}
