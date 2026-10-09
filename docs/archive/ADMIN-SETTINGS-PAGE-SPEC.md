# FisHotel Admin Settings Page — Full Spec
*Final pass task — pull all hardcoded values into one admin UI*

## The Principle
Nothing in the theme should be hardcoded. Every setting that could 
vary between stores or change over time gets a field in the admin.

---

## Settings to expose (discovered so far)

### Shop Page
- **Shop page display** — Categories / Products / Both
  (currently hardcoded: shows categories when is_shop())
- **Categories to show on shop page** — multi-checkbox of all product categories
  (currently shows ALL including empty/internal ones)
- **Hide empty categories** — Yes / No toggle

### Product Page — QT Certificate
- **QT protocol line 1** — text field (currently: "14 days observation")
- **QT protocol line 2** — text field (currently: "+ 14 days treatment")

### Product Page — Trust Strip  
- **Trust item 1** — text field (currently: "28-day QT protocol")
- **Trust item 2** — text field (currently: "Live arrival guarantee")
- **Trust item 3** — text field (currently: "Ships Mon–Tue")

### Product Page — Shipping Days
- **Ships on** — multi-checkbox: Mon / Tue / Wed / Thu / Fri
  (trust strip auto-generates "Ships Mon–Tue" from selection)

### Navigation
- **Left nav items** — (currently driven by WP menu — leave as WP menus)
- **Right nav items** — (currently driven by WP menu — leave as WP menus)

### Branding
- **Tagline under logo** — text field (currently: "We quarantine. You reef.")

### Care Guide Defaults
- **Default Foods & Feeding text** — shown when no custom field filled in
- **Default Habitat & Behavior text** — shown when no custom field filled in

---

## Implementation

### Where it lives
Add a new "FisHotel Settings" page under the existing FisHotel Tools menu.
WP Admin → FisHotel Tools → Settings (new tab)

### How to build
Use WordPress Settings API:
- `register_setting()` for each option
- `add_settings_section()` to group them
- `add_settings_field()` for each field
- All values stored in `wp_options` table as `fh_setting_*`

### How theme reads them
Replace every hardcoded value with:
```php
get_option( 'fh_setting_qt_line_1', '14 days observation' )
//                                   ↑ fallback default if not set
```

### Example structure
```
FisHotel Tools
├── Tools (existing — migration button)
└── Settings (new)
    ├── ── SHOP ──────────────────────────
    │   Shop page display:    [Categories ▼]
    │   Show categories:      [✓] Quarantined Fish
    │                         [✓] Merchandise  
    │                         [ ] Gift Cards
    │                         [ ] (hide empty automatically)
    │
    ├── ── PRODUCT PAGE ──────────────────
    │   QT Line 1:            [14 days observation      ]
    │   QT Line 2:            [+ 14 days treatment      ]
    │   Trust item 1:         [28-day QT protocol       ]
    │   Trust item 2:         [Live arrival guarantee   ]
    │   Trust item 3:         [Ships Mon–Tue            ]
    │
    ├── ── BRANDING ──────────────────────
    │   Logo tagline:         [We quarantine. You reef. ]
    │
    └── ── CARE GUIDE ────────────────────
        Default Foods text:   [textarea                 ]
        Default Habitat text: [textarea                 ]
```

---

## Note on current shop page
Until the settings page is built, the shop page shows ALL categories 
including empty internal ones (3D Printed Parts, Delivery Dates, etc).

Quick interim fix for `archive-product.php`:
- Add `'hide_empty' => true` to the get_terms() call (already there but 
  WC may be counting child products — try `'count' => true` as well)
- OR: filter to only show categories whose parent = 0 AND count > 0

The settings page is the permanent fix.


---

## ADDITION: Hidden Categories Setting

Add a new field to the Shop Page section of FisHotel Settings:

```
── SHOP PAGE ─────────────────────────────────────────
Shop page display:       [Categories ▼]
Hide empty categories:   [✓] Yes
Hidden categories:       [ ] 3D Printed Parts
                         [ ] Delivery Dates
                         [ ] Donations
                         [ ] Fish Food
                         [✓] Gift Cards
                         [✓] Invoices
                         [ ] Inverts
                         [ ] Medications
                         [✓] Merchandise
                         [✓] Quarantined Fish
                         [ ] Sales
                         [ ] Tank Equipment
                         [ ] Wallet
```

### Implementation

**In `admin-settings.php` — register new field:**
```php
// In the 'shop' section fields array:
'fh_shop_hidden_cats' => [
    'label'       => 'Hidden categories',
    'type'        => 'multicheck',
    'description' => 'These categories will never appear on the shop page.',
    'options'     => [], // populated dynamically from get_terms()
],
```

**render_field() — add multicheck type:**
```php
} elseif ( $type === 'multicheck' ) {
    $saved    = get_option( $key, [] );
    if ( ! is_array( $saved ) ) $saved = [];
    $all_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    foreach ( $all_cats as $cat ) {
        printf(
            '<label style="display:block; margin-bottom:4px;">
                <input type="checkbox" name="%s[]" value="%s" %s> %s
            </label>',
            esc_attr( $key ),
            esc_attr( $cat->slug ),
            checked( in_array( $cat->slug, $saved ), true, false ),
            esc_html( $cat->name )
        );
    }
}
```

**register_settings() — save as array:**
```php
register_setting( self::OPTION_GROUP, 'fh_shop_hidden_cats', [
    'type'              => 'array',
    'sanitize_callback' => function( $val ) {
        if ( ! is_array( $val ) ) return [];
        return array_map( 'sanitize_text_field', $val );
    },
    'default' => [],
] );
```

**In `archive-product.php` — filter hidden cats:**
```php
$hidden_cats = FisHotel_Admin_Settings::get('fh_shop_hidden_cats');
if ( ! is_array( $hidden_cats ) ) $hidden_cats = [];

if ( ! empty( $hidden_cats ) ) {
    $cats = array_filter( $cats, function( $cat ) use ( $hidden_cats ) {
        return ! in_array( $cat->slug, $hidden_cats );
    });
}
```

**Also add to `FisHotel_Admin_Settings::get()` — handle array default:**
```php
public static function get( $key ) {
    $defaults = self::defaults();
    $default  = $defaults[ $key ] ?? '';
    $value    = get_option( $key, $default );
    return $value;
}
```

### Result
Jeff goes to FisHotel Settings → Shop Page → Hidden Categories
Checks "Invoices" → Invoices disappears from the shop page immediately.
Works regardless of whether the category has products or not.
Safe to check/uncheck at any time.
