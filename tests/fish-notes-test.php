<?php
/**
 * Test for the private staff Notes (theme v1.28.0):
 *  - inc/fish-notes.php: which fish of the one-time list still have a note,
 *    the notice wording, the products-list filter;
 *  - the product page's certificate never prints a note
 *    (fishotel_render_purchase_badge() in inc/template-functions.php);
 *  - FisHotel_Hotel_Data::get_all() (the storefront's reader) leaves Notes out.
 * No WordPress, no database. Invented data.
 *
 *   php tests/fish-notes-test.php
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
function add_action() {}
function add_filter() {}
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function get_the_terms( $id, $tax ) { return [ (object) [ 'slug' => 'quarantined-fish' ] ]; }
function wp_list_pluck( $list, $field ) { return array_map( function ( $o ) use ( $field ) { return $o->$field; }, $list ); }
function is_wp_error( $x ) { return false; }
function get_post_meta( $id, $key, $single = false ) {
	$meta = [ '_fh_notes' => 'Ate pellets day 3 — STAFF ONLY', '_fh_region' => 'Red Sea', '_fh_scientific_name' => 'Testus' ];
	return $meta[ $key ] ?? '';
}
class FisHotel_Admin_Settings { public static function get( $k ) { return 'line for ' . $k; } }

require_once dirname( __DIR__ ) . '/inc/fish-notes.php';
require_once dirname( __DIR__ ) . '/inc/template-functions.php';
require_once dirname( __DIR__ ) . '/inc/hotel-data.php';

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

$N = 'FisHotel_Fish_Notes';

echo "still_noted()\n";
check( 'keeps snapshot fish that still have a note', [ 11, 13 ] === $N::still_noted( [ 11, 12, 13 ], [ 11 => 'x', 12 => '  ', 13 => 'y', 14 => 'z' ] ) );
check( 'a fish not in the snapshot never counts', [] === $N::still_noted( [ 11 ], [ 14 => 'z' ] ) );
check( 'duplicates and zero ids dropped', [ 11 ] === $N::still_noted( [ 11, 11, 0 ], [ 11 => 'x', 0 => 'x' ] ) );

echo "notice_text()\n";
check( 'plural', '3 fish have Notes that the product page showed to customers until now' === $N::notice_text( 3 ) );
check( 'singular', '1 fish has Notes that the product page showed to customers until now' === $N::notice_text( 1 ) );

echo "list_query_vars()\n";
$q = $N::list_query_vars( [ 11, '13', 0 ] );
check( 'only the snapshot fish', [ 11, 13 ] === $q['post__in'] );
check( 'only those with a note left', '_fh_notes' === $q['meta_query'][0]['key'] && '!=' === $q['meta_query'][0]['compare'] && '' === $q['meta_query'][0]['value'] );
check( 'empty snapshot shows nothing (not every product)', [ 0 ] === $N::list_query_vars( [] )['post__in'] );

echo "certificate\n";
ob_start();
fishotel_render_purchase_badge( 77, [ 'region' => 'Red Sea', 'hotel_notes' => 'Ate pellets day 3 — STAFF ONLY' ] );
$html = ob_get_clean();
check( 'the certificate prints', false !== strpos( $html, 'Quarantine Complete' ), substr( $html, 0, 120 ) );
check( 'region still printed', false !== strpos( $html, 'Region: Red Sea' ) );
check( 'a note passed in is NOT printed', false === strpos( $html, 'STAFF ONLY' ) && false === strpos( $html, 'fh-qt-cert__notes' ) );

echo "get_all()\n";
$all = FisHotel_Hotel_Data::get_all( 77 );
check( 'facts are there', 'Testus' === $all['scientific_name'] && 'Red Sea' === $all['region'] );
check( 'notes are not', ! array_key_exists( 'notes', $all ) );
check( 'single-product.php no longer passes notes to the certificate', false === strpos( file_get_contents( dirname( __DIR__ ) . '/woocommerce/single-product.php' ), "hotel_notes" ) );

echo "\n" . ( $failures ? "$failures FAILED" : 'all passed' ) . "\n";
exit( $failures ? 1 : 0 );
