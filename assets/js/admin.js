/**
 * Admin JavaScript for Import/Export Post Block Media from ZIP
 */

(function($) {
    'use strict';

    // Plugin object
    const ImportExportMediaFromZip = {

        // Configuration
        config: {
            // Export elements
            exportButton: '#export-post-button',
            exportIncludeImages: '#export-include-images',
            exportIncludeMeta: '#export-include-meta',
            exportIncludePatterns: '#export-include-patterns',
            exportProgress: '#export-progress',
            exportProgressBar: '#export-progress-bar',
            exportProgressMessage: '#export-progress-message',

            // Import elements
            importButton: '#import-post-button',
            importFileInput: '#import-zip-file-input',
            importIncludeImages: '#import-include-images',
            importIncludeMeta: '#import-include-meta',
            importIncludePatterns: '#import-include-patterns',

            // Image-only import elements
            imageImportButton: '#import-images-only-button',
            imageFileInput: '#image-zip-file-input',

            // Synced patterns elements
            exportPatternsButton: '#export-patterns-button',
            importPatternsButton: '#import-patterns-button',
            patternsFileInput: '#patterns-zip-file-input',
            patternImportModeRadio: 'input[name="pattern-import-mode"]',

            // Common elements
            progressContainer: '#operation-progress',
            progressBar: '#progress-bar',
            progressMessage: '#progress-message',
            resultsContainer: '#operation-results',
            resultsContent: '#results-content',
            resultsTitle: '#results-title',
            performAnotherButton: '#perform-another',
            container: '#import-export-container'
        },

        // State
        state: {
            isProcessing: false,
            currentOperation: null,
            selectedImportFile: null,
            selectedImageFile: null,
            selectedPatternsFile: null
        },

        /**
         * Initialize the plugin
         */
        init: function() {
            this.bindEvents();
            this.updateButtonStates();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            const self = this;

            // Export button
            $(this.config.exportButton).on('click', function() {
                self.handleExport();
            });

            // Import file input
            $(this.config.importFileInput).on('change', function() {
                self.handleImportFileSelection(this);
            });

            // Import button
            $(this.config.importButton).on('click', function() {
                self.handleImport();
            });

            // Import mode radio buttons

            // Image-only file input
            $(this.config.imageFileInput).on('change', function() {
                self.handleImageFileSelection(this);
            });

            // Image-only import button
            $(this.config.imageImportButton).on('click', function() {
                self.handleImageOnlyImport();
            });

            // Synced patterns export button
            $(this.config.exportPatternsButton).on('click', function() {
                self.handlePatternsExport();
            });

            // Synced patterns file input
            $(this.config.patternsFileInput).on('change', function() {
                self.handlePatternsFileSelection(this);
            });

            // Synced patterns import button
            $(this.config.importPatternsButton).on('click', function() {
                self.handlePatternsImport();
            });

            // Perform another operation button
            $(this.config.performAnotherButton).on('click', function() {
                self.resetForm();
            });

            // Setup drag and drop for file inputs
            this.setupDragAndDrop();
        },

        /**
         * Setup drag and drop functionality
         */
        setupDragAndDrop: function() {
            const self = this;

            // Import file drag and drop
            this.setupFileInputDragDrop(this.config.importFileInput, function(file) {
                self.state.selectedImportFile = file;
                self.updateButtonStates();
            });

            // Image file drag and drop
            this.setupFileInputDragDrop(this.config.imageFileInput, function(file) {
                self.state.selectedImageFile = file;
                self.updateButtonStates();
            });

            // Patterns file drag and drop
            this.setupFileInputDragDrop(this.config.patternsFileInput, function(file) {
                self.state.selectedPatternsFile = file;
                self.updateButtonStates();
            });
        },

        /**
         * Setup drag and drop for a specific file input
         */
        setupFileInputDragDrop: function(inputSelector, callback) {
            const $input = $(inputSelector);
            const $parent = $input.closest('.function-section');

            $parent.on('dragover dragenter', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('drag-over');
            });

            $parent.on('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('drag-over');
            });

            $parent.on('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('drag-over');

                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0 && this.validateFile(files[0])) {
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(files[0]);
                    $input[0].files = dataTransfer.files;
                    callback(files[0]);
                }
            }.bind(this));
        },

        /**
         * Handle export operation
         */
        handleExport: function() {
            if (this.state.isProcessing) {
                return;
            }

            this.state.currentOperation = 'export';
            this.state.isProcessing = true;
            this.updateButtonStates();
            this.showProgress(importExportMediaFromZip.strings.exporting);

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'export_post_with_media');
            formData.append('post_id', importExportMediaFromZip.postId);
            formData.append('nonce', importExportMediaFromZip.nonce);
            formData.append('include_images', $(this.config.exportIncludeImages).is(':checked') ? '1' : '0');
            formData.append('include_meta', $(this.config.exportIncludeMeta).is(':checked') ? '1' : '0');
            formData.append('include_synced_patterns', $(this.config.exportIncludePatterns).is(':checked') ? '1' : '0');

            this.performAjaxRequest(formData, 'export');
        },

        /**
         * Handle import file selection
         */
        handleImportFileSelection: function(input) {
            const file = input.files[0];

            if (file && this.validateFile(file)) {
                this.state.selectedImportFile = file;
            } else {
                this.state.selectedImportFile = null;
                $(input).val('');
            }

            this.updateButtonStates();
        },


        /**
         * Handle import operation
         */
        handleImport: function() {
            if (this.state.isProcessing || !this.state.selectedImportFile) {
                return;
            }

            // Confirm before replacing current post
            if (!confirm(importExportMediaFromZip.strings.confirmReplace)) {
                return;
            }

            this.state.currentOperation = 'import';
            this.state.isProcessing = true;
            this.updateButtonStates();
            this.showProgress(importExportMediaFromZip.strings.importing);

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'import_post_with_media');
            formData.append('post_id', importExportMediaFromZip.postId);
            formData.append('nonce', importExportMediaFromZip.nonce);
            formData.append('zip_file', this.state.selectedImportFile);
            formData.append('import_mode', 'replace_current');
            formData.append('include_images', $(this.config.importIncludeImages).is(':checked') ? '1' : '0');
            formData.append('include_meta', $(this.config.importIncludeMeta).is(':checked') ? '1' : '0');
            formData.append('include_synced_patterns', $(this.config.importIncludePatterns).is(':checked') ? '1' : '0');

            this.performAjaxRequest(formData, 'import');
        },

        /**
         * Handle image file selection
         */
        handleImageFileSelection: function(input) {
            const file = input.files[0];

            if (file && this.validateFile(file)) {
                this.state.selectedImageFile = file;
            } else {
                this.state.selectedImageFile = null;
                $(input).val('');
            }

            this.updateButtonStates();
        },

        /**
         * Handle image-only import operation
         */
        handleImageOnlyImport: function() {
            if (this.state.isProcessing || !this.state.selectedImageFile) {
                return;
            }

            this.state.currentOperation = 'image-import';
            this.state.isProcessing = true;
            this.updateButtonStates();
            this.showProgress(importExportMediaFromZip.strings.importing);

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'import_images_only');
            formData.append('post_id', importExportMediaFromZip.postId);
            formData.append('nonce', importExportMediaFromZip.nonce);
            formData.append('zip_file', this.state.selectedImageFile);

            this.performAjaxRequest(formData, 'image-import');
        },

        /**
         * Validate selected file
         */
        validateFile: function(file) {
            // Check file type
            if (file.type !== 'application/zip' && !file.name.toLowerCase().endsWith('.zip')) {
                this.showError(importExportMediaFromZip.strings.invalidFile);
                return false;
            }

            // Check file size
            const maxSize = 50 * 1024 * 1024; // 50MB hard limit
            if (file.size > maxSize) {
                this.showError(importExportMediaFromZip.strings.error + ': ファイルサイズが上限を超えています。最大: ' + this.formatFileSize(maxSize));
                return false;
            }

            // Warning for large files
            const warningSize = 10 * 1024 * 1024; // 10MB
            if (file.size > warningSize) {
                const message = importExportMediaFromZip.strings.warning + ': ' +
                               'ファイルサイズが大きいです (' + this.formatFileSize(file.size) + ')。' +
                               '処理に時間がかかる場合があります。';
                this.showWarning(message);
            }

            return true;
        },

        /**
         * Update button states
         */
        updateButtonStates: function() {
            // Export button
            $(this.config.exportButton).prop('disabled', this.state.isProcessing);

            // Import button
            $(this.config.importButton).prop('disabled',
                this.state.isProcessing || !this.state.selectedImportFile);

            // Image-only import button
            $(this.config.imageImportButton).prop('disabled',
                this.state.isProcessing || !this.state.selectedImageFile);

            // Patterns export button
            $(this.config.exportPatternsButton).prop('disabled', this.state.isProcessing);

            // Patterns import button
            $(this.config.importPatternsButton).prop('disabled',
                this.state.isProcessing || !this.state.selectedPatternsFile);
        },

        /**
         * Perform AJAX request
         */
        performAjaxRequest: function(formData, operationType) {
            const self = this;

            $.ajax({
                url: importExportMediaFromZip.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    const xhr = new window.XMLHttpRequest();

                    // Upload progress
                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            const percentComplete = (e.loaded / e.total) * 50; // Upload is 50% of process
                            self.updateProgress(percentComplete, importExportMediaFromZip.strings.uploading);
                        }
                    });

                    return xhr;
                },
                success: function(response) {
                    self.handleAjaxSuccess(response, operationType);
                },
                error: function(xhr, status, error) {
                    self.handleAjaxError(xhr, status, error);
                }
            });
        },

        /**
         * Handle AJAX success
         */
        handleAjaxSuccess: function(response, operationType) {
            this.updateProgress(100, importExportMediaFromZip.strings.completed);

            setTimeout(() => {
                this.hideProgress();

                if (response.success) {
                    this.showResults(response.data, operationType);
                } else {
                    this.showError(response.data.message || importExportMediaFromZip.strings.error);
                    this.resetProcessingState();
                }
            }, 500);
        },

        /**
         * Handle AJAX error
         */
        handleAjaxError: function(xhr, status, error) {
            this.hideProgress();

            let errorMessage = importExportMediaFromZip.strings.error;

            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMessage = xhr.responseJSON.data.message;
            } else if (error) {
                errorMessage += ': ' + error;
            }

            this.showError(errorMessage);
            this.resetProcessingState();
        },

        /**
         * Show progress indicator
         */
        showProgress: function(message) {
            $('.function-section').hide();
            $(this.config.resultsContainer).hide();
            $(this.config.progressContainer).show();
            this.updateProgress(0, message || importExportMediaFromZip.strings.processing);
        },

        /**
         * Update progress
         */
        updateProgress: function(percent, message) {
            $(this.config.progressBar).css('width', percent + '%');
            $(this.config.progressMessage).text(message);
        },

        /**
         * Hide progress indicator
         */
        hideProgress: function() {
            $(this.config.progressContainer).hide();
        },

        /**
         * Show results
         */
        showResults: function(data, operationType) {
            const $resultsContent = $(this.config.resultsContent);
            const $resultsTitle = $(this.config.resultsTitle);
            $resultsContent.empty();

            // Set appropriate title
            if (operationType === 'export') {
                $resultsTitle.text(importExportMediaFromZip.strings.exportSuccess);
                this.showExportResults(data, $resultsContent);
            } else if (operationType === 'import') {
                $resultsTitle.text(importExportMediaFromZip.strings.importSuccess);
                this.showImportResults(data, $resultsContent);
            } else if (operationType === 'image-import') {
                $resultsTitle.text('画像インポート結果');
                this.showImageImportResults(data, $resultsContent);
            } else if (operationType === 'export-patterns') {
                $resultsTitle.text('同期パターンエクスポート結果');
                this.showPatternsExportResults(data, $resultsContent);
            } else if (operationType === 'import-patterns') {
                $resultsTitle.text('同期パターンインポート結果');
                this.showPatternsImportResults(data, $resultsContent);
            }

            $(this.config.resultsContainer).show();

            // Handle post-operation actions
            this.handlePostOperationActions(data, operationType);
        },

        /**
         * Show export results
         */
        showExportResults: function(data, $container) {
            const statsHtml = `
                <div class="results-stats">
                    <div class="result-success">
                        <strong>${importExportMediaFromZip.strings.exportSuccess}</strong>
                        <ul>
                            <li>${importExportMediaFromZip.strings.exportedImages}: ${data.image_count}</li>
                            <li>${importExportMediaFromZip.strings.fileSize}: ${data.file_size_formatted}</li>
                        </ul>
                    </div>
                    <p>${data.message}</p>
                    <p>
                        <a href="${data.zip_url}" class="download-link" download>
                            ${importExportMediaFromZip.strings.downloadFile}
                        </a>
                    </p>
                </div>
            `;
            $container.append(statsHtml);
        },

        /**
         * Show import results
         */
        showImportResults: function(data, $container) {
            const postAction = data.is_new_post
                ? importExportMediaFromZip.strings.newPost
                : importExportMediaFromZip.strings.updatedPost;

            const statsHtml = `
                <div class="results-stats">
                    <div class="result-success">
                        <strong>${postAction}</strong>
                        <ul>
                            <li>記事: "${data.post_title}"</li>
                            <li>${importExportMediaFromZip.strings.importedImages}: ${data.imported_images}</li>
                            <li>${importExportMediaFromZip.strings.updatedBlocks}: ${data.updated_blocks}</li>
                        </ul>
                    </div>
                    <p>${data.message}</p>
                    <p>
                        <a href="${data.post_url}" class="download-link">
                            ${importExportMediaFromZip.strings.editPost}
                        </a>
                    </p>
                </div>
            `;
            $container.append(statsHtml);

            // Add failed matches if any
            if (data.failed_matches && data.failed_matches.length > 0) {
                const failedHtml = this.buildFailedMatchesHtml(data.failed_matches);
                $container.append(failedHtml);
            }
        },

        /**
         * Show image import results
         */
        showImageImportResults: function(data, $container) {
            const statsHtml = `
                <div class="results-stats">
                    <div class="result-success">
                        <strong>${importExportMediaFromZip.strings.success}</strong>
                        <ul>
                            <li>${importExportMediaFromZip.strings.importedImages}: ${data.imported_count}</li>
                            <li>${importExportMediaFromZip.strings.updatedBlocks}: ${data.updated_blocks}</li>
                        </ul>
                    </div>
                    <p>${data.message}</p>
                </div>
            `;
            $container.append(statsHtml);

            // Add failed matches and import errors
            if (data.failed_matches && data.failed_matches.length > 0) {
                const failedHtml = this.buildFailedMatchesHtml(data.failed_matches);
                $container.append(failedHtml);
            }

            if (data.import_errors && Object.keys(data.import_errors).length > 0) {
                const errorsHtml = this.buildImportErrorsHtml(data.import_errors);
                $container.append(errorsHtml);
            }
        },

        /**
         * Build failed matches HTML
         */
        buildFailedMatchesHtml: function(failedMatches) {
            let html = `
                <div class="failed-matches">
                    <div class="result-warning">
                        <strong>${importExportMediaFromZip.strings.warning}</strong>
                        <p>${importExportMediaFromZip.strings.failedMatches}:</p>
                        <ul>
            `;

            failedMatches.forEach(function(match) {
                html += `<li>${match.message || match}</li>`;
            });

            html += `
                        </ul>
                    </div>
                </div>
            `;

            return html;
        },

        /**
         * Build import errors HTML
         */
        buildImportErrorsHtml: function(importErrors) {
            let html = `
                <div class="import-errors">
                    <div class="result-error">
                        <strong>インポートエラー</strong>
                        <ul>
            `;

            for (const filename in importErrors) {
                html += `<li>${filename}: ${importErrors[filename]}</li>`;
            }

            html += `
                        </ul>
                    </div>
                </div>
            `;

            return html;
        },

        /**
         * Handle post-operation actions
         */
        handlePostOperationActions: function(data, operationType) {
            // Ask to reload page for import operations with updated blocks
            if ((operationType === 'import' || operationType === 'image-import') && data.updated_blocks > 0) {
                setTimeout(() => {
                    // Try to refresh the block editor first
                    if (window.wp && window.wp.data && window.wp.data.dispatch) {
                        try {
                            const { dispatch } = window.wp.data;
                            const coreEditor = dispatch('core/editor');

                            if (coreEditor && coreEditor.refreshPost) {
                                coreEditor.refreshPost();
                                console.log('Block editor refreshed');
                            }
                        } catch (e) {
                            console.log('Could not refresh block editor:', e);
                        }
                    }

                    if (confirm(importExportMediaFromZip.strings.reloadPage)) {
                        window.location.reload();
                    }
                }, 2000);
            }

            // For new post creation, offer to navigate to the new post
            if (operationType === 'import' && data.is_new_post && data.post_url) {
                setTimeout(() => {
                    if (confirm('新しい記事が作成されました。編集画面に移動しますか？')) {
                        window.location.href = data.post_url;
                    }
                }, 1000);
            }
        },

        /**
         * Reset form
         */
        resetForm: function() {
            this.resetFileInputs();
            this.resetProcessingState();
            $(this.config.resultsContainer).hide();
            $('.function-section').show();
        },

        /**
         * Reset file inputs
         */
        resetFileInputs: function() {
            $(this.config.importFileInput).val('');
            $(this.config.imageFileInput).val('');
            $(this.config.patternsFileInput).val('');
            this.state.selectedImportFile = null;
            this.state.selectedImageFile = null;
            this.state.selectedPatternsFile = null;
            this.updateButtonStates();
        },

        /**
         * Reset processing state
         */
        resetProcessingState: function() {
            this.state.isProcessing = false;
            this.state.currentOperation = null;
            this.updateButtonStates();
        },

        /**
         * Show error message
         */
        showError: function(message) {
            this.showNotice(message, 'error');
        },

        /**
         * Show warning message
         */
        showWarning: function(message) {
            this.showNotice(message, 'warning');
        },

        /**
         * Show notice
         */
        showNotice: function(message, type) {
            // Remove existing notices
            $('.import-export-notice').remove();

            const noticeClass = type === 'error' ? 'notice-error' : 'notice-warning';
            const notice = $(`
                <div class="notice ${noticeClass} import-export-notice is-dismissible">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">この通知を閉じる</span>
                    </button>
                </div>
            `);

            // Insert before the container
            $(this.config.container).before(notice);

            // Auto dismiss after 8 seconds
            setTimeout(function() {
                notice.fadeOut();
            }, 8000);

            // Handle dismiss button
            notice.find('.notice-dismiss').on('click', function() {
                notice.fadeOut();
            });
        },

        /**
         * Handle patterns file selection
         */
        handlePatternsFileSelection: function(input) {
            const file = input.files[0];

            if (file && this.validateFile(file)) {
                this.state.selectedPatternsFile = file;
            } else {
                this.state.selectedPatternsFile = null;
                $(input).val('');
            }

            this.updateButtonStates();
        },

        /**
         * Handle patterns export operation
         */
        handlePatternsExport: function() {
            if (this.state.isProcessing) {
                return;
            }

            this.state.currentOperation = 'export-patterns';
            this.state.isProcessing = true;
            this.updateButtonStates();
            this.showProgress(importExportMediaFromZip.strings.exportingPatterns || 'Exporting synced patterns...');

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'export_synced_patterns');
            formData.append('nonce', importExportMediaFromZip.nonce);

            this.performAjaxRequest(formData, 'export-patterns');
        },

        /**
         * Handle patterns import operation
         */
        handlePatternsImport: function() {
            if (this.state.isProcessing || !this.state.selectedPatternsFile) {
                return;
            }

            this.state.currentOperation = 'import-patterns';
            this.state.isProcessing = true;
            this.updateButtonStates();
            this.showProgress(importExportMediaFromZip.strings.importingPatterns || 'Importing synced patterns...');

            // Get selected import mode
            const importMode = $(this.config.patternImportModeRadio + ':checked').val();

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'import_synced_patterns');
            formData.append('nonce', importExportMediaFromZip.nonce);
            formData.append('zip_file', this.state.selectedPatternsFile);
            formData.append('import_mode', importMode);

            this.performAjaxRequest(formData, 'import-patterns');
        },

        /**
         * Show patterns export results
         */
        showPatternsExportResults: function(data, $container) {
            const statsHtml = `
                <div class="results-stats">
                    <div class="result-success">
                        <strong>同期パターンエクスポート完了</strong>
                        <ul>
                            <li>エクスポートされたパターン: ${data.pattern_count}個</li>
                            <li>含まれる画像: ${data.image_count}件</li>
                            <li>ファイルサイズ: ${data.file_size_formatted}</li>
                        </ul>
                    </div>
                    <p>${data.message}</p>
                    <p>
                        <a href="${data.zip_url}" class="download-link" download>
                            <strong>ZIPファイルをダウンロード</strong>
                        </a>
                    </p>
                </div>
            `;
            $container.append(statsHtml);

            // Show errors if any
            if (data.errors && data.errors.length > 0) {
                const errorsHtml = `
                    <div class="results-errors">
                        <strong>エラー:</strong>
                        <ul>
                            ${data.errors.map(error => `<li>${error}</li>`).join('')}
                        </ul>
                    </div>
                `;
                $container.append(errorsHtml);
            }
        },

        /**
         * Show patterns import results
         */
        showPatternsImportResults: function(data, $container) {
            const statsHtml = `
                <div class="results-stats">
                    <div class="result-success">
                        <strong>同期パターンインポート完了</strong>
                        <ul>
                            <li>新規作成されたパターン: ${data.imported_patterns}個</li>
                            <li>更新されたパターン: ${data.updated_patterns}個</li>
                            <li>スキップされたパターン: ${data.skipped_patterns}個</li>
                            <li>インポートされた画像: ${data.imported_images}件</li>
                        </ul>
                    </div>
                    <p>${data.message}</p>
                </div>
            `;
            $container.append(statsHtml);

            // Show errors if any
            if (data.errors && data.errors.length > 0) {
                const errorsHtml = `
                    <div class="results-errors">
                        <strong>エラー:</strong>
                        <ul>
                            ${data.errors.map(error => `<li>${error}</li>`).join('')}
                        </ul>
                    </div>
                `;
                $container.append(errorsHtml);
            }
        },

        /**
         * Format file size
         */
        formatFileSize: function(bytes) {
            if (bytes === 0) return '0 Bytes';

            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));

            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        // Only initialize if we're on a post editing screen and the container exists
        if ($('#import-export-container').length > 0) {
            ImportExportMediaFromZip.init();
        }
    });

})(jQuery);