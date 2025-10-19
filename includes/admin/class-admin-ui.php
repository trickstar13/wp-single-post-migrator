<?php

/**
 * Admin UI Class
 *
 * Handles the admin interface for the plugin
 */

if (!defined('ABSPATH')) {
  exit;
}

class IPBMFZ_Admin_UI
{

  /**
   * Constructor
   */
  public function __construct()
  {
    add_action('add_meta_boxes', array($this, 'add_meta_box'));
    add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
  }

  /**
   * Add meta box to post editing screens
   */
  public function add_meta_box()
  {
    $post_types = get_post_types(array('public' => true), 'names');

    foreach ($post_types as $post_type) {
      add_meta_box(
        'import-export-media-zip',
        __('Import/Export Post with Media', 'import-post-block-media-from-zip'),
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
  public function render_meta_box($post)
  {
    // Only show for saved posts (excluding auto-drafts)
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

    <div id="import-export-container">
      <!-- Export Section -->
      <div id="export-section" class="function-section">
        <h4><?php _e('エクスポート', 'import-post-block-media-from-zip'); ?></h4>
        <p><?php _e('この記事をXMLと画像ファイルを含むZIPファイルとしてエクスポートします。', 'import-post-block-media-from-zip'); ?></p>

        <div class="export-options">
          <label>
            <input type="checkbox" id="export-include-images" checked>
            <?php _e('画像ファイルを含める', 'import-post-block-media-from-zip'); ?>
          </label>
          <label>
            <input type="checkbox" id="export-include-meta" checked>
            <?php _e('メタフィールドを含める', 'import-post-block-media-from-zip'); ?>
          </label>
        </div>

        <p>
          <button
            type="button"
            id="export-post-button"
            class="button button-secondary"
            data-post-id="<?php echo esc_attr($post->ID); ?>">
            <?php _e('記事をエクスポート', 'import-post-block-media-from-zip'); ?>
          </button>
        </p>

        <div id="export-progress" style="display: none;">
          <div class="progress-bar-container">
            <div class="progress-bar" id="export-progress-bar"></div>
          </div>
          <p id="export-progress-message"><?php _e('エクスポート中...', 'import-post-block-media-from-zip'); ?></p>
        </div>
      </div>

      <!-- Import Section -->
      <div id="import-section" class="function-section">
        <h4><?php _e('インポート', 'import-post-block-media-from-zip'); ?></h4>
        <p><?php _e('XMLと画像を含むZIPファイルから記事をインポートします。', 'import-post-block-media-from-zip'); ?></p>

        <div class="import-options">
          <p>
            <strong><?php _e('現在の記事を置き換えてインポートします', 'import-post-block-media-from-zip'); ?></strong>
          </p>
          <label>
            <input type="checkbox" id="import-include-images" checked>
            <?php _e('画像ファイルをインポート', 'import-post-block-media-from-zip'); ?>
          </label>
          <label>
            <input type="checkbox" id="import-include-meta" checked>
            <?php _e('メタフィールドをインポート', 'import-post-block-media-from-zip'); ?>
          </label>
        </div>

        <p>
          <input
            type="file"
            id="import-zip-file-input"
            accept=".zip"
            style="width: 100%;" />
        </p>
        <p>
          <button
            type="button"
            id="import-post-button"
            class="button button-primary"
            disabled
            data-post-id="<?php echo esc_attr($post->ID); ?>">
            <?php _e('記事をインポート', 'import-post-block-media-from-zip'); ?>
          </button>
        </p>
      </div>

      <!-- Image Only Import Section -->
      <div id="image-import-section" class="function-section">
        <h4><?php _e('画像のみインポート', 'import-post-block-media-from-zip'); ?></h4>
        <p><?php _e('画像ファイルのみを含むZIPファイルから画像をインポートし、現在の記事内のブロックを更新します。', 'import-post-block-media-from-zip'); ?></p>

        <p>
          <input
            type="file"
            id="image-zip-file-input"
            accept=".zip"
            style="width: 100%;" />
        </p>
        <p>
          <button
            type="button"
            id="import-images-only-button"
            class="button button-secondary"
            disabled
            data-post-id="<?php echo esc_attr($post->ID); ?>">
            <?php _e('画像のみインポート', 'import-post-block-media-from-zip'); ?>
          </button>
        </p>
      </div>

      <!-- Progress Section -->
      <div id="operation-progress" style="display: none;">
        <div class="progress-bar-container">
          <div class="progress-bar" id="progress-bar"></div>
        </div>
        <p id="progress-message"><?php _e('処理中...', 'import-post-block-media-from-zip'); ?></p>
      </div>

      <!-- Results Section -->
      <div id="operation-results" style="display: none;">
        <h4 id="results-title"><?php _e('操作結果', 'import-post-block-media-from-zip'); ?></h4>
        <div id="results-content"></div>
        <p>
          <button type="button" id="perform-another" class="button">
            <?php _e('別の操作を実行', 'import-post-block-media-from-zip'); ?>
          </button>
        </p>
      </div>
    </div>

    <div class="plugin-info">
      <h4><?php _e('機能説明', 'import-post-block-media-from-zip'); ?></h4>
      <div class="info-tabs">
        <div class="info-tab">
          <h5><?php _e('エクスポート機能', 'import-post-block-media-from-zip'); ?></h5>
          <ul>
            <li><?php _e('記事データをXML形式でエクスポート', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('関連画像ファイルも一括エクスポート', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('メタフィールド（ACF等）も含めて出力', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('ZIPファイルとしてダウンロード', 'import-post-block-media-from-zip'); ?></li>
          </ul>
        </div>
        <div class="info-tab">
          <h5><?php _e('インポート機能', 'import-post-block-media-from-zip'); ?></h5>
          <ul>
            <li><?php _e('XMLファイルから記事データを復元', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('画像ファイルも自動的にインポート', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('新規作成または既存記事の置き換え', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('ブロック内の画像URLを自動更新', 'import-post-block-media-from-zip'); ?></li>
          </ul>
        </div>
        <div class="info-tab">
          <h5><?php _e('画像のみインポート', 'import-post-block-media-from-zip'); ?></h5>
          <ul>
            <li><?php _e('対応形式: JPG, PNG, GIF, WebP, SVG', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('ZIPファイル内のサブフォルダも対応', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('ファイル名が一致する画像ブロックを自動更新', 'import-post-block-media-from-zip'); ?></li>
            <li><?php _e('推奨: 10MB以下のZIPファイル', 'import-post-block-media-from-zip'); ?></li>
          </ul>
        </div>
      </div>
    </div>

    <style>
      .function-section {
        margin-bottom: 20px;
        padding: 15px;
        border: 1px solid #ddd;
        border-radius: 5px;
        background: #f9f9f9;
      }

      .function-section h4 {
        margin-top: 0;
        margin-bottom: 10px;
        color: #23282d;
        font-size: 14px;
      }

      .function-section p {
        margin-bottom: 10px;
        font-size: 12px;
        color: #666;
      }

      .export-options,
      .import-options {
        margin: 10px 0;
      }

      .export-options label,
      .import-options label {
        display: block;
        margin-bottom: 8px;
        font-size: 12px;
      }

      .export-options input,
      .import-options input {
        margin-right: 8px;
      }

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

      .plugin-info {
        margin-top: 20px;
        padding-top: 15px;
        border-top: 1px solid #ddd;
      }

      .plugin-info h4 {
        margin-bottom: 15px;
        font-size: 13px;
      }

      .info-tabs {
        display: flex;
        flex-direction: column;
        gap: 15px;
      }

      .info-tab {
        padding: 10px;
        background: #f0f0f1;
        border-radius: 3px;
      }

      .info-tab h5 {
        margin: 0 0 8px 0;
        font-size: 12px;
        color: #135e96;
      }

      .info-tab ul {
        font-size: 11px;
        margin: 0 0 0 15px;
        color: #666;
      }

      .info-tab li {
        margin-bottom: 4px;
      }

      #operation-results {
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

      #export-section {
        border-left: 4px solid #00a0d2;
      }

      #import-section {
        border-left: 4px solid #46b450;
      }

      #image-import-section {
        border-left: 4px solid #ffb900;
      }

      .download-link {
        display: inline-block;
        margin-top: 10px;
        padding: 8px 12px;
        background: #0073aa;
        color: white !important;
        text-decoration: none;
        border-radius: 3px;
        font-size: 12px;
      }

      .download-link:hover {
        background: #005a87;
        color: white !important;
      }

      @media (max-width: 782px) {
        .info-tabs {
          flex-direction: column;
        }
      }
    </style>

    <?php
  }

  /**
   * Enqueue admin scripts and styles
   *
   * @param string $hook Current admin page hook
   */
  public function enqueue_scripts($hook)
  {
    // Only load on post editing screens
    if (!in_array($hook, array('post.php', 'post-new.php'))) {
      return;
    }

    global $post;

    // Only load for saved posts (excluding auto-drafts)
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
    wp_localize_script('import-media-from-zip-admin', 'importExportMediaFromZip', array(
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('import_media_from_zip_nonce'),
      'postId' => $post->ID,
      'strings' => array(
        'selectFile' => __('ZIPファイルを選択してください', 'import-post-block-media-from-zip'),
        'uploading' => __('アップロード中...', 'import-post-block-media-from-zip'),
        'processing' => __('処理中...', 'import-post-block-media-from-zip'),
        'exporting' => __('エクスポート中...', 'import-post-block-media-from-zip'),
        'importing' => __('インポート中...', 'import-post-block-media-from-zip'),
        'completed' => __('完了しました', 'import-post-block-media-from-zip'),
        'error' => __('エラーが発生しました', 'import-post-block-media-from-zip'),
        'success' => __('成功', 'import-post-block-media-from-zip'),
        'warning' => __('警告', 'import-post-block-media-from-zip'),
        'noFile' => __('ファイルが選択されていません', 'import-post-block-media-from-zip'),
        'invalidFile' => __('ZIPファイルを選択してください', 'import-post-block-media-from-zip'),
        'exportSuccess' => __('エクスポートが完了しました', 'import-post-block-media-from-zip'),
        'importSuccess' => __('インポートが完了しました', 'import-post-block-media-from-zip'),
        'importedImages' => __('インポートされた画像数', 'import-post-block-media-from-zip'),
        'updatedBlocks' => __('更新されたブロック数', 'import-post-block-media-from-zip'),
        'failedMatches' => __('マッチングに失敗した画像', 'import-post-block-media-from-zip'),
        'exportedImages' => __('エクスポートされた画像数', 'import-post-block-media-from-zip'),
        'fileSize' => __('ファイルサイズ', 'import-post-block-media-from-zip'),
        'downloadFile' => __('ファイルをダウンロード', 'import-post-block-media-from-zip'),
        'updatedPost' => __('記事が更新されました', 'import-post-block-media-from-zip'),
        'confirmReplace' => __('現在の記事内容を置き換えてもよろしいですか？この操作は元に戻せません。', 'import-post-block-media-from-zip'),
        'reloadPage' => __('ページをリロードして変更を確認しますか？', 'import-post-block-media-from-zip'),
        'viewPost' => __('記事を表示', 'import-post-block-media-from-zip'),
        'editPost' => __('記事を編集', 'import-post-block-media-from-zip')
      )
    ));
  }

  /**
   * Display admin notice for successful import
   */
  public function display_success_notice()
  {
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
  private function get_supported_post_types()
  {
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
  private function should_show_meta_box()
  {
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
  public function add_help_tab()
  {
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
  private function get_help_content()
  {
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
