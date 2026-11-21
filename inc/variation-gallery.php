<?php
/**
 * The picture a product page opens on.
 *
 * A variable product can open with a variation already picked: the product
 * has default attributes, the address carries ?attribute_…=, or only one
 * choice is left in stock and main.js picks it for the customer. When that
 * variation has a picture of its own, woocommerce/single-product.php prints
 * that picture in the gallery's big image straight away. Left to the
 * browser, the page would show the product's main image first and change
 * it a moment later, once WooCommerce's script has reported the variation
 * (found_variation) and the picture has been downloaded.
 *
 * Two pure functions make the decision, covered by
 * tests/opening-variation-test.php; fishotel_pdp_opening_picture() feeds
 * them from WooCommerce. After that assets/js/main.js takes over
 * (variationImagePlan(), `node tests/gallery-variation.test.js`).
 *
 * A product whose variations have no pictures of their own — every size of
 * a fish — gets null here and its page is printed exactly as before.
 *
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'fishotel_opening_variation' ) ) {
	/**
	 * The variation the purchase form opens on, or null when the customer
	 * still has something to choose.
	 *
	 * Follows, step by step, what the page does once it has loaded:
	 *   - single-product.php prints a group (buttons + hidden <select>) only
	 *     for an attribute with something in stock, only the in-stock
	 *     options, and selects the wanted value when it is one of them;
	 *   - main.js (initVariationAutoSelect) clicks the button of an attribute
	 *     that has one single value across the in-stock variations;
	 *   - WooCommerce's add-to-cart-variation.js looks for a match only when
	 *     every <select> has a value, and takes the first variation whose
	 *     attributes agree with them ('' on a variation means "any").
	 *
	 * @param array $variations Variation data as the form gets it
	 *                          (WC_Product_Variable::get_available_variations()).
	 * @param array $attributes Per form field name ('attribute_pa_size'):
	 *                          [ 'options' => string[], 'selected' => string ],
	 *                          `selected` being the default / requested value.
	 * @return array|null One entry of $variations.
	 */
	function fishotel_opening_variation( array $variations, array $attributes ) {
		$in_stock = array(); // field => [ sanitized value => true ]
		$any      = array(); // field => true (a variation takes any value)
		$distinct = array(); // field => [ value => true ]
		foreach ( $variations as $v ) {
			if ( empty( $v['is_in_stock'] ) || empty( $v['attributes'] ) || ! is_array( $v['attributes'] ) ) {
				continue;
			}
			foreach ( $v['attributes'] as $key => $val ) {
				$val = (string) $val;
				if ( '' === $val ) {
					$any[ $key ] = true;
				} else {
					$in_stock[ $key ][ sanitize_title( $val ) ] = true;
					$distinct[ $key ][ $val ]                   = true;
				}
			}
		}

		$chosen = array();
		foreach ( $attributes as $key => $attribute ) {
			$has_any = ! empty( $any[ $key ] );
			if ( ! $has_any && empty( $in_stock[ $key ] ) ) {
				continue; // Nothing in stock for it: no group, no <select>.
			}
			$selected = isset( $attribute['selected'] ) && is_scalar( $attribute['selected'] ) ? (string) $attribute['selected'] : '';
			$options  = isset( $attribute['options'] ) ? (array) $attribute['options'] : array();
			$printed  = array();
			$value    = '';
			foreach ( $options as $option ) {
				$option = (string) $option;
				if ( ! $has_any && ! isset( $in_stock[ $key ][ sanitize_title( $option ) ] ) ) {
					continue;
				}
				$printed[] = $option;
				if ( '' !== $selected && $option === $selected ) {
					$value = $option;
				}
			}
			// One single value in stock: main.js clicks its button, unless the
			// button is printed as selected already.
			if ( isset( $distinct[ $key ] ) && 1 === count( $distinct[ $key ] ) ) {
				$only = (string) key( $distinct[ $key ] );
				if ( in_array( $only, $printed, true ) && sanitize_title( $only ) !== sanitize_title( $selected ) ) {
					$value = $only;
				}
			}
			if ( '' === $value ) {
				return null; // Still to choose.
			}
			$chosen[ $key ] = $value;
		}
		if ( ! $chosen ) {
			return null;
		}

		foreach ( $variations as $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			$attrs = isset( $v['attributes'] ) && is_array( $v['attributes'] ) ? $v['attributes'] : array();
			foreach ( $attrs as $key => $val ) {
				$val = (string) $val;
				if ( isset( $chosen[ $key ] ) && '' !== $val && $val !== $chosen[ $key ] ) {
					continue 2;
				}
			}
			return $v;
		}
		return null;
	}
}

