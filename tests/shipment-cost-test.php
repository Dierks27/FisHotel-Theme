<?php
/**
 * Stub-based test for inc/order-fulfillment/class-fishotel-shipment-cost.php
 * — no WordPress, no ShipTracker, no database.
 *
 *   php tests/shipment-cost-test.php
 *
 * Covers: the money helpers (whole cents, no float drift), the standard box
 * / label source lists as the dropdowns use them, the card text ("Label cost
 * … · size · source", "No cost recorded"), which card of a shared box shows
 * the cost (once), an order's shipping split over its boxes, and the line
 * under the list — a box counted once, a missing cost never treated as 0, a
 * recorded 0.00 kept, the difference and its red flag, a box shared with
 * orders outside the view.
 *
 * Also the capability probe: no ShipTracker, an older ShipTracker (run in a
 * child process, `--old-shiptracker`, because a class cannot be redefined)
 * and one that records label costs, read through stubs of its public API.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/inc/order-fulfillment/class-fishotel-shipment-cost.php';

$failures = 0;
function check( $label, $ok, $detail = '' ) {
	global $failures;
	if ( $ok ) {
		echo "  ok   $label\n";
	} else {
		$failures++;
		echo "  FAIL $label" . ( $detail !== '' ? " — $detail" : '' ) . "\n";
	}
}
function finish() {
	global $failures;
	echo "\n" . ( $failures ? "$failures FAILED\n" : "all passed\n" );
	exit( $failures ? 1 : 0 );
}
/** A get_order_boxes()-shaped box. */
function box( $tracking, $cost, array $order_ids, $dims = null, $source = null, $weight = null ) {
	return [
		'tracking_number'  => $tracking,
		'label_cost'       => $cost,
		'box_weight'       => $weight,
		'box_dims'         => $dims,
		'label_source'     => $source,
		'shared_box'       => count( $order_ids ) > 1,
		'order_ids'        => $order_ids,
		'shipment_ids'     => [],
		'cost_shipment_id' => null === $cost ? 0 : 1,
	];
}
/** A shipment row as the meta box lists it. */
function row( $id, $order_id, $tracking ) {
	return (object) [ 'id' => $id, 'order_id' => $order_id, 'tracking_number' => $tracking ];
}
function paid( $shipping, array $box_keys = [] ) {
	return [ 'shipping' => $shipping, 'box_keys' => $box_keys ];
}

$C = 'FisHotel_Shipment_Cost';

// ── An older ShipTracker (child process) ────────────────────────────────
if ( isset( $argv[1] ) && '--old-shiptracker' === $argv[1] ) {
	// v1.9.16 and earlier: the classes exist, the label cost API does not.
	class FST_Shipment {
		public static function get_by_order( $order_id ) {
			return [];
		}
	}
	class FST_Settings {}

	echo "older ShipTracker\n";
	check( 'supported() is false without get_order_boxes()', false === FisHotel_Shipment_Cost::supported() );
	check( 'order_boxes() → []',    [] === FisHotel_Shipment_Cost::order_boxes( [ 101 ] ) );
	check( 'standard_boxes() → []', [] === FisHotel_Shipment_Cost::standard_boxes() );
	check( 'label_sources() → []',  [] === FisHotel_Shipment_Cost::label_sources() );
	finish();
}

// ── No ShipTracker at all ───────────────────────────────────────────────
echo "no ShipTracker\n";
check( 'supported() is false',  false === FisHotel_Shipment_Cost::supported() );
check( 'order_boxes() → []',    [] === FisHotel_Shipment_Cost::order_boxes( [ 101 ] ) );
check( 'standard_boxes() → []', [] === FisHotel_Shipment_Cost::standard_boxes() );
check( 'label_sources() → []',  [] === FisHotel_Shipment_Cost::label_sources() );

