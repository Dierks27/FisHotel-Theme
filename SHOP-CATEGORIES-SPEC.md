# Shop Page — Show Categories Instead of Products

## The Problem
Clicking "Shop" in the nav shows ALL products mixed together 
(fish, t-shirts, gift cards, deposits, invoices). 

Jeff wants: Shop → category tiles → click a category → products in that category.

The old WooCommerce "Shop page display" setting (show categories/products/both)
was removed in newer WooCommerce versions. Must be handled in the theme.

## The Fix — in `archive-product.php`

At the very top of the template, add a check:

```php
// If on the main shop page (not inside a category), show category tiles
if ( is_shop() && ! is_product_category() ) {
    // Get all top-level product categories with products
    $cats = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'parent'     => 0,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    get_header(); ?>
    <div class="fh-page-hero">
        <div class="fh-page-hero__inner">
            <h1 class="fh-page-hero__title">Shop</h1>
        </div>
    </div>
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
                    <span class="fh-cat-card__count"><?php echo $cat->count; ?> product<?php echo $cat->count !== 1 ? 's' : ''; ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php get_footer();
    return; // Stop here — don't render the normal product grid
}
// === Everything below is existing archive-product.php code ===
```

## CSS to add to `woocommerce.css`

```css
.fh-shop-categories {
    max-width: var(--fh-max-width, 1200px);
    margin: 0 auto;
    padding: 60px var(--fh-page-pad, 24px);
}
.fh-shop-categories__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 24px;
}
.fh-cat-card {
    display: block;
    background: var(--fh-bg-dk);
    border-radius: 4px;
    overflow: hidden;
    text-decoration: none;
    transition: transform 0.2s;
}
.fh-cat-card:hover {
    transform: translateY(-3px);
}
.fh-cat-card__image {
    height: 220px;
    background-size: cover;
    background-position: center;
    background-color: var(--fh-bg-dkr);
}
.fh-cat-card__body {
    padding: 20px;
}
.fh-cat-card__name {
    color: var(--fh-text-1);
    font-family: 'Montserrat', sans-serif;
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin: 0 0 6px 0;
}
.fh-cat-card__count {
    color: var(--fh-text-2);
    font-size: 12px;
    font-family: 'Montserrat', sans-serif;
}
```

## Result
- /shop/ → shows category tiles (Quarantined Fish, Merchandise, etc.)
- /product-category/quarantined-fish/ → shows fish grid (unchanged)
- Nav "Shop" link stays pointing to /shop/
- Breadcrumb: Home / Shop / Quarantined Fish / Product (all clickable)

