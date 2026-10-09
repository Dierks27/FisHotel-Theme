<?php
/**
 * FisHotel template functions
 * Helper functions available to all templates
 *
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;

/**
 * Output the page hero banner with breadcrumb + title + tags
 */
function fishotel_page_hero( $args = [] ) {
    $defaults = [
        'title'      => get_the_title(),
        'latin'      => '',
        'tags'       => [],
        'breadcrumb' => true,
    ];
    $args = wp_parse_args( $args, $defaults );
    get_template_part( 'template-parts/header/page-hero', null, $args );
}

/**
 * Output fish card for shop loop
 */
function fishotel_fish_card( $product_id = null ) {
    if ( ! $product_id ) $product_id = get_the_ID();
    get_template_part( 'template-parts/product/fish-card', null, [ 'product_id' => $product_id ] );
}

/**
 * Get FisHotel hotel data for a product
 * Wrapper around FisHotel_Hotel_Data::get_all()
 */
function fishotel_get_hotel_data( $product_id = null ) {
    if ( ! $product_id ) $product_id = get_the_ID();
    if ( class_exists( 'FisHotel_Hotel_Data' ) ) {
        return FisHotel_Hotel_Data::get_all( $product_id );
    }
    return [];
}

/**
 * URL of the Quarantined Fish category archive. The homepage / empty-cart
 * "View Available Fish" CTAs used to send customers to the WC shop page,
 * which (with "Shop page display: Categories") is the 3-tile landing page
 * — one extra click away from the fish grid. Falls back to the shop
 * permalink when the term is missing so the buttons always work. The
 * header "Shop" nav is intentionally left on the shop page.
 *
 * @return string
 */
function fishotel_quarantined_fish_url() {
    $link = get_term_link( 'quarantined-fish', 'product_cat' );
    if ( is_string( $link ) && $link !== '' && ! is_wp_error( $link ) ) {
        return $link;
    }
    return (string) wc_get_page_permalink( 'shop' );
}

/**
 * Resolve the public URL of one of the theme's tool pages (Medication
 * Dosing, QT Scheduler, Bleach Calculator, Build-a-Tank) by its page
 * template, with a slug fallback. Resolving by `_wp_page_template` first
 * matters because the live slugs don't match the template names (the
 * dosing calculator lives at /medication-dosing/, not /quarantine-help/)
 * and Jeff can rename a page without breaking the links.
 *
 * All four templates are fetched in ONE query the first time any of them
 * is requested (per-request static), so the footer + PDP row cost a
 * single cheap lookup per request. Deliberately NOT persisted in the
 * object cache: with Redis/Memcached a renamed, unpublished or
 * re-templated tool page kept its stale footer/PDP link for up to an hour
 * and there is no save_post invalidation worth maintaining for one query.
 * Only published pages resolve; returns '' when nothing matches so callers
 * can skip the link entirely.
 *
 * @param string $template      Template file name, e.g. 'page-qt-scheduler.php'.
 * @param string $fallback_slug Page slug to try when no page uses the template.
 * @return string Permalink or '' when the page doesn't exist / isn't published.
 */
function fishotel_tool_page_url( $template, $fallback_slug = '' ) {
    static $map = null;

    $known = [
        'page-quarantine-help.php',
        'page-qt-scheduler.php',
        'page-bleach-calculator.php',
        'page-compatibility-guide.php',
    ];

    if ( $map === null ) {
        $map = [];
        $ids = get_posts( [
            'post_type'              => 'page',
            'post_status'            => 'publish',
            'posts_per_page'         => 20,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => [
                [
                    'key'     => '_wp_page_template',
                    'value'   => $known,
                    'compare' => 'IN',
                ],
            ],
        ] );
        foreach ( (array) $ids as $id ) {
            $tpl = (string) get_post_meta( $id, '_wp_page_template', true );
            // First (lowest ID) page per template wins — mirrors the
            // bootstraps, which force the template onto a fixed page.
            if ( $tpl !== '' && ! isset( $map[ $tpl ] ) ) {
                $map[ $tpl ] = (int) $id;
            }
        }
    }

    $template = (string) $template;
    if ( $template !== '' && ! empty( $map[ $template ] ) ) {
        $url = get_permalink( $map[ $template ] );
        if ( $url ) {
            return $url;
        }
    }

    $fallback_slug = (string) $fallback_slug;
    if ( $fallback_slug !== '' ) {
        $page = get_page_by_path( $fallback_slug, OBJECT, 'page' );
        if ( $page && $page->post_status === 'publish' ) {
            $url = get_permalink( $page );
            if ( $url ) {
                return $url;
            }
        }
    }

    return '';
}

