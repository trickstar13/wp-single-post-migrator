<?php
/**
 * Admin UI Class
 *
 * Handles the admin interface for the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class IPBMFZ_Admin_UI {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Add meta box to post editing screens
     */
    public function add_meta_box() {
        $post_types = get_post_types(array('public' => true), 'names');

        foreach ($post_types as $post_type) {
            add_meta_box(
                'import-media-from-zip',
                __('Import Media from ZIP', 'import-post-block-media-from-zip'),
                array($this, 'render_meta_box'),
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Render meta box content
     *
     * @param WP_Post $post Current post object
     */
    public function render_meta_box($post) {
        // Only show for existing posts
        if (!$post->ID || $post->post_status === 'auto-draft') {
            ?>
            <p><?php _e('この機能は記事を保存した後に利用できます。', 'import-post-block-media-from-zip'); ?></p>
            <?php
            return;
        }

        // Check user permissions
        if (!current_user_can('edit_post', $post->ID)) {
            ?>
            <p><?php _e('この記事を編集する権限がありません。', 'import-post-block-media-from-zip'); ?></p>
            <?php
            return;
        }

        wp_nonce_field('import_media_from_zip_nonce', 'import_media_from_zip_nonce');
        ?>

        <div id="import-media-container">
            <div id="import-media-upload-area">
                <p>
                    <label for="zip-file-input">
                        <?php _e('ZIPファイルを選択してください:', 'import-post-block-media-from-zip'); ?>
                    </label>
                </p>
                <p>
                    <input
                        type="file"
                        id="zip-file-input"
                        accept=".zip"
                        style="width: 100%;"
                    />
                </p>
                <p>
                    <button
                        type="button"
                        id="import-media-button"
                        class="button button-primary"
                        disabled
                        data-post-id="<?php echo esc_attr($post->ID); ?>"
                    >
                        <?php _e('画像をインポート', 'import-post-block-media-from-zip'); ?>
                    </button>
                </p>
                <div id="import-progress" style="display: none;">
                    <div class="progress-bar-container">
                        <div class="progress-bar" id="progress-bar"></div>
                    </div>
                    <p id="progress-message"><?php _e('処理中...', 'import-post-block-media-from-zip'); ?></p>
                </div>
            </div>

            <div id="import-results" style="display: none;">
                <h4><?php _e('インポート結果', 'import-post-block-media-from-zip'); ?></h4>
                <div id="results-content"></div>
                <p>
                    <button type="button" id="import-another" class="button">
                        <?php _e('別のZIPファイルをインポート', 'import-post-block-media-from-zip'); ?>
                    </button>
                </p>
            </div>
        </div>

        <div class="import-info">
            <h4><?php _e('使用方法', 'import-post-block-media-from-zip'); ?></h4>
            <ul>
                <li><?php _e('対応形式: JPG, PNG, GIF, WebP, SVG', 'import-post-block-media-from-zip'); ?></li>
                <li><?php _e('ZIPファイル内のサブフォルダも対応', 'import-post-block-media-from-zip'); ?></li>
                <li><?php _e('ファイル名が一致する画像ブロックを自動更新', 'import-post-block-media-from-zip'); ?></li>
                <li><?php _e('推奨: 10MB以下のZIPファイル', 'import-post-block-media-from-zip'); ?></li>
            </ul>
        </div>

        <style>
        .progress-bar-container {
            width: 100%;
            height: 20px;
            background-color: #f1f1f1;
            border-radius: 3px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-bar {
            height: 100%;
            background-color: #0073aa;
            width: 0%;
            transition: width 0.3s ease;
        }
        .import-info {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }
        .import-info h4 {
            margin-bottom: 10px;
            font-size: 13px;
        }
        .import-info ul {
            font-size: 12px;
            margin-left: 15px;
        }
        .import-info li {
            margin-bottom: 5px;
        }
        #import-results {
            padding: 15px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 3px;
            margin-top: 15px;
        }
        .result-success {
            color: #46b450;
        }
        .result-warning {
            color: #ffb900;
        }
        .result-error {
            color: #dc3232;
        }
        .results-stats {
            margin-bottom: 15px;
        }
        .results-stats strong {
            display: block;
            margin-bottom: 5px;
        }
        .failed-matches {
            margin-top: 10px;
        }
        .failed-matches ul {
            margin-left: 15px;
            font-size: 12px;
        }
        </style>

        <?php
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_scripts($hook) {
        // Only load on post editing screens
        if (!in_array($hook, array('post.php', 'post-new.php'))) {
            return;
        }

        global $post;

        // Only load for existing posts
        if (!$post || !$post->ID || $post->post_status === 'auto-draft') {
            return;
        }

        // Check if user can edit this post
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }

        wp_enqueue_script(
            'import-media-from-zip-admin',
            IPBMFZ_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            IPBMFZ_VERSION,
            true
        );

        wp_enqueue_style(
            'import-media-from-zip-admin',
            IPBMFZ_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            IPBMFZ_VERSION
        );

        // Localize script
        wp_localize_script('import-media-from-zip-admin', 'importMediaFromZip', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('import_media_from_zip_nonce'),
            'postId' => $post->ID,
            'strings' => array(
                'selectFile' => __('ZIPファイルを選択してください', 'import-post-block-media-from-zip'),
                'uploading' => __('アップロード中...', 'import-post-block-media-from-zip'),
                'processing' => __('処理中...', 'import-post-block-media-from-zip'),
                'completed' => __('完了しました', 'import-post-block-media-from-zip'),
                'error' => __('エラーが発生しました', 'import-post-block-media-from-zip'),
                'success' => __('成功', 'import-post-block-media-from-zip'),
                'warning' => __('警告', 'import-post-block-media-from-zip'),
                'noFile' => __('ファイルが選択されていません', 'import-post-block-media-from-zip'),
                'invalidFile' => __('ZIPファイルを選択してください', 'import-post-block-media-from-zip'),
                'importedImages' => __('インポートされた画像数', 'import-post-block-media-from-zip'),
                'updatedBlocks' => __('更新されたブロック数', 'import-post-block-media-from-zip'),
                'failedMatches' => __('マッチングに失敗した画像', 'import-post-block-media-from-zip'),
                'reloadPage' => __('ページをリロードして変更を確認しますか？', 'import-post-block-media-from-zip')
            )
        ));
    }

    /**
     * Display admin notice for successful import
     */
    public function display_success_notice() {
        if (isset($_GET['import_success']) && $_GET['import_success'] === '1') {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('画像のインポートが完了しました。', 'import-post-block-media-from-zip'); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Get supported post types
     *
     * @return array Array of supported post types
     */
    private function get_supported_post_types() {
        $post_types = get_post_types(array(
            'public' => true,
            'show_ui' => true
        ), 'objects');

        $supported = array();
        foreach ($post_types as $post_type) {
            // Check if post type supports editor (block editor)
            if (post_type_supports($post_type->name, 'editor')) {
                $supported[] = $post_type->name;
            }
        }

        return $supported;
    }

    /**
     * Check if current screen should show the meta box
     *
     * @return bool
     */
    private function should_show_meta_box() {
        $screen = get_current_screen();

        if (!$screen) {
            return false;
        }

        // Check if we're on a post editing screen
        if ($screen->base !== 'post') {
            return false;
        }

        // Check if post type supports editor
        if (!post_type_supports($screen->post_type, 'editor')) {
            return false;
        }

        // Check if block editor is enabled
        if (!use_block_editor_for_post_type($screen->post_type)) {
            return false;
        }

        return true;
    }

    /**
     * Add help tab to the admin screen
     */
    public function add_help_tab() {
        $screen = get_current_screen();

        if (!$this->should_show_meta_box()) {
            return;
        }

        $screen->add_help_tab(array(
            'id' => 'import-media-from-zip-help',
            'title' => __('Import Media from ZIP', 'import-post-block-media-from-zip'),
            'content' => $this->get_help_content()
        ));
    }

    /**
     * Get help content
     *
     * @return string Help content HTML
     */
    private function get_help_content() {
        ob_start();
        ?>
        <h3><?php _e('Import Media from ZIP の使用方法', 'import-post-block-media-from-zip'); ?></h3>

        <h4><?php _e('基本的な使い方', 'import-post-block-media-from-zip'); ?></h4>
        <ol>
            <li><?php _e('記事を保存してから機能を使用してください', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('サイドバーの「Import Media from ZIP」セクションでZIPファイルを選択', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('「画像をインポート」ボタンをクリック', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('処理完了後、ページをリロードして確認', 'import-post-block-media-from-zip'); ?></li>
        </ol>

        <h4><?php _e('対応ファイル形式', 'import-post-block-media-from-zip'); ?></h4>
        <ul>
            <li>JPG/JPEG</li>
            <li>PNG</li>
            <li>GIF</li>
            <li>WebP</li>
            <li>SVG</li>
        </ul>

        <h4><?php _e('注意事項', 'import-post-block-media-from-zip'); ?></h4>
        <ul>
            <li><?php _e('ファイル名が一致する画像ブロックのみ自動更新されます', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('同名ファイルが既に存在する場合は自動的にリネームされます', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('ZIPファイルは処理後に自動削除されます', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('推奨ZIPファイルサイズ: 10MB以下', 'import-post-block-media-from-zip'); ?></li>
        </ul>
        <?php
        return ob_get_clean();
    }
}