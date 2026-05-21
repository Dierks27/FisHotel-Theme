<?php
/**
 * FisHotel — Shop Archive (Product Listing)
 * @package FisHotel
 */
defined('ABSPATH') || exit;

// If on the main shop page (not inside a category), check display setting
$shop_display = class_exists('FisHotel_Admin_Settings') ? FisHotel_Admin_Settings::get('fh_shop_display') : 'categories';
$hide_empty   = class_exists('FisHotel_Admin_Settings') ? FisHotel_Admin_Settings::get('fh_shop_hide_empty') : '1';

if ( is_shop() && ! is_product_category() && $shop_display !== 'products' ) {
    // Always fetch all top-level cats then filter — more reliable than hide_empty arg
    $cats = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'parent'     => 0,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    // Apply hide_empty setting in PHP
    $should_hide_empty = ( $hide_empty === '' ) ? true : (bool) $hide_empty;
    if ( $should_hide_empty ) {
        $cats = array_filter( $cats, function( $cat ) { return $cat->count > 0; } );
    }

    // Filter out hidden categories from settings
    $hidden_cats = class_exists('FisHotel_Admin_Settings') ? FisHotel_Admin_Settings::get('fh_shop_hidden_cats') : [];
    if ( ! is_array( $hidden_cats ) ) $hidden_cats = [];
    if ( ! empty( $hidden_cats ) ) {
        $cats = array_filter( $cats, function( $cat ) use ( $hidden_cats ) {
            return ! in_array( $cat->slug, $hidden_cats, true );
        });
    }

    get_header(); ?>
    <div class="page-hero">
        <div class="page-hero__inner">
            <nav class="page-hero__breadcrumb">
                <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
                <span>/</span>
                <span style="color:var(--fh-text-2)">Shop</span>
            </nav>
            <h1 class="page-hero__title">
                <span class="word-1">The</span>&nbsp;<span class="word-2">Shop</span>
            </h1>
        </div>
    </div>
    <?php if ( $cats && ! is_wp_error( $cats ) ) : ?>
    <div class="fh-shop-categories">
        <div class="fh-shop-categories__grid">
            <?php foreach ( $cats as $cat ) :
                $thumb_id  = get_term_meta( $cat->term_id, 'thumbnail_id', true );
                $thumb_url = $thumb_id
                    ? wp_get_attachment_image_url( $thumb_id, 'medium_large' )
                    : wc_placeholder_img_src();
                $cat_url   = get_term_link( $cat );
            ?>
            <a href="<?php echo esc_url( $cat_url ); ?>" class="fh-cat-card">
                <div class="fh-cat-card__image" style="background-image:url('<?php echo esc_url($thumb_url); ?>')"></div>
                <div class="fh-cat-card__body">
                    <h2 class="fh-cat-card__name"><?php echo esc_html( $cat->name ); ?></h2>
                    <span class="fh-cat-card__count"><?php echo esc_html( $cat->count ); ?> <?php echo esc_html( _n( 'product', 'products', $cat->count, 'fishotel' ) ); ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php get_footer();
    return;
}

get_header();
?>

<?php /* ── PAGE HERO ── */ ?>
<div class="page-hero">
    <div class="page-hero__inner">
        <nav class="page-hero__breadcrumb">
            <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
            <span>/</span>
            <?php if (is_product_category()) :
                $cat = get_queried_object();
                echo '<a href="' . esc_url(get_permalink(wc_get_page_id('shop'))) . '">Shop</a>';
                echo '<span>/</span>';
                echo '<span style="color:var(--fh-text-2)">' . esc_html($cat->name) . '</span>';
            else : ?>
                <span style="color:var(--fh-text-2)">Shop</span>
            <?php endif; ?>
        </nav>
        <h1 class="page-hero__title">
            <?php if (is_product_category()) :
                $words = explode(' ', single_term_title('', false), 2);
                echo '<span class="word-1">' . esc_html($words[0]) . '</span>'
                   . ( !empty($words[1]) ? '&nbsp;<span class="word-2">' . esc_html($words[1]) . '</span>' : '' );
            else : ?>
                <span class="word-1">Quarantined</span>&nbsp;<span class="word-2">Fish</span>
            <?php endif; ?>
        </h1>
        <?php if (is_product_category() && category_description()) : ?>
        <p style="color:var(--fh-text-3); font-size:13px; max-width:600px; margin-top:10px;"><?php echo wp_kses_post(category_description()); ?></p>
        <?php endif; ?>
    </div>
</div>

