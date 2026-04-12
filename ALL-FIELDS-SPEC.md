# Full Custom Fields Spec — No More Parsing
*Every piece of data on the product page gets its own field*

## The Goal
Zero regex. Zero description parsing. Every field on the product page 
comes from a dedicated meta field that Jeff or any non-developer fills in.
Missing field = that element is hidden gracefully. No errors, no broken layouts.

---

## NEW META BOX STRUCTURE IN WP ADMIN

Replace the current FisHotel — Product Data meta box with this full layout.
Keep it clean and grouped — not an intimidating wall of inputs.

```
╔══════════════════════════════════════════════════════╗
║  🐟 FisHotel — Product Data                          ║
╠══════════════════════════════════════════════════════╣
║                                                      ║
║  ── SPECIES INFO ──────────────────────────────────  ║
║  Scientific Name   [_____________________________]   ║
║  Common Names      [_____________________________]   ║
║  Max Length        [_____________________________]   ║
║  Min Tank Size     [_____________________________]   ║
║  Temperament       [_____________________________]   ║
║  Reef Safe         ( ) Yes  ( ) No  ( ) With Caution ║
║  Region            [_____________________________]   ║
║                                                      ║
║  ── CARE GUIDE ────────────────────────────────────  ║
║  Foods & Feeding   [textarea_____________________]   ║
║                    [_____________________________]   ║
║  Habitat &         [textarea_____________________]   ║
║  Behavior          [_____________________________]   ║
║                                                      ║
║  ── NOTES (internal, not shown on site) ───────────  ║
║  Notes             [textarea_____________________]   ║
║                                                      ║
╚══════════════════════════════════════════════════════╝
```

---

## META KEYS (save/retrieve these)

| Field | Meta Key | Input Type |
|-------|----------|------------|
| Scientific Name | `_fh_scientific_name` | text |
| Common Names | `_fh_common_names` | text |
| Max Length | `_fh_max_length` | text |
| Min Tank Size | `_fh_min_tank_size` | text |
| Temperament | `_fh_temperament` | text |
| Reef Safe | `_fh_reef_safe` | radio: `yes` / `no` / `caution` |
| Region | `_fh_region` | text |
| Foods & Feeding | `_fh_foods_feeding` | textarea |
| Habitat & Behavior | `_fh_habitat` | textarea |
| Notes (internal) | `_fh_notes` | textarea |

---

## UPDATES TO `woocommerce/single-product.php`

### About This Species section — read from meta fields, not description

```php
$sci_name    = get_post_meta( $product->get_id(), '_fh_scientific_name', true );
$common      = get_post_meta( $product->get_id(), '_fh_common_names', true );
$max_length  = get_post_meta( $product->get_id(), '_fh_max_length', true );
$tank_size   = get_post_meta( $product->get_id(), '_fh_min_tank_size', true );
$temperament = get_post_meta( $product->get_id(), '_fh_temperament', true );
$reef_safe   = get_post_meta( $product->get_id(), '_fh_reef_safe', true );
$region      = get_post_meta( $product->get_id(), '_fh_region', true );

// Build the table only with rows that have content
$spec_rows = [];
if ( $sci_name )    $spec_rows[] = [ 'Scientific Name', $sci_name ];
if ( $common )      $spec_rows[] = [ 'Common Names',    $common ];
if ( $max_length )  $spec_rows[] = [ 'Max Length',      $max_length ];
if ( $tank_size )   $spec_rows[] = [ 'Min Tank Size',   $tank_size ];
if ( $temperament ) $spec_rows[] = [ 'Temperament',     $temperament ];
if ( $reef_safe ) {
    $labels = [ 'yes' => '✓ Yes', 'no' => '✗ No', 'caution' => '⚠ With Caution' ];
    $spec_rows[] = [ 'Reef Safe', $labels[ $reef_safe ] ?? $reef_safe ];
}
if ( $region )      $spec_rows[] = [ 'Region',          $region ];

// Only show section if there's at least one row
if ( ! empty( $spec_rows ) ) :
    // render table with $spec_rows
endif;
```

### Care Guide section — already using meta fields, just update to new keys

```php
$foods_feeding = get_post_meta( $product->get_id(), '_fh_foods_feeding', true );
$habitat       = get_post_meta( $product->get_id(), '_fh_habitat', true );
```

### Description prose — just use WooCommerce's standard description

The product's main description (`$product->get_description()`) stays as-is 
for the "About This Species" prose paragraph. Only the STRUCTURED data 
moves to custom fields. The description is just free-form text now.

---

## BACKWARDS COMPATIBILITY

After this change, existing products will show empty spec tables until 
Jeff fills in the fields. That's fine and expected. The table/section 
simply won't render if all fields are empty.

The old meta keys (`_fishotel_foods_feeding`, `_fishotel_habitat`) 
can be migrated: when saving, if the new key is empty but the old key 
has a value, copy it over. Or just re-enter — there aren't many products.

---

## IMPORTANT UX RULES FOR THE META BOX

1. **Labels should be plain English** — "Scientific Name" not "fh_scientific_name"
2. **Placeholder text** on every field explaining what to enter
3. **No required fields** — everything is optional, missing = hidden on frontend
4. **Group with visual separators** — Species Info / Care Guide / Internal Notes
5. **Reef Safe as radio buttons** — not a dropdown, not a text field
6. **Notes field** — add "(not shown on website)" so Jeff knows it's internal only

