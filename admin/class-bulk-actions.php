<?php
class Bexio_WC_Bulk_Actions {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_filter('bulk_actions-edit-shop_order', array($this, 'add_bulk_actions'));
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handle_bulk_actions'), 10, 3);
        add_action('admin_notices', array($this, 'bulk_action_notices'));
    }
    
    public function add_bulk_actions($actions) {
        $actions['bexio_sync_orders'] = __('Sync to Bexio', 'bexio-wc');
        $actions['bexio_print_order_pdfs'] = __('Print Order PDFs', 'bexio-wc');
        $actions['bexio_print_invoice_pdfs'] = __('Print Invoice PDFs', 'bexio-wc');
        return $actions;
    }
    
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action === 'bexio_sync_orders') {
            $synced = 0;
            $order_sync = Bexio_WC_Order_Sync::get_instance();
            
            foreach ($post_ids as $post_id) {
                $order = wc_get_order($post_id);
                if ($order && $order_sync->create_bexio_order($order)) {
                    $synced++;
                }
            }
            
            $redirect_to = add_query_arg('bexio_synced', $synced, $redirect_to);
        }
        
        if ($action === 'bexio_print_order_pdfs') {
            $this->bulk_print_pdfs($post_ids, 'order');
            exit;
        }
        
        if ($action === 'bexio_print_invoice_pdfs') {
            $this->bulk_print_pdfs($post_ids, 'invoice');
            exit;
        }
        
        return $redirect_to;
    }
    
    private function bulk_print_pdfs($order_ids, $type) {
        require_once(ABSPATH . 'wp-admin/includes/class-pclzip.php');
        
        $pdf_handler = Bexio_WC_PDF_Handler::get_instance();
        $zip_file = tempnam(sys_get_temp_dir(), 'bexio_pdfs_');
        $zip = new PclZip($zip_file);
        
        $files = array();
        
        foreach ($order_ids as $order_id) {
            if ($type === 'order') {
                $pdf = $pdf_handler->get_order_pdf($order_id);
            } else {
                $pdf = $pdf_handler->get_invoice_pdf($order_id);
            }
            
            if ($pdf && file_exists($pdf)) {
                $files[] = $pdf;
            }
        }
        
        if (!empty($files)) {
            $zip->create($files, PCLZIP_OPT_REMOVE_ALL_PATH);
            
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="bexio-' . $type . 's-' . date('Y-m-d') . '.zip"');
            header('Content-Length: ' . filesize($zip_file));
            readfile($zip_file);
            
            unlink($zip_file);
        }
    }
    
    public function bulk_action_notices() {
        if (!empty($_REQUEST['bexio_synced'])) {
            $synced = intval($_REQUEST['bexio_synced']);
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                sprintf(__('%d orders synced to Bexio.', 'bexio-wc'), $synced)
            );
        }
    }
}