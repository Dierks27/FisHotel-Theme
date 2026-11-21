# Migration Button — Full Handoff to Claude Code

## Current state
Every attempt to run the migration via the admin button crashes
WordPress on admin-post.php with a generic "critical error".

## What's been tried and ruled out
1. ❌ require_once of CLI script — FIXED (removed)
2. ❌ Catastrophic backtracking regex `[\s\S]+?` — FIXED (replaced with line-by-line parser)
3. ✅ Theme loads fine — WP admin dashboard works normally
4. ✅ Code is syntactically correct (reviewed manually)
5. ❌ Root cause of current crash — UNKNOWN, needs debugging on server

## Most likely remaining causes (in order)
1. **Memory limit** — `wc_get_products(['limit' => -1])` loads ALL products as
   full WC objects. If there are 50+ products, this may exceed PHP memory_limit.
   Fix: add `@ini_set('memory_limit', '256M')` at top of handle_migration()
   
2. **Missing WooCommerce context** — admin-post.php may not fully load WC hooks.
   Fix: switch from admin_post_ to wp_ajax_ hook (see below)

3. **Some other PHP fatal** — need to see the actual error

## The Real Fix — Convert to AJAX

Stop using admin-post.php entirely. Use wp_ajax_ instead — it's
more reliable, loads WC properly, and you can catch errors better.

### In hotel-data.php, replace:
```php
add_action( 'admin_post_fishotel_run_migration', [ __CLASS__, 'handle_migration' ] );
```
with:
```php
add_action( 'wp_ajax_fishotel_run_migration', [ __CLASS__, 'handle_migration' ] );
```

### In handle_migration(), replace the redirect with JSON response:
```php
// At top of function:
@ini_set( 'memory_limit', '256M' );
@set_time_limit( 120 );

// At bottom, instead of wp_safe_redirect:
wp_send_json_success( array(
    'message' => "Migration complete: {$migrated} products updated, {$skipped} skipped."
) );
```

### In render_tools_page(), replace the form with a button + JS:
```php
<button id="fh-run-migration" class="button button-primary" style="...">
    Run Migration Now
</button>
<div id="fh-migration-result" style="margin-top:12px;"></div>

<script>
document.getElementById('fh-run-migration').addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Running...';
    
    fetch(ajaxurl, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=fishotel_run_migration&_wpnonce=<?php echo wp_create_nonce("fishotel_run_migration"); ?>'
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('fh-migration-result').innerHTML = 
            '<div class="notice notice-success"><p>' + data.data.message + '</p></div>';
        btn.disabled = false;
        btn.textContent = 'Run Migration Now';
    })
    .catch(err => {
        document.getElementById('fh-migration-result').innerHTML = 
            '<div class="notice notice-error"><p>Error: ' + err + '</p></div>';
        btn.disabled = false;
        btn.textContent = 'Run Migration Now';
    });
});
</script>
```

### Also add to handle_migration() nonce check:
```php
check_ajax_referer( 'fishotel_run_migration' );
```

## After fixing
Test at: WP Admin → Products → FisHotel Tools → Run Migration Now
Expected: Button shows "Running..." then displays success message inline
No page reload, no admin-post.php, no mystery crashes.

