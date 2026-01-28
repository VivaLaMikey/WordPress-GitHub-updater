<?php
/**
 * Deployer class - handles file deployment
 *
 * @package GitHub_Branch_Deploy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GBD_Deployer {

    /**
     * GitHub API instance
     */
    private $github_api;

    /**
     * Temp directory
     */
    private $temp_dir;

    /**
     * Constructor
     */
    public function __construct( GBD_GitHub_API $github_api ) {
        $this->github_api = $github_api;
        $this->temp_dir   = WP_CONTENT_DIR . '/gbd-temp';
    }

    /**
     * Deploy from branch
     */
    public function deploy( $branch_name = null, $triggered_by = 'manual' ) {
        $start_time = microtime( true );

        if ( null === $branch_name ) {
            $branch_name = GitHub_Branch_Deploy::get_option( 'selected_branch', 'main' );
        }

        $source_path = GitHub_Branch_Deploy::get_option( 'source_path', 'client-site/wp-content' );

        $log_entry = array(
            'id'           => uniqid( 'deploy_' ),
            'branch'       => $branch_name,
            'triggered_by' => $triggered_by,
            'start_time'   => current_time( 'mysql' ),
            'status'       => 'running',
            'message'      => '',
            'files_copied' => 0,
            'duration'     => 0,
        );

        try {
            // Ensure temp directory exists
            if ( ! $this->ensure_temp_dir() ) {
                throw new Exception( __( 'Failed to create temp directory.', 'github-branch-deploy' ) );
            }

            // Get latest commit info
            $commit = $this->github_api->get_latest_commit( $branch_name );
            if ( is_wp_error( $commit ) ) {
                throw new Exception( $commit->get_error_message() );
            }
            $log_entry['commit_sha'] = substr( $commit['sha'], 0, 7 );
            $log_entry['commit_message'] = substr( $commit['message'], 0, 100 );

            // Download archive
            $zip_file = $this->temp_dir . '/repo-' . time() . '.zip';
            $result   = $this->github_api->download_archive( $branch_name, $zip_file );

            if ( is_wp_error( $result ) ) {
                throw new Exception( $result->get_error_message() );
            }

            // Extract archive
            $extract_dir = $this->temp_dir . '/extract-' . time();
            $extracted   = $this->extract_zip( $zip_file, $extract_dir );

            if ( is_wp_error( $extracted ) ) {
                @unlink( $zip_file );
                throw new Exception( $extracted->get_error_message() );
            }

            // Find the source path in extracted content
            $source_dir = $this->find_source_dir( $extract_dir, $source_path );

            if ( is_wp_error( $source_dir ) ) {
                $this->cleanup( $zip_file, $extract_dir );
                throw new Exception( $source_dir->get_error_message() );
            }

            // Copy files to wp-content (preserving existing)
            $files_copied = $this->copy_files( $source_dir, WP_CONTENT_DIR );

            if ( is_wp_error( $files_copied ) ) {
                $this->cleanup( $zip_file, $extract_dir );
                throw new Exception( $files_copied->get_error_message() );
            }

            // Cleanup
            $this->cleanup( $zip_file, $extract_dir );

            // Update log entry
            $log_entry['status']       = 'success';
            $log_entry['message']      = sprintf( __( 'Successfully deployed %d files.', 'github-branch-deploy' ), $files_copied );
            $log_entry['files_copied'] = $files_copied;

        } catch ( Exception $e ) {
            $log_entry['status']  = 'failed';
            $log_entry['message'] = $e->getMessage();
        }

        $log_entry['duration']  = round( microtime( true ) - $start_time, 2 );
        $log_entry['end_time']  = current_time( 'mysql' );

        // Save log
        $this->save_log_entry( $log_entry );

        // Update last deploy time
        if ( $log_entry['status'] === 'success' ) {
            GitHub_Branch_Deploy::update_option( 'last_deploy', current_time( 'mysql' ) );
        }

        return $log_entry;
    }

    /**
     * Ensure temp directory exists
     */
    private function ensure_temp_dir() {
        if ( ! file_exists( $this->temp_dir ) ) {
            if ( ! wp_mkdir_p( $this->temp_dir ) ) {
                return false;
            }
            file_put_contents( $this->temp_dir . '/.htaccess', 'Deny from all' );
            file_put_contents( $this->temp_dir . '/index.php', '<?php // Silence is golden' );
        }
        return true;
    }

    /**
     * Extract zip file
     */
    private function extract_zip( $zip_file, $extract_dir ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            // Fallback to WP_Filesystem
            WP_Filesystem();
            $result = unzip_file( $zip_file, $extract_dir );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return true;
        }

        $zip = new ZipArchive();
        $res = $zip->open( $zip_file );

        if ( $res !== true ) {
            return new WP_Error( 'zip_error', __( 'Failed to open zip archive.', 'github-branch-deploy' ) );
        }

        if ( ! wp_mkdir_p( $extract_dir ) ) {
            $zip->close();
            return new WP_Error( 'mkdir_error', __( 'Failed to create extraction directory.', 'github-branch-deploy' ) );
        }

        $zip->extractTo( $extract_dir );
        $zip->close();

        return true;
    }

    /**
     * Find source directory in extracted content
     */
    private function find_source_dir( $extract_dir, $source_path ) {
        // GitHub archives have a root folder like "owner-repo-sha"
        $contents = scandir( $extract_dir );
        $root_dir = null;

        foreach ( $contents as $item ) {
            if ( $item !== '.' && $item !== '..' && is_dir( $extract_dir . '/' . $item ) ) {
                $root_dir = $extract_dir . '/' . $item;
                break;
            }
        }

        if ( ! $root_dir ) {
            return new WP_Error( 'no_root', __( 'Could not find root directory in archive.', 'github-branch-deploy' ) );
        }

        // Build full source path
        $full_source = $root_dir . '/' . trim( $source_path, '/' );

        if ( ! file_exists( $full_source ) || ! is_dir( $full_source ) ) {
            return new WP_Error(
                'source_not_found',
                sprintf( __( 'Source path "%s" not found in repository.', 'github-branch-deploy' ), $source_path )
            );
        }

        return $full_source;
    }

    /**
     * Copy files from source to destination (preserving existing)
     */
    private function copy_files( $source, $dest ) {
        $files_copied = 0;
        $iterator     = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        // Directories/files to skip
        $skip_patterns = array(
            '/^\.git/',
            '/^\.github/',
            '/^\.DS_Store$/',
            '/^Thumbs\.db$/',
            '/^gbd-temp/',
        );

        foreach ( $iterator as $item ) {
            $relative_path = substr( $item->getPathname(), strlen( $source ) + 1 );

            // Skip certain files/directories
            $should_skip = false;
            foreach ( $skip_patterns as $pattern ) {
                if ( preg_match( $pattern, $relative_path ) ) {
                    $should_skip = true;
                    break;
                }
            }

            if ( $should_skip ) {
                continue;
            }

            $dest_path = $dest . '/' . $relative_path;

            if ( $item->isDir() ) {
                if ( ! file_exists( $dest_path ) ) {
                    wp_mkdir_p( $dest_path );
                }
            } else {
                // Ensure parent directory exists
                $parent_dir = dirname( $dest_path );
                if ( ! file_exists( $parent_dir ) ) {
                    wp_mkdir_p( $parent_dir );
                }

                // Copy file
                if ( copy( $item->getPathname(), $dest_path ) ) {
                    $files_copied++;
                }
            }
        }

        return $files_copied;
    }

    /**
     * Cleanup temporary files
     */
    private function cleanup( $zip_file, $extract_dir ) {
        @unlink( $zip_file );
        $this->delete_directory( $extract_dir );
    }

    /**
     * Recursively delete a directory
     */
    private function delete_directory( $dir ) {
        if ( ! file_exists( $dir ) ) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                @rmdir( $item->getPathname() );
            } else {
                @unlink( $item->getPathname() );
            }
        }

        @rmdir( $dir );
    }

    /**
     * Save log entry
     */
    private function save_log_entry( $entry ) {
        $log = GitHub_Branch_Deploy::get_option( 'deploy_log', array() );

        if ( ! is_array( $log ) ) {
            $log = array();
        }

        // Add new entry at the beginning
        array_unshift( $log, $entry );

        // Keep only last 50 entries
        $log = array_slice( $log, 0, 50 );

        GitHub_Branch_Deploy::update_option( 'deploy_log', $log );
    }

    /**
     * Get deployment log
     */
    public function get_log( $limit = 20 ) {
        $log = GitHub_Branch_Deploy::get_option( 'deploy_log', array() );

        if ( ! is_array( $log ) ) {
            return array();
        }

        return array_slice( $log, 0, $limit );
    }

    /**
     * Clear deployment log
     */
    public function clear_log() {
        GitHub_Branch_Deploy::update_option( 'deploy_log', array() );
    }

    /**
     * Check if deployment is currently running
     */
    public function is_running() {
        $log = $this->get_log( 1 );

        if ( empty( $log ) ) {
            return false;
        }

        return $log[0]['status'] === 'running';
    }
}
