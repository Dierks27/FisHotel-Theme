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
| **DB** | name/user: ahtbszgmef, password: BBAunUW3Qw |
| **phpMyAdmin** | https://woocommerce-1611979-6343482.cloudwaysapps.com:8082/ |
| **PHP Version** | 8.2.30 |
| **Deploy** | GitHub push → Cloudways → Deployment via GIT → Pull |

---

## DONE ✅

### Product Page (single-product.php) — COMPLETE
- QT Certificate panel — green left border, "✓ QUARANTINE COMPLETE / 14 days observation + 14 days treatment"
- Trust strip — "✓ 28-day QT protocol · ✓ Live arrival guarantee · ✓ Ships Mon–Tue"
- Variation buttons (SIZE) — styled, gold on hover/selected
- Species stat grid — two-column table, 6 rows from custom fields
- Fish Dossier — two clean labeled blocks (FOODS & FEEDING + HABITAT & BEHAVIOR)
- Also in Quarantine — related fish cards rendering
- Colored tag chips — Carnivore amber, Peaceful green, Reef Safe teal

### Custom Fields System — COMPLETE
All product data in dedicated meta fields. Zero regex parsing.

WP Admin → edit product → FisHotel — Product Data:
```
── SPECIES INFO ──────────────────────
Scientific Name   [_fh_scientific_name]
Common Names      [_fh_common_names]
Max Length        [_fh_max_length]
Min Tank Size     [_fh_min_tank_size]
Temperament       [_fh_temperament]
Reef Safe         (•) Yes / No / With Caution  [_fh_reef_safe]
Region            [_fh_region]

── CARE GUIDE ────────────────────────
Foods & Feeding   [_fh_foods_feeding]
Habitat & Behav.  [_fh_habitat]

── INTERNAL NOTES (not shown on site) ─
Notes             [_fh_notes]
```

### FisHotel Tools — COMPLETE
WP Admin → Products → FisHotel Tools
- "Run Migration Now" button — AJAX, no page reload
- Parsed all 226 products from existing descriptions → auto-filled custom fields
- Safe to re-run — never overwrites fields already filled in

### CSS Design System — COMPLETE
All in assets/css/woocommerce.css using design tokens:
```
--fh-bg: #252525 / --fh-bg-dk: #1c1c1c / --fh-bg-dkr: #151515
--fh-gold: #c9963a / --fh-text-1: #e8e4dc / --fh-text-2: #a09890
--fh-green: #5aaa78 (QT cert) / --fh-amber: #d4903a / --fh-blue: #4a9db8
Fonts: Montserrat (UI) + Roboto Slab (display)
```

---

## STILL TO DO 🔧

### High Priority
1. **Nav menus** — Left side shows Services/Features/Shop/Contacts (wrong)
   Should be: Home · Our Process · Shop
   Fix: WP Admin → Appearance → Menus — assign correct menu to Primary Left location
   OR hardcode in header.php

2. **Homepage below-fold sections** — Stats bar, stages, transparency, quote, CTA
   all exist in code but need visual review. Screenshot tool can't capture them.
   Jeff needs to scroll through in his browser and report what needs fixing.

3. **Shop/archive page** — needs visual review

### Medium Priority
4. **Mobile responsive** — full polish pass needed
5. **Logo** — current white icon, Jeff said no retro logo — confirm current is correct
6. **Homepage header** — needs more breathing room (was specced at 120px)

### Low Priority
7. **Live site deployment** — same Git setup for fishotel.com when ready
8. **Git Updater** — still crashes on activation, skip for now

---

## Design Tokens
```
--fh-bg:      #252525   --fh-gold:    #c9963a
--fh-bg-dk:   #1c1c1c   --fh-gold-lt: #deb96a
--fh-bg-dkr:  #151515   --fh-text-1:  #e8e4dc
--fh-green:   #5aaa78   --fh-text-2:  #a09890
Fonts: Montserrat (UI) + Roboto Slab (display/prices)
```

## Workflow
- Claude.ai (this chat) — browser testing, screenshots, project managing, spec writing
- Claude Code — all PHP, CSS, JS file edits
- Push to GitHub main → Pull in Cloudways = deploy
- Always hard-refresh browser (Ctrl+Shift+R) when checking changes
