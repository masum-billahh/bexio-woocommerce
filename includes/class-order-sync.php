<?php
/**
 * Order Sync Handler
 * Manages synchronization between WooCommerce and Bexio
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bexio_WC_Order_Sync {
    
    private static $instance = null;
    private $api;
	private $processing_orders = array();
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->api = Bexio_WC_API::get_instance();
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Order status change hooks
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);
        
        // Order update hooks
        //add_action('woocommerce_update_order', array($this, 'handle_order_update'), 10, 1);
        
        // Payment complete hook
        add_action('woocommerce_payment_complete', array($this, 'handle_payment_complete'), 10, 1);
		
		add_action('woocommerce_before_trash_order', [$this, 'handle_order_trash_delete'], 10, 2);
		add_action('woocommerce_before_delete_order', [$this, 'handle_order_trash_delete'], 10, 2);
        add_action('wp_ajax_cancel_bexio_order', [$this, 'ajax_cancel_bexio_order_handler']);
		//invoice delay 3 days action schedule handler
		add_action('bexio_wc_send_invoice_pdf_delayed', [ $this, 'handle_delayed_invoice_pdf' ], 10, 2);
		
		//resync button actions
		add_action('wp_ajax_resync_bexio_order', [$this, 'ajax_resync_bexio_order_handler']);
		// complete-no-invoice button
		add_action('wp_ajax_complete_no_invoice_bexio_order', [$this, 'ajax_complete_no_invoice_bexio_handler']);
	
    }
 
    /**
     * Handle order status changes
     */
    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
    if (isset($this->processing_orders[$order_id])) {
        return;
    }

    $this->processing_orders[$order_id] = true;

    try {
        // Check if order should be cancelled in Bexio
        if ($new_status === 'cancelled') {
            $this->cancel_bexio_order($order);
            return;
        }
        
        $has_service_items = $this->order_has_service_items($order);
        $has_mixed_item_pick_home = $this->order_has_mixed_items_pick_home($order, $has_service_items);
        
        // Determine create status based on order items
        if ($has_mixed_item_pick_home) {
            $create_status = 'service-pickhome';
        } elseif ($has_service_items) {
            $create_status = 'service-eingetrof';
        } else {
            $create_status = 'processing';
        }

        $complete_statuses = (array) get_option('bexio_wc_complete_on_status', ['shipped','abholbereit', 'completed']);

        // Check if order should be created in Bexio
        if ($new_status === $create_status) {
            $this->create_bexio_order($order);
        } else {
            $this->handle_order_update($order_id);
        }

        // Check if order should be completed in Bexio
        if (in_array($new_status, $complete_statuses)) {
            $this->complete_bexio_order($order);
        }
    } finally {
        unset($this->processing_orders[$order_id]);
    }
}
    
	/**
	 * Delete order and invoice in bexio when woo order in trash or deleted.
	 */
	public function handle_order_trash_delete($order_id, $order) {
		if (!$order instanceof WC_Order) {
			$order = wc_get_order($order_id);
		}

		if (!$order) {
			return;
		}
		$this->cancel_bexio_order($order);
	}
	
	/**
 * Check if order contains service category items
 */
private function order_has_service_items($order) {
    // Get the service category in the default language (German)
    $service_cat = get_term_by('slug', 'service', 'product_cat');
    
    if (!$service_cat) {
        return false;
    }
    
    // Get all language versions of the service category using WPML
    $service_cat_ids = array($service_cat->term_id);
    
    if (function_exists('icl_object_id')) {
        $languages = apply_filters('wpml_active_languages', null);
        
        foreach ($languages as $lang) {
            $translated_id = apply_filters('wpml_object_id', $service_cat->term_id, 'product_cat', false, $lang['code']);
            if ($translated_id && !in_array($translated_id, $service_cat_ids)) {
                $service_cat_ids[] = $translated_id;
            }
        }
    }
    
    // Get all child categories for each service category
    $all_service_cat_ids = array();
    foreach ($service_cat_ids as $cat_id) {
        $all_service_cat_ids[] = $cat_id;
        $children = get_term_children($cat_id, 'product_cat');
        if (!is_wp_error($children)) {
            $all_service_cat_ids = array_merge($all_service_cat_ids, $children);
        }
    }
    
    // Check if any order item belongs to service categories
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product) {
            $this->log_sync($order->get_id(), 'order_items_loop', 'NoProduct', 'No Product found for this product', $item->get_name());
        }
        
        // Check if item has the _is_service meta which we added in stock as service
        if ($item->get_meta('_is_service') === 'yes') {
            return true;
        }
		
		if ($item->get_meta('_is_service_item') === 'yes') {
            return true;
        }
		
		if ($item->get_meta('_original_sku') === 'Service') {
            return true;
        }
        
        if ( $product && $product->get_id() ) {
             $product_id = $product->get_id();
    
    if ($product->is_type('variation')) {
        $product_id = $product->get_parent_id();
    }
            
			$product_cats = wp_get_post_terms( $product_id, 'product_cat', ['fields' => 'ids'] );

			if ( ! is_wp_error( $product_cats ) && array_intersect( $product_cats, $all_service_cat_ids ) ) {
				return true;
			}
		}
        
        
    }
    
    return false;
}

    /**
 * Check if order has mixed items (both service and non-service items) with pick_home delivery
 */
