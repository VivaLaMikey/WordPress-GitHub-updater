<?php
/**
 * Admin class - handles admin UI and AJAX
 *
 * @package GitHub_Branch_Deploy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GBD_Admin {

    /**
     * GitHub API instance
     */
    private $github_api;

    /**
     * Deployer instance
     */
    private $deployer;

    /**
     * Constructor
     */
    public function __construct( GBD_GitHub_API $github_api, GBD_Deployer $deployer ) {
        $this->github_api = $github_api;
        $this->deployer   = $deployer;

        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 100 );
        add_action( 'admin_head', array( $this, 'admin_bar_styles' ) );
        add_action( 'wp_head', array( $this, 'admin_bar_styles' ) );
        add_action( 'admin_footer', array( $this, 'admin_bar_scripts' ) );
        add_action( 'wp_footer', array( $this, 'admin_bar_scripts' ) );

        // AJAX handlers
        add_action( 'wp_ajax_gbd_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_gbd_get_branches', array( $this, 'ajax_get_branches' ) );
        add_action( 'wp_ajax_gbd_deploy_now', array( $this, 'ajax_deploy_now' ) );
        add_action( 'wp_ajax_gbd_get_log', array( $this, 'ajax_get_log' ) );
        add_action( 'wp_ajax_gbd_clear_log', array( $this, 'ajax_clear_log' ) );
        add_action( 'wp_ajax_gbd_regenerate_secret', array( $this, 'ajax_regenerate_secret' ) );
        add_action( 'wp_ajax_gbd_switch_branch', array( $this, 'ajax_switch_branch' ) );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'GitHub Deploy', 'github-branch-deploy' ),
            __( 'GitHub Deploy', 'github-branch-deploy' ),
            'manage_options',
            'github-branch-deploy',
            array( $this, 'render_admin_page' ),
            'dashicons-cloud-upload',
            80
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting( 'gbd_settings', 'gbd_github_repo', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        register_setting( 'gbd_settings', 'gbd_github_token', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ) );

        register_setting( 'gbd_settings', 'gbd_source_path', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_path' ),
            'default'           => 'client-site/wp-content',
        ) );

        register_setting( 'gbd_settings', 'gbd_selected_branch', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'main',
        ) );

        register_setting( 'gbd_settings', 'gbd_auto_deploy', array(
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => true,
        ) );
    }

    /**
     * Sanitize path
     */
    public function sanitize_path( $path ) {
        $path = sanitize_text_field( $path );
        $path = trim( $path, '/' );
        return $path;
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_github-branch-deploy' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'gbd-admin',
            GBD_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            GBD_VERSION
        );

        wp_enqueue_script(
            'gbd-admin',
            GBD_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            GBD_VERSION,
            true
        );

        wp_localize_script( 'gbd-admin', 'gbdAdmin', array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'gbd_admin_nonce' ),
            'strings'    => array(
                'deploying'        => __( 'Deploying...', 'github-branch-deploy' ),
                'deploySuccess'    => __( 'Deployment completed successfully!', 'github-branch-deploy' ),
                'deployFailed'     => __( 'Deployment failed.', 'github-branch-deploy' ),
                'testing'          => __( 'Testing connection...', 'github-branch-deploy' ),
                'connectionOk'     => __( 'Connection successful!', 'github-branch-deploy' ),
                'connectionFailed' => __( 'Connection failed.', 'github-branch-deploy' ),
                'loadingBranches'  => __( 'Loading branches...', 'github-branch-deploy' ),
                'confirmDeploy'    => __( 'Are you sure you want to deploy now?', 'github-branch-deploy' ),
                'confirmClearLog'  => __( 'Are you sure you want to clear the deployment log?', 'github-branch-deploy' ),
            ),
        ) );
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        include GBD_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * AJAX: Test GitHub connection
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'gbd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'github-branch-deploy' ) ) );
        }

        $result = $this->github_api->test_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Get branches
     */
    public function ajax_get_branches() {
        check_ajax_referer( 'gbd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'github-branch-deploy' ) ) );
        }

        $branches = $this->github_api->get_branches();

        if ( is_wp_error( $branches ) ) {
            wp_send_json_error( array( 'message' => $branches->get_error_message() ) );
        }

        wp_send_json_success( array( 'branches' => $branches ) );
    }

    /**
     * AJAX: Deploy now
     */
    public function ajax_deploy_now() {
        check_ajax_referer( 'gbd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'github-branch-deploy' ) ) );
        }

        $branch = isset( $_POST['branch'] ) ? sanitize_text_field( $_POST['branch'] ) : null;

        if ( empty( $branch ) ) {
            $branch = GitHub_Branch_Deploy::get_option( 'selected_branch', 'main' );
        }

        $result = $this->deployer->deploy( $branch, 'manual (admin)' );

        if ( $result['status'] === 'success' ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * AJAX: Get deployment log
     */
    public function ajax_get_log() {
        check_ajax_referer( 'gbd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'github-branch-deploy' ) ) );
        }

        $limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 20;
        $log   = $this->deployer->get_log( $limit );

        wp_send_json_success( array( 'log' => $log ) );
    }

    /**
     * AJAX: Clear log
     */
    public function ajax_clear_log() {
        check_ajax_referer( 'gbd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'github-branch-deploy' ) ) );
        }

        $this->deployer->clear_log();

        wp_send_json_success( array( 'message' => __( 'Log cleared.', 'github-branch-deploy' ) ) );
    }

    /**
     * AJAX: Regenerate webhook secret
     */
    public function ajax_regenerate_secret() {
        check_ajax_referer( 'gbd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'github-branch-deploy' ) ) );
        }

        $new_secret = wp_generate_password( 32, false );
        GitHub_Branch_Deploy::update_option( 'webhook_secret', $new_secret );

        wp_send_json_success( array(
            'secret'  => $new_secret,
            'message' => __( 'Webhook secret regenerated. Remember to update it in GitHub!', 'github-branch-deploy' ),
        ) );
    }

    /**
     * AJAX: Switch branch
     */
    public function ajax_switch_branch() {
        check_ajax_referer( 'gbd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'github-branch-deploy' ) ) );
        }

        $branch = isset( $_POST['branch'] ) ? sanitize_text_field( $_POST['branch'] ) : '';

        if ( empty( $branch ) ) {
            wp_send_json_error( array( 'message' => __( 'No branch specified.', 'github-branch-deploy' ) ) );
        }

        GitHub_Branch_Deploy::update_option( 'selected_branch', $branch );

        wp_send_json_success( array(
            'branch'  => $branch,
            'message' => sprintf( __( 'Switched to branch: %s', 'github-branch-deploy' ), $branch ),
        ) );
    }

    /**
     * Add admin bar menu
     */
    public function add_admin_bar_menu( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $current_branch = GitHub_Branch_Deploy::get_option( 'selected_branch', 'main' );
        $is_main = ( $current_branch === 'main' );

        // Main menu item
        $wp_admin_bar->add_node( array(
            'id'    => 'gbd-branch',
            'title' => '<span class="ab-icon dashicons dashicons-cloud-upload"></span>' .
                       '<span class="gbd-branch-label">Branch: </span>' .
                       '<span class="gbd-branch-name' . ( ! $is_main ? ' gbd-branch-warning' : '' ) . '">' .
                       esc_html( $current_branch ) . '</span>',
            'href'  => admin_url( 'admin.php?page=github-branch-deploy' ),
            'meta'  => array(
                'title' => __( 'GitHub Deploy - Current Branch', 'github-branch-deploy' ),
            ),
        ) );

        // Loading indicator (hidden by default)
        $wp_admin_bar->add_node( array(
            'parent' => 'gbd-branch',
            'id'     => 'gbd-loading',
            'title'  => '<span class="gbd-loading-text">Loading branches...</span>',
            'meta'   => array(
                'class' => 'gbd-loading-item',
            ),
        ) );
    }

    /**
     * Admin bar styles
     */
    public function admin_bar_styles() {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <style>
            #wpadminbar #wp-admin-bar-gbd-branch > .ab-item {
                display: flex;
                align-items: center;
                gap: 4px;
            }
            #wpadminbar #wp-admin-bar-gbd-branch .ab-icon {
                top: 2px;
                margin-right: 2px;
            }
            #wpadminbar #wp-admin-bar-gbd-branch .ab-icon:before {
                font-size: 16px;
            }
            #wpadminbar .gbd-branch-label {
                opacity: 0.7;
            }
            #wpadminbar .gbd-branch-name {
                font-weight: 600;
                color: #00b9eb;
            }
            #wpadminbar .gbd-branch-warning {
                color: #ffb900 !important;
                background: rgba(255, 185, 0, 0.15);
                padding: 2px 6px;
                border-radius: 3px;
            }
            #wpadminbar #wp-admin-bar-gbd-branch .ab-submenu {
                min-width: 200px;
            }
            #wpadminbar .gbd-branch-item .ab-item {
                display: flex !important;
                justify-content: space-between;
                align-items: center;
            }
            #wpadminbar .gbd-branch-item .gbd-current-indicator {
                color: #00b9eb;
                font-weight: bold;
            }
            #wpadminbar .gbd-branch-item.gbd-active .ab-item {
                background: rgba(0, 185, 235, 0.1) !important;
            }
            #wpadminbar .gbd-loading-item {
                display: block;
            }
            #wpadminbar .gbd-loading-text {
                color: #999;
                font-style: italic;
            }
            #wpadminbar .gbd-branch-item .ab-item:hover {
                background: rgba(0, 185, 235, 0.2) !important;
            }
            #wpadminbar #wp-admin-bar-gbd-branch .gbd-switching {
                opacity: 0.5;
                pointer-events: none;
            }
        </style>
        <?php
    }

    /**
     * Admin bar scripts
     */
    public function admin_bar_scripts() {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <script>
        (function($) {
            var gbdBranchesLoaded = false;
            var gbdCurrentBranch = '<?php echo esc_js( GitHub_Branch_Deploy::get_option( 'selected_branch', 'main' ) ); ?>';
            var gbdNonce = '<?php echo wp_create_nonce( 'gbd_admin_nonce' ); ?>';
            var gbdAjaxUrl = '<?php echo admin_url( 'admin-ajax.php' ); ?>';

            // Load branches when hovering over the menu
            $('#wp-admin-bar-gbd-branch').on('mouseenter', function() {
                if (gbdBranchesLoaded) return;
                loadBranches();
            });

            function loadBranches() {
                $.ajax({
                    url: gbdAjaxUrl,
                    type: 'POST',
                    data: {
                        action: 'gbd_get_branches',
                        nonce: gbdNonce
                    },
                    success: function(response) {
                        if (response.success && response.data.branches) {
                            renderBranches(response.data.branches);
                            gbdBranchesLoaded = true;
                        } else {
                            $('#wp-admin-bar-gbd-loading .gbd-loading-text').text('Failed to load branches');
                        }
                    },
                    error: function() {
                        $('#wp-admin-bar-gbd-loading .gbd-loading-text').text('Error loading branches');
                    }
                });
            }

            function renderBranches(branches) {
                var $submenu = $('#wp-admin-bar-gbd-branch .ab-submenu');

                // Remove loading item
                $('#wp-admin-bar-gbd-loading').remove();

                // Add branch items
                branches.forEach(function(branch) {
                    var isActive = (branch.name === gbdCurrentBranch);
                    var $item = $('<li class="gbd-branch-item' + (isActive ? ' gbd-active' : '') + '" data-branch="' + branch.name + '">' +
                        '<a class="ab-item" href="#">' +
                        '<span class="gbd-branch-name-item">' + branch.name + '</span>' +
                        (isActive ? '<span class="gbd-current-indicator">●</span>' : '') +
                        '</a></li>');

                    $submenu.append($item);
                });

                // Add click handlers
                $submenu.on('click', '.gbd-branch-item', function(e) {
                    e.preventDefault();
                    var branch = $(this).data('branch');
                    if (branch !== gbdCurrentBranch) {
                        switchBranch(branch);
                    }
                });
            }

            function switchBranch(branch) {
                var $menu = $('#wp-admin-bar-gbd-branch');
                $menu.addClass('gbd-switching');

                $.ajax({
                    url: gbdAjaxUrl,
                    type: 'POST',
                    data: {
                        action: 'gbd_switch_branch',
                        nonce: gbdNonce,
                        branch: branch
                    },
                    success: function(response) {
                        if (response.success) {
                            gbdCurrentBranch = branch;

                            // Update display
                            var $branchName = $menu.find('.gbd-branch-name').first();
                            $branchName.text(branch);

                            if (branch === 'main') {
                                $branchName.removeClass('gbd-branch-warning');
                            } else {
                                $branchName.addClass('gbd-branch-warning');
                            }

                            // Update active state in dropdown
                            $('.gbd-branch-item').removeClass('gbd-active')
                                .find('.gbd-current-indicator').remove();
                            $('.gbd-branch-item[data-branch="' + branch + '"]')
                                .addClass('gbd-active')
                                .find('.ab-item').append('<span class="gbd-current-indicator">●</span>');

                            // Brief success flash
                            $branchName.css('background', 'rgba(0, 185, 0, 0.3)');
                            setTimeout(function() {
                                $branchName.css('background', '');
                            }, 500);
                        } else {
                            alert('Failed to switch branch: ' + (response.data.message || 'Unknown error'));
                        }
                    },
                    error: function() {
                        alert('Error switching branch');
                    },
                    complete: function() {
                        $menu.removeClass('gbd-switching');
                    }
                });
            }
        })(jQuery);
        </script>
        <?php
    }
}
