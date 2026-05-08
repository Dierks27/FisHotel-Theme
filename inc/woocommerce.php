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

/*
 * ─────────────────────────────────────────
 * STANDARD WC HOOK COMPATIBILITY
 *
 * Our custom PDP and loop templates (woocommerce/single-product.php,
 * archive-product.php, front-page.php, search.php) build their own
 * markup. They still fire the canonical WC hooks so plugins (e.g.
 * FisHotel Misc Coming Soon) can inject UI at the standard injection
 * points.
 *
 * To keep our layout clean, we remove the default WC callbacks that
 * would otherwise re-render duplicate title/price/add-to-cart/image
 * blocks. Plugins hooking in at any priority still run normally.
 *
 * Runs late on init so WC has registered its callbacks first.
 * ─────────────────────────────────────────
 */
add_action( 'init', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    // Single product summary defaults — title, rating, price, excerpt,
    // add-to-cart, meta, sharing.
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title',       5  );
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating',      10 );
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price',       10 );
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt',     20 );
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta',        40 );
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_sharing',     50 );

    // Before/after summary — sale flash, gallery images, tabs, upsells,
    // related products. We render our own gallery and a custom related
    // row, so suppress these.
    remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );
    remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images',     20 );
    remove_action( 'woocommerce_after_single_product_summary',  'woocommerce_output_product_data_tabs', 10 );
    remove_action( 'woocommerce_after_single_product_summary',  'woocommerce_upsell_display',           15 );
    remove_action( 'woocommerce_after_single_product_summary',  'woocommerce_output_related_products',  20 );

    // Loop card defaults — anchor open/close, sale flash, thumbnail,
    // title, rating, price, add-to-cart link. Our card builds all of
    // this manually inside its own anchor.
    remove_action( 'woocommerce_before_shop_loop_item',       'woocommerce_template_loop_product_link_open',  10 );
    remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash',     10 );
    remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail',  10 );
    remove_action( 'woocommerce_shop_loop_item_title',        'woocommerce_template_loop_product_title',      10 );
    remove_action( 'woocommerce_after_shop_loop_item_title',  'woocommerce_template_loop_rating',             5  );
    remove_action( 'woocommerce_after_shop_loop_item_title',  'woocommerce_template_loop_price',              10 );
    remove_action( 'woocommerce_after_shop_loop_item',        'woocommerce_template_loop_product_link_close', 5  );
    remove_action( 'woocommerce_after_shop_loop_item',        'woocommerce_template_loop_add_to_cart',        10 );
}, 20 );

