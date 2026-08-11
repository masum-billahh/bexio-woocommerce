<?php
/**
 * PDF Handler
 * Manages PDF generation, storage, and sending
 * HPOS Compatible
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bexio_WC_PDF_Handler {
    
    private static $instance = null;
    private $api;
    private $upload_dir;
    
    
private $pickup_shipping_instance_ids = array(18, 19);

// Shipping method instance IDs that require a GAS ("gas_eco") label —
// these are independent of the pickup instance IDs above.
private $gas_shipping_instance_ids = array(23, 24);

// Confirmed from the plugin's own handler:
// wp_verify_nonce($_POST['nonce'], 'ajax-nonce')
const SWISSPOST_NONCE_ACTION = 'ajax-nonce';

// whether it should be pulled from a plugin option instead.
const SWISSPOST_LICENSE = '60156186';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->api = Bexio_WC_API::get_instance();
        $this->setup_upload_dir();
        $this->init_hooks();
    }
    
    private function setup_upload_dir() {
        $upload = wp_upload_dir();
        $this->upload_dir = $upload['basedir'] . '/bexio-pdfs';
        
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
            
            // Add .htaccess for security
            file_put_contents($this->upload_dir . '/.htaccess', 'deny from all');
        }
    }
    
    private function init_hooks() {
		add_filter('woocommerce_email_attachments', [$this, 'attach_bexio_pdf_to_alg_email'], 10, 4);
		
		//bulk
		add_action('admin_footer', [$this, 'download_pdf_files']);
        add_action('admin_init', [$this, 'single_pdf_download']);
		add_action('wp_ajax_cleanup_bulk_download', [$this, 'cleanup_bulk_download']);
		add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'add_bulk_action'));
		add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'handle_bulk_action'), 10, 3);
		add_action('admin_init', array($this, 'serve_bulk_zip'));
		
        // Add meta boxes to order screen
        add_action('add_meta_boxes', array($this, 'add_pdf_meta_boxes'));
        
        // Add print buttons to order actions
        add_action('woocommerce_order_actions', array($this, 'add_order_actions'));
        add_action('woocommerce_order_action_print_bexio_order_pdf', array($this, 'download_order_pdf'));
        add_action('woocommerce_order_action_print_bexio_invoice_pdf', array($this, 'download_invoice_pdf'));
		//add_action('woocommerce_order_action_email_bexio_order_pdf', array($this, 'send_order_pdf'));
        //add_action('woocommerce_order_action_email_bexio_invoice_pdf', array($this, 'send_invoice_pdf'));
       add_action('woocommerce_order_action_email_bexio_order_pdf', function($order){
		add_filter('bexio_wc_force_send_email', '__return_true');
		}, 1);

		add_action('woocommerce_order_action_email_bexio_invoice_pdf', function($order){
			add_filter('bexio_wc_force_send_email', '__return_true');
 		    $this->send_invoice_pdf($order);
		}, 1);
 
        // HPOS-compatible columns 
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_order_column'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_order_column'), 10, 2);
        
        // legacy support for non-HPOS sites
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_order_column_legacy'), 10, 2);
        
        // Add AJAX handlers for PDF downloads
        add_action('wp_ajax_bexio_download_order_pdf', array($this, 'ajax_download_order_pdf'));
        add_action('wp_ajax_bexio_download_invoice_pdf', array($this, 'ajax_download_invoice_pdf'));
        
        // Add custom CSS for the column icons
        add_action('admin_head', array($this, 'add_column_styles'));
        
        //bulk pdf print
        add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'add_curated_bulk_action'));
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'handle_curated_bulk_action'), 10, 3);
        add_action('admin_init', array($this, 'render_curated_print_view'));
        add_action('wp_ajax_bexio_stream_curated_pdf', array($this, 'stream_curated_pdf'));
        add_action('admin_footer', array($this, 'add_curated_print_new_tab_script'));
        add_action('wp_ajax_bexio_stream_merged_curated_pdf', array($this, 'stream_merged_curated_pdf'));
        //swiss post bulk print
        
        add_filter('bulk_actions-woocommerce_page_wc-orders', array($this, 'add_swisspost_curated_bulk_action'));
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', array($this, 'handle_swisspost_curated_bulk_action'), 10, 3);
        add_action('admin_init', array($this, 'render_swisspost_curated_print_view'));
        add_action('wp_ajax_bexio_swisspost_resolve_labels', array($this, 'ajax_resolve_swisspost_labels'));
        add_action('wp_ajax_bexio_swisspost_stream_pdf', array($this, 'ajax_stream_swisspost_pdf'));
    
    }
    
    /**
     * Add custom CSS for clickable icons in orders list
     */
    public function add_column_styles() {
        $screen = get_current_screen();
        if (!$screen || ($screen->id !== 'woocommerce_page_wc-orders' && $screen->id !== 'edit-shop_order')) {
            return;
        }
        ?>
        <style>
            .bexio-doc-icon {
                display: inline-block;
                margin-right: 5px;
                cursor: pointer;
                text-decoration: none;
                color: #2271b1;
            }
            .bexio-doc-icon:hover {
                color: #135e96;
            }
            .bexio-doc-icon .dashicons {
                width: 18px;
                height: 18px;
                font-size: 18px;
            }
        </style>
        <?php
    }
    
    /**
     * AJAX handler for downloading order PDF
     */
    public function ajax_download_order_pdf() {
        check_ajax_referer('bexio_pdf', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_die(__('Insufficient permissions', 'bexio-wc'));
        }
        
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        
        if (!$order_id) {
            wp_die(__('Invalid order ID', 'bexio-wc'));
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(__('Order not found', 'bexio-wc'));
        }
        
        $this->download_order_pdf($order);
    }
    
    /**
     * AJAX handler for downloading invoice PDF
     */
    public function ajax_download_invoice_pdf() {
        check_ajax_referer('bexio_pdf', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_die(__('Insufficient permissions', 'bexio-wc'));
        }
        
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        
        if (!$order_id) {
            wp_die(__('Invalid order ID', 'bexio-wc'));
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(__('Order not found', 'bexio-wc'));
        }
        
        $this->download_invoice_pdf($order);
    }
	
	public function attach_bexio_pdf_to_alg_email($attachments, $email_id, $order, $email) {

		if (!$order instanceof WC_Order) {
			return $attachments;
		}
		
		// If already attached once, stop
		if ($order->get_meta('_pdf_attached_once')) {
			return $attachments;
		}
		
		if (!$order->get_meta('_bexio_order_pdf')) {
			$this->get_order_pdf($order->get_id());
		}
		
		$filename = $order->get_meta('_bexio_order_pdf');
		if (!$filename) {
			return $attachments;
		}

		$filepath = trailingslashit($this->upload_dir) . $filename;
		$status = $order->get_status(); 

		if (
			file_exists($filepath) && 
			get_option('bexio_wc_auto_send_pdfs') === 'yes' &&
			in_array($status, ['service-eingetrof', 'processing'], true)
		) {
			$attachments[] = $filepath;
			
			// Mark as attached so next emails don't include it
			$order->update_meta_data('_pdf_attached_once', 1);
			$order->add_order_note(__('Bexio order pdf was attached to the email', 'bexio-wc'));
			$order->save();
		}

		return $attachments;
	}
  
    /**
     * Get order PDF from Bexio and store locally
     */
    public function get_order_pdf($order_id, $bexio_order_id = null) {
        if (!$bexio_order_id) {
            $order = wc_get_order($order_id);
            $bexio_order_id = $order ? $order->get_meta('_bexio_order_id') : '';
        }
        
        if (!$bexio_order_id) {
            return false;
        }
        
        $pdf_content = $this->api->get_order_pdf($bexio_order_id);
        
        if ($pdf_content) {
            $filename = 'order-' . $order_id . '-' . $bexio_order_id . '.pdf';
            $filepath = $this->upload_dir . '/' . $filename;
            
            file_put_contents($filepath, $pdf_content);
            
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_bexio_order_pdf', $filename);
                $order->save();
            }
            
            return $filepath;
        }
        
        return false;
    }
    
    /**
     * Get invoice PDF from Bexio and store locally
     */
    public function get_invoice_pdf($order_id, $bexio_invoice_id = null) {
        if (!$bexio_invoice_id) {
            $order = wc_get_order($order_id);
            $bexio_invoice_id = $order ? $order->get_meta('_bexio_invoice_id') : '';
        }
        
        if (!$bexio_invoice_id) {
            return false;
        }
        
        $pdf_content = $this->api->get_invoice_pdf($bexio_invoice_id);
        
        if ($pdf_content) {
            $filename = 'invoice-' . $order_id . '-' . $bexio_invoice_id . '.pdf';
            $filepath = $this->upload_dir . '/' . $filename;
            
            file_put_contents($filepath, $pdf_content);
            
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_bexio_invoice_pdf', $filename);
                $order->save();
            }
            
            return $filepath;
        }
        
        return false;
    }
    
    /**
     * Send order PDF to customer
     */
    public function send_order_pdf($order, $bexio_order_id = null) {
		if (
			apply_filters('bexio_wc_skip_order_email', false) &&
			! apply_filters('bexio_wc_force_send_email', false)
		) {
			return false;
		}
		
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        
        if (!$order) {
            return false;
        }
		
		if (!$bexio_order_id) {
			$bexio_order_id = $order->get_meta('_bexio_order_id', true, 'edit');
		}
        
        $force = apply_filters('bexio_wc_force_send_email', false);

		$was_sent = $order->get_meta('_bexio_order_pdf_sent');
		if ($was_sent && ! $force) {
			$order->add_order_note(__('Duplicate email - order pdf was sent before', 'bexio-wc'));
			return false;
		}

        
        $pdf_path = $this->get_order_pdf($order->get_id(), $bexio_order_id);
        
        if (!$pdf_path || !file_exists($pdf_path)) {
            return false;
        }
		
		$order_date = $order->get_date_created();
        $original_order_id = $order->get_meta('_original_order_id', true);
		// Use _original_order_id for orders before 2026, otherwise use order_id
		$order_reference = $order->get_id();
		if ($order_date && $order_date->format('Y') < 2026) {
			if ($original_order_id) {
				$order_reference = $original_order_id;
				//$order->add_order_note(__('Order pdf Email stopped. Reason- Migrated order', 'bexio-wc'));
				//return false;
			}
		}
		
        $to = $order->get_meta('_shipping_email', true, 'edit');
        //$to = 'masumb911@gmail.com';
        
        //$order_completed_date = $order->get_date_completed();
        $order_completed_date = $order->get_meta('_date_paid');
        
       if ($original_order_id && $order_completed_date) {
          $completed_dt = new WC_DateTime($order_completed_date); 
      if ((int) $completed_dt->format('Y') < 2026) {
          $to = 'example@example.com';
        }
      }
		
		
        $subject = sprintf(__('Your Order Confirmation #%s', 'bexio-wc'), $order_reference);
        
        $message = $this->get_order_email_template($order);
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: RePan <' . get_option('admin_email') . '>'
        );
        
        $attachments = array($pdf_path);
        		
		$mailer = WC()->mailer();
		$sent = $mailer->send(
			$to,
			$subject,
			$message,
			array('Content-Type: text/html; charset=UTF-8'),          
			$attachments
		);
        
        if ($sent) {
            
            $order->add_order_note( __('Order PDF sent to customer', 'bexio-wc') . ' ' . $to );
            $order->update_meta_data('_bexio_order_pdf_sent', current_time('mysql'));
            $order->save();
        }
        
        return $sent;
    }
    
    /**
     * Send invoice PDF to customer
     */
    public function send_invoice_pdf($order, $bexio_invoice_id = null) {
		if (
			apply_filters('bexio_wc_skip_invoice_email', false)  &&
			! apply_filters('bexio_wc_force_send_email', false)
		   ) {
			return false;
		}
		
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        
        if (!$order) {
            return false;
        }
		
		if (!$bexio_order_id) {
			$bexio_order_id = $order->get_meta('_bexio_order_id', true, 'edit');
		}
 	
		$force = apply_filters('bexio_wc_force_send_email', false);
        $was_sent = $order->get_meta('_bexio_invoice_pdf_sent');
        if ($was_sent && ! $force) {
            $order->add_order_note( __('Duplicate email - Invoice pdf was sent before', 'bexio-wc'));
            return false;
        }
        
        $pdf_path = $this->get_invoice_pdf($order->get_id(), $bexio_invoice_id);
        
        if (!$pdf_path || !file_exists($pdf_path)) {
            return false;
        }
		
		$to = $order->get_billing_email();
 	    //$to = 'masumb911@gmail.com';
  	   
		$original_order_id = $order->get_meta('_original_order_id');
		$order_date = $order->get_date_paid() ?: $order->get_date_created();
		// Use _original_order_id for orders before 2026, otherwise use order_id
		$order_reference = $order->get_id();
		if ($order_date && $order_date->format('Y') < 2026) {
			if ($original_order_id) {
				$order_reference = $original_order_id;
				//$order->add_order_note(__('Invoice pdf Email stopped. Reason- Migrated order', 'bexio-wc'));
				//return false;
			}
		}
		
		 $order_completed_date = $order->get_meta('_paid_date');
 		 $hi = $order->get_meta('_completed_date');
   	     $hitt = $order->get_meta('completed_date');
		
 		 $date_paid = $order->get_meta('_date_paid');
  	   
        
       if ($original_order_id && $date_paid) {
          $completed_dt = new WC_DateTime($date_paid);
      if ((int) $completed_dt->format('Y') < 2026) {
          $to = 'example@example.com';
        }else{
          //$to = 'xample@example.com';
        }
      }else{
          //$to = 'ample@example.com';
        }
        
        //$to = 'example@example.com';
        $subject = sprintf(__('RePan – Rechnung #%s', 'bexio-wc'), $order_reference);
        
        $message = $this->get_invoice_email_template($order);
        
        $headers = array(
			'Content-Type: text/html; charset=UTF-8'
		);
        
        $attachments = array($pdf_path);
        		
		$mailer = WC()->mailer();
		$sent = $mailer->send(
			$to,
			$subject,
			$message,
			array('Content-Type: text/html; charset=UTF-8'),          
			$attachments
		);
        
        if ($sent) {
            //$order->add_order_note(__('Invoice PDF sent to customer', 'bexio-wc' . $to));
			//$order->add_order_note( __('Invoice PDF sent to customer', 'bexio-wc') . ' ' . $to );
 		$order->add_order_note(
    __('Invoice PDF sent to customer', 'bexio-wc') .
    ' | to: ' . $to .
    ' | original_order_id: ' . $original_order_id .
    ' | date_paid: ' . $date_paid 
);
            $order->update_meta_data('_bexio_invoice_pdf_sent', current_time('mysql'));
            $order->save();
            
            // Mark as sent in Bexio
            if ($bexio_invoice_id) {
                $this->api->mark_invoice_as_sent($bexio_invoice_id);
            }
        }
        
        return $sent;
    }
    
    /**
     * Email templates
     */
    private function get_order_email_template($order) {
		// Use _original_order_id for orders before 2026, otherwise use order_id
		$order_date = $order->get_date_created();
		$order_reference = $order->get_id();
		if ($order_date && $order_date->format('Y') < 2026) {
			$original_order_id = $order->get_meta('_original_order_id', true);
			if ($original_order_id) {
				$order_reference = $original_order_id;
			}
		}
		
        ob_start();
		wc_get_template(
			'emails/email-header.php',
			array(
				'email_heading' => __('Order PDF', 'bexio-wc'),
			)
		);
		?>
		<p><?php esc_html_e('Thank you for your order.', 'bexio-wc'); ?></p>
		<p>
			<?php
			printf(
				esc_html__('The PDF for order is attached #%s.', 'bexio-wc'),
				esc_html($order_reference)
			);
			?>
		</p>

		<?php
		wc_get_template('emails/email-footer.php');
		return ob_get_clean();
    }
    
    private function get_invoice_email_template($order) {
		// Use _original_order_id for orders before 2026, otherwise use order_id
		$order_date = $order->get_date_created();
		$order_reference = $order->get_id();
		if ($order_date && $order_date->format('Y') < 2026) {
			$original_order_id = $order->get_meta('_original_order_id', true);
			if ($original_order_id) {
				$order_reference = $original_order_id;
			}
		}
		
		ob_start();
		wc_get_template(
			'emails/email-header.php',
			array(
				'email_heading' => __('Rechnung', 'bexio-wc'),
			)
		);
		?>
		<p><?php esc_html_e('Vielen Dank für den Auftrag.', 'bexio-wc'); ?></p>
		<p>
			<?php
			printf(
				esc_html__('Im Anhang befindet sich die Rechnung zur Bestellung #%s.', 'bexio-wc'),
				esc_html($order_reference)
			);
			?>
		</p>

		<?php
		wc_get_template('emails/email-footer.php');
		return ob_get_clean();
	}
    
    /**
     * Download PDF for admin
     */
    public function download_order_pdf($order, $return_path = false) {
        $pdf_path = $this->get_order_pdf($order->get_id());
        
        if ($pdf_path && file_exists($pdf_path)) {
			if ($return_path) {
				return $pdf_path; 
			}
			$this->serve_pdf($pdf_path, 'order-' . $order->get_order_number() . '.pdf');
		}
		return false;
    }
    
    public function download_invoice_pdf($order, $return_path = false) {
		if (!$order instanceof WC_Order) {
			$order = wc_get_order($order);
		}

		$pdf_path = $this->get_invoice_pdf($order->get_id());

		if ($pdf_path && file_exists($pdf_path)) {
			if ($return_path) {
				return $pdf_path;
			}
			$this->serve_pdf($pdf_path, 'invoice-' . $order->get_order_number() . '.pdf');
		}
		return false;
	}
    
    private function serve_pdf($filepath, $filename) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
    
    /**
     * Add meta boxes to order screen
     */
    public function add_pdf_meta_boxes() {
        add_meta_box(
            'bexio_order_pdfs',
            __('Bexio Documents', 'bexio-wc'),
            array($this, 'render_pdf_meta_box'),
            'shop_order',
            'side',
            'default'
        );
        
        // HPOS compatibility
        add_meta_box(
            'bexio_order_pdfs',
            __('Bexio Documents', 'bexio-wc'),
            array($this, 'render_pdf_meta_box'),
            'woocommerce_page_wc-orders',
            'side',
            'default'
        );
    }
    
    public function render_pdf_meta_box($post_or_order) {
		if ($post_or_order instanceof WC_Order) {
			$order = $post_or_order;
			$order_id = $order->get_id();
		} else {
			$order_id = $post_or_order->ID;
			$order = wc_get_order($order_id);
		}

		$bexio_order_id   = $order->get_meta('_bexio_order_id');
		$bexio_invoice_id = $order->get_meta('_bexio_invoice_id');
		$bexio_offer_id = $order->get_meta('_bexio_quote_id');
		?>
		<div class="bexio-pdfs">

			<?php if ($bexio_order_id): ?>
				<p>
					<strong><?php _e('Order PDF:', 'bexio-wc'); ?></strong><br>
					<a href="<?php echo admin_url('admin-ajax.php?action=bexio_download_order_pdf&order_id=' . $order_id . '&nonce=' . wp_create_nonce('bexio_pdf')); ?>"
					   class="button" target="_blank">
						<?php _e('Download Order PDF', 'bexio-wc'); ?>
					</a>
				</p>
			<?php endif; ?>

			<?php if ($bexio_invoice_id): ?>
				<p>
					<strong><?php _e('Invoice PDF:', 'bexio-wc'); ?></strong><br>
					<a href="<?php echo admin_url('admin-ajax.php?action=bexio_download_invoice_pdf&order_id=' . $order_id . '&nonce=' . wp_create_nonce('bexio_pdf')); ?>"
					   class="button" target="_blank">
						<?php _e('Download Invoice PDF', 'bexio-wc'); ?>
					</a>
				</p>
			<?php endif; ?>
			
			<?php do_action( 'bexio_wc_after_pdf_meta_box', $order ); ?>

			<?php if (!$bexio_order_id && !$bexio_invoice_id && !$bexio_offer_id): ?>
				<p><?php _e('No Bexio documents available yet.', 'bexio-wc'); ?></p>
			<?php endif; ?>

			<hr style="margin: 12px 0;">

			<!-- Cancel Bexio Order -->
			<?php if ($bexio_order_id): ?>
			<p>
				<button class="button cancel-bexio-order"
						data-order_id="<?php echo esc_attr($order_id); ?>"
						style="width:100%;">
					🗑 <?php _e('Cancel Bexio Order', 'bexio-wc'); ?>
				</button>
			</p>
			<?php endif; ?>

			<!-- Resync (delete + recreate) -->
			<p>
				<button class="button resync-bexio-order"
						data-order_id="<?php echo esc_attr($order_id); ?>"
						style="width:100%; background:#b32d2e; color:#fff; border-color:#a02020;">
					↻ <?php _e('Delete & Recreate Bexio Order', 'bexio-wc'); ?>
				</button>
			</p>

			<!-- Complete in Bexio without invoice email -->
			<p>
				<button class="button complete-no-invoice-bexio-order"
						data-order_id="<?php echo esc_attr($order_id); ?>"
						style="width:100%; background:#2e7d32; color:#fff; border-color:#1b5e20;">
					✓ <?php _e('Complete in Bexio (No email)', 'bexio-wc'); ?>
				</button>
			</p>
 		
  	
  	        <?php if ($bexio_invoice_id): ?>
<p>
	<button class="button reschedule-bexio-invoice"
			data-order_id="<?php echo esc_attr($order_id); ?>"
			style="width:100%; background:#8e24aa; color:#fff; border-color:#6a1b9a;">
		⏱ <?php _e('Refresh & Reschedule Invoice (+3 days)', 'bexio-wc'); ?>
	</button>
</p>
<?php endif; ?>

		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {

			// --- Cancel ---
			$('.cancel-bexio-order').on('click', function(e) {
				e.preventDefault();
				if (!confirm('Cancel this order in Bexio?')) return;
				var $btn = $(this);
				$btn.prop('disabled', true);
				$.ajax({
					url: ajaxurl, type: 'POST',
					data: {
						action: 'cancel_bexio_order',
						order_id: $btn.data('order_id'),
						security: '<?php echo wp_create_nonce("cancel_bexio_order_nonce"); ?>'
					},
					success: function(r) { alert(r.data.message || 'Done'); },
					complete: function() { $btn.prop('disabled', false); }
				});
			});

			// --- Resync ---
			$('.resync-bexio-order').on('click', function(e) {
				e.preventDefault();
				if (!confirm('This will delete and recreate the Bexio order + invoice without sending any emails. Continue?')) return;
				var $btn = $(this);
				$btn.prop('disabled', true).text('Resyncing…');
				$.ajax({
					url: ajaxurl, type: 'POST',
					data: {
						action: 'resync_bexio_order',
						order_id: $btn.data('order_id'),
						security: '<?php echo wp_create_nonce("resync_bexio_order_nonce"); ?>'
					},
					success: function(r) { alert(r.data.message || 'Done'); },
					error: function() { alert('Request failed. Check error logs.'); },
					complete: function() { $btn.prop('disabled', false).text('↻ Delete & Recreate Bexio Order'); }
				});
			});
 		
  	        $('.reschedule-bexio-invoice').on('click', function(e) {
	e.preventDefault();
	if (!confirm('This will revert the invoice to draft, resync current order data, re-issue it, and schedule the email for 3 days from now. Continue?')) return;
	var $btn = $(this);
	$btn.prop('disabled', true).text('Processing…');
	$.ajax({
		url: ajaxurl, type: 'POST',
		data: {
			action: 'reschedule_bexio_invoice',
			order_id: $btn.data('order_id'),
			security: '<?php echo wp_create_nonce("reschedule_bexio_invoice_nonce"); ?>'
		},
		success: function(r) { alert(r.data.message || 'Done'); },
		error: function() { alert('Request failed. Check error logs.'); },
		complete: function() { $btn.prop('disabled', false).text('⏱ Refresh & Reschedule Invoice (+3 days)'); }
	});
});

			// --- Complete, no invoice email ---
			$('.complete-no-invoice-bexio-order').on('click', function(e) {
				e.preventDefault();
				if (!confirm('This will complete the order in Bexio and create an invoice, but will NOT send any invoice email. Continue?')) return;
				var $btn = $(this);
				$btn.prop('disabled', true).text('Processing…');
				$.ajax({
					url: ajaxurl, type: 'POST',
					data: {
						action: 'complete_no_invoice_bexio_order',
						order_id: $btn.data('order_id'),
						security: '<?php echo wp_create_nonce("complete_no_invoice_bexio_nonce"); ?>'
					},
					success: function(r) { alert(r.data.message || 'Done'); },
					error: function() { alert('Request failed. Check error logs.'); },
					complete: function() { $btn.prop('disabled', false).text('✓ Complete in Bexio (no invoice email)'); }
				});
			});

		});
		</script>
		<?php
	}
    
    /**
     * Add order actions
     */
    public function add_order_actions($actions) {
        $actions['print_bexio_order_pdf'] = __('Print Bexio Order PDF', 'bexio-wc');
		$actions['email_bexio_order_pdf'] = __('Send Email Bexio Order PDF', 'bexio-wc');
        $actions['print_bexio_invoice_pdf'] = __('Print Bexio Invoice PDF', 'bexio-wc');
		$actions['email_bexio_invoice_pdf'] = __('Send Email Bexio Invoice PDF', 'bexio-wc');
        return $actions;
    }
    
    /**
     * Add column to orders list (HPOS compatible)
     */
    public function add_order_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            
            // Add after status column
            if ($key === 'order_status') {
                $new_columns['bexio_docs'] = __('Bexio Docs', 'bexio-wc');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Render order column content (HPOS compatible)
     */
    public function render_order_column($column, $order) {
        if ($column === 'bexio_docs') {
            // Handle both order object (HPOS) and post ID (legacy)
            if ($order instanceof WC_Order) {
                $order_id = $order->get_id();
                $bexio_order_id = $order->get_meta('_bexio_order_id');
                $bexio_invoice_id = $order->get_meta('_bexio_invoice_id');
            } else {
                $order_id = $order;
                $order_obj = wc_get_order($order_id);
                $bexio_order_id = $order_obj ? $order_obj->get_meta('_bexio_order_id') : '';
                $bexio_invoice_id = $order_obj ? $order_obj->get_meta('_bexio_invoice_id') : '';
            }
            
            if ($bexio_order_id) {
                $order_url = admin_url('admin-ajax.php?action=bexio_download_order_pdf&order_id=' . $order_id . '&nonce=' . wp_create_nonce('bexio_pdf'));
                echo '<a href="' . esc_url($order_url) . '" class="bexio-doc-icon" title="' . esc_attr__('Download Order PDF', 'bexio-wc') . '" target="_blank">';
                echo '<span class="dashicons dashicons-media-document"></span>';
                echo '</a>';
            }
            
            if ($bexio_invoice_id) {
                $invoice_url = admin_url('admin-ajax.php?action=bexio_download_invoice_pdf&order_id=' . $order_id . '&nonce=' . wp_create_nonce('bexio_pdf'));
                echo '<a href="' . esc_url($invoice_url) . '" class="bexio-doc-icon" title="' . esc_attr__('Download Invoice PDF', 'bexio-wc') . '" target="_blank">';
                echo '<span class="dashicons dashicons-media-spreadsheet"></span>';
                echo '</a>';
            }
            
            if (!$bexio_order_id && !$bexio_invoice_id) {
                echo '<span style="color: #999;">—</span>';
            }
        }
    }
    
    /**
     * Render order column content for legacy posts
     */
    public function render_order_column_legacy($column, $post_id) {
        $this->render_order_column($column, $post_id);
    }
	
	public function add_bulk_action($bulk_actions) {
		$bulk_actions['download_bexio_orders_pdf'] = 'Bulk Download bexio orders PDF';
		return $bulk_actions;
	}
	
	 public function handle_bulk_action($redirect_to, $action, $post_ids) {
        if ($action !== 'download_bexio_orders_pdf' || empty($post_ids)) {
            return $redirect_to;
        }

        $successful_files = [];
        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];

        foreach ($post_ids as $post_id) {
            $order = wc_get_order($post_id);
            if (!$order) continue;

            $bexio_order_id = $order->get_meta('_bexio_order_id');
            $bexio_invoice_id = $order->get_meta('_bexio_invoice_id');

            if ($bexio_order_id) {
                $pdf_path = $this->download_order_pdf($order, true);
                if ($pdf_path && file_exists($pdf_path)) {
                    $successful_files[] = basename($pdf_path);
                }
            }

            if ($bexio_invoice_id) {
                $pdf_path = $this->download_invoice_pdf($order, true);
                if ($pdf_path && file_exists($pdf_path)) {
                    $successful_files[] = basename($pdf_path);
                }
            }
        }

        if (!empty($successful_files)) {
            set_transient('bexio_bulk_download_' . get_current_user_id(), $successful_files, 300);
        }

        return add_query_arg(['download_bexio_orders_pdf' => 1], $redirect_to);
    }
	
	public function download_pdf_files() {
        if (!isset($_GET['download_bexio_orders_pdf'])) return;

        $files = get_transient('bexio_bulk_download_' . get_current_user_id());
        if (!$files || empty($files)) return;

        $base_url = admin_url('admin.php?page=wc-orders&download_single_pdf=');
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <script>
        let filesToDownload = <?php echo json_encode($files); ?>;
        let currentIndex = 0;

        function downloadNext() {
            if (currentIndex < filesToDownload.length) {
                let iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.src = '<?php echo $base_url; ?>' + encodeURIComponent(filesToDownload[currentIndex]);
                document.body.appendChild(iframe);
                currentIndex++;
                setTimeout(downloadNext, 1000);
            } else {
                fetch('<?php echo $ajax_url; ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=cleanup_bulk_download'
                });
            }
        }

        setTimeout(downloadNext, 500);
        </script>
        <?php
    }
	
	 public function cleanup_bulk_download() {
        delete_transient('bexio_bulk_download_' . get_current_user_id());
        wp_die();
    }
	
	 public function single_pdf_download() {
        if (!isset($_GET['download_single_pdf'])) return;

        $filename = sanitize_file_name($_GET['download_single_pdf']);
        $upload_dir = wp_upload_dir();
        $filepath = $upload_dir['basedir'] . '/bexio-pdfs/' . $filename;

        if (file_exists($filepath)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            unlink($filepath);
            exit;
        }
    }

	/**
	 * Serve ZIP file download
	 */
	public function serve_bulk_zip() {
		if (!empty($_GET['bexio_bulk_zip'])) {
			$upload_dir = wp_upload_dir();
			$file = $upload_dir['basedir'] . '/' . sanitize_file_name($_GET['bexio_bulk_zip']);
			if (file_exists($file)) {
				header('Content-Type: application/zip');
				header('Content-Disposition: attachment; filename="' . basename($file) . '"');
				header('Content-Length: ' . filesize($file));
				readfile($file);
				exit;
			}
		}
	}
 
   /**
 * Add the curated bulk action to the HPOS orders list dropdown.
 */
