<?php
/**
 * Customer Sync Handler
 * Manages customer/contact synchronization with Bexio
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bexio_WC_Customer_Sync {
    
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
     * Sync customer to Bexio
     * Returns Bexio contact ID
     */
    public function sync_customer($order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        
        if (!$order) {
            return false;
        }
        
        // Check if contact already exists
        $existing_contact_id = $order->get_meta('_bexio_contact_id');
        
        if ($existing_contact_id) {
            // Update existing contact
            return $this->update_customer($order, $existing_contact_id);
        }
        
        // Search for contact by email
        //$email = $order->get_billing_email();
		$email = $order->get_meta('_shipping_email');
		
		if ($email){
			$existing_contact = $this->api->search_contact($email);
		}
		
        
        
        if ($existing_contact && isset($existing_contact['id'])) {
            // Contact found, update and return
            $order->update_meta_data('_bexio_contact_id', $existing_contact['id']);
			$order->save();
            return $this->update_customer($order, $existing_contact['id']);
        }
        
        // Create new contact
        return $this->create_customer($order);
    }
    
    /**
     * Create new customer in Bexio
     */
    private function create_customer($order) {
        $contact_data = $this->prepare_contact_data($order, false);
        
        $bexio_contact = $this->api->create_contact($contact_data);
        
        if ($bexio_contact && isset($bexio_contact['id'])) {
			$order->update_meta_data('_bexio_contact_id', $bexio_contact['id']);
			$order->save();
            
            // Also store on customer if registered
            $customer_id = $order->get_customer_id();
            if ($customer_id) {
                update_user_meta($customer_id, '_bexio_contact_id', $bexio_contact['id']);
            }
            
            return $bexio_contact['id'];
        }
        
        return false;
    }
    
    /**
     * Update existing customer in Bexio
     */
    private function update_customer($order, $contact_id) {
        $contact_data = $this->prepare_contact_data($order, true);
        
        $bexio_contact = $this->api->update_contact($contact_id, $contact_data);
        
        if ($bexio_contact && isset($bexio_contact['id'])) {
            return $bexio_contact['id'];
        }
        
        return $contact_id; // Return existing ID even if update failed
    }
    
    /**
     * Prepare contact data for Bexio
     */
    private function prepare_contact_data($order, $is_it_update= false) {
        $billing = $order->get_address('billing');
        $shipping = $order->get_address('shipping');
		$second_mail = $order->get_meta('_shipping_email');
		
		if (!$second_mail){
			$second_mail= null;
		}
        
        // Determine language
        $language_id = $this->get_language_id($order);
        
        // Determine country
        $country_id = $this->get_country_id($billing['country']);
		
		// Billing is priority for address. Fall back to shipping if billing fields are empty.
		$address_source = (
			!empty($billing['address_1']) ||
			!empty($billing['postcode']) ||
			!empty($billing['city'])
		) ? $billing : $shipping;
        
        $data = array(
            'contact_type_id' => !empty($billing['company']) ? 1 : 2, // if company 1,  person 2
            'name_1' => !empty($billing['company']) ? $billing['company'] : $billing['last_name'],
            'name_2' => !empty($billing['company']) ? $billing['first_name'] . ' ' . $billing['last_name'] : $billing['first_name'],
            'mail' => $billing['email'],
			'mail_second' => $second_mail,
            'phone_fixed' => $billing['phone'],
			'street_name'      => $address_source['address_1'],
			'house_number' => null,
			'address_addition' => !empty($address_source['address_2']) ? $address_source['address_2'] : null,
            'postcode' => $address_source['postcode'],
            'city' => $address_source['city'],
            'country_id' => $country_id,
            'language_id' => $language_id,
        );
        
		if (!$is_it_update) {
			$data['user_id'] = 1;
			$data['owner_id'] = 1;
		}
        
        return $data;
    }
    
    /**
     * Get language ID for Bexio
     */
    private function get_language_id($order) {
        // Try to get language from WPML or Polylang
        $locale = $locale = $order->get_meta('wpml_language');        
        if (!$locale) {
            $locale = $order->get_meta('_user_language');  
        }
        
        if (!$locale) {
            // Fall back to site locale
            $locale = substr(get_locale(), 0, 2);
        }
        
        // Map locale to Bexio language ID
        $language_map = array(
            'de' => 1, 
            'fr' => 2, 
            'it' => 3, 
            'en' => 4, 
        );
        
        return isset($language_map[$locale]) ? $language_map[$locale] : 1;
    }
    
    /**
     * Get country ID for Bexio
     */
    private function get_country_id($country_code) {
        // Common European countries
        $country_map = array(
            'CH' => 1,   // Switzerland
            'LI' => 2,   // Liechtenstein
            'DE' => 11,  // Germany
            'AT' => 14,  // Austria
            'FR' => 26,  // France
            'IT' => 109, // Italy
            'GB' => 77,  // United Kingdom
            'US' => 213, // United States
            'NL' => 156, // Netherlands
            'BE' => 20,  // Belgium
            'ES' => 68,  // Spain
            'PT' => 172, // Portugal
        );
        
        return isset($country_map[$country_code]) ? $country_map[$country_code] : 1;
    }
    
    /**
     * Check if order has different shipping address
     */
    private function has_different_shipping($order) {
        $billing = $order->get_address('billing');
        $shipping = $order->get_address('shipping');
        
        if (empty($shipping['address_1'])) {
            return false;
        }
        
        return (
            $billing['address_1'] !== $shipping['address_1'] ||
            $billing['city'] !== $shipping['city'] ||
            $billing['postcode'] !== $shipping['postcode'] ||
            $billing['country'] !== $shipping['country']
        );
    }
}