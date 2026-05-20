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
