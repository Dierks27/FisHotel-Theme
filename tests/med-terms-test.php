<?php
/**
 * Stub-free test for the pure medication-terms record builder in
 * inc/medication-store/terms-record.php — no WordPress, no database.
 *
 *   php tests/med-terms-test.php
 *
 * Covers: build_record() field set, UA/IP truncation, a stable terms_hash
 * across whitespace/tag changes, products captured including EA-fulfilled
 * lines, extra-ack dedupe by hash + the required-set computation from a
 * fake cart, validation rejecting a missing / unchecked extra ack and a
 * forged index, the manual record shape, legacy fallback detection and
 * the display formatter (local date, version).
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

// terms-record.php is pure PHP, but keep the same guard the other tests
// use so it also loads when a future edit adds the ABSPATH check.
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/inc/medication-store/terms-record.php';

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

$body  = "<p>All medications sold by FisHotel are intended <strong>exclusively</strong> for ornamental aquarium fish.</p>\n<p>Not for human consumption.</p>";
$label = 'I acknowledge that the medications in this order are for ornamental aquarium use only.';
$tz    = new DateTimeZone( 'America/New_York' );
$ts    = 1792000000; // 2026-10-14 17:46:40 UTC

// A fake cart: two EA-fulfilled meds (one with an extra ack), one self-stocked
// med with the SAME extra ack as the first, and one with a different ack.
$cart = [
	[ 'id' => 33458, 'name' => 'Chloroquine Flake',  'qty' => 2, 'extra_ack' => "I understand chloroquine is <b>toxic</b> to inverts.\n", 'fulfillment' => 'ea' ],
	[ 'id' => 33385, 'name' => 'Praziquantel Powder', 'qty' => 1, 'extra_ack' => '',                                                        'fulfillment' => 'ea' ],
	[ 'id' => 33665, 'name' => 'Chloroquine Pellet',  'qty' => 1, 'extra_ack' => '  I understand   chloroquine is toxic to inverts. ',     'fulfillment' => 'self' ],
	[ 'id' => 33380, 'name' => 'Copper Power',        'qty' => 3, 'extra_ack' => 'Copper kills invertebrates and corals.',                   'fulfillment' => 'amazon' ],
];

echo "build_record()\n";
$record = FisHotel_Med_Terms::build_record( [
	'timestamp'     => $ts,
	'timezone'      => $tz,
	'ip'            => '203.0.113.9',
	'user_agent'    => 'Mozilla/5.0 (test)',
	'label'         => '<span>' . $label . '</span>',
	'terms_version' => '2026-10',
	'terms_body'    => $body,
	'products'      => $cart,
	'extra_acks'    => [ 'I understand chloroquine is toxic to inverts.' ],
	'source'        => 'checkout',
] );

$expected_keys = [ 'accepted', 'accepted_at_utc', 'accepted_at_local', 'accepted_at', 'ip', 'user_agent', 'label', 'terms_version', 'terms_hash', 'products', 'extra_acks', 'source' ];
check( 'field set', array_keys( $record ) === $expected_keys, implode( ',', array_keys( $record ) ) );
check( 'accepted is true',             $record['accepted'] === true );
check( 'accepted_at_utc is gmdate(c)', $record['accepted_at_utc'] === gmdate( 'c', $ts ), $record['accepted_at_utc'] );
check( 'accepted_at_local in site tz', $record['accepted_at_local'] === '2026-10-14 13:46 EDT', $record['accepted_at_local'] );
check( 'accepted_at timestamp kept',   $record['accepted_at'] === $ts );
check( 'ip stored',                    $record['ip'] === '203.0.113.9' );
check( 'user agent stored',            $record['user_agent'] === 'Mozilla/5.0 (test)' );
check( 'label stripped of tags',       $record['label'] === $label, $record['label'] );
check( 'terms version',                $record['terms_version'] === '2026-10' );
check( 'terms hash is md5',            preg_match( '/^[a-f0-9]{32}$/', $record['terms_hash'] ) === 1 );
check( 'source checkout',              $record['source'] === 'checkout' );
check( 'extra acks captured',          $record['extra_acks'] === [ 'I understand chloroquine is toxic to inverts.' ] );

echo "\nproducts incl. EA-fulfilled\n";
check( 'four products captured',  count( $record['products'] ) === 4 );
check( 'only id/name/qty stored', array_keys( $record['products'][0] ) === [ 'id', 'name', 'qty' ], json_encode( $record['products'][0] ) );
check( 'EA line present',         $record['products'][1] === [ 'id' => 33385, 'name' => 'Praziquantel Powder', 'qty' => 1 ] );
check( 'amazon line present',     $record['products'][3] === [ 'id' => 33380, 'name' => 'Copper Power', 'qty' => 3 ] );
check( 'qty cast to int',         is_int( $record['products'][0]['qty'] ) && $record['products'][0]['qty'] === 2 );
check( 'junk rows dropped',       FisHotel_Med_Terms::normalise_products( [ 'x', [ 'qty' => 1 ], null ] ) === [] );

echo "\ntruncation\n";
$long_ua = str_repeat( 'a', 300 );
$long_ip = str_repeat( '1', 60 );
$t = FisHotel_Med_Terms::build_record( [ 'timestamp' => $ts, 'ip' => $long_ip, 'user_agent' => $long_ua ] );
check( 'UA truncated to 255',           strlen( $t['user_agent'] ) === 255 );
check( 'IP bounded to 45',              strlen( $t['ip'] ) === 45 );
check( 'IPv6 fits untouched',           FisHotel_Med_Terms::build_record( [ 'ip' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334' ] )['ip'] === '2001:0db8:85a3:0000:0000:8a2e:0370:7334' );
check( 'UTF-8 never split',             FisHotel_Med_Terms::truncate( str_repeat( 'é', 10 ), 5 ) === 'éé' );
check( 'control chars stripped from UA', FisHotel_Med_Terms::build_record( [ 'user_agent' => "Mozilla\r\n/5.0\x00" ] )['user_agent'] === 'Mozilla /5.0' );
check( 'missing inputs default',        $t['label'] === '' && $t['ip'] !== '' && $t['products'] === [] && $t['extra_acks'] === [] && $t['source'] === 'checkout' );
check( 'blank version falls back',      $t['terms_version'] === FisHotel_Med_Terms::DEFAULT_VERSION );
check( 'version sanitised',             FisHotel_Med_Terms::sanitize_version( ' 2026-11 <b>x</b> ' ) === '2026-11x', FisHotel_Med_Terms::sanitize_version( ' 2026-11 <b>x</b> ' ) );
check( 'timezone string accepted',      FisHotel_Med_Terms::build_record( [ 'timestamp' => $ts, 'timezone' => 'Europe/Berlin' ] )['accepted_at_local'] === '2026-10-14 19:46 CEST' );
check( 'bad timezone falls back to UTC', FisHotel_Med_Terms::build_record( [ 'timestamp' => $ts, 'timezone' => 'Nowhere/Land' ] )['accepted_at_local'] === '2026-10-14 17:46 UTC' );

echo "\nterms_hash stability\n";
$h1 = FisHotel_Med_Terms::terms_hash( $body, $label );
$h2 = FisHotel_Med_Terms::terms_hash( "All medications sold by FisHotel are intended exclusively for ornamental   aquarium fish.\n\n\tNot for human consumption.", "  $label\n" );
$h3 = FisHotel_Med_Terms::terms_hash( '<div><em>All</em> medications sold by FisHotel are intended exclusively for ornamental aquarium fish. Not for human consumption.</div>', $label );
$h4 = FisHotel_Med_Terms::terms_hash( $body, 'I acknowledge something else entirely.' );
$h5 = FisHotel_Med_Terms::terms_hash( $body . ' Also not for food fish.', $label );
check( 'whitespace changes hash the same', $h1 === $h2 );
check( 'tag changes hash the same',        $h1 === $h3 );
check( 'label change changes the hash',    $h1 !== $h4 );
check( 'wording change changes the hash',  $h1 !== $h5 );
check( 'record carries that hash',         $record['terms_hash'] === $h1 );
check( 'nbsp + entities normalised',       FisHotel_Med_Terms::normalise_text( "a&nbsp;b\xC2\xA0c &amp; d" ) === 'a b c & d', FisHotel_Med_Terms::normalise_text( "a&nbsp;b\xC2\xA0c &amp; d" ) );

echo "\nextra-ack required set\n";
$required = FisHotel_Med_Terms::extra_ack_required_set( $cart );
$chloro_hash = md5( 'I understand chloroquine is toxic to inverts.' );
$copper_hash = md5( 'Copper kills invertebrates and corals.' );
check( 'two distinct texts',               count( $required ) === 2, json_encode( array_keys( $required ) ) );
check( 'keyed by md5 of normalised text',  isset( $required[ $chloro_hash ] ) && isset( $required[ $copper_hash ] ) );
check( 'first-seen order kept',            array_keys( $required ) === [ $chloro_hash, $copper_hash ] );
check( 'tags stripped from text',          $required[ $chloro_hash ]['text'] === 'I understand chloroquine is toxic to inverts.' );
check( 'both chloroquine products listed', $required[ $chloro_hash ]['products'] === [ 'Chloroquine Flake', 'Chloroquine Pellet' ] );
check( 'copper product listed once',       $required[ $copper_hash ]['products'] === [ 'Copper Power' ] );
check( 'label prefixed by products',       FisHotel_Med_Terms::extra_ack_label( $required[ $chloro_hash ] ) === 'Chloroquine Flake, Chloroquine Pellet: I understand chloroquine is toxic to inverts.' );
check( 'no acks → empty set',              FisHotel_Med_Terms::extra_ack_required_set( [ [ 'name' => 'Prazi', 'extra_ack' => '   ' ], [ 'name' => 'X' ] ] ) === [] );

echo "\nextra-ack validation\n";
$ok_post = [ $chloro_hash => '1', $copper_hash => '1' ];
check( 'all checked → valid',                 FisHotel_Med_Terms::validate_extra_acks( $required, $ok_post ) === [] );
check( 'missing one → that hash',             FisHotel_Med_Terms::validate_extra_acks( $required, [ $chloro_hash => '1' ] ) === [ $copper_hash ] );
check( 'unchecked (value 0) → rejected',      FisHotel_Med_Terms::validate_extra_acks( $required, [ $chloro_hash => '1', $copper_hash => '0' ] ) === [ $copper_hash ] );
check( 'empty value → rejected',              FisHotel_Med_Terms::validate_extra_acks( $required, [ $chloro_hash => '1', $copper_hash => '' ] ) === [ $copper_hash ] );
check( 'forged index does not satisfy',       FisHotel_Med_Terms::validate_extra_acks( $required, [ '0' => '1', '1' => '1', md5( 'other' ) => '1' ] ) === [ $chloro_hash, $copper_hash ] );
check( 'forged extra key ignored when valid', FisHotel_Med_Terms::validate_extra_acks( $required, $ok_post + [ 'evil' => '1' ] ) === [] );
check( 'nothing posted → all missing',        FisHotel_Med_Terms::validate_extra_acks( $required, null ) === [ $chloro_hash, $copper_hash ] );
check( 'string posted → all missing',         FisHotel_Med_Terms::validate_extra_acks( $required, '1' ) === [ $chloro_hash, $copper_hash ] );
check( 'no required set → always valid',      FisHotel_Med_Terms::validate_extra_acks( [], [ 'x' => '1' ] ) === [] );
check( 'extra_acks deduped in record',        FisHotel_Med_Terms::normalise_extra_acks( [ 'A <b>b</b>', ' A   b ', '', [ 'text' => 'C' ] ] ) === [ 'A b', 'C' ] );

echo "\nmanual record\n";
$manual = FisHotel_Med_Terms::build_manual_record( [
	'timestamp'       => $ts,
	'timezone'        => $tz,
	'ip'              => '198.51.100.1',   // must be dropped: admin's IP, not the customer's
	'user_agent'      => 'AdminBrowser',   // same
	'label'           => $label,
	'terms_version'   => '2026-10',
	'terms_body'      => $body,
	'products'        => [ [ 'id' => 33385, 'name' => 'Praziquantel Powder', 'qty' => 1 ] ],
	'admin_user_id'   => 7,
	'admin_user_name' => 'Jeff',
	'note'            => "Confirmed by phone\n10/17",
	'source'          => 'checkout',       // caller cannot override
] );
check( 'source forced to manual',  $manual['source'] === 'manual' );
check( 'admin id + name stored',   $manual['admin_user_id'] === 7 && $manual['admin_user_name'] === 'Jeff' );
check( 'note is one line',         $manual['note'] === 'Confirmed by phone 10/17', $manual['note'] );
check( 'no ip / ua on manual',     $manual['ip'] === '' && $manual['user_agent'] === '' );
check( 'manual keeps base fields', $manual['accepted'] === true && $manual['terms_hash'] === $h1 && $manual['accepted_at_local'] === '2026-10-14 13:46 EDT' );
check( 'manual note bounded',      strlen( FisHotel_Med_Terms::build_manual_record( [ 'note' => str_repeat( 'n', 500 ) ] )['note'] ) === 200 );
check( 'manual is a record',       FisHotel_Med_Terms::is_record( $manual ) );

echo "\nstate detection\n";
check( 'full record → recorded',            FisHotel_Med_Terms::detect_state( $record, '1', true ) === 'recorded' );
check( 'legacy flag only → legacy',         FisHotel_Med_Terms::detect_state( '', '1', true ) === 'legacy' );
check( 'legacy flag, no med lines → legacy', FisHotel_Med_Terms::detect_state( null, '1', false ) === 'legacy' );
check( 'meds, nothing stored → missing',    FisHotel_Med_Terms::detect_state( '', '', true ) === 'missing' );
check( 'no meds, nothing stored → none',    FisHotel_Med_Terms::detect_state( '', '', false ) === 'none' );
check( 'malformed record ignored',          FisHotel_Med_Terms::detect_state( [ 'accepted' => true ], '', true ) === 'missing' );
check( 'string meta is not a record',       ! FisHotel_Med_Terms::is_record( 'a:1:{s:8:"accepted";b:1;}' ) );

echo "\ndisplay formatter\n";
check( 'email line',        FisHotel_Med_Terms::format_line( $record ) === 'Medication terms accepted 2026-10-14 13:46 EDT · terms v2026-10', FisHotel_Med_Terms::format_line( $record ) );
check( 'pdf line with ip',  FisHotel_Med_Terms::format_line( $record, true ) === 'Medication terms accepted 2026-10-14 13:46 EDT · IP 203.0.113.9 · v2026-10', FisHotel_Med_Terms::format_line( $record, true ) );
check( 'manual line marks it', FisHotel_Med_Terms::format_line( $manual ) === 'Medication terms accepted 2026-10-14 13:46 EDT · terms v2026-10 (recorded manually by Jeff)', FisHotel_Med_Terms::format_line( $manual ) );
check( 'manual pdf line, no ip', FisHotel_Med_Terms::format_line( $manual, true ) === 'Medication terms accepted 2026-10-14 13:46 EDT · IP n/a · v2026-10 (recorded manually by Jeff)' );
check( 'legacy timestamp formatter', FisHotel_Med_Terms::local_time_string( $ts, $tz ) === '2026-10-14 13:46 EDT' );

echo "\n" . ( $failures ? "$failures FAILED\n" : "all passed\n" );
exit( $failures ? 1 : 0 );
