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

    $orderby  = isset( $_POST['orderby'] )  ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : '';
    $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( $_POST['taxonomy'] ) : '';
    $term     = isset( $_POST['term'] )     ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

    // Products already rendered on the page (server batch + any prior AJAX
    // batches). We exclude these instead of paginating by offset — see the
    // post__not_in note below.
    $loaded_ids = isset( $_POST['loaded_ids'] ) ? array_map( 'absint', (array) $_POST['loaded_ids'] ) : [];
    $loaded_ids = array_values( array_filter( array_unique( $loaded_ids ) ) );

    // per_page = how many to append per click. Mirrors the archive's main
    // query value (theme forces 16, meds force 50), passed from the client
    // which reads the live query var. Cap against tampering.
    $default_per_page = (int) apply_filters( 'loop_shop_per_page', wc_get_default_products_per_row() * wc_get_default_product_rows_per_page() );
    $per_page         = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : $default_per_page;
    $per_page         = min( max( 1, $per_page ), 100 );

    // Only product_cat / product_tag archives drive Load More — guard the
    // taxonomy so a tampered request can't query an arbitrary taxonomy.
    if ( $taxonomy && ! in_array( $taxonomy, [ 'product_cat', 'product_tag' ], true ) ) {
        $taxonomy = '';
        $term     = '';
    }

    // Exclusion-based pagination. Rather than fetch "page N" by offset and
    // hope the AJAX query's ordering lines up byte-for-byte with the main
    // archive query (it didn't — QA on v1.11.2 saw boundary + tie-driven
    // duplicates), we exclude every product already on the page and return
    // the next batch from the front of the remaining set. Duplicates are then
    // impossible by construction and no product can be skipped, regardless of
    // any residual ordering drift between the two queries.
    $args = [
        'post_type'           => 'product',
        'post_status'         => 'publish',
        'posts_per_page'      => $per_page,
        'post__not_in'        => $loaded_ids,
        'ignore_sticky_posts' => true,
    ];

    // Resolve ordering through WC's parser so the appended batch follows the
    // same order as the initial render. Split a combined value like
    // "price-desc" into orderby + order BEFORE calling — passing it whole
    // skips get_catalog_ordering_args()'s own split (that only runs when its
    // $orderby arg is empty), so "price-desc" fell through to the menu_order
    // default and produced a completely different order than the main query
    // (the scattered price-desc duplicates QA saw). An empty orderby resolves
    // the same default the main query uses (woocommerce_default_catalog_orderby).
    if ( isset( WC()->query ) && is_callable( [ WC()->query, 'get_catalog_ordering_args' ] ) ) {
        if ( $orderby !== '' ) {
            $parts         = explode( '-', $orderby );
            $ob            = $parts[0];
            $ord           = ! empty( $parts[1] ) ? strtoupper( $parts[1] ) : '';
            $ordering_args = WC()->query->get_catalog_ordering_args( $ob, $ord );
        } else {
            $ordering_args = WC()->query->get_catalog_ordering_args();
        }
        $args = array_merge( $args, $ordering_args );
    }

    // Build the visibility/stock query through WC's own helpers so the AJAX
    // result set is byte-for-byte the set the main archive query produces
    // (catalog-visibility, hide-out-of-stock, etc.). A standalone WP_Query
    // never triggers WC_Query::product_query, so we replicate it explicitly.
    // get_meta_query()/get_tax_query() are INSTANCE methods on the WC_Query
    // singleton (WC()->query) — calling them statically fatals on PHP 8
    // (the v1.11.1 regression). Fall back to a hand-rolled visibility query
    // only if the singleton isn't available.
    if ( isset( WC()->query ) && is_callable( [ WC()->query, 'get_meta_query' ] ) ) {
        $meta_query = WC()->query->get_meta_query();
        $tax_query  = WC()->query->get_tax_query();
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
        // With exclusion-based paging the query's found_posts is the size of
        // the remaining (not-yet-loaded) set. More remain only if it exceeds
        // what we just returned.
        'has_more' => $query->found_posts > $query->post_count,
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
