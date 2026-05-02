<?php
/**
 * FisHotel Theme — functions.php
 *
 * @package FisHotel
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

define( 'FISHOTEL_THEME_VERSION', '1.0.2' );
define( 'FISHOTEL_THEME_DIR', get_template_directory() );
define( 'FISHOTEL_THEME_URI', get_template_directory_uri() );

/**
 * Cache-bust helper — returns filemtime() for a theme-relative asset path,
 * falling back to FISHOTEL_THEME_VERSION if the file is missing. Pass to
 * wp_enqueue_style/script as $ver so browsers (and most edge caches) drop
 * the cached copy whenever the file actually changes on disk.
 */
function fishotel_asset_version( $relative_path ) {
	$path = FISHOTEL_THEME_DIR . '/' . ltrim( $relative_path, '/' );
	return file_exists( $path ) ? filemtime( $path ) : FISHOTEL_THEME_VERSION;
}

// Medication Dosing Calculator
require_once FISHOTEL_THEME_DIR . '/inc/calculator/init.php';
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

// ─────────────────────────────────────────
// INCLUDE MODULES
// ─────────────────────────────────────────
$includes = [
	'/inc/template-functions.php',
	'/inc/template-tags.php',
	'/inc/customizer.php',
	'/inc/woocommerce.php',
	'/inc/hotel-data.php',       // FisHotel quarantine meta
	'/inc/hotel-journal.php',    // Quarantine journal entries
	'/inc/variation-display.php', // Visual variation buttons
	'/inc/widgets.php',
	'/inc/admin-settings.php',   // FisHotel admin settings page
	'/inc/contact-form.php',     // Native /contacts/ form handler
	'/inc/compatibility-guide-bootstrap.php',    // /compatibility-guide/ template assignment
	'/inc/compatibility-guide-data.php',         // JSON loaders + volume modifier helpers
	'/inc/compatibility-guide-enqueue.php',      // Conditional CSS/JS for the guide
	'/inc/compatibility-guide-product-meta.php', // WC product → matrix category meta box
	'/inc/compatibility-guide-rest.php',         // REST endpoint for inventory panel
	'/inc/compatibility-guide-backfill.php',     // Admin backfill tool for product → category meta
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
