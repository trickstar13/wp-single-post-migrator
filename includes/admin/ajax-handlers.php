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
        add_action('wp_ajax_import_media_from_zip', array($this, 'handle_import_media'));
        add_action('wp_ajax_debug_block_content', array($this, 'handle_debug_block_content'));
    }

    /**
     * Handle media import AJAX request
     */
    public function handle_import_media() {
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
            // Log import start
            $this->log('INFO', "Started import for post ID {$post_id}");

            // Initialize handlers
            $zip_handler = new IPBMFZ_ZIP_Handler();
            $media_importer = new IPBMFZ_Media_Importer();
            $block_updater = new IPBMFZ_Block_Updater();

            // Step 1: Upload and extract ZIP
            $this->log('INFO', 'Uploading and extracting ZIP file');
            $extraction_result = $zip_handler->upload_and_extract($file_data, $post_id);

            if (is_wp_error($extraction_result)) {
                $this->log('ERROR', 'ZIP extraction failed: ' . $extraction_result->get_error_message());
                wp_send_json_error(array(
                    'message' => $extraction_result->get_error_message()
                ));
            }

            $extract_path = $extraction_result['extract_path'];
            $image_files = $extraction_result['files'];

            $this->log('INFO', 'Found ' . count($image_files) . ' image files');

            // Step 2: Import images to media library
            $this->log('INFO', 'Importing images to media library');
            $import_results = $media_importer->import_multiple_images($image_files, $post_id);

            if (empty($import_results['imported'])) {
                $zip_handler->cleanup($extract_path);
                wp_send_json_error(array(
                    'message' => __('インポートできる画像がありませんでした。', 'import-post-block-media-from-zip'),
                    'errors' => $import_results['errors']
                ));
            }

            // Step 3: Update blocks
            $this->log('INFO', 'Updating blocks with new media');
            $block_results = $block_updater->update_blocks($post_id, $import_results['imported']);

            if (is_wp_error($block_results)) {
                $zip_handler->cleanup($extract_path);
                $this->log('ERROR', 'Block update failed: ' . $block_results->get_error_message());
                wp_send_json_error(array(
                    'message' => $block_results->get_error_message()
                ));
            }

            // Step 4: Cleanup temporary files
            $zip_handler->cleanup($extract_path);

            // Log completion
            $imported_count = $import_results['count'];
            $updated_blocks = $block_results['updated_blocks'];
            $this->log('INFO', "Import completed. {$imported_count} images imported, {$updated_blocks} blocks updated");

            // Prepare response
            $response_data = array(
                'imported_count' => $imported_count,
                'updated_blocks' => $updated_blocks,
                'failed_matches' => $this->format_failed_matches($block_results['failed_matches']),
                'import_errors' => $import_results['errors'],
                'processed_images' => $block_results['processed_images'],
                'message' => $this->generate_success_message($imported_count, $updated_blocks)
            );

            // Log warnings for failed matches
            if (!empty($block_results['failed_matches'])) {
                foreach ($block_results['failed_matches'] as $failed_match) {
                    $this->log('WARNING', 'Failed match: ' . $failed_match);
                }
            }

            wp_send_json_success($response_data);

        } catch (Exception $e) {
            // Cleanup on error
            if (isset($extract_path)) {
                $zip_handler->cleanup($extract_path);
            }

            $this->log('ERROR', 'Exception during import: ' . $e->getMessage());

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
     * Generate success message
     *
     * @param int $imported_count Number of imported images
     * @param int $updated_blocks Number of updated blocks
     * @return string Success message
     */
    private function generate_success_message($imported_count, $updated_blocks) {
        if ($updated_blocks > 0) {
            return sprintf(
                __('%d件の画像をインポートし、%d個のブロックを更新しました。', 'import-post-block-media-from-zip'),
                $imported_count,
                $updated_blocks
            );
        } else {
            return sprintf(
                __('%d件の画像をインポートしましたが、マッチするブロックが見つかりませんでした。', 'import-post-block-media-from-zip'),
                $imported_count
            );
        }
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