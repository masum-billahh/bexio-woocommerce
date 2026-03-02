<?php
/* Bexio API Handler
 * Handles all API communication with Bexio
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bexio_WC_API {
    
    private static $instance = null;
    private $api_token;
    private $api_url = 'https://api.bexio.com';
    private $api_version = '2.0';
	private $api_version_taxes = '3.0';
    private $helvy_api_url;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->api_token = get_option('bexio_wc_api_access_token');
        $this->helvy_api_url = $this->get_helvy_api_url();
    }
    
    /**
     * Get Helvy API URL based on environment
     */
    private function get_helvy_api_url() {
        if (defined('BEXIO_WC_ENV')) {
            if (BEXIO_WC_ENV == "dev") {
                return 'http://192.168.64.1:3000/v1';
            } else if (BEXIO_WC_ENV == "stage") {
                return 'https://dev-wc-bexio-connector-api.helvy.ch/v1';
            }
        }
        return 'https://wc-bexio-connector-api.helvy.ch/v1';
    }
    
    /**
     * Make API request with automatic token refresh
     */
  
public function request($endpoint, $method = 'GET', $data = null, $from_taxes = false) {
    add_filter('https_ssl_verify', '__return_false');
    add_filter('https_local_ssl_verify', '__return_false');
    
    $version = $from_taxes ? $this->api_version_taxes : $this->api_version;
    $url = $this->api_url . '/' . $version . '/' . ltrim($endpoint, '/');
    
    $args = array(
        'method' => strtoupper($method),
        'headers' => array(
            'Authorization' => 'Bearer ' . $this->api_token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ),
        'timeout' => 30,
    );
    
    if (!empty($data)) {
        if ($method === 'GET') {
            $url = add_query_arg(array_map('rawurlencode', $data), $url);
        } else {
            $args['body'] = wp_json_encode($data);
        }
    }
    
    $this->log_error('api_request', array(
        'method'  => $method,
        'route'     => $url,
        'request_data' => array(
            'params' => 'null',
            'body'   => $data,
        ),
        'headers' => $args['headers'],
    ));
    
    $response = wp_remote_request($url, $args);
    
    if (is_wp_error($response)) {
        $this->log_error('api_response', array(
            'url'     => $url,
            'status'  => 'wp_error',
            'error'   => $response->get_error_message(),
        ));
        return false;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);
    
    $log_data = array(
        'route'        => $url,
        'status_code'  => $status_code,
        'response_data'=> $decoded,
        'headers'      => wp_remote_retrieve_headers($response),
    );
    
    $this->log_error('api_response', $log_data);
    
    // Check for API errors in response
    if (!empty($decoded["error_code"]) || !empty($decoded["errors"])) {
        if (!empty($decoded["errors"])) {
            $this->log_error('API Error', 'bexio API error: ' . join(", ", $decoded["errors"]));
        } elseif (!empty($decoded["message"])) {
            $this->log_error('API Error', 'bexio API error: ' . $decoded["message"]);
        }
        return false;
    }
    
    if ($status_code >= 200 && $status_code < 300) {
        return $decoded;
    } elseif ($status_code === 401) {
        $this->log_error('Token Expired', 'Attempting to refresh token and retry request');
        
        // Token expired, refresh and retry
        $refresh_result = $this->refresh_token();
        
        if (!$refresh_result) {
            $this->log_error('API Error', 'Token refresh failed, cannot retry request');
            return false;
        }
        
        // Update the authorization header with the new token
        $args['headers']['Authorization'] = 'Bearer ' . $this->api_token;
        
        // Rebuild the request body if needed (in case it was modified)
        if (!empty($data) && $method !== 'GET') {
            $args['body'] = wp_json_encode($data);
        }
        
        $this->log_error('bexio_api_retry', array(
            'message' => 'Retrying request with new token',
            'url'      => $url,
            'method'   => $method,
        ));
        
        // Retry the request with the new token
        $retry_response = wp_remote_request($url, $args);
        
        if (is_wp_error($retry_response)) {
            $this->log_error('API Retry Failed', array(
                'url'   => $url,
                'error' => $retry_response->get_error_message(),
            ));
            return false;
        }
        
        $retry_status_code = wp_remote_retrieve_response_code($retry_response);
        $retry_body = wp_remote_retrieve_body($retry_response);
        $retry_decoded = json_decode($retry_body, true);
        
        $this->log_error('api_retry_response', array(
            'route'        => $url,
            'status_code'  => $retry_status_code,
            'response_data'=> $retry_decoded,
            'headers'      => wp_remote_retrieve_headers($retry_response),
        ));
        
        // Check for success after retry
        if ($retry_status_code >= 200 && $retry_status_code < 300) {
            $this->log_error('API Retry Success', 'Request succeeded after token refresh');
            return $retry_decoded;
        }
        
        // Check for API errors in retry response
        if (!empty($retry_decoded["error_code"]) || !empty($retry_decoded["errors"])) {
            if (!empty($retry_decoded["errors"])) {
                $this->log_error('API Retry Error', 'bexio API error: ' . join(", ", $retry_decoded["errors"]));
            } elseif (!empty($retry_decoded["message"])) {
                $this->log_error('API Retry Error', 'bexio API error: ' . $retry_decoded["message"]);
            }
        }
        
        $this->log_error('API Error', 'Authentication failed after token refresh - Status: ' . $retry_status_code);
        return false;
    } else {
        $this->log_error('API Error', 'Status: ' . $status_code . ', Response: ' . $body);
        return false;
    }
}
    
    /**
     * Refresh access token using refresh token
     */
    private function refresh_token() {
		$client_id = get_option('bexio_wc_client_id');
		$client_secret = get_option('bexio_wc_client_secret');
		$refresh_token = get_option('bexio_wc_api_refresh_token');

		if (empty($refresh_token)) {
			$this->log_error('Token Refresh Failed', 'No refresh token available');
			return false;
		}

		$response = wp_remote_post('https://auth.bexio.com/realms/bexio/protocol/openid-connect/token', array(
			'body' => array(
				'grant_type' => 'refresh_token',
				'client_id' => $client_id,
				'client_secret' => $client_secret,
				'refresh_token' => $refresh_token
			)
		));

		if (is_wp_error($response)) {
			$this->log_error('Token Refresh Failed', $response->get_error_message());
			return false;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);

		if (isset($body['access_token'])) {
			// Update tokens
			$this->api_token = $body['access_token'];
			update_option('bexio_wc_api_access_token', $body['access_token']);

			if (isset($body['refresh_token'])) {
				update_option('bexio_wc_api_refresh_token', $body['refresh_token']);
			}

			return true;
		}

		$this->log_error('Token Refresh Failed', 'Invalid response from token endpoint');
		return false;
	}
    
    /**
     * Login using stored credentials
     */
    private function login() {
        $url = $this->helvy_api_url . '/auth/login';
        
        $site_domain = $this->extract_domain(get_site_url());
        
        $args = array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'hostname' => $site_domain,
                'password' => get_option('bexio_wc_api_hash'),
            )),
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            $this->log_error('Login Failed', $response->get_error_message());
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        
        if ($status_code >= 200 && $status_code < 300 && isset($decoded['tokens']['access']['token'])) {
            // Update tokens
            $this->api_token = $decoded['tokens']['access']['token'];
            update_option('bexio_wc_api_access_token', $decoded['tokens']['access']['token']);
            update_option('bexio_wc_api_refresh_token', $decoded['tokens']['refresh']['token']);
            return true;
        }
        
        $this->log_error('Login Failed', 'Invalid credentials');
        return false;
    }
    
    /**
     * Extract domain from URL
     */
    private function extract_domain($url) {
        $parsed_url = wp_parse_url($url);
        
        if ($parsed_url && isset($parsed_url['host'])) {
            if (isset($parsed_url['port']) && $parsed_url['port'] != 80 && $parsed_url['port'] != 443) {
                return $parsed_url['host'] . ':' . $parsed_url['port'];
            } else {
                return $parsed_url['host'];
            }
        }
        
        return null;
    }
    
    /**
     * Contact Management
     */
    public function create_contact($data) {
        return $this->request('contact', 'POST', $data);
    }
    
    public function get_contact($contact_id) {
        return $this->request('contact/' . $contact_id);
    }
    
    public function search_contact($email) {
        $contacts = $this->request('contact/search', 'POST', array(
            array(
                'field' => 'mail',
                'value' => $email,
                'criteria' => '='
            )
        ));
        
        return !empty($contacts) ? $contacts[0] : null;
    }
    
    public function update_contact($contact_id, $data) {
        return $this->request('contact/' . $contact_id, 'POST', $data);
    }
    
    /**
     * Order Management
     */
    public function create_order($data) {
        return $this->request('kb_order', 'POST', $data);
    }
    
    public function get_order($order_id) {
        return $this->request('kb_order/' . $order_id);
    }
    
    public function update_order($order_id, $data) {
        return $this->request('kb_order/' . $order_id, 'POST', $data);
    }
    
    public function issue_order($order_id) {
        return $this->request('kb_order/' . $order_id . '/issue', 'POST');
    }
    
    public function revert_order($order_id) {
        return $this->request('kb_order/' . $order_id . '/revert', 'POST');
    }
	
	/**
     * Delete order, invoice & payment
     */
	public function delete_order($bexio_order_id) {
		return $this->request('kb_order/' . $bexio_order_id, 'DELETE');
	}
	
	public function cancel_invoice($bexio_invoice_id) {
		return $this->request('kb_invoice/' . $bexio_invoice_id . '/cancel', 'POST');
	}
	
	public function issue_to_draft_invoice($bexio_invoice_id) {
		return $this->request('kb_invoice/' . $bexio_invoice_id . '/revert_issue', 'POST');
	}
	
	public function delete_invoice($bexio_invoice_id) {
		return $this->request('kb_invoice/' . $bexio_invoice_id, 'DELETE');
	}
	
	public function delete_payment($bexio_invoice_id, $bexio_payment_id) {
		return $this->request('kb_invoice/' . $bexio_invoice_id . '/payment/' . $bexio_payment_id, 'DELETE');
	}
    
    /**
     * Invoice Management
     */
    public function create_invoice($bexio_order_id) {
        return $this->request('kb_order/' . $bexio_order_id . '/invoice', 'POST');
    }
    
    public function get_invoice($invoice_id) {
        return $this->request('kb_invoice/' . $invoice_id);
    }
	
	public function edit_invoice($invoice_id, $data) {
        return $this->request('kb_invoice/' . $invoice_id, 'POST', $data);
    }
    
    public function issue_invoice($invoice_id) {
        return $this->request('kb_invoice/' . $invoice_id . '/issue', 'POST');
    }
    
    public function mark_invoice_as_sent($invoice_id) {
        return $this->request('kb_invoice/' . $invoice_id . '/mark_as_sent', 'POST');
    }
    
	/**
     * Delivery Management
     */
    public function create_delivery($bexio_order_id) {
        return $this->request('kb_order/' . $bexio_order_id . '/delivery', 'POST');
    }
	
	 public function issue_delivery($delivery_id) {
        return $this->request('kb_delivery/' . $delivery_id . '/issue', 'POST');
    }
	
	/**
	 * Position Management
	 */
	public function get_document_positions($kb_document_type, $document_id) {
		return $this->request($kb_document_type . '/' . $document_id . '/kb_position_custom');
	}

	public function create_document_position($kb_document_type, $document_id, $data) {
		return $this->request($kb_document_type . '/' . $document_id . '/kb_position_custom', 'POST', $data);
	}

	public function update_document_position($kb_document_type, $document_id, $position_id, $data) {
		return $this->request($kb_document_type . '/' . $document_id . '/kb_position_custom/' . $position_id, 'POST', $data);
	}

	public function delete_document_position($kb_document_type, $document_id, $position_id) {
		return $this->request($kb_document_type . '/' . $document_id . '/kb_position_custom/' . $position_id, 'DELETE');
	}
	
	
    /**
     * PDF Management
     */
    public function get_order_pdf($order_id) {
		$response = $this->request('kb_order/' . $order_id . '/pdf', 'GET');

		if (is_wp_error($response)) {
			return false;
		}

		return base64_decode($response['content']);
	}

    
    public function get_invoice_pdf($invoice_id) {
		$response = $this->request('kb_invoice/' . $invoice_id . '/pdf', 'GET');
        
        if (is_wp_error($response)) {
			return false;
		}

		return base64_decode($response['content']);
    }
    
    /**
     * Payment Management
     */
    public function create_payment($invoice_id, $data) {
        return $this->request('kb_invoice/' . $invoice_id . '/payment', 'POST', $data);
    }
    
    /**
     * Account & Tax Management
     */
    public function get_accounts() {
        return $this->request('account');
    }
    
    public function get_taxes() {
		return $this->request('taxes', 'GET', null, true);
	}
    
    public function get_banks() {
        return $this->request('banking/account');
    }
    
    /**
     * Language/Country Management
     */
    public function get_languages() {
        return $this->request('language');
    }
    
    public function get_countries() {
        return $this->request('country');
    }
    
    /**
     * Utilities
     */
    public function test_connection() {
        $result = $this->request('contact', 'GET');
        return $result !== false;
    }
    
    private function log_error($type, $data = array()) {
        //if (get_option('bexio_wc_debug_mode') !== 'yes') return;
		if (function_exists('save_wc_log')) {
        	save_wc_log($type, $data);
    	}
    }
    
    /**
     * Helper to format contact data for Bexio
     */
    public function format_contact_data($order) {
        $billing = $order->get_address('billing');
        
        // Get language code (map WooCommerce to Bexio language IDs)
        $language_map = array(
            'de' => 1, // German
            'fr' => 2, // French
            'it' => 3, // Italian
            'en' => 4  // English
        );
        
       $locale = $order->get_meta('wpml_language');
        if (!$locale) {
            $locale = substr(get_locale(), 0, 2);
        }
        $language_id = isset($language_map[$locale]) ? $language_map[$locale] : 1;
        
        return array(
            'contact_type_id' => 1, // Customer
            'name_1' => $billing['company'] ?: $billing['last_name'],
            'name_2' => $billing['first_name'],
            'mail' => $billing['email'],
            'phone_fixed' => $billing['phone'],
            'address' => $billing['address_1'],
            'postcode' => $billing['postcode'],
            'city' => $billing['city'],
            'country_id' => $this->get_country_id($billing['country']),
            'language_id' => $language_id,
        );
    }
    
    /**
     * Get Bexio country ID from ISO code
     */
    public function get_country_id($country_code) {
        // Common country IDs in Bexio
        $country_map = array(
            'CH' => 1,  // Switzerland
            'DE' => 11, // Germany
            'AT' => 14, // Austria
            'FR' => 26, // France
            'IT' => 109 // Italy
        );
        
        return isset($country_map[$country_code]) ? $country_map[$country_code] : 1;
    }
}