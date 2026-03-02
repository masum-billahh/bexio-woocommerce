<?php
/**
 * Admin Settings - COMPLETE FIX with Tab-Specific Forms
 * Provides settings page for Bexio integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bexio_WC_Admin_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_bexio_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_bexio_fetch_accounts', array($this, 'ajax_fetch_accounts'));
    }
    
    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __('Bexio Integration', 'bexio-wc'),
            __('Bexio', 'bexio-wc'),
            'manage_woocommerce',
            'bexio-integration',
            array($this, 'render_settings_page')
        );
    }
    
    public function register_settings() {
        // ===================================================================
        // API SETTINGS GROUP - Only for API tab
        // ===================================================================
        register_setting('bexio_wc_api_settings', 'bexio_wc_client_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        register_setting('bexio_wc_api_settings', 'bexio_wc_client_secret', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        register_setting('bexio_wc_api_settings', 'bexio_wc_connected', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'no'
        ));
        
        register_setting('bexio_wc_api_settings', 'bexio_wc_debug_mode', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => 'no'
        ));
        
        // ===================================================================
        // ACCOUNTING SETTINGS GROUP - Only for Accounting tab
        // ===================================================================
        register_setting('bexio_wc_accounting_settings', 'bexio_wc_shipping_account', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '3600'
        ));
        
        register_setting('bexio_wc_accounting_settings', 'bexio_wc_product_account', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '3200'
        ));
        
        register_setting('bexio_wc_accounting_settings', 'bexio_wc_vat_code', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'UN81'
        ));
        
        register_setting('bexio_wc_accounting_settings', 'bexio_wc_tax_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        register_setting('bexio_wc_accounting_settings', 'bexio_wc_invoice_bank_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        register_setting('bexio_wc_accounting_settings', 'bexio_wc_card_bank_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        // ===================================================================
        // ORDER SETTINGS GROUP - Only for Orders tab
        // ===================================================================
        register_setting('bexio_wc_order_settings', 'bexio_wc_create_on_status', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_array'),
            'default' => array('processing', 'service-arrived')
        ));
        
        register_setting('bexio_wc_order_settings', 'bexio_wc_complete_on_status', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_array'),
            'default' => array('shipped', 'abholbereit')
        ));
        
        register_setting('bexio_wc_order_settings', 'bexio_wc_auto_send_pdfs', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => 'yes'
        ));
    }
    
    /**
     * Sanitize checkbox values
     */
    public function sanitize_checkbox($value) {
        return ($value === 'yes' || $value === '1') ? 'yes' : 'no';
    }
    
    /**
     * Sanitize array values
     */
    public function sanitize_array($value) {
        if (!is_array($value)) {
            return array();
        }
        return array_map('sanitize_text_field', $value);
    }
    
    public function render_settings_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'api';
        ?>
        <div class="wrap">
            <h1><?php _e('Bexio Integration Settings', 'bexio-wc'); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=bexio-integration&tab=api" class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('API', 'bexio-wc'); ?>
                </a>
                <a href="?page=bexio-integration&tab=accounting" class="nav-tab <?php echo $active_tab === 'accounting' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Accounting', 'bexio-wc'); ?>
                </a>
                <a href="?page=bexio-integration&tab=orders" class="nav-tab <?php echo $active_tab === 'orders' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Order Sync', 'bexio-wc'); ?>
                </a>
                <a href="?page=bexio-integration&tab=migration" class="nav-tab <?php echo $active_tab === 'migration' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Migration', 'bexio-wc'); ?>
                </a>
                <a href="?page=bexio-integration&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Sync Logs', 'bexio-wc'); ?>
                </a>
            </h2>
            
            <?php
            // Tabs that don't need forms (read-only)
            if (in_array($active_tab, array('migration', 'logs'))) {
                switch ($active_tab) {
                    case 'migration':
                        $this->render_migration_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                }
                echo '</div>';
                return;
            }
            ?>
            
            <form method="post" action="options.php">
                <?php
                // Each tab has its own settings group - prevents cross-contamination
                switch ($active_tab) {
                    case 'api':
                        settings_fields('bexio_wc_api_settings');
                        $this->render_api_settings();
                        break;
                    case 'accounting':
                        settings_fields('bexio_wc_accounting_settings');
                        $this->render_accounting_settings();
                        break;
                    case 'orders':
                        settings_fields('bexio_wc_order_settings');
                        $this->render_order_settings();
                        break;
                }
                
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    private function render_api_settings() {
        $is_connected = get_option('bexio_wc_connected') === 'yes';

        if (isset($_GET['connected']) && $_GET['connected'] == '1') {
            echo '<div class="notice notice-success"><p>' . __('Successfully connected to Bexio!', 'bexio-wc') . '</p></div>';
        }
        if (isset($_GET['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html($_GET['error']) . '</p></div>';
        }
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="bexio_wc_client_id"><?php _e('Client ID', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <input type="text" 
                           id="bexio_wc_client_id" 
                           name="bexio_wc_client_id" 
                           value="<?php echo esc_attr(get_option('bexio_wc_client_id')); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Get this from your Bexio Developer Portal: https://developer.bexio.com/', 'bexio-wc'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="bexio_wc_client_secret"><?php _e('Client Secret', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <input type="password" 
                           id="bexio_wc_client_secret" 
                           name="bexio_wc_client_secret" 
                           value="<?php echo esc_attr(get_option('bexio_wc_client_secret')); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Get this from your Bexio Developer Portal', 'bexio-wc'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label><?php _e('Redirect URI', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <code><?php echo admin_url('admin.php?page=bexio-integration&action=bexio_callback'); ?></code>
                    <p class="description">
                        <?php _e('Add this URL to your Bexio application settings', 'bexio-wc'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label><?php _e('Connection Status', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <?php if ($is_connected): ?>
                        <span style="color: green;">✓ <?php _e('Connected', 'bexio-wc'); ?></span>
                        <p class="description">
                            <?php _e('Your site is connected to Bexio.', 'bexio-wc'); ?>
                        </p>
                    <?php else: ?>
                        <span style="color: orange;">⚠ <?php _e('Not Connected', 'bexio-wc'); ?></span>
                        <p class="description">
                            <?php _e('Click "Connect to Bexio" below to authenticate.', 'bexio-wc'); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label><?php _e('Bexio Authentication', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <a href="<?php echo get_rest_url(null, 'bexio-wc/v1/auth/connect'); ?>" 
                       class="button button-primary">
                        <?php _e('Connect to Bexio', 'bexio-wc'); ?>
                    </a>
                    <p class="description">
                        <?php _e('Authenticate with Bexio to start syncing orders.', 'bexio-wc'); ?>
                    </p>
                </td>
            </tr>

            <!-- Hidden field to preserve connected status -->
            <input type="hidden" name="bexio_wc_connected" value="<?php echo esc_attr(get_option('bexio_wc_connected', 'no')); ?>">

            <tr>
                <th scope="row">
                    <label><?php _e('Test Connection', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <button type="button" id="test-connection" class="button">
                        <?php _e('Test Connection', 'bexio-wc'); ?>
                    </button>
                    <span id="connection-status"></span>
                    <p class="description">
                        <?php _e('Test your connection to Bexio API.', 'bexio-wc'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="bexio_wc_debug_mode"><?php _e('Debug Mode', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" 
                               id="bexio_wc_debug_mode" 
                               name="bexio_wc_debug_mode" 
                               value="yes" 
                               <?php checked(get_option('bexio_wc_debug_mode'), 'yes'); ?>>
                        <?php _e('Enable debug logging', 'bexio-wc'); ?>
                    </label>
                    <p class="description">
                        <?php _e('Log API requests and errors for debugging purposes.', 'bexio-wc'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    private function render_accounting_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="bexio_wc_product_account"><?php _e('Product Account', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <input type="text" 
                           id="bexio_wc_product_account" 
                           name="bexio_wc_product_account" 
                           value="<?php echo esc_attr(get_option('bexio_wc_product_account', '3200')); ?>" 
                           class="small-text">
                    <p class="description"><?php _e('Account for product sales (default: 3200)', 'bexio-wc'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="bexio_wc_shipping_account"><?php _e('Shipping Account', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <input type="text" 
                           id="bexio_wc_shipping_account" 
                           name="bexio_wc_shipping_account" 
                           value="<?php echo esc_attr(get_option('bexio_wc_shipping_account', '3600')); ?>" 
                           class="small-text">
                    <p class="description"><?php _e('Account for shipping (default: 3600)', 'bexio-wc'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="bexio_wc_vat_code"><?php _e('VAT Code', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <input type="text" 
                           id="bexio_wc_vat_code" 
                           name="bexio_wc_vat_code" 
                           value="<?php echo esc_attr(get_option('bexio_wc_vat_code', 'UN81')); ?>" 
                           class="small-text">
                    <p class="description"><?php _e('VAT code (default: UN81 - Revenue NS 8.1%)', 'bexio-wc'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="bexio_wc_tax_id"><?php _e('Tax ID', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <input type="text" 
                           id="bexio_wc_tax_id" 
                           name="bexio_wc_tax_id" 
                           value="<?php echo esc_attr(get_option('bexio_wc_tax_id', '')); ?>" 
                           class="small-text">
                    <p class="description"><?php _e('Tax ID for invoices', 'bexio-wc'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label><?php _e('Bank Accounts', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <p>
                        <label>
                            <?php _e('Invoice & Twint:', 'bexio-wc'); ?>
                            <select name="bexio_wc_invoice_bank_id" id="bexio_wc_invoice_bank_id" class="regular-text">
                                <option value=""><?php _e('Select Bank Account', 'bexio-wc'); ?></option>
                                <?php
                                $selected_invoice = get_option('bexio_wc_invoice_bank_id');
                                $saved_banks = get_transient('bexio_wc_bank_accounts');
                                if ($saved_banks && is_array($saved_banks)) {
                                    foreach ($saved_banks as $bank) {
                                        printf(
                                            '<option value="%s" %s>%s - %s</option>',
                                            esc_attr($bank['id']),
                                            selected($selected_invoice, $bank['id'], false),
                                            esc_html($bank['name']),
                                            esc_html($bank['account_no'])
                                        );
                                    }
                                }
                                ?>
                            </select>
                        </label>
                    </p>
                    <p>
                        <label>
                            <?php _e('Credit Card:', 'bexio-wc'); ?>
                            <select name="bexio_wc_card_bank_id" id="bexio_wc_card_bank_id" class="regular-text">
                                <option value=""><?php _e('Select Bank Account', 'bexio-wc'); ?></option>
                                <?php
                                $selected_card = get_option('bexio_wc_card_bank_id');
                                if ($saved_banks && is_array($saved_banks)) {
                                    foreach ($saved_banks as $bank) {
                                        printf(
                                            '<option value="%s" %s>%s - %s</option>',
                                            esc_attr($bank['id']),
                                            selected($selected_card, $bank['id'], false),
                                            esc_html($bank['name']),
                                            esc_html($bank['account_no'])
                                        );
                                    }
                                }
                                ?>
                            </select>
                        </label>
                    </p>
                    <button type="button" id="fetch-accounts" class="button">
                        <?php _e('Fetch Bank Accounts', 'bexio-wc'); ?>
                    </button>
                    <span id="fetch-status"></span>
                    <p class="description">
                        <?php _e('Click to load your bank accounts from Bexio. Accounts are cached for 24 hours.', 'bexio-wc'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    private function render_order_settings() {
        $all_statuses = wc_get_order_statuses();
        $create_statuses = get_option('bexio_wc_create_on_status', array('processing', 'service-arrived'));
        $complete_statuses = get_option('bexio_wc_complete_on_status', array('shipped', 'abholbereit'));
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label><?php _e('Create Order in Bexio', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <p><?php _e('Select statuses that trigger order creation in Bexio:', 'bexio-wc'); ?></p>
                    <fieldset>
                        <?php foreach ($all_statuses as $status_key => $status_label): 
                            $status_key = str_replace('wc-', '', $status_key);
                        ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" 
                                       name="bexio_wc_create_on_status[]" 
                                       value="<?php echo esc_attr($status_key); ?>"
                                       <?php checked(in_array($status_key, (array)$create_statuses)); ?>>
                                <?php echo esc_html($status_label); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description">
                        <?php _e('When an order reaches these statuses, it will be created in Bexio.', 'bexio-wc'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label><?php _e('Complete Order & Create Invoice', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <p><?php _e('Select statuses that trigger invoice creation in Bexio:', 'bexio-wc'); ?></p>
                    <fieldset>
                        <?php foreach ($all_statuses as $status_key => $status_label): 
                            $status_key = str_replace('wc-', '', $status_key);
                        ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" 
                                       name="bexio_wc_complete_on_status[]" 
                                       value="<?php echo esc_attr($status_key); ?>"
                                       <?php checked(in_array($status_key, (array)$complete_statuses)); ?>>
                                <?php echo esc_html($status_label); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description">
                        <?php _e('When an order reaches these statuses, it will be completed and an invoice will be created in Bexio.', 'bexio-wc'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="bexio_wc_auto_send_pdfs"><?php _e('Auto-send PDFs', 'bexio-wc'); ?></label>
                </th>
                <td>
                    <label>
                        <input type="checkbox" 
                               id="bexio_wc_auto_send_pdfs" 
                               name="bexio_wc_auto_send_pdfs" 
                               value="yes" 
                               <?php checked(get_option('bexio_wc_auto_send_pdfs'), 'yes'); ?>>
                        <?php _e('Automatically send order and invoice PDFs to customers', 'bexio-wc'); ?>
                    </label>
                    <p class="description">
                        <?php _e('When enabled, PDFs will be automatically sent via email when orders and invoices are created in Bexio.', 'bexio-wc'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    private function render_migration_tab() {
        ?>
        <div style="max-width: 800px;">
            <h2><?php _e('Migrate Existing Orders', 'bexio-wc'); ?></h2>
            <p><?php _e('This tool will sync all existing WooCommerce orders to Bexio. This process may take a while for stores with many orders.', 'bexio-wc'); ?></p>
            
            <div class="notice notice-warning inline">
                <p>
                    <strong><?php _e('Important:', 'bexio-wc'); ?></strong>
                    <?php _e('Please ensure you have tested your connection and configured your accounting settings before starting the migration.', 'bexio-wc'); ?>
                </p>
            </div>
            
            <div id="migration-status" style="margin: 20px 0; padding: 15px; background: #f8f8f8; border-left: 4px solid #0073aa; display: none;">
                <h3><?php _e('Migration Progress', 'bexio-wc'); ?></h3>
                <div id="migration-progress-bar" style="width: 100%; height: 30px; background: #e0e0e0; border-radius: 3px; overflow: hidden; margin: 10px 0;">
                    <div id="migration-progress" style="width: 0%; height: 100%; background: #0073aa; transition: width 0.3s;"></div>
                </div>
                <p id="migration-message"><?php _e('Preparing migration...', 'bexio-wc'); ?></p>
                <div id="migration-details" style="margin-top: 15px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto; background: white; padding: 10px; border: 1px solid #ddd;"></div>
            </div>
            
            <p>
                <button type="button" id="start-migration" class="button button-primary button-large">
                    <?php _e('Start Migration', 'bexio-wc'); ?>
                </button>
                <button type="button" id="stop-migration" class="button button-large" style="display: none;">
                    <?php _e('Stop Migration', 'bexio-wc'); ?>
                </button>
            </p>
        </div>
        <?php
    }
    
    private function render_logs_tab() {
        global $wpdb;
        $table = $wpdb->prefix . 'bexio_sync_log';
        
        // Pagination
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Get total count
        $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $total_pages = ceil($total_logs / $per_page);
        
        // Get logs for current page
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        // Filter options
        $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
        $filter_type = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';
        
        if ($filter_status || $filter_type) {
            $where = array();
            if ($filter_status) {
                $where[] = $wpdb->prepare("sync_status = %s", $filter_status);
            }
            if ($filter_type) {
                $where[] = $wpdb->prepare("sync_type = %s", $filter_type);
            }
            $where_sql = implode(' AND ', $where);
            
            $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where_sql");
            $total_pages = ceil($total_logs / $per_page);
            
            $logs = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ));
        }
        ?>
        <div style="max-width: 1200px;">
            <h2><?php _e('Synchronization Logs', 'bexio-wc'); ?></h2>
            
            <!-- Filters -->
            <div style="margin-bottom: 15px;">
                <form method="get" action="">
                    <input type="hidden" name="page" value="bexio-integration">
                    <input type="hidden" name="tab" value="logs">
                    
                    <select name="filter_status">
                        <option value=""><?php _e('All Statuses', 'bexio-wc'); ?></option>
                        <option value="success" <?php selected($filter_status, 'success'); ?>><?php _e('Success', 'bexio-wc'); ?></option>
                        <option value="error" <?php selected($filter_status, 'error'); ?>><?php _e('Error', 'bexio-wc'); ?></option>
                    </select>
                    
                    <select name="filter_type">
                        <option value=""><?php _e('All Types', 'bexio-wc'); ?></option>
                        <option value="order" <?php selected($filter_type, 'order'); ?>><?php _e('Order', 'bexio-wc'); ?></option>
                        <option value="invoice" <?php selected($filter_type, 'invoice'); ?>><?php _e('Invoice', 'bexio-wc'); ?></option>
                        <option value="customer" <?php selected($filter_type, 'customer'); ?>><?php _e('Customer', 'bexio-wc'); ?></option>
                    </select>
                    
                    <button type="submit" class="button"><?php _e('Filter', 'bexio-wc'); ?></button>
                    <a href="?page=bexio-integration&tab=logs" class="button"><?php _e('Reset', 'bexio-wc'); ?></a>
                </form>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 80px;"><?php _e('Order ID', 'bexio-wc'); ?></th>
                        <th style="width: 100px;"><?php _e('Type', 'bexio-wc'); ?></th>
                        <th style="width: 100px;"><?php _e('Status', 'bexio-wc'); ?></th>
                        <th><?php _e('Message', 'bexio-wc'); ?></th>
                        <th style="width: 150px;"><?php _e('Date', 'bexio-wc'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 30px;">
                                <?php _e('No sync logs found.', 'bexio-wc'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo admin_url('post.php?post=' . $log->order_id . '&action=edit'); ?>" target="_blank">
                                        #<?php echo $log->order_id; ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-<?php 
                                        echo $log->sync_type === 'order' ? 'cart' : 
                                            ($log->sync_type === 'invoice' ? 'media-text' : 'admin-users'); 
                                    ?>"></span>
                                    <?php echo esc_html(ucfirst($log->sync_type)); ?>
                                </td>
                                <td>
                                    <?php if ($log->sync_status === 'success'): ?>
                                        <span style="color: #46b450;">
                                            <span class="dashicons dashicons-yes"></span>
                                            <?php _e('Success', 'bexio-wc'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #dc3232;">
                                            <span class="dashicons dashicons-no"></span>
                                            <?php _e('Error', 'bexio-wc'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <details>
                                        <summary style="cursor: pointer;">
                                            <?php 
                                            $message = esc_html($log->sync_message);
                                            echo strlen($message) > 100 ? substr($message, 0, 100) . '...' : $message;
                                            ?>
                                        </summary>
                                        <div style="margin-top: 10px; padding: 10px; background: #f5f5f5; border-left: 3px solid #0073aa;">
                                            <?php echo nl2br(esc_html($log->sync_message)); ?>
                                        </div>
                                    </details>
                                </td>
                                <td>
                                    <?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $log->created_at)); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php printf(__('%s items', 'bexio-wc'), number_format_i18n($total_logs)); ?>
                        </span>
                        <span class="pagination-links">
                            <?php
                            $base_url = add_query_arg(array(
                                'page' => 'bexio-integration',
                                'tab' => 'logs',
                                'filter_status' => $filter_status,
                                'filter_type' => $filter_type,
                            ), admin_url('admin.php'));
                            
                            // First page
                            if ($current_page > 1) {
                                echo '<a class="first-page button" href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '">&laquo;</a> ';
                                echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $current_page - 1, $base_url)) . '">&lsaquo;</a> ';
                            } else {
                                echo '<span class="tablenav-pages-navspan button disabled">&laquo;</span> ';
                                echo '<span class="tablenav-pages-navspan button disabled">&lsaquo;</span> ';
                            }
                            
                            // Current page
                            echo '<span class="paging-input">';
                            echo '<span class="tablenav-paging-text">';
                            printf(__('%1$s of %2$s', 'bexio-wc'), $current_page, $total_pages);
                            echo '</span>';
                            echo '</span> ';
                            
                            // Last page
                            if ($current_page < $total_pages) {
                                echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $current_page + 1, $base_url)) . '">&rsaquo;</a> ';
                                echo '<a class="last-page button" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '">&raquo;</a>';
                            } else {
                                echo '<span class="tablenav-pages-navspan button disabled">&rsaquo;</span> ';
                                echo '<span class="tablenav-pages-navspan button disabled">&raquo;</span>';
                            }
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Clear logs button -->
            <div style="margin-top: 20px;">
                <button type="button" id="clear-logs" class="button button-secondary" 
                        onclick="if(confirm('<?php _e('Are you sure you want to clear all logs? This action cannot be undone.', 'bexio-wc'); ?>')) { 
                            jQuery.post(ajaxurl, {
                                action: 'bexio_clear_logs',
                                nonce: '<?php echo wp_create_nonce('bexio-clear-logs'); ?>'
                            }, function(response) {
                                if(response.success) {
                                    location.reload();
                                } else {
                                    alert(response.data.message);
                                }
                            });
                        }">
                    <?php _e('Clear All Logs', 'bexio-wc'); ?>
                </button>
            </div>
        </div>
        <?php
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'woocommerce_page_bexio-integration') {
            return;
        }
        
        wp_enqueue_script(
            'bexio-admin',
            BEXIO_WC_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            time(),
            true
        );
        
        wp_localize_script('bexio-admin', 'bexioAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => get_rest_url(),
            'nonce' => wp_create_nonce('wp_rest'),
            'i18n' => array(
                'testing' => __('Testing connection...', 'bexio-wc'),
                'success' => __('Connection successful!', 'bexio-wc'),
                'failed' => __('Connection failed. Please check your credentials.', 'bexio-wc'),
                'fetching' => __('Fetching bank accounts...', 'bexio-wc'),
                'fetched' => __('Bank accounts loaded successfully!', 'bexio-wc'),
                'fetch_failed' => __('Failed to fetch bank accounts.', 'bexio-wc'),
                'migrating' => __('Migration in progress...', 'bexio-wc'),
                'migration_complete' => __('Migration completed!', 'bexio-wc'),
                'migration_stopped' => __('Migration stopped.', 'bexio-wc'),
                'confirm_stop' => __('Are you sure you want to stop the migration?', 'bexio-wc'),
            )
        ));
        
        // Add admin styles
        wp_add_inline_style('wp-admin', '
            .bexio-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
            }
            .bexio-status-success {
                background: #d4edda;
                color: #155724;
            }
            .bexio-status-error {
                background: #f8d7da;
                color: #721c24;
            }
            .bexio-status-pending {
                background: #fff3cd;
                color: #856404;
            }
            #connection-status,
            #fetch-status {
                margin-left: 10px;
                font-weight: 600;
            }
            #connection-status.success,
            #fetch-status.success {
                color: #46b450;
            }
            #connection-status.error,
            #fetch-status.error {
                color: #dc3232;
            }
            #migration-details {
                white-space: pre-wrap;
            }
        ');
    }
    
    public function ajax_test_connection() {
        check_ajax_referer('wp_rest', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Insufficient permissions.', 'bexio-wc')
            ));
        }
        
        $api = Bexio_WC_API::get_instance();
        $result = $api->test_connection();
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Connection successful!', 'bexio-wc')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Connection failed. Please check your API credentials.', 'bexio-wc')
            ));
        }
    }
    
    public function ajax_fetch_accounts() {
        check_ajax_referer('wp_rest', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('Insufficient permissions.', 'bexio-wc')
            ));
        }
        
        $api = Bexio_WC_API::get_instance();
        $banks = $api->get_banks();
        
        if ($banks && is_array($banks)) {
            // Cache the results for 24 hours
            set_transient('bexio_wc_bank_accounts', $banks, DAY_IN_SECONDS);
            
            wp_send_json_success(array(
                'accounts' => $banks,
                'message' => __('Bank accounts fetched successfully!', 'bexio-wc')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Failed to fetch bank accounts. Please check your connection.', 'bexio-wc')
            ));
        }
    }
}