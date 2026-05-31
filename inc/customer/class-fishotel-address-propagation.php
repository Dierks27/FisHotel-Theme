<?php
/**
 * FisHotel My Account Address Propagation — Piece 3.
 *
 * My Account → Addresses is the source of truth for a customer's shipping
 * address. When a customer changes their shipping address there and has open
 * unshipped orders, those orders are updated to ship to the new address — so
 * a repeat customer never ends up with open orders going to two different
 * places (which would block the auto-combine and double their shipping).
 *
 * Once the checkout lock (Piece 2a) is live, the only way to get
 * mismatched-address open orders is a My Account change between orders (this
 * closes that loop) or a deliberate admin edit (left alone — the admin flag,
 * Piece 4, surfaces those).
 *
 * Kill switch: define FISHOTEL_ADDRESS_PROPAGATION_OFF.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Address_Propagation {

	public static function init() {
		// Priority 20: after WC has saved the customer's address.
		add_action( 'woocommerce_customer_save_address', [ __CLASS__, 'propagate' ], 20, 2 );
	}

	/** True when propagation is switched off. */
	public static function disabled() {
		return defined( 'FISHOTEL_ADDRESS_PROPAGATION_OFF' ) && FISHOTEL_ADDRESS_PROPAGATION_OFF;
	}

	/**
	 * Propagate a saved shipping address to the customer's open orders.
	 *
	 * @param int    $customer_id
	 * @param string $address_type 'billing' | 'shipping'
	 */
	public static function propagate( $customer_id, $address_type ) {
		if ( self::disabled() || 'shipping' !== $address_type ) {
			return;
		}
		$customer_id = (int) $customer_id;
		if ( $customer_id <= 0 || ! function_exists( 'fishotel_get_open_unshipped_orders_for_customer' ) ) {
			return;
		}

		$customer = new WC_Customer( $customer_id );
		$new      = [
			'first_name' => $customer->get_shipping_first_name(),
			'last_name'  => $customer->get_shipping_last_name(),
			'company'    => $customer->get_shipping_company(),
			'address_1'  => $customer->get_shipping_address_1(),
			'address_2'  => $customer->get_shipping_address_2(),
			'city'       => $customer->get_shipping_city(),
			'state'      => $customer->get_shipping_state(),
			'postcode'   => $customer->get_shipping_postcode(),
			'country'    => $customer->get_shipping_country(),
		];
		$new_hash = self::ship_hash_from_array( $new );

		$updated = [];
		foreach ( fishotel_get_open_unshipped_orders_for_customer( $customer_id ) as $order ) {
			// Leave partial shipments in motion alone.
			if ( function_exists( 'fishotel_order_has_shipped_line' ) && fishotel_order_has_shipped_line( $order ) ) {
				continue;
			}
			// Already shipping here → nothing to do.
			if ( self::order_ship_hash( $order ) === $new_hash ) {
				continue;
			}
			self::apply_shipping( $order, $new );
			$order->add_order_note( __( 'Shipping address updated via My Account → Addresses.', 'fishotel' ) );
			$order->save();
			$updated[] = $order->get_order_number();

			// Keep the parent fulfillment's display address consistent so the
			// physical box isn't labelled to the old destination.
			self::maybe_sync_fulfillment( $order, $new, $new_hash );
		}

		if ( $updated && function_exists( 'wc_add_notice' ) ) {
			$count = count( $updated );
			$nums  = implode( ', ', array_map( static function ( $n ) {
				return '#' . $n;
			}, $updated ) );
			wc_add_notice(
				sprintf(
					/* translators: 1: number of orders, 2: comma-separated order numbers */
					_n(
						'✓ Your shipping address has been updated. We also updated %1$d open order to ship to this new address: %2$s.',
						'✓ Your shipping address has been updated. We also updated %1$d open orders to ship to this new address: %2$s.',
						$count,
						'fishotel'
					),
					$count,
					$nums
				),
				'success'
			);
		}
	}

	/** When the source belongs to a fulfillment, mirror the address there too. */
	private static function maybe_sync_fulfillment( WC_Order $source, array $new, $new_hash ) {
		$ff_id = (int) $source->get_meta( '_fishotel_fulfilled_by_order' );
		if ( $ff_id <= 0 ) {
			return;
		}
		$ff = wc_get_order( $ff_id );
		if ( ! $ff instanceof WC_Order || self::order_ship_hash( $ff ) === $new_hash ) {
			return;
		}
		self::apply_shipping( $ff, $new );
		$ff->add_order_note( sprintf(
			/* translators: %s = source order number */
			__( 'Shipping address updated to match source order #%s (My Account change).', 'fishotel' ),
			$source->get_order_number()
		) );
		$ff->save();
	}

	/** Set an order's shipping address from a saved-address array. */
	private static function apply_shipping( WC_Order $order, array $a ) {
		$order->set_shipping_first_name( (string) ( $a['first_name'] ?? '' ) );
		$order->set_shipping_last_name( (string) ( $a['last_name'] ?? '' ) );
		$order->set_shipping_company( (string) ( $a['company'] ?? '' ) );
		$order->set_shipping_address_1( (string) ( $a['address_1'] ?? '' ) );
		$order->set_shipping_address_2( (string) ( $a['address_2'] ?? '' ) );
		$order->set_shipping_city( (string) ( $a['city'] ?? '' ) );
		$order->set_shipping_state( (string) ( $a['state'] ?? '' ) );
		$order->set_shipping_postcode( (string) ( $a['postcode'] ?? '' ) );
		$order->set_shipping_country( (string) ( $a['country'] ?? '' ) );
	}

	/** Normalized comparable hash of address parts (matches auto-combine). */
	private static function ship_hash_from_array( array $a ) {
		$parts = [
			$a['first_name'] ?? '',
			$a['last_name'] ?? '',
			$a['address_1'] ?? '',
			$a['address_2'] ?? '',
			$a['city'] ?? '',
			$a['state'] ?? '',
			$a['postcode'] ?? '',
			$a['country'] ?? '',
		];
		return implode( '|', array_map( static function ( $p ) {
			return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $p ) ) );
		}, $parts ) );
	}

	/** Normalized comparable hash of an order's shipping address. */
	private static function order_ship_hash( WC_Order $o ) {
		return self::ship_hash_from_array( [
			'first_name' => $o->get_shipping_first_name(),
			'last_name'  => $o->get_shipping_last_name(),
			'address_1'  => $o->get_shipping_address_1(),
			'address_2'  => $o->get_shipping_address_2(),
			'city'       => $o->get_shipping_city(),
			'state'      => $o->get_shipping_state(),
			'postcode'   => $o->get_shipping_postcode(),
			'country'    => $o->get_shipping_country(),
		] );
	}
}
