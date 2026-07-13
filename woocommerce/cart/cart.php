<?php
/**
 * FisHotel — Cart Page Template
 *
 * Renders the populated-cart layout: page hero, two-column reservations
 * + order-total cards, and a full-width trust strip. All copy comes from
 * FisHotel Settings → Cart Page so nothing in this file needs editing
 * to change wording, image, or links.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_cart' );

$kses_inline = [ 'em' => [], 'strong' => [], 'i' => [], 'b' => [] ];

$cart_count = (int) WC()->cart->get_cart_contents_count();
// Cart-content-aware preset (default / fish / meds / food / merch).
// Drives the subtitle, shipping line, trust strip, and the Subtotal
// row's noun word.
$preset     = fishotel_cart_resolve_preset();
$eyebrow    = (string) FisHotel_Admin_Settings::get( 'fh_cart_hero_eyebrow' );
$title_html = (string) FisHotel_Admin_Settings::get( 'fh_cart_hero_title_html' );
$sub_tpl    = fishotel_cart_preset_get( $preset, 'subtitle_template' );
$subtitle   = fishotel_cart_resolve_subtitle( $sub_tpl, $cart_count );
?>
<div class="page-hero fh-cart-hero">
	<div class="page-hero__inner">
		<nav class="page-hero__breadcrumb">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'fishotel' ); ?></a>
			<span>·</span>
			<span><?php esc_html_e( 'Your Cart', 'fishotel' ); ?></span>
		</nav>
		<div class="fh-cart-hero__center">
			<div class="fh-cart-hero__eyebrow"><?php echo esc_html( $eyebrow ); ?></div>
			<h1 class="fh-cart-hero__title"><?php echo wp_kses( $title_html, $kses_inline ); ?></h1>
			<div class="fh-cart-hero__rule"></div>
			<div class="fh-cart-hero__subtitle"><?php echo esc_html( $subtitle ); ?></div>
		</div>
	</div>
</div>

<div class="fh-cart-wrap">
	<form class="woocommerce-cart-form fh-cart-form" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
		<?php do_action( 'woocommerce_before_cart_table' ); ?>

		<div class="fh-cart-layout">

			<?php /* ── LEFT CARD — RESERVATIONS ──
			        The `woocommerce-cart-form__contents` class is REQUIRED, not
			        cosmetic: WC core cart.js bails out of its submit handler
			        (`if ( 0 === $form.find('.woocommerce-cart-form__contents').length ) return;`)
			        when no element carries it. Without this class every submit
			        inside the cart form — qty stepper auto-submit and coupon
			        Apply — is silently swallowed (no AJAX, no POST). cart.js
			        only tests for the class's existence, so any wrapper qualifies. */ ?>
			<section class="fh-cart-card fh-cart-items woocommerce-cart-form__contents">
				<header class="fh-cart-card__header">
					<span class="fh-cart-card__eyebrow"><?php esc_html_e( 'Checked In', 'fishotel' ); ?></span>
					<span class="fh-cart-card__cols"><?php esc_html_e( 'Price · Qty · Total', 'fishotel' ); ?></span>
				</header>

				<?php do_action( 'woocommerce_before_cart_contents' ); ?>

				<?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
					$_product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
					$product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );

					if ( ! ( $_product && $_product->exists() && $cart_item['quantity'] > 0
						&& apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) ) {
						continue;
					}

					$product_permalink = apply_filters(
						'woocommerce_cart_item_permalink',
						$_product->is_visible() ? $_product->get_permalink( $cart_item ) : '',
						$cart_item,
						$cart_item_key
					);

					$thumb_html_raw = fishotel_get_product_thumb_html( $product_id, 'fishotel-product-thumb' );
					$thumb_html     = apply_filters( 'woocommerce_cart_item_thumbnail', $thumb_html_raw, $cart_item, $cart_item_key );

					$item_name = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );

					// Scientific name lives in the product's short description (the same
					// source the PDP renders under the H1 as the italic subtitle).
					$sci_name = wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $product_id ) );

					// Variation pills — one pill per chosen attribute value.
					// `get_variation_attributes()` returns slugs keyed by
					// `attribute_<taxonomy>` (e.g. `attribute_pa_size => 2-3-5`).
					// Resolve each slug to its term name so pills preserve the
					// admin-entered label, including characters WP strips from
					// slugs (`"`, `.`, `<`, `>`, `+`).
					$variation_pills = [];
					if ( $_product->is_type( 'variation' ) ) {
						foreach ( $_product->get_variation_attributes() as $attr_key => $attr_value ) {
							if ( $attr_value === '' ) continue;
							$taxonomy = preg_replace( '/^attribute_/', '', (string) $attr_key );
							$label    = function_exists( 'fishotel_variation_option_label' )
								? fishotel_variation_option_label( $taxonomy, $attr_value )
								: $attr_value;
							$variation_pills[] = $label;
						}
					}

					$is_fish_item = function_exists( 'fishotel_is_quarantined_fish' )
						&& fishotel_is_quarantined_fish( $product_id );

					$unit_price = apply_filters(
						'woocommerce_cart_item_price',
						WC()->cart->get_product_price( $_product ),
						$cart_item,
						$cart_item_key
					);
					$subtotal   = apply_filters(
						'woocommerce_cart_item_subtotal',
						WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ),
						$cart_item,
						$cart_item_key
					);

					$remove_url = wc_get_cart_remove_url( $cart_item_key );
					$max_qty    = $_product->get_max_purchase_quantity();
					$min_qty    = 0;
					$item_class = esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) );
				?>
				<article class="fh-cart-item <?php echo $item_class; ?>">
					<div class="fh-cart-item__thumb">
						<?php echo $product_permalink ? '<a href="' . esc_url( $product_permalink ) . '">' . $thumb_html . '</a>' : $thumb_html; ?>
					</div>
					<div class="fh-cart-item__body">
						<h3 class="fh-cart-item__title">
							<?php echo $product_permalink
								? '<a href="' . esc_url( $product_permalink ) . '">' . wp_kses_post( $item_name ) . '</a>'
								: wp_kses_post( $item_name ); ?>
						</h3>
						<?php if ( $sci_name !== '' ) : ?>
							<div class="fh-cart-item__sci"><?php echo esc_html( $sci_name ); ?></div>
						<?php endif; ?>
						<?php if ( $variation_pills || $is_fish_item ) : ?>
						<div class="fh-cart-item__pills">
							<?php foreach ( $variation_pills as $pill_val ) : ?>
								<span class="fh-cart-pill fh-cart-pill--size"><?php echo esc_html( $pill_val ); ?></span>
							<?php endforeach; ?>
							<?php if ( $is_fish_item ) : ?>
								<span class="fh-cart-pill fh-cart-pill--qt"><?php esc_html_e( 'QT Complete', 'fishotel' ); ?></span>
							<?php endif; ?>
						</div>
						<?php endif; ?>
						<div class="fh-cart-item__controls">
							<div class="fh-cart-qty">
								<button type="button" class="fh-cart-qty__btn" data-action="dec" aria-label="<?php esc_attr_e( 'Decrease quantity', 'fishotel' ); ?>">−</button>
								<?php /* Only emit `max` when there's a real stock limit.
								        get_max_purchase_quantity() returns -1 for "no
								        limit"; rendering max="-1" with min="0" fails HTML
								        constraint validation permanently (max < min), so
								        the browser silently blocks EVERY cart-form submit
								        (qty auto-submit, coupon Apply) before cart.js sees
								        it. Keep min="0" — WC uses qty 0 to remove an item. */ ?>
								<input type="number"
									name="cart[<?php echo esc_attr( $cart_item_key ); ?>][qty]"
									value="<?php echo esc_attr( $cart_item['quantity'] ); ?>"
									min="<?php echo esc_attr( $min_qty ); ?>"
									<?php echo $max_qty > 0 ? 'max="' . esc_attr( $max_qty ) . '"' : ''; ?>
									class="fh-cart-qty__input"
									inputmode="numeric">
								<button type="button" class="fh-cart-qty__btn" data-action="inc" aria-label="<?php esc_attr_e( 'Increase quantity', 'fishotel' ); ?>">+</button>
							</div>
							<a href="<?php echo esc_url( $remove_url ); ?>" class="fh-cart-item__remove" data-product_id="<?php echo esc_attr( $product_id ); ?>">
								<?php esc_html_e( 'Remove', 'fishotel' ); ?>
							</a>
						</div>
					</div>
					<div class="fh-cart-item__pricing">
						<div class="fh-cart-item__unit"><?php echo $unit_price; ?></div>
						<div class="fh-cart-item__total"><?php echo $subtotal; ?></div>
					</div>
				</article>
				<?php endforeach; ?>

				<?php do_action( 'woocommerce_cart_contents' ); ?>
				<?php do_action( 'woocommerce_after_cart_contents' ); ?>

				<footer class="fh-cart-card__footer">
					<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="fh-cart-back-link">
						← <?php esc_html_e( 'Head back into the gift shop', 'fishotel' ); ?>
					</a>
				</footer>
			</section>

			<?php /* ── RIGHT CARD — ORDER TOTAL (sticky) ──
			        The `cart_totals` class lets WC core cart.js refresh totals
			        after its AJAX quantity update: update_cart_totals_div() does
			        `$('.cart_totals').replaceWith( html_str )`. This container
			        sits INSIDE .woocommerce-cart-form, so cart.js's update_wc_div
			        replaces the whole form (totals included) with the re-rendered
			        themed fragment first; the .cart_totals replace that follows
			        is an identical no-op swap. Totals update live, no reload. */ ?>
			<aside class="fh-cart-card fh-cart-totals cart_totals">
				<header class="fh-cart-card__header">
					<span class="fh-cart-card__eyebrow"><?php esc_html_e( 'Order Total', 'fishotel' ); ?></span>
				</header>

				<?php
				$billing_state    = WC()->customer ? WC()->customer->get_billing_state() : '';
				$shipping_label   = fishotel_cart_preset_get( $preset, 'shipping_label' );
				$shipping_subtext = fishotel_cart_preset_get( $preset, 'shipping_subtext' );
				$shipping_total   = WC()->cart->get_shipping_total();
				?>
				<ul class="fh-cart-ledger">
					<li class="fh-cart-ledger__row">
						<span class="fh-cart-ledger__label">
							<?php /* translators: %1$d = number of items in cart, %2$s = noun (fish / medications / items) per preset */ ?>
							<?php printf( esc_html__( 'Subtotal · %1$d %2$s', 'fishotel' ), $cart_count, esc_html( fishotel_cart_preset_noun( $preset, $cart_count ) ) ); ?>
						</span>
						<span class="fh-cart-ledger__value"><?php echo wc_price( WC()->cart->get_subtotal() ); ?></span>
					</li>
					<li class="fh-cart-ledger__row">
						<span class="fh-cart-ledger__label">
							<?php echo esc_html( $shipping_label ); ?>
							<?php if ( $shipping_subtext !== '' ) : ?>
								<small class="fh-cart-ledger__sub"><?php echo esc_html( $shipping_subtext ); ?></small>
							<?php endif; ?>
						</span>
						<span class="fh-cart-ledger__value">
							<?php echo $shipping_total > 0
								? wc_price( $shipping_total )
								: '<span class="fh-cart-ledger__placeholder">' . esc_html__( 'Calculated at checkout', 'fishotel' ) . '</span>'; ?>
						</span>
					</li>
					<li class="fh-cart-ledger__row">
						<span class="fh-cart-ledger__label">
							<?php echo $billing_state
								? sprintf( esc_html__( 'Tax · %s', 'fishotel' ), esc_html( $billing_state ) )
								: esc_html__( 'Tax', 'fishotel' ); ?>
						</span>
						<span class="fh-cart-ledger__value"><?php echo wc_price( WC()->cart->get_total_tax() ); ?></span>
					</li>
				</ul>

				<div class="fh-cart-ledger__divider"></div>

				<div class="fh-cart-ledger__total">
					<span class="fh-cart-ledger__total-label"><?php esc_html_e( 'Total', 'fishotel' ); ?></span>
					<span class="fh-cart-ledger__total-value"><?php echo WC()->cart->get_total(); ?></span>
				</div>

				<div class="fh-cart-checkout-cta">
					<a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="fh-btn fh-btn--gold fh-btn--full">
						<?php esc_html_e( 'Proceed to Checkout', 'fishotel' ); ?> →
					</a>
				</div>

				<?php
				/* No "Or pay with" express-payment block on the cart. PayPal +
				   Venmo render on the checkout page where customers land after
				   Proceed to Checkout — keeping them off the cart sidesteps the
				   plugin-injected duplicate gift-card form and the asymmetric
				   look when only one of the two methods is configured. */
				?>

				<div class="fh-cart-discounts">
					<details class="fh-cart-discount-row">
						<summary>
							<span><?php esc_html_e( 'Have a coupon code?', 'fishotel' ); ?></span>
							<span class="fh-cart-discount-row__icon" aria-hidden="true">+</span>
						</summary>
						<?php if ( wc_coupons_enabled() ) : ?>
						<?php /* Two WC core cart.js contracts are REQUIRED here:
						        1. id="coupon_code" — apply_coupon() reads the code via
						           $('#coupon_code') (by ID, not name); without it an
						           empty value is posted every time.
						        2. class="coupon" on the wrapper — show_coupon_error()
						           routes error notices to $text_field.closest('.coupon');
						           without a .coupon ancestor "does not exist"/"expired"
						           messages are silently dropped. */ ?>
						<div class="fh-cart-discount-row__body coupon">
							<input type="text" id="coupon_code" name="coupon_code" class="fh-input fh-cart-coupon__input" placeholder="<?php esc_attr_e( 'Enter coupon code', 'fishotel' ); ?>">
							<button type="submit" name="apply_coupon" value="1" class="fh-btn fh-btn--ghost fh-cart-coupon__apply"><?php esc_html_e( 'Apply', 'fishotel' ); ?></button>
						</div>
						<?php endif; ?>
					</details>
					<?php /* Gift cards are redeemed at checkout via WC Gift Cards'
					        native "Have a gift card?" field under Payment Method —
					        NOT here. The old cart row posted gift card codes to the
					        coupon endpoint (WC Gift Cards does not register codes as
					        coupons), which dead-ended with `Coupon "…" does not
					        exist!` and matched the customer "it says it didn't
					        exist" reports. It also put a second name="coupon_code"
					        input in the form, so the empty field overwrote a real
					        coupon on submit. Replaced with a hint that points to the
					        working checkout field; the cart form now contains
					        exactly one name="coupon_code" input. */ ?>
					<p class="fh-cart-giftcard-hint">
						<?php esc_html_e( 'Have a gift card? You can apply it at checkout under Payment Method.', 'fishotel' ); ?>
					</p>
				</div>
			</aside>

		</div><!-- .fh-cart-layout -->

		<?php /* Hidden update-cart button. JS clicks this on debounced qty
		        change so WC posts an update; nonce required for the action. */ ?>
		<button type="submit" name="update_cart" value="1" class="fh-cart-update-button">
			<?php esc_html_e( 'Update Cart', 'fishotel' ); ?>
		</button>
		<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
		<?php do_action( 'woocommerce_cart_actions' ); ?>
		<?php do_action( 'woocommerce_after_cart_table' ); ?>
	</form>

	<?php
	/* ── FULL-WIDTH TRUST STRIP ── */
	$trust_cols = [];
	for ( $i = 1; $i <= 4; $i++ ) {
		$label = fishotel_cart_preset_get( $preset, "trust_{$i}_label" );
		$body  = fishotel_cart_preset_get( $preset, "trust_{$i}_body" );
		if ( $label === '' && $body === '' ) {
			continue;
		}
		$trust_cols[] = [ 'label' => $label, 'body' => $body ];
	}
	if ( $trust_cols ) :
	?>
	<section class="fh-cart-trust">
		<div class="fh-cart-trust__grid">
			<?php foreach ( $trust_cols as $col ) : ?>
			<div class="fh-cart-trust__col">
				<div class="fh-cart-trust__icon" aria-hidden="true">✓</div>
				<h3 class="fh-cart-trust__label"><?php echo esc_html( $col['label'] ); ?></h3>
				<p class="fh-cart-trust__body"><?php echo esc_html( $col['body'] ); ?></p>
			</div>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>
</div>

<?php do_action( 'woocommerce_after_cart' ); ?>
