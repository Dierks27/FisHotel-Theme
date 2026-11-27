<?php
/**
 * FisHotel_Theme_US_Dates — the one place the theme writes a date for a
 * person to read (MM-DD-YY) and reads one a person typed. Stub-free: no
 * WordPress, no database.
 *
 *   php tests/us-dates-test.php
 *
 * Covers the helper only: showing a stored date, date-times (the time part
 * stays), reading typed text, two-digit years, impossible dates, empty, the
 * minutes around midnight, the two days the clocks change, and that the
 * server's time zone can never move a date to the day before or after.
 * Where the theme uses it is in tests/us-dates-screens-test.php.
 *
 * All dates here are invented.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

require_once dirname( __DIR__ ) . '/inc/us-dates.php';

$passes   = 0;
$failures = 0;
function check( $label, $cond, $detail = '' ) {
	global $passes, $failures;
	if ( $cond ) {
		$passes++;
		echo "  ok   $label\n";
	} else {
		$failures++;
		echo "  FAIL $label" . ( '' !== $detail ? " — $detail" : '' ) . "\n";
	}
}
function section( $title ) {
	echo "\n$title\n";
}
function same( $label, $want, $got ) {
	check( $label, $want === $got, 'want ' . var_export( $want, true ) . ', got ' . var_export( $got, true ) );
}

/* ───────────── Showing a stored date ───────────── */
section( 'show(): a stored YYYY-MM-DD reads MM-DD-YY' );
same( '2026-11-27 → 11-27-26', '11-27-26', FisHotel_Theme_US_Dates::show( '2026-11-27' ) );
same( 'single-digit month and day keep their zero', '01-05-27', FisHotel_Theme_US_Dates::show( '2027-01-05' ) );
same( 'the year 2000', '02-29-00', FisHotel_Theme_US_Dates::show( '2000-02-29' ) );
same( 'the last day a two-digit year can name', '12-31-99', FisHotel_Theme_US_Dates::show( '2099-12-31' ) );
same( 'white space around it is ignored', '11-27-26', FisHotel_Theme_US_Dates::show( " 2026-11-27\n" ) );

section( 'show(): empty and odd values' );
same( 'empty → empty', '', FisHotel_Theme_US_Dates::show( '' ) );
same( 'null → empty', '', FisHotel_Theme_US_Dates::show( null ) );
same( 'false → empty', '', FisHotel_Theme_US_Dates::show( false ) );
same( 'not a date: shown as it is stored, never guessed', 'soon', FisHotel_Theme_US_Dates::show( 'soon' ) );
same( 'an impossible stored date is shown as it is stored', '2026-02-30', FisHotel_Theme_US_Dates::show( '2026-02-30' ) );
same( 'month 13 is shown as it is stored', '2026-13-01', FisHotel_Theme_US_Dates::show( '2026-13-01' ) );
same( 'a date already in US form is left alone', '11-27-26', FisHotel_Theme_US_Dates::show( '11-27-26' ) );

section( 'show(): a year outside 2000–2099 keeps all four digits' );
same( '1999 is not written as 99', '12-31-1999', FisHotel_Theme_US_Dates::show( '1999-12-31' ) );
same( '2100 is not written as 00', '01-01-2100', FisHotel_Theme_US_Dates::show( '2100-01-01' ) );

