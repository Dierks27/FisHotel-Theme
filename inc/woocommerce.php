<?php
/**
 * FisHotel WooCommerce integration
 * TODO: Build full templates in Phase 2
 *
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;

// Remove default WooCommerce wrappers — we handle layout
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content',  'woocommerce_output_content_wrapper_end', 10 );
remove_action( 'woocommerce_sidebar',             'woocommerce_get_sidebar', 10 );

// Add our wrappers
add_action( 'woocommerce_before_main_content', function() {
    echo '<div class="fh-woocommerce-wrap">';
} );
add_action( 'woocommerce_after_main_content', function() {
    echo '</div>';
} );

// Product loop columns
add_filter( 'loop_shop_columns', function() { return 4; } );

// Products per page
add_filter( 'loop_shop_per_page', function() { return 16; } );
