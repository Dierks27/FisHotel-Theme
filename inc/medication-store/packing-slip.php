<?php
/**
 * Medication store — EA packing slip generator.
 *
 * The "Generate Slip" button opens a print-ready HTML view (browser
 * handles Save-as-PDF via the OS print dialog). The "Email to Dena"
 * button — and the auto-email-on-processing flow — render that same
 * branded slip to a real PDF via dompdf (bundled through Composer in
 * vendor/) and send it as an attachment, with a short cover note in
 * the body. If dompdf can't load (vendor/ not deployed yet, render
 * throws, etc.) the email gracefully falls back to the HTML-in-body
 * behavior so an order status transition never fatals. Either way:
 * button on the order screen, FisHotel-branded layout, only EA-mode
 * line items.
 *
 * Slip layout per spec §7:
 *   - FisHotel logo + tagline header (dark band, gold rule).
 *   - Order #, order date.
 *   - Recipient block (customer name + full shipping address).
 *   - Itemized list — ONLY ea-mode rows. Columns: Qty, Product, Size,
 *     EA SKU.
 *   - amazon-mode / self-mode rows are excluded.
 *   - No prices on the slip.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Packing_Slip {

	const ACTION_VIEW  = 'fishotel_med_packing_slip_view';
	const ACTION_EMAIL = 'fishotel_med_packing_slip_email';

	public static function init() {
		// Meta box renders on both legacy and HPOS order screens by
		// targeting both screen IDs. Using a meta box (vs. the
		// woocommerce_admin_order_data_after_order_details hook) is
		// the only approach that survives HPOS unconditionally.
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_box' ] );

		// Admin POST handlers (open print view / send email).
		add_action( 'admin_post_' . self::ACTION_VIEW,  [ __CLASS__, 'handle_view' ] );
		add_action( 'admin_post_' . self::ACTION_EMAIL, [ __CLASS__, 'handle_email' ] );

		// Auto-email on processing if toggle is on.
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'maybe_auto_email' ], 20, 2 );
	}

	/** Register the EA Packing Slip meta box on legacy + HPOS screens. */
	public static function register_meta_box() {
		// Legacy post-type-based edit screen.
		add_meta_box(
			'fishotel-med-packing-slip',
			__( 'EA Packing Slip', 'fishotel' ),
			[ __CLASS__, 'render_meta_box' ],
			'shop_order',
			'side',
			'default'
		);
		// HPOS Custom Orders Table screen.
		add_meta_box(
			'fishotel-med-packing-slip',
			__( 'EA Packing Slip', 'fishotel' ),
			[ __CLASS__, 'render_meta_box' ],
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box. WP calls this with a WP_Post on legacy, WC
	 * calls it with a WC_Order on HPOS. Normalize both into a WC_Order
	 * before dispatching to the shared body.
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = null;
		if ( $post_or_order instanceof WC_Order ) {
			$order = $post_or_order;
		} elseif ( $post_or_order instanceof WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
		}
		if ( ! $order instanceof WC_Order ) {
			echo '<p style="color:#666;">' . esc_html__( 'Order not loaded yet.', 'fishotel' ) . '</p>';
			return;
		}
		// Source orders folded into a fulfillment don't own their own slip —
		// the fulfillment carries the aggregated items and generates one slip.
		if ( function_exists( 'fishotel_order_get_fulfillment' ) ) {
			$ff_id = fishotel_order_get_fulfillment( $order );
			if ( $ff_id ) {
				$ff   = wc_get_order( $ff_id );
				$link = $ff instanceof WC_Order ? $ff->get_edit_order_url() : '#';
				echo '<p style="margin:0;color:#666;">';
				printf(
					/* translators: %s = fulfillment order link */
					wp_kses_post( __( 'This order is part of fulfillment %s — generate the slip from there.', 'fishotel' ) ),
					'<a href="' . esc_url( $link ) . '"><strong>#FF-' . esc_html( (string) $ff_id ) . '</strong></a>'
				);
				echo '</p>';
				return;
			}
		}
		if ( ! self::order_has_ea_items( $order ) ) {
			// EA-mode lines with no EA SKU are silently non-EA everywhere
			// (slip, EA column, mixed-order split). Name them here so the
			// generic "No EA-mode items" message doesn't hide a missing SKU.
			$missing = self::ea_items_missing_sku( $order );
			if ( ! empty( $missing ) ) {
				$links = [];
				foreach ( $missing as $pid => $name ) {
					$edit    = get_edit_post_link( (int) $pid, 'raw' );
					$links[] = $edit
						? '<a href="' . esc_url( $edit ) . '">' . esc_html( $name ) . '</a>'
						: esc_html( $name );
				}
				echo '<p style="margin:0;color:#b32d2e;">';
				printf(
					/* translators: %s = comma-separated product names (linked to the product edit screen) */
					wp_kses_post( __( 'EA-mode item(s) missing an EA SKU — add it on the product to include them on the slip: %s', 'fishotel' ) ),
					implode( ', ', $links ) // Already escaped above.
				);
				echo '</p>';
				return;
			}
			echo '<p style="margin:0;color:#666;font-style:italic;">'
				. esc_html__( 'No EA-mode items in this order.', 'fishotel' )
				. '</p>';
			return;
		}
		self::render_order_actions( $order );
	}

	/**
	 * EA-mode line items (raw _fishotel_fulfillment === 'ea') on the slip's
	 * order set whose parent AND variation both lack an _fishotel_ea_sku —
	 * i.e. items fishotel_is_ea_fulfilled_product() rejects for SKU reasons
	 * only. Keyed by parent product ID => item name.
	 *
	 * @return array<int,string>
	 */
	public static function ea_items_missing_sku( WC_Order $order ) {
		$missing = [];
		foreach ( self::slip_source_orders( $order ) as $src ) {
			foreach ( $src->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) continue;
				$product_id   = (int) $item->get_product_id();
				$variation_id = (int) $item->get_variation_id();
				if ( ! $product_id ) continue;
				if ( get_post_meta( $product_id, '_fishotel_fulfillment', true ) !== 'ea' ) continue;
				if ( fishotel_is_ea_fulfilled_product( $product_id, $variation_id ) ) continue;
				$missing[ $product_id ] = $item->get_name();
			}
		}
		return $missing;
	}

	/**
	 * Render the two action buttons. Called from the meta box once we've
	 * confirmed the order has at least one EA-mode med line.
	 */
	public static function render_order_actions( $order ) {
		if ( ! $order instanceof WC_Order ) return;

		$order_id = $order->get_id();
		$view_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_VIEW . '&order_id=' . $order_id ),
			self::ACTION_VIEW . '_' . $order_id
		);
		$email_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_EMAIL . '&order_id=' . $order_id ),
			self::ACTION_EMAIL . '_' . $order_id
		);

		$dena = (string) FisHotel_Med_Settings::get( 'fishotel_ea_fulfillment_email' );
		$slip_sent = isset( $_GET['fishotel_slip_sent'] ) ? (int) $_GET['fishotel_slip_sent'] : null;

		// Orders whose slip was already auto-emailed (status → processing).
		// WHY: auto-email fires per SOURCE order before the fulfillment
		// exists, and the fulfillment never enters processing, so its box
		// had no idea the same items already went to Dena — a manual
		// "Email to Dena" from here re-sent them. Surface the sent orders
		// and make the button ask first.
		$already_sent = [];
		if ( function_exists( 'fishotel_is_fulfillment' ) && fishotel_is_fulfillment( $order ) ) {
			foreach ( fishotel_fulfillment_get_sources( $order ) as $sid ) {
				$src = wc_get_order( (int) $sid );
				if ( $src instanceof WC_Order && $src->get_meta( '_fishotel_med_slip_sent' ) === '1' ) {
					$already_sent[] = '#' . $src->get_order_number();
				}
			}
		} elseif ( $order->get_meta( '_fishotel_med_slip_sent' ) === '1' ) {
			$already_sent[] = '#' . $order->get_order_number();
		}
		$confirm_msg = empty( $already_sent ) ? '' : sprintf(
			/* translators: %s = comma-separated order numbers */
			__( 'A packing slip was already auto-emailed to Dena for %s. Send another one?', 'fishotel' ),
			implode( ', ', $already_sent )
		);
		?>
		<div class="fishotel-med-slip-actions">
			<?php if ( $slip_sent === 1 ) : ?>
				<p class="notice notice-success" style="margin:0 0 10px;padding:6px 10px;"><?php esc_html_e( 'Packing slip emailed.', 'fishotel' ); ?></p>
			<?php elseif ( $slip_sent === 0 ) : ?>
				<p class="notice notice-error" style="margin:0 0 10px;padding:6px 10px;"><?php esc_html_e( 'Packing slip email failed — see order notes.', 'fishotel' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $already_sent ) ) : ?>
				<p style="margin:0 0 8px;color:#b07d3c;">
					<?php
					printf(
						/* translators: %s = comma-separated order numbers */
						esc_html__( 'Slip already auto-emailed for %s.', 'fishotel' ),
						esc_html( implode( ', ', $already_sent ) )
					);
					?>
				</p>
			<?php endif; ?>
			<p style="margin:0 0 8px;">
				<a class="button button-primary" href="<?php echo esc_url( $view_url ); ?>" target="_blank">
					<?php esc_html_e( 'Generate Slip', 'fishotel' ); ?>
				</a>
				<a class="button button-secondary" href="<?php echo esc_url( $email_url ); ?>"<?php if ( $confirm_msg !== '' ) : ?> onclick="return confirm(<?php echo esc_attr( wp_json_encode( $confirm_msg ) ); ?>);"<?php endif; ?>>
					<?php esc_html_e( 'Email to Dena', 'fishotel' ); ?>
				</a>
			</p>
			<p class="description" style="margin:0;">
				<?php if ( $dena === '' ) : ?>
					<strong style="color:#b32d2e;"><?php esc_html_e( 'No fulfillment email set yet — add one in FisHotel Theme → Medication Store before clicking Email.', 'fishotel' ); ?></strong>
				<?php else : ?>
					<?php
					printf(
						/* translators: %s = email address */
						esc_html__( 'Email will go to %s. Generate opens a print-ready view — use your browser to save as PDF.', 'fishotel' ),
						'<code>' . esc_html( $dena ) . '</code>'
					);
					?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * True when at least one line item is an EA-mode medication — including
	 * items pulled in from combined secondary orders.
	 */
	public static function order_has_ea_items( WC_Order $order ) {
		foreach ( self::collect_ea_items_combined( $order ) as $_ ) {
			return true;
		}
		return false;
	}

	/**
	 * The orders whose line items feed this slip, in slip order.
	 *
	 *  - Phase 2.5 fulfillment: the LIVE source orders, skipping any that
	 *    are cancelled / refunded / failed. WHY: the fulfillment's own
	 *    copied line items are a one-time snapshot that nothing resyncs
	 *    when a source is edited or cancelled, so a slip built from them
	 *    told Dena to ship items that no longer exist on the source.
	 *  - Otherwise: the order itself plus every Phase 2 secondary combined
	 *    into it.
	 *
	 * @return WC_Order[]
	 */
	public static function slip_source_orders( WC_Order $order ) {
		if ( function_exists( 'fishotel_is_fulfillment' ) && fishotel_is_fulfillment( $order ) ) {
			$orders = [];
			foreach ( fishotel_fulfillment_get_sources( $order ) as $sid ) {
				$src = wc_get_order( (int) $sid );
				if ( ! $src instanceof WC_Order ) continue;
				if ( in_array( $src->get_status(), [ 'cancelled', 'refunded', 'failed' ], true ) ) continue;
				$orders[] = $src;
			}
			return $orders;
		}

		$orders = [ $order ];
		if ( function_exists( 'fishotel_order_get_secondaries' ) ) {
			foreach ( fishotel_order_get_secondaries( $order->get_id() ) as $sid ) {
				$secondary = wc_get_order( $sid );
				if ( $secondary instanceof WC_Order ) {
					$orders[] = $secondary;
				}
			}
		}
		return $orders;
	}

	/**
	 * Yield EA-mode rows for an order plus every order combined into it
	 * (Phase 2), or — for a fulfillment — its live source orders. On a
	 * standalone order this is just its own items.
	 *
	 * @return Generator<array>
	 */
	public static function collect_ea_items_combined( WC_Order $order ) {
		foreach ( self::slip_source_orders( $order ) as $src ) {
			foreach ( self::collect_ea_items( $src ) as $row ) {
				yield $row;
			}
		}
	}

	/**
	 * Order numbers in a combined bundle (primary first), or [] when the
	 * order has no secondaries. Used for the slip's "Combined order" line.
	 *
	 * @return string[]
	 */
	public static function combined_order_numbers( WC_Order $order ) {
		// Phase 2.5: a fulfillment lists its bundled source orders.
		if ( function_exists( 'fishotel_is_fulfillment' ) && fishotel_is_fulfillment( $order ) ) {
			$numbers = [];
			foreach ( fishotel_fulfillment_get_sources( $order ) as $sid ) {
				$src       = wc_get_order( (int) $sid );
				$numbers[] = '#' . ( $src instanceof WC_Order ? $src->get_order_number() : $sid );
			}
			return $numbers;
		}
		return [];
	}

	/**
	 * Walk the order's line items, yielding only those whose parent
	 * product is an EA-mode medication. Items where the product is
	 * gone (deleted) are skipped. Variation-level data overrides the
	 * parent's where present.
	 *
	 * @return Generator<array>
	 */
	public static function collect_ea_items( WC_Order $order ) {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) continue;

			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$lookup_id    = $product_id;
			if ( ! $lookup_id ) continue;
			if ( ! fishotel_is_ea_fulfilled_product( $lookup_id, $variation_id ) ) continue;

			// Net of refunds: a slip generated after a partial refund must not
			// tell Dena to ship the refunded units; a fully refunded line drops
			// off entirely (which also makes order_has_ea_items() false when
			// every EA line is refunded).
			$qty = (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( (int) $item->get_id() ) );
			if ( $qty <= 0 ) continue;

			$size  = '';
			$sku   = '';
			$variation = $variation_id ? wc_get_product( $variation_id ) : null;
			$parent    = wc_get_product( $product_id );

			if ( $variation ) {
				$attrs = $variation->get_attributes();
				if ( is_array( $attrs ) && ! empty( $attrs ) ) {
					$size = implode( ' / ', array_map( function ( $v ) {
						return wp_strip_all_tags( (string) $v );
					}, $attrs ) );
				}
				$ea_sku_var = (string) get_post_meta( $variation_id, '_fishotel_ea_sku', true );
				if ( $ea_sku_var !== '' ) {
					$sku = $ea_sku_var;
				}
			}
			if ( $sku === '' ) {
				$sku = (string) get_post_meta( $product_id, '_fishotel_ea_sku', true );
			}

			yield [
				'qty'     => $qty,
				'name'    => $parent ? $parent->get_name() : $item->get_name(),
				'size'    => $size,
				'ea_sku'  => $sku,
			];
		}
	}

	/** Open the print-ready HTML view in a new tab. */
	public static function handle_view() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'fishotel' ), '', [ 'response' => 403 ] );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		check_admin_referer( self::ACTION_VIEW . '_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'fishotel' ) );
		}
		// Headers explicit so the print view doesn't inherit admin chrome.
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::build_slip_html( $order, /*for_email=*/ false );
		exit;
	}

	/** Send the rendered slip as a styled HTML email. */
	public static function handle_email() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'fishotel' ), '', [ 'response' => 403 ] );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		check_admin_referer( self::ACTION_EMAIL . '_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'fishotel' ) );
		}

		$result = self::send_email( $order );
		$redirect = $order->get_edit_order_url();
		$redirect = add_query_arg( [ 'fishotel_slip_sent' => $result ? 1 : 0 ], $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Email the slip. Sends a real PDF attachment (rendered from the
	 * branded slip HTML via dompdf) with a short cover note in the body.
	 * Falls back to the slip HTML in the body when dompdf is unavailable
	 * or the PDF can't be staged, so an order status transition never
	 * fatals on a missing dependency.
	 *
	 * Returns true on success, false on failure (missing email or
	 * wp_mail() refused).
	 */
	public static function send_email( WC_Order $order ) {
		$to = (string) FisHotel_Med_Settings::get( 'fishotel_ea_fulfillment_email' );
		if ( $to === '' || ! is_email( $to ) ) {
			$order->add_order_note( __( 'EA packing slip email skipped — no fulfillment email configured.', 'fishotel' ) );
			return false;
		}

		$subject = sprintf(
			/* translators: %s = order number */
			__( 'FisHotel EA packing slip — Order #%s', 'fishotel' ),
			$order->get_order_number()
		);
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		$pdf = self::build_slip_pdf( $order );

		if ( $pdf !== null ) {
			// Stage the PDF in the OS temp dir (outside the web root) so it
			// can never be served over HTTP. nginx ignores .htaccess, so a
			// file under uploads/ could otherwise be fetched in the brief
			// window before cleanup; sys_get_temp_dir() sidesteps that and
			// works the same on Cloudways/staging/local. The finally block
			// removes it regardless of how we exit.
			$tmp_path = '';
			$tmp_dir  = '';
			try {
				// Attachment name = basename of the temp file, so the temp
				// file must be named exactly per spec: fishotel-ea-{n}.pdf.
				$safe_number = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $order->get_order_number() );
				if ( $safe_number === '' ) {
					$safe_number = (string) $order->get_id();
				}
				// Stage inside a private, unguessable sub-directory rather than
				// straight in the shared temp dir. WHY: the predictable path
				// fishotel-ea-{n}.pdf plus a symlink-following
				// file_put_contents() let another local user on a shared /tmp
				// pre-plant a symlink and have us overwrite (or read) a file of
				// their choosing. The basename is unchanged so the attachment
				// name is still fishotel-ea-{n}.pdf.
				$dir = trailingslashit( sys_get_temp_dir() ) . 'fishotel-' . wp_generate_password( 12, false );
				if ( ! wp_mkdir_p( $dir ) ) {
					throw new \RuntimeException( 'Failed to create temp dir for the PDF.' );
				}
				$tmp_dir = $dir;
				@chmod( $dir, 0700 );
				$tmp_path = trailingslashit( $dir ) . 'fishotel-ea-' . $safe_number . '.pdf';

				if ( file_put_contents( $tmp_path, $pdf ) === false ) {
					$tmp_path = '';
					throw new \RuntimeException( 'Failed to stage temp PDF.' );
				}

				$sent = wp_mail( $to, $subject, self::build_cover_note( $order ), $headers, [ $tmp_path ] );
				if ( $sent ) {
					$order->add_order_note( sprintf(
						/* translators: %s = email address */
						__( 'EA packing slip emailed to %s with PDF attachment.', 'fishotel' ),
						$to
					) );
				} else {
					$order->add_order_note( __( 'EA packing slip email failed (wp_mail returned false).', 'fishotel' ) );
				}
				return $sent;
			} catch ( \Throwable $e ) {
				// Staging/sending the PDF threw — drop to the HTML fallback.
			} finally {
				if ( $tmp_path !== '' && file_exists( $tmp_path ) ) {
					@unlink( $tmp_path );
				}
				if ( $tmp_dir !== '' && is_dir( $tmp_dir ) ) {
					@rmdir( $tmp_dir );
				}
			}
		}

		// Fallback: dompdf unavailable or PDF staging failed — send the
		// slip as HTML in the body.
		$order->add_order_note( __( 'EA packing slip emailed as HTML — PDF generation unavailable (see dompdf status).', 'fishotel' ) );
		$body = self::build_slip_html( $order, /*for_email=*/ true );
		$sent = wp_mail( $to, $subject, $body, $headers );
		if ( $sent ) {
			$order->add_order_note( sprintf(
				/* translators: %s = email address */
				__( 'EA packing slip emailed to %s as HTML (PDF unavailable).', 'fishotel' ),
				$to
			) );
		} else {
			$order->add_order_note( __( 'EA packing slip email failed (wp_mail returned false).', 'fishotel' ) );
		}
		return $sent;
	}

	/**
	 * Render the slip HTML to a PDF binary string via dompdf.
	 *
	 * Returns null on failure (autoloader missing, dompdf throws, etc.)
	 * so callers can fall back to HTML-only emails without crashing the
	 * order status transition.
	 *
	 * @param WC_Order $order
	 * @return string|null Binary PDF, or null on failure.
	 */
	public static function build_slip_pdf( WC_Order $order ) {
		if ( ! class_exists( '\\Dompdf\\Dompdf' ) ) {
			return null;
		}
		try {
			$html   = self::build_slip_html( $order, /*for_email=*/ true );
			$dompdf = new \Dompdf\Dompdf( [
				'isRemoteEnabled'      => false, // No remote assets in the slip HTML.
				'defaultFont'          => 'Helvetica',
				'isHtml5ParserEnabled' => true,
				// Render with the slip's @media print rules (white page, no
				// card fill/shadow) — this is a slip meant for Dena's printer.
				'defaultMediaType'     => 'print',
			] );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->setPaper( 'letter', 'portrait' );
			$dompdf->render();
			return $dompdf->output();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Short HTML cover note for the PDF email body. Order # and customer
	 * name mirror what build_slip_html() shows; the full itemized slip
	 * lives in the attached PDF, not inline.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	public static function build_cover_note( WC_Order $order ) {
		$order_number = $order->get_order_number();

		$name = trim( $order->get_formatted_shipping_full_name() );
		if ( $name === '' ) {
			$name = trim( $order->get_formatted_billing_full_name() );
		}

		ob_start();
		?>
<p>Hi Dena,</p>
<p>Attached is the FisHotel packing slip for <strong>Order #<?php echo esc_html( $order_number ); ?></strong>
(<?php echo esc_html( $name ); ?>).</p>
<p>Thank you!</p>
<p style="color:#777;font-size:12px;">Order placed via fishotel.com — non-EA items
on this order are fulfilled separately and are intentionally not on the attached slip.</p>
		<?php
		return ob_get_clean();
	}

	/** Auto-email hook — runs on order → processing if toggle is on. */
	public static function maybe_auto_email( $order_id, $order ) {
		if ( ! (int) FisHotel_Med_Settings::get( 'fishotel_med_auto_email_slip' ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) return;
		if ( ! self::order_has_ea_items( $order ) ) return;
		// Don't re-send if we've already sent for this order.
		if ( $order->get_meta( '_fishotel_med_slip_sent' ) === '1' ) return;

		$sent = self::send_email( $order );
		if ( $sent ) {
			$order->update_meta_data( '_fishotel_med_slip_sent', '1' );
			$order->save();
		}
	}

	/** Build the slip HTML. Used for both the print view and emails. */
	public static function build_slip_html( WC_Order $order, $for_email = false ) {
		$site_name = get_bloginfo( 'name' );
		$tagline   = class_exists( 'FisHotel_Admin_Settings' )
			? (string) FisHotel_Admin_Settings::get( 'fh_tagline' )
			: 'We quarantine. You reef.';

		$order_id    = $order->get_order_number();
		$order_date  = $order->get_date_created();
		$order_date  = $order_date ? $order_date->date_i18n( 'M j, Y' ) : '';

		$name        = trim( $order->get_formatted_shipping_full_name() );
		if ( $name === '' ) {
			$name = trim( $order->get_formatted_billing_full_name() );
		}
		$ship_addr   = $order->get_formatted_shipping_address();
		if ( ! $ship_addr ) {
			$ship_addr = $order->get_formatted_billing_address();
		}

		$rows = [];
		foreach ( self::collect_ea_items_combined( $order ) as $row ) {
			$rows[] = $row;
		}

		// Phase 2: when this order bundles combined secondaries, surface the
		// full set of order numbers so Dena knows what shipped together.
		$combined_numbers = self::combined_order_numbers( $order );

		// C2: medication terms acceptance line(s) so EverythingAquatic sees
		// the customer accepted the ornamental-use terms (one per source
		// order on a fulfillment). Date + version only: the customer's IP
		// stays on FisHotel's own documents, not on paperwork emailed to a
		// third party.
		$terms_lines = class_exists( 'FisHotel_Med_Terms_Display' )
			? FisHotel_Med_Terms_Display::lines_for_order( $order, false )
			: [];

		ob_start();
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>FisHotel Packing Slip — Order #<?php echo esc_html( $order_id ); ?></title>
<style>
	body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; color: #1a1a1a; background: #f6f5f1; }
	.slip { max-width: 760px; margin: 24px auto; background: #fff; box-shadow: 0 1px 0 #e2dccc, 0 8px 24px rgba(0,0,0,.04); }
	/* Ink-light header: no solid fill (saves Dena's toner). Dark text on
	   white with the gold accent rule preserved for FisHotel branding. */
	.slip__header { padding: 20px 28px; display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid #d4a574; }
	.slip__brand { font-family: "Roboto Slab", Georgia, serif; font-size: 22px; letter-spacing: 1px; color: #0f0f0f; }
	.slip__brand small { display: block; font-size: 11px; color: #b07d3c; letter-spacing: 2px; margin-top: 2px; }
	.slip__doc { font-size: 12px; text-transform: uppercase; letter-spacing: 2px; color: #b07d3c; text-align: right; }
	.slip__doc strong { display: block; font-size: 16px; color: #0f0f0f; margin-top: 4px; letter-spacing: 1px; }
	.slip__meta { padding: 18px 28px; font-size: 13px; border-bottom: 1px solid #ece6d6; }
	.slip__meta > div { display: inline-block; width: 48%; vertical-align: top; box-sizing: border-box; }
	.slip__meta dt { text-transform: uppercase; letter-spacing: 1.5px; font-size: 10px; color: #847d6c; margin-bottom: 2px; }
	.slip__meta dd { margin: 0; font-size: 14px; color: #1a1a1a; }
	.slip__recipient { padding: 18px 28px; border-bottom: 1px solid #ece6d6; }
	.slip__recipient h3 { margin: 0 0 6px; font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #847d6c; }
	.slip__recipient address { font-style: normal; font-size: 14px; line-height: 1.5; color: #1a1a1a; }
	.slip__items { padding: 18px 28px; }
	.slip__items table { width: 100%; border-collapse: collapse; font-size: 14px; }
	.slip__items th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 2px; color: #847d6c; padding: 8px 6px; border-bottom: 1px solid #ece6d6; }
	.slip__items td { padding: 10px 6px; border-bottom: 1px solid #f4eedc; vertical-align: top; }
	.slip__items td.qty { width: 56px; font-weight: 700; }
	.slip__items td.size { color: #555; }
	.slip__items td.sku { font-family: "Courier New", monospace; font-size: 13px; color: #555; }
	.slip__empty { padding: 18px 28px; color: #b32d2e; font-style: italic; }
	.slip__terms { padding: 12px 28px; border-top: 1px solid #ece6d6; font-size: 11px; color: #555; }
	.slip__terms p { margin: 0 0 3px; }
	.slip__footer { padding: 22px 28px; border-top: 2px solid #d4a574; background: #faf8f1; font-size: 12px; color: #555; }
	.slip__footer p { margin: 0 0 4px; }
	.slip__footer .note { font-size: 11px; color: #847d6c; }
	.print-bar { max-width: 760px; margin: 12px auto; padding: 8px 12px; background: #fff; border: 1px solid #d8d1bd; font-size: 13px; color: #555; display: flex; justify-content: space-between; align-items: center; }
	.print-bar button { background: #d4a574; color: #0f0f0f; border: 0; padding: 8px 14px; font-weight: 700; cursor: pointer; }
	@media print {
		body { background: #fff; }
		.slip { box-shadow: none; margin: 0; max-width: none; }
		.print-bar { display: none; }
	}
</style>
</head>
<body>
	<?php if ( ! $for_email ) : ?>
	<div class="print-bar">
		<span><?php esc_html_e( 'Tip: use your browser\'s Print dialog → "Save as PDF" to keep a copy.', 'fishotel' ); ?></span>
		<button type="button" onclick="window.print();return false;"><?php esc_html_e( 'Print / Save PDF', 'fishotel' ); ?></button>
	</div>
	<?php endif; ?>
	<div class="slip">
		<header class="slip__header">
			<div class="slip__brand">
				<?php echo esc_html( $site_name ); ?>
				<small><?php echo esc_html( $tagline ); ?></small>
			</div>
			<div class="slip__doc">
				Packing Slip
				<strong>#<?php echo esc_html( $order_id ); ?></strong>
				<?php if ( ! empty( $combined_numbers ) ) : ?>
					<small style="display:block;font-size:10px;letter-spacing:1px;color:#b07d3c;margin-top:3px;">
						<?php
						printf(
							/* translators: %s = list of combined order numbers */
							esc_html__( 'Combined order: %s', 'fishotel' ),
							esc_html( implode( ' · ', $combined_numbers ) )
						);
						?>
					</small>
				<?php endif; ?>
			</div>
		</header>

		<dl class="slip__meta">
			<div><dt>Order #</dt><dd><?php echo esc_html( $order_id ); ?></dd></div>
			<div><dt>Order Date</dt><dd><?php echo esc_html( $order_date ); ?></dd></div>
		</dl>

		<section class="slip__recipient">
			<h3>Ship To</h3>
			<address>
				<strong><?php echo esc_html( $name ); ?></strong><br>
				<?php echo wp_kses_post( $ship_addr ); ?>
			</address>
		</section>

		<section class="slip__items">
			<?php if ( empty( $rows ) ) : ?>
				<p class="slip__empty"><?php esc_html_e( 'No EA-mode line items found on this order.', 'fishotel' ); ?></p>
			<?php else : ?>
				<table>
					<thead>
						<tr>
							<th>Qty</th>
							<th>Product</th>
							<th>Size</th>
							<th>SKU</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td class="qty"><?php echo esc_html( (string) $row['qty'] ); ?></td>
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td class="size"><?php echo esc_html( $row['size'] ); ?></td>
								<td class="sku"><?php echo esc_html( $row['ea_sku'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<?php if ( ! empty( $terms_lines ) ) : ?>
		<section class="slip__terms">
			<?php foreach ( $terms_lines as $terms_line ) : ?>
				<p><?php echo esc_html( $terms_line ); ?></p>
			<?php endforeach; ?>
		</section>
		<?php endif; ?>

		<footer class="slip__footer">
			<p><strong>Thank you for your order!</strong></p>
			<p class="note">Items not shown on this slip may ship separately.</p>
		</footer>
	</div>
</body>
</html>
		<?php
		return ob_get_clean();
	}
}
