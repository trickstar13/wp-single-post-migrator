<?php

/**
 * Plugin Name: WP Single Post Migrator
 * Plugin URI: https://github.com/trickstar13/wp-single-post-migrator
 * Description: WordPress記事を画像ファイルと共にZIP形式で完全にエクスポート・インポートできる包括的な移行ツール。
 * Version: 2.3.0
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Author: Ayumi Sato
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-single-post-migrator
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
  exit;
}

// Plugin constants
define('IPBMFZ_VERSION', '2.3.0');
define('IPBMFZ_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IPBMFZ_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IPBMFZ_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class Import_Post_Block_Media_From_ZIP
{

  /**
   * Plugin instance
   */
  private static $instance = null;

  /**
   * Get plugin instance
   */
  public static function get_instance()
  {
    if (null === self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Constructor
   */
  private function __construct()
  {
    add_action('init', array($this, 'init'));
    register_activation_hook(__FILE__, array($this, 'activate'));
    register_deactivation_hook(__FILE__, array($this, 'deactivate'));
  }

  /**
   * Initialize plugin
   */
  public function init()
  {
    // Check if ZipArchive is available
    if (!class_exists('ZipArchive')) {
      add_action('admin_notices', array($this, 'zip_extension_notice'));
      return;
    }

    // Load required files
    $this->load_dependencies();

    // Initialize classes
    if (is_admin()) {
      new IPBMFZ_Admin_UI();
    }
  }

  /**
   * Load plugin dependencies
   */
  private function load_dependencies()
  {
    require_once IPBMFZ_PLUGIN_DIR . 'includes/class-zip-handler.php';
    require_once IPBMFZ_PLUGIN_DIR . 'includes/class-media-importer.php';
    require_once IPBMFZ_PLUGIN_DIR . 'includes/class-block-updater.php';
    require_once IPBMFZ_PLUGIN_DIR . 'includes/class-synced-pattern-handler.php';
    require_once IPBMFZ_PLUGIN_DIR . 'includes/class-post-exporter.php';
    require_once IPBMFZ_PLUGIN_DIR . 'includes/class-post-importer.php';
    require_once IPBMFZ_PLUGIN_DIR . 'includes/admin/class-admin-ui.php';
    require_once IPBMFZ_PLUGIN_DIR . 'includes/admin/ajax-handlers.php';
  }

  /**
   * Plugin activation hook
   */
  public function activate()
  {
    // Check WordPress version
    if (version_compare(get_bloginfo('version'), '5.9', '<')) {
      deactivate_plugins(IPBMFZ_PLUGIN_BASENAME);
      wp_die(__('このプラグインはWordPress 5.9以上が必要です。', 'wp-single-post-migrator'));
    }

    // Check PHP version
    if (version_compare(PHP_VERSION, '7.4', '<')) {
      deactivate_plugins(IPBMFZ_PLUGIN_BASENAME);
      wp_die(__('このプラグインはPHP 7.4以上が必要です。', 'wp-single-post-migrator'));
    }

    // Check ZipArchive extension
    if (!class_exists('ZipArchive')) {
      deactivate_plugins(IPBMFZ_PLUGIN_BASENAME);
      wp_die(__('このプラグインはPHP ZipArchive拡張が必要です。', 'wp-single-post-migrator'));
    }
  }

  /**
   * Plugin deactivation hook
   */
  public function deactivate()
  {
    // Cleanup any temporary files if needed
    $this->cleanup_temp_files();
  }

  /**
   * Display notice if ZipArchive extension is not available
   */
  public function zip_extension_notice()
  {
?>
    <div class="notice notice-error">
      <p><?php _e('WP Single Post Migrator: このサーバーはZIPファイルの展開に対応していません。PHP ZipArchive拡張を有効にしてください。', 'wp-single-post-migrator'); ?></p>
    </div>
<?php
  }

  /**
   * Cleanup temporary files
   */
  private function cleanup_temp_files()
  {
    $upload_dir = wp_upload_dir();
    $temp_pattern = $upload_dir['basedir'] . '/temp-import-*';

    foreach (glob($temp_pattern, GLOB_ONLYDIR) as $temp_dir) {
      $this->delete_directory($temp_dir);
    }
  }

  /**
   * Recursively delete directory
   */
  private function delete_directory($dir)
  {
    if (!is_dir($dir)) {
      return;
    }

    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
      $path = $dir . '/' . $file;
      if (is_dir($path)) {
        $this->delete_directory($path);
      } else {
        unlink($path);
      }
    }
    rmdir($dir);
  }
}

/**
 * Initialize plugin
 */
function import_post_block_media_from_zip()
{
  return Import_Post_Block_Media_From_ZIP::get_instance();
}

// Start the plugin
import_post_block_media_from_zip();
