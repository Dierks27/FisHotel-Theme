<?php
/**
 * FisHotel — Order email display blocks.
 *
 * Appends a "FisHotel Delivery Date" block after the order items table on
 * customer + admin transactional emails. Data source: _fishotel_shipping_date
 * order meta (already populated by the cart-presets / scheduler flow).
 *
 * Hook approach (rather than full template overrides) so we don't pin the
 * theme to a specific WC email template version. Fires inside the existing
 * woocommerce_email_after_order_table block on every email, which we then
 * filter by email id to avoid duplicating across cancellation / refunded
 * variants where it isn't useful.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Email IDs that should carry the delivery-date block. Customer-facing
 * confirmation states + admin "new order" notice.
 *
 * @return string[]
 */
function fishotel_emails_with_delivery_date() {
	return [
		'customer_processing_order',
		'customer_on_hold_order',
		'customer_completed_order',
		'customer_invoice',
		'new_order',
	];
}

/**
 * Render the FisHotel Delivery Date block in WC transactional emails.
 *
 * @param WC_Order $order
 * @param bool     $sent_to_admin
 * @param bool     $plain_text
 * @param object   $email
 */
function fishotel_email_delivery_date_block( $order, $sent_to_admin, $plain_text, $email ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$email_id = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';
	if ( ! in_array( $email_id, fishotel_emails_with_delivery_date(), true ) ) {
		return;
	}

	$delivery_date = function_exists( 'fishotel_get_effective_delivery_date' )
		? fishotel_get_effective_delivery_date( $order )
		: (string) $order->get_meta( '_fishotel_shipping_date' );
	if ( '' === $delivery_date ) {
		return;
	}
	$ts = strtotime( $delivery_date );
	if ( ! $ts ) {
		return;
	}

	$formatted = date_i18n( 'l, F j, Y', $ts );

	if ( $plain_text ) {
		echo "\n" . esc_html__( '== FisHotel Delivery Date ==', 'fishotel' ) . "\n";
		echo esc_html( $formatted ) . "\n";
		echo esc_html__( 'Need to change this date? Visit your order on fishotel.com → Past Stays (changes allowed up to 48 hours before delivery).', 'fishotel' ) . "\n\n";
		return;
	}

	$is_admin_note = ( 'new_order' === $email_id );
	?>
	<div style="margin: 24px 0; padding: 18px 22px; background: rgba(212, 165, 116, 0.10); border-left: 4px solid #d4a574;">
		<div style="font-family: 'Josefin Sans', 'Helvetica Neue', Arial, sans-serif; font-size: 11px; letter-spacing: 0.22em; text-transform: uppercase; color: #d4a574; margin-bottom: 6px;">
			<?php esc_html_e( 'FisHotel Delivery Date', 'fishotel' ); ?>
		</div>
		<div style="font-family: 'Playfair Display', Georgia, serif; font-size: 22px; line-height: 1.25; color: #2b2b2b;">
			<?php echo esc_html( $formatted ); ?>
		</div>
		<?php if ( ! $is_admin_note ) : ?>
			<div style="margin-top: 8px; font-size: 13px; color: #666;">
				<?php esc_html_e( 'Need to change this date? Visit your order on fishotel.com → Past Stays (changes allowed up to 48 hours before delivery).', 'fishotel' ); ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}
add_action( 'woocommerce_email_after_order_table', 'fishotel_email_delivery_date_block', 15, 4 );