/**
 * Resolve a `fh-tag--<modifier>` class from a tag slug. Empty string when
 * no rule matches (falls through to the muted gray default). Substring
 * matching mirrors the legacy fish-only resolver so existing slugs like
 * `aggressive-with-reef-fish` keep colorizing correctly. The med/food
 * additions are exact-slug for precision (Phase 3.6).
 *
 * @param string $slug product_tag slug, e.g. `reef-safe` or `dewormer`.
 * @return string `fh-tag--reef-safe` etc., or ''.
 */
function fishotel_tag_modifier_class( $slug ) {
    $slug = (string) $slug;
    if ( $slug === '' ) {
        return '';
    }

    // Substring matches — fish tag groups (preserves legacy behavior).
    $substring_map = [
        'reef-safe'  => 'fh-tag--reef-safe',
        'peaceful'   => 'fh-tag--peaceful',
        'carnivore'  => 'fh-tag--carnivore',
        'aggressive' => 'fh-tag--aggressive',
    ];
    foreach ( $substring_map as $needle => $class ) {
        if ( strpos( $slug, $needle ) !== false ) {
            return $class;
        }
    }

    // Exact-slug matches — medication + food tag groups. Each modifier
    // class also has a matching CSS rule in main.css; the modifier name
    // just needs to be unique per group, not per slug.
    $exact_map = [
        // Treatment type
        'dewormer'           => 'fh-tag--dewormer',
        'antibiotic'         => 'fh-tag--antibiotic',
        'antibacterial'      => 'fh-tag--antibacterial',
        'antiparasitic'      => 'fh-tag--antiparasitic',
        'external-parasites' => 'fh-tag--external-parasites',
        'antifungal'         => 'fh-tag--antifungal',
        'broad-spectrum'     => 'fh-tag--broad-spectrum',
        'multi-purpose'      => 'fh-tag--multi-purpose',
        // Pathogens / symptoms (muted coral)
        'ich'                  => 'fh-tag--ich',
        'velvet'               => 'fh-tag--velvet',
        'brook'                => 'fh-tag--brook',
        'brooklynella'         => 'fh-tag--brooklynella',
        'cryptocaryon'         => 'fh-tag--cryptocaryon',
        'amyloodinium'         => 'fh-tag--amyloodinium',
        'uronema'              => 'fh-tag--uronema',
        'bacterial-infection'  => 'fh-tag--bacterial-infection',
        'fin-rot'              => 'fh-tag--fin-rot',
        'fungus'               => 'fh-tag--fungus',
        'fungal-infection'     => 'fh-tag--fungal-infection',
        'saprolegnia'          => 'fh-tag--saprolegnia',
        'cestodes'             => 'fh-tag--cestodes',
        'monogeneans'          => 'fh-tag--monogeneans',
        'digeneans'            => 'fh-tag--digeneans',
        'flukes'               => 'fh-tag--flukes',
        'nematodes'            => 'fh-tag--nematodes',
        'worms'                => 'fh-tag--worms',
        'internal-worms'       => 'fh-tag--internal-worms',
        'internal-parasites'   => 'fh-tag--internal-parasites',
        'hex'                  => 'fh-tag--hex',
        'hexamita'             => 'fh-tag--hexamita',
        'spironucleus'         => 'fh-tag--spironucleus',
        'hydroids'             => 'fh-tag--hydroids',
        'camallanus'           => 'fh-tag--camallanus',
        'cnidaria'             => 'fh-tag--cnidaria',
        'bloat'                => 'fh-tag--bloat',
        // Drug-class (muted teal)
        'gram-positive'  => 'fh-tag--gram-positive',
        'gram-negative'  => 'fh-tag--gram-negative',
        'phenothiazine'  => 'fh-tag--phenothiazine',
        'benzimidazole'  => 'fh-tag--benzimidazole',
        'imidazothiazole'=> 'fh-tag--imidazothiazole',
        'aminoglycoside' => 'fh-tag--aminoglycoside',
        'nitrofuran'     => 'fh-tag--nitrofuran',
        'quinoline'      => 'fh-tag--quinoline',
        'formalin'       => 'fh-tag--formalin',
        'metronidazole'  => 'fh-tag--metronidazole',
        'anthelmintic'   => 'fh-tag--anthelmintic',
        'azole'          => 'fh-tag--azole',
        // Food benefit (green)
        'high-protein'  => 'fh-tag--high-protein',
        'premium-food'  => 'fh-tag--premium-food',
        'conditioning'  => 'fh-tag--conditioning',
        'daily-staple'  => 'fh-tag--daily-staple',
        'reef-food'     => 'fh-tag--reef-food',
        // Food use-case (blue)
        'lps-food'      => 'fh-tag--lps-food',
        'sps-food'      => 'fh-tag--sps-food',
        'planktivores'  => 'fh-tag--planktivores',
    ];
    if ( isset( $exact_map[ $slug ] ) ) {
        return $exact_map[ $slug ];
    }

    return '';
}

