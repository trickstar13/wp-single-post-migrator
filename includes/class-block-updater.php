<?php

/**
 * Block Updater Class
 *
 * Handles parsing and updating WordPress block content with new media
 */

if (!defined('ABSPATH')) {
  exit;
}

class IPBMFZ_Block_Updater
{

  /**
   * Update blocks with new media
   *
   * @param int $post_id Post ID
   * @param array $image_map Array mapping filename to attachment_id
   * @return array Results array
   */
  public function update_blocks($post_id, $image_map)
  {
    $post = get_post($post_id);
    if (!$post) {
      return new WP_Error(
        'post_not_found',
        __('記事が見つかりません。', 'wp-single-post-migrator')
      );
    }

    // Parse blocks
    $blocks = parse_blocks($post->post_content);
    $results = array(
      'updated_blocks' => 0,
      'failed_matches' => array(),
      'processed_images' => array()
    );

    // Process blocks recursively
    $this->process_blocks($blocks, $image_map, $results);

    // Process meta fields
    $meta_updates = $this->process_meta_fields($post_id, $image_map, $results);
    $results['updated_blocks'] += $meta_updates;

    // Validate blocks before saving
    $validation = $this->validate_blocks($blocks);
    if (!$validation['valid']) {
      return new WP_Error(
        'block_validation_failed',
        __('ブロックの検証に失敗しました: ', 'wp-single-post-migrator') . implode(', ', $validation['errors'])
      );
    }

    // Rebuild content using WordPress standard method
    $new_content = serialize_blocks($blocks);

    // Simple newline protection: just fix the common \n -> n corruption in the final serialized content
    $new_content = $this->fix_newline_corruption($new_content);

    // Validate that the content can be parsed again
    $test_blocks = parse_blocks($new_content);
    if (empty($test_blocks) && !empty($blocks)) {
      return new WP_Error(
        'content_serialization_failed',
        __('コンテンツの再構築に失敗しました。', 'wp-single-post-migrator')
      );
    }

    // Log the final content before saving
    $this->log('INFO', sprintf(
      'Saving post content for post %d. Original length: %d, New length: %d',
      $post_id,
      strlen($post->post_content),
      strlen($new_content)
    ));

    // Additional debug: log first 500 chars of new content
    $this->log('INFO', sprintf(
      'New content preview: %s',
      substr($new_content, 0, 500) . '...'
    ));

    // Disable WordPress autosave and revisions temporarily
    $this->disable_wp_interference();

    // Method 1: Use wp_update_post with specific parameters
    $update_result = wp_update_post(array(
      'ID' => $post_id,
      'post_content' => $new_content,
      'post_modified' => current_time('mysql'),
      'post_modified_gmt' => current_time('mysql', 1),
    ));

    if (is_wp_error($update_result) || !$update_result) {
      $this->log('ERROR', 'wp_update_post failed, trying direct database update');

      // Fallback: Direct database update
      global $wpdb;
      $db_result = $wpdb->update(
        $wpdb->posts,
        array(
          'post_content' => $new_content,
          'post_modified' => current_time('mysql'),
          'post_modified_gmt' => current_time('mysql', 1),
        ),
        array('ID' => $post_id),
        array('%s', '%s', '%s'),
        array('%d')
      );

      if ($db_result === false) {
        $this->log('ERROR', 'Both update methods failed');
        return new WP_Error('save_failed', __('記事の保存に失敗しました。', 'wp-single-post-migrator'));
      } else {
        $this->log('INFO', 'Direct database update successful');
        $update_result = $post_id;
      }
    } else {
      $this->log('INFO', 'wp_update_post successful');
    }

    // Clear all caches aggressively
    $this->clear_wp_caches($post_id);

    // Re-enable WordPress features
    $this->restore_wp_interference();

    if (is_wp_error($update_result)) {
      return $update_result;
    }

    // Wait a moment for database to settle
    usleep(100000); // 0.1 second

    // Force reload from database (bypass all caches)
    global $wpdb;
    $fresh_content = $wpdb->get_var($wpdb->prepare(
      "SELECT post_content FROM $wpdb->posts WHERE ID = %d",
      $post_id
    ));

    $this->log('INFO', sprintf(
      'Fresh content from database (length: %d): %s',
      strlen($fresh_content),
      substr($fresh_content, 0, 200) . '...'
    ));

    // Parse fresh content
    $saved_blocks = parse_blocks($fresh_content);

    // Detailed verification
    $verification_failed = false;
    $updated_ids = array_values($image_map);

    foreach ($saved_blocks as $i => $saved_block) {
      if ($saved_block['blockName'] === 'core/image' && isset($saved_block['attrs']['id'])) {
        $saved_id = $saved_block['attrs']['id'];
        $saved_url = isset($saved_block['attrs']['url']) ? $saved_block['attrs']['url'] : '';

        $this->log('INFO', sprintf(
          'Block %d verification: ID=%d, URL=%s, innerHTML_contains_correct_id=%s, innerHTML_contains_correct_url=%s',
          $i,
          $saved_id,
          $saved_url,
          strpos($saved_block['innerHTML'], 'wp-image-' . $saved_id) !== false ? 'YES' : 'NO',
          strpos($saved_block['innerHTML'], $saved_url) !== false ? 'YES' : 'NO'
        ));

        // Check if this block should have been updated
        if (in_array($saved_id, $updated_ids)) {
          if (
            strpos($saved_block['innerHTML'], 'wp-image-' . $saved_id) === false ||
            strpos($saved_block['innerHTML'], $saved_url) === false
          ) {
            $verification_failed = true;
            $this->log('ERROR', sprintf(
              'Verification failed for saved block ID %d. Expected URL: %s, Expected class: wp-image-%d, innerHTML: %s',
              $saved_id,
              $saved_url,
              $saved_id,
              $saved_block['innerHTML']
            ));
          } else {
            $this->log('INFO', sprintf(
              'Block ID %d verified successfully',
              $saved_id
            ));
          }
        }
      }
    }

    if ($verification_failed) {
      $this->log('WARNING', 'Some blocks were not saved correctly. This may be due to WordPress autosave or theme interference.');

      // Try one more time with even more aggressive approach
      $this->log('INFO', 'Attempting final database update...');
      $final_result = $wpdb->update(
        $wpdb->posts,
        array('post_content' => $new_content),
        array('ID' => $post_id),
        array('%s'),
        array('%d')
      );

      if ($final_result !== false) {
        $this->log('INFO', 'Final database update completed');
      }
    } else {
      $this->log('INFO', 'All blocks verified successfully after save.');
    }

    // Add validation warnings to results
    if (!empty($validation['warnings'])) {
      $results['warnings'] = $validation['warnings'];
    }

    return $results;
  }

