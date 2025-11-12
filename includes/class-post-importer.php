<?php

/**
 * Post Importer Class
 *
 * Handles importing WordPress posts with images from ZIP format
 */

if (!defined('ABSPATH')) {
  exit;
}

class IPBMFZ_Post_Importer
{

  /**
   * ZIP handler instance
   */
  private $zip_handler;

  /**
   * Media importer instance
   */
  private $media_importer;

  /**
   * Block updater instance
   */
  private $block_updater;

  /**
   * Synced pattern handler instance
   */
  private $pattern_handler;

  /**
   * Constructor
   *
   * @throws Exception If required classes are not available
   */
  public function __construct()
  {
    if (!class_exists('IPBMFZ_ZIP_Handler')) {
      throw new Exception('IPBMFZ_ZIP_Handler class not found');
    }
    if (!class_exists('IPBMFZ_Media_Importer')) {
      throw new Exception('IPBMFZ_Media_Importer class not found');
    }
    if (!class_exists('IPBMFZ_Block_Updater')) {
      throw new Exception('IPBMFZ_Block_Updater class not found');
    }

    $this->zip_handler = new IPBMFZ_ZIP_Handler();
    $this->media_importer = new IPBMFZ_Media_Importer();
    $this->block_updater = new IPBMFZ_Block_Updater();

    try {
      $this->pattern_handler = new IPBMFZ_Synced_Pattern_Handler();
    } catch (Exception $e) {
      throw new Exception('Failed to initialize synced pattern handler: ' . $e->getMessage());
    }
  }

  /**
   * Import post and images from ZIP
   *
   * @param array $file_data $_FILES array data
   * @param array $options Import options
   * @return array|WP_Error Import result
   */
  public function import_post_from_zip($file_data, $options = array())
  {
    $default_options = array(
      'import_mode' => 'create_new', // 'create_new', 'update_existing', 'replace_current'
      'target_post_id' => null,
      'import_images' => true,
      'import_meta' => true,
      'import_synced_patterns' => false
    );
    $options = array_merge($default_options, $options);

    try {
      $this->log('INFO', 'Starting post import from ZIP');

      // Step 1: Extract ZIP file
      $extraction_result = $this->zip_handler->upload_and_extract($file_data, 0, false);
      if (is_wp_error($extraction_result)) {
        return $extraction_result;
      }

      $extract_path = $extraction_result['extract_path'];
      $extracted_files = $extraction_result['files'];

      // Step 2: Find and parse XML file
      $xml_result = $this->find_and_parse_xml($extract_path);
      if (is_wp_error($xml_result)) {
        $this->zip_handler->cleanup($extract_path);
        return $xml_result;
      }

      $post_data = $xml_result['post_data'];
      $meta_data = $xml_result['meta_data'];
      $taxonomy_data = isset($xml_result['taxonomy_data']) ? $xml_result['taxonomy_data'] : array();

      // Step 3: Import or update post
      $post_result = $this->import_post_data($post_data, $meta_data, $taxonomy_data, $options);
      if (is_wp_error($post_result)) {
        $this->zip_handler->cleanup($extract_path);
        return $post_result;
      }

      $imported_post_id = $post_result['post_id'];
      $is_new_post = $post_result['is_new'];

      // Step 4: Import images if requested
      $image_results = array();
      $pattern_results = array();
      if ($options['import_images']) {
        $image_results = $this->import_images_and_update_content($extracted_files, $imported_post_id, $extract_path);
        if (is_wp_error($image_results)) {
          $this->log('WARNING', 'Image import failed but post was created: ' . $image_results->get_error_message());
          $image_results = array('imported_count' => 0, 'updated_blocks' => 0);
        }
      }

      // Step 5: Import synced patterns if requested and update pattern references
      $pattern_reference_map = array();
      if ($options['import_synced_patterns']) {
        $pattern_results = $this->import_synced_patterns_from_zip($extract_path, $image_results);
        if (is_wp_error($pattern_results)) {
          $this->log('WARNING', 'Pattern import failed: ' . $pattern_results->get_error_message());
          $pattern_results = array('imported_patterns' => 0, 'updated_patterns' => 0);
        } else {
          // Get pattern reference mapping from import results
          $pattern_reference_map = isset($pattern_results['pattern_reference_map']) ? $pattern_results['pattern_reference_map'] : array();
        }
      } else {
        // Check if ZIP contains pattern reference mapping even if patterns aren't being imported
        $old_patterns = $this->pattern_handler->load_pattern_reference_mapping($extract_path);
        if (!empty($old_patterns)) {
          // Create enhanced mapping using pattern titles from export
          $pattern_reference_map = $this->create_enhanced_pattern_mapping($imported_post_id, $old_patterns);
        } else {
          // Try to use any saved pattern mapping from previous imports
          $pattern_reference_map = $this->pattern_handler->get_saved_pattern_reference_mapping();

          // Check if the existing mapping actually covers the patterns used in the post
          if (!empty($pattern_reference_map)) {
            $content_pattern_mapping = $this->create_pattern_mapping_from_content($imported_post_id);
            if (!empty($content_pattern_mapping)) {
              // Merge content-based mapping with existing mapping, prioritizing content-based
              // Use + operator to preserve numeric keys (array_merge reindexes numeric keys!)
              $pattern_reference_map = $content_pattern_mapping + $pattern_reference_map;

            }
          } else {
            $this->log('INFO', 'No pattern mapping found, attempting to create mapping by matching existing patterns');
            $pattern_reference_map = $this->create_pattern_mapping_from_content($imported_post_id);
          }
        }
      }

      // Step 6: Update pattern references in post content if mapping exists
      if (!empty($pattern_reference_map)) {
        $this->log('INFO', sprintf('Found pattern reference mapping with %d entries for post %d', count($pattern_reference_map), $imported_post_id));
        $this->update_post_pattern_references($imported_post_id, $pattern_reference_map);
      } else {
        $this->log('INFO', sprintf('No pattern reference mapping found for post %d', $imported_post_id));
      }

      // Step 7: Cleanup
      $this->zip_handler->cleanup($extract_path);

      $this->log('INFO', sprintf(
        'Post import completed. Post ID: %d, New post: %s, Images: %d, Updated blocks: %d, Patterns: %d',
        $imported_post_id,
        $is_new_post ? 'yes' : 'no',
        isset($image_results['imported_count']) ? $image_results['imported_count'] : 0,
        isset($image_results['updated_blocks']) ? $image_results['updated_blocks'] : 0,
        isset($pattern_results['imported_patterns']) ? $pattern_results['imported_patterns'] : 0
      ));

      return array(
        'post_id' => $imported_post_id,
        'post_title' => get_the_title($imported_post_id),
        'post_url' => get_edit_post_link($imported_post_id),
        'is_new_post' => $is_new_post,
        'imported_images' => isset($image_results['imported_count']) ? $image_results['imported_count'] : 0,
        'updated_blocks' => isset($image_results['updated_blocks']) ? $image_results['updated_blocks'] : 0,
        'imported_patterns' => isset($pattern_results['imported_patterns']) ? $pattern_results['imported_patterns'] : 0,
        'updated_patterns' => isset($pattern_results['updated_patterns']) ? $pattern_results['updated_patterns'] : 0,
        'failed_matches' => isset($image_results['failed_matches']) ? $image_results['failed_matches'] : array(),
        'pattern_errors' => isset($pattern_results['errors']) ? $pattern_results['errors'] : array(),
        'message' => $this->generate_success_message($is_new_post, $imported_post_id, $image_results, $pattern_results)
      );
    } catch (Exception $e) {
      if (isset($extract_path)) {
        $this->zip_handler->cleanup($extract_path);
      }
      $this->log('ERROR', 'Import exception: ' . $e->getMessage());
      return new WP_Error(
        'import_exception',
        sprintf(__('インポート中にエラーが発生しました: %s', 'wp-single-post-migrator'), $e->getMessage())
      );
    }
  }

