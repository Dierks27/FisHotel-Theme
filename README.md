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
├── functions.php          Theme setup, WooCommerce support, asset loading, module includes
├── index.php              Fallback template
├── header.php / footer.php  Site nav (centered logo, split left/right links) + 4-column footer
├── front-page.php         Homepage
├── archive-product.php    Shop + category grid (Load More, Coming Soon cards)
├── page-*.php             About, Contacts, FAQ, Newsletter, Quarantine Help (dosing calculator),
│                          QT Scheduler, Compatibility Guide, Bleach Calculator templates
├── inc/
│   ├── admin-settings.php     ★ FisHotel Theme admin pages (all editable copy + images)
│   ├── hotel-data.php         ★ Product Data meta box (species info, care guide, notes)
│   ├── hotel-journal.php      ★ Quarantine journal entries (repeatable)
│   ├── variation-display.php  ★ Visual variation buttons (replaces WC dropdowns)
│   ├── placeholder-library.php  Placeholder images for products without a photo
│   ├── tool-product-links.php   Calculator / QT Scheduler → medication product links
│   ├── theme-updater.php      Self-updater from the GitHub main branch
│   ├── woocommerce.php        WooCommerce wrappers + hooks
│   ├── template-functions.php Helper functions
│   ├── calculator/            Medication Dosing Calculator data + wiring
│   ├── scheduler/             QT Scheduler wiring
│   ├── medication-store/      Medication / food store (EA sync, pricing, packing slip)
│   ├── order-fulfillment/     Split fulfillment, shipments meta box, checkout reuse
│   ├── customer/              Self-serve delivery date, address propagation
│   └── email/                 Consolidated shipping email
├── parts/                 Compatibility Guide + Bleach Calculator template parts
├── template-parts/
│   └── product/fish-card.php
├── woocommerce/
│   ├── single-product.php
│   ├── cart/ · checkout/ · myaccount/ · loop/
├── assets/
│   ├── css/               main.css ★ design system, woocommerce.css, per-page sheets
│   └── js/                main.js, calculator/, scheduler/, per-page scripts
├── tests/                 Headless unit tests (`node tests/*.test.js`)
├── tools/                 One-off admin scripts (product field migration)
├── docs/                  Specs; docs/archive/ holds the original planning documents
├── vendor/                Composer deps (dompdf) — committed on purpose, see below
└── .github/workflows/     Lint (php -l / node --check) on push + PR
```

`vendor/` is intentionally committed: the site deploys by git pull on Cloudways with no
Composer step, so dompdf (used for the EA packing-slip PDF) has to ship in the repo.

## ★ FisHotel Custom Features

### Product Data Meta Fields (inc/hotel-data.php)
Every WooCommerce product gets a "FisHotel — Product Data" meta box with three groups:

**Species Info** (rendered as the species stat grid on the product page)
- **Scientific Name** (`_fh_scientific_name`)
- **Common Names** (`_fh_common_names`)
- **Max Length** (`_fh_max_length`)
- **Min Tank Size** (`_fh_min_tank_size`)
- **Temperament** (`_fh_temperament`)
- **Reef Safe** — radio: yes / no / with caution (`_fh_reef_safe`)
- **Region** (`_fh_region`)

**Care Guide (Fish Dossier)**
- **Foods & Feeding** (`_fh_foods_feeding`) — falls back to the Care Guide default in FisHotel Settings
- **Habitat & Behavior** (`_fh_habitat`) — same fallback

**Notes** (not shown on website)
- **Notes** (`_fh_notes`) — internal only

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
- **Phase 2** ✅ Templates — header.php, single-product.php, archive-product.php, woocommerce.css
- **Phase 3** ✅ Homepage, cart/checkout styling, admin settings pages
- **Phase 4** 🔄 Mobile polish, performance, go live

Current status and remaining work: see `FISHOTEL-PROJECT-STATUS.md`.

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
