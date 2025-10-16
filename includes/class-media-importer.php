<?php
/**
 * Media Importer Class
 *
 * Handles importing images to WordPress media library and checking for duplicates
 */

if (!defined('ABSPATH')) {
    exit;
}

class IPBMFZ_Media_Importer {

    /**
     * Check if attachment with same filename already exists
     *
     * @param string $filename Filename to check
     * @return int|false Attachment ID if exists, false otherwise
     */
    public function check_existing_attachment($filename) {
        global $wpdb;

        $attachment_id = $wpdb->get_var($wpdb->prepare("
            SELECT post_id
            FROM $wpdb->postmeta
            WHERE meta_key = '_wp_attached_file'
            AND meta_value LIKE %s
            LIMIT 1
        ", '%' . $wpdb->esc_like($filename)));

        return $attachment_id ? (int) $attachment_id : false;
    }

    /**
     * Import image to media library
     *
     * @param string $file_path Path to image file
     * @param int $post_id Post ID to attach media to
     * @param string $filename Original filename
     * @return int|WP_Error Attachment ID on success, WP_Error on failure
     */
    public function import_image($file_path, $post_id, $filename) {
        // Check if file exists
        if (!file_exists($file_path)) {
            return new WP_Error(
                'file_not_found',
                __('ファイルが見つかりません: ' . $filename, 'import-post-block-media-from-zip')
            );
        }

        // Check for existing attachment
        $existing_attachment_id = $this->check_existing_attachment($filename);

        if ($existing_attachment_id) {
            $existing_post = get_post($existing_attachment_id);

            // If existing attachment has no parent or parent is 0, update it
            if (!$existing_post || !$existing_post->post_parent || $existing_post->post_parent == 0) {
                $updated = $this->update_existing_attachment($existing_attachment_id, $post_id);
                if ($updated) {
                    return $existing_attachment_id;
                }
            }
            // If existing attachment has a parent, create new file (WordPress will handle naming conflict)
        }

        // Import as new attachment
        return $this->create_new_attachment($file_path, $post_id, $filename);
    }

    /**
     * Update existing attachment's post_parent
     *
     * @param int $attachment_id Attachment ID
     * @param int $post_id New parent post ID
     * @return bool Success status
     */
    private function update_existing_attachment($attachment_id, $post_id) {
        $result = wp_update_post(array(
            'ID' => $attachment_id,
            'post_parent' => $post_id
        ));

        return !is_wp_error($result) && $result > 0;
    }

    /**
     * Create new attachment
     *
     * @param string $file_path Path to image file
     * @param int $post_id Post ID to attach to
     * @param string $filename Original filename
     * @return int|WP_Error Attachment ID on success, WP_Error on failure
     */
    private function create_new_attachment($file_path, $post_id, $filename) {
        // Get file info
        $file_info = wp_check_filetype($filename);
        if (!$file_info['type']) {
            return new WP_Error(
                'invalid_file_type',
                __('サポートされていないファイル形式です: ' . $filename, 'import-post-block-media-from-zip')
            );
        }

        // Copy file to uploads directory
        $upload_dir = wp_upload_dir();
        $new_file_path = $this->get_unique_filename($upload_dir['path'], $filename);

        if (!copy($file_path, $new_file_path)) {
            return new WP_Error(
                'file_copy_failed',
                __('ファイルのコピーに失敗しました: ' . $filename, 'import-post-block-media-from-zip')
            );
        }

        // Create attachment data
        $file_url = $upload_dir['url'] . '/' . basename($new_file_path);
        $attachment_data = array(
            'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_mime_type' => $file_info['type'],
            'post_parent' => $post_id,
        );

        // Insert attachment
        $attachment_id = wp_insert_attachment($attachment_data, $new_file_path, $post_id);

        if (is_wp_error($attachment_id)) {
            unlink($new_file_path);
            return $attachment_id;
        }

        // Generate attachment metadata
        $this->generate_attachment_metadata($attachment_id, $new_file_path);

        return $attachment_id;
    }

    /**
     * Get unique filename in upload directory
     *
     * @param string $upload_path Upload directory path
     * @param string $filename Original filename
     * @return string Unique file path
     */
    private function get_unique_filename($upload_path, $filename) {
        $file_path = $upload_path . '/' . $filename;

        // If file doesn't exist, use original name
        if (!file_exists($file_path)) {
            return $file_path;
        }

        // Generate unique filename
        $pathinfo = pathinfo($filename);
        $basename = $pathinfo['filename'];
        $extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';

        $counter = 1;
        do {
            $new_filename = $basename . '-' . $counter . $extension;
            $file_path = $upload_path . '/' . $new_filename;
            $counter++;
        } while (file_exists($file_path));

        return $file_path;
    }

    /**
     * Generate attachment metadata
     *
     * @param int $attachment_id Attachment ID
     * @param string $file_path File path
     */
    private function generate_attachment_metadata($attachment_id, $file_path) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attach_data = wp_generate_attachment_metadata($attachment_id, $file_path);
        wp_update_attachment_metadata($attachment_id, $attach_data);
    }

    /**
     * Import multiple images
     *
     * @param array $image_files Array of image files (filename => path)
     * @param int $post_id Post ID to attach to
     * @return array Results array with imported attachments and errors
     */
    public function import_multiple_images($image_files, $post_id) {
        $results = array(
            'imported' => array(),
            'errors' => array(),
            'count' => 0
        );

        foreach ($image_files as $filename => $file_path) {
            $attachment_id = $this->import_image($file_path, $post_id, $filename);

            if (is_wp_error($attachment_id)) {
                $results['errors'][$filename] = $attachment_id->get_error_message();
            } else {
                $results['imported'][$filename] = $attachment_id;
                $results['count']++;
            }
        }

        return $results;
    }

    /**
     * Get attachment info by ID
     *
     * @param int $attachment_id Attachment ID
     * @return array|false Attachment info or false
     */
    public function get_attachment_info($attachment_id) {
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return false;
        }

        return array(
            'id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
            'title' => $attachment->post_title,
            'filename' => basename(get_attached_file($attachment_id)),
            'mime_type' => $attachment->post_mime_type,
            'metadata' => wp_get_attachment_metadata($attachment_id)
        );
    }

    /**
     * Validate image file
     *
     * @param string $file_path Path to image file
     * @param string $filename Filename
     * @return true|WP_Error
     */
    public function validate_image_file($file_path, $filename) {
        // Check if file exists
        if (!file_exists($file_path)) {
            return new WP_Error(
                'file_not_found',
                __('ファイルが見つかりません: ' . $filename, 'import-post-block-media-from-zip')
            );
        }

        // Check file size
        $file_size = filesize($file_path);
        $max_size = wp_max_upload_size();

        if ($file_size > $max_size) {
            return new WP_Error(
                'file_too_large',
                sprintf(
                    __('ファイルサイズが上限を超えています (%s): %s', 'import-post-block-media-from-zip'),
                    size_format($max_size),
                    $filename
                )
            );
        }

        // Check file type
        $file_info = wp_check_filetype($filename);
        if (!$file_info['type']) {
            return new WP_Error(
                'invalid_file_type',
                __('サポートされていないファイル形式です: ' . $filename, 'import-post-block-media-from-zip')
            );
        }

        // Check if it's actually an image
        if (strpos($file_info['type'], 'image/') !== 0) {
            return new WP_Error(
                'not_image',
                __('画像ファイルではありません: ' . $filename, 'import-post-block-media-from-zip')
            );
        }

        return true;
    }

    /**
     * Log import activity
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