public function add_curated_bulk_action($bulk_actions) {
    $bulk_actions['bexio_print_curated_pdfs']         = __('Print all bexio Invoices & Orders pdf', 'bexio-wc');
    $bulk_actions['bexio_print_curated_orders_only']  = __('Print all bexio ORDER pdf only', 'bexio-wc');
    $bulk_actions['bexio_print_curated_invoices_only']= __('Print all bexio INVOICE pdf only', 'bexio-wc');
    return $bulk_actions;
}
 
/**
 * Handle the curated bulk action: build the print sequence, stash it
 * for the current admin user, and redirect into the print view.
 */
public function handle_curated_bulk_action($redirect_to, $action, $post_ids) {
    $valid_actions = array(
        'bexio_print_curated_pdfs',
        'bexio_print_curated_orders_only',
        'bexio_print_curated_invoices_only',
    );

    if (!in_array($action, $valid_actions, true) || empty($post_ids)) {
        return $redirect_to;
    }

    switch ($action) {
        case 'bexio_print_curated_orders_only':
            $sequence = $this->build_curated_pdf_sequence($post_ids, 'order');
            break;
        case 'bexio_print_curated_invoices_only':
            $sequence = $this->build_curated_pdf_sequence($post_ids, 'invoice');
            break;
        default:
            $sequence = $this->build_curated_pdf_sequence($post_ids, 'both');
            break;
    }

    if (!empty($sequence)) {
        set_transient('bexio_curated_print_' . get_current_user_id(), $sequence, 5 * MINUTE_IN_SECONDS);
    }

    return add_query_arg(array('bexio_print_curated' => 1), $redirect_to);
}
 
