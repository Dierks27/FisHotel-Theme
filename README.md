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
│   ├── order-fulfillment/     Split fulfillment, shipments meta box (+ what each box cost: label, packing; local pickups), checkout reuse
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
├── tests/                 Headless unit tests (`node tests/*.test.js`, `php tests/*-test.php`)
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
- **Lock after purchase:** when the order is paid / on hold / processing / completed the invoice
  product is set out of stock and remembers the order (`_fh_invoice_order_id`). Adding it to a
  cart again shows "This invoice was already paid on order #N. Contact us if you need a new one."
  (for an On-hold order: "…already has an order (#N) that is waiting for its payment…") until that
  order is cancelled, failed or refunded; cancelling the order puts the invoice back in stock
  (after a refund or failure it simply stays out of stock until you restock it by hand).
- **Hold while unpaid (v1.25.1):** an order that is still Pending payment keeps the invoice away
  from every other shopper for WooCommerce's "Hold stock (minutes)" (WooCommerce → Settings →
  Products → Inventory; 60 by default, empty = no hold): "This invoice is being paid for on
  another order right now…" on Add to cart and at Place order. The shopper who owns that unpaid
  order can retry. The product records it as `_fh_invoice_pending_order_id`.
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
| invoice product | `_fh_invoice_order_id` | the order that paid for it (lock); `_fh_invoice_pending_order_id` the unpaid order checking it out; `_fh_invoice_lines` optional line override |

The parser (same grammar as the Batch plugin's `parse_invoice_lines()`, credits returned instead
of skipped), the expand/fallback decision and the money split are pure PHP, tested by
`php tests/invoice-lines-test.php`.

### What each box cost to ship (inc/order-fulfillment/class-fishotel-shipment-cost.php)
The price of a label is known the moment it is bought, so since v1.26.0 the Shipments box on the
order screen records it with the tracking number and shows it against the shipping the customer
paid. The data lives in ShipTracker (v1.9.17+, one cost per tracking number = one physical box);
the theme only shows and posts it.

- **Only when ShipTracker understands it.** Detected by capability, not version text:
  `method_exists( 'FST_Shipment', 'get_order_boxes' )` (`FisHotel_Shipment_Cost::supported()`).
  Against an older ShipTracker none of the below is rendered and Add Shipment posts exactly what
  it always did.
- **Add Shipment** gets four optional inputs above the button (no `name` attributes, like the
  rest of the box): label cost `#fhsm-new-cost`; box size `#fhsm-new-box` — ShipTracker's standard
  boxes by name with their size (value = the size, e.g. `10x10x10`) plus "Custom…" (value
  `custom`), which reveals `#fhsm-new-dims` for a typed `LxWxH`; weight in lb `#fhsm-new-weight`;
  and "Bought through" `#fhsm-new-source`, ShipTracker's label sources (e.g. Partner A,
  Partner B), left out when that list is empty. They ride along with the existing
  `fst_add_tracking` request as `label_cost`, `box_dims`, `box_weight`, `label_source` — only the
  ones filled in. Both lists are edited under ShipTracker → Settings → Boxes & label costs; with
  no standard boxes saved the size is simply typed.
- **The last source used is remembered per browser** (`localStorage` key
  `fishotelLastLabelSource`) and preselected with a "Same as last time" note. Labels are bought
  wherever the rate is best, so that default never records itself: it is sent only together with
  a cost, size or weight, or when it was picked in that page view.
- **Existing shipments** show `Label cost $53.73 · Small (10x10x10) · 11 lb · Partner A` with an
  Edit link, or `No cost recorded` with an Add link. The inline form saves through ShipTracker's
  `fst_set_label_cost`: every field is sent, so an emptied field is cleared (a form without a
  source dropdown does not send `label_source`, which leaves it alone). A box shared by several
  orders says so — "One box for 2 orders (#201, #202) — this is the cost of the whole box" — and
  on a fulfillment, where the box has one card per source order, the cost is shown on the first
  card only. The cost is always read per box (`FST_Shipment::get_order_boxes()`), never from a
  shipment row: the sibling rows of a shared box are empty.
