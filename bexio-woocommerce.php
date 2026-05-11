<?php
/**
 * Plugin Name: Bexio WooCommerce Integration
 * Plugin URI: https://www.upwork.com/freelancers/~01a6e65817b86d4589?mp_source=share
 * Description: Complete Bexio API integration for WooCommerce with order sync, PDF generation, and accounting automation
 * Version: 1.2.0
 * Author: Masum Billah
 * Author URI: https://www.upwork.com/freelancers/~01a6e65817b86d4589?mp_source=share
 * Text Domain: bexio-wc
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BEXIO_WC_VERSION', '1.1.0');
define('BEXIO_WC_PLUGIN_FILE', __FILE__);
define('BEXIO_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BEXIO_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Plugin Class
 */
class Bexio_WC_Integration {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
        $this->includes();
    }
    
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'check_dependencies'));
        add_action('init', array($this, 'load_textdomain'));
		add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }
        return true;
    }
    
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>' . 
             esc_html__('Bexio WooCommerce Integration requires WooCommerce to be installed and active.', 'bexio-wc') . 
             '</strong></p></div>';
    }
	
	public function declare_hpos_compatibility() {
		if ( class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
    
    private function includes() {
        // Core classes
        require_once BEXIO_WC_PLUGIN_DIR . 'includes/class-bexio-api.php';
        require_once BEXIO_WC_PLUGIN_DIR . 'includes/class-order-sync.php';
        require_once BEXIO_WC_PLUGIN_DIR . 'includes/class-pdf-handler.php';
        require_once BEXIO_WC_PLUGIN_DIR . 'includes/class-customer-sync.php';
        require_once BEXIO_WC_PLUGIN_DIR . 'includes/class-payment-mapper.php';
        require_once BEXIO_WC_PLUGIN_DIR . 'includes/class-migration-handler.php';
        require_once BEXIO_WC_PLUGIN_DIR . 'includes/class-rest-api-auth.php';
        
        //require_once BEXIO_WC_PLUGIN_DIR . 'includes/class-product-sync.php';
        
        // Admin
        if (is_admin()) {
            require_once BEXIO_WC_PLUGIN_DIR . 'admin/class-admin-settings.php';
            require_once BEXIO_WC_PLUGIN_DIR . 'admin/class-bulk-actions.php';
        }
        
        // Initialize classes
        Bexio_WC_REST_Auth::get_instance();
        Bexio_WC_Order_Sync::get_instance();
        Bexio_WC_PDF_Handler::get_instance();
        Bexio_WC_Customer_Sync::get_instance();
        
        
        if (is_admin()) {
            Bexio_WC_Admin_Settings::get_instance();
            Bexio_WC_Bulk_Actions::get_instance();
        }
    }
    
    public function load_textdomain() {
        load_plugin_textdomain('bexio-wc', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function activate() {
        // Create necessary database tables
        $this->create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_name = $wpdb->prefix . 'bexio_sync_log';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            bexio_order_id bigint(20) DEFAULT NULL,
            bexio_invoice_id bigint(20) DEFAULT NULL,
            sync_type varchar(50) NOT NULL,
            sync_status varchar(20) NOT NULL,
            sync_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY bexio_order_id (bexio_order_id),
            KEY sync_status (sync_status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    private function set_default_options() {
        $defaults = array(
            'bexio_wc_api_access_token' => '',
            'bexio_wc_api_refresh_token' => '',
            'bexio_wc_api_hash' => '',
            'bexio_wc_shipping_account' => '3600',
            'bexio_wc_product_account' => '3200',
            'bexio_wc_vat_code' => 'UN81',
            'bexio_wc_invoice_bank' => 'Raiffeisen',
            'bexio_wc_card_bank' => 'Paypal',
            'bexio_wc_create_on_status' => array('processing', 'service-arrived'),
            'bexio_wc_complete_on_status' => array('shipped', 'abholbereit'),
            'bexio_wc_auto_send_pdfs' => 'yes',
            'bexio_wc_debug_mode' => 'no',
            'bexio_wc_registered' => 'no'
        );
        
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
}

// Initialize the plugin
function bexio_wc_init() {
    return Bexio_WC_Integration::get_instance();
}

add_action('plugins_loaded', 'bexio_wc_init', 20);