<?php /* ── FILTER BAR ── */ ?>
<?php
// Category-aware archive copy. Helpers come from inc/template-functions.php;
// fall back to "fish" so the page still renders if the include hasn't
// loaded for some reason.
$archive_noun_plural   = function_exists( 'fishotel_archive_noun' ) ? fishotel_archive_noun( false ) : 'fish';
$archive_noun_singular = function_exists( 'fishotel_archive_noun' ) ? fishotel_archive_noun( true )  : 'fish';

// Legacy sub-category chip strip ("All Fish / Saltwater / Reef-Safe / ...")
// is livestock-specific. Render it on the shop root and on the quarantined-
// fish term + its descendants only — never on medications, foods, gift
// cards, etc. The Medications archive gets its own dual-axis strip below.
$is_qf_family = false;
if ( is_shop() && ! is_product_category() ) {
    $is_qf_family = true;
} elseif ( is_product_category() ) {
    $cur_term = get_queried_object();
    if ( $cur_term && isset( $cur_term->slug ) ) {
        $qf = get_term_by( 'slug', 'quarantined-fish', 'product_cat' );
        if ( $qf && ! is_wp_error( $qf ) ) {
            if ( (int) $cur_term->term_id === (int) $qf->term_id ) {
                $is_qf_family = true;
            } else {
                $ancestors = get_ancestors( (int) $cur_term->term_id, 'product_cat', 'taxonomy' );
                $is_qf_family = in_array( (int) $qf->term_id, array_map( 'intval', $ancestors ), true );
            }
        }
    }
}
?>
<div class="fh-shop-header">
    <div class="fh-shop-header__inner">
        <span class="fh-shop-count" data-fh-result-count>
            <?php
            global $wp_query;
            $total = $wp_query->found_posts;
            $noun  = $total === 1 ? $archive_noun_singular : $archive_noun_plural;
            printf(
                /* translators: %1$s formatted count, %2$s noun (fish/medications/foods/products) */
                esc_html__( 'Showing %1$s %2$s', 'fishotel' ),
                '<strong>' . number_format_i18n( $total ) . '</strong>',
                esc_html( $noun )
            );
            ?>
        </span>

        <?php
        // Sub-category chip strip (Saltwater / Reef-Safe / …). The legacy
        // "All Fish" reset button was removed — the breadcrumb + heading
        // already establish category context. Only render the container when
        // the quarantined-fish parent actually has child categories with
        // products, so we never leave an empty chip row behind.
        $fish_cats = [];
        if ( $is_qf_family ) {
            $qf_parent = get_term_by( 'slug', 'quarantined-fish', 'product_cat' );
            $fish_cats = $qf_parent ? get_terms([
                'taxonomy'   => 'product_cat',
                'parent'     => (int) $qf_parent->term_id,
                'hide_empty' => true,
            ]) : [];
            if ( is_wp_error( $fish_cats ) ) {
                $fish_cats = [];
            }
        }
        ?>
        <?php if ( ! empty( $fish_cats ) ) : ?>
        <div class="fh-shop-filters">
            <?php foreach ( $fish_cats as $fcat ) : ?>
                <a href="<?php echo esc_url(get_term_link($fcat)); ?>"
                   class="fh-filter-btn <?php echo is_product_category($fcat->slug) ? 'active' : ''; ?>">
                    <?php echo esc_html($fcat->name); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <select class="fh-shop-sort" onchange="window.location=this.value">
            <?php
            // Alphabetical is the default (no orderby param → title A→Z), so
            // it's the selected option on a fresh visit. Build option URLs
            // with add_query_arg so an existing query string (e.g. ?utm=…)
            // stays valid — the old `base . '?orderby='` concat produced a
            // malformed double-`?` URL.
            $sort_opts = [
                'title'      => 'Alphabetical',
                'date'       => 'Newest',
                'price'      => 'Price: Low to High',
                'price-desc' => 'Price: High to Low',
            ];
            $current_orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'title';
            $sort_base       = remove_query_arg( [ 'orderby', 'paged' ] );
            foreach ( $sort_opts as $key => $label ) :
                $opt_url = add_query_arg( 'orderby', $key, $sort_base );
            ?>
                <option value="<?php echo esc_url( $opt_url ); ?>" <?php selected( $current_orderby, $key ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php /* ── MEDICATIONS DUAL-AXIS FILTER STRIP ── */ ?>
<?php if ( function_exists( 'fishotel_is_medications_archive' ) && fishotel_is_medications_archive() ) :
    // Axes are hardcoded — slugs map 1:1 to the term IDs in the Phase 3.4
    // data-model table. Labels intentionally reframe the taxonomy slugs
    // ("antibacterial" → "Antibiotics", "antiparasitic" → "External") for
    // the customer-facing chip copy.
    $med_filter_axes = [
        'form' => [
            'label' => 'Form',
            'chips' => [
                [ 'slug' => 'flakes',  'label' => 'Flakes'  ],
                [ 'slug' => 'pellets', 'label' => 'Pellets' ],
                [ 'slug' => 'powders', 'label' => 'Powders' ],
                [ 'slug' => 'liquid',  'label' => 'Liquid'  ],
            ],
        ],
        'treats' => [
            'label' => 'Treats',
            'chips' => [
                [ 'slug' => 'antibacterial', 'label' => 'Antibiotics' ],
                [ 'slug' => 'dewormer',      'label' => 'Dewormers'   ],
                [ 'slug' => 'antiparasitic', 'label' => 'External'    ],
            ],
        ],
    ];
?>
<div class="fh-med-filters" data-fh-filter-strip>
    <?php foreach ( $med_filter_axes as $axis_key => $axis ) : ?>
    <div class="fh-med-filters__row" data-axis="<?php echo esc_attr( $axis_key ); ?>">
        <span class="fh-med-filters__label"><?php echo esc_html( strtoupper( $axis['label'] ) ); ?></span>
        <button type="button" class="fh-med-filters__chip is-active" data-slug="">All</button>
        <?php foreach ( $axis['chips'] as $chip ) : ?>
        <button type="button" class="fh-med-filters__chip" data-slug="<?php echo esc_attr( $chip['slug'] ); ?>"><?php echo esc_html( $chip['label'] ); ?></button>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <p class="fh-med-filters__empty" hidden>No medications match these filters. <a href="#" data-fh-clear-filters>Clear filters</a></p>
</div>
<?php endif; ?>

<?php /* ── PRODUCT GRID ── */ ?>
<?php if (have_posts()) : ?>
<div class="fh-product-grid">
    <?php while (have_posts()) : the_post();
        get_template_part( 'template-parts/product/fish-card' );
    endwhile; ?>
</div>

<?php /* ── LOAD MORE + PAGINATION FALLBACK ──
        The Load More button AJAX-appends the next batch (assets/js/shop-load-more.js).
        The paginate_links() block below is the no-JS fallback: shop-load-more.js
        hides it on init, so visitors with JS get Load More and visitors without
        still get working pagination links. Both only render when there's a page 2. */ ?>
<?php global $wp_query; if ( $wp_query->max_num_pages > 1 ) :
    $lm_button_text = class_exists( 'FisHotel_Admin_Settings' )
        ? (string) FisHotel_Admin_Settings::get( 'fh_load_more_text' )
        : 'Show More Fish';
    $lm_end_text = class_exists( 'FisHotel_Admin_Settings' )
        ? (string) FisHotel_Admin_Settings::get( 'fh_load_more_end_text' )
        : "You've Toured the Whole Hotel";
    $lm_orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
?>
<div class="fishotel-load-more-wrap"
     data-max-pages="<?php echo esc_attr( $wp_query->max_num_pages ); ?>"
     data-orderby="<?php echo esc_attr( $lm_orderby ); ?>"
     data-end-text="<?php echo esc_attr( $lm_end_text ); ?>">
    <button type="button" class="fishotel-load-more-btn" data-page="1">
        <span class="fishotel-load-more-label"><?php echo esc_html( $lm_button_text ); ?></span>
        <span class="fishotel-load-more-spinner" aria-hidden="true"></span>
    </button>
</div>

<div class="fishotel-pagination-fallback" style="text-align:center; padding:48px; border-top:1px solid var(--fh-border);">
    <?php
    echo paginate_links([
        'prev_text' => '&larr; Previous',
        'next_text' => 'Next &rarr;',
        'type'      => 'plain',
    ]);
    ?>
</div>
<?php endif; ?>

<?php else : ?>
<div style="text-align:center; padding:120px 48px; color:var(--fh-text-3);">
    <div style="font-family:var(--fh-serif); font-size:48px; font-weight:700; color:var(--fh-text-4); margin-bottom:16px;">No Fish Right Now</div>
    <p style="font-size:14px; margin-bottom:32px;">Check back soon — new arrivals are always checking in.</p>
    <a href="<?php echo esc_url(home_url('/newsletter/')); ?>" class="fh-btn fh-btn--gold">
        Get Notified of New Arrivals
    </a>
</div>
<?php endif; ?>

<?php get_footer(); ?>
