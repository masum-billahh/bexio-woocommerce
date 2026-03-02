<?php
/**
 * Payment Mapper
 * Maps WooCommerce payments to Bexio and syncs payment status
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bexio_WC_Payment_Mapper {
    
    private static $instance = null;
    private $api;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->api = Bexio_WC_API::get_instance();
    }
    
    /**
     * Sync payment to Bexio invoice
     */
    public function sync_payment($order, $bexio_invoice_id) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        
        if (!$order || !$order->is_paid()) {
            return false;
        }
        
        // Check if payment already synced
        $synced = $order->get_meta('_bexio_payment_synced');
        if ($synced) {
            return true;
        }
        
        try {
            // Get payment method and map to bank account
            $payment_method = $order->get_payment_method();
			if (
				$payment_method === 'cod' ||
				empty($payment_method) ||
				$payment_method === 'N/A'
			) {
				$order->add_order_note(__('Payment method cod, no payment will be created for this method in bexio ', 'bexio-wc'));
				return false;
			}
			
            $bank_account_id = $this->get_bank_account_id($payment_method);
            
            // Prepare payment data
            $payment_data = array(
                'date' => $order->get_date_paid() ? $order->get_date_paid()->date('Y-m-d') : date('Y-m-d'),
                'value' => floatval($order->get_total()),
                'bank_account_id' => $bank_account_id,
                //'title' => sprintf('Payment for Order #%s', $order->get_order_number()),
                //'is_client_account_redemption' => false,
            );
            
            // Create payment in Bexio
            $result = $this->api->create_payment($bexio_invoice_id, $payment_data);
            $bexio_payment_id = $result['id'];
			
            if ($result) {
				$order->update_meta_data('_bexio_payment_id', $bexio_payment_id);
                $order->update_meta_data('_bexio_payment_synced', true);
				$order->update_meta_data('_bexio_payment_date', current_time('mysql'));
				$order->save();
                
                $order->add_order_note(__('Payment synced to Bexio', 'bexio-wc'));
                
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log('[Bexio WC] Payment sync error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get bank account ID based on payment method
     */
    private function get_bank_account_id($payment_method) {
        // Define payment method mappings
        $card_methods = array(
            'stripe',
            'stripe_cc',
            'paypal',
            'square',
            'square_credit_card',
        );
        
        $twint_methods = array(
            'twint',
            'wc_twint',
        );
        
        $invoice_methods = array(
            'bacs',
            'cheque',
            'cod',
            'invoice',
        );
        
        // Map to configured bank accounts
        if (in_array($payment_method, $card_methods)) {
            // Credit card → Paypal account
            return get_option('bexio_wc_card_bank_id', 1);
        } elseif (in_array($payment_method, $twint_methods) || in_array($payment_method, $invoice_methods)) {
            // Invoice & Twint → Raiffeisen account
            return get_option('bexio_wc_invoice_bank_id', 1);
        }
        
        // Default to invoice bank account
        return get_option('bexio_wc_invoice_bank_id', 1);
    }
    
    /**
     * Get payment method display name
     */
    public function get_payment_method_name($payment_method) {
        $gateways = WC()->payment_gateways->payment_gateways();
        
        if (isset($gateways[$payment_method])) {
            return $gateways[$payment_method]->get_title();
        }
        
        return $payment_method;
    }
    
    /**
     * Check if payment method should create invoice or mark as paid
     */
    public function should_create_invoice($payment_method) {
        $invoice_methods = array(
            'bacs',
            'cheque',
            'cod',
            'invoice',
        );
        
        return in_array($payment_method, $invoice_methods);
    }
}