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
		FISHOTEL_THEME_VERSION
	);

	// WooCommerce override styles
	if ( class_exists( 'WooCommerce' ) ) {
		wp_enqueue_style(
			'fishotel-woocommerce',
			FISHOTEL_THEME_URI . '/assets/css/woocommerce.css',
			[ 'fishotel-style' ],
			FISHOTEL_THEME_VERSION
		);
	}

	// Main JS
	wp_enqueue_script(
		'fishotel-main',
		FISHOTEL_THEME_URI . '/assets/js/main.js',
		[ 'jquery' ],
		FISHOTEL_THEME_VERSION,
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
            FISHOTEL_THEME_VERSION
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
            FISHOTEL_THEME_VERSION
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
            FISHOTEL_THEME_VERSION
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
            FISHOTEL_THEME_VERSION
        );
    }
}
add_action( 'wp_enqueue_scripts', 'fishotel_enqueue_about_assets' );

/**
 * Bootstrap the About page once.
 *
 * Runs at admin_init, idempotent via the fh_about_page_bootstrapped option.
 * Creates /about-us/ as a Draft with the "About — Founder's Edition" template
 * assigned. Will not touch the page on subsequent runs, even if it's been
 * deleted — the option flag prevents re-creation. To re-bootstrap, delete the
 * fh_about_page_bootstrapped option.
 */
function fishotel_bootstrap_about_page() {
    if ( get_option( 'fh_about_page_bootstrapped' ) ) {
        return;
    }
    $existing = get_page_by_path( 'about-us' );
    if ( ! $existing ) {
        $page_id = wp_insert_post( [
            'post_title'   => 'About Us',
            'post_name'    => 'about-us',
            'post_status'  => 'draft',
            'post_type'    => 'page',
            'post_content' => '', // Body content lives in FisHotel Settings.
        ] );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_post_meta( $page_id, '_wp_page_template', 'page-about.php' );
        }
    }
    update_option( 'fh_about_page_bootstrapped', 1, false );
}
add_action( 'admin_init', 'fishotel_bootstrap_about_page' );

/**
 * Bootstrap the Contacts page once.
 *
 * Idempotent via fh_contacts_page_bootstrapped. Creates /contacts/ as a
 * Draft with the Contacts template assigned, only if no page exists at
 * that slug. To re-bootstrap, delete the option.
 */
function fishotel_bootstrap_contacts_page() {
    if ( get_option( 'fh_contacts_page_bootstrapped' ) ) {
        return;
    }
    $existing = get_page_by_path( 'contacts' );
    if ( ! $existing ) {
        $page_id = wp_insert_post( [
            'post_title'   => 'Contacts',
            'post_name'    => 'contacts',
            'post_status'  => 'draft',
            'post_type'    => 'page',
            'post_content' => '',
        ] );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_post_meta( $page_id, '_wp_page_template', 'page-contacts.php' );
        }
    }
    update_option( 'fh_contacts_page_bootstrapped', 1, false );
}
add_action( 'admin_init', 'fishotel_bootstrap_contacts_page' );

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