// ── Money helpers ───────────────────────────────────────────────────────
echo "money\n";
check( 'to_cents: "53.73"',                    5373 === FisHotel_Shipment_Cost::to_cents( '53.73' ) );
check( 'to_cents: float 19.99 does not drift', 1999 === FisHotel_Shipment_Cost::to_cents( 19.99 ) );
check( 'to_cents: 0.1 + 0.2',                  30 === FisHotel_Shipment_Cost::to_cents( 0.1 + 0.2 ) );
check( 'to_cents: "0.00"',                     0 === FisHotel_Shipment_Cost::to_cents( '0.00' ) );
check( 'from_cents: 5373',                     '53.73' === FisHotel_Shipment_Cost::from_cents( 5373 ) );
check( 'from_cents: 5 → "0.05"',               '0.05' === FisHotel_Shipment_Cost::from_cents( 5 ) );
check( 'from_cents: negative',                 '-8.73' === FisHotel_Shipment_Cost::from_cents( -873 ) );
check( 'from_cents: -5 → "-0.05"',             '-0.05' === FisHotel_Shipment_Cost::from_cents( -5 ) );
check( 'from_cents: 0',                        '0.00' === FisHotel_Shipment_Cost::from_cents( 0 ) );
check( 'trim_decimal: "11.00" → "11"',         '11' === FisHotel_Shipment_Cost::trim_decimal( '11.00' ) );
check( 'trim_decimal: "12.50" → "12.5"',       '12.5' === FisHotel_Shipment_Cost::trim_decimal( '12.50' ) );
check( 'trim_decimal: "100" stays',            '100' === FisHotel_Shipment_Cost::trim_decimal( '100' ) );
check( 'box_key: trims + ignores letter case', '1ZABC' === FisHotel_Shipment_Cost::box_key( " 1zAbc\n" ) );

// ── Lists for the dropdowns ─────────────────────────────────────────────
echo "clean_boxes() / clean_sources()\n";
$raw_boxes = [
	[ 'name' => 'Small', 'length' => '10', 'width' => '10', 'height' => '10', 'dims' => '10x10x10' ],
	[ 'name' => ' Tall ', 'dims' => '10.5x8x16' ],
	[ 'name' => '', 'dims' => '12x12x12' ],
	[ 'name' => 'No size' ],
	[ 'name' => 'Bad size', 'dims' => 'custom' ],
	[ 'name' => 'Two sizes', 'dims' => '10x10' ],
	[ 'name' => [ 'x' ], 'dims' => '10x10x10' ],
	'Small',
];
$boxes = FisHotel_Shipment_Cost::clean_boxes( $raw_boxes );
check( 'named boxes with an LxWxH size are kept, the rest dropped', [ [ 'name' => 'Small', 'dims' => '10x10x10' ], [ 'name' => 'Tall', 'dims' => '10.5x8x16' ] ] === $boxes, json_encode( $boxes ) );
check( 'not a list → []', [] === FisHotel_Shipment_Cost::clean_boxes( null ) && [] === FisHotel_Shipment_Cost::clean_boxes( 'Small' ) );
check( 'nothing saved → []', [] === FisHotel_Shipment_Cost::clean_boxes( [] ) );

$sources = FisHotel_Shipment_Cost::clean_sources( [ 'Partner A', ' Partner B ', '', 'Partner A', [ 'x' ], null, true ] );
check( 'sources: trimmed, no blanks, no repeats, no non-text', [ 'Partner A', 'Partner B' ] === $sources, json_encode( $sources ) );
check( 'sources: not a list → []', [] === FisHotel_Shipment_Cost::clean_sources( 'Partner A' ) );

check( 'size_label: a standard box shows its name',  'Small (10x10x10)' === FisHotel_Shipment_Cost::size_label( '10x10x10', $boxes ) );
check( 'size_label: any other size shows as it is',  '12x9x6' === FisHotel_Shipment_Cost::size_label( '12x9x6', $boxes ) );
check( 'size_label: none',                           '' === FisHotel_Shipment_Cost::size_label( null, $boxes ) );
check( 'box_choice: nothing recorded → ""',          '' === FisHotel_Shipment_Cost::box_choice( null, $boxes ) );
check( 'box_choice: a standard size selects it',     '10x10x10' === FisHotel_Shipment_Cost::box_choice( '10x10x10', $boxes ) );
check( 'box_choice: any other size → Custom',        'custom' === FisHotel_Shipment_Cost::box_choice( '12x9x6', $boxes ) );
check( 'box_choice: no standard boxes → Custom',     'custom' === FisHotel_Shipment_Cost::box_choice( '10x10x10', [] ) );

