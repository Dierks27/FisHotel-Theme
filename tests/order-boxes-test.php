<?php
/**
 * Test for the theme's boxes on the order edit screen (v1.28.0):
 *  - EA Packing Slip is registered only when the order (or its combined
 *    secondaries, or a combined box's sources) has a line set to EA —
 *    an EA line missing its EA SKU still counts;
 *  - Combine Orders is not registered on a combined box (fulfillment);
 *  - the raw `_fishotel_source_line_item_id` under combined-box items is
 *    hidden (woocommerce_hidden_order_itemmeta);
 *  - Date Change Override stays on a source folded into a combined box
 *    (the customer's self-serve change reads the override from that order).
 * Loads the real files with stub orders. No WordPress, no database.
 *
 *   php tests/order-boxes-test.php
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['boxes']   = [];
$GLOBALS['orders']  = [];
$GLOBALS['pmeta']   = [];
function add_action() {}
function add_filter() {}
function __( $t, $d = '' ) { return $t; }
function add_meta_box( $id, $title, $cb, $screen, $ctx = 'advanced', $prio = 'default' ) { $GLOBALS['boxes'][] = [ $id, $screen, $ctx, $prio ]; }
function wc_get_page_screen_id( $s ) { return 'woocommerce_page_wc-orders'; }
function get_current_screen() { return (object) [ 'id' => $GLOBALS['screen'] ?? 'shop_order' ]; }
function wc_get_order( $id ) { return $GLOBALS['orders'][ (int) $id ] ?? false; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['pmeta'][ $id ][ $key ] ?? ''; }
class WP_Post {
	public $ID; public $post_type;
	public function __construct( $id, $type ) { $this->ID = $id; $this->post_type = $type; }
}
class WC_Order {
	private $id; private $meta; private $items; private $status;
	public function __construct( $id, $meta = [], $items = [], $status = 'processing' ) {
		$this->id = $id; $this->meta = $meta; $this->items = $items; $this->status = $status;
		$GLOBALS['orders'][ $id ] = $this;
	}
	public function get_id() { return $this->id; }
	public function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
	public function get_items( $type = 'line_item' ) { return $this->items; }
	public function get_status() { return $this->status; }
	public function get_type() { return 'shop_order'; }
}
class WC_Order_Item_Product {
	private $pid;
	public function __construct( $pid ) { $this->pid = $pid; }
	public function get_product_id() { return $this->pid; }
	public function get_variation_id() { return 0; }
}
// Secondaries lookup (Phase 2 combine) — SQL in the real code.
class Fake_WPDB {
	public $prefix = 'wp_'; public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts';
	public function prepare( $q, ...$a ) { return [ $q, $a ]; }
	public function get_col( $prepared ) { $primary = (int) end( $prepared[1] ); return $GLOBALS['secondaries'][ $primary ] ?? []; }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require_once dirname( __DIR__ ) . '/inc/order-fulfillment/class-fishotel-fulfillment.php';
require_once dirname( __DIR__ ) . '/inc/order-fulfillment/class-fishotel-order-fulfillment.php';
require_once dirname( __DIR__ ) . '/inc/medication-store/packing-slip.php';
require_once dirname( __DIR__ ) . '/inc/customer/class-fishotel-date-unlock-metabox.php';

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
function boxes_after( callable $fn ) {
	$GLOBALS['boxes'] = [];
	$fn();
	return array_map( function ( $b ) { return $b[0] . '@' . $b[1]; }, $GLOBALS['boxes'] );
}

// Products: 10 = live fish, 20 = EA med with SKU, 21 = EA med missing SKU, 30 = self-shipped med.
$GLOBALS['pmeta'] = [
	20 => [ '_fishotel_fulfillment' => 'ea', '_fishotel_ea_sku' => 'EA-TEST-1' ],
	21 => [ '_fishotel_fulfillment' => 'ea' ],
	30 => [ '_fishotel_fulfillment' => 'self' ],
];
$fish_only  = new WC_Order( 101, [], [ new WC_Order_Item_Product( 10 ) ] );
$with_ea    = new WC_Order( 102, [], [ new WC_Order_Item_Product( 10 ), new WC_Order_Item_Product( 20 ) ] );
$ea_no_sku  = new WC_Order( 103, [], [ new WC_Order_Item_Product( 21 ) ] );
$self_med   = new WC_Order( 104, [], [ new WC_Order_Item_Product( 30 ) ] );
$primary    = new WC_Order( 105, [], [ new WC_Order_Item_Product( 10 ) ] );
$secondary  = new WC_Order( 106, [ '_fishotel_combined_into' => 105 ], [ new WC_Order_Item_Product( 20 ) ] );
$GLOBALS['secondaries'][105] = [ 106 ];
// Combined box 120 with sources 121 (fish) + 122 (EA med); 123 is a box of fish only.
$src_fish   = new WC_Order( 121, [ '_fishotel_fulfilled_by_order' => 120 ], [ new WC_Order_Item_Product( 10 ) ] );
$src_ea     = new WC_Order( 122, [ '_fishotel_fulfilled_by_order' => 120 ], [ new WC_Order_Item_Product( 20 ) ] );
$box_ea     = new WC_Order( 120, [ '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '121,122' ], [ new WC_Order_Item_Product( 10 ), new WC_Order_Item_Product( 20 ) ], 'fulfillment' );
$src_fish2  = new WC_Order( 124, [ '_fishotel_fulfilled_by_order' => 123 ], [ new WC_Order_Item_Product( 10 ) ] );
$box_fish   = new WC_Order( 123, [ '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '124' ], [ new WC_Order_Item_Product( 10 ) ], 'fulfillment' );
// A box whose EA source was cancelled: the slip ignores cancelled sources.
$src_dead   = new WC_Order( 126, [ '_fishotel_fulfilled_by_order' => 125 ], [ new WC_Order_Item_Product( 20 ) ], 'cancelled' );
$box_dead   = new WC_Order( 125, [ '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '126,124' ], [], 'fulfillment' );

$slip = function ( $order, $legacy = true ) {
	return boxes_after( function () use ( $order, $legacy ) {
		FisHotel_Med_Packing_Slip::register_meta_box(
			$legacy ? 'shop_order' : 'woocommerce_page_wc-orders',
			$legacy ? new WP_Post( $order->get_id(), 'shop_order' ) : $order
		);
	} );
};

echo "EA Packing Slip\n";
check( 'fish-only order: no slip box', [] === $slip( $fish_only ) );
check( 'order with an EA line: slip box on both screens', [ 'fishotel-med-packing-slip@shop_order', 'fishotel-med-packing-slip@woocommerce_page_wc-orders' ] === $slip( $with_ea ), json_encode( $slip( $with_ea ) ) );
check( 'HPOS screen gets the order object', 2 === count( $slip( $with_ea, false ) ) );
check( 'EA line missing its SKU still gets the box (it names the line)', 2 === count( $slip( $ea_no_sku ) ) );
check( 'self-shipped medication only: no slip box', [] === $slip( $self_med ) );
check( 'primary whose combined secondary has an EA line: box', 2 === count( $slip( $primary ) ) );
check( 'combined box with an EA source: box', 2 === count( $slip( $box_ea ) ) );
check( 'combined box of fish only: no box', [] === $slip( $box_fish ) );
check( 'combined box whose EA source was cancelled: no box', [] === $slip( $box_dead ) );
check( 'source with EA lines folded into a box: box (it points to the box)', 2 === count( $slip( $src_ea ) ) );
check( 'fish source folded into a box: no box', [] === $slip( $src_fish ) );
check( 'a product screen (not an order): no box', [] === boxes_after( function () { FisHotel_Med_Packing_Slip::register_meta_box( 'product', new WP_Post( 102, 'product' ) ); } ) );
check( 'no post at all: no box', [] === boxes_after( function () { FisHotel_Med_Packing_Slip::register_meta_box( 'shop_order', null ); } ) );

echo "Combine Orders\n";
$combine = function ( $order ) {
	return boxes_after( function () use ( $order ) { FisHotel_Order_Fulfillment::register_combine_meta_box( new WP_Post( $order->get_id(), 'shop_order' ) ); } );
};
check( 'normal order: box', [ 'fishotel-combine-orders@shop_order' ] === $combine( $fish_only ), json_encode( $combine( $fish_only ) ) );
check( 'source folded into a box: box (it points to the box)', 1 === count( $combine( $src_fish ) ) );
check( 'combined box: no box', [] === $combine( $box_ea ) );
$GLOBALS['screen'] = 'woocommerce_page_wc-orders';
check( 'combined box on HPOS (order object): no box', [] === boxes_after( function () use ( $box_ea ) { FisHotel_Order_Fulfillment::register_combine_meta_box( $box_ea ); } ) );
check( 'normal order on HPOS: box on the HPOS screen', [ 'fishotel-combine-orders@woocommerce_page_wc-orders' ] === boxes_after( function () use ( $fish_only ) { FisHotel_Order_Fulfillment::register_combine_meta_box( $fish_only ); } ) );
$GLOBALS['screen'] = 'shop_order';

echo "Date Change Override\n";
$unlock = function ( $order ) {
	return boxes_after( function () use ( $order ) { FisHotel_Date_Unlock_Metabox::register( new WP_Post( $order->get_id(), 'shop_order' ) ); } );
};
check( 'normal order: box', 1 === count( $unlock( $fish_only ) ) );
check( 'folded source: box stays (self-serve reads the override from it)', 1 === count( $unlock( $src_fish ) ) );
check( 'combined box: no box (as before)', [] === $unlock( $box_ea ) );

echo "Combined-box item meta\n";
$hidden = FisHotel_Fulfillment::hide_source_line_item_meta( [ '_qty', '_line_total' ] );
check( 'source line item id hidden', in_array( '_fishotel_source_line_item_id', $hidden, true ) );
check( 'WooCommerce keys kept', in_array( '_qty', $hidden, true ) && in_array( '_line_total', $hidden, true ) );
check( 'Source order (the linked one) stays visible', ! in_array( '_fishotel_source_order', $hidden, true ) );
check( 'not added twice', 1 === count( array_keys( FisHotel_Fulfillment::hide_source_line_item_meta( $hidden ), '_fishotel_source_line_item_id', true ) ) );
check( 'a non-array from another filter is survived', [ '_fishotel_source_line_item_id' ] === FisHotel_Fulfillment::hide_source_line_item_meta( null ) );

echo "\n" . ( $failures ? "$failures FAILED" : 'all passed' ) . "\n";
exit( $failures ? 1 : 0 );
