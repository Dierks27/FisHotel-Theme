<?php
/**
 * US dates — where the theme shows a date as digits (1.29.1).
 *
 *   php tests/us-dates-screens-test.php
 *   php tests/us-dates-screens-test.php --theme=DIR   (another copy of the theme)
 *
 * What a person reads is month-day-year (11-27-26); what is saved or sent
 * stays YYYY-MM-DD. Runs the real classes against stand-ins for WordPress
 * and WooCommerce — no WordPress, no database:
 *
 *   - FisHotel_Self_Serve_Date::ajax_change_delivery_date(): the order note,
 *     the note on every order of a combined box, the e-mail to staff — and
 *     the saved order meta, the AJAX answer and the customer's e-mail, which
 *     must not change;
 *   - FisHotel_Fulfillment::add_source(): the two delivery-date notes of a
 *     combined box, and the date saved on the fulfillment;
 *   - the medication terms line (wp-admin box, e-mails, My Account, PDFs):
 *     shown US-style from the stored record, which is never rewritten;
 *   - the theme has no pop-up question and no text box that asks for a date.
 *
 * All orders, people and dates here are invented.
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
define( 'DAY_IN_SECONDS', 86400 );
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
// WordPress pins PHP's own zone to UTC; the shop is in Central time.
date_default_timezone_set( 'UTC' );

// ── WordPress ────────────────────────────────────────────────────────
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function __( $text ) { return $text; }
function esc_html__( $text ) { return $text; }
function esc_html_e( $text ) { echo $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return (string) $url; }
function _n( $one, $many, $n ) { return 1 === (int) $n ? $one : $many; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function check_ajax_referer() { return true; }
function get_current_user_id() { return 7; }
function get_option( $name, $default = false ) { return 'admin_email' === $name ? 'frontdesk@example.test' : $default; }
function admin_url( $path = '' ) { return 'https://shop.example.test/wp-admin/' . $path; }
function wp_timezone() { return new DateTimeZone( 'America/Chicago' ); }
function wp_date( $format, $ts = null ) {
	$d = new DateTime( '@' . (int) ( null === $ts ? time() : $ts ) );
	$d->setTimezone( wp_timezone() );
	return $d->format( $format );
}
function date_i18n( $format, $ts = false ) { return gmdate( $format, false === $ts ? time() : (int) $ts ); }
function wp_list_pluck( $rows, $field ) {
	$out = [];
	foreach ( $rows as $key => $row ) {
		$out[ $key ] = $row[ $field ];
	}
	return $out;
}
function is_admin() { return false; }
function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }

$mails = [];
function wp_mail( $to, $subject, $body ) {
	$GLOBALS['mails'][] = [ 'to' => $to, 'subject' => $subject, 'body' => $body ];
	return true;
}

/** wp_send_json_*() end the request; here they end the call. */
class FH_Json_Sent extends Exception {
	public $ok;
	public $data;
	public function __construct( $ok, $data ) {
		parent::__construct( 'json' );
		$this->ok   = $ok;
		$this->data = $data;
	}
}
function wp_send_json_success( $data = null ) { throw new FH_Json_Sent( true, $data ); }
function wp_send_json_error( $data = null ) { throw new FH_Json_Sent( false, $data ); }