// ── describe_box() ──────────────────────────────────────────────────────
echo "describe_box()\n";
$d = FisHotel_Shipment_Cost::describe_box( box( '1Z1', '53.73', [ 101 ], '10x10x10', 'Partner A' ), [] );
check( 'cost · size · source', [ 'cost' => '53.73', 'details' => [ '10x10x10', 'Partner A' ] ] === $d, json_encode( $d ) );
$d = FisHotel_Shipment_Cost::describe_box( box( '1Z1', '18.4', [ 101 ], '10x10x10', 'Partner B', '11.00' ), $boxes );
check( 'two decimals, standard box name, weight in lb', [ 'cost' => '18.40', 'details' => [ 'Small (10x10x10)', '11 lb', 'Partner B' ] ] === $d, json_encode( $d ) );
$d = FisHotel_Shipment_Cost::describe_box( box( '1Z1', null, [ 101 ] ), $boxes );
check( 'nothing recorded → no cost, no details', [ 'cost' => null, 'details' => [] ] === $d );
$d = FisHotel_Shipment_Cost::describe_box( box( '1Z1', null, [ 101 ], '12x9x6', 'Partner A' ), $boxes );
check( 'size and source without a cost → still "no cost"', null === $d['cost'] && [ '12x9x6', 'Partner A' ] === $d['details'] );
$d = FisHotel_Shipment_Cost::describe_box( box( '1Z1', '0.00', [ 101 ] ), $boxes );
check( 'a recorded 0.00 is a cost, not "no cost"', '0.00' === $d['cost'] );
check( 'no box → no cost', [ 'cost' => null, 'details' => [] ] === FisHotel_Shipment_Cost::describe_box( null, $boxes ) );
$d = FisHotel_Shipment_Cost::describe_box( [ 'label_cost' => '', 'box_dims' => '', 'box_weight' => '', 'label_source' => '' ], $boxes );
check( 'empty strings are not recorded values', [ 'cost' => null, 'details' => [] ] === $d );

// ── plan_cards() ────────────────────────────────────────────────────────
echo "plan_cards()\n";
$plan = FisHotel_Shipment_Cost::plan_cards( [ row( 7, 101, '1ZAAA' ) ], [ box( '1ZAAA', '53.73', [ 101 ] ) ] );
check( 'one order, one box: the card shows the cost', true === $plan[7]['primary'] && '53.73' === $plan[7]['box']['label_cost'] && [] === $plan[7]['other_order_ids'] );

// A fulfillment of orders 101 + 102: one box (two cards), plus a second box for 102 only.
$shared = box( '1ZSHARED', '53.73', [ 101, 102 ], '10x10x10', 'Partner A' );
$solo   = box( '1ZSOLO', null, [ 102 ] );
$plan   = FisHotel_Shipment_Cost::plan_cards(
	[ row( 11, 101, '1ZSHARED' ), row( 13, 102, '1ZSOLO' ), row( 12, 102, '1zshared' ) ],
	[ $shared, $solo ]
);
check( 'shared box: the first card shows the cost',               true === $plan[11]['primary'] );
check( 'shared box: the second card does not (cost shown once)',  false === $plan[12]['primary'] );
check( 'shared box: both cards know the box',                     '53.73' === $plan[11]['box']['label_cost'] && '53.73' === $plan[12]['box']['label_cost'] );
check( 'shared box: every order of the box is listed',            [ 101, 102 ] === $plan[11]['order_ids'] );
check( 'shared box: "other" orders leave out the card\'s own',    [ 102 ] === $plan[11]['other_order_ids'] && [ 101 ] === $plan[12]['other_order_ids'] );
check( 'a different box in between is its own primary',           true === $plan[13]['primary'] && [ 102 ] === $plan[13]['order_ids'] );
check( 'exactly one card per box shows the cost',                 2 === count( array_filter( array_column( $plan, 'primary' ) ) ) );

$plan = FisHotel_Shipment_Cost::plan_cards( [ row( 20, 101, '1ZGONE' ), 'junk', (object) [ 'order_id' => 1 ] ], [] );
check( 'a shipment ShipTracker returned no box for still gets a card', isset( $plan[20] ) && null === $plan[20]['box'] && true === $plan[20]['primary'] && [] === $plan[20]['order_ids'] );
check( 'rows without an id are skipped', 1 === count( $plan ) );

