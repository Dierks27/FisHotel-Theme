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
 * Shipping-class guard: a cart may only piggyback (get $0 shipping) onto a
 * source order that is ALREADY shipping at a tier able to carry it. Live fish
 * ship overnight in their own class (see fishotel_overnight_shipping_classes,
 * default `premium`). A ground-only cart rides anything; a cart with any
 * overnight item can only piggyback a source that already ships overnight.
 * An overnight cart piggybacking a ground-only source resolves to the
 * `incompatible` state — shipping is charged normally and no piggyback meta is
 * written. Fail-safe direction: if a source item's product/class can't be
 * determined, that order is treated as ground-only (charge the fish order —
 * undercharging is the bug; an overcharge is refundable).
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
		// Mark the piggyback relationship on the saved order (Piece 2b).
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'mark_piggyback' ], 30, 2 );
	}

	/** True when the feature is switched off via constant or option. */
	private static function disabled() {
		if ( defined( 'FISHOTEL_CHECKOUT_REUSE_OFF' ) && FISHOTEL_CHECKOUT_REUSE_OFF ) {
			return true;
		}
		// Unified re-order kill switch also turns the piggyback off (Piece 2a/2b).
		if ( defined( 'FISHOTEL_REORDER_CHECKOUT_LOCK_OFF' ) && FISHOTEL_REORDER_CHECKOUT_LOCK_OFF ) {
			return true;
		}
		return '0' === (string) get_option( 'fishotel_checkout_reuse', '1' );
	}

	// ─────────────────────────────────────────
	// Shipping-class compatibility guard
	// ─────────────────────────────────────────

	/**
	 * Overnight shipping-class slugs. Option-backed
	 * (fishotel_overnight_shipping_classes, default `premium`) so the site
	 * principle of "no hardcoded slugs in logic" holds and future overnight
	 * classes can be added without a code change. Accepts a comma/space/
	 * newline-separated string or an array; compared lowercased.
	 *
	 * @return string[]
	 */
	private static function overnight_classes() {
		$raw  = get_option( 'fishotel_overnight_shipping_classes', 'premium' );
		$list = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
		$list = array_filter( array_map( static function ( $slug ) {
			return strtolower( trim( (string) $slug ) );
		}, (array) $list ) );
		$list = array_values( array_unique( $list ) );
		// Empty option → fall back to the default so the guard is never silently
		// disabled (the kill switch is the intended way to turn the feature off).
		return $list ? $list : [ 'premium' ];
	}

	/**
	 * True when any current cart line ships in an overnight class. Reads the
	 * shipping class off the cart item's product object so a variation resolves
	 * to its own class (or the parent's when not overridden).
	 *
	 * @return bool
	 */
	private static function cart_requires_overnight() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return false;
		}
		$overnight = self::overnight_classes();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			if ( in_array( strtolower( (string) $product->get_shipping_class() ), $overnight, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when the given order already ships at least one item in an overnight
	 * class — i.e. it is a box that can host an overnight cart for free.
	 *
	 * Fail-safe: if an item's product is deleted or its class can't be read, the
	 * item is skipped, so an order whose classes are all indeterminate reads as
	 * ground-only (the new fish order then gets charged, not undercharged).
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	public static function order_can_host_overnight( WC_Order $order ) {
		$overnight = self::overnight_classes();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			if ( in_array( strtolower( (string) $product->get_shipping_class() ), $overnight, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Shared compatibility predicate: may the current cart piggyback for free
	 * onto this source order? A ground-only cart rides anything; an overnight
	 * cart needs a source that already ships overnight. This is the single
	 * predicate every path (zeroing, meta, notice) consults, and it is only ever
	 * evaluated inside the per-request-cached reuse_state()/anchor lookups.
	 *
	 * @param WC_Order $order
	 * @return bool
	 */
	private static function order_hosts_cart( WC_Order $order ) {
		if ( ! self::cart_requires_overnight() ) {
			return true;
		}
		return self::order_can_host_overnight( $order );
	}

	/**
	 * The customer-facing source order this checkout piggybacks on. Prefers
	 * the re-order lock's anchor (always a real customer order, never a
	 * fulfillment entity); falls back to the matched reuse order.
	 *
	 * @return WC_Order|null
	 */
	private static function piggyback_source_order() {
		if ( class_exists( 'FisHotel_Reorder_Checkout_Lock' ) ) {
			$anchor = FisHotel_Reorder_Checkout_Lock::anchor_order();
			// Only host the piggyback if the anchor can carry this cart. An
			// overnight cart on a ground-only anchor must fall through so it is
			// neither zeroed nor stamped with the piggyback meta.
			if ( $anchor instanceof WC_Order && self::order_hosts_cart( $anchor ) ) {
				return $anchor;
			}
		}
		$state = self::reuse_state();
		return ( 'match' === $state['state'] && $state['order'] instanceof WC_Order ) ? $state['order'] : null;
	}

	/**
	 * The same-address open order this cart is blocked from piggybacking on
	 * because it can't host the cart's overnight items — used only for the
	 * customer notice. Mirrors piggyback_source_order(): prefers the lock anchor
	 * (whose address is forced onto this checkout), else the resolved state.
	 * Returns null whenever a free piggyback is (or could be) available.
	 *
	 * @return WC_Order|null
	 */
	private static function incompatible_host_order() {
		if ( class_exists( 'FisHotel_Reorder_Checkout_Lock' ) ) {
			$anchor = FisHotel_Reorder_Checkout_Lock::anchor_order();
			if ( $anchor instanceof WC_Order ) {
				return self::order_hosts_cart( $anchor ) ? null : $anchor;
			}
		}
		$state = self::reuse_state();
		return ( 'incompatible' === $state['state'] && $state['order'] instanceof WC_Order ) ? $state['order'] : null;
	}

	/**
	 * Resolve the reuse state for a destination.
	 *
	 * @param array|null $destination WC package destination, or null to read
	 *                                the current customer's shipping address.
	 * @return array{state:string,order:?WC_Order} state = match|incompatible|differ|none
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

		$found_differ       = null;
		$found_incompatible = null;
		foreach ( $existing as $order ) {
			$order_hash = self::normalize_address( [
				'address'  => $order->get_shipping_address_1(),
				'city'     => $order->get_shipping_city(),
				'state'    => $order->get_shipping_state(),
				'postcode' => $order->get_shipping_postcode(),
				'country'  => $order->get_shipping_country(),
			] );
			if ( '' !== $dest_hash && $order_hash === $dest_hash ) {
				// Address matches — but it may only host a free piggyback if it
				// already ships at a tier that can carry this cart.
				if ( self::order_hosts_cart( $order ) ) {
					$result = [ 'state' => 'match', 'order' => $order ];
					$cache[ $key ] = $result;
					return $result;
				}
				// Same address but can't host (e.g. ground-only order, fish
				// cart). Remember it, but keep scanning — another open order
				// (e.g. an open fish order) may still be able to host.
				if ( null === $found_incompatible ) {
					$found_incompatible = $order;
				}
				continue;
			}
			if ( null === $found_differ ) {
				$found_differ = $order;
			}
		}

		// Address-matched candidates existed but none could host this cart.
		if ( null !== $found_incompatible ) {
			$result = [ 'state' => 'incompatible', 'order' => $found_incompatible ];
			$cache[ $key ] = $result;
			return $result;
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
		$source = self::piggyback_source_order();
		$num    = $source instanceof WC_Order ? $source->get_order_number() : '';
		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof WC_Shipping_Rate ) {
				continue;
			}
			$rate->set_cost( 0 );
			$rate->set_taxes( [] );
			// Suffix the label so the $0 line reads as a piggyback, not free
			// shipping. Guard against double-append on repeat rate calcs.
			if ( '' !== $num && false === strpos( (string) $rate->get_label(), '#' . $num ) ) {
				$rate->set_label( $rate->get_label() . ' ' . sprintf(
					/* translators: %s = existing order number */
					__( '— Piggybacking on order #%s', 'fishotel' ),
					$num
				) );
			}
		}
		return $rates;
	}

	/**
	 * Record _fishotel_piggyback_of on the new order when it piggybacks on an
	 * existing open order, giving admin a visible backlink (and a hook to
	 * reverse the piggyback if the orders ultimately can't be combined).
	 */
	public static function mark_piggyback( $order, $data ) {
		if ( self::disabled() || ! $order instanceof WC_Order ) {
			return;
		}
		$source = self::piggyback_source_order();
		if ( $source instanceof WC_Order ) {
			$order->update_meta_data( '_fishotel_piggyback_of', $source->get_id() );
		}
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

		// When the checkout lock is active it forces this order's destination to
		// the open order's address (see FisHotel_Reorder_Checkout_Lock), so the
		// shipping IS combined regardless of what the customer's session address
		// reads — never show the contradictory "different address" notice.
		$source = self::piggyback_source_order();
		if ( $source instanceof WC_Order ) {
			$msg = '<span class="fishotel-combine-notice">' . sprintf(
				/* translators: %s = existing order number */
				esc_html__( '📦 Shipping combined. You have an unshipped order (#%s) shipping to the same address. We’ll ship both together — shipping charge removed.', 'fishotel' ),
				esc_html( $source->get_order_number() )
			) . '</span>';
			self::add_notice_once( $msg, 'notice' );
			return;
		}

		// Overnight cart blocked from piggybacking a ground-only open order:
		// explain why fish can't share the existing box, so the shipping charge
		// isn't a surprise.
		$incompatible = self::incompatible_host_order();
		if ( $incompatible instanceof WC_Order ) {
			self::add_notice_once(
				'<span class="fishotel-combine-notice">' . sprintf(
					/* translators: %s = existing order number */
					esc_html__( '🐠 Your live fish ship overnight in their own insulated box, so they can’t share shipping with order #%s. Shipping is charged normally on this order.', 'fishotel' ),
					esc_html( $incompatible->get_order_number() )
				) . '</span>',
				'notice'
			);
			return;
		}

		// No lock / no match: only warn when there's an open order to a
		// different address (lock off or kill switch on).
		$state = self::reuse_state();
		if ( 'differ' === $state['state'] ) {
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
