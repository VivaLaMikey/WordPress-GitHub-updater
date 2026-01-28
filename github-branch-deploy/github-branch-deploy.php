<?php
/**
 * Plugin Name: GitHub Branch Deploy
 * Plugin URI: https://github.com/your-repo/github-branch-deploy
 * Description: Deploy WordPress files from a selected GitHub branch with automatic webhook deployments.
 * Version: 1.1.0
 * Author: GuardCore
 * Author URI: https://guardcore.co.uk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: github-branch-deploy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GBD_VERSION', '1.1.0' );
define( 'GBD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GBD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GBD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class
 */
final class GitHub_Branch_Deploy {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Plugin components
     */
    public $admin;
    public $github_api;
    public $deployer;
    public $webhook_handler;

    /**
     * Get instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->register_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once GBD_PLUGIN_DIR . 'includes/class-github-api.php';
        require_once GBD_PLUGIN_DIR . 'includes/class-deployer.php';
        require_once GBD_PLUGIN_DIR . 'includes/class-webhook-handler.php';
        require_once GBD_PLUGIN_DIR . 'includes/class-admin.php';
    }

    /**
     * Initialize components
     */
    private function init_components() {
        $this->github_api      = new GBD_GitHub_API();
        $this->deployer        = new GBD_Deployer( $this->github_api );
        $this->webhook_handler = new GBD_Webhook_Handler( $this->deployer );
        $this->admin           = new GBD_Admin( $this->github_api, $this->deployer );
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'rest_api_init', array( $this->webhook_handler, 'register_routes' ) );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $defaults = array(
            'github_repo'       => '',
            'github_token'      => '',
            'webhook_secret'    => wp_generate_password( 32, false ),
            'source_path'       => 'client-site/wp-content',
            'selected_branch'   => 'main',
            'deploy_log'        => array(),
            'last_deploy'       => null,
            'auto_deploy'       => true,
        );

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( 'gbd_' . $key ) ) {
                add_option( 'gbd_' . $key, $value );
            }
        }

        // Create temp directory
        $temp_dir = WP_CONTENT_DIR . '/gbd-temp';
        if ( ! file_exists( $temp_dir ) ) {
            wp_mkdir_p( $temp_dir );
            file_put_contents( $temp_dir . '/.htaccess', 'Deny from all' );
            file_put_contents( $temp_dir . '/index.php', '<?php // Silence is golden' );
        }

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Get plugin option
     */
    public static function get_option( $key, $default = false ) {
        return get_option( 'gbd_' . $key, $default );
    }

    /**
     * Update plugin option
     */
    public static function update_option( $key, $value ) {
        return update_option( 'gbd_' . $key, $value );
    }
}

/**
 * Initialize plugin
 */
function github_branch_deploy() {
    return GitHub_Branch_Deploy::get_instance();
}

// Start the plugin
add_action( 'plugins_loaded', 'github_branch_deploy' );
