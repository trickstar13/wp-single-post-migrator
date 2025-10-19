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
   * Constructor
   */
  public function __construct()
  {
    $this->zip_handler = new IPBMFZ_ZIP_Handler();
    $this->media_importer = new IPBMFZ_Media_Importer();
    $this->block_updater = new IPBMFZ_Block_Updater();
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
      'import_meta' => true
    );
    $options = array_merge($default_options, $options);

    try {
      $this->log('INFO', 'Starting post import from ZIP');

      // Step 1: Extract ZIP file
      $extraction_result = $this->zip_handler->upload_and_extract($file_data, 0);
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
      if ($options['import_images']) {
        $image_results = $this->import_images_and_update_content($extracted_files, $imported_post_id, $extract_path);
        if (is_wp_error($image_results)) {
          $this->log('WARNING', 'Image import failed but post was created: ' . $image_results->get_error_message());
          $image_results = array('imported_count' => 0, 'updated_blocks' => 0);
        }
      }

      // Step 5: Cleanup
      $this->zip_handler->cleanup($extract_path);

      $this->log('INFO', sprintf(
        'Post import completed. Post ID: %d, New post: %s, Images: %d, Updated blocks: %d',
        $imported_post_id,
        $is_new_post ? 'yes' : 'no',
        isset($image_results['imported_count']) ? $image_results['imported_count'] : 0,
        isset($image_results['updated_blocks']) ? $image_results['updated_blocks'] : 0
      ));

      return array(
        'post_id' => $imported_post_id,
        'post_title' => get_the_title($imported_post_id),
        'post_url' => get_edit_post_link($imported_post_id),
        'is_new_post' => $is_new_post,
        'imported_images' => isset($image_results['imported_count']) ? $image_results['imported_count'] : 0,
        'updated_blocks' => isset($image_results['updated_blocks']) ? $image_results['updated_blocks'] : 0,
        'failed_matches' => isset($image_results['failed_matches']) ? $image_results['failed_matches'] : array(),
        'message' => $this->generate_success_message($is_new_post, $imported_post_id, $image_results)
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
      // Update existing post
      $sanitized_data['ID'] = $options['target_post_id'];
      $post_id = wp_update_post($sanitized_data);
      $is_new = false;
    } elseif ($options['import_mode'] === 'replace_current' && !empty($options['target_post_id'])) {
      // Replace current post content
      $sanitized_data['ID'] = $options['target_post_id'];
      $post_id = wp_update_post($sanitized_data);
      $is_new = false;
    } else {
      // Create new post
      $post_id = wp_insert_post($sanitized_data);
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
    $sanitized['post_content'] = wp_kses_post($post_data['post_content'] ?? '');

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

    // Update post content with new image URLs
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

      // Step 3: Update blocks
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
  private function generate_success_message($is_new_post, $post_id, $image_results)
  {
    $post_action = $is_new_post ? __('作成', 'wp-single-post-migrator') : __('更新', 'wp-single-post-migrator');
    $image_count = isset($image_results['imported_count']) ? $image_results['imported_count'] : 0;
    $block_count = isset($image_results['updated_blocks']) ? $image_results['updated_blocks'] : 0;

    if ($image_count > 0) {
      return sprintf(
        __('記事を%sし、%d件の画像をインポートして%d個のブロックを更新しました。', 'wp-single-post-migrator'),
        $post_action,
        $image_count,
        $block_count
      );
    } else {
      return sprintf(
        __('記事を%sしました。画像のインポートはありませんでした。', 'wp-single-post-migrator'),
        $post_action
      );
    }
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
}
