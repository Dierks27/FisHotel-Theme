<?php
/**
 * FisHotel Theme — functions.php
 *
 * @package FisHotel
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

define( 'FISHOTEL_THEME_VERSION', '1.16.0' );
define( 'FISHOTEL_THEME_DIR', get_template_directory() );
define( 'FISHOTEL_THEME_URI', get_template_directory_uri() );

// Composer autoloader — bundles dompdf for the EA packing-slip PDF. Guarded
// so the theme still boots if vendor/ hasn't been deployed to the server yet.
if ( file_exists( FISHOTEL_THEME_DIR . '/vendor/autoload.php' ) ) {
	require_once FISHOTEL_THEME_DIR . '/vendor/autoload.php';
}

/**
 * Cache-bust helper — returns filemtime() for a theme-relative asset path,
 * falling back to FISHOTEL_THEME_VERSION if the file is missing. Pass to
 * wp_enqueue_style/script as $ver so browsers (and most edge caches) drop
 * the cached copy whenever the file actually changes on disk.
 */
function fishotel_asset_version( $relative_path ) {
	$path = FISHOTEL_THEME_DIR . '/' . ltrim( $relative_path, '/' );
	return ( file_exists( $path ) ? filemtime( $path ) : 0 ) . '-' . FISHOTEL_THEME_VERSION;
}

// Medication Dosing Calculator
require_once FISHOTEL_THEME_DIR . '/inc/calculator/init.php';
// QT Scheduler
require_once FISHOTEL_THEME_DIR . '/inc/scheduler/init.php';
// Medication Store (Phase 1) — universal product model, EA sync, disclaimer wall.
require_once FISHOTEL_THEME_DIR . '/inc/medication-store/init.php';
// Order Fulfillment (Phase 1) — opt-in split fulfillment for mixed orders.
require_once FISHOTEL_THEME_DIR . '/inc/order-fulfillment/class-fishotel-order-fulfillment.php';
FisHotel_Order_Fulfillment::init();
// Fulfillment Orders (Phase 2.5) — aggregate source orders into a single
// operational shipping unit. Replaces Phase 2 primary/secondary linking.
require_once FISHOTEL_THEME_DIR . '/inc/order-fulfillment/class-fishotel-fulfillment.php';
FisHotel_Fulfillment::init();
// Checkout Shipping Reuse (Phase 3) — zero duplicate shipping when a customer
// already has an unshipped order/fulfillment to the same address.
require_once FISHOTEL_THEME_DIR . '/inc/order-fulfillment/class-fishotel-checkout-reuse.php';
FisHotel_Checkout_Reuse::init();
// ─────────────────────────────────────────
// THEME SETUP
// ─────────────────────────────────────────
function fishotel_setup() {
	load_theme_textdomain( 'fishotel', FISHOTEL_THEME_DIR . '/languages' );

	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ] );
	add_theme_support( 'customize-selective-refresh-widgets' );

	// Custom logo
	add_theme_support( 'custom-logo', [
		'height'      => 80,
		'width'       => 200,
		'flex-width'  => true,
		'flex-height' => true,
	] );

	// WooCommerce
	add_theme_support( 'woocommerce', [
		'thumbnail_image_width'         => 600,
		'single_image_width'            => 800,
		'product_grid'                  => [
			'default_rows'    => 3,
			'min_rows'        => 1,
			'default_columns' => 4,
			'min_columns'     => 2,
			'max_columns'     => 4,
		],
	] );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	// Image sizes
	add_image_size( 'fishotel-product-card',  480, 480, true );
	add_image_size( 'fishotel-product-hero',  800, 800, true );
	add_image_size( 'fishotel-product-thumb', 120, 120, true );
	add_image_size( 'fishotel-hero-banner',  1920, 700, true );

	// Nav menus
	register_nav_menus( [
		'primary-left'  => __( 'Primary — Left Side',  'fishotel' ),
		'primary-right' => __( 'Primary — Right Side', 'fishotel' ),
		'footer'        => __( 'Footer Navigation',    'fishotel' ),
	] );
}
add_action( 'after_setup_theme', 'fishotel_setup' );

