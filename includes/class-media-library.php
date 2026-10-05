<?php
/**
 * Media Library Integration
 * Minimalist status badges, uncluttered column controls, bulk actions, and attachment modal details.
 * Eliminates stuffed row actions and gives users full control.
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

        // Filter Dropdown in Media Library
        add_action('restrict_manage_posts', array($this, 'render_location_filter'));
        add_action('pre_get_posts', array($this, 'apply_location_filter'));

        // Row Actions (Clean, uncluttered)
        add_filter('media_row_actions', array($this, 'filter_media_row_actions'), 99, 2);

        // Bulk Actions
        add_filter('bulk_actions-upload', array($this, 'register_bulk_actions'));
        add_filter('handle_bulk_actions-upload', array($this, 'handle_bulk_actions'), 10, 3);
        add_action('admin_notices', array($this, 'render_bulk_action_notices'));

        // Attachment Details Modal & Edit screen fields
        add_filter('attachment_fields_to_edit', array($this, 'add_attachment_modal_fields'), 10, 2);

        // Ajax Handlers
        add_action('wp_ajax_r2g_sync_single', array($this, 'ajax_sync_single'));
        add_action('wp_ajax_r2g_download_single', array($this, 'ajax_download_single'));
        add_action('wp_ajax_r2g_delete_local_single', array($this, 'ajax_delete_local_single'));
        add_action('wp_ajax_r2g_delete_r2_single', array($this, 'ajax_delete_r2_single'));
        add_action('wp_ajax_r2g_convert_webp_single', array($this, 'ajax_convert_webp_single'));
    }

    /**
     * Add "Cloudflare R2" column to Media Library
     *
     * @param array $columns
     * @return array
     */
    public function add_location_column($columns) {
        $columns['r2g_storage'] = esc_html__('R2 Cloud Storage', 'r2-by-grisma');
        return $columns;
    }

    /**
     * Render Storage Column badge and actions
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

        // Check sync status from DB or postmeta
        $record = class_exists('R2G_Database') ? R2G_Database::get($post_id) : null;
        $is_synced = false;

        if ($record && $record->status === 'synced') {
            $is_synced = true;
            $has_local = (bool) $record->has_local;
        } elseif (get_post_meta($post_id, '_r2g_synced', true) || get_post_meta($post_id, '_mcs_synced', true)) {
            $is_synced = true;
            $has_local = $has_local && !get_post_meta($post_id, '_r2g_local_deleted', true);
        }

        // Get CDN URL
        $rewriter = class_exists('R2G_URL_Rewriter') ? R2G_URL_Rewriter::instance() : null;
        $cdn_base = $rewriter ? $rewriter->get_cdn_base() : '';
        $raw_url = wp_get_attachment_url($post_id);
        $cdn_url = ($rewriter && !empty($cdn_base)) ? $rewriter->rewrite_url($raw_url) : $raw_url;

        echo '<div class="r2g-col-wrap">';

        // 1. Status Badge
        if ($is_synced && $has_local) {
            echo '<span class="r2g-badge r2g-badge-both" title="' . esc_attr__('Synced on both Local Server and Cloudflare R2', 'r2-by-grisma') . '">
                    <svg class="r2g-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                    <span>' . esc_html__('Synced (Both)', 'r2-by-grisma') . '</span>
                  </span>';
        } elseif ($is_synced && !$has_local) {
            echo '<span class="r2g-badge r2g-badge-cloud" title="' . esc_attr__('Offloaded to Cloudflare R2 only (Local file deleted to save disk space)', 'r2-by-grisma') . '">
                    <svg class="r2g-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
                    <span>' . esc_html__('Cloud Only', 'r2-by-grisma') . '</span>
                  </span>';
        } elseif ($has_local) {
            echo '<span class="r2g-badge r2g-badge-local" title="' . esc_attr__('Stored on local server only (Not yet synced to R2)', 'r2-by-grisma') . '">
                    <svg class="r2g-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/></svg>
                    <span>' . esc_html__('Local Only', 'r2-by-grisma') . '</span>
                  </span>';
        } else {
            echo '<span class="r2g-badge r2g-badge-missing" title="' . esc_attr__('File missing both locally and on R2', 'r2-by-grisma') . '">
                    <svg class="r2g-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/></svg>
                    <span>' . esc_html__('Missing', 'r2-by-grisma') . '</span>
                  </span>';
        }

        // 2. Action Toolbar (Clean buttons instead of stuffed links)
        echo '<div class="r2g-col-btns">';

        if (!$is_synced && $has_local) {
            echo '<button type="button" class="button button-small r2g-row-action r2g-btn-push" data-action="r2g_sync_single" data-id="' . esc_attr($post_id) . '">' . esc_html__('Push to R2', 'r2-by-grisma') . '</button>';
        }

        if ($is_synced && $has_local) {
            echo '<button type="button" class="button button-small r2g-row-action" data-action="r2g_delete_local_single" data-id="' . esc_attr($post_id) . '" data-confirm="' . esc_attr__('Delete local server copy? The file remains safe on Cloudflare R2.', 'r2-by-grisma') . '" title="' . esc_attr__('Free server disk space', 'r2-by-grisma') . '">' . esc_html__('Delete Local', 'r2-by-grisma') . '</button>';
            echo '<button type="button" class="button button-small r2g-row-action r2g-btn-danger" data-action="r2g_delete_r2_single" data-id="' . esc_attr($post_id) . '" data-confirm="' . esc_attr__('Remove this file and its thumbnails from Cloudflare R2?', 'r2-by-grisma') . '" title="' . esc_attr__('Remove cloud copy', 'r2-by-grisma') . '">' . esc_html__('Remove R2', 'r2-by-grisma') . '</button>';
        }

        if ($is_synced && !$has_local) {
            echo '<button type="button" class="button button-small r2g-row-action" data-action="r2g_download_single" data-id="' . esc_attr($post_id) . '" title="' . esc_attr__('Download from R2 to local server disk', 'r2-by-grisma') . '">' . esc_html__('Restore Local', 'r2-by-grisma') . '</button>';
            echo '<button type="button" class="button button-small r2g-row-action r2g-btn-danger" data-action="r2g_delete_r2_single" data-id="' . esc_attr($post_id) . '" data-confirm="' . esc_attr__('Delete from Cloudflare R2? WARNING: Local file is already deleted.', 'r2-by-grisma') . '" title="' . esc_attr__('Remove cloud copy', 'r2-by-grisma') . '">' . esc_html__('Remove R2', 'r2-by-grisma') . '</button>';
        }

        // Copy CDN URL button
        if ($is_synced && !empty($cdn_url)) {
            echo '<button type="button" class="button button-small r2g-btn-copy-cdn" data-url="' . esc_url($cdn_url) . '" title="' . esc_attr__('Copy Public CDN URL', 'r2-by-grisma') . '">' . esc_html__('Copy CDN', 'r2-by-grisma') . '</button>';
        }

        echo '</div>'; // .r2g-col-btns
        echo '</div>'; // .r2g-col-wrap
    }

    /**
     * Clean and unclutter Row Actions under title
     * Removes stuffed links injected by previous plugins and keeps only clean links.
     *
     * @param array $actions
     * @param WP_Post $post
     * @return array
     */
    public function filter_media_row_actions($actions, $post) {
        if ($post->post_type !== 'attachment') {
            return $actions;
        }

        // Remove stuffed legacy actions if present
        unset(
            $actions['r2g_push'],
            $actions['r2g_pull'],
            $actions['r2g_del_local'],
            $actions['mcs_push'],
            $actions['mcs_delete_r2'],
            $actions['mcs_delete_local'],
            $actions['media_cloud_sync_push']
        );

        $id = $post->ID;
        $is_synced = (bool) get_post_meta($id, '_r2g_synced', true);
        if (!$is_synced && class_exists('R2G_Database')) {
            $rec = R2G_Database::get($id);
            $is_synced = ($rec && $rec->status === 'synced');
        }

        // Only add a sleek single link if helpful
        if ($is_synced) {
            $rewriter = class_exists('R2G_URL_Rewriter') ? R2G_URL_Rewriter::instance() : null;
            $cdn_url = $rewriter ? $rewriter->rewrite_url(wp_get_attachment_url($id)) : '';
            if (!empty($cdn_url)) {
                $actions['r2g_copy_cdn'] = sprintf(
                    '<a href="#" class="r2g-link-copy-cdn" data-url="%s">%s</a>',
                    esc_url($cdn_url),
                    esc_html__('Copy CDN URL', 'r2-by-grisma')
                );
            }
        } else {
            $actions['r2g_quick_push'] = sprintf(
                '<a href="#" class="r2g-row-action" data-action="r2g_sync_single" data-id="%d">%s</a>',
                $id,
                esc_html__('Push to R2', 'r2-by-grisma')
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
     * Register Bulk Actions in WordPress Media Library
     *
     * @param array $bulk_actions
     * @return array
     */
    public function register_bulk_actions($bulk_actions) {
        $bulk_actions['r2g_bulk_push']         = esc_html__('Push to Cloudflare R2', 'r2-by-grisma');
        $bulk_actions['r2g_bulk_delete_local']  = esc_html__('Delete Local Files (Offload to R2)', 'r2-by-grisma');
        $bulk_actions['r2g_bulk_download']      = esc_html__('Download from R2 to Local', 'r2-by-grisma');
        $bulk_actions['r2g_bulk_delete_r2']     = esc_html__('Remove from Cloudflare R2', 'r2-by-grisma');
        return $bulk_actions;
    }

    /**
     * Handle Bulk Actions execution
     *
     * @param string $redirect_url
     * @param string $action
     * @param array $post_ids
     * @return string
     */
    public function handle_bulk_actions($redirect_url, $action, $post_ids) {
        if (strpos($action, 'r2g_bulk_') !== 0 || empty($post_ids)) {
            return $redirect_url;
        }

        $count = 0;

        foreach ($post_ids as $id) {
            $id = (int) $id;
            switch ($action) {
                case 'r2g_bulk_push':
                    if (R2G_Media_Handler::sync_attachment_to_r2($id, true)) {
                        $count++;
                    }
                    break;

                case 'r2g_bulk_delete_local':
                    $rec = class_exists('R2G_Database') ? R2G_Database::get($id) : null;
                    if (get_post_meta($id, '_r2g_synced', true) || ($rec && $rec->status === 'synced')) {
                        R2G_Media_Handler::delete_local_files($id);
                        $count++;
                    }
                    break;

                case 'r2g_bulk_download':
                    if (R2G_Media_Handler::download_from_r2_to_local($id)) {
                        $count++;
                    }
                    break;

                case 'r2g_bulk_delete_r2':
                    if (R2G_Media_Handler::delete_from_r2($id)) {
                        $count++;
                    }
                    break;
            }
        }

        return add_query_arg(array(
            'r2g_bulk_done'  => $action,
            'r2g_bulk_count' => $count,
        ), $redirect_url);
    }

    /**
     * Render Bulk Action completion notice
     */
    public function render_bulk_action_notices() {
        if (empty($_GET['r2g_bulk_done']) || !isset($_GET['r2g_bulk_count'])) {
            return;
        }

        $action = sanitize_text_field($_GET['r2g_bulk_done']);
        $count = (int) $_GET['r2g_bulk_count'];

        $messages = array(
            'r2g_bulk_push'         => sprintf(esc_html__('Successfully pushed %d media items to Cloudflare R2.', 'r2-by-grisma'), $count),
            'r2g_bulk_delete_local' => sprintf(esc_html__('Deleted local server files for %d attachments (Offloaded to R2).', 'r2-by-grisma'), $count),
            'r2g_bulk_download'     => sprintf(esc_html__('Downloaded %d files from R2 back to local server disk.', 'r2-by-grisma'), $count),
            'r2g_bulk_delete_r2'    => sprintf(esc_html__('Removed %d attachments from Cloudflare R2 bucket.', 'r2-by-grisma'), $count),
        );

        $msg = $messages[$action] ?? sprintf(esc_html__('Processed %d items.', 'r2-by-grisma'), $count);

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }

    /**
     * Add R2 Cloud Details & Action Buttons into the Attachment Details Modal & Edit screen
     *
     * @param array $form_fields
     * @param WP_Post $post
     * @return array
     */
    public function add_attachment_modal_fields($form_fields, $post) {
        $id = $post->ID;
        $is_synced = (bool) get_post_meta($id, '_r2g_synced', true);
        $file_path = get_attached_file($id);
        $has_local = !empty($file_path) && file_exists($file_path);

        $record = class_exists('R2G_Database') ? R2G_Database::get($id) : null;
        if ($record && $record->status === 'synced') {
            $is_synced = true;
            $has_local = (bool) $record->has_local;
        }

        $rewriter = class_exists('R2G_URL_Rewriter') ? R2G_URL_Rewriter::instance() : null;
        $cdn_url = $rewriter ? $rewriter->rewrite_url(wp_get_attachment_url($id)) : wp_get_attachment_url($id);

        $status_label = $is_synced ? ($has_local ? 'Synced (Local + Cloud)' : 'Cloud Only (Offloaded)') : ($has_local ? 'Local Only' : 'Missing');
        $status_color = $is_synced ? '#059669' : '#475569';

        $is_image = wp_attachment_is_image($id);
        $mime_type = get_post_mime_type($id);
        $is_webp = ($mime_type === 'image/webp');

        $html = '<div class="r2g-modal-meta-box" style="padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:8px;">';
        $html .= '<div style="margin-bottom:8px; font-size:12px;"><strong>Storage Status:</strong> <span style="color:' . esc_attr($status_color) . '; font-weight:600;">' . esc_html($status_label) . '</span></div>';

        if ($is_image) {
            $format_name = $is_webp ? 'WebP (Optimized)' : strtoupper(pathinfo($file_path, PATHINFO_EXTENSION) ?: 'Standard');
            $format_color = $is_webp ? '#059669' : '#0284c7';
            $html .= '<div style="margin-bottom:8px; font-size:12px;"><strong>Format:</strong> <span style="color:' . esc_attr($format_color) . '; font-weight:600;">' . esc_html($format_name) . '</span></div>';

            if (!$is_webp) {
                $html .= '<div style="margin-bottom:10px;">';
                $html .= '<button type="button" class="button button-small r2g-row-action" data-action="r2g_convert_webp_single" data-id="' . esc_attr($id) . '" style="background:#0284c7; color:#fff; border-color:#0284c7; width:100%; font-weight:600;" title="' . esc_attr__('Convert image to WebP and sync to Cloudflare R2', 'r2-by-grisma') . '">⚡ ' . esc_html__('Convert to WebP & Sync to R2', 'r2-by-grisma') . '</button>';
                $html .= '</div>';
            }
        }

        if (!empty($cdn_url)) {
            $html .= '<div style="margin-bottom:10px;">';
            $html .= '<div style="font-size:11px; color:#64748b; margin-bottom:3px;">Public CDN URL:</div>';
            $html .= '<input type="text" readonly value="' . esc_url($cdn_url) . '" style="width:100%; font-size:11px; padding:4px 8px; background:#ffffff; border:1px solid #cbd5e1; border-radius:4px;" />';
            $html .= '<div style="margin-top:6px;"><button type="button" class="button button-small r2g-btn-copy-cdn" data-url="' . esc_url($cdn_url) . '">' . esc_html__('Copy CDN URL', 'r2-by-grisma') . '</button></div>';
            $html .= '</div>';
        }

        $html .= '<div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">';
        if (!$is_synced && $has_local) {
            $html .= '<button type="button" class="button button-small r2g-row-action" data-action="r2g_sync_single" data-id="' . esc_attr($id) . '">' . esc_html__('Push to R2', 'r2-by-grisma') . '</button>';
        }
        if ($is_synced && $has_local) {
            $html .= '<button type="button" class="button button-small r2g-row-action" data-action="r2g_delete_local_single" data-id="' . esc_attr($id) . '" data-confirm="' . esc_attr__('Delete local server copy? The file stays safe on Cloudflare R2.', 'r2-by-grisma') . '">' . esc_html__('Delete Local Copy', 'r2-by-grisma') . '</button>';
            $html .= '<button type="button" class="button button-small r2g-row-action r2g-btn-danger" data-action="r2g_delete_r2_single" data-id="' . esc_attr($id) . '" data-confirm="' . esc_attr__('Remove from Cloudflare R2?', 'r2-by-grisma') . '">' . esc_html__('Remove from R2', 'r2-by-grisma') . '</button>';
        }
        if ($is_synced && !$has_local) {
            $html .= '<button type="button" class="button button-small r2g-row-action" data-action="r2g_download_single" data-id="' . esc_attr($id) . '">' . esc_html__('Restore to Local Server', 'r2-by-grisma') . '</button>';
            $html .= '<button type="button" class="button button-small r2g-row-action r2g-btn-danger" data-action="r2g_delete_r2_single" data-id="' . esc_attr($id) . '" data-confirm="' . esc_attr__('Remove from Cloudflare R2?', 'r2-by-grisma') . '">' . esc_html__('Remove from R2', 'r2-by-grisma') . '</button>';
        }
        $html .= '</div>';
        $html .= '</div>';

        $form_fields['r2g_storage_details'] = array(
            'label' => esc_html__('Cloudflare R2', 'r2-by-grisma'),
            'input' => 'html',
            'html'  => $html,
        );

        return $form_fields;
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
            wp_send_json_success(array('message' => esc_html__('Successfully uploaded to Cloudflare R2', 'r2-by-grisma')));
        }
        wp_send_json_error(array('message' => esc_html__('Failed to upload to Cloudflare R2', 'r2-by-grisma')));
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
            wp_send_json_success(array('message' => esc_html__('Successfully downloaded to local server', 'r2-by-grisma')));
        }
        wp_send_json_error(array('message' => esc_html__('Failed to download from Cloudflare R2', 'r2-by-grisma')));
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
        wp_send_json_success(array('message' => esc_html__('Local file deleted. Cloud copy retained safely on R2.', 'r2-by-grisma')));
    }

    /**
     * Ajax: Remove from Cloudflare R2 bucket
     */
    public function ajax_delete_r2_single() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $id = (int)($_POST['id'] ?? 0);
        $ok = R2G_Media_Handler::delete_from_r2($id);
        if ($ok) {
            wp_send_json_success(array('message' => esc_html__('File removed from Cloudflare R2 bucket.', 'r2-by-grisma')));
        }
        wp_send_json_error(array('message' => esc_html__('Failed to remove from Cloudflare R2', 'r2-by-grisma')));
    }

    /**
     * Ajax: Convert existing attachment to WebP and sync to R2
     */
    public function ajax_convert_webp_single() {
        check_ajax_referer('r2g_admin_nonce', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error(array('message' => 'Invalid attachment ID'));
        }

        $file_path = get_attached_file($id);
        if (!$file_path || !file_exists($file_path)) {
            R2G_Media_Handler::download_from_r2_to_local($id);
            $file_path = get_attached_file($id);
        }

        if (!$file_path || !file_exists($file_path)) {
            wp_send_json_error(array('message' => 'File not found on server or R2.'));
        }

        $quality = (int) get_option('r2g_compress_quality', 82);
        $max_width = (int) get_option('r2g_max_width', 1920);

        $opt_res = R2G_Optimizer::optimize_local_file($file_path, array(
            'format'    => 'webp',
            'quality'   => $quality,
            'max_width' => $max_width,
            'compress'  => true,
        ));

        if ($opt_res['success'] && !empty($opt_res['file_path'])) {
            $new_file = $opt_res['file_path'];
            update_attached_file($id, $new_file);
            wp_update_post(array(
                'ID'             => $id,
                'post_mime_type' => !empty($opt_res['mime']) ? $opt_res['mime'] : 'image/webp',
            ));

            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata($id, $new_file);
            wp_update_attachment_metadata($id, $metadata);

            R2G_Media_Handler::sync_attachment_to_r2($id, true, $metadata);

            $rewriter = class_exists('R2G_URL_Rewriter') ? R2G_URL_Rewriter::instance() : null;
            $new_url = $rewriter ? $rewriter->rewrite_url(wp_get_attachment_url($id)) : wp_get_attachment_url($id);

            wp_send_json_success(array(
                'message' => esc_html__('Successfully converted to WebP and synced to Cloudflare R2!', 'r2-by-grisma'),
                'url'     => $new_url,
            ));
        }

        wp_send_json_error(array('message' => 'Failed to convert image to WebP.'));
    }
}
