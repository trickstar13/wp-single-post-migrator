<?php
/**
 * ZIP Handler Class
 *
 * Handles ZIP file upload, extraction, and cleanup operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class IPBMFZ_ZIP_Handler {

    /**
     * Supported image file extensions
     */
    private $supported_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg');

    /**
     * Upload and extract ZIP file
     *
     * @param array $file_data $_FILES array data
     * @param int $post_id Post ID for creating unique temp directory
     * @return array|WP_Error Array with extract_path and files, or WP_Error on failure
     */
    public function upload_and_extract($file_data, $post_id) {
        // Validate file
        $validation = $this->validate_zip_file($file_data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Handle upload
        $uploaded_file = $this->handle_upload($file_data);
        if (is_wp_error($uploaded_file)) {
            return $uploaded_file;
        }

        // Create temp directory
        $extract_path = $this->create_temp_directory($post_id);
        if (is_wp_error($extract_path)) {
            unlink($uploaded_file['file']);
            return $extract_path;
        }

        // Extract ZIP
        $extraction_result = $this->extract_zip($uploaded_file['file'], $extract_path);
        unlink($uploaded_file['file']); // Remove uploaded ZIP file

        if (is_wp_error($extraction_result)) {
            $this->cleanup($extract_path);
            return $extraction_result;
        }

        // Get image files
        $image_files = $this->get_image_files($extract_path);

        if (empty($image_files)) {
            $this->cleanup($extract_path);
            return new WP_Error(
                'no_images_found',
                __('ZIPファイル内に対応する画像が見つかりませんでした。対応形式: ' . implode(', ', $this->supported_extensions), 'import-post-block-media-from-zip')
            );
        }

        return array(
            'extract_path' => $extract_path,
            'files' => $image_files
        );
    }

    /**
     * Validate ZIP file
     *
     * @param array $file_data $_FILES array data
     * @return true|WP_Error
     */
    private function validate_zip_file($file_data) {
        // Check for upload errors
        if ($file_data['error'] !== UPLOAD_ERR_OK) {
            switch ($file_data['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    return new WP_Error(
                        'file_too_large',
                        __('ファイルサイズが上限を超えています。最大: ' . ini_get('upload_max_filesize'), 'import-post-block-media-from-zip')
                    );
                case UPLOAD_ERR_PARTIAL:
                    return new WP_Error(
                        'upload_incomplete',
                        __('ファイルのアップロードが不完全です。', 'import-post-block-media-from-zip')
                    );
                case UPLOAD_ERR_NO_FILE:
                    return new WP_Error(
                        'no_file',
                        __('ファイルが選択されていません。', 'import-post-block-media-from-zip')
                    );
                default:
                    return new WP_Error(
                        'upload_error',
                        __('ファイルのアップロードエラーが発生しました。', 'import-post-block-media-from-zip')
                    );
            }
        }

        // Check file type
        $file_info = wp_check_filetype($file_data['name']);
        if ($file_info['ext'] !== 'zip') {
            return new WP_Error(
                'invalid_file_type',
                __('ZIPファイルのみアップロード可能です。', 'import-post-block-media-from-zip')
            );
        }

        // Check file size (recommend under 10MB)
        $max_size = 10 * 1024 * 1024; // 10MB
        if ($file_data['size'] > $max_size) {
            error_log('Import Media from ZIP - WARNING: Large file uploaded: ' . size_format($file_data['size']));
        }

        return true;
    }

    /**
     * Handle file upload
     *
     * @param array $file_data $_FILES array data
     * @return array|WP_Error Upload result or WP_Error
     */
    private function handle_upload($file_data) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');

        $upload_overrides = array(
            'test_form' => false,
            'mimes' => array('zip' => 'application/zip')
        );

        $uploaded_file = wp_handle_upload($file_data, $upload_overrides);

        if (isset($uploaded_file['error'])) {
            return new WP_Error(
                'upload_failed',
                $uploaded_file['error']
            );
        }

        return $uploaded_file;
    }

    /**
     * Create temporary directory for extraction
     *
     * @param int $post_id Post ID
     * @return string|WP_Error Directory path or WP_Error
     */
    private function create_temp_directory($post_id) {
        $upload_dir = wp_upload_dir();
        $temp_dir_name = 'temp-import-' . $post_id . '-' . time();
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
     * Extract ZIP file
     *
     * @param string $zip_path Path to ZIP file
     * @param string $extract_path Path to extract to
     * @return true|WP_Error
     */
    private function extract_zip($zip_path, $extract_path) {
        $zip = new ZipArchive();
        $result = $zip->open($zip_path);

        if ($result !== TRUE) {
            return new WP_Error(
                'zip_extraction_failed',
                __('ZIPファイルの展開に失敗しました。ファイルが破損している可能性があります。', 'import-post-block-media-from-zip')
            );
        }

        $extracted = $zip->extractTo($extract_path);
        $zip->close();

        if (!$extracted) {
            return new WP_Error(
                'zip_extraction_failed',
                __('ZIPファイルの展開に失敗しました。', 'import-post-block-media-from-zip')
            );
        }

        return true;
    }

    /**
     * Get image files from extracted directory
     *
     * @param string $extract_path Path to extracted files
     * @return array Array of image file paths with filename as key
     */
    public function get_image_files($extract_path) {
        $image_files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extract_path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $file_path = $file->getPathname();
                $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

                if (in_array($file_extension, $this->supported_extensions)) {
                    // Use filename as key for easier matching
                    $filename = $file->getFilename();
                    $image_files[$filename] = $file_path;
                }
            }
        }

        return $image_files;
    }

    /**
     * Clean up temporary directory
     *
     * @param string $extract_path Path to clean up
     * @return bool Success status
     */
    public function cleanup($extract_path) {
        if (!is_dir($extract_path)) {
            return true;
        }

        try {
            $this->delete_directory_recursive($extract_path);
            return true;
        } catch (Exception $e) {
            error_log('Import Media from ZIP - ERROR: Failed to cleanup temp directory: ' . $e->getMessage());
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
     * Log import activity
     *
     * @param string $level Log level (INFO, WARNING, ERROR)
     * @param string $message Log message
     */
    public function log($level, $message) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf('[%s] Import Media from ZIP - %s: %s', current_time('Y-m-d H:i:s'), $level, $message));
        }
    }
}