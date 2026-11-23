<?php
/**
 * Stub-based test for inc/order-fulfillment/class-fishotel-packing-prefill.php
 * — no WordPress, no ShipTracker, no FisHotel Misc, no database.
 *
 *   php tests/packing-prefill-test.php
 *
 * Covers what the Shipments box does with the plan the Packing section of
 * the FisHotel Misc plugin has for an order: which entry of the box size
 * dropdown Packing's box selects (the standard box of its name, else of its
 * size, else "Custom…" with the size typed in), which box an order in
 * several boxes starts on (the first one not used up by a box already
 * recorded — same-size boxes in Packing's order), what a box recorded
 * without a size is offered, the words ("From Packing (suggested) · Box 2
 * of 3", "Use Packing's box: …"), a source order packed with a combined
 * box, and a plan that lists no box.
 *
 * Also the guard rails: Misc missing (this process), Misc answering — a
 * plan, "no plan", something that is not a plan — and Misc throwing (child
 * process `--with-misc`, with a stand-in for fishotel_packing_shipment_plan()).
 * Every one of the "nothing" cases must come back as null.
 *
 * All orders, boxes and fish here are invented.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/inc/order-fulfillment/class-fishotel-shipment-cost.php';
require_once dirname( __DIR__ ) . '/inc/order-fulfillment/class-fishotel-packing-prefill.php';

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

/** One box of Misc's answer. */
function plan_box( $index, $name, $dims, $standard, $layers, array $items = [] ) {
	return [
		'index'    => $index,
		'name'     => $name,
		'dims'     => $dims,
		'standard' => $standard,
		'match'    => null === $standard ? 'none' : 'name',
		'fish'     => (int) ( $layers / 2 ),
		'bags'     => (int) ( $layers / 2 ),
		'layers'   => $layers,
		'by_bag'   => [],
		'items'    => $items,
	];
}
/** Misc's answer for an order with these boxes. */
function plan( array $boxes, $from = 'suggestion', $order_id = 5001 ) {
	$layers = 0;
	foreach ( $boxes as $box ) {
		$layers += is_array( $box ) && isset( $box['layers'] ) ? (int) $box['layers'] : 0;
	}
	return [
		'available'   => true,
		'reason'      => '',
		'order_id'    => $order_id,
		'owner_id'    => $order_id,
		'packed_with' => 0,
		'from'        => $from,
		'split'       => count( $boxes ) > 1,
		'boxes'       => $boxes,
		'lines'       => [],
		'totals'      => [ 'fish' => 0, 'bags' => 0, 'layers' => $layers ],
	];
}
/** Misc's answer when it has nothing to say. */
function no_plan( $reason = 'no_plan', $order_id = 5001 ) {
	return [
		'available' => false, 'reason' => $reason, 'order_id' => $order_id, 'owner_id' => $order_id, 'packed_with' => 0,
		'from' => '', 'split' => false, 'boxes' => [], 'lines' => [], 'totals' => [ 'fish' => 0, 'bags' => 0, 'layers' => 0 ],
	];
}
/** A box already on the order, as FST_Shipment::get_order_boxes() lists it. */
function recorded( $tracking, $dims = null ) {
	return [ 'tracking_number' => $tracking, 'box_dims' => $dims, 'label_cost' => null, 'order_ids' => [ 5001 ] ];
}

$P = 'FisHotel_Packing_Prefill';

// ShipTracker's standard boxes, as the dropdown lists them. "Tall 12" has
// the size of "Cube 12": only the name tells them apart.
$standard = [
	[ 'name' => 'Cube 12', 'dims' => '12x12x12' ],
	[ 'name' => 'Tall 12', 'dims' => '12x12x12' ],
	[ 'name' => 'Long 20', 'dims' => '20x14x12' ],
];
$cube = plan_box( 0, 'Cube 12', '12x12x12', [ 'name' => 'Cube 12', 'dims' => '12x12x12' ], 12, [ '5001-7001' => 2, '5001-7002' => 3 ] );
$tall = plan_box( 0, 'Tall 12', '12x12x12', [ 'name' => 'Tall 12', 'dims' => '12x12x12' ], 8 );
$long = plan_box( 1, 'Long 20', '20x14x12', [ 'name' => 'Long 20', 'dims' => '20x14x12' ], 14 );

