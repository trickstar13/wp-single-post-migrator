<?php
/**
 * Plugin Name: WP Single Post Migrator
 * Plugin URI: https://github.com/trickstar13/wp-single-post-migrator
 * Description: WordPress plugin for importing and exporting posts with their associated media files and synced patterns
 * Version: 1.0.1
 * Author: Ayumi Sato
 * Text Domain: wp-single-post-migrator
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IPBMFZ_VERSION', '1.0.0');
define('IPBMFZ_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IPBMFZ_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IPBMFZ_PLUGIN_BASENAME', plugin_basename(__FILE__));

class IPBMFZ_WP_Single_Post_Migrator {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'init'));
    }

    public function init() {
        if (!$this->check_requirements()) {
            return;
        }

        $this->load_dependencies();
        $this->init_hooks();
    }

    private function check_requirements() {
        if (!class_exists('ZipArchive')) {
            add_action('admin_notices', array($this, 'zip_archive_notice'));
            return false;
        }

        global $wp_version;
        if (version_compare($wp_version, '5.9', '<')) {
            add_action('admin_notices', array($this, 'wp_version_notice'));
            return false;
        }

        return true;
    }

    private function load_dependencies() {
        require_once IPBMFZ_PLUGIN_DIR . 'includes/class-zip-handler.php';
        require_once IPBMFZ_PLUGIN_DIR . 'includes/class-media-importer.php';
        require_once IPBMFZ_PLUGIN_DIR . 'includes/class-block-updater.php';
        require_once IPBMFZ_PLUGIN_DIR . 'includes/class-post-exporter.php';
        require_once IPBMFZ_PLUGIN_DIR . 'includes/class-post-importer.php';
        require_once IPBMFZ_PLUGIN_DIR . 'includes/class-synced-pattern-handler.php';
        require_once IPBMFZ_PLUGIN_DIR . 'includes/admin/class-admin-ui.php';
        require_once IPBMFZ_PLUGIN_DIR . 'includes/admin/ajax-handlers.php';
    }

    private function init_hooks() {
        if (is_admin()) {
            new IPBMFZ_Admin_UI();
        }
    }

    public function zip_archive_notice() {
        echo '<div class="notice notice-error"><p>';
        _e('WP Single Post Migrator requires ZipArchive PHP extension to be enabled.', 'wp-single-post-migrator');
        echo '</p></div>';
    }

    public function wp_version_notice() {
        echo '<div class="notice notice-error"><p>';
        _e('WP Single Post Migrator requires WordPress 5.9 or higher.', 'wp-single-post-migrator');
        echo '</p></div>';
    }
}

function IPBMFZ() {
    return IPBMFZ_WP_Single_Post_Migrator::get_instance();
}

IPBMFZ();

register_activation_hook(__FILE__, function() {
    if (!class_exists('ZipArchive')) {
        wp_die(__('This plugin requires ZipArchive PHP extension to be enabled.', 'wp-single-post-migrator'));
    }
});