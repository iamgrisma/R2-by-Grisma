<?php
/**
 * Media Library Integration
 * Minimalist status badges (NO cheesy emojis), location filters, and row action controls.
 *
 * @package R2_By_Grisma
 */

if (!defined('ABSPATH')) {
    exit;
}

class R2G_Media_Library {
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

        // Add Column
        add_filter('manage_media_columns', array($this, 'add_location_column'));
        add_action('manage_media_custom_column', array($this, 'render_location_column'), 10, 2);

        // Filter Dropdown
        add_action('restrict_manage_posts', array($this, 'render_location_filter'));
        add_action('pre_get_posts', array($this, 'apply_location_filter'));

        // Row Actions
        add_filter('media_row_actions', array($this, 'add_media_row_actions'), 10, 2);

        // Ajax Handlers
        add_action('wp_ajax_r2g_sync_single', array($this, 'ajax_sync_single'));
        add_action('wp_ajax_r2g_download_single', array($this, 'ajax_download_single'));
        add_action('wp_ajax_r2g_delete_local_single', array($this, 'ajax_delete_local_single'));
        add_action('wp_ajax_r2g_delete_r2_single', array($this, 'ajax_delete_r2_single'));
    }

    /**
     * Add "Storage" column to Media Library
     *
     * @param array $columns
     * @return array
     */
    public function add_location_column($columns) {
        $columns['r2g_storage'] = esc_html__('Storage', 'r2-by-grisma');
        return $columns;
    }

    /**
     * Render Storage Column badge with clean minimal SVG icons (No emojis!)
     *
     * @param string $column_name
     * @param int $post_id
     */
    public function render_location_column($column_name, $post_id) {
        if ($column_name !== 'r2g_storage') {
            return;
        }

        $file_path = get_attached_file($post_id);
        $has_local = !empty($file_path) && file_exists($file_path);
        $is_synced = (bool) get_post_meta($post_id, '_r2g_synced', true);

        if ($is_synced && $has_local) {
            echo '<span class="r2g-badge r2g-badge-both" title="' . esc_attr__('Stored on both local server and Cloudflare R2', 'r2-by-grisma') . '">
                    <svg class="r2g-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                    <span>' . esc_html__('Synced', 'r2-by-grisma') . '</span>
                  </span>';
        } elseif ($is_synced && !$has_local) {
            echo '<span class="r2g-badge r2g-badge-cloud" title="' . esc_attr__('Offloaded to Cloudflare R2 only (Local file deleted to save disk space)', 'r2-by-grisma') . '">
                    <svg class="r2g-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                    <span>' . esc_html__('Cloud', 'r2-by-grisma') . '</span>
                  </span>';
        } elseif ($has_local) {
            echo '<span class="r2g-badge r2g-badge-local" title="' . esc_attr__('Stored on local server only (Not yet pushed to R2)', 'r2-by-grisma') . '">
                    <svg class="r2g-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/></svg>
                    <span>' . esc_html__('Local', 'r2-by-grisma') . '</span>
                  </span>';
        } else {
            echo '<span class="r2g-badge r2g-badge-missing" title="' . esc_attr__('File missing both locally and on Cloudflare R2', 'r2-by-grisma') . '">
                    <svg class="r2g-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                    <span>' . esc_html__('Missing', 'r2-by-grisma') . '</span>
                  </span>';
        }
    }

    /**
     * Add Row Actions under each media item
     *
     * @param array $actions
     * @param WP_Post $post
     * @return array
     */
    public function add_media_row_actions($actions, $post) {
        if ($post->post_type !== 'attachment') {
            return $actions;
        }

        $id = $post->ID;
        $file_path = get_attached_file($id);
        $has_local = !empty($file_path) && file_exists($file_path);
        $is_synced = (bool) get_post_meta($id, '_r2g_synced', true);

        if (!$is_synced && $has_local) {
            $actions['r2g_push'] = sprintf(
                '<a href="#" class="r2g-row-action" data-action="r2g_sync_single" data-id="%d">%s</a>',
                $id,
                esc_html__('Push to R2', 'r2-by-grisma')
            );
        }

        if ($is_synced && !$has_local) {
            $actions['r2g_pull'] = sprintf(
                '<a href="#" class="r2g-row-action" data-action="r2g_download_single" data-id="%d">%s</a>',
                $id,
                esc_html__('Pull to Local', 'r2-by-grisma')
            );
        }

        if ($is_synced && $has_local) {
            $actions['r2g_del_local'] = sprintf(
                '<a href="#" class="r2g-row-action r2g-action-delete" data-action="r2g_delete_local_single" data-id="%d" data-confirm="%s">%s</a>',
                $id,
                esc_attr__('Delete local copy? The file will remain safely on Cloudflare R2.', 'r2-by-grisma'),
                esc_html__('Delete Local Copy', 'r2-by-grisma')
            );
        }

        return $actions;
    }

    /**
     * Filter dropdown in Media Library
     *
     * @param string $post_type
     */
    public function render_location_filter($post_type) {
        if ($post_type !== 'attachment') {
            return;
        }

        $selected = isset($_GET['r2g_filter']) ? sanitize_text_field(wp_unslash($_GET['r2g_filter'])) : '';
        ?>
        <select name="r2g_filter">
            <option value=""><?php esc_html_e('All Storage Locations', 'r2-by-grisma'); ?></option>
            <option value="synced" <?php selected($selected, 'synced'); ?>><?php esc_html_e('Synced (Both)', 'r2-by-grisma'); ?></option>
            <option value="cloud_only" <?php selected($selected, 'cloud_only'); ?>><?php esc_html_e('Cloud Only (R2)', 'r2-by-grisma'); ?></option>
            <option value="local_only" <?php selected($selected, 'local_only'); ?>><?php esc_html_e('Local Only', 'r2-by-grisma'); ?></option>
        </select>
        <?php
    }

    /**
     * Apply filter query in Media Library
     *
     * @param WP_Query $query
     */
    public function apply_location_filter($query) {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'attachment') {
            return;
        }

        $filter = isset($_GET['r2g_filter']) ? sanitize_text_field(wp_unslash($_GET['r2g_filter'])) : '';
        if (empty($filter)) {
            return;
        }

        $meta_query = $query->get('meta_query') ?: array();

        if ($filter === 'synced') {
            $meta_query[] = array('key' => '_r2g_synced', 'value' => '1', 'compare' => '=');
            $meta_query[] = array('key' => '_r2g_local_deleted', 'compare' => 'NOT EXISTS');
        } elseif ($filter === 'cloud_only') {
            $meta_query[] = array('key' => '_r2g_synced', 'value' => '1', 'compare' => '=');
            $meta_query[] = array('key' => '_r2g_local_deleted', 'value' => '1', 'compare' => '=');
        } elseif ($filter === 'local_only') {
            $meta_query[] = array('key' => '_r2g_synced', 'compare' => 'NOT EXISTS');
        }

        $query->set('meta_query', $meta_query);
    }

    /**
     * Ajax: Push single attachment to R2
     */
    public function ajax_sync_single() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error(array('message' => 'Invalid attachment ID'));
        }

        $ok = R2G_Media_Handler::sync_attachment_to_r2($id, true);
        if ($ok) {
            wp_send_json_success(array('message' => 'Successfully uploaded to R2'));
        }
        wp_send_json_error(array('message' => 'Failed to upload to R2'));
    }

    /**
     * Ajax: Pull file back to local server
     */
    public function ajax_download_single() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $id = (int)($_POST['id'] ?? 0);
        $ok = R2G_Media_Handler::download_from_r2_to_local($id);
        if ($ok) {
            wp_send_json_success(array('message' => 'Successfully downloaded to local server'));
        }
        wp_send_json_error(array('message' => 'Failed to download from R2'));
    }

    /**
     * Ajax: Delete local copy (keep R2)
     */
    public function ajax_delete_local_single() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $id = (int)($_POST['id'] ?? 0);
        R2G_Media_Handler::delete_local_files($id);
        wp_send_json_success(array('message' => 'Local file deleted. Cloud copy retained.'));
    }
}
