# Migration Button — Fix Needed

## What's happening
WP Admin → Products → FisHotel Tools → "Run Migration Now" crashes 
with a WordPress critical error on admin-post.php.

## What was already tried
- Removed `require_once get_template_directory() . '/tools/migrate-product-fields.php'`
  from the handler — that was causing the first crash (the CLI script has 
  bare echo/exit that fires on include)

## Most likely remaining cause
The migration loops ALL products with `wc_get_products(['limit' => -1])` 
and does `get_post_meta()` + `update_post_meta()` in a tight loop.
On a shared host with PHP max_execution_time this times out and 
WordPress shows a critical error.

## The Fix — Two options, pick the simpler one:

### Option A: Bump execution time at start of handler (simplest)
```php
public static function handle_migration() {
    check_admin_referer( 'fishotel_run_migration', 'fishotel_migration_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );
    
    // Give migration enough time
    @set_time_limit( 120 );
    @ini_set( 'max_execution_time', 120 );
    
    // ... rest of migration code
}
```

### Option B: AJAX with per-product processing (more robust)
Process one product at a time via AJAX calls from JS, 
show a progress bar in the UI.

**Recommendation: Option A first.** If the site has ~20-50 products 
Option A will work fine. Only need Option B if there are hundreds.

## Also check
Make sure `wc_get_products` is available in the admin-post context.
Add this safety check at the top of handle_migration():
```php
if ( ! function_exists( 'wc_get_products' ) ) {
    wp_die( 'WooCommerce not loaded' );
}
```

## How to test
After fix: WP Admin → Products → FisHotel Tools → Run Migration Now
Should show: "Migration complete: X products updated, Y skipped."
Then check: /product/pajama-cardinalfish/ — species table should 
show all rows populated from custom fields.

