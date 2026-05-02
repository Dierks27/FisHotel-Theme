<?php
/**
 * Compatibility Guide — REST endpoint for the inventory panel.
 *
 * GET /wp-json/fishotel/v1/compat-products
 *   ?categories[]=tangs_zebrasoma&categories[]=clownfish&limit=20
 *
 * Returns in-stock products whose _fishotel_compat_category meta lands in
 * the supplied list. Public read-only (no auth) since the data is the same
 * shop-front anyone could browse manually.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'fishotel_compat_register_rest' );
function fishotel_compat_register_rest() {
	register_rest_route( 'fishotel/v1', '/compat-products', [
		'methods'             => 'GET',
		'callback'            => 'fishotel_compat_rest_products',
		'permission_callback' => '__return_true',
		'args'                => [
			'categories' => [
				'required'          => true,
				'type'              => 'array',
				'items'             => [ 'type' => 'string' ],
				'sanitize_callback' => function ( $val ) {
					if ( ! is_array( $val ) ) {
						$val = is_string( $val ) ? explode( ',', $val ) : [];
					}
					$out = [];
					foreach ( $val as $v ) {
						$k = sanitize_key( (string) $v );
						if ( $k !== '' ) {
							$out[] = $k;
						}
					}
					return array_values( array_unique( $out ) );
				},
			],
			'limit' => [
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			],
		],
	] );
}

function fishotel_compat_rest_products( WP_REST_Request $request ) {
	$categories = (array) $request->get_param( 'categories' );
	$limit      = (int) $request->get_param( 'limit' );
	if ( $limit < 1 || $limit > 100 ) {
		$limit = 20;
	}
	if ( empty( $categories ) ) {
		return new WP_REST_Response( [], 200 );
	}

	$args = [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => $limit,
		'no_found_rows'  => true,
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
		'meta_query'     => [
			'relation' => 'AND',
			[
				'key'     => '_fishotel_compat_category',
				'value'   => $categories,
				'compare' => 'IN',
			],
			[
				'key'     => '_stock_status',
				'value'   => 'instock',
				'compare' => '=',
			],
		],
	];

	$query = new WP_Query( $args );

	// Build category-key → label map once.
	$cat_lookup = [];
	if ( function_exists( 'fishotel_compat_load_data' ) ) {
		$cats_data = (array) fishotel_compat_load_data( 'categories' );
		foreach ( $cats_data as $c ) {
			if ( ! empty( $c['key'] ) && ! empty( $c['name'] ) ) {
				$cat_lookup[ $c['key'] ] = $c['name'];
			}
		}
	}

	$out = [];
	foreach ( $query->posts as $post ) {
		$product   = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
		$cat_key   = (string) get_post_meta( $post->ID, '_fishotel_compat_category', true );
		$thumb_id  = get_post_thumbnail_id( $post->ID );
		$image_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';

		$out[] = [
			'id'             => (int) $post->ID,
			'name'           => get_the_title( $post ),
			'slug'           => $post->post_name,
			'category_key'   => $cat_key,
			'category_label' => isset( $cat_lookup[ $cat_key ] ) ? $cat_lookup[ $cat_key ] : '',
			'price_html'     => $product ? $product->get_price_html() : '',
			'stock_status'   => $product ? $product->get_stock_status() : 'instock',
			'image_url'      => $image_url ?: '',
			'permalink'      => get_permalink( $post ),
		];
	}

	return new WP_REST_Response( $out, 200 );
}