- **Under the list**, one line for the order on screen: `Shipping paid $45.00 · Label cost
  recorded $53.73 · Difference -$8.73` (red when the labels cost more) and how many boxes have no
  cost yet. Shipping paid is the order's shipping total excluding tax, less any shipping refunded
  — the figure ShipTracker's Analytics report uses; a fulfillment adds up its source orders. A
  box is counted once. A missing cost is never treated as 0: with none recorded there is no
  difference, and while some boxes still lack one it reads "Difference so far"; a recorded
  `0.00` is a cost. When a box is shared with an order that is not on screen, what that order
  paid towards the box is included and named ("includes $25.00 paid on #201, in the same box");
  if that order also went out in other boxes only its even share counts. Unlike the Analytics
  report the line counts a box as soon as it has a tracking number, whatever its status.
- **A value ShipTracker refuses** (an amount that is not 0 or more, a box size that is not three
  sizes or a standard box name, a weight that is not above 0) is reported in ShipTracker's own
  words next to the field; nothing is saved and nothing typed is lost. Enter in a cost field
  never submits the order form around the box: on an existing shipment it saves the cost, in the
  Add Shipment form it does nothing (adding a shipment emails the customer, so that stays a click).
- **No writes in the theme.** Both AJAX actions belong to ShipTracker, which checks the
  `fst_nonce` nonce and `manage_woocommerce`; everything printed is escaped and money goes
  through `wc_price()`, summed in whole cents.

