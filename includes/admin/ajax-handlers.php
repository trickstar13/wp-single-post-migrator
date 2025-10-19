<?php
/**
 * AJAX Handlers
 *
 * Handles AJAX requests for the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class IPBMFZ_AJAX_Handlers {

    /**
     * Constructor
     */
    public function __construct() {
        // Existing endpoint for backward compatibility
        add_action('wp_ajax_import_media_from_zip', array($this, 'handle_import_images_only'));

        // New endpoints
        add_action('wp_ajax_export_post_with_media', array($this, 'handle_export_post'));
        add_action('wp_ajax_import_post_with_media', array($this, 'handle_import_post'));
        add_action('wp_ajax_import_images_only', array($this, 'handle_import_images_only'));
        add_action('wp_ajax_debug_block_content', array($this, 'handle_debug_block_content'));
    }

    /**
     * Handle export post with media AJAX request
     */
    public function handle_export_post() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'import_media_from_zip_nonce')) {
            wp_send_json_error(array(
                'message' => __('セキュリティチェックに失敗しました。', 'import-post-block-media-from-zip')
            ));
        }

        // Check user permissions
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array(
                'message' => __('この操作を実行する権限がありません。', 'import-post-block-media-from-zip')
            ));
        }

        // Get post ID
        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error(array(
                'message' => __('無効な記事IDです。', 'import-post-block-media-from-zip')
            ));
        }

        // Check if user can edit this specific post
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array(
                'message' => __('この記事を編集する権限がありません。', 'import-post-block-media-from-zip')
            ));
        }

        // Get export options
        $options = array(
            'include_images' => isset($_POST['include_images']) ? (bool) $_POST['include_images'] : true,
            'include_meta' => isset($_POST['include_meta']) ? (bool) $_POST['include_meta'] : true,
            'export_format' => 'wxr'
        );

        try {
            $this->log('INFO', "Starting export for post ID {$post_id}");

            // Initialize exporter
            $exporter = new IPBMFZ_Post_Exporter();

            // Export post
            $export_result = $exporter->export_post($post_id, $options);

            if (is_wp_error($export_result)) {
                $this->log('ERROR', 'Export failed: ' . $export_result->get_error_message());
                wp_send_json_error(array(
                    'message' => $export_result->get_error_message()
                ));
            }

            $this->log('INFO', "Export completed for post ID {$post_id}. Images: " . $export_result['image_count']);

            // Prepare response
            $response_data = array(
                'zip_url' => $export_result['zip_url'],
                'post_title' => $export_result['post_title'],
                'image_count' => $export_result['image_count'],
                'xml_file' => $export_result['xml_file'],
                'file_size' => $export_result['file_size'],
                'file_size_formatted' => size_format($export_result['file_size']),
                'message' => sprintf(
                    __('記事「%s」をエクスポートしました。画像: %d件, ファイルサイズ: %s', 'import-post-block-media-from-zip'),
                    $export_result['post_title'],
                    $export_result['image_count'],
                    size_format($export_result['file_size'])
                )
            );

            wp_send_json_success($response_data);

        } catch (Exception $e) {
            $this->log('ERROR', 'Export exception: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => sprintf(
                    __('エクスポート中にエラーが発生しました: %s', 'import-post-block-media-from-zip'),
                    $e->getMessage()
                )
            ));
        }
    }

    /**
     * Handle import post with media AJAX request
     */
    public function handle_import_post() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'import_media_from_zip_nonce')) {
            wp_send_json_error(array(
                'message' => __('セキュリティチェックに失敗しました。', 'import-post-block-media-from-zip')
            ));
        }

        // Check user permissions
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array(
                'message' => __('この操作を実行する権限がありません。', 'import-post-block-media-from-zip')
            ));
        }

        // Get current post ID for replace mode
        $current_post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : null;

        // Validate file upload
        if (!isset($_FILES['zip_file'])) {
            wp_send_json_error(array(
                'message' => __('ファイルがアップロードされていません。', 'import-post-block-media-from-zip')
            ));
        }

        // Get import options (always replace current post)
        $import_mode = 'replace_current';
        $options = array(
            'import_mode' => $import_mode,
            'target_post_id' => $current_post_id,
            'import_images' => isset($_POST['include_images']) ? (bool) $_POST['include_images'] : true,
            'import_meta' => isset($_POST['include_meta']) ? (bool) $_POST['include_meta'] : true
        );

        // Validate target post
        if (!$current_post_id || !current_user_can('edit_post', $current_post_id)) {
            wp_send_json_error(array(
                'message' => __('置き換え対象の記事にアクセスできません。', 'import-post-block-media-from-zip')
            ));
        }

        $file_data = $_FILES['zip_file'];

        try {
            $this->log('INFO', "Starting post import with mode: {$import_mode}");

            // Initialize importer
            $importer = new IPBMFZ_Post_Importer();

            // Import post
            $import_result = $importer->import_post_from_zip($file_data, $options);

            if (is_wp_error($import_result)) {
                $this->log('ERROR', 'Import failed: ' . $import_result->get_error_message());
                wp_send_json_error(array(
                    'message' => $import_result->get_error_message()
                ));
            }

            $this->log('INFO', sprintf(
                "Post import completed. Post ID: %d, Images: %d, Blocks: %d",
                $import_result['post_id'],
                $import_result['imported_images'],
                $import_result['updated_blocks']
            ));

            // Prepare response
            $response_data = array(
                'post_id' => $import_result['post_id'],
                'post_title' => $import_result['post_title'],
                'post_url' => $import_result['post_url'],
                'is_new_post' => $import_result['is_new_post'],
                'imported_images' => $import_result['imported_images'],
                'updated_blocks' => $import_result['updated_blocks'],
                'failed_matches' => $this->format_failed_matches($import_result['failed_matches']),
                'message' => $import_result['message']
            );

            // Log warnings for failed matches
            if (!empty($import_result['failed_matches'])) {
                foreach ($import_result['failed_matches'] as $failed_match) {
                    $this->log('WARNING', 'Failed match: ' . $failed_match);
                }
            }

            wp_send_json_success($response_data);

        } catch (Exception $e) {
            $this->log('ERROR', 'Import exception: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => sprintf(
                    __('インポート中にエラーが発生しました: %s', 'import-post-block-media-from-zip'),
                    $e->getMessage()
                )
            ));
        }
    }

    /**
     * Handle images-only import AJAX request (existing functionality)
     */
    public function handle_import_images_only() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'import_media_from_zip_nonce')) {
            wp_send_json_error(array(
                'message' => __('セキュリティチェックに失敗しました。', 'import-post-block-media-from-zip')
            ));
        }

        // Check user permissions
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array(
                'message' => __('この操作を実行する権限がありません。', 'import-post-block-media-from-zip')
            ));
        }

        // Get post ID
        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error(array(
                'message' => __('無効な記事IDです。', 'import-post-block-media-from-zip')
            ));
        }

        // Check if user can edit this specific post
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array(
                'message' => __('この記事を編集する権限がありません。', 'import-post-block-media-from-zip')
            ));
        }

        // Validate file upload
        if (!isset($_FILES['zip_file'])) {
            wp_send_json_error(array(
                'message' => __('ファイルがアップロードされていません。', 'import-post-block-media-from-zip')
            ));
        }

        $file_data = $_FILES['zip_file'];

        try {
            $this->log('INFO', "Starting image-only import for post ID {$post_id}");

            // Initialize importer
            $importer = new IPBMFZ_Post_Importer();

            // Import images only
            $import_result = $importer->import_images_only($file_data, $post_id);

            if (is_wp_error($import_result)) {
                $this->log('ERROR', 'Image import failed: ' . $import_result->get_error_message());
                wp_send_json_error(array(
                    'message' => $import_result->get_error_message()
                ));
            }

            $this->log('INFO', sprintf(
                "Image-only import completed for post ID {$post_id}. Images: %d, Blocks: %d",
                $import_result['imported_count'],
                $import_result['updated_blocks']
            ));

            // Prepare response
            $response_data = array(
                'imported_count' => $import_result['imported_count'],
                'updated_blocks' => $import_result['updated_blocks'],
                'failed_matches' => $this->format_failed_matches($import_result['failed_matches']),
                'import_errors' => $import_result['import_errors'],
                'processed_images' => $import_result['processed_images'],
                'message' => $import_result['message']
            );

            // Log warnings for failed matches
            if (!empty($import_result['failed_matches'])) {
                foreach ($import_result['failed_matches'] as $failed_match) {
                    $this->log('WARNING', 'Failed match: ' . $failed_match);
                }
            }

            wp_send_json_success($response_data);

        } catch (Exception $e) {
            $this->log('ERROR', 'Exception during image import: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => sprintf(
                    __('処理中にエラーが発生しました: %s', 'import-post-block-media-from-zip'),
                    $e->getMessage()
                )
            ));
        }
    }

    /**
     * Format failed matches for display
     *
     * @param array $failed_matches Array of failed match messages
     * @return array Formatted failed matches
     */
    private function format_failed_matches($failed_matches) {
        if (empty($failed_matches)) {
            return array();
        }

        $formatted = array();
        foreach ($failed_matches as $message) {
            $formatted[] = array(
                'message' => $message,
                'type' => 'warning'
            );
        }

        return $formatted;
    }


    /**
     * Validate request data
     *
     * @param array $data Request data
     * @return true|WP_Error
     */
    private function validate_request($data) {
        // Check required fields
        $required_fields = array('post_id', 'nonce');

        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return new WP_Error(
                    'missing_field',
                    sprintf(__('必須フィールドが不足しています: %s', 'import-post-block-media-from-zip'), $field)
                );
            }
        }

        // Validate post ID
        $post_id = intval($data['post_id']);
        if (!get_post($post_id)) {
            return new WP_Error(
                'invalid_post',
                __('指定された記事が見つかりません。', 'import-post-block-media-from-zip')
            );
        }

        return true;
    }

    /**
     * Log activity
     *
     * @param string $level Log level
     * @param string $message Log message
     */
    private function log($level, $message) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf('[%s] Import Media from ZIP - %s: %s', current_time('Y-m-d H:i:s'), $level, $message));
        }
    }

    /**
     * Handle file size check (AJAX endpoint for pre-validation)
     */
    public function check_file_size() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'import_media_from_zip_nonce')) {
            wp_send_json_error(array(
                'message' => __('セキュリティチェックに失敗しました。', 'import-post-block-media-from-zip')
            ));
        }

        $file_size = intval($_POST['file_size']);
        $max_size = wp_max_upload_size();

        if ($file_size > $max_size) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('ファイルサイズが上限を超えています。最大: %s', 'import-post-block-media-from-zip'),
                    size_format($max_size)
                ),
                'max_size' => $max_size,
                'file_size' => $file_size
            ));
        }

        // Warning for large files
        $warning_size = 10 * 1024 * 1024; // 10MB
        $warning = false;

        if ($file_size > $warning_size) {
            $warning = sprintf(
                __('ファイルサイズが大きいため、処理に時間がかかる場合があります (%s)', 'import-post-block-media-from-zip'),
                size_format($file_size)
            );
        }

        wp_send_json_success(array(
            'message' => __('ファイルサイズは適切です。', 'import-post-block-media-from-zip'),
            'warning' => $warning,
            'file_size' => $file_size,
            'max_size' => $max_size
        ));
    }

    /**
     * Debug block content (development only)
     */
    public function handle_debug_block_content() {
        // Only allow in debug mode
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            wp_send_json_error(array('message' => 'Debug mode not enabled'));
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'import_media_from_zip_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }

        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error(array('message' => 'Invalid post ID'));
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(array('message' => 'Post not found'));
        }

        $blocks = parse_blocks($post->post_content);
        $block_info = array();

        foreach ($blocks as $i => $block) {
            if ($block['blockName'] === 'core/image') {
                $block_info[] = array(
                    'index' => $i,
                    'blockName' => $block['blockName'],
                    'attrs' => $block['attrs'],
                    'innerHTML' => $block['innerHTML'],
                    'innerContent' => $block['innerContent']
                );
            }
        }

        wp_send_json_success(array(
            'post_id' => $post_id,
            'blocks_count' => count($blocks),
            'image_blocks' => $block_info,
            'raw_content' => $post->post_content
        ));
    }
}

// Initialize AJAX handlers
new IPBMFZ_AJAX_Handlers();