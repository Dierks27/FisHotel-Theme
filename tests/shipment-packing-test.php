<?php
/**
 * Stub-based test for the packing side of
 * inc/order-fulfillment/class-fishotel-shipment-cost.php — no WordPress, no
 * ShipTracker, no database.
 *
 *   php tests/shipment-packing-test.php
 *
 * Covers: the supplies, materials records and usual packing as the packing
 * block uses them, the full cost of a box (label + materials — a missing
 * label cost is never 0, a box without materials costs its label), the
 * words on a shipment card, the fish a refunded line still puts in a box,
 * how an order went out (label / local pickup / in another order's box),
 * the rules for the order it went with, and the line under the list: full
 * cost with its two parts adding up, free shipping, a local pickup, and an
 * order that went in another order's box counted against that box exactly
 * like an order sharing it.
 *
 * Also the capability probe: no ShipTracker, a ShipTracker that records
 * label costs only (v1.9.17, child process `--label-cost-only`) and one
 * that records packing, read through stubs of its public API.
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
/** A get_order_boxes()-shaped box; $materials is its materials total, null = none recorded. */
function box( $tracking, $cost, array $order_ids, $materials = null, $dims = null ) {
	return [
		'tracking_number' => $tracking,
		'label_cost'      => $cost,
		'box_weight'      => null,
		'box_dims'        => $dims,
		'label_source'    => null,
		'shared_box'      => count( $order_ids ) > 1,
		'order_ids'       => $order_ids,
		'shipment_ids'    => [],
		'materials'       => null === $materials ? null : [ 'lines' => [], 'foam_cost' => $materials, 'paper_cost' => '0.00', 'total' => $materials ],
		'materials_total' => $materials,
		'total_cost'      => null,
	];
}
function paid( $shipping, array $box_keys = [] ) {
	return [ 'shipping' => $shipping, 'box_keys' => $box_keys ];
}
/** A ShipTracker materials line. */
function line( $id, $name, $role, $qty, $unit_cost, $cost, $unit = 'each' ) {
	return [ 'supply_id' => $id, 'name' => $name, 'role' => $role, 'unit' => $unit, 'qty' => $qty, 'unit_cost' => $unit_cost, 'cost' => $cost ];
}

$C = 'FisHotel_Shipment_Cost';

// ── A ShipTracker that records label costs only (child process) ─────────
if ( isset( $argv[1] ) && '--label-cost-only' === $argv[1] ) {
	// v1.9.17: the label cost API exists, the supplies / ship mode API does not.
	class FST_Shipment {
		public static function get_order_boxes( $order_ids ) {
			return [ box( '1ZAAA', '53.73', [ 101 ] ) ];
		}
	}
	class FST_Settings {}
	class FST_Order {}
	class WC_Order {}

	echo "ShipTracker with label costs only\n";
	check( 'supported() is true',                          true === FisHotel_Shipment_Cost::supported() );
	check( 'packing_supported() is false without FST_Supplies', false === FisHotel_Shipment_Cost::packing_supported() );
	check( 'supplies() → []',                              [] === FisHotel_Shipment_Cost::supplies() );
	check( 'pack_action() → ""',                           '' === FisHotel_Shipment_Cost::pack_action( new WC_Order() ) );
	check( 'fish_count() → 0',                             0.0 === FisHotel_Shipment_Cost::fish_count( new WC_Order() ) );
	check( 'ship_mode() → label',                          'label' === FisHotel_Shipment_Cost::ship_mode( new WC_Order() )['mode'] );
	check( 'rider_ids() → []',                             [] === FisHotel_Shipment_Cost::rider_ids( [ 101 ] ) );
	$d = FisHotel_Shipment_Cost::default_materials( 6, 'heat_pack' );
	check( 'default_materials() → nothing usual',          [] === $d['boxes'] && '0.00' === $d['custom']['total'] );
	$f = FisHotel_Shipment_Cost::full_cost( FisHotel_Shipment_Cost::order_boxes( [ 101 ] )[0] );
	check( 'a box still reads as its label cost',          '53.73' === $f['label'] && null === $f['materials'] && '53.73' === $f['total'] );
	finish();
}

// ── No ShipTracker at all ───────────────────────────────────────────────
echo "no ShipTracker\n";
check( 'packing_supported() is false', false === FisHotel_Shipment_Cost::packing_supported() );
check( 'supplies() → []',              [] === FisHotel_Shipment_Cost::supplies() );
check( 'pack_action() → ""',           '' === FisHotel_Shipment_Cost::pack_action( null ) );
check( 'fish_count() → 0',             0.0 === FisHotel_Shipment_Cost::fish_count( null ) );
check( 'box_fish_count() → 0',         0.0 === FisHotel_Shipment_Cost::box_fish_count( '1ZAAA', [ 101 ] ) );
check( 'ship_mode() → label',          'label' === FisHotel_Shipment_Cost::ship_mode( null )['mode'] );
check( 'rider_ids() → []',             [] === FisHotel_Shipment_Cost::rider_ids( [ 101 ] ) );
check( 'host_order_ids() → []',        [] === FisHotel_Shipment_Cost::host_order_ids( null ) );

