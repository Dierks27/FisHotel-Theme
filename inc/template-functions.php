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
 * True when the product is in the `quarantined-fish` category — the
 * single allowlist gate for fish-specific PDP UI (QT badge, trust
 * strip, "About This Species" data table, "About This Fish" prose
 * heading). Everything outside that category — medications, freeze-
 * dried foods, gift cards, future categories Jeff hasn't created yet
 * — is treated as non-fish.
 *
 * Allowlist instead of blocklist on purpose: new product categories
 * shouldn't have to be added to a hide-list to stop leaking QT copy.
 *
 * @param int|null $product_id Defaults to the current loop ID.
 * @return bool
 */
function fishotel_is_quarantined_fish( $product_id = null ) {
    $product_id = $product_id ? (int) $product_id : (int) get_the_ID();
    if ( ! $product_id ) {
        return false;
    }
    $cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
    if ( is_wp_error( $cats ) || empty( $cats ) ) {
        return false;
    }
    return in_array( 'quarantined-fish', $cats, true );
}
