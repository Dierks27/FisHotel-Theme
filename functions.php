<?php
/**
 * FisHotel Theme — functions.php
 *
 * @package FisHotel
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

define( 'FISHOTEL_THEME_VERSION', '1.0.0' );
define( 'FISHOTEL_THEME_DIR', get_template_directory() );
define( 'FISHOTEL_THEME_URI', get_template_directory_uri() );

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
		'https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,300;1,400&family=Roboto+Slab:wght@300;400;700&display=swap',
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