// ── clean_supplies() ────────────────────────────────────────────────────
echo "clean_supplies()\n";
$supplies = FisHotel_Shipment_Cost::clean_supplies( [
	[ 'id' => 3, 'name' => ' Fish bag ', 'unit' => 'each', 'counted' => true, 'reorder_level' => '100.00', 'role' => 'fish_bag', 'box_name' => '', 'price_each' => '0.1100', 'on_hand' => '34.00', 'recount_needed' => false, 'low' => true ],
	[ 'id' => 6, 'name' => 'Small box', 'unit' => '', 'counted' => true, 'role' => 'box', 'box_name' => 'Small', 'price_each' => null, 'on_hand' => '0.00', 'recount_needed' => true, 'low' => true ],
	[ 'id' => 8, 'name' => 'Tape', 'unit' => 'rolls', 'counted' => false, 'role' => '', 'price_each' => '3.0000', 'on_hand' => null ],
	[ 'id' => 9, 'name' => 'Stickers', 'unit' => 'each', 'counted' => false, 'on_hand' => '12.50' ],
	[ 'id' => 3, 'name' => 'Fish bag again' ],
	[ 'id' => 0, 'name' => 'No id' ],
	[ 'id' => 10, 'name' => '' ],
	[ 'id' => 11, 'name' => [ 'x' ] ],
	[ 'name' => 'No id key' ],
	'Fish bag',
] );
check( 'named supplies with an id are kept, the rest dropped', [ 3, 6, 8, 9 ] === array_column( $supplies, 'id' ), json_encode( array_column( $supplies, 'id' ) ) );
check( 'name trimmed, price each and stock as short text', 'Fish bag' === $supplies[0]['name'] && '0.1100' === $supplies[0]['price_each'] && '34' === $supplies[0]['on_hand'] && true === $supplies[0]['low'] );
check( 'no unit → "each"; no purchase yet → price null', 'each' === $supplies[1]['unit'] && null === $supplies[1]['price_each'] );
check( 'a box supply keeps its box; "recount needed" passes through', 'box' === $supplies[1]['role'] && 'Small' === $supplies[1]['box_name'] && true === $supplies[1]['recount_needed'] && '0' === $supplies[1]['on_hand'] );
check( 'a supply that is not counted has no stock', null === $supplies[2]['on_hand'] && null === $supplies[3]['on_hand'] );
check( 'not a list → []', [] === FisHotel_Shipment_Cost::clean_supplies( null ) && [] === FisHotel_Shipment_Cost::clean_supplies( 'x' ) );

// ── clean_materials() ───────────────────────────────────────────────────
echo "clean_materials()\n";
$record = [
	'lines'      => [
		line( 6, 'Small box', 'box', '1.00', '1.2500', '1.25' ),
		line( 3, 'Fish bag', 'fish_bag', '6.00', '0.1100', '0.66' ),
		line( 1, 'Heat pack', 'heat_pack', '1.00', '0.6000', '0.60' ),
	],
	'foam_cost'  => '1.80',
	'paper_cost' => '0.40',
	'total'      => '4.71',
	'split'      => [ 'boxes' => '1.25', 'foam' => '1.80', 'paper' => '0.40', 'bags' => '0.66', 'packs' => '0.60', 'other' => '0.00' ],
];
$m = FisHotel_Shipment_Cost::clean_materials( $record );
check( 'three lines, quantities shortened', 3 === count( $m['lines'] ) && '1' === $m['lines'][0]['qty'] && '6' === $m['lines'][1]['qty'] );
check( 'unit cost keeps four decimals, line cost two', '0.1100' === $m['lines'][1]['unit_cost'] && '0.66' === $m['lines'][1]['cost'] );
check( 'foam, paper and the total', '1.80' === $m['foam_cost'] && '0.40' === $m['paper_cost'] && '4.71' === $m['total'] );
check( 'no record → null', null === FisHotel_Shipment_Cost::clean_materials( null ) && null === FisHotel_Shipment_Cost::clean_materials( 'x' ) );
$m = FisHotel_Shipment_Cost::clean_materials( [ 'lines' => [], 'foam_cost' => '0.00', 'paper_cost' => '0.00', 'total' => '0.00' ] );
check( 'a record of nothing but zeros is still a record', is_array( $m ) && [] === $m['lines'] && '0.00' === $m['total'] );
$m = FisHotel_Shipment_Cost::clean_materials( [ 'lines' => [ line( 3, 'Fish bag', 'fish_bag', '2.50', '0.1100', '0.28' ), line( 9, '', '', '1.00', '', '0.05', '' ), line( 4, 'Zero', '', '0.00', '1.0000', '0.00' ), 'junk', [ 'qty' => '1' ] ], 'foam_cost' => '1.5' ] );
check( 'a half quantity stays "2.5"', '2.5' === $m['lines'][0]['qty'] );
check( 'a line without a name or unit still shows (#id, each)', '#9' === $m['lines'][1]['name'] && 'each' === $m['lines'][1]['unit'] && '0.0000' === $m['lines'][1]['unit_cost'] );
check( 'a line at 0 and junk are dropped', 2 === count( $m['lines'] ) );
check( 'no total given: lines + foam + paper', '1.83' === $m['total'] && '1.50' === $m['foam_cost'] && '0.00' === $m['paper_cost'], json_encode( $m ) );

// ── clean_defaults() ────────────────────────────────────────────────────
echo "clean_defaults()\n";
$d = FisHotel_Shipment_Cost::clean_defaults( [
	'boxes'  => [
		[ 'name' => 'Small', 'dims' => '10x10x10', 'materials' => $record ],
		[ 'name' => 'Large', 'dims' => '16x16x16' ],
		[ 'name' => '', 'dims' => '1x1x1', 'materials' => $record ],
		'junk',
	],
	'custom' => [ 'lines' => [ line( 3, 'Fish bag', 'fish_bag', '6.00', '0.1100', '0.66' ) ], 'foam_cost' => '0.00', 'paper_cost' => '0.00', 'total' => '0.66' ],
] );
check( 'one entry per named standard box', [ 'Small', 'Large' ] === array_column( $d['boxes'], 'name' ) );
check( 'with its usual packing', '4.71' === $d['boxes'][0]['materials']['total'] && 3 === count( $d['boxes'][0]['materials']['lines'] ) );
check( 'a box ShipTracker gave nothing for starts empty', [] === $d['boxes'][1]['materials']['lines'] && '0.00' === $d['boxes'][1]['materials']['total'] );
check( 'a custom box: bags only, foam and paper 0', '0.66' === $d['custom']['total'] && '0.00' === $d['custom']['foam_cost'] && '0.00' === $d['custom']['paper_cost'] );
$d = FisHotel_Shipment_Cost::clean_defaults( null );
check( 'nothing from ShipTracker → no boxes, an empty custom', [] === $d['boxes'] && [] === $d['custom']['lines'] && '0.00' === $d['custom']['total'] );

