<?php

/**
 * Synced Pattern Handler Class
 *
 * Handles export and import of WordPress synced patterns (reusable blocks)
 */

if (!defined('ABSPATH')) {
  exit;
}

class IPBMFZ_Synced_Pattern_Handler
{

  /**
   * Get all synced patterns from the database
   *
   * @return array Array of synced pattern post objects
   */
  public function get_all_synced_patterns()
  {
    // Check WordPress version
    global $wp_version;
    $this->log('INFO', sprintf('WordPress version: %s', $wp_version));

    $patterns = get_posts(array(
      'post_type' => 'wp_block',
      'post_status' => 'publish',
      'numberposts' => -1,
      'orderby' => 'title',
      'order' => 'ASC'
    ));

    $this->log('INFO', sprintf('Found %d synced patterns (post_type: wp_block)', count($patterns)));

    // Debug: Log pattern details
    if (defined('WP_DEBUG') && WP_DEBUG) {
      foreach ($patterns as $pattern) {
        $this->log('DEBUG', sprintf(
          'Pattern ID: %d, Title: %s, Status: %s',
          $pattern->ID,
          $pattern->post_title,
          $pattern->post_status
        ));
      }
    }

    return $patterns;
  }

  /**
   * Export all synced patterns to JSON files
   *
   * @param string $export_dir Directory to export patterns to
   * @return array Results array with success/error information
   */
  public function export_synced_patterns($export_dir)
  {
    $patterns = $this->get_all_synced_patterns();
    $results = array(
      'exported_patterns' => 0,
      'collected_images' => array(),
      'errors' => array()
    );

    if (empty($patterns)) {
      $this->log('INFO', 'No synced patterns found to export');
      $results['message'] = __('エクスポート可能な同期パターンが見つかりませんでした。まず同期パターンを作成してください。', 'wp-single-post-migrator');
      return $results;
    }

    // Create synced-patterns directory
    $patterns_dir = $export_dir . '/synced-patterns';
    if (!file_exists($patterns_dir)) {
      if (!wp_mkdir_p($patterns_dir)) {
        return new WP_Error(
          'directory_creation_failed',
          __('同期パターンディレクトリの作成に失敗しました。', 'wp-single-post-migrator')
        );
      }
    }

    // Create pattern-images directory
    $pattern_images_dir = $patterns_dir . '/pattern-images';
    if (!wp_mkdir_p($pattern_images_dir)) {
      return new WP_Error(
        'directory_creation_failed',
        __('パターン画像ディレクトリの作成に失敗しました。', 'wp-single-post-migrator')
      );
    }

    // Create reference mapping file for post import
    $reference_map = array();

    foreach ($patterns as $pattern) {
      try {
        // Add to reference mapping
        $reference_map[$pattern->ID] = array(
          'title' => $pattern->post_title,
          'slug' => $pattern->post_name ?: sanitize_title($pattern->post_title)
        );

        // Generate pattern JSON data
        $pattern_data = $this->generate_pattern_json($pattern);
        if (is_wp_error($pattern_data)) {
          $results['errors'][] = sprintf(
            __('パターン "%s" のデータ生成に失敗: %s', 'wp-single-post-migrator'),
            $pattern->post_title,
            $pattern_data->get_error_message()
          );
          continue;
        }

        // Collect images from pattern content
        $pattern_images = $this->extract_images_from_pattern_content($pattern->post_content);
        $pattern_data['images'] = array();

        foreach ($pattern_images as $image_url) {
          $image_result = $this->copy_pattern_image($image_url, $pattern_images_dir, $pattern->ID);
          if ($image_result && !is_wp_error($image_result)) {
            $pattern_data['images'][] = $image_result;
            $results['collected_images'][] = $image_result['filename'];
          }
        }

        // Save pattern JSON file
        $json_filename = $patterns_dir . '/pattern-' . $pattern->ID . '.json';
        $json_content = wp_json_encode($pattern_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (file_put_contents($json_filename, $json_content) === false) {
          $results['errors'][] = sprintf(
            __('パターン "%s" のJSONファイル保存に失敗', 'wp-single-post-migrator'),
            $pattern->post_title
          );
          continue;
        }

        $results['exported_patterns']++;
        $this->log('INFO', sprintf(
          'Exported pattern: %s (ID: %d) with %d images',
          $pattern->post_title,
          $pattern->ID,
          count($pattern_data['images'])
        ));

      } catch (Exception $e) {
        $results['errors'][] = sprintf(
          __('パターン "%s" の処理中にエラー: %s', 'wp-single-post-migrator'),
          $pattern->post_title,
          $e->getMessage()
        );
      }
    }

    // Save reference mapping file
    if (!empty($reference_map)) {
      $refs_file = $patterns_dir . '/pattern-refs.json';
      $refs_content = wp_json_encode($reference_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      file_put_contents($refs_file, $refs_content);
      $this->log('INFO', sprintf('Created pattern reference mapping with %d patterns', count($reference_map)));
    }

    $this->log('INFO', sprintf(
      'Pattern export completed. Exported: %d, Images: %d, Errors: %d',
      $results['exported_patterns'],
      count($results['collected_images']),
      count($results['errors'])
    ));

    return $results;
  }

  /**
   * Generate JSON data for a synced pattern
   *
   * @param WP_Post $pattern Pattern post object
   * @return array|WP_Error Pattern data array or error
   */
  public function generate_pattern_json($pattern)
  {
    if (!$pattern || $pattern->post_type !== 'wp_block') {
      return new WP_Error(
        'invalid_pattern',
        __('無効なパターンデータです。', 'wp-single-post-migrator')
      );
    }

    // Get pattern categories (wp_pattern_category taxonomy)
    $categories = wp_get_post_terms($pattern->ID, 'wp_pattern_category', array('fields' => 'slugs'));
    if (is_wp_error($categories)) {
      $categories = array();
    }

    // Get pattern meta data
    $sync_status = get_post_meta($pattern->ID, 'wp_pattern_sync_status', true);

    $pattern_data = array(
      'id' => $pattern->ID,
      'title' => $pattern->post_title,
      'content' => $pattern->post_content,
      'status' => $pattern->post_status,
      'type' => 'wp_block',
      'date_created' => $pattern->post_date,
      'date_modified' => $pattern->post_modified,
      'meta' => array(
        'wp_pattern_sync_status' => $sync_status ?: 'full'
      ),
      'categories' => $categories,
      'keywords' => array(), // WordPress doesn't store keywords in database for patterns
      'images' => array() // Will be populated during export
    );

    return $pattern_data;
  }

  /**
   * Extract images from pattern content
   *
   * @param string $content Pattern block content
   * @return array Array of image URLs
   */
  public function extract_images_from_pattern_content($content)
  {
    $images = array();

    // Parse blocks to find images
    $blocks = parse_blocks($content);
    $this->extract_images_from_blocks($blocks, $images);

    // Also search for images in HTML content using regex as fallback
    preg_match_all('/(?:src|data-src)=["\']([^"\']*\.(?:jpg|jpeg|png|gif|webp|svg))["\']/', $content, $matches);
    if (!empty($matches[1])) {
      foreach ($matches[1] as $url) {
        if ($this->is_local_image($url)) {
          $images[] = $url;
        }
      }
    }

    // Remove duplicates and filter out external URLs
    $images = array_unique($images);
    $local_images = array();
    $original_images = array();

    foreach ($images as $image_url) {
      if ($this->is_local_image($image_url)) {
        $local_images[] = $image_url;
      }
    }

    // Filter out thumbnail images and keep only original images
    foreach ($local_images as $image_url) {
      $original_url = $this->get_original_image_url($image_url);
      if ($original_url && !in_array($original_url, $original_images)) {
        $original_images[] = $original_url;
      }
    }

    $this->log('INFO', sprintf('Extracted %d images from pattern content (%d after removing thumbnails)', count($local_images), count($original_images)));
    return $original_images;
  }

  /**
   * Recursively extract images from block structures
   *
   * @param array $blocks Array of parsed blocks
   * @param array $images Array to store found image URLs (passed by reference)
   */
  private function extract_images_from_blocks($blocks, &$images)
  {
    foreach ($blocks as $block) {
      // Core image block
      if ($block['blockName'] === 'core/image' && !empty($block['attrs']['url'])) {
        $images[] = $block['attrs']['url'];
      }

      // Core gallery block
      if ($block['blockName'] === 'core/gallery' && !empty($block['attrs']['images'])) {
        foreach ($block['attrs']['images'] as $gallery_image) {
          if (!empty($gallery_image['fullUrl'])) {
            $images[] = $gallery_image['fullUrl'];
          } elseif (!empty($gallery_image['url'])) {
            $images[] = $gallery_image['url'];
          }
        }
      }

      // LazyBlocks and other custom blocks - check innerHTML and attrs
      if (strpos($block['blockName'], 'lazyblock/') === 0) {
        $this->log('INFO', sprintf('Processing LazyBlock: %s', $block['blockName']));
        $images_before = count($images);

        // Check block attributes for image URLs
        if (!empty($block['attrs'])) {
          $this->log('DEBUG', sprintf('LazyBlock has %d attributes to scan', count($block['attrs'])));
          $this->extract_images_from_lazyblock_attrs($block['attrs'], $images);
        } else {
          $this->log('DEBUG', 'LazyBlock has no attributes');
        }

        // Also check innerHTML for any missed images
        if (!empty($block['innerHTML'])) {
          $this->log('DEBUG', sprintf('Scanning LazyBlock innerHTML (%d chars)', strlen($block['innerHTML'])));
          preg_match_all('/(?:src|data-src)=["\']([^"\']*\.(?:jpg|jpeg|png|gif|webp|svg))["\']/', $block['innerHTML'], $matches);
          if (!empty($matches[1])) {
            $this->log('INFO', sprintf('Found %d image(s) in LazyBlock innerHTML', count($matches[1])));
            $images = array_merge($images, $matches[1]);
          } else {
            $this->log('DEBUG', 'No images found in LazyBlock innerHTML via regex');
          }

          // Also try to find wp-content/uploads/ URLs in innerHTML
          preg_match_all('/(?:src|data-src|href)=["\']([^"\']*wp-content\/uploads\/[^"\']*)["\']/', $block['innerHTML'], $upload_matches);
          if (!empty($upload_matches[1])) {
            $this->log('INFO', sprintf('Found %d uploads URL(s) in LazyBlock innerHTML', count($upload_matches[1])));
            $images = array_merge($images, $upload_matches[1]);
          }
        } else {
          $this->log('DEBUG', 'LazyBlock has no innerHTML');
        }

        $images_found = count($images) - $images_before;
        $this->log('INFO', sprintf('LazyBlock %s processed: %d image(s) found', $block['blockName'], $images_found));
      }

      // Process inner blocks recursively
      if (!empty($block['innerBlocks'])) {
        $this->extract_images_from_blocks($block['innerBlocks'], $images);
      }
    }
  }

  /**
   * Extract images from LazyBlock attributes
   *
   * @param array $attrs Block attributes
   * @param array $images Array to store found image URLs (passed by reference)
   */
  private function extract_images_from_lazyblock_attrs($attrs, &$images)
  {
    $found_in_attrs = 0;

    foreach ($attrs as $key => $value) {
      if (is_string($value)) {
        // Method 1: Check if value looks like an image URL (by extension)
        if (preg_match('/\.(?:jpg|jpeg|png|gif|webp|svg)$/i', $value)) {
          $this->log('INFO', sprintf('Found image URL in LazyBlock attr "%s": %s', $key, $value));
          $images[] = $value;
          $found_in_attrs++;
        }
        // Method 2: Check for wp-content/uploads/ URLs (even without extension)
        elseif (strpos($value, 'wp-content/uploads/') !== false) {
          $this->log('INFO', sprintf('Found uploads URL in LazyBlock attr "%s": %s', $key, substr($value, 0, 200)));

          // Extract all wp-content/uploads URLs from the string
          preg_match_all('/(?:https?:\/\/[^\/\s"\']*)?\/wp-content\/uploads\/[^\s"\']*\.(?:jpg|jpeg|png|gif|webp|svg)/i', $value, $matches);
          if (!empty($matches[0])) {
            $this->log('INFO', sprintf('Extracted %d image URL(s) from attr "%s"', count($matches[0]), $key));
            $images = array_merge($images, $matches[0]);
            $found_in_attrs += count($matches[0]);
          }

          // Also try URL decoding if it looks URL encoded
          if (strpos($value, '%') !== false) {
            $decoded_value = urldecode($value);
            $this->log('DEBUG', sprintf('URL decoded value: %s', substr($decoded_value, 0, 200)));

            preg_match_all('/(?:https?:\/\/[^\/\s"\']*)?\/wp-content\/uploads\/[^\s"\']*\.(?:jpg|jpeg|png|gif|webp|svg)/i', $decoded_value, $decoded_matches);
            if (!empty($decoded_matches[0])) {
              $this->log('INFO', sprintf('Extracted %d image URL(s) from URL-decoded attr "%s"', count($decoded_matches[0]), $key));
              $images = array_merge($images, $decoded_matches[0]);
              $found_in_attrs += count($decoded_matches[0]);
            }
          }
        }
        // Method 3: Check for URLs in JSON strings (try both encoded and non-encoded)
        elseif (strpos($value, '{') !== false || strpos($value, '[') !== false || strpos($value, '%5B') !== false || strpos($value, '%7B') !== false) {
          // First try direct JSON decode
          $json_data = json_decode($value, true);
          if (json_last_error() === JSON_ERROR_NONE) {
            $this->log('DEBUG', sprintf('Successfully parsed JSON from attr "%s"', $key));
            $before_count = count($images);
            $this->extract_images_from_data($json_data, $images);
            $found_count = count($images) - $before_count;
            if ($found_count > 0) {
              $this->log('INFO', sprintf('Found %d image(s) in JSON attr "%s"', $found_count, $key));
              $found_in_attrs += $found_count;
            }
          } else {
            // Try URL decoding first, then JSON decode
            $decoded_value = urldecode($value);
            $this->log('DEBUG', sprintf('Trying URL-decoded value for attr "%s": %s', $key, substr($decoded_value, 0, 200)));

            $json_data = json_decode($decoded_value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
              $this->log('DEBUG', sprintf('Successfully parsed JSON from URL-decoded attr "%s"', $key));
              $before_count = count($images);
              $this->extract_images_from_data($json_data, $images);
              $found_count = count($images) - $before_count;
              if ($found_count > 0) {
                $this->log('INFO', sprintf('Found %d image(s) in URL-decoded JSON attr "%s"', $found_count, $key));
                $found_in_attrs += $found_count;
              }
            } else {
              $this->log('DEBUG', sprintf('Failed to parse JSON from attr "%s": %s', $key, json_last_error_msg()));

              // Last resort: try to extract URLs directly from the encoded string
              if (strpos($value, 'wp-content') !== false || strpos($decoded_value, 'wp-content') !== false) {
                // Try on both original and decoded values
                $search_values = array($value, $decoded_value);
                foreach ($search_values as $search_value) {
                  preg_match_all('/(?:https?:\/\/[^\/\s"\'\\\\]*)?\/wp-content\/uploads\/[^\s"\'\\\\]*\.(?:jpg|jpeg|png|gif|webp|svg)/i', $search_value, $fallback_matches);
                  if (!empty($fallback_matches[0])) {
                    $this->log('INFO', sprintf('Extracted %d image URL(s) via fallback regex from attr "%s"', count($fallback_matches[0]), $key));
                    $images = array_merge($images, $fallback_matches[0]);
                    $found_in_attrs += count($fallback_matches[0]);
                    break; // Stop after first successful match
                  }
                }
              }
            }
          }
        }
        // Method 4: Check for attachment IDs and convert to URLs
        elseif (is_numeric($value) && $value > 0) {
          $attachment_url = wp_get_attachment_url($value);
          if ($attachment_url && $this->is_local_image($attachment_url)) {
            $this->log('INFO', sprintf('Converted attachment ID %s to URL in attr "%s": %s', $value, $key, $attachment_url));
            $images[] = $attachment_url;
            $found_in_attrs++;
          }
        }
      } elseif (is_array($value)) {
        $before_count = count($images);
        $this->extract_images_from_data($value, $images);
        $found_count = count($images) - $before_count;
        if ($found_count > 0) {
          $this->log('INFO', sprintf('Found %d image(s) in array attr "%s"', $found_count, $key));
          $found_in_attrs += $found_count;
        }
      } elseif (is_numeric($value) && $value > 0) {
        // Handle numeric attachment IDs in array context
        $attachment_url = wp_get_attachment_url($value);
        if ($attachment_url && $this->is_local_image($attachment_url)) {
          $this->log('INFO', sprintf('Converted attachment ID %s to URL in attr "%s": %s', $value, $key, $attachment_url));
          $images[] = $attachment_url;
          $found_in_attrs++;
        }
      }
    }

    if ($found_in_attrs > 0) {
      $this->log('INFO', sprintf('LazyBlock attrs scan complete: found %d image(s)', $found_in_attrs));
    } else {
      $this->log('DEBUG', 'No images found in LazyBlock attributes');
    }
  }

  /**
   * Recursively extract images from data structures
   *
   * @param mixed $data Data to search
   * @param array $images Array to store found image URLs (passed by reference)
   */
  private function extract_images_from_data($data, &$images)
  {
    if (is_array($data)) {
      foreach ($data as $key => $item) {
        $this->extract_images_from_data($item, $images);
      }
    } elseif (is_string($data)) {
      // Method 1: Check for image URLs by extension
      if (preg_match('/\.(?:jpg|jpeg|png|gif|webp|svg)$/i', $data)) {
        $images[] = $data;
      }
      // Method 2: Check for wp-content/uploads/ URLs
      elseif (strpos($data, 'wp-content/uploads/') !== false) {
        $images[] = $data;
      }
      // Method 3: Check for full URLs with image extensions anywhere in the path
      elseif (preg_match('/https?:\/\/[^\s]*\.(?:jpg|jpeg|png|gif|webp|svg)/i', $data)) {
        $images[] = $data;
      }
    } elseif (is_numeric($data) && $data > 0) {
      // Method 4: Handle attachment IDs
      $attachment_url = wp_get_attachment_url($data);
      if ($attachment_url && $this->is_local_image($attachment_url)) {
        $images[] = $attachment_url;
      }
    }
  }

  /**
   * Check if URL is a local image
   *
   * @param string $url Image URL
   * @return bool True if local image
   */
  private function is_local_image($url)
  {
    $upload_dir = wp_upload_dir();
    $site_url = site_url();

    // Check if URL contains upload directory path or site URL
    return (strpos($url, $upload_dir['baseurl']) !== false || strpos($url, $site_url) !== false);
  }

  /**
   * Get original image URL from thumbnail URL
   *
   * @param string $url Image URL (may be thumbnail)
   * @return string Original image URL
   */
  private function get_original_image_url($url)
  {
    // Remove size suffixes like -300x200, -150x150, -1024x768, etc.
    $original_url = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $url);

    // Also remove numbered suffixes like -1, -2 that WordPress adds for duplicates
    // But only if they come after size suffixes or at the end
    $original_url = preg_replace('/-\d+(\.[^.]+)$/', '$1', $original_url);

    // If the URL didn't change, it might already be original
    if ($original_url === $url) {
      // Check if the original file actually exists by trying to get attachment info
      $filename = basename(parse_url($original_url, PHP_URL_PATH));
      $upload_dir = wp_upload_dir();

      // Convert URL to file path to check existence
      $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $original_url);
      if (file_exists($file_path)) {
        $this->log('DEBUG', sprintf('Original image confirmed: %s', basename($original_url)));
        return $original_url;
      }
    } else {
      // Check if the original version exists
      $upload_dir = wp_upload_dir();
      $original_file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $original_url);

      if (file_exists($original_file_path)) {
        $this->log('DEBUG', sprintf('Found original image: %s -> %s', basename($url), basename($original_url)));
        return $original_url;
      } else {
        // If original doesn't exist, fall back to the provided URL
        $this->log('DEBUG', sprintf('Original image not found, using provided URL: %s', basename($url)));
        return $url;
      }
    }

