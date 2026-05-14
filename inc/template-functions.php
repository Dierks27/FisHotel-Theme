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

/**
 * True when the current request is the Medications parent category
 * archive — `/product-category/medications/`. False on the shop root,
 * on form/treats child archives (flakes, dewormer, etc.), and on every
 * non-medication archive. Gates the dual-axis filter chip strip.
 */
function fishotel_is_medications_archive() {
    if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
        return false;
    }
    $term = get_queried_object();
    return ( $term && isset( $term->slug ) && $term->slug === 'medications' );
}

/**
 * Resolve the noun used in archive count + "ALL X" copy based on the
 * current product_cat slug. Allowlist on the slugs we ship; everything
 * else gets a generic "product(s)" fallback so future categories Jeff
 * adds (gift cards, merchandise, test kits, etc.) don't inherit the
 * fish copy.
 *
 * @param bool $singular Return the singular form ("fish", "medication",
 *                       "food", "product") instead of the plural.
 * @return string
 */
function fishotel_archive_noun( $singular = false ) {
    $term = ( function_exists( 'is_product_category' ) && is_product_category() )
        ? get_queried_object()
        : null;
    $slug = ( $term && isset( $term->slug ) ) ? (string) $term->slug : '';

    $fish_slugs = [ 'quarantined-fish' ];
    $med_slugs  = [ 'medications', 'flakes', 'pellets', 'powders', 'liquid', 'antibacterial', 'antiparasitic', 'dewormer' ];
    $food_slugs = [ 'freeze-dried-foods', 'fish-food', 'medicated-food' ];

    if ( in_array( $slug, $fish_slugs, true ) ) {
        return 'fish'; // same in singular + plural
    }
    if ( in_array( $slug, $med_slugs, true ) ) {
        return $singular ? 'medication' : 'medications';
    }
    if ( in_array( $slug, $food_slugs, true ) ) {
        return $singular ? 'food' : 'foods';
    }
    return $singular ? 'product' : 'products';
}

/**
 * True when the product is in the medications taxonomy bucket — either
 * the parent term or any of the Phase 3.4 form/treats children. Used
 * by the trust strip + future medication-specific PDP blocks.
 *
 * @param int|null $product_id Defaults to the current loop ID.
 * @return bool
 */
function fishotel_is_medication_product( $product_id = null ) {
    $product_id = $product_id ? (int) $product_id : (int) get_the_ID();
    if ( ! $product_id ) {
        return false;
    }
    $cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
    if ( is_wp_error( $cats ) || empty( $cats ) ) {
        return false;
    }
    $med_slugs = [ 'medications', 'flakes', 'pellets', 'powders', 'liquid', 'antibacterial', 'antiparasitic', 'dewormer' ];
    return (bool) array_intersect( $med_slugs, (array) $cats );
}

/**
 * True when the product is in any of the food taxonomy buckets.
 *
 * @param int|null $product_id Defaults to the current loop ID.
 * @return bool
 */
function fishotel_is_food_product( $product_id = null ) {
    $product_id = $product_id ? (int) $product_id : (int) get_the_ID();
    if ( ! $product_id ) {
        return false;
    }
    $cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
    if ( is_wp_error( $cats ) || empty( $cats ) ) {
        return false;
    }
    $food_slugs = [ 'freeze-dried-foods', 'fish-food', 'medicated-food' ];
    return (bool) array_intersect( $food_slugs, (array) $cats );
}

/**
 * True when a product is Tier 2 — i.e. an Amazon affiliate item, keyed
 * on a non-empty `_fishotel_amazon_asin` post meta value. The check is
 * orthogonal to product_cat so it correctly identifies affiliate items
 * regardless of whether they live in Medications (Copper Power today)
 * or some future category (Seachem etc.).
 *
 * @param int|null $product_id Defaults to the current loop ID.
 * @return bool
 */
function fishotel_is_amazon_affiliate_product( $product_id = null ) {
    $product_id = $product_id ? (int) $product_id : (int) get_the_ID();
    if ( ! $product_id ) {
        return false;
    }
    $asin = (string) get_post_meta( $product_id, '_fishotel_amazon_asin', true );
    return trim( $asin ) !== '';
}

/**
 * Render the three-line trust strip below Add to Cart. Single markup
 * pipeline for the fish / medication / food variants — content swaps,
 * markup + classes stay identical so the visual layout matches across
 * categories. Skips lines that resolve to an empty string after the
 * admin override / default fallback.
 *
 * @param string[] $items 1-3 trust-strip lines, already resolved.
 */
function fishotel_render_trust_strip( $items ) {
    $items = array_values( array_filter( array_map( 'strval', (array) $items ), function ( $line ) {
        return trim( $line ) !== '';
    } ) );
    if ( empty( $items ) ) {
        return;
    }
    echo '<div class="fh-trust-strip">';
    foreach ( $items as $line ) {
        echo '<span class="fh-trust-strip__item">&#10003; ' . esc_html( $line ) . '</span>';
    }
    echo '</div>';
}

/**
 * Resolve the appropriate trust-strip lines for a product. Reads admin
 * overrides via FisHotel_Admin_Settings (so non-developers can edit
 * copy), falling back to the spec-supplied defaults when the stored
 * value is empty. Returns an empty array for products outside the
 * fish/medication/food buckets (gift cards, merchandise, etc.) so the
 * caller can skip the trust-strip slot entirely.
 *
 * @param int|null $product_id Defaults to the current loop ID.
 * @return string[]
 */
function fishotel_get_trust_strip_items( $product_id = null ) {
    $product_id = $product_id ? (int) $product_id : (int) get_the_ID();
    if ( ! $product_id ) {
        return [];
    }

    // Defaults live in FisHotel_Admin_Settings::defaults() + register_setting().
    // FisHotel_Admin_Settings::get() falls back to that default both when
    // the option key is absent from the DB AND when the stored value is
    // an empty string (Phase 3.5.1) so callers no longer need their own
    // ?: ladder.
    $get = function ( $key ) {
        return class_exists( 'FisHotel_Admin_Settings' )
            ? (string) FisHotel_Admin_Settings::get( $key )
            : '';
    };

    // Branch order matters: Tier 2 (Amazon-affiliate) products are still
    // categorised under medications, so the affiliate check has to win
    // before the medication branch. Fish gates first because it's the
    // dominant category and the helper is the cheapest.
    if ( fishotel_is_quarantined_fish( $product_id ) ) {
        return [ $get( 'fh_trust_1' ), $get( 'fh_trust_2' ), $get( 'fh_trust_3' ) ];
    }
    if ( fishotel_is_amazon_affiliate_product( $product_id ) ) {
        return [ $get( 'fh_tier2_trust_1' ), $get( 'fh_tier2_trust_2' ), $get( 'fh_tier2_trust_3' ) ];
    }
    if ( fishotel_is_medication_product( $product_id ) ) {
        return [ $get( 'fh_med_trust_1' ), $get( 'fh_med_trust_2' ), $get( 'fh_med_trust_3' ) ];
    }
    if ( fishotel_is_food_product( $product_id ) ) {
        return [ $get( 'fh_food_trust_1' ), $get( 'fh_food_trust_2' ), $get( 'fh_food_trust_3' ) ];
    }
    return [];
}