// ── full_cost() ─────────────────────────────────────────────────────────
echo "full_cost()\n";
$f = FisHotel_Shipment_Cost::full_cost( box( '1Z1', '53.73', [ 101 ], '6.10' ) );
check( 'label 53.73 + materials 6.10 = 59.83', [ 'label' => '53.73', 'materials' => '6.10', 'total' => '59.83' ] === $f, json_encode( $f ) );
$f = FisHotel_Shipment_Cost::full_cost( box( '1Z1', '53.73', [ 101 ] ) );
check( 'no materials recorded: the box costs its label', [ 'label' => '53.73', 'materials' => null, 'total' => '53.73' ] === $f );
$f = FisHotel_Shipment_Cost::full_cost( box( '1Z1', null, [ 101 ], '6.10' ) );
check( 'no label cost: no total — never materials alone', [ 'label' => null, 'materials' => '6.10', 'total' => null ] === $f );
$f = FisHotel_Shipment_Cost::full_cost( box( '1Z1', '0.00', [ 101 ], '6.10' ) );
check( 'a recorded 0.00 label is a cost', '0.00' === $f['label'] && '6.10' === $f['total'] );
$f = FisHotel_Shipment_Cost::full_cost( box( '1Z1', '18.4', [ 101 ], '0.00' ) );
check( 'materials of 0.00 are recorded materials', '0.00' === $f['materials'] && '18.40' === $f['total'] );
$f = FisHotel_Shipment_Cost::full_cost( [ 'label_cost' => '10.00', 'materials' => $record ] );
check( 'reads the record when the total is not given apart', '4.71' === $f['materials'] && '14.71' === $f['total'] );
$f = FisHotel_Shipment_Cost::full_cost( [ 'label_cost' => '0.10', 'materials_total' => '0.20' ] );
check( 'cents add up exactly (0.10 + 0.20)', '0.30' === $f['total'] );
check( 'no box → nothing', [ 'label' => null, 'materials' => null, 'total' => null ] === FisHotel_Shipment_Cost::full_cost( null ) );

// ── materials_parts() ───────────────────────────────────────────────────
echo "materials_parts()\n";
$p = FisHotel_Shipment_Cost::materials_parts( FisHotel_Shipment_Cost::clean_materials( $record ) );
check( 'lines as "qty × name"', [ '1 × Small box', '6 × Fish bag', '1 × Heat pack' ] === $p['lines'], json_encode( $p['lines'] ) );
check( 'foam and paper amounts', '1.80' === $p['foam'] && '0.40' === $p['paper'] );
$p = FisHotel_Shipment_Cost::materials_parts( FisHotel_Shipment_Cost::clean_materials( [ 'lines' => [], 'foam_cost' => '0.00', 'paper_cost' => '0.00' ] ) );
check( 'a zero record has no words', [] === $p['lines'] && null === $p['foam'] && null === $p['paper'] );
check( 'no record → no words', [ 'lines' => [], 'foam' => null, 'paper' => null ] === FisHotel_Shipment_Cost::materials_parts( null ) );

// ── item_fish() / fish_text() ───────────────────────────────────────────
echo "item_fish()\n";
check( 'a line of 3 fish puts 3 in a box',          3.0 === FisHotel_Shipment_Cost::item_fish( 3, 3, 3 ) );
check( 'one of 4 refunded: 3 left to bag',          3.0 === FisHotel_Shipment_Cost::item_fish( 4, 4, 3 ) );
check( 'fully refunded: none',                      0.0 === FisHotel_Shipment_Cost::item_fish( 4, 4, 0 ) );
check( 'a count ShipTracker changed scales the same way', 4.0 === FisHotel_Shipment_Cost::item_fish( 6, 3, 2 ) );
check( 'a line ShipTracker counts as 0 (dry goods) stays 0', 0.0 === FisHotel_Shipment_Cost::item_fish( 0, 2, 2 ) );
check( 'more available than ordered changes nothing', 2.0 === FisHotel_Shipment_Cost::item_fish( 2, 2, 5 ) );
check( 'junk → 0',                                  0.0 === FisHotel_Shipment_Cost::item_fish( 'x', null, [] ) );
check( 'never negative',                            0.0 === FisHotel_Shipment_Cost::item_fish( -2, 2, 2 ) );
check( 'fish_text: 6.0 → "6", 2.5 → "2.5", 0 → "0"', '6' === FisHotel_Shipment_Cost::fish_text( 6.0 ) && '2.5' === FisHotel_Shipment_Cost::fish_text( 2.5 ) && '0' === FisHotel_Shipment_Cost::fish_text( 0 ) );

// ── weather_label() ─────────────────────────────────────────────────────
echo "weather_label()\n";
check( 'heat_pack → Heat pack',     'Heat pack' === FisHotel_Shipment_Cost::weather_label( 'heat_pack' ) );
check( 'cooling → Cooling packs',   'Cooling packs' === FisHotel_Shipment_Cost::weather_label( 'cooling' ) );
check( 'every other action says nothing', '' === FisHotel_Shipment_Cost::weather_label( 'none' ) && '' === FisHotel_Shipment_Cost::weather_label( 'hold_cold' ) && '' === FisHotel_Shipment_Cost::weather_label( 'unavailable' ) );
check( 'no weather data → ""',      '' === FisHotel_Shipment_Cost::weather_label( '' ) && '' === FisHotel_Shipment_Cost::weather_label( null ) );

