<?php
/**
 * FisHotel — Reservation Details (view order).
 *
 * Wraps WooCommerce's standard order-details + customer-details output in a
 * styled card and adds a Reorder action. The dynamic page-header subtitle
 * ("{date} · #{number}") is produced by inc/account-relabels.php.
 *
 * @package FisHotel
 * @var int      $order_id
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;

$notes       = $order ? $order->get_customer_order_notes() : [];
$reorder_url = $order ? wp_nonce_url( add_query_arg( 'order_again', $order->get_id(), wc_get_cart_url() ), 'woocommerce-order_again' ) : '';
?>

<section class="fh-card fh-view-order">
	<p class="fh-view-order__status">
		<?php
		printf(
			/* translators: 1: order date, 2: order status */
			esc_html__( 'Reservation placed on %1$s — currently %2$s.', 'fishotel' ),
			'<strong>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</strong>',
			'<strong>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</strong>'
		);
		?>
	</p>

	<?php
	$delivery_date = function_exists( 'fishotel_get_effective_delivery_date' )
		? fishotel_get_effective_delivery_date( $order )
		: (string) $order->get_meta( '_fishotel_shipping_date' );
	$delivery_ts   = '' !== $delivery_date ? strtotime( $delivery_date ) : false;
	$can_change    = class_exists( 'FisHotel_Self_Serve_Date' ) && FisHotel_Self_Serve_Date::can_change( $order );
	$lock_reason   = class_exists( 'FisHotel_Self_Serve_Date' ) ? FisHotel_Self_Serve_Date::lock_reason( $order ) : '';
	if ( $delivery_ts ) : ?>
		<div class="fh-view-order__delivery" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>" id="fh-change-date">
			<div class="fh-view-order__delivery-eyebrow"><?php esc_html_e( 'Your Reserved Delivery Date', 'fishotel' ); ?></div>
			<div class="fh-view-order__delivery-date"><?php echo esc_html( date_i18n( 'l, F j, Y', $delivery_ts ) ); ?></div>

			<?php if ( $can_change ) : ?>
				<div class="fh-view-order__delivery-actions">
					<button type="button" class="fh-change-date-btn"><?php esc_html_e( 'Change Delivery Date', 'fishotel' ); ?></button>
				</div>
				<div class="fh-change-date-form" style="display:none;">
					<select class="fh-date-select" aria-label="<?php esc_attr_e( 'Select new delivery date', 'fishotel' ); ?>">
						<option><?php esc_html_e( 'Loading available dates…', 'fishotel' ); ?></option>
					</select>
					<button type="button" class="fh-save-date-btn" disabled><?php esc_html_e( 'Confirm', 'fishotel' ); ?></button>
					<button type="button" class="fh-cancel-date-btn"><?php esc_html_e( 'Cancel', 'fishotel' ); ?></button>
					<div class="fh-date-message" aria-live="polite"></div>
				</div>
				<?php wp_nonce_field( FisHotel_Self_Serve_Date::NONCE_ACTION, FisHotel_Self_Serve_Date::NONCE_FIELD ); ?>
			<?php elseif ( '' !== $lock_reason ) : ?>
				<div class="fh-view-order__delivery-locked">
					<?php
					switch ( $lock_reason ) {
						case 'cutoff':
							esc_html_e( 'Date changes are locked within 48 hours of delivery.', 'fishotel' );
							break;
						case 'shipped':
							esc_html_e( 'Your order has already shipped — the delivery date can no longer be changed.', 'fishotel' );
							break;
						case 'status':
							esc_html_e( 'Date changes are no longer available for this order.', 'fishotel' );
							break;
					}
					?>
					<a href="<?php echo esc_url( home_url( '/contacts/' ) ); ?>"><?php esc_html_e( 'Contact us', 'fishotel' ); ?></a>
					<?php esc_html_e( 'if you need to make a change.', 'fishotel' ); ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $notes ) : ?>
		<ol class="fh-view-order__notes">
			<?php foreach ( $notes as $note ) : ?>
				<li class="fh-view-order__note">
					<span class="fh-view-order__note-date"><?php echo esc_html( date_i18n( wc_date_format(), strtotime( $note->comment_date ) ) ); ?></span>
					<span class="fh-view-order__note-content"><?php echo wp_kses_post( wpautop( wptexturize( $note->comment_content ) ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<?php
	/**
	 * Standard WooCommerce items table + totals + billing/shipping addresses.
	 * Skinned via assets/css/my-account.css.
	 */
	do_action( 'woocommerce_view_order', $order_id );
	?>

	<div class="fh-form-actions fh-view-order__actions">
		<?php if ( $reorder_url ) : ?>
			<a class="fh-btn fh-btn--primary" href="<?php echo esc_url( $reorder_url ); ?>"><?php esc_html_e( 'REORDER', 'fishotel' ); ?></a>
		<?php endif; ?>
		<a class="fh-btn fh-btn--ghost" href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>"><?php esc_html_e( '← BACK TO PAST STAYS', 'fishotel' ); ?></a>
	</div>
</section>
