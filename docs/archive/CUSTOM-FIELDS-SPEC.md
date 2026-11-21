# Custom Fields — Foods & Feeding + Habitat & Behavior
*Replace regex parsing with proper meta fields*

## The Problem
Current approach parses "Foods & Feeding" and "Habitat & Behavior" 
content out of the WooCommerce product description using regex.
This is fragile — if Jeff writes descriptions differently the blocks 
break or show wrong content.

## The Fix
Add two dedicated textarea fields to the Hotel Data meta box in WP Admin.
Jeff fills them in once per product. Done. No parsing, no regex, no guessing.

---

## 1. ADD TO `inc/hotel-data.php`

Add two textarea fields to the existing FisHotel Hotel Data meta box:

```php
// In the meta box render function, add these two fields:

// Foods & Feeding
echo '<tr>';
echo '<th><label for="fishotel_foods_feeding">Foods &amp; Feeding</label></th>';
echo '<td><textarea id="fishotel_foods_feeding" name="fishotel_foods_feeding" 
     rows="4" style="width:100%">' 
     . esc_textarea( get_post_meta( $post->ID, '_fishotel_foods_feeding', true ) ) 
     . '</textarea>';
echo '<p class="description">What this fish eats, feeding frequency, recommended foods.</p>';
echo '</td></tr>';

// Habitat & Behavior
echo '<tr>';
echo '<th><label for="fishotel_habitat">Habitat &amp; Behavior</label></th>';
echo '<td><textarea id="fishotel_habitat" name="fishotel_habitat" 
     rows="4" style="width:100%">'
     . esc_textarea( get_post_meta( $post->ID, '_fishotel_habitat', true ) )
     . '</textarea>';
echo '<p class="description">Tank requirements, temperament, compatible tankmates.</p>';
echo '</td></tr>';
```

In the save function, add:
```php
if ( isset( $_POST['fishotel_foods_feeding'] ) ) {
    update_post_meta( $post_id, '_fishotel_foods_feeding', 
        sanitize_textarea_field( $_POST['fishotel_foods_feeding'] ) );
}
if ( isset( $_POST['fishotel_habitat'] ) ) {
    update_post_meta( $post_id, '_fishotel_habitat', 
        sanitize_textarea_field( $_POST['fishotel_habitat'] ) );
}
```

---

## 2. UPDATE `woocommerce/single-product.php`

Replace the regex-based block building with direct meta field reads:

```php
// REMOVE all the regex parsing for foods/habitat content

// REPLACE with:
$foods_feeding = get_post_meta( $product->get_id(), '_fishotel_foods_feeding', true );
$habitat       = get_post_meta( $product->get_id(), '_fishotel_habitat', true );

// Only show the dossier section if at least one field has content
if ( $foods_feeding || $habitat ) : ?>
    <div class="fh-careguide">
        <div class="fh-careguide__inner">
            <span class="fh-eyebrow">CARE GUIDE</span>
            <h2 class="fh-serif-head">Fish Dossier</h2>
            <div class="fh-careguide__blocks">
                <?php if ( $foods_feeding ) : ?>
                <div class="fh-careguide__block">
                    <h3 class="fh-careguide__block-title">Foods &amp; Feeding</h3>
                    <p class="fh-careguide__block-text"><?php echo esc_html( $foods_feeding ); ?></p>
                </div>
                <?php endif; ?>
                <?php if ( $habitat ) : ?>
                <div class="fh-careguide__block">
                    <h3 class="fh-careguide__block-title">Habitat &amp; Behavior</h3>
                    <p class="fh-careguide__block-text"><?php echo esc_html( $habitat ); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif;
```

---

## 3. ALSO CLEAN UP `woocommerce/single-product.php`

The current "About This Species" section still uses regex to parse 
Scientific Name, Max Length etc out of the description. 

For now, leave the species table as-is — the regex approach works 
fine for structured WooCommerce description data. Only the 
Foods & Feeding / Habitat sections need the custom field treatment
since those are variable per fish.

---

## Result

Jeff's WP Admin product edit screen will show:

```
FisHotel Hotel Data
├── Region: [_______________]
├── Notes: [_______________]
├── Foods & Feeding: [textarea]
└── Habitat & Behavior: [textarea]
```

Product page dossier blocks read directly from those fields.
If a field is empty, that block is hidden. Clean and simple.

