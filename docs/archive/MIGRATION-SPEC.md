# One-Time Migration Script
*Auto-populate new custom fields from existing product descriptions*

## The Problem
All products have structured data in their WooCommerce descriptions:
  Scientific Name: Sphaeramia nematoptera
  Common Names: Pajama Cardinalfish...
  etc.

The new custom fields (_fh_scientific_name, _fh_common_names, etc.) 
are empty because the migration hasn't run. So the species table 
doesn't render on any product page.

## The Fix
Write a WP-CLI command OR a one-time PHP script that:
1. Loops through ALL products
2. Parses the existing description using the same regex patterns
3. Saves each value to the corresponding new meta key
4. Skips any field that already has a value (don't overwrite)

## Script to build — `tools/migrate-product-fields.php`

```php
<?php
/**
 * One-time migration: parse product descriptions into custom fields
 * Run via WP-CLI: wp eval-file tools/migrate-product-fields.php
 * OR visit: /wp-admin/?run_fishotel_migration=1 (add nonce check)
 */

$products = wc_get_products(['limit' => -1, 'status' => 'publish']);
$migrated = 0;
$skipped  = 0;

foreach ($products as $product) {
    $id   = $product->get_id();
    $desc = wp_strip_all_tags($product->get_description());

    // Helper: extract value after a label
    $extract = function($patterns, $text) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return trim($m[1]);
            }
        }
        return '';
    };

    // Map: meta_key => regex patterns to try
    $fields = [
        '_fh_scientific_name' => [
            '/Scientific Name[\s:–-]+([^\n]+)/i',
        ],
        '_fh_common_names' => [
            '/Common Names?[\s:–-]+([^\n]+)/i',
        ],
        '_fh_max_length' => [
            '/Maximum? Lengths?[\s:–-]+([^\n]+)/i',
            '/Max\.? Lengths?[\s:–-]+([^\n]+)/i',
        ],
        '_fh_min_tank_size' => [
            '/Minimum Aquarium Sizes?[\s:–-]+([^\n]+)/i',
            '/Min\.? Tank Sizes?[\s:–-]+([^\n]+)/i',
        ],
        '_fh_temperament' => [
            '/Temperament[\s:–-]+([^\n]+)/i',
        ],
        '_fh_foods_feeding' => [
            '/Foods? and Feeding Habits?[\s:–-]+([\s\S]+?)(?=\n[A-Z]|Reef Safety|Temperament|Description|$)/i',
            '/Foods? and Feeding[\s:–-]+([\s\S]+?)(?=\n[A-Z]|Reef Safety|Temperament|Description|$)/i',
            '/Feeding[\s:–-]+([\s\S]+?)(?=\n[A-Z]|Reef Safety|Temperament|Description|$)/i',
        ],
        '_fh_habitat' => [
            '/Habitat and Behavior[\s:–-]+([\s\S]+?)(?=\n[A-Z]|Foods?|Reef|$)/i',
            '/Habitat[\s:–-]+([\s\S]+?)(?=\n[A-Z]|Foods?|Reef|$)/i',
        ],
    ];

    // Reef safe — special case (look for Yes/No/With Caution)
    $reef_safe = '';
    if (preg_match('/Reef.?Safe[\s:–-]+([^\n]+)/i', $desc, $m)) {
        $val = strtolower(trim($m[1]));
        if (strpos($val, 'caution') !== false) $reef_safe = 'caution';
        elseif (strpos($val, 'no') !== false)  $reef_safe = 'no';
        else                                    $reef_safe = 'yes';
    }

    $updated = false;

    foreach ($fields as $key => $patterns) {
        // Don't overwrite existing values
        if (get_post_meta($id, $key, true)) continue;
        
        $value = $extract($patterns, $desc);
        if ($value) {
            update_post_meta($id, $key, sanitize_textarea_field($value));
            $updated = true;
        }
    }

    if ($reef_safe && !get_post_meta($id, '_fh_reef_safe', true)) {
        update_post_meta($id, '_fh_reef_safe', $reef_safe);
        $updated = true;
    }

    if ($updated) $migrated++;
    else $skipped++;
}

echo "Migration complete: {$migrated} products updated, {$skipped} skipped.\n";
```

## How to Run
WP-CLI (preferred):
```bash
wp eval-file tools/migrate-product-fields.php
```

## After Running
- All existing product data auto-fills the new custom fields
- Product pages show the styled species table immediately  
- Jeff can then edit individual fields in WP Admin if needed
- Run once and delete the script