// ── clean_ship_mode() ───────────────────────────────────────────────────
echo "clean_ship_mode()\n";
$label = [ 'mode' => 'label', 'with_order_id' => 0, 'with_order_number' => '', 'date' => '', 'materials' => null, 'materials_total' => null ];
check( 'shipped with a label: nothing stored', $label === FisHotel_Shipment_Cost::clean_ship_mode( [ 'mode' => 'label', 'with_order_id' => null, 'date' => null, 'with_order_number' => null, 'materials' => null, 'materials_total' => null ] ) );
check( 'nothing from ShipTracker → label',     $label === FisHotel_Shipment_Cost::clean_ship_mode( null ) && $label === FisHotel_Shipment_Cost::clean_ship_mode( 'local' ) );
$s = FisHotel_Shipment_Cost::clean_ship_mode( [ 'mode' => 'local', 'with_order_id' => null, 'date' => '2026-11-12 09:00:00', 'with_order_number' => null, 'materials' => [ 'lines' => [ line( 3, 'Fish bag', 'fish_bag', '3.00', '0.1100', '0.33' ) ], 'foam_cost' => '0.00', 'paper_cost' => '0.00', 'total' => '0.33' ], 'materials_total' => '0.33' ] );
check( 'local pickup with its packing', 'local' === $s['mode'] && '0.33' === $s['materials_total'] && '3' === $s['materials']['lines'][0]['qty'] && '2026-11-12 09:00:00' === $s['date'] && 0 === $s['with_order_id'] );
$s = FisHotel_Shipment_Cost::clean_ship_mode( [ 'mode' => 'local', 'date' => null, 'materials' => null ] );
check( 'local pickup with no packing recorded', 'local' === $s['mode'] && null === $s['materials'] && null === $s['materials_total'] && '' === $s['date'] );
$s = FisHotel_Shipment_Cost::clean_ship_mode( [ 'mode' => 'with_order', 'with_order_id' => 100, 'with_order_number' => '100', 'date' => '2026-11-12 09:00:00', 'materials' => [ 'lines' => [], 'total' => '9.99' ] ] );
check( 'went in another order\'s box: that order, never materials', 'with_order' === $s['mode'] && 100 === $s['with_order_id'] && '100' === $s['with_order_number'] && null === $s['materials'] );
$s = FisHotel_Shipment_Cost::clean_ship_mode( [ 'mode' => 'with_order', 'with_order_id' => '100' ] );
check( 'no order number given: the id stands in', '100' === $s['with_order_number'] );
check( 'with_order without an order → label', $label === FisHotel_Shipment_Cost::clean_ship_mode( [ 'mode' => 'with_order', 'with_order_id' => 0 ] ) );
check( 'an unknown mode → label',             $label === FisHotel_Shipment_Cost::clean_ship_mode( [ 'mode' => 'teleport', 'with_order_id' => 5 ] ) );

// ── order_ref_id() / host_problem() / host_summary() ────────────────────
echo "the order it went with\n";
check( '"34183" → 34183',            34183 === FisHotel_Shipment_Cost::order_ref_id( '34183' ) );
check( '"#34183 " → 34183',          34183 === FisHotel_Shipment_Cost::order_ref_id( ' #34183 ' ) );
check( 'a number is a number',       34183 === FisHotel_Shipment_Cost::order_ref_id( 34183 ) );
check( 'text, empty, negative → 0',  0 === FisHotel_Shipment_Cost::order_ref_id( 'Ann' ) && 0 === FisHotel_Shipment_Cost::order_ref_id( '' ) && 0 === FisHotel_Shipment_Cost::order_ref_id( '-5' ) && 0 === FisHotel_Shipment_Cost::order_ref_id( '12 or 13' ) );
check( 'not text → 0',               0 === FisHotel_Shipment_Cost::order_ref_id( [ 5 ] ) && 0 === FisHotel_Shipment_Cost::order_ref_id( true ) );

$ok_host = [ 'own_id' => 102, 'host_id' => 100, 'exists' => true, 'mode' => 'label' ];
check( 'an order with a label (or none yet) can take another in its box', '' === FisHotel_Shipment_Cost::host_problem( $ok_host ) );
check( 'no number typed',            'Enter the order number of the box it went in.' === FisHotel_Shipment_Cost::host_problem( [ 'own_id' => 102, 'host_id' => 0 ] ) );
check( 'the order itself',           'That is this order. Enter the order whose box it went in.' === FisHotel_Shipment_Cost::host_problem( [ 'host_id' => 102 ] + $ok_host ) );
check( 'an order that does not exist', 'Order #100 not found.' === FisHotel_Shipment_Cost::host_problem( [ 'exists' => false ] + $ok_host ) );
check( 'an order that itself went in another box (no chains)', 'Order #100 itself went in another order\'s box (#90). Enter the order that has the box.' === FisHotel_Shipment_Cost::host_problem( [ 'mode' => 'with_order', 'with_order_number' => '90' ] + $ok_host ) );
check( 'a local pickup has no box',  'Order #100 is a local pickup, so it has no box.' === FisHotel_Shipment_Cost::host_problem( [ 'mode' => 'local' ] + $ok_host ) );

check( 'customer and the box found', 'Order #100 — Ann Able. Box: 1ZAAA · Small (10x10x10) · In Transit.' === FisHotel_Shipment_Cost::host_summary( [ 'number' => '100', 'customer' => 'Ann Able', 'boxes' => [ [ 'tracking_number' => '1ZAAA', 'size' => 'Small (10x10x10)', 'status' => 'In Transit' ] ] ] ) );
check( 'two boxes, one without a size', 'Order #100 — Ann Able. Boxes: 1ZAAA · Small (10x10x10) · In Transit; 1ZBBB · Delivered.' === FisHotel_Shipment_Cost::host_summary( [ 'number' => '100', 'customer' => 'Ann Able', 'boxes' => [ [ 'tracking_number' => '1ZAAA', 'size' => 'Small (10x10x10)', 'status' => 'In Transit' ], [ 'tracking_number' => '1ZBBB', 'size' => '', 'status' => 'Delivered' ] ] ] ) );
check( 'no shipment on it yet', 'Order #100 — Ann Able. No shipment on it yet — its box will count this order once the tracking is added.' === FisHotel_Shipment_Cost::host_summary( [ 'number' => '100', 'customer' => 'Ann Able', 'boxes' => [] ] ) );
check( 'no customer name', 0 === strpos( FisHotel_Shipment_Cost::host_summary( [ 'number' => '100', 'customer' => '' ] ), 'Order #100. ' ) );

// ── pickup_summary() ────────────────────────────────────────────────────
echo "pickup_summary()\n";
$p = FisHotel_Shipment_Cost::pickup_summary( 0.0, '0.33' );
check( 'a free pickup with bags: costs its packing, no difference, no loss', [ 'paid' => '0.00', 'cost' => '0.33', 'difference' => null, 'loss' => false ] === $p, json_encode( $p ) );
$p = FisHotel_Shipment_Cost::pickup_summary( '10.00', '0.33' );
check( 'shipping was paid but it was picked up: +9.67', '10.00' === $p['paid'] && '9.67' === $p['difference'] && false === $p['loss'] );
$p = FisHotel_Shipment_Cost::pickup_summary( '0.25', '0.33' );
check( 'paid less than the packing cost: a loss', '-0.08' === $p['difference'] && true === $p['loss'] );
$p = FisHotel_Shipment_Cost::pickup_summary( null, null );
check( 'nothing paid, nothing used: a real zero', [ 'paid' => '0.00', 'cost' => '0.00', 'difference' => null, 'loss' => false ] === $p );