The display maths (which card shows a shared box's cost, the totals line, the split of an
order's shipping over its boxes) and the capability probe are tested by
`php tests/shipment-cost-test.php`; what the form posts (untouched form, remembered source,
cleared vs left alone, which field a ShipTracker message belongs to) by
`node tests/admin-shipments.test.js`.

### Packing, and how an order went out (same files, ShipTracker v1.9.18+)
A box costs more than its label: the box itself, a bag per fish, a heat pack or ice packs, foam,
paper. ShipTracker v1.9.18 keeps the supplies (with a purchase log and stock) and records the
materials of each box; the Shipments box shows and posts them. Still v1.26.0 of the theme.

- **Only when ShipTracker understands it** (`FisHotel_Shipment_Cost::packing_supported()`):
  `class_exists( 'FST_Supplies' )`, `method_exists( 'FST_Supplies', 'get_default_materials' )`
  and `method_exists( 'FST_Order', 'set_ship_mode' )`. Against v1.9.17 the box is exactly the
  label cost box described above; against anything older, the plain box.
- **Add Shipment → "Packing".** Picking a box (`#fhsm-new-box`) opens `.fhsm-packing` under the
  cost inputs, filled with ShipTracker's own defaults for that box and this order
  (`FST_Supplies::get_default_materials()`): one line per supply with a quantity — the box
  itself (when the standard box has a supply), fish bags, heat packs, ice packs — the foam cost
  `#fhsm-new-foam`, the paper cost `#fhsm-new-paper` and a running total. Any other supply is
  added through "Add supply…" (`#fhsm-new-supply`; the supplies kept as foam and as paper are
  left out — they are the two amounts). Each line shows the price each and, for a counted
  supply, what is on hand.
  - A line added through "Add supply…" has its own cost each, in an input
    (`.fhsm-packing-rate`): it starts at the supply's price each — empty when the supply has
    none — and what the input shows is what is recorded. That is where a custom box that was
    paid for gets its price (`4.50`), and a free one its `0`. Left empty it is 0.
  - Fish bags = the fish of the items ticked (all unshipped items while none is ticked); a
    refunded fish is not bagged.
  - A note says when a pack is there because of the weather check: "Heat pack added: the weather
    check says Heat pack." / "Ice pack added (2): the weather check says Cooling packs." The
    action comes from ShipTracker (`FST_Supplies::order_pack_action()`), which reads what the
    FisHotel Misc plugin's Weather section noted on the order; with no weather data there is no
    pack and no note.
  - Changing the box (or the items ticked) re-fills only the lines, foam and paper the owner
    has not touched. "Use the usual for this box" drops everything for the defaults.
  - A custom size starts with foam and paper at 0 and no box line.
  - It is sent with `fst_add_tracking` as `materials` — a JSON string
    `{"lines":[{"supply_id":6,"qty":"1"},…],"foam_cost":"1.80","paper_cost":"0.40"}`; lines at 0
    are left out. An added line also carries its `unit_cost`
    (`{"supply_id":9,"qty":"1","unit_cost":"4.50"}`); every other line is priced by ShipTracker.
    With no box picked nothing is sent, as before. A block with nothing in it and
    nothing touched (no supplies saved yet) is not sent either — a line then points at
    ShipTracker → Settings → Boxes & label costs, where supplies are saved.
  - A tracking number that is already a box with its packing recorded — another order went out
    in it — is asked about first ("This tracking number is already a box on order #100 …"): the
    order joins that box and the packing is not sent, because ShipTracker takes `materials` as
    sent and would replace what the box has on record. This order's bags are then added on the
    shipment card.
- **Existing shipments** show the full cost — `Label $53.73 + materials $6.10 = $59.83 · Small
  (10x10x10) · …` — and what went in: `Packing: 1 × Small box · 6 × Fish bag · foam $1.80`. The
  inline form holds the same block, filled from the record (a recorded line keeps the name,
  the unit and the unit cost it was recorded with; changing the box rewrites nothing by itself)
  and saved with `fst_set_label_cost`. Two things about the unit cost there: a line recorded
  at 0 before its supply had a price shows the price each it now has, because ShipTracker
  gives it that price when the box is saved; and an added line shows the cost each it was
  recorded at in its input, which is sent back as it stands — so a box entered as free stays
  free whatever the supply costs by then. A box with no packing recorded says so; its block
  stays closed behind "Add packing", so saving a label cost never records packing unseen
  (ShipTracker leaves `materials` alone when it is not sent).
- **How it went out** (`.fhsm-ship-mode`, on an order with no shipment): `#fhsm-ship-mode` —
  "Shipped with a label" (the default; nothing is stored), "Local pickup" (with its own packing
  block: a bag per fish, no pack) or "Went in another order's box" (an order number,
  `#fhsm-ship-with`, with Find: the customer and the box or boxes found on that order). Saved
  through ShipTracker's `fst_set_ship_mode`, which stores order meta only — no shipment, no
  e-mail to the customer, no status change. The order then says it plainly ("Local pickup — no
  label, so no label cost", "Went in the box of order #100"), the totals line is its own
  (packing against shipping paid; "counted towards what the box of order #100 cost") and no
  "no cost yet" is counted for it. Picking "Shipped with a label" again clears it; so does
  adding a shipment. A fulfillment order carries no shipping of its own, so there the control
  is left out and a line points at the source orders.
- **The order that carried it** lists who went along ("Also in this order's box, with no label
  of its own: #102") and counts what they paid for shipping towards the box, exactly like an
  order sharing a fan-out box (`FisHotel_Shipment_Cost::rider_ids()` → `summarize()`).
- **Under the list** the cost is the full cost once a box has packing — `Shipping paid $45.00 ·
  Cost $59.83 (label $53.73 + materials $6.10) · Difference -$14.83` — and free shipping is
  named instead of shown as a loss: "Shipped free: this box cost $59.83". A box without a label
  cost still counts as "no cost yet"; its materials are named apart, never added on their own.
- **A value ShipTracker refuses** (a quantity that is not 0 or more with at most 2 decimals, a
  unit cost, a foam or paper amount, an unknown supply, an order that cannot carry another or
  that carries others and so cannot be a local pickup) is reported in
  its own words — next to the packing lines, the foam or the paper field when it is about one.
- **Two requests are the theme's own, both read-only** (nonce `fst_nonce`,
  `manage_woocommerce`): `wp_ajax_fishotel_ship_with_lookup`, the order lookup behind Find, and
  `wp_ajax_fishotel_box_lookup`, "is this tracking number already a box with its packing
  recorded?". Everything that writes is posted to ShipTracker, which checks the same nonce and
  capability.

The helpers behind it (supplies / materials as shown, full cost, the fish of a refunded line,
ship mode, the rules for the order it went with, free shipping, a rider counted like a sharer)
are tested by `php tests/shipment-packing-test.php`; the packing block (what a box fills in,
what a box change keeps, the running total, what is sent, the unit cost of an added line, the
weather note, the ship mode fields, when to ask about a box that is already recorded) by
`node tests/admin-shipments-packing.test.js`.

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
