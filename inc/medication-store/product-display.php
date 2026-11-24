<?php
/**
 * Medication store — product page rewiring.
 *
 * Renders:
 *   - The "Buy on Amazon →" CTA in place of Add to Cart for `amazon`-
 *     mode products.
 *   - The neutral "Ships direct from our supplier." line for `ea`-mode
 *     products (until v1.29.0: "Fulfilled by EverythingAquatic, our
 *     trusted medication partner.").
 *
 * Since v1.29.0 the eyebrow tagline and the "Dose it with our Medication
 * Calculator" link are no longer printed: a medication page is a plain
 * product listing (inc/medication-store/listing-rules.php). The tagline
 * and the dosing anchor stay in wp-admin and in the database.
 *   - Add-to-cart blocking for `amazon`-mode products in case anyone
 *     reaches the form via REST/scripts.
 *
 * The single-product.php template includes a small `if ( $is_amazon )`
 * branch that consults fishotel_med_render_amazon_panel(). Everything
 * else hooks in via standard WC actions so the template stays small.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Product_Display {

	public static function init() {
		// No eyebrow tagline and no calculator link on the product page
		// since v1.29.0 (plain product listing). render_purchase_eyebrow()
		// and render_calculator_link() are kept, unhooked, so a child
		// theme or snippet calling them keeps working; both print nothing.
		// Neutral shipping line (mode = ea only).
		add_action( 'woocommerce_after_add_to_cart_form', [ __CLASS__, 'render_ea_disclosure' ], 20 );
		// Defensive: block server-side add-to-cart for amazon-mode meds.
		add_filter( 'woocommerce_add_to_cart_validation',  [ __CLASS__, 'block_amazon_atc' ], 10, 2 );
		add_filter( 'woocommerce_is_purchasable',          [ __CLASS__, 'amazon_is_not_purchasable' ], 10, 2 );
	}

	/**
	 * Until v1.29.0: the eyebrow tagline ("The Persistent Worm
	 * Specialist") above the variation block. Taglines are treatment
	 * claims by nature, so nothing is printed any more; the field stays
	 * in wp-admin. Kept as a no-op for any external caller.
	 */
	public static function render_purchase_eyebrow() {}

	/**
	 * Until v1.29.0: the "Dose it with our Medication Calculator →" link.
	 * Product pages no longer link to the calculator (the calculator stays
	 * a standalone page). Kept as a no-op for any external caller.
	 */
	public static function render_calculator_link() {}

	/**
	 * Neutral shipping line for EA-mode products: "Ships direct from our
	 * supplier." (until v1.29.0 it read "Fulfilled by EverythingAquatic,
	 * our trusted medication partner.").
	 */
	public static function render_ea_disclosure() {
		if ( ! is_product() ) return;
		$pid = get_queried_object_id();
		if ( ! fishotel_med_is_med_product( $pid ) ) return;
		// Raw meta, NOT fishotel_med_get_mode(): the helper defaults an unset
		// mode to 'ea', so an untagged Medications product would show
		// the EA shipping line while every fulfillment path
		// (slip, EA column, mixed-order split) treats it as non-EA.
		if ( get_post_meta( $pid, '_fishotel_fulfillment', true ) !== 'ea' ) return;
		// Tier 2 (Amazon-affiliate) products are never EA-fulfilled, so the
		// disclosure copy would mislead. Belt-and-suspenders on top of the
		// mode check above — covers any future hybrid product that ends up
		// with mode=ea while also carrying an `_fishotel_amazon_asin` value.
		if ( function_exists( 'fishotel_is_amazon_affiliate_product' ) && fishotel_is_amazon_affiliate_product( $pid ) ) return;
		// The description already ends with the same line (written by the
		// Misc plugin's Med listings cleanup): print it once, not twice.
		if ( FisHotel_Med_Listing::says_shipping_line( (string) get_post_field( 'post_content', $pid ) ) ) return;
		?>
		<p class="fishotel-med-ea-disclosure">
			<?php echo esc_html( FisHotel_Med_Listing::SHIPPING_LINE ); ?>
		</p>
		<?php
	}

	/**
	 * Render the Amazon CTA panel (called from single-product.php inline
	 * branch). Returns markup that fully replaces the Add-to-Cart form.
	 */
	public static function render_amazon_panel( $product ) {
		if ( ! $product instanceof WC_Product ) return;
		$pid = $product->get_id();
		$url = fishotel_med_build_amazon_url( $pid );
		?>
		<div class="fishotel-med-amazon-panel">
			<?php if ( $url === '' ) : ?>
				<p class="fishotel-med-amazon-panel__warning">
					<?php esc_html_e( 'Amazon ASIN is not set on this product yet — outbound link is unavailable. Add the ASIN in the Medication & food tab of this product to enable the button.', 'fishotel' ); ?>
				</p>
			<?php else : ?>
				<a class="fh-btn fh-btn--gold fh-btn--full fishotel-med-amazon-panel__btn"
				   href="<?php echo esc_url( $url ); ?>"
				   target="_blank"
				   rel="noopener nofollow sponsored">
					<?php esc_html_e( 'Buy on Amazon', 'fishotel' ); ?>
					<span aria-hidden="true" class="fishotel-med-amazon-panel__arrow">&rarr;</span>
				</a>
				<p class="fishotel-med-amazon-panel__note">
					<?php esc_html_e( 'EverythingAquatic doesn\'t stock this one. We earn a small commission if you buy through this link — at no extra cost to you.', 'fishotel' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Defensive: stop add-to-cart of an amazon-mode med even if someone
	 * forges the request. Returns false (block) with a notice.
	 */
	public static function block_amazon_atc( $valid, $product_id ) {
		if ( ! $product_id ) return $valid;
		if ( ! fishotel_med_is_med_product( $product_id ) ) return $valid;
		if ( self::is_amazon_mode( $product_id ) ) {
			wc_add_notice(
				__( 'This medication is sold via Amazon — please use the Buy on Amazon button instead.', 'fishotel' ),
				'error'
			);
			return false;
		}
		return $valid;
	}

	/**
	 * Amazon-mode meds should not show as "purchasable" — keeps WC
	 * widgets, REST endpoints, and analytics in sync with the
	 * frontend rewiring.
	 */
	public static function amazon_is_not_purchasable( $purchasable, $product ) {
		if ( ! $product instanceof WC_Product ) return $purchasable;
		// Resolve variations to the parent BEFORE the Medications check:
		// a variation carries no product_cat terms, so checking the
		// variation's own ID returned early and left variations of an
		// Amazon-mode variable product purchasable.
		$lookup = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		if ( ! $lookup || ! fishotel_med_is_med_product( $lookup ) ) return $purchasable;
		if ( self::is_amazon_mode( $lookup ) ) {
			return false;
		}
		return $purchasable;
	}

	/**
	 * True when a medication product must be presented as Amazon-mode.
	 *
	 * WHY: mode === 'amazon' is the explicit switch, but a product that
	 * carries an Amazon ASIN with no mode set (live example: Copper Power,
	 * #33380 — ASIN, no price, mode unset so the helper defaults it to
	 * 'ea') rendered "In Stock — Ready to Ship" + Add to Cart, and WC then
	 * rejected the add with nowhere else to go. The template already
	 * branches on fishotel_med_is_amazon_panel(), so widening the
	 * predicate here fixes the PDP, the purchasable filter and the
	 * add-to-cart block in one place. Callers must already have checked
	 * fishotel_med_is_med_product().
	 *
	 * The ASIN fallback applies ONLY when the mode is unset: the save handler
	 * keeps the ASIN when Jeff flips a med to EA/self (hybrid products keep
	 * it for the affiliate alternative — see render_ea_disclosure), and an
	 * explicit 'ea'/'self' choice must never be hijacked into Amazon-only.
	 *
	 * @param int $product_id Parent/simple product ID (never a variation).
	 * @return bool
	 */
	public static function is_amazon_mode( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! $product_id ) return false;
		// Raw meta, not fishotel_med_get_mode(): the helper defaults unset to
		// 'ea', which would hide the "mode never chosen" case we need here.
		$raw = strtolower( trim( (string) get_post_meta( $product_id, '_fishotel_fulfillment', true ) ) );
		if ( $raw === 'amazon' ) return true;
		if ( $raw === 'ea' || $raw === 'self' ) return false;
		return function_exists( 'fishotel_is_amazon_affiliate_product' )
			&& fishotel_is_amazon_affiliate_product( $product_id );
	}
}

/**
 * Template helper — called from single-product.php to decide whether
 * to render the Amazon panel in place of the Add-to-Cart form.
 *
 * Returns true when the product is an amazon-mode medication — explicit
 * mode, OR an Amazon ASIN on a product whose mode was never set (see
 * FisHotel_Med_Product_Display::is_amazon_mode() for why). The caller
 * should then invoke fishotel_med_render_amazon_panel($product).
 */
function fishotel_med_is_amazon_panel( $product ) {
	if ( ! $product instanceof WC_Product ) return false;
	$pid = $product->get_id();
	if ( ! fishotel_med_is_med_product( $pid ) ) return false;
	return FisHotel_Med_Product_Display::is_amazon_mode( $pid );
}

/** Render the Amazon CTA panel. Thin wrapper for use in templates. */
function fishotel_med_render_amazon_panel( $product ) {
	FisHotel_Med_Product_Display::render_amazon_panel( $product );
}