// ── WooCommerce ──────────────────────────────────────────────────────
class WC_Order_Item_Product {}
class WC_Order {
	public $id;
	public $meta;
	public $notes   = [];
	public $saves   = 0;
	public $status  = 'processing';
	public $total   = 0.0;
	public $address = [ 'billing' => [ 'email' => 'maria.testwell@example.test' ], 'shipping' => [] ];
	public function __construct( $id = 0, array $meta = [] ) {
		$this->id   = $id;
		$this->meta = $meta;
	}
	public function get_id() { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_type() { return 'shop_order'; }
	public function get_customer_id() { return 7; }
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function add_order_note( $note, $is_customer_note = 0 ) {
		$this->notes[] = [ 'text' => $note, 'to_customer' => (bool) $is_customer_note ];
		return count( $this->notes );
	}
	public function save() { $this->saves++; return $this->id; }
	public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
	public function get_status() { return $this->status; }
	public function get_items( $type = 'line_item' ) { return []; }
	public function get_subtotal() { return 40.0; }
	public function set_total( $total ) { $this->total = (float) $total; }
	public function get_billing_first_name() { return 'Maria'; }
	public function get_billing_last_name() { return 'Testwell'; }
	public function get_billing_email() { return $this->address['billing']['email']; }
	public function get_address( $kind ) { return $this->address[ $kind ]; }
	public function set_address( $address, $kind ) { $this->address[ $kind ] = $address; }
	public function get_edit_order_url() { return admin_url( 'post.php?post=' . $this->id . '&action=edit' ); }
	/** Every note as one text. */
	public function all_notes() { return implode( "\n", array_column( $this->notes, 'text' ) ); }
}
$orders = [];
function wc_get_order( $id ) {
	global $orders;
	return isset( $orders[ (int) $id ] ) ? $orders[ (int) $id ] : false;
}
function wc_get_orders( $args ) { return []; }
function wc_format_decimal( $n ) { return number_format( (float) $n, 2, '.', '' ); }

// ── Medication store stand-ins (what terms-display.php asks of its neighbours) ──
function fishotel_med_order_med_lines( $order ) { return [ [ 'id' => 1, 'name' => 'Test Prazi Powder', 'qty' => 1 ] ]; }
class FisHotel_Med_Disclaimer {
	public static function terms_link_html() { return '<a href="#terms">Read the terms</a>'; }
}

require_once $theme . '/inc/us-dates.php';
require_once $theme . '/inc/customer/shipping-days-helper.php';
require_once $theme . '/inc/order-fulfillment/class-fishotel-fulfillment.php';
require_once $theme . '/inc/customer/class-fishotel-self-serve-date.php';
require_once $theme . '/inc/medication-store/terms-record.php';
require_once $theme . '/inc/medication-store/terms-display.php';

$passes   = 0;
$failures = 0;
function check( $label, $ok, $detail = '' ) {
	global $passes, $failures;
	if ( $ok ) {
		$passes++;
		echo "  ok   $label\n";
	} else {
		$failures++;
		echo "  FAIL $label" . ( '' !== $detail ? " — $detail" : '' ) . "\n";
	}
}
function same( $label, $want, $got ) {
	check( $label, $want === $got, 'want ' . var_export( $want, true ) . ', got ' . var_export( $got, true ) );
}
function has( $label, $needle, $haystack ) {
	check( $label, false !== strpos( (string) $haystack, $needle ), 'no "' . $needle . '" in: ' . $haystack );
}
function has_not( $label, $needle, $haystack ) {
	check( $label, false === strpos( (string) $haystack, $needle ), '"' . $needle . '" found in: ' . $haystack );
}
/** MM-DD-YY written out by hand, so the test does not lean on the helper it checks. */
function us( $ymd ) {
	return substr( $ymd, 5, 2 ) . '-' . substr( $ymd, 8, 2 ) . '-' . substr( $ymd, 2, 2 );
}
/** A customer presses Confirm on My Account → Change Delivery Date. */
function customer_changes_date( $order_id, $new_date ) {
	$_POST = [ 'order_id' => (string) $order_id, 'new_date' => $new_date, 'nonce' => 'x' ];
	try {
		FisHotel_Self_Serve_Date::ajax_change_delivery_date();
	} catch ( FH_Json_Sent $sent ) {
		return $sent;
	}
	return null;
}

// Delivery days the picker would really offer today (they move with the clock,
// so they are read from the picker and never typed here).
$offered = array_column( FisHotel_Self_Serve_Date::get_available_dates( '' ), 'date' );
check( 'the picker offers at least three days to test with', count( $offered ) >= 3, (string) count( $offered ) );
list( $old, $new, $newer ) = array_slice( $offered, 1, 3 ) + [ '', '', '' ];
check( 'the picker\'s own values are year-first', 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $old ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new ) );
$labels = array_column( FisHotel_Self_Serve_Date::get_available_dates( $old ), 'label' );
check( 'what the customer picks from is in words ("Tuesday, December 8")', [] === preg_grep( '/^[A-Z][a-z]+day, [A-Z][a-z]+ \d{1,2}$/', $labels, PREG_GREP_INVERT ), implode( ' | ', $labels ) );

