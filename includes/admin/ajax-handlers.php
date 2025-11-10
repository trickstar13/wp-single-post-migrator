<?php

/**
 * AJAX Handlers
 *
 * Handles AJAX requests for the plugin
 */

if (!defined('ABSPATH')) {
  exit;
}

class IPBMFZ_AJAX_Handlers
{

  /**
   * Constructor
   */
  public function __construct()
  {
    // Existing endpoint for backward compatibility
    add_action('wp_ajax_import_media_from_zip', array($this, 'handle_import_images_only'));

    // New endpoints
    add_action('wp_ajax_export_post_with_media', array($this, 'handle_export_post'));
    add_action('wp_ajax_import_post_with_media', array($this, 'handle_import_post'));
    add_action('wp_ajax_import_images_only', array($this, 'handle_import_images_only'));
    add_action('wp_ajax_debug_block_content', array($this, 'handle_debug_block_content'));

    // Synced patterns endpoints
    add_action('wp_ajax_export_synced_patterns', array($this, 'handle_export_synced_patterns'));
    add_action('wp_ajax_import_synced_patterns', array($this, 'handle_import_synced_patterns'));
  }

  /**
   * Handle export post with media AJAX request
   */
  public function handle_export_post()
  {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'import_media_from_zip_nonce')) {
      wp_send_json_error(array(
        'message' => __('セキュリティチェックに失敗しました。', 'wp-single-post-migrator')
      ));
    }

    // Check user permissions
    if (!current_user_can('edit_posts')) {
      wp_send_json_error(array(
        'message' => __('この操作を実行する権限がありません。', 'wp-single-post-migrator')
      ));
    }

    // Get post ID
    $post_id = intval($_POST['post_id']);
    if (!$post_id) {
      wp_send_json_error(array(
        'message' => __('無効な記事IDです。', 'wp-single-post-migrator')
      ));
    }

    // Check if user can edit this specific post
    if (!current_user_can('edit_post', $post_id)) {
      wp_send_json_error(array(
        'message' => __('この記事を編集する権限がありません。', 'wp-single-post-migrator')
      ));
    }

    // Get export options
    $options = array(
      'include_images' => isset($_POST['include_images']) ? (bool) $_POST['include_images'] : true,
      'include_meta' => isset($_POST['include_meta']) ? (bool) $_POST['include_meta'] : true,
      'include_synced_patterns' => isset($_POST['include_synced_patterns']) ? (bool) $_POST['include_synced_patterns'] : false,
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
      $pattern_count = isset($export_result['pattern_count']) ? $export_result['pattern_count'] : 0;
      $message_parts = array();
      $message_parts[] = sprintf(__('記事「%s」をエクスポートしました。', 'wp-single-post-migrator'), $export_result['post_title']);
      $message_parts[] = sprintf(__('画像: %d件', 'wp-single-post-migrator'), $export_result['image_count']);
      if ($pattern_count > 0) {
        $message_parts[] = sprintf(__('同期パターン: %d件', 'wp-single-post-migrator'), $pattern_count);
      }
      $message_parts[] = sprintf(__('ファイルサイズ: %s', 'wp-single-post-migrator'), size_format($export_result['file_size']));

      $response_data = array(
        'zip_url' => $export_result['zip_url'],
        'post_title' => $export_result['post_title'],
        'image_count' => $export_result['image_count'],
        'pattern_count' => $pattern_count,
        'xml_file' => $export_result['xml_file'],
        'file_size' => $export_result['file_size'],
        'file_size_formatted' => size_format($export_result['file_size']),
        'message' => implode(', ', $message_parts)
      );

      wp_send_json_success($response_data);
    } catch (Exception $e) {
      $this->log('ERROR', 'Export exception: ' . $e->getMessage());
      wp_send_json_error(array(
        'message' => sprintf(
          __('エクスポート中にエラーが発生しました: %s', 'wp-single-post-migrator'),
          $e->getMessage()
        )
      ));
    }
  }

  /**
   * Handle import post with media AJAX request
   */
  public function handle_import_post()
  {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'import_media_from_zip_nonce')) {
      wp_send_json_error(array(
        'message' => __('セキュリティチェックに失敗しました。', 'wp-single-post-migrator')
      ));
    }

    // Check user permissions
    if (!current_user_can('edit_posts')) {
      wp_send_json_error(array(
        'message' => __('この操作を実行する権限がありません。', 'wp-single-post-migrator')
      ));
    }

    // Get current post ID for replace mode
    $current_post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : null;

    // Validate file upload
    if (!isset($_FILES['zip_file'])) {
      wp_send_json_error(array(
        'message' => __('ファイルがアップロードされていません。', 'wp-single-post-migrator')
      ));
    }

    // Get import options (always replace current post)
    $import_mode = 'replace_current';
    $options = array(
      'import_mode' => $import_mode,
      'target_post_id' => $current_post_id,
      'import_images' => isset($_POST['include_images']) ? (bool) $_POST['include_images'] : true,
      'import_meta' => isset($_POST['include_meta']) ? (bool) $_POST['include_meta'] : true,
      'import_synced_patterns' => isset($_POST['include_synced_patterns']) ? (bool) $_POST['include_synced_patterns'] : false
    );

    // Validate target post
    if (!$current_post_id || !current_user_can('edit_post', $current_post_id)) {
      wp_send_json_error(array(
        'message' => __('置き換え対象の記事にアクセスできません。', 'wp-single-post-migrator')
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
          __('インポート中にエラーが発生しました: %s', 'wp-single-post-migrator'),
          $e->getMessage()
        )
      ));
    }
  }

  /**
   * Handle images-only import AJAX request (existing functionality)
   */
  public function handle_import_images_only()
  {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'import_media_from_zip_nonce')) {
      wp_send_json_error(array(
        'message' => __('セキュリティチェックに失敗しました。', 'wp-single-post-migrator')
      ));
    }

    // Check user permissions
    if (!current_user_can('edit_posts')) {
      wp_send_json_error(array(
        'message' => __('この操作を実行する権限がありません。', 'wp-single-post-migrator')
      ));
    }

    // Get post ID
    $post_id = intval($_POST['post_id']);
    if (!$post_id) {
      wp_send_json_error(array(
        'message' => __('無効な記事IDです。', 'wp-single-post-migrator')
      ));
    }

    // Check if user can edit this specific post
    if (!current_user_can('edit_post', $post_id)) {
      wp_send_json_error(array(
        'message' => __('この記事を編集する権限がありません。', 'wp-single-post-migrator')
      ));
    }

    // Validate file upload
    if (!isset($_FILES['zip_file'])) {
      wp_send_json_error(array(
        'message' => __('ファイルがアップロードされていません。', 'wp-single-post-migrator')
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
          __('処理中にエラーが発生しました: %s', 'wp-single-post-migrator'),
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
  private function format_failed_matches($failed_matches)
  {
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
  private function validate_request($data)
  {
    // Check required fields
    $required_fields = array('post_id', 'nonce');

    foreach ($required_fields as $field) {
      if (empty($data[$field])) {
        return new WP_Error(
          'missing_field',
          sprintf(__('必須フィールドが不足しています: %s', 'wp-single-post-migrator'), $field)
        );
      }
    }

    // Validate post ID
    $post_id = intval($data['post_id']);
    if (!get_post($post_id)) {
      return new WP_Error(
        'invalid_post',
        __('指定された記事が見つかりません。', 'wp-single-post-migrator')
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
  private function log($level, $message)
  {
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
      error_log(sprintf('[%s] Import Media from ZIP - %s: %s', current_time('Y-m-d H:i:s'), $level, $message));
    }
  }

  /**
   * Handle file size check (AJAX endpoint for pre-validation)
   */
  public function check_file_size()
  {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'import_media_from_zip_nonce')) {
      wp_send_json_error(array(
        'message' => __('セキュリティチェックに失敗しました。', 'wp-single-post-migrator')
      ));
    }

    $file_size = intval($_POST['file_size']);
    $max_size = wp_max_upload_size();

    if ($file_size > $max_size) {
      wp_send_json_error(array(
        'message' => sprintf(
          __('ファイルサイズが上限を超えています。最大: %s', 'wp-single-post-migrator'),
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
        __('ファイルサイズが大きいため、処理に時間がかかる場合があります (%s)', 'wp-single-post-migrator'),
        size_format($file_size)
      );
    }

    wp_send_json_success(array(
      'message' => __('ファイルサイズは適切です。', 'wp-single-post-migrator'),
      'warning' => $warning,
      'file_size' => $file_size,
      'max_size' => $max_size
    ));
  }

  /**
   * Debug block content (development only)
   */
  public function handle_debug_block_content()
  {
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

  /**
   * Handle export synced patterns AJAX request
   */
  public function handle_export_synced_patterns()
  {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'import_media_from_zip_nonce')) {
      wp_send_json_error(array(
        'message' => __('セキュリティチェックに失敗しました。', 'wp-single-post-migrator')
      ));
    }

    // Check user permissions (theme management capability required for patterns)
    if (!current_user_can('edit_theme_options')) {
      wp_send_json_error(array(
        'message' => __('同期パターンを操作する権限がありません。', 'wp-single-post-migrator')
      ));
    }

    try {
      $this->log('INFO', 'Starting synced patterns export');

      // Initialize pattern handler
      $pattern_handler = new IPBMFZ_Synced_Pattern_Handler();

      // Create temporary directory for patterns
      $temp_dir = wp_upload_dir()['basedir'] . '/wp-single-post-migrator-temp-' . time();
      if (!wp_mkdir_p($temp_dir)) {
        wp_send_json_error(array(
          'message' => __('一時ディレクトリの作成に失敗しました。', 'wp-single-post-migrator')
        ));
      }

      // Export patterns
      $export_result = $pattern_handler->export_synced_patterns($temp_dir);
      if (is_wp_error($export_result)) {
        $this->cleanup_temp_directory($temp_dir);
        wp_send_json_error(array(
          'message' => $export_result->get_error_message()
        ));
      }

      // Check if no patterns were found
      if ($export_result['exported_patterns'] === 0) {
        $this->cleanup_temp_directory($temp_dir);
        wp_send_json_error(array(
          'message' => isset($export_result['message']) ?
            $export_result['message'] :
            __('エクスポート可能な同期パターンが見つかりませんでした。', 'wp-single-post-migrator')
        ));
      }

      // Create ZIP file
      $zip_result = $this->create_patterns_zip($temp_dir);
      if (is_wp_error($zip_result)) {
        $this->cleanup_temp_directory($temp_dir);
        wp_send_json_error(array(
          'message' => $zip_result->get_error_message()
        ));
      }

      // Cleanup temp directory
      $this->cleanup_temp_directory($temp_dir);

      $this->log('INFO', sprintf(
        'Patterns export completed. Patterns: %d, Images: %d',
        $export_result['exported_patterns'],
        count($export_result['collected_images'])
      ));

      // Prepare response
      $this->log('INFO', 'Preparing AJAX response for patterns export');
      $response_data = array(
        'zip_url' => $zip_result['zip_url'],
        'pattern_count' => $export_result['exported_patterns'],
        'image_count' => count($export_result['collected_images']),
        'file_size' => $zip_result['file_size'],
        'file_size_formatted' => size_format($zip_result['file_size']),
        'errors' => $export_result['errors'],
        'message' => sprintf(
          __('%d個の同期パターンをエクスポートしました。画像: %d件, ファイルサイズ: %s', 'wp-single-post-migrator'),
          $export_result['exported_patterns'],
          count($export_result['collected_images']),
          size_format($zip_result['file_size'])
        )
      );

      $this->log('INFO', 'Sending success response to client');
      wp_send_json_success($response_data);
    } catch (Exception $e) {
      if (isset($temp_dir)) {
        $this->cleanup_temp_directory($temp_dir);
      }
      $this->log('ERROR', 'Pattern export exception: ' . $e->getMessage());
      $this->log('ERROR', 'Exception trace: ' . $e->getTraceAsString());
      wp_send_json_error(array(
        'message' => sprintf(
          __('エクスポート中にエラーが発生しました: %s', 'wp-single-post-migrator'),
          $e->getMessage()
        )
      ));
    } catch (Error $e) {
      if (isset($temp_dir)) {
        $this->cleanup_temp_directory($temp_dir);
      }
      $this->log('ERROR', 'Pattern export fatal error: ' . $e->getMessage());
      $this->log('ERROR', 'Error trace: ' . $e->getTraceAsString());
      wp_send_json_error(array(
        'message' => sprintf(
          __('エクスポート中に致命的エラーが発生しました: %s', 'wp-single-post-migrator'),
          $e->getMessage()
        )
      ));
    }
  }

  /**
   * Handle import synced patterns AJAX request
   */
  public function handle_import_synced_patterns()
  {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'import_media_from_zip_nonce')) {
      wp_send_json_error(array(
        'message' => __('セキュリティチェックに失敗しました。', 'wp-single-post-migrator')
      ));
    }

    // Check user permissions
    if (!current_user_can('edit_theme_options')) {
      wp_send_json_error(array(
        'message' => __('同期パターンを操作する権限がありません。', 'wp-single-post-migrator')
      ));
    }

    // Validate file upload
    if (!isset($_FILES['zip_file'])) {
      wp_send_json_error(array(
        'message' => __('ファイルがアップロードされていません。', 'wp-single-post-migrator')
      ));
    }

    // Get import mode
    $import_mode = isset($_POST['import_mode']) ? sanitize_text_field($_POST['import_mode']) : 'create_new';
    if (!in_array($import_mode, array('create_new', 'replace_existing'))) {
      $import_mode = 'create_new';
    }

    $file_data = $_FILES['zip_file'];

    try {
      $this->log('INFO', "Starting patterns import with mode: {$import_mode}");

      // Initialize ZIP handler and extract
      $zip_handler = new IPBMFZ_ZIP_Handler();
      $extraction_result = $zip_handler->upload_and_extract($file_data, 0);

      if (is_wp_error($extraction_result)) {
        wp_send_json_error(array(
          'message' => $extraction_result->get_error_message()
        ));
      }

      $extract_path = $extraction_result['extract_path'];
      $this->log('INFO', sprintf('ZIP extracted to: %s', $extract_path));

      // Initialize pattern handler and media importer
      $pattern_handler = new IPBMFZ_Synced_Pattern_Handler();
      $media_importer = new IPBMFZ_Media_Importer();

      // Import pattern images first
      $pattern_images_dir = $extract_path . '/synced-patterns/pattern-images';
      $image_map = array();

      $this->log('INFO', sprintf('Checking for pattern images in: %s', $pattern_images_dir));

      if (is_dir($pattern_images_dir)) {
        $pattern_images = glob($pattern_images_dir . '/*');
        $this->log('INFO', sprintf('Found %d pattern images to import', count($pattern_images)));

        foreach ($pattern_images as $image_path) {
          $filename = basename($image_path);
          $this->log('INFO', sprintf('Importing pattern image: %s', $filename));

          // Import image to media library (post_id = 0 for unattached)
          $attachment_id = $media_importer->import_image($image_path, 0, $filename);
          if (!is_wp_error($attachment_id)) {
            $image_map[$filename] = $attachment_id;
            $this->log('INFO', sprintf('Successfully imported pattern image: %s (ID: %d)', $filename, $attachment_id));
          } else {
            $this->log('WARNING', sprintf('Failed to import pattern image: %s - %s', $filename, $attachment_id->get_error_message()));
          }
        }
      } else {
        $this->log('INFO', 'No pattern images directory found');
      }

      // Find pattern JSON files
      $patterns_dir = $extract_path . '/synced-patterns';
      $json_files = glob($patterns_dir . '/*.json');

      $this->log('INFO', sprintf('Looking for JSON files in: %s', $patterns_dir));
      $this->log('INFO', sprintf('Found %d JSON files to import', count($json_files)));

      if (empty($json_files)) {
        $this->log('ERROR', 'No pattern JSON files found');
        $zip_handler->cleanup($extract_path);
        wp_send_json_error(array(
          'message' => __('同期パターンファイルが見つかりませんでした。', 'wp-single-post-migrator')
        ));
      }

      // Import patterns
      $this->log('INFO', sprintf('Starting pattern import with %d images mapped', count($image_map)));
      $import_result = $pattern_handler->import_synced_patterns($json_files, $image_map, $import_mode);

      // Cleanup
      $zip_handler->cleanup($extract_path);

      if (is_wp_error($import_result)) {
        wp_send_json_error(array(
          'message' => $import_result->get_error_message()
        ));
      }

      $this->log('INFO', sprintf(
        'Patterns import completed. Created: %d, Updated: %d, Errors: %d',
        $import_result['imported_patterns'],
        $import_result['updated_patterns'],
        count($import_result['errors'])
      ));

      // Prepare response
      $total_patterns = $import_result['imported_patterns'] + $import_result['updated_patterns'];
      $message_parts = array();

      if ($import_result['imported_patterns'] > 0) {
        $message_parts[] = sprintf(__('%d個の新規パターンを作成', 'wp-single-post-migrator'), $import_result['imported_patterns']);
      }
      if ($import_result['updated_patterns'] > 0) {
        $message_parts[] = sprintf(__('%d個のパターンを更新', 'wp-single-post-migrator'), $import_result['updated_patterns']);
      }
      if ($import_result['skipped_patterns'] > 0) {
        $message_parts[] = sprintf(__('%d個のパターンをスキップ', 'wp-single-post-migrator'), $import_result['skipped_patterns']);
      }

      $message = empty($message_parts) ?
        __('パターンのインポートが完了しました。', 'wp-single-post-migrator') :
        implode('、', $message_parts) . __('しました。', 'wp-single-post-migrator');

      $response_data = array(
        'imported_patterns' => $import_result['imported_patterns'],
        'updated_patterns' => $import_result['updated_patterns'],
        'skipped_patterns' => $import_result['skipped_patterns'],
        'total_patterns' => $total_patterns,
        'imported_images' => count($image_map),
        'errors' => $import_result['errors'],
        'message' => $message
      );

      wp_send_json_success($response_data);
    } catch (Exception $e) {
      if (isset($extract_path)) {
        $zip_handler->cleanup($extract_path);
      }
      $this->log('ERROR', 'Pattern import exception: ' . $e->getMessage());
      wp_send_json_error(array(
        'message' => sprintf(
          __('インポート中にエラーが発生しました: %s', 'wp-single-post-migrator'),
          $e->getMessage()
        )
      ));
    }
  }

  /**
   * Create ZIP file from patterns directory
   *
   * @param string $temp_dir Temporary directory
   * @return array|WP_Error ZIP creation result
   */
  private function create_patterns_zip($temp_dir)
  {
    $this->log('INFO', 'Starting ZIP file creation for patterns');

    $upload_dir = wp_upload_dir();
    $zip_filename = 'synced-patterns-export-' . date('Y-m-d-H-i-s') . '.zip';
    $zip_path = $upload_dir['path'] . '/' . $zip_filename;

    $this->log('INFO', sprintf('ZIP path: %s', $zip_path));
    $this->log('INFO', sprintf('Temp dir: %s', $temp_dir));

    // Check if temp directory exists and has content
    if (!is_dir($temp_dir)) {
      $this->log('ERROR', 'Temp directory does not exist');
      return new WP_Error(
        'temp_dir_missing',
        __('一時ディレクトリが見つかりません。', 'wp-single-post-migrator')
      );
    }

    $zip = new ZipArchive();
    $zip_result = $zip->open($zip_path, ZipArchive::CREATE);
    if ($zip_result !== TRUE) {
      $this->log('ERROR', sprintf('ZIP creation failed with error code: %d', $zip_result));
      return new WP_Error(
        'zip_creation_failed',
        sprintf(__('ZIPファイルの作成に失敗しました。エラーコード: %d', 'wp-single-post-migrator'), $zip_result)
      );
    }

    // Add all files from temp directory
    $files = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($temp_dir),
      RecursiveIteratorIterator::LEAVES_ONLY
    );

    $file_count = 0;
    foreach ($files as $name => $file) {
      if (!$file->isDir()) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($temp_dir) + 1);
        $this->log('DEBUG', sprintf('Adding file to ZIP: %s -> %s', $filePath, $relativePath));
        $zip->addFile($filePath, $relativePath);
        $file_count++;
      }
    }

    $this->log('INFO', sprintf('Added %d files to ZIP archive', $file_count));

    $zip_close_result = $zip->close();
    $this->log('INFO', sprintf('ZIP close result: %s', $zip_close_result ? 'success' : 'failed'));

    if (!file_exists($zip_path)) {
      $this->log('ERROR', 'ZIP file was not created at expected path');
      return new WP_Error(
        'zip_file_missing',
        __('ZIPファイルの作成に失敗しました。', 'wp-single-post-migrator')
      );
    }

    $file_size = filesize($zip_path);
    $this->log('INFO', sprintf('ZIP file created successfully. Size: %d bytes', $file_size));

    return array(
      'zip_path' => $zip_path,
      'zip_url' => $upload_dir['url'] . '/' . $zip_filename,
      'file_size' => filesize($zip_path)
    );
  }

  /**
   * Cleanup temporary directory
   *
   * @param string $temp_dir Directory to cleanup
   */
  private function cleanup_temp_directory($temp_dir)
  {
    if (is_dir($temp_dir)) {
      $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
      );

      foreach ($files as $fileinfo) {
        if ($fileinfo->isDir()) {
          rmdir($fileinfo->getRealPath());
        } else {
          unlink($fileinfo->getRealPath());
        }
      }
      rmdir($temp_dir);
    }
  }
}

// Initialize AJAX handlers
new IPBMFZ_AJAX_Handlers();