// ── summarize(): label + materials ──────────────────────────────────────
echo "summarize() with materials\n";
$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', '53.73', [ 101 ], '6.10' ) ], [ 101 => paid( '45.00' ) ], [ 101 ] );
check( 'the cost is label + materials: 59.83 against 45.00 → -14.83', '59.83' === $s['cost'] && '-14.83' === $s['difference'] && true === $s['loss'], json_encode( $s ) );
check( 'with its two parts', '53.73' === $s['label_cost'] && '6.10' === $s['materials'] && 1 === $s['boxes_with_materials'] );
check( 'the parts add up to the cost', FisHotel_Shipment_Cost::to_cents( $s['label_cost'] ) + FisHotel_Shipment_Cost::to_cents( $s['materials'] ) === FisHotel_Shipment_Cost::to_cents( $s['cost'] ) );
check( 'shipping was paid: not free', false === $s['free'] );

$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', '53.73', [ 101 ] ) ], [ 101 => paid( '45.00' ) ], [ 101 ] );
check( 'no materials recorded: the cost is the label, as before', '53.73' === $s['cost'] && '53.73' === $s['label_cost'] && '0.00' === $s['materials'] && 0 === $s['boxes_with_materials'] && '-8.73' === $s['difference'] );

$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', null, [ 101 ], '6.10' ) ], [ 101 => paid( '45.00' ) ], [ 101 ] );
check( 'materials without a label cost: still no cost and no difference', null === $s['cost'] && null === $s['label_cost'] && null === $s['difference'] && 1 === $s['boxes_without_cost'] && false === $s['loss'], json_encode( $s ) );
check( 'they are named apart, not added', '6.10' === $s['materials_without_cost'] && '0.00' === $s['materials'] && 1 === $s['boxes_with_materials'] );

$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', '50.00', [ 101 ], '4.71' ), box( '1ZBBB', null, [ 101 ], '3.00' ), box( '1ZCCC', '20.00', [ 101 ] ) ], [ 101 => paid( '90.00' ) ], [ 101 ] );
check( 'three boxes: only the two with a label cost are in the cost', '74.71' === $s['cost'] && '70.00' === $s['label_cost'] && '4.71' === $s['materials'] && '3.00' === $s['materials_without_cost'] && '15.29' === $s['difference'] && false === $s['complete'] && 1 === $s['boxes_without_cost'] );

$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', '0.10', [ 101 ], '0.20' ), box( '1ZBBB', '0.30', [ 101 ], '0.40' ) ], [ 101 => paid( '1.00' ) ], [ 101 ] );
check( 'cents add up exactly across boxes', '1.00' === $s['cost'] && '0.00' === $s['difference'] && false === $s['loss'] );

// Free shipping.
$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', '53.73', [ 101 ], '6.10' ) ], [ 101 => paid( '0.00' ) ], [ 101 ] );
check( 'nothing paid towards the box: free, and it cost 59.83', true === $s['free'] && '59.83' === $s['cost'] && '0.00' === $s['paid'] );
$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZAAA', null, [ 101 ] ) ], [ 101 => paid( '0.00' ) ], [ 101 ] );
check( 'free with no cost recorded yet: free, cost unknown', true === $s['free'] && null === $s['cost'] );
$s = FisHotel_Shipment_Cost::summarize( [], [ 101 => paid( '0.00' ) ], [ 101 ] );
check( 'no box yet: nothing was shipped free', false === $s['free'] );
$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZSHARED', '53.73', [ 101, 102 ] ) ], [ 101 => paid( '0.00' ), 102 => paid( '20.00', [ '1ZSHARED' ] ) ], [ 101 ] );
check( 'this order paid nothing but the order sharing the box did: not free', false === $s['free'] && '20.00' === $s['paid'] );

// An order that went in another order's box (a "rider"): no shipment of its own.
echo "summarize(): went in another order's box\n";
$host_box = box( '1ZHOST', '53.73', [ 100 ], '6.10' );
$s = FisHotel_Shipment_Cost::summarize( [ $host_box ], [ 100 => paid( '45.00' ), 102 => paid( '20.00', [ '1ZHOST' ] ) ], [ 100 ], [ 102 ] );
check( 'its shipping counts towards the box it went in: 45 + 20', '65.00' === $s['paid'] && '45.00' === $s['paid_view'] && '20.00' === $s['paid_others'], json_encode( $s ) );
check( 'against the full cost: 65.00 − 59.83 = +5.17', '59.83' === $s['cost'] && '5.17' === $s['difference'] && false === $s['loss'] );
check( 'it is named, as a rider', [ 102 ] === $s['other_order_ids'] && [ 102 ] === $s['rider_order_ids'] );

$s = FisHotel_Shipment_Cost::summarize( [ $host_box ], [ 100 => paid( '45.00' ), 102 => paid( '20.01', [ '1ZHOST', '1ZHOST2' ] ) ], [ 100 ], [ 102 ] );
check( 'the host went out in two boxes, one on screen: the rider\'s share of this one (spare cent to the earliest)', '10.01' === $s['paid_others'] );
$s = FisHotel_Shipment_Cost::summarize( [ $host_box, box( '1ZHOST2', '30.00', [ 100 ] ) ], [ 100 => paid( '45.00' ), 102 => paid( '20.01', [ '1ZHOST', '1ZHOST2' ] ) ], [ 100 ], [ 102 ] );
check( 'both boxes on screen: all of the rider\'s shipping, once', '20.01' === $s['paid_others'] && '65.01' === $s['paid'] && 2 === $s['boxes'] );

$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZSHARED', '53.73', [ 100, 101 ] ) ], [ 100 => paid( '25.00' ), 101 => paid( '20.00', [ '1ZSHARED' ] ), 102 => paid( '10.00', [ '1ZSHARED' ] ) ], [ 100 ], [ 102 ] );
check( 'a rider beside an order sharing the box: exactly like it', '55.00' === $s['paid'] && '30.00' === $s['paid_others'] && [ 101, 102 ] === $s['other_order_ids'] && [ 102 ] === $s['rider_order_ids'] );