    return $original_url;
  }

  /**
   * Copy pattern image to export directory
   *
   * @param string $image_url Image URL
   * @param string $pattern_images_dir Directory to copy image to
   * @param int $pattern_id Pattern ID for logging
   * @return array|WP_Error Image data array or error
   */
  private function copy_pattern_image($image_url, $pattern_images_dir, $pattern_id)
  {
    if (!$this->is_local_image($image_url)) {
      $this->log('WARNING', sprintf('Skipping external image: %s', $image_url));
      return false;
    }

    // Convert URL to file path
    $upload_dir = wp_upload_dir();
    $image_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_url);

    // Handle relative URLs
    if (strpos($image_url, '/') === 0) {
      $image_path = ABSPATH . ltrim($image_url, '/');
    }

    if (!file_exists($image_path)) {
      $this->log('WARNING', sprintf('Image file not found: %s', $image_path));
      return false;
    }

    $filename = basename($image_path);
    $destination = $pattern_images_dir . '/' . $filename;

    // Handle filename conflicts
    $counter = 1;
    $original_filename = $filename;
    while (file_exists($destination)) {
      $file_info = pathinfo($original_filename);
      $filename = $file_info['filename'] . '-' . $counter . '.' . $file_info['extension'];
      $destination = $pattern_images_dir . '/' . $filename;
      $counter++;
    }

    if (!copy($image_path, $destination)) {
      $this->log('ERROR', sprintf('Failed to copy image: %s to %s', $image_path, $destination));
      return new WP_Error(
        'image_copy_failed',
        sprintf(__('画像のコピーに失敗: %s', 'wp-single-post-migrator'), $filename)
      );
    }

    $this->log('INFO', sprintf('Copied pattern image: %s', $filename));

    return array(
      'original_url' => $image_url,
      'filename' => $filename,
      'size' => filesize($destination)
    );
  }

  /**
   * Import synced patterns from JSON files
   *
   * @param array $json_files Array of JSON file paths
   * @param array $image_map Mapping of old filenames to new attachment IDs
   * @param string $import_mode Import mode: 'create_new' or 'replace_existing'
   * @return array Results array
   */
  public function import_synced_patterns($json_files, $image_map = array(), $import_mode = 'create_new')
  {
    $results = array(
      'imported_patterns' => 0,
      'updated_patterns' => 0,
      'skipped_patterns' => 0,
      'errors' => array(),
      'pattern_reference_map' => array() // For post import reference mapping
    );

    if (empty($json_files)) {
      $this->log('INFO', 'No pattern JSON files found to import');
      return $results;
    }

    // Filter out reference mapping files at this level as well
    $filtered_files = array();
    foreach ($json_files as $file) {
      $filename = basename($file);
      if ($filename !== 'pattern-refs.json') {
        $filtered_files[] = $file;
      } else {
        $this->log('INFO', sprintf('Filtered out reference mapping file: %s', $filename));
      }
    }
    $json_files = $filtered_files;

    if (empty($json_files)) {
      $this->log('INFO', 'No valid pattern files to import after filtering');
      return $results;
    }

    foreach ($json_files as $json_file) {
      try {
        $pattern_data = $this->parse_pattern_json($json_file);
        if (is_wp_error($pattern_data)) {
          $filename = basename($json_file);

          // Skip reference mapping files silently (these are not errors)
          if ($filename === 'pattern-refs.json' || $pattern_data->get_error_code() === 'invalid_pattern_file') {
            $this->log('INFO', sprintf('Skipped non-pattern file: %s', $filename));
            continue;
          }

          $results['errors'][] = sprintf(
            __('JSONファイル %s の解析に失敗: %s', 'wp-single-post-migrator'),
            $filename,
            $pattern_data->get_error_message()
          );
          continue;
        }

        // First, update all domain URLs in the pattern content
        $pattern_data['content'] = $this->update_pattern_domains($pattern_data['content']);

        // Update image URLs in pattern content
        if (!empty($image_map)) {
          $pattern_data['content'] = $this->update_pattern_image_urls($pattern_data['content'], $image_map);
        }

        $import_result = $this->create_or_update_pattern($pattern_data, $import_mode);
        if (is_wp_error($import_result)) {
          $results['errors'][] = sprintf(
            __('パターン "%s" のインポートに失敗: %s', 'wp-single-post-migrator'),
            $pattern_data['title'],
            $import_result->get_error_message()
          );
          continue;
        }

        if ($import_result['action'] === 'created') {
          $results['imported_patterns']++;
        } elseif ($import_result['action'] === 'updated') {
          $results['updated_patterns']++;
        } else {
          $results['skipped_patterns']++;
        }

        // Add to reference mapping for post import
        if (isset($pattern_data['id'])) {
          $results['pattern_reference_map'][$pattern_data['id']] = $import_result['pattern_id'];
        }

        $this->log('INFO', sprintf(
          'Pattern %s: %s (ID: %d, Original ID: %s)',
          $import_result['action'],
          $pattern_data['title'],
          $import_result['pattern_id'],
          $pattern_data['id'] ?? 'unknown'
        ));

      } catch (Exception $e) {
        $results['errors'][] = sprintf(
          __('ファイル %s の処理中にエラー: %s', 'wp-single-post-migrator'),
          basename($json_file),
          $e->getMessage()
        );
      }
    }

    // Save pattern reference mapping for later use
    if (!empty($results['pattern_reference_map'])) {
      $this->save_pattern_reference_mapping($results['pattern_reference_map']);
    }

    $this->log('INFO', sprintf(
      'Pattern import completed. Created: %d, Updated: %d, Skipped: %d, Errors: %d',
      $results['imported_patterns'],
      $results['updated_patterns'],
      $results['skipped_patterns'],
      count($results['errors'])
    ));

    return $results;
  }

  /**
   * Parse pattern JSON file
   *
   * @param string $json_file Path to JSON file
   * @return array|WP_Error Pattern data or error
   */
  public function parse_pattern_json($json_file)
  {
    if (!file_exists($json_file)) {
      return new WP_Error(
        'file_not_found',
        __('JSONファイルが見つかりません。', 'wp-single-post-migrator')
      );
    }

    $filename = basename($json_file);

    // Skip reference mapping file
    if ($filename === 'pattern-refs.json') {
      return new WP_Error(
        'invalid_pattern_file',
        __('pattern-refs.jsonはパターンファイルではありません。', 'wp-single-post-migrator')
      );
    }

    $json_content = file_get_contents($json_file);
    if ($json_content === false) {
      return new WP_Error(
        'file_read_failed',
        __('JSONファイルの読み込みに失敗しました。', 'wp-single-post-migrator')
      );
    }

    $pattern_data = json_decode($json_content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return new WP_Error(
        'json_decode_failed',
        sprintf(__('JSON解析エラー: %s', 'wp-single-post-migrator'), json_last_error_msg())
      );
    }

    // Check if this is a reference mapping file by structure
    if ($this->is_reference_mapping_data($pattern_data)) {
      return new WP_Error(
        'invalid_pattern_file',
        sprintf(__('%sはリファレンスマッピングファイルです。パターンファイルではありません。', 'wp-single-post-migrator'), $filename)
      );
    }

    // Validate required fields for pattern files
    $required_fields = array('title', 'content', 'type');
    foreach ($required_fields as $field) {
      if (empty($pattern_data[$field])) {
        return new WP_Error(
          'missing_required_field',
          sprintf(__('必須フィールドが不足: %s', 'wp-single-post-migrator'), $field)
        );
      }
    }

    return $pattern_data;
  }

  /**
   * Check if data structure is a reference mapping file
   *
   * @param array $data JSON data
   * @return bool True if this looks like a reference mapping file
   */
  private function is_reference_mapping_data($data)
  {
    if (!is_array($data)) {
      return false;
    }

    // Reference mapping files have numeric keys and specific structure
    foreach ($data as $key => $value) {
      if (!is_numeric($key)) {
        return false;
      }
      if (!is_array($value) || !isset($value['title'])) {
        return false;
      }
      // If it has 'title' and 'slug' but no 'content' or 'type', it's likely a reference mapping
      if (isset($value['title']) && isset($value['slug']) && !isset($value['content']) && !isset($value['type'])) {
        return true;
      }
    }

    return false;
  }

  /**
   * Update image URLs in pattern content
   *
   * @param string $content Pattern content
   * @param array $image_map Mapping of old filenames to new attachment IDs
   * @return string Updated content
   */
  private function update_pattern_image_urls($content, $image_map)
  {
    if (empty($image_map)) {
      $this->log('INFO', 'No image map provided for URL updating');
      return $content;
    }

    $this->log('INFO', sprintf('Updating pattern content with %d image mappings', count($image_map)));

    $updated_content = $content;
    $blocks = parse_blocks($updated_content);

    foreach ($image_map as $old_filename => $attachment_id) {
      $new_url = wp_get_attachment_url($attachment_id);
      if (!$new_url) {
        $this->log('WARNING', sprintf('Could not get URL for attachment ID: %d', $attachment_id));
        continue;
      }

      $this->log('INFO', sprintf('Processing image mapping: %s -> %s (ID: %d)', $old_filename, $new_url, $attachment_id));

      // Update blocks with detailed logging
      $this->update_blocks_image_references($blocks, $old_filename, $attachment_id, $new_url);
    }

    // Additional fallback: Force update any remaining broken image blocks
    $this->force_update_broken_image_blocks($blocks, $image_map);

    // Serialize blocks back to content using WordPress method with corruption fix
    $updated_content = serialize_blocks($blocks);
    $updated_content = $this->fix_serialization_corruption($updated_content);

    // Validate that our changes actually took effect
    $validation_blocks = parse_blocks($updated_content);
    $changes_preserved = $this->validate_image_changes($validation_blocks, $image_map);

    if (!$changes_preserved) {
      $this->log('WARNING', 'serialize_blocks() corrupted our changes, attempting manual reconstruction');
      // Try a different approach - manually build the content
      $updated_content = $this->manually_reconstruct_content($blocks);
    }

    // Additional step: Update attachment IDs in LazyBlocks JSON attributes
    $blocks_updated = false;
    $this->update_attachment_ids_in_blocks($blocks, $image_map, $blocks_updated);

    if ($blocks_updated) {
      $this->log('INFO', 'Updating pattern content after attachment ID mapping');
      $updated_content = serialize_blocks($blocks);
      $updated_content = $this->fix_serialization_corruption($updated_content);
    }

    if ($updated_content !== $content) {
      $this->log('INFO', 'Pattern content was updated with new image URLs and attachment IDs');
    } else {
      $this->log('WARNING', 'Pattern content was not changed after URL update attempt');
    }

    return $updated_content;
  }

  /**
   * Force update any broken image blocks that weren't caught by normal logic
   *
   * @param array $blocks Array of blocks (passed by reference)
   * @param array $image_map Image mapping array
   */
  private function force_update_broken_image_blocks(&$blocks, $image_map)
  {
    foreach ($blocks as &$block) {
      // Handle core image blocks
      if ($block['blockName'] === 'core/image') {
        // Check if this block has valid attributes but broken HTML
        if (!empty($block['attrs']['id']) && !empty($block['attrs']['url'])) {
          $attachment_id = $block['attrs']['id'];
          $new_url = $block['attrs']['url'];

          // Check if HTML is broken (missing src or wrong class)
          $html_broken = false;
          if (empty($block['innerHTML']) || strpos($block['innerHTML'], 'src=') === false) {
            $html_broken = true;
          } else if (preg_match('/wp-image-(\d+)/', $block['innerHTML'], $matches) && $matches[1] != $attachment_id) {
            $html_broken = true;
          }

          if ($html_broken) {
            $this->log('INFO', sprintf('Force rebuilding broken core image block: ID=%d, URL=%s', $attachment_id, $new_url));

            // Rebuild the HTML completely
            $alt_text = !empty($block['attrs']['alt']) ? esc_attr($block['attrs']['alt']) : '';
            $size_slug = !empty($block['attrs']['sizeSlug']) ? $block['attrs']['sizeSlug'] : 'full';

            // Build CSS classes
            $css_classes = array('wp-image-' . $attachment_id);
            if ($size_slug !== 'full') {
              $css_classes[] = 'size-' . $size_slug;
            }

            // Build figure classes
            $figure_classes = array('wp-block-image', 'size-' . $size_slug);
            if (!empty($block['attrs']['className'])) {
              $custom_classes = explode(' ', $block['attrs']['className']);
              foreach ($custom_classes as $custom_class) {
                $custom_class = trim($custom_class);
                if ($custom_class) {
                  $figure_classes[] = $custom_class;
                }
              }
            }

            // Rebuild HTML
            $img_tag = sprintf('<img src="%s" alt="%s" class="%s" />',
              esc_attr($new_url),
              $alt_text,
              esc_attr(implode(' ', array_unique($css_classes)))
            );

            $block['innerHTML'] = sprintf('<figure class="%s">%s</figure>',
              esc_attr(implode(' ', array_unique($figure_classes))),
              $img_tag
            );

            $this->log('INFO', sprintf('Force updated broken core image block HTML'));
          }
        }
      }

      // Handle LazyBlocks
      elseif (strpos($block['blockName'], 'lazyblock/') === 0) {
        // Check if LazyBlock has image attributes but broken HTML
        if (!empty($block['attrs'])) {
          foreach ($image_map as $old_filename => $attachment_id) {
            $new_url = wp_get_attachment_url($attachment_id);
            if (!$new_url) continue;

            // Check if this LazyBlock needs this image
            $needs_image = false;
            foreach ($block['attrs'] as $key => $value) {
              if (is_string($value) && $this->filename_matches($value, $old_filename)) {
                $needs_image = true;
                break;
              } elseif (is_array($value) && $this->array_contains_image($value, $old_filename)) {
                $needs_image = true;
                break;
              }
            }

            if ($needs_image && !empty($block['innerHTML'])) {
              $html_broken = false;

              // Check if HTML is missing src attributes
              if (strpos($block['innerHTML'], '<img') !== false && strpos($block['innerHTML'], 'src=') === false) {
                $html_broken = true;
              }
              // Check if HTML doesn't contain the updated URL
              elseif (strpos($block['innerHTML'], $new_url) === false) {
                $html_broken = true;
              }

              if ($html_broken) {
                $this->log('INFO', sprintf('Force rebuilding broken LazyBlock: %s, Image: %s -> %s', $block['blockName'], $old_filename, $new_url));

                // Fix the HTML
                $block['innerHTML'] = $this->fix_lazyblock_missing_src($block['innerHTML'], $block['attrs'], $attachment_id, $new_url);

                $this->log('INFO', sprintf('Force updated broken LazyBlock HTML'));
              }
            }
          }
        }
      }

      // Process inner blocks
      if (!empty($block['innerBlocks'])) {
        $this->force_update_broken_image_blocks($block['innerBlocks'], $image_map);
      }
    }
  }

  /**
   * Check if array contains image matching filename
   *
   * @param array $array Array to search
   * @param string $filename Filename to match
   * @return bool True if found
   */
  private function array_contains_image($array, $filename)
  {
    foreach ($array as $item) {
      if (is_string($item) && $this->filename_matches($item, $filename)) {
        return true;
      } elseif (is_array($item) && $this->array_contains_image($item, $filename)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Create or update synced pattern
   *
   * @param array $pattern_data Pattern data
   * @param string $import_mode Import mode
   * @return array|WP_Error Result array or error
   */
  public function create_or_update_pattern($pattern_data, $import_mode = 'create_new')
  {
    // Check if pattern already exists by title using WP_Query (replaces deprecated get_page_by_title)
    $existing_pattern = $this->get_pattern_by_title($pattern_data['title']);

    if ($existing_pattern && $import_mode === 'create_new') {
      // Create unique title
      $counter = 1;
      $original_title = $pattern_data['title'];
      $max_attempts = 1000;
      do {
        if ($counter > $max_attempts) {
          return new WP_Error(
            'max_title_attempts_exceeded',
            sprintf(
              'Could not create unique pattern title after %d attempts. Original title: "%s"',
              $max_attempts,
              $original_title
            )
          );
        }
        $pattern_data['title'] = $original_title . ' (' . $counter . ')';
        $existing_pattern = $this->get_pattern_by_title($pattern_data['title']);
        $counter++;
      } while ($existing_pattern);
    }

    $post_data = array(
      'post_title' => sanitize_text_field($pattern_data['title']),
      'post_content' => wp_kses_post($pattern_data['content']),
      'post_status' => 'publish',
      'post_type' => 'wp_block'
    );

    if ($existing_pattern && $import_mode === 'replace_existing') {
      // Update existing pattern
      $post_data['ID'] = $existing_pattern->ID;
      $pattern_id = wp_update_post($post_data);
      $action = 'updated';
    } else {
      // Create new pattern
      $pattern_id = wp_insert_post($post_data);
      $action = 'created';
    }

    if (is_wp_error($pattern_id) || !$pattern_id) {
      return new WP_Error(
        'pattern_save_failed',
        __('パターンの保存に失敗しました。', 'wp-single-post-migrator')
      );
    }

    // Set pattern meta data
    if (!empty($pattern_data['meta']['wp_pattern_sync_status'])) {
      update_post_meta($pattern_id, 'wp_pattern_sync_status', $pattern_data['meta']['wp_pattern_sync_status']);
    }

    // Set pattern categories
    if (!empty($pattern_data['categories'])) {
      wp_set_post_terms($pattern_id, $pattern_data['categories'], 'wp_pattern_category');
    }

    return array(
      'pattern_id' => $pattern_id,
      'action' => $action
    );
  }

  /**
   * Validate pattern data
   *
   * @param array $data Pattern data
   * @return array Validation result
   */
  public function validate_pattern_data($data)
  {
    $errors = array();

    if (empty($data['title'])) {
      $errors[] = __('パターンタイトルが必要です。', 'wp-single-post-migrator');
    }

    if (empty($data['content'])) {
      $errors[] = __('パターンコンテンツが必要です。', 'wp-single-post-migrator');
    }

    if (!empty($data['content'])) {
      // Try to parse blocks to validate content
      $blocks = parse_blocks($data['content']);
      if (empty($blocks) && !empty($data['content'])) {
        $errors[] = __('パターンコンテンツの形式が無効です。', 'wp-single-post-migrator');
      }
    }

    return array(
      'valid' => empty($errors),
      'errors' => $errors
    );
  }

  /**
   * Update image references in parsed block structures
   *
   * @param array $blocks Array of parsed blocks (passed by reference)
   * @param string $old_filename Original image filename to match
   * @param int $attachment_id New attachment ID
   * @param string $new_url New image URL
   */
  private function update_blocks_image_references(&$blocks, $old_filename, $attachment_id, $new_url)
  {
    foreach ($blocks as &$block) {
      // Handle core image blocks
      if ($block['blockName'] === 'core/image') {
        $updated = false;
        $needs_update = false;
        $debug_reason = '';

        // Method 1: Check if block attributes contain the old image URL
        if (!empty($block['attrs']['url']) && $this->filename_matches($block['attrs']['url'], $old_filename)) {
          $needs_update = true;
          $debug_reason = 'attrs url matches';
        }

        // Method 2: Check if innerHTML contains the old filename
        if (!empty($block['innerHTML']) && strpos($block['innerHTML'], $old_filename) !== false) {
          $needs_update = true;
          $debug_reason = 'innerHTML contains filename';
        }

        // Method 3: Check if block already has the new attachment ID but broken HTML (attributes already updated)
        if (!empty($block['attrs']['id']) && $block['attrs']['id'] == $attachment_id) {
          // This block has the new ID, check if HTML is broken
          if (empty($block['innerHTML']) || strpos($block['innerHTML'], 'src=') === false) {
            $needs_update = true;
            $debug_reason = 'has new ID but broken HTML';
          }
        }

        // Method 4: Check for blocks with wp-image class that doesn't match the current attachment ID
        if (!empty($block['innerHTML']) && preg_match('/wp-image-(\d+)/', $block['innerHTML'], $id_matches)) {
          $html_attachment_id = (int)$id_matches[1];
          if (!empty($block['attrs']['id']) && $block['attrs']['id'] != $html_attachment_id) {
            $needs_update = true;
            $debug_reason = 'attrs ID mismatch with HTML class';
          }
        }

        // Method 5: Check for image blocks with missing src attribute
        if (!empty($block['innerHTML']) && strpos($block['innerHTML'], '<img') !== false && strpos($block['innerHTML'], 'src=') === false) {
          // This is an img tag without src - if this block has any image attributes, update it
          if (!empty($block['attrs']['id']) || !empty($block['attrs']['url'])) {
            $needs_update = true;
            $debug_reason = 'missing src attribute';
          }
        }

        if ($needs_update) {
          $this->log('INFO', sprintf('Updating image block (reason: %s): ID=%s, URL=%s',
            $debug_reason,
            $block['attrs']['id'] ?? 'none',
            $block['attrs']['url'] ?? 'none'
          ));

          // Update block attributes
          $block['attrs']['id'] = $attachment_id;
          $block['attrs']['url'] = $new_url;

          // Completely rebuild the innerHTML to ensure consistency
          $alt_text = !empty($block['attrs']['alt']) ? esc_attr($block['attrs']['alt']) : '';
          $size_slug = !empty($block['attrs']['sizeSlug']) ? $block['attrs']['sizeSlug'] : 'full';
          $css_classes = array('wp-image-' . $attachment_id);

          // Preserve existing classes from current innerHTML
          if (!empty($block['innerHTML']) && preg_match('/<img[^>]*class="([^"]*)"/', $block['innerHTML'], $matches)) {
            $existing_classes = explode(' ', $matches[1]);
            foreach ($existing_classes as $class) {
              $class = trim($class);
              if ($class && !preg_match('/^wp-image-\d+$/', $class)) {
                $css_classes[] = $class;
              }
            }
          }

          // Add size class if not already present
          $size_class = 'size-' . $size_slug;
          if (!in_array($size_class, $css_classes)) {
            $css_classes[] = $size_class;
          }

          // Build new img tag
          $class_attr = 'class="' . esc_attr(implode(' ', array_unique($css_classes))) . '"';
          $new_img_tag = '<img src="' . esc_attr($new_url) . '" alt="' . $alt_text . '" ' . $class_attr . ' />';

          // Rebuild the figure wrapper
          $figure_classes = array('wp-block-image', 'size-' . $size_slug);

          // Preserve custom figure classes from className attribute
          if (!empty($block['attrs']['className'])) {
            $custom_classes = explode(' ', $block['attrs']['className']);
            foreach ($custom_classes as $custom_class) {
              $custom_class = trim($custom_class);
              if ($custom_class && !in_array($custom_class, $figure_classes)) {
                $figure_classes[] = $custom_class;
              }
            }
          }

          // Also extract any custom figure classes from current innerHTML
          if (!empty($block['innerHTML']) && preg_match('/<figure[^>]*class="([^"]*)"/', $block['innerHTML'], $figure_matches)) {
            $existing_figure_classes = explode(' ', $figure_matches[1]);
            foreach ($existing_figure_classes as $class) {
              $class = trim($class);
              if ($class && !in_array($class, $figure_classes) && !preg_match('/^size-/', $class)) {
                $figure_classes[] = $class;
              }
            }
          }

          $figure_class_attr = 'class="' . esc_attr(implode(' ', array_unique($figure_classes))) . '"';
          $block['innerHTML'] = '<figure ' . $figure_class_attr . '>' . $new_img_tag . '</figure>';

          $updated = true;
          $this->log('INFO', sprintf('Rebuilt image block HTML: %s -> %s (ID: %d) - %s',
            $old_filename, $new_url, $attachment_id, $debug_reason));
        }
      }

      // Handle core gallery blocks
      if ($block['blockName'] === 'core/gallery' && !empty($block['attrs']['images'])) {
        foreach ($block['attrs']['images'] as &$gallery_image) {
          if (
            (!empty($gallery_image['fullUrl']) && $this->filename_matches($gallery_image['fullUrl'], $old_filename)) ||
            (!empty($gallery_image['url']) && $this->filename_matches($gallery_image['url'], $old_filename))
          ) {
            $gallery_image['id'] = $attachment_id;
            $gallery_image['url'] = $new_url;
            $gallery_image['fullUrl'] = $new_url;
            $this->log('INFO', sprintf('Updated gallery image: %s -> %s', $old_filename, $new_url));
          }
        }
      }

      // Handle LazyBlocks and other custom blocks
      if (strpos($block['blockName'], 'lazyblock/') === 0) {
        $updated = false;
        $needs_update = false;
        $debug_reason = '';

        // Method 1: Check if block attributes contain the old image URL
        if (!empty($block['attrs'])) {
          $attrs_updated = $this->update_lazyblock_image_attrs($block['attrs'], $old_filename, $attachment_id, $new_url);
          if ($attrs_updated) {
            $needs_update = true;
            $debug_reason = 'lazyblock attrs contains image';
          }
        }

        // Method 2: Check if innerHTML contains the old filename
        if (!empty($block['innerHTML']) && strpos($block['innerHTML'], $old_filename) !== false) {
          $needs_update = true;
          if (empty($debug_reason)) {
            $debug_reason = 'lazyblock innerHTML contains filename';
          }
        }

        // Method 3: Check for missing src attributes in LazyBlock HTML
        if (!empty($block['innerHTML']) && strpos($block['innerHTML'], '<img') !== false) {
          // Check if there are img tags without src or with wrong src
          if (strpos($block['innerHTML'], 'src=') === false ||
              (!empty($block['attrs']) && $this->lazyblock_needs_html_fix($block['attrs'], $block['innerHTML'], $attachment_id))) {
            $needs_update = true;
            if (empty($debug_reason)) {
              $debug_reason = 'lazyblock HTML needs repair';
            }
          }
        }

        if ($needs_update) {
          $this->log('INFO', sprintf('Updating LazyBlock (reason: %s): %s', $debug_reason, $block['blockName']));

          // Update innerHTML for LazyBlocks
          if (!empty($block['innerHTML'])) {
            $original_html = $block['innerHTML'];

            // Update existing src attributes
            $block['innerHTML'] = preg_replace(
              '#src="[^"]*' . preg_quote($old_filename, '#') . '"#',
              'src="' . esc_attr($new_url) . '"',
              $block['innerHTML']
            );

            // Fix missing src attributes
            $block['innerHTML'] = $this->fix_lazyblock_missing_src($block['innerHTML'], $block['attrs'], $attachment_id, $new_url);

            if ($original_html !== $block['innerHTML']) {
              $updated = true;
            }
          }

          if ($updated) {
            $this->log('INFO', sprintf('Updated LazyBlock HTML: %s - %s', $block['blockName'], $debug_reason));
          }
        }
      }

      // Process inner blocks recursively
      if (!empty($block['innerBlocks'])) {
        $this->update_blocks_image_references($block['innerBlocks'], $old_filename, $attachment_id, $new_url);
      }
    }
  }

  /**
   * Check if filename matches the URL
   *
   * @param string $url Image URL
   * @param string $filename Filename to match
   * @return bool True if filename matches
   */
  private function filename_matches($url, $filename)
  {
    // Skip if URL is actually JSON data (avoid false positives)
    if (strlen($url) > 500 || strpos($url, '%5B') !== false || strpos($url, '%7B') !== false) {
      return false;
    }

    // Extract filename from URL
    $url_filename = basename(parse_url($url, PHP_URL_PATH));

    // Remove size suffixes like -150x150, -300x200 from both
    $clean_url_filename = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $url_filename);
    $clean_filename = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $filename);

    // Also remove numbered suffixes like -1, -2
    $clean_url_filename = preg_replace('/-\d+(\.[^.]+)$/', '$1', $clean_url_filename);
    $clean_filename = preg_replace('/-\d+(\.[^.]+)$/', '$1', $clean_filename);

    // Multiple matching strategies
    $matches = array(
      $url_filename === $filename,                    // Exact match
      $clean_url_filename === $clean_filename,        // Clean filename match
      $clean_url_filename === $filename,              // Clean URL vs original filename
      $url_filename === $clean_filename,              // Original URL vs clean filename
      strpos($url, $filename) !== false,              // URL contains filename
      strpos($url, $clean_filename) !== false,        // URL contains clean filename
    );

    $is_match = in_array(true, $matches);

    if ($is_match) {
      $this->log('DEBUG', sprintf('Filename match: URL="%s" <-> File="%s" (Clean: "%s" <-> "%s")',
        $url_filename, $filename, $clean_url_filename, $clean_filename));
    }

    return $is_match;
  }

  /**
   * Check if URL has the same filename (even with different domain/path)
   *
   * @param string $url URL to check
   * @param string $filename Filename to match
   * @return bool True if URL has the same filename
   */
  private function url_has_same_filename($url, $filename)
  {
    // Skip if URL is actually JSON data
    if (strlen($url) > 500 || strpos($url, '%5B') !== false || strpos($url, '%7B') !== false) {
      return false;
    }

    // Must be a valid URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      return false;
    }

    // Extract filename from URL
    $url_filename = basename(parse_url($url, PHP_URL_PATH));

    // Remove size suffixes like -150x150, -300x200 from both
    $clean_url_filename = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $url_filename);
    $clean_filename = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $filename);

    // Also remove numbered suffixes like -1, -2
    $clean_url_filename = preg_replace('/-\d+(\.[^.]+)$/', '$1', $clean_url_filename);
    $clean_filename = preg_replace('/-\d+(\.[^.]+)$/', '$1', $clean_filename);

    return ($clean_url_filename === $clean_filename || $url_filename === $filename);
  }

  /**
   * Check if a URL needs domain updating
   *
   * @param string $url URL to check
   * @return bool True if URL needs domain update
   */
  private function needs_domain_update($url)
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
   * Update URL domain to match current site
   *
   * @param string $url URL to update
   * @return string Updated URL
   */
  private function update_url_domain($url)
  {
    $parsed_url = parse_url($url);
    $current_site_url = site_url();
    $current_parsed = parse_url($current_site_url);

    if (!$parsed_url || !$current_parsed) {
      return $url;
    }

    // Build new URL with current domain
    $scheme = !empty($current_parsed['scheme']) ? $current_parsed['scheme'] : 'https';
    $host = !empty($current_parsed['host']) ? $current_parsed['host'] : '';
    $port = !empty($current_parsed['port']) ? ':' . $current_parsed['port'] : '';
    $path = !empty($parsed_url['path']) ? $parsed_url['path'] : '';
    $query = !empty($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
    $fragment = !empty($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

    return $scheme . '://' . $host . $port . $path . $query . $fragment;
  }

  /**
   * Update all domain URLs in pattern content
   *
   * @param string $content Pattern content
   * @return string Updated content with corrected domains
   */
  private function update_pattern_domains($content)
  {
    $this->log('INFO', 'Starting comprehensive domain update for pattern content');

    $blocks = parse_blocks($content);
    $this->update_blocks_domains($blocks);

    // Serialize blocks back to content
    $updated_content = serialize_blocks($blocks);
    $updated_content = $this->fix_serialization_corruption($updated_content);

    if ($updated_content !== $content) {
      $this->log('INFO', 'Pattern content domains were updated');
    }

    return $updated_content;
  }

  /**
   * Recursively update domains in all blocks
   *
   * @param array $blocks Array of blocks (passed by reference)
   */
  private function update_blocks_domains(&$blocks)
  {
    foreach ($blocks as &$block) {
      // Handle nested blocks
      if (!empty($block['innerBlocks'])) {
        $this->update_blocks_domains($block['innerBlocks']);
      }

      // Update block attributes
      if (!empty($block['attrs'])) {
        $this->update_block_attrs_domains($block['attrs']);
      }

      // Update innerHTML content
      if (!empty($block['innerHTML'])) {
        $block['innerHTML'] = $this->update_html_domains($block['innerHTML']);
      }
    }
  }

  /**
   * Update domains in block attributes
   *
   * @param array $attrs Block attributes (passed by reference)
   */
  private function update_block_attrs_domains(&$attrs)
  {
    foreach ($attrs as $key => &$value) {
      if (is_string($value)) {
        // Handle JSON-encoded strings
        if ($this->is_json_string($value)) {
          $json_data = json_decode($value, true);
          if (json_last_error() === JSON_ERROR_NONE) {
            if ($this->update_json_domains($json_data)) {
              $value = wp_json_encode($json_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
              $this->log('DEBUG', sprintf('Updated domains in JSON attr %s', $key));
            }
          }
        } elseif ($this->is_urlencoded_json($value)) {
          // Handle URL-encoded JSON
          $decoded_value = urldecode($value);
          $json_data = json_decode($decoded_value, true);
          if (json_last_error() === JSON_ERROR_NONE) {
            if ($this->update_json_domains($json_data)) {
              $updated_json = wp_json_encode($json_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
              $value = urlencode($updated_json);
              $this->log('DEBUG', sprintf('Updated domains in URL-encoded JSON attr %s', $key));
            }
          }
        } elseif ($this->needs_domain_update($value)) {
          // Handle simple URLs
          $old_value = $value;
          $value = $this->update_url_domain($value);
          if ($value !== $old_value) {
            $this->log('DEBUG', sprintf('Updated domain in attr %s: %s -> %s', $key, substr($old_value, 0, 50), substr($value, 0, 50)));
          }
        }
      } elseif (is_array($value)) {
        // Recursively process arrays
        $this->update_json_domains($value);
      }
    }
  }

  /**
   * Update domains in JSON data structures
   *
   * @param array $data JSON data (passed by reference)
   * @return bool True if any updates were made
   */
  private function update_json_domains(&$data)
  {
    $updated = false;

    if (is_array($data)) {
      foreach ($data as $key => &$item) {
        if (is_array($item)) {
          if ($this->update_json_domains($item)) {
            $updated = true;
          }
        } elseif (is_string($item)) {
          // Handle direct URLs
          if ($this->needs_domain_update($item)) {
            $old_item = $item;
            $item = $this->update_url_domain($item);
            if ($item !== $old_item) {
              $this->log('DEBUG', sprintf('Updated domain in JSON key "%s": %s -> %s', $key, substr($old_item, 0, 50), substr($item, 0, 50)));
              $updated = true;
            }
          }
          // Handle escaped HTML content that contains URLs
          elseif (strpos($item, 'wp-content/uploads/') !== false) {
            $old_item = $item;
            $current_site_url = site_url();
            $current_domain = parse_url($current_site_url, PHP_URL_HOST);

            // Replace any domain in wp-content/uploads URLs with current domain
            $item = preg_replace(
              '#(https?://)([^/\s"\'\\\\]+)(/wp-content/uploads/)#',
              '$1' . $current_domain . '$3',
              $item
            );

            if ($item !== $old_item) {
              $this->log('DEBUG', sprintf('Updated escaped domain URLs in JSON key "%s"', $key));
              $updated = true;
            }
          }
          // Handle WordPress attachment permalink URLs (like /?attachment_id=123)
          elseif (strpos($item, '?attachment_id=') !== false || strpos($item, '/?attachment_id=') !== false) {
            $old_item = $item;
            $current_site_url = site_url();
            $current_domain = parse_url($current_site_url, PHP_URL_HOST);
            $current_scheme = parse_url($current_site_url, PHP_URL_SCHEME);

            // Replace domain in attachment links
            $item = preg_replace(
              '#(https?://)([^/\s"\'\\\\]+)(/?\?attachment_id=)#',
              $current_scheme . '://' . $current_domain . '$3',
              $item
            );

            if ($item !== $old_item) {
              $this->log('DEBUG', sprintf('Updated attachment link domain in JSON key "%s"', $key));
              $updated = true;
            }
          }
        }
      }
    }

    return $updated;
  }

  /**
   * Update attachment IDs in JSON data after image import
   *
   * @param array $data JSON data (passed by reference)
   * @param array $image_map Image mapping array (filename => attachment_id)
   * @return bool True if any updates were made
   */
  private function update_json_attachment_ids(&$data, $image_map)
  {
    $updated = false;

    if (is_array($data)) {
      foreach ($data as $key => &$item) {
        if (is_array($item)) {
          if ($this->update_json_attachment_ids($item, $image_map)) {
            $updated = true;
          }
        } elseif ($key === 'id' && is_numeric($item)) {
          // Look for a 'url' field in the same object to determine the filename
          $url_field = $this->find_url_field_in_object($data);
          if ($url_field && isset($data[$url_field])) {
            $image_url = $data[$url_field];
            $filename = basename(parse_url($image_url, PHP_URL_PATH));

            // Find matching attachment ID in image map
            foreach ($image_map as $mapped_filename => $new_attachment_id) {
              if ($this->filename_matches($filename, $mapped_filename)) {
                $old_id = $item;
                $item = $new_attachment_id;
                $this->log('DEBUG', sprintf('Updated attachment ID: %d -> %d for %s', $old_id, $new_attachment_id, $filename));
                $updated = true;
                break;
              }
            }
          }
        }
      }
    }

    return $updated;
  }

  /**
   * Find URL field in the same JSON object
   *
   * @param array $data JSON object data
   * @return string|null URL field key if found
   */
  private function find_url_field_in_object($data)
  {
    $url_fields = ['url', 'src', 'href', 'link'];

    foreach ($url_fields as $field) {
      if (isset($data[$field]) && is_string($data[$field]) && filter_var($data[$field], FILTER_VALIDATE_URL)) {
        return $field;
      }
    }

    return null;
  }

  /**
   * Update attachment IDs in blocks
   *
   * @param array $blocks Array of blocks (passed by reference)
   * @param array $image_map Image mapping array
   * @param bool $updated Reference to track if any updates were made
   */
  private function update_attachment_ids_in_blocks(&$blocks, $image_map, &$updated)
  {
    foreach ($blocks as &$block) {
      // Handle nested blocks
      if (!empty($block['innerBlocks'])) {
        $this->update_attachment_ids_in_blocks($block['innerBlocks'], $image_map, $updated);
      }

      // Update LazyBlocks attributes
      if (!empty($block['attrs']) && strpos($block['blockName'], 'lazyblock/') === 0) {
        foreach ($block['attrs'] as $key => &$value) {
          if (is_string($value)) {
            // Handle JSON-encoded strings
            if ($this->is_json_string($value)) {
              $json_data = json_decode($value, true);
              if (json_last_error() === JSON_ERROR_NONE) {
                if ($this->update_json_attachment_ids($json_data, $image_map)) {
                  $value = wp_json_encode($json_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                  $this->log('DEBUG', sprintf('Updated attachment IDs in LazyBlock JSON attr %s', $key));
                  $updated = true;
                }
              }
            } elseif ($this->is_urlencoded_json($value)) {
              // Handle URL-encoded JSON
              $decoded_value = urldecode($value);
              $json_data = json_decode($decoded_value, true);
              if (json_last_error() === JSON_ERROR_NONE) {
                if ($this->update_json_attachment_ids($json_data, $image_map)) {
                  $updated_json = wp_json_encode($json_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                  $value = urlencode($updated_json);
                  $this->log('DEBUG', sprintf('Updated attachment IDs in LazyBlock URL-encoded JSON attr %s', $key));
                  $updated = true;
                }
              }
            }
          } elseif (is_array($value)) {
            // Recursively process arrays
            if ($this->update_json_attachment_ids($value, $image_map)) {
              $this->log('DEBUG', sprintf('Updated attachment IDs in LazyBlock array attr %s', $key));
              $updated = true;
            }
          }
        }
      }
    }
  }

  /**
   * Update domains in HTML content
   *
   * @param string $html HTML content
   * @return string Updated HTML content
   */
  private function update_html_domains($html)
  {
    // Update any URLs in HTML attributes
    $html = preg_replace_callback(
      '/\b(src|href|data-[a-zA-Z-]+)\s*=\s*["\']([^"\']*\/wp-content\/uploads\/[^"\']*)["\']/',
      function ($matches) {
        $attr = $matches[1];
        $url = $matches[2];
        if ($this->needs_domain_update($url)) {
          $new_url = $this->update_url_domain($url);
          $this->log('DEBUG', sprintf('Updated domain in HTML %s: %s -> %s', $attr, substr($url, 0, 50), substr($new_url, 0, 50)));
          return $attr . '="' . esc_attr($new_url) . '"';
        }
        return $matches[0];
      },
      $html
    );

    return $html;
  }

  /**
   * Check if a string is JSON-encoded
   *
   * @param string $string String to check
   * @return bool True if string is JSON
   */
  private function is_json_string($string)
  {
    return is_string($string) &&
           strlen($string) > 2 &&
           (($string[0] === '{' && substr($string, -1) === '}') ||
            ($string[0] === '[' && substr($string, -1) === ']'));
  }

  /**
   * Check if a string is URL-encoded JSON
   *
   * @param string $string String to check
   * @return bool True if string is URL-encoded JSON
   */
  private function is_urlencoded_json($string)
  {
    return is_string($string) &&
           (strpos($string, '%5B') !== false || strpos($string, '%7B') !== false);
  }

  /**
   * Update LazyBlock image attributes
   *
   * @param array $attrs Block attributes (passed by reference)
   * @param string $old_filename Original filename
   * @param int $attachment_id New attachment ID
   * @param string $new_url New URL
   * @return bool True if any attributes were updated
   */
  private function update_lazyblock_image_attrs(&$attrs, $old_filename, $attachment_id, $new_url)
  {
    $updated = false;

    foreach ($attrs as $key => &$value) {
      if (is_string($value)) {
        // Skip if this looks like JSON data (don't treat JSON as a direct URL)
        if (strpos($value, '%5B') === 0 || strpos($value, '%7B') === 0 || strpos($value, '[') === 0 || strpos($value, '{') === 0) {
          // This is JSON data, handle it in the JSON section below
          continue;
        }

        // Direct URL update (only for simple string URLs)
        if ($this->filename_matches($value, $old_filename)) {
          $value = $new_url;
          $this->log('INFO', sprintf('Updated LazyBlock attr %s: %s -> %s', $key, $old_filename, $new_url));
          $updated = true;
        }

        // JSON string update (try both encoded and non-encoded)
        if (strpos($value, '{') !== false || strpos($value, '[') !== false || strpos($value, '%5B') !== false || strpos($value, '%7B') !== false) {
          // First try direct JSON decode
          $json_data = json_decode($value, true);
          if (json_last_error() === JSON_ERROR_NONE) {
            if ($this->update_json_image_refs($json_data, $old_filename, $attachment_id, $new_url)) {
              $value = wp_json_encode($json_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
              $this->log('INFO', sprintf('Updated LazyBlock JSON attr %s', $key));
              $updated = true;
            }
          } else {
            // Try URL decoding first, then JSON decode
            $decoded_value = urldecode($value);
            $json_data = json_decode($decoded_value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
              $this->log('DEBUG', sprintf('Processing URL-decoded JSON in LazyBlock attr %s', $key));
              if ($this->update_json_image_refs($json_data, $old_filename, $attachment_id, $new_url)) {
                // Re-encode using URL encoding to maintain compatibility
                $updated_json = wp_json_encode($json_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $value = urlencode($updated_json);
                $this->log('INFO', sprintf('Updated LazyBlock URL-encoded JSON attr %s', $key));
                $updated = true;
              }
            } else {
              $this->log('DEBUG', sprintf('Failed to parse JSON in LazyBlock attr %s: %s', $key, json_last_error_msg()));
            }
          }
        }
      } elseif (is_array($value)) {
        if ($this->update_json_image_refs($value, $old_filename, $attachment_id, $new_url)) {
          $this->log('INFO', sprintf('Updated LazyBlock array attr %s', $key));
          $updated = true;
        }
      }
    }

    return $updated;
  }

  /**
   * Update image references in JSON data
   *
   * @param array $data JSON data (passed by reference)
   * @param string $old_filename Original filename
   * @param int $attachment_id New attachment ID
   * @param string $new_url New URL
   * @return bool True if any updates were made
   */
  private function update_json_image_refs(&$data, $old_filename, $attachment_id, $new_url)
  {
    $updated = false;

    if (is_array($data)) {
      foreach ($data as $key => &$item) {
        if (is_array($item)) {
          // Recursively process nested arrays
          if ($this->update_json_image_refs($item, $old_filename, $attachment_id, $new_url)) {
            $updated = true;
          }
        } elseif (is_string($item)) {
          // Check if this string contains an image URL
          if ($this->filename_matches($item, $old_filename)) {
            $this->log('DEBUG', sprintf('Found matching image URL in JSON key "%s": %s', $key, substr($item, 0, 100)));
            $item = $new_url;
            $this->log('DEBUG', sprintf('Updated JSON key "%s": %s -> %s', $key, $old_filename, $new_url));
            $updated = true;
          }
          // Also update URLs with different domains but same filename
          elseif ($this->url_has_same_filename($item, $old_filename)) {
            $this->log('DEBUG', sprintf('Found URL with same filename in JSON key "%s": %s', $key, substr($item, 0, 100)));
            $item = $new_url;
            $this->log('DEBUG', sprintf('Updated domain in JSON key "%s": %s', $key, $new_url));
            $updated = true;
          }
          // Update URLs inside HTML content (like description.rendered)
          elseif (strpos($item, 'wp-content/uploads/') !== false && strpos($item, $old_filename) !== false) {
            $old_item = $item;

            // Update all instances of the filename in HTML content
            $old_url_pattern = '#(https?://[^/\s"\']*)?/wp-content/uploads/[^/\s"\']*' . preg_quote($old_filename, '#') . '#';
            $item = preg_replace($old_url_pattern, $new_url, $item);

            if ($item !== $old_item) {
              $this->log('DEBUG', sprintf('Updated URLs in HTML content for JSON key "%s"', $key));
              $updated = true;
            }
          }
          // Check for any URL with the wrong domain that needs updating
          elseif ($this->needs_domain_update($item)) {
            $old_item = $item;
            $item = $this->update_url_domain($item);
            if ($item !== $old_item) {
              $this->log('DEBUG', sprintf('Updated domain in JSON key "%s": %s -> %s', $key, substr($old_item, 0, 50), substr($item, 0, 50)));
              $updated = true;
            }
          }
          // Also check for attachment IDs and update them
          elseif ($key === 'id' && is_numeric($item)) {
            // This might be an attachment ID, check if it matches
            $current_url = wp_get_attachment_url($item);
            if ($current_url && $this->filename_matches($current_url, $old_filename)) {
              $this->log('DEBUG', sprintf('Found matching attachment ID in JSON: %s -> %s', $item, $attachment_id));
              $item = $attachment_id;
              $updated = true;
            }
          }
        }
      }
    }

    return $updated;
  }

  /**
   * Check if LazyBlock HTML needs fixing
   *
   * @param array $attrs Block attributes
   * @param string $innerHTML Current HTML
   * @param int $attachment_id Expected attachment ID
   * @return bool True if HTML needs fixing
   */
  private function lazyblock_needs_html_fix($attrs, $innerHTML, $attachment_id)
  {
    // Check if there are img tags without src
    if (strpos($innerHTML, '<img') !== false && strpos($innerHTML, 'src=') === false) {
      return true;
    }

    // Check if we can find the attachment URL in attributes but it's missing from HTML
    foreach ($attrs as $key => $value) {
      if (is_string($value) && strpos($value, 'wp-content/uploads/') !== false) {
        // This looks like an image URL in attributes
        if (strpos($innerHTML, $value) === false) {
          return true; // URL in attrs but not in HTML
        }
      }
    }

    return false;
  }

  /**
   * Fix missing src attributes in LazyBlock HTML
   *
   * @param string $innerHTML Current HTML
   * @param array $attrs Block attributes
   * @param int $attachment_id Attachment ID
   * @param string $new_url New image URL
   * @return string Fixed HTML
   */
  private function fix_lazyblock_missing_src($innerHTML, $attrs, $attachment_id, $new_url)
  {
    // If there are img tags without src, try to add them
    if (strpos($innerHTML, '<img') !== false && strpos($innerHTML, 'src=') === false) {
      // Find img tags and add src attribute
      $innerHTML = preg_replace(
        '/<img([^>]*?)>/i',
        '<img$1 src="' . esc_attr($new_url) . '">',
        $innerHTML
      );

      $this->log('INFO', sprintf('Added missing src attribute to LazyBlock img tag: %s', $new_url));
    }

    // Look for img tags with empty or placeholder src
    $innerHTML = preg_replace(
      '/src="[^"]*placeholder[^"]*"/i',
      'src="' . esc_attr($new_url) . '"',
      $innerHTML
    );

    $innerHTML = preg_replace(
      '/src=""/i',
      'src="' . esc_attr($new_url) . '"',
      $innerHTML
    );

    return $innerHTML;
  }

  /**
   * Get pattern by title using WP_Query (replacement for deprecated get_page_by_title)
   *
   * @param string $title Pattern title to search for
   * @return WP_Post|null Pattern post object or null if not found
   */
  private function get_pattern_by_title($title)
  {
    $query = new WP_Query(array(
      'post_type' => 'wp_block',
      'post_status' => 'publish',
      'title' => $title,
      'posts_per_page' => 1,
      'no_found_rows' => true,
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false
    ));

    if ($query->have_posts()) {
      return $query->posts[0];
    }

    return null;
  }

  /**
   * Validate that image changes were preserved after serialization
   *
   * @param array $blocks Parsed blocks to validate
   * @param array $image_map Image mapping array
   * @return bool True if changes were preserved
   */
  private function validate_image_changes($blocks, $image_map)
  {
    foreach ($blocks as $block) {
      if ($block['blockName'] === 'core/image') {
        // Check if any image block still has broken HTML
        if (!empty($block['attrs']['id']) && !empty($block['attrs']['url'])) {
          $attachment_id = $block['attrs']['id'];

          // Check if HTML matches the attributes
          if (empty($block['innerHTML']) || strpos($block['innerHTML'], 'src=') === false) {
            $this->log('WARNING', sprintf('Validation failed: Block ID %d missing src attribute', $attachment_id));
            return false;
          }

          if (preg_match('/wp-image-(\d+)/', $block['innerHTML'], $matches) && $matches[1] != $attachment_id) {
            $this->log('WARNING', sprintf('Validation failed: Block ID %d has wrong class wp-image-%s', $attachment_id, $matches[1]));
            return false;
          }
        }
      }

      // Check inner blocks
      if (!empty($block['innerBlocks'])) {
        if (!$this->validate_image_changes($block['innerBlocks'], $image_map)) {
          return false;
        }
      }
    }

    return true;
  }

  /**
   * Manually reconstruct block content without using serialize_blocks()
   *
   * @param array $blocks Array of blocks
   * @return string Manually constructed content
   */
  private function manually_reconstruct_content($blocks)
  {
    $content_parts = array();

    foreach ($blocks as $block) {
      $content_parts[] = $this->reconstruct_single_block($block);
    }

    return implode("\n\n", $content_parts);
  }

  /**
   * Reconstruct a single block manually
   *
   * @param array $block Block array
   * @return string Block content
   */
  private function reconstruct_single_block($block)
  {
    if (empty($block['blockName'])) {
      // Text block
      return $block['innerHTML'] ?? '';
    }

    // Build block comment
    $attrs_json = '';
    if (!empty($block['attrs'])) {
      $attrs_json = ' ' . wp_json_encode($block['attrs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $block_start = '<!-- wp:' . $block['blockName'] . $attrs_json . ' -->';
    $block_end = '<!-- /wp:' . $block['blockName'] . ' -->';

    $inner_content = $block['innerHTML'] ?? '';

    // Handle inner blocks
    if (!empty($block['innerBlocks'])) {
      $inner_blocks_content = array();
      foreach ($block['innerBlocks'] as $inner_block) {
        $inner_blocks_content[] = $this->reconstruct_single_block($inner_block);
      }
      $inner_content .= implode("\n", $inner_blocks_content);
    }

    return $block_start . "\n" . $inner_content . "\n" . $block_end;
  }

  /**
   * Fix WordPress serialize_blocks() corruption issues
   *
   * @param string $content Serialized block content
   * @return string Fixed content
   */
  private function fix_serialization_corruption($content)
  {
    // Fix newline corruption that WordPress serialize_blocks() can cause
    // This is especially common with Japanese text and complex HTML
    $original_content = $content;

    // IMPORTANT: Skip newline conversion for LazyBlocks to prevent content corruption
    // LazyBlocks use \\n in JSON attributes which should not be converted to actual newlines
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

    if ($content !== $original_content) {
      $this->log('INFO', 'Fixed serialization corruption in pattern content (LazyBlocks protected)');
    }

    return $content;
  }

  /**
   * Update synced pattern references in post content
   *
   * @param string $content Post content with block references
   * @param array $pattern_reference_map Map of old pattern ID to new pattern ID
   * @return string Updated content
   */
  public function update_pattern_references($content, $pattern_reference_map)
  {
    if (empty($pattern_reference_map)) {
      return $content;
    }

    $this->log('INFO', sprintf('Updating pattern references with %d mappings', count($pattern_reference_map)));

    $blocks = parse_blocks($content);

    // Check for any core/block references in the content before processing
    $block_refs_found = $this->find_block_references_in_content($content);
    if (!empty($block_refs_found)) {
      $this->log('INFO', sprintf('Found %d block references in content: %s', count($block_refs_found), implode(', ', $block_refs_found)));
    } else {
      $this->log('WARNING', 'No block references found in content');
    }

    $updated = $this->update_blocks_pattern_references($blocks, $pattern_reference_map);

    if ($updated) {
      $new_content = serialize_blocks($blocks);
      $new_content = $this->fix_serialization_corruption($new_content);
      $this->log('INFO', 'Pattern references updated successfully');
      return $new_content;
    } else {
      $this->log('WARNING', 'No pattern references were updated');
    }

    return $content;
  }

  /**
   * Find block references in content for debugging
   *
   * @param string $content Post content
   * @return array Array of found reference IDs
   */
  private function find_block_references_in_content($content)
  {
    $refs = array();

    // Look for wp:block with ref attribute using regex
    if (preg_match_all('/<!-- wp:block[^>]*"ref":(\d+)/', $content, $matches)) {
      $refs = array_unique($matches[1]);
    }

    // Also check for core/block in serialized format
    if (preg_match_all('/"blockName":"core\/block"[^}]*"ref":(\d+)/', $content, $matches)) {
      $refs = array_merge($refs, array_unique($matches[1]));
    }

    return array_unique($refs);
  }

  /**
   * Update pattern references in blocks recursively
   *
   * @param array $blocks Array of blocks (passed by reference)
   * @param array $pattern_reference_map Reference mapping
   * @return bool True if any updates were made
   */
  private function update_blocks_pattern_references(&$blocks, $pattern_reference_map)
  {
    $updated = false;

    foreach ($blocks as &$block) {
      // Handle wp:block references (synced patterns)
      if ($block['blockName'] === 'core/block' && !empty($block['attrs']['ref'])) {
        $old_ref = (int)$block['attrs']['ref'];

        if (isset($pattern_reference_map[$old_ref])) {
          $new_ref = $pattern_reference_map[$old_ref];
          $block['attrs']['ref'] = $new_ref;
          $updated = true;

          $this->log('INFO', sprintf('Updated block reference: %d -> %d', $old_ref, $new_ref));

          // Also update innerHTML if it contains the old reference
          if (!empty($block['innerHTML'])) {
            $old_innerHTML = $block['innerHTML'];
            $block['innerHTML'] = str_replace(
              'wp-block-' . $old_ref,
              'wp-block-' . $new_ref,
              $block['innerHTML']
            );
            if ($block['innerHTML'] !== $old_innerHTML) {
              $this->log('DEBUG', 'Updated innerHTML as well');
            }
          }
        } else {
          $this->log('WARNING', sprintf('No mapping found for block reference %d', $old_ref));
        }
      } else if ($block['blockName'] === 'core/block') {
      }

      // Handle inner blocks recursively
      if (!empty($block['innerBlocks'])) {
        if ($this->update_blocks_pattern_references($block['innerBlocks'], $pattern_reference_map)) {
          $updated = true;
        }
      }
    }

    return $updated;
  }

  /**
   * Load pattern reference mapping from export directory
   *
   * @param string $export_dir Export directory path
   * @return array Pattern reference mapping or empty array
   */
  public function load_pattern_reference_mapping($export_dir)
  {
    // Check for pattern-refs.json in multiple locations
    $possible_locations = array(
      $export_dir . '/synced-patterns/pattern-refs.json', // Synced pattern export
      $export_dir . '/pattern-refs.json' // Post export
    );

    $refs_file = null;
    foreach ($possible_locations as $location) {
      if (file_exists($location)) {
        $refs_file = $location;
        break;
      }
    }

    if (!$refs_file) {
      $this->log('INFO', 'No pattern reference mapping file found');
      return array();
    }

    $refs_content = file_get_contents($refs_file);
    if ($refs_content === false) {
      $this->log('WARNING', 'Failed to read pattern reference mapping file');
      return array();
    }

    $refs_data = json_decode($refs_content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $this->log('WARNING', 'Failed to parse pattern reference mapping: ' . json_last_error_msg());
      return array();
    }

    $this->log('INFO', sprintf('Loaded pattern reference mapping with %d entries', count($refs_data)));
    return $refs_data;
  }

  /**
   * Save pattern reference mapping to WordPress options
   *
   * @param array $pattern_reference_map Pattern reference mapping
   */
  public function save_pattern_reference_mapping($pattern_reference_map)
  {
    $option_name = 'ipbmfz_pattern_reference_map';

    // Get existing mapping and merge with new one
    $existing_mapping = get_option($option_name, array());

    // Ensure all keys are integers for consistency
    $normalized_new_mapping = array();
    foreach ($pattern_reference_map as $old_id => $new_id) {
      $normalized_new_mapping[(int)$old_id] = (int)$new_id;
    }

    $merged_mapping = array_merge($existing_mapping, $normalized_new_mapping);

    // Save to WordPress options table
    update_option($option_name, $merged_mapping);

    $this->log('INFO', sprintf(
      'Saved pattern reference mapping: %d new mappings (total: %d)',
      count($normalized_new_mapping),
      count($merged_mapping)
    ));
  }

  /**
   * Get saved pattern reference mapping
   *
   * @return array Saved pattern reference mapping
   */
  public function get_saved_pattern_reference_mapping()
  {
    $option_name = 'ipbmfz_pattern_reference_map';
    $mapping = get_option($option_name, array());

    $this->log('INFO', sprintf('Retrieved saved pattern reference mapping: %d mappings', count($mapping)));

    if (empty($mapping)) {
      $this->log('WARNING', 'No pattern reference mapping found in database');
    }

    return $mapping;
  }

  /**
   * Clear saved pattern reference mapping
   */
  public function clear_pattern_reference_mapping()
  {
    $option_name = 'ipbmfz_pattern_reference_map';
    delete_option($option_name);
    $this->log('INFO', 'Cleared saved pattern reference mapping');
  }

  /**
   * Get pattern reference mapping info for admin display
   *
   * @return array Formatted mapping info
   */
  public function get_pattern_mapping_info()
  {
    $mapping = $this->get_saved_pattern_reference_mapping();
    $info = array(
      'total_mappings' => count($mapping),
      'mappings' => array()
    );

    foreach ($mapping as $old_id => $new_id) {
      $new_pattern = get_post($new_id);
      $info['mappings'][] = array(
        'old_id' => $old_id,
        'new_id' => $new_id,
        'title' => $new_pattern ? $new_pattern->post_title : __('パターンが見つかりません', 'wp-single-post-migrator'),
        'status' => $new_pattern && $new_pattern->post_status === 'publish' ? 'active' : 'inactive'
      );
    }

    return $info;
  }

  /**
   * Create pattern reference mapping for post import
   *
   * @param array $old_patterns Original pattern data from export
   * @param array $import_results Results from pattern import
   * @return array Pattern reference mapping (old_id => new_id)
   */
  public function create_pattern_reference_mapping($old_patterns, $import_results)
  {
    $mapping = array();

    if (isset($import_results['pattern_reference_map'])) {
      return $import_results['pattern_reference_map'];
    }

    // Try to get saved mapping first
    $saved_mapping = $this->get_saved_pattern_reference_mapping();
    if (!empty($saved_mapping)) {
      // Filter saved mapping to only include patterns from the current export
      foreach ($old_patterns as $old_id => $old_data) {
        if (isset($saved_mapping[$old_id])) {
          $mapping[$old_id] = $saved_mapping[$old_id];
          $this->log('INFO', sprintf('Using saved mapping: %d -> %d ("%s")', $old_id, $saved_mapping[$old_id], $old_data['title']));
        }
      }
    }

    // Fallback: try to match by title/slug for unmapped patterns
    foreach ($old_patterns as $old_id => $old_data) {
      if (!isset($mapping[$old_id])) {
        $pattern = $this->get_pattern_by_title($old_data['title']);
        if ($pattern) {
          $mapping[$old_id] = $pattern->ID;
          $this->log('INFO', sprintf('Mapped pattern by title: %d -> %d ("%s")', $old_id, $pattern->ID, $old_data['title']));
        }
      }
    }

    return $mapping;
  }

  /**
   * Log message with prefix
   *
   * @param string $level Log level
   * @param string $message Message to log
   */
  private function log($level, $message)
  {
    if (defined('WP_DEBUG') && WP_DEBUG) {
      error_log(sprintf('[%s] Synced Pattern Handler - %s: %s', date('Y-m-d H:i:s'), $level, $message));
    }
  }
}