# EA REST API — wholesale gate status

**Status (May 2026):** EA's WC REST API is wholesale-role-gated for Jeff's consumer key. All medication-store code paths that relied on a live fetch from EA have been retired in Phase 1.6.

This file is the rationale for that decision. Three places in the codebase point here:
- `inc/medication-store/ea-rest-client.php` (class docblock)
- `inc/medication-store/cli/class-importer-cli.php` (class docblock + `create_ea_product` note)
- `inc/medication-store/product-meta.php` (the help-text replacing the per-product "Sync stock from EA" button)

## What we tested

Direct `curl` against EA's WC REST API using the stored consumer key + secret, Basic-Auth header. Tested across 10 endpoints:

```
GET /wp-json/                                  → 200 (API root, full endpoint catalog)
GET /wp-json/wc/v3/products                    → 403  woocommerce_rest_cannot_view
GET /wp-json/wc/v3/products?slug=chloroquine   → 403  woocommerce_rest_cannot_view
GET /wp-json/wc/v3/products/categories         → 403  woocommerce_rest_cannot_view
GET /wp-json/wc/v3/products/tags               → 403  woocommerce_rest_cannot_view
GET /wp-json/wc/v3/products/attributes         → 403  woocommerce_rest_cannot_view
GET /wp-json/wc/v3/products/reviews            → 403  woocommerce_rest_cannot_view
GET /wp-json/wc/v3/shipping/zones              → 403  woocommerce_rest_cannot_view
GET /wp-json/wc/v3/coupons                     → 403  woocommerce_rest_cannot_view
GET /wp-json/wc/v3/products/{any-id}           → 404 (not 403 — single-resource fetch works
                                                       if we knew real IDs, but they're not
                                                       discoverable from any public surface)
```

The auth handshake itself is clean — 200 on `/wp-json/` proves the consumer key + secret are valid. The 403s are deliberate role-gating, not credential issues.

## Why we can't fix it from this side

EA's WC REST capability mapping is role-based: list endpoints require a customer with `manage_woocommerce` (or an equivalent wholesale-shop role). Jeff's consumer key was issued against a user that does NOT have that capability. Lifting the gate would require Dena (EA's admin) to escalate Jeff's user account, which Jeff has declined to ask for.

Product IDs aren't recoverable from the public storefront either:
- `https://everythingaquatic.net/product/{slug}/` 302-redirects (and the redirect target doesn't expose the ID in a stable HTML attribute we could scrape).
- `/product-sitemap.xml` 301-redirects.

So single-resource fetch (`/products/{id}`) is theoretically allowed but practically unusable.

## Architectural consequences

- **Importer (Phase 1.5):** the live-fetch path (`fetch_by_slug` → `fetch_variations` → wholesale extraction from `meta_data`) is removed from the `wp fishotel-meds import` flow. The range-based fallback (linear distribution of `wholesale_range` / `retail_range` from Phase 1 spec §6 across three Small / Medium / Large placeholder variations) is the steady-state architecture, not a fallback.
- **Per-product "Sync stock from EA" button:** hidden behind a `FISHOTEL_MED_EA_API_ENABLED` constant. The button JS + AJAX endpoint + REST client method all remain in the codebase, dormant. If the gate ever lifts, define the constant in `wp-config.php` and the button reappears.
- **Settings page:** the EA Consumer Key + Secret fields stay registered (no schema migration). Description text under each input documents the gate.
- **EA REST client class (`FisHotel_Med_EA_REST`):** `do_request()`, `auth_header()`, `base_url()` are still useful if the gate lifts. `fetch_by_slug()`, `fetch_variations()`, `fetch_product()`, and `extract_wholesale()` are kept as dormant code per the same reasoning. Class-level docblock points here.

## What changes if EA opens the gate

1. Define `FISHOTEL_MED_EA_API_ENABLED` (any truthy value) in `wp-config.php`.
2. The "Sync stock from EA" button re-appears on EA-mode products.
3. If the importer should also re-enable live fetches, re-add the EA fetch + variation walk inside `FisHotel_Med_Importer_CLI::create_ea_product()` — the helpers (`placeholder_variations`, `interpolate_range`, `set_size_attribute`, `create_variation`) all still apply, just point them at EA's live variation array instead of the spec range.

## Filing history

- Phase 1.6 addendum (2026-05) — gate confirmed, paths retired.
- See PR introducing this file for the empirical test transcript + commit-level changes.