  /**
   * Find and parse XML file from extracted directory
   *
   * @param string $extract_path Extracted directory path
   * @return array|WP_Error Parse result
   */
  private function find_and_parse_xml($extract_path)
  {
    // Find XML files
    $xml_files = glob($extract_path . '/*.xml');

    if (empty($xml_files)) {
      return new WP_Error(
        'no_xml_found',
        __('ZIPファイル内にXMLファイルが見つかりませんでした。', 'wp-single-post-migrator')
      );
    }

    // Use the first XML file found
    $xml_file = $xml_files[0];
    $xml_content = file_get_contents($xml_file);

    if ($xml_content === false) {
      return new WP_Error(
        'xml_read_failed',
        __('XMLファイルの読み込みに失敗しました。', 'wp-single-post-migrator')
      );
    }

    // Try to determine XML format and parse
    if (strpos($xml_content, 'xmlns:wp="http://wordpress.org/export/1.2/"') !== false) {
      // WXR format
      return $this->parse_wxr_xml($xml_content);
    } else {
      // Simple format
      return $this->parse_simple_xml($xml_content);
    }
  }

  /**
   * Parse WXR (WordPress eXtended RSS) XML
   *
   * @param string $xml_content XML content
   * @return array|WP_Error Parse result
   */
  private function parse_wxr_xml($xml_content)
  {
    $xml = simplexml_load_string($xml_content);
    if ($xml === false) {
      return new WP_Error(
        'xml_parse_failed',
        __('XMLの解析に失敗しました。', 'wp-single-post-migrator')
      );
    }

    // Register namespaces
    $xml->registerXPathNamespace('wp', 'http://wordpress.org/export/1.2/');
    $xml->registerXPathNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
    $xml->registerXPathNamespace('excerpt', 'http://wordpress.org/export/1.2/excerpt/');

    $items = $xml->xpath('//item');
    if (empty($items)) {
      return new WP_Error(
        'no_posts_found',
        __('XMLファイル内に投稿データが見つかりませんでした。', 'wp-single-post-migrator')
      );
    }

    // Get the first post (assuming single post export)
    $item = $items[0];

    $post_data = array(
      'post_title' => (string) $item->title,
      'post_content' => (string) $item->children('content', true)->encoded,
      'post_excerpt' => (string) $item->children('excerpt', true)->encoded,
      'post_status' => (string) $item->children('wp', true)->status,
      'post_type' => (string) $item->children('wp', true)->post_type,
      'post_name' => (string) $item->children('wp', true)->post_name,
      'post_date' => (string) $item->children('wp', true)->post_date,
      'comment_status' => (string) $item->children('wp', true)->comment_status,
      'ping_status' => (string) $item->children('wp', true)->ping_status,
      'menu_order' => (int) $item->children('wp', true)->menu_order,
      'post_password' => (string) $item->children('wp', true)->post_password
    );

    // Extract meta data
    $meta_data = array();
    $postmetas = $item->xpath('.//wp:postmeta');
    foreach ($postmetas as $postmeta) {
      $meta_key = (string) $postmeta->children('wp', true)->meta_key;
      $meta_value = (string) $postmeta->children('wp', true)->meta_value;
      $meta_data[$meta_key] = $meta_value;
    }

    // Extract taxonomy data
    $taxonomy_data = array();
    $categories = $item->xpath('.//category');
    foreach ($categories as $category) {
      $domain = (string) $category['domain'];
      $nicename = (string) $category['nicename'];
      $name = (string) $category;

      if (!isset($taxonomy_data[$domain])) {
        $taxonomy_data[$domain] = array();
      }

      $taxonomy_data[$domain][] = array(
        'name' => $name,
        'slug' => $nicename
      );
    }

    return array(
      'post_data' => $post_data,
      'meta_data' => $meta_data,
      'taxonomy_data' => $taxonomy_data,
      'format' => 'wxr'
    );
  }