// Viewing source order 101 on its own: the box is shared with 102, outside the view.
$plan = FisHotel_Shipment_Cost::plan_cards( [ row( 11, 101, '1ZSHARED' ) ], [ $shared ] );
check( 'a box shared with an order outside the view says so', true === $plan[11]['primary'] && [ 102 ] === $plan[11]['other_order_ids'] );

// ── share_cents() ───────────────────────────────────────────────────────
echo "share_cents()\n";
check( 'one box: all of it',                      4500 === FisHotel_Shipment_Cost::share_cents( 4500, [ 'A' ], [ 'A' => true ] ) );
check( 'two boxes, both wanted: all of it',       4501 === FisHotel_Shipment_Cost::share_cents( 4501, [ 'A', 'B' ], [ 'A' => true, 'B' => true ] ) );
check( 'two boxes: the spare cent goes to the earliest', 2251 === FisHotel_Shipment_Cost::share_cents( 4501, [ 'A', 'B' ], [ 'A' => true ] ) && 2250 === FisHotel_Shipment_Cost::share_cents( 4501, [ 'A', 'B' ], [ 'B' => true ] ) );
check( 'three boxes: 34 + 33 + 33',               34 === FisHotel_Shipment_Cost::share_cents( 100, [ 'A', 'B', 'C' ], [ 'A' => true ] ) && 66 === FisHotel_Shipment_Cost::share_cents( 100, [ 'A', 'B', 'C' ], [ 'B' => true, 'C' => true ] ) );
check( 'none of its boxes wanted: nothing',       0 === FisHotel_Shipment_Cost::share_cents( 4500, [ 'A', 'B' ], [ 'Z' => true ] ) );
check( 'boxes unknown: all of it',                4500 === FisHotel_Shipment_Cost::share_cents( 4500, [], [ 'A' => true ] ) );
check( 'a box listed twice counts once',          2250 === FisHotel_Shipment_Cost::share_cents( 4500, [ 'a', 'A ', 'B' ], [ 'A' => true ] ) );
check( 'never negative',                          0 === FisHotel_Shipment_Cost::share_cents( -500, [ 'A' ], [ 'A' => true ] ) );

// ── summarize() ─────────────────────────────────────────────────────────
echo "summarize()\n";
$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', '53.73', [ 101 ] ) ], [ 101 => paid( '45.00' ) ], [ 101 ] );
check( 'the label cost more than was paid: difference -8.73, flagged', '45.00' === $s['paid'] && '53.73' === $s['cost'] && '-8.73' === $s['difference'] && true === $s['loss'] && true === $s['complete'], json_encode( $s ) );
check( 'counts: 1 box, 1 with a cost, 0 without', 1 === $s['boxes'] && 1 === $s['boxes_with_cost'] && 0 === $s['boxes_without_cost'] );

$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', '53.73', [ 101 ] ) ], [ 101 => paid( '60.00' ) ], [ 101 ] );
check( 'paid more than the label: +6.27, not flagged', '6.27' === $s['difference'] && false === $s['loss'] );

$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', '45.00', [ 101 ] ) ], [ 101 => paid( 45.0 ) ], [ 101 ] );
check( 'break-even is not a loss', '0.00' === $s['difference'] && false === $s['loss'] );

$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', null, [ 101 ] ) ], [ 101 => paid( '45.00' ) ], [ 101 ] );
check( 'no cost recorded: no total, no difference — never 0', null === $s['cost'] && null === $s['difference'] && false === $s['loss'], json_encode( $s ) );
check( 'no cost recorded: 1 box without a cost, not complete', 1 === $s['boxes_without_cost'] && false === $s['complete'] && '45.00' === $s['paid'] );

$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', '0.00', [ 101 ] ) ], [ 101 => paid( '45.00' ) ], [ 101 ] );
check( 'a recorded 0.00 is a cost: counted, difference = paid', '0.00' === $s['cost'] && '45.00' === $s['difference'] && 1 === $s['boxes_with_cost'] && true === $s['complete'] );

$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', '50.00', [ 101 ] ), box( '1ZBBB', null, [ 101 ] ) ], [ 101 => paid( '60.00' ) ], [ 101 ] );
check( 'two boxes, one cost: the difference is only "so far"', '50.00' === $s['cost'] && '10.00' === $s['difference'] && false === $s['complete'] && 1 === $s['boxes_without_cost'] && 2 === $s['boxes'] );

