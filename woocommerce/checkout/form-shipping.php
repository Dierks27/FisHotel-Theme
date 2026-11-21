<?php
/**
 * FisHotel — Checkout shipping information form (override).
 *
 * Copied from WooCommerce's templates/checkout/form-shipping.php, @version
 * 3.6.0 (unchanged through WC 9.x–11.1). The `.woocommerce-shipping-fields`
 * block is verbatim: the `#ship-to-different-address-checkbox` input and the
 * `.shipping_address` div are contracts that main.js (external toggle) and
 * woocommerce.css (`:has(.shipping_address[style*="display: none"])` card
 * hide) depend on.
 *
 * WHY the override exists: WC's stock template ALSO renders the
 * `.woocommerce-additional-fields` block, firing `woocommerce_before_order_notes`
 * / `woocommerce_after_order_notes` inside the (usually hidden) Delivery
 * Details card. form-checkout.php fires the same two hooks again outside
 * that card, so the Batch Plugin's required delivery-date <select> rendered
 * TWICE with the same name — with "Ship to a different address" open, the
 * date chosen in the first picker was discarded (last same-name field wins)
 * and checkout failed with "Please select a delivery date." The order-notes
 * block is dropped here and rendered exactly once by form-checkout.php.
 *
 * @package FisHotel
 * @version 3.6.0
 * @global WC_Checkout $checkout
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="woocommerce-shipping-fields">
	<?php if ( true === WC()->cart->needs_shipping_address() ) : ?>

		<h3 id="ship-to-different-address">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
				<input id="ship-to-different-address-checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" <?php checked( apply_filters( 'woocommerce_ship_to_different_address_checked', 'shipping' === get_option( 'woocommerce_ship_to_destination' ) ? 1 : 0 ), 1 ); ?> type="checkbox" name="ship_to_different_address" value="1" /> <span><?php esc_html_e( 'Ship to a different address?', 'woocommerce' ); ?></span>
			</label>
		</h3>

		<div class="shipping_address">

			<?php do_action( 'woocommerce_before_checkout_shipping_form', $checkout ); ?>

			<div class="woocommerce-shipping-fields__field-wrapper">
				<?php
				$fields = $checkout->get_checkout_fields( 'shipping' );

				foreach ( $fields as $key => $field ) {
					woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
				}
				?>
			</div>

			<?php do_action( 'woocommerce_after_checkout_shipping_form', $checkout ); ?>

		</div>

	<?php endif; ?>
</div>
<?php /* `.woocommerce-additional-fields` (order notes + before/after_order_notes
        hooks) intentionally omitted — see the file header; form-checkout.php
        renders it once, outside the shipping card. */ ?>
