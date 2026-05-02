<?php
/**
 * Compatibility Guide — page bootstrap.
 *
 * Forces page id 6512 onto the page-compatibility-guide.php template so the
 * conditional CSS/JS enqueue actually fires. The page already exists in the
 * DB on the default template; running this on admin_init + after_switch_theme
 * keeps the assignment durable across deploys (we hit this exact symptom on
 * Contacts and Newsletter — bootstrap that only set the template on insert
 * never ran for pre-existing pages).
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

function fishotel_bootstrap_compat_template() {
	$page_id = 6512;
	if ( ! get_post( $page_id ) ) {
		return; // page deleted — nothing to assign
	}
	$current = get_post_meta( $page_id, '_wp_page_template', true );
	if ( $current !== 'page-compatibility-guide.php' ) {
		update_post_meta( $page_id, '_wp_page_template', 'page-compatibility-guide.php' );
	}
}
add_action( 'after_switch_theme', 'fishotel_bootstrap_compat_template' );
add_action( 'admin_init',         'fishotel_bootstrap_compat_template' );
