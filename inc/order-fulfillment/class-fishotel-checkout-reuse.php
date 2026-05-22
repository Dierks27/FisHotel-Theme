<?php
/**
 * FisHotel Checkout Shipping Reuse — Phase 3.
 *
 * Prevents a customer from paying shipping twice. When someone places a new
 * order while they already have an unshipped order or fulfillment going to
 * the SAME address, we zero the new order's shipping (Phase 2.5 then folds
 * it into one fulfillment on payment, so it ships as one box). Different
 * address → shipping charges normally with a soft heads-up.
 *
 * No auto-refund anywhere: anything that slips through is caught by the
 * bright-flag fallback on the fulfillment (see FisHotel_Fulfillment), which
 * is a manual, admin-confirmed refund.
 *
 * Implementation note: shipping is zeroed via the woocommerce_package_rates
 * filter (the reliable, persistent way to drop a shipping charge), not by
 * writing WC()->cart->shipping_total in a fee hook — that value is
 * recalculated downstream and wouldn't stick.
 *
 * Kill switch: define FISHOTEL_CHECKOUT_REUSE_OFF, or set the option
 * fishotel_checkout_reuse = '0', to bypass entirely.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Checkout_Reuse {

	public static function init() {
		// Vary the shipping-package hash by our reuse state so WC re-runs rate
		// calculation (and our zeroing) when the customer's situation flips,
		// instead of serving a stale cached rate.
		add_filter( 'woocommerce_cart_shipping_packages', [ __CLASS__, 'tag_packages' ] );
		// Zero the shipping rates when reuse applies (priority 100 so we run
		// after methods/free-shipping have populated the rate list).
		add_filter( 'woocommerce_package_rates', [ __CLASS__, 'maybe_zero_shipping' ], 100, 2 );
		// Surface the combine / different-address notice.
		add_action( 'woocommerce_cart_calculate_fees', [ __CLASS__, 'maybe_notice' ], 20 );
		// Themed styling for the combine notice.
		add_action( 'wp_head', [ __CLASS__, 'notice_css' ] );
	}

	/** True when the feature is switched off via constant or option. */
	private static function disabled() {
		if ( defined( 'FISHOTEL_CHECKOUT_REUSE_OFF' ) && FISHOTEL_CHECKOUT_REUSE_OFF ) {
			return true;
		}
		return '0' === (string) get_option( 'fishotel_checkout_reuse', '1' );
	}

	/**
	 * Resolve the reuse state for a destination.
	 *
	 * @param array|null $destination WC package destination, or null to read
	 *                                the current customer's shipping address.
	 * @return array{state:string,order:?WC_Order} state = match|differ|none
	 */
	private static function reuse_state( $destination = null ) {
		static $cache = [];

		if ( self::disabled() || ! function_exists( 'WC' ) || ! WC() || ! WC()->customer ) {
			return [ 'state' => 'none', 'order' => null ];
		}

		$customer = WC()->customer;
		if ( null === $destination ) {
			$destination = [
				'address'  => $customer->get_shipping_address_1(),
				'city'     => $customer->get_shipping_city(),
				'state'    => $customer->get_shipping_state(),
				'postcode' => $customer->get_shipping_postcode(),
				'country'  => $customer->get_shipping_country(),
			];
		}

		$customer_id = (int) $customer->get_id();
		$email       = strtolower( trim( (string) $customer->get_billing_email() ) );
		$dest_hash   = self::normalize_address( $destination );
		$key         = $customer_id . '|' . $email . '|' . $dest_hash;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$result = [ 'state' => 'none', 'order' => null ];

		$existing = self::existing_unshipped_orders( $customer_id, $email );
		if ( empty( $existing ) ) {
			$cache[ $key ] = $result;
			return $result;
		}

		$found_differ = null;
		foreach ( $existing as $order ) {
			$order_hash = self::normalize_address( [
				'address'  => $order->get_shipping_address_1(),
				'city'     => $order->get_shipping_city(),
				'state'    => $order->get_shipping_state(),
				'postcode' => $order->get_shipping_postcode(),
				'country'  => $order->get_shipping_country(),
			] );
			if ( '' !== $dest_hash && $order_hash === $dest_hash ) {
				$result = [ 'state' => 'match', 'order' => $order ];
				$cache[ $key ] = $result;
				return $result;
			}
			if ( null === $found_differ ) {
				$found_differ = $order;
			}
		}

		// Existing orders, but none to this destination.
		$result = [ 'state' => 'differ', 'order' => $found_differ ];
		$cache[ $key ] = $result;
		return $result;
	}

	/**
	 * Existing unshipped orders/fulfillments for this customer (processing or
	 * fulfillment status). The current cart's draft order is not in these
	 * statuses, so it's naturally excluded.
	 *
	 * @return WC_Order[]
	 */
	private static function existing_unshipped_orders( $customer_id, $email ) {
		$base = [
			'limit'   => 10,
			'status'  => [ 'wc-processing', 'wc-fulfillment' ],
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'ids',
		];

		$found = [];
		if ( $customer_id > 0 ) {
			foreach ( (array) wc_get_orders( $base + [ 'customer_id' => $customer_id ] ) as $id ) {
				$found[ (int) $id ] = true;
			}
		}
		if ( '' !== $email ) {
			foreach ( (array) wc_get_orders( $base + [ 'billing_email' => $email ] ) as $id ) {
				$found[ (int) $id ] = true;
			}
		}

		$orders = [];
		foreach ( array_keys( $found ) as $id ) {
			$o = wc_get_order( $id );
			if ( $o instanceof WC_Order ) {
				$orders[] = $o;
			}
		}
		return $orders;
	}

	/** Normalize an address into a comparable lowercase string. */
	private static function normalize_address( array $a ) {
		$parts = [
			$a['address'] ?? '',
			$a['city'] ?? '',
			$a['state'] ?? '',
			$a['postcode'] ?? '',
			$a['country'] ?? '',
		];
		$norm = array_map( static function ( $p ) {
			return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $p ) ) );
		}, $parts );
		// Empty destination (no address yet) → '' so it never matches.
		if ( '' === implode( '', $norm ) ) {
			return '';
		}
		return implode( '|', $norm );
	}

	/** Tag each shipping package with the reuse state (varies the rate cache). */
	public static function tag_packages( $packages ) {
		if ( self::disabled() ) {
			return $packages;
		}
		foreach ( $packages as $i => $package ) {
			$dest = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : null;
			$state = self::reuse_state( $dest );
			$packages[ $i ]['fishotel_reuse'] = $state['state'];
		}
		return $packages;
	}

	/** Zero every shipping rate for the package when reuse matches. */
	public static function maybe_zero_shipping( $rates, $package ) {
		if ( self::disabled() ) {
			return $rates;
		}
		$destination = isset( $package['destination'] ) && is_array( $package['destination'] ) ? $package['destination'] : null;
		$state       = self::reuse_state( $destination );
		if ( 'match' !== $state['state'] ) {
			return $rates;
		}
		foreach ( $rates as $rate ) {
			if ( $rate instanceof WC_Shipping_Rate ) {
				$rate->set_cost( 0 );
				$rate->set_taxes( [] );
			}
		}
		return $rates;
	}

	/** Add the combine / different-address notice at cart + checkout. */
	public static function maybe_notice() {
		if ( self::disabled() || ! function_exists( 'wc_add_notice' ) ) {
			return;
		}
		// Only on the customer-facing cart/checkout, not admin/REST recalcs.
		if ( ! ( ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_cart' ) && is_cart() ) ) ) {
			return;
		}

		$state = self::reuse_state();
		if ( 'match' === $state['state'] ) {
			$num = $state['order'] instanceof WC_Order ? $state['order']->get_order_number() : '';
			$msg = '<span class="fishotel-combine-notice">' . sprintf(
				/* translators: %s = existing order number */
				esc_html__( '📦 Shipping combined. You have an unshipped order (#%s) shipping to the same address. We’ll ship both together — shipping charge removed.', 'fishotel' ),
				esc_html( $num )
			) . '</span>';
			self::add_notice_once( $msg, 'notice' );
		} elseif ( 'differ' === $state['state'] ) {
			self::add_notice_once(
				esc_html__( 'You have an unshipped order shipping to a different address. Shipping applies normally on this order.', 'fishotel' ),
				'notice'
			);
		}
	}

	/** Add a notice only if an identical one isn't already queued. */
	private static function add_notice_once( $msg, $type = 'notice' ) {
		if ( function_exists( 'wc_get_notices' ) ) {
			foreach ( (array) wc_get_notices( $type ) as $n ) {
				$existing = is_array( $n ) ? ( $n['notice'] ?? '' ) : $n;
				if ( $existing === $msg ) {
					return;
				}
			}
		}
		wc_add_notice( $msg, $type );
	}

	/** Minimal FisHotel theming for the combine notice. */
	public static function notice_css() {
		if ( ! ( ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_cart' ) && is_cart() ) ) ) {
			return;
		}
		echo '<style>.fishotel-combine-notice{display:block;color:#d4a574;font-weight:600;}</style>';
	}
}
