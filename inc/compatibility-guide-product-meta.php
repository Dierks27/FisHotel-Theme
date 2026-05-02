<?php
/**
 * Compatibility Guide — WC product → category mapping meta box.
 *
 * Adds an admin-side "Compatibility Category" select on the product edit
 * screen. The selected category key is stored in _fishotel_compat_category
 * post meta and is what the front-end will use to map a Woo product to
 * the compatibility matrix in v2 (browse-from-store flow). v1 reads it
 * but doesn't surface it on the front-end yet.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

function fishotel_compat_register_product_meta() {
	add_meta_box(
		'fishotel-compat-category',
		'Compatibility Category',
		'fishotel_compat_render_product_meta',
		'product',
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'fishotel_compat_register_product_meta' );

function fishotel_compat_render_product_meta( $post ) {
	wp_nonce_field( 'fishotel_compat_meta_save', 'fishotel_compat_meta_nonce' );
	$current = (string) get_post_meta( $post->ID, '_fishotel_compat_category', true );

	$categories = function_exists( 'fishotel_compat_load_data' )
		? (array) fishotel_compat_load_data( 'categories' )
		: [];

	echo '<p style="margin-top:0;">Maps this product to a row/column of the compatibility matrix.</p>';
	echo '<select name="fishotel_compat_category" style="width:100%;">';
	echo '<option value="">— None —</option>';
	foreach ( $categories as $cat ) {
		if ( empty( $cat['key'] ) || empty( $cat['name'] ) ) {
			continue;
		}
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( $cat['key'] ),
			selected( $current, $cat['key'], false ),
			esc_html( $cat['name'] )
		);
	}
	echo '</select>';
}

function fishotel_compat_save_product_meta( $post_id ) {
	if ( ! isset( $_POST['fishotel_compat_meta_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['fishotel_compat_meta_nonce'], 'fishotel_compat_meta_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$value = isset( $_POST['fishotel_compat_category'] ) ? sanitize_text_field( wp_unslash( $_POST['fishotel_compat_category'] ) ) : '';
	if ( $value === '' ) {
		delete_post_meta( $post_id, '_fishotel_compat_category' );
	} else {
		update_post_meta( $post_id, '_fishotel_compat_category', $value );
	}
}
add_action( 'save_post_product', 'fishotel_compat_save_product_meta' );
