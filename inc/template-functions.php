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
