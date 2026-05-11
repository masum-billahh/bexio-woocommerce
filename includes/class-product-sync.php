<?php
/**
 * Bexio Product Sync
 * Syncs WooCommerce products/variations to Bexio articles.
 *
 * ── TEST: sync one product ────────────────────────────────────────────────────
 *   /wp-admin/?bexio_test_product_sync=<product_or_variation_id>
 *
 * ── TEST: push KbPositionArticle positions onto an existing Bexio order ──────
 *   /wp-admin/?bexio_test_article_positions=<wc_order_id>
 *
 *   This will:
 *     1. Load the WC order.
 *     2. For each line item, look up _bexio_product_id on the variation/product.
 *     3. Build KbPositionArticle entries (falls back to KbPositionCustom when
 *        no bexio article ID is stored yet).
 *     4. DELETE all existing kb_position_custom positions on the Bexio order
 *        and POST the new ones, then dump the full result.
 *
 * ── PRODUCTION USE ────────────────────────────────────────────────────────────
 *   // Sync article:
 *   Bexio_WC_Product_Sync::get_instance()->sync_product( $product );
 *
 *   // Build positions array for an order (use instead of KbPositionCustom):
 *   $positions = Bexio_WC_Product_Sync::get_instance()->build_article_positions( $order );
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Bexio_WC_Product_Sync {

    private static $instance = null;
    private $api;

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api = Bexio_WC_API::get_instance();

        // ------------------------------------------------------------------
        // TEST HOOKS – remove or gate behind capability check before going live.
        // ------------------------------------------------------------------
        add_action( 'admin_init', [ $this, 'maybe_run_test' ] );
        add_action( 'admin_init', [ $this, 'maybe_run_order_position_test' ] );
    }

    // -------------------------------------------------------------------------
    // Test trigger – single product sync
    // -------------------------------------------------------------------------

    public function maybe_run_test() {
        if ( ! isset( $_GET['bexio_test_product_sync'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $product_id = intval( $_GET['bexio_test_product_sync'] );
        $product    = wc_get_product( $product_id );

        if ( ! $product ) {
            wp_die( 'Product not found: ' . $product_id );
        }

        $result = $this->sync_product( $product );

        echo '<pre>';
        echo '<strong>Product:</strong> ' . esc_html( $product->get_name() ) . ' (ID: ' . $product_id . ")\n\n";
        echo '<strong>Result:</strong>' . "\n";
        print_r( $result );
        echo '</pre>';
        wp_die( '— Bexio product sync test complete —' );
    }

    // -------------------------------------------------------------------------
    // Test trigger – push KbPositionArticle positions onto a Bexio order
    // -------------------------------------------------------------------------

    /**
     * Visit: /wp-admin/?bexio_test_article_positions=<wc_order_id>
     *
     * The test will:
     *   - Load the WC order and its stored _bexio_order_id
     *   - Build positions using KbPositionArticle where possible
     *   - Wipe existing kb_position_custom entries on the Bexio order
     *   - POST the new positions one by one
     *   - Dump everything to screen
     */
    public function maybe_run_order_position_test() {
        if ( ! isset( $_GET['bexio_test_article_positions'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $wc_order_id = intval( $_GET['bexio_test_article_positions'] );
        $order       = wc_get_order( $wc_order_id );

        if ( ! $order ) {
            wp_die( 'WC Order not found: ' . $wc_order_id );
        }

        $bexio_order_id = $order->get_meta( '_bexio_order_id', true );
        if ( ! $bexio_order_id ) {
            wp_die( 'No _bexio_order_id found on WC order ' . $wc_order_id . '. Sync the order to Bexio first.' );
        }

        echo '<pre>';
        echo "<strong>WC Order:</strong> {$wc_order_id}  |  <strong>Bexio Order:</strong> {$bexio_order_id}\n\n";

        // 1. Build the new positions
        $positions = $this->build_article_positions( $order );
        echo "<strong>Positions to send (" . count( $positions ) . "):</strong>\n";
        print_r( $positions );
        echo "\n";

        // 2. Delete existing kb_position_custom entries on the Bexio order
        $existing = $this->api->request( 'kb_order/' . $bexio_order_id . '/kb_position_custom' );
        if ( is_array( $existing ) ) {
            foreach ( $existing as $pos ) {
                $del = $this->api->request(
                    'kb_order/' . $bexio_order_id . '/kb_position_custom/' . $pos['id'],
                    'DELETE'
                );
                echo "Deleted existing position ID {$pos['id']}: ";
                print_r( $del );
                echo "\n";
            }
        }

        // 3. POST each new position
        $responses = [];
        foreach ( $positions as $position ) {
            // _endpoint is our internal routing key — strip it before sending
            $endpoint_type = isset( $position['_endpoint'] ) ? $position['_endpoint'] : 'kb_position_custom';
            unset( $position['_endpoint'] );

            $resp = $this->api->request(
                'kb_order/' . $bexio_order_id . '/' . $endpoint_type,
                'POST',
                $position
            );
            $responses[] = [
                'endpoint' => $endpoint_type,
                'sent'     => $position,
                'response' => $resp,
            ];
        }

        echo "<strong>API Responses:</strong>\n";
        print_r( $responses );
        echo '</pre>';
        wp_die( '— Bexio article position test complete —' );
    }

    // -------------------------------------------------------------------------
    // Main sync method
    // -------------------------------------------------------------------------

    /**
     * Sync a single WooCommerce product or variation to Bexio.
     *
     * @param  WC_Product|WC_Product_Variation $product
     * @return array|WP_Error  ['bexio_product' => [...]] on success, WP_Error on failure.
     */
    public function sync_product( $product ) {

        // ---- Resolve the actual variation ID for meta lookups ---------------
        $variation_id = $product->get_id();

        // ---- OEM price (goes into intern_description) -----------------------
        $oem_price = get_post_meta( $variation_id, '_oem_price_field', true );

        // Build a clean description string.
        // If there is no OEM price we still send an empty string so the field
        // is always present in the payload.
        $intern_description = '';
        if ( $oem_price ) {
            $intern_description = 'OEM Price: ' . $oem_price;
        }

        // ---- Product name ---------------------------------------------------
        $product_name = $product->get_name();
        if ( $product instanceof WC_Product_Variation ) {
            $formatted = wc_get_formatted_variation( $product, true, false );
            if ( $formatted ) {
                $product_name .= ' - ' . $formatted;
            }
        }

        // ---- Physical / virtual / downloadable ------------------------------
        $is_virtual      = $product->is_virtual();
        $is_downloadable = $product->is_downloadable();
        $is_physical     = ! $is_virtual && ! $is_downloadable;

        // ---- Stock setting --------------------------------------------------
        $is_stock = get_option( 'bexio_wc_is_stock', false ); // adjust option key as needed

        // ---- Look up existing Bexio article ---------------------------------
        $bexio_product = null;

        if ( $product->get_sku() ) {
            $bexio_product = $this->get_article_by_sku( $product->get_sku() );
        }

        // Fallback: stored bexio article ID on the product
        if ( ! $bexio_product ) {
            $stored_bexio_id = get_post_meta( $variation_id, '_bexio_product_id', true );
            if ( $stored_bexio_id ) {
                $bexio_product = $this->api->request( 'article/' . intval( $stored_bexio_id ) );
            }
        }

        // ---- BUILD payload --------------------------------------------------

        $common = [
            'intern_code'        => $product->get_sku() ?: null,
            'intern_name'        => $product_name,
            'intern_description' => $intern_description, // ← OEM price lives here
            'sale_price'         => $product->get_price() ?: null,
            'purchase_price'     => get_post_meta( $variation_id, '_purchase_price', true ) ?: null,
            'currency_id'        => get_option( 'bexio_wc_currency_id', 1 ),
            'stock_nr'           => $product->get_stock_quantity(),
            'width'              => $product->get_width()  ? round( $product->get_width()  * 10 )   : null, // cm → mm
            'height'             => $product->get_height() ? round( $product->get_height() * 10 )   : null,
            'weight'             => $product->get_weight() ? round( $product->get_weight() * 1000 ) : null, // kg → g
        ];

        // ---- CREATE or UPDATE -----------------------------------------------

        if ( $bexio_product && isset( $bexio_product['id'] ) ) {

            // UPDATE existing article
            $result = $this->api->request(
                'article/' . $bexio_product['id'],
                'POST',
                $common
            );

        } else {

            // CREATE new article
            $create_payload = array_merge( [
                'user_id'          => get_option( 'bexio_wc_default_user_id', 1 ),
                'article_type_id'  => $is_physical ? 1 : 2, // 1 = physical, 2 = service
                'contact_id'       => null,
                'deliverer_code'   => null,
                'deliverer_name'   => null,
                'deliverer_description' => null,
                'purchase_total'   => null,
                'sale_total'       => null,
                'tax_income_id'    => null,
                'tax_expense_id'   => null,
                'unit_id'          => null,
                'is_stock'         => (bool) $is_stock,
                'stock_id'         => null,
                'stock_place_id'   => null,
                'stock_min_nr'     => 0,
                'volume'           => null,
                'html_text'        => null,
                'remarks'          => null,
                'delivery_price'   => null,
                'article_group_id' => null,
                'account_id'       => null,
                'expense_account_id' => null,
            ], $common );

            $result = $this->api->request( 'article', 'POST', $create_payload );
        }

        // ---- Error handling -------------------------------------------------

        if ( ! $result || isset( $result['error_code'] ) ) {
            return new WP_Error(
                'bexio_product_sync_failed',
                'Bexio article sync failed for product ID ' . $variation_id,
                $result
            );
        }

        // ---- Persist the Bexio article ID on the WC product -----------------
        update_post_meta( $variation_id, '_bexio_product_id', $result['id'] );

        return [ 'bexio_product' => $result ];
    }

    // -------------------------------------------------------------------------
    // Public: build positions for an order using KbPositionArticle where possible
    // -------------------------------------------------------------------------

    /**
     * Build a Bexio positions array for a WC order.
     *
     * For each line item:
     *   - If the product/variation has a stored _bexio_product_id → KbPositionArticle
     *   - Otherwise                                               → KbPositionCustom (fallback)
     * Shipping and fee items always use KbPositionCustom.
     *
     * Drop-in replacement for prepare_order_positions() in the order sync class.
     *
     * @param  WC_Order $order
     * @return array
     */
    public function build_article_positions( WC_Order $order ) {
        $positions  = [];
        $order_date = $order->get_date_paid();

        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product    = $item->get_product();
            $quantity   = $item->get_quantity();
            $unit_price = round( ( $item->get_total() + $item->get_total_tax() ) / $quantity, 2 );
            $text       = ucfirst( $item->get_name() );
            $tax_id     = 28; // adjust if needed

            $bexio_article_id = $this->get_bexio_article_id( $item, $product );

            if ( $bexio_article_id ) {
                // ── KbPositionArticle ──────────────────────────────────────
                // endpoint: kb_order/{id}/kb_position_article
                // "type" and "parent_id" must NOT be in the body
                $positions[] = [
                    '_endpoint'          => 'kb_position_article', // internal routing key, stripped before sending
                    'amount'             => (string) $quantity,
                    'unit_id'            => 1,
                    'account_id'         => 149,
                    'tax_id'             => $tax_id,
                    'text'               => $text,
                    'unit_price'         => (string) $unit_price,
                    'discount_in_percent'=> '0',
                    'article_id'         => (int) $bexio_article_id,
                    'is_optional'        => false,
                ];
            } else {
                // ── KbPositionCustom fallback ──────────────────────────────
                // endpoint: kb_order/{id}/kb_position_custom
                // "type" must NOT be in the body
                $positions[] = [
                    '_endpoint'          => 'kb_position_custom', // internal routing key, stripped before sending
                    'amount'             => $quantity,
                    'unit_id'            => 1,
                    'account_id'         => 149,
                    'tax_id'             => $tax_id,
                    'text'               => $text,
                    'unit_price'         => $unit_price,
                    'discount_in_percent'=> 0,
                ];
            }
        }

        // ── Shipping ─────────────────────────────────────────────────────────
        $shipping_items = $order->get_items( 'shipping' );
        if ( ! empty( $shipping_items ) ) {
            $shipping_title = '';
            foreach ( $shipping_items as $si ) {
                $shipping_title = $si->get_name();
                break;
            }
            $shipping_total = $order->get_shipping_total();
            $shipping_tax   = $order->get_shipping_tax();

            $positions[] = [
                '_endpoint'          => 'kb_position_custom',
                'amount'             => 1,
                'unit_id'            => 1,
                'account_id'         => 151,
                'tax_id'             => 28,
                'text'               => $shipping_title ?: __( 'Shipping', 'bexio-wc' ),
                'unit_price'         => round( $shipping_total + $shipping_tax, 2 ),
                'discount_in_percent'=> 0,
            ];
        }

        // ── Fee items ─────────────────────────────────────────────────────────
        foreach ( $order->get_items( 'fee' ) as $fee ) {
            $fee_total = (float) $fee->get_total();
            $fee_tax   = (float) $fee->get_total_tax();

            if ( $fee_total > 0 ) {
                $positions[] = [
                    '_endpoint'          => 'kb_position_custom',
                    'amount'             => 1,
                    'unit_id'            => 1,
                    'account_id'         => 149,
                    'tax_id'             => 28,
                    'text'               => $fee->get_name(),
                    'unit_price'         => round( $fee_total + $fee_tax, 2 ),
                    'discount_in_percent'=> 0,
                ];
            } elseif ( $fee_total < 0 ) {
                $positions[] = [
                    '_endpoint'     => 'kb_position_discount',
                    'text'          => $fee->get_name(),
                    'is_percentual' => false,
                    'value'         => (string) abs( $fee_total + $fee_tax ),
                ];
            }
        }

        return $positions;
    }

    /**
     * Resolve the stored Bexio article ID for a given order item / product.
     * Checks the variation first, then the parent product.
     *
     * @param  WC_Order_Item_Product      $item
     * @param  WC_Product|false           $product
     * @return int|null
     */
    public function get_bexio_article_id( $item, $product ) {
        if ( ! $product ) {
            return null;
        }

        // Check the concrete product/variation ID first
        $id = (int) get_post_meta( $product->get_id(), '_bexio_product_id', true );
        if ( $id ) {
            return $id;
        }

        // For variations: also check the parent
        if ( $product->is_type( 'variation' ) ) {
            $id = (int) get_post_meta( $product->get_parent_id(), '_bexio_product_id', true );
            if ( $id ) {
                return $id;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Find a Bexio article by its intern_code (SKU).
     *
     * @param  string $sku
     * @return array|null
     */
    private function get_article_by_sku( $sku ) {
        $results = $this->api->request( 'article/search', 'POST', [
            [
                'field'    => 'intern_code',
                'value'    => $sku,
                'criteria' => '=',
            ],
        ] );

        return ( ! empty( $results ) && is_array( $results ) ) ? $results[0] : null;
    }
}

// Boot the singleton so the admin_init hook registers.
Bexio_WC_Product_Sync::get_instance();