  /**
   * Process blocks recursively
   *
   * @param array $blocks Array of blocks
   * @param array $image_map Filename to attachment_id mapping
   * @param array $results Results array (passed by reference)
   */
  private function process_blocks(&$blocks, $image_map, &$results)
  {
    foreach ($blocks as &$block) {
      if (empty($block['blockName'])) {
        continue;
      }

      switch ($block['blockName']) {
        case 'core/image':
          if ($this->update_image_block($block, $image_map, $results)) {
            $results['updated_blocks']++;
          }
          break;

        case 'core/gallery':
          $gallery_updates = $this->update_gallery_block($block, $image_map, $results);
          $results['updated_blocks'] += $gallery_updates;
          break;

        default:
          // Check for LazyBlocks custom blocks
          if (strpos($block['blockName'], 'lazyblock/') === 0) {
            $lazyblock_updates = $this->update_lazyblock_images($block, $image_map, $results);
            $results['updated_blocks'] += $lazyblock_updates;
          }
          // Process inner blocks for other block types
          if (!empty($block['innerBlocks'])) {
            $this->process_blocks($block['innerBlocks'], $image_map, $results);
          }
          break;
      }
    }
  }

  /**
   * Update image block
   *
   * @param array $block Block data (passed by reference)
   * @param array $image_map Filename to attachment_id mapping
   * @param array $results Results array (passed by reference)
   * @return bool True if updated, false otherwise
   */
  private function update_image_block(&$block, $image_map, &$results)
  {
    $matched_data = $this->match_image_in_block($block, $image_map);

    if (!$matched_data) {
      return false;
    }

    $filename = $matched_data['filename'];
    $attachment_id = $matched_data['attachment_id'];
    $attachment_url = wp_get_attachment_url($attachment_id);

    if (!$attachment_url) {
      $results['failed_matches'][] = sprintf(
        __('添付ファイルのURLを取得できませんでした: %s', 'wp-single-post-migrator'),
        $filename
      );
      return false;
    }

    // Preserve original block structure and only update necessary attributes
    $original_attrs = isset($block['attrs']) ? $block['attrs'] : array();

    // Keep original attributes that don't conflict
    $preserved_attrs = array();
    foreach ($original_attrs as $key => $value) {
      if (!in_array($key, array('id', 'url', 'width', 'height'))) {
        $preserved_attrs[$key] = $value;
      }
    }

    // Set default values if not present
    if (!isset($preserved_attrs['sizeSlug'])) {
      $preserved_attrs['sizeSlug'] = 'full';
    }
    if (!isset($preserved_attrs['linkDestination'])) {
      $preserved_attrs['linkDestination'] = 'none';
    }

    // Rebuild attrs with minimal necessary changes
    $block['attrs'] = array_merge($preserved_attrs, array(
      'id' => $attachment_id,
      'url' => $attachment_url
    ));

    // Don't add width/height to avoid unwanted style attributes

    // Only rebuild innerHTML for core image blocks, not custom blocks
    $this->rebuild_gutenberg_compatible_block($block, $attachment_id, $attachment_url);

    // Verify the reconstruction was successful (only for core blocks)
    if (
      strpos($block['innerHTML'], $attachment_url) === false ||
      strpos($block['innerHTML'], 'wp-image-' . $attachment_id) === false
    ) {
      $this->log('ERROR', sprintf(
        'Block reconstruction failed for attachment %d. URL: %s, innerHTML: %s',
        $attachment_id,
        $attachment_url,
        $block['innerHTML']
      ));
      return false;
    }

    // Debug: Log the updated block structure
    $this->log('INFO', sprintf(
      'Final block for %s: ID=%d, URL=%s, attrs=%s, innerHTML=%s',
      $filename,
      $attachment_id,
      $attachment_url,
      json_encode($block['attrs']),
      $block['innerHTML']
    ));

    $results['processed_images'][] = $filename;
    return true;
  }

  /**
   * Update gallery block
   *
   * @param array $block Block data (passed by reference)
   * @param array $image_map Filename to attachment_id mapping
   * @param array $results Results array (passed by reference)
   * @return int Number of updated images
   */
  private function update_gallery_block(&$block, $image_map, &$results)
  {
    $updated_count = 0;

    if (!empty($block['innerBlocks'])) {
      foreach ($block['innerBlocks'] as &$inner_block) {
        if ($inner_block['blockName'] === 'core/image') {
          if ($this->update_image_block($inner_block, $image_map, $results)) {
            $updated_count++;
          }
        }
      }
    }

    // Update gallery block attributes if needed
    if ($updated_count > 0 && !empty($block['attrs']['ids'])) {
      $this->update_gallery_ids($block, $image_map);
    }

    return $updated_count;
  }

