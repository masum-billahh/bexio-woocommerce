/**
 * Bexio WooCommerce Integration - Admin JS
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        
        // Test Connection Button
        $('#test-connection').on('click', function() {
            var $button = $(this);
            var $status = $('#connection-status');
            
            $button.prop('disabled', true).text(bexioAdmin.i18n.testing);
            $status.removeClass('success error').text('');
            
            $.ajax({
                url: bexioAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'bexio_test_connection',
                    nonce: bexioAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.addClass('success').html('✓ ' + response.data.message);
                    } else {
                        $status.addClass('error').html('✗ ' + response.data.message);
                    }
                },
                error: function() {
                    $status.addClass('error').html('✗ ' + bexioAdmin.i18n.failed);
                },
                complete: function() {
                    $button.prop('disabled', false).text(bexioAdmin.i18n.testing.replace('...', ''));
                }
            });
        });
        
        // Fetch Bank Accounts Button
        $('#fetch-accounts').on('click', function() {
            var $button = $(this);
            var $status = $('#fetch-status');
            var $invoiceSelect = $('#bexio_wc_invoice_bank_id');
            var $cardSelect = $('#bexio_wc_card_bank_id');
            
            $button.prop('disabled', true).text(bexioAdmin.i18n.fetching);
            $status.removeClass('success error').text('');
            
            $.ajax({
                url: bexioAdmin.ajax_url,
                type: 'POST',
                data: {
                    action: 'bexio_fetch_accounts',
                    nonce: bexioAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data.accounts) {
                        // Clear existing options except the first one
                        $invoiceSelect.find('option:not(:first)').remove();
                        $cardSelect.find('option:not(:first)').remove();
                        
                        // Add new options
                        $.each(response.data.accounts, function(i, account) {
                            var optionText = account.name + ' - ' + account.account_no;
                            var option = $('<option></option>')
                                .attr('value', account.id)
                                .text(optionText);
                            
                            $invoiceSelect.append(option.clone());
                            $cardSelect.append(option);
                        });
                        
                        $status.addClass('success').html('✓ ' + response.data.message);
                        
                        // Show save button reminder
                        setTimeout(function() {
                            $status.append(' <em>' + 'Don\'t forget to save your changes!' + '</em>');
                        }, 1000);
                    } else {
                        $status.addClass('error').html('✗ ' + (response.data.message || bexioAdmin.i18n.fetch_failed));
                    }
                },
                error: function() {
                    $status.addClass('error').html('✗ ' + bexioAdmin.i18n.fetch_failed);
                },
                complete: function() {
                    $button.prop('disabled', false).text(bexioAdmin.i18n.fetching.replace('...', ''));
                }
            });
        });
        
        // Migration functionality
        var migrationInProgress = false;
        var migrationStopped = false;
        
        $('#start-migration').on('click', function() {
            if (migrationInProgress) return;
            
            if (!confirm('Are you sure you want to start migrating all orders to Bexio? This may take a while.')) {
                return;
            }
            
            migrationInProgress = true;
            migrationStopped = false;
            
            $('#migration-status').show();
            $('#start-migration').hide();
            $('#stop-migration').show();
            $('#migration-progress').css('width', '0%');
            $('#migration-message').text(bexioAdmin.i18n.migrating);
            $('#migration-details').empty();
            
            runMigration();
        });
        
        $('#stop-migration').on('click', function() {
            if (confirm(bexioAdmin.i18n.confirm_stop)) {
                migrationStopped = true;
                $(this).prop('disabled', true).text('Stopping...');
                $('#migration-message').text(bexioAdmin.i18n.migration_stopped);
            }
        });
        
        function runMigration() {
            $.ajax({
                url: bexioAdmin.rest_url + 'bexio-wc/v1/migrate/orders',
                type: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', bexioAdmin.nonce);
                },
                data: JSON.stringify({
                    batch_size: 10 // Process 10 orders at a time
                }),
                contentType: 'application/json',
                success: function(response) {
                    if (response.success) {
                        // Update progress
                        var progress = (response.processed / response.total) * 100;
                        $('#migration-progress').css('width', progress + '%');
                        $('#migration-message').text(
                            'Processed ' + response.processed + ' of ' + response.total + ' orders'
                        );
                        
                        // Add details
                        if (response.details) {
                            $.each(response.details, function(i, detail) {
                                var status = detail.success ? '✓' : '✗';
                                var color = detail.success ? '#46b450' : '#dc3232';
                                $('#migration-details').append(
                                    '<div style="color: ' + color + '">' + 
                                    status + ' Order #' + detail.order_id + ': ' + detail.message + 
                                    '</div>'
                                );
                            });
                            
                            // Auto-scroll to bottom
                            $('#migration-details').scrollTop($('#migration-details')[0].scrollHeight);
                        }
                        
                        // Continue if not complete and not stopped
                        if (!response.complete && !migrationStopped) {
                            setTimeout(runMigration, 2000); // Wait 2 seconds between batches
                        } else {
                            completeMigration(response.complete);
                        }
                    } else {
                        $('#migration-message').html('<span style="color: #dc3232;">Error: ' + response.message + '</span>');
                        completeMigration(false);
                    }
                },
                error: function(xhr) {
                    var errorMsg = 'Migration failed. Please check the logs.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    $('#migration-message').html('<span style="color: #dc3232;">Error: ' + errorMsg + '</span>');
                    completeMigration(false);
                }
            });
        }
        
        function completeMigration(success) {
            migrationInProgress = false;
            $('#stop-migration').hide();
            $('#start-migration').show().prop('disabled', false);
            
            if (success) {
                $('#migration-message').html('<span style="color: #46b450;">✓ ' + bexioAdmin.i18n.migration_complete + '</span>');
            } else if (migrationStopped) {
                $('#migration-message').html('<span style="color: #856404;">⚠ ' + bexioAdmin.i18n.migration_stopped + '</span>');
            }
        }
        
        // Form validation before submit
        $('form[action="options.php"]').on('submit', function(e) {
            var tab = new URLSearchParams(window.location.search).get('tab');
            
            if (tab === 'api') {
                var clientId = $('#bexio_wc_client_id').val();
                var clientSecret = $('#bexio_wc_client_secret').val();
                
                if (!clientId && !clientSecret) {
                    // Both empty - might be intentional, allow
                    return true;
                }
                
                if (!clientId || !clientSecret) {
                    alert('Please fill in both Client ID and Client Secret, or leave both empty.');
                    e.preventDefault();
                    return false;
                }
            }
            
            if (tab === 'orders') {
                var createChecked = $('input[name="bexio_wc_create_on_status[]"]:checked').length;
                var completeChecked = $('input[name="bexio_wc_complete_on_status[]"]:checked').length;
                
                if (createChecked === 0 && completeChecked === 0) {
                    alert('Please select at least one order status for synchronization.');
                    e.preventDefault();
                    return false;
                }
            }
        });
        
        // Show/hide password fields
        $('.toggle-password').on('click', function() {
            var $input = $(this).siblings('input');
            var type = $input.attr('type') === 'password' ? 'text' : 'password';
            $input.attr('type', type);
            $(this).text(type === 'password' ? 'Show' : 'Hide');
        });
        
        // Add unsaved changes warning
        var formChanged = false;
        $('form[action="options.php"] input, form[action="options.php"] select, form[action="options.php"] textarea').on('change', function() {
            formChanged = true;
        });
        
        $(window).on('beforeunload', function() {
            if (formChanged) {
                return 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
        
        $('form[action="options.php"]').on('submit', function() {
            formChanged = false;
        });
    });
    
})(jQuery);