# Claude Code Kickoff Prompt — FisHotel Theme

---

Paste this into Claude Code to get started:

---

You are taking over coding on the **FisHotel custom WordPress/WooCommerce theme**.

## Your Role
You handle ALL code — PHP, CSS, JS, file edits. Claude.ai handles browser testing, deployment, and project management. Never make Jeff manually edit files.

## The Repo
- **GitHub:** https://github.com/Dierks27/FisHotel-Theme (private, branch: main)
- Clone it, work in it, push to main
- **Deploy:** After pushing, someone clicks Pull in Cloudways (or we'll automate this)

## Staging
- **URL:** https://woocommerce-1611979-6343482.cloudwaysapps.com
- **WP Admin:** jeff@fishotel.com / DwKAx9E34e  
- **htpasswd:** fstrvwvmjq / ZkVkBVV2Ts
- **PHP:** 8.2.30

## The Stack
Custom WordPress theme. No page builders. No Elementor. Pure PHP templates + vanilla CSS/JS. WooCommerce variable products with custom Hotel Data meta boxes.

## Design System
```
--fh-bg: #252525 | --fh-gold: #c9963a | --fh-text-1: #e8e4dc
Fonts: Montserrat (UI) + Roboto Slab (display)
```

## What Works Right Now
- Homepage (hero, nav, bubbles)
- Single product page (photo, SIZE buttons, price, Fish Dossier)
- WP Admin

## What Needs Fixing First
1. **Git Updater plugin crashes on activation** — it's installed at `/wp-content/plugins/git-updater/`. Debug why it crashes on PHP 8.2, fix it. This gives us WordPress dashboard auto-updates from GitHub.
2. **Shop/category pages** — test https://woocommerce-1611979-6343482.cloudwaysapps.com/shop/ — if still erroring, check PHP error log and fix
3. **Nav menus** — Left side should show: Home, Our Process, Shop. Right side: FAQ's, About Us, Cart. Currently wrong items showing.

## File Structure
```
fishotel-theme/
├── style.css           (theme declaration + GitHub Updater headers)
├── functions.php
├── front-page.php      (homepage)
├── archive-product.php (shop grid)
├── header.php / footer.php
├── inc/
│   ├── hotel-data.php      (quarantine meta box)
│   ├── hotel-journal.php   (journal entries)
│   ├── variation-display.php
│   └── woocommerce.php
├── woocommerce/
│   ├── single-product.php
│   ├── cart/cart.php
│   └── checkout/form-checkout.php
└── assets/
    ├── css/main.css
    ├── css/home.css
    ├── css/woocommerce.css
    └── js/main.js
```

## Important Notes
- PHP 8.2 — no compat workarounds needed, use modern syntax freely
- All `match()`, `fn()`, `str_contains()` are fine
- TRX Addons and Slider Revolution are GONE — do not reference them
- Theme is active on staging, WooCommerce is active
- Push to GitHub main branch to deploy

Start by cloning the repo and auditing what's there. Then fix Git Updater first.
