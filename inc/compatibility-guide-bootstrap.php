<?php
/**
 * Compatibility Guide — page bootstrap.
 *
 * Ensures /compatibility-guide/ exists and is bound to the
 * page-compatibility-guide.php template so the conditional CSS/JS enqueue
 * actually fires. Binds by SLUG through the shared helper (same pattern as
 * about-us / contacts / newsletter / qt-scheduler) instead of the literal
 * post id 6512 the page happened to have — a hard-coded id silently did
 * nothing the day that row disappeared. Running this on admin_init +
 * after_switch_theme keeps the assignment durable across deploys (we hit
 * this exact symptom on Contacts and Newsletter — bootstrap that only set
 * the template on insert never ran for pre-existing pages).
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

function fishotel_bootstrap_compat_template() {
	// The helper lives in functions.php (compiled before this include runs,
	// and both hooks fire long after). Guarded anyway so this file stands
	// on its own. The once-per-version admin_init gate lives in the helper.
	if ( ! function_exists( 'fishotel_bootstrap_template_page' ) ) {
		return;
	}
	fishotel_bootstrap_template_page( 'compatibility-guide', 'page-compatibility-guide.php', 'Compatibility Guide' );
}
add_action( 'after_switch_theme', 'fishotel_bootstrap_compat_template' );
add_action( 'admin_init',         'fishotel_bootstrap_compat_template' );