// ── With the Misc plugin (child process) ────────────────────────────────
if ( isset( $argv[1] ) && '--with-misc' === $argv[1] ) {
	// What Misc answers per order id; an order it has never heard of throws.
	$GLOBALS['misc_answers'] = [
		5001 => plan( [ $cube ] ),
		5002 => no_plan(),
		5003 => 'not a plan',
		5004 => [ 'available' => true ],
		5005 => array_merge( plan( [] ), [ 'packed_with' => 5900, 'owner_id' => 5900 ] ),
		5006 => no_plan( 'disabled' ),
	];
	function fishotel_packing_shipment_plan( $order_id ) {
		if ( ! isset( $GLOBALS['misc_answers'][ $order_id ] ) ) {
			throw new RuntimeException( 'Misc fell over' );
		}
		return $GLOBALS['misc_answers'][ $order_id ];
	}
	/** An order on screen: only its id is asked for. */
	class Fake_Order {
		private $id;
		public function __construct( $id ) { $this->id = $id; }
		public function get_id() { return $this->id; }
		public function get_order_number() { return 'FH-' . $this->id; }
		public function get_edit_order_url() { return 'https://shop.test/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->id; }
	}
	function wc_get_order( $id ) {
		return 5900 === (int) $id ? new Fake_Order( 5900 ) : false;
	}

	echo "Misc answers\n";
	$got = FisHotel_Packing_Prefill::plan( 5001 );
	check( 'a plan is read', is_array( $got ) && 'plan' === $got['state'] && 'Cube 12' === $got['boxes'][0]['standard_name'], json_encode( $got ) );
	check( '"no plan for this order" → nothing', null === FisHotel_Packing_Prefill::plan( 5002 ) );
	check( 'Packing switched off → nothing', null === FisHotel_Packing_Prefill::plan( 5006 ) );
	check( 'an answer that is not a plan → nothing', null === FisHotel_Packing_Prefill::plan( 5003 ) );
	check( '"available" with no box list → nothing', null === FisHotel_Packing_Prefill::plan( 5004 ) );
	check( 'Misc throwing → nothing, and no error gets out', null === FisHotel_Packing_Prefill::plan( 4040 ) );
	check( 'no order id → Misc is not asked', null === FisHotel_Packing_Prefill::plan( 0 ) );

	echo "for_order()\n";
	$view = FisHotel_Packing_Prefill::for_order( new Fake_Order( 5001 ), $standard, [] );
	check( 'the order on screen starts on Packing\'s box', is_array( $view ) && 'plan' === $view['state'] && 'Cube 12' === $view['add']['name'] && true === $view['add']['on'], json_encode( $view ) );
	check( 'Misc throwing → nothing', null === FisHotel_Packing_Prefill::for_order( new Fake_Order( 4040 ), $standard, [] ) );
	check( 'no plan → nothing', null === FisHotel_Packing_Prefill::for_order( new Fake_Order( 5002 ), $standard, [] ) );
	check( 'not an order → nothing', null === FisHotel_Packing_Prefill::for_order( null, $standard, [] ) && null === FisHotel_Packing_Prefill::for_order( 5001, $standard, [] ) );
	$folded = FisHotel_Packing_Prefill::for_order( new Fake_Order( 5005 ), $standard, [] );
	check( 'a source order packed with a combined box: no box, the combined order\'s number and link',
		is_array( $folded ) && 'folded' === $folded['state'] && 5900 === $folded['packed_with'] && 'FH-5900' === $folded['number'] && false !== strpos( $folded['url'], 'id=5900' ) && ! isset( $folded['add'] ),
		json_encode( $folded ) );
	$GLOBALS['misc_answers'][5007] = array_merge( plan( [] ), [ 'packed_with' => 5901 ] );
	$gone = FisHotel_Packing_Prefill::for_order( new Fake_Order( 5007 ), $standard, [] );
	check( 'the combined order is gone: its id, no link', is_array( $gone ) && '5901' === $gone['number'] && '' === $gone['url'], json_encode( $gone ) );
	finish();
}

// ── No Misc plugin at all ───────────────────────────────────────────────
echo "no Misc plugin\n";
check( 'plan() → nothing', null === FisHotel_Packing_Prefill::plan( 5001 ) );
check( 'for_order() → nothing', null === FisHotel_Packing_Prefill::for_order( new stdClass(), $standard, [] ) );
check( 'view() of nothing → nothing', null === FisHotel_Packing_Prefill::view( null, $standard, [] ) );

// ── dims ────────────────────────────────────────────────────────────────
echo "sizes\n";
check( '"12.5x10x8" reads as three numbers', [ 12.5, 10.0, 8.0 ] === FisHotel_Packing_Prefill::dims_numbers( '12.5x10x8' ) );
check( 'spaces around and a capital X are fine', [ 10.0, 10.0, 10.0 ] === FisHotel_Packing_Prefill::dims_numbers( ' 10X10x10 ' ) );
foreach ( [ '', '10x10', '10x10x10x10', '0x10x10', 'ten x ten x ten', '10x-1x10', null, true, [ '10x10x10' ], '10x10x10 in' ] as $bad ) {
	check( 'not a size: ' . json_encode( $bad ), null === FisHotel_Packing_Prefill::dims_numbers( $bad ) );
}
check( 'written back without trailing zeros', '12.5x9x6' === FisHotel_Packing_Prefill::dims_text( [ 12.50, 9, 6.0 ] ) );
check( '10x10x10 is 10.0x10x10.00', true === FisHotel_Packing_Prefill::same_dims( '10x10x10', '10.0x10x10.00' ) );
check( '12x10x8 is not 8x10x12 (the sides are not swapped)', false === FisHotel_Packing_Prefill::same_dims( '12x10x8', '8x10x12' ) );
check( 'a size is never the same as no size', false === FisHotel_Packing_Prefill::same_dims( '10x10x10', '' ) && false === FisHotel_Packing_Prefill::same_dims( '', '' ) );

// ── clean_plan() ────────────────────────────────────────────────────────
echo "clean_plan()\n";
$clean = FisHotel_Packing_Prefill::clean_plan( plan( [ $cube, $long ], 'confirmed' ) );
check( 'a plan keeps its boxes in Packing\'s order', 'plan' === $clean['state'] && [ 'Cube 12', 'Long 20' ] === array_column( $clean['boxes'], 'name' ), json_encode( $clean ) );
check( 'confirmed stays confirmed', 'confirmed' === $clean['from'] );
check( 'each box: its size, ShipTracker\'s name for it, its bags, its order lines', [ 'name' => 'Long 20', 'dims' => '20x14x12', 'standard_name' => 'Long 20', 'layers' => '14', 'items' => [] ] === $clean['boxes'][1], json_encode( $clean['boxes'][1] ) );
check( 'the lines Packing put in a box, by "<order id>-<item id>"', [ '5001-7001', '5001-7002' ] === $clean['boxes'][0]['items'], json_encode( $clean['boxes'][0] ) );
$odd = $cube;
$odd['items'] = [ '5001-7001' => 2, 'nonsense' => 1, 7 => 1, '5001-' => 1, '5001-7002-1' => 1 ];
check( 'what is not such a line is left out', [ '5001-7001' ] === FisHotel_Packing_Prefill::clean_plan( plan( [ $odd ] ) )['boxes'][0]['items'] );
$odd['items'] = 'all of them';
check( 'lines that are not a list: none known, the box stays', [] === FisHotel_Packing_Prefill::clean_plan( plan( [ $odd ] ) )['boxes'][0]['items'] );
check( 'not confirmed yet → a suggestion', 'suggestion' === FisHotel_Packing_Prefill::clean_plan( plan( [ $cube ] ) )['from'] );
check( 'no word about it → a suggestion', 'suggestion' === FisHotel_Packing_Prefill::clean_plan( plan( [ $cube ], '' ) )['from'] );
$own = FisHotel_Packing_Prefill::clean_plan( plan( [ plan_box( 0, ' Fish 12 ', '12.50x9x6', null, 6 ) ] ) );
check( 'a box ShipTracker does not have: no standard name, the size tidied', '' === $own['boxes'][0]['standard_name'] && '12.5x9x6' === $own['boxes'][0]['dims'] && 'Fish 12' === $own['boxes'][0]['name'], json_encode( $own ) );
$bare = $cube;
unset( $bare['layers'] );
check( 'a box that does not say its bags: no number, not 0', '' === FisHotel_Packing_Prefill::clean_plan( plan( [ $bare ] ) )['boxes'][0]['layers'] );
check( 'a plan that lists no box is still a plan (nothing to fill in)', [ 'state' => 'plan', 'from' => 'suggestion', 'boxes' => [] ] === FisHotel_Packing_Prefill::clean_plan( plan( [] ) ) );
check( 'packed with a combined box', [ 'state' => 'folded', 'packed_with' => 5900 ] === FisHotel_Packing_Prefill::clean_plan( array_merge( plan( [] ), [ 'packed_with' => 5900 ] ) ) );
check( 'packed with a combined box, the id as text', 5900 === FisHotel_Packing_Prefill::clean_plan( array_merge( plan( [] ), [ 'packed_with' => '5900' ] ) )['packed_with'] );

$broken = $cube;
$broken['dims'] = 'big';
foreach ( [
	'nothing at all'                         => null,
	'text'                                   => 'available',
	'a list of nothing'                      => [],
	'not available'                          => no_plan(),
	'not available, though it lists a box'   => array_merge( plan( [ $cube ] ), [ 'available' => false ] ),
	'"available" as text'                    => array_merge( plan( [ $cube ] ), [ 'available' => 'yes' ] ),
	'not available but "packed with"'        => array_merge( no_plan(), [ 'packed_with' => 5900 ] ),
	'boxes that are not a list'              => array_merge( plan( [] ), [ 'boxes' => 'Cube 12' ] ),
	'a box that is not a box'                => plan( [ 'Cube 12' ] ),
	'a box with no size'                     => plan( [ $cube, $broken ] ),
] as $what => $garbage ) {
	check( 'nothing to go on: ' . $what, null === FisHotel_Packing_Prefill::clean_plan( $garbage ) );
}

// ── box_option(): name, then size, then Custom… ─────────────────────────
echo "box_option()\n";
$boxes = FisHotel_Packing_Prefill::clean_plan( plan( [ $cube, $tall, $long ] ) )['boxes'];
check( 'the standard box of that name', [ 'value' => '12x12x12', 'name' => 'Cube 12', 'dims' => '', 'match' => 'name' ] === FisHotel_Packing_Prefill::box_option( $boxes[0], $standard ) );
check( 'two standard boxes of one size: the one Packing named, not the first', 'Tall 12' === FisHotel_Packing_Prefill::box_option( $boxes[1], $standard )['name'] );
check( 'letter case and spaces around the name are set aside — ShipTracker\'s own spelling is used',
	'Long 20' === FisHotel_Packing_Prefill::box_option( [ 'name' => 'x', 'dims' => '1x1x1', 'standard_name' => '  long 20 ', 'layers' => '' ], $standard )['name'] );
check( 'Misc named no standard box: Packing\'s own name is tried', [ 'value' => '20x14x12', 'name' => 'Long 20', 'dims' => '', 'match' => 'name' ] === FisHotel_Packing_Prefill::box_option( [ 'name' => 'LONG 20', 'dims' => '20x14x12', 'standard_name' => '', 'layers' => '' ], $standard ) );
check( 'no box of that name: the first standard box of that size', [ 'value' => '12x12x12', 'name' => 'Cube 12', 'dims' => '', 'match' => 'dims' ] === FisHotel_Packing_Prefill::box_option( [ 'name' => 'Fish cube', 'dims' => '12.0x12x12', 'standard_name' => '', 'layers' => '' ], $standard ) );
check( 'neither: Custom…, with the size typed in', [ 'value' => 'custom', 'name' => '', 'dims' => '12.5x9x6', 'match' => 'custom' ] === FisHotel_Packing_Prefill::box_option( $own['boxes'][0], $standard ) );
check( 'no standard boxes saved: Custom…, with the size typed in', [ 'value' => 'custom', 'name' => '', 'dims' => '12x12x12', 'match' => 'custom' ] === FisHotel_Packing_Prefill::box_option( $boxes[0], [] ) );
check( '"custom" is the dropdown\'s own value for it', FisHotel_Shipment_Cost::CUSTOM_BOX === FisHotel_Packing_Prefill::CUSTOM_BOX );

// ── assign(): which box is next ─────────────────────────────────────────
echo "assign()\n";
function options_of( array $plan_boxes, array $standard ) {
	$options = [];
	foreach ( FisHotel_Packing_Prefill::clean_plan( plan( $plan_boxes ) )['boxes'] as $box ) {
		$options[] = FisHotel_Packing_Prefill::box_option( $box, $standard );
	}
	return $options;
}
$cube2 = $cube;
$cube2['index'] = 1;
$three = options_of( [ $cube, $cube2, $long ], $standard );
check( 'nothing recorded: the first box', [ 'next' => 0, 'cards' => [] ] === FisHotel_Packing_Prefill::assign( $three, [] ) );
check( 'one box of that size recorded: the second of that size', 1 === FisHotel_Packing_Prefill::assign( $three, [ recorded( '1ZAAA', '12x12x12' ) ] )['next'] );
check( 'both of that size recorded: the other size', 2 === FisHotel_Packing_Prefill::assign( $three, [ recorded( '1ZAAA', '12x12x12' ), recorded( '1ZBBB', '12.0x12x12' ) ] )['next'] );
check( 'the big one went first: the first small one is still next', 0 === FisHotel_Packing_Prefill::assign( $three, [ recorded( '1ZCCC', '20x14x12' ) ] )['next'] );
check( 'every box recorded: nothing to fill in', null === FisHotel_Packing_Prefill::assign( $three, [ recorded( '1ZAAA', '12x12x12' ), recorded( '1ZBBB', '12x12x12' ), recorded( '1ZCCC', '20x14x12' ) ] )['next'] );
check( 'a recorded box of a size Packing never planned was still one of the boxes — corrected by hand: it uses up the next one', 1 === FisHotel_Packing_Prefill::assign( $three, [ recorded( '1ZDDD', '8x8x8' ) ] )['next'] && [] === FisHotel_Packing_Prefill::assign( $three, [ recorded( '1ZDDD', '8x8x8' ) ] )['cards'] );
check( '— after the boxes recorded with a planned size took theirs, whichever came first', 2 === FisHotel_Packing_Prefill::assign( $three, [ recorded( '1ZDDD', '8x8x8' ), recorded( '1ZAAA', '12x12x12' ) ] )['next'] );
$two_cubes = options_of( [ $cube, $cube2 ], $standard );
check( 'two boxes planned, the first recorded in a corrected size: the form goes on the second, not back to the first', 1 === FisHotel_Packing_Prefill::assign( $two_cubes, [ recorded( '1ZDDD', '20x14x12' ) ] )['next'] );
check( '— and with both recorded that way nothing is left to fill in', null === FisHotel_Packing_Prefill::assign( $two_cubes, [ recorded( '1ZDDD', '20x14x12' ), recorded( '1ZEEE', '9x9x9' ) ] )['next'] );

// Dry goods sent ahead of the fish: a recorded box known to hold only lines
// that are in none of Packing's boxes was never one of them.
$planned = [ '5001-7001', '5001-7002' ];
check( 'a box that holds only a line Packing boxed nowhere (a kit sent ahead) uses up none — whatever its size', 0 === FisHotel_Packing_Prefill::assign( $two_cubes, [ recorded( '1ZKIT', '12x12x12' ) ], [ '1ZKIT' => [ '5001-7009' ] ], $planned )['next'] && 0 === FisHotel_Packing_Prefill::assign( $two_cubes, [ recorded( '1ZKIT', '6x6x6' ) ], [ '1ZKIT' => [ '5001-7009' ] ], $planned )['next'] );
$kit = FisHotel_Packing_Prefill::assign( $two_cubes, [ recorded( '1zkit' ) ], [ '1ZKIT' => [ '5001-7009' ] ], $planned );
check( '— and recorded with no size it is not offered Packing\'s box either', [ 'next' => 0, 'cards' => [] ] === $kit, json_encode( $kit ) );
check( 'a box with a fish line and the kit is one of Packing\'s', 1 === FisHotel_Packing_Prefill::assign( $two_cubes, [ recorded( '1ZMIX', '12x12x12' ) ], [ '1ZMIX' => [ '5001-7009', '5001-7001' ] ], $planned )['next'] );
check( 'a box that covers every line of its order (no list) is one of Packing\'s', 1 === FisHotel_Packing_Prefill::assign( $two_cubes, [ recorded( '1ZALL', '12x12x12' ) ], [ '1ZALL' => null ], $planned )['next'] && 1 === FisHotel_Packing_Prefill::assign( $two_cubes, [ recorded( '1ZALL', '12x12x12' ) ], [], $planned )['next'] );
check( 'Packing\'s lines not told: no recorded box is left out', 1 === FisHotel_Packing_Prefill::assign( $two_cubes, [ recorded( '1ZKIT', '12x12x12' ) ], [ '1ZKIT' => [ '5001-7009' ] ], [] )['next'] );

echo "box_lines()\n";
$rows = [
	(object) [ 'id' => 1, 'order_id' => 5001, 'tracking_number' => ' 1zkit ', 'line_item_ids' => '[7009]' ],
	(object) [ 'id' => 2, 'order_id' => 5001, 'tracking_number' => '1ZFISH', 'line_item_ids' => [ 7001, '7002' ] ],
	(object) [ 'id' => 3, 'order_id' => 5002, 'tracking_number' => '1ZFISH', 'line_item_ids' => '[7101]' ],
	(object) [ 'id' => 4, 'order_id' => 5001, 'tracking_number' => '1ZALL', 'line_item_ids' => null ],
	(object) [ 'id' => 5, 'order_id' => 5001, 'tracking_number' => '1ZBAD', 'line_item_ids' => 'not json' ],
	(object) [ 'id' => 6, 'order_id' => 5001, 'tracking_number' => '', 'line_item_ids' => '[1]' ],
	'not a row',
];
check( 'the lines of each recorded box, over the orders sharing it, keyed like the cards; a row with no list (or one that cannot be read) says nothing',
	[ '1ZKIT' => [ '5001-7009' ], '1ZFISH' => [ '5001-7001', '5001-7002', '5002-7101' ], '1ZALL' => null, '1ZBAD' => null ] === FisHotel_Packing_Prefill::box_lines( $rows ),
	json_encode( FisHotel_Packing_Prefill::box_lines( $rows ) ) );
check( 'a box with one row listing lines and one covering everything: nothing can be told', [ '1ZX' => null ] === FisHotel_Packing_Prefill::box_lines( [ (object) [ 'order_id' => 5001, 'tracking_number' => '1ZX', 'line_item_ids' => '[7001]' ], (object) [ 'order_id' => 5002, 'tracking_number' => '1zx', 'line_item_ids' => '' ] ] ) && [ '1ZX' => null ] === FisHotel_Packing_Prefill::box_lines( [ (object) [ 'order_id' => 5002, 'tracking_number' => '1zx', 'line_item_ids' => '' ], (object) [ 'order_id' => 5001, 'tracking_number' => '1ZX', 'line_item_ids' => '[7001]' ] ] ) );
check( 'no shipments: nothing', [] === FisHotel_Packing_Prefill::box_lines( [] ) );
$mixed = FisHotel_Packing_Prefill::assign( $three, [ recorded( '1zeee' ), recorded( '1ZAAA', '12x12x12' ) ] );
check( 'a box recorded with no size takes the next one left — after the sized ones took theirs', [ 'next' => 2, 'cards' => [ '1ZEEE' => 1 ] ] === $mixed, json_encode( $mixed ) );
check( 'its key is the box key the cards use', FisHotel_Shipment_Cost::box_key( '1zeee' ) === array_keys( $mixed['cards'] )[0] );
$over = FisHotel_Packing_Prefill::assign( options_of( [ $cube ], $standard ), [ recorded( '1ZAAA' ), recorded( '1ZBBB' ) ] );
check( 'more unsized boxes than Packing planned: the extra one is offered nothing', [ 'next' => null, 'cards' => [ '1ZAAA' => 0 ] ] === $over, json_encode( $over ) );
check( 'a recorded custom size uses up the Packing box of that size', null === FisHotel_Packing_Prefill::assign( options_of( [ plan_box( 0, 'Fish 12', '12.5x9x6', null, 6 ) ], $standard ), [ recorded( '1ZFFF', '12.50x9x6' ) ] )['next'] );
check( 'rows that are not boxes are passed over', 0 === FisHotel_Packing_Prefill::assign( $three, [ null, 'box', [ 'box_dims' => '12x12x12' ] ] )['next'] );
check( 'a plan with no box: nothing next', [ 'next' => null, 'cards' => [] ] === FisHotel_Packing_Prefill::assign( [], [ recorded( '1ZAAA' ) ] ) );

// ── The words ───────────────────────────────────────────────────────────
echo "words\n";
check( 'confirmed', 'From Packing (confirmed)' === FisHotel_Packing_Prefill::from_label( 'confirmed' ) );
check( 'suggested', 'From Packing (suggested)' === FisHotel_Packing_Prefill::from_label( 'suggestion' ) && 'From Packing (suggested)' === FisHotel_Packing_Prefill::from_label( '' ) );
check( 'one box: no "Box 1 of 1"', '' === FisHotel_Packing_Prefill::box_of( 0, 1 ) );
check( 'the second of three', 'Box 2 of 3' === FisHotel_Packing_Prefill::box_of( 1, 3 ) );

// ── view() ──────────────────────────────────────────────────────────────
echo "view()\n";
$view = FisHotel_Packing_Prefill::view( FisHotel_Packing_Prefill::clean_plan( plan( [ $cube ] ) ), $standard, [] );
check( 'one box, nothing recorded: the Add Shipment form starts on it',
	[ 'on' => true, 'value' => '12x12x12', 'name' => 'Cube 12', 'dims' => '', 'match' => 'name', 'layers' => '12', 'items' => [ '5001-7001', '5001-7002' ], 'label' => 'From Packing (suggested)', 'use' => 'Use Packing\'s box: Cube 12 (12x12x12)' ] === $view['add'],
	json_encode( $view['add'] ) );
check( 'no card is offered anything', [] === $view['cards'] );
check( 'the bag line says where its number comes from', 'Double-bagged, triple for spiny fish — from Packing' === $view['bag_note'] );

$kit_rows = FisHotel_Packing_Prefill::box_lines( [ (object) [ 'order_id' => 5001, 'tracking_number' => '1ZKIT', 'line_item_ids' => '[7009]' ] ] );
$view     = FisHotel_Packing_Prefill::view( FisHotel_Packing_Prefill::clean_plan( plan( [ $cube ] ) ), $standard, [ recorded( '1ZKIT', '12x12x12' ) ], $kit_rows );
check( 'the kit went ahead in a box of its own — even one recorded with the fish box\'s size: the fish box is still filled in', is_array( $view['add'] ) && 'Cube 12' === $view['add']['name'] && 'From Packing (suggested)' === $view['add']['label'], json_encode( $view['add'] ) );
$view = FisHotel_Packing_Prefill::view( FisHotel_Packing_Prefill::clean_plan( plan( [ $cube ] ) ), $standard, [ recorded( '1ZKIT' ) ], $kit_rows );
check( '— and the kit\'s box, recorded with no size, is not offered the fish box', is_array( $view['add'] ) && [] === $view['cards'], json_encode( $view ) );
$cube14 = plan_box( 0, 'Cube 12', '12x12x12', [ 'name' => 'Cube 12', 'dims' => '12x12x12' ], 14, [ '5001-7001' => 7 ] );
$cube9  = plan_box( 1, 'Cube 12', '12x12x12', [ 'name' => 'Cube 12', 'dims' => '12x12x12' ], 9, [ '5001-7001' => 1, '5001-7002' => 2 ] );
$view   = FisHotel_Packing_Prefill::view( FisHotel_Packing_Prefill::clean_plan( plan( [ $cube14, $cube9 ], 'confirmed' ) ), $standard, [ recorded( '1ZAAA', '20x14x12' ) ] );
check( 'two boxes (14 and 9 bags), the first recorded in a size corrected by hand: "Box 2 of 2" with its 9 bags — not box 1 again', 'From Packing (confirmed) · Box 2 of 2' === $view['add']['label'] && '9' === $view['add']['layers'] && [ '5001-7001', '5001-7002' ] === $view['add']['items'], json_encode( $view['add'] ) );

$view = FisHotel_Packing_Prefill::view( FisHotel_Packing_Prefill::clean_plan( plan( [ $cube ], 'confirmed' ) ), $standard, [ recorded( '1ZAAA', '12x12x12' ) ] );
check( 'its one box is already recorded: nothing is filled in, nothing offered', null === $view['add'] && [] === $view['cards'] );
check( '— but the bags are still Packing\'s', '' !== $view['bag_note'] );

$split = FisHotel_Packing_Prefill::clean_plan( plan( [ $cube, $cube2, $long ], 'confirmed' ) );
$view  = FisHotel_Packing_Prefill::view( $split, $standard, [ recorded( '1ZAAA', '12x12x12' ) ] );
check( 'three boxes, one recorded: the form starts on the second', 'From Packing (confirmed) · Box 2 of 3' === $view['add']['label'] && 'Cube 12' === $view['add']['name'], json_encode( $view['add'] ) );
$view = FisHotel_Packing_Prefill::view( $split, $standard, [ recorded( '1ZAAA', '12x12x12' ), recorded( '1ZBBB', '12x12x12' ) ] );
check( 'two recorded: the third, with its own bags', 'From Packing (confirmed) · Box 3 of 3' === $view['add']['label'] && '14' === $view['add']['layers'] && '20x14x12' === $view['add']['value'], json_encode( $view['add'] ) );

$view = FisHotel_Packing_Prefill::view( $split, $standard, [ recorded( '1ZAAA', '12x12x12' ), recorded( '1ZGGG' ) ] );
check( 'a box recorded without a size is offered the next Packing box — not put on it',
	isset( $view['cards']['1ZGGG'] ) && false === $view['cards']['1ZGGG']['on'] && 'Use Packing\'s box: Cube 12 (12x12x12)' === $view['cards']['1ZGGG']['use'] && 'From Packing (confirmed) · Box 2 of 3' === $view['cards']['1ZGGG']['label'],
	json_encode( $view['cards'] ) );
check( 'a box recorded with a size is offered nothing', ! isset( $view['cards']['1ZAAA'] ) );
check( 'the Add Shipment form moves on to the box after those', 'Long 20' === $view['add']['name'] );

$view = FisHotel_Packing_Prefill::view( $own, $standard, [] );
check( 'a box that is not a standard box: Custom…, its size, and a word saying so',
	'custom' === $view['add']['value'] && '12.5x9x6' === $view['add']['dims']
		&& 'From Packing (suggested) — “Fish 12” is not on your standard box list, so its size is typed in' === $view['add']['label']
		&& 'Use Packing\'s box: Fish 12 (12.5x9x6)' === $view['add']['use'],
	json_encode( $view['add'] ) );
$view = FisHotel_Packing_Prefill::view( $own, [], [] );
check( 'no standard boxes saved: the size is typed anyway, so only whose box it is', 'From Packing (suggested) — “Fish 12”' === $view['add']['label'], $view['add']['label'] );
$nameless = FisHotel_Packing_Prefill::clean_plan( plan( [ plan_box( 0, '', '9x9x9', null, 4 ) ] ) );
$view     = FisHotel_Packing_Prefill::view( $nameless, $standard, [] );
check( 'a box with no name: "this size"', 'From Packing (suggested) — this size is not on your standard box list, so its size is typed in' === $view['add']['label'] && 'Use Packing\'s box: 9x9x9' === $view['add']['use'], json_encode( $view['add'] ) );

$view = FisHotel_Packing_Prefill::view( FisHotel_Packing_Prefill::clean_plan( plan( [] ) ), $standard, [ recorded( '1ZAAA' ) ] );
check( 'a plan that lists no box: nothing filled in or offered, the bags are Packing\'s', 'plan' === $view['state'] && null === $view['add'] && [] === $view['cards'] && '' !== $view['bag_note'] );
$view = FisHotel_Packing_Prefill::view( [ 'state' => 'folded', 'packed_with' => 5900 ], $standard, [] );
check( 'packed with a combined box: which one — and nothing to fill in', 'folded' === $view['state'] && 5900 === $view['packed_with'] && ! isset( $view['add'] ) && '' !== $view['bag_note'] );
check( 'something that is not a plan → nothing', null === FisHotel_Packing_Prefill::view( [ 'state' => 'else' ], $standard, [] ) && null === FisHotel_Packing_Prefill::view( [], $standard, [] ) );

// ── With the Misc plugin ────────────────────────────────────────────────
echo "with the Misc plugin (child process)\n";
if ( function_exists( 'exec' ) ) {
	$output = [];
	$code   = 1;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --with-misc 2>&1', $output, $code );
	echo '    ' . implode( "\n    ", $output ) . "\n";
	check( 'Misc answering, having no plan, answering nonsense and throwing', 0 === $code );
} else {
	echo "  skip (exec() is unavailable)\n";
}

finish();
