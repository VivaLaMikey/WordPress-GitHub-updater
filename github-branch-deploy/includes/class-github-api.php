<?php
/**
 * GitHub API wrapper class
 *
 * @package GitHub_Branch_Deploy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GBD_GitHub_API {

    /**
     * GitHub API base URL
     */
    const API_BASE = 'https://api.github.com';

    /**
     * Get API headers
     */
    private function get_headers() {
        $token = GitHub_Branch_Deploy::get_option( 'github_token' );

        return array(
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/vnd.github.v3+json',
            'User-Agent'    => 'GitHub-Branch-Deploy-WordPress-Plugin',
        );
    }

    /**
     * Make API request
     */
    private function request( $endpoint, $args = array(), $query_params = array() ) {
        $url = self::API_BASE . $endpoint;

        // Append query parameters to URL for GET requests
        if ( ! empty( $query_params ) ) {
            $url = add_query_arg( $query_params, $url );
        }

        $defaults = array(
            'headers' => $this->get_headers(),
            'timeout' => 30,
        );

        $args = wp_parse_args( $args, $defaults );

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code >= 400 ) {
            $error_data = json_decode( $body, true );
            $message    = isset( $error_data['message'] ) ? $error_data['message'] : 'GitHub API error';
            return new WP_Error( 'github_api_error', $message, array( 'status' => $code ) );
        }

        return json_decode( $body, true );
    }

    /**
     * Test API connection
     */
    public function test_connection() {
        $repo = GitHub_Branch_Deploy::get_option( 'github_repo' );

        if ( empty( $repo ) ) {
            return new WP_Error( 'missing_repo', __( 'GitHub repository not configured.', 'github-branch-deploy' ) );
        }

        $result = $this->request( '/repos/' . $repo );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return array(
            'success' => true,
            'repo'    => $result['full_name'],
            'private' => $result['private'],
        );
    }

    /**
     * Get repository branches
     */
    public function get_branches() {
        $repo = GitHub_Branch_Deploy::get_option( 'github_repo' );

        if ( empty( $repo ) ) {
            return new WP_Error( 'missing_repo', __( 'GitHub repository not configured.', 'github-branch-deploy' ) );
        }

        $branches  = array();
        $page      = 1;
        $per_page  = 100;

        do {
            $result = $this->request(
                '/repos/' . $repo . '/branches',
                array(),
                array(
                    'per_page' => $per_page,
                    'page'     => $page,
                )
            );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            foreach ( $result as $branch ) {
                $branches[] = array(
                    'name'   => $branch['name'],
                    'sha'    => $branch['commit']['sha'],
                    'protected' => $branch['protected'],
                );
            }

            $page++;
        } while ( count( $result ) === $per_page );

        return $branches;
    }

    /**
     * Get branch info
     */
    public function get_branch( $branch_name ) {
        $repo = GitHub_Branch_Deploy::get_option( 'github_repo' );

        if ( empty( $repo ) ) {
            return new WP_Error( 'missing_repo', __( 'GitHub repository not configured.', 'github-branch-deploy' ) );
        }

        return $this->request( '/repos/' . $repo . '/branches/' . urlencode( $branch_name ) );
    }

    /**
     * Get latest commit for a branch
     */
    public function get_latest_commit( $branch_name ) {
        $branch = $this->get_branch( $branch_name );

        if ( is_wp_error( $branch ) ) {
            return $branch;
        }

        return array(
            'sha'     => $branch['commit']['sha'],
            'message' => isset( $branch['commit']['commit']['message'] ) ? $branch['commit']['commit']['message'] : '',
            'author'  => isset( $branch['commit']['commit']['author']['name'] ) ? $branch['commit']['commit']['author']['name'] : '',
            'date'    => isset( $branch['commit']['commit']['author']['date'] ) ? $branch['commit']['commit']['author']['date'] : '',
        );
    }

    /**
     * Download repository archive (zip)
     */
    public function download_archive( $branch_name, $destination ) {
        $repo  = GitHub_Branch_Deploy::get_option( 'github_repo' );
        $token = GitHub_Branch_Deploy::get_option( 'github_token' );

        if ( empty( $repo ) ) {
            return new WP_Error( 'missing_repo', __( 'GitHub repository not configured.', 'github-branch-deploy' ) );
        }

        $url = self::API_BASE . '/repos/' . $repo . '/zipball/' . urlencode( $branch_name );

        $response = wp_remote_get( $url, array(
            'headers'  => $this->get_headers(),
            'timeout'  => 300,
            'stream'   => true,
            'filename' => $destination,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code >= 400 ) {
            @unlink( $destination );
            return new WP_Error( 'download_failed', __( 'Failed to download repository archive.', 'github-branch-deploy' ), array( 'status' => $code ) );
        }

        if ( ! file_exists( $destination ) || filesize( $destination ) < 100 ) {
            @unlink( $destination );
            return new WP_Error( 'download_failed', __( 'Downloaded file is empty or invalid.', 'github-branch-deploy' ) );
        }

        return true;
    }

    /**
     * Get repository contents (for specific path)
     */
    public function get_contents( $path, $branch_name ) {
        $repo = GitHub_Branch_Deploy::get_option( 'github_repo' );

        if ( empty( $repo ) ) {
            return new WP_Error( 'missing_repo', __( 'GitHub repository not configured.', 'github-branch-deploy' ) );
        }

        $endpoint = '/repos/' . $repo . '/contents/' . ltrim( $path, '/' );

        return $this->request( $endpoint, array(), array( 'ref' => $branch_name ) );
    }

    /**
     * Verify webhook signature
     */
    public static function verify_webhook_signature( $payload, $signature ) {
        $secret = GitHub_Branch_Deploy::get_option( 'webhook_secret' );

        if ( empty( $secret ) || empty( $signature ) ) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac( 'sha256', $payload, $secret );

        return hash_equals( $expected, $signature );
    }
}
