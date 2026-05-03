<?php
/**
 * Bleach Calculator — conditional asset enqueue.
 *
 * Loads CSS, JS, and the Playfair font only when the page is using the
 * page-bleach-calculator.php template. Uses filemtime() cache-busting via
 * fishotel_asset_version() so disk changes invalidate the browser cache.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

function fishotel_bleach_enqueue() {
	if ( ! is_page_template( 'page-bleach-calculator.php' ) ) {
		return;
	}

	wp_enqueue_style(
		'fishotel-bleach-fonts',
		'https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'fishotel-bleach',
		FISHOTEL_THEME_URI . '/assets/css/bleach-calculator.css',
		array( 'fishotel-style', 'fishotel-bleach-fonts' ),
		fishotel_asset_version( 'assets/css/bleach-calculator.css' )
	);

	wp_enqueue_script(
		'fishotel-bleach',
		FISHOTEL_THEME_URI . '/assets/js/bleach-calculator.js',
		array(),
		fishotel_asset_version( 'assets/js/bleach-calculator.js' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'fishotel_bleach_enqueue' );