echo "\nA customer changes the delivery date of an order on its own\n";
$orders = [ 8101 => new WC_Order( 8101, [ '_fishotel_shipping_date' => $old ] ) ];
$mails  = [];
$sent   = customer_changes_date( 8101, $new );
check( 'the change is accepted', $sent instanceof FH_Json_Sent && true === $sent->ok, $sent ? json_encode( $sent->data ) : 'no answer' );
same( 'SAVED on the order: still year-first', $new, $orders[8101]->meta['_fishotel_shipping_date'] );
same( 'SENT back to the page: new_date still year-first', $new, $sent->data['new_date'] );
same( 'SENT back to the page: the worded date is as before', gmdate( 'l, F j, Y', strtotime( $new ) ), $sent->data['new_date_formatted'] );
same( 'the order note reads month-day-year', 'Customer self-served delivery date change: ' . us( $old ) . ' → ' . us( $new ) . '.', $orders[8101]->notes[0]['text'] );
has_not( 'no year-first date left in the note', substr( $new, 0, 5 ), $orders[8101]->all_notes() );
check( 'the note is for staff, not sent to the customer', false === $orders[8101]->notes[0]['to_customer'] );
same( 'two e-mails: staff, then the customer', [ 'frontdesk@example.test', 'maria.testwell@example.test' ], array_column( $mails, 'to' ) );
has( 'staff e-mail: old date month-day-year', 'Old date: ' . us( $old ) . "\n", $mails[0]['body'] );
has( 'staff e-mail: new date month-day-year', 'New date: ' . us( $new ) . "\n", $mails[0]['body'] );
has_not( 'staff e-mail: no year-first date', substr( $new, 0, 5 ), $mails[0]['body'] );
has( 'customer e-mail: the date in words, as before', gmdate( 'l, F j, Y', strtotime( $new ) ), $mails[1]['body'] );
check( 'customer e-mail: no digits-only date', 0 === preg_match( '/\d{2}-\d{2}-\d{2}/', $mails[1]['body'] ), $mails[1]['body'] );

echo "\nAn order that had no date yet\n";
// Cannot happen from My Account (no date, no button) — the words for it are kept all the same.
$none = new ReflectionMethod( 'FisHotel_Self_Serve_Date', 'notify_admin' );
$none->setAccessible( true );
$mails = [];
$none->invoke( null, $orders[8101], $orders[8101], false, [ $orders[8101] ], '', '2026-12-01' );
has( '"(unset)" stays "(unset)"', "Old date: (unset)\nNew date: 12-01-26\n", $mails[0]['body'] );

echo "\nThe same date again, and a date that is not offered\n";
$sent = customer_changes_date( 8101, $new );
check( 'the date the order already has is refused as before', $sent && false === $sent->ok && 'That is already your delivery date.' === $sent->data, $sent ? json_encode( $sent->data ) : '' );
$before = $orders[8101]->meta['_fishotel_shipping_date'];
$sent   = customer_changes_date( 8101, us( $newer ) );
check( 'a month-day-year value sent by hand is refused — the form only ever sends year-first', $sent && false === $sent->ok, $sent ? json_encode( $sent->data ) : '' );
same( 'and nothing was saved', $before, $orders[8101]->meta['_fishotel_shipping_date'] );

