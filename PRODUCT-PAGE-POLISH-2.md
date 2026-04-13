# Product Page — Polish Round 2
*20 min scope — 3 targeted fixes*

---

## 1. "Description:" label — style it, don't strip it

WooCommerce REQUIRES the description field to be filled for variable 
products to function. Cannot remove it. Instead, style it as an 
intentional section header.

In `single-product.php`, before outputting the prose, transform:
```php
// Strip "Description:" prefix from display text but wrap it styled
$prose = preg_replace('/^Description:\s*/i', '', trim($prose));
```
Then output the prose section like this:
```php
<?php if ( $prose ) : ?>
<div class="fh-species__prose">
    <h3 class="fh-species__prose-label">About This Fish</h3>
    <p><?php echo wp_kses_post( nl2br( $prose ) ); ?></p>
</div>
<?php endif; ?>
```

CSS for `.fh-species__prose-label`:
```css
.fh-species__prose-label {
    color: var(--fh-gold);           /* #c9963a */
    font-family: 'Montserrat', sans-serif;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    margin: 0 0 10px 0;
}
.fh-species__prose {
    margin-top: 28px;
    padding-top: 24px;
    border-top: 1px solid rgba(255,255,255,0.06);
    color: var(--fh-text-2);
    font-size: 14px;
    line-height: 1.8;
}
```

So it renders as:
```
ABOUT THIS FISH          ← gold eyebrow label
The Pajama Cardinalfish is a charming nocturnal species...
```

Clean, intentional, no raw "Description:" showing.

---

## 2. Strip Fun Facts from prose output

Fun Facts are being removed from the product page. They live in the 
WooCommerce description but should not display on the frontend.

In `single-product.php`, when building the prose output, strip 
everything from "Fun Facts:" onwards:

```php
// Strip Fun Facts and everything after it
$prose = preg_replace('/Fun Facts?:?[\s\S]*/i', '', $prose);
$prose = trim($prose);
```

Apply this AFTER stripping the structured labels (Scientific Name, etc.)
but BEFORE outputting.

---

## 3. Prose visual separation from species table

Already covered by the CSS in fix #1 above:
- `margin-top: 28px` 
- `padding-top: 24px`
- `border-top: 1px solid rgba(255,255,255,0.06)` hairline

---

## Files to edit
- `woocommerce/single-product.php` — prose cleanup + Fun Facts strip
- `assets/css/woocommerce.css` — add `.fh-species__prose` and `.fh-species__prose-label` styles

## What NOT to change
- The WooCommerce description field itself — leave it alone
- The species table — working perfectly
- The dossier blocks — working perfectly
- The QT cert / trust strip / variation buttons — all good