  /**
   * Parse simple XML format
   *
   * @param string $xml_content XML content
   * @return array|WP_Error Parse result
   */
  private function parse_simple_xml($xml_content)
  {
    $xml = simplexml_load_string($xml_content);
    if ($xml === false) {
      return new WP_Error(
        'xml_parse_failed',
        __('XMLの解析に失敗しました。', 'wp-single-post-migrator')
      );
    }

    $post_data = array(
      'post_title' => (string) $xml->title,
      'post_content' => (string) $xml->content,
      'post_excerpt' => (string) $xml->excerpt,
      'post_status' => (string) $xml->status ?: 'draft',
      'post_type' => (string) $xml->type ?: 'post',
      'post_name' => (string) $xml->slug,
      'post_date' => (string) $xml->date
    );

    // Extract meta data
    $meta_data = array();
    if (isset($xml->meta_fields)) {
      foreach ($xml->meta_fields->meta_field as $meta_field) {
        $meta_key = (string) $meta_field['key'];
        $meta_value = (string) $meta_field;
        $meta_data[$meta_key] = $meta_value;
      }
    }

    // Extract taxonomy data (simple format)
    $taxonomy_data = array();
    if (isset($xml->taxonomies)) {
      foreach ($xml->taxonomies->taxonomy as $taxonomy) {
        $taxonomy_name = (string) $taxonomy['name'];
        if (!isset($taxonomy_data[$taxonomy_name])) {
          $taxonomy_data[$taxonomy_name] = array();
        }

        foreach ($taxonomy->term as $term) {
          $taxonomy_data[$taxonomy_name][] = array(
            'name' => (string) $term['name'],
            'slug' => (string) $term['slug']
          );
        }
      }
    }

    return array(
      'post_data' => $post_data,
      'meta_data' => $meta_data,
      'taxonomy_data' => $taxonomy_data,
      'format' => 'simple'
    );
  }

  /**
   * Import post data and meta fields
   *
   * @param array $post_data Post data
   * @param array $meta_data Meta data
   * @param array $options Import options
   * @return array|WP_Error Import result
   */
  private function import_post_data($post_data, $meta_data, $taxonomy_data, $options)
  {
    // Sanitize post data
    $sanitized_data = $this->sanitize_post_data($post_data);

    if ($options['import_mode'] === 'update_existing' && !empty($options['target_post_id'])) {
      // Update existing post using direct database update
      $post_id = $this->direct_post_update($options['target_post_id'], $sanitized_data);
      $is_new = false;
    } elseif ($options['import_mode'] === 'replace_current' && !empty($options['target_post_id'])) {
      // Replace current post content using direct database update
      $post_id = $this->direct_post_update($options['target_post_id'], $sanitized_data);
      $is_new = false;
    } else {
      // Create new post using direct database insert
      $post_id = $this->direct_post_insert($sanitized_data);
      $is_new = true;
    }

    if (is_wp_error($post_id)) {
      return $post_id;
    }

    if (!$post_id) {
      return new WP_Error(
        'post_creation_failed',
        __('投稿の作成に失敗しました。', 'wp-single-post-migrator')
      );
    }

    // Import meta fields if requested
    if ($options['import_meta'] && !empty($meta_data)) {
      foreach ($meta_data as $meta_key => $meta_value) {
        update_post_meta($post_id, $meta_key, $meta_value);
      }
    }

    // Import taxonomies if available
    if (!empty($taxonomy_data)) {
      $this->import_taxonomies($post_id, $taxonomy_data);
    }

    $this->log('INFO', sprintf(
      'Post %s successfully. ID: %d, Title: %s',
      $is_new ? 'created' : 'updated',
      $post_id,
      $sanitized_data['post_title']
    ));

    return array(
      'post_id' => $post_id,
      'is_new' => $is_new
    );
  }

  /**
   * Import taxonomies for a post
   *
   * @param int $post_id Post ID
   * @param array $taxonomy_data Taxonomy data
   */
  private function import_taxonomies($post_id, $taxonomy_data)
  {
    foreach ($taxonomy_data as $taxonomy => $terms) {
      // Check if taxonomy exists on this site
      if (!taxonomy_exists($taxonomy)) {
        $this->log('WARNING', "Taxonomy '{$taxonomy}' does not exist on this site. Skipping.");
        continue;
      }

      $term_ids = array();

      foreach ($terms as $term_data) {
        $term_name = $term_data['name'];
        $term_slug = $term_data['slug'];

        // Try to find existing term first
        $existing_term = get_term_by('slug', $term_slug, $taxonomy);

        if ($existing_term) {
          // Use existing term
          $term_ids[] = $existing_term->term_id;
          $this->log('INFO', "Using existing term '{$term_name}' in taxonomy '{$taxonomy}'");
        } else {
          // Create new term if it doesn't exist
          $term_result = wp_insert_term($term_name, $taxonomy, array('slug' => $term_slug));

          if (!is_wp_error($term_result)) {
            $term_ids[] = $term_result['term_id'];
            $this->log('INFO', "Created new term '{$term_name}' in taxonomy '{$taxonomy}'");
          } else {
            $this->log('ERROR', "Failed to create term '{$term_name}' in taxonomy '{$taxonomy}': " . $term_result->get_error_message());
          }
        }
      }

      // Set terms for the post
      if (!empty($term_ids)) {
        $result = wp_set_post_terms($post_id, $term_ids, $taxonomy);
        if (is_wp_error($result)) {
          $this->log('ERROR', "Failed to set terms for taxonomy '{$taxonomy}': " . $result->get_error_message());
        } else {
          $this->log('INFO', "Set " . count($term_ids) . " terms for taxonomy '{$taxonomy}' on post {$post_id}");
        }
      }
    }
  }