/**
 * Build the ordered list of PDF filenames: grouped by customer
 * (billing email), Invoice-then-Order within each group, Invoice
 * only included when the order's payment method is 'cod'.
 *
 * @return string[] basenames of files under uploads/bexio-pdfs
 */
private function build_curated_pdf_sequence($order_ids, $mode = 'both') {
    $groups = array();

    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            continue;
        }

        $customer_key = $order->get_billing_email();
        if (empty($customer_key)) {
            $customer_key = $order->get_customer_id()
                ? 'uid_' . $order->get_customer_id()
                : 'order_' . $order_id;
        }

        if (!isset($groups[$customer_key])) {
            $groups[$customer_key] = array();
        }
        $groups[$customer_key][] = $order_id;
    }

    $sequence = array();

    foreach ($groups as $customer_order_ids) {
        foreach ($customer_order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            // Invoice PDF: included for 'both' (only on cod, same as before)
            // or always for 'invoice'-only mode.
            $include_invoice = ($mode === 'invoice')
                || ($mode === 'both' && $order->get_payment_method() === 'cod');

            if ($include_invoice) {
                $invoice_path = $this->get_invoice_pdf($order_id);
                if ($invoice_path && file_exists($invoice_path)) {
                    $sequence[] = basename($invoice_path);
                }
            }

            // Order PDF: included for 'both' and 'order'-only, skipped for 'invoice'-only.
            if ($mode !== 'invoice') {
                $order_path = $this->get_order_pdf($order_id);
                if ($order_path && file_exists($order_path)) {
                    $sequence[] = basename($order_path);
                }
            }
        }
    }

    return $sequence;
}
 