/**
 * product_cat slugs of a product, memoised per product id for the request.
 *
 * Backs the category predicates below (quarantined fish / medication /
 * food), which are asked several times per product on the PDP, the cart
 * and every checkout AJAX refresh. get_the_terms() reads the object-term
 * cache WP_Query primes for the loop (and fills it once otherwise), so a
 * product costs at most one term query per request instead of one per
 * predicate call via wp_get_post_terms().
 *
 * @param int $product_id Product post ID.
 * @return string[] Slugs, [] when none / unknown product.
 */
function fishotel_product_cat_slugs( $product_id ) {
    static $memo = [];
    $product_id = (int) $product_id;
    if ( ! $product_id ) {
        return [];
    }
    if ( ! isset( $memo[ $product_id ] ) ) {
        $terms = get_the_terms( $product_id, 'product_cat' );
        $memo[ $product_id ] = ( is_array( $terms ) && ! is_wp_error( $terms ) )
            ? array_values( wp_list_pluck( $terms, 'slug' ) )
            : [];
    }
    return $memo[ $product_id ];
}

/**
 * True when the product is in the `quarantined-fish` category — the
 * single allowlist gate for fish-specific PDP UI (QT badge, trust
 * strip, "About This Species" data table, "About This Fish" prose
 * heading). Everything outside that category — medications, freeze-
 * dried foods, gift cards, future categories Jeff hasn't created yet
 * — is treated as non-fish.
 *
 * Allowlist instead of blocklist on purpose: new product categories
 * shouldn't have to be added to a hide-list to stop leaking QT copy.
 *
 * @param int|null $product_id Defaults to the current loop ID.
 * @return bool
 */
function fishotel_is_quarantined_fish( $product_id = null ) {
    $product_id = $product_id ? (int) $product_id : (int) get_the_ID();
    if ( ! $product_id ) {
        return false;
    }
    return in_array( 'quarantined-fish', fishotel_product_cat_slugs( $product_id ), true );
}

/**
 * Overnight shipping-class slugs, lowercased. Reads the same option the
 * reorder piggyback reads (fishotel_overnight_shipping_classes, default
 * `premium`) with the same comma/space/newline parsing and the same
 * never-empty fallback, so the two features agree on what "overnight" is.
 *
 * WHY a second reader: FisHotel_Checkout_Reuse::overnight_classes() is
 * private to that class, and this list is also needed by template-level
 * helpers (fishotel_cart_has_livestock). Keep the parsing in sync with it —
 * or point the reuse class at this function.
 *
 * @return string[]
 */
function fishotel_overnight_shipping_classes() {
    $raw  = get_option( 'fishotel_overnight_shipping_classes', 'premium' );
    $list = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
    $list = array_filter( array_map( static function ( $slug ) {
        return strtolower( trim( (string) $slug ) );
    }, (array) $list ) );
    $list = array_values( array_unique( $list ) );
    return $list ? $list : [ 'premium' ];
}