$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', '50.00', [ 101 ] ), box( '1ZBBB', '21.5', [ 101 ] ) ], [ 101 => paid( '60.00' ) ], [ 101 ] );
check( 'two boxes, both costs: added up against the one shipping charge', '71.50' === $s['cost'] && '-11.50' === $s['difference'] && true === $s['loss'] && true === $s['complete'] );

$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', '0.10', [ 101 ] ), box( '1ZBBB', '0.20', [ 101 ] ) ], [ 101 => paid( '0.30' ) ], [ 101 ] );
check( 'cents add up exactly (0.10 + 0.20 against 0.30)', '0.30' === $s['cost'] && '0.00' === $s['difference'] && false === $s['loss'] );

// Fulfillment of 101 + 102, one shared box: counted once, both orders' shipping.
$s = FisHotel_Shipment_Cost::summarize( [ $shared ], [ 101 => paid( '25.00' ), 102 => paid( '20.00' ) ], [ 101, 102 ] );
check( 'shared box in a fulfillment: its cost once, both orders\' shipping', '53.73' === $s['cost'] && '45.00' === $s['paid'] && '-8.73' === $s['difference'] && 1 === $s['boxes'] );
check( 'both orders are in the view: nothing from "other" orders', [] === $s['other_order_ids'] && '0.00' === $s['paid_others'] && '45.00' === $s['paid_view'] );

$s = FisHotel_Shipment_Cost::summarize( [ $shared, box( '1zShared ', '53.73', [ 101, 102 ] ) ], [ 101 => paid( '25.00' ), 102 => paid( '20.00' ) ], [ 101, 102 ] );
check( 'the same box listed twice is still one box', 1 === $s['boxes'] && '53.73' === $s['cost'] );

// Viewing source order 101 alone: 102 shares the box from outside the view.
$s = FisHotel_Shipment_Cost::summarize( [ $shared ], [ 101 => paid( '25.00' ), 102 => paid( '20.00', [ '1ZSHARED' ] ) ], [ 101 ] );
check( 'shared with an order outside the view: its shipping counts towards the box', '45.00' === $s['paid'] && '25.00' === $s['paid_view'] && '20.00' === $s['paid_others'] && [ 102 ] === $s['other_order_ids'] && '-8.73' === $s['difference'] );

$s = FisHotel_Shipment_Cost::summarize( [ $shared ], [ 101 => paid( '25.00' ), 102 => paid( '40.01', [ '1ZSHARED', '1ZOTHER' ] ) ], [ 101 ] );
check( 'that order also went out in another box: only its share of this one', '20.01' === $s['paid_others'] && '45.01' === $s['paid'], json_encode( $s ) );
$s = FisHotel_Shipment_Cost::summarize( [ $shared ], [ 101 => paid( '25.00' ), 102 => paid( '40.01', [ '1ZOTHER', '1ZSHARED' ] ) ], [ 101 ] );
check( 'the spare cent stays with its earlier box', '20.00' === $s['paid_others'] );

$s = FisHotel_Shipment_Cost::summarize( [ $shared ], [ 101 => paid( '25.00' ), 102 => paid( null ) ], [ 101 ] );
check( 'an order that no longer exists paid nothing', '25.00' === $s['paid'] && '0.00' === $s['paid_others'] && [ 102 ] === $s['other_order_ids'] );
$s = FisHotel_Shipment_Cost::summarize( [ $shared ], [ 101 => paid( '25.00' ) ], [ 101 ] );
check( 'an order missing from the lookup paid nothing', '25.00' === $s['paid'] );

$s = FisHotel_Shipment_Cost::summarize( [], [ 101 => paid( '45.00' ) ], [ 101 ] );
check( 'no boxes yet: the shipping paid, nothing else', '45.00' === $s['paid'] && 0 === $s['boxes'] && null === $s['cost'] && null === $s['difference'] && false === $s['complete'] && false === $s['loss'] );

$s = FisHotel_Shipment_Cost::summarize( [ 'junk', [ 'label_cost' => '5.00' ], box( '', '9.00', [ 101 ] ) ], [ 101 => paid( '45.00' ) ], [ 101, 0, -3 ] );
check( 'entries without a tracking number are ignored', 0 === $s['boxes'] && null === $s['cost'] && '45.00' === $s['paid'] );

