<?php
/**
 * FisHotel template functions
 * Helper functions available to all templates
 *
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;

/**
 * Output the page hero banner with breadcrumb + title + tags
 */
function fishotel_page_hero( $args = [] ) {
    $defaults = [
        'title'      => get_the_title(),
        'latin'      => '',
        'tags'       => [],
        'breadcrumb' => true,
    ];
    $args = wp_parse_args( $args, $defaults );
    get_template_part( 'template-parts/header/page-hero', null, $args );
}

/**
 * Output fish card for shop loop
 */
function fishotel_fish_card( $product_id = null ) {
    if ( ! $product_id ) $product_id = get_the_ID();
    get_template_part( 'template-parts/product/fish-card', null, [ 'product_id' => $product_id ] );
}

/**
 * Get FisHotel hotel data for a product
 * Wrapper around FisHotel_Hotel_Data::get_all()
 */
function fishotel_get_hotel_data( $product_id = null ) {
    if ( ! $product_id ) $product_id = get_the_ID();
    if ( class_exists( 'FisHotel_Hotel_Data' ) ) {
        return FisHotel_Hotel_Data::get_all( $product_id );
    }
    return [];
}

/**
 * Classify a product as `fish`, `medication`, or `food` for UI labeling
 * (single-product description heading, QT cert visibility, etc.).
 *
 * Order matters: food check runs first because the Phase 3 bulk-importer
 * may place freeze-dried products under the Medications parent when the
 * --category-foods flag was omitted, so `fishotel_med_is_med_product()`
 * would otherwise classify them as `medication`. Slug-based fallbacks
 * mirror the importer's freeze-dried detection so a freshly-imported
 * product is classified correctly without any manual category cleanup.
 *
 * @param int|WC_Product|null $product Product ID, product object, or null
 *                                     to use the current loop ID.
 * @return string `fish` | `medication` | `food`
 */
function fishotel_classify_product( $product = null ) {
    if ( null === $product ) {
        $product = get_the_ID();
    }
    $id = ( is_object( $product ) && method_exists( $product, 'get_id' ) )
        ? $product->get_id()
        : (int) $product;
    if ( ! $id ) {
        return 'fish';
    }

    $cat_slugs = wp_get_post_terms( $id, 'product_cat', [ 'fields' => 'slugs' ] );
    if ( ! is_wp_error( $cat_slugs ) ) {
        foreach ( (array) $cat_slugs as $slug ) {
            if ( $slug === 'foods' || $slug === 'freeze-dried-foods' || stripos( $slug, 'food' ) !== false ) {
                return 'food';
            }
        }
    }

    $post_slug = (string) get_post_field( 'post_name', $id );
    foreach ( [ 'freeze-dried', 'blackworm', 'tubifex', 'copepod' ] as $hint ) {
        if ( strpos( $post_slug, $hint ) !== false ) {
            return 'food';
        }
    }

    if ( function_exists( 'fishotel_med_is_med_product' ) && fishotel_med_is_med_product( $id ) ) {
        return 'medication';
    }

    return 'fish';
}
