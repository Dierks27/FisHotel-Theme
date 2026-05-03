<?php
/**
 * Bleach Calculator — page bootstrap.
 *
 * Ensures /bleach-calculator/ exists and is bound to the
 * page-bleach-calculator.php template. Idempotent on every admin_init —
 * same durable pattern used by compat-guide / about-us / contacts /
 * newsletter after the fix audit.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

function fishotel_bootstrap_bleach_page() {
	$slug     = 'bleach-calculator';
	$template = 'page-bleach-calculator.php';
	$title    = 'Bleach-Out Calculator';

	$page = get_page_by_path( $slug );
	if ( ! $page ) {
		$page_id = wp_insert_post( array(
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_status'  => 'draft',
			'post_type'    => 'page',
			'post_content' => '',
		) );
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
add_action( 'after_switch_theme', 'fishotel_bootstrap_bleach_page' );
add_action( 'admin_init',         'fishotel_bootstrap_bleach_page' );