// ── A ShipTracker that records label costs (stubs of its public API) ────
echo "ShipTracker with label costs\n";

// Declared inside a block on purpose: a top-level class is bound when the
// file is compiled, and the checks above need ShipTracker to be absent.
if ( ! class_exists( 'FST_Shipment' ) ) {
	class FST_Shipment {
		/** @var array[] [ id, order_id, tracking, cost, dims, source ] — the cost on ONE row per tracking number. */
		public static $rows = [];

		public static function get_order_boxes( $order_ids ) {
			$boxes = [];
			foreach ( (array) $order_ids as $order_id ) {
				foreach ( self::$rows as $r ) {
					if ( $r[1] !== (int) $order_id ) {
						continue;
					}
					$key = strtoupper( $r[2] );
					if ( isset( $boxes[ $key ] ) ) {
						continue;
					}
					$entry = box( $r[2], null, [] );
					foreach ( self::$rows as $sibling ) {
						if ( strtoupper( $sibling[2] ) !== $key ) {
							continue;
						}
						$entry['order_ids'][]    = $sibling[1];
						$entry['shipment_ids'][] = $sibling[0];
						if ( null !== $sibling[3] ) {
							$entry['label_cost']       = $sibling[3];
							$entry['box_dims']         = $sibling[4];
							$entry['label_source']     = $sibling[5];
							$entry['cost_shipment_id'] = $sibling[0];
						}
					}
					$entry['shared_box']  = count( $entry['order_ids'] ) > 1;
					$entry['shipment_id'] = $r[0];
					$boxes[ $key ]        = $entry;
				}
			}
			return array_values( $boxes );
		}
	}
	class FST_Settings {
		public static $throw = false;

		public static function get_standard_boxes() {
			if ( self::$throw ) {
				throw new RuntimeException( 'settings unavailable' );
			}
			return [
				[ 'name' => 'Small', 'length' => '10', 'width' => '10', 'height' => '10', 'dims' => '10x10x10' ],
				[ 'name' => 'Large', 'length' => '16', 'width' => '16', 'height' => '16', 'dims' => '16x16x16' ],
			];
		}
		public static function get_label_sources() {
			if ( self::$throw ) {
				throw new RuntimeException( 'settings unavailable' );
			}
			return [ 'Partner A', 'Partner B' ];
		}
	}
	class WC_Order {
		private $shipping;
		private $refunded;
		public function __construct( $shipping, $refunded = 0 ) {
			$this->shipping = $shipping;
			$this->refunded = $refunded;
		}
		public function get_shipping_total() {
			return $this->shipping;
		}
		public function get_total_shipping_refunded() {
			return $this->refunded;
		}
	}
}
$orders = [
	101 => new WC_Order( '25.00' ),
	102 => new WC_Order( '30.00', 10.0 ),
	103 => new WC_Order( '40.01' ),
];
function wc_get_order( $id ) {
	global $orders;
	return isset( $orders[ (int) $id ] ) ? $orders[ (int) $id ] : false;
}

check( 'supported() is true once get_order_boxes() exists', true === FisHotel_Shipment_Cost::supported() );
check( 'standard_boxes(): name + size only', [ [ 'name' => 'Small', 'dims' => '10x10x10' ], [ 'name' => 'Large', 'dims' => '16x16x16' ] ] === FisHotel_Shipment_Cost::standard_boxes() );
check( 'label_sources()', [ 'Partner A', 'Partner B' ] === FisHotel_Shipment_Cost::label_sources() );
FST_Settings::$throw = true;
check( 'a failing settings read → empty lists, no fatal', [] === FisHotel_Shipment_Cost::standard_boxes() && [] === FisHotel_Shipment_Cost::label_sources() );
FST_Settings::$throw = false;

check( 'shipping_paid(): the shipping total',            25.0 === FisHotel_Shipment_Cost::shipping_paid( $orders[101] ) );
check( 'shipping_paid(): less the shipping refunded',    20.0 === FisHotel_Shipment_Cost::shipping_paid( $orders[102] ) );
check( 'shipping_paid(): never below zero',              0.0 === (float) FisHotel_Shipment_Cost::shipping_paid( new WC_Order( '10.00', 15.0 ) ) );
check( 'shipping_paid(): not an order → null',           null === FisHotel_Shipment_Cost::shipping_paid( false ) );