  /**
   * Match image in block with available images
   *
   * @param array $block Block data
   * @param array $image_map Filename to attachment_id mapping
   * @return array|false Matched data or false
   */
  private function match_image_in_block($block, $image_map)
  {
    // Try to get URL from block attributes
    $image_url = '';

    if (!empty($block['attrs']['url'])) {
      $image_url = $block['attrs']['url'];
    } elseif (!empty($block['innerHTML'])) {
      // Extract URL from innerHTML
      if (preg_match('/src="([^"]*)"/', $block['innerHTML'], $matches)) {
        $image_url = $matches[1];
      }
    }

    if (empty($image_url)) {
      return false;
    }

    // Extract filename from URL
    $url_filename = basename(parse_url($image_url, PHP_URL_PATH));

    // Remove size suffixes (e.g., -150x150, -300x200)
    $clean_filename = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $url_filename);

    // Try exact match first
    if (isset($image_map[$clean_filename])) {
      return array(
        'filename' => $clean_filename,
        'attachment_id' => $image_map[$clean_filename]
      );
    }

    // Try partial matches
    foreach ($image_map as $filename => $attachment_id) {
      $clean_map_filename = pathinfo($filename, PATHINFO_FILENAME);
      $clean_url_filename = pathinfo($clean_filename, PATHINFO_FILENAME);

      if ($clean_map_filename === $clean_url_filename) {
        return array(
          'filename' => $filename,
          'attachment_id' => $attachment_id
        );
      }
    }

