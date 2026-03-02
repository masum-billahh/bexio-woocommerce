<?php
/**
 * REST API Auth Handler
 * Handles Bexio OAuth2 authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once BEXIO_WC_PLUGIN_DIR . 'OpenID/jumbojet/vendor/autoload.php';
use Jumbojett\OpenIDConnectClient;

class Bexio_WC_REST_Auth {
    
    private static $instance = null;
    private $namespace = 'bexio-wc/v1';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('admin_init', array($this, 'handle_oauth_callback'));
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Start OAuth flow endpoint
        register_rest_route($this->namespace, '/auth/connect', array(
            'methods' => 'GET',
            'callback' => array($this, 'start_oauth_flow'),
            'permission_callback' => array($this, 'check_permission')
        ));
        
        // Test connection endpoint
        register_rest_route($this->namespace, '/auth/test', array(
            'methods' => 'GET',
            'callback' => array($this, 'test_connection'),
            'permission_callback' => array($this, 'check_permission')
        ));
    }
    
    /**
     * Start OAuth flow - redirect to Bexio
     */
    public function start_oauth_flow($request) {
        try {
            $client_id = get_option('bexio_wc_client_id');
            $client_secret = get_option('bexio_wc_client_secret');
            
            if (empty($client_id) || empty($client_secret)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => __('Client ID and Secret must be configured first', 'bexio-wc')
                ), 400);
            }
            
            $redirect_uri = admin_url('admin.php?page=bexio-integration&action=bexio_callback');
            
            $oidc = new OpenIDConnectClient(
                "https://auth.bexio.com/realms/bexio",
                $client_id,
                $client_secret
            );
            
            $oidc->setRedirectURL($redirect_uri);
            $oidc->addScope(array(
                "openid",
                "profile",
                "offline_access",
                "contact_show",
                "contact_edit",
                "kb_order_show",
                "kb_order_edit",
				"kb_delivery_show",
				"kb_delivery_edit",
				"kb_article_order_show",
				"kb_article_order_edit",
                "kb_invoice_show",
                "kb_invoice_edit"  
                ));
            
            // This will redirect to Bexio
            $oidc->authenticate();
            exit;
            
        } catch (Exception $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $e->getMessage()
            ), 500);
        }
    }
    
    /**
     * Handle OAuth callback from Bexio
     */
    public function handle_oauth_callback() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'bexio-integration') {
            return;
        }
        
        if (!isset($_GET['action']) || $_GET['action'] !== 'bexio_callback') {
            return;
        }
        
        if (!isset($_GET['code'])) {
            return;
        }
        
        try {
            $client_id = get_option('bexio_wc_client_id');
            $client_secret = get_option('bexio_wc_client_secret');
            $redirect_uri = admin_url('admin.php?page=bexio-integration&action=bexio_callback');
            
            $oidc = new OpenIDConnectClient(
                "https://auth.bexio.com/realms/bexio",
                $client_id,
                $client_secret
            );
            
            $oidc->setRedirectURL($redirect_uri);
            $oidc->addScope(array(
                "openid",
                "profile",
                "offline_access",
                "contact_show",
                "contact_edit",
                "kb_order_show",
                "kb_order_edit",
				"kb_delivery_show",
				"kb_delivery_edit",
				"kb_article_order_show",
				"kb_article_order_edit",
                "kb_invoice_show",
                "kb_invoice_edit"
                 
              ));
            
            // Complete authentication
            $oidc->authenticate();
            
            // Get tokens
            $access_token = $oidc->getAccessToken();
            $refresh_token = $oidc->getRefreshToken();
            
            if ($access_token) {
                // Save tokens
                update_option('bexio_wc_api_access_token', $access_token);
                update_option('bexio_wc_api_refresh_token', $refresh_token);
                update_option('bexio_wc_connected', 'yes');
                
                // Redirect back to settings with success message
                wp_redirect(admin_url('admin.php?page=bexio-integration&tab=api&connected=1'));
                exit;
            }
            
        } catch (Exception $e) {
            // Redirect with error
            wp_redirect(admin_url('admin.php?page=bexio-integration&tab=api&error=' . urlencode($e->getMessage())));
            exit;
        }
    }
    
    /**
     * Test API connection
     */
    public function test_connection($request) {
        $api = Bexio_WC_API::get_instance();
        $result = $api->test_connection();
        
        return new WP_REST_Response(array(
            'success' => $result,
            'message' => $result ? __('Connection successful', 'bexio-wc') : __('Connection failed', 'bexio-wc')
        ), $result ? 200 : 500);
    }
    
    /**
     * Refresh access token using refresh token
     */
    public function refresh_access_token() {
        try {
            $client_id = get_option('bexio_wc_client_id');
            $client_secret = get_option('bexio_wc_client_secret');
            $refresh_token = get_option('bexio_wc_api_refresh_token');
            
            if (empty($refresh_token)) {
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
                return false;
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['access_token'])) {
                update_option('bexio_wc_api_access_token', $body['access_token']);
                
                if (isset($body['refresh_token'])) {
                    update_option('bexio_wc_api_refresh_token', $body['refresh_token']);
                }
                
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check permission callback
     */
    public function check_permission() {
        //return current_user_can('manage_woocommerce') || current_user_can('administrator');
        return true;
    }
}