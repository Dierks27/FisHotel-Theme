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
 * Since ShipTracker v1.9.18 a box also has its packing — the materials
 * that went into it (the box itself, fish bags, heat or ice packs, a foam
 * and a paper amount) — and an order with no label of its own can be a
 * local pickup or have gone in another order's box. This class reads those
 * too (packing_supported()): the supplies and the usual packing of each
 * standard box for an order, the full cost of a box (label + materials)
 * and how an order went out.
 *
 * Nothing here writes. The meta box posts to ShipTracker's own
 * fst_add_tracking / fst_set_label_cost / fst_set_ship_mode AJAX actions
 * (nonce fst_nonce, manage_woocommerce — both checked there), and a box's
 * cost is only ever read through FST_Shipment::get_order_boxes(): never
 * from a raw shipment row, because the sibling rows of a shared box hold
 * NULL.
 *
 * Everything above the "ShipTracker / WooCommerce readers" divider needs
 * neither WordPress nor ShipTracker and is covered by
 * `php tests/shipment-cost-test.php` and `php tests/shipment-packing-test.php`.
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
	 * The line under the shipments list: shipping paid against what the
	 * boxes cost (label + materials).
	 *
	 * Rules:
	 *  - A box is one tracking number and is counted once, whatever number
	 *    of cards (source orders) it has.
	 *  - A box without a recorded LABEL cost is never treated as costing 0:
	 *    with no cost at all there is no difference, and while some boxes
	 *    still lack one the difference is only "so far" (`complete` is
	 *    false). Its materials stay out of the cost too, as in ShipTracker's
	 *    report, and are returned apart.
	 *  - A box with a label cost and no materials recorded costs its label.
	 *  - Shipping paid = the shipping of the orders of this view, plus — for
	 *    a box shared with orders outside the view — those orders' share of
	 *    it (share_cents()), so a shared box is compared with everything
	 *    that was paid towards it. An order that "went in another order's
	 *    box" ($rider_ids) has no shipment of its own; it is counted the
	 *    same way, against the boxes of the order it went with.
	 *  - Nothing paid towards a box at all is free shipping (`free`).
	 *
	 * All money is summed in whole cents.
	 *
	 * @param array $boxes          FST_Shipment::get_order_boxes() for the view.
	 * @param array $orders         order id => { shipping: decimal paid, or null when the
	 *                              order is gone (counts as nothing paid); box_keys: the
	 *                              boxes that order's shipping is split over, oldest first
	 *                              (only read for orders outside the view) }.
	 * @param int[] $view_order_ids The order on screen, or a fulfillment's source orders.
	 * @param int[] $rider_ids      Orders that went in a box of this view without a
	 *                              shipment of their own.
	 * @return array {
	 *     @type int         $boxes
	 *     @type int         $boxes_with_cost
	 *     @type int         $boxes_without_cost
	 *     @type int         $boxes_with_materials
	 *     @type string      $paid            Decimal string, e.g. "45.00".
	 *     @type string      $paid_view       The part paid on the view's own orders.
	 *     @type string      $paid_others     The part paid on other orders sharing a box.
	 *     @type int[]       $other_order_ids Those other orders (riders included).
	 *     @type int[]       $rider_order_ids The ones among them that went in another order's box.
	 *     @type string|null $cost            Label + materials of the boxes with a label cost;
	 *                                        null = no label cost recorded.
	 *     @type string|null $label_cost      The label part of it.
	 *     @type string      $materials       The materials part of it ("0.00" when none).
	 *     @type string      $materials_without_cost Materials on boxes still without a label cost.
	 *     @type string|null $difference      paid − cost; null when no cost is recorded.
	 *     @type bool        $complete        Every box has a label cost.
	 *     @type bool        $loss            The boxes cost more than was paid.
	 *     @type bool        $free            There are boxes and nothing was paid towards them.
	 * }
	 */
	public static function summarize( array $boxes, array $orders, array $view_order_ids, array $rider_ids = [] ) {
		$view = [];
		foreach ( $view_order_ids as $oid ) {
			$oid = (int) $oid;
			if ( $oid > 0 ) {
				$view[ $oid ] = true;
			}
		}

		$keys            = [];
		$others          = [];
		$with_cost       = 0;
		$with_materials  = 0;
		$label_cents     = 0;
		$materials_cents = 0;
		$materials_left  = 0;
		foreach ( $boxes as $box ) {
			if ( ! is_array( $box ) || ! isset( $box['tracking_number'] ) ) {
				continue;
			}
			$key = self::box_key( $box['tracking_number'] );
			if ( '' === $key || isset( $keys[ $key ] ) ) {
				continue;
			}
			$keys[ $key ] = true;

			$full = self::full_cost( $box );
			if ( null !== $full['materials'] ) {
				$with_materials++;
			}
			if ( null !== $full['label'] ) {
				$with_cost++;
				$label_cents     += self::to_cents( $full['label'] );
				$materials_cents += self::to_cents( $full['materials'] );
			} else {
				$materials_left += self::to_cents( $full['materials'] );
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

		// Riders only count where there is a box for them to have gone in.
		$riders = [];
		if ( ! empty( $keys ) ) {
			foreach ( $rider_ids as $oid ) {
				$oid = (int) $oid;
				if ( $oid > 0 && ! isset( $view[ $oid ] ) && ! isset( $others[ $oid ] ) ) {
					$others[ $oid ] = true;
					$riders[]       = $oid;
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
		$cost_cents = $label_cents + $materials_cents;
		$other_ids  = array_keys( $others );
		sort( $other_ids );
		sort( $riders );

		return [
			'boxes'                  => count( $keys ),
			'boxes_with_cost'        => $with_cost,
			'boxes_without_cost'     => count( $keys ) - $with_cost,
			'boxes_with_materials'   => $with_materials,
			'paid'                   => self::from_cents( $paid_cents ),
			'paid_view'              => self::from_cents( $paid_view ),
			'paid_others'            => self::from_cents( $paid_others ),
			'other_order_ids'        => $other_ids,
			'rider_order_ids'        => $riders,
			'cost'                   => $with_cost > 0 ? self::from_cents( $cost_cents ) : null,
			'label_cost'             => $with_cost > 0 ? self::from_cents( $label_cents ) : null,
			'materials'              => self::from_cents( $materials_cents ),
			'materials_without_cost' => self::from_cents( $materials_left ),
			'difference'             => $with_cost > 0 ? self::from_cents( $paid_cents - $cost_cents ) : null,
			'complete'               => count( $keys ) > 0 && count( $keys ) === $with_cost,
			'loss'                   => $with_cost > 0 && $paid_cents < $cost_cents,
			'free'                   => count( $keys ) > 0 && 0 === $paid_cents,
		];
	}

	/** Shipping paid on one order of a summarize() $orders map, in cents (0 when unknown). */
	private static function order_shipping_cents( array $orders, $order_id ) {
		if ( ! isset( $orders[ $order_id ]['shipping'] ) ) {
			return 0;
		}
		return max( 0, self::to_cents( $orders[ $order_id ]['shipping'] ) );
	}

	// ── Packing (materials) and "how it went out" — pure ────────────────

	/** A decimal string from anything numeric, else null. */
	private static function decimal_or_null( $value ) {
		if ( is_bool( $value ) || ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return null;
		}
		return (string) $value;
	}

	/** A trimmed string from a scalar key of a row, else ''. */
	private static function text_of( array $row, $key ) {
		if ( ! isset( $row[ $key ] ) || is_bool( $row[ $key ] ) || ! is_scalar( $row[ $key ] ) ) {
			return '';
		}
		return trim( (string) $row[ $key ] );
	}

	/**
	 * ShipTracker's supplies reduced to what the packing block needs.
	 *
	 * @param mixed $raw FST_Supplies::get_supplies() result.
	 * @return array[] [ { id, name, unit, role, box_name, price_each ("0.6000" or null until a
	 *                 purchase is logged), on_hand ("34" — null when the supply is not counted),
	 *                 low, recount_needed } ]
	 */
	public static function clean_supplies( $raw ) {
		$supplies = [];
		$seen     = [];
		if ( ! is_array( $raw ) ) {
			return $supplies;
		}
		foreach ( $raw as $supply ) {
			if ( ! is_array( $supply ) || ! isset( $supply['id'] ) || ! is_scalar( $supply['id'] ) ) {
				continue;
			}
			$id   = (int) $supply['id'];
			$name = self::text_of( $supply, 'name' );
			if ( $id <= 0 || '' === $name || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$unit    = self::text_of( $supply, 'unit' );
			$on_hand = ! empty( $supply['counted'] ) && isset( $supply['on_hand'] ) ? self::decimal_or_null( $supply['on_hand'] ) : null;

			$supplies[] = [
				'id'             => $id,
				'name'           => $name,
				'unit'           => '' === $unit ? 'each' : $unit,
				'role'           => self::text_of( $supply, 'role' ),
				'box_name'       => self::text_of( $supply, 'box_name' ),
				'price_each'     => isset( $supply['price_each'] ) ? self::decimal_or_null( $supply['price_each'] ) : null,
				'on_hand'        => null === $on_hand ? null : self::trim_decimal( $on_hand ),
				'low'            => ! empty( $supply['low'] ),
				'recount_needed' => ! empty( $supply['recount_needed'] ),
			];
		}
		return $supplies;
	}

	/**
	 * A ShipTracker materials record reduced to what is shown and edited.
	 *
	 * @param mixed $record FST_Supplies::get_box_materials() shape, or null.
	 * @return array|null { lines: [ { supply_id, name, role, unit, qty "6", unit_cost "0.1100",
	 *                    cost "0.66" } ], foam_cost, paper_cost, total } — null when there is no
	 *                    record (a record of nothing but zeros is still a record).
	 */
	public static function clean_materials( $record ) {
		if ( ! is_array( $record ) ) {
			return null;
		}

		$lines = [];
		$sum   = 0;
		foreach ( isset( $record['lines'] ) && is_array( $record['lines'] ) ? $record['lines'] : [] as $line ) {
			if ( ! is_array( $line ) || ! isset( $line['supply_id'] ) || ! is_scalar( $line['supply_id'] ) ) {
				continue;
			}
			$id  = (int) $line['supply_id'];
			$qty = isset( $line['qty'] ) ? self::decimal_or_null( $line['qty'] ) : null;
			if ( $id <= 0 || null === $qty || (float) $qty <= 0 ) {
				continue;
			}
			$rate  = isset( $line['unit_cost'] ) ? self::decimal_or_null( $line['unit_cost'] ) : null;
			$cents = isset( $line['cost'] ) && null !== self::decimal_or_null( $line['cost'] ) ? self::to_cents( $line['cost'] ) : 0;
			$sum  += $cents;
			$name  = self::text_of( $line, 'name' );
			$unit  = self::text_of( $line, 'unit' );

			$lines[] = [
				'supply_id' => $id,
				'name'      => '' === $name ? '#' . $id : $name,
				'role'      => self::text_of( $line, 'role' ),
				'unit'      => '' === $unit ? 'each' : $unit,
				'qty'       => self::trim_decimal( $qty ),
				'unit_cost' => null === $rate ? '0.0000' : $rate,
				'cost'      => self::from_cents( $cents ),
			];
		}

		$foam  = isset( $record['foam_cost'] ) && null !== self::decimal_or_null( $record['foam_cost'] ) ? max( 0, self::to_cents( $record['foam_cost'] ) ) : 0;
		$paper = isset( $record['paper_cost'] ) && null !== self::decimal_or_null( $record['paper_cost'] ) ? max( 0, self::to_cents( $record['paper_cost'] ) ) : 0;
		$total = isset( $record['total'] ) && null !== self::decimal_or_null( $record['total'] ) ? self::to_cents( $record['total'] ) : $sum + $foam + $paper;

		return [
			'lines'      => $lines,
			'foam_cost'  => self::from_cents( $foam ),
			'paper_cost' => self::from_cents( $paper ),
			'total'      => self::from_cents( $total ),
		];
	}

	/**
	 * The usual packing of every standard box (and of a custom one), as
	 * ShipTracker worked it out for a number of fish and a weather action.
	 *
	 * @param mixed $raw FST_Supplies::get_default_materials() result.
	 * @return array { boxes: [ { name, dims, materials } ], custom: materials } with
	 *               clean_materials() records (an empty one where ShipTracker gave none).
	 */
	public static function clean_defaults( $raw ) {
		$empty = [
			'lines'      => [],
			'foam_cost'  => '0.00',
			'paper_cost' => '0.00',
			'total'      => '0.00',
		];
		$out   = [
			'boxes'  => [],
			'custom' => $empty,
		];
		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( isset( $raw['boxes'] ) && is_array( $raw['boxes'] ) ? $raw['boxes'] : [] as $box ) {
			if ( ! is_array( $box ) ) {
				continue;
			}
			$name = self::text_of( $box, 'name' );
			if ( '' === $name ) {
				continue;
			}
			$materials      = self::clean_materials( isset( $box['materials'] ) ? $box['materials'] : null );
			$out['boxes'][] = [
				'name'      => $name,
				'dims'      => self::text_of( $box, 'dims' ),
				'materials' => null === $materials ? $empty : $materials,
			];
		}

		$custom = self::clean_materials( isset( $raw['custom'] ) ? $raw['custom'] : null );
		if ( null !== $custom ) {
			$out['custom'] = $custom;
		}
		return $out;
	}

	/**
	 * What a box cost in full: its label, its materials, and the two added.
	 *
	 * @param array|null $box FST_Shipment::get_order_boxes() entry.
	 * @return array {
	 *     @type string|null $label     "53.73"; null = no label cost recorded (never 0).
	 *     @type string|null $materials "6.10"; null = no materials recorded.
	 *     @type string|null $total     Label + materials; null while the label cost is missing.
	 * }
	 */
	public static function full_cost( $box ) {
		$out = [
			'label'     => null,
			'materials' => null,
			'total'     => null,
		];
		if ( ! is_array( $box ) ) {
			return $out;
		}

		if ( isset( $box['label_cost'] ) && '' !== (string) $box['label_cost'] ) {
			$out['label'] = self::from_cents( self::to_cents( $box['label_cost'] ) );
		}

		if ( isset( $box['materials_total'] ) && null !== self::decimal_or_null( $box['materials_total'] ) ) {
			$out['materials'] = self::from_cents( self::to_cents( $box['materials_total'] ) );
		} elseif ( isset( $box['materials'] ) && is_array( $box['materials'] ) ) {
			$record           = self::clean_materials( $box['materials'] );
			$out['materials'] = $record['total'];
		}

		if ( null !== $out['label'] ) {
			$out['total'] = self::from_cents( self::to_cents( $out['label'] ) + self::to_cents( $out['materials'] ) );
		}
		return $out;
	}

	/**
	 * A materials record as the words of a shipment card: "1 × Small box",
	 * "6 × Fish bag", and the foam and paper amounts when there are any.
	 *
	 * @param array|null $materials clean_materials() record.
	 * @return array { lines: string[], foam: "1.80"|null, paper: "0.40"|null }
	 */
	public static function materials_parts( $materials ) {
		$out = [
			'lines' => [],
			'foam'  => null,
			'paper' => null,
		];
		if ( ! is_array( $materials ) ) {
			return $out;
		}
		foreach ( isset( $materials['lines'] ) && is_array( $materials['lines'] ) ? $materials['lines'] : [] as $line ) {
			$out['lines'][] = self::trim_decimal( $line['qty'] ) . ' × ' . $line['name'];
		}
		if ( isset( $materials['foam_cost'] ) && self::to_cents( $materials['foam_cost'] ) > 0 ) {
			$out['foam'] = self::from_cents( self::to_cents( $materials['foam_cost'] ) );
		}
		if ( isset( $materials['paper_cost'] ) && self::to_cents( $materials['paper_cost'] ) > 0 ) {
			$out['paper'] = self::from_cents( self::to_cents( $materials['paper_cost'] ) );
		}
		return $out;
	}

	/**
	 * How many fish one order line puts in a box: ShipTracker's count for
	 * the line, less the part of it that was refunded (those fish are not
	 * in any box).
	 *
	 * @param mixed $count         ShipTracker's fish count for the whole line.
	 * @param mixed $original_qty  The line's quantity.
	 * @param mixed $available_qty What is left of it after refunds.
	 * @return float Two decimals at most, never negative.
	 */
	public static function item_fish( $count, $original_qty, $available_qty ) {
		$count     = is_numeric( $count ) ? max( 0.0, (float) $count ) : 0.0;
		$original  = is_numeric( $original_qty ) ? (float) $original_qty : 0.0;
		$available = is_numeric( $available_qty ) ? max( 0.0, (float) $available_qty ) : 0.0;
		if ( $original > 0 && $available < $original ) {
			$count = $count * $available / $original;
		}
		return round( $count, 2 );
	}

	/** A fish count as a short decimal string: 6.0 is "6", 2.5 is "2.5". */
	public static function fish_text( $count ) {
		return self::trim_decimal( number_format( max( 0.0, (float) $count ), 2, '.', '' ) );
	}

	/**
	 * What the weather check asks to be packed, in words. The action names
	 * are the ones the Weather section of the FisHotel Misc plugin notes on
	 * an order; ShipTracker passes them on. Only the two that put a pack in
	 * the box have words here — '' for every other action.
	 */
	public static function weather_label( $action ) {
		switch ( (string) $action ) {
			case 'heat_pack':
				return 'Heat pack';
			case 'cooling':
				return 'Cooling packs';
			default:
				return '';
		}
	}

	/**
	 * How an order went out, as ShipTracker describes it, made safe to show.
	 *
	 * @param mixed $state FST_Ship_Mode::describe() result.
	 * @return array { mode: label | local | with_order, with_order_id, with_order_number,
	 *               date ("2026-11-12 09:00:00" or ''), materials (clean_materials() or null),
	 *               materials_total ("1.40" or null) }
	 */
	public static function clean_ship_mode( $state ) {
		$out = [
			'mode'              => 'label',
			'with_order_id'     => 0,
			'with_order_number' => '',
			'date'              => '',
			'materials'         => null,
			'materials_total'   => null,
		];
		if ( ! is_array( $state ) ) {
			return $out;
		}

		$mode = self::text_of( $state, 'mode' );
		$with = isset( $state['with_order_id'] ) && is_scalar( $state['with_order_id'] ) ? (int) $state['with_order_id'] : 0;
		if ( 'local' !== $mode && ( 'with_order' !== $mode || $with <= 0 ) ) {
			return $out;
		}

		$out['mode'] = $mode;
		$out['date'] = self::text_of( $state, 'date' );
		if ( 'with_order' === $mode ) {
			$number                   = self::text_of( $state, 'with_order_number' );
			$out['with_order_id']     = $with;
			$out['with_order_number'] = '' === $number ? (string) $with : $number;
			return $out;
		}

		$out['materials'] = self::clean_materials( isset( $state['materials'] ) ? $state['materials'] : null );
		if ( null !== $out['materials'] ) {
			$out['materials_total'] = $out['materials']['total'];
		}
		return $out;
	}

	/**
	 * The order id in what was typed as an order number: "#34183", " 34183 ".
	 * 0 when it is not a number.
	 */
	public static function order_ref_id( $typed ) {
		if ( is_bool( $typed ) || ! is_scalar( $typed ) ) {
			return 0;
		}
		$typed = ltrim( trim( (string) $typed ), '#' );
		return preg_match( '/^\d{1,18}$/', $typed ) ? (int) $typed : 0;
	}

	/**
	 * Why an order cannot be the one whose box another order went in — the
	 * rules ShipTracker applies when it saves, told before the save. '' when
	 * it can.
	 *
	 * @param array $facts own_id, host_id, exists, mode (label | local | with_order — how
	 *                     the host itself went out), with_order_number (when its mode is
	 *                     with_order).
	 * @return string
	 */
	public static function host_problem( array $facts ) {
		$host_id = isset( $facts['host_id'] ) ? (int) $facts['host_id'] : 0;
		$own_id  = isset( $facts['own_id'] ) ? (int) $facts['own_id'] : 0;
		$mode    = isset( $facts['mode'] ) ? (string) $facts['mode'] : 'label';

		if ( $host_id <= 0 ) {
			return 'Enter the order number of the box it went in.';
		}
		if ( $host_id === $own_id ) {
			return 'That is this order. Enter the order whose box it went in.';
		}
		if ( empty( $facts['exists'] ) ) {
			return sprintf( 'Order #%d not found.', $host_id );
		}
		if ( 'with_order' === $mode ) {
			$other = isset( $facts['with_order_number'] ) ? trim( (string) $facts['with_order_number'] ) : '';
			return sprintf(
				'Order #%1$d itself went in another order\'s box%2$s. Enter the order that has the box.',
				$host_id,
				'' === $other ? '' : ' (#' . $other . ')'
			);
		}
		if ( 'local' === $mode ) {
			return sprintf( 'Order #%d is a local pickup, so it has no box.', $host_id );
		}
		return '';
	}

	/**
	 * One line about the order another order went with: who it is for and
	 * the box (or boxes) found on it.
	 *
	 * @param array $host number, customer, boxes: [ { tracking_number, size, status } ].
	 * @return string e.g. "Order #34183 — Jane Doe. Box: 1Z999 · Small (10x10x10) · In Transit."
	 */
	public static function host_summary( array $host ) {
		$number   = isset( $host['number'] ) ? trim( (string) $host['number'] ) : '';
		$customer = isset( $host['customer'] ) ? trim( (string) $host['customer'] ) : '';
		$text     = 'Order #' . $number . ( '' === $customer ? '' : ' — ' . $customer ) . '.';

		$boxes = [];
		foreach ( isset( $host['boxes'] ) && is_array( $host['boxes'] ) ? $host['boxes'] : [] as $box ) {
			if ( ! is_array( $box ) ) {
				continue;
			}
			$parts = [];
			foreach ( [ 'tracking_number', 'size', 'status' ] as $key ) {
				if ( isset( $box[ $key ] ) && '' !== trim( (string) $box[ $key ] ) ) {
					$parts[] = trim( (string) $box[ $key ] );
				}
			}
			if ( ! empty( $parts ) ) {
				$boxes[] = implode( ' · ', $parts );
			}
		}

		if ( empty( $boxes ) ) {
			return $text . ' No shipment on it yet — its box will count this order once the tracking is added.';
		}
		return $text . ( 1 === count( $boxes ) ? ' Box: ' : ' Boxes: ' ) . implode( '; ', $boxes ) . '.';
	}

	/**
	 * A local pickup against the shipping its customer paid: no label, so
	 * the cost is whatever packing was recorded for it. With nothing paid
	 * there is nothing to compare it with — that is a free pickup, not a
	 * loss.
	 *
	 * @param mixed $paid            Shipping paid on the order (null = unknown, counts as 0).
	 * @param mixed $materials_total "1.40", or null when no packing is recorded.
	 * @return array {
	 *     @type string      $paid
	 *     @type string      $cost       The packing ("0.00" when none is recorded).
	 *     @type string|null $difference paid − cost; null when nothing was paid.
	 *     @type bool        $loss       Shipping was paid and the packing cost more.
	 * }
	 */
	public static function pickup_summary( $paid, $materials_total ) {
		$paid_cents = null === $paid ? 0 : max( 0, self::to_cents( $paid ) );
		$cost_cents = null === $materials_total ? 0 : max( 0, self::to_cents( $materials_total ) );
		return [
			'paid'       => self::from_cents( $paid_cents ),
			'cost'       => self::from_cents( $cost_cents ),
			'difference' => $paid_cents > 0 ? self::from_cents( $paid_cents - $cost_cents ) : null,
			'loss'       => $paid_cents > 0 && $paid_cents < $cost_cents,
		];
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
	 * Whether the active ShipTracker records what went into a box and how
	 * an order without a label went out (its v1.9.18). Detected by
	 * capability, like supported(): against an older build the Shipments
	 * box shows the label cost only, exactly as before.
	 */
	public static function packing_supported() {
		return self::supported()
			&& class_exists( 'FST_Supplies' ) && method_exists( 'FST_Supplies', 'get_default_materials' )
			&& class_exists( 'FST_Order' ) && method_exists( 'FST_Order', 'set_ship_mode' );
	}

	/** ShipTracker's supplies with price each and stock, [] when none or unavailable. */
	public static function supplies() {
		if ( ! self::packing_supported() || ! method_exists( 'FST_Supplies', 'get_supplies' ) ) {
			return [];
		}
		try {
			return self::clean_supplies( FST_Supplies::get_supplies() );
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	/**
	 * The usual packing of every standard box and of a custom one for a
	 * number of fish and a weather action — ShipTracker's own defaults.
	 *
	 * @param int|float $fish_count
	 * @param string    $action
	 * @return array clean_defaults() shape.
	 */
	public static function default_materials( $fish_count, $action ) {
		if ( ! self::packing_supported() ) {
			return self::clean_defaults( null );
		}
		try {
			return self::clean_defaults( FST_Supplies::get_default_materials( $fish_count, (string) $action ) );
		} catch ( \Throwable $e ) {
			return self::clean_defaults( null );
		}
	}

	/** The weather action ShipTracker sees for an order (heat_pack, cooling, …), '' when none. */
	public static function pack_action( $order ) {
		if ( ! self::packing_supported() || ! method_exists( 'FST_Supplies', 'order_pack_action' ) ) {
			return '';
		}
		try {
			$action = FST_Supplies::order_pack_action( $order );
		} catch ( \Throwable $e ) {
			return '';
		}
		return is_string( $action ) ? $action : '';
	}

	/**
	 * How many fish ShipTracker counts on an order — or on some of its
	 * line items.
	 *
	 * @param mixed      $order
	 * @param int[]|null $item_ids Null = every item of the order.
	 * @return float
	 */
	public static function fish_count( $order, $item_ids = null ) {
		if ( ! self::packing_supported() || ! method_exists( 'FST_Supplies', 'fish_count' ) ) {
			return 0.0;
		}
		try {
			$count = FST_Supplies::fish_count( $order, $item_ids );
		} catch ( \Throwable $e ) {
			return 0.0;
		}
		return is_numeric( $count ) ? max( 0.0, (float) $count ) : 0.0;
	}

	/**
	 * How many fish are in a box: the items of every shipment row with its
	 * tracking number, over all the orders sharing it.
	 *
	 * @param string $tracking_number
	 * @param int[]  $order_ids The orders with a row for the box.
	 * @return float
	 */
	public static function box_fish_count( $tracking_number, array $order_ids ) {
		if ( ! class_exists( 'FST_Shipment' ) || ! method_exists( 'FST_Shipment', 'get_by_order' ) ) {
			return 0.0;
		}
		$key  = self::box_key( $tracking_number );
		$fish = 0.0;
		foreach ( $order_ids as $oid ) {
			$oid = (int) $oid;
			try {
				$rows = $oid > 0 ? FST_Shipment::get_by_order( $oid ) : [];
			} catch ( \Throwable $e ) {
				$rows = [];
			}
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				if ( ! is_object( $row ) || ! isset( $row->tracking_number ) || self::box_key( $row->tracking_number ) !== $key ) {
					continue;
				}
				// No list of items on the row = every item of that order.
				$ids = empty( $row->line_item_ids ) ? null : ( is_array( $row->line_item_ids ) ? $row->line_item_ids : json_decode( (string) $row->line_item_ids, true ) );
				$fish += self::fish_count( wc_get_order( $oid ), is_array( $ids ) ? array_map( 'intval', $ids ) : null );
			}
		}
		return round( $fish, 2 );
	}

	/**
	 * How an order went out: with a label (the default), as a local pickup
	 * or in another order's box.
	 *
	 * @param mixed $order
	 * @return array clean_ship_mode() shape.
	 */
	public static function ship_mode( $order ) {
		if ( ! self::packing_supported() || ! class_exists( 'FST_Ship_Mode' ) || ! method_exists( 'FST_Ship_Mode', 'describe' ) || ! $order instanceof WC_Order ) {
			return self::clean_ship_mode( null );
		}
		try {
			return self::clean_ship_mode( FST_Ship_Mode::describe( $order ) );
		} catch ( \Throwable $e ) {
			return self::clean_ship_mode( null );
		}
	}

	/**
	 * The orders whose shipment rows hold the boxes of an order: the order
	 * itself, and for a fulfillment order its source orders.
	 *
	 * @param mixed $order
	 * @return int[]
	 */
	public static function host_order_ids( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return [];
		}
		$ids = [ (int) $order->get_id() ];
		if ( class_exists( 'FST_Ship_Mode' ) && method_exists( 'FST_Ship_Mode', 'host_order_ids' ) ) {
			try {
				$ids = array_map( 'intval', (array) FST_Ship_Mode::host_order_ids( $order ) );
			} catch ( \Throwable $e ) {
				$ids = [ (int) $order->get_id() ];
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * The orders another order may have been marked as going with, as far
	 * as a view is concerned: the view's own orders, every order sharing
	 * one of its boxes, and the fulfillment order any of them is part of.
	 *
	 * @param int[] $view_order_ids The order on screen, or a fulfillment's source orders.
	 * @param array $boxes          order_boxes( $view_order_ids ).
	 * @return int[]
	 */
	public static function view_host_ids( array $view_order_ids, array $boxes ) {
		$ids = array_map( 'intval', $view_order_ids );
		foreach ( $boxes as $box ) {
			if ( is_array( $box ) && isset( $box['order_ids'] ) && is_array( $box['order_ids'] ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $box['order_ids'] ) );
			}
		}
		$ids = array_values( array_unique( array_filter( $ids, static function ( $id ) {
			return $id > 0;
		} ) ) );

		$hosts = $ids;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof WC_Order ) {
				$hosts[] = (int) $order->get_meta( '_fishotel_fulfilled_by_order' );
			}
		}
		return array_values( array_unique( array_filter( $hosts, static function ( $id ) {
			return $id > 0;
		} ) ) );
	}

	/**
	 * The orders marked as gone in the box of one of the given orders, and
	 * still without a shipment of their own.
	 *
	 * @param int[] $host_ids
	 * @return int[]
	 */
	public static function rider_ids( array $host_ids ) {
		if ( ! self::packing_supported() || ! class_exists( 'FST_Ship_Mode' ) || ! method_exists( 'FST_Ship_Mode', 'get_riders_of' ) ) {
			return [];
		}
		$riders = [];
		foreach ( $host_ids as $host_id ) {
			$host_id = (int) $host_id;
			if ( $host_id <= 0 ) {
				continue;
			}
			try {
				$found = FST_Ship_Mode::get_riders_of( $host_id );
			} catch ( \Throwable $e ) {
				$found = [];
			}
			foreach ( is_array( $found ) ? $found : [] as $rider_id ) {
				$rider_id = (int) $rider_id;
				// An order with a label of its own is no rider, whatever its mark says.
				if ( $rider_id > 0 && ! in_array( $rider_id, $riders, true ) && empty( self::order_boxes( [ $rider_id ] ) ) ) {
					$riders[] = $rider_id;
				}
			}
		}
		sort( $riders );
		return $riders;
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
	 * view's orders, on any other order sharing one of its boxes, and on the
	 * orders that went in one of its boxes without a shipment of their own.
	 *
	 * @param int[] $view_order_ids The order on screen, or a fulfillment's source orders.
	 * @param array $boxes          order_boxes( $view_order_ids ).
	 * @param int[] $rider_ids      rider_ids() for the view.
	 * @return array See summarize().
	 */
	public static function view_summary( array $view_order_ids, array $boxes, array $rider_ids = [] ) {
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
				$orders[ $oid ] = [
					'shipping' => self::shipping_paid( wc_get_order( $oid ) ),
					'box_keys' => self::box_keys( self::order_boxes( [ $oid ] ) ),
				];
			}
		}

		foreach ( $rider_ids as $oid ) {
			$oid = (int) $oid;
			if ( $oid <= 0 || isset( $orders[ $oid ] ) ) {
				continue;
			}
			// An order that went in another order's box: its shipping is
			// split over all the boxes of the order it went with.
			$rider          = wc_get_order( $oid );
			$state          = self::ship_mode( $rider );
			$host           = $state['with_order_id'] > 0 ? wc_get_order( $state['with_order_id'] ) : null;
			$orders[ $oid ] = [
				'shipping' => self::shipping_paid( $rider ),
				'box_keys' => self::box_keys( self::order_boxes( self::host_order_ids( $host ) ) ),
			];
		}

		return self::summarize( $boxes, $orders, array_keys( $view ), $rider_ids );
	}

	/** box_key() of every box of an order_boxes() list, in its order (oldest first). */
	private static function box_keys( array $boxes ) {
		$keys = [];
		foreach ( $boxes as $box ) {
			if ( is_array( $box ) && isset( $box['tracking_number'] ) ) {
				$keys[] = self::box_key( $box['tracking_number'] );
			}
		}
		return $keys;
	}
}
