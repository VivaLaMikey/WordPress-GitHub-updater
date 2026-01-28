<?php
/**
 * Admin page template
 *
 * @package GitHub_Branch_Deploy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$repo           = GitHub_Branch_Deploy::get_option( 'github_repo', '' );
$token          = GitHub_Branch_Deploy::get_option( 'github_token', '' );
$source_path    = GitHub_Branch_Deploy::get_option( 'source_path', 'client-site/wp-content' );
$selected_branch = GitHub_Branch_Deploy::get_option( 'selected_branch', 'main' );
$webhook_secret = GitHub_Branch_Deploy::get_option( 'webhook_secret', '' );
$auto_deploy    = GitHub_Branch_Deploy::get_option( 'auto_deploy', true );
$last_deploy    = GitHub_Branch_Deploy::get_option( 'last_deploy', '' );
$webhook_url    = GBD_Webhook_Handler::get_webhook_url();
?>

<div class="wrap gbd-admin">
    <h1><?php esc_html_e( 'GitHub Branch Deploy', 'github-branch-deploy' ); ?></h1>

    <!-- Explainer Panel -->
    <div class="gbd-explainer">
        <div class="gbd-explainer-header">
            <span class="dashicons dashicons-info-outline"></span>
            <h2><?php esc_html_e( 'How It Works', 'github-branch-deploy' ); ?></h2>
            <button type="button" class="gbd-explainer-toggle" aria-expanded="true">
                <span class="dashicons dashicons-arrow-up-alt2"></span>
            </button>
        </div>
        <div class="gbd-explainer-content">
            <div class="gbd-explainer-grid">
                <div class="gbd-explainer-step">
                    <div class="gbd-step-number">1</div>
                    <h3><?php esc_html_e( 'Configure', 'github-branch-deploy' ); ?></h3>
                    <p><?php esc_html_e( 'Enter your GitHub repository details and Personal Access Token below. The token needs "repo" scope to access private repositories.', 'github-branch-deploy' ); ?></p>
                </div>
                <div class="gbd-explainer-step">
                    <div class="gbd-step-number">2</div>
                    <h3><?php esc_html_e( 'Select Branch', 'github-branch-deploy' ); ?></h3>
                    <p><?php esc_html_e( 'Choose which branch to deploy from. Click "Refresh" to load all available branches from your repository.', 'github-branch-deploy' ); ?></p>
                </div>
                <div class="gbd-explainer-step">
                    <div class="gbd-step-number">3</div>
                    <h3><?php esc_html_e( 'Setup Webhook', 'github-branch-deploy' ); ?></h3>
                    <p><?php esc_html_e( 'Add the webhook URL and secret to your GitHub repository settings. This enables automatic deployments when you push code.', 'github-branch-deploy' ); ?></p>
                </div>
                <div class="gbd-explainer-step">
                    <div class="gbd-step-number">4</div>
                    <h3><?php esc_html_e( 'Deploy', 'github-branch-deploy' ); ?></h3>
                    <p><?php esc_html_e( 'Push to your selected branch for automatic deployment, or use the "Deploy Now" button to manually sync files at any time.', 'github-branch-deploy' ); ?></p>
                </div>
            </div>
            <div class="gbd-explainer-notes">
                <h4><?php esc_html_e( 'Important Notes:', 'github-branch-deploy' ); ?></h4>
                <ul>
                    <li><strong><?php esc_html_e( 'Safe Updates:', 'github-branch-deploy' ); ?></strong> <?php esc_html_e( 'Files are added or updated only - existing files (like uploads) are never deleted.', 'github-branch-deploy' ); ?></li>
                    <li><strong><?php esc_html_e( 'Source Path:', 'github-branch-deploy' ); ?></strong> <?php esc_html_e( 'Only files from the configured source path in your repo are deployed to wp-content.', 'github-branch-deploy' ); ?></li>
                    <li><strong><?php esc_html_e( 'Webhook Security:', 'github-branch-deploy' ); ?></strong> <?php esc_html_e( 'All webhook requests are verified using HMAC SHA-256 signatures to prevent unauthorized deployments.', 'github-branch-deploy' ); ?></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="gbd-admin-grid">
        <!-- Settings Panel -->
        <div class="gbd-panel gbd-settings-panel">
            <h2><?php esc_html_e( 'Settings', 'github-branch-deploy' ); ?></h2>

            <form method="post" action="options.php" id="gbd-settings-form">
                <?php settings_fields( 'gbd_settings' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="gbd_github_repo"><?php esc_html_e( 'GitHub Repository', 'github-branch-deploy' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="gbd_github_repo" name="gbd_github_repo"
                                   value="<?php echo esc_attr( $repo ); ?>"
                                   class="regular-text"
                                   placeholder="owner/repository">
                            <p class="description"><?php esc_html_e( 'Format: owner/repository (e.g., guardcore/saas-system)', 'github-branch-deploy' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="gbd_github_token"><?php esc_html_e( 'Personal Access Token', 'github-branch-deploy' ); ?></label>
                        </th>
                        <td>
                            <input type="password" id="gbd_github_token" name="gbd_github_token"
                                   value="<?php echo esc_attr( $token ); ?>"
                                   class="regular-text"
                                   autocomplete="new-password">
                            <button type="button" class="button gbd-toggle-password">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                            <p class="description">
                                <?php esc_html_e( 'GitHub PAT with "repo" scope for private repositories.', 'github-branch-deploy' ); ?>
                                <a href="https://github.com/settings/tokens" target="_blank"><?php esc_html_e( 'Create token', 'github-branch-deploy' ); ?></a>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="gbd_source_path"><?php esc_html_e( 'Source Path in Repo', 'github-branch-deploy' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="gbd_source_path" name="gbd_source_path"
                                   value="<?php echo esc_attr( $source_path ); ?>"
                                   class="regular-text"
                                   placeholder="client-site/wp-content">
                            <p class="description"><?php esc_html_e( 'Path to wp-content folder within the repository.', 'github-branch-deploy' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="gbd_selected_branch"><?php esc_html_e( 'Selected Branch', 'github-branch-deploy' ); ?></label>
                        </th>
                        <td>
                            <select id="gbd_selected_branch" name="gbd_selected_branch" class="regular-text">
                                <option value="<?php echo esc_attr( $selected_branch ); ?>">
                                    <?php echo esc_html( $selected_branch ); ?>
                                </option>
                            </select>
                            <button type="button" id="gbd-refresh-branches" class="button">
                                <span class="dashicons dashicons-update"></span>
                                <?php esc_html_e( 'Refresh', 'github-branch-deploy' ); ?>
                            </button>
                            <span id="gbd-branches-status"></span>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Auto Deploy', 'github-branch-deploy' ); ?>
                        </th>
                        <td>
                            <label for="gbd_auto_deploy">
                                <input type="checkbox" id="gbd_auto_deploy" name="gbd_auto_deploy"
                                       value="1" <?php checked( $auto_deploy ); ?>>
                                <?php esc_html_e( 'Automatically deploy when selected branch receives a push', 'github-branch-deploy' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <?php submit_button( __( 'Save Settings', 'github-branch-deploy' ), 'primary', 'submit', false ); ?>
                    <button type="button" id="gbd-test-connection" class="button">
                        <?php esc_html_e( 'Test Connection', 'github-branch-deploy' ); ?>
                    </button>
                    <span id="gbd-connection-status"></span>
                </p>
            </form>
        </div>

        <!-- Webhook Panel -->
        <div class="gbd-panel gbd-webhook-panel">
            <h2><?php esc_html_e( 'Webhook Configuration', 'github-branch-deploy' ); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Webhook URL', 'github-branch-deploy' ); ?></th>
                    <td>
                        <code id="gbd-webhook-url"><?php echo esc_html( $webhook_url ); ?></code>
                        <button type="button" class="button gbd-copy-btn" data-copy="gbd-webhook-url">
                            <span class="dashicons dashicons-clipboard"></span>
                        </button>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Webhook Secret', 'github-branch-deploy' ); ?></th>
                    <td>
                        <code id="gbd-webhook-secret"><?php echo esc_html( $webhook_secret ); ?></code>
                        <button type="button" class="button gbd-copy-btn" data-copy="gbd-webhook-secret">
                            <span class="dashicons dashicons-clipboard"></span>
                        </button>
                        <button type="button" id="gbd-regenerate-secret" class="button">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e( 'Regenerate', 'github-branch-deploy' ); ?>
                        </button>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Content Type', 'github-branch-deploy' ); ?></th>
                    <td><code>application/json</code></td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Events', 'github-branch-deploy' ); ?></th>
                    <td><?php esc_html_e( 'Just the push event', 'github-branch-deploy' ); ?></td>
                </tr>
            </table>

            <div class="gbd-webhook-instructions">
                <h4><?php esc_html_e( 'Setup Instructions:', 'github-branch-deploy' ); ?></h4>
                <ol>
                    <li><?php esc_html_e( 'Go to your GitHub repository settings', 'github-branch-deploy' ); ?></li>
                    <li><?php esc_html_e( 'Navigate to Webhooks > Add webhook', 'github-branch-deploy' ); ?></li>
                    <li><?php esc_html_e( 'Paste the Webhook URL above', 'github-branch-deploy' ); ?></li>
                    <li><?php esc_html_e( 'Set Content type to application/json', 'github-branch-deploy' ); ?></li>
                    <li><?php esc_html_e( 'Paste the Webhook Secret above', 'github-branch-deploy' ); ?></li>
                    <li><?php esc_html_e( 'Select "Just the push event"', 'github-branch-deploy' ); ?></li>
                    <li><?php esc_html_e( 'Save the webhook', 'github-branch-deploy' ); ?></li>
                </ol>
            </div>
        </div>

        <!-- Deploy Panel -->
        <div class="gbd-panel gbd-deploy-panel">
            <h2><?php esc_html_e( 'Manual Deployment', 'github-branch-deploy' ); ?></h2>

            <div class="gbd-deploy-status">
                <div class="gbd-status-item">
                    <strong><?php esc_html_e( 'Selected Branch:', 'github-branch-deploy' ); ?></strong>
                    <span id="gbd-current-branch"><?php echo esc_html( $selected_branch ); ?></span>
                </div>

                <div class="gbd-status-item">
                    <strong><?php esc_html_e( 'Last Deployment:', 'github-branch-deploy' ); ?></strong>
                    <span id="gbd-last-deploy">
                        <?php echo $last_deploy ? esc_html( $last_deploy ) : esc_html__( 'Never', 'github-branch-deploy' ); ?>
                    </span>
                </div>
            </div>

            <p>
                <button type="button" id="gbd-deploy-now" class="button button-primary button-hero">
                    <span class="dashicons dashicons-cloud-upload"></span>
                    <?php esc_html_e( 'Deploy Now', 'github-branch-deploy' ); ?>
                </button>
            </p>

            <div id="gbd-deploy-progress" class="gbd-progress" style="display: none;">
                <div class="gbd-progress-bar">
                    <div class="gbd-progress-fill"></div>
                </div>
                <p class="gbd-progress-text"><?php esc_html_e( 'Deploying...', 'github-branch-deploy' ); ?></p>
            </div>

            <div id="gbd-deploy-result" class="gbd-result" style="display: none;"></div>
        </div>

        <!-- Log Panel -->
        <div class="gbd-panel gbd-log-panel">
            <h2>
                <?php esc_html_e( 'Deployment Log', 'github-branch-deploy' ); ?>
                <button type="button" id="gbd-refresh-log" class="button button-small">
                    <span class="dashicons dashicons-update"></span>
                </button>
                <button type="button" id="gbd-clear-log" class="button button-small">
                    <?php esc_html_e( 'Clear', 'github-branch-deploy' ); ?>
                </button>
            </h2>

            <div id="gbd-log-container">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'github-branch-deploy' ); ?></th>
                            <th><?php esc_html_e( 'Branch', 'github-branch-deploy' ); ?></th>
                            <th><?php esc_html_e( 'Triggered By', 'github-branch-deploy' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'github-branch-deploy' ); ?></th>
                            <th><?php esc_html_e( 'Files', 'github-branch-deploy' ); ?></th>
                            <th><?php esc_html_e( 'Duration', 'github-branch-deploy' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="gbd-log-body">
                        <tr>
                            <td colspan="6"><?php esc_html_e( 'Loading...', 'github-branch-deploy' ); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
