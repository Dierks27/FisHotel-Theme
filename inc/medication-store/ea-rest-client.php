<?php
/**
 * EA REST API client — Basic Auth against EverythingAquatic's WC REST.
 *
 * Spec §2 → "Use Basic Auth header on REST requests
 * (consumer_key:consumer_secret base64-encoded), not OAuth."
 *
 * All requests go through fetch_product(); we do NOT log credentials,
 * URLs, or response bodies anywhere persistent. Errors surface to the
 * caller as WP_Error so the admin notice can show them without leaking
 * the key.
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
	 * Fetch a single product from EA.
	 *
	 * @param int    $product_id EA WC product ID (preferred).
	 * @param string $sku        EA SKU fallback when ID unknown.
	 * @return array|WP_Error  Parsed product payload, or WP_Error on failure.
	 */
	public static function fetch_product( $product_id = 0, $sku = '' ) {
		$auth = self::auth_header();
		if ( $auth === '' ) {
			return new WP_Error( 'fishotel_ea_no_credentials', 'EA Consumer Key or Secret is not set.' );
		}
		$base = self::base_url();
		if ( $base === '' ) {
			return new WP_Error( 'fishotel_ea_no_store_url', 'EA Store URL is not set.' );
		}

		$product_id = (int) $product_id;
		$sku        = trim( (string) $sku );

		if ( $product_id > 0 ) {
			$url = $base . '/wp-json/wc/v3/products/' . $product_id;
		} elseif ( $sku !== '' ) {
			$url = $base . '/wp-json/wc/v3/products?sku=' . rawurlencode( $sku );
		} else {
			return new WP_Error( 'fishotel_ea_no_lookup_key', 'Provide either an EA product ID or SKU.' );
		}

		$args = [
			'timeout'     => self::TIMEOUT,
			'redirection' => 2,
			'headers'     => [
				'Accept'        => 'application/json',
				'Authorization' => $auth,
				'User-Agent'    => 'FisHotel/' . FISHOTEL_THEME_VERSION . ' (+https://fishotel.com)',
			],
		];

		$res = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $res ) ) {
			// Discard the underlying message so the credential's URL
			// can't surface in transient logs; we only need the code.
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

		// SKU search returns an array; ID lookup returns the object.
		if ( $product_id <= 0 ) {
			if ( empty( $data ) ) {
				return new WP_Error( 'fishotel_ea_not_found', 'No EA product matched that SKU.' );
			}
			return $data[0];
		}
		return $data;
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