public function order_has_mixed_items_pick_home($order, $has_service) {
    // If there are no service items, no need to check for mixed items
    if (!$has_service) {
        return false;
    }
    
    // Get the service category in the default language
    $service_cat = get_term_by('slug', 'service', 'product_cat');
    
    if (!$service_cat) {
        return false;
    }
    
    $service_cat_ids = array($service_cat->term_id);
    
    if (function_exists('icl_object_id')) {
        $languages = apply_filters('wpml_active_languages', null);
        
        foreach ($languages as $lang) {
            $translated_id = apply_filters('wpml_object_id', $service_cat->term_id, 'product_cat', false, $lang['code']);
            if ($translated_id && !in_array($translated_id, $service_cat_ids)) {
                $service_cat_ids[] = $translated_id;
            }
        }
    }
    
    // Get all child categories for each service category
    $all_service_cat_ids = array();
    foreach ($service_cat_ids as $cat_id) {
        $all_service_cat_ids[] = $cat_id;
        $children = get_term_children($cat_id, 'product_cat');
        if (!is_wp_error($children)) {
            $all_service_cat_ids = array_merge($all_service_cat_ids, $children);
        }
    }
    
/*
// Debug: Add detailed service category info to order note
$debug_info = array();
foreach ($all_service_cat_ids as $cat_id) {
    $term = get_term($cat_id, 'product_cat');
    if ($term && !is_wp_error($term)) {
        $debug_info[] = sprintf(
            '- %s (slug: %s, ID: %d)',
            $term->name,
            $term->slug,
            $cat_id
        );
    }
}

if (!empty($debug_info)) {
    $note = "Service Categories Found:\n" . implode("\n", $debug_info);
    $order->add_order_note($note, false, true);
}
*/
    
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        
        if (!$product) {
            //$this->log_sync($order->get_id(), 'order_items_loop', 'NoProduct', 'No Product found for this product', $item->get_name());
            continue;
        }
        
        // Check if item is marked as service
        $is_service = false;
        
        if ($item->get_meta('_is_service') === 'yes' || 
            $item->get_meta('_is_service_item') === 'yes' || 
            $item->get_meta('_original_sku') === 'Service') {
            $is_service = true;
        }
        
        // Check if product belongs to service category
        if (!$is_service && $product->get_id()) {
             $product_id = $product->get_id();
    
    if ($product->is_type('variation')) {
        $product_id = $product->get_parent_id();
    }
            
            $product_cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
            if (!is_wp_error($product_cats) && array_intersect($product_cats, $all_service_cat_ids)) {
                $is_service = true;
            }
        }
        
        // If we found a non-service item, the order has mixed items
        if (!$is_service) {
            $order->add_order_note('Mixed order - Non-service item: ' . $item->get_name(), false, true);
            return true;
        }
    }
    
    return false;
}
	
    /**
     * Create order in Bexio
     */
    public function create_bexio_order($order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        
        if (!$order) {
            return false;
        }
		
		$bexio_order_id = (int) $order->get_meta('_bexio_order_id', true);
		
		 // Check if already synced
        if ($bexio_order_id) {
            $this->log_sync($order->get_id(), 'order_create', 'skipped', 'Order already exists in Bexio- Doing update');
            return $this->update_bexio_order($order, $bexio_order_id);
        }
        
        try {
            // Create or get customer contact
            $contact_id = $this->sync_customer($order);
            if (!$contact_id) {
                throw new Exception('Failed to create/get customer contact');
            }
            
            // Prepare order data
            $order_data = $this->prepare_order_data($order, $contact_id, false);
            
            // Create order in Bexio
            $bexio_order = $this->api->create_order($order_data);
            
            if (!$bexio_order || !isset($bexio_order['id'])) {
                throw new Exception('Failed to create order in Bexio');
            }
            
            $bexio_order_id = $bexio_order['id'];
            
            // Save Bexio order ID
			$order->update_meta_data('_bexio_order_id', $bexio_order_id);
			$order->update_meta_data('_bexio_contact_id', $contact_id);
			$order->save();
            
            // Handle PDF generation and email
            $status = $order->get_status(); 
			if (
				get_option('bexio_wc_auto_send_pdfs') === 'yes' &&
				in_array($status, ['service-eingetrof', 'processing'], true)
			) {
				//$this->send_order_pdf($order, $bexio_order_id);
			}
            
            $this->log_sync($order->get_id(), 'order_create', 'success', 'Order created: ' . $bexio_order_id, $bexio_order_id);
			$order->update_meta_data('_bexio_sync_timestamp', current_time('timestamp'));
			$order->save();
            
            return $bexio_order_id;
            
        } catch (Exception $e) {
            $this->log_sync($order->get_id(), 'order_create', 'error', $e->getMessage());
            return false;
        }
    }
    
    /**
     * Complete order and create invoice in Bexio
     */
    public function complete_bexio_order($order, $is_it_from_only_invoice = false, $skip_invoice_email = false) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        
        if (!$order) {
            return false;
        }
		
		// Switch to order's language
		$current_lang = function_exists('icl_object_id') ? apply_filters('wpml_current_language', null) : false;
		$this->switch_to_order_language($order);
        
        $bexio_order_id = $order->get_meta('_bexio_order_id', true);
        
        if (!$bexio_order_id) {
            // Create order first if it doesn't exist
            $bexio_order_id = $this->create_bexio_order($order);
            if (!$bexio_order_id) {
				$this->restore_language($current_lang);
                return false;
            }
        }
        
        try {
			
			// Create delivery from order
			if (!$is_it_from_only_invoice) {
				$bexio_delivery = $this->api->create_delivery($bexio_order_id);
				if (!$bexio_delivery || !isset($bexio_delivery['id'])) {
					throw new Exception('Failed to create delivery in Bexio');
				}
				$bexio_delivery_id = $bexio_delivery['id'];

				$this->api->issue_delivery($bexio_delivery_id);
			}
			
			
            // Create invoice from order
            $order_created_date = $order->get_date_created();
			$order_reference = $order->get_id();
			if ($order_created_date && $order_created_date->format('Y') < 2026) {
				$original_order_id = $order->get_meta('_original_order_id', true);
				if ($original_order_id) {
					$order_reference = $original_order_id;
				}
			}

			$bexio_invoice = $this->api->create_invoice($bexio_order_id, [
				'document_nr' => $order_reference,
			]);
            
            if (!$bexio_invoice || !isset($bexio_invoice['id'])) {
                throw new Exception('Failed to create invoice in Bexio');
            }
            
            $bexio_invoice_id = $bexio_invoice['id'];
            
			$this->edit_invoice($bexio_invoice_id, $order);
			
            // Issue the invoice (marks as "to be paid")
            $this->api->issue_invoice($bexio_invoice_id);
            
            //Save invoice & delivery ID
            $order->update_meta_data('_bexio_delivery_id', $bexio_invoice_id);
			$order->update_meta_data('_bexio_invoice_id', $bexio_invoice_id);
			$order->save();
			
			$this->sync_bexio_positions($order, $bexio_order_id, $bexio_invoice_id);
            
            // Handle payment if already paid
            if ($order->is_paid()) {
                $this->sync_payment($order, $bexio_invoice_id);
            }
            
            //OLD- Send invoice PDF to customer
            //$this->send_invoice_pdf($order, $bexio_invoice_id);

			//NEW- Invoice Delayed 3 days
			if ( $skip_invoice_email ) {
				$this->log_sync($order->get_id(), 'Scheduled_invoice', 'skipped', 'Invoice email skipped — triggered via no-email button', null, $bexio_invoice_id);
			} elseif ( $order->get_payment_method() == 'cod' ) {
				as_schedule_single_action(
					strtotime('+3 days'),
					'bexio_wc_send_invoice_pdf_delayed',
					[ 'order_id' => $order->get_id(), 'bexio_invoice_id' => $bexio_invoice_id ],
					'bexio-wc'
				);
				$this->log_sync($order->get_id(), 'Scheduled_invoice', 'success', 'Invoice Email Scheduled after 3 days: ' . $bexio_invoice_id, null, $bexio_invoice_id);
			} else {
				$this->log_sync($order->get_id(), 'Scheduled_invoice', 'skipped', 'PAID order — invoice email not scheduled', null, $bexio_invoice_id);
			}

            $this->log_sync($order->get_id(), 'invoice_create', 'success', 'Invoice created: ' . $bexio_invoice_id, null, $bexio_invoice_id);
            $this->log_sync($order->get_id(), 'delivery_create', 'success', 'Delivery created: ' . $bexio_delivery_id, null, $bexio_delivery_id);
			
			$this->restore_language($current_lang);
			
            return $bexio_invoice_id;
            
        } catch (Exception $e) {
            $this->log_sync($order->get_id(), 'invoice_create', 'error', $e->getMessage());
			// Restore original language
        	$this->restore_language($current_lang);
            return false;
        }
    }
	
	public function handle_delayed_invoice_pdf( $order_id, $bexio_invoice_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		// Don't send if order was cancelled/refunded after scheduling
		if ( in_array( $order->get_status(), [ 'cancelled', 'refunded', 'failed' ] ) ) {
			return;
		}

		$this->send_invoice_pdf( $order, $bexio_invoice_id );
	}
	
	/**
	 * Cancel bexio order
	 */
	public function cancel_bexio_order($order) {
		if (!$order instanceof WC_Order) {
			$order = wc_get_order($order);
		}
		if (!$order) {
			return false;
		}

		$bexio_order_id   = (int) $order->get_meta('_bexio_order_id', true);
		$bexio_invoice_id = (int) $order->get_meta('_bexio_invoice_id', true);
		$bexio_payment_id = (int) $order->get_meta('_bexio_payment_id', true);
		$bexio_delivery_id = (int) $order->get_meta('_bexio_delivery_id', true);

		if ($bexio_invoice_id > 0 && $bexio_payment_id > 0) {
			$this->api->delete_payment($bexio_invoice_id, $bexio_payment_id);
			$order->delete_meta_data('_bexio_payment_id');
			$order->delete_meta_data('_bexio_payment_synced');
			$order->save();
			//$this->log_sync($order->get_id(), 'bexio_delete', 'delete', 'Payment deleted in Bexio');
		}

		if ($bexio_invoice_id > 0) {
			$this->api->issue_to_draft_invoice($bexio_invoice_id);
			//$this->api->cancel_invoice($bexio_invoice_id);
			$this->api->delete_invoice($bexio_invoice_id);
			//$this->log_sync($order->get_id(), 'bexio_delete', 'delete', 'Invoice canceled and deleted in Bexio');
		}

		if ($bexio_order_id > 0) {
			//$this->api->cancel_order($bexio_order_id);
			$this->api->delete_order($bexio_order_id);
			$this->log_sync($order->get_id(), 'bexio_delete', 'delete', 'Order & Invoice deleted in Bexio');
		}

		if ($bexio_order_id > 0 || $bexio_invoice_id > 0) {
			$order->delete_meta_data('_bexio_order_id');
			$order->delete_meta_data('_bexio_invoice_id');
			$order->delete_meta_data('_bexio_delivery_id');
			$order->delete_meta_data('_bexio_contact_id');
			$order->delete_meta_data('_bexio_sync_timestamp');
			$order->delete_meta_data('_bexio_order_pdf');
			$order->delete_meta_data('_bexio_invoice_pdf');
			$order->delete_meta_data('_bexio_order_pdf_sent');
			$order->delete_meta_data('_bexio_invoice_pdf_sent');
			$order->save();
		}

		return true;
	}
    
	 private function edit_invoice($bexio_invoice_id, $order) {
		 $date_completed = $order->get_date_paid() ?: $order->get_date_completed();
		 
		 $order_created_date = $order->get_date_created();
		// Use _original_order_id for orders before 2026, otherwise use order_id
		$order_reference = $order->get_id();
		if ($order_created_date && $order_created_date->format('Y') < 2026) {
			$original_order_id = $order->get_meta('_original_order_id', true);
			if ($original_order_id) {
				$order_reference = $original_order_id;
			}
		}
		 
		 // If both are empty, use current time
		if (!$date_completed) {
			$date_completed = wc_string_to_datetime('now');
		}
		 
		 $is_valid_from  = $date_completed->date('Y-m-d');
		 $is_valid_to  = date('Y-m-d', strtotime($is_valid_from . ' +1 month'));
		 $data = array(
			'document_nr' => $order_reference,
			'is_valid_from' => $is_valid_from,
			'is_valid_to' => $is_valid_to,
			'api_reference' => 'wc_order_' . $order->get_id(),
		);
		 
		  return $this->api->edit_invoice($bexio_invoice_id, $data);
	 }
	
    /**
     * Sync customer to Bexio
     */
    private function sync_customer($order) {
        $customer_sync = Bexio_WC_Customer_Sync::get_instance();
        return $customer_sync->sync_customer($order);
    }
    
    /**
     * Prepare order data for Bexio
     */
    private function prepare_order_data($order, $contact_id, $is_it_update = false) {		
				
		$order_created_date = $order->get_date_created();
		// Use _original_order_id for orders before 2026, otherwise use order_id
		$order_reference = $order->get_id();
		if ($order_created_date && $order_created_date->format('Y') < 2026) {
			$original_order_id = $order->get_meta('_original_order_id', true);
			if ($original_order_id) {
				$order_reference = $original_order_id;
			}
		}
		
		$data = array(
			'document_nr'      => $order_reference,
			'contact_id'       => $contact_id,
			'user_id'          => 1,
			'language_id'      => $this->get_language_id($order),
			'bank_account_id'  => $this->get_bank_account_id($order->get_payment_method()),
			'currency_id'      => $this->get_currency_id($order->get_currency()),
			'mwst_type'        => 0, // default
 		    'mwst_is_net'      => false,
		);
		
		$billing  = $order->get_address('billing');
		$shipping = $order->get_address('shipping');
		$fields_to_check = ['first_name','last_name','address_1','address_2','city','state','postcode','country'];

		$is_different_address = false;
		foreach ($fields_to_check as $field) {
			if ( ( $billing[$field] ?? '' ) !== ( $shipping[$field] ?? '' ) ) {
				$is_different_address = true;
				break;
			}
		}
		
		if ($is_different_address) {
			$lines = [];
			if (!empty($billing['company'])) {
				$lines[] = $billing['company'];
			}

			$full_name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
			if ($full_name) {
				$lines[] = $full_name;
			}

			if (!empty($billing['address_1'])) {
				$lines[] = $billing['address_1'];
			}

			if (!empty($billing['address_2'])) {
				$lines[] = $billing['address_2'];
			}

			$postcode_city = trim(($billing['postcode'] ?? '') . ' ' . ($billing['city'] ?? ''));
			if ($postcode_city) {
				$lines[] = $postcode_city;
			}

			if (!empty($billing['state'])) {
				$lines[] = $billing['state'];
			}

			$billing_address_string = implode("\n", $lines);
			$data['contact_address_manual'] = $billing_address_string;
		}

        
        $order_date = $order->get_meta('_date_paid');
        
        $status     = $order->get_status();
        $has_orig   = $order->get_meta('_original_order_id');
        
        if ($has_orig && $order_date) {
            if (is_string($order_date)) {
                $order_date = new WC_DateTime($order_date);
            }

        if ((int) $order_date->format('Y') < 2026) {
            $data['mwst_type'] = 2;
            unset($data['mwst_is_net']);
        }
      }
		
 	    
		$date_format = $order_created_date->format('Y-m-d');
		$data['is_valid_from'] = $date_format;

		$data['title']         = sprintf(__('Order #%s', 'bexio-wc'), $order_reference);
		$data['header']        = $this->get_order_header($order);
		$data['footer']        = $this->get_order_footer($order);
		$data['api_reference'] = 'wc_order_' . $order_reference;

		if (!$is_it_update) {
			$data['positions'] = $this->prepare_order_positions($order);
		}

		return $data;
	}

    
    /**
     * Prepare positions array for order creation
     */
   private function prepare_order_positions($order) {
        $positions = array();
        $order_date = $order->get_date_paid();
	    $current_lang = function_exists('icl_object_id') ? apply_filters('wpml_current_language', null) : false;
    	$this->switch_to_order_language($order);
		
        // Add products
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $account_id = get_option('bexio_wc_product_account', '149');
            $tax_id = $this->get_tax_id();
			
			$unit_price = ($item->get_total() + $item->get_total_tax()) / $item->get_quantity();

			$text = ucfirst($item->get_name());
			/*
			if ( $item->get_meta('_is_service') === 'yes' ) {
				$oem_price = get_post_meta($product->get_id(), '_oem_price_field', true);
				if ( $oem_price ) {
					$oem_price_numeric = wc_format_decimal((float) $oem_price, wc_get_price_decimals());
					$text = $text . ('Original price<del>' . wc_price($oem_price_numeric) . '</del> ');
				} else {
					$text = $item->get_name();
				}
			} else {
				$text = $text;
			}
			*/
            
            $positions[] = array(
                'type' => 'KbPositionCustom',
                'amount' => $item->get_quantity(),
                'unit_id' => 1, // Pieces
                'account_id' => 149,
                'tax_id' => 28,
                'text' => $text,
                'unit_price' => round(
    ($item->get_total() + $item->get_total_tax()) / $item->get_quantity(),
    2
),
                'discount_in_percent' => 0,
            );
			if ($order_date && $order_date->format('Y') < 2026) {
				$positions[count($positions)-1]['tax_id'] = 28;
			}
			
			// working for original price in the invoice
			$oem_price = get_post_meta($product->get_id(), '_oem_price_field', true);
			if (is_numeric($oem_price) && (float) $oem_price > 0) {
				$oem_price_numeric = wc_format_decimal((float) $oem_price, wc_get_price_decimals());
				$oem_text = sprintf(
								__('Originalpreis <del>%s</del> ', 'bexio-wc'),
								wc_price($oem_price_numeric)
							);
				
				$positions[] = array(
					'type' => 'KbPositionText',
					'text' => $oem_text,
            	);
			}
			
        }
        
        // Add shipping
        $shipping_items = $order->get_items('shipping');
    	if (!empty($shipping_items)) {
            $account_id = get_option('bexio_wc_shipping_account', '151');
            $tax_id = $this->get_tax_id();
			
			 foreach ($shipping_items as $shipping_item) {
				$shipping_method_title = $shipping_item->get_name();
			}
			
			$shipping_total = $order->get_shipping_total();
			$shipping_tax = $order->get_shipping_tax();

			if ($order_date && $order_date->format('Y') < 2026) {
				$unit_price = round($shipping_total + $shipping_tax, 2);
			} else {
				$unit_price = round($shipping_total + $shipping_tax, 2);
			}
            
            $positions[] = array(
                'type' => 'KbPositionCustom',
                'amount' => 1,
                'unit_id' => 1,
                'account_id' => 151,
                'tax_id' => 28,
                'text' => $shipping_method_title,
                'unit_price' => $unit_price,
                'discount_in_percent' => 0,
            );
			
			if ($order_date && $order_date->format('Y') < 2026) {
				$positions[count($positions)-1]['tax_id'] = 28;
			}
        }
	   
	   //fee items
	    $fee_items = $order->get_items('fee');
		foreach ( $fee_items as $fee ) {
			$fee_total = (float) $fee->get_total();
			$fee_tax   = (float) $fee->get_total_tax();

			// Normal fee (positive)
			if ( $fee_total > 0 ) {
				$positions[] = array(
					'type'        => 'KbPositionCustom',
					'amount'      => 1,
					'unit_id'     => 1,
					'account_id'  => 149, 
					'tax_id'      => 28,
					'text'        => $fee->get_name(),
					'unit_price'  => round($fee_total + $fee_tax, 2),
					'discount_in_percent' => 0,
				);
			}	
			// Discount fee (negative)
			elseif ( $fee_total < 0 ) {
				$positions[] = array(
					'type'          => 'KbPositionDiscount',
					'text'          => $fee->get_name(),
					'is_percentual' => false,
					'value'         => (string) abs( $fee_total + $fee_tax ),
				);
			}
		}

	   
	   // Add discount (coupon) positions
		$discount_items = $order->get_items('coupon');
		foreach ( $discount_items as $discount ) {
			$discount_amount = (float) $item->get_discount();

			if ( $discount_amount <= 0 ) {
				continue;
			}

			$positions[] = array(
				'type'          => 'KbPositionDiscount',
				'text'          => $item->get_name(), 
				'is_percentual' => false,
				'value'         => (string) $discount_amount,
			);
		}

	   
        $this->restore_language($current_lang);
	   
        return $positions;
    }
	
	private function prepare_order_positions_when_updating($order) {
    $positions = array();
	$order_date = $order->get_date_completed();
	$current_lang = function_exists('icl_object_id') ? apply_filters('wpml_current_language', null) : false;
    $this->switch_to_order_language($order);
    
    // Add products
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $account_id = get_option('bexio_wc_product_account', '149');
        $tax_id = $this->get_tax_id();
        
        $unit_price = round( ($item->get_total() + $item->get_total_tax()) / $item->get_quantity(),2
);

        $text = ucfirst($item->get_name());
       
        $positions[] = array(
            'amount' => $item->get_quantity(),
            'unit_id' => 1, // Pieces
            'account_id' => 149,
            'tax_id' => 28,
            'text' => $text,
            'unit_price' => $unit_price,

            'discount_in_percent' => 0,
        );
		
		if ($order_date && $order_date->format('Y') < 2026) {
				$positions[count($positions)-1]['tax_id'] = 28;
			}
		
			// Working for original price in the invoice
			$oem_price = get_post_meta($product->get_id(), '_oem_price_field', true);
			if (is_numeric($oem_price) && (float) $oem_price > 0) {
				$oem_price_numeric = wc_format_decimal((float) $oem_price, wc_get_price_decimals());
				$oem_text = sprintf(
								__('Originalpreis <del>%s</del> ', 'bexio-wc'),
								wc_price($oem_price_numeric)
							);
				
				$positions[] = array(
					'type' => 'KbPositionText',
					'text' => $oem_text,
            	);	
				
			}
		
    }
    
    // Add shipping
    $shipping_items = $order->get_items('shipping');
    if (!empty($shipping_items)) {
        $account_id = get_option('bexio_wc_shipping_account', '151');
        $tax_id = $this->get_tax_id();
        
        $shipping_method_title = '';
        
       foreach ($shipping_items as $shipping_item) {
            $shipping_method_title = $shipping_item->get_name();
            
            if (function_exists('icl_object_id')) {
                $shipping_method_id = $shipping_item->get_method_id();
                $instance_id = $shipping_item->get_instance_id();
                
                $current_lang = apply_filters('wpml_current_language', null);
                $order_lang = $order->get_meta('wpml_language', true);
                
                if ($order_lang && $order_lang !== $current_lang) {
                    do_action('wpml_switch_language', $order_lang);
                    
                    // Get shipping zones
                    $shipping_zones = WC_Shipping_Zones::get_zones();
                    
                    foreach ($shipping_zones as $zone) {
                        foreach ($zone['shipping_methods'] as $method) {
                            if ($method->id === $shipping_method_id && $method->instance_id == $instance_id) {
                                $shipping_method_title = $method->title;
                                break 2;
                            }
                        }
                    }
                    
                    // Switch back to current language
                    do_action('wpml_switch_language', $current_lang);
                }
            }
            
            break; 
        }
        
        if (empty($shipping_method_title)) {
            $shipping_method_title = __('Shipping', 'bexio-wc');
        }
		
		$shipping_total = $order->get_shipping_total();
		$shipping_tax = $order->get_shipping_tax();

		if ($order_date && $order_date->format('Y') < 2026) {
			$unit_price = round($shipping_total + $shipping_tax, 2);
		} else {
			$unit_price = round($shipping_total + $shipping_tax, 2);
		}
        
        $positions[] = array(
            'amount' => 1,
            'unit_id' => 1,
            'account_id' => 151,
            'tax_id' => 28,
            'text' => $shipping_method_title,
            'unit_price' => $unit_price,
            'discount_in_percent' => 0,
        );
		
		if ($order_date && $order_date->format('Y') < 2026) {
				$positions[count($positions)-1]['tax_id'] = 28;
			}
    }
		
		 //fee items
	    $fee_items = $order->get_items('fee');
		foreach ( $fee_items as $fee ) {
			$fee_total = (float) $fee->get_total();
			$fee_tax   = (float) $fee->get_total_tax();

			// Normal fee (positive)
			if ( $fee_total > 0 ) {
				$positions[] = array(
					'type'        => 'KbPositionCustom',
					'amount'      => 1,
					'unit_id'     => 1,
					'account_id'  => 149, 
					'tax_id'      => 28,
					'text'        => $fee->get_name(),
					'unit_price'  => round($fee_total + $fee_tax, 2),
					'discount_in_percent' => 0,
				);
			}	
			// Discount fee (negative)
			elseif ( $fee_total < 0 ) {
				$positions[] = array(
					'type'          => 'KbPositionDiscount',
					'text'          => $fee->get_name(),
					'is_percentual' => false,
					'value'         => (string) abs( $fee_total + $fee_tax ),
				);
			}
		}
		
		// Add discount (coupon) positions
		$discount_items = $order->get_items('coupon');
		foreach ( $discount_items as $discount ) {
			$discount_amount = (float) $item->get_discount();

			if ( $discount_amount <= 0 ) {
				continue;
			}

			$positions[] = array(
				'type'          => 'KbPositionDiscount',
				'text'          => $item->get_name(), 
				'is_percentual' => false,
				'value'         => (string) $discount_amount,
			);
		}
    
    return $positions;
}
    
    /**
     * Handle order updates
     */
    public function handle_order_update($order_id) {
		$order = wc_get_order($order_id);
		if (!$order) {
			return;
		}

		$bexio_order_id = $order->get_meta('_bexio_order_id', true);

		if (!$bexio_order_id) {
			return;
		}

		$last_sync_time = $order->get_meta('_bexio_sync_timestamp', true);

		// If synced less than 1 minutes ago, skip
		if (!empty($last_sync_time) && (current_time('timestamp') - $last_sync_time) < 60) {
			return; 
		}

		$should_update = $this->should_sync_order_update($order);
		if (!$should_update) {
			return;
		}

		$this->processing_orders[$order_id] = true;

		try {
			$this->update_bexio_order($order, $bexio_order_id);
		} finally {
			unset($this->processing_orders[$order_id]);
		}
	}
	
	/**
	 * Determine if order update should sync to Bexio
	 */
	private function should_sync_order_update($order) {
		$status = $order->get_status();
		if (in_array($status, ['draft', 'pending', 'auto-draft'])) {
			return false;
		}

		// Check if this update is triggered by our own sync
		if (doing_action('bexio_sync_order')) {
			return false;
		}

		return true;
	}

    

    /**
 	* Update existing Bexio order
 	*/
