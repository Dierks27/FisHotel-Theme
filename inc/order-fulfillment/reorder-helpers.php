<?php
/**
 * FisHotel Re-Order Helpers.
 *
 * Shared detection used by the checkout shipping-address lock (Piece 2a,
 * FisHotel_Reorder_Checkout_Lock), the free-shipping piggyback (Piece 2b,
 * FisHotel_Checkout_Reuse), the My Account address propagation (Piece 3,
 * FisHotel_Address_Propagation), and the Batch Plugin's checkout date lock
 * (Piece 1, applied separately in that repo).
 *
 * One conceptual question — "does this customer have an open order still in
 * flight?" — asked from several invocation points: checkout render, package
 * rate calculation, and My Account address save. Resolution mirrors the
 * auto-combine (FisHotel_Fulfillment::customer_open_orders): customer_id for
 * logged-in shoppers, billing email for guests.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

/**
 * The customer's most recent open, unshipped order — or null when none.
 *
 * Pass a numeric customer ID for logged-in shoppers, or a billing email for
 * guests (the caller decides, same precedence as auto-combine).
 *
 * Considers wc-processing and wc-on-hold orders. Excludes fulfillment
 * entities (_fishotel_is_fulfillment = '1'), orders whose every line item
 * is already shipped (_fishotel_line_status = 'shipped'), and orders that
 * already have a ShipTracker shipment row (a box is in motion).
 *
 * @param int|string $customer_id_or_email
 * @return WC_Order|null
 */
function fishotel_get_open_unshipped_order_for_customer( $customer_id_or_email ) {
	$orders = fishotel_get_open_unshipped_orders_for_customer( $customer_id_or_email, 1 );
	return $orders ? $orders[0] : null;
}

/**
 * All open, unshipped orders for the customer, newest first.
 *
 * Same resolution and filtering as the singular helper; used by address
 * propagation (Piece 3), where every open order is updated.
 *
 * @param int|string $customer_id_or_email
 * @param int        $limit Max orders to scan/return (default 20).
 * @return WC_Order[]
 */
function fishotel_get_open_unshipped_orders_for_customer( $customer_id_or_email, $limit = 20 ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return [];
	}

	$customer_id = 0;
	$email       = '';
	if ( is_numeric( $customer_id_or_email ) ) {
		$customer_id = (int) $customer_id_or_email;
	} else {
		$email = strtolower( trim( (string) $customer_id_or_email ) );
	}
	if ( $customer_id <= 0 && '' === $email ) {
		return [];
	}

	// HPOS-safe: wc_get_orders abstracts the storage backend. Mirrors the
	// arg shape already used by FisHotel_Fulfillment::customer_open_orders.
	$args = [
		'limit'   => max( 1, (int) $limit ),
		'type'    => 'shop_order',
		'status'  => [ 'wc-processing', 'wc-on-hold' ],
		'orderby' => 'date',
		'order'   => 'DESC',
	];
	if ( $customer_id > 0 ) {
		$args['customer_id'] = $customer_id;
	} else {
		$args['billing_email'] = $email;
	}

	$out = [];
	foreach ( (array) wc_get_orders( $args ) as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}
		// A fulfillment entity aggregates sources — it is not the customer's
		// own order, so it never anchors a checkout lock or piggyback.
		if ( '1' === (string) $order->get_meta( '_fishotel_is_fulfillment' ) ) {
			continue;
		}
		// Already fully shipped → not "in flight".
		if ( fishotel_order_is_fully_shipped( $order ) ) {
			continue;
		}
		// A ShipTracker shipment row means a box has already left for this
		// order, even when the order is still wc-processing (e.g. a fully
		// refunded line keeps ShipTracker's coverage check from ever
		// auto-advancing it). Such an order must not anchor the checkout
		// lock or the $0 piggyback — nothing can ride in a box that's gone.
		if ( fishotel_order_has_shiptracker_shipment( $order ) ) {
			continue;
		}
		$out[] = $order;
	}
	return $out;
}

