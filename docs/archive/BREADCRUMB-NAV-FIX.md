# Breadcrumb + Nav Fix

## Two bugs, same root cause — everything points to /shop/ instead of the category

---

## 1. Breadcrumb — "Quarantined Fish" not a link

In `woocommerce/single-product.php`, the breadcrumb renders:
```html
<span>Quarantined Fish</span>   ← plain text, not clickable
```

Fix: Make it a proper link:
```php
// Get the product's first category and link it
$cats = get_the_terms( $product->get_id(), 'product_cat' );
$cat  = $cats ? $cats[0] : null;
?>
<a href="<?php echo esc_url( home_url('/') ); ?>">Home</a>
<span>/</span>
<?php if ( $cat ) : ?>
    <a href="<?php echo esc_url( get_term_link( $cat ) ); ?>"><?php echo esc_html( strtoupper( $cat->name ) ); ?></a>
<?php else : ?>
    <a href="<?php echo esc_url( wc_get_page_permalink('shop') ); ?>">Shop</a>
<?php endif; ?>
<span>/</span>
<span style="color:var(--fh-text-2)"><?php the_title(); ?></span>
```

This makes "Quarantined Fish" link directly to `/product-category/quarantined-fish/`
No hardcoding — it reads the actual product category dynamically.
Works for any product category automatically.

---

## 2. Nav "Shop" link — points to /shop/ (messy mixed page)

The main nav "SHOP" link goes to `/shop/` which shows everything:
t-shirts, gift cards, deposits, fish — all mixed together.

Should go to `/product-category/quarantined-fish/` instead.

Fix in `header.php` — wherever the left nav links are built:
Change the Shop href from `<?php echo wc_get_page_permalink('shop'); ?>`
to `<?php echo esc_url( get_term_link( 'quarantined-fish', 'product_cat' ) ); ?>`

OR if nav is driven by a WordPress menu (Appearance → Menus),
just update the menu item URL in WP Admin to point to the category URL.

---

## Result
- Breadcrumb: Home / Quarantined Fish (clickable) / Pajama Cardinalfish
- Nav Shop link: goes directly to the fish category, not the mixed shop
- Both use the real category URL — no hardcoding

