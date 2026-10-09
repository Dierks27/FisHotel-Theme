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

// Defensive bail-out: if WC's cart isn't a usable WC_Cart instance the
// rest of the template (which calls cart methods unconditionally) would
// fatal mid-render and the WP critical-error template would leak in.
// Returning early lets WC's AJAX update_order_review still replace the
// fragment cleanly on the next cart change.
$cart = ( function_exists( 'WC' ) && WC() ) ? WC()->cart : null;
if ( ! $cart instanceof WC_Cart ) {
	return;
}

// Display count excludes sticker lines (Misc plugin giveaway, cart item data
// fh_sticker) so "3 fish" stays 3 fish; WC's own contents count is untouched.
$cart_count      = function_exists( 'fishotel_cart_display_count' )
	? fishotel_cart_display_count()
	: (int) $cart->get_cart_contents_count();
$preset          = function_exists( 'fishotel_cart_resolve_preset' ) ? fishotel_cart_resolve_preset() : 'default';
$shipping_label  = function_exists( 'fishotel_cart_preset_get' ) ? (string) fishotel_cart_preset_get( $preset, 'shipping_label' )   : __( 'Shipping', 'fishotel' );
$shipping_subtxt = function_exists( 'fishotel_cart_preset_get' ) ? (string) fishotel_cart_preset_get( $preset, 'shipping_subtext' ) : '';
$billing_state   = WC()->customer ? WC()->customer->get_billing_state() : '';
$shipping_total  = $cart->get_shipping_total();
// A chosen $0 rate (Free class, repeat-customer piggyback) is a real price,
// not "not calculated yet" — only fall back to the placeholder when no rate
// has been resolved.
$shipping_rate   = function_exists( 'fishotel_cart_chosen_shipping_rate' ) ? fishotel_cart_chosen_shipping_rate() : null;
$noun            = function_exists( 'fishotel_cart_preset_noun' )
	? fishotel_cart_preset_noun( $preset, $cart_count )
	: ( $cart_count === 1 ? __( 'item', 'fishotel' ) : __( 'items', 'fishotel' ) );