private function update_bexio_order($order, $bexio_order_id) {
    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order);
    }

    if (!$order) {
        return false;
    }

    try {
        $contact_id = $this->sync_customer($order);
        if (!$contact_id) {
            throw new Exception('Failed to get/update customer contact');
        }

        $order_data = $this->prepare_order_data($order, $contact_id, true);

        $updated_order = $this->api->update_order($bexio_order_id, $order_data);

        if (!$updated_order) {
            throw new Exception('Failed to update order in Bexio');
        }

		$order->update_meta_data('_bexio_contact_id', $contact_id);
		$order->save();

        // Sync positions for order and invoice (if exists)
		$bexio_invoice_id = $order->get_meta('_bexio_invoice_id', true);
        $this->sync_bexio_positions($order, $bexio_order_id, $bexio_invoice_id);
		
		//$this->send_order_pdf($order, $bexio_order_id);

        $this->log_sync($order->get_id(), 'order_update', 'success', 'Order updated: ' . $bexio_order_id, $bexio_order_id);			$order->update_meta_data('_bexio_sync_timestamp', current_time('timestamp'));
		$order->save();

        return $bexio_order_id;

    } catch (Exception $e) {
        $this->log_sync($order->get_id(), 'order_update', 'error', $e->getMessage());
        return false;
    }
}
	
	/**
 * Sync positions for order and invoice
 */
