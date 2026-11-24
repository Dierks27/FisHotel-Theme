<?php
/**
 * Packing's box, filled in on the Shipments meta box.
 *
 * The Packing section of the FisHotel Misc plugin picks the box (and the
 * bag of every fish) of an order. Since v1.27.0 the Add Shipment form
 * starts on that box, so it is corrected when it is wrong instead of
 * entered a second time. This class is the theme's side of that: it asks
 * Misc for the order's plan — fishotel_packing_shipment_plan( $order_id ),
 * Misc v0.46.15+ — and decides which entry of the box size dropdown the
 * plan selects.
 *
 * Nothing here writes, and nothing is recorded until Add Shipment (or a
 * card's Save) is clicked. With Misc missing, older, switched off, failing
 * or answering something else than a plan, every method answers "nothing"
 * and the Shipments box is exactly the one of v1.26.
 *
 * The bags are not counted here. Misc answers ShipTracker's own question
 * ("how many fish are in this box?", the `fst_box_fish_count` filter) with
 * the bags to pull — every bag doubled, tripled for a fish with spines —
 * so the fish bag line of a packing block already holds that number. What
 * this class adds is the box's own number of bags — used while no item is
 * ticked, or exactly the box's lines are — the lines Packing put in the
 * box, and the words that say where the number comes from.
 *
 * Everything above the "Misc / WooCommerce readers" divider needs neither
 * WordPress nor Misc and is covered by `php tests/packing-prefill-test.php`.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Packing_Prefill {

	/** Value of the "Custom…" entry in the box size dropdown (FisHotel_Shipment_Cost::CUSTOM_BOX). */
	const CUSTOM_BOX = 'custom';

	/** What the fish bag line says once its number comes from Packing. */
	const BAG_NOTE = 'Double-bagged, triple for spiny fish — from Packing';

	// ── Pure helpers ────────────────────────────────────────────────────

	/**
	 * "LxWxH" as three numbers — null when it is not a box size. Read the
	 * way both plugins write one: sizes above 0, two decimals at most.
	 *
	 * @param mixed $dims "10x10x10", "12.5x9x6".
	 * @return float[]|null
	 */
	public static function dims_numbers( $dims ) {
		if ( is_bool( $dims ) || ! is_scalar( $dims ) ) {
			return null;
		}
		if ( ! preg_match( '/^(\d{1,4}(?:\.\d{1,2})?)x(\d{1,4}(?:\.\d{1,2})?)x(\d{1,4}(?:\.\d{1,2})?)$/', strtolower( trim( (string) $dims ) ), $m ) ) {
			return null;
		}
		$numbers = [ (float) $m[1], (float) $m[2], (float) $m[3] ];
		foreach ( $numbers as $number ) {
			if ( $number <= 0 ) {
				return null;
			}
		}
		return $numbers;
	}

	/** Three numbers as "LxWxH" without trailing zeros: 12.50 x 9 x 6 is "12.5x9x6". */
	public static function dims_text( array $numbers ) {
		$parts = [];
		foreach ( $numbers as $number ) {
			$text    = number_format( (float) $number, 2, '.', '' );
			$parts[] = rtrim( rtrim( $text, '0' ), '.' );
		}
		return implode( 'x', $parts );
	}

	/** Two sizes are the same size, however they are spelled: "10x10x10" and "10.0x10x10". */
	public static function same_dims( $a, $b ) {
		$a = self::dims_numbers( $a );
		$b = self::dims_numbers( $b );
		if ( null === $a || null === $b ) {
			return false;
		}
		for ( $i = 0; $i < 3; $i++ ) {
			if ( abs( $a[ $i ] - $b[ $i ] ) > 0.001 ) {
				return false;
			}
		}
		return true;
	}

	/** A name for comparing: trimmed, letter case set aside — as ShipTracker compares box names. */
	private static function lower( $text ) {
		$text = trim( (string) $text );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}

	/** A value of the plan as trimmed text; '' for anything that is not text or a number. */
	private static function text_of( $row, $key ) {
		if ( ! is_array( $row ) || ! isset( $row[ $key ] ) || is_bool( $row[ $key ] ) || ! is_scalar( $row[ $key ] ) ) {
			return '';
		}
		return trim( (string) $row[ $key ] );
	}

	/**
	 * Misc's answer reduced to what the Shipments box uses. Null — nothing
	 * to go on — unless Misc says the order has a plan (`available` true)
	 * and every box it lists has a size.
	 *
	 * A plan may list no box: Packing has no box that takes the bags, or
	 * the order is a source order folded into a combined box (the boxes are
	 * on the combined order). Nothing is filled in then; the bags of the
	 * fish bag line are still Packing's.
	 *
	 * @param mixed $raw fishotel_packing_shipment_plan() result.
	 * @return array|null { state: 'plan', from: 'confirmed' | 'suggestion',
	 *                    boxes: [ { name, dims, standard_name, layers ("14", or '' when not told),
	 *                    items: the order lines in the box, as "<order id>-<item id>" } ] }
	 *                    or { state: 'folded', packed_with: int }.
	 */
	public static function clean_plan( $raw ) {
		if ( ! is_array( $raw ) || ! isset( $raw['available'] ) || true !== $raw['available'] ) {
			return null;
		}

		// A source order folded into a combined box has no boxes of its own.
		$packed_with = isset( $raw['packed_with'] ) && is_scalar( $raw['packed_with'] ) && ! is_bool( $raw['packed_with'] ) && is_numeric( $raw['packed_with'] )
			? (int) $raw['packed_with']
			: 0;
		if ( $packed_with > 0 ) {
			return [
				'state'       => 'folded',
				'packed_with' => $packed_with,
			];
		}

		if ( ! isset( $raw['boxes'] ) || ! is_array( $raw['boxes'] ) ) {
			return null;
		}

		$boxes = [];
		foreach ( $raw['boxes'] as $box ) {
			$numbers = is_array( $box ) && isset( $box['dims'] ) ? self::dims_numbers( $box['dims'] ) : null;
			if ( null === $numbers ) {
				// One box that cannot be read makes "Box 2 of 3" a guess: no plan.
				return null;
			}
			$layers  = isset( $box['layers'] ) && ! is_bool( $box['layers'] ) && is_numeric( $box['layers'] ) && (float) $box['layers'] >= 0
				? rtrim( rtrim( number_format( (float) $box['layers'], 2, '.', '' ), '0' ), '.' )
				: '';
			$items = [];
			foreach ( isset( $box['items'] ) && is_array( $box['items'] ) ? array_keys( $box['items'] ) : [] as $line ) {
				if ( is_string( $line ) && preg_match( '/^\d+-\d+$/', $line ) ) {
					$items[] = $line;
				}
			}
			$boxes[] = [
				'name'          => self::text_of( $box, 'name' ),
				'dims'          => self::dims_text( $numbers ),
				'standard_name' => isset( $box['standard'] ) ? self::text_of( $box['standard'], 'name' ) : '',
				'layers'        => $layers,
				'items'         => $items,
			];
		}

		return [
			'state' => 'plan',
			'from'  => 'confirmed' === self::text_of( $raw, 'from' ) ? 'confirmed' : 'suggestion',
			'boxes' => $boxes,
		];
	}

	/**
	 * Which entry of the box size dropdown a Packing box selects:
	 *  1. the standard box with its NAME — the one Misc matched it to, else
	 *     the name Packing has for it. Two standard boxes may share a size,
	 *     and the packing of a box is filled from the one picked;
	 *  2. else the first standard box of its size;
	 *  3. else "Custom…", with the size typed in.
	 *
	 * @param array $plan_box       clean_plan() box.
	 * @param array $standard_boxes FisHotel_Shipment_Cost::standard_boxes() — [ { name, dims } ].
	 * @return array { value (the option's value: a standard size, or "custom"), name (the standard
	 *               box's name, '' for custom), dims (the size typed in, '' unless custom),
	 *               match: name | dims | custom }
	 */
	public static function box_option( array $plan_box, array $standard_boxes ) {
		$dims = isset( $plan_box['dims'] ) ? (string) $plan_box['dims'] : '';

		foreach ( [ 'standard_name', 'name' ] as $key ) {
			$wanted = isset( $plan_box[ $key ] ) ? self::lower( $plan_box[ $key ] ) : '';
			if ( '' === $wanted ) {
				continue;
			}
			foreach ( $standard_boxes as $box ) {
				if ( isset( $box['name'], $box['dims'] ) && self::lower( $box['name'] ) === $wanted ) {
					return [
						'value' => (string) $box['dims'],
						'name'  => (string) $box['name'],
						'dims'  => '',
						'match' => 'name',
					];
				}
			}
		}

		foreach ( $standard_boxes as $box ) {
			if ( isset( $box['name'], $box['dims'] ) && self::same_dims( $box['dims'], $dims ) ) {
				return [
					'value' => (string) $box['dims'],
					'name'  => (string) $box['name'],
					'dims'  => '',
					'match' => 'dims',
				];
			}
		}

		return [
			'value' => self::CUSTOM_BOX,
			'name'  => '',
			'dims'  => $dims,
			'match' => 'custom',
		];
	}

	/**
	 * The order lines each recorded box holds, from the shipment rows of
	 * the view: tracking number (as FisHotel_Shipment_Cost::box_key() spells
	 * it) => [ "<order id>-<item id>" ]. Null for a box with a row that
	 * lists no lines — it covers every line of its order, so nothing can be
	 * told from it.
	 *
	 * @param array $shipments FST_Shipment rows: tracking_number, order_id, line_item_ids
	 *                         (a list, or the JSON ShipTracker stores).
	 * @return array box key => string[]|null
	 */
	public static function box_lines( array $shipments ) {
		$lines = [];
		foreach ( $shipments as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->tracking_number ) || ! is_scalar( $row->tracking_number ) ) {
				continue;
			}
			$key = strtoupper( trim( (string) $row->tracking_number ) );
			if ( '' === $key ) {
				continue;
			}
			$ids      = isset( $row->line_item_ids ) ? $row->line_item_ids : null;
			$ids      = is_string( $ids ) && '' !== $ids ? json_decode( $ids, true ) : $ids;
			$order_id = isset( $row->order_id ) && is_numeric( $row->order_id ) ? (int) $row->order_id : 0;
			if ( ! is_array( $ids ) || empty( $ids ) || $order_id <= 0 ) {
				$lines[ $key ] = null;
				continue;
			}
			if ( array_key_exists( $key, $lines ) && null === $lines[ $key ] ) {
				continue;
			}
			foreach ( $ids as $id ) {
				if ( is_scalar( $id ) && (int) $id > 0 ) {
					$lines[ $key ][] = $order_id . '-' . (int) $id;
				}
			}
		}
		return $lines;
	}

	/**
	 * Which of Packing's boxes the boxes already on the order used up, and
	 * which one is next.
	 *
	 * The theme cannot know which physical box a tracking number is. So
	 * boxes of the same size are taken in Packing's order: a recorded box
	 * with a size uses up the first Packing box of that size nobody used
	 * yet. Then every other recorded box takes the next one that is left,
	 * oldest first: a box recorded with a size Packing never planned (the
	 * owner corrected it — it still was one of the boxes), and a box
	 * recorded with no size (that is the box its "Use Packing's box" link
	 * offers).
	 *
	 * One kind of recorded box uses up none: a box known to hold only lines
	 * that are in none of Packing's boxes — dry goods sent ahead of the
	 * fish, or lines Packing no longer plans. It was never one of them.
	 *
	 * @param array[]  $options   box_option() of each Packing box, in Packing's order.
	 * @param array[]  $recorded  FST_Shipment::get_order_boxes() entries, oldest first.
	 * @param array    $box_lines box_lines(): what each recorded box holds; a box that is not
	 *                            listed (or null) may hold anything.
	 * @param string[] $planned   Every order line in one of Packing's boxes ("<order id>-<item id>");
	 *                            [] = not told, and then no recorded box is left out.
	 * @return array { next: int|null (position in $options of the box to fill in next),
	 *               cards: FisHotel_Shipment_Cost::box_key() => position, for the recorded boxes
	 *               with no size }
	 */
	public static function assign( array $options, array $recorded, array $box_lines = [], array $planned = [] ) {
		$options = array_values( $options );
		$used    = [];
		$sizes   = [];
		foreach ( $options as $option ) {
			$sizes[] = self::CUSTOM_BOX === $option['value'] ? $option['dims'] : $option['value'];
		}

		// [ box key, has no size ] of the recorded boxes that claimed none by their size.
		$waiting = [];
		foreach ( $recorded as $box ) {
			if ( ! is_array( $box ) || ! isset( $box['tracking_number'] ) ) {
				continue;
			}
			$key  = strtoupper( trim( (string) $box['tracking_number'] ) );
			$held = isset( $box_lines[ $key ] ) && is_array( $box_lines[ $key ] ) ? $box_lines[ $key ] : [];
			if ( $held && $planned && ! array_intersect( $held, $planned ) ) {
				continue;
			}

			$dims    = isset( $box['box_dims'] ) ? trim( (string) $box['box_dims'] ) : '';
			$claimed = false;
			foreach ( '' === $dims ? [] : $sizes as $position => $size ) {
				if ( ! isset( $used[ $position ] ) && self::same_dims( $size, $dims ) ) {
					$used[ $position ] = true;
					$claimed           = true;
					break;
				}
			}
			if ( ! $claimed ) {
				$waiting[] = [ $key, '' === $dims ];
			}
		}

		$cards = [];
		foreach ( $waiting as $one ) {
			foreach ( $sizes as $position => $size ) {
				if ( ! isset( $used[ $position ] ) ) {
					$used[ $position ] = true;
					if ( $one[1] && '' !== $one[0] ) {
						$cards[ $one[0] ] = $position;
					}
					break;
				}
			}
		}

		$next = null;
		foreach ( $sizes as $position => $size ) {
			if ( ! isset( $used[ $position ] ) ) {
				$next = $position;
				break;
			}
		}

		return [
			'next'  => $next,
			'cards' => $cards,
		];
	}

	/** "From Packing (confirmed)" once the plan was confirmed on the order, else "From Packing (suggested)". */
	public static function from_label( $from ) {
		return 'confirmed' === $from ? 'From Packing (confirmed)' : 'From Packing (suggested)';
	}

	/** "Box 2 of 3" for an order that goes out in several boxes; '' for one box. */
	public static function box_of( $position, $count ) {
		$count = (int) $count;
		return $count > 1 ? sprintf( 'Box %1$d of %2$d', (int) $position + 1, $count ) : '';
	}

	/**
	 * One Packing box as a form uses it: the entry it selects and the words
	 * around it.
	 *
	 * @param array  $plan_box clean_plan() box.
	 * @param array  $option   box_option() of it.
	 * @param string $from     confirmed | suggestion.
	 * @param int    $position Its place among Packing's boxes (0 = first).
	 * @param int    $count    How many boxes Packing planned.
	 * @param bool   $has_list ShipTracker has standard boxes saved.
	 * @param bool   $on       The form starts on it (Add Shipment); false = it is only offered.
	 * @return array { on, value, name, dims, match, layers, items, label, use }
	 */
	public static function entry( array $plan_box, array $option, $from, $position, $count, $has_list, $on ) {
		$label  = self::from_label( $from );
		$box_of = self::box_of( $position, $count );
		if ( '' !== $box_of ) {
			$label .= ' · ' . $box_of;
		}

		$name = isset( $plan_box['name'] ) ? (string) $plan_box['name'] : '';
		if ( 'custom' === $option['match'] ) {
			// The size is typed in: say whose box it is, and why it is typed.
			if ( $has_list ) {
				$label .= ' — ' . ( '' === $name ? 'this size' : '“' . $name . '”' ) . ' is not on your standard box list, so its size is typed in';
			} elseif ( '' !== $name ) {
				$label .= ' — “' . $name . '”';
			}
			$size = '' === $name ? $option['dims'] : $name . ' (' . $option['dims'] . ')';
		} else {
			$size = $option['name'] . ' (' . $option['value'] . ')';
		}

		return [
			'on'     => (bool) $on,
			'value'  => $option['value'],
			'name'   => $option['name'],
			'dims'   => $option['dims'],
			'match'  => $option['match'],
			'layers' => isset( $plan_box['layers'] ) ? (string) $plan_box['layers'] : '',
			'items'  => isset( $plan_box['items'] ) && is_array( $plan_box['items'] ) ? array_values( $plan_box['items'] ) : [],
			'label'  => $label,
			'use'    => 'Use Packing\'s box: ' . $size,
		];
	}

	/**
	 * What the Shipments box does with a plan.
	 *
	 * @param array|null $plan           clean_plan() result.
	 * @param array      $standard_boxes FisHotel_Shipment_Cost::standard_boxes().
	 * @param array      $recorded       FisHotel_Shipment_Cost::order_boxes() of the view.
	 * @param array      $box_lines      box_lines() of the view's shipments.
	 * @return array|null Null = nothing (the box of v1.26). Else
	 *                    { state: 'folded', packed_with, bag_note } or
	 *                    { state: 'plan', from,
	 *                      add: entry() the Add Shipment form starts on — null when Packing
	 *                           lists no box, or once every one of them is used up,
	 *                      cards: box_key() => entry() offered to a recorded box with no size,
	 *                      bag_note }.
	 */
	public static function view( $plan, array $standard_boxes, array $recorded, array $box_lines = [] ) {
		if ( ! is_array( $plan ) || ! isset( $plan['state'] ) ) {
			return null;
		}
		if ( 'folded' === $plan['state'] ) {
			return [
				'state'       => 'folded',
				'packed_with' => (int) $plan['packed_with'],
				'bag_note'    => self::BAG_NOTE,
			];
		}
		if ( 'plan' !== $plan['state'] || ! isset( $plan['boxes'] ) || ! is_array( $plan['boxes'] ) ) {
			return null;
		}

		$boxes   = array_values( $plan['boxes'] );
		$options = [];
		$planned = [];
		foreach ( $boxes as $box ) {
			$options[] = self::box_option( $box, $standard_boxes );
			$planned   = array_merge( $planned, isset( $box['items'] ) && is_array( $box['items'] ) ? $box['items'] : [] );
		}
		$assigned = self::assign( $options, $recorded, $box_lines, array_values( array_unique( $planned ) ) );
		$count    = count( $boxes );
		$has_list = ! empty( $standard_boxes );

		$view = [
			'state'    => 'plan',
			'from'     => $plan['from'],
			'add'      => null,
			'cards'    => [],
			'bag_note' => self::BAG_NOTE,
		];
		if ( null !== $assigned['next'] ) {
			$at          = $assigned['next'];
			$view['add'] = self::entry( $boxes[ $at ], $options[ $at ], $plan['from'], $at, $count, $has_list, true );
		}
		foreach ( $assigned['cards'] as $key => $at ) {
			$view['cards'][ $key ] = self::entry( $boxes[ $at ], $options[ $at ], $plan['from'], $at, $count, $has_list, false );
		}
		return $view;
	}

	// ── Misc / WooCommerce readers ──────────────────────────────────────

	/**
	 * The plan Packing has for an order — a fulfillment (combined) order is
	 * asked by its own id. Null when Misc cannot be asked, fails or answers
	 * anything else than a plan.
	 *
	 * @param int $order_id
	 * @return array|null clean_plan() shape.
	 */
	public static function plan( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! function_exists( 'fishotel_packing_shipment_plan' ) ) {
			return null;
		}
		try {
			return self::clean_plan( fishotel_packing_shipment_plan( $order_id ) );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * view() for the order on screen. For a source order that was packed
	 * with a combined box, also where that box is: `number` and `url` ('' when
	 * the order is gone).
	 *
	 * @param mixed $order          The order on screen.
	 * @param array $standard_boxes FisHotel_Shipment_Cost::standard_boxes().
	 * @param array $recorded       FisHotel_Shipment_Cost::order_boxes() of the view.
	 * @param array $shipments      The view's shipment rows (FisHotel_Shipments_Metabox::resolve_shipments()).
	 * @return array|null
	 */
	public static function for_order( $order, array $standard_boxes, array $recorded, array $shipments = [] ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return null;
		}
		try {
			$view = self::view( self::plan( $order->get_id() ), $standard_boxes, $recorded, self::box_lines( $shipments ) );
			if ( is_array( $view ) && 'folded' === $view['state'] ) {
				$view['number'] = (string) $view['packed_with'];
				$view['url']    = '';
				$combined       = function_exists( 'wc_get_order' ) ? wc_get_order( $view['packed_with'] ) : null;
				if ( is_object( $combined ) && method_exists( $combined, 'get_edit_order_url' ) ) {
					$view['number'] = (string) $combined->get_order_number();
					$view['url']    = (string) $combined->get_edit_order_url();
				}
			}
			return $view;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