// ─────────────────────────────────────────
// ENQUEUE SCRIPTS & STYLES
// ─────────────────────────────────────────
function fishotel_enqueue_assets() {
	// Google Fonts
	wp_enqueue_style(
		'fishotel-fonts',
		'https://fonts.googleapis.com/css2?family=Courier+Prime&family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,300;1,400&family=Roboto+Slab:wght@300;400;700&display=swap',
		[],
		null
	);

	// Main stylesheet
	wp_enqueue_style(
		'fishotel-style',
		FISHOTEL_THEME_URI . '/assets/css/main.css',
		[ 'fishotel-fonts' ],
		fishotel_asset_version( 'assets/css/main.css' )
	);

	// WooCommerce override styles
	if ( class_exists( 'WooCommerce' ) ) {
		wp_enqueue_style(
			'fishotel-woocommerce',
			FISHOTEL_THEME_URI . '/assets/css/woocommerce.css',
			[ 'fishotel-style' ],
			fishotel_asset_version( 'assets/css/woocommerce.css' )
		);
	}

	// Main JS
	wp_enqueue_script(
		'fishotel-main',
		FISHOTEL_THEME_URI . '/assets/js/main.js',
		[ 'jquery' ],
		fishotel_asset_version( 'assets/js/main.js' ),
		true
	);

	// Pass data to JS
	wp_localize_script( 'fishotel-main', 'fishotelData', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'fishotel_nonce' ),
	] );

	// Comments
	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'fishotel_enqueue_assets' );

/**
 * Re-add a cache-bust param to theme asset URLs after the security
 * plugin strips `?ver=`. Really Simple Security's "Hide your WordPress
 * version" hardening removes every `?ver=` query string from enqueued
 * CSS/JS — which also kills the cache-bust timestamps `fishotel_asset_version()`
 * generates, leaving customers on stale CSS/JS after deploys.
 *
 * Uses a different query key (`fhv`) so the security plugin's `?ver=`
 * filter doesn't strip it again, and only touches URLs containing
 * `/fishotel-theme/` so WP core + plugin assets stay unversioned.
 * Priority 999 runs last, after any stripping filters.
 */
function fishotel_cachebust_src( $src ) {
	if ( ! is_string( $src ) || strpos( $src, '/fishotel-theme/' ) === false ) {
		return $src;
	}
	$relative = str_replace( FISHOTEL_THEME_URI . '/', '', strtok( $src, '?' ) );
	$file     = FISHOTEL_THEME_DIR . '/' . $relative;
	$stamp    = file_exists( $file ) ? filemtime( $file ) : FISHOTEL_THEME_VERSION;
	$sep      = ( strpos( $src, '?' ) !== false ) ? '&' : '?';
	return $src . $sep . 'fhv=' . $stamp;
}
add_filter( 'style_loader_src',  'fishotel_cachebust_src', 999 );
add_filter( 'script_loader_src', 'fishotel_cachebust_src', 999 );

/**
 * Always show the WC Gift Cards balance checkbox at checkout, even when
 * the cart contains gift card products. The plugin's WC_GC_Cart bails out
 * of its balance calculation when `cart_contains_gift_card()` is true,
 * which zeroes `available_total` and hides the "Use $X from your balance"
 * checkbox — blocking customers from spending balance on the fish/med
 * portion of a mixed cart. Forcing the contains-check to false keeps the
 * balance calc (and the checkbox) alive for every cart.
 *
 * Trade-off: balance then applies to the whole cart total (the plugin has
 * no per-line exclusion), so it can also pay toward gift-card items. That
 * edge case is acceptable. "Block gift card discounts" (WC → Settings →
 * Gift Cards) still governs coupon discounts separately.
 */
add_filter( 'woocommerce_gc_cart_contains_gift_card', '__return_false' );

// ─────────────────────────────────────────
// INCLUDE MODULES
// ─────────────────────────────────────────
$includes = [
	'/inc/account-icons.php',     // My Account inline-SVG icon set
	'/inc/account-relabels.php',  // My Account labels, copy, page-header lookup
	'/inc/template-functions.php',
	'/inc/template-tags.php',
	'/inc/customizer.php',
	'/inc/woocommerce.php',
	'/inc/shop-load-more.php', // Shop archive Load More (AJAX) + enqueue
	'/inc/hotel-data.php',       // FisHotel quarantine meta
	'/inc/hotel-journal.php',    // Quarantine journal entries
	'/inc/variation-display.php', // Visual variation buttons
	'/inc/widgets.php',
	'/inc/admin-settings.php',   // FisHotel admin settings page
	'/inc/placeholder-library.php', // Product image placeholder library
	'/inc/cart-presets.php',     // Category-aware cart copy presets
	'/inc/coming-soon.php',      // Coming Soon (Arriving Soon) UI helpers
	'/inc/contact-form.php',     // Native /contacts/ form handler
	'/inc/compatibility-guide-bootstrap.php',    // /compatibility-guide/ template assignment
	'/inc/compatibility-guide-data.php',         // JSON loaders + volume modifier helpers
	'/inc/compatibility-guide-enqueue.php',      // Conditional CSS/JS for the guide
	'/inc/compatibility-guide-product-meta.php', // WC product → matrix category meta box
	'/inc/compatibility-guide-rest.php',         // REST endpoint for inventory panel
	'/inc/compatibility-guide-backfill.php',     // Admin backfill tool for product → category meta
	'/inc/bleach-calculator-bootstrap.php',      // /bleach-calculator/ template assignment
	'/inc/bleach-calculator-enqueue.php',        // Conditional CSS/JS for the bleach calculator
	'/inc/theme-updater.php',    // Self-updater via raw GitHub branch
];