private function sync_bexio_positions($order, $bexio_order_id, $bexio_invoice_id = null) {
    try {
        // Sync order positions
        $this->sync_positions_for_document($order, 'kb_order', $bexio_order_id);
        
        // Sync invoice positions if invoice exists
        if ($bexio_invoice_id) {
            $this->sync_positions_for_document($order, 'kb_invoice', $bexio_invoice_id);
        }
        
        return true;
        
    } catch (Exception $e) {
        $this->log_sync($order->get_id(), 'position_sync', 'error', $e->getMessage());
        return false;
    }
}
	
	/**
 * Strip HTML tags and normalize text for comparison
 */
private function normalize_text_for_comparison($text) {
    // Remove HTML tags
    $text = strip_tags($text);
    // Remove extra whitespace
    $text = preg_replace('/\s+/', ' ', $text);
    // Trim
    $text = trim($text);
    return $text;
}

/**
 * Sync positions for a specific document (order or invoice)
 */
private function sync_positions_for_document($order, $kb_document_type, $document_id) {
    // Get current WooCommerce order items (without type field for updates)
    $wc_positions = $this->prepare_order_positions_when_updating($order);
    
    // Get existing positions from Bexio
    $bexio_positions = $this->api->get_document_positions($kb_document_type, $document_id);
    
    if (!$bexio_positions) {
        $bexio_positions = array();
    }
    
    // Separate positions by type (product: account_id 149, shipping: account_id 151)
    $bexio_products = array();
    $bexio_shipping = null;
    
    foreach ($bexio_positions as $position) {
        if ($position['account_id'] == 149) {
            // Product position - normalize text and use as key
            $normalized_text = $this->normalize_text_for_comparison($position['text']);
            $bexio_products[$normalized_text] = $position;
        } elseif ($position['account_id'] == 151) {
            // Shipping position
            $bexio_shipping = $position;
        }
    }
    
    // Separate WooCommerce positions
    $wc_products = array();
    $wc_shipping = null;
    
    foreach ($wc_positions as $wc_position) {
        if ($wc_position['account_id'] == 149) {
            $normalized_text = $this->normalize_text_for_comparison($wc_position['text']);
            $wc_products[$normalized_text] = $wc_position;
        } elseif ($wc_position['account_id'] == 151) {
            $wc_shipping = $wc_position;
        }
    }
    
    // Track processed product names
    $processed_products = array();
    
    // Process product positions
    foreach ($wc_products as $normalized_name => $wc_position) {
        $processed_products[] = $normalized_name;
        
        if (isset($bexio_products[$normalized_name])) {
            // Product exists - update if needed
            $existing_position = $bexio_products[$normalized_name];
            
            if ($this->position_needs_update($existing_position, $wc_position)) {
                $this->api->update_document_position(
                    $kb_document_type, 
                    $document_id, 
                    $existing_position['id'], 
                    $wc_position
                );
                /*
                $this->log_sync(
                    $order->get_id(), 
                    'position_update', 
                    'success', 
                    "Updated {$kb_document_type} product position: {$wc_position['text']}"
                );
				*/
            }
        } else {
            // Product doesn't exist - create it
            $this->api->create_document_position($kb_document_type, $document_id, $wc_position);
            
            $this->log_sync(
                $order->get_id(), 
                'position_create', 
                'success', 
                "Created {$kb_document_type} product position: {$wc_position['text']}"
            );
        }
    }
    
    // Delete product positions that no longer exist in WooCommerce
    foreach ($bexio_products as $normalized_name => $bexio_position) {
        if (!in_array($normalized_name, $processed_products)) {
            $this->api->delete_document_position($kb_document_type, $document_id, $bexio_position['id']);
            
            $this->log_sync(
                $order->get_id(), 
                'position_delete', 
                'success', 
                "Deleted {$kb_document_type} product position: " . $this->normalize_text_for_comparison($bexio_position['text'])
            );
        }
    }
    
    // Process shipping position
    if ($wc_shipping) {
        if ($bexio_shipping) {
            // Shipping exists - update if needed
            if ($this->position_needs_update($bexio_shipping, $wc_shipping)) {
                $this->api->update_document_position(
                    $kb_document_type, 
                    $document_id, 
                    $bexio_shipping['id'], 
                    $wc_shipping
                );
                /*
                $this->log_sync(
                    $order->get_id(), 
                    'position_update', 
                    'success', 
                    "Updated {$kb_document_type} shipping position: {$wc_shipping['text']}"
                );
				*/
            }
        } else {
            // Shipping doesn't exist - create it
            $this->api->create_document_position($kb_document_type, $document_id, $wc_shipping);
            
            $this->log_sync(
                $order->get_id(), 
                'position_create', 
                'success', 
                "Created {$kb_document_type} shipping position: {$wc_shipping['text']}"
            );
        }
    } else {
        // No shipping in WooCommerce but exists in Bexio - delete it
        if ($bexio_shipping) {
            $this->api->delete_document_position($kb_document_type, $document_id, $bexio_shipping['id']);
            
            $this->log_sync(
                $order->get_id(), 
                'position_delete', 
                'success', 
                "Deleted {$kb_document_type} shipping position"
            );
        }
    }
}