    return false;
  }

  /**
   * Update image block innerHTML
   *
   * @param array $block Block data (passed by reference)
   * @param int $attachment_id Attachment ID
   * @param string $attachment_url Attachment URL
   */
  private function update_image_inner_html(&$block, $attachment_id, $attachment_url)
  {
    // Get the size slug for CSS class
    $size_slug = isset($block['attrs']['sizeSlug']) ? $block['attrs']['sizeSlug'] : 'full';

    // Debug: Log original innerHTML
    $this->log('INFO', sprintf(
      'Original innerHTML for attachment %d: %s',
      $attachment_id,
      $block['innerHTML']
    ));

    if (empty($block['innerHTML'])) {
      // If innerHTML is empty, reconstruct it completely
      $block['innerHTML'] = sprintf(
        '<figure class="wp-block-image size-%s"><img src="%s" alt="" class="wp-image-%d"/></figure>',
        esc_attr($size_slug),
        esc_url($attachment_url),
        $attachment_id
      );
      return;
    }

    $html = $block['innerHTML'];

    // Debug: Log the URL we're trying to update to
    $this->log('INFO', sprintf(
      'Updating to URL: %s for attachment %d',
      $attachment_url,
      $attachment_id
    ));

    // Step 1: Update src attribute - be more specific to target only img src
    $original_html = $html;
    $html = preg_replace('/(<img[^>]*?)src="[^"]*"/', '$1src="' . esc_url($attachment_url) . '"', $html);

    // Debug: Check if src was actually updated
    if ($html === $original_html) {
      $this->log('WARNING', sprintf(
        'Failed to update src attribute for attachment %d. Original HTML: %s',
        $attachment_id,
        $original_html
      ));
    } else {
      $this->log('INFO', sprintf(
        'Successfully updated src attribute for attachment %d',
        $attachment_id
      ));
    }

    // Step 2: Update wp-image class more carefully
    if (preg_match('/(<img[^>]*?)class="([^"]*)"/', $html, $matches)) {
      $img_tag_start = $matches[1];
      $current_classes = $matches[2];

      // Remove ALL existing wp-image classes
      $classes = preg_replace('/\bwp-image-\d+\b/', '', $current_classes);

      // Clean up multiple spaces
      $classes = preg_replace('/\s+/', ' ', trim($classes));

      // Add the new wp-image class
      $new_classes = trim($classes . ' wp-image-' . $attachment_id);

      // Replace the class attribute
      $html = preg_replace(
        '/(<img[^>]*?)class="[^"]*"/',
        '$1class="' . esc_attr($new_classes) . '"',
        $html
      );
    } else {
      // Add class attribute if it doesn't exist on img tag
      $html = preg_replace(
        '/(<img[^>]*?)(\s*\/?>)/',
        '$1 class="wp-image-' . $attachment_id . '"$2',
        $html
      );
    }

    // Step 3: Update figure class if needed
    if (preg_match('/<figure[^>]*class="([^"]*)"/', $html, $matches)) {
      $figure_classes = $matches[1];

      // Update size class in figure
      $figure_classes = preg_replace('/\bsize-\w+\b/', 'size-' . $size_slug, $figure_classes);

      $html = preg_replace(
        '/<figure([^>]*\s)class="[^"]*"/',
        '<figure$1class="' . esc_attr($figure_classes) . '"',
        $html
      );
    }

    $block['innerHTML'] = $html;
  }

  /**
   * Rebuild image block content completely for consistency
   *
   * @param array $block Block data (passed by reference)
   * @param int $attachment_id Attachment ID
   * @param string $attachment_url Attachment URL
   */
  private function rebuild_image_block_content(&$block, $attachment_id, $attachment_url)
  {
    // Get block attributes
    $size_slug = isset($block['attrs']['sizeSlug']) ? $block['attrs']['sizeSlug'] : 'full';
    $link_destination = isset($block['attrs']['linkDestination']) ? $block['attrs']['linkDestination'] : 'none';

    // Get attachment data
    $attachment = get_post($attachment_id);
    $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

    // Build img tag
    $img_tag = sprintf(
      '<img src="%s" alt="%s" class="wp-image-%d"/>',
      esc_url($attachment_url),
      esc_attr($alt_text),
      $attachment_id
    );

    // Wrap in link if needed
    if ($link_destination === 'media') {
      $img_tag = sprintf(
        '<a href="%s">%s</a>',
        esc_url($attachment_url),
        $img_tag
      );
    } elseif ($link_destination === 'attachment') {
      $attachment_page_url = get_attachment_link($attachment_id);
      $img_tag = sprintf(
        '<a href="%s">%s</a>',
        esc_url($attachment_page_url),
        $img_tag
      );
    }

    // Build complete figure
    $block['innerHTML'] = sprintf(
      '<figure class="wp-block-image size-%s">%s</figure>',
      esc_attr($size_slug),
      $img_tag
    );

    // Log the rebuilt content
    $this->log('INFO', sprintf(
      'Rebuilt innerHTML for attachment %d: %s',
      $attachment_id,
      $block['innerHTML']
    ));
  }

  /**
   * Rebuild block in a Gutenberg-compatible way
   *
   * @param array $block Block data (passed by reference)
   * @param int $attachment_id Attachment ID
   * @param string $attachment_url Attachment URL
   */
  private function rebuild_gutenberg_compatible_block(&$block, $attachment_id, $attachment_url)
  {
    // Get block attributes
    $size_slug = isset($block['attrs']['sizeSlug']) ? $block['attrs']['sizeSlug'] : 'full';
    $link_destination = isset($block['attrs']['linkDestination']) ? $block['attrs']['linkDestination'] : 'none';

    // Get attachment data
    $alt_text = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

    // Build exactly what Gutenberg expects (minimal attributes)
    $img_attributes = array(
      'src' => esc_url($attachment_url),
      'alt' => esc_attr($alt_text),
      'class' => 'wp-image-' . $attachment_id
    );

    // Don't add width/height attributes to img tag
    // This prevents unwanted inline styles

    // Build img tag
    $img_attrs_string = '';
    foreach ($img_attributes as $attr => $value) {
      $img_attrs_string .= sprintf(' %s="%s"', $attr, $value);
    }

    $img_tag = '<img' . $img_attrs_string . '/>';

    // Handle link destination
    if ($link_destination === 'media') {
      $img_tag = sprintf('<a href="%s">%s</a>', esc_url($attachment_url), $img_tag);
    } elseif ($link_destination === 'attachment') {
      $attachment_page_url = get_attachment_link($attachment_id);
      $img_tag = sprintf('<a href="%s">%s</a>', esc_url($attachment_page_url), $img_tag);
    }

    // Build the complete figure with Gutenberg-style structure
    $block['innerHTML'] = sprintf(
      '<figure class="wp-block-image size-%s">%s</figure>',
      esc_attr($size_slug),
      $img_tag
    );

    // Update innerContent to match innerHTML (Gutenberg requirement)
    $block['innerContent'] = array($block['innerHTML']);

    // Log the Gutenberg-compatible rebuild
    $this->log('INFO', sprintf(
      'Gutenberg-compatible rebuild for attachment %d: %s',
      $attachment_id,
      $block['innerHTML']
    ));
  }

  /**
   * Update LazyBlocks images
   *
   * @param array $block LazyBlock (passed by reference)
   * @param array $image_map Filename to attachment_id mapping
   * @param array $results Results array (passed by reference)
   * @return int Number of updated images
   */
  private function update_lazyblock_images(&$block, $image_map, &$results)
  {
    $updated_count = 0;

    if (!isset($block['attrs']) || empty($block['attrs'])) {
      return $updated_count;
    }

    $this->log('INFO', sprintf(
      'Processing LazyBlock: %s with %d attributes',
      $block['blockName'],
      count($block['attrs'])
    ));

    // First, check if this LazyBlock has any images that need updating
    $has_updatable_images = false;
    foreach ($block['attrs'] as $attr_name => $attr_value) {
      if (is_string($attr_value) && $this->looks_like_json_image_data($attr_value)) {
        $this->log('INFO', sprintf('Checking LazyBlock attribute %s for updatable images', $attr_name));

        $decoded = urldecode($attr_value);
        $data = json_decode($decoded, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          $found_images = $this->find_images_in_data($data, $image_map);
          if (!empty($found_images)) {
            $has_updatable_images = true;
            $this->log('INFO', sprintf('Found %d updatable images in %s', count($found_images), $attr_name));
            break;
          } else {
            $this->log('INFO', sprintf('No updatable images found in %s', $attr_name));
          }
        } else {
          $this->log('WARNING', sprintf('Failed to parse JSON in %s: %s', $attr_name, json_last_error_msg()));
        }
      }
    }

    // If no images need updating, leave the block completely untouched
    if (!$has_updatable_images) {
      $this->log('INFO', sprintf(
        'LazyBlock %s has no images to update, leaving untouched',
        $block['blockName']
      ));
      return $updated_count;
    }

    // Process each attribute that might contain image data
    foreach ($block['attrs'] as $attr_name => &$attr_value) {
      // Debug: log the raw attribute value for problematic blocks
      if ($attr_name === 'title' || $attr_name === 'image') {
        $this->log('INFO', sprintf(
          'Processing attribute %s (type: %s): %s',
          $attr_name,
          gettype($attr_value),
          substr(is_string($attr_value) ? $attr_value : json_encode($attr_value), 0, 200) . '...'
        ));
      }

      // Handle simple string attributes like 'title' that might contain newlines
      if ($attr_name === 'title' && is_string($attr_value)) {
        // Just preserve the title as-is (no changes needed for simple string)
        $this->log('INFO', sprintf(
          'Preserving LazyBlock title: "%s"',
          $attr_value
        ));
        continue;
      }

      if (is_string($attr_value) && $this->looks_like_json_image_data($attr_value)) {
        $this->log('INFO', sprintf(
          'Found potential image attribute: %s',
          $attr_name
        ));

        // Store original value in case update fails
        $original_value = $attr_value;

        if ($this->update_lazyblock_image_attribute($attr_value, $image_map, $results)) {
          $updated_count++;
          $this->log('INFO', sprintf(
            'Updated LazyBlock image attribute: %s',
            $attr_name
          ));
        } else {
          // If update failed, restore original value to prevent data loss
          $attr_value = $original_value;
          $this->log('WARNING', sprintf(
            'LazyBlock attribute update failed, restored original value for: %s',
            $attr_name
          ));
        }
      }
    }

    return $updated_count;
  }

  /**
   * Check if a string looks like JSON image data
   *
   * @param string $value String to check
   * @return bool
   */
  private function looks_like_json_image_data($value)
  {
    // URL decode first
    $decoded = urldecode($value);

    // Check if it's JSON and contains image-related keys
    $data = json_decode($decoded, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return false;
    }

    return $this->contains_image_data($data);
  }

  /**
   * Recursively check if data contains image information
   *
   * @param mixed $data Data to check
   * @return bool
   */
  private function contains_image_data($data)
  {
    if (!is_array($data)) {
      return false;
    }

    // Check for direct image data keys
    $image_keys = array('id', 'url', 'alt', 'title', 'sizes');
    foreach ($image_keys as $key) {
      if (isset($data[$key])) {
        return true;
      }
    }

    // Check for nested image data
    foreach ($data as $key => $value) {
      // Common nested image field names
      if (in_array($key, array('itemImage', 'image', 'imageData'))) {
        if (is_array($value) && $this->contains_image_data($value)) {
          return true;
        }
      }

      // Recursively check arrays
      if (is_array($value) && $this->contains_image_data($value)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Update LazyBlock image attribute
   *
   * @param string $attr_value Attribute value (passed by reference)
   * @param array $image_map Filename to attachment_id mapping
   * @param array $results Results array (passed by reference)
   * @return bool True if updated
   */
  private function update_lazyblock_image_attribute(&$attr_value, $image_map, &$results)
  {
    // Store original value to preserve formatting
    $original_decoded = urldecode($attr_value);

    // Parse JSON with preservation of special characters
    $image_data = json_decode($original_decoded, true, 512, JSON_BIGINT_AS_STRING);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($image_data)) {
      $this->log('WARNING', sprintf(
        'Failed to parse LazyBlock JSON: %s. Error: %s',
        substr($original_decoded, 0, 100),
        json_last_error_msg()
      ));
      return false;
    }

    // Debug: log the parsed JSON data to see the original title
    $this->log('INFO', sprintf(
      'Parsed JSON data - Title: "%s", URL: "%s"',
      isset($image_data['title']) ? $image_data['title'] : 'N/A',
      isset($image_data['url']) ? $image_data['url'] : 'N/A'
    ));

    $updated = false;

    // Use the same logic as meta field processing for consistency
    $updated = $this->update_image_data_recursively($image_data, $image_map, $results);

    if ($updated) {
      // Clean up any problematic HTML content in description fields
      $this->clean_html_in_json_data($image_data);

      // Use conservative JSON encoding flags
      $new_json = json_encode($image_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

      if ($new_json === false) {
        $this->log('ERROR', 'JSON encoding failed for LazyBlock data: ' . json_last_error_msg());
        return false;
      }

      // Validate that we can parse it back correctly
      $validation_data = json_decode($new_json, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->log('ERROR', 'JSON validation failed after encoding: ' . json_last_error_msg());
        $this->log('ERROR', 'Problematic JSON snippet: ' . substr($new_json, 0, 500) . '...');
        return false;
      }

      // Double-check that critical data is preserved
      if (isset($image_data['title']) && (!isset($validation_data['title']) || $validation_data['title'] !== $image_data['title'])) {
        $this->log('ERROR', sprintf(
          'Title corruption detected during JSON processing. Original: "%s", After: "%s"',
          $image_data['title'],
          isset($validation_data['title']) ? $validation_data['title'] : 'MISSING'
        ));
        return false;
      }

      $attr_value = urlencode($new_json);

      $this->log('INFO', sprintf(
        'Updated LazyBlock attribute with safe JSON encoding. Title preserved: %s',
        isset($image_data['title']) ? $image_data['title'] : 'N/A'
      ));
    }

    return $updated;
  }

  /**
   * Update image data recursively (for both block attributes and meta fields)
   *
   * @param array $data Data to process (passed by reference)
   * @param array $image_map Filename to attachment_id mapping
   * @param array $results Results array (passed by reference)
   * @return bool True if any updates were made
   */
  private function update_image_data_recursively(&$data, $image_map, &$results)
  {
    if (!is_array($data)) {
      return false;
    }

    $updated = false;

    // Check if this is a direct image object
    if (isset($data['url']) && isset($data['id'])) {
      if ($this->update_single_image_data($data, $image_map, $results)) {
        $updated = true;
      }
    }

    // Process array elements recursively
    foreach ($data as $key => &$value) {
      if (is_array($value)) {
        if ($this->update_image_data_recursively($value, $image_map, $results)) {
          $updated = true;
        }
      }
    }

    return $updated;
  }

  /**
   * Clean problematic HTML content in JSON data to prevent JSON encoding issues
   *
   * @param array $data Data to clean (passed by reference)
   */
  private function clean_html_in_json_data(&$data)
  {
    if (!is_array($data)) {
      return;
    }

    foreach ($data as $key => &$value) {
      if (is_string($value)) {
        // Clean up HTML content that might cause JSON issues
        if (strpos($key, 'rendered') !== false || strpos($key, 'description') !== false) {
          // Remove or escape problematic characters in HTML
          $value = $this->sanitize_html_for_json($value);
        }
      } elseif (is_array($value)) {
        // Recursively clean nested arrays
        $this->clean_html_in_json_data($value);
      }
    }
  }

  /**
   * Sanitize HTML content to be JSON-safe
   *
   * @param string $html HTML content
   * @return string Sanitized HTML
   */
  private function sanitize_html_for_json($html)
  {
    // Replace problematic characters that can break JSON
    $html = str_replace(array("\r\n", "\r", "\n"), '', $html); // Remove line breaks
    $html = str_replace(array('"'), '\"', $html); // Escape quotes
    $html = str_replace(array("'"), "\'", $html); // Escape single quotes

    // Remove any non-printable characters
    $html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $html);

    return $html;
  }

  /**
   * Find all images in data that have matching files in image_map
   *
   * @param array $data Data to search
   * @param array $image_map Image map
   * @return array Array of found image URLs
   */
  private function find_images_in_data($data, $image_map)
  {
    $found_images = array();

    if (!is_array($data)) {
      return $found_images;
    }

    // Check if this is a direct image object
    if (isset($data['url'])) {
      $filename = basename(parse_url($data['url'], PHP_URL_PATH));
      $clean_filename = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $filename);
      if ($this->find_matching_attachment($clean_filename, $image_map)) {
        $found_images[] = $data['url'];
        $this->log('INFO', sprintf('Found matching image: %s -> %s', $filename, $clean_filename));
      } else {
        $this->log('INFO', sprintf('No match for image: %s (clean: %s)', $filename, $clean_filename));
      }
    }

    // Search recursively
    foreach ($data as $value) {
      if (is_array($value)) {
        $nested_images = $this->find_images_in_data($value, $image_map);
        $found_images = array_merge($found_images, $nested_images);
      }
    }

    return $found_images;
  }

  /**
   * Update LazyBlock image sizes
   *
   * @param array $sizes Sizes array (passed by reference)
   * @param int $attachment_id Attachment ID
   */
  private function update_lazyblock_image_sizes(&$sizes, $attachment_id)
  {
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!$metadata || !isset($metadata['sizes'])) {
      return;
    }

    $upload_dir = wp_upload_dir();
    $base_url = dirname(wp_get_attachment_url($attachment_id));

    foreach ($sizes as $size_name => &$size_data) {
      if (isset($metadata['sizes'][$size_name])) {
        $size_info = $metadata['sizes'][$size_name];
        $size_data['url'] = $base_url . '/' . $size_info['file'];
        $size_data['width'] = $size_info['width'];
        $size_data['height'] = $size_info['height'];
      }
    }

    // Update full size
    if (isset($sizes['full'])) {
      $sizes['full']['url'] = wp_get_attachment_url($attachment_id);
      if ($metadata && isset($metadata['width']) && isset($metadata['height'])) {
        $sizes['full']['width'] = $metadata['width'];
        $sizes['full']['height'] = $metadata['height'];
      }
    }
  }

  /**
   * Find matching attachment in image map
   *
   * @param string $filename Filename to match
   * @param array $image_map Image map
   * @return int|false Attachment ID or false
   */
  private function find_matching_attachment($filename, $image_map)
  {
    // Try exact match first
    if (isset($image_map[$filename])) {
      return $image_map[$filename];
    }

    // Try partial matches
    foreach ($image_map as $map_filename => $attachment_id) {
      $clean_map_filename = pathinfo($map_filename, PATHINFO_FILENAME);
      $clean_filename = pathinfo($filename, PATHINFO_FILENAME);

      if ($clean_map_filename === $clean_filename) {
        return $attachment_id;
      }
    }

    return false;
  }

  /**
   * Update gallery block IDs
   *
   * @param array $block Gallery block (passed by reference)
   * @param array $image_map Filename to attachment_id mapping
   */
  private function update_gallery_ids(&$block, $image_map)
  {
    if (empty($block['attrs']['ids']) || !is_array($block['attrs']['ids'])) {
      return;
    }

    $new_ids = array();

    foreach ($block['innerBlocks'] as $inner_block) {
      if ($inner_block['blockName'] === 'core/image' && !empty($inner_block['attrs']['id'])) {
        $new_ids[] = $inner_block['attrs']['id'];
      }
    }

    if (!empty($new_ids)) {
      $block['attrs']['ids'] = $new_ids;
    }
  }

  /**
   * Get block content summary
   *
   * @param int $post_id Post ID
   * @return array Block summary
   */
  public function get_block_summary($post_id)
  {
    $post = get_post($post_id);
    if (!$post) {
      return array();
    }

    $blocks = parse_blocks($post->post_content);
    $summary = array(
      'total_blocks' => count($blocks),
      'image_blocks' => 0,
      'gallery_blocks' => 0,
      'images_in_galleries' => 0,
      'other_blocks' => 0
    );

    $this->count_blocks($blocks, $summary);

    return $summary;
  }

  /**
   * Count blocks recursively
   *
   * @param array $blocks Blocks array
   * @param array $summary Summary array (passed by reference)
   */
  private function count_blocks($blocks, &$summary)
  {
    foreach ($blocks as $block) {
      if (empty($block['blockName'])) {
        continue;
      }

      switch ($block['blockName']) {
        case 'core/image':
          $summary['image_blocks']++;
          break;

        case 'core/gallery':
          $summary['gallery_blocks']++;
          if (!empty($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $inner_block) {
              if ($inner_block['blockName'] === 'core/image') {
                $summary['images_in_galleries']++;
              }
            }
          }
          break;

        default:
          $summary['other_blocks']++;
          if (!empty($block['innerBlocks'])) {
            $this->count_blocks($block['innerBlocks'], $summary);
          }
          break;
      }
    }
  }

  /**
   * Validate block structure
   *
   * @param array $blocks Blocks array
   * @return array Validation results
   */
  public function validate_blocks($blocks)
  {
    $validation = array(
      'valid' => true,
      'errors' => array(),
      'warnings' => array()
    );

    foreach ($blocks as $block) {
      if (empty($block['blockName'])) {
        continue;
      }

      // Check for malformed blocks
      if (!isset($block['attrs']) || !isset($block['innerHTML'])) {
        $validation['errors'][] = sprintf(
          __('ブロック構造が不正です: %s', 'wp-single-post-migrator'),
          $block['blockName']
        );
        $validation['valid'] = false;
      }

      // Check image blocks
      if ($block['blockName'] === 'core/image') {
        if (empty($block['attrs']['url']) && empty($block['innerHTML'])) {
          $validation['warnings'][] = __('画像URLが見つからないブロックがあります', 'wp-single-post-migrator');
        }
      }

      // Recursively validate inner blocks
      if (!empty($block['innerBlocks'])) {
        $inner_validation = $this->validate_blocks($block['innerBlocks']);
        if (!$inner_validation['valid']) {
          $validation['valid'] = false;
          $validation['errors'] = array_merge($validation['errors'], $inner_validation['errors']);
        }
        $validation['warnings'] = array_merge($validation['warnings'], $inner_validation['warnings']);
      }
    }

    return $validation;
  }


  /**
   * Disable WordPress interference (autosave, revisions, etc.)
   */
  private function disable_wp_interference()
  {
    // Disable autosave
    if (!defined('DOING_AUTOSAVE')) {
      define('DOING_AUTOSAVE', true);
    }

    // Remove autosave hook
    remove_action('admin_init', 'wp_auto_save_post_revisioned');

    // Temporarily disable revisions
    add_filter('wp_revisions_to_keep', '__return_zero');

    // Disable heartbeat API that might interfere
    add_filter('heartbeat_settings', function ($settings) {
      $settings['interval'] = 60; // Increase interval
      return $settings;
    });

    $this->log('INFO', 'WordPress interference disabled');
  }

  /**
   * Restore WordPress functionality
   */
  private function restore_wp_interference()
  {
    // Re-enable revisions
    remove_filter('wp_revisions_to_keep', '__return_zero');

    // Restore autosave
    add_action('admin_init', 'wp_auto_save_post_revisioned');

    $this->log('INFO', 'WordPress interference restored');
  }

  /**
   * Clear WordPress caches aggressively
   */
  private function clear_wp_caches($post_id)
  {
    // Clear post cache
    clean_post_cache($post_id);

    // Clear object cache
    wp_cache_delete($post_id, 'posts');
    wp_cache_delete($post_id, 'post_meta');

    // Clear any opcode cache if available
    if (function_exists('opcache_reset')) {
      opcache_reset();
    }

    // Force WordPress to reload from database
    wp_cache_flush();

    $this->log('INFO', 'All caches cleared for post ' . $post_id);
  }

  /**
   * Log block update activity
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
   * Protect newlines in block attributes before serialization
   *
   * @param array $blocks Blocks array (passed by reference)
   */
  private function protect_newlines_in_blocks(&$blocks)
  {
    foreach ($blocks as &$block) {
      if (isset($block['attrs']) && is_array($block['attrs'])) {
        foreach ($block['attrs'] as $attr_name => &$attr_value) {
          if (is_string($attr_value)) {
            // Check for direct newlines (non-JSON attributes)
            if (strpos($attr_value, "\n") !== false && strpos($attr_value, '%') === false) {
              $attr_value = str_replace("\n", '___NEWLINE_PLACEHOLDER___', $attr_value);
              $this->log('INFO', sprintf('Protected direct newlines in %s attribute', $attr_name));
            }

            // Handle URL-encoded JSON attributes more carefully
            if (strpos($attr_value, '%') !== false && $this->looks_like_json_image_data($attr_value)) {
              $decoded = urldecode($attr_value);
              if ($decoded !== $attr_value && strpos($decoded, "\n") !== false) {
                $this->log('INFO', sprintf('Found newlines in JSON attribute %s, original: %s', $attr_name, substr($decoded, 0, 100)));

                // Try to parse as JSON to verify structure
                $json_data = json_decode($decoded, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($json_data)) {
                  // Only protect newlines in string values within the JSON
                  $this->protect_newlines_in_json_data($json_data);

                  // Re-encode with the same flags as before
                  $new_json = json_encode($json_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                  $attr_value = urlencode($new_json);

                  $this->log('INFO', sprintf('Protected newlines in JSON attribute %s', $attr_name));
                } else {
                  $this->log('WARNING', sprintf('Could not parse JSON in attribute %s, skipping newline protection', $attr_name));
                }
              }
            }
          }
        }
      }

      // Recursively protect inner blocks
      if (!empty($block['innerBlocks'])) {
        $this->protect_newlines_in_blocks($block['innerBlocks']);
      }
    }
  }

  /**
   * Protect newlines in JSON data recursively
   *
   * @param array $data JSON data (passed by reference)
   */
  private function protect_newlines_in_json_data(&$data)
  {
    if (is_array($data)) {
      foreach ($data as &$value) {
        if (is_string($value) && strpos($value, "\n") !== false) {
          $value = str_replace("\n", '___NEWLINE_PLACEHOLDER___', $value);
        } elseif (is_array($value)) {
          $this->protect_newlines_in_json_data($value);
        }
      }
    }
  }

  /**
   * Fix common newline corruption in serialized content
   *
   * @param string $content Serialized block content
   * @return string Content with fixed newlines
   */
  private function fix_newline_corruption($content)
  {
    // More aggressive pattern to fix known newline corruptions in LazyBlock titles
    // Pattern 1: Fix "季節のゆず庵nコース" -> "季節のゆず庵\\nコース"
    $patterns = array(
      // Japanese text with 'n' that should be newline
      '/("title":"[^"]*?)ゆず庵n([^"]*?")/u' => '$1ゆず庵\\\\n$2',
      '/("title":"[^"]*?)限定ランチn([^"]*?")/u' => '$1限定ランチ\\\\n$2',
      '/("title":"[^"]*?)(?:コースn|nコース)([^"]*?")/u' => function ($matches) {
        if (strpos($matches[0], 'コースn') !== false) {
          return str_replace('コースn', 'コース\\\\n', $matches[0]);
        } elseif (strpos($matches[0], 'nコース') !== false) {
          return str_replace('nコース', '\\\\nコース', $matches[0]);
        }
        return $matches[0];
      },
      // General pattern for Japanese text where 'n' appears between characters (likely corrupted newline)
      '/("title":"[^"]*?)([あ-ん一-龯])n([あ-ん一-龯][^"]*?")/u' => '$1$2\\\\n$3'
    );

    $fixed_content = $content;
    $changes_made = false;

    foreach ($patterns as $pattern => $replacement) {
      if (is_callable($replacement)) {
        $new_content = preg_replace_callback($pattern, $replacement, $fixed_content);
      } else {
        $new_content = preg_replace($pattern, $replacement, $fixed_content);
      }

      if ($new_content !== $fixed_content) {
        $changes_made = true;
        $fixed_content = $new_content;
        $this->log('INFO', 'Applied newline corruption fix with pattern: ' . $pattern);
      }
    }

    if ($changes_made) {
      $this->log('INFO', 'Applied newline corruption fixes to serialized content');
    }

    return $fixed_content;
  }

  /**
   * Process post meta fields for image updates
   *
   * @param int $post_id Post ID
   * @param array $image_map Filename to attachment_id mapping
   * @param array $results Results array (passed by reference)
   * @return int Number of updated meta fields
   */
  private function process_meta_fields($post_id, $image_map, &$results)
  {
    $updated_count = 0;

    // Get all post meta
    $all_meta = get_post_meta($post_id);
    if (empty($all_meta)) {
      return $updated_count;
    }

    $this->log('INFO', sprintf('Processing %d meta fields for post %d', count($all_meta), $post_id));

    // Process each meta field
    foreach ($all_meta as $meta_key => $meta_values) {
      // Skip WordPress internal fields and ACF field references
      if (strpos($meta_key, '_') === 0) {
        continue;
      }

      foreach ($meta_values as $meta_value) {
        if (is_string($meta_value) && $this->looks_like_json_image_data($meta_value)) {
          $this->log('INFO', sprintf('Processing meta field: %s', $meta_key));

          // Store original value in case update fails
          $original_value = $meta_value;

          if ($this->update_meta_field_image_data($meta_value, $image_map, $results)) {
            // Update the meta field with new value
            update_post_meta($post_id, $meta_key, $meta_value);
            $updated_count++;
            $this->log('INFO', sprintf('Updated meta field: %s', $meta_key));
          } else {
            $this->log('WARNING', sprintf('Meta field update failed for: %s', $meta_key));
          }
        }
      }
    }

    if ($updated_count > 0) {
      $this->log('INFO', sprintf('Updated %d meta fields', $updated_count));
    }

    return $updated_count;
  }

  /**
   * Update image data in meta field value
   *
   * @param string $meta_value Meta field value (passed by reference)
   * @param array $image_map Filename to attachment_id mapping
   * @param array $results Results array (passed by reference)
   * @return bool True if updated
   */
  private function update_meta_field_image_data(&$meta_value, $image_map, &$results)
  {
    // Handle URL-encoded JSON
    $decoded = urldecode($meta_value);
    $data = json_decode($decoded, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      // Try direct JSON decode
      $data = json_decode($meta_value, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
      }
      $decoded = $meta_value;
    }

    if (!is_array($data)) {
      return false;
    }

    // Use the same recursive logic as block attributes
    $updated = $this->update_image_data_recursively($data, $image_map, $results);

    if ($updated) {
      // Clean up any problematic HTML content
      $this->clean_html_in_json_data($data);

      // Re-encode the data with safe JSON encoding
      $new_json = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

      if ($new_json !== false) {
        // Validate the JSON can be parsed back
        $test_decode = json_decode($new_json, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          // If original was URL-encoded, re-encode it
          if ($decoded !== $meta_value) {
            $meta_value = urlencode($new_json);
          } else {
            $meta_value = $new_json;
          }
          return true;
        } else {
          $this->log('ERROR', 'Meta field JSON validation failed: ' . json_last_error_msg());
        }
      } else {
        $this->log('ERROR', 'Meta field JSON encoding failed: ' . json_last_error_msg());
      }
    }

    return false;
  }

  /**
   * Update single image data object
   *
   * @param array $image_data Image data (passed by reference)
   * @param array $image_map Filename to attachment_id mapping
   * @param array $results Results array (passed by reference)
   * @return bool True if updated
   */
  private function update_single_image_data(&$image_data, $image_map, &$results)
  {
    if (!isset($image_data['url'])) {
      return false;
    }

    $filename = basename(parse_url($image_data['url'], PHP_URL_PATH));
    $clean_filename = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $filename);

    $matched_attachment_id = $this->find_matching_attachment($clean_filename, $image_map);

    if ($matched_attachment_id) {
      $new_url = wp_get_attachment_url($matched_attachment_id);

      if ($new_url) {
        $this->log('INFO', sprintf(
          'Matched meta field image: %s -> ID %d, URL %s',
          $filename,
          $matched_attachment_id,
          $new_url
        ));

        // Update image data
        $image_data['id'] = $matched_attachment_id;
        $image_data['url'] = $new_url;

        // Update sizes if they exist
        if (isset($image_data['sizes']) && is_array($image_data['sizes'])) {
          $this->update_lazyblock_image_sizes($image_data['sizes'], $matched_attachment_id);
        }

        $results['processed_images'][] = $filename;
        return true;
      }
    } else {
      $results['failed_matches'][] = sprintf(
        __('メタフィールドの画像がマッチしませんでした: %s', 'wp-single-post-migrator'),
        $filename
      );
    }

    return false;
  }
}
