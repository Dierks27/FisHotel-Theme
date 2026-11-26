<?php
/**
 * USPS letters (ShipTracker v1.10.0) and the two customer-side readers of
 * ShipTracker's rows: the delivery-date lock (FisHotel_Self_Serve_Date
 * can_change() / lock_reason()) and the My Account address update
 * (FisHotel_Address_Propagation has_shipment_in_motion()). Runs the real
 * classes against stand-ins for WordPress, WooCommerce and ShipTracker.
 * No WordPress, no database.
 *
 *   php tests/letters-customer-locks-test.php
 *   php tests/letters-customer-locks-test.php --theme=DIR   (another copy of the theme)
 *
 * A letter is a wp_fst_shipments row with carrier `usps_letter` (stickers
 * mailed in an envelope). It is not a box:
 *   - a sticker letter on the EA-only order of a combined box does not lock
 *     the delivery date of the fish order in the same box, nor its own;
 *   - a fish order with only a letter still takes a My Account address
 *     change (no box is in motion);
 *   - a real box still locks and still stops the address, letter or not;
 *   - with ShipTracker 1.9.18 (no letters) every answer is what it was.
 *
 * All orders and dates here are invented.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$theme = dirname( __DIR__ );
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( preg_match( '/^--theme=(.+)$/', $arg, $m ) ) {
		$theme = rtrim( $m[1], '/' );
	}
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

// WordPress.
function add_action() {}
function wp_timezone() { return new DateTimeZone( 'UTC' ); }

// WooCommerce.
class WC_Order_Item_Product {
	private $meta;
	public function __construct( array $meta = [] ) { $this->meta = $meta; }
	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
}
class WC_Order {
	private $id;
	private $meta;
	private $items;
	public function __construct( $id, array $items, array $meta = [] ) {
		$this->id    = $id;
		$this->items = $items;
		$this->meta  = $meta;
	}
	public function get_id() { return $this->id; }
	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function get_items( $type = 'line_item' ) { return $this->items; }
	public function has_status( $statuses ) { return in_array( 'processing', (array) $statuses, true ); }
}

$date   = gmdate( 'Y-m-d', time() + 30 * 86400 );
$orders = [
	// A combined box: fish order 7001 and EA-only order 7002 (stickers by letter).
	7900 => new WC_Order( 7900, [], [ 'sources' => [ 7001, 7002 ], '_fishotel_shipping_date' => $date ] ),
	7001 => new WC_Order( 7001, [ 9001 => new WC_Order_Item_Product() ], [ '_fishotel_fulfilled_by_order' => 7900 ] ),
	7002 => new WC_Order( 7002, [ 9002 => new WC_Order_Item_Product() ], [ '_fishotel_fulfilled_by_order' => 7900, '_fishotel_sticker_mail_letter' => 1 ] ),
	// A fish order on its own.
	7003 => new WC_Order( 7003, [ 9003 => new WC_Order_Item_Product() ], [ '_fishotel_shipping_date' => $date ] ),
];
function wc_get_order( $id ) {
	global $orders;
	return isset( $orders[ (int) $id ] ) ? $orders[ (int) $id ] : false;
}
function fishotel_is_fulfillment( $order ) { return [] !== fishotel_fulfillment_get_sources( $order ); }
function fishotel_fulfillment_get_sources( $order ) {
	$sources = $order->get_meta( 'sources' );
	return is_array( $sources ) ? $sources : [];
}

// ShipTracker: the rows of the case being checked.
$rows = [];
class FST_Shipment {
	public static function get_by_order( $order_id ) {
		$out = [];
		foreach ( $GLOBALS['rows'] as $id => $r ) {
			if ( $r[0] === (int) $order_id ) {
				$out[] = (object) [ 'id' => $id, 'order_id' => $r[0], 'tracking_number' => $r[1], 'carrier' => $r[2], 'status' => 'in_transit', 'line_item_ids' => $r[3] ];
			}
		}
		return $out;
	}
}
$letter = static function ( $order_id, $n ) {
	return [ $order_id, '00700123456000000' . sprintf( '%04d', $n ) . '155555123456', 'usps_letter', null ];
};
$box = static function ( $order_id, $item_id ) {
	return [ $order_id, '1ZBOX' . $order_id, 'ups', json_encode( [ $item_id ] ) ];
};

require_once $theme . '/inc/order-fulfillment/class-fishotel-shipment-cost.php';
require_once $theme . '/inc/customer/class-fishotel-self-serve-date.php';
require_once $theme . '/inc/customer/class-fishotel-address-propagation.php';

$failures = 0;
function check( $label, $ok, $detail = '' ) {
	global $failures;
	if ( $ok ) {
		echo "  ok   $label\n";
	} else {
		$failures++;
		echo "  FAIL $label" . ( '' !== $detail ? " — $detail" : '' ) . "\n";
	}
}
function can( $id ) { return FisHotel_Self_Serve_Date::can_change( wc_get_order( $id ) ); }
function reason( $id ) { return FisHotel_Self_Serve_Date::lock_reason( wc_get_order( $id ) ); }
function in_motion( $id ) {
	$m = new ReflectionMethod( 'FisHotel_Address_Propagation', 'has_shipment_in_motion' );
	$m->setAccessible( true );
	return $m->invoke( null, wc_get_order( $id ) );
}

echo "ShipTracker 1.9.18 (no letters)\n";
$rows = [];
check( 'combined box, nothing shipped: the fish order can change its date', true === can( 7001 ) && '' === reason( 7001 ) );
check( 'the fish order on its own: date open, no box in motion', true === can( 7003 ) && false === in_motion( 7003 ) );
$rows = [ 11 => $box( 7001, 9001 ) ];
check( 'a box on the fish order locks the whole combined box ("shipped")', false === can( 7001 ) && false === can( 7002 ) && 'shipped' === reason( 7002 ) );
check( 'that box is in motion for the fish order and for its combined box', true === in_motion( 7001 ) );

echo "a sticker letter on the EA-only order of a combined box\n";
$rows = [ 21 => $letter( 7002, 1 ) ];
check( 'the fish order in the same box can still change its date', true === can( 7001 ), var_export( can( 7001 ), true ) );
check( 'its lock reason is none ("")', '' === reason( 7001 ), reason( 7001 ) );
check( 'the EA-only order with the letter is not locked either', true === can( 7002 ) && '' === reason( 7002 ) );
check( 'the EA-only order has no box in motion', false === in_motion( 7002 ) );
$rows = [ 21 => $letter( 7002, 1 ), 22 => $box( 7001, 9001 ) ];
check( 'a real box next to the letter still locks ("shipped")', false === can( 7001 ) && 'shipped' === reason( 7001 ) && 'shipped' === reason( 7002 ) );

echo "a fish order with only a letter\n";
$rows = [ 31 => $letter( 7003, 2 ) ];
check( 'no box in motion: a My Account address change still reaches it', false === in_motion( 7003 ) );
check( 'its delivery date is open', true === can( 7003 ) && '' === reason( 7003 ) );
$rows = [ 31 => $letter( 7003, 2 ), 32 => $box( 7003, 9003 ) ];
check( 'with its box as well: in motion and locked', true === in_motion( 7003 ) && false === can( 7003 ) && 'shipped' === reason( 7003 ) );

echo "\n" . ( $failures ? "$failures FAILED\n" : "all passed\n" );
exit( $failures ? 1 : 0 );