  /**
   * Sanitize post data
   *
   * @param array $post_data Raw post data
   * @return array Sanitized post data
   */
  private function sanitize_post_data($post_data)
  {
    $sanitized = array();

    // Required fields
    $sanitized['post_title'] = sanitize_text_field($post_data['post_title'] ?? '');

    // Skip wp_kses_post() to prevent LazyBlocks newline corruption
    // Use minimal sanitization instead
    $sanitized['post_content'] = $this->minimal_content_sanitization($post_data['post_content'] ?? '');

    // Optional fields
    if (!empty($post_data['post_excerpt'])) {
      $sanitized['post_excerpt'] = sanitize_textarea_field($post_data['post_excerpt']);
    }

    if (!empty($post_data['post_status'])) {
      $allowed_statuses = get_post_stati();
      $status = $post_data['post_status'];
      $sanitized['post_status'] = in_array($status, array_keys($allowed_statuses)) ? $status : 'draft';
    }

    if (!empty($post_data['post_type'])) {
      $post_types = get_post_types();
      $type = $post_data['post_type'];
      $sanitized['post_type'] = in_array($type, $post_types) ? $type : 'post';
    }

    if (!empty($post_data['post_name'])) {
      $sanitized['post_name'] = sanitize_title($post_data['post_name']);
    }

    if (!empty($post_data['post_date'])) {
      $sanitized['post_date'] = sanitize_text_field($post_data['post_date']);
    }

    if (!empty($post_data['comment_status'])) {
      $sanitized['comment_status'] = in_array($post_data['comment_status'], array('open', 'closed'))
        ? $post_data['comment_status'] : 'open';
    }

    if (!empty($post_data['ping_status'])) {
      $sanitized['ping_status'] = in_array($post_data['ping_status'], array('open', 'closed'))
        ? $post_data['ping_status'] : 'open';
    }

    if (isset($post_data['menu_order'])) {
      $sanitized['menu_order'] = intval($post_data['menu_order']);
    }

    if (!empty($post_data['post_password'])) {
      $sanitized['post_password'] = sanitize_text_field($post_data['post_password']);
    }

    return $sanitized;
  }

  /**
   * Import images and update content
   *
   * @param array $image_files Extracted image files
   * @param int $post_id Post ID
   * @param string $extract_path Extract path
   * @return array|WP_Error Import result
   */
  private function import_images_and_update_content($image_files, $post_id, $extract_path)
  {
    // Find images directory
    $images_dir = $extract_path . '/images';
    if (!is_dir($images_dir)) {
      // Images might be in root directory
      $images_dir = $extract_path;
    }

    // Get image files from images directory
    $image_files_in_dir = glob($images_dir . '/*.{jpg,jpeg,png,gif,webp,svg}', GLOB_BRACE);

    if (empty($image_files_in_dir)) {
      return new WP_Error(
        'no_images_found',
        __('ZIPファイル内に画像が見つかりませんでした。', 'wp-single-post-migrator')
      );
    }

    // Convert to filename => path mapping
    $image_map_for_import = array();
    foreach ($image_files_in_dir as $image_path) {
      $filename = basename($image_path);
      $image_map_for_import[$filename] = $image_path;
    }

    // Import images to media library
    $import_results = $this->media_importer->import_multiple_images($image_map_for_import, $post_id);

    if (empty($import_results['imported'])) {
      return new WP_Error(
        'no_images_imported',
        __('画像のインポートに失敗しました。', 'wp-single-post-migrator'),
        $import_results['errors']
      );
    }

    // Update post content with new image URLs using automatic domain detection
    $block_results = $this->block_updater->update_blocks($post_id, $import_results['imported']);

    if (is_wp_error($block_results)) {
      return $block_results;
    }

    return array(
      'imported_count' => $import_results['count'],
      'updated_blocks' => $block_results['updated_blocks'],
      'failed_matches' => $block_results['failed_matches'],
      'processed_images' => $block_results['processed_images']
    );
  }

  /**
   * Import images only (existing functionality)
   *
   * @param array $file_data $_FILES array data
   * @param int $post_id Post ID
   * @return array|WP_Error Import result
   */
  public function import_images_only($file_data, $post_id)
  {
    try {
      $this->log('INFO', "Starting image-only import for post ID {$post_id}");

      // Step 1: Upload and extract ZIP
      $extraction_result = $this->zip_handler->upload_and_extract($file_data, $post_id);
      if (is_wp_error($extraction_result)) {
        return $extraction_result;
      }

      $extract_path = $extraction_result['extract_path'];
      $image_files = $extraction_result['files'];

      // Step 2: Import images to media library
      $import_results = $this->media_importer->import_multiple_images($image_files, $post_id);

      if (empty($import_results['imported'])) {
        $this->zip_handler->cleanup($extract_path);
        return new WP_Error(
          'no_images_imported',
          __('インポートできる画像がありませんでした。', 'wp-single-post-migrator'),
          $import_results['errors']
        );
      }

      // Step 3: Update blocks using automatic domain detection
      $block_results = $this->block_updater->update_blocks($post_id, $import_results['imported']);

      if (is_wp_error($block_results)) {
        $this->zip_handler->cleanup($extract_path);
        return $block_results;
      }

      // Step 4: Cleanup
      $this->zip_handler->cleanup($extract_path);

      $this->log('INFO', sprintf(
        "Image-only import completed for post ID {$post_id}. Images: %d, Blocks: %d",
        $import_results['count'],
        $block_results['updated_blocks']
      ));

      return array(
        'imported_count' => $import_results['count'],
        'updated_blocks' => $block_results['updated_blocks'],
        'failed_matches' => $block_results['failed_matches'],
        'import_errors' => $import_results['errors'],
        'processed_images' => $block_results['processed_images'],
        'message' => $this->generate_image_only_success_message($import_results['count'], $block_results['updated_blocks'])
      );
    } catch (Exception $e) {
      if (isset($extract_path)) {
        $this->zip_handler->cleanup($extract_path);
      }
      $this->log('ERROR', 'Image-only import exception: ' . $e->getMessage());
      return new WP_Error(
        'import_exception',
        sprintf(__('処理中にエラーが発生しました: %s', 'wp-single-post-migrator'), $e->getMessage())
      );
    }
  }

