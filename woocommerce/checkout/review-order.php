<?php
/**
 * FisHotel — Checkout Review Order (Itemized Statement)
 *
 * Renders inside #order_review on the checkout page. Replaces WC's
 * default review-order.php with the compact "hotel folio" line items
 * and a ledger (subtotal · shipping · tax · total) — no payment box
 * here; payment lives in its own card in form-checkout.php. WC's AJAX
 * update_order_review replaces the .woocommerce-checkout-review-order-table
 * element each time the cart / address changes, so all ledger values
 * stay in sync without a full page reload.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

$cart            = WC()->cart;
$cart_count      = (int) $cart->get_cart_contents_count();
$preset          = function_exists( 'fishotel_cart_resolve_preset' ) ? fishotel_cart_resolve_preset() : 'default';
$shipping_label  = function_exists( 'fishotel_cart_preset_get' ) ? (string) fishotel_cart_preset_get( $preset, 'shipping_label' )   : __( 'Shipping', 'fishotel' );
$shipping_subtxt = function_exists( 'fishotel_cart_preset_get' ) ? (string) fishotel_cart_preset_get( $preset, 'shipping_subtext' ) : '';
$billing_state   = WC()->customer ? WC()->customer->get_billing_state() : '';
$shipping_total  = $cart->get_shipping_total();
$noun            = function_exists( 'fishotel_cart_preset_noun' )
	? fishotel_cart_preset_noun( $preset, $cart_count )
	: ( $cart_count === 1 ? __( 'item', 'fishotel' ) : __( 'items', 'fishotel' ) );
?>
<table class="shop_table woocommerce-checkout-review-order-table fh-statement-table">
	<tbody>
		<?php
		do_action( 'woocommerce_review_order_before_cart_contents' );

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) :
			$_product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
			$product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );

			if ( ! ( $_product && $_product->exists() && $cart_item['quantity'] > 0
				&& apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key ) ) ) {
				continue;
			}

			$thumb_html_raw = function_exists( 'fishotel_get_product_thumb_html' )
				? fishotel_get_product_thumb_html( $product_id, 'woocommerce_thumbnail' )
				: $_product->get_image( 'woocommerce_thumbnail' );
			$thumb_html = apply_filters( 'woocommerce_cart_item_thumbnail', $thumb_html_raw, $cart_item, $cart_item_key );

			$item_name = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );

			// Variation pills — collapse all chosen attributes into a single
			// inline "WORMS · 1OZ" string instead of WC's default stacked
			// "Type:\nWorms\nBag Size:\n1oz" output.
			$variation_pills = [];
			if ( $_product->is_type( 'variation' ) ) {
				foreach ( $_product->get_variation_attributes() as $attr_value ) {
					if ( $attr_value !== '' ) {
						$variation_pills[] = $attr_value;
					}
				}
			}

			$is_fish_item = function_exists( 'fishotel_is_quarantined_fish' )
				&& fishotel_is_quarantined_fish( $product_id );

			$subtotal = apply_filters(
				'woocommerce_cart_item_subtotal',
				$cart->get_product_subtotal( $_product, $cart_item['quantity'] ),
				$cart_item,
				$cart_item_key
			);
			?>
			<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item fh-statement-item', $cart_item, $cart_item_key ) ); ?>">
				<td class="fh-statement-item__thumb"><?php echo $thumb_html; ?></td>
				<td class="fh-statement-item__body">
					<div class="fh-statement-item__title"><?php echo wp_kses_post( $item_name ); ?></div>
					<?php if ( $variation_pills || $is_fish_item || $cart_item['quantity'] > 0 ) : ?>
					<div class="fh-statement-item__meta">
						<?php foreach ( $variation_pills as $pill_val ) : ?>
							<span class="fh-cart-pill fh-cart-pill--size"><?php echo esc_html( $pill_val ); ?></span>
						<?php endforeach; ?>
						<?php if ( $is_fish_item ) : ?>
							<span class="fh-cart-pill fh-cart-pill--qt"><?php esc_html_e( 'QT Complete', 'fishotel' ); ?></span>
						<?php endif; ?>
						<span class="fh-statement-item__qty">&times;<?php echo esc_html( $cart_item['quantity'] ); ?></span>
					</div>
					<?php endif; ?>
				</td>
				<td class="fh-statement-item__price"><?php echo $subtotal; ?></td>
			</tr>
		<?php endforeach;

		do_action( 'woocommerce_review_order_after_cart_contents' );
		?>
	</tbody>

	<tfoot class="fh-statement-ledger">
		<tr class="fh-statement-ledger__row fh-statement-ledger__subtotal">
			<td colspan="2" class="fh-statement-ledger__label">
				<?php printf( esc_html__( 'Subtotal · %1$d %2$s', 'fishotel' ), $cart_count, esc_html( $noun ) ); ?>
			</td>
			<td class="fh-statement-ledger__value"><?php wc_cart_totals_subtotal_html(); ?></td>
		</tr>

		<?php foreach ( $cart->get_coupons() as $code => $coupon ) : ?>
		<tr class="fh-statement-ledger__row fh-statement-ledger__coupon">
			<td colspan="2" class="fh-statement-ledger__label"><?php wc_cart_totals_coupon_label( $coupon ); ?></td>
			<td class="fh-statement-ledger__value"><?php wc_cart_totals_coupon_html( $coupon ); ?></td>
		</tr>
		<?php endforeach; ?>

		<?php if ( $cart->needs_shipping() && $cart->show_shipping() ) : ?>
		<tr class="fh-statement-ledger__row fh-statement-ledger__shipping">
			<td colspan="2" class="fh-statement-ledger__label">
				<span class="fh-statement-ledger__label-main"><?php echo esc_html( $shipping_label ); ?></span>
				<?php if ( $shipping_subtxt !== '' ) : ?>
					<small class="fh-statement-ledger__label-sub"><?php echo esc_html( $shipping_subtxt ); ?></small>
				<?php endif; ?>
			</td>
			<td class="fh-statement-ledger__value">
				<?php if ( $shipping_total > 0 ) : ?>
					<?php echo wc_price( $shipping_total ); ?>
				<?php else : ?>
					<span class="fh-cart-ledger__placeholder"><?php esc_html_e( 'Calculated at checkout', 'fishotel' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php endif; ?>

		<?php foreach ( $cart->get_fees() as $fee ) : ?>
		<tr class="fh-statement-ledger__row fh-statement-ledger__fee">
			<td colspan="2" class="fh-statement-ledger__label"><?php echo esc_html( $fee->name ); ?></td>
			<td class="fh-statement-ledger__value"><?php wc_cart_totals_fee_html( $fee ); ?></td>
		</tr>
		<?php endforeach; ?>

		<?php if ( wc_tax_enabled() && ! $cart->display_prices_including_tax() ) : ?>
			<?php if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) : ?>
				<?php foreach ( $cart->get_tax_totals() as $code => $tax ) : ?>
				<tr class="fh-statement-ledger__row fh-statement-ledger__tax">
					<td colspan="2" class="fh-statement-ledger__label"><?php echo esc_html( $tax->label ); ?></td>
					<td class="fh-statement-ledger__value"><?php echo wp_kses_post( $tax->formatted_amount ); ?></td>
				</tr>
				<?php endforeach; ?>
			<?php else : ?>
			<tr class="fh-statement-ledger__row fh-statement-ledger__tax">
				<td colspan="2" class="fh-statement-ledger__label">
					<?php echo $billing_state
						? sprintf( esc_html__( 'Tax · %s', 'fishotel' ), esc_html( $billing_state ) )
						: esc_html( WC()->countries->tax_or_vat() ); ?>
				</td>
				<td class="fh-statement-ledger__value"><?php wc_cart_totals_taxes_total_html(); ?></td>
			</tr>
			<?php endif; ?>
		<?php endif; ?>

		<tr class="fh-statement-ledger__row fh-statement-ledger__total">
			<td colspan="2" class="fh-statement-ledger__label"><?php esc_html_e( 'Total', 'fishotel' ); ?></td>
			<td class="fh-statement-ledger__value"><?php wc_cart_totals_order_total_html(); ?></td>
		</tr>

		<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>
	</tfoot>
</table>
