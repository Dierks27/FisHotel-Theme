<?php
/**
 * FisHotel — Forwarding Addresses (billing + shipping display).
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

$customer_id = get_current_user_id();

if ( ! wc_ship_to_billing_address_only() && wc_shipping_enabled() ) {
	$get_addresses = apply_filters(
		'woocommerce_my_account_get_addresses',
		[
			'billing'  => __( 'Billing address', 'woocommerce' ),
			'shipping' => __( 'Shipping address', 'woocommerce' ),
		],
		$customer_id
	);
} else {
	$get_addresses = apply_filters(
		'woocommerce_my_account_get_addresses',
		[ 'billing' => __( 'Billing address', 'woocommerce' ) ],
		$customer_id
	);
}

$labels = [
	'billing'  => __( 'BILLING ADDRESS', 'fishotel' ),
	'shipping' => __( 'SHIPPING ADDRESS', 'fishotel' ),
];
?>

<div class="fh-address-grid">
	<?php foreach ( $get_addresses as $name => $title ) :
		$address = wc_get_account_formatted_address( $name );
		?>
		<div class="fh-card fh-address-card">
			<span class="fh-corner fh-corner--tr" aria-hidden="true"></span>
			<span class="fh-corner fh-corner--bl" aria-hidden="true"></span>

			<div class="fh-address-card__label"><?php echo esc_html( $labels[ $name ] ?? $title ); ?></div>

			<address class="fh-address-card__body">
				<?php echo $address ? wp_kses_post( $address ) : esc_html__( 'You have not set up this type of address yet.', 'fishotel' ); ?>
			</address>

			<a class="fh-btn fh-btn--mini-ghost" href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address', $name ) ); ?>">
				<?php esc_html_e( 'EDIT →', 'fishotel' ); ?>
			</a>
		</div>
	<?php endforeach; ?>
</div>
