# FisHotel Theme

Custom WordPress + WooCommerce theme for [fishotel.com](https://fishotel.com)

## Design System

| Token | Value | Notes |
|-------|-------|-------|
| Base background | `#252525` | The exact grey |
| Dark background | `#1c1c1c` | Sections, header |
| Gold accent | `#c9963a` | Primary accent |
| Gold light | `#deb96a` | Headings, prices |
| Font — UI | Montserrat | All UI text |
| Font — Display | Roboto Slab | Headings, prices |

## File Structure

```
fishotel-theme/
├── style.css              Theme declaration
├── functions.php          Theme setup, WooCommerce support, asset loading
├── index.php              Fallback template
├── header.php             Site nav (centered logo, split left/right links)
├── footer.php             Footer with 4-column layout
├── inc/
│   ├── hotel-data.php         ★ Quarantine meta fields (arrival, days, stage, health, treatments)
│   ├── hotel-journal.php      ★ Quarantine journal entries (repeatable)
│   ├── variation-display.php  ★ Visual variation buttons (replaces WC dropdowns)
│   ├── woocommerce.php        WooCommerce wrappers + hooks
│   ├── template-functions.php Helper functions
│   └── customizer.php         (TODO Phase 2)
├── assets/
│   ├── css/
│   │   ├── main.css        ★ Full design system (tokens, components, responsive)
│   │   └── woocommerce.css (TODO Phase 2)
│   └── js/
│       └── main.js         Variation buttons, gallery, mobile nav, qty
├── template-parts/
│   ├── header/
│   ├── product/
│   ├── shop/
│   └── home/
└── woocommerce/
    ├── single-product.php  (TODO Phase 2)
    └── loop/               (TODO Phase 2)
```

## ★ FisHotel Custom Features

### Hotel Data Meta Fields (inc/hotel-data.php)
Every WooCommerce product gets a "FisHotel — Hotel Data" meta box with:
- **Check-In Date** — when the fish arrived
- **Days In Quarantine** — auto-calculated from arrival date
- **QT Stage** — Check-In / Observation / Treatment / Souvenir Shop / Flying Home
- **Health Grade** — A / B / C / Pending
- **Importer** — e.g. "EMark Aquatics (New York)"
- **Foods Eating** — e.g. "Mysis, Nori, Pellets"
- **Health Note** — short status message
- **Treatments** — repeatable list with dates

### Quarantine Journal (inc/hotel-journal.php)
- Repeatable journal entries per product
- Types: arrival, observation, treatment, cleared, eating, note
- Color-coded timeline on the product page
- Sorted newest-first

### Visual Variation Selectors (inc/variation-display.php)
- Replaces WooCommerce dropdowns with button groups
- Supports: Size, Sex, Phase, Color, and any custom attribute
- Hidden `<select>` keeps WooCommerce JS working underneath

## Build Phases

- **Phase 1** ✅ Foundation — Design system, meta fields, variation display
- **Phase 2** 🔄 Templates — header.php, single-product.php, archive-product.php
- **Phase 3** — Journal CRUD UI, homepage, cart/checkout styling
- **Phase 4** — Mobile polish, performance, go live

## Staging

Live at: `Staging-fishotel` on Cloudways (fishotel-server, Atlanta)

## Notes for Claude

When continuing this project, tell Claude:
> "Continue the FisHotel theme — check the GitHub repo at Dierks27/FisHotel-Theme for current state"

Key decisions made:
- Base grey is exactly `#252525` — do NOT change this
- Montserrat is the existing site font — keep it as primary
- Roboto Slab for display headers (Safari2 influence)
- ALL CAPS for product names — retro personality
- Centered logo nav (Safari2 influence)
- No RevSlider, no Elementor, no TRX Addons — clean slate
- WooCommerce variable products with Phase/Color/Size/Sex attributes