  /**
   * Generate success message for post import
   *
   * @param bool $is_new_post Whether this is a new post
   * @param int $post_id Post ID
   * @param array $image_results Image import results
   * @return string Success message
   */
  private function generate_success_message($is_new_post, $post_id, $image_results, $pattern_results = array())
  {
    $post_action = $is_new_post ? __('作成', 'wp-single-post-migrator') : __('更新', 'wp-single-post-migrator');
    $image_count = isset($image_results['imported_count']) ? $image_results['imported_count'] : 0;
    $block_count = isset($image_results['updated_blocks']) ? $image_results['updated_blocks'] : 0;
    $pattern_count = isset($pattern_results['imported_patterns']) ? $pattern_results['imported_patterns'] : 0;
    $updated_pattern_count = isset($pattern_results['updated_patterns']) ? $pattern_results['updated_patterns'] : 0;

    $messages = array();

    // Base message
    $messages[] = sprintf(__('記事を%sしました。', 'wp-single-post-migrator'), $post_action);

    // Image message
    if ($image_count > 0) {
      $messages[] = sprintf(
        __('%d件の画像をインポートして%d個のブロックを更新しました。', 'wp-single-post-migrator'),
        $image_count,
        $block_count
      );
    }

    // Pattern message
    if ($pattern_count > 0 || $updated_pattern_count > 0) {
      $pattern_msg = array();
      if ($pattern_count > 0) {
        $pattern_msg[] = sprintf(__('%d個の同期パターンをインポート', 'wp-single-post-migrator'), $pattern_count);
      }
      if ($updated_pattern_count > 0) {
        $pattern_msg[] = sprintf(__('%d個の同期パターンを更新', 'wp-single-post-migrator'), $updated_pattern_count);
      }
      $messages[] = implode('、', $pattern_msg) . __('しました。', 'wp-single-post-migrator');
    }

    return implode(' ', $messages);
  }

  /**
   * Generate success message for image-only import
   *
   * @param int $imported_count Number of imported images
   * @param int $updated_blocks Number of updated blocks
   * @return string Success message
   */
  private function generate_image_only_success_message($imported_count, $updated_blocks)
  {
    if ($updated_blocks > 0) {
      return sprintf(
        __('%d件の画像をインポートし、%d個のブロックを更新しました。', 'wp-single-post-migrator'),
        $imported_count,
        $updated_blocks
      );
    } else {
      return sprintf(
        __('%d件の画像をインポートしましたが、マッチするブロックが見つかりませんでした。', 'wp-single-post-migrator'),
        $imported_count
      );
    }
  }

  /**
   * Import synced patterns from ZIP file
   *
   * @param string $extract_path Extracted ZIP directory path
   * @param array $image_results Image import results containing image mapping
   * @return array|WP_Error Import results
   */
  private function import_synced_patterns_from_zip($extract_path, $image_results = array())
  {
    // Check if synced-patterns directory exists
    $patterns_dir = $extract_path . '/synced-patterns';
    if (!is_dir($patterns_dir)) {
      $this->log('INFO', 'No synced-patterns directory found in ZIP file');
      return array(
        'imported_patterns' => 0,
        'updated_patterns' => 0,
        'errors' => array()
      );
    }

    // Find all JSON files in the synced-patterns directory, excluding reference mapping file
    $all_json_files = glob($patterns_dir . '/*.json');
    $json_files = array();

    foreach ($all_json_files as $file) {
      $filename = basename($file);
      // Skip the reference mapping file
      if ($filename !== 'pattern-refs.json') {
        $json_files[] = $file;
      }
    }

    if (empty($json_files)) {
      $this->log('INFO', 'No pattern JSON files found in synced-patterns directory (excluding pattern-refs.json)');
      return array(
        'imported_patterns' => 0,
        'updated_patterns' => 0,
        'errors' => array()
      );
    }

    // Create image map from pattern images if any were imported
    $pattern_image_map = array();
    $pattern_images_dir = $patterns_dir . '/pattern-images';
    if (is_dir($pattern_images_dir) && !empty($image_results)) {
      $pattern_image_map = $this->create_pattern_image_map($pattern_images_dir, $image_results);
    }

    // Import patterns using the pattern handler
    $import_mode = 'create_new'; // Default to creating new patterns to avoid conflicts
    $results = $this->pattern_handler->import_synced_patterns($json_files, $pattern_image_map, $import_mode);

    if (is_wp_error($results)) {
      $this->log('ERROR', 'Pattern import failed: ' . $results->get_error_message());
      return $results;
    }

    $this->log('INFO', sprintf(
      'Pattern import completed. Created: %d, Updated: %d, Errors: %d',
      $results['imported_patterns'],
      $results['updated_patterns'],
      count($results['errors'])
    ));

    return $results;
  }

  /**
   * Create image mapping for pattern images
   *
   * @param string $pattern_images_dir Pattern images directory
   * @param array $image_results Image import results
   * @return array Image filename to attachment ID mapping
   */
  private function create_pattern_image_map($pattern_images_dir, $image_results)
  {
    $image_map = array();

    // Get list of pattern images
    $pattern_images = glob($pattern_images_dir . '/*');
    if (empty($pattern_images)) {
      return $image_map;
    }

    // Create mapping from image results if available
    if (!empty($image_results['image_mapping'])) {
      foreach ($image_results['image_mapping'] as $filename => $attachment_id) {
        $image_map[$filename] = $attachment_id;
      }
    }

    // For pattern images that weren't already imported, try to match existing media
    foreach ($pattern_images as $image_path) {
      $filename = basename($image_path);

      if (!isset($image_map[$filename])) {
        // Try to find existing attachment with same filename
        $existing_attachment = $this->find_existing_attachment_by_filename($filename);
        if ($existing_attachment) {
          $image_map[$filename] = $existing_attachment;
          $this->log('INFO', sprintf('Mapped pattern image %s to existing attachment %d', $filename, $existing_attachment));
        }
      }
    }

    return $image_map;
  }