/**
 * True when every product line on the order is marked shipped. Orders with
 * no product lines are NOT considered fully shipped.
 *
 * @param WC_Order $order
 * @return bool
 */
function fishotel_order_is_fully_shipped( WC_Order $order ) {
	$items = $order->get_items( 'line_item' );
	if ( empty( $items ) ) {
		return false;
	}
	foreach ( $items as $item ) {
		if ( 'shipped' !== (string) $item->get_meta( '_fishotel_line_status' ) ) {
			return false;
		}
	}
	return true;
}

/**
 * True when ShipTracker says this order's box has actually left — i.e.
 * nothing new can ride in it. Shared by the checkout lock / piggyback
 * (above) and FisHotel_Fulfillment::customer_open_orders() (auto-combine)
 * so the three paths agree on which orders are still "in motion".
 *
 * The rule is "has everything owed shipped", NOT "any wp_fst_shipments row
 * exists". A row alone proves little:
 *   - A row with explicit line_item_ids is a partial box (an EA drop-ship
 *     med split off a fish order, for example). It only counts when the
 *     union of explicit ids across the order's rows covers every line item
 *     still owed — lines already marked shipped by the order's own
 *     _fishotel_line_status, and fully refunded lines (which never ship),
 *     are not owed.
 *   - A row with NULL/empty line_item_ids is what ShipTracker's fulfillment
 *     fan-out writes for every sibling source that has nothing in the box
 *     (ShipTracker's own coverage check gives such rows no coverage). It
 *     counts only when the order's own line status says something shipped
 *     (fishotel_order_has_shipped_line — Phase 1 meta, same reconciliation
 *     the Shipments meta box uses for legacy NULL rows).
 *
 * Guarded so the theme keeps working with the plugin deactivated (then no
 * row can exist and the answer is simply "no").
 *
 * @param WC_Order $order
 * @return bool
 */
function fishotel_order_has_shiptracker_shipment( WC_Order $order ) {
	if ( ! class_exists( 'FST_Shipment' ) || ! method_exists( 'FST_Shipment', 'get_by_order' ) ) {
		return false;
	}
	$rows = FST_Shipment::get_by_order( (int) $order->get_id() );
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return false;
	}

	$covered  = [];
	$has_null = false;
	foreach ( $rows as $row ) {
		$raw = ( is_object( $row ) && isset( $row->line_item_ids ) ) ? $row->line_item_ids : null;
		$ids = empty( $raw ) ? null : ( is_array( $raw ) ? $raw : json_decode( (string) $raw, true ) );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			$has_null = true;
			continue;
		}
		foreach ( $ids as $id ) {
			$covered[ (int) $id ] = true;
		}
	}

	if ( $has_null && fishotel_order_has_shipped_line( $order ) ) {
		return true;
	}
	if ( empty( $covered ) ) {
		return false;
	}
	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		$item_id = (int) $item_id;
		if ( isset( $covered[ $item_id ] ) ) {
			continue;
		}
		if ( 'shipped' === (string) $item->get_meta( '_fishotel_line_status' ) ) {
			continue;
		}
		// WC reports refunded quantities as negatives.
		if ( abs( (int) $order->get_qty_refunded_for_item( $item_id ) ) >= (int) $item->get_quantity() ) {
			continue;
		}
		return false; // Something is still owed — a box is still to come.
	}
	return true;
}

/**
 * True when at least one product line on the order has shipped — a partial
 * shipment is in motion. Used by address propagation to leave in-motion
 * orders untouched.
 *
 * @param WC_Order $order
 * @return bool
 */
function fishotel_order_has_shipped_line( WC_Order $order ) {
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( 'shipped' === (string) $item->get_meta( '_fishotel_line_status' ) ) {
			return true;
		}
	}
	return false;
}
