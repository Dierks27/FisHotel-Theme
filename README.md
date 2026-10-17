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
│   ├── invoice-lines.php      ★ Invoice products → itemised fish lines at checkout, purchase lock, backfill
│   ├── theme-updater.php      Self-updater from the GitHub main branch
│   ├── woocommerce.php        WooCommerce wrappers + hooks
│   ├── template-functions.php Helper functions
│   ├── calculator/            Medication Dosing Calculator data + wiring
│   ├── scheduler/             QT Scheduler wiring
│   ├── medication-store/      Medication / food store (EA sync, pricing, packing slip, checkout terms record — see disclaimer.php docblock)
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

### Medication Terms Record (inc/medication-store/terms-record.php + terms-display.php)
When the cart holds a medication, checkout shows the required "ornamental use only" checkbox
with a "Read the terms" link (opens the disclaimer in a read-only modal, or the Terms page when
one is set). On order creation the full acceptance record is stored as `_fishotel_med_terms`
order meta: timestamp (UTC + site time), IP, user agent, the exact checkbox text, terms version
and hash, the medication lines in the cart and any extra acknowledgements. It shows on the admin
order screen ("Medication terms" box — an order with meds and no record is flagged NOT RECORDED
with a "Record manual acknowledgement" button for phone / admin-created orders; a fulfillment
lists its source orders' records), in the processing / completed / invoice / new-order emails,
on the WPO PDF invoice and packing slip, on the EA packing slip (date + version, no IP) and on
My Account → view order.

Settings (FisHotel Theme → Medication Store):
- **Terms version** (`fishotel_med_terms_version`, default `2026-10`) — bump when the wording changes;
  every order records the version it accepted.
- **Terms page** (`fishotel_med_terms_page`) — optional public page containing `[fishotel_med_terms]`
  (prints the body, version and checkbox label); the "Read the terms" links then go there.
- **Extra checkout acknowledgement** — per-product textarea (`_fishotel_med_extra_ack`) on the
  FisHotel Medication tab; each distinct text adds one more required checkbox at checkout
  (e.g. chloroquine) and the accepted text is stored on the order.

### Invoices become itemised orders (inc/invoice-lines.php)
An invoice is a hidden product in the `invoices` category (or with "Invoice" in its name) whose
description lists the fish — `Blue Tang x2 — $90`, `Firefish x1 — $12`, `Credit: lost Cleaner
Wrasse — -$82` — and whose regular price is the total. The customer adds it by link and checks
out normally. Since v1.25.0 the resulting order shows the fish, not the invoice:

- **Cart / checkout:** an "Includes: Blue Tang × 2, Firefish × 1, Credit: lost Cleaner Wrasse"
  row under the invoice; an invoice is sold individually (never twice in one cart).
- **At checkout** (`woocommerce_checkout_create_order`, priority 20, i.e. after WooCommerce has
  added the items, fees, shipping, taxes and coupons to the not-yet-saved order) every invoice
  line is **expanded** into one line item per fish line (`Blue Tang × 2`, `Firefish × 1`, no
  product behind them) and one negative fee per credit (`Credit: lost Cleaner Wrasse −$82.00`).
  A coupon discount and every tax amount are split across the fish lines in proportion to their
  subtotal, in whole cents (leftover cents go to the lines that lost most in rounding, the last
  line first, so no line is ever discounted below zero or above its own subtotal), so the new rows
  add up to exactly what the invoice line was. The order total, tax and coupon lines are never
  recalculated — what the customer pays does not change.
- **Fallback:** the invoice stays a single line when the item quantity is not 1, the description
  has no fish lines, a fish line has no price, or the lines (fish qty × price plus credits) do not
  add up to the line subtotal within a cent (so an invoice sold at a sale price, whose lines add
  up to the regular price, stays one line). The line then carries a visible "Includes" meta with
  the fish list and a hidden line snapshot. Either way the order gets a note: `Invoice jpreef
  Invoice (7/3/26) (#123) itemised into 2 fish lines and 1 credit.` or `Invoice … kept as one
  line: <reason>.`
- **Paid orders stay in Processing:** WooCommerce would auto-complete a paid order whose lines have
  no product; the theme keeps an itemised invoice order in Processing so it ships like any other.
- **Lock after purchase:** when the order is paid / processing / completed the invoice product is
  set out of stock and remembers the order (`_fh_invoice_order_id`). Adding it to a cart again
  shows "This invoice was already paid on order #N. Contact us if you need a new one." until that
  order is cancelled, failed or refunded; cancelling the order puts the invoice back in stock
  (after a refund or failure it simply stays out of stock until you restock it by hand).
- **Backfill:** FisHotel Theme → Tools → "Add fish lines to past invoice orders" ("Scan the next
  100 orders" button) scans 100 orders per click (newest first, cursor in option
  `fishotel_invoice_backfill_page`) and adds the "Includes" meta + line snapshot to invoice lines
  on older orders — nothing else changes.
- **Explicit lines:** product meta `_fh_invoice_lines` (JSON `[{"name","qty","price","kind","product_id"}]`,
  kind `fish` | `credit` | `note`) wins over the description; a `product_id` makes the fish line
  reference that product.

Meta keys (shared with the Batch plugin's Ship Day pick list, which reads them):

| Where | Key | Meaning |
|-------|-----|---------|
| fish line item | `_fh_invoice_fish` = 1, `_fh_invoice_product_id`, `_fh_invoice_title` | one fish of an invoice; the invoice product id / name |
| credit fee item | `_fh_invoice_credit` = 1, `_fh_invoice_product_id` | an invoice credit (money only, never a pick row) |
| invoice line kept whole | `Includes` (visible), `_fh_invoice_lines` (JSON), `_fh_invoice_product_id` | the fallback shape and the backfill shape |
| invoice product | `_fh_invoice_order_id` | the order that paid for it (lock); `_fh_invoice_lines` optional line override |

The parser (same grammar as the Batch plugin's `parse_invoice_lines()`, credits returned instead
of skipped), the expand/fallback decision and the money split are pure PHP, tested by
`php tests/invoice-lines-test.php`.

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