/**
 * Intercept the redirect back to the orders screen and render the
 * print view instead: one page, every PDF stacked as an <embed>,
 * auto-triggers window.print() once loaded.
 */
public function render_curated_print_view() {
    if (empty($_GET['bexio_print_curated']) || !isset($_GET['page']) || $_GET['page'] !== 'wc-orders') {
        return;
    }

    if (!current_user_can('edit_shop_orders')) {
        wp_die(__('Insufficient permissions', 'bexio-wc'));
    }

    $files = get_transient('bexio_curated_print_' . get_current_user_id());
    if (!$files || empty($files)) {
        wp_die(__('Nothing to print — no matching PDFs were found for the selected orders.', 'bexio-wc'));
    }

    $stream_nonce = wp_create_nonce('bexio_stream_pdf');
    $ajax_url     = admin_url('admin-ajax.php');

    nocache_headers();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title><?php esc_html_e('Print Documents', 'bexio-wc'); ?></title>
        <style>
            html, body { margin: 0; padding: 0; height: 100%; background: #525659; }
            .pdf-frame { width: 100%; height: 100vh; border: none; display: block; }
            @media print {
                body { background: #fff; }
            }
        </style>
    </head>
    <body>
        <embed
            class="pdf-frame"
            type="application/pdf"
            src="<?php echo esc_url(add_query_arg(array(
                'action'   => 'bexio_stream_merged_curated_pdf',
                '_wpnonce' => $stream_nonce,
            ), $ajax_url)); ?>">
        <script>
            window.addEventListener('load', function () {
                // give the merged PDF a moment to finish rendering before printing
                setTimeout(function () { window.print(); }, 1200);
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}
 
/**
 * Stream a single cached PDF inline (not as a download) for the
 * <embed> tags above. Only serves files that are on the current
 * admin user's active curated-print whitelist.
 */
public function stream_curated_pdf() {
    check_ajax_referer('bexio_stream_pdf', '_wpnonce');
 
    if (!current_user_can('edit_shop_orders')) {
        wp_die('', '', array('response' => 403));
    }
 
    $filename  = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';
    $whitelist = get_transient('bexio_curated_print_' . get_current_user_id());
 
    if (!$filename || !$whitelist || !in_array($filename, $whitelist, true)) {
        wp_die('', '', array('response' => 403));
    }
 
    $filepath = $this->upload_dir . '/' . $filename;
    if (!file_exists($filepath)) {
        wp_die('', '', array('response' => 404));
    }
 
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

/**
 * Force the "Print all bexio Invoices & Orders pdf" bulk action
 * to open in a new tab instead of navigating away from the list.
 */
public function add_curated_print_new_tab_script() {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'woocommerce_page_wc-orders') {
        return;
    }
    ?>
    <script type="text/javascript">
    jQuery(function($) {
        var newTabActions = [
            'bexio_print_curated_pdfs',
            'bexio_print_curated_orders_only',
            'bexio_print_curated_invoices_only',
            'swisspost_print_curated_labels'
        ];

        $(document).on('click', '#doaction, #doaction2', function(e) {
            var $btn = $(this);
            var isTop = $btn.attr('id') === 'doaction';
            var selectedAction = isTop
                ? $('select[name="action"]').val()
                : $('select[name="action2"]').val();

            var $form = $btn.closest('form');

            if (newTabActions.indexOf(selectedAction) !== -1) {
                $form.attr('target', '_blank');
            } else {
                $form.removeAttr('target');
            }
        });
    });
    </script>
    <?php
}

public function stream_merged_curated_pdf() {
    check_ajax_referer('bexio_stream_pdf', '_wpnonce');

    if (!current_user_can('edit_shop_orders')) {
        wp_die('', '', array('response' => 403));
    }

    $files = get_transient('bexio_curated_print_' . get_current_user_id());
    if (!$files || empty($files)) {
        wp_die('', '', array('response' => 404));
    }

    $fpdf_path = get_stylesheet_directory() . '/bexio-pdf-QR/fpdf/fpdf.php';
    $fpdi_path = get_stylesheet_directory() . '/bexio-pdf-QR/fpdi/src/autoload.php';

    if (!class_exists('FPDF')) {
        require_once $fpdf_path;
    }
    if (!class_exists('\setasign\Fpdi\Fpdi')) {
        require_once $fpdi_path;
    }

    $pdf = new \setasign\Fpdi\Fpdi();
    $found_any = false;

    foreach ($files as $filename) {
        $filepath = trailingslashit($this->upload_dir) . $filename;
        if (!file_exists($filepath)) {
            continue;
        }

        $page_count = $pdf->setSourceFile($filepath);
        for ($i = 1; $i <= $page_count; $i++) {
            $template_id = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($template_id);

            $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
            $pdf->AddPage($orientation, array($size['width'], $size['height']));
            $pdf->useTemplate($template_id);
            $found_any = true;
        }
    }

    if (!$found_any) {
        wp_die('No pages found to merge', '', array('response' => 404));
    }

    while (ob_get_level() > 0) { ob_end_clean(); }
    nocache_headers();
    $pdf->Output('documents.pdf', 'I'); // inline — one merged document
    exit;
}


//swiss post bulk

/**
 * ─────────────────────────────────────────────────────────────────
 * Bulk action registration
 * ─────────────────────────────────────────────────────────────────
 */
public function add_swisspost_curated_bulk_action($bulk_actions) {
    $bulk_actions['swisspost_print_curated_labels'] = __('Print all Swiss Post shipping labels', 'bexio-wc');
    return $bulk_actions;
}
 
/**
 * Build the plan (which orders need which delivery methods, grouped
 * by customer) and stash it — mirrors build_curated_pdf_sequence()
 * but the payload is a generation PLAN, not finished filenames,
 * because labels don't exist until generate_barcode is called.
 */
public function handle_swisspost_curated_bulk_action($redirect_to, $action, $post_ids) {
    if ($action !== 'swisspost_print_curated_labels' || empty($post_ids)) {
        return $redirect_to;
    }
 
    $plan = $this->build_swisspost_label_plan($post_ids);
 
    if (!empty($plan)) {
        set_transient('swisspost_curated_print_' . get_current_user_id(), $plan, 5 * MINUTE_IN_SECONDS);
    }
 
    return add_query_arg(array('swisspost_print_curated' => 1), $redirect_to);
}
 
/**
 * Group by customer (same pattern as bexio), and for each order work
 * out which delivery method(s) are actually needed.
 *
 * @return array[] list of ['order_id' => int, 'methods' => string[]]
 *                 in curated (grouped-by-customer) order
 */
private function build_swisspost_label_plan($order_ids) {
    $groups = array();
 
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            continue;
        }
 
        $customer_key = $order->get_billing_email();
        if (empty($customer_key)) {
            $customer_key = $order->get_customer_id()
                ? 'uid_' . $order->get_customer_id()
                : 'order_' . $order_id;
        }
 
        $groups[$customer_key][] = $order_id;
    }
 
    $plan = array();
 
    foreach ($groups as $customer_order_ids) {
        foreach ($customer_order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
 
            $methods = $this->get_swisspost_needed_methods($order);
            if (!empty($methods)) {
                $plan[] = array(
                    'order_id' => $order_id,
                    'methods'  => $methods,
                );
            }
        }
    }
 
    return $plan;
}
 
