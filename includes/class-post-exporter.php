<?php
/**
 * Post Exporter Class
 *
 * Handles exporting WordPress posts with images to ZIP format
 */

if (!defined('ABSPATH')) {
    exit;
}

class IPBMFZ_Post_Exporter {

    /**
     * Block updater instance for image detection
     */
    private $block_updater;

    /**
     * ZIP handler instance
     */
    private $zip_handler;

    /**
     * Constructor
     */
    public function __construct() {
        $this->block_updater = new IPBMFZ_Block_Updater();
        $this->zip_handler = new IPBMFZ_ZIP_Handler();
    }

    /**
     * Export post with images to ZIP
     *
     * @param int $post_id Post ID to export
     * @param array $options Export options
     * @return array|WP_Error Export result with file path or error
     */
    public function export_post($post_id, $options = array()) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error(
                'post_not_found',
                __('記事が見つかりません。', 'import-post-block-media-from-zip')
            );
        }

        $default_options = array(
            'include_images' => true,
            'include_meta' => true,
            'export_format' => 'wxr'
        );
        $options = array_merge($default_options, $options);

        try {
            $this->log('INFO', "Starting export for post ID {$post_id}");

            // Create temporary directory
            $temp_dir = $this->create_temp_directory($post_id);
            if (is_wp_error($temp_dir)) {
                return $temp_dir;
            }

            // Generate XML export
            $xml_result = $this->generate_post_xml($post, $temp_dir, $options);
            if (is_wp_error($xml_result)) {
                $this->cleanup_temp_directory($temp_dir);
                return $xml_result;
            }

            $exported_files = array($xml_result['xml_file']);
            $image_count = 0;

            // Collect and copy images if requested
            if ($options['include_images']) {
                $images_result = $this->collect_post_images($post, $temp_dir);
                if (is_wp_error($images_result)) {
                    $this->cleanup_temp_directory($temp_dir);
                    return $images_result;
                }

                $exported_files = array_merge($exported_files, $images_result['copied_files']);
                $image_count = count($images_result['copied_files']);
            }

            // Create ZIP file
            $zip_result = $this->create_export_zip($temp_dir, $post);
            if (is_wp_error($zip_result)) {
                $this->cleanup_temp_directory($temp_dir);
                return $zip_result;
            }

            // Cleanup temp directory
            $this->cleanup_temp_directory($temp_dir);

            $this->log('INFO', "Export completed for post ID {$post_id}. Images: {$image_count}");

            return array(
                'zip_file' => $zip_result['zip_path'],
                'zip_url' => $zip_result['zip_url'],
                'post_title' => $post->post_title,
                'image_count' => $image_count,
                'xml_file' => basename($xml_result['xml_file']),
                'file_size' => filesize($zip_result['zip_path'])
            );

        } catch (Exception $e) {
            if (isset($temp_dir)) {
                $this->cleanup_temp_directory($temp_dir);
            }
            $this->log('ERROR', 'Export exception: ' . $e->getMessage());
            return new WP_Error(
                'export_exception',
                sprintf(__('エクスポート中にエラーが発生しました: %s', 'import-post-block-media-from-zip'), $e->getMessage())
            );
        }
    }

    /**
     * Generate XML export for the post
     *
     * @param WP_Post $post Post object
     * @param string $temp_dir Temporary directory
     * @param array $options Export options
     * @return array|WP_Error XML generation result
     */
    private function generate_post_xml($post, $temp_dir, $options) {
        $xml_filename = sanitize_file_name($post->post_title . '-' . $post->ID . '.xml');
        $xml_path = $temp_dir . '/' . $xml_filename;

        if ($options['export_format'] === 'wxr') {
            $xml_content = $this->generate_wxr_xml($post, $options);
        } else {
            $xml_content = $this->generate_simple_xml($post, $options);
        }

        if (file_put_contents($xml_path, $xml_content) === false) {
            return new WP_Error(
                'xml_write_failed',
                __('XMLファイルの書き込みに失敗しました。', 'import-post-block-media-from-zip')
            );
        }

        return array(
            'xml_file' => $xml_path,
            'xml_content' => $xml_content
        );
    }

    /**
     * Generate WXR (WordPress eXtended RSS) format XML
     *
     * @param WP_Post $post Post object
     * @param array $options Export options
     * @return string XML content
     */
    private function generate_wxr_xml($post, $options) {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        // RSS root element
        $rss = $xml->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:excerpt', 'http://wordpress.org/export/1.2/excerpt/');
        $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
        $rss->setAttribute('xmlns:wfw', 'http://wellformedweb.org/CommentAPI/');
        $rss->setAttribute('xmlns:dc', 'http://purl.org/dc/elements/1.1/');
        $rss->setAttribute('xmlns:wp', 'http://wordpress.org/export/1.2/');
        $xml->appendChild($rss);

        // Channel element
        $channel = $xml->createElement('channel');
        $rss->appendChild($channel);

        // Channel info
        $channel->appendChild($xml->createElement('title', get_bloginfo('name')));
        $channel->appendChild($xml->createElement('link', get_bloginfo('url')));
        $channel->appendChild($xml->createElement('description', get_bloginfo('description')));
        $channel->appendChild($xml->createElement('language', get_bloginfo('language')));
        $channel->appendChild($xml->createElement('wp:wxr_version', '1.2'));
        $channel->appendChild($xml->createElement('wp:base_site_url', get_site_url()));
        $channel->appendChild($xml->createElement('wp:base_blog_url', get_bloginfo('url')));

        // Generator
        $generator = $xml->createElement('generator');
        $generator->appendChild($xml->createCDATASection('Import Post Block Media from ZIP v' . IPBMFZ_VERSION));
        $channel->appendChild($generator);

        // Post item
        $item = $xml->createElement('item');
        $channel->appendChild($item);

        // Post data
        $item->appendChild($xml->createElement('title', htmlspecialchars($post->post_title)));
        $item->appendChild($xml->createElement('link', get_permalink($post->ID)));
        $item->appendChild($xml->createElement('pubDate', mysql2date('D, d M Y H:i:s +0000', $post->post_date_gmt)));
        $item->appendChild($xml->createElement('dc:creator', get_the_author_meta('login', $post->post_author)));
        $item->appendChild($xml->createElement('guid', get_permalink($post->ID)));
        $item->appendChild($xml->createElement('description'));

        // Post content
        $content = $xml->createElement('content:encoded');
        $content->appendChild($xml->createCDATASection($post->post_content));
        $item->appendChild($content);

        // Post excerpt
        $excerpt = $xml->createElement('excerpt:encoded');
        $excerpt->appendChild($xml->createCDATASection($post->post_excerpt));
        $item->appendChild($excerpt);

        // WordPress specific fields
        $item->appendChild($xml->createElement('wp:post_id', $post->ID));
        $item->appendChild($xml->createElement('wp:post_date', $post->post_date));
        $item->appendChild($xml->createElement('wp:post_date_gmt', $post->post_date_gmt));
        $item->appendChild($xml->createElement('wp:comment_status', $post->comment_status));
        $item->appendChild($xml->createElement('wp:ping_status', $post->ping_status));
        $item->appendChild($xml->createElement('wp:post_name', $post->post_name));
        $item->appendChild($xml->createElement('wp:status', $post->post_status));
        $item->appendChild($xml->createElement('wp:post_parent', $post->post_parent));
        $item->appendChild($xml->createElement('wp:menu_order', $post->menu_order));
        $item->appendChild($xml->createElement('wp:post_type', $post->post_type));
        $item->appendChild($xml->createElement('wp:post_password', $post->post_password));
        $item->appendChild($xml->createElement('wp:is_sticky', is_sticky($post->ID) ? '1' : '0'));

        // Add meta fields if requested
        if ($options['include_meta']) {
            $this->add_meta_fields_to_xml($xml, $item, $post->ID);
        }

        return $xml->saveXML();
    }

    /**
     * Generate simple XML format
     *
     * @param WP_Post $post Post object
     * @param array $options Export options
     * @return string XML content
     */
    private function generate_simple_xml($post, $options) {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        // Root element
        $root = $xml->createElement('wordpress_post');
        $xml->appendChild($root);

        // Basic post data
        $root->appendChild($xml->createElement('id', $post->ID));
        $root->appendChild($xml->createElement('title', htmlspecialchars($post->post_title)));
        $root->appendChild($xml->createElement('slug', $post->post_name));
        $root->appendChild($xml->createElement('status', $post->post_status));
        $root->appendChild($xml->createElement('type', $post->post_type));
        $root->appendChild($xml->createElement('date', $post->post_date));
        $root->appendChild($xml->createElement('modified', $post->post_modified));
        $root->appendChild($xml->createElement('author', get_the_author_meta('login', $post->post_author)));

        // Content
        $content = $xml->createElement('content');
        $content->appendChild($xml->createCDATASection($post->post_content));
        $root->appendChild($content);

        // Excerpt
        $excerpt = $xml->createElement('excerpt');
        $excerpt->appendChild($xml->createCDATASection($post->post_excerpt));
        $root->appendChild($excerpt);

        // Add meta fields if requested
        if ($options['include_meta']) {
            $meta_container = $xml->createElement('meta_fields');
            $root->appendChild($meta_container);
            $this->add_meta_fields_to_simple_xml($xml, $meta_container, $post->ID);
        }

        return $xml->saveXML();
    }

    /**
     * Add meta fields to WXR XML
     *
     * @param DOMDocument $xml XML document
     * @param DOMElement $item Item element
     * @param int $post_id Post ID
     */
    private function add_meta_fields_to_xml($xml, $item, $post_id) {
        $meta_data = get_post_meta($post_id);

        foreach ($meta_data as $meta_key => $meta_values) {
            // Skip private WordPress fields
            if (strpos($meta_key, '_') === 0) {
                continue;
            }

            foreach ($meta_values as $meta_value) {
                $postmeta = $xml->createElement('wp:postmeta');
                $item->appendChild($postmeta);

                $meta_key_elem = $xml->createElement('wp:meta_key');
                $meta_key_elem->appendChild($xml->createCDATASection($meta_key));
                $postmeta->appendChild($meta_key_elem);

                $meta_value_elem = $xml->createElement('wp:meta_value');
                $meta_value_elem->appendChild($xml->createCDATASection($meta_value));
                $postmeta->appendChild($meta_value_elem);
            }
        }
    }

    /**
     * Add meta fields to simple XML
     *
     * @param DOMDocument $xml XML document
     * @param DOMElement $container Meta container element
     * @param int $post_id Post ID
     */
    private function add_meta_fields_to_simple_xml($xml, $container, $post_id) {
        $meta_data = get_post_meta($post_id);

        foreach ($meta_data as $meta_key => $meta_values) {
            // Skip private WordPress fields
            if (strpos($meta_key, '_') === 0) {
                continue;
            }

            foreach ($meta_values as $meta_value) {
                $meta_field = $xml->createElement('meta_field');
                $container->appendChild($meta_field);

                $meta_field->setAttribute('key', $meta_key);
                $meta_field->appendChild($xml->createCDATASection($meta_value));
            }
        }
    }

    /**
     * Collect all images used in the post
     *
     * @param WP_Post $post Post object
     * @param string $temp_dir Temporary directory
     * @return array|WP_Error Collection result
     */
    private function collect_post_images($post, $temp_dir) {
        $images_dir = $temp_dir . '/images';
        if (!wp_mkdir_p($images_dir)) {
            return new WP_Error(
                'images_dir_failed',
                __('画像ディレクトリの作成に失敗しました。', 'import-post-block-media-from-zip')
            );
        }

        $collected_images = array();
        $copied_files = array();

        // Parse blocks to find images
        $blocks = parse_blocks($post->post_content);
        $block_images = $this->extract_images_from_blocks($blocks);

        // Get images from meta fields
        $meta_images = $this->extract_images_from_meta($post->ID);

        // Merge all found images
        $all_images = array_merge($block_images, $meta_images);
        $all_images = array_unique($all_images);

        $this->log('INFO', 'Found ' . count($all_images) . ' images to export');

        foreach ($all_images as $image_url) {
            $copy_result = $this->copy_image_to_export($image_url, $images_dir);
            if (!is_wp_error($copy_result)) {
                $copied_files[] = $copy_result['copied_path'];
                $collected_images[] = array(
                    'original_url' => $image_url,
                    'filename' => $copy_result['filename'],
                    'copied_path' => $copy_result['copied_path']
                );
            } else {
                $this->log('WARNING', 'Failed to copy image: ' . $image_url . ' - ' . $copy_result->get_error_message());
            }
        }

        return array(
            'collected_images' => $collected_images,
            'copied_files' => $copied_files,
            'images_dir' => $images_dir
        );
    }

    /**
     * Extract images from blocks
     *
     * @param array $blocks Parsed blocks
     * @return array Array of image URLs
     */
    private function extract_images_from_blocks($blocks) {
        $images = array();

        foreach ($blocks as $block) {
            if (empty($block['blockName'])) {
                continue;
            }

            switch ($block['blockName']) {
                case 'core/image':
                    // Try to get URL from attrs first
                    if (!empty($block['attrs']['url'])) {
                        $images[] = $block['attrs']['url'];
                    }
                    // If not in attrs, extract from innerHTML
                    elseif (!empty($block['innerHTML'])) {
                        $url = $this->extract_image_url_from_html($block['innerHTML']);
                        if ($url) {
                            $images[] = $url;
                        }
                    }
                    break;

                case 'core/gallery':
                    if (!empty($block['innerBlocks'])) {
                        foreach ($block['innerBlocks'] as $inner_block) {
                            if ($inner_block['blockName'] === 'core/image') {
                                // Try to get URL from attrs first
                                if (!empty($inner_block['attrs']['url'])) {
                                    $images[] = $inner_block['attrs']['url'];
                                }
                                // If not in attrs, extract from innerHTML
                                elseif (!empty($inner_block['innerHTML'])) {
                                    $url = $this->extract_image_url_from_html($inner_block['innerHTML']);
                                    if ($url) {
                                        $images[] = $url;
                                    }
                                }
                            }
                        }
                    }
                    break;

                default:
                    // Check for LazyBlocks and other custom blocks
                    if (strpos($block['blockName'], 'lazyblock/') === 0) {
                        $lazy_images = $this->extract_images_from_lazyblock($block);
                        $images = array_merge($images, $lazy_images);
                    }

                    // Process inner blocks recursively
                    if (!empty($block['innerBlocks'])) {
                        $nested_images = $this->extract_images_from_blocks($block['innerBlocks']);
                        $images = array_merge($images, $nested_images);
                    }
                    break;
            }
        }

        return $images;
    }

    /**
     * Extract image URL from HTML content
     *
     * @param string $html HTML content
     * @return string|null Image URL or null if not found
     */
    private function extract_image_url_from_html($html) {
        if (empty($html)) {
            return null;
        }

        // Use DOMDocument for safe HTML parsing
        $dom = new DOMDocument();

        // Suppress errors for invalid HTML
        libxml_use_internal_errors(true);

        // Load HTML content
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        // Clear errors
        libxml_clear_errors();

        // Find img tags
        $images = $dom->getElementsByTagName('img');

        if ($images->length > 0) {
            $img = $images->item(0); // Get first image
            $src = $img->getAttribute('src');

            if (!empty($src)) {
                return $src;
            }
        }

        // Fallback: use regex for simple cases
        if (preg_match('/src=["\']([^"\']+)["\']/', $html, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract images from LazyBlock
     *
     * @param array $block LazyBlock data
     * @return array Array of image URLs
     */
    private function extract_images_from_lazyblock($block) {
        $images = array();

        if (empty($block['attrs'])) {
            return $images;
        }

        foreach ($block['attrs'] as $attr_value) {
            if (is_string($attr_value) && $this->looks_like_json_image_data($attr_value)) {
                $decoded = urldecode($attr_value);
                $data = json_decode($decoded, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $found_urls = $this->extract_urls_from_data($data);
                    $images = array_merge($images, $found_urls);
                }
            }
        }

        return $images;
    }

    /**
     * Extract images from meta fields
     *
     * @param int $post_id Post ID
     * @return array Array of image URLs
     */
    private function extract_images_from_meta($post_id) {
        $images = array();
        $meta_data = get_post_meta($post_id);

        foreach ($meta_data as $meta_key => $meta_values) {
            // Skip WordPress internal fields
            if (strpos($meta_key, '_') === 0) {
                continue;
            }

            foreach ($meta_values as $meta_value) {
                $found_images = $this->extract_images_from_meta_value($meta_value);
                $images = array_merge($images, $found_images);
            }
        }

        return array_unique($images);
    }

    /**
     * Extract images from a single meta value
     *
     * @param mixed $meta_value Meta value to process
     * @return array Array of image URLs
     */
    private function extract_images_from_meta_value($meta_value) {
        $images = array();

        if (is_string($meta_value)) {
            // Check if it's a direct image URL
            if ($this->is_image_url($meta_value)) {
                $images[] = $meta_value;
                return $images;
            }

            // Try to decode URL-encoded JSON data
            $decoded = urldecode($meta_value);

            // Check if it's JSON
            $json_data = json_decode($decoded, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $found_urls = $this->extract_urls_from_data($json_data);
                $images = array_merge($images, $found_urls);

                // Also check for HTML-encoded URLs within the JSON
                $html_urls = $this->extract_urls_from_html($decoded);
                $images = array_merge($images, $html_urls);
            } else {
                // Check for URLs in HTML content
                $html_urls = $this->extract_urls_from_html($meta_value);
                $images = array_merge($images, $html_urls);
            }
        }
        // Handle serialized data
        elseif (is_array($meta_value) || is_object($meta_value)) {
            $found_urls = $this->extract_urls_from_data($meta_value);
            $images = array_merge($images, $found_urls);
        }

        return $images;
    }

    /**
     * Extract image URLs from HTML content
     *
     * @param string $html HTML content
     * @return array Array of image URLs
     */
    private function extract_urls_from_html($html) {
        $urls = array();

        if (empty($html) || !is_string($html)) {
            return $urls;
        }

        // Extract URLs from src attributes
        if (preg_match_all('/src=["\']([^"\']+)["\']/', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if ($this->is_image_url($url)) {
                    $urls[] = $url;
                }
            }
        }

        // Extract URLs from href attributes
        if (preg_match_all('/href=["\']([^"\']+)["\']/', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if ($this->is_image_url($url)) {
                    $urls[] = $url;
                }
            }
        }

        // Extract direct URLs from text
        if (preg_match_all('/(https?:\/\/[^\s<>"\']+\.(?:jpg|jpeg|png|gif|webp|svg))/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if ($this->is_image_url($url)) {
                    $urls[] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * Extract URLs from data recursively
     *
     * @param mixed $data Data to search
     * @return array Array of URLs
     */
    private function extract_urls_from_data($data) {
        $urls = array();

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                // Check for direct URL keys
                if (($key === 'url' || $key === 'src') && is_string($value) && $this->is_image_url($value)) {
                    $urls[] = $value;
                }
                // Recursively check nested arrays/objects
                elseif (is_array($value) || is_object($value)) {
                    $nested_urls = $this->extract_urls_from_data($value);
                    $urls = array_merge($urls, $nested_urls);
                }
                // Check if the value itself is a URL string
                elseif (is_string($value) && $this->is_image_url($value)) {
                    $urls[] = $value;
                }
            }
        }
        // Handle objects as well
        elseif (is_object($data)) {
            foreach (get_object_vars($data) as $key => $value) {
                if (($key === 'url' || $key === 'src') && is_string($value) && $this->is_image_url($value)) {
                    $urls[] = $value;
                }
                elseif (is_array($value) || is_object($value)) {
                    $nested_urls = $this->extract_urls_from_data($value);
                    $urls = array_merge($urls, $nested_urls);
                }
                elseif (is_string($value) && $this->is_image_url($value)) {
                    $urls[] = $value;
                }
            }
        }
        // Check if data itself is a URL string
        elseif (is_string($data) && $this->is_image_url($data)) {
            $urls[] = $data;
        }

        return $urls;
    }

    /**
     * Check if string looks like JSON image data
     *
     * @param string $value String to check
     * @return bool
     */
    private function looks_like_json_image_data($value) {
        $decoded = urldecode($value);
        $data = json_decode($decoded, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return false;
        }

        // Check for image-related keys
        $image_keys = array('id', 'url', 'alt', 'title', 'sizes');
        foreach ($image_keys as $key) {
            if (isset($data[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if URL is an image
     *
     * @param string $url URL to check
     * @return bool
     */
    private function is_image_url($url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $supported_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg');

        if (!in_array($extension, $supported_extensions)) {
            return false;
        }

        // Exclude WordPress thumbnail images (e.g., image-300x200.jpg, image-1024x683.jpg)
        $filename = pathinfo($path, PATHINFO_FILENAME);

        // Check if filename ends with size pattern like -300x200, -1024x683, etc.
        if (preg_match('/-\d+x\d+$/', $filename)) {
            return false;
        }

        return true;
    }

    /**
     * Copy image to export directory
     *
     * @param string $image_url Image URL
     * @param string $images_dir Images directory
     * @return array|WP_Error Copy result
     */
    private function copy_image_to_export($image_url, $images_dir) {
        // Convert URL to local file path
        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];

        if (strpos($image_url, $upload_url) !== 0) {
            return new WP_Error(
                'external_image',
                sprintf(__('外部画像はエクスポートできません: %s', 'import-post-block-media-from-zip'), $image_url)
            );
        }

        $relative_path = str_replace($upload_url, '', $image_url);
        $source_path = $upload_dir['basedir'] . $relative_path;

        if (!file_exists($source_path)) {
            return new WP_Error(
                'image_not_found',
                sprintf(__('画像ファイルが見つかりません: %s', 'import-post-block-media-from-zip'), $source_path)
            );
        }

        $filename = basename($source_path);
        $dest_path = $images_dir . '/' . $filename;

        // Handle filename conflicts
        $counter = 1;
        $original_filename = $filename;
        while (file_exists($dest_path)) {
            $pathinfo = pathinfo($original_filename);
            $filename = $pathinfo['filename'] . '-' . $counter . '.' . $pathinfo['extension'];
            $dest_path = $images_dir . '/' . $filename;
            $counter++;
        }

        if (!copy($source_path, $dest_path)) {
            return new WP_Error(
                'copy_failed',
                sprintf(__('画像のコピーに失敗しました: %s', 'import-post-block-media-from-zip'), $filename)
            );
        }

        return array(
            'filename' => $filename,
            'copied_path' => $dest_path,
            'original_path' => $source_path
        );
    }

    /**
     * Create export ZIP file
     *
     * @param string $temp_dir Temporary directory
     * @param WP_Post $post Post object
     * @return array|WP_Error ZIP creation result
     */
    private function create_export_zip($temp_dir, $post) {
        $upload_dir = wp_upload_dir();
        $zip_filename = sanitize_file_name('export-' . $post->post_title . '-' . $post->ID . '-' . time() . '.zip');
        $zip_path = $upload_dir['path'] . '/' . $zip_filename;
        $zip_url = $upload_dir['url'] . '/' . $zip_filename;

        $zip = new ZipArchive();
        $result = $zip->open($zip_path, ZipArchive::CREATE);

        if ($result !== TRUE) {
            return new WP_Error(
                'zip_creation_failed',
                sprintf(__('ZIPファイルの作成に失敗しました: %s', 'import-post-block-media-from-zip'), $result)
            );
        }

        // Add files recursively
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temp_dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $file_path = $file->getPathname();
                $relative_path = str_replace($temp_dir . '/', '', $file_path);
                $zip->addFile($file_path, $relative_path);
            }
        }

        $zip->close();

        if (!file_exists($zip_path)) {
            return new WP_Error(
                'zip_not_created',
                __('ZIPファイルが作成されませんでした。', 'import-post-block-media-from-zip')
            );
        }

        return array(
            'zip_path' => $zip_path,
            'zip_url' => $zip_url,
            'zip_filename' => $zip_filename
        );
    }

    /**
     * Create temporary directory for export
     *
     * @param int $post_id Post ID
     * @return string|WP_Error Directory path or error
     */
    private function create_temp_directory($post_id) {
        $upload_dir = wp_upload_dir();
        $temp_dir_name = 'temp-export-' . $post_id . '-' . time();
        $temp_path = $upload_dir['basedir'] . '/' . $temp_dir_name;

        if (!wp_mkdir_p($temp_path)) {
            return new WP_Error(
                'temp_dir_failed',
                __('一時ディレクトリの作成に失敗しました。', 'import-post-block-media-from-zip')
            );
        }

        return $temp_path;
    }

    /**
     * Clean up temporary directory
     *
     * @param string $temp_dir Directory to clean up
     * @return bool Success status
     */
    private function cleanup_temp_directory($temp_dir) {
        if (!is_dir($temp_dir)) {
            return true;
        }

        try {
            $this->delete_directory_recursive($temp_dir);
            return true;
        } catch (Exception $e) {
            $this->log('ERROR', 'Failed to cleanup temp directory: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Recursively delete directory
     *
     * @param string $dir Directory to delete
     */
    private function delete_directory_recursive($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->delete_directory_recursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Log export activity
     *
     * @param string $level Log level
     * @param string $message Log message
     */
    public function log($level, $message) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf('[%s] Import Media from ZIP - %s: %s', current_time('Y-m-d H:i:s'), $level, $message));
        }
    }
}