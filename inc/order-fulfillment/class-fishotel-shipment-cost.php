<?php
/**
 * Shipment cost — what each box cost to ship, on the Shipments meta box.
 *
 * ShipTracker (v1.9.17+) records, per tracking number (= one physical box),
 * the label cost, the box size, the weight and where the label was bought.
 * This class is the theme's side of that: it detects whether the active
 * ShipTracker understands those fields, reads its standard boxes and label
 * sources, and holds the display maths (which card shows a shared box's
 * cost, the "shipping paid vs label cost" line under the list).
 *
 * Nothing here writes. The meta box posts to ShipTracker's own
 * fst_add_tracking / fst_set_label_cost AJAX actions (nonce fst_nonce,
 * manage_woocommerce — both checked there), and a box's cost is only ever
 * read through FST_Shipment::get_order_boxes(): never from a raw shipment
 * row, because the sibling rows of a shared box hold NULL.
 *
 * Everything above the "ShipTracker / WooCommerce readers" divider needs
 * neither WordPress nor ShipTracker and is covered by
 * `php tests/shipment-cost-test.php`.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Shipment_Cost {

	/** Value of the "Custom…" entry in the box size dropdown. Never posted as a size. */
	const CUSTOM_BOX = 'custom';

	// ── Pure helpers ────────────────────────────────────────────────────

	/**
	 * Key that groups shipment rows into one box. ShipTracker (and MySQL)
	 * compare tracking numbers without regard to letter case.
	 */
	public static function box_key( $tracking_number ) {
		return strtoupper( trim( (string) $tracking_number ) );
	}

	/** A decimal amount as whole cents. */
	public static function to_cents( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/** Whole cents as a two-decimal string: -873 is "-8.73". */
	public static function from_cents( $cents ) {
		$cents = (int) $cents;
		$abs   = abs( $cents );
		return ( $cents < 0 ? '-' : '' ) . sprintf( '%d.%02d', intdiv( $abs, 100 ), $abs % 100 );
	}

	/** Drop trailing decimal zeros: "11.00" is "11", "12.50" is "12.5". */
	public static function trim_decimal( $decimal ) {
		$decimal = (string) $decimal;
		if ( false === strpos( $decimal, '.' ) ) {
			return $decimal;
		}
		return rtrim( rtrim( $decimal, '0' ), '.' );
	}

	/**
	 * ShipTracker's standard boxes reduced to what the dropdown needs.
	 * Anything that is not a named box with an "LxWxH" size is dropped.
	 *
	 * @param mixed $raw FST_Settings::get_standard_boxes() result.
	 * @return array[] [ { name, dims } ]
	 */
	public static function clean_boxes( $raw ) {
		$boxes = [];
		if ( ! is_array( $raw ) ) {
			return $boxes;
		}
		foreach ( $raw as $box ) {
			if ( ! is_array( $box ) || ! isset( $box['name'], $box['dims'] ) ) {
				continue;
			}
			if ( ! is_scalar( $box['name'] ) || ! is_scalar( $box['dims'] ) ) {
				continue;
			}
			$name = trim( (string) $box['name'] );
			$dims = trim( (string) $box['dims'] );
			if ( '' === $name || ! preg_match( '/^\d+(?:\.\d+)?(?:x\d+(?:\.\d+)?){2}$/', $dims ) ) {
				continue;
			}
			$boxes[] = [
				'name' => $name,
				'dims' => $dims,
			];
		}
		return $boxes;
	}

	/**
	 * ShipTracker's label sources as a clean list of names.
	 *
	 * @param mixed $raw FST_Settings::get_label_sources() result.
	 * @return string[]
	 */
	public static function clean_sources( $raw ) {
		$sources = [];
		if ( ! is_array( $raw ) ) {
			return $sources;
		}
		foreach ( $raw as $source ) {
			if ( ! is_scalar( $source ) || is_bool( $source ) ) {
				continue;
			}
			$source = trim( (string) $source );
			if ( '' !== $source && ! in_array( $source, $sources, true ) ) {
				$sources[] = $source;
			}
		}
		return $sources;
	}

	/** "Small (10x10x10)" when the size is a standard box, else the size itself. */
	public static function size_label( $dims, array $boxes ) {
		$dims = trim( (string) $dims );
		if ( '' === $dims ) {
			return '';
		}
		foreach ( $boxes as $box ) {
			if ( isset( $box['dims'], $box['name'] ) && $box['dims'] === $dims ) {
				return $box['name'] . ' (' . $dims . ')';
			}
		}
		return $dims;
	}

	/**
	 * Which entry of the box size dropdown a recorded size selects: '' when
	 * no size is recorded, the size itself when it is a standard box,
	 * otherwise the "Custom…" entry.
	 */
	public static function box_choice( $dims, array $boxes ) {
		$dims = trim( (string) $dims );
		if ( '' === $dims ) {
			return '';
		}
		foreach ( $boxes as $box ) {
			if ( isset( $box['dims'] ) && $box['dims'] === $dims ) {
				return $dims;
			}
		}
		return self::CUSTOM_BOX;
	}

	/**
	 * What a shipment card says about its box.
	 *
	 * @param array|null $box   FST_Shipment::get_order_boxes() entry.
	 * @param array      $boxes clean_boxes() list, for the size name.
	 * @return array {
	 *     @type string|null $cost    "53.73"; null = no cost recorded (never 0).
	 *     @type string[]    $details Size, weight and source — whichever are recorded.
	 * }
	 */
	public static function describe_box( $box, array $boxes ) {
		$out = [
			'cost'    => null,
			'details' => [],
		];
		if ( ! is_array( $box ) ) {
			return $out;
		}
		if ( isset( $box['label_cost'] ) && '' !== (string) $box['label_cost'] ) {
			$out['cost'] = self::from_cents( self::to_cents( $box['label_cost'] ) );
		}
		if ( isset( $box['box_dims'] ) && '' !== (string) $box['box_dims'] ) {
			$out['details'][] = self::size_label( $box['box_dims'], $boxes );
		}
		if ( isset( $box['box_weight'] ) && '' !== (string) $box['box_weight'] ) {
			$out['details'][] = self::trim_decimal( $box['box_weight'] ) . ' lb';
		}
		if ( isset( $box['label_source'] ) && '' !== (string) $box['label_source'] ) {
			$out['details'][] = (string) $box['label_source'];
		}
		return $out;
	}

	/**
	 * Decide, for the shipment cards of one order view, where each box's
	 * cost is shown. A box is one tracking number; a fulfillment view lists
	 * one card per source order for it, so only the FIRST card of a box
	 * shows (and edits) the cost — the others point at it.
	 *
	 * @param array $shipments Shipment rows in display order (objects with
	 *                         id, order_id, tracking_number).
	 * @param array $boxes     FST_Shipment::get_order_boxes() for the view.
	 * @return array shipment id => {
	 *     @type array|null $box             The box entry, null when unknown.
	 *     @type bool       $primary         This card shows the cost.
	 *     @type int[]      $order_ids       Every order with a row for the box.
	 *     @type int[]      $other_order_ids Those orders minus the card's own.
	 * }
	 */
	public static function plan_cards( array $shipments, array $boxes ) {
		$by_key = [];
		foreach ( $boxes as $box ) {
			if ( ! is_array( $box ) || ! isset( $box['tracking_number'] ) ) {
				continue;
			}
			$key = self::box_key( $box['tracking_number'] );
			if ( '' !== $key && ! isset( $by_key[ $key ] ) ) {
				$by_key[ $key ] = $box;
			}
		}

		$plan = [];
		$seen = [];
		foreach ( $shipments as $shipment ) {
			if ( ! is_object( $shipment ) || ! isset( $shipment->id ) ) {
				continue;
			}
			$key = self::box_key( isset( $shipment->tracking_number ) ? $shipment->tracking_number : '' );
			$box = isset( $by_key[ $key ] ) ? $by_key[ $key ] : null;
			$own = isset( $shipment->order_id ) ? (int) $shipment->order_id : 0;

			$order_ids = [];
			if ( $box && isset( $box['order_ids'] ) && is_array( $box['order_ids'] ) ) {
				foreach ( $box['order_ids'] as $oid ) {
					$oid = (int) $oid;
					if ( $oid > 0 && ! in_array( $oid, $order_ids, true ) ) {
						$order_ids[] = $oid;
					}
				}
			}

			$plan[ (int) $shipment->id ] = [
				'box'             => $box,
				'primary'         => ! isset( $seen[ $key ] ),
				'order_ids'       => $order_ids,
				'other_order_ids' => array_values( array_diff( $order_ids, [ $own ] ) ),
			];
			$seen[ $key ] = true;
		}
		return $plan;
	}

	/**
	 * The share of an order's shipping that belongs to some of its boxes.
	 *
	 * Same rule as ShipTracker's cost report: an order that went out in
	 * several boxes has its shipping split evenly between them, in whole
	 * cents, spare cents to the earliest boxes — so it is counted once.
	 *
	 * @param int      $shipping_cents The order's shipping paid.
	 * @param string[] $order_keys     box_key() of every box of that order, oldest first.
	 * @param array    $wanted         box_key() => true for the boxes to count.
	 * @return int Cents.
	 */
	public static function share_cents( $shipping_cents, array $order_keys, array $wanted ) {
		$shipping_cents = max( 0, (int) $shipping_cents );

		$keys = [];
		foreach ( $order_keys as $key ) {
			$key = self::box_key( $key );
			if ( '' !== $key && ! in_array( $key, $keys, true ) ) {
				$keys[] = $key;
			}
		}
		// Nothing known about the order's other boxes: all of it counts.
		if ( empty( $keys ) ) {
			return $shipping_cents;
		}

		$count = count( $keys );
		$base  = intdiv( $shipping_cents, $count );
		$spare = $shipping_cents % $count;
		$share = 0;
		foreach ( $keys as $n => $key ) {
			if ( isset( $wanted[ $key ] ) ) {
				$share += $base + ( $n < $spare ? 1 : 0 );
			}
		}
		return $share;
	}

	/**
	 * The line under the shipments list: shipping paid against label cost.
	 *
	 * Rules:
	 *  - A box is one tracking number and is counted once, whatever number
	 *    of cards (source orders) it has.
	 *  - A box without a recorded cost is never treated as costing 0: with
	 *    no cost at all there is no difference, and while some boxes still
	 *    lack one the difference is only "so far" (`complete` is false).
	 *  - Shipping paid = the shipping of the orders of this view, plus — for
	 *    a box shared with orders outside the view — those orders' share of
	 *    it (share_cents()), so a shared box is compared with everything
	 *    that was paid towards it.
	 *
	 * All money is summed in whole cents.
	 *
	 * @param array $boxes          FST_Shipment::get_order_boxes() for the view.
	 * @param array $orders         order id => { shipping: decimal paid, or null when the
	 *                              order is gone (counts as nothing paid); box_keys: that
	 *                              order's boxes, oldest first (only read for orders
	 *                              outside the view) }.
	 * @param int[] $view_order_ids The order on screen, or a fulfillment's source orders.
	 * @return array {
	 *     @type int         $boxes
	 *     @type int         $boxes_with_cost
	 *     @type int         $boxes_without_cost
	 *     @type string      $paid            Decimal string, e.g. "45.00".
	 *     @type string      $paid_view       The part paid on the view's own orders.
	 *     @type string      $paid_others     The part paid on other orders sharing a box.
	 *     @type int[]       $other_order_ids Those other orders.
	 *     @type string|null $cost            Total recorded label cost; null = none recorded.
	 *     @type string|null $difference      paid − cost; null when no cost is recorded.
	 *     @type bool        $complete        Every box has a cost.
	 *     @type bool        $loss            The labels cost more than was paid.
	 * }
	 */
	public static function summarize( array $boxes, array $orders, array $view_order_ids ) {
		$view = [];
		foreach ( $view_order_ids as $oid ) {
			$oid = (int) $oid;
			if ( $oid > 0 ) {
				$view[ $oid ] = true;
			}
		}

		$keys       = [];
		$others     = [];
		$with_cost  = 0;
		$cost_cents = 0;
		foreach ( $boxes as $box ) {
			if ( ! is_array( $box ) || ! isset( $box['tracking_number'] ) ) {
				continue;
			}
			$key = self::box_key( $box['tracking_number'] );
			if ( '' === $key || isset( $keys[ $key ] ) ) {
				continue;
			}
			$keys[ $key ] = true;

			if ( isset( $box['label_cost'] ) && '' !== (string) $box['label_cost'] ) {
				$with_cost++;
				$cost_cents += self::to_cents( $box['label_cost'] );
			}

			if ( isset( $box['order_ids'] ) && is_array( $box['order_ids'] ) ) {
				foreach ( $box['order_ids'] as $oid ) {
					$oid = (int) $oid;
					if ( $oid > 0 && ! isset( $view[ $oid ] ) ) {
						$others[ $oid ] = true;
					}
				}
			}
		}

		$paid_view = 0;
		foreach ( array_keys( $view ) as $oid ) {
			$paid_view += self::order_shipping_cents( $orders, $oid );
		}

		$paid_others = 0;
		foreach ( array_keys( $others ) as $oid ) {
			$order_keys   = isset( $orders[ $oid ]['box_keys'] ) && is_array( $orders[ $oid ]['box_keys'] ) ? $orders[ $oid ]['box_keys'] : [];
			$paid_others += self::share_cents( self::order_shipping_cents( $orders, $oid ), $order_keys, $keys );
		}

		$paid_cents = $paid_view + $paid_others;
		$other_ids  = array_keys( $others );
		sort( $other_ids );

		return [
			'boxes'              => count( $keys ),
			'boxes_with_cost'    => $with_cost,
			'boxes_without_cost' => count( $keys ) - $with_cost,
			'paid'               => self::from_cents( $paid_cents ),
			'paid_view'          => self::from_cents( $paid_view ),
			'paid_others'        => self::from_cents( $paid_others ),
			'other_order_ids'    => $other_ids,
			'cost'               => $with_cost > 0 ? self::from_cents( $cost_cents ) : null,
			'difference'         => $with_cost > 0 ? self::from_cents( $paid_cents - $cost_cents ) : null,
			'complete'           => count( $keys ) > 0 && count( $keys ) === $with_cost,
			'loss'               => $with_cost > 0 && $paid_cents < $cost_cents,
		];
	}

	/** Shipping paid on one order of a summarize() $orders map, in cents (0 when unknown). */
	private static function order_shipping_cents( array $orders, $order_id ) {
		if ( ! isset( $orders[ $order_id ]['shipping'] ) ) {
			return 0;
		}
		return max( 0, self::to_cents( $orders[ $order_id ]['shipping'] ) );
	}

	// ── ShipTracker / WooCommerce readers ───────────────────────────────

	/**
	 * Whether the active ShipTracker records label costs. Detected by
	 * capability — the box-cost reader its v1.9.17 adds — not by version
	 * text, so the Shipments box works unchanged against an older build.
	 */
	public static function supported() {
		return class_exists( 'FST_Shipment' ) && method_exists( 'FST_Shipment', 'get_order_boxes' );
	}

	/** ShipTracker's standard boxes (Settings → Boxes & label costs), [] when none or unavailable. */
	public static function standard_boxes() {
		if ( ! class_exists( 'FST_Settings' ) || ! method_exists( 'FST_Settings', 'get_standard_boxes' ) ) {
			return [];
		}
		try {
			return self::clean_boxes( FST_Settings::get_standard_boxes() );
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	/** ShipTracker's label sources (Settings → Boxes & label costs), [] when none or unavailable. */
	public static function label_sources() {
		if ( ! class_exists( 'FST_Settings' ) || ! method_exists( 'FST_Settings', 'get_label_sources' ) ) {
			return [];
		}
		try {
			return self::clean_sources( FST_Settings::get_label_sources() );
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	/**
	 * The boxes of the given orders — one entry per tracking number, with
	 * the box's cost data and the orders sharing it.
	 *
	 * @param int[] $order_ids
	 * @return array[] FST_Shipment::get_order_boxes() entries, [] when unsupported.
	 */
	public static function order_boxes( array $order_ids ) {
		if ( ! self::supported() || empty( $order_ids ) ) {
			return [];
		}
		try {
			$boxes = FST_Shipment::get_order_boxes( $order_ids );
		} catch ( \Throwable $e ) {
			return [];
		}
		return is_array( $boxes ) ? $boxes : [];
	}

	/**
	 * What the customer paid for shipping on an order: the shipping total
	 * excluding tax, less any shipping refunded — the figure ShipTracker's
	 * cost report uses.
	 *
	 * @param mixed $order
	 * @return float|null Null when the order no longer exists.
	 */
	public static function shipping_paid( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$paid = (float) $order->get_shipping_total() - (float) $order->get_total_shipping_refunded();
		return max( 0, round( $paid, 2 ) );
	}

	/**
	 * summarize() for an order view: looks up the shipping paid on the
	 * view's orders and on any other order sharing one of its boxes.
	 *
	 * @param int[] $view_order_ids The order on screen, or a fulfillment's source orders.
	 * @param array $boxes          order_boxes( $view_order_ids ).
	 * @return array See summarize().
	 */
	public static function view_summary( array $view_order_ids, array $boxes ) {
		$view = [];
		foreach ( $view_order_ids as $oid ) {
			$oid = (int) $oid;
			if ( $oid > 0 ) {
				$view[ $oid ] = true;
			}
		}

		$orders = [];
		foreach ( array_keys( $view ) as $oid ) {
			$orders[ $oid ] = [
				'shipping' => self::shipping_paid( wc_get_order( $oid ) ),
				'box_keys' => [],
			];
		}

		foreach ( $boxes as $box ) {
			if ( ! is_array( $box ) || ! isset( $box['order_ids'] ) || ! is_array( $box['order_ids'] ) ) {
				continue;
			}
			foreach ( $box['order_ids'] as $oid ) {
				$oid = (int) $oid;
				if ( $oid <= 0 || isset( $orders[ $oid ] ) ) {
					continue;
				}
				// An order outside this view: its shipping is split over all
				// the boxes it went out in, and only this view's count.
				$box_keys = [];
				foreach ( self::order_boxes( [ $oid ] ) as $other_box ) {
					if ( is_array( $other_box ) && isset( $other_box['tracking_number'] ) ) {
						$box_keys[] = self::box_key( $other_box['tracking_number'] );
					}
				}
				$orders[ $oid ] = [
					'shipping' => self::shipping_paid( wc_get_order( $oid ) ),
					'box_keys' => $box_keys,
				];
			}
		}

		return self::summarize( $boxes, $orders, array_keys( $view ) );
	}
}
