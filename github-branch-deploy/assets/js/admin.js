/**
 * GitHub Branch Deploy - Admin JavaScript
 */

(function($) {
    'use strict';

    var GBD = {
        init: function() {
            this.bindEvents();
            this.restoreExplainerState();
            this.loadBranches();
            this.loadLog();
        },

        bindEvents: function() {
            // Explainer toggle
            $('.gbd-explainer-header').on('click', this.toggleExplainer.bind(this));

            // Test connection
            $('#gbd-test-connection').on('click', this.testConnection.bind(this));

            // Refresh branches
            $('#gbd-refresh-branches').on('click', this.loadBranches.bind(this));

            // Deploy now
            $('#gbd-deploy-now').on('click', this.deployNow.bind(this));

            // Refresh log
            $('#gbd-refresh-log').on('click', this.loadLog.bind(this));

            // Clear log
            $('#gbd-clear-log').on('click', this.clearLog.bind(this));

            // Regenerate secret
            $('#gbd-regenerate-secret').on('click', this.regenerateSecret.bind(this));

            // Toggle password visibility
            $('.gbd-toggle-password').on('click', this.togglePassword.bind(this));

            // Copy to clipboard
            $('.gbd-copy-btn').on('click', this.copyToClipboard.bind(this));

            // Update current branch display when select changes
            $('#gbd_selected_branch').on('change', function() {
                $('#gbd-current-branch').text($(this).val());
            });
        },

        testConnection: function(e) {
            e.preventDefault();

            var $button = $('#gbd-test-connection');
            var $status = $('#gbd-connection-status');

            $button.prop('disabled', true);
            $status.removeClass('success error').addClass('loading').html(
                '<span class="gbd-spinner"></span> ' + gbdAdmin.strings.testing
            );

            $.ajax({
                url: gbdAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gbd_test_connection',
                    nonce: gbdAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.removeClass('loading error').addClass('success').html(
                            '<span class="dashicons dashicons-yes"></span> ' +
                            gbdAdmin.strings.connectionOk + ' (' + response.data.repo + ')'
                        );
                    } else {
                        $status.removeClass('loading success').addClass('error').html(
                            '<span class="dashicons dashicons-no"></span> ' +
                            response.data.message
                        );
                    }
                },
                error: function() {
                    $status.removeClass('loading success').addClass('error').html(
                        '<span class="dashicons dashicons-no"></span> ' +
                        gbdAdmin.strings.connectionFailed
                    );
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        loadBranches: function(e) {
            if (e) e.preventDefault();

            var $select = $('#gbd_selected_branch');
            var $button = $('#gbd-refresh-branches');
            var $status = $('#gbd-branches-status');
            var currentValue = $select.val();

            $button.prop('disabled', true);
            $status.removeClass('success error').addClass('loading').html(
                '<span class="gbd-spinner"></span> ' + gbdAdmin.strings.loadingBranches
            );

            $.ajax({
                url: gbdAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gbd_get_branches',
                    nonce: gbdAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $select.empty();

                        response.data.branches.forEach(function(branch) {
                            var $option = $('<option></option>')
                                .val(branch.name)
                                .text(branch.name);

                            if (branch.name === currentValue) {
                                $option.prop('selected', true);
                            }

                            $select.append($option);
                        });

                        $status.removeClass('loading error').addClass('success').html(
                            '<span class="dashicons dashicons-yes"></span> ' +
                            response.data.branches.length + ' branches loaded'
                        );

                        setTimeout(function() {
                            $status.html('');
                        }, 3000);
                    } else {
                        $status.removeClass('loading success').addClass('error').html(
                            '<span class="dashicons dashicons-no"></span> ' +
                            response.data.message
                        );
                    }
                },
                error: function() {
                    $status.removeClass('loading success').addClass('error').html(
                        '<span class="dashicons dashicons-no"></span> Failed to load branches'
                    );
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        deployNow: function(e) {
            e.preventDefault();

            if (!confirm(gbdAdmin.strings.confirmDeploy)) {
                return;
            }

            var $button = $('#gbd-deploy-now');
            var $progress = $('#gbd-deploy-progress');
            var $result = $('#gbd-deploy-result');
            var branch = $('#gbd_selected_branch').val();

            $button.prop('disabled', true);
            $progress.show();
            $result.hide().removeClass('success error');

            $.ajax({
                url: gbdAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gbd_deploy_now',
                    nonce: gbdAdmin.nonce,
                    branch: branch
                },
                success: function(response) {
                    $progress.hide();

                    if (response.success) {
                        $result.addClass('success').html(
                            '<h4><span class="dashicons dashicons-yes-alt"></span> ' +
                            gbdAdmin.strings.deploySuccess + '</h4>' +
                            '<ul>' +
                            '<li><strong>Branch:</strong> ' + response.data.branch + '</li>' +
                            '<li><strong>Files Copied:</strong> ' + response.data.files_copied + '</li>' +
                            '<li><strong>Duration:</strong> ' + response.data.duration + 's</li>' +
                            (response.data.commit_sha ? '<li><strong>Commit:</strong> <code>' + response.data.commit_sha + '</code></li>' : '') +
                            '</ul>'
                        ).show();

                        $('#gbd-last-deploy').text(response.data.end_time);
                    } else {
                        $result.addClass('error').html(
                            '<h4><span class="dashicons dashicons-warning"></span> ' +
                            gbdAdmin.strings.deployFailed + '</h4>' +
                            '<p>' + (response.data.message || 'Unknown error') + '</p>'
                        ).show();
                    }

                    // Refresh log
                    GBD.loadLog();
                },
                error: function(xhr) {
                    $progress.hide();
                    var message = 'Server error';
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        message = resp.data ? resp.data.message : message;
                    } catch (e) {}

                    $result.addClass('error').html(
                        '<h4><span class="dashicons dashicons-warning"></span> ' +
                        gbdAdmin.strings.deployFailed + '</h4>' +
                        '<p>' + message + '</p>'
                    ).show();
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        loadLog: function(e) {
            if (e) e.preventDefault();

            var $tbody = $('#gbd-log-body');

            $.ajax({
                url: gbdAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gbd_get_log',
                    nonce: gbdAdmin.nonce,
                    limit: 20
                },
                success: function(response) {
                    if (response.success && response.data.log.length > 0) {
                        $tbody.empty();

                        response.data.log.forEach(function(entry) {
                            var statusClass = 'status-' + entry.status;
                            var statusIcon = entry.status === 'success' ? 'yes-alt' :
                                           entry.status === 'failed' ? 'warning' : 'update';

                            var row = '<tr>' +
                                '<td>' + entry.start_time + '</td>' +
                                '<td>' + entry.branch +
                                    (entry.commit_sha ? ' <span class="commit-sha">' + entry.commit_sha + '</span>' : '') +
                                '</td>' +
                                '<td>' + entry.triggered_by + '</td>' +
                                '<td class="' + statusClass + '">' +
                                    '<span class="dashicons dashicons-' + statusIcon + '"></span> ' +
                                    entry.status +
                                '</td>' +
                                '<td>' + entry.files_copied + '</td>' +
                                '<td>' + entry.duration + 's</td>' +
                                '</tr>';

                            $tbody.append(row);
                        });
                    } else {
                        $tbody.html('<tr><td colspan="6">No deployments yet.</td></tr>');
                    }
                },
                error: function() {
                    $tbody.html('<tr><td colspan="6">Failed to load log.</td></tr>');
                }
            });
        },

        clearLog: function(e) {
            e.preventDefault();

            if (!confirm(gbdAdmin.strings.confirmClearLog)) {
                return;
            }

            $.ajax({
                url: gbdAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gbd_clear_log',
                    nonce: gbdAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        GBD.loadLog();
                    }
                }
            });
        },

        regenerateSecret: function(e) {
            e.preventDefault();

            if (!confirm('Regenerate webhook secret? You will need to update it in GitHub.')) {
                return;
            }

            var $button = $('#gbd-regenerate-secret');
            $button.prop('disabled', true);

            $.ajax({
                url: gbdAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'gbd_regenerate_secret',
                    nonce: gbdAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#gbd-webhook-secret').text(response.data.secret);
                        alert(response.data.message);
                    }
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        },

        togglePassword: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var $input = $button.prev('input');
            var $icon = $button.find('.dashicons');

            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
            } else {
                $input.attr('type', 'password');
                $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            }
        },

        copyToClipboard: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var targetId = $button.data('copy');
            var text = $('#' + targetId).text();

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    GBD.showCopyFeedback($button);
                });
            } else {
                // Fallback for older browsers
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
                GBD.showCopyFeedback($button);
            }
        },

        showCopyFeedback: function($button) {
            $button.addClass('gbd-copied');
            setTimeout(function() {
                $button.removeClass('gbd-copied');
            }, 1500);
        },

        toggleExplainer: function(e) {
            e.preventDefault();
            var $explainer = $('.gbd-explainer');
            var $toggle = $explainer.find('.gbd-explainer-toggle');
            var isCollapsed = $explainer.hasClass('collapsed');

            $explainer.toggleClass('collapsed');
            $toggle.attr('aria-expanded', isCollapsed ? 'true' : 'false');

            // Save preference
            localStorage.setItem('gbd_explainer_collapsed', isCollapsed ? 'false' : 'true');
        },

        restoreExplainerState: function() {
            var isCollapsed = localStorage.getItem('gbd_explainer_collapsed');
            if (isCollapsed === 'true') {
                $('.gbd-explainer').addClass('collapsed');
                $('.gbd-explainer-toggle').attr('aria-expanded', 'false');
            }
        }
    };

    $(document).ready(function() {
        GBD.init();
    });

})(jQuery);