/* ───────────── Date-times ───────────── */
section( 'show_datetime(): the date part turns US, the time part stays' );
same( 'seconds kept', '11-27-26 14:03:22', FisHotel_Theme_US_Dates::show_datetime( '2026-11-27 14:03:22' ) );
same( 'minutes only', '11-27-26 14:03', FisHotel_Theme_US_Dates::show_datetime( '2026-11-27 14:03' ) );
same( 'a zone after the time stays', '10-17-26 14:03 EDT', FisHotel_Theme_US_Dates::show_datetime( '2026-10-17 14:03 EDT' ) );
same( 'the T of a form value becomes a space', '11-27-26 09:30', FisHotel_Theme_US_Dates::show_datetime( '2026-11-27T09:30' ) );
same( 'a date alone works too', '11-27-26', FisHotel_Theme_US_Dates::show_datetime( '2026-11-27' ) );
same( 'empty → empty', '', FisHotel_Theme_US_Dates::show_datetime( '' ) );
same( 'null → empty', '', FisHotel_Theme_US_Dates::show_datetime( null ) );
same( 'not a date-time: left as it is', 'never', FisHotel_Theme_US_Dates::show_datetime( 'never' ) );
same( 'an impossible date part: left as it is', '2026-02-30 10:00:00', FisHotel_Theme_US_Dates::show_datetime( '2026-02-30 10:00:00' ) );

section( 'show_datetime(): near midnight the day never moves' );
same( 'one second before midnight', '11-27-26 23:59:59', FisHotel_Theme_US_Dates::show_datetime( '2026-11-27 23:59:59' ) );
same( 'midnight itself', '11-28-26 00:00:00', FisHotel_Theme_US_Dates::show_datetime( '2026-11-28 00:00:00' ) );
same( 'New Year\'s Eve', '12-31-26 23:59:59', FisHotel_Theme_US_Dates::show_datetime( '2026-12-31 23:59:59' ) );
same( 'New Year', '01-01-27 00:00:00', FisHotel_Theme_US_Dates::show_datetime( '2027-01-01 00:00:00' ) );

/* ───────────── Reading typed text ───────────── */
section( 'parse(): the forms a person may type' );
same( 'MM-DD-YY', '2026-11-27', FisHotel_Theme_US_Dates::parse( '11-27-26' ) );
same( 'MM/DD/YY', '2026-11-27', FisHotel_Theme_US_Dates::parse( '11/27/26' ) );
same( 'MM-DD-YYYY', '2026-11-27', FisHotel_Theme_US_Dates::parse( '11-27-2026' ) );
same( 'MM/DD/YYYY', '2026-11-27', FisHotel_Theme_US_Dates::parse( '11/27/2026' ) );
same( 'M/D/YY', '2026-03-05', FisHotel_Theme_US_Dates::parse( '3/5/26' ) );
same( 'M-D-YY', '2026-03-05', FisHotel_Theme_US_Dates::parse( '3-5-26' ) );
same( 'M/D/YYYY', '2026-03-05', FisHotel_Theme_US_Dates::parse( '3/5/2026' ) );
same( 'YYYY-MM-DD still works', '2026-11-27', FisHotel_Theme_US_Dates::parse( '2026-11-27' ) );
same( 'white space around it is ignored', '2026-11-27', FisHotel_Theme_US_Dates::parse( "  11-27-26\t" ) );

section( 'parse(): a two-digit year means 20YY' );
same( '00 → 2000', '2000-01-01', FisHotel_Theme_US_Dates::parse( '01-01-00' ) );
same( '26 → 2026', '2026-01-01', FisHotel_Theme_US_Dates::parse( '01-01-26' ) );
same( '69 → 2069 (not 1969)', '2069-07-04', FisHotel_Theme_US_Dates::parse( '07-04-69' ) );
same( '70 → 2070 (not 1970)', '2070-07-04', FisHotel_Theme_US_Dates::parse( '07-04-70' ) );
same( '99 → 2099', '2099-12-31', FisHotel_Theme_US_Dates::parse( '12-31-99' ) );

section( 'parse(): month comes first, never day' );
same( '02-03-26 is February 3', '2026-02-03', FisHotel_Theme_US_Dates::parse( '02-03-26' ) );
same( '27-11-26 (day first) is refused, not swapped', null, FisHotel_Theme_US_Dates::parse( '27-11-26' ) );

section( 'parse(): empty' );
same( 'empty → empty (no date)', '', FisHotel_Theme_US_Dates::parse( '' ) );
same( 'spaces only → empty', '', FisHotel_Theme_US_Dates::parse( '   ' ) );
same( 'null → empty', '', FisHotel_Theme_US_Dates::parse( null ) );