if ( ! function_exists( 'fishotel_opening_variation_picture' ) ) {
	/**
	 * What the gallery shows for the variation the page opens on, or null
	 * for "the product's own picture, as always".
	 *
	 * The same rule as variationImagePlan() in main.js: WooCommerce hands a
	 * variation with no picture of its own the product's main image, and
	 * that is no reason to change anything.
	 *
	 * @param array|null $variation   Variation data (image_id, image), or null.
	 * @param int        $main_img_id The product's main image (0 when none).
	 * @param array      $gallery_ids The product's gallery image ids.
	 * @return array|null [ 'image_id' => int,
	 *                      'thumb'    => int position among the printed thumbs, -1 when none carries the picture,
	 *                      'alt'      => string the image's own alt text, '' for "the product's name" ]
	 */
	function fishotel_opening_variation_picture( $variation, $main_img_id, array $gallery_ids ) {
		$image_id    = is_array( $variation ) && isset( $variation['image_id'] ) ? (int) $variation['image_id'] : 0;
		$main_img_id = (int) $main_img_id;
		if ( $image_id <= 0 || $image_id === $main_img_id ) {
			return null;
		}

		// The thumbs single-product.php prints: the main image when there is
		// one, then the first three gallery images — and none at all for a
		// product without gallery images.
		$thumb = -1;
		if ( $gallery_ids ) {
			$at = array_search( $image_id, array_map( 'intval', array_slice( array_values( $gallery_ids ), 0, 3 ) ), true );
			if ( false !== $at ) {
				$thumb = $at + ( $main_img_id ? 1 : 0 );
			}
		}

		// WooCommerce falls back to the attachment title (usually the file
		// name) when an image has no alt text: not an alt of its own.
		$image = isset( $variation['image'] ) && is_array( $variation['image'] ) ? $variation['image'] : array();
		$alt   = isset( $image['alt'] ) ? (string) $image['alt'] : '';
		$title = isset( $image['title'] ) ? (string) $image['title'] : '';

		return array(
			'image_id' => $image_id,
			'thumb'    => $thumb,
			'alt'      => ( '' !== $alt && $alt !== $title ) ? $alt : '',
		);
	}
}

if ( ! function_exists( 'fishotel_pdp_opening_picture' ) ) {
	/**
	 * The picture of the variation a product page opens on — see the two
	 * functions above — or null.
	 *
	 * @param WC_Product $product         The product of the page.
	 * @param array      $variations_data The in-stock variation data the form prints.
	 * @param int        $main_img_id     The product's main image.
	 * @param array      $gallery_ids     The product's gallery image ids.
	 * @return array|null
	 */
	function fishotel_pdp_opening_picture( $product, array $variations_data, $main_img_id, array $gallery_ids ) {
		if ( ! $variations_data || ! ( $product instanceof WC_Product ) || ! $product->is_type( 'variable' ) ) {
			return null;
		}

		$attributes = array();
		foreach ( $product->get_variation_attributes() as $attr_name => $options ) {
			$key = 'attribute_' . sanitize_title( $attr_name );
			// The value the form in single-product.php selects, read the same way.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public product link, nothing is changed.
			$selected = isset( $_REQUEST[ $key ] ) ? wc_clean( wp_unslash( $_REQUEST[ $key ] ) ) : $product->get_variation_default_attribute( $attr_name );
			$attributes[ $key ] = array(
				'options'  => (array) $options,
				'selected' => is_scalar( $selected ) ? (string) $selected : '',
			);
		}

		$picture = fishotel_opening_variation_picture(
			fishotel_opening_variation( $variations_data, $attributes ),
			$main_img_id,
			$gallery_ids
		);
		if ( ! $picture || ! wp_attachment_is_image( $picture['image_id'] ) ) {
			return null;
		}
		return $picture;
	}
}