/**
 * Check if position needs updating
 */
private function position_needs_update($existing, $new) {
    $fields_to_check = array('amount', 'unit_price', 'discount_in_percent', 'text');
    
    foreach ($fields_to_check as $field) {
        if (isset($existing[$field]) && isset($new[$field])) {
            if ($field === 'amount' || $field === 'unit_price' || $field === 'discount_in_percent') {
                // Compare as floats to avoid precision issues
                if (abs(floatval($existing[$field]) - floatval($new[$field])) > 0.01) {
                    return true;
                }
            } else {
                // String comparison
                if ($existing[$field] !== $new[$field]) {
                    return true;
                }
            }
        }
    }
    
    return false;
}
    
    /**
     * Handle payment completion
     */
    public function handle_payment_complete($order_id) {
        $order = wc_get_order($order_id);
		$bexio_invoice_id = $order->get_meta('_bexio_invoice_id', true);
        
        if ($bexio_invoice_id) {
            $this->sync_payment($order, $bexio_invoice_id);
        }
    }
    
    /**
     * Sync payment to Bexio
     */
    private function sync_payment($order, $bexio_invoice_id) {
        $payment_mapper = Bexio_WC_Payment_Mapper::get_instance();
        return $payment_mapper->sync_payment($order, $bexio_invoice_id);
    }
    
    /**
     * Helper functions
     */
    private function get_order_header($order) {
        //$header = 'Order #' . $order->get_order_number();
        
        // Add customer notes if available
        $customer_note = $order->get_customer_note();
        if ($customer_note) {
			$header .= "\n\n" . __('Customer Notes:', 'bexio-wc') . "\n" . $customer_note;
		}
        
        return $header;
    }
    
    private function get_order_footer($order) {
        return '';
    }
    
    private function get_language_id($order) {
        // Get order language
		$locale = $order->get_meta('wpml_language', true);
        if (!$locale) {
            $locale = substr(get_locale(), 0, 2);
        }
        
        $language_map = array(
            'de' => 1,
            'fr' => 2,
            'it' => 3,
            'en' => 4
        );
        
        return isset($language_map[$locale]) ? $language_map[$locale] : 1;
    }
	
	private function switch_to_order_language($order) {
		if (function_exists('icl_object_id')) {
			$order_lang = $order->get_meta('wpml_language', true);
			if ($order_lang) {
				do_action('wpml_switch_language', $order_lang);
				return $order_lang;
			}
		}
		return false;
	}

	private function restore_language($original_lang) {
		if ($original_lang && function_exists('icl_object_id')) {
			do_action('wpml_switch_language', $original_lang);
		}
	}
    
    private function get_bank_account_id($payment_method) {
        // Map payment methods to bank accounts
        $card_methods = array('stripe', 'paypal', 'square');
        
        if (in_array($payment_method, $card_methods)) {
            return get_option('bexio_wc_card_bank_id', 1);
        }
        
        //return get_option('bexio_wc_invoice_bank_id', 1);
		return 1;
    }
    
    private function get_currency_id($currency_code) {
        // Common currency IDs
        $currency_map = array(
            'CHF' => 1,
            'EUR' => 2,
            'USD' => 3
        );
        
        return isset($currency_map[$currency_code]) ? $currency_map[$currency_code] : 1;
    }
    
    private function get_tax_id() {
        // Get VAT ID from settings (UN81 - 8.1%)
        return get_option('bexio_wc_tax_id', 1);
    }
    
    /**
     * Send order PDF
     */
    private function send_order_pdf($order, $bexio_order_id) {
        $pdf_handler = Bexio_WC_PDF_Handler::get_instance();
        $pdf_handler->send_order_pdf($order, $bexio_order_id);
    }
    


