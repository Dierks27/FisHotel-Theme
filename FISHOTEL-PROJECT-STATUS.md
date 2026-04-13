# FisHotel Theme — Project Status
*Last updated: April 2026*

---

## Infrastructure

| Thing | Detail |
|-------|--------|
| **GitHub Repo** | https://github.com/Dierks27/FisHotel-Theme (private, branch: main) |
| **Staging URL** | https://woocommerce-1611979-6343482.cloudwaysapps.com |
| **WP Admin** | jeff@fishotel.com / DwKAx9E34e |
| **htpasswd** | fstrvwvmjq / ZkVkBVV2Ts |
| **Deploy** | GitHub push → Cloudways → Deployment via GIT → Pull |
| **Settings page URL** | /wp-admin/edit.php?post_type=product&page=fishotel-settings |

---

## DONE ✅

### Product Page — COMPLETE
- QT Certificate, trust strip, variation buttons, species table, dossier blocks
- All data from custom fields — zero regex
- "ABOUT THIS FISH" prose section, Fun Facts stripped
- Breadcrumb: Home / Category (clickable) / Product

### Custom Fields — COMPLETE
WP Admin → Products → edit product → FisHotel — Product Data
Scientific Name, Common Names, Max Length, Min Tank Size, Temperament, Reef Safe, Region, Foods & Feeding, Habitat & Behavior, Notes

### FisHotel Tools — COMPLETE
WP Admin → Products → FisHotel Tools
- "Run Migration Now" AJAX button — ran on 226 products

### FisHotel Settings — COMPLETE
WP Admin → Products → FisHotel Settings
- Shop display (Categories/Products/Both)
- Hide empty categories toggle
- Hidden categories — 3-column multicheck (Invoices hidden)
- QT Certificate lines 1 & 2
- Trust strip items 1, 2, 3
- Logo tagline
- Care guide defaults

### Shop Page — COMPLETE
- /shop/ → category tiles (Gift Cards, Merchandise, Quarantined Fish)
- Category images at 80% size, breathing room
- Admin-controlled: hide empty, hide specific categories
- Footer sits correctly with min-height

### Newsletter Page — COMPLETE
- URL: /newsletter/
- Template: page-newsletter.php (select in Page Attributes)
- Left: FisHotel Gazette AI image (tilted, drop shadow)
- Right: Vintage newspaper ad box — double gold border, masthead,
  "BE FIRST TO KNOW" headline, diamond bullet benefits, dark form
- Newsletter plugin form: First Name + Last Name + Email + Subscribe
- Hero matches site standard (page-hero CSS)
- Nav: NEWSLETTER link in right nav

### Nav — UPDATED
- Left: HOME · OUR PROCESS · SHOP
- Right: NEWSLETTER · CONTACTS · FAQ'S · ABOUT US

---

## STILL TO DO 🔧

### High Priority
1. **Homepage below-fold** — Stats bar, process stages, transparency, quote, CTA
   needs visual review. Screenshot tool can't capture scrolled content.

### Medium Priority
2. **Mobile responsive** — full polish pass needed across all pages
3. **Category page** (/product-category/quarantined-fish/) — visual review

### Low Priority
4. **Live site deployment** — fishotel.com when staging is ready

---

## Design Tokens
```
--fh-bg: #252525   --fh-bg-dk: #1c1c1c   --fh-bg-dkr: #151515
--fh-gold: #c9963a  --fh-text-1: #e8e4dc  --fh-text-2: #a09890
--fh-green: #5aaa78
Fonts: Montserrat (UI) + Roboto Slab (display)
```

## Key Rules
- Claude.ai — browser testing, specs, project management
- Claude Code — all PHP/CSS/JS file edits
- Always ?v=something to bust cache when checking changes
- Settings page: /wp-admin/edit.php?post_type=product&page=fishotel-settings
  (NOT admin.php?page=fishotel-settings — that crashes)