$s = FisHotel_Shipment_Cost::summarize( [], [ 100 => paid( '45.00' ), 102 => paid( '20.00', [] ) ], [ 100 ], [ 102 ] );
check( 'no box on the host yet: the rider adds nothing so far', '45.00' === $s['paid'] && [] === $s['rider_order_ids'] && [] === $s['other_order_ids'] );
$s = FisHotel_Shipment_Cost::summarize( [ $host_box ], [ 100 => paid( '45.00' ), 102 => paid( '20.00', [ '1ZHOST' ] ) ], [ 100, 102 ], [ 102 ] );
check( 'a rider that is itself on screen is counted once', '65.00' === $s['paid'] && '0.00' === $s['paid_others'] && [] === $s['rider_order_ids'] );
$s = FisHotel_Shipment_Cost::summarize( [ box( '1ZSHARED', '53.73', [ 100, 102 ] ) ], [ 100 => paid( '45.00' ), 102 => paid( '20.00', [ '1ZSHARED' ] ) ], [ 100 ], [ 102, 102, 0, -1 ] );
check( 'an order already sharing the box is not a rider on top', '65.00' === $s['paid'] && [ 102 ] === $s['other_order_ids'] && [] === $s['rider_order_ids'] );
$s = FisHotel_Shipment_Cost::summarize( [ $host_box ], [ 100 => paid( '0.00' ), 102 => paid( '0.00', [ '1ZHOST' ] ) ], [ 100 ], [ 102 ] );
check( 'host and rider both paid nothing: shipped free', true === $s['free'] );
$s = FisHotel_Shipment_Cost::summarize( [ $host_box ], [ 100 => paid( '45.00' ) ], [ 100 ], [ 102 ] );
check( 'a rider that no longer exists paid nothing', '45.00' === $s['paid'] && [ 102 ] === $s['rider_order_ids'] );

// ── A ShipTracker that records packing (stubs of its public API) ────────
echo "ShipTracker with packing\n";

// Declared inside a block on purpose: a top-level class is bound when the
// file is compiled, and the checks above need ShipTracker to be absent.
if ( ! class_exists( 'FST_Shipment' ) ) {
	class FST_Shipment {
		/** @var array[] [ id, order_id, tracking, label cost, materials total, line_item_ids ] */
		public static $rows = [];

		public static function get_by_order( $order_id ) {
			$out = [];
			foreach ( self::$rows as $r ) {
				if ( $r[1] === (int) $order_id ) {
					$out[] = (object) [ 'id' => $r[0], 'order_id' => $r[1], 'tracking_number' => $r[2], 'line_item_ids' => $r[5] ];
				}
			}
			return $out;
		}
		public static function get_order_boxes( $order_ids ) {
			$boxes = [];
			foreach ( (array) $order_ids as $order_id ) {
				foreach ( self::$rows as $r ) {
					$key = strtoupper( $r[2] );
					if ( $r[1] !== (int) $order_id || isset( $boxes[ $key ] ) ) {
						continue;
					}
					$entry = box( $r[2], null, [] );
					foreach ( self::$rows as $sibling ) {
						if ( strtoupper( $sibling[2] ) !== $key ) {
							continue;
						}
						$entry['order_ids'][] = $sibling[1];
						if ( null !== $sibling[3] ) {
							$entry['label_cost'] = $sibling[3];
						}
						if ( null !== $sibling[4] ) {
							$entry['materials_total'] = $sibling[4];
						}
					}
					$boxes[ $key ] = $entry;
				}
			}
			return array_values( $boxes );
		}
	}
	class FST_Order {
		public function set_ship_mode( $order_id, array $args ) {
			return [];
		}
	}
	class FST_Supplies {
		public static $throw = false;

		public static function get_supplies() {
			if ( self::$throw ) {
				throw new RuntimeException( 'supplies unavailable' );
			}
			return [
				[ 'id' => 3, 'name' => 'Fish bag', 'unit' => 'each', 'counted' => true, 'reorder_level' => '100.00', 'role' => 'fish_bag', 'box_name' => '', 'price_each' => '0.1100', 'on_hand' => '500.00', 'recount_needed' => false, 'low' => false ],
				[ 'id' => 1, 'name' => 'Heat pack', 'unit' => 'each', 'counted' => true, 'reorder_level' => '10.00', 'role' => 'heat_pack', 'box_name' => '', 'price_each' => '0.6000', 'on_hand' => '7.00', 'recount_needed' => false, 'low' => true ],
			];
		}
		public static function get_default_materials( $fish_count, $action ) {
			if ( self::$throw ) {
				throw new RuntimeException( 'supplies unavailable' );
			}
			$lines = [ line( 3, 'Fish bag', 'fish_bag', number_format( $fish_count, 2, '.', '' ), '0.1100', number_format( $fish_count * 0.11, 2, '.', '' ) ) ];
			if ( 'heat_pack' === $action ) {
				$lines[] = line( 1, 'Heat pack', 'heat_pack', '1.00', '0.6000', '0.60' );
			}
			return [
				'boxes'  => [ [ 'name' => 'Small', 'dims' => '10x10x10', 'materials' => [ 'lines' => $lines, 'foam_cost' => '1.80', 'paper_cost' => '0.40' ] ] ],
				'custom' => [ 'lines' => $lines, 'foam_cost' => '0.00', 'paper_cost' => '0.00' ],
			];
		}
		public static function order_pack_action( $order ) {
			if ( self::$throw ) {
				throw new RuntimeException( 'supplies unavailable' );
			}
			return $order instanceof WC_Order ? (string) $order->get_meta( '_fh_weather_action' ) : '';
		}
		public static function fish_count( $order, $item_ids = null ) {
			if ( self::$throw ) {
				throw new RuntimeException( 'supplies unavailable' );
			}
			$count = 0;
			foreach ( $order instanceof WC_Order ? $order->items : [] as $item_id => $qty ) {
				if ( null === $item_ids || in_array( $item_id, $item_ids, true ) ) {
					$count += $qty;
				}
			}
			return $count;
		}
	}
	class FST_Ship_Mode {
		public static $throw = false;

		public static function describe( $order ) {
			if ( self::$throw ) {
				throw new RuntimeException( 'ship mode unavailable' );
			}
			$mode = (string) $order->get_meta( '_fst_ship_mode' );
			$with = (int) $order->get_meta( '_fst_ship_with_order' );
			return [
				'mode'              => '' === $mode ? 'label' : $mode,
				'with_order_id'     => $with ? $with : null,
				'with_order_number' => $with ? (string) $with : null,
				'date'              => '' === $mode ? null : '2026-11-12 09:00:00',
				'materials'         => null,
				'materials_total'   => null,
			];
		}
		public static function get_riders_of( $order_id ) {
			if ( self::$throw ) {
				throw new RuntimeException( 'ship mode unavailable' );
			}
			global $orders;
			$riders = [];
			foreach ( $orders as $id => $order ) {
				if ( 'with_order' === $order->get_meta( '_fst_ship_mode' ) && (int) $order->get_meta( '_fst_ship_with_order' ) === (int) $order_id ) {
					$riders[] = (string) $id;
				}
			}
			return $riders;
		}
		public static function host_order_ids( $host ) {
			$ids = [ $host->get_id() ];
			foreach ( array_filter( explode( ',', (string) $host->get_meta( '_fishotel_fulfillment_sources' ) ) ) as $source_id ) {
				$ids[] = (int) $source_id;
			}
			return $ids;
		}
	}
	class WC_Order {
		public $items;
		private $id;
		private $shipping;
		private $meta;
		public function __construct( $id, $shipping, array $items = [], array $meta = [] ) {
			$this->id       = $id;
			$this->shipping = $shipping;
			$this->items    = $items;
			$this->meta     = $meta;
		}
		public function get_id() {
			return $this->id;
		}
		public function get_meta( $key ) {
			return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
		}
		public function get_shipping_total() {
			return $this->shipping;
		}
		public function get_total_shipping_refunded() {
			return 0;
		}
	}
}
$orders = [
	100 => new WC_Order( 100, '45.00', [ 1 => 2, 2 => 3, 3 => 1 ], [ '_fh_weather_action' => 'heat_pack' ] ),
	101 => new WC_Order( 101, '25.00', [ 4 => 4 ] ),
	102 => new WC_Order( 102, '20.01', [ 6 => 2 ], [ '_fst_ship_mode' => 'with_order', '_fst_ship_with_order' => 100 ] ),
	103 => new WC_Order( 103, '0.00', [ 7 => 3 ], [ '_fst_ship_mode' => 'local' ] ),
	104 => new WC_Order( 104, '12.00', [ 8 => 1 ], [ '_fst_ship_mode' => 'with_order', '_fst_ship_with_order' => 100 ] ),
	201 => new WC_Order( 201, '25.00', [ 11 => 2 ], [ '_fishotel_fulfilled_by_order' => 300 ] ),
	202 => new WC_Order( 202, '30.00', [ 21 => 4 ], [ '_fishotel_fulfilled_by_order' => '300' ] ),
	300 => new WC_Order( 300, '0.00', [], [ '_fishotel_fulfillment_sources' => '201,202' ] ),
	301 => new WC_Order( 301, '8.00', [ 31 => 1 ], [ '_fst_ship_mode' => 'with_order', '_fst_ship_with_order' => 300 ] ),
];
function wc_get_order( $id ) {
	global $orders;
	return isset( $orders[ (int) $id ] ) ? $orders[ (int) $id ] : false;
}