echo "\nA customer changes the date of a combined box\n";
$orders = [
	8200 => new WC_Order( 8200, [ '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '8201,8202', '_fishotel_shipping_date' => $old ] ),
	8201 => new WC_Order( 8201, [ '_fishotel_fulfilled_by_order' => 8200, '_fishotel_fulfillment_orig_ship_date' => $old ] ),
	8202 => new WC_Order( 8202, [ '_fishotel_fulfilled_by_order' => 8200 ] ),
];
check( 'the stand-in is read as a combined box', FisHotel_Fulfillment::is_fulfillment( $orders[8200] ) && [ 8201, 8202 ] === FisHotel_Fulfillment::get_sources( $orders[8200] ) );
$mails = [];
$sent  = customer_changes_date( 8201, $new );
check( 'the change is accepted', $sent instanceof FH_Json_Sent && true === $sent->ok, $sent ? json_encode( $sent->data ) : 'no answer' );
same( 'SAVED on the combined box: year-first', $new, $orders[8200]->meta['_fishotel_shipping_date'] );
same( 'SAVED as the order\'s own date to go back to: year-first', $new, $orders[8201]->meta['_fishotel_fulfillment_orig_ship_date'] );
check( 'an order without such a date gets none', ! isset( $orders[8202]->meta['_fishotel_fulfillment_orig_ship_date'] ) );
same( 'note on the combined box', 'Customer self-served delivery date change: ' . us( $old ) . ' → ' . us( $new ) . '.', $orders[8200]->notes[0]['text'] );
same( 'note on the first order in it', 'Delivery date changed via combined fulfillment FF-8200: ' . us( $old ) . ' → ' . us( $new ) . '.', $orders[8201]->notes[0]['text'] );
same( 'note on the second order in it', 'Delivery date changed via combined fulfillment FF-8200: ' . us( $old ) . ' → ' . us( $new ) . '.', $orders[8202]->notes[0]['text'] );
has( 'staff e-mail (combined): old date', 'Old date: ' . us( $old ) . "\n", $mails[0]['body'] );
has( 'staff e-mail (combined): new date', 'New date: ' . us( $new ) . "\n", $mails[0]['body'] );
has_not( 'staff e-mail (combined): no year-first date', substr( $new, 0, 5 ), $mails[0]['body'] );
same( 'SENT back to the page: year-first', $new, $sent->data['new_date'] );

echo "\nAn order joins a combined box (wp-admin)\n";
// The joining customer chose an EARLIER day: the box moves to it.
$orders = [
	8300 => new WC_Order( 8300, [ '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '8301', '_fishotel_shipping_date' => '2026-12-09' ] ),
	8301 => new WC_Order( 8301, [ '_fishotel_fulfilled_by_order' => 8300 ] ),
	8302 => new WC_Order( 8302, [ '_fishotel_shipping_date' => '2026-12-01' ] ),
];
$orders[8300]->status = 'fulfillment';
check( 'the order is added', true === FisHotel_Fulfillment::add_source( 8300, 8302 ) );
same( 'SAVED on the combined box: the earlier day, year-first', '2026-12-01', $orders[8300]->meta['_fishotel_shipping_date'] );
same( 'SAVED as the order\'s own date to go back to: year-first', '2026-12-01', $orders[8302]->meta['_fishotel_fulfillment_orig_ship_date'] );
has( 'the note reads month-day-year', 'Delivery date moved to 12-01-26 — order #8302 chose it (was 12-09-26).', $orders[8300]->all_notes() );
has_not( 'no year-first date in the box\'s notes', '2026-', $orders[8300]->all_notes() );

// The box had no date at all.
$orders = [
	8310 => new WC_Order( 8310, [ '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '8311' ] ),
	8311 => new WC_Order( 8311, [ '_fishotel_fulfilled_by_order' => 8310 ] ),
	8312 => new WC_Order( 8312, [ '_fishotel_shipping_date' => '2027-01-05' ] ),
];
$orders[8310]->status = 'fulfillment';
FisHotel_Fulfillment::add_source( 8310, 8312 );
has( '"none" stays "none"', 'Delivery date moved to 01-05-27 — order #8312 chose it (was none).', $orders[8310]->all_notes() );
same( 'SAVED: year-first', '2027-01-05', $orders[8310]->meta['_fishotel_shipping_date'] );

// The joining customer chose a LATER day: the box keeps its own.
$orders = [
	8320 => new WC_Order( 8320, [ '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '8321', '_fishotel_shipping_date' => '2026-12-01' ] ),
	8321 => new WC_Order( 8321, [ '_fishotel_fulfilled_by_order' => 8320 ] ),
	8322 => new WC_Order( 8322, [ '_fishotel_shipping_date' => '2026-12-09' ] ),
];
$orders[8320]->status = 'fulfillment';
FisHotel_Fulfillment::add_source( 8320, 8322 );
same( 'SAVED on the combined box: unchanged, year-first', '2026-12-01', $orders[8320]->meta['_fishotel_shipping_date'] );
has( 'the note on the joining order reads month-day-year', 'Requested delivery date 12-09-26 superseded by fulfillment #FF-8320, which ships on 12-01-26.', $orders[8322]->all_notes() );
has_not( 'no year-first date in its notes', '2026-', $orders[8322]->all_notes() );

echo "\nMedication terms: accepted at checkout\n";
$tz     = wp_timezone();
$ts     = gmmktime( 19, 3, 0, 10, 17, 2026 ); // 19:03 UTC = 14:03 in Chicago (CDT).
$record = FisHotel_Med_Terms::build_record( [
	'timestamp'     => $ts,
	'timezone'      => $tz,
	'ip'            => '203.0.113.9',
	'label'         => 'I acknowledge that the medications in this order are for ornamental aquarium use only.',
	'terms_version' => '2026-10',
	'terms_body'    => 'For ornamental aquarium fish only.',
	'products'      => [ [ 'id' => 1, 'name' => 'Test Prazi Powder', 'qty' => 1 ] ],
] );
same( 'STORED: the local time string is unchanged', '2026-10-17 14:03 CDT', $record['accepted_at_local'] );
same( 'STORED: the UTC time is unchanged', '2026-10-17T19:03:00+00:00', $record['accepted_at_utc'] );
same( 'STORED: the timestamp is unchanged', $ts, $record['accepted_at'] );
same( 'what is stored is still made by local_time_string()', '2026-10-17 14:03 CDT', FisHotel_Med_Terms::local_time_string( $ts, $tz ) );
same( 'shown_time(): the date part US, the time and zone as stored', '10-17-26 14:03 CDT', FisHotel_Med_Terms::shown_time( $record['accepted_at_local'] ) );
same( 'shown_time(): empty stays empty', '', FisHotel_Med_Terms::shown_time( '' ) );
same( 'shown_time(): text that is not a time is shown as stored', 'unknown', FisHotel_Med_Terms::shown_time( 'unknown' ) );
same( 'accepted_time(): reads it from the record', '10-17-26 14:03 CDT', FisHotel_Med_Terms::accepted_time( $record ) );
same( 'accepted_time(): a record without it gives nothing', '', FisHotel_Med_Terms::accepted_time( [] ) );
same( 'e-mail line', 'Medication terms accepted 10-17-26 14:03 CDT · terms v2026-10', FisHotel_Med_Terms::format_line( $record ) );
same( 'PDF line (with IP)', 'Medication terms accepted 10-17-26 14:03 CDT · IP 203.0.113.9 · v2026-10', FisHotel_Med_Terms::format_line( $record, true ) );
$copy = $record;
FisHotel_Med_Terms::format_line( $copy, true );
check( 'showing a record does not change it', $copy === $record );

$order  = new WC_Order( 8401, [ FisHotel_Med_Terms::META_KEY => $record ] );
$orders = [ 8401 => $order ];
$stored = serialize( $order->meta );

same( 'PDF invoice / packing slip lines', [ 'Medication terms accepted 10-17-26 14:03 CDT · IP 203.0.113.9 · v2026-10' ], FisHotel_Med_Terms_Display::lines_for_order( $order, true ) );

ob_start();
FisHotel_Med_Terms_Display::email_line( $order, false, false, (object) [ 'id' => 'customer_processing_order' ] );
$html = (string) ob_get_clean();
has( 'customer e-mail (HTML)', '>Medication terms accepted 10-17-26 14:03 CDT · terms v2026-10</p>', $html );
ob_start();
FisHotel_Med_Terms_Display::email_line( $order, true, true, (object) [ 'id' => 'new_order' ] );
$text = (string) ob_get_clean();
has( 'staff e-mail (plain text)', "\nMedication terms accepted 10-17-26 14:03 CDT · terms v2026-10\n", $text );

ob_start();
FisHotel_Med_Terms_Display::account_line( $order );
$html = (string) ob_get_clean();
has( 'My Account', 'You accepted the medication terms on 10-17-26 14:03 CDT.', $html );

$render = new ReflectionMethod( 'FisHotel_Med_Terms_Display', 'render_record' );
$render->setAccessible( true );
ob_start();
$render->invoke( null, $record );
$html = (string) ob_get_clean();
check( 'wp-admin box: "Accepted at" reads month-day-year', 1 === preg_match( '~Accepted at</th>\s*<td>10-17-26 14:03 CDT</td>~', $html ), $html );
check( 'wp-admin box: the terms version is a name, not a date — unchanged', 1 === preg_match( '~Terms version</th>\s*<td>2026-10</td>~', $html ), $html );
has_not( 'wp-admin box: no year-first date', '2026-10-17', $html );

echo "\nMedication terms: recorded by hand, on a combined box, and an old order\n";
$manual = FisHotel_Med_Terms::build_manual_record( [
	'timestamp'       => $ts,
	'timezone'        => $tz,
	'label'           => 'Told on the phone.',
	'terms_version'   => '2026-10',
	'admin_user_id'   => 3,
	'admin_user_name' => 'Sam Frontdesk',
] );
same( 'STORED (by hand): unchanged', '2026-10-17 14:03 CDT', $manual['accepted_at_local'] );
same( 'line for staff', 'Medication terms accepted 10-17-26 14:03 CDT · terms v2026-10 (recorded manually by Sam Frontdesk)', FisHotel_Med_Terms::format_line( $manual ) );
$by_hand = new WC_Order( 8402, [ FisHotel_Med_Terms::META_KEY => $manual, '_fishotel_fulfilled_by_order' => 8500 ] );
ob_start();
FisHotel_Med_Terms_Display::email_line( $by_hand, false, false, (object) [ 'id' => 'customer_invoice' ] );
$html = (string) ob_get_clean();
has( 'customer e-mail of a record made by hand', 'Medication terms acknowledgement recorded 10-17-26 14:03 CDT · terms v2026-10', $html );
ob_start();
FisHotel_Med_Terms_Display::account_line( $by_hand );
$html = (string) ob_get_clean();
has( 'My Account of a record made by hand', 'Your medication terms acknowledgement was recorded on 10-17-26 14:03 CDT.', $html );

$legacy = new WC_Order( 8403, [
	FisHotel_Med_Terms::LEGACY_ACCEPTED_KEY    => '1',
	FisHotel_Med_Terms::LEGACY_ACCEPTED_AT_KEY => $ts,
	'_fishotel_fulfilled_by_order'             => 8500,
] );
same( 'an old order (only a timestamp stored)', [ 'Medication terms accepted 10-17-26 14:03 CDT (legacy record)' ], FisHotel_Med_Terms_Display::lines_for_order( $legacy, false ) );
same( 'STORED on the old order: the timestamp, untouched', $ts, $legacy->meta[ FisHotel_Med_Terms::LEGACY_ACCEPTED_AT_KEY ] );
$old_box = new ReflectionMethod( 'FisHotel_Med_Terms_Display', 'render_legacy' );
$old_box->setAccessible( true );
ob_start();
$old_box->invoke( null, $legacy );
has( 'wp-admin box of an old order', 'Accepted at 10-17-26 14:03 CDT', (string) ob_get_clean() );

$order->meta['_fishotel_fulfilled_by_order'] = 8500;
$stored = serialize( $order->meta );
$orders = [
	8500 => new WC_Order( 8500, [ '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '8401,8402,8403' ] ),
	8401 => $order,
	8402 => $by_hand,
	8403 => $legacy,
];
same( 'combined box: one line per order, each month-day-year', [
	'Order #8401: Medication terms accepted 10-17-26 14:03 CDT · terms v2026-10',
	'Order #8402: Medication terms accepted 10-17-26 14:03 CDT · terms v2026-10 (recorded manually by Sam Frontdesk)',
	'Order #8403: Medication terms accepted 10-17-26 14:03 CDT (legacy record)',
], FisHotel_Med_Terms_Display::lines_for_order( $orders[8500], false ) );
$box = new ReflectionMethod( 'FisHotel_Med_Terms_Display', 'render_fulfillment' );
$box->setAccessible( true );
ob_start();
$box->invoke( null, $orders[8500] );
$html = (string) ob_get_clean();
has( 'wp-admin box of a combined box: accepted at checkout', 'Accepted at checkout 10-17-26 14:03 CDT · v2026-10', $html );
has( 'wp-admin box of a combined box: recorded by hand', 'Acknowledged (manual record) 10-17-26 14:03 CDT · v2026-10', $html );
has( 'wp-admin box of a combined box: old order', 'Accepted 10-17-26 14:03 CDT (legacy record)', $html );
has_not( 'wp-admin box of a combined box: no year-first date', '2026-10-17', $html );
check( 'after every screen, e-mail and PDF line, the stored record is byte for byte what it was', serialize( $order->meta ) === $stored );

echo "\nMedication terms: a time near midnight, and the night the clocks go back\n";
// 23:59 on Oct 31 in Chicago is already Nov 1 in UTC: the line must say Oct 31.
$late = FisHotel_Med_Terms::build_record( [ 'timestamp' => gmmktime( 4, 59, 0, 11, 1, 2026 ), 'timezone' => $tz ] );
same( 'STORED: 11:59 pm local', '2026-10-31 23:59 CDT', $late['accepted_at_local'] );
same( 'shown: still Oct 31', '10-31-26 23:59 CDT', FisHotel_Med_Terms::accepted_time( $late ) );
// 1:30 am happens twice on Nov 1, 2026; the zone letters tell them apart and stay.
$first  = FisHotel_Med_Terms::build_record( [ 'timestamp' => gmmktime( 6, 30, 0, 11, 1, 2026 ), 'timezone' => $tz ] );
$second = FisHotel_Med_Terms::build_record( [ 'timestamp' => gmmktime( 7, 30, 0, 11, 1, 2026 ), 'timezone' => $tz ] );
same( 'the first 1:30 am', '11-01-26 01:30 CDT', FisHotel_Med_Terms::accepted_time( $first ) );
same( 'the second 1:30 am', '11-01-26 01:30 CST', FisHotel_Med_Terms::accepted_time( $second ) );
// The server's own zone must not matter.
foreach ( [ 'Pacific/Kiritimati', 'Pacific/Honolulu' ] as $zone ) {
	date_default_timezone_set( $zone );
	same( "with the server in $zone the line is the same", 'Medication terms accepted 10-31-26 23:59 CDT · terms v2026-10', FisHotel_Med_Terms::format_line( $late ) );
}
date_default_timezone_set( 'UTC' );

echo "\nThe theme's code (source scan)\n";
$own = [];
$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $theme, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	$path = str_replace( $theme . '/', '', $file->getPathname() );
	if ( preg_match( '~^(vendor|tests|tools|docs|node_modules|\.git)/|/vendor/~', $path ) || ! preg_match( '/\.(php|js)$/', $path ) ) {
		continue;
	}
	$own[ $path ] = (string) file_get_contents( $file->getPathname() );
}
check( 'the theme\'s own PHP and scripts were found', count( $own ) > 80, (string) count( $own ) );
$prompts = [];
$typed   = [];
$written = [];
foreach ( $own as $path => $code ) {
	if ( preg_match( '/(?<![A-Za-z0-9_.$])(?:window\.)?prompt\s*\(/', $code ) ) {
		$prompts[] = $path;
	}
	// A text box that asks for a date: a date-shaped placeholder or pattern.
	if ( preg_match( '/(?:placeholder|pattern)\s*=\s*["\'][^"\']*(?:YYYY|yyyy|MM[-\/]DD|mm[-\/]dd|\\\\d\{4\}-\\\\d\{2\})/', $code ) ) {
		$typed[] = $path;
	}
	// The written form lives in one place: nobody else builds m-d-y themselves.
	if ( 'inc/us-dates.php' !== $path && preg_match( '/[\'"]m-d-y(?: [^\'"]*)?[\'"]/', $code ) ) {
		$written[] = $path;
	}
}
same( 'no pop-up question (prompt) anywhere — so none can ask for a date', [], $prompts );
same( 'no text box asks for a date', [], $typed );
same( 'only inc/us-dates.php writes the month-day-year form', [], $written );
has( 'the QT Scheduler\'s "Day 1 starts on" is still the browser\'s calendar', 'id="fst-start-date" class="fst-startdate-input" type="date"', $own['page-qt-scheduler.php'] );
has( 'My Account sends the picked day as the list gives it (year-first)', 'new_date: newDate', $own['assets/js/customer-date-change.js'] );
has( 'the terms record still stores the year-first local time', "const LOCAL_FORMAT    = 'Y-m-d H:i T';", $own['inc/medication-store/terms-record.php'] );
has( 'functions.php loads the helper', "/inc/us-dates.php'", $own['functions.php'] );
foreach ( [ 'inc/customer/class-fishotel-self-serve-date.php', 'inc/order-fulfillment/class-fishotel-fulfillment.php', 'inc/medication-store/terms-record.php' ] as $path ) {
	has( "$path loads the helper itself (it can be run on its own)", "/us-dates.php'", $own[ $path ] );
}

echo "\n" . ( $failures ? "$failures FAILED" : 'all passed' ) . " ($passes checks)\n";
exit( $failures ? 1 : 0 );