/**
 * True when the current cart holds livestock: any cart line whose product
 * (the PARENT id for variations — the category lives there) is in the
 * quarantined-fish category OR ships in an overnight class. Sticker
 * giveaway lines (Misc plugin, cart item data fh_sticker = free|extra)
 * are skipped — they ride in whatever box ships and are never livestock.
 *
 * Drives the livestock-only checkout rules (billing phone required for the
 * UPS label); dry-goods carts keep WC's defaults.
 *
 * Safe outside a front-end request: returns false when WC or its cart is
 * not loaded (admin, REST, cron) instead of touching a null cart. Not
 * memoised on purpose — the cart can change within one request (add-to-
 * cart AJAX, coupon/gift-card removal) and the loop is in-memory anyway;
 * fishotel_is_quarantined_fish() already caches the term lookups.
 *
 * @return bool
 */
function fishotel_cart_has_livestock() {
    if ( ! function_exists( 'WC' ) || ! WC() || ! ( WC()->cart instanceof WC_Cart ) ) {
        return false;
    }
    $overnight = fishotel_overnight_shipping_classes();
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        if ( ! is_array( $cart_item ) || ! empty( $cart_item['fh_sticker'] ) ) {
            continue;
        }
        $product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product
            ? $cart_item['data']
            : null;

        // WC stores the parent product id on the line; fall back to the
        // product object's parent (or itself for simple products).
        $parent_id = ! empty( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
        if ( ! $parent_id && $product ) {
            $parent_id = $product->get_parent_id() ? (int) $product->get_parent_id() : (int) $product->get_id();
        }
        if ( $parent_id && fishotel_is_quarantined_fish( $parent_id ) ) {
            return true;
        }
        // get_shipping_class() on a variation resolves to its own class or
        // the parent's when not overridden — same read as the piggyback.
        if ( $product && in_array( strtolower( (string) $product->get_shipping_class() ), $overnight, true ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Filter the PDP top-of-page tag list. Drops:
 *   1. "Fish" (redundant on a fish product).
 *   2. Any tag whose name matches the genus of the product's scientific
 *      name. The genus is resolved from `_fh_scientific_name` meta first,
 *      then from the first word of the first line of the product's short
 *      description (which is the italicized subtitle on the PDP). Strips
 *      the matching tag case-insensitively.
 *
 * Deny-list is applied centrally; do not edit tags per-product.
 *
 * @param WP_Term[] $tags        product_tag terms as returned by get_the_terms().
 * @param int       $product_id  Product post ID — used to source the genus.
 * @return WP_Term[]
 */
function fishotel_pdp_display_tags( $tags, $product_id ) {
    if ( ! is_array( $tags ) || empty( $tags ) ) {
        return [];
    }
    $deny       = [ 'fish' ];
    $product_id = (int) $product_id;
    $genus      = '';

    if ( $product_id ) {
        $sci = (string) get_post_meta( $product_id, '_fh_scientific_name', true );
        if ( $sci !== '' ) {
            $parts = preg_split( '/\s+/', trim( wp_strip_all_tags( $sci ) ) );
            if ( ! empty( $parts[0] ) ) {
                $genus = $parts[0];
            }
        }
        if ( $genus === '' ) {
            $short = (string) get_post_field( 'post_excerpt', $product_id );
            if ( $short !== '' ) {
                $first_line = strtok( wp_strip_all_tags( $short ), "\n" );
                if ( $first_line ) {
                    $parts = preg_split( '/\s+/', trim( $first_line ) );
                    if ( ! empty( $parts[0] ) ) {
                        $genus = $parts[0];
                    }
                }
            }
        }
    }

    if ( $genus !== '' ) {
        $deny[] = strtolower( $genus );
    }

    return array_values( array_filter( $tags, function ( $t ) use ( $deny ) {
        return ! in_array( strtolower( $t->name ), $deny, true );
    } ) );
}

/**
 * Compute the PDP stock-badge state + label from WC's max_purchase_quantity
 * convention: 0 = sold out, -1 = unmanaged (or backorders allowed), n>0 =
 * managed with n in stock. JS in main.js mirrors this exactly — keep them
 * in sync when copy or thresholds change.
 *
 * @param bool $in_stock  Whether the product/variation is purchasable now.
 * @param int  $max_qty   WC max purchase quantity (see above).
 * @return array{state:string,text:string}
 */
function fishotel_stock_badge_label( $in_stock, $max_qty ) {
    // WC's variation payload (get_available_variations → max_qty) uses ''
    // for "no limit" where the product API uses -1. Treat '' as unlimited;
    // a bare (int) cast turned it into 0 = "Sold Out" on in-stock variations.
    if ( '' === $max_qty ) {
        $max_qty = -1;
    }
    $max_qty = (int) $max_qty;
    if ( ! $in_stock || $max_qty === 0 ) {
        return [ 'state' => 'soldout', 'text' => 'Sold Out' ];
    }
    if ( $max_qty === 1 ) {
        return [ 'state' => 'last', 'text' => 'Last one — Just 1 left' ];
    }
    if ( $max_qty >= 2 && $max_qty < 5 ) {
        return [ 'state' => 'low', 'text' => 'Only ' . $max_qty . ' left in stock' ];
    }
    return [ 'state' => 'in-stock', 'text' => 'In Stock — Ready to Ship' ];
}

/**
 * Clean up a variation label for display. Handles three slug-style
 * patterns the customer would otherwise see uppercased verbatim:
 *   - Hyphen-as-decimal between digits ("0-5oz" → "0.5oz")
 *   - Missing space between number and unit ("1oz" → "1 oz", "3lb" → "3 lb")
 *   - Slug-style multi-word names ("omnivore-flake" → "omnivore flake")
 * CSS `text-transform: uppercase` does the rest at render time, so
 * downstream output looks like "0.5 OZ" / "1 OZ" / "OMNIVORE FLAKE".
 *
 * @param string $label Raw term name or option string.
 * @return string
 */
function fishotel_format_variation_label( $label ) {
    $label = (string) $label;
    if ( $label === '' ) {
        return $label;
    }
    // Hyphen between two digits is the WP slug-sanitizer's stand-in for
    // a decimal point. Restore the period first so the "0-5oz" case
    // becomes "0.5oz" before any other transform runs.
    $label = preg_replace( '/(\d)-(\d)/', '$1.$2', $label );
    // Add a space between a number (optionally decimal) and a trailing
    // alpha unit ("1oz" → "1 oz", "0.5oz" → "0.5 oz", "3lb" → "3 lb").
    $label = preg_replace( '/(\d(?:\.\d+)?)([a-zA-Z]+)/', '$1 $2', $label );
    // Any remaining hyphens are word separators in slug-style names.
    $label = str_replace( '-', ' ', $label );
    // Collapse runs of whitespace so we don't double-space anything.
    $label = preg_replace( '/\s+/', ' ', trim( $label ) );
    return $label;
}

/**
 * Display label for a single variation-attribute option. For taxonomy
 * attributes WC's `get_variation_attributes()` returns SLUGS, which
 * WordPress sanitizes — periods become hyphens, and characters like
 * `"`, `<`, `>`, `+` are stripped entirely. Rendering the slug
 * directly would show "0-5OZ" / "2-3-5" / "1" for terms admin
 * actually named "0.5oz" / "2-3.5\"" / "<1\"". Look up the term and
 * return its name verbatim so display preserves whatever admin
 * entered, including non-URL-safe characters.
 *
 * Only when no term resolves (rare — orphaned slug or non-taxonomy
 * attribute whose option still looks slug-style) do we run the raw
 * value through `fishotel_format_variation_label()` to recover
 * decimals + spaces.
 *
 * @param string $attr_name Attribute taxonomy / name (e.g. `pa_bag-size`).
 * @param string $option    Raw option value as returned by WC.
 * @return string
 */
function fishotel_variation_option_label( $attr_name, $option ) {
    $attr_name = (string) $attr_name;
    $option    = (string) $option;
    if ( $attr_name !== '' && taxonomy_exists( $attr_name ) ) {
        $term = get_term_by( 'slug', $option, $attr_name );
        if ( $term && ! is_wp_error( $term ) && $term->name !== '' ) {
            return $term->name;
        }
    }
    return fishotel_format_variation_label( $option );
}

/**
 * Sort variation-attribute options by unit group first (oz before lb
 * before other) then ascending numeric value. Fixes two problems with
 * the WC default alphabetic-on-slug order:
 *   - "0-25-ounce" sorts before "0-5oz" because "25" < "5" lexically.
 *   - "1lb" sorts between "1oz" and "2oz" because the leading number
 *     wins when units are ignored, scrambling oz / lb pairs.
 * Options without an extractable number sort last in their original
 * order (stable).
 *
 * @param array  $options   Raw option values (slugs for taxonomy attrs).
 * @param string $attr_name Attribute taxonomy / name; used to resolve
 *                          display labels for unit + number extraction.
 * @return array
 */
function fishotel_sort_variation_options( $options, $attr_name = '' ) {
    if ( ! is_array( $options ) || count( $options ) < 2 ) {
        return $options;
    }

    $unit_priority = [ 'oz' => 0, 'lb' => 1 ];

    $indexed = [];
    foreach ( $options as $i => $slug ) {
        $label = fishotel_variation_option_label( $attr_name, $slug );
        $num   = PHP_INT_MAX;
        $unit  = '';
        if ( preg_match( '/(\d+(?:\.\d+)?)\s*([a-zA-Z]+)?/', $label, $m ) ) {
            $num  = (float) $m[1];
            $unit = isset( $m[2] ) ? strtolower( $m[2] ) : '';
        }
        // No number found → sort last; with a number but an unknown
        // unit → sort after the known units (oz, lb).
        if ( $num === PHP_INT_MAX ) {
            $priority = 99;
        } elseif ( isset( $unit_priority[ $unit ] ) ) {
            $priority = $unit_priority[ $unit ];
        } else {
            $priority = 2;
        }
        $indexed[] = [ 'slug' => $slug, 'priority' => $priority, 'num' => $num, 'idx' => $i ];
    }

    usort( $indexed, function ( $a, $b ) {
        if ( $a['priority'] !== $b['priority'] ) {
            return $a['priority'] <=> $b['priority'];
        }
        if ( $a['num'] !== $b['num'] ) {
            return $a['num'] <=> $b['num'];
        }
        return $a['idx'] <=> $b['idx'];
    } );
    return array_column( $indexed, 'slug' );
}

/**
 * True when the current request is the Medications parent category
 * archive — `/product-category/medications/`. False on the shop root,
 * on form/treats child archives (flakes, dewormer, etc.), and on every
 * non-medication archive. Gates the dual-axis filter chip strip.
 */
function fishotel_is_medications_archive() {
    if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
        return false;
    }
    $term = get_queried_object();
    return ( $term && isset( $term->slug ) && $term->slug === 'medications' );
}

/**
 * Resolve the noun used in archive count + "ALL X" copy based on the
 * current product_cat slug. Allowlist on the slugs we ship; everything
 * else gets a generic "product(s)" fallback so future categories Jeff
 * adds (gift cards, merchandise, test kits, etc.) don't inherit the
 * fish copy.
 *
 * @param bool $singular Return the singular form ("fish", "medication",
 *                       "food", "product") instead of the plural.
 * @return string
 */
function fishotel_archive_noun( $singular = false ) {
    $term = ( function_exists( 'is_product_category' ) && is_product_category() )
        ? get_queried_object()
        : null;
    $slug = ( $term && isset( $term->slug ) ) ? (string) $term->slug : '';

    $fish_slugs = [ 'quarantined-fish' ];
    $med_slugs  = [ 'medications', 'flakes', 'pellets', 'powders', 'liquid', 'antibacterial', 'antiparasitic', 'dewormer' ];
    $food_slugs = [ 'freeze-dried-foods', 'fish-food', 'medicated-food' ];

    if ( in_array( $slug, $fish_slugs, true ) ) {
        return 'fish'; // same in singular + plural
    }
    if ( in_array( $slug, $med_slugs, true ) ) {
        return $singular ? 'medication' : 'medications';
    }
    if ( in_array( $slug, $food_slugs, true ) ) {
        return $singular ? 'food' : 'foods';
    }
    return $singular ? 'product' : 'products';
}

/**
 * True when the product is in the medications taxonomy bucket — either
 * the parent term or any of the Phase 3.4 form/treats children. Used
 * by the trust strip + future medication-specific PDP blocks.
 *
 * @param int|null $product_id Defaults to the current loop ID.
 * @return bool
 */
function fishotel_is_medication_product( $product_id = null ) {
    $product_id = $product_id ? (int) $product_id : (int) get_the_ID();
    if ( ! $product_id ) {
        return false;
    }
    $cats = fishotel_product_cat_slugs( $product_id );
    if ( empty( $cats ) ) {
        return false;
    }
    $med_slugs = [ 'medications', 'flakes', 'pellets', 'powders', 'liquid', 'antibacterial', 'antiparasitic', 'dewormer' ];
    return (bool) array_intersect( $med_slugs, $cats );
}

/**
 * True when the product is in any of the food taxonomy buckets.
 *
 * @param int|null $product_id Defaults to the current loop ID.
 * @return bool
 */
function fishotel_is_food_product( $product_id = null ) {
    $product_id = $product_id ? (int) $product_id : (int) get_the_ID();
    if ( ! $product_id ) {
        return false;
    }
    $cats = fishotel_product_cat_slugs( $product_id );
    if ( empty( $cats ) ) {
        return false;
    }
    $food_slugs = [ 'freeze-dried-foods', 'fish-food', 'medicated-food' ];
    return (bool) array_intersect( $food_slugs, $cats );
}

/**
 * True when a product is Tier 2 — i.e. an Amazon affiliate item, keyed
 * on a non-empty `_fishotel_amazon_asin` post meta value. The check is
 * orthogonal to product_cat so it correctly identifies affiliate items
 * regardless of whether they live in Medications (Copper Power today)
 * or some future category (Seachem etc.).
 *
 * @param int|null $product_id Defaults to the current loop ID.
 * @return bool
 */
function fishotel_is_amazon_affiliate_product( $product_id = null ) {
    $product_id = $product_id ? (int) $product_id : (int) get_the_ID();
    if ( ! $product_id ) {
        return false;
    }
    $asin = (string) get_post_meta( $product_id, '_fishotel_amazon_asin', true );
    return trim( $asin ) !== '';
}

/**
 * Render the purchase-panel badge above the price. Branch by category:
 *   - quarantined-fish → "Quarantine Complete" with the two QT protocol
 *     lines, region, hotel notes (existing fish behavior).
 *   - medications + Tier 2 affiliate → "From the Pharmacy" with the
 *     admin-editable pharmacy subtitle.
 *   - foods → "From the Pantry" with the admin-editable pantry subtitle.
 *   - everything else → nothing.
 *
 * All three variants share the .fh-qt-cert markup so CSS doesn't need
 * separate styling — only the copy swaps.
 *
 * @param int   $product_id Product ID.
 * @param array $extras     Optional fish-specific extras (region, hotel_notes).
 */
function fishotel_render_purchase_badge( $product_id, $extras = [] ) {
    $product_id = (int) $product_id;
    if ( ! $product_id ) {
        return;
    }

    $get = function ( $key ) {
        return class_exists( 'FisHotel_Admin_Settings' )
            ? (string) FisHotel_Admin_Settings::get( $key )
            : '';
    };

    if ( fishotel_is_quarantined_fish( $product_id ) ) {
        $title    = 'Quarantine Complete';
        $line_one = $get( 'fh_qt_line_1' );
        $line_two = $get( 'fh_qt_line_2' );
        $extras_html  = '';
        if ( ! empty( $extras['region'] ) ) {
            $extras_html .= '<div class="fh-qt-cert__region">Region: ' . esc_html( $extras['region'] ) . '</div>';
        }
        if ( ! empty( $extras['hotel_notes'] ) ) {
            $extras_html .= '<div class="fh-qt-cert__notes">' . esc_html( $extras['hotel_notes'] ) . '</div>';
        }
    } elseif (
        ( function_exists( 'fishotel_is_amazon_affiliate_product' ) && fishotel_is_amazon_affiliate_product( $product_id ) )
        || ( function_exists( 'fishotel_is_medication_product' )    && fishotel_is_medication_product( $product_id ) )
    ) {
        $title    = 'From the Pharmacy';
        $line_one = $get( 'fh_pharmacy_badge_subtitle' );
        $line_two = '';
        $extras_html = '';
    } elseif ( function_exists( 'fishotel_is_food_product' ) && fishotel_is_food_product( $product_id ) ) {
        $title    = 'From the Pantry';
        $line_one = $get( 'fh_pantry_badge_subtitle' );
        $line_two = '';
        $extras_html = '';
    } else {
        return;
    }
    ?>
    <div class="fh-qt-cert">
        <div class="fh-qt-cert__header">
            <span class="fh-qt-cert__check">&#10003;</span>
            <span class="fh-qt-cert__title"><?php echo esc_html( $title ); ?></span>
        </div>
        <?php if ( $line_one !== '' || $line_two !== '' ) : ?>
        <div class="fh-qt-cert__protocol">
            <?php
            if ( $line_one !== '' ) {
                echo esc_html( $line_one );
            }
            if ( $line_two !== '' ) {
                if ( $line_one !== '' ) {
                    echo '<br>';
                }
                echo esc_html( $line_two );
            }
            ?>
        </div>
        <?php endif; ?>
        <?php echo $extras_html; // already escaped above ?>
    </div>
    <?php
}

/**
 * Render the three-line trust strip below Add to Cart. Single markup
 * pipeline for the fish / medication / food variants — content swaps,
 * markup + classes stay identical so the visual layout matches across
 * categories. Skips lines that resolve to an empty string after the
 * admin override / default fallback.
 *
 * @param string[] $items 1-3 trust-strip lines, already resolved.
 */
function fishotel_render_trust_strip( $items ) {
    $items = array_values( array_filter( array_map( 'strval', (array) $items ), function ( $line ) {
        return trim( $line ) !== '';
    } ) );
    if ( empty( $items ) ) {
        return;
    }
    echo '<div class="fh-trust-strip">';
    foreach ( $items as $line ) {
        echo '<span class="fh-trust-strip__item">&#10003; ' . esc_html( $line ) . '</span>';
    }
    echo '</div>';
}

/**
 * Resolve the appropriate trust-strip lines for a product. Reads admin
 * overrides via FisHotel_Admin_Settings (so non-developers can edit
 * copy), falling back to the spec-supplied defaults when the stored
 * value is empty. Returns an empty array for products outside the
 * fish/medication/food buckets (gift cards, merchandise, etc.) so the
 * caller can skip the trust-strip slot entirely.
 *
 * @param int|null $product_id Defaults to the current loop ID.
 * @return string[]
 */
function fishotel_get_trust_strip_items( $product_id = null ) {
    $product_id = $product_id ? (int) $product_id : (int) get_the_ID();
    if ( ! $product_id ) {
        return [];
    }

    // Defaults live in FisHotel_Admin_Settings::defaults() + register_setting().
    // FisHotel_Admin_Settings::get() falls back to that default both when
    // the option key is absent from the DB AND when the stored value is
    // an empty string (Phase 3.5.1) so callers no longer need their own
    // ?: ladder.
    $get = function ( $key ) {
        return class_exists( 'FisHotel_Admin_Settings' )
            ? (string) FisHotel_Admin_Settings::get( $key )
            : '';
    };

    // Branch order matters: Tier 2 (Amazon-affiliate) products are still
    // categorised under medications, so the affiliate check has to win
    // before the medication branch. Fish gates first because it's the
    // dominant category and the helper is the cheapest.
    if ( fishotel_is_quarantined_fish( $product_id ) ) {
        return [ $get( 'fh_trust_1' ), $get( 'fh_trust_2' ), $get( 'fh_trust_3' ) ];
    }
    if ( fishotel_is_amazon_affiliate_product( $product_id ) ) {
        return [ $get( 'fh_tier2_trust_1' ), $get( 'fh_tier2_trust_2' ), $get( 'fh_tier2_trust_3' ) ];
    }
    if ( fishotel_is_medication_product( $product_id ) ) {
        return [ $get( 'fh_med_trust_1' ), $get( 'fh_med_trust_2' ), $get( 'fh_med_trust_3' ) ];
    }
    if ( fishotel_is_food_product( $product_id ) ) {
        return [ $get( 'fh_food_trust_1' ), $get( 'fh_food_trust_2' ), $get( 'fh_food_trust_3' ) ];
    }
    return [];
}