check( 'packing_supported() is true once FST_Supplies and set_ship_mode exist', true === FisHotel_Shipment_Cost::packing_supported() );
$supplies = FisHotel_Shipment_Cost::supplies();
check( 'supplies(): id, name, price each, stock', [ 3, 1 ] === array_column( $supplies, 'id' ) && '0.1100' === $supplies[0]['price_each'] && '500' === $supplies[0]['on_hand'] && true === $supplies[1]['low'] );
$d = FisHotel_Shipment_Cost::default_materials( 6, 'heat_pack' );
check( 'default_materials(): ShipTracker\'s usual for 6 fish + heat pack', 'Small' === $d['boxes'][0]['name'] && '6' === $d['boxes'][0]['materials']['lines'][0]['qty'] && '1.80' === $d['boxes'][0]['materials']['foam_cost'] && 2 === count( $d['custom']['lines'] ) && '0.00' === $d['custom']['foam_cost'] );
$d = FisHotel_Shipment_Cost::default_materials( 2, '' );
check( 'no weather data: no pack in the usual', 1 === count( $d['boxes'][0]['materials']['lines'] ) && '2' === $d['custom']['lines'][0]['qty'] );
check( 'pack_action(): what ShipTracker sees on the order', 'heat_pack' === FisHotel_Shipment_Cost::pack_action( $orders[100] ) && '' === FisHotel_Shipment_Cost::pack_action( $orders[101] ) );
check( 'fish_count(): every item, or the ones named', 6.0 === FisHotel_Shipment_Cost::fish_count( $orders[100] ) && 3.0 === FisHotel_Shipment_Cost::fish_count( $orders[100], [ 2 ] ) );

// A box of order 100 (items 1 + 2) shared with order 101 (a row with no item list = all of it).
FST_Shipment::$rows = [
	[ 11, 100, '1ZHOST', '53.73', '6.10', '[1,2]' ],
	[ 12, 101, '1zhost', null, null, null ],
	[ 13, 100, '1ZHOST2', '30.00', null, [ 3 ] ],
];
check( 'box_fish_count(): the items of every row of the box, over its orders', 9.0 === FisHotel_Shipment_Cost::box_fish_count( '1ZHOST', [ 100, 101 ] ), (string) FisHotel_Shipment_Cost::box_fish_count( '1ZHOST', [ 100, 101 ] ) );
check( 'box_fish_count(): one order of it',  5.0 === FisHotel_Shipment_Cost::box_fish_count( '1ZHOST', [ 100 ] ) );
check( 'box_fish_count(): its other box',    1.0 === FisHotel_Shipment_Cost::box_fish_count( '1ZHOST2', [ 100, 101 ] ) );
check( 'box_fish_count(): an unknown box',   0.0 === FisHotel_Shipment_Cost::box_fish_count( '1ZNOPE', [ 100 ] ) );

