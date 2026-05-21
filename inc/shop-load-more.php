<?php
/**
 * FisHotel — Shop archive "Load More".
 *
 * AJAX-appends the next batch of products to the archive grid, replacing
 * pagination. Sort/filter context travels in the request so ordering is
 * preserved across loads (no page-2 state to lose). Renders the SAME
 * card template part the archive uses (template-parts/product/fish-card)
 * so appended cards are byte-identical to the server-rendered grid.
 *
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;

/**
 * Product-visibility tax_query clauses, mirroring WC core's catalog query
 * so AJAX-appended products match what page 1 shows (hidden + optionally
 * out-of-stock products excluded). The AJAX request runs a standalone
 * WP_Query that never hits WC_Query::product_query, so we replicate it.
 */
function fishotel_load_more_visibility_tax_query() {
    if ( ! function_exists( 'wc_get_product_visibility_term_ids' ) ) {
        return [];
    }
    $term_ids = wc_get_product_visibility_term_ids();
    $clauses  = [];

    if ( ! empty( $term_ids['exclude-from-catalog'] ) ) {
        $clauses[] = [
            'taxonomy' => 'product_visibility',
            'field'    => 'term_taxonomy_id',
            'terms'    => [ $term_ids['exclude-from-catalog'] ],
            'operator' => 'NOT IN',
        ];
    }
    if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! empty( $term_ids['outofstock'] ) ) {
        $clauses[] = [
            'taxonomy' => 'product_visibility',
            'field'    => 'term_taxonomy_id',
            'terms'    => [ $term_ids['outofstock'] ],
            'operator' => 'NOT IN',
        ];
    }
    return $clauses;
}

function fishotel_load_more_products() {
    check_ajax_referer( 'fishotel_load_more', 'nonce' );

    $paged    = isset( $_POST['paged'] )    ? max( 2, absint( $_POST['paged'] ) ) : 2;
    $orderby  = isset( $_POST['orderby'] )  ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : '';
    $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( $_POST['taxonomy'] ) : '';
    $term     = isset( $_POST['term'] )     ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

    // per_page must match the archive's main query exactly or pagination
    // math drifts (skipped/duplicated products). The theme forces 16 via
    // loop_shop_per_page, and the medications archive forces 50 via
    // pre_get_posts — both flow through here from the client, which read
    // the live query value. Cap to a sane ceiling against tampering.
    $default_per_page = (int) apply_filters( 'loop_shop_per_page', wc_get_default_products_per_row() * wc_get_default_product_rows_per_page() );
    $per_page         = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : $default_per_page;
    $per_page         = min( max( 1, $per_page ), 100 );

    // Only product_cat / product_tag archives drive Load More — guard the
    // taxonomy so a tampered request can't query an arbitrary taxonomy.
    if ( $taxonomy && ! in_array( $taxonomy, [ 'product_cat', 'product_tag' ], true ) ) {
        $taxonomy = '';
        $term     = '';
    }

    $args = [
        'post_type'           => 'product',
        'post_status'         => 'publish',
        'posts_per_page'      => $per_page,
        'paged'               => $paged,
        'ignore_sticky_posts' => true,
    ];

    // Reuse WC's catalog ordering parser for EVERY request — including when
    // no orderby is sent. With an empty orderby it resolves the same default
    // the main archive query uses (the woocommerce_default_catalog_orderby
    // option/filter, e.g. title), and applies the same secondary tiebreakers
    // (date+ID, the price meta-lookup with product_id, etc.). Skipping it on
    // the default view was the bug: WP_Query then fell back to date DESC while
    // page 1 was menu_order/title-ordered, so later AJAX pages overlapped
    // page 1 — the duplicate products QA saw. (PR follow-up to #54.)
    if ( isset( WC()->query ) && is_callable( [ WC()->query, 'get_catalog_ordering_args' ] ) ) {
        $args = array_merge( $args, WC()->query->get_catalog_ordering_args( $orderby ) );
    }

    // Build the visibility/stock query through WC's own helpers so the AJAX
    // result set is byte-for-byte the set the main archive query produces
    // (catalog-visibility, hide-out-of-stock, etc.). A standalone WP_Query
    // never triggers WC_Query::product_query, so we replicate it explicitly.
    if ( class_exists( 'WC_Query' ) ) {
        $meta_query = WC_Query::get_meta_query();
        $tax_query  = WC_Query::get_tax_query();
    } else {
        $meta_query = [];
        $tax_query  = fishotel_load_more_visibility_tax_query();
    }

    if ( $taxonomy && $term ) {
        $tax_query[] = [
            'taxonomy'         => $taxonomy,
            'field'            => 'slug',
            'terms'            => $term,
            'include_children' => true,
        ];
    }
    if ( ! empty( $meta_query ) ) {
        $args['meta_query'] = $meta_query;
    }
    if ( ! empty( $tax_query ) ) {
        $args['tax_query'] = $tax_query;
    }

    $query = new WP_Query( $args );

    if ( ! $query->have_posts() ) {
        wp_send_json( [ 'html' => '', 'has_more' => false ] );
    }

    ob_start();
    while ( $query->have_posts() ) {
        $query->the_post();
        get_template_part( 'template-parts/product/fish-card' );
    }
    $html = ob_get_clean();
    wp_reset_postdata();

    wp_send_json( [
        'html'     => $html,
        'has_more' => $paged < $query->max_num_pages,
    ] );
}
add_action( 'wp_ajax_fishotel_load_more_products',        'fishotel_load_more_products' );
add_action( 'wp_ajax_nopriv_fishotel_load_more_products', 'fishotel_load_more_products' );

/**
 * Enqueue the Load More script on product archives only (shop, category,
 * tag). Search results, single products, and landing pages are out of scope.
 */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'is_shop' ) ) {
        return;
    }
    if ( ! ( is_shop() || is_product_category() || is_product_tag() ) ) {
        return;
    }

    wp_enqueue_script(
        'fishotel-load-more',
        FISHOTEL_THEME_URI . '/assets/js/shop-load-more.js',
        [],
        fishotel_asset_version( 'assets/js/shop-load-more.js' ),
        true
    );

    global $wp_query;
    $context = [
        'ajax_url'  => admin_url( 'admin-ajax.php' ),
        'nonce'     => wp_create_nonce( 'fishotel_load_more' ),
        'taxonomy'  => '',
        'term'      => '',
        // The live main-query value — matches whatever loop_shop_per_page /
        // pre_get_posts set for this specific archive.
        'per_page'  => (int) $wp_query->get( 'posts_per_page' ),
    ];

    if ( is_product_category() || is_product_tag() ) {
        $obj = get_queried_object();
        if ( $obj && isset( $obj->slug, $obj->taxonomy ) ) {
            $context['taxonomy'] = $obj->taxonomy;
            $context['term']     = $obj->slug;
        }
    }

    wp_localize_script( 'fishotel-load-more', 'FishotelLoadMore', $context );
}, 20 );
