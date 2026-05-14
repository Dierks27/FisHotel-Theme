<?php
/**
 * Medication store — product page rewiring.
 *
 * Renders:
 *   - The eyebrow tagline above the hero title (all modes).
 *   - The "Buy on Amazon →" CTA in place of Add to Cart for `amazon`-
 *     mode products.
 *   - The "Fulfilled by EverythingAquatic" disclosure footer for
 *     `ea`-mode products.
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
		// Eyebrow + form pills above hero title content. We hook the
		// theme's own `woocommerce_before_single_product_summary` so
		// the eyebrow renders in the purchase panel.
		add_action( 'woocommerce_before_single_product_summary', [ __CLASS__, 'render_purchase_eyebrow' ], 15 );
		// EA disclosure footer (mode = ea only).
		add_action( 'woocommerce_after_add_to_cart_form', [ __CLASS__, 'render_ea_disclosure' ], 20 );
		// Defensive: block server-side add-to-cart for amazon-mode meds.
		add_filter( 'woocommerce_add_to_cart_validation',  [ __CLASS__, 'block_amazon_atc' ], 10, 2 );
		add_filter( 'woocommerce_is_purchasable',          [ __CLASS__, 'amazon_is_not_purchasable' ], 10, 2 );
	}

	/**
	 * Echo the eyebrow tagline + dosing-calculator link above the
	 * variation block. Skipped when not a med product or when no
	 * eyebrow is set.
	 */
	public static function render_purchase_eyebrow() {
		if ( ! is_product() ) return;
		$pid = get_queried_object_id();
		if ( ! fishotel_med_is_med_product( $pid ) ) return;

		$eyebrow = (string) get_post_meta( $pid, '_fishotel_med_eyebrow', true );
		if ( $eyebrow === '' ) return;
		?>
		<div class="fishotel-med-eyebrow"><?php echo esc_html( $eyebrow ); ?></div>
		<?php
	}

	/** Render the "Fulfilled by EverythingAquatic" disclosure for EA-mode. */
	public static function render_ea_disclosure() {
		if ( ! is_product() ) return;
		$pid = get_queried_object_id();
		if ( ! fishotel_med_is_med_product( $pid ) ) return;
		if ( fishotel_med_get_mode( $pid ) !== 'ea' ) return;
		// Tier 2 (Amazon-affiliate) products are never EA-fulfilled, so the
		// disclosure copy would mislead. Belt-and-suspenders on top of the
		// mode check above — covers any future hybrid product that ends up
		// with mode=ea while also carrying an `_fishotel_amazon_asin` value.
		if ( function_exists( 'fishotel_is_amazon_affiliate_product' ) && fishotel_is_amazon_affiliate_product( $pid ) ) return;
		?>
		<p class="fishotel-med-ea-disclosure">
			<?php esc_html_e( 'Fulfilled by EverythingAquatic, our trusted medication partner.', 'fishotel' ); ?>
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
					<?php esc_html_e( 'Amazon ASIN is not set on this product yet — outbound link is unavailable. Add the ASIN in the FisHotel Medication tab of this product to enable the button.', 'fishotel' ); ?>
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
		if ( fishotel_med_get_mode( $product_id ) === 'amazon' ) {
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
		$pid = $product->get_id();
		if ( ! fishotel_med_is_med_product( $pid ) ) return $purchasable;
		// Variations: check the parent's mode.
		if ( $product->get_type() === 'variation' ) {
			$parent_id = $product->get_parent_id();
			if ( fishotel_med_get_mode( $parent_id ) === 'amazon' ) {
				return false;
			}
			return $purchasable;
		}
		if ( fishotel_med_get_mode( $pid ) === 'amazon' ) {
			return false;
		}
		return $purchasable;
	}
}

/**
 * Template helper — called from single-product.php to decide whether
 * to render the Amazon panel in place of the Add-to-Cart form.
 *
 * Returns true when the product is an amazon-mode medication. The
 * caller should then invoke fishotel_med_render_amazon_panel($product).
 */
function fishotel_med_is_amazon_panel( $product ) {
	if ( ! $product instanceof WC_Product ) return false;
	$pid = $product->get_id();
	if ( ! fishotel_med_is_med_product( $pid ) ) return false;
	return fishotel_med_get_mode( $pid ) === 'amazon';
}

/** Render the Amazon CTA panel. Thin wrapper for use in templates. */
function fishotel_med_render_amazon_panel( $product ) {
	FisHotel_Med_Product_Display::render_amazon_panel( $product );
}