foreach ( $includes as $file ) {
	$path = FISHOTEL_THEME_DIR . $file;
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}

// ─────────────────────────────────────────
// REMOVE UNNEEDED WOOCOMMERCE STYLES
// ─────────────────────────────────────────
add_filter( 'woocommerce_enqueue_styles', function( $styles ) {
	// We handle all WooCommerce styling ourselves
	unset( $styles['woocommerce-general'] );
	unset( $styles['woocommerce-layout'] );
	unset( $styles['woocommerce-smallscreen'] );
	return $styles;
} );

// ─────────────────────────────────────────
// REMOVE WOOCOMMERCE SIDEBAR (we handle layout)
// ─────────────────────────────────────────
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

// ─────────────────────────────────────────
// BODY CLASSES
// ─────────────────────────────────────────
add_filter( 'body_class', function( $classes ) {
	$classes[] = 'fishotel-theme';
	if ( is_product() ) {
		$classes[] = 'fishotel-product-page';
	}
	if ( is_shop() || is_product_category() ) {
		$classes[] = 'fishotel-shop-page';
	}
	return $classes;
} );

// Enqueue homepage styles
function fishotel_enqueue_home_assets() {
    if ( is_front_page() ) {
        wp_enqueue_style(
            'fishotel-home',
            FISHOTEL_THEME_URI . '/assets/css/home.css',
            ['fishotel-style'],
            fishotel_asset_version( 'assets/css/home.css' )
        );
    }
}
add_action( 'wp_enqueue_scripts', 'fishotel_enqueue_home_assets' );