/**
 * Priority: always, EXCEPT the two pickup shipping methods (18, 19).
 * GAS: only when the shipping method is one of the two designated
 * GAS instance ids (23, 24) — completely independent of the pickup
 * rule above, so an order can get priority only, GAS only, both,
 * or neither.
 */
private function get_swisspost_needed_methods($order) {
    $methods = array();

    $instance_id = 0;
    foreach ($order->get_shipping_methods() as $shipping_item) {
        $instance_id = (int) $shipping_item->get_instance_id();
        break; // assume one shipping line per order
    }

    $is_pickup = in_array($instance_id, $this->pickup_shipping_instance_ids, true);
    if (!$is_pickup) {
        $methods[] = 'pri';
    }

    $is_gas_method = in_array($instance_id, $this->gas_shipping_instance_ids, true);
    if ($is_gas_method) {
        $methods[] = 'gas_eco';
    }

    return $methods;
}
 
 
/**
 * ─────────────────────────────────────────────────────────────────
 * Print view — generates labels via chained AJAX, then prints
 * ─────────────────────────────────────────────────────────────────
 */
public function render_swisspost_curated_print_view() {
    if (empty($_GET['swisspost_print_curated']) || !isset($_GET['page']) || $_GET['page'] !== 'wc-orders') {
        return;
    }
 
    if (!current_user_can('edit_shop_orders')) {
        wp_die(__('Insufficient permissions', 'bexio-wc'));
    }
 
    $plan = get_transient('swisspost_curated_print_' . get_current_user_id());
    if (!$plan) {
        wp_die(__('Nothing to print — no orders needed a label.', 'bexio-wc'));
    }
 
    $swisspost_nonce = wp_create_nonce(self::SWISSPOST_NONCE_ACTION);
    // Our own resolver nonce (see ajax_resolve_swisspost_labels below) —
    // separate from the Swiss Post plugin's nonce, we control both ends.
    $resolve_nonce   = wp_create_nonce('bexio_swisspost_resolve');
    $ajax_url        = admin_url('admin-ajax.php');
 
    nocache_headers();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title><?php esc_html_e('Generating Labels…', 'bexio-wc'); ?></title>
        <style>
            html, body { margin: 0; padding: 0; background: #525659; color: #fff; font-family: sans-serif; }
            #status { padding: 40px; text-align: center; font-size: 16px; }
            .pdf-frame { width: 100%; height: 100vh; border: none; display: block; }
            @media print {
                body { background: #fff; }
                .pdf-frame { page-break-after: always; }
                .pdf-frame:last-child { page-break-after: auto; }
            }
        </style>
    </head>
    <body>
        <script src="<?php echo includes_url('js/jquery/jquery.min.js'); ?>"></script>

        <div id="status"><?php esc_html_e('Generating labels, please wait…', 'bexio-wc'); ?></div>
        <div id="embeds"></div>
        <script>
        (function () {
            var plan          = <?php echo wp_json_encode($plan); ?>; // [{order_id, methods:[...]}, ...] in curated order
            var ajaxUrl       = <?php echo wp_json_encode($ajax_url); ?>;
            var swisspostNonce = <?php echo wp_json_encode($swisspost_nonce); ?>;
            var resolveNonce  = <?php echo wp_json_encode($resolve_nonce); ?>;
            var license       = <?php echo wp_json_encode(self::SWISSPOST_LICENSE); ?>;
 
            var labelFiles = []; // collected in curated (per-customer) order
 
            // Generate every needed method for one order (sequentially —
            // Swiss Post's own endpoint may not like concurrent calls for
            // the same order), then resolve whatever landed on disk for
            // that order_id and move to the next order.
            function generateMethodsForOrder(orderId, methods, mi, done) {
                if (mi >= methods.length) {
                    return resolveOrder(orderId, done);
                }
                jQuery.post(ajaxUrl, {
                    action: 'swisspost_generate_barcode',
                    deliveryMethod: methods[mi],
                    license: license,
                    nonce: swisspostNonce,
                    orders: orderId
                }).always(function () {
                    generateMethodsForOrder(orderId, methods, mi + 1, done);
                });
            }
 
            // Ask our own resolver (folder scan, see
            // ajax_resolve_swisspost_labels in PHP) which files exist for
            // this order_id right now — avoids guessing the generate
            // response shape entirely.
            function resolveOrder(orderId, done) {
                jQuery.post(ajaxUrl, {
                    action: 'bexio_swisspost_resolve_labels',
                    order_id: orderId,
                    nonce: resolveNonce
                }).done(function (response) {
                    var files = (response && response.success && response.data && response.data.files) || [];
                    files.forEach(function (f) { labelFiles.push(f); });
                }).always(done);
            }
 
            function processOrder(i) {
    if (i >= plan.length) {
        return renderEmbeds();
    }
    var row = plan[i];

    // Check first — if labels already exist for this order, use them
    // and skip generation entirely.
    jQuery.post(ajaxUrl, {
        action: 'bexio_swisspost_resolve_labels',
        order_id: row.order_id,
        nonce: resolveNonce
    }).done(function (response) {
        var existing = (response && response.success && response.data && response.data.files) || [];

        if (existing.length > 0) {
            existing.forEach(function (f) { labelFiles.push(f); });
            processOrder(i + 1);
        } else {
            generateMethodsForOrder(row.order_id, row.methods, 0, function () {
                processOrder(i + 1);
            });
        }
    }).fail(function () {
        // Resolver failed — fall back to generating rather than skipping the order.
        generateMethodsForOrder(row.order_id, row.methods, 0, function () {
            processOrder(i + 1);
        });
    });
}
 
            function renderEmbeds() {
    document.getElementById('status').textContent =
        labelFiles.length
            ? 'Loading ' + labelFiles.length + ' label(s)…'
            : 'No label files were found after generation — check the swisspost-labels folder and the resolver.';

    if (!labelFiles.length) {
        return;
    }

    var container = document.getElementById('embeds');
    var embed = document.createElement('embed');
    embed.className = 'pdf-frame';
    embed.type = 'application/pdf';
    embed.src = ajaxUrl + '?action=bexio_swisspost_stream_pdf&labels=' + encodeURIComponent(labelFiles.join(','));
    container.appendChild(embed);

    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 1200);
    });
    setTimeout(function () { window.print(); }, 1500);
}
 
            processOrder(0);
        })();
        </script>
    </body>
    </html>
    <?php
    exit;
}
 
