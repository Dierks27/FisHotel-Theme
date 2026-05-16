<?php
/**
 * FisHotel — Checkout Form Template
 *
 * Two-column "Folio" layout: returning-customer + coupon accordions,
 * Guest Details + Delivery Details + Payment Method cards on the left;
 * sticky Itemized Statement on the right; trust strip below. All copy
 * comes from FisHotel Settings → Checkout Page so the template needs
 * no edits to change wording.
 *
 * @package FisHotel
 *
 * @var WC_Checkout $checkout
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_checkout_form', $checkout );

// Bail-out if checkout fields aren't ready (e.g. cart emptied via AJAX).
if ( ! $checkout->get_checkout_fields() ) {
	echo '<div class="woocommerce-error">' . esc_html__( 'Sorry, your session has expired.', 'fishotel' ) . ' <a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '" class="wc-backward">' . esc_html__( 'Return to shop', 'fishotel' ) . '</a></div>';
	return;
}

$kses_inline = [ 'em' => [], 'strong' => [], 'i' => [], 'b' => [] ];

$hero_eyebrow  = (string) FisHotel_Admin_Settings::get( 'fh_checkout_hero_eyebrow' );
$hero_title    = (string) FisHotel_Admin_Settings::get( 'fh_checkout_hero_title_html' );
$hero_subtitle = (string) FisHotel_Admin_Settings::get( 'fh_checkout_hero_subtitle' );

$section_returning = (string) FisHotel_Admin_Settings::get( 'fh_checkout_section_returning' );
$section_coupon    = (string) FisHotel_Admin_Settings::get( 'fh_checkout_section_coupon' );
$section_guest     = (string) FisHotel_Admin_Settings::get( 'fh_checkout_section_guest_details' );
$section_delivery  = (string) FisHotel_Admin_Settings::get( 'fh_checkout_section_delivery_details' );
$section_payment   = (string) FisHotel_Admin_Settings::get( 'fh_checkout_section_payment' );
$section_statement = (string) FisHotel_Admin_Settings::get( 'fh_checkout_section_statement' );

$show_login_reminder = ! is_user_logged_in() && 'no' !== get_option( 'woocommerce_enable_checkout_login_reminder' );
$show_coupon_form    = wc_coupons_enabled() && WC()->cart && ! WC()->cart->is_empty();
?>

<div class="page-hero fh-cart-hero fh-checkout-hero">
	<div class="page-hero__inner">
		<nav class="page-hero__breadcrumb">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'fishotel' ); ?></a>
			<span>·</span>
			<a href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'Cart', 'fishotel' ); ?></a>
			<span>·</span>
			<span><?php esc_html_e( 'Checkout', 'fishotel' ); ?></span>
		</nav>
		<div class="fh-cart-hero__center">
			<div class="fh-cart-hero__eyebrow"><?php echo esc_html( $hero_eyebrow ); ?></div>
			<h1 class="fh-cart-hero__title"><?php echo wp_kses( $hero_title, $kses_inline ); ?></h1>
			<div class="fh-cart-hero__rule"></div>
			<?php if ( $hero_subtitle !== '' ) : ?>
				<div class="fh-cart-hero__subtitle"><?php echo esc_html( $hero_subtitle ); ?></div>
			<?php endif; ?>
		</div>
	</div>
</div>

<div class="fh-cart-wrap fh-checkout-wrap">

	<form name="checkout" method="post" class="checkout woocommerce-checkout fh-checkout-form"
		action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data">

		<div class="fh-checkout-layout">

			<?php /* ── LEFT COLUMN ── */ ?>
			<section class="fh-checkout-left">

				<?php /* Returning customer + coupon accordions — collapsed by
				        default, same pattern as cart's discount rows. WC's
				        default top-of-page notices for both are unhooked in
				        inc/woocommerce.php so they don't duplicate here. */ ?>
				<?php if ( $show_login_reminder || $show_coupon_form ) : ?>
				<div class="fh-checkout-prompts">
					<?php if ( $show_login_reminder ) : ?>
					<details class="fh-cart-discount-row fh-checkout-accordion">
						<summary>
							<span><?php echo esc_html( $section_returning ); ?></span>
							<span class="fh-cart-discount-row__icon" aria-hidden="true">+</span>
						</summary>
						<div class="fh-cart-discount-row__body fh-checkout-login-body">
							<?php woocommerce_login_form( [
								'redirect' => wc_get_checkout_url(),
								'hidden'   => false,
								'message'  => '',
							] ); ?>
						</div>
					</details>
					<?php endif; ?>

					<?php if ( $show_coupon_form ) : ?>
					<details class="fh-cart-discount-row fh-checkout-accordion">
						<summary>
							<span><?php echo esc_html( $section_coupon ); ?></span>
							<span class="fh-cart-discount-row__icon" aria-hidden="true">+</span>
						</summary>
						<div class="fh-cart-discount-row__body fh-checkout-coupon-body">
							<?php wc_get_template( 'checkout/form-coupon.php' ); ?>
						</div>
					</details>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<?php /* Guest Details card (billing form) */ ?>
				<div class="fh-checkout-card fh-checkout-billing">
					<header class="fh-checkout-card__header">
						<span class="fh-checkout-card__eyebrow"><?php echo esc_html( $section_guest ); ?></span>
					</header>
					<?php do_action( 'woocommerce_checkout_billing' ); ?>
				</div>

				<?php /* Delivery Details card. WC toggles the inner fields'
				        visibility via the "Ship to different address"
				        checkbox; our CSS hides the card header when no
				        fields are visible so the empty card chrome doesn't
				        sit there. The checkbox itself lives inside the
				        shipping form WC renders here. */ ?>
				<div class="fh-checkout-card fh-checkout-shipping">
					<header class="fh-checkout-card__header">
						<span class="fh-checkout-card__eyebrow"><?php echo esc_html( $section_delivery ); ?></span>
					</header>
					<?php do_action( 'woocommerce_checkout_shipping' ); ?>
				</div>

				<?php /* Payment Method card. woocommerce_checkout_payment()
				        renders WC's payment box — options, terms, Place
				        Order button, and the nonce — inside #payment.
				        That element must stay in the DOM so WC's AJAX
				        fragments keep the payment HTML in sync on cart /
				        address changes. The _before / _after_payment
				        actions normally fire inside review-order.php;
				        since we moved payment out of the order-review
				        card we fire them here so plugins still see the
				        canonical injection points. */ ?>
				<div class="fh-checkout-card fh-checkout-payment fh-checkout-payment-card">
					<header class="fh-checkout-card__header">
						<span class="fh-checkout-card__eyebrow"><?php echo esc_html( $section_payment ); ?></span>
					</header>
					<?php do_action( 'woocommerce_review_order_before_payment' ); ?>
					<?php woocommerce_checkout_payment(); ?>
					<?php do_action( 'woocommerce_review_order_after_payment' ); ?>
				</div>

			</section>

			<?php /* ── RIGHT COLUMN — Itemized Statement (sticky) ── */ ?>
			<aside class="fh-checkout-right">
				<div class="fh-checkout-card fh-checkout-statement">
					<header class="fh-checkout-card__header">
						<span class="fh-checkout-card__eyebrow"><?php echo esc_html( $section_statement ); ?></span>
					</header>
					<?php /* WC's order_review hook calls review-order.php —
					        our override emits the compact line items + ledger
					        here. The #order_review wrapper element is what
					        WC's AJAX update_order_review replaces on cart /
					        address change. */ ?>
					<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>
					<div id="order_review" class="woocommerce-checkout-review-order">
						<?php do_action( 'woocommerce_checkout_order_review' ); ?>
					</div>
					<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
				</div>
			</aside>

		</div><!-- .fh-checkout-layout -->

	</form>

	<?php /* ── FULL-WIDTH TRUST STRIP ── shares the cart's preset copy */ ?>
	<?php
	$preset     = function_exists( 'fishotel_cart_resolve_preset' ) ? fishotel_cart_resolve_preset() : 'default';
	$trust_cols = [];
	for ( $i = 1; $i <= 4; $i++ ) {
		$label = function_exists( 'fishotel_cart_preset_get' )
			? (string) fishotel_cart_preset_get( $preset, "trust_{$i}_label" )
			: '';
		$body  = function_exists( 'fishotel_cart_preset_get' )
			? (string) fishotel_cart_preset_get( $preset, "trust_{$i}_body" )
			: '';
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

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
