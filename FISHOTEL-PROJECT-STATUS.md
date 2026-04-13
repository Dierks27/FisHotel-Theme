# FisHotel Theme — Project Status
*Last updated: April 2026*

---

## Infrastructure

| Thing | Detail |
|-------|--------|
| **GitHub Repo** | https://github.com/Dierks27/FisHotel-Theme (private, branch: main) |
| **Staging URL** | https://woocommerce-1611979-6343482.cloudwaysapps.com |
| **Live URL** | https://fishotel.com (not yet deployed) |
| **WP Admin** | jeff@fishotel.com / DwKAx9E34e |
| **htpasswd** | fstrvwvmjq / ZkVkBVV2Ts |
| **Deploy** | GitHub push → Cloudways → Deployment via GIT → Pull |
| **Settings page URL** | /wp-admin/edit.php?post_type=product&page=fishotel-settings |
| **Tools page URL** | /wp-admin/edit.php?post_type=product&page=fishotel-tools |

---

## COMPLETED ✅

### Product Page (single-product.php)
- QT Certificate panel — green left border, QUARANTINE COMPLETE
- Trust strip — 28-day QT · Live arrival guarantee · Ships Mon–Tue
- Variation buttons (SIZE) — styled, gold on hover/selected
- Species stat grid — two-column table from custom fields
- Fish Dossier — FOODS & FEEDING + HABITAT & BEHAVIOR blocks
- "ABOUT THIS FISH" gold eyebrow label + prose paragraph
- Fun Facts stripped from output (WC description field left intact for variable products)
- Breadcrumb — Home / Category (clickable) / Product Name
- Related fish cards — 4 cards rendering

### Custom Fields System (inc/hotel-data.php)
Zero regex. All product data in dedicated meta fields.
WP Admin → Products → edit product → FisHotel — Product Data:

**Species Info:**
- Scientific Name (_fh_scientific_name)
- Common Names (_fh_common_names)
- Max Length (_fh_max_length)
- Min Tank Size (_fh_min_tank_size)
- Temperament (_fh_temperament)
- Reef Safe — radio: yes/no/caution (_fh_reef_safe)
- Region (_fh_region)

**Care Guide:**
- Foods & Feeding (_fh_foods_feeding)
- Habitat & Behavior (_fh_habitat)

**Internal:**
- Notes — not shown on site (_fh_notes)

### FisHotel Tools (Products → FisHotel Tools)
- "Run Migration Now" button — AJAX, no page reload
- Ran successfully: 226 products updated, 892 skipped
- Safe to re-run — never overwrites existing values

### FisHotel Settings (Products → FisHotel Settings)
Everything admin-controlled, nothing hardcoded in theme:
- **Shop Page**: display mode (Categories/Products/Both), hide empty categories toggle, hidden categories 3-column multicheck (Invoices currently hidden)
- **QT Certificate**: Protocol line 1 & 2
- **Trust Strip**: 3 editable trust items
- **Branding**: Logo tagline
- **Care Guide Defaults**: Default Foods & Feeding + Habitat textarea

### Shop Page (/shop/)
- Shows 3 category tiles: Gift Cards, Merchandise, Quarantined Fish
- Images at 80% size with breathing room — no cropping
- Admin-controlled: hide empty, hide specific categories
- Footer gap tightened

### Category Page (/product-category/quarantined-fish/)
- 19 fish, 4-column grid
- "Coming Soon" placeholder cards for upcoming fish
- Fish name, latin name, price visible

### Newsletter Page (/newsletter/)
- Template: page-newsletter.php (assign in Page Attributes)
- Left: FisHotel Gazette AI illustration (tilted with drop shadow)
- Right: Vintage newspaper ad box — double gold border, masthead,
  "BE FIRST TO KNOW" headline, diamond bullet benefits, dark form
- Newsletter plugin form: First Name + Last Name + Email + Subscribe
- Hero matches site standard (page-hero CSS)
- Footer: Newsletter link added to right nav

### Navigation
- Left: HOME · OUR PROCESS · SHOP
- Right: NEWSLETTER · CONTACTS · FAQ'S · ABOUT US
- Breadcrumbs clickable on all pages

### Homepage (front-page.php)
All sections built and rendering:
- Hero — "WHERE FISH CHECK OUT HEALTHY" with animated particles
- Stats bar — 30+ days · 100% transparency · 3 importers · ★★★★★
- Available Now — live fish grid with "VIEW ALL FISH" link
- "Your fish deserve a proper welcome" — FisHotel sign illustration + copy
- The Quarantine Journey — 6 stage cards (Check-In → Observation → Treatment → Souvenir Shop → Flying Home → Ready For Your Reef)
- Transparency section
- Customer quote
- CTA — "READY TO CHECK YOUR FISH IN?"

### Design System
```
--fh-bg: #252525     --fh-bg-dk: #1c1c1c     --fh-bg-dkr: #151515
--fh-gold: #c9963a   --fh-gold-lt: #deb96a
--fh-text-1: #e8e4dc --fh-text-2: #a09890
--fh-green: #5aaa78  (QT cert accent)
Fonts: Montserrat (UI) + Roboto Slab (display)
```

---

## REMAINING WORK 🔧

### Final Polish Pass (before go-live)
1. **Spacing consistency** — review all pages for alignment and padding consistency
2. **Category tile alignment** — with only 3 tiles, center them rather than left-align
3. **Any homepage section tweaks** Jeff spotted while reviewing
4. **Mobile responsive** — full polish pass needed across all pages

### Pre-Launch
5. **Live deployment** — point GitHub deploy to fishotel.com
6. **Newsletter plugin** — confirm First Name/Last Name fields showing for logged-out users
7. **Test checkout flow** end to end
8. **DNS / SSL** — confirm when going live

---

## Workflow Rules
- Claude.ai — browser testing, screenshots, specs, project management
- Claude Code — all PHP/CSS/JS file edits
- Always use ?v=something when checking changes to bust cache
- Settings page: /wp-admin/edit.php?post_type=product&page=fishotel-settings
  (NOT admin.php?page=... — that URL crashes)