section( 'parse(): impossible dates are refused, never changed' );
same( '02-30-26', null, FisHotel_Theme_US_Dates::parse( '02-30-26' ) );
same( 'month 13', null, FisHotel_Theme_US_Dates::parse( '13-01-26' ) );
same( 'month 0', null, FisHotel_Theme_US_Dates::parse( '00-10-26' ) );
same( 'day 0', null, FisHotel_Theme_US_Dates::parse( '10-00-26' ) );
same( 'day 32', null, FisHotel_Theme_US_Dates::parse( '01-32-26' ) );
same( 'April 31', null, FisHotel_Theme_US_Dates::parse( '04/31/2026' ) );
same( 'February 29 in a year that has none', null, FisHotel_Theme_US_Dates::parse( '02-29-26' ) );
same( 'February 29 in a leap year is fine', '2028-02-29', FisHotel_Theme_US_Dates::parse( '02-29-28' ) );
same( '2100 is not a leap year', null, FisHotel_Theme_US_Dates::parse( '02-29-2100' ) );
same( '2000 was a leap year', '2000-02-29', FisHotel_Theme_US_Dates::parse( '02-29-2000' ) );
same( 'year-first impossible date', null, FisHotel_Theme_US_Dates::parse( '2026-02-30' ) );
same( 'year-first month 13', null, FisHotel_Theme_US_Dates::parse( '2026-13-01' ) );
same( 'the year 0000', null, FisHotel_Theme_US_Dates::parse( '01-01-0000' ) );

section( 'parse(): text that is not a date is refused' );
foreach ( [ 'soon', '11-27', '11-27-126', '11-27-26 14:00', '11.27.26', 'Nov 27', '2026-11-27T00:00', '1-2-3', '112726', '11--27-26' ] as $junk ) {
	same( "'{$junk}'", null, FisHotel_Theme_US_Dates::parse( $junk ) );
}

section( 'refusal(): one plain sentence' );
same( 'an accepted date has no refusal', '', FisHotel_Theme_US_Dates::refusal( '11-27-26' ) );
same( 'empty has no refusal', '', FisHotel_Theme_US_Dates::refusal( '' ) );
$sentence = FisHotel_Theme_US_Dates::refusal( '02-30-26' );
same( 'the sentence names what was typed and shows the way', '"02-30-26" is not a real date — type it as month-day-year, like 11-27-26.', $sentence );
check( 'no year-first hint in the sentence', false === stripos( $sentence, 'YYYY' ) && false === strpos( $sentence, 'Y-m-d' ) );
same( 'the same sentence for month 13', '"13-01-26" is not a real date — type it as month-day-year, like 11-27-26.', FisHotel_Theme_US_Dates::refusal( '13-01-26' ) );

/* ───────────── is_ymd ───────────── */
section( 'is_ymd(): the stored shape' );
check( 'a real date', FisHotel_Theme_US_Dates::is_ymd( '2026-11-27' ) );
check( 'not without zeros', ! FisHotel_Theme_US_Dates::is_ymd( '2026-1-5' ) );
check( 'not US form', ! FisHotel_Theme_US_Dates::is_ymd( '11-27-26' ) );
check( 'not an impossible date', ! FisHotel_Theme_US_Dates::is_ymd( '2026-02-30' ) );
check( 'not empty', ! FisHotel_Theme_US_Dates::is_ymd( '' ) );
check( 'not an array', ! FisHotel_Theme_US_Dates::is_ymd( [ '2026-11-27' ] ) );