// Enqueue My Account styles + display fonts only on the WooCommerce account area
function fishotel_enqueue_account_assets() {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}
	wp_enqueue_style(
		'fishotel-account-fonts',
		'https://fonts.googleapis.com/css2?family=EB+Garamond:ital@0;1&family=Josefin+Sans:wght@300;400;500&family=Playfair+Display:ital,wght@0,500;0,700;1,500&display=swap',
		[],
		null
	);
	wp_enqueue_style(
		'fishotel-my-account',
		FISHOTEL_THEME_URI . '/assets/css/my-account.css',
		[ 'fishotel-style', 'fishotel-account-fonts' ],
		fishotel_asset_version( 'assets/css/my-account.css' )
	);
	wp_enqueue_script(
		'fishotel-my-account',
		FISHOTEL_THEME_URI . '/assets/js/my-account.js',
		[],
		fishotel_asset_version( 'assets/js/my-account.js' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'fishotel_enqueue_account_assets' );

// Enqueue FAQ page styles only on pages using the FAQ template
function fishotel_enqueue_faq_assets() {
    if ( is_page_template( 'page-faq.php' ) ) {
        wp_enqueue_style(
            'fishotel-faq',
            FISHOTEL_THEME_URI . '/assets/css/faq.css',
            [ 'fishotel-style' ],
            fishotel_asset_version( 'assets/css/faq.css' )
        );
    }
}
add_action( 'wp_enqueue_scripts', 'fishotel_enqueue_faq_assets' );

// Enqueue Contacts-page styles only on pages using the Contacts template
function fishotel_enqueue_contacts_assets() {
    if ( is_page_template( 'page-contacts.php' ) ) {
        wp_enqueue_style(
            'fishotel-contacts',
            FISHOTEL_THEME_URI . '/assets/css/contacts.css',
            [ 'fishotel-style' ],
            fishotel_asset_version( 'assets/css/contacts.css' )
        );
    }
}
add_action( 'wp_enqueue_scripts', 'fishotel_enqueue_contacts_assets' );

// Enqueue About-page styles + display fonts only on the About template
function fishotel_enqueue_about_assets() {
    if ( is_page_template( 'page-about.php' ) ) {
        wp_enqueue_style(
            'fishotel-about-fonts',
            'https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,700;1,400;1,500&family=Playfair+Display:ital,wght@0,400;0,500;0,700;0,800;0,900;1,400;1,500;1,700&display=swap',
            [],
            null
        );
        wp_enqueue_style(
            'fishotel-about',
            FISHOTEL_THEME_URI . '/assets/css/about.css',
            [ 'fishotel-style', 'fishotel-about-fonts' ],
            fishotel_asset_version( 'assets/css/about.css' )
        );
    }
}
add_action( 'wp_enqueue_scripts', 'fishotel_enqueue_about_assets' );

/**
 * Generic page bootstrap: ensure a page exists at $slug and is bound to
 * $template. Durable against the case where the page already existed on
 * the default template (the symptom we hit on Contacts and Newsletter).
 *
 * Runs on admin_init — no option-flag gating, because update_post_meta
 * is idempotent and skipping when the meta is already correct is cheap.
 * Creates the page as a Draft if it doesn't exist; reasserts the template
 * meta if it does but the binding is missing or wrong.
 */
function fishotel_bootstrap_template_page( $slug, $template, $title ) {
    $page = get_page_by_path( $slug );
    if ( ! $page ) {
        $page_id = wp_insert_post( [
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_status'  => 'draft',
            'post_type'    => 'page',
            'post_content' => '', // Body lives in FisHotel Settings / template parts.
        ] );
        if ( ! $page_id || is_wp_error( $page_id ) ) {
            return;
        }
        update_post_meta( $page_id, '_wp_page_template', $template );
        return;
    }
    $current = (string) get_post_meta( $page->ID, '_wp_page_template', true );
    if ( $current !== $template ) {
        update_post_meta( $page->ID, '_wp_page_template', $template );
    }
}

function fishotel_bootstrap_about_page()    { fishotel_bootstrap_template_page( 'about-us',  'page-about.php',     'About Us' ); }
function fishotel_bootstrap_contacts_page() { fishotel_bootstrap_template_page( 'contacts',  'page-contacts.php',  'Contacts' ); }
function fishotel_bootstrap_newsletter_page(){ fishotel_bootstrap_template_page( 'newsletter','page-newsletter.php','Newsletter' ); }
add_action( 'admin_init', 'fishotel_bootstrap_about_page' );
add_action( 'admin_init', 'fishotel_bootstrap_contacts_page' );
add_action( 'admin_init', 'fishotel_bootstrap_newsletter_page' );

// Live cart-count badge — WooCommerce swaps this fragment on AJAX add/remove,
// so the count in the header updates without a page reload.
add_filter( 'woocommerce_add_to_cart_fragments', function ( $fragments ) {
    $count = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
    ob_start();
    ?>
    <span class="site-header__icon-count fishotel-cart-count" data-count="<?php echo esc_attr( $count ); ?>"><?php echo esc_html( $count ); ?></span>
    <?php
    $fragments['span.fishotel-cart-count'] = ob_get_clean();

    // Mobile drawer's "Cart (N)" — distinct class so it doesn't inherit
    // the desktop pill styling.
    $fragments['span.fishotel-drawer-cart-count'] = sprintf(
        '<span class="fishotel-drawer-cart-count">%d</span>',
        intval( $count )
    );

    return $fragments;
} );

// Override the browser-tab title for the Medication Dosing calculator page
// so it matches the on-page heading. The post itself is still titled
// "Quarantine Help" in WP admin (used elsewhere); we only swap the
// document title for this template.
add_filter( 'document_title_parts', function ( $parts ) {
	if ( is_page_template( 'page-quarantine-help.php' ) ) {
		$parts['title'] = 'Medication Dosing';
	}
	return $parts;
} );

// Normalize "Contacts" → "Contact" in nav menu items so the label matches the
// page heading without forcing an admin menu edit. Preserves casing so a
// "CONTACTS" item becomes "CONTACT" and "Contacts" becomes "Contact".
add_filter( 'wp_nav_menu_objects', function ( $items ) {
	foreach ( $items as $item ) {
		if ( ! isset( $item->title ) ) continue;
		if ( $item->title === 'CONTACTS' ) {
			$item->title = 'CONTACT';
		} elseif ( $item->title === 'Contacts' ) {
			$item->title = 'Contact';
		}
	}
	return $items;
} );

// Homepage bubble animation
function fishotel_hero_inline_js() {
    if ( is_front_page() ) : ?>
    <script>
    (function(){
        var c = document.getElementById('fh-bubbles');
        if (!c) return;
        for (var i=0;i<16;i++){
            var b=document.createElement('div');
            var s=Math.random()*40+8;
            b.style.cssText='position:absolute;bottom:-20px;border-radius:50%;width:'+s+'px;height:'+s+'px;background:rgba(74,157,184,0.12);border:1px solid rgba(74,157,184,0.15);left:'+Math.random()*100+'%;animation:fh-rise '+(Math.random()*12+8)+'s linear '+(Math.random()*10)+'s infinite;';
            c.appendChild(b);
        }
        if(!document.getElementById('fh-bubble-style')){
            var style=document.createElement('style');
            style.id='fh-bubble-style';
            style.textContent='@keyframes fh-rise{0%{transform:translateY(0) scale(1);opacity:0}10%{opacity:1}90%{opacity:.3}100%{transform:translateY(-110vh) scale(.4);opacity:0}}';
            document.head.appendChild(style);
        }
    })();
    </script>
    <?php endif;
}
add_action('wp_footer', 'fishotel_hero_inline_js');
