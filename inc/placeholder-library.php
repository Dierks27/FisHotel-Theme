<?php
/**
 * FisHotel — Product Image Placeholder Library
 *
 * Library of admin-curated images used whenever a product has no featured
 * image. Each product is assigned a stable placeholder (via post meta) so
 * the chosen image stays consistent across page loads. When a product runs
 * out of stock the assignment is cleared so the next restock picks fresh.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Placeholder_Library {

	const OPTION_KEY     = 'fh_placeholder_library';
	const PRODUCT_META   = '_fishotel_assigned_placeholder_id';

	public static function init() {
		add_action( 'woocommerce_product_set_stock', [ __CLASS__, 'on_stock_set' ] );
		add_action( 'woocommerce_variation_set_stock', [ __CLASS__, 'on_stock_set' ] );
		add_action( 'woocommerce_no_stock', [ __CLASS__, 'on_stock_set' ] );
	}

	/** Library = array of attachment IDs, filtered to those still attached. */
	public static function get_library() {
		$ids = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $ids ) ) {
			return [];
		}
		$out = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && get_post_status( $id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Resolve which attachment ID should stand in for this product.
	 * Returns 0 if the library is empty (caller should fall back to the WC
	 * default placeholder).
	 */
	public static function get_for_product( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! $product_id ) {
			return 0;
		}

		$library = self::get_library();
		if ( empty( $library ) ) {
			return 0;
		}

		$assigned = (int) get_post_meta( $product_id, self::PRODUCT_META, true );
		if ( $assigned && in_array( $assigned, $library, true ) ) {
			return $assigned;
		}

		// Stable, distributed assignment.
		$picked = $library[ $product_id % count( $library ) ];
		update_post_meta( $product_id, self::PRODUCT_META, $picked );
		return $picked;
	}

	/**
	 * Render the placeholder for a product at the given size. Returns the
	 * library image if we have one, otherwise the WC default placeholder.
	 */
	public static function render( $product_id, $size = 'fishotel-product-card', $attrs = [] ) {
		$attachment_id = self::get_for_product( $product_id );
		if ( $attachment_id ) {
			$alt = isset( $attrs['alt'] ) ? $attrs['alt'] : get_the_title( $product_id );
			$attrs = array_merge( [ 'alt' => $alt ], $attrs );
			return wp_get_attachment_image( $attachment_id, $size, false, $attrs );
		}
		// Library empty — fall back to WooCommerce default placeholder so we
		// never render nothing.
		if ( function_exists( 'woocommerce_placeholder_img' ) ) {
			return woocommerce_placeholder_img( $size );
		}
		return '';
	}

	/**
	 * Stock change hook. Clears the assigned placeholder when stock hits 0
	 * so the next render after restock picks a fresh image.
	 *
	 * @param WC_Product|int $product
	 */
	public static function on_stock_set( $product ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( $product );
		}
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$qty = $product->get_stock_quantity();
		// Treat null (not managed) as not zero — only act when we know it's 0.
		if ( $qty !== null && (int) $qty === 0 ) {
			$pid = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			delete_post_meta( $pid, self::PRODUCT_META );
		}
	}
}

FisHotel_Placeholder_Library::init();

/**
 * Render a product thumbnail, falling back to the placeholder library.
 *
 * Mirrors the_post_thumbnail() but echoes a library image (or the WC
 * default placeholder) when no featured image is set.
 */
function fishotel_product_thumbnail( $product_id = null, $size = 'fishotel-product-card', $attrs = [] ) {
	if ( ! $product_id ) {
		$product_id = get_the_ID();
	}
	if ( has_post_thumbnail( $product_id ) ) {
		echo get_the_post_thumbnail( $product_id, $size, $attrs );
		return;
	}
	echo FisHotel_Placeholder_Library::render( $product_id, $size, $attrs );
}