  /**
   * Find existing attachment by filename
   *
   * @param string $filename Image filename
   * @return int|null Attachment ID or null if not found
   */
  private function find_existing_attachment_by_filename($filename)
  {
    $attachments = get_posts(array(
      'post_type' => 'attachment',
      'post_status' => 'inherit',
      'meta_query' => array(
        array(
          'key' => '_wp_attached_file',
          'value' => $filename,
          'compare' => 'LIKE'
        )
      ),
      'posts_per_page' => 1,
      'fields' => 'ids'
    ));

    return !empty($attachments) ? $attachments[0] : null;
  }

  /**
   * Update pattern references in post content
   *
   * @param int $post_id Post ID
   * @param array $pattern_reference_map Pattern ID mapping (old_id => new_id)
   */
  private function update_post_pattern_references($post_id, $pattern_reference_map)
  {
    if (empty($pattern_reference_map)) {
      return;
    }

    $post = get_post($post_id);
    if (!$post) {
      $this->log('ERROR', sprintf('Post %d not found for pattern reference update', $post_id));
      return;
    }

    $this->log('INFO', sprintf('Updating pattern references in post %d with %d mappings', $post_id, count($pattern_reference_map)));


    $updated_content = $this->pattern_handler->update_pattern_references($post->post_content, $pattern_reference_map);

    if ($updated_content !== $post->post_content) {
      $update_result = $this->direct_content_update($post_id, $updated_content);

      if (!is_wp_error($update_result) && $update_result) {
        $this->log('INFO', sprintf('Pattern references updated successfully in post %d', $post_id));
      } else {
        $this->log('ERROR', sprintf('Failed to update pattern references in post %d', $post_id));
      }
    } else {
      $this->log('INFO', sprintf('No pattern references found to update in post %d', $post_id));
    }
  }

  /**
   * Create pattern mapping by matching titles
   *
   * @param array $old_patterns Original pattern data from export
   * @return array Pattern reference mapping (old_id => new_id)
   */
  private function create_pattern_mapping_by_title($old_patterns)
  {
    $mapping = array();

    foreach ($old_patterns as $old_id => $old_data) {
      // Try to find existing pattern by title
      $existing_patterns = get_posts(array(
        'post_type' => 'wp_block',
        'post_status' => 'publish',
        'title' => $old_data['title'],
        'posts_per_page' => 1,
        'fields' => 'ids'
      ));

      if (!empty($existing_patterns)) {
        $new_id = $existing_patterns[0];
        $mapping[$old_id] = $new_id;
        $this->log('INFO', sprintf('Mapped pattern by title: %d -> %d ("%s")', $old_id, $new_id, $old_data['title']));
      } else {
        // Try by slug as fallback
        if (!empty($old_data['slug'])) {
          $existing_patterns = get_posts(array(
            'post_type' => 'wp_block',
            'post_status' => 'publish',
            'name' => $old_data['slug'],
            'posts_per_page' => 1,
            'fields' => 'ids'
          ));

          if (!empty($existing_patterns)) {
            $new_id = $existing_patterns[0];
            $mapping[$old_id] = $new_id;
            $this->log('INFO', sprintf('Mapped pattern by slug: %d -> %d ("%s")', $old_id, $new_id, $old_data['slug']));
          }
        }
      }
    }

    return $mapping;
  }

  /**
   * Create enhanced pattern mapping using pattern titles from export
   *
   * @param int $post_id Post ID to analyze
   * @param array $old_patterns Pattern reference data from export
   * @return array Pattern reference mapping
   */
  private function create_enhanced_pattern_mapping($post_id, $old_patterns)
  {
    $post = get_post($post_id);
    if (!$post) {
      return array();
    }

    $mapping = array();

    // Find all block references in the content
    if (preg_match_all('/<!-- wp:block[^>]*"ref":(\d+)/', $post->post_content, $matches)) {
      $referenced_ids = array_unique($matches[1]);

      // Try to map each referenced ID to an existing pattern using title matching
      foreach ($referenced_ids as $old_ref_id) {
        $old_ref_id = (int)$old_ref_id;

        if (isset($old_patterns[$old_ref_id])) {
          $old_pattern_info = $old_patterns[$old_ref_id];
          $old_title = $old_pattern_info['title'];
          $old_slug = $old_pattern_info['slug'];


          // First try: exact title match
          $new_pattern = $this->find_pattern_by_title($old_title);
          if ($new_pattern) {
            $mapping[$old_ref_id] = $new_pattern->ID;
            $this->log('INFO', sprintf('Exact title match: %d -> %d ("%s")', $old_ref_id, $new_pattern->ID, $old_title));
            continue;
          }

          // Second try: slug match
          if ($old_slug) {
            $new_pattern = $this->find_pattern_by_slug($old_slug);
            if ($new_pattern) {
              $mapping[$old_ref_id] = $new_pattern->ID;
              $this->log('INFO', sprintf('Slug match: %d -> %d ("%s" / %s)', $old_ref_id, $new_pattern->ID, $old_title, $old_slug));
              continue;
            }
          }

          // Third try: partial title match
          $new_pattern = $this->find_pattern_by_partial_title($old_title);
          if ($new_pattern) {
            $mapping[$old_ref_id] = $new_pattern->ID;
            $this->log('INFO', sprintf('Partial title match: %d -> %d ("%s" -> "%s")', $old_ref_id, $new_pattern->ID, $old_title, $new_pattern->post_title));
            continue;
          }

          $this->log('WARNING', sprintf('No matching pattern found for: %d ("%s")', $old_ref_id, $old_title));
        } else {
          $this->log('WARNING', sprintf('No reference info found for pattern ID: %d', $old_ref_id));

          // Fallback to existing logic for unmapped patterns
          $fallback_mapping = $this->create_fallback_mapping_for_id($old_ref_id);
          if ($fallback_mapping) {
            $mapping[$old_ref_id] = $fallback_mapping;
          }
        }
      }
    }

    $this->log('INFO', sprintf('Enhanced pattern mapping created with %d entries', count($mapping)));

    return $mapping;
  }