// Fulfillment of 101 + 102 in one box (fan-out: two rows, the cost on the first).
FST_Shipment::$rows = [
	[ 11, 101, '1ZSHARED', '53.73', '10x10x10', 'Partner A' ],
	[ 12, 102, '1ZSHARED', null, null, null ],
];
$view_boxes = FisHotel_Shipment_Cost::order_boxes( [ 101, 102 ] );
check( 'order_boxes(): two rows, one box', 1 === count( $view_boxes ) && [ 101, 102 ] === $view_boxes[0]['order_ids'] );
check( 'order_boxes(): no orders → []', [] === FisHotel_Shipment_Cost::order_boxes( [] ) );
$s = FisHotel_Shipment_Cost::view_summary( [ 101, 102 ], $view_boxes );
check( 'fulfillment view: 25.00 + (30.00 − 10.00 refunded) against 53.73 once', '45.00' === $s['paid'] && '53.73' === $s['cost'] && '-8.73' === $s['difference'] && true === $s['loss'], json_encode( $s ) );

// The same box seen from source order 102 — its sibling row holds no cost.
$view_boxes = FisHotel_Shipment_Cost::order_boxes( [ 102 ] );
$plan       = FisHotel_Shipment_Cost::plan_cards( [ row( 12, 102, '1ZSHARED' ) ], $view_boxes );
check( 'source order view: the cost is read from the box, not the row', '53.73' === $plan[12]['box']['label_cost'] && [ 101 ] === $plan[12]['other_order_ids'] );
$s = FisHotel_Shipment_Cost::view_summary( [ 102 ], $view_boxes );
check( 'source order view: the other order\'s shipping counts towards the box', '45.00' === $s['paid'] && '20.00' === $s['paid_view'] && '25.00' === $s['paid_others'] && [ 101 ] === $s['other_order_ids'] );

// Order 103 shares one box with 101 and also went out in a box of its own, earlier.
FST_Shipment::$rows = [
	[ 20, 103, '1ZOWN', '30.00', '16x16x16', 'Partner B' ],
	[ 21, 101, '1ZBOTH', '50.00', '10x10x10', 'Partner A' ],
	[ 22, 103, '1ZBOTH', null, null, null ],
];
$view_boxes = FisHotel_Shipment_Cost::order_boxes( [ 101 ] );
$s          = FisHotel_Shipment_Cost::view_summary( [ 101 ], $view_boxes );
check( 'an outside order with a box of its own adds only its share (40.01 → 20.00)', '45.00' === $s['paid'] && '20.00' === $s['paid_others'] && '50.00' === $s['cost'] && '-5.00' === $s['difference'], json_encode( $s ) );
$s = FisHotel_Shipment_Cost::view_summary( [ 103 ], FisHotel_Shipment_Cost::order_boxes( [ 103 ] ) );
check( 'seen from that order: both its boxes, all its shipping + the sharer\'s', 2 === $s['boxes'] && '80.00' === $s['cost'] && '65.01' === $s['paid'] && '-14.99' === $s['difference'] && [ 101 ] === $s['other_order_ids'] );

// An order with no shipments yet, and one that no longer exists.
FST_Shipment::$rows = [];
$s = FisHotel_Shipment_Cost::view_summary( [ 101 ], FisHotel_Shipment_Cost::order_boxes( [ 101 ] ) );
check( 'no shipments yet: shipping paid, no cost, no difference', '25.00' === $s['paid'] && 0 === $s['boxes'] && null === $s['cost'] && null === $s['difference'] );
$s = FisHotel_Shipment_Cost::view_summary( [ 999 ], [] );
check( 'an order that cannot be loaded paid nothing', '0.00' === $s['paid'] );

// ── An older ShipTracker, in a child process ────────────────────────────
echo "older ShipTracker (child process)\n";
if ( function_exists( 'exec' ) ) {
	$output = [];
	$code   = 1;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --old-shiptracker 2>&1', $output, $code );
	check( 'the Shipments box sees no label cost support', 0 === $code && in_array( 'all passed', $output, true ), implode( ' | ', $output ) );
} else {
	echo "  skip (exec() is unavailable)\n";
}

finish();
