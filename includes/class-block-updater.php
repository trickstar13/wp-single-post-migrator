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
   * Update blocks with new media using automatic domain detection
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

    // Check if post content is actually there
    if (empty($post->post_content)) {
      global $wpdb;
      $direct_content = $wpdb->get_var($wpdb->prepare(
        "SELECT post_content FROM $wpdb->posts WHERE ID = %d",
        $post_id
      ));

      if ($direct_content && !$post->post_content) {
        $post->post_content = $direct_content;
      }
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

    // Clean up null values in block attributes before serialization to prevent WordPress core errors
    $this->clean_null_attributes($blocks);

    // Rebuild content using WordPress standard method
    $new_content = serialize_blocks($blocks);

    // Fix serialization corruption (same as synced patterns) - this prevents newline corruption
    $new_content = $this->fix_serialization_corruption($new_content);

    // Additional legacy fix for any remaining newline corruption
    $new_content = $this->fix_newline_corruption($new_content);

    // Validate that the content can be parsed again
    $test_blocks = parse_blocks($new_content);
    if (empty($test_blocks) && !empty($blocks)) {
      return new WP_Error(
        'content_serialization_failed',
        __('コンテンツの再構築に失敗しました。', 'wp-single-post-migrator')
      );
    }

    // Skip wp_update_post completely to prevent newline corruption
    // Use direct database update as primary method
    $this->disable_wp_interference();

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
      return new WP_Error('save_failed', __('記事の保存に失敗しました。', 'wp-single-post-migrator'));
    } else {
      $update_result = $post_id;
    }

    // Clear all caches aggressively
    $this->clear_wp_caches($post_id);

    // Re-enable WordPress features
    $this->restore_wp_interference();

    if (is_wp_error($update_result)) {
      return $update_result;
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

    // Extract custom link href from original innerHTML if linkDestination is custom
    $custom_href = null;
    if (isset($original_attrs['linkDestination']) && $original_attrs['linkDestination'] === 'custom') {
      $custom_href = $this->extract_custom_link_href($block);
    }

    // Keep original attributes that don't conflict
    $preserved_attrs = array();
    foreach ($original_attrs as $key => $value) {
      if (!in_array($key, array('id', 'url', 'width', 'height'))) {
        $preserved_attrs[$key] = $value;
      }
    }

    // Preserve custom link href if found
    if ($custom_href) {
      $preserved_attrs['href'] = $custom_href;
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

    // Only rebuild innerHTML for core image blocks, not custom blocks
    $this->rebuild_gutenberg_compatible_block($block, $attachment_id, $attachment_url);

    // Verify the reconstruction was successful (only for core blocks)
    if (
      strpos($block['innerHTML'], $attachment_url) === false ||
      strpos($block['innerHTML'], 'wp-image-' . $attachment_id) === false
    ) {
      return false;
    }

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
   * Extract custom link href from block innerHTML
   *
   * @param array $block Block data
   * @return string|null Custom link href or null if not found
   */
  private function extract_custom_link_href($block)
  {
    if (empty($block['innerHTML'])) {
      return null;
    }

    // Use regex to extract href from anchor tag
    if (preg_match('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/', $block['innerHTML'], $matches)) {
      return $matches[1];
    }

    return null;
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
    } elseif ($link_destination === 'custom' && isset($block['attrs']['href'])) {
      $custom_url = $block['attrs']['href'];
      $img_tag = sprintf('<a href="%s">%s</a>', esc_url($custom_url), $img_tag);
    }

    // Build figure classes
    $figure_classes = array('wp-block-image', 'size-' . $size_slug);

    // Preserve custom CSS classes if they exist
    if (isset($block['attrs']['className'])) {
      $figure_classes[] = $block['attrs']['className'];
    }

    $figure_class_string = implode(' ', $figure_classes);

    // Build the complete figure with Gutenberg-style structure
    $block['innerHTML'] = sprintf(
      '<figure class="%s">%s</figure>',
      esc_attr($figure_class_string),
      $img_tag
    );

    // Update innerContent to match innerHTML (Gutenberg requirement)
    $block['innerContent'] = array($block['innerHTML']);
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

    // First, check if this LazyBlock has any images that need updating
    $has_updatable_images = false;
    foreach ($block['attrs'] as $attr_name => $attr_value) {
      // Skip null or non-string attributes to prevent WordPress block support errors
      if ($attr_value === null || !is_string($attr_value)) {
        continue;
      }
      if ($this->looks_like_json_image_data($attr_value)) {
        $decoded = urldecode($attr_value);
        $data = json_decode($decoded, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          $found_images = $this->find_images_in_data($data, $image_map);
          if (!empty($found_images)) {
            $has_updatable_images = true;
            break;
          }
        }
      }
    }

    // If no images need updating, leave the block completely untouched
    if (!$has_updatable_images) {
      return $updated_count;
    }

    // Process each attribute that might contain image data
    foreach ($block['attrs'] as $attr_name => &$attr_value) {
      // Skip null or non-string attributes to prevent WordPress block support errors
      if ($attr_value === null || !is_string($attr_value)) {
        continue;
      }

      // Handle simple string attributes like 'title' that might contain newlines
      if ($attr_name === 'title' && is_string($attr_value)) {
        // Just preserve the title as-is (no changes needed for simple string)
        continue;
      }

      if (is_string($attr_value) && $this->looks_like_json_image_data($attr_value)) {
        // Store original value in case update fails
        $original_value = $attr_value;

        if ($this->update_lazyblock_image_attribute($attr_value, $image_map, $results)) {
          $updated_count++;
        } else {
          // If update failed, restore original value to prevent data loss
          $attr_value = $original_value;
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
      return false;
    }

    $updated = false;

    // Use the same logic as meta field processing for consistency
    $updated = $this->update_image_data_recursively($image_data, $image_map, $results);

    if ($updated) {
      // Clean up any problematic HTML content in description fields
      $this->clean_html_in_json_data($image_data);

      // Use conservative JSON encoding flags
      $new_json = json_encode($image_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

      if ($new_json === false) {
        return false;
      }

      // Validate that we can parse it back correctly
      $validation_data = json_decode($new_json, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
      }

      // Double-check that critical data is preserved
      if (isset($image_data['title']) && (!isset($validation_data['title']) || $validation_data['title'] !== $image_data['title'])) {
        return false;
      }

      $attr_value = urlencode($new_json);
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
      } elseif (is_string($value)) {
        // Update URLs in all string fields that contain external domains
        if ($this->update_html_image_urls($value, $image_map)) {
          $updated = true;
        }
      }
    }

    return $updated;
  }

  /**
   * Update image URLs in HTML content by automatic domain detection
   *
   * @param string $html_content HTML content (passed by reference)
   * @param array $image_map Image mapping array (not used, kept for compatibility)
   * @return bool True if any URLs were updated
   */
  private function update_html_image_urls(&$html_content, $image_map)
  {
    if (!is_string($html_content)) {
      return false;
    }

    $updated = false;
    $current_site_url = site_url();

    // Use regex to find all URLs that contain wp-content/uploads
    $pattern = '/https?:\/\/[^\/\s]+\/wp-content\/uploads\/[^"\s<>]+/i';

    if (preg_match_all($pattern, $html_content, $matches)) {
      foreach ($matches[0] as $url) {
        if ($this->needs_domain_update($url)) {
          $new_url = $this->update_url_domain($url);
          if ($new_url !== $url) {
            $html_content = str_replace($url, $new_url, $html_content);
            $updated = true;
          }
        }
      }
    }

    return $updated;
  }

  /**
   * Check if URL needs domain update
   *
   * @param string $url URL to check
   * @return bool True if URL needs updating
   */
  public function needs_domain_update($url)
  {
    // Skip non-URLs and very long strings that are likely JSON
    if (strlen($url) > 500 || strpos($url, '%5B') !== false || strpos($url, '%7B') !== false) {
      return false;
    }

    // Must be a valid URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      return false;
    }

    // Check if URL contains wp-content/uploads and has a different domain
    if (strpos($url, '/wp-content/uploads/') !== false) {
      $parsed_url = parse_url($url);
      $current_site_url = site_url();
      $current_domain = parse_url($current_site_url, PHP_URL_HOST);

      // If URL domain is different from current domain, it needs updating
      if (!empty($parsed_url['host']) && $parsed_url['host'] !== $current_domain) {
        return true;
      }
    }

    return false;
  }

  /**
   * Update URL domain to match current site with proper subdirectory handling
   *
   * @param string $url URL to update
   * @return string Updated URL
   */
  public function update_url_domain($url)
  {
    $parsed_url = parse_url($url);
    $current_site_url = site_url();
    $current_parsed = parse_url($current_site_url);

    if (!$parsed_url || !$current_parsed) {
      return $url;
    }

    // Extract the path starting from /wp-content/
    $path = !empty($parsed_url['path']) ? $parsed_url['path'] : '';
    $wp_content_pos = strpos($path, '/wp-content/');

    if ($wp_content_pos === false) {
      // If no wp-content found, return original URL
      return $url;
    }

    // Get the relative path from wp-content onwards
    $relative_path = substr($path, $wp_content_pos);

    // Get the base path from current site (e.g., "/blog" if WordPress is in subdirectory)
    $base_path = !empty($current_parsed['path']) ? rtrim($current_parsed['path'], '/') : '';

    // Build new URL with current domain
    $scheme = !empty($current_parsed['scheme']) ? $current_parsed['scheme'] : 'https';
    $host = !empty($current_parsed['host']) ? $current_parsed['host'] : '';
    $port = !empty($current_parsed['port']) ? ':' . $current_parsed['port'] : '';
    $full_path = $base_path . $relative_path;
    $query = !empty($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
    $fragment = !empty($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

    return $scheme . '://' . $host . $port . $full_path . $query . $fragment;
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

    foreach ($patterns as $pattern => $replacement) {
      if (is_callable($replacement)) {
        $new_content = preg_replace_callback($pattern, $replacement, $fixed_content);
      } else {
        $new_content = preg_replace($pattern, $replacement, $fixed_content);
      }

      if ($new_content !== $fixed_content) {
        $fixed_content = $new_content;
      }
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

    // Process each meta field
    foreach ($all_meta as $meta_key => $meta_values) {
      // Skip WordPress internal fields and ACF field references
      if (strpos($meta_key, '_') === 0) {
        continue;
      }

      foreach ($meta_values as $meta_value) {
        if (is_string($meta_value) && $this->looks_like_json_image_data($meta_value)) {
          // Store original value in case update fails
          $original_value = $meta_value;

          if ($this->update_meta_field_image_data($meta_value, $image_map, $results)) {
            // Update the meta field with new value
            update_post_meta($post_id, $meta_key, $meta_value);
            $updated_count++;
          }
        }
      }
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
        }
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

  /**
   * Fix WordPress serialize_blocks() corruption issues
   * This method is copied from IPBMFZ_Synced_Pattern_Handler to ensure
   * consistent newline handling between post and pattern imports
   *
   * @param string $content Serialized block content
   * @return string Content with fixed serialization corruption
   */
  private function fix_serialization_corruption($content)
  {
    // Fix newline corruption that WordPress serialize_blocks() can cause
    // This is especially common with Japanese text and complex HTML
    $original_content = $content;

    // Fix escaped newlines that get corrupted
    // NOTE: Commented out - this breaks LazyBlocks by converting \n to actual newlines
    // serialize_blocks() already handles newlines correctly
    // $content = str_replace('\\n', "\n", $content);

    // Fix double-escaped quotes
    $content = str_replace('\"', '"', $content);

    // Fix JSON corruption in block attributes
    $content = preg_replace_callback('/<!--\s*wp:[\w\/\-]+\s*(\{[^}]*\})\s*-->/', function($matches) {
      $json = $matches[1];
      $decoded = json_decode($json, true);
      if (json_last_error() === JSON_ERROR_NONE) {
        // Re-encode properly
        return str_replace($matches[1], wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $matches[0]);
      }
      return $matches[0];
    }, $content);

    return $content;
  }

  /**
   * Remove null values from block attributes to prevent WordPress core errors
   *
   * @param array $blocks Blocks array (passed by reference)
   */
  private function clean_null_attributes(&$blocks)
  {
    foreach ($blocks as &$block) {
      if (isset($block['attrs']) && is_array($block['attrs'])) {
        // Remove null values
        foreach ($block['attrs'] as $attr_name => $attr_value) {
          if ($attr_value === null) {
            unset($block['attrs'][$attr_name]);
          }
        }
      }

      // Recursively clean inner blocks
      if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
        $this->clean_null_attributes($block['innerBlocks']);
      }
    }
  }
}