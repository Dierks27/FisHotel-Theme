<?php
/**
 * EA REST API client — Basic Auth against EverythingAquatic's WC REST.
 *
 * Spec §2 → "Use Basic Auth header on REST requests
 * (consumer_key:consumer_secret base64-encoded), not OAuth."
 *
 * All requests go through do_request(); we do NOT log credentials,
 * URLs, or response bodies anywhere persistent. Errors surface to the
 * caller as WP_Error so the admin notice can show them without leaking
 * the key.
 *
 * ─── PHASE 1.6 STATUS — NOT CURRENTLY USED ──────────────────────────
 * EA's WC REST API is wholesale-role-gated for Jeff's consumer key.
 * Every list endpoint returns 403 ("woocommerce_rest_cannot_view"),
 * and product IDs are not discoverable from any public surface, so
 * single-resource fetches can't be used either. See
 * /docs/specs/ea-api-status.md for the empirical test matrix.
 *
 * fetch_by_slug(), fetch_variations(), fetch_product(), and
 * extract_wholesale() are kept in place — cheap insurance against
 * having to rewrite the auth + HTTP plumbing if EA ever opens the
 * gate — but nothing in the medication-store module calls them as
 * of Phase 1.6.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_EA_REST {

	/** Default request timeout in seconds. */
	const TIMEOUT = 15;

	/**
	 * Build the Basic Auth header value from stored credentials.
	 * Returns '' when either credential is missing.
	 */
	protected static function auth_header() {
		$key    = (string) get_option( 'fishotel_ea_consumer_key', '' );
		$secret = (string) get_option( 'fishotel_ea_consumer_secret', '' );
		if ( $key === '' || $secret === '' ) {
			return '';
		}
		return 'Basic ' . base64_encode( $key . ':' . $secret );
	}

	/** Resolve the EA store base URL with no trailing slash. */
	protected static function base_url() {
		$url = (string) FisHotel_Med_Settings::get( 'fishotel_ea_store_url' );
		return untrailingslashit( $url );
	}

	/**
	 * Low-level GET against the EA REST API. Centralizes auth + HTTP
	 * + error normalization so every wrapper method gets the same
	 * credential-leak-safe behavior. Returns the decoded JSON body
	 * (array) on success, or WP_Error on failure.
	 *
	 * @param string $path Path relative to /wp-json/, e.g.
	 *                     'wc/v3/products?slug=foo'.
	 * @return array|WP_Error
	 */
	protected static function do_request( $path ) {
		$auth = self::auth_header();
		if ( $auth === '' ) {
			return new WP_Error( 'fishotel_ea_no_credentials', 'EA Consumer Key or Secret is not set.' );
		}
		$base = self::base_url();
		if ( $base === '' ) {
			return new WP_Error( 'fishotel_ea_no_store_url', 'EA Store URL is not set.' );
		}

		$url = $base . '/wp-json/' . ltrim( $path, '/' );

		$res = wp_safe_remote_get( $url, [
			'timeout'     => self::TIMEOUT,
			'redirection' => 2,
			'headers'     => [
				'Accept'        => 'application/json',
				'Authorization' => $auth,
				'User-Agent'    => 'FisHotel/' . FISHOTEL_THEME_VERSION . ' (+https://fishotel.com)',
			],
		] );
		if ( is_wp_error( $res ) ) {
			// Discard the underlying message so the credential's URL
			// can't surface in transient logs; only the code is kept.
			return new WP_Error( 'fishotel_ea_http', 'EA API request failed (network).' );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );

		if ( $code === 401 || $code === 403 ) {
			return new WP_Error( 'fishotel_ea_auth', 'EA API rejected credentials (401/403).' );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'fishotel_ea_status', sprintf( 'EA API returned HTTP %d.', $code ) );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'fishotel_ea_parse', 'EA API returned malformed JSON.' );
		}
		return $data;
	}

	/**
	 * Fetch a single product from EA.
	 *
	 * @param int    $product_id EA WC product ID (preferred).
	 * @param string $sku        EA SKU fallback when ID unknown.
	 * @return array|WP_Error  Parsed product payload, or WP_Error on failure.
	 */
	public static function fetch_product( $product_id = 0, $sku = '' ) {
		$product_id = (int) $product_id;
		$sku        = trim( (string) $sku );

		if ( $product_id > 0 ) {
			$path = 'wc/v3/products/' . $product_id;
			return self::do_request( $path );
		}
		if ( $sku !== '' ) {
			$path = 'wc/v3/products?sku=' . rawurlencode( $sku );
			$data = self::do_request( $path );
			if ( is_wp_error( $data ) ) return $data;
			if ( empty( $data ) ) {
				return new WP_Error( 'fishotel_ea_not_found', 'No EA product matched that SKU.' );
			}
			return $data[0];
		}
		return new WP_Error( 'fishotel_ea_no_lookup_key', 'Provide either an EA product ID or SKU.' );
	}

	/**
	 * Fetch a single product by slug. The CLI importer's primary entry
	 * point since the spec's "Source data" table provides EA URL slugs,
	 * not numeric IDs.
	 *
	 * @return array|WP_Error
	 */
	public static function fetch_by_slug( $slug ) {
		$slug = trim( (string) $slug );
		if ( $slug === '' ) {
			return new WP_Error( 'fishotel_ea_no_slug', 'Slug is required.' );
		}
		$data = self::do_request( 'wc/v3/products?slug=' . rawurlencode( $slug ) );
		if ( is_wp_error( $data ) ) return $data;
		if ( empty( $data ) ) {
			return new WP_Error( 'fishotel_ea_not_found', sprintf( 'No EA product matched slug "%s".', $slug ) );
		}
		return $data[0];
	}

	/**
	 * Fetch all variations for an EA parent product. Pulls up to 100
	 * per page — adequate for Phase 1's sizing (max ~12 sizes per product).
	 *
	 * @return array|WP_Error  List of variation payloads.
	 */
	public static function fetch_variations( $parent_id ) {
		$parent_id = (int) $parent_id;
		if ( $parent_id <= 0 ) {
			return new WP_Error( 'fishotel_ea_no_parent', 'Parent product ID is required.' );
		}
		$data = self::do_request( 'wc/v3/products/' . $parent_id . '/variations?per_page=100' );
		if ( is_wp_error( $data ) ) return $data;
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Best-effort wholesale-price extraction from an EA product or
	 * variation payload. Walks the meta_data array looking for the
	 * common wholesale-plugin keys; falls back to the response's
	 * regular_price only if the caller forces it (the importer's
	 * range-based fallback is preferred when nothing wholesale-like
	 * is found, to avoid silently using retail-as-wholesale).
	 *
	 * @return float|null  Wholesale price, or null if not detected.
	 */
	public static function extract_wholesale( $payload ) {
		if ( ! is_array( $payload ) ) return null;
		$candidates = [
			'_wholesale_price',
			'wholesale_price',
			'_wholesale_customer_price',
			'wholesale_customer_wholesale_price',
			'_wcwl_wholesale_price',
			'wcwl_wholesale_price',
			'_wholesale_role_price',
		];
		if ( ! empty( $payload['meta_data'] ) && is_array( $payload['meta_data'] ) ) {
			foreach ( $payload['meta_data'] as $meta ) {
				if ( ! is_array( $meta ) ) continue;
				$key   = isset( $meta['key'] )   ? (string) $meta['key']   : '';
				$value = isset( $meta['value'] ) ? $meta['value']          : '';
				if ( $key === '' || $value === '' ) continue;
				if ( in_array( $key, $candidates, true ) && is_numeric( $value ) && (float) $value > 0 ) {
					return (float) $value;
				}
			}
		}
		// Some wholesale plugins expose a `wholesale_prices` array keyed
		// by role; pick the first numeric entry.
		if ( ! empty( $payload['wholesale_prices'] ) && is_array( $payload['wholesale_prices'] ) ) {
			foreach ( $payload['wholesale_prices'] as $price ) {
				if ( is_numeric( $price ) && (float) $price > 0 ) {
					return (float) $price;
				}
			}
		}
		return null;
	}

	/**
	 * Normalize a fetched product's stock data into our local format.
	 *
	 *   stock_status: 'instock' | 'outofstock' | 'onbackorder' | ''
	 *   stock_quantity: int|null
	 *
	 * @param array $product EA REST product payload.
	 * @return array { status:string, quantity:int|null }
	 */
	public static function extract_stock( $product ) {
		$status   = isset( $product['stock_status'] )   ? (string) $product['stock_status']   : '';
		$quantity = isset( $product['stock_quantity'] ) ? $product['stock_quantity']          : null;
		if ( ! in_array( $status, [ 'instock', 'outofstock', 'onbackorder' ], true ) ) {
			$status = '';
		}
		if ( $quantity !== null && ! is_numeric( $quantity ) ) {
			$quantity = null;
		}
		return [
			'status'   => $status,
			'quantity' => ( $quantity === null ) ? null : (int) $quantity,
		];
	}
}
