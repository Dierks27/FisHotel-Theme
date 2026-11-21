<?php
/**
 * Stub-based test for the cart / checkout notice of
 * inc/order-fulfillment/class-fishotel-checkout-reuse.php — no WordPress,
 * no WooCommerce, no database.
 *
 *   php tests/checkout-reuse-notice-test.php
 *
 * Covers: "Shipping combined … shipping charge removed" is shown to a
 * customer with an unshipped order when the cart ships something, and is
 * NOT shown when the cart ships nothing (a wallet refill, a request fee):
 * such a cart has no shipping charge to remove and nothing to put in a box.
 * Also: the notice is not queued twice, and nothing is said outside the
 * cart and checkout pages or while the feature is switched off.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

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

/* ---- WordPress / WooCommerce, as far as the notice goes ------------- */

$GLOBALS['fh_options']  = [];
$GLOBALS['fh_notices']  = [];
$GLOBALS['fh_page']     = 'checkout'; // checkout | cart | other
$GLOBALS['fh_wc']       = null;

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['fh_options'] ) ? $GLOBALS['fh_options'][ $key ] : $default;
}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $domain = null ) { return esc_html( $s ); }
function __( $s, $domain = null ) { return $s; }
function is_checkout() { return 'checkout' === $GLOBALS['fh_page']; }
function is_cart() { return 'cart' === $GLOBALS['fh_page']; }
function wc_add_notice( $msg, $type = 'success' ) { $GLOBALS['fh_notices'][] = [ 'notice' => $msg, 'type' => $type ]; }
function wc_get_notices( $type = '' ) {
	return array_values( array_filter( $GLOBALS['fh_notices'], function ( $n ) use ( $type ) { return '' === $type || $n['type'] === $type; } ) );
}
function WC() { return $GLOBALS['fh_wc']; }

class WC_Order {
	private $number;
	public function __construct( $number ) { $this->number = $number; }
	public function get_order_number() { return (string) $this->number; }
	public function get_id() { return (int) $this->number; }
	public function get_items() { return []; }
}
class WC_Product {}
class WC_Shipping_Rate {}

/** The cart: no lines are needed here, only whether anything in it ships. */
class FH_Test_Cart {
	public $ships;
	public function __construct( $ships ) { $this->ships = $ships; }
	public function get_cart() { return []; }
	public function needs_shipping() { return $this->ships; }
}

/** The re-order lock: the customer's unshipped order, when there is one. */
class FisHotel_Reorder_Checkout_Lock {
	public static $anchor = null;
	public static function anchor_order() { return self::$anchor; }
}

require_once dirname( __DIR__ ) . '/inc/order-fulfillment/class-fishotel-checkout-reuse.php';

function fh_notice_texts() {
	return array_map( function ( $n ) { return strip_tags( $n['notice'] ); }, $GLOBALS['fh_notices'] );
}
function fh_run( $ships, $page = 'checkout' ) {
	$GLOBALS['fh_notices'] = [];
	$GLOBALS['fh_page']    = $page;
	$GLOBALS['fh_wc']      = (object) [ 'cart' => null === $ships ? null : new FH_Test_Cart( $ships ), 'customer' => null ];
	FisHotel_Checkout_Reuse::maybe_notice();
	return fh_notice_texts();
}

FisHotel_Reorder_Checkout_Lock::$anchor = new WC_Order( 4501 );
$combined = '📦 Shipping combined. You have an unshipped order (#4501) shipping to the same address. We’ll ship both together — shipping charge removed.';

echo "A cart that ships something, with an unshipped order\n";
check( 'checkout: "Shipping combined", naming the order', [ $combined ] === fh_run( true ), implode( ' | ', fh_run( true ) ) );
check( 'cart page: the same', [ $combined ] === fh_run( true, 'cart' ) );
FisHotel_Checkout_Reuse::maybe_notice();
check( 'totals worked out a second time: the notice is not queued twice', 1 === count( $GLOBALS['fh_notices'] ) );

echo "\nA cart that ships nothing (a wallet refill, a request fee)\n";
check( 'checkout: nothing is said about shipping', [] === fh_run( false ), implode( ' | ', fh_run( false ) ) );
check( 'cart page: nothing either', [] === fh_run( false, 'cart' ) );

echo "\nNo unshipped order\n";
FisHotel_Reorder_Checkout_Lock::$anchor = null;
check( 'a cart that ships: no notice', [] === fh_run( true ) );
check( 'a cart that does not: no notice', [] === fh_run( false ) );
FisHotel_Reorder_Checkout_Lock::$anchor = new WC_Order( 4501 );

echo "\nThe edges\n";
check( 'no cart object to ask: as before — the notice shows', [ $combined ] === fh_run( null ) );
check( 'any other page: nothing', [] === fh_run( true, 'other' ) );
$GLOBALS['fh_options']['fishotel_checkout_reuse'] = '0';
check( 'the feature switched off: nothing', [] === fh_run( true ) );
unset( $GLOBALS['fh_options']['fishotel_checkout_reuse'] );

echo "\n" . ( $failures ? "$failures FAILED\n" : "all passed\n" );
exit( $failures ? 1 : 0 );
