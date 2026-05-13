<?php
/**
 * Medication store — EA packing slip generator.
 *
 * Phase 1 ships a print-ready HTML view (browser handles Save-as-PDF
 * via the OS print dialog) plus a "Email to Dena" button that sends
 * the same HTML as a styled message to the EA fulfillment email.
 * Real PDF attachment requires a dependency (dompdf/TCPDF) that
 * isn't bundled in this theme — Phase 2 swaps in a real attachment
 * once Composer is wired up. The acceptance flow is the same
 * either way: button on the order screen, FisHotel-branded layout,
 * only EA-mode line items.
 *
 * Slip layout per spec §7:
 *   - FisHotel logo + tagline header (dark band, gold rule).
 *   - Order #, order date, ship-by date.
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
		// Admin button on the classic order edit screen.
		add_action( 'woocommerce_admin_order_data_after_order_details', [ __CLASS__, 'render_order_actions' ] );

		// Admin POST handlers (open print view / send email).
		add_action( 'admin_post_' . self::ACTION_VIEW,  [ __CLASS__, 'handle_view' ] );
		add_action( 'admin_post_' . self::ACTION_EMAIL, [ __CLASS__, 'handle_email' ] );

		// Auto-email on processing if toggle is on.
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'maybe_auto_email' ], 20, 2 );
	}

	/**
	 * Print the two action buttons inside the order data box. Only
	 * shown when the order contains at least one EA-mode med line.
	 */
	public static function render_order_actions( $order ) {
		if ( ! $order instanceof WC_Order ) return;
		if ( ! self::order_has_ea_items( $order ) ) return;

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
		?>
		<div class="fishotel-med-slip-actions">
			<h3 style="margin:14px 0 6px;"><?php esc_html_e( 'EA Packing Slip', 'fishotel' ); ?></h3>
			<p style="margin:0 0 8px;">
				<a class="button button-primary" href="<?php echo esc_url( $view_url ); ?>" target="_blank">
					<?php esc_html_e( 'Generate EA Packing Slip', 'fishotel' ); ?>
				</a>
				<a class="button button-secondary" href="<?php echo esc_url( $email_url ); ?>">
					<?php esc_html_e( 'Email Packing Slip to Dena', 'fishotel' ); ?>
				</a>
			</p>
			<p class="description" style="margin:0;">
				<?php if ( $dena === '' ) : ?>
					<strong style="color:#b32d2e;"><?php esc_html_e( 'No fulfillment email set yet — add one in FisHotel Theme → Medication Store before clicking Email.', 'fishotel' ); ?></strong>
				<?php else : ?>
					<?php
					printf(
						/* translators: %s = email address */
						esc_html__( 'Email will go to %s. Generate button opens a print-ready view — use your browser to save as PDF.', 'fishotel' ),
						'<code>' . esc_html( $dena ) . '</code>'
					);
					?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/** True when at least one line item is an EA-mode medication. */
	public static function order_has_ea_items( WC_Order $order ) {
		foreach ( self::collect_ea_items( $order ) as $_ ) {
			return true;
		}
		return false;
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
			if ( ! fishotel_med_is_med_product( $lookup_id ) ) continue;
			if ( fishotel_med_get_mode( $lookup_id ) !== 'ea' ) continue;

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
				'qty'     => (int) $item->get_quantity(),
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
	 * Email the slip. Returns true on success, false on failure
	 * (missing email or wp_mail() refused).
	 */
	public static function send_email( WC_Order $order ) {
		$to = (string) FisHotel_Med_Settings::get( 'fishotel_ea_fulfillment_email' );
		if ( $to === '' || ! is_email( $to ) ) {
			$order->add_order_note( __( 'EA packing slip email skipped — no fulfillment email configured.', 'fishotel' ) );
			return false;
		}

		$subject = sprintf(
			/* translators: %d = order number */
			__( 'FisHotel EA packing slip — Order #%d', 'fishotel' ),
			$order->get_order_number()
		);
		$body    = self::build_slip_html( $order, /*for_email=*/ true );
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		$sent = wp_mail( $to, $subject, $body, $headers );
		if ( $sent ) {
			$order->add_order_note( sprintf(
				/* translators: %s = email address */
				__( 'EA packing slip emailed to %s.', 'fishotel' ),
				$to
			) );
		} else {
			$order->add_order_note( __( 'EA packing slip email failed (wp_mail returned false).', 'fishotel' ) );
		}
		return $sent;
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
		$ship_by     = $order_date; // Phase 1: ship-by = order date. Refine when scheduling is wired.

		$name        = trim( $order->get_formatted_shipping_full_name() );
		if ( $name === '' ) {
			$name = trim( $order->get_formatted_billing_full_name() );
		}
		$ship_addr   = $order->get_formatted_shipping_address();
		if ( ! $ship_addr ) {
			$ship_addr = $order->get_formatted_billing_address();
		}

		$rows = [];
		foreach ( self::collect_ea_items( $order ) as $row ) {
			$rows[] = $row;
		}

		ob_start();
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>FisHotel EA Packing Slip — Order #<?php echo esc_html( $order_id ); ?></title>
<style>
	body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; color: #1a1a1a; background: #f6f5f1; }
	.slip { max-width: 760px; margin: 24px auto; background: #fff; box-shadow: 0 1px 0 #e2dccc, 0 8px 24px rgba(0,0,0,.04); }
	.slip__header { background: #0f0f0f; color: #e8e4dc; padding: 20px 28px; display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #d4a574; }
	.slip__brand { font-family: "Roboto Slab", Georgia, serif; font-size: 22px; letter-spacing: 1px; }
	.slip__brand small { display: block; font-size: 11px; color: #d4a574; letter-spacing: 2px; margin-top: 2px; }
	.slip__doc { font-size: 12px; text-transform: uppercase; letter-spacing: 2px; color: #d4a574; text-align: right; }
	.slip__doc strong { display: block; font-size: 16px; color: #fff; margin-top: 4px; letter-spacing: 1px; }
	.slip__meta { padding: 18px 28px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 18px; font-size: 13px; border-bottom: 1px solid #ece6d6; }
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
				EA Packing Slip
				<strong>#<?php echo esc_html( $order_id ); ?></strong>
			</div>
		</header>

		<dl class="slip__meta">
			<div><dt>Order #</dt><dd><?php echo esc_html( $order_id ); ?></dd></div>
			<div><dt>Order Date</dt><dd><?php echo esc_html( $order_date ); ?></dd></div>
			<div><dt>Ship By</dt><dd><?php echo esc_html( $ship_by ); ?></dd></div>
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
							<th>EA SKU</th>
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

		<footer class="slip__footer">
			<p><strong>Thank you — please pack and ship per the EA fulfillment agreement.</strong></p>
			<p class="note">Order placed via fishotel.com — non-EA items in this order are fulfilled separately and are intentionally excluded from this slip.</p>
		</footer>
	</div>
</body>
</html>
		<?php
		return ob_get_clean();
	}
}
