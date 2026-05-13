<?php
/**
 * Medication store helpers — shared predicates and URL builders.
 *
 * Everything here is read-only against products. Mutations happen in
 * the dedicated modules (product-meta, stock-sync, etc.).
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Top-level medication parent category slug. Subcategories (Antiparasitic,
 * Antibacterial, etc.) live under this slug. A product is considered a
 * medication if it sits under this category — directly or through any
 * descendant.
 */
const FISHOTEL_MED_PARENT_SLUG = 'medications';

/** Is this product (id or WC_Product) any kind of medication? */
function fishotel_med_is_med_product( $product ) {
	$id = is_object( $product ) && method_exists( $product, 'get_id' ) ? $product->get_id() : (int) $product;
	if ( ! $id ) return false;

	$parent = get_term_by( 'slug', FISHOTEL_MED_PARENT_SLUG, 'product_cat' );
	if ( ! $parent || is_wp_error( $parent ) ) return false;

	$terms = wp_get_post_terms( $id, 'product_cat', [ 'fields' => 'ids' ] );
	if ( is_wp_error( $terms ) || empty( $terms ) ) return false;

	if ( in_array( (int) $parent->term_id, $terms, true ) ) return true;

	foreach ( $terms as $tid ) {
		$ancestors = get_ancestors( (int) $tid, 'product_cat', 'taxonomy' );
		if ( in_array( (int) $parent->term_id, array_map( 'intval', $ancestors ), true ) ) {
			return true;
		}
	}
	return false;
}

/** Fulfillment mode for a product: `ea` | `amazon` | `self`. Defaults to `ea`. */
function fishotel_med_get_mode( $product_id ) {
	$mode = get_post_meta( (int) $product_id, '_fishotel_fulfillment', true );
	$mode = strtolower( trim( (string) $mode ) );
	if ( ! in_array( $mode, [ 'ea', 'amazon', 'self' ], true ) ) {
		$mode = 'ea';
	}
	return $mode;
}

/** True when the current view is a medication category archive (top or child). */
function fishotel_med_is_med_archive() {
	if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
		return false;
	}
	$term = get_queried_object();
	if ( ! $term || empty( $term->term_id ) ) {
		return false;
	}
	$parent = get_term_by( 'slug', FISHOTEL_MED_PARENT_SLUG, 'product_cat' );
	if ( ! $parent ) {
		return false;
	}
	if ( (int) $term->term_id === (int) $parent->term_id ) {
		return true;
	}
	$ancestors = get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' );
	return in_array( (int) $parent->term_id, array_map( 'intval', $ancestors ), true );
}

/** True if the current WC cart has any medication line item. */
function fishotel_med_cart_has_meds() {
	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
		return false;
	}
	foreach ( WC()->cart->get_cart() as $item ) {
		$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
		if ( $pid && fishotel_med_is_med_product( $pid ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Build the outbound Amazon affiliate URL for a product. Returns empty
 * when ASIN/tag are missing. Tag is appended only when non-empty so
 * Phase 1 launch isn't blocked on the Associates account.
 */
function fishotel_med_build_amazon_url( $product_id ) {
	$asin = trim( (string) get_post_meta( (int) $product_id, '_fishotel_amazon_asin', true ) );
	if ( $asin === '' ) {
		return '';
	}
	$url = 'https://www.amazon.com/dp/' . rawurlencode( $asin );
	$tag = trim( (string) FisHotel_Med_Settings::get( 'fishotel_amazon_tag' ) );
	if ( $tag !== '' ) {
		$url .= '?tag=' . rawurlencode( $tag );
	}
	return $url;
}

/**
 * Pricing function — smallest sizes carry aggressive markup, largest
 * ride EA retail. EA retail is always the floor. Output is the next
 * dollar up + .99.
 *
 * Matches the spec's worked example for Praziquantel Powder (the 250g
 * row demonstrates the floor: calc'd $472.60 < EA $485.00, so EA wins
 * and we round to $485.99).
 *
 * @param float $wholesale  Wholesale cost from EA.
 * @param float $ea_retail  EA retail price for this size.
 * @param int   $size_index 0 = smallest, increments up.
 * @param int   $size_count Total number of size variations on the parent.
 * @return float
 */
function fishotel_calculate_med_price( $wholesale, $ea_retail, $size_index, $size_count ) {
	$wholesale  = (float) $wholesale;
	$ea_retail  = (float) $ea_retail;
	$size_index = max( 0, (int) $size_index );
	$size_count = max( 1, (int) $size_count );

	$max_mult = 1.75;
	$min_mult = 1.30;

	if ( $size_count <= 1 ) {
		$mult = ( $max_mult + $min_mult ) / 2;
	} else {
		$denom = max( 1, $size_count - 1 );
		$t     = min( 1.0, max( 0.0, $size_index / $denom ) );
		$mult  = $max_mult - ( ( $max_mult - $min_mult ) * $t );
	}

	$calculated = $wholesale * $mult;
	$price      = max( $calculated, $ea_retail );
	return floor( $price ) + 0.99;
}
