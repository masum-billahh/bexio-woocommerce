<?php
/**
 * Quote / Offer Sync Handler
 *
 * Creates a Bexio quote (kb_offer) when a WooCommerce order enters the
 * "bexio-offers" status, fetches the PDF, and emails it to the customer.
 *
 * Mirrors the conventions used by Bexio_WC_Order_Sync / Bexio_WC_PDF_Handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bexio_WC_Quote_Sync {

	private static $instance = null;
	private $api;

	/* ------------------------------------------------------------------ */
	/*  Singleton                                                           */
	/* ------------------------------------------------------------------ */

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->api = Bexio_WC_API::get_instance();
		$this->init_hooks();
	}

	/* ------------------------------------------------------------------ */
	/*  Hooks                                                               */
	/* ------------------------------------------------------------------ */

	private function init_hooks() {
		// Trigger quote creation whenever an order enters "bexio-offers".
		add_action( 'woocommerce_order_status_bexio-offer', array( $this, 'handle_offers_status' ), 10, 2 );

		// Allow re-sending the quote email from the order action dropdown.
		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_actions' ) );
		add_action( 'woocommerce_order_action_send_bexio_quote_pdf', array( $this, 'action_send_quote_pdf' ) );

		// Download quote PDF from admin meta box / orders list.
		add_action( 'wp_ajax_bexio_download_quote_pdf', array( $this, 'ajax_download_quote_pdf' ) );

		// Cancel / delete quote when the order is cancelled / trashed / deleted.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'maybe_cancel_bexio_quote' ), 10, 2 );
		add_action( 'woocommerce_before_trash_order',     array( $this, 'handle_order_trash_delete' ), 10, 2 );
		add_action( 'woocommerce_before_delete_order',    array( $this, 'handle_order_trash_delete' ), 10, 2 );

		// Extend the existing Bexio Documents meta box with quote info.
		add_action( 'bexio_wc_after_pdf_meta_box', array( $this, 'render_quote_meta_box_section' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Status handler                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Called automatically when order status changes to "bexio-offers".
	 *
	 * @param int      $order_id
	 * @param WC_Order $order
	 */
	public function handle_offers_status( $order_id, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		// If a quote already exists for this order, skip creation but
		// allow re-sending (idempotent behaviour).
		$existing_quote_id = (int) $order->get_meta( '_bexio_quote_id', true );
		if ( $existing_quote_id ) {
			$this->log( $order_id, 'quote_create', 'skipped', 'Quote already exists in Bexio: ' . $existing_quote_id );
			// Optionally re-send the PDF email.
			$this->send_quote_pdf( $order, $existing_quote_id );
			return;
		}

		$quote_id = $this->create_bexio_quote( $order );

		if ( $quote_id ) {
			$this->send_quote_pdf( $order, $quote_id );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Quote creation                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Create a Bexio quote from a WooCommerce order.
	 *
	 * @param  WC_Order $order
	 * @return int|false  Bexio quote ID on success, false on failure.
	 */
	public function create_bexio_quote( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return false;
		}

		try {
			// 1. Sync / create the customer contact.
			$customer_sync = Bexio_WC_Customer_Sync::get_instance();
			$contact_id    = $customer_sync->sync_customer( $order );

			if ( ! $contact_id ) {
				throw new Exception( 'Failed to create/get customer contact for quote' );
			}

			// 2. Build the quote payload.
			$quote_data = $this->prepare_quote_data( $order, $contact_id );

			// 3. POST to Bexio.
			$bexio_quote = $this->api->create_quote( $quote_data );

			if ( ! $bexio_quote || ! isset( $bexio_quote['id'] ) ) {
				throw new Exception( 'Failed to create quote in Bexio' );
			}

			$quote_id = $bexio_quote['id'];

			// 4. Issue the quote so it gets a proper document number and PDF.
			$this->api->issue_quote( $quote_id );

			// 5. Persist meta.
			$order->update_meta_data( '_bexio_quote_id', $quote_id );
			$order->update_meta_data( '_bexio_contact_id', $contact_id );
			$order->save();

			$this->log( $order->get_id(), 'quote_create', 'success', 'Quote created: ' . $quote_id, $quote_id );

			return $quote_id;

		} catch ( Exception $e ) {
			$this->log( $order->get_id(), 'quote_create', 'error', $e->getMessage() );
			return false;
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Quote data / positions                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Build the full quote payload for the Bexio API.
	 */
	private function prepare_quote_data( $order, $contact_id ) {
		$order_date      = $order->get_date_created();
		$order_reference = $this->resolve_order_reference( $order );

		$data = array(
			'document_nr'     => $order_reference,
			'contact_id'      => $contact_id,
			'user_id'         => 1,
			'language_id'     => $this->get_language_id( $order ),
			'bank_account_id' => $this->get_bank_account_id( $order->get_payment_method() ),
			'currency_id'     => $this->get_currency_id( $order->get_currency() ),
			'mwst_type'       => 0,
			'mwst_is_net'     => false,
			'is_valid_from'   => $order_date ? $order_date->date( 'Y-m-d' ) : date( 'Y-m-d' ),
			'is_valid_until'     => date( 'Y-m-d', strtotime( '+30 days' ) ),
			'title'           => sprintf( __( 'Offer #%s', 'bexio-wc' ), $order_reference ),
			'header'          => $this->get_quote_header( $order ),
			'footer'          => $this->get_quote_footer( $order ),
			'api_reference'   => 'wc_offer_' . $order->get_id(),
			'positions'       => $this->prepare_quote_positions( $order ),
		);

		// Manual billing address when billing ≠ shipping.
		$billing  = $order->get_address( 'billing' );
		$shipping = $order->get_address( 'shipping' );
		$fields   = array( 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
		$is_diff  = false;
		foreach ( $fields as $f ) {
			if ( ( $billing[ $f ] ?? '' ) !== ( $shipping[ $f ] ?? '' ) ) {
				$is_diff = true;
				break;
			}
		}

		if ( $is_diff ) {
			$lines = array();
			if ( ! empty( $billing['company'] ) )    $lines[] = $billing['company'];
			$full_name = trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) );
			if ( $full_name )                         $lines[] = $full_name;
			if ( ! empty( $billing['address_1'] ) )  $lines[] = $billing['address_1'];
			if ( ! empty( $billing['address_2'] ) )  $lines[] = $billing['address_2'];
			$pc_city = trim( ( $billing['postcode'] ?? '' ) . ' ' . ( $billing['city'] ?? '' ) );
			if ( $pc_city )                           $lines[] = $pc_city;
			if ( ! empty( $billing['state'] ) )       $lines[] = $billing['state'];

			$data['contact_address_manual'] = implode( "\n", $lines );
		}

		return $data;
	}

	/**
	 * Build the positions array for the quote — products + shipping + fees + discounts.
	 */
	private function prepare_quote_positions( $order ) {
		$positions    = array();
		$current_lang = function_exists( 'icl_object_id' ) ? apply_filters( 'wpml_current_language', null ) : false;
		$order_lang   = $order->get_meta( 'wpml_language', true );

		if ( $order_lang && function_exists( 'icl_object_id' ) ) {
			do_action( 'wpml_switch_language', $order_lang );
		}

		// ── Products ──────────────────────────────────────────────────────
		foreach ( $order->get_items() as $item ) {
			$product   = $item->get_product();
			$qty       = $item->get_quantity();
			$unit_price = round( ( $item->get_total() + $item->get_total_tax() ) / $qty, 2 );
			$text       = ucfirst( $item->get_name() );

			$positions[] = array(
				'type'                 => 'KbPositionCustom',
				'amount'               => $qty,
				'unit_id'              => 1,
				'account_id'           => 149,
				'tax_id'               => 28,
				'text'                 => $text,
				'unit_price'           => $unit_price,
				'discount_in_percent'  => 0,
			);

			// Optional original/RRP price note.
			if ( $product ) {
				$oem_price = get_post_meta( $product->get_id(), '_oem_price_field', true );
				if ( is_numeric( $oem_price ) && (float) $oem_price > 0 ) {
					$oem_price_fmt = wc_format_decimal( (float) $oem_price, wc_get_price_decimals() );
					$positions[]   = array(
						'type' => 'KbPositionText',
						'text' => sprintf(
							__( 'Originalpreis <del>%s</del> ', 'bexio-wc' ),
							wc_price( $oem_price_fmt )
						),
					);
				}
			}
		}

		// ── Shipping ─────────────────────────────────────────────────────
		$shipping_items = $order->get_items( 'shipping' );
		if ( ! empty( $shipping_items ) ) {
			$shipping_title = '';
			foreach ( $shipping_items as $s_item ) {
				$shipping_title = $s_item->get_name();
				break;
			}
			if ( empty( $shipping_title ) ) {
				$shipping_title = __( 'Shipping', 'bexio-wc' );
			}

			$positions[] = array(
				'type'                => 'KbPositionCustom',
				'amount'              => 1,
				'unit_id'             => 1,
				'account_id'          => 151,
				'tax_id'              => 28,
				'text'                => $shipping_title,
				'unit_price'          => round( $order->get_shipping_total() + $order->get_shipping_tax(), 2 ),
				'discount_in_percent' => 0,
			);
		}

		// ── Fees ─────────────────────────────────────────────────────────
		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$fee_total = (float) $fee->get_total();
			$fee_tax   = (float) $fee->get_total_tax();

			if ( $fee_total > 0 ) {
				$positions[] = array(
					'type'                => 'KbPositionCustom',
					'amount'              => 1,
					'unit_id'             => 1,
					'account_id'          => 149,
					'tax_id'              => 28,
					'text'                => $fee->get_name(),
					'unit_price'          => round( $fee_total + $fee_tax, 2 ),
					'discount_in_percent' => 0,
				);
			} elseif ( $fee_total < 0 ) {
				$positions[] = array(
					'type'          => 'KbPositionDiscount',
					'text'          => $fee->get_name(),
					'is_percentual' => false,
					'value'         => (string) abs( $fee_total + $fee_tax ),
				);
			}
		}

		// ── Coupon discounts ──────────────────────────────────────────────
		foreach ( $order->get_items( 'coupon' ) as $coupon ) {
			$discount_amount = (float) $coupon->get_discount();
			if ( $discount_amount <= 0 ) {
				continue;
			}
			$positions[] = array(
				'type'          => 'KbPositionDiscount',
				'text'          => $coupon->get_name(),
				'is_percentual' => false,
				'value'         => (string) $discount_amount,
			);
		}

		if ( $current_lang && function_exists( 'icl_object_id' ) ) {
			do_action( 'wpml_switch_language', $current_lang );
		}

		return $positions;
	}

	/* ------------------------------------------------------------------ */
	/*  PDF + email                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Fetch the quote PDF from Bexio, save it locally, and email it to
	 * the customer.
	 *
	 * @param  WC_Order $order
	 * @param  int      $quote_id
	 * @return bool
	 */
	public function send_quote_pdf( $order, $quote_id = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return false;
		}

		if ( ! $quote_id ) {
			$quote_id = (int) $order->get_meta( '_bexio_quote_id', true );
		}
		if ( ! $quote_id ) {
			$this->log( $order->get_id(), 'quote_email', 'error', 'No quote ID — cannot send PDF' );
			return false;
		}

		// Guard: skip if already sent (unless forced via action).
		$force    = apply_filters( 'bexio_wc_force_send_email', false );
		$was_sent = $order->get_meta( '_bexio_quote_pdf_sent' );
		if ( $was_sent && ! $force ) {
			$order->add_order_note( __( 'Duplicate email — quote PDF was sent before', 'bexio-wc' ) );
			return false;
		}

		$pdf_path = $this->get_quote_pdf( $order->get_id(), $quote_id );
		if ( ! $pdf_path || ! file_exists( $pdf_path ) ) {
			$this->log( $order->get_id(), 'quote_email', 'error', 'Could not retrieve quote PDF from Bexio' );
			return false;
		}

		$order_reference = $this->resolve_order_reference( $order );
		$to              = $order->get_meta('_shipping_email', true, 'edit');
		$creator_id 	 = $order->get_meta('_order_creator_id');

		$cc = '';
		if ($creator_id) {
			$creator = get_user_by('ID', $creator_id);
			if ($creator) {
				$cc = $creator->user_email;
			}
		}

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		if ($cc) {
			$headers[] = 'Cc: ' . $cc;
		}

		// Allow overriding the recipient (e.g. for migrated orders).
		$to = apply_filters( 'bexio_wc_quote_email_recipient', $to, $order );

		$subject = sprintf(
			__( 'Ihre Offerte #%s', 'bexio-wc' ),
			$order_reference
		);

		$message     = $this->get_quote_email_template( $order );
		$attachments = array( $pdf_path );

		$mailer = WC()->mailer();
		$sent   = $mailer->send(
			$to,
			$subject,
			$message,
			$headers,
			$attachments
		);

		if ( $sent ) {
			$order->add_order_note(
				__( 'Quote PDF sent to customer', 'bexio-wc' ) . ' | to: ' . $to
			);
			$order->update_meta_data( '_bexio_quote_pdf_sent', current_time( 'mysql' ) );
			$order->save();

			// Mark as sent in Bexio.
			$this->api->mark_quote_as_sent( $quote_id );

			//$this->log( $order->get_id(), 'quote_email', 'success', 'Quote PDF emailed to: ' . $to, $quote_id );
		} else {
			$this->log( $order->get_id(), 'quote_email', 'error', 'WC mailer returned false for: ' . $to );
		}

		return $sent;
	}

	/**
	 * Fetch and cache the quote PDF locally.
	 *
	 * @param  int      $order_id
	 * @param  int|null $quote_id
	 * @return string|false  Absolute filepath or false on failure.
	 */
	public function get_quote_pdf( $order_id, $quote_id = null ) {
		if ( ! $quote_id ) {
			$order    = wc_get_order( $order_id );
			$quote_id = $order ? (int) $order->get_meta( '_bexio_quote_id' ) : 0;
		}
		if ( ! $quote_id ) {
			return false;
		}

		$pdf_content = $this->api->get_quote_pdf( $quote_id );
		if ( ! $pdf_content ) {
			return false;
		}

		$upload    = wp_upload_dir();
		$dir       = $upload['basedir'] . '/bexio-pdfs';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/.htaccess', 'deny from all' );
		}

		$filename = 'quote-' . $order_id . '-' . $quote_id . '.pdf';
		$filepath = $dir . '/' . $filename;
		file_put_contents( $filepath, $pdf_content );

		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( '_bexio_quote_pdf', $filename );
			$order->save();
		}

		return $filepath;
	}

	/* ------------------------------------------------------------------ */
	/*  Cancel / delete quote                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Cancel / delete quote in Bexio when the WC order is cancelled.
	 */
	public function maybe_cancel_bexio_quote( $order_id, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		$this->delete_bexio_quote( $order );
	}

	/**
	 * Cancel / delete quote in Bexio when the WC order is trashed or deleted.
	 */
	public function handle_order_trash_delete( $order_id, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		$this->delete_bexio_quote( $order );
	}

	/**
	 * Delete the Bexio quote and clear local meta.
	 */
	public function delete_bexio_quote( $order ) {
		$quote_id = (int) $order->get_meta( '_bexio_quote_id', true );
		if ( ! $quote_id ) {
			return false;
		}

		// Revert to draft first so it can be deleted.
		$this->api->revert_quote( $quote_id );
		$this->api->delete_quote( $quote_id );

		$order->delete_meta_data( '_bexio_quote_id' );
		$order->delete_meta_data( '_bexio_quote_pdf' );
		$order->delete_meta_data( '_bexio_quote_pdf_sent' );
		$order->save();

		$this->log( $order->get_id(), 'quote_delete', 'delete', 'Quote deleted in Bexio: ' . $quote_id );

		return true;
	}

	/* ------------------------------------------------------------------ */
	/*  Admin order actions                                                 */
	/* ------------------------------------------------------------------ */

	public function add_order_actions( $actions ) {
		$actions['send_bexio_quote_pdf'] = __( 'Send Bexio Quote PDF (re-send)', 'bexio-wc' );
		return $actions;
	}

	/** Called by WooCommerce when the admin selects the action from the dropdown. */
	public function action_send_quote_pdf( $order ) {
		add_filter( 'bexio_wc_force_send_email', '__return_true' );
		$this->send_quote_pdf( $order );
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX download (admin)                                               */
	/* ------------------------------------------------------------------ */

	public function ajax_download_quote_pdf() {
		check_ajax_referer( 'bexio_pdf', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( __( 'Insufficient permissions', 'bexio-wc' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_die( __( 'Invalid order ID', 'bexio-wc' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( __( 'Order not found', 'bexio-wc' ) );
		}

		$pdf_path = $this->get_quote_pdf( $order_id );
		if ( $pdf_path && file_exists( $pdf_path ) ) {
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: attachment; filename="quote-' . $order->get_order_number() . '.pdf"' );
			header( 'Content-Length: ' . filesize( $pdf_path ) );
			readfile( $pdf_path );
			exit;
		}

		wp_die( __( 'PDF not found', 'bexio-wc' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Meta box section (injected into the existing Bexio Documents box)  */
	/* ------------------------------------------------------------------ */

	public function render_quote_meta_box_section( $order ) {
		$quote_id  = $order->get_meta( '_bexio_quote_id' );
		$order_id  = $order->get_id();
		$sent_time = $order->get_meta( '_bexio_quote_pdf_sent' );

		if ( ! $quote_id ) {
			return;
		}
		?>
		<hr style="margin:12px 0;">
		<p><strong><?php esc_html_e( 'Quote PDF:', 'bexio-wc' ); ?></strong><br>
			<?php if ( $sent_time ) : ?>
				<small style="color:#666;"><?php echo esc_html( sprintf( __( 'Last sent: %s', 'bexio-wc' ), $sent_time ) ); ?></small><br>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=bexio_download_quote_pdf&order_id=' . $order_id . '&nonce=' . wp_create_nonce( 'bexio_pdf' ) ) ); ?>"
			   class="button" target="_blank">
				<?php esc_html_e( 'Download Quote PDF', 'bexio-wc' ); ?>
			</a>
		</p>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/*  Email template                                                      */
	/* ------------------------------------------------------------------ */

	private function get_quote_email_template( $order ) {
    $order_reference = $this->resolve_order_reference( $order );

    ob_start();
    wc_get_template(
        'emails/email-header.php',
        array( 'email_heading' => __( 'Ihre Offerte', 'bexio-wc' ) )
    );
    ?>
    <p><?php esc_html_e( 'Guten Tag', 'bexio-wc' ); ?></p>
    <p><?php esc_html_e( 'Anbei sende ich Ihnen die gewünschte Offerte.', 'bexio-wc' ); ?></p>
    <p><?php esc_html_e( 'Falls Sie Fragen haben oder Anpassungen wünschen, stehe ich Ihnen jederzeit gerne zur Verfügung.', 'bexio-wc' ); ?></p>
    <p>
        <?php esc_html_e( 'Freundliche Grüsse', 'bexio-wc' ); ?><br>
        <?php esc_html_e( 'RePan Team', 'bexio-wc' ); ?><br>
        <?php esc_html_e( 'DE: +41 78 257 0450', 'bexio-wc' ); ?><br>
        <?php esc_html_e( 'FR: +41 76 794 1050', 'bexio-wc' ); ?>
    </p>
    <?php
    wc_get_template( 'emails/email-footer.php' );
    return ob_get_clean();
}

	/* ------------------------------------------------------------------ */
	/*  Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/** Resolve the document reference number, respecting migrated orders. */
	private function resolve_order_reference( $order ) {
		$order_date = $order->get_date_created();
		$reference  = $order->get_id();

		if ( $order_date && $order_date->format( 'Y' ) < 2026 ) {
			$original = $order->get_meta( '_original_order_id', true );
			if ( $original ) {
				$reference = $original;
			}
		}

		return $reference;
	}

	private function get_language_id( $order ) {
		$locale = $order->get_meta( 'wpml_language', true );
		if ( ! $locale ) {
			$locale = substr( get_locale(), 0, 2 );
		}
		$map = array( 'de' => 1, 'fr' => 2, 'it' => 3, 'en' => 4 );
		return isset( $map[ $locale ] ) ? $map[ $locale ] : 1;
	}

	private function get_bank_account_id( $payment_method ) {
		$card_methods = array( 'stripe', 'paypal', 'square' );
		return in_array( $payment_method, $card_methods )
			? get_option( 'bexio_wc_card_bank_id', 1 )
			: 1;
	}

	private function get_currency_id( $currency_code ) {
		$map = array( 'CHF' => 1, 'EUR' => 2, 'USD' => 3 );
		return isset( $map[ $currency_code ] ) ? $map[ $currency_code ] : 1;
	}

	private function get_quote_header( $order ) {
		$note = $order->get_customer_note();
		if ( $note ) {
			return __( 'Customer Notes:', 'bexio-wc' ) . "\n" . $note;
		}
		return '';
	}

	private function get_quote_footer( $order ) {
		return '';
	}

	private function log( $order_id, $sync_type, $status, $message = '', $bexio_quote_id = null ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'bexio_sync_log',
			array(
				'order_id'         => $order_id,
				'bexio_order_id'   => $bexio_quote_id, // reusing the column for quote ID
				'bexio_invoice_id' => null,
				'sync_type'        => $sync_type,
				'sync_status'      => $status,
				'sync_message'     => $message,
			)
		);

		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->add_order_note(
				sprintf( '[Bexio Quote] %s: %s', ucfirst( $sync_type ), $message ),
				false,
				false
			);
		}
	}
}