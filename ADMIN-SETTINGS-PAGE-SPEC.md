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

