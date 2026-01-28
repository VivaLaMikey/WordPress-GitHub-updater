<?php
/**
 * Webhook Handler class - processes GitHub webhooks
 *
 * @package GitHub_Branch_Deploy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GBD_Webhook_Handler {

    /**
     * Deployer instance
     */
    private $deployer;

    /**
     * Rate limit window (seconds)
     */
    const RATE_LIMIT_WINDOW = 60;

    /**
     * Max requests per window
     */
    const RATE_LIMIT_MAX = 10;

    /**
     * Constructor
     */
    public function __construct( GBD_Deployer $deployer ) {
        $this->deployer = $deployer;
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        register_rest_route( 'github-deploy/v1', '/webhook', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => '__return_true', // We verify signature manually
        ) );

        register_rest_route( 'github-deploy/v1', '/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_status' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Handle incoming webhook
     */
    public function handle_webhook( WP_REST_Request $request ) {
        // Rate limiting
        if ( $this->is_rate_limited() ) {
            return new WP_REST_Response(
                array( 'error' => 'Rate limit exceeded' ),
                429
            );
        }

        // Get raw payload for signature verification
        $payload   = $request->get_body();
        $signature = $request->get_header( 'X-Hub-Signature-256' );
        $event     = $request->get_header( 'X-GitHub-Event' );

        // Log webhook receipt
        $this->log_webhook( $event, $signature ? 'present' : 'missing' );

        // Verify signature
        if ( ! GBD_GitHub_API::verify_webhook_signature( $payload, $signature ) ) {
            return new WP_REST_Response(
                array( 'error' => 'Invalid signature' ),
                403
            );
        }

        // Only process push events
        if ( $event !== 'push' ) {
            return new WP_REST_Response(
                array( 'message' => 'Event ignored', 'event' => $event ),
                200
            );
        }

        // Parse payload
        $data = json_decode( $payload, true );

        if ( ! $data ) {
            return new WP_REST_Response(
                array( 'error' => 'Invalid JSON payload' ),
                400
            );
        }

        // Get the branch from the ref
        $ref    = isset( $data['ref'] ) ? $data['ref'] : '';
        $branch = str_replace( 'refs/heads/', '', $ref );

        if ( empty( $branch ) ) {
            return new WP_REST_Response(
                array( 'error' => 'Could not determine branch from ref' ),
                400
            );
        }

        // Check if this is the selected branch
        $selected_branch = GitHub_Branch_Deploy::get_option( 'selected_branch', 'main' );

        if ( $branch !== $selected_branch ) {
            return new WP_REST_Response(
                array(
                    'message'         => 'Push ignored - not selected branch',
                    'pushed_branch'   => $branch,
                    'selected_branch' => $selected_branch,
                ),
                200
            );
        }

        // Check if auto-deploy is enabled
        $auto_deploy = GitHub_Branch_Deploy::get_option( 'auto_deploy', true );

        if ( ! $auto_deploy ) {
            return new WP_REST_Response(
                array( 'message' => 'Auto-deploy is disabled' ),
                200
            );
        }

        // Check if deployment is already running
        if ( $this->deployer->is_running() ) {
            return new WP_REST_Response(
                array( 'message' => 'Deployment already in progress' ),
                409
            );
        }

        // Get commit info for logging
        $commit_info = '';
        if ( isset( $data['head_commit'] ) ) {
            $commit_info = sprintf(
                '%s by %s',
                substr( $data['head_commit']['id'], 0, 7 ),
                isset( $data['head_commit']['author']['name'] ) ? $data['head_commit']['author']['name'] : 'unknown'
            );
        }

        // Trigger deployment
        $result = $this->deployer->deploy( $branch, 'webhook: ' . $commit_info );

        return new WP_REST_Response(
            array(
                'message' => $result['status'] === 'success' ? 'Deployment completed' : 'Deployment failed',
                'deploy'  => $result,
            ),
            $result['status'] === 'success' ? 200 : 500
        );
    }

    /**
     * Get status endpoint
     */
    public function get_status( WP_REST_Request $request ) {
        $last_deploy = GitHub_Branch_Deploy::get_option( 'last_deploy' );
        $log         = $this->deployer->get_log( 1 );

        return new WP_REST_Response(
            array(
                'status'         => 'ok',
                'last_deploy'    => $last_deploy,
                'last_status'    => ! empty( $log ) ? $log[0]['status'] : null,
                'selected_branch' => GitHub_Branch_Deploy::get_option( 'selected_branch' ),
            ),
            200
        );
    }

    /**
     * Check rate limiting
     */
    private function is_rate_limited() {
        $transient_key = 'gbd_rate_limit_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        $requests      = get_transient( $transient_key );

        if ( false === $requests ) {
            set_transient( $transient_key, 1, self::RATE_LIMIT_WINDOW );
            return false;
        }

        if ( $requests >= self::RATE_LIMIT_MAX ) {
            return true;
        }

        set_transient( $transient_key, $requests + 1, self::RATE_LIMIT_WINDOW );
        return false;
    }

    /**
     * Log webhook receipt
     */
    private function log_webhook( $event, $signature_status ) {
        $log = get_option( 'gbd_webhook_log', array() );

        if ( ! is_array( $log ) ) {
            $log = array();
        }

        array_unshift( $log, array(
            'time'      => current_time( 'mysql' ),
            'event'     => $event,
            'signature' => $signature_status,
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ) );

        // Keep last 100 entries
        $log = array_slice( $log, 0, 100 );

        update_option( 'gbd_webhook_log', $log );
    }

    /**
     * Get webhook URL for display
     */
    public static function get_webhook_url() {
        return rest_url( 'github-deploy/v1/webhook' );
    }
}