  /**
   * Create pattern mapping from post content
   *
   * @param int $post_id Post ID to analyze
   * @return array Pattern reference mapping
   */
  private function create_pattern_mapping_from_content($post_id)
  {
    $post = get_post($post_id);
    if (!$post) {
      return array();
    }

    $mapping = array();

    // Find all block references in the content
    if (preg_match_all('/<!-- wp:block[^>]*"ref":(\d+)/', $post->post_content, $matches)) {
      $referenced_ids = array_unique($matches[1]);

      // Try to map each referenced ID to an existing pattern
      foreach ($referenced_ids as $old_ref_id) {
        // Ensure we use integer keys for consistency
        $old_ref_id = (int)$old_ref_id;

        // First, check if there's already a pattern with this ID
        $existing_pattern = get_post($old_ref_id);
        if ($existing_pattern && $existing_pattern->post_type === 'wp_block' && $existing_pattern->post_status === 'publish') {
          $mapping[$old_ref_id] = $old_ref_id;
          $this->log('INFO', sprintf('Direct mapping (pattern exists): %d -> %d ("%s")', $old_ref_id, $old_ref_id, $existing_pattern->post_title));
          continue;
        }

        // If not found, try to find a pattern that might be a match
        // Get all available patterns
        $available_patterns = get_posts(array(
          'post_type' => 'wp_block',
          'post_status' => 'publish',
          'posts_per_page' => -1,
          'orderby' => 'ID',
          'order' => 'ASC'
        ));

        if (!empty($available_patterns)) {
          // Try to find the best match by checking recently created patterns first
          $best_match = null;
          $recent_patterns = array_filter($available_patterns, function($pattern) {
            // Patterns created in the last hour are likely from recent import
            return strtotime($pattern->post_date) > (time() - 3600);
          });

          if (!empty($recent_patterns)) {
            // Use the most recently created pattern
            $best_match = end($recent_patterns);
            $this->log('INFO', sprintf('Smart mapping (recent pattern): %d -> %d ("%s")', $old_ref_id, $best_match->ID, $best_match->post_title));
          } else {
            // Fallback to first available pattern
            $best_match = $available_patterns[0];
            $this->log('WARNING', sprintf('Fallback mapping: %d -> %d ("%s") - This may need manual correction', $old_ref_id, $best_match->ID, $best_match->post_title));
          }

          if ($best_match) {
            $mapping[$old_ref_id] = $best_match->ID;
          }
        }
      }
    }


    return $mapping;
  }

  /**
   * Find pattern by exact title match
   *
   * @param string $title Pattern title
   * @return WP_Post|null Found pattern or null
   */
  private function find_pattern_by_title($title)
  {
    $patterns = get_posts(array(
      'post_type' => 'wp_block',
      'post_status' => 'publish',
      'title' => $title,
      'posts_per_page' => 1
    ));

    return !empty($patterns) ? $patterns[0] : null;
  }

  /**
   * Find pattern by slug match
   *
   * @param string $slug Pattern slug
   * @return WP_Post|null Found pattern or null
   */
  private function find_pattern_by_slug($slug)
  {
    $patterns = get_posts(array(
      'post_type' => 'wp_block',
      'post_status' => 'publish',
      'name' => $slug,
      'posts_per_page' => 1
    ));

    return !empty($patterns) ? $patterns[0] : null;
  }

  /**
   * Find pattern by partial title match
   *
   * @param string $title Pattern title
   * @return WP_Post|null Found pattern or null
   */
  private function find_pattern_by_partial_title($title)
  {
    // Try to find patterns with similar titles
    $patterns = get_posts(array(
      'post_type' => 'wp_block',
      'post_status' => 'publish',
      'posts_per_page' => 20
    ));

    foreach ($patterns as $pattern) {
      // Simple similarity check
      similar_text(strtolower($title), strtolower($pattern->post_title), $percent);
      if ($percent > 70) { // 70% similarity threshold
        return $pattern;
      }
    }

    return null;
  }

  /**
   * Create fallback mapping for a single pattern ID
   *
   * @param int $old_ref_id Original pattern ID
   * @return int|null New pattern ID or null
   */
  private function create_fallback_mapping_for_id($old_ref_id)
  {
    // Check if pattern exists with same ID
    $existing_pattern = get_post($old_ref_id);
    if ($existing_pattern && $existing_pattern->post_type === 'wp_block' && $existing_pattern->post_status === 'publish') {
      $this->log('INFO', sprintf('Direct ID match: %d -> %d ("%s")', $old_ref_id, $old_ref_id, $existing_pattern->post_title));
      return $old_ref_id;
    }

    // Get recent patterns as fallback
    $available_patterns = get_posts(array(
      'post_type' => 'wp_block',
      'post_status' => 'publish',
      'posts_per_page' => 5,
      'orderby' => 'date',
      'order' => 'DESC'
    ));

    if (!empty($available_patterns)) {
      $fallback_pattern = $available_patterns[0];
      $this->log('WARNING', sprintf('Fallback mapping: %d -> %d ("%s")', $old_ref_id, $fallback_pattern->ID, $fallback_pattern->post_title));
      return $fallback_pattern->ID;
    }

    return null;
  }