/* ───────────── A date is a calendar day ───────────── */
section( 'A date is a calendar day: no time zone can move it' );
$zones = [ 'UTC', 'America/Chicago', 'America/Los_Angeles', 'Pacific/Honolulu', 'Pacific/Kiritimati', 'Asia/Kolkata', 'Australia/Lord_Howe' ];
// Every day of 2026, plus the days the clocks change (Mar 8 and Nov 1, 2026 in the US).
$days = [];
$day  = new DateTimeImmutable( '2026-01-01', new DateTimeZone( 'UTC' ) );
for ( $i = 0; $i < 365; $i++ ) {
	$days[] = $day->modify( "+{$i} days" )->format( 'Y-m-d' );
}
$before = date_default_timezone_get();
foreach ( $zones as $zone ) {
	date_default_timezone_set( $zone );
	$bad = [];
	foreach ( $days as $ymd ) {
		$us = FisHotel_Theme_US_Dates::show( $ymd );
		if ( substr( $ymd, 5, 2 ) . '-' . substr( $ymd, 8, 2 ) . '-' . substr( $ymd, 2, 2 ) !== $us || FisHotel_Theme_US_Dates::parse( $us ) !== $ymd ) {
			$bad[] = $ymd . '→' . $us;
		}
	}
	check( "all 365 days of 2026 show and read back the same day with the server in {$zone}", [] === $bad, implode( ', ', array_slice( $bad, 0, 5 ) ) );
	same( "{$zone}: the day the clocks go forward", '03-08-26', FisHotel_Theme_US_Dates::show( '2026-03-08' ) );
	same( "{$zone}: the hour that does not exist that night is still that day", '03-08-26 02:30:00', FisHotel_Theme_US_Dates::show_datetime( '2026-03-08 02:30:00' ) );
	same( "{$zone}: the day the clocks go back", '11-01-26', FisHotel_Theme_US_Dates::show( '2026-11-01' ) );
	same( "{$zone}: the hour that happens twice that night is still that day", '11-01-26 01:30:00', FisHotel_Theme_US_Dates::show_datetime( '2026-11-01 01:30:00' ) );
	same( "{$zone}: 11:59 pm stays on its day", '03-07-26 23:59', FisHotel_Theme_US_Dates::show_datetime( '2026-03-07 23:59' ) );
	same( "{$zone}: typed 3/8/26 is March 8", '2026-03-08', FisHotel_Theme_US_Dates::parse( '3/8/26' ) );
	same( "{$zone}: typed 11/1/26 is November 1", '2026-11-01', FisHotel_Theme_US_Dates::parse( '11/1/26' ) );
}
date_default_timezone_set( $before );

section( 'Round trip: what show() writes, parse() reads back' );
foreach ( [ '2000-01-01', '2024-02-29', '2026-11-27', '2099-12-31', '1999-12-31', '2100-01-01', '1987-06-15' ] as $ymd ) {
	same( $ymd, $ymd, FisHotel_Theme_US_Dates::parse( FisHotel_Theme_US_Dates::show( $ymd ) ) );
}

section( 'The helper is pure: no clock, no time zone, no WordPress' );
$src = (string) file_get_contents( dirname( __DIR__ ) . '/inc/us-dates.php' );
// Comments and strings out, so only code is scanned.
$code = '';
foreach ( token_get_all( $src ) as $tok ) {
	if ( is_array( $tok ) && in_array( $tok[0], [ T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML ], true ) ) {
		continue;
	}
	$code .= is_array( $tok ) ? $tok[1] : $tok;
}
foreach ( [ 'strtotime', 'date', 'gmdate', 'mktime', 'time', 'wp_date', 'date_i18n', 'current_time', 'get_option', 'date_default_timezone_set', 'wp_timezone' ] as $banned ) {
	check( "no {$banned}() in the helper", 0 === preg_match( '/(?<![A-Za-z0-9_])' . $banned . '\s*\(/i', $code ) );
}
check( 'no DateTime object in the helper', 0 === preg_match( '/\bDateTime(Immutable|Zone|Interface)?\b/', $code ) );

echo "\n" . ( $failures ? "$failures FAILED" : 'all passed' ) . " ($passes checks)\n";
exit( $failures ? 1 : 0 );