public function ajax_cancel_bexio_order_handler() {
    check_ajax_referer('cancel_bexio_order_nonce', 'security');

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    if (!$order_id) {
        wp_send_json_error(['message' => 'Invalid order ID']);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Order not found']);
    }

    // Call existing cancel function
    $this->cancel_bexio_order($order);

    wp_send_json_success(['message' => 'Bexio order cancelled']);
}

	
	/**
	 * cancel + recreate + complete Bexio order, Turn off all emails
	 */
	public function ajax_resync_bexio_order_handler() {
		check_ajax_referer('resync_bexio_order_nonce', 'security');

		if (!current_user_can('edit_shop_orders')) {
			wp_send_json_error(['message' => 'Insufficient permissions']);
		}

		$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
		if (!$order_id) {
			wp_send_json_error(['message' => 'Invalid order ID']);
		}

		$order = wc_get_order($order_id);
		if (!$order) {
			wp_send_json_error(['message' => 'Order not found']);
		}

		// Suppress all PDF emails
		add_filter('bexio_wc_skip_order_email',   '__return_true');
		add_filter('bexio_wc_skip_invoice_email', '__return_true');

		// Cancel any pending scheduled invoice email
		as_unschedule_all_actions(
			'bexio_wc_send_invoice_pdf_delayed',
			['order_id' => $order_id],
			'bexio-wc'
		);

		// ── Resolve order_reference (same logic as prepare_order_data) ──
		$order_created_date = $order->get_date_created();
		$order_reference    = $order->get_id();
		if ($order_created_date && $order_created_date->format('Y') < 2026) {
			$original_order_id = $order->get_meta('_original_order_id', true);
			if ($original_order_id) {
				$order_reference = $original_order_id;
			}
		}

		// ── Search Bexio for the order by document_nr ──
		$search_results = $this->api->search_orders('document_nr', (string) $order_reference);

		$bexio_order_id = 0;

		if (!empty($search_results) && isset($search_results[0]['id'])) {
			$bexio_order_id = (int) $search_results[0]['id'];

			// Keep local meta in sync with what Bexio actually has
			$order->update_meta_data('_bexio_order_id', $bexio_order_id);
			$order->save();

			$this->log_sync($order->get_id(), 'resync', 'success', 'Found Bexio order via document_nr search: ' . $bexio_order_id, $bexio_order_id);
		} else {
			// Fall back to stored meta
			$bexio_order_id = (int) $order->get_meta('_bexio_order_id', true);

			if (!$bexio_order_id) {
				$this->log_sync($order->get_id(), 'resync', 'error', 'No Bexio order found via search or meta for document_nr: ' . $order_reference);
			}
		}

		// ── Delete payment first (invoice can't be deleted while paid) ──
		$bexio_invoice_id = (int) $order->get_meta('_bexio_invoice_id', true);
		$bexio_payment_id = (int) $order->get_meta('_bexio_payment_id', true);

		if ($bexio_invoice_id > 0 && $bexio_payment_id > 0) {
			$this->api->delete_payment($bexio_invoice_id, $bexio_payment_id);
			$order->delete_meta_data('_bexio_payment_id');
			$order->delete_meta_data('_bexio_payment_synced');
			$order->save();
		}

		// ── Delete the existing invoice ──
		if ($bexio_invoice_id > 0) {
			$this->api->issue_to_draft_invoice($bexio_invoice_id);
			$this->api->delete_invoice($bexio_invoice_id);
			$order->delete_meta_data('_bexio_invoice_id');
			$order->delete_meta_data('_bexio_delivery_id');
			$order->delete_meta_data('_bexio_invoice_pdf');
			$order->delete_meta_data('_bexio_invoice_pdf_sent');
			$order->save();
		}

		// ── If still no Bexio order, create one ──
		if (!$bexio_order_id) {
			$bexio_order_id = $this->create_bexio_order($order);
			if (!$bexio_order_id) {
				wp_send_json_error(['message' => 'No Bexio order found and failed to create one']);
			}
		}

		// ── Create a fresh invoice from the existing order ──
		$new_bexio_invoice_id = $this->complete_bexio_order($order, $is_it_from_only_invoice= true);

		$msg = $new_bexio_invoice_id
			? "Invoice recreated. Order #{$bexio_order_id}, Invoice #{$new_bexio_invoice_id}. No emails sent."
			: "Invoice creation failed for Order #{$bexio_order_id}. No emails sent.";

		wp_send_json_success(['message' => $msg]);
	}

	
	/**
	 * Complete order in Bexio WITHOUT sending any invoice email
	 */
	public function ajax_complete_no_invoice_bexio_handler() {
		check_ajax_referer('complete_no_invoice_bexio_nonce', 'security');

		if (!current_user_can('edit_shop_orders')) {
			wp_send_json_error(['message' => 'Insufficient permissions']);
		}

		$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
		if (!$order_id) {
			wp_send_json_error(['message' => 'Invalid order ID']);
		}

		$order = wc_get_order($order_id);
		if (!$order) {
			wp_send_json_error(['message' => 'Order not found']);
		}

		// Suppress Bexio PDF emails
		add_filter('bexio_wc_skip_order_email',   '__return_true');
		add_filter('bexio_wc_skip_invoice_email', '__return_true');

		// Suppress WooCommerce's own "completed order" email
		add_filter('woocommerce_email_enabled_customer_completed_order', '__return_false');
		add_filter('woocommerce_email_enabled_completed_order',          '__return_false');

		// Cancel any pending scheduled invoice email
		as_unschedule_all_actions(
			'bexio_wc_send_invoice_pdf_delayed',
			['order_id' => $order_id],
			'bexio-wc'
		);

		// Ensure order exists in Bexio first
		$bexio_order_id = (int) $order->get_meta('_bexio_order_id', true);
		if (!$bexio_order_id) {
			$bexio_order_id = $this->create_bexio_order($order);
			if (!$bexio_order_id) {
				wp_send_json_error(['message' => 'Failed to create Bexio order']);
			}
		}

		// Create delivery + invoice in Bexio, no emails
		$bexio_invoice_id = $this->complete_bexio_order($order, false, true);

		if (!$bexio_invoice_id) {
			wp_send_json_error(['message' => "Invoice creation failed for Order #{$bexio_order_id}."]);
		}

		// Mark this order as being processed by us so handle_order_status_change
		// doesn't trigger another Bexio sync cycle
		$this->processing_orders[$order_id] = true;

		// Now move WooCommerce order to completed (emails already suppressed above)
		$order->update_status('completed', '[Bexio] Completed via no-invoice button — no email sent.', true);

		unset($this->processing_orders[$order_id]);

		wp_send_json_success([
			'message' => "Order completed. Bexio Order #{$bexio_order_id}, Invoice #{$bexio_invoice_id}. No emails sent."
		]);
	}
    
    /**
     * Send invoice PDF
     */
    private function send_invoice_pdf($order, $bexio_invoice_id) {
        $pdf_handler = Bexio_WC_PDF_Handler::get_instance();
        $pdf_handler->send_invoice_pdf($order, $bexio_invoice_id);
    }
    
    /**
     * Log sync activity
     */
		private function log_sync($order_id, $sync_type, $status, $message = '', $bexio_order_id = null, $bexio_invoice_id = null) {
		global $wpdb;

		$table = $wpdb->prefix . 'bexio_sync_log';

		$wpdb->insert($table, array(
			'order_id' => $order_id,
			'bexio_order_id' => $bexio_order_id,
			'bexio_invoice_id' => $bexio_invoice_id,
			'sync_type' => $sync_type,
			'sync_status' => $status,
			'sync_message' => $message,
		));

		// Add order note WITHOUT triggering updates
		$order = wc_get_order($order_id);
		if ($order) {
			$this->processing_orders[$order_id] = true;

			$order->add_order_note(
				sprintf('[Bexio] %s: %s', ucfirst($sync_type), $message),
				false, 
				false 
			);
			unset($this->processing_orders[$order_id]);
		}
	}

}