/**
 * Scan the swisspost-labels folder for whatever files exist for a
 * given order_id (naming convention: {order_id}_{label_id}.png,
 * confirmed from your example link). Called by the print view after
 * triggering generate_barcode for that order, so it just reflects
 * whatever is on disk right now — no need to parse
 * swisspost_generate_barcode's own response shape.
 *
 * If an order already had older label files sitting in the folder from
 * a previous, unrelated generation, this will pick those up too, since
 * we're matching on order_id prefix only. That's fine here because we
 * only just generated the labels this order needs, but flag it if you
 * ever want it stricter (e.g. only files modified in the last minute).
 */
public function ajax_resolve_swisspost_labels() {
    check_ajax_referer('bexio_swisspost_resolve', 'nonce');
 
    if (!current_user_can('edit_shop_orders')) {
        wp_send_json_error();
    }
 
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    if (!$order_id) {
        wp_send_json_error();
    }
 

    $upload_dir = wp_upload_dir();
    $labels_dir = $upload_dir['basedir'] . '/swisspost-labels';
 
    $matches = glob($labels_dir . '/' . $order_id . '_*.png');
    $files   = array_map('basename', $matches ?: array());
 
    wp_send_json_success(array('files' => $files));
}
 
 
 public function ajax_stream_swisspost_pdf() {
    if (!current_user_can('edit_shop_orders')) {
        wp_die(__('Insufficient permissions', 'bexio-wc'));
    }

    ini_set('display_errors', '0');
    if (ob_get_level() === 0) {
        ob_start();
    }

    $labelSize   = get_option('swisspost_pdf', 'a5');
    $paperSize   = get_option('swisspost_pdf_paper', $labelSize);
    $orientation = ((int) substr($paperSize, 1, 1) - (int) substr($labelSize, 1, 1)) % 2 === 0 ? 'L' : 'P';

    $labels     = isset($_GET['labels']) ? sanitize_text_field($_GET['labels']) : '';
    $label_list = array_filter(array_map('sanitize_file_name', explode(',', $labels)));

    if (empty($label_list)) {
        wp_die('Missing labels parameter');
    }

    $positions = \FirstPoint\SwissPost\Api\Barcode::calculate_positions($labelSize, $paperSize, $orientation);
    if (empty($positions)) {
        wp_die('Could not calculate label positions');
    }

    $pdf = new \TCPDF($orientation, 'mm', strtoupper($paperSize), true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->AddPage();

    $upload_dir = wp_upload_dir();
    $labels_dir = rtrim($upload_dir['basedir'], '/\\') . '/swisspost-labels/';

    $position = 0;
    $at_least_one_image = false;

    foreach ($label_list as $label) {
        if ($position >= count($positions)) {
            $position = 0;
            $pdf->AddPage();
        }
        $file_path = $labels_dir . $label;
        if (isset($positions[$position]) && file_exists($file_path)) {
            $pdf->Image(
                $file_path,
                $positions[$position]['x'],
                $positions[$position]['y'],
                $positions[$position]['w'],
                $positions[$position]['h']
            );
            $at_least_one_image = true;
        }
        ++$position;
    }

    if (!$at_least_one_image) {
        wp_die('No label image found');
    }

    while (ob_get_level() > 0) { ob_end_clean(); }
    nocache_headers();
    $pdf->Output('label.pdf', 'I'); // 'I' = inline, not 'D' = download
    exit;
}
   
}