// Gift-card CODE applied to the cart (WooCommerce Gift Cards). Feeds the
// hidden .fh-gc-applied marker in the Total row, which main.js's
// initGiftCardSmartButtonGuard() reads to suppress PPCP's smart / express
// buttons: those build the PayPal order from the cart line items and miss
// the gift-card deduction, so a code applied at checkout left the
// double-charge path (order #34494) open — the existing guard only saw the
// account-BALANCE checkbox. Rendered here because this template is re-run
// on every update_order_review fragment, so the marker tracks apply/remove.
// Fails closed — any doubt about the plugin API means no marker (buttons
// stay visible, exactly as before), never a fatal mid-fragment.
$gc_applied = false;
if ( function_exists( 'WC_GC' ) ) {
	try {
		$gc_plugin = WC_GC();
		if ( is_object( $gc_plugin ) && isset( $gc_plugin->giftcards ) && is_object( $gc_plugin->giftcards )
			&& method_exists( $gc_plugin->giftcards, 'get_applied_giftcards' ) ) {
			$gc_applied_list = $gc_plugin->giftcards->get_applied_giftcards( 'cart' );
			$gc_applied      = is_array( $gc_applied_list ) && ! empty( $gc_applied_list );
		}
	} catch ( \Throwable $e ) {
		$gc_applied = false;
	}
}
?>
<table class="shop_table woocommerce-checkout-review-order-table fh-statement-table">
	<tbody>
		<?php
		do_action( 'woocommerce_review_order_before_cart_contents' );

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) :
			$item_data  = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			$item_pid   = isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0;
			$_product   = apply_filters( 'woocommerce_cart_item_product', $item_data, $cart_item, $cart_item_key );
			$product_id = (int) apply_filters( 'woocommerce_cart_item_product_id', $item_pid, $cart_item, $cart_item_key );
			$quantity   = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;

			// Skip the row unless we have a real, queryable WC_Product. A
			// plugin filter on `woocommerce_cart_item_product` can return
			// false/null/stdClass for an orphaned variation or a deleted
			// parent — the old `$_product->exists()` call would fatal on
			// those before this guard short-circuited.
			if ( ! ( $_product instanceof WC_Product && $_product->exists() && $quantity > 0
				&& apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key ) ) ) {
				continue;
			}

			$thumb_html_raw = function_exists( 'fishotel_get_product_thumb_html' )
				? fishotel_get_product_thumb_html( $product_id ?: $_product->get_id(), 'woocommerce_thumbnail' )
				: $_product->get_image( 'woocommerce_thumbnail' );
			$thumb_html = apply_filters( 'woocommerce_cart_item_thumbnail', $thumb_html_raw, $cart_item, $cart_item_key );

			$item_name = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );

			// Variation pills — collapse all chosen attributes into a single
			// inline "WORMS · 1OZ" string instead of WC's default stacked
			// "Type:\nWorms\nBag Size:\n1oz" output. Guard against products
			// that report is_type('variation') via filter but don't actually
			// expose get_variation_attributes(), and against an attribute
			// list that comes back non-array from a misbehaving plugin.
			// Resolve slug values to term names so attributes whose names
			// contain characters WP strips from slugs (`"`, `.`, `<`, `>`,
			// `+`) render as entered in admin.
			$variation_pills = [];
			if ( $_product->is_type( 'variation' ) && method_exists( $_product, 'get_variation_attributes' ) ) {
				$variation_attrs = $_product->get_variation_attributes();
				if ( is_array( $variation_attrs ) ) {
					foreach ( $variation_attrs as $attr_key => $attr_value ) {
						if ( ! is_scalar( $attr_value ) || (string) $attr_value === '' ) continue;
						$taxonomy = preg_replace( '/^attribute_/', '', (string) $attr_key );
						$label    = function_exists( 'fishotel_variation_option_label' )
							? fishotel_variation_option_label( $taxonomy, (string) $attr_value )
							: (string) $attr_value;
						$variation_pills[] = $label;
					}
				}
			}

			$is_fish_item = $product_id > 0
				&& function_exists( 'fishotel_is_quarantined_fish' )
				&& fishotel_is_quarantined_fish( $product_id );

			$subtotal = apply_filters(
				'woocommerce_cart_item_subtotal',
				$cart->get_product_subtotal( $_product, $quantity ),
				$cart_item,
				$cart_item_key
			);
			?>
			<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item fh-statement-item', $cart_item, $cart_item_key ) ); ?>">
				<td class="fh-statement-item__thumb"><?php echo $thumb_html; ?></td>
				<td class="fh-statement-item__body">
					<div class="fh-statement-item__title"><?php echo wp_kses_post( $item_name ); ?></div>
					<?php /* Plugin-only cart item data (woocommerce_get_item_data): WC
					        Gift Cards recipient/message, the Misc plugin's sticker
					        label, etc. Filtered from an empty array on purpose —
					        wc_get_formatted_cart_item_data() would also list the
					        variation attributes the pills below already show.
					        Mirrors WC's hidden / key / display resolution. */ ?>
					<?php
					$extra_rows = [];
					foreach ( (array) apply_filters( 'woocommerce_get_item_data', [], $cart_item ) as $data ) {
						if ( ! is_array( $data ) || ! empty( $data['hidden'] ) ) {
							continue;
						}
						$data_key     = ! empty( $data['key'] ) ? $data['key'] : ( isset( $data['name'] ) ? $data['name'] : '' );
						$data_display = ! empty( $data['display'] ) ? $data['display'] : ( isset( $data['value'] ) ? $data['value'] : '' );
						if ( '' === trim( (string) $data_display ) ) {
							continue;
						}
						$extra_rows[] = [ 'key' => (string) $data_key, 'display' => (string) $data_display ];
					}
					?>
					<?php if ( $extra_rows ) : ?>
						<div class="fh-cart-item__meta">
							<dl class="variation">
								<?php foreach ( $extra_rows as $row ) : ?>
									<?php if ( '' !== $row['key'] ) : ?><dt><?php echo esc_html( $row['key'] ); ?>:</dt><?php endif; ?>
									<dd><?php echo wp_kses_post( $row['display'] ); ?></dd>
								<?php endforeach; ?>
							</dl>
						</div>
					<?php endif; ?>
					<?php if ( $variation_pills || $is_fish_item || $quantity > 0 ) : ?>
					<div class="fh-statement-item__meta">
						<?php foreach ( $variation_pills as $pill_val ) : ?>
							<span class="fh-cart-pill fh-cart-pill--size"><?php echo esc_html( $pill_val ); ?></span>
						<?php endforeach; ?>
						<?php if ( $is_fish_item ) : ?>
							<span class="fh-cart-pill fh-cart-pill--qt"><?php esc_html_e( 'QT Complete', 'fishotel' ); ?></span>
						<?php endif; ?>
						<span class="fh-statement-item__qty">&times;<?php echo esc_html( $quantity ); ?></span>
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

		<?php do_action( 'woocommerce_review_order_before_shipping' ); ?>

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
				<?php elseif ( $shipping_rate ) : ?>
					<?php echo wc_price( $shipping_rate->get_cost() ); ?>
					<?php /* The rate label carries the "Piggybacking on order #N" suffix. */ ?>
					<small class="fh-statement-ledger__label-sub"><?php echo esc_html( $shipping_rate->get_label() ); ?></small>
				<?php else : ?>
					<span class="fh-cart-ledger__placeholder"><?php esc_html_e( 'Calculated at checkout', 'fishotel' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php endif; ?>

		<?php do_action( 'woocommerce_review_order_after_shipping' ); ?>

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
					<?php
					if ( $billing_state ) {
						printf( esc_html__( 'Tax · %s', 'fishotel' ), esc_html( $billing_state ) );
					} else {
						$tax_label = ( WC()->countries instanceof WC_Countries )
							? WC()->countries->tax_or_vat()
							: __( 'Tax', 'fishotel' );
						echo esc_html( $tax_label );
					}
					?>
				</td>
				<td class="fh-statement-ledger__value"><?php wc_cart_totals_taxes_total_html(); ?></td>
			</tr>
			<?php endif; ?>
		<?php endif; ?>

		<tr class="fh-statement-ledger__row fh-statement-ledger__total">
			<td colspan="2" class="fh-statement-ledger__label">
				<?php esc_html_e( 'Total', 'fishotel' ); ?>
				<?php if ( $gc_applied ) : ?>
					<?php /* Marker for main.js (see $gc_applied above). Lives INSIDE
					        the table so the AJAX fragment swap carries it in and
					        out; inside a <td> so it is valid table content. No
					        `name` — it must never be POSTed with the checkout. */ ?>
					<input type="hidden" class="fh-gc-applied" value="1">
				<?php endif; ?>
			</td>
			<td class="fh-statement-ledger__value"><?php wc_cart_totals_order_total_html(); ?></td>
		</tr>

		<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>
	</tfoot>
</table>
<?php /* NOTE: `woocommerce_review_order_before_order_total` (WC Gift Cards'
        balance checkbox) is intentionally NOT fired here. WC's AJAX
        update_order_review re-renders this template into the
        `.woocommerce-checkout-review-order-table` fragment and replaces the
        <table> element with it — but the fragment is this whole template's
        output, so anything echoed after </table> got duplicated (one copy
        from the initial PHP render that stays put, plus a new copy folded
        into the replaced table). The hook now fires once in
        form-checkout.php, OUTSIDE #order_review, so AJAX can't clone it. */ ?>