  /**
   * Log import activity
   *
   * @param string $level Log level
   * @param string $message Log message
   */
  public function log($level, $message)
  {
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
      error_log(sprintf('[%s] Import Media from ZIP - %s: %s', current_time('Y-m-d H:i:s'), $level, $message));
    }
  }


  /**
   * Minimal content sanitization that preserves LazyBlocks newlines
   * This replaces wp_kses_post() which corrupts newlines in LazyBlocks
   *
   * @param string $content Raw content
   * @return string Minimally sanitized content
   */
  private function minimal_content_sanitization($content)
  {
    // Only apply basic XSS protection without affecting block structure
    // Remove potentially dangerous scripts but preserve block HTML
    $content = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $content);
    $content = preg_replace('/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/mi', '', $content);

    // Remove dangerous attributes but preserve block attributes
    $content = preg_replace('/\son\w+\s*=\s*["\'][^"\']*["\']/i', '', $content);

    // Basic HTML entity handling for safety
    $content = str_replace(array('<script', '</script'), array('&lt;script', '&lt;/script'), $content);

    return $content;
  }

  /**
   * Direct database post update to preserve LazyBlocks newlines and update all sanitized fields
   *
   * Updates all sanitized post fields including post_content, post_title, post_excerpt,
   * post_status, post_type, post_name, post_date, comment_status, ping_status,
   * menu_order, and post_password when present in the data.
   *
   * @param int $post_id Post ID
   * @param array $post_data Sanitized post data from sanitize_post_data()
   * @return int|WP_Error Post ID on success, WP_Error on failure
   */
  private function direct_post_update($post_id, $post_data)
  {
    global $wpdb;



    $update_data = array();
    $update_format = array();

    // Map all sanitized post data to database columns
    if (isset($post_data['post_content'])) {
      $update_data['post_content'] = $post_data['post_content'];
      $update_format[] = '%s';
    }
    if (isset($post_data['post_title'])) {
      $update_data['post_title'] = $post_data['post_title'];
      $update_format[] = '%s';
    }
    if (isset($post_data['post_excerpt'])) {
      $update_data['post_excerpt'] = $post_data['post_excerpt'];
      $update_format[] = '%s';
    }
    if (isset($post_data['post_status'])) {
      $update_data['post_status'] = $post_data['post_status'];
      $update_format[] = '%s';
    }
    if (isset($post_data['post_type'])) {
      $update_data['post_type'] = $post_data['post_type'];
      $update_format[] = '%s';
    }
    if (isset($post_data['post_name'])) {
      $update_data['post_name'] = $post_data['post_name'];
      $update_format[] = '%s';
    }
    if (isset($post_data['post_date'])) {
      $update_data['post_date'] = $post_data['post_date'];
      $update_format[] = '%s';

      // Also update post_date_gmt if post_date is provided
      if ($post_data['post_date'] !== '0000-00-00 00:00:00') {
        $gmt_date = get_gmt_from_date($post_data['post_date']);
        $update_data['post_date_gmt'] = $gmt_date;
        $update_format[] = '%s';
      }
    }
    if (isset($post_data['comment_status'])) {
      $update_data['comment_status'] = $post_data['comment_status'];
      $update_format[] = '%s';
    }
    if (isset($post_data['ping_status'])) {
      $update_data['ping_status'] = $post_data['ping_status'];
      $update_format[] = '%s';
    }
    if (isset($post_data['menu_order'])) {
      $update_data['menu_order'] = $post_data['menu_order'];
      $update_format[] = '%d';
    }
    if (isset($post_data['post_password'])) {
      $update_data['post_password'] = $post_data['post_password'];
      $update_format[] = '%s';
    }

    $update_data['post_modified'] = current_time('mysql');
    $update_data['post_modified_gmt'] = current_time('mysql', 1);
    $update_format[] = '%s';
    $update_format[] = '%s';

    $result = $wpdb->update(
      $wpdb->posts,
      $update_data,
      array('ID' => $post_id),
      $update_format,
      array('%d')
    );

    if ($result === false) {
      return new WP_Error('post_update_failed', __('記事の更新に失敗しました。', 'wp-single-post-migrator'));
    }

    $this->log('INFO', sprintf('Direct database post update successful for post %d', $post_id));
    return $post_id;
  }

  /**
   * Direct database post insert to preserve LazyBlocks newlines
   *
   * @param array $post_data Post data
   * @return int|WP_Error Post ID on success, WP_Error on failure
   */
  private function direct_post_insert($post_data)
  {
    global $wpdb;


    $insert_data = array(
      'post_author' => get_current_user_id(),
      'post_date' => current_time('mysql'),
      'post_date_gmt' => current_time('mysql', 1),
      'post_modified' => current_time('mysql'),
      'post_modified_gmt' => current_time('mysql', 1),
      'post_status' => 'publish',
      'post_type' => 'post',
      'comment_status' => 'closed',
      'ping_status' => 'closed',
      'post_name' => '',
      'guid' => ''
    );

    // Override with provided data
    if (isset($post_data['post_content'])) {
      $insert_data['post_content'] = $post_data['post_content'];
    }
    if (isset($post_data['post_title'])) {
      $insert_data['post_title'] = $post_data['post_title'];
    }
    if (isset($post_data['post_excerpt'])) {
      $insert_data['post_excerpt'] = $post_data['post_excerpt'];
    }
    if (isset($post_data['post_status'])) {
      $insert_data['post_status'] = $post_data['post_status'];
    }

    $result = $wpdb->insert(
      $wpdb->posts,
      $insert_data,
      array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
    );

    if ($result === false) {
      return new WP_Error('post_insert_failed', __('記事の作成に失敗しました。', 'wp-single-post-migrator'));
    }

    $post_id = $wpdb->insert_id;
    $this->log('INFO', sprintf('Direct database post insert successful, new post ID: %d', $post_id));
    return $post_id;
  }

  /**
   * Direct database content update to preserve LazyBlocks newlines
   *
   * @param int $post_id Post ID
   * @param string $content New content
   * @return int|WP_Error Post ID on success, WP_Error on failure
   */
  private function direct_content_update($post_id, $content)
  {
    global $wpdb;


    $result = $wpdb->update(
      $wpdb->posts,
      array(
        'post_content' => $content,
        'post_modified' => current_time('mysql'),
        'post_modified_gmt' => current_time('mysql', 1)
      ),
      array('ID' => $post_id),
      array('%s', '%s', '%s'),
      array('%d')
    );

    if ($result === false) {
      return new WP_Error('content_update_failed', __('コンテンツの更新に失敗しました。', 'wp-single-post-migrator'));
    }

    $this->log('INFO', sprintf('Direct database content update successful for post %d', $post_id));
    return $post_id;
  }
}
