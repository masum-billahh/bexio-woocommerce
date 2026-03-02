<?php
/**
 * Bexio Order Batch Migration Handler
 * Handles batch migration of WooCommerce orders to Bexio
 */

class Bexio_Order_Batch_Migration {
    
    private static $instance = null;
    private $batch_size = 3;
    private $order_sync; 
	
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->order_sync = Bexio_WC_Order_Sync::get_instance();
        
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('wp_ajax_start_bexio_batch_migration', [$this, 'start_batch_migration']);
        add_action('wp_ajax_process_bexio_batch', [$this, 'process_batch']);
        add_action('wp_ajax_get_bexio_migration_status', [$this, 'get_migration_status']);
        add_action('wp_ajax_reset_bexio_migration', [$this, 'reset_migration']);
        add_action('wp_ajax_resume_bexio_migration', [$this, 'resume_batch_migration']);
        add_action('wp_ajax_migrate_single_bexio_order', [$this, 'migrate_single_order_ajax']);
        add_action('wp_ajax_start_migration_after_order', [$this, 'start_migration_after_order']);
		add_action('wp_ajax_delete_all_bexio_orders', [$this, 'delete_all_bexio_orders']);
    }
    
    public function init() {
        if (!get_option('bexio_batch_migration_status')) {
            update_option('bexio_batch_migration_status', [
                'status' => 'idle',
                'total_orders' => 0,
                'processed_orders' => 0,
                'failed_orders' => 0,
                'created_orders' => 0,
                'completed_orders' => 0,
                'current_batch' => 0,
                'start_time' => null,
                'end_time' => null,
                'errors' => [],
                'skip_emails' => true // Always skip emails during migration
            ]);
        }
    }
    
    public function add_admin_page() {
        add_submenu_page(
            'woocommerce',
            'Bexio Order Migration',
            'Bexio Migration',
            'manage_woocommerce',
            'bexio-order-migration',
            [$this, 'admin_page_html']
        );
    }
    
    public function admin_page_html() {
        $status = get_option('bexio_batch_migration_status');
        ?>
        <div class="wrap">
            <h1>Bexio Order Batch Migration</h1>
            
            <div class="notice notice-warning">
                <p><strong>Important:</strong> This will migrate WooCommerce orders to Bexio. Orders with status "completed" will be both created and completed in Bexio. No emails will be sent during migration.</p>
            </div>
            
            <div id="migration-controls">
                <button id="start-migration" class="button button-primary">Start Batch Migration</button>
                <button id="reset-migration" class="button">Reset Progress</button>
                
                <?php if ($status['status'] == 'processing' || $status['status'] == 'error'): ?>
                <div style="margin-top: 10px;">
                    <label for="resume-order-id">Resume from Order ID:</label>
                    <input type="number" id="resume-order-id" value="<?php echo $status['last_processed_id'] ?? ''; ?>" />
                    <button id="resume-migration" class="button button-secondary">Resume Migration</button>
                </div>
                <?php endif; ?>
            </div>
            
            <div id="single-migration" style="margin-top: 20px;">
                <h3>Migrate Single/Multiple Orders</h3>
                <label for="single-order-id">Order ID(s) - comma separated for multiple:</label>
                <input type="text" id="single-order-id" placeholder="e.g., 123 or 123,124,125" />
                <button id="migrate-single" class="button button-secondary">Migrate Order(s)</button>
                <div id="single-migration-result" style="margin-top: 10px;"></div>
            </div>
            
            <div id="date-based-migration" style="margin-top: 20px;">
                <h3>Migrate Orders After Specific Order ID</h3>
                <label for="after-order-id">Start after Order ID:</label>
                <input type="number" id="after-order-id" placeholder="e.g., 12345" />
                <button id="migrate-after-order" class="button button-secondary">Migrate All Orders After This</button>
                <div id="after-order-result" style="margin-top: 10px;"></div>
            </div>
			
			<div id="delete-section" style="margin-top: 20px; border: 2px solid #dc3232; padding: 15px; background: #fff8f8;">
				<h3 style="color: #dc3232;">Delete All Bexio Orders</h3>
				<p><strong>WARNING:</strong> This will delete ALL synced Bexio orders and invoices. This action cannot be undone!</p>
				<button id="delete-all-bexio" class="button button-secondary" style="background: #dc3232; color: white; border-color: #dc3232;">Delete All Bexio Orders</button>
				<div id="delete-result" style="margin-top: 10px;"></div>
			</div>
            
            <div id="migration-progress" style="margin-top: 20px;">
                <div id="progress-info">
                    <p><strong>Status:</strong> <span id="status-text">Idle</span></p>
                    <p><strong>Progress:</strong> <span id="progress-text">0 / 0</span></p>
                    <p><strong>Created:</strong> <span id="created-text">0</span></p>
                    <p><strong>Completed:</strong> <span id="completed-text">0</span></p>
                    <p><strong>Failed:</strong> <span id="failed-text">0</span></p>
                    <p><strong>Last Processed ID:</strong> <span id="last-id-text">-</span></p>
                    <p><strong>Time Elapsed:</strong> <span id="time-text">00:00:00</span></p>
                </div>
                
                <div id="progress-bar-container" style="width: 100%; background-color: #f0f0f0; border-radius: 5px; margin: 10px 0;">
                    <div id="progress-bar" style="width: 0%; height: 20px; background-color: #0073aa; border-radius: 5px; transition: width 0.3s;"></div>
                </div>
                
                <div id="error-log" style="margin-top: 20px;">
                    <h3>Error Log</h3>
                    <div id="error-list" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;"></div>
                </div>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            let migrationInterval;
            let startTime;
            updateStatus();
            
            $('#start-migration').click(function() {
                if (!confirm('Start migration? This will process all WooCommerce orders and create them in Bexio. Continue?')) {
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'start_bexio_batch_migration',
                    nonce: '<?php echo wp_create_nonce('bexio_batch_migration'); ?>'
                }, function(response) {
                    if (response.success) {
                        startTime = new Date();
                        startProgressTracking();
                        processBatch();
                    } else {
                        alert('Error starting migration: ' + response.data);
                    }
                });
            });
            
            $('#resume-migration').click(function() {
                const resumeId = $('#resume-order-id').val();
                if (!resumeId) {
                    alert('Please enter an Order ID to resume from');
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'resume_bexio_migration',
                    resume_order_id: resumeId,
                    nonce: '<?php echo wp_create_nonce('bexio_batch_migration'); ?>'
                }, function(response) {
                    if (response.success) {
                        startTime = new Date();
                        startProgressTracking();
                        processBatch();
                    } else {
                        alert('Error resuming migration: ' + response.data);
                    }
                });
            });
            
            $('#reset-migration').click(function() {
                if (confirm('Are you sure you want to reset the migration progress?')) {
                    $.post(ajaxurl, {
                        action: 'reset_bexio_migration',
                        nonce: '<?php echo wp_create_nonce('bexio_batch_migration'); ?>'
                    }, function(response) {
                        updateStatus();
                        stopProgressTracking();
                    });
                }
            });
            
            $('#migrate-after-order').click(function() {
                const afterOrderId = $('#after-order-id').val();
                if (!afterOrderId) {
                    alert('Please enter an Order ID');
                    return;
                }

                if (!confirm('This will migrate all orders created after Order #' + afterOrderId + '. Continue?')) {
                    return;
                }

                $('#migrate-after-order').prop('disabled', true).text('Preparing...');
                $('#after-order-result').html('');

                $.post(ajaxurl, {
                    action: 'start_migration_after_order',
                    after_order_id: afterOrderId,
                    nonce: '<?php echo wp_create_nonce('bexio_batch_migration'); ?>'
                }, function(response) {
                    $('#migrate-after-order').prop('disabled', false).text('Migrate All Orders After This');

                    if (response.success) {
                        startTime = new Date();
                        startProgressTracking();
                        processBatch();
                        $('#after-order-result').html('<p style="color: blue;">Migration started. Found ' + response.data.total_orders + ' orders.</p>');
                    } else {
                        $('#after-order-result').html('<p style="color: red;">Error: ' + response.data + '</p>');
                    }
                });
            });
            
            $('#migrate-single').click(function() {
                const orderId = $('#single-order-id').val();
                if (!orderId) {
                    alert('Please enter an Order ID');
                    return;
                }
                
                $('#migrate-single').prop('disabled', true).text('Migrating...');
                $('#single-migration-result').html('');
                
                $.post(ajaxurl, {
                    action: 'migrate_single_bexio_order',
                    order_id: orderId,
                    nonce: '<?php echo wp_create_nonce('bexio_batch_migration'); ?>'
                }, function(response) {
                    $('#migrate-single').prop('disabled', false).text('Migrate Order(s)');
                    
                    if (response.success) {
                        if (response.data.use_batch) {
                            startTime = new Date();
                            startProgressTracking();
                            processBatch();
                            $('#single-migration-result').html('<p style="color: blue;">Processing multiple orders...</p>');
                        } else {
                            $('#single-migration-result').html('<p style="color: green;">' + response.data.message + '</p>');
                        }
                    } else {
                        $('#single-migration-result').html('<p style="color: red;">Error: ' + response.data + '</p>');
                    }
                });
            });
			
			//DELETE all bexio order
			$('#delete-all-bexio').click(function() {
				if (!confirm('⚠️ WARNING ⚠️\n\nThis will DELETE ALL Bexio orders and invoices!\n\nThis action CANNOT be undone!\n\nAre you ABSOLUTELY sure?')) {
					return;
				}

				if (!confirm('FINAL CONFIRMATION:\n\nYou are about to permanently delete all Bexio orders.\n\nType YES in the next prompt to continue.')) {
					return;
				}

				const confirmation = prompt('Type YES to confirm deletion:');
				if (confirmation !== 'YES') {
					alert('Deletion cancelled.');
					return;
				}

				$('#delete-all-bexio').prop('disabled', true).text('Deleting...');
				$('#delete-result').html('<p style="color: blue;">Deleting all Bexio orders...</p>');

				$.post(ajaxurl, {
					action: 'delete_all_bexio_orders',
					nonce: '<?php echo wp_create_nonce('bexio_batch_migration'); ?>'
				}, function(response) {
					$('#delete-all-bexio').prop('disabled', false).text('Delete All Bexio Orders');

					if (response.success) {
						$('#delete-result').html('<p style="color: green;">' + response.data.message + '</p>');
						if (response.data.errors.length > 0) {
							let errorHtml = '<div style="margin-top: 10px;"><strong>Errors:</strong><ul>';
							response.data.errors.forEach(function(error) {
								errorHtml += '<li>Order #' + error.order_id + ': ' + error.message + '</li>';
							});
							errorHtml += '</ul></div>';
							$('#delete-result').append(errorHtml);
						}
					} else {
						$('#delete-result').html('<p style="color: red;">Error: ' + response.data + '</p>');
					}
				});
			});
            
            function processBatch() {
                $.post(ajaxurl, {
                    action: 'process_bexio_batch',
                    nonce: '<?php echo wp_create_nonce('bexio_batch_migration'); ?>'
                }, function(response) {
                    if (response.success) {
                        updateStatus();
                        if (response.data.continue) {
                            setTimeout(processBatch, 4000); // 4 second delay between batches
                        } else {
                            stopProgressTracking();
                            alert('Batch migration completed!');
                        }
                    } else {
                        stopProgressTracking();
                        alert('Error during migration: ' + response.data);
                    }
                });
            }
            
            function startProgressTracking() {
                migrationInterval = setInterval(updateStatus, 5000); // Update every 5 seconds
                $('#start-migration').prop('disabled', true).text('Migration in Progress...');
                $('#resume-migration').prop('disabled', true);
            }
            
            function stopProgressTracking() {
                if (migrationInterval) {
                    clearInterval(migrationInterval);
                }
                $('#start-migration').prop('disabled', false).text('Start Batch Migration');
                $('#resume-migration').prop('disabled', false);
                updateStatus();
            }
            
            function updateStatus() {
                $.post(ajaxurl, {
                    action: 'get_bexio_migration_status',
                    nonce: '<?php echo wp_create_nonce('bexio_batch_migration'); ?>'
                }, function(response) {
                    if (response.success) {
                        const status = response.data;
                        
                        $('#status-text').text(status.status);
                        $('#progress-text').text(status.processed_orders + ' / ' + status.total_orders);
                        $('#created-text').text(status.created_orders);
                        $('#completed-text').text(status.completed_orders);
                        $('#failed-text').text(status.failed_orders);
                        $('#last-id-text').text(status.last_processed_id || '-');
                        
                        const percentage = status.total_orders > 0 ? (status.processed_orders / status.total_orders) * 100 : 0;
                        $('#progress-bar').css('width', percentage + '%');
                        
                        if (status.status === 'processing' && startTime) {
                            const elapsed = Math.floor((new Date() - startTime) / 1000);
                            $('#time-text').text(formatTime(elapsed));
                        } else if (status.start_time && status.end_time) {
                            const elapsed = Math.floor((new Date(status.end_time) - new Date(status.start_time)) / 1000);
                            $('#time-text').text(formatTime(elapsed));
                        }
                        
                        let errorHtml = '';
                        if (status.errors && status.errors.length > 0) {
                            status.errors.forEach(function(error) {
                                errorHtml += '<div style="margin-bottom: 5px; padding: 5px; background: #ffebee; border-left: 3px solid #f44336;">';
                                errorHtml += '<strong>Order #' + error.order_id + ':</strong> ' + error.message;
                                errorHtml += '</div>';
                            });
                        } else {
                            errorHtml = '<p>No errors recorded.</p>';
                        }
                        $('#error-list').html(errorHtml);
                    }
                });
            }
            
            function formatTime(seconds) {
                const hours = Math.floor(seconds / 3600);
                const minutes = Math.floor((seconds % 3600) / 60);
                const secs = seconds % 60;
                return [hours, minutes, secs].map(v => v.toString().padStart(2, '0')).join(':');
            }
        });
        </script>
        <?php
    }
    
    public function start_migration_after_order() {
        check_ajax_referer('bexio_batch_migration', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        $after_order_id = intval($_POST['after_order_id']);

        $reference_order = wc_get_order($after_order_id);
        if (!$reference_order) {
            wp_send_json_error('Order ID not found');
        }

        $reference_date = $reference_order->get_date_created();
        if (!$reference_date) {
            wp_send_json_error('Could not get order creation date');
        }

        $args = [
            'limit' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'return' => 'ids',
            'type' => 'shop_order',
            'date_created' => '>' . $reference_date->date('Y-m-d H:i:s'),
            'status' => 'any'
        ];

        $order_ids = wc_get_orders($args);

        if (empty($order_ids)) {
            wp_send_json_error('No orders found after Order #' . $after_order_id);
        }

        $status = [
            'status' => 'processing',
            'total_orders' => count($order_ids),
            'processed_orders' => 0,
            'failed_orders' => 0,
            'created_orders' => 0,
            'completed_orders' => 0,
            'current_batch' => 0,
            'start_time' => current_time('mysql'),
            'end_time' => null,
            'errors' => [],
            'order_ids' => $order_ids,
            'last_processed_id' => null,
            'skip_emails' => true
        ];

        update_option('bexio_batch_migration_status', $status);

        wp_send_json_success([
            'message' => 'Migration started', 
            'total_orders' => count($order_ids),
            'reference_order_id' => $after_order_id,
            'reference_date' => $reference_date->date('Y-m-d H:i:s')
        ]);
    }
    
    public function start_batch_migration() {
        check_ajax_referer('bexio_batch_migration', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        $args = [
            'limit' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'return' => 'ids',
            'type' => 'shop_order',
            'status' => 'any'
        ];
        
        $order_ids = wc_get_orders($args);
        
        $status = [
            'status' => 'processing',
            'total_orders' => count($order_ids),
            'processed_orders' => 0,
            'failed_orders' => 0,
            'created_orders' => 0,
            'completed_orders' => 0,
            'current_batch' => 0,
            'start_time' => current_time('mysql'),
            'end_time' => null,
            'errors' => [],
            'order_ids' => $order_ids,
            'last_processed_id' => null,
            'skip_emails' => true
        ];
        
        update_option('bexio_batch_migration_status', $status);
        
        wp_send_json_success(['message' => 'Migration started', 'total_orders' => count($order_ids)]);
    }

    public function resume_batch_migration() {
        check_ajax_referer('bexio_batch_migration', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        $resume_order_id = intval($_POST['resume_order_id']);
        $status = get_option('bexio_batch_migration_status');
        
        if (!$status || !isset($status['order_ids'])) {
            wp_send_json_error('No previous migration found');
        }
        
        $resume_position = array_search($resume_order_id, $status['order_ids']);
        
        if ($resume_position === false) {
            wp_send_json_error('Order ID not found in migration list');
        }
        
        $orders_to_skip = $resume_position;
        $status['current_batch'] = floor($orders_to_skip / $this->batch_size);
        $status['processed_orders'] = $orders_to_skip;
        $status['status'] = 'processing';
        $status['start_time'] = current_time('mysql');
        $status['end_time'] = null;
        
        update_option('bexio_batch_migration_status', $status);
        
        wp_send_json_success(['message' => 'Migration resumed from Order ID: ' . $resume_order_id]);
    }
    
    public function migrate_single_order_ajax() {
        check_ajax_referer('bexio_batch_migration', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        $order_ids_input = sanitize_text_field($_POST['order_id']);
        $order_ids = array_map('intval', array_map('trim', explode(',', $order_ids_input)));
        $order_ids = array_filter($order_ids);
        
        if (empty($order_ids)) {
            wp_send_json_error('Invalid Order ID(s)');
        }
        
        // If multiple orders, use batch system
        if (count($order_ids) > 1) {
            $status = [
                'status' => 'processing',
                'total_orders' => count($order_ids),
                'processed_orders' => 0,
                'failed_orders' => 0,
                'created_orders' => 0,
                'completed_orders' => 0,
                'current_batch' => 0,
                'start_time' => current_time('mysql'),
                'end_time' => null,
                'errors' => [],
                'order_ids' => $order_ids,
                'last_processed_id' => null,
                'skip_emails' => true
            ];
            
            update_option('bexio_batch_migration_status', $status);
            wp_send_json_success(['use_batch' => true, 'total_orders' => count($order_ids)]);
        }
        
        // Single order migration
        $result = $this->migrate_single_order($order_ids[0]);
        
        if ($result['success']) {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    public function process_batch() {
        check_ajax_referer('bexio_batch_migration', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        $status = get_option('bexio_batch_migration_status');
        
        if ($status['status'] !== 'processing') {
            wp_send_json_error('Migration not in progress');
        }
        
        $start_index = $status['current_batch'] * $this->batch_size;
        $order_ids_batch = array_slice($status['order_ids'], $start_index, $this->batch_size);
        
        if (empty($order_ids_batch)) {
            $status['status'] = 'completed';
            $status['end_time'] = current_time('mysql');
            update_option('bexio_batch_migration_status', $status);
            
            wp_send_json_success(['continue' => false, 'message' => 'Migration completed']);
        }
        
        foreach ($order_ids_batch as $order_id) {
            $result = $this->migrate_single_order($order_id);
            
            if ($result['success']) {
                $status['processed_orders']++;
                $status['last_processed_id'] = $order_id;
                
                if ($result['created']) {
                    $status['created_orders']++;
                }
                if ($result['completed']) {
                    $status['completed_orders']++;
                }
            } else {
                $status['failed_orders']++;
                $status['errors'][] = [
                    'order_id' => $order_id,
                    'message' => $result['message']
                ];
                
                if (count($status['errors']) > 50) {
                    $status['errors'] = array_slice($status['errors'], -50);
                }
            }
        }
        
        $status['current_batch']++;
        update_option('bexio_batch_migration_status', $status);
        
        $continue = ($start_index + $this->batch_size) < $status['total_orders'];
        
        wp_send_json_success([
            'continue' => $continue,
            'processed' => $status['processed_orders'],
            'total' => $status['total_orders']
        ]);
    }
    
    /**
     * Migrate a single order to Bexio
     */
    private function migrate_single_order($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return [
                'success' => false,
                'message' => 'Order not found'
            ];
        }
        
        $created = false;
        $completed = false;
        
        try {
            // Temporarily disable email sending
            $this->disable_emails();
            
            $order_status = $order->get_status();
            
            // Use handle_order_status_change to properly create the order
            $this->order_sync->handle_order_status_change(
                $order_id,
                '', // old status doesn't matter for migration
                $order_status,
                $order
            );
            
            // Check if order was created
            $bexio_order_id = $order->get_meta('_bexio_order_id', true);
            if ($bexio_order_id) {
                $created = true;
            }
            
            // Check if order was completed (invoice exists)
			$bexio_invoice_id = $order->get_meta('_bexio_invoice_id', true);
			if ($bexio_invoice_id) {
				$completed = true;
			}
            
            // Re-enable emails
            $this->enable_emails();
            
            if (!$created) {
				throw new Exception('Order was not created in Bexio');
			}

			$message = 'Order created in Bexio.';
			if ($completed) {
				$message = 'Order created and completed in Bexio.';
			}
            
            return [
                'success' => true,
                'created' => $created,
                'completed' => $completed,
                'message' => $message
            ];
            
        } catch (Exception $e) {
            // Re-enable emails in case of error
            $this->enable_emails();
            
            return [
                'success' => false,
                'created' => $created,
                'completed' => $completed,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Disable email sending during migration
     */
    private function disable_emails() {
        // Hook into the PDF handler methods to prevent email sending
        add_filter('bexio_wc_skip_order_email', '__return_true', 999);
        add_filter('bexio_wc_skip_invoice_email', '__return_true', 999);
    }
    
    /**
     * Re-enable email sending after migration
     */
    private function enable_emails() {
        remove_filter('bexio_wc_skip_order_email', '__return_true', 999);
        remove_filter('bexio_wc_skip_invoice_email', '__return_true', 999);
    }
    
    public function get_migration_status() {
        check_ajax_referer('bexio_batch_migration', 'nonce');
        
        $status = get_option('bexio_batch_migration_status');
        unset($status['order_ids']); // Don't send large array back
        
        wp_send_json_success($status);
    }
    
    public function reset_migration() {
        check_ajax_referer('bexio_batch_migration', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }
        
        $status = [
            'status' => 'idle',
            'total_orders' => 0,
            'processed_orders' => 0,
            'failed_orders' => 0,
            'created_orders' => 0,
            'completed_orders' => 0,
            'current_batch' => 0,
            'start_time' => null,
            'end_time' => null,
            'errors' => [],
            'skip_emails' => true
        ];
        
        update_option('bexio_batch_migration_status', $status);
        
        wp_send_json_success('Migration reset');
    }
	
	/**
	 * Delete all Bexio orders that have been synced
	 */
	public function delete_all_bexio_orders() {
		check_ajax_referer('bexio_batch_migration', 'nonce');

		if (!current_user_can('manage_woocommerce')) {
			wp_die('Unauthorized');
		}

		// Get all orders that have Bexio order IDs
		$args = [
			'limit' => -1,
			'meta_key' => '_bexio_order_id',
			'meta_compare' => 'EXISTS',
			'return' => 'ids',
			'type' => 'shop_order',
			'status' => 'any'
		];

		$order_ids = wc_get_orders($args);

		$deleted_count = 0;
		$failed_count = 0;
		$errors = [];

		foreach ($order_ids as $order_id) {
			$order = wc_get_order($order_id);

			if ($order) {
				$result = $this->order_sync->cancel_bexio_order($order);

				if ($result) {
					$deleted_count++;
				} else {
					$failed_count++;
					$errors[] = [
						'order_id' => $order_id,
						'message' => 'Failed to delete Bexio order'
					];
				}
			}
		}

		wp_send_json_success([
			'message' => "Deletion completed. Deleted: {$deleted_count}, Failed: {$failed_count}",
			'deleted' => $deleted_count,
			'failed' => $failed_count,
			'errors' => $errors
		]);
	}
	
}

// Initialize the migration handler as singleton
add_action('plugins_loaded', function() {
    if (class_exists('Bexio_WC_Order_Sync')) {
        Bexio_Order_Batch_Migration::get_instance();
    }
}, 25);