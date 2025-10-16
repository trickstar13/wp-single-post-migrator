/**
 * Admin JavaScript for Import Post Block Media from ZIP
 */

(function($) {
    'use strict';

    // Plugin object
    const ImportMediaFromZip = {

        // Configuration
        config: {
            fileInput: '#zip-file-input',
            importButton: '#import-media-button',
            progressContainer: '#import-progress',
            progressBar: '#progress-bar',
            progressMessage: '#progress-message',
            resultsContainer: '#import-results',
            resultsContent: '#results-content',
            importAnotherButton: '#import-another',
            uploadArea: '#import-media-upload-area'
        },

        // State
        state: {
            isProcessing: false,
            selectedFile: null
        },

        /**
         * Initialize the plugin
         */
        init: function() {
            this.bindEvents();
            this.updateButtonState();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            const self = this;

            // File input change
            $(this.config.fileInput).on('change', function() {
                self.handleFileSelection(this);
            });

            // Import button click
            $(this.config.importButton).on('click', function() {
                self.handleImport();
            });

            // Import another button click
            $(this.config.importAnotherButton).on('click', function() {
                self.resetForm();
            });

            // Drag and drop support
            this.setupDragAndDrop();
        },

        /**
         * Setup drag and drop functionality
         */
        setupDragAndDrop: function() {
            const self = this;
            const $uploadArea = $(this.config.uploadArea);

            $uploadArea.on('dragover dragenter', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('drag-over');
            });

            $uploadArea.on('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('drag-over');
            });

            $uploadArea.on('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('drag-over');

                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    self.handleDroppedFile(files[0]);
                }
            });
        },

        /**
         * Handle file selection
         */
        handleFileSelection: function(input) {
            const file = input.files[0];

            if (file) {
                if (this.validateFile(file)) {
                    this.state.selectedFile = file;
                    this.updateButtonState();
                } else {
                    this.resetFileInput();
                }
            } else {
                this.state.selectedFile = null;
                this.updateButtonState();
            }
        },

        /**
         * Handle dropped file
         */
        handleDroppedFile: function(file) {
            if (this.validateFile(file)) {
                this.state.selectedFile = file;

                // Update file input
                const $fileInput = $(this.config.fileInput);
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                $fileInput[0].files = dataTransfer.files;

                this.updateButtonState();
            }
        },

        /**
         * Validate selected file
         */
        validateFile: function(file) {
            // Check file type
            if (file.type !== 'application/zip' && !file.name.toLowerCase().endsWith('.zip')) {
                this.showError(importMediaFromZip.strings.invalidFile);
                return false;
            }

            // Check file size
            const maxSize = 50 * 1024 * 1024; // 50MB hard limit
            if (file.size > maxSize) {
                this.showError(importMediaFromZip.strings.error + ': ' + this.formatFileSize(maxSize));
                return false;
            }

            // Warning for large files
            const warningSize = 10 * 1024 * 1024; // 10MB
            if (file.size > warningSize) {
                const message = importMediaFromZip.strings.warning + ': ' +
                               'ファイルサイズが大きいです (' + this.formatFileSize(file.size) + ')。' +
                               '処理に時間がかかる場合があります。';
                this.showWarning(message);
            }

            return true;
        },

        /**
         * Update button state
         */
        updateButtonState: function() {
            const $button = $(this.config.importButton);

            if (this.state.selectedFile && !this.state.isProcessing) {
                $button.prop('disabled', false);
            } else {
                $button.prop('disabled', true);
            }
        },

        /**
         * Handle import process
         */
        handleImport: function() {
            if (!this.state.selectedFile) {
                this.showError(importMediaFromZip.strings.noFile);
                return;
            }

            if (this.state.isProcessing) {
                return;
            }

            this.startImport();
        },

        /**
         * Start import process
         */
        startImport: function() {
            this.state.isProcessing = true;
            this.updateButtonState();
            this.showProgress();

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'import_media_from_zip');
            formData.append('post_id', importMediaFromZip.postId);
            formData.append('nonce', importMediaFromZip.nonce);
            formData.append('zip_file', this.state.selectedFile);

            // Start AJAX request
            this.performAjaxRequest(formData);
        },

        /**
         * Perform AJAX request
         */
        performAjaxRequest: function(formData) {
            const self = this;

            $.ajax({
                url: importMediaFromZip.ajaxUrl,
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
                            self.updateProgress(percentComplete, importMediaFromZip.strings.uploading);
                        }
                    });

                    return xhr;
                },
                success: function(response) {
                    self.handleAjaxSuccess(response);
                },
                error: function(xhr, status, error) {
                    self.handleAjaxError(xhr, status, error);
                }
            });
        },

        /**
         * Handle AJAX success
         */
        handleAjaxSuccess: function(response) {
            this.updateProgress(100, importMediaFromZip.strings.completed);

            setTimeout(() => {
                this.hideProgress();

                if (response.success) {
                    this.showResults(response.data);
                } else {
                    this.showError(response.data.message || importMediaFromZip.strings.error);
                    this.resetProcessingState();
                }
            }, 500);
        },

        /**
         * Handle AJAX error
         */
        handleAjaxError: function(xhr, status, error) {
            this.hideProgress();

            let errorMessage = importMediaFromZip.strings.error;

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
        showProgress: function() {
            $(this.config.uploadArea).hide();
            $(this.config.resultsContainer).hide();
            $(this.config.progressContainer).show();
            this.updateProgress(0, importMediaFromZip.strings.uploading);
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
        showResults: function(data) {
            const $resultsContent = $(this.config.resultsContent);
            $resultsContent.empty();

            // Success stats
            const statsHtml = this.buildStatsHtml(data);
            $resultsContent.append(statsHtml);

            // Validation warnings
            if (data.warnings && data.warnings.length > 0) {
                const warningsHtml = this.buildWarningsHtml(data.warnings);
                $resultsContent.append(warningsHtml);
            }

            // Failed matches
            if (data.failed_matches && data.failed_matches.length > 0) {
                const failedHtml = this.buildFailedMatchesHtml(data.failed_matches);
                $resultsContent.append(failedHtml);
            }

            // Import errors
            if (data.import_errors && Object.keys(data.import_errors).length > 0) {
                const errorsHtml = this.buildImportErrorsHtml(data.import_errors);
                $resultsContent.append(errorsHtml);
            }

            $(this.config.resultsContainer).show();

            // Ask to reload page
            if (data.updated_blocks > 0) {
                setTimeout(() => {
                    // Try to refresh the block editor first
                    if (window.wp && window.wp.data && window.wp.data.dispatch) {
                        try {
                            const { select, dispatch } = window.wp.data;
                            const coreEditor = dispatch('core/editor');

                            // Force refresh the editor content
                            if (coreEditor && coreEditor.refreshPost) {
                                coreEditor.refreshPost();
                                console.log('Block editor refreshed');
                            }
                        } catch (e) {
                            console.log('Could not refresh block editor:', e);
                        }
                    }

                    if (confirm(importMediaFromZip.strings.reloadPage)) {
                        window.location.reload();
                    }
                }, 1000);
            }
        },

        /**
         * Build stats HTML
         */
        buildStatsHtml: function(data) {
            return `
                <div class="results-stats">
                    <div class="result-success">
                        <strong>${importMediaFromZip.strings.success}</strong>
                        <ul>
                            <li>${importMediaFromZip.strings.importedImages}: ${data.imported_count}</li>
                            <li>${importMediaFromZip.strings.updatedBlocks}: ${data.updated_blocks}</li>
                        </ul>
                    </div>
                    <p>${data.message}</p>
                </div>
            `;
        },

        /**
         * Build warnings HTML
         */
        buildWarningsHtml: function(warnings) {
            let html = `
                <div class="validation-warnings">
                    <div class="result-warning">
                        <strong>ブロック検証警告</strong>
                        <ul>
            `;

            warnings.forEach(function(warning) {
                html += `<li>${warning}</li>`;
            });

            html += `
                        </ul>
                    </div>
                </div>
            `;

            return html;
        },

        /**
         * Build failed matches HTML
         */
        buildFailedMatchesHtml: function(failedMatches) {
            let html = `
                <div class="failed-matches">
                    <div class="result-warning">
                        <strong>${importMediaFromZip.strings.warning}</strong>
                        <p>${importMediaFromZip.strings.failedMatches}:</p>
                        <ul>
            `;

            failedMatches.forEach(function(match) {
                html += `<li>${match.message}</li>`;
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
         * Reset form
         */
        resetForm: function() {
            this.resetFileInput();
            this.resetProcessingState();
            $(this.config.resultsContainer).hide();
            $(this.config.uploadArea).show();
        },

        /**
         * Reset file input
         */
        resetFileInput: function() {
            $(this.config.fileInput).val('');
            this.state.selectedFile = null;
            this.updateButtonState();
        },

        /**
         * Reset processing state
         */
        resetProcessingState: function() {
            this.state.isProcessing = false;
            this.updateButtonState();
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
            $('.import-notice').remove();

            const noticeClass = type === 'error' ? 'notice-error' : 'notice-warning';
            const notice = $(`
                <div class="notice ${noticeClass} import-notice is-dismissible">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">この通知を閉じる</span>
                    </button>
                </div>
            `);

            // Insert before the meta box
            $('#import-media-container').before(notice);

            // Auto dismiss after 5 seconds
            setTimeout(function() {
                notice.fadeOut();
            }, 5000);

            // Handle dismiss button
            notice.find('.notice-dismiss').on('click', function() {
                notice.fadeOut();
            });
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
        // Only initialize if we're on a post editing screen and the meta box exists
        if ($('#import-media-container').length > 0) {
            ImportMediaFromZip.init();
        }
    });

})(jQuery);