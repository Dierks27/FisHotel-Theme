# FisHotel Theme — Project Status & Roadmap
*Last updated: April 2026*

---

## What We're Building

A full custom WordPress + WooCommerce theme for **fishotel.com** — replacing the old bloated Nelson/TRX Addons/Elementor/RevSlider stack with a clean, fast, purpose-built theme.

**Brand:** Dark charcoal `#252525` base, retro roadside hotel sign identity, Montserrat + Roboto Slab fonts, gold `#c9963a` accent.

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
| **Deploy method** | GitHub push → Cloudways Deployment via GIT → Pull button |

---

## What Is DONE ✅

### Theme Files Built
- `style.css` — Theme declaration with GitHub Updater headers
- `functions.php` — WooCommerce support, image sizes, asset enqueueing
- `index.php`, `header.php`, `footer.php`, `page.php`, `404.php`, `search.php`
- `front-page.php` — Full homepage: hero + bubbles, stats bar, fish grid, stages, transparency section, quote, CTA
- `archive-product.php` — Shop grid with filter bar

### WooCommerce Templates
- `woocommerce/single-product.php` — Full product page (CONFIRMED WORKING)
- `woocommerce/cart/cart.php`
- `woocommerce/checkout/form-checkout.php`
- `woocommerce/loop/` — loop-start, loop-end, no-products-found, add-to-cart

### Inc Modules
- `inc/hotel-data.php` — Quarantine meta box (arrival date, QT stage, health grade, treatments)
- `inc/hotel-journal.php` — Repeatable journal entries sorted newest-first
- `inc/variation-display.php` — Visual variation buttons replacing WC dropdowns
- `inc/woocommerce.php`, `inc/template-functions.php`, `inc/customizer.php`

### Assets
- `assets/css/main.css` — Complete responsive design system
- `assets/css/home.css` — Homepage-specific styles
- `assets/css/woocommerce.css` — WooCommerce overrides
- `assets/js/main.js` — Variation buttons, gallery switcher, mobile nav, qty buttons

### Infrastructure Done
- ✅ Git deployment pipeline (GitHub → Cloudways Pull)
- ✅ Varnish cache disabled on staging (dev mode)
- ✅ PHP 8.2 confirmed
- ✅ TRX Addons / TRX Updater / Slider Revolution removed from staging
- ✅ Single product page confirmed rendering perfectly
- ✅ Homepage hero, nav, logo, bubbles all working

---

## What Is NOT Done / Needs Work 🔧

### Priority 1 — Broken
- **Shop / category pages** — PHP errors, needs testing after latest fixes
- **WP Admin stability** — trx_addons keeps coming back; needs permanent cleanup
- **Theme auto-updates** — Git Updater plugin crashes on activation (cause unknown, needs Claude Code to debug)

### Priority 2 — Polish
- **Nav menus** — Left: Home, Our Process, Shop | Right: FAQ's, About Us, Cart
  - Currently showing wrong items (old WP menu assigned)
- **Logo** — White background visible on retro sign GIF; need transparent PNG
- **Homepage below-fold sections** — Stats bar, stages, transparency, quote, CTA all exist in code but need visual review
- **Mobile responsive** — Polish pass needed

### Priority 3 — Feature Complete
- **Hotel Data meta fields** — Add arrival date, QT stage, health grade to real products
- **Shop filter bar** — Filter by fish type, size, temperament
- **Checkout flow** — Full test with real order
- **Cart page** — Visual review

---

## Design Tokens (Reference)
```
--fh-bg:       #252525   (base grey)
--fh-bg-dk:    #1c1c1c
--fh-bg-dkr:   #151515
--fh-gold:     #c9963a
--fh-gold-lt:  #deb96a
--fh-text-1:   #e8e4dc   (warm white)
--fh-text-2:   #a09890   (body)

Fonts: Montserrat (UI) + Roboto Slab (display/prices)
```

## Brand Assets
- Logo GIF: `https://fishotel.com/wp-content/uploads/2021/12/FisHotel-Retro-Hotel-Sign.gif`
- Building icon: `https://fishotel.com/wp-content/uploads/2020/06/Small-Fish-Hotel-White.png`
- Gold plane: `https://fishotel.com/wp-content/uploads/2026/03/fishotel-plane.png`

---

## Workflow Going Forward

1. **Claude Code** handles all file edits, PHP, CSS, JS
2. **Claude.ai (this chat)** handles browser automation — deploying, testing, reviewing screenshots, project managing
3. **Push to GitHub → Pull in Cloudways** = deploy (until auto-update is sorted)
4. **Never edit files in claude.ai chat** — all code goes through Claude Code

---

## Immediate Next Steps (for Claude Code)

1. Debug why Git Updater crashes on activation (check PHP error log)
2. Fix shop/archive pages — confirm they load after PHP compat fixes
3. Set up correct nav menus in WP
4. Homepage below-fold visual review and polish
5. Mobile responsive pass
