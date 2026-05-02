<?php
/**
 * Compatibility Guide — conditional asset enqueue.
 *
 * Loads CSS, JS, and the Playfair / Josefin font pair only when the page
 * is actually using the compatibility-guide template. Localizes the data
 * URLs (with filemtime cache-busting) so the JS can fetch them lazily.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

function fishotel_compat_enqueue() {
	if ( ! is_page_template( 'page-compatibility-guide.php' ) ) {
		return;
	}

	$css_path = FISHOTEL_THEME_DIR . '/assets/css/compatibility-guide.css';
	$js_path  = FISHOTEL_THEME_DIR . '/assets/js/compatibility-guide.js';
	$css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : FISHOTEL_THEME_VERSION;
	$js_ver   = file_exists( $js_path )  ? filemtime( $js_path )  : FISHOTEL_THEME_VERSION;

	wp_enqueue_style(
		'fishotel-compat-fonts',
		'https://fonts.googleapis.com/css2?family=Josefin+Sans:wght@300;400;500;600&family=Playfair+Display:ital,wght@0,400;0,600;0,700;0,800;1,400&display=swap',
		[],
		null
	);
	wp_enqueue_style(
		'fishotel-compat',
		FISHOTEL_THEME_URI . '/assets/css/compatibility-guide.css',
		[ 'fishotel-style', 'fishotel-compat-fonts' ],
		$css_ver
	);

	wp_enqueue_script(
		'fishotel-compat',
		FISHOTEL_THEME_URI . '/assets/js/compatibility-guide.js',
		[],
		$js_ver,
		true
	);

	wp_localize_script( 'fishotel-compat', 'fishotelCompat', [
		'urls' => [
			'categories'   => fishotel_compat_data_url( 'categories' ),
			'matrix'       => fishotel_compat_data_url( 'matrix' ),
			'cirrhilabrus' => fishotel_compat_data_url( 'cirrhilabrus' ),
			'species'      => fishotel_compat_data_url( 'species' ),
			'sampleTanks'  => fishotel_compat_data_url( 'sample-tanks' ),
		],
		'verdictLabels' => [
			'C' => 'Compatible',
			'W' => 'Watch',
			'O' => 'Order matters',
			'1' => 'Single only',
			'N' => 'Not recommended',
		],
		'storageKey' => 'fishotel_tank_state_v1',
	] );
}
add_action( 'wp_enqueue_scripts', 'fishotel_compat_enqueue' );