check( 'ship_mode(): an order with a label',       'label' === FisHotel_Shipment_Cost::ship_mode( $orders[100] )['mode'] );
check( 'ship_mode(): a local pickup',              'local' === FisHotel_Shipment_Cost::ship_mode( $orders[103] )['mode'] );
$s = FisHotel_Shipment_Cost::ship_mode( $orders[102] );
check( 'ship_mode(): went in the box of #100',     'with_order' === $s['mode'] && 100 === $s['with_order_id'] && '100' === $s['with_order_number'] );
check( 'ship_mode(): not an order → label',        'label' === FisHotel_Shipment_Cost::ship_mode( false )['mode'] );

check( 'host_order_ids(): the order itself',       [ 100 ] === FisHotel_Shipment_Cost::host_order_ids( $orders[100] ) );
check( 'host_order_ids(): a fulfillment and its source orders', [ 300, 201, 202 ] === FisHotel_Shipment_Cost::host_order_ids( $orders[300] ) );

check( 'rider_ids(): the orders marked as gone in its box', [ 102, 104 ] === FisHotel_Shipment_Cost::rider_ids( [ 100 ] ), json_encode( FisHotel_Shipment_Cost::rider_ids( [ 100 ] ) ) );
check( 'rider_ids(): none for an order nobody went with',   [] === FisHotel_Shipment_Cost::rider_ids( [ 101 ] ) );
check( 'rider_ids(): listed once over several hosts',       [ 102, 104, 301 ] === FisHotel_Shipment_Cost::rider_ids( [ 100, 300, 100, 0 ] ) );
FST_Shipment::$rows[] = [ 14, 104, '1ZOWN', null, null, null ];
check( 'rider_ids(): an order with a label of its own is no rider', [ 102 ] === FisHotel_Shipment_Cost::rider_ids( [ 100 ] ) );

// Order 100 on screen: two boxes, shared with 101, and 102 went in its box.
$view_boxes = FisHotel_Shipment_Cost::order_boxes( [ 100 ] );
$s          = FisHotel_Shipment_Cost::view_summary( [ 100 ], $view_boxes, FisHotel_Shipment_Cost::rider_ids( [ 100 ] ) );
check( 'view_summary(): 45.00 + 25.00 (sharing) + 20.01 (went in its box)', '90.01' === $s['paid'] && '45.00' === $s['paid_view'] && '45.01' === $s['paid_others'] && [ 101, 102 ] === $s['other_order_ids'] && [ 102 ] === $s['rider_order_ids'], json_encode( $s ) );
check( 'view_summary(): against label + materials of both boxes', '89.83' === $s['cost'] && '83.73' === $s['label_cost'] && '6.10' === $s['materials'] && '0.18' === $s['difference'] && false === $s['loss'] );

// Seen from order 101, which shares only the first of the host's two boxes:
// the order that went with the host is still in that box.
$view_boxes = FisHotel_Shipment_Cost::order_boxes( [ 101 ] );
$hosts      = FisHotel_Shipment_Cost::view_host_ids( [ 101 ], $view_boxes );
check( 'view_host_ids(): the view\'s order and the order sharing its box', [ 101, 100 ] === $hosts, json_encode( $hosts ) );
$s = FisHotel_Shipment_Cost::view_summary( [ 101 ], $view_boxes, FisHotel_Shipment_Cost::rider_ids( $hosts ) );
check( 'seen from the sharing order: its own shipping + half of the host\'s + half of the rider\'s', '57.51' === $s['paid'] && [ 100, 102 ] === $s['other_order_ids'] && [ 102 ] === $s['rider_order_ids'] && '59.83' === $s['cost'], json_encode( $s ) );

// A fulfillment of 201 + 202: order 301 went "in the box of" the fulfillment order.
FST_Shipment::$rows = [
	[ 21, 201, '1ZFF', '40.00', '5.00', null ],
	[ 22, 202, '1ZFF', null, null, null ],
];
$view_boxes = FisHotel_Shipment_Cost::order_boxes( [ 201, 202 ] );
$hosts      = FisHotel_Shipment_Cost::view_host_ids( [ 201, 202 ], $view_boxes );
check( 'view_host_ids(): the source orders and the fulfillment they are in', [ 201, 202, 300 ] === $hosts, json_encode( $hosts ) );
check( 'view_host_ids(): no orders, junk boxes → []', [] === FisHotel_Shipment_Cost::view_host_ids( [ 0, -3 ], [ 'junk', [ 'order_ids' => 'x' ] ] ) );
$s = FisHotel_Shipment_Cost::view_summary( [ 201, 202 ], $view_boxes, FisHotel_Shipment_Cost::rider_ids( $hosts ) );
check( 'fulfillment view: both sources + the order that went with the fulfillment', '63.00' === $s['paid'] && '8.00' === $s['paid_others'] && [ 301 ] === $s['rider_order_ids'] && '45.00' === $s['cost'] && '18.00' === $s['difference'], json_encode( $s ) );

// A failing ShipTracker read never breaks the box.
FST_Supplies::$throw  = true;
FST_Ship_Mode::$throw = true;
check( 'a failing supplies read → [] / nothing usual / no action / 0 fish', [] === FisHotel_Shipment_Cost::supplies() && [] === FisHotel_Shipment_Cost::default_materials( 6, 'heat_pack' )['boxes'] && '' === FisHotel_Shipment_Cost::pack_action( $orders[100] ) && 0.0 === FisHotel_Shipment_Cost::fish_count( $orders[100] ) );
check( 'a failing ship mode read → label, no riders', 'label' === FisHotel_Shipment_Cost::ship_mode( $orders[103] )['mode'] && [] === FisHotel_Shipment_Cost::rider_ids( [ 100 ] ) );
FST_Supplies::$throw  = false;
FST_Ship_Mode::$throw = false;

// ── A ShipTracker with label costs only, in a child process ─────────────
echo "ShipTracker with label costs only (child process)\n";
if ( function_exists( 'exec' ) ) {
	$output = [];
	$code   = 1;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --label-cost-only 2>&1', $output, $code );
	check( 'the Shipments box sees label cost support and no packing support', 0 === $code && in_array( 'all passed', $output, true ), implode( ' | ', $output ) );
} else {
	echo "  skip (exec() is unavailable)\n";
}

finish();
