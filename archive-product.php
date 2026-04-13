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
<div class="fh-shop-header">
    <div class="fh-shop-header__inner">
        <span class="fh-shop-count">
            <?php
            global $wp_query;
            $total = $wp_query->found_posts;
            printf(
                esc_html(_n('Showing %s fish', 'Showing %s fish', $total, 'fishotel')),
                '<strong>' . number_format_i18n($total) . '</strong>'
            );
            ?>
        </span>

        <div class="fh-shop-filters">
            <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>"
               class="fh-filter-btn <?php echo is_shop() && !is_product_category() ? 'active' : ''; ?>">
                All Fish
            </a>
            <?php
            $fish_cats = get_terms(['taxonomy' => 'product_cat', 'parent' => get_term_by('slug', 'quarantined-fish', 'product_cat')->term_id ?? 0, 'hide_empty' => true]);
            if ($fish_cats && !is_wp_error($fish_cats)) :
                foreach ($fish_cats as $fcat) : ?>
                <a href="<?php echo esc_url(get_term_link($fcat)); ?>"
                   class="fh-filter-btn <?php echo is_product_category($fcat->slug) ? 'active' : ''; ?>">
                    <?php echo esc_html($fcat->name); ?>
                </a>
            <?php endforeach; endif; ?>
        </div>

        <select class="fh-shop-sort" onchange="window.location=this.value">
            <?php
            $sort_opts = [
                '?orderby=date'       => 'Newest',
                '?orderby=price'      => 'Price: Low to High',
                '?orderby=price-desc' => 'Price: High to Low',
                '?orderby=title'      => 'Name: A–Z',
            ];
            $current = add_query_arg('orderby', get_query_var('orderby'), get_pagenum_link());
            foreach ($sort_opts as $url => $label) : ?>
                <option value="<?php echo esc_url(get_pagenum_link() . $url); ?>"><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php /* ── PRODUCT GRID ── */ ?>
<?php if (have_posts()) : ?>
<div class="fh-product-grid">
    <?php while (have_posts()) : the_post();
        global $product;
        $pid        = $product->get_ID();
        $hotel      = function_exists('fishotel_get_hotel_data') ? fishotel_get_hotel_data($pid) : [];
        $stage      = $hotel['qt_stage'] ?? '';
        $days       = $hotel['days_in_qt'] ?? '';
        if ( $stage === 'souvenir-shop' ) {
            $status_class = 'fh-fish-card__status--available';
        } elseif ( in_array( $stage, array( 'treatment', 'checkin', 'observation' ) ) ) {
            $status_class = 'fh-fish-card__status--qt';
        } else {
            $status_class = '';
        }
        if ( $stage === 'souvenir-shop' )    { $status_label = 'Available'; }
        elseif ( $stage === 'treatment' )    { $status_label = 'In Treatment'; }
        elseif ( $stage === 'observation' )  { $status_label = 'In QT'; }
        elseif ( $stage === 'checkin' )      { $status_label = 'Checked In'; }
        else                                 { $status_label = ''; }
    ?>
        <a href="<?php the_permalink(); ?>" class="fh-fish-card">
            <div class="fh-fish-card__image">
                <?php the_post_thumbnail('fishotel-product-card'); ?>
                <?php if ($status_label) : ?>
                <span class="fh-fish-card__status <?php echo esc_attr($status_class); ?>">
                    <?php echo esc_html($status_label); ?>
                </span>
                <?php endif; ?>
                <?php if ($days) : ?>
                <div class="fh-fish-card__days">
                    <span class="fh-fish-card__days-num"><?php echo esc_html($days); ?></span>
                    <span class="fh-fish-card__days-label">Days QT</span>
                </div>
                <?php endif; ?>
            </div>
            <div class="fh-fish-card__body">
                <div class="fh-fish-card__name"><?php the_title(); ?></div>
                <div class="fh-fish-card__latin"><?php echo wp_kses_post($product->get_short_description()); ?></div>
                <div class="fh-fish-card__footer">
                    <span class="fh-fish-card__price"><?php echo $product->get_price_html(); ?></span>
                    <?php if ($days) : ?>
                    <span class="fh-fish-card__qt"><?php echo esc_html($days); ?> Days QT</span>
                    <?php endif; ?>
                </div>
            </div>
        </a>
    <?php endwhile; ?>
</div>

<?php /* ── PAGINATION ── */ ?>
<div style="text-align:center; padding:48px; border-top:1px solid var(--fh-border);">
    <?php
    echo paginate_links([
        'prev_text' => '&larr; Previous',
        'next_text' => 'Next &rarr;',
        'type'      => 'plain',
    ]);
    ?>
</div>

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
