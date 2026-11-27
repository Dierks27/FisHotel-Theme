<?php
/**
 * FisHotel_Theme_US_Dates — how the theme shows a date to a person, and how
 * it reads one a person typed (1.29.1).
 *
 * In the United States a date is written month-day-year, so everything a
 * person READS as digits in wp-admin, an order note, a staff e-mail or the
 * medication terms line is MM-DD-YY (11-27-26). What is STORED or SENT never
 * changes: order meta, the terms record, form values, AJAX fields and sort
 * keys stay YYYY-MM-DD.
 *
 *   FisHotel_Theme_US_Dates::show( '2026-11-27' )                   '11-27-26'
 *   FisHotel_Theme_US_Dates::show_datetime( '2026-10-17 14:03 EDT' ) '10-17-26 14:03 EDT'
 *   FisHotel_Theme_US_Dates::parse( '11/27/26' )                    '2026-11-27'
 *   FisHotel_Theme_US_Dates::parse( '02-30-26' )                    null  (refused)
 *   FisHotel_Theme_US_Dates::refusal( '02-30-26' )                  the sentence to show
 *
 * This is the one place the written form lives (write() below). Dates
 * already written out in words ("Friday, November 27") are not this class's
 * business. The theme has no script that shows a date as digits, so there is
 * no JavaScript twin; the Batch Manager and Misc plugins carry the same
 * helper under their own names.
 *
 * A date is a calendar day. Nothing here builds a timestamp, reads the clock
 * or knows a time zone — the digits are moved around as text, so a day can
 * never slip to the one before or after. Pure: no WordPress, no database.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

final class FisHotel_Theme_US_Dates {

	/** What refusal() shows as the way to type a date. */
	const EXAMPLE = '11-27-26';

	/**
	 * A stored YYYY-MM-DD as a person reads it: MM-DD-YY.
	 *
	 * Empty gives ''. Anything that is not a real stored date (free text, an
	 * impossible date) comes back as it is, so nothing is hidden or guessed.
	 *
	 * @param mixed $ymd
	 */
	public static function show( $ymd ) {
		$text  = self::text( $ymd );
		$parts = self::ymd_parts( $text );
		return $parts ? self::write( $parts[0], $parts[1], $parts[2] ) : $text;
	}

	/**
	 * A stored date-time ('2026-11-27 14:03:22', '2026-11-27T14:03', with or
	 * without a zone after it): the date part as show() writes it, the time part
	 * exactly as stored. Anything else comes back as it is.
	 *
	 * @param mixed $stored
	 */
	public static function show_datetime( $stored ) {
		$text = self::text( $stored );
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})(?:[ T](\S.*))?$/', $text, $m ) ) {
			return $text;
		}
		$parts = self::ymd_parts( $m[1] );
		if ( ! $parts ) {
			return $text;
		}
		$date = self::write( $parts[0], $parts[1], $parts[2] );
		return isset( $m[2] ) && '' !== $m[2] ? $date . ' ' . $m[2] : $date;
	}

	/**
	 * Text a person typed → the stored YYYY-MM-DD.
	 *
	 * Reads MM-DD-YY, MM/DD/YY, MM-DD-YYYY, M/D/YY and YYYY-MM-DD. The month is
	 * always first unless the year is; a two-digit year means 20YY.
	 *
	 * @param mixed $typed
	 * @return string|null '' when nothing was typed; null when it is not a real
	 *                     date (02-30-26, month 13, free text) — never changed
	 *                     into a nearby date. refusal() has the sentence.
	 */
	public static function parse( $typed ) {
		$text = self::text( $typed );
		if ( '' === $text ) {
			return '';
		}
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m ) ) {
			$year  = (int) $m[1];
			$month = (int) $m[2];
			$day   = (int) $m[3];
		} elseif ( preg_match( '/^(\d{1,2})([-\/])(\d{1,2})\2(\d{2}|\d{4})$/', $text, $m ) ) {
			$month = (int) $m[1];
			$day   = (int) $m[3];
			$year  = 2 === strlen( $m[4] ) ? 2000 + (int) $m[4] : (int) $m[4];
		} else {
			return null;
		}
		if ( ! self::is_real( $year, $month, $day ) ) {
			return null;
		}
		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * The one sentence to show when parse() refuses what was typed ('' when it
	 * reads fine or nothing was typed). Plain text — escape it where it is printed.
	 *
	 * @param mixed $typed
	 */
	public static function refusal( $typed ) {
		if ( null !== self::parse( $typed ) ) {
			return '';
		}
		return '"' . self::text( $typed ) . '" is not a real date — type it as month-day-year, like ' . self::EXAMPLE . '.';
	}

	/**
	 * Whether a value is a stored date: exactly YYYY-MM-DD and a real day.
	 *
	 * @param mixed $value
	 */
	public static function is_ymd( $value ) {
		return is_string( $value ) && null !== self::ymd_parts( $value );
	}

	/* ───────────── inside ───────────── */

	/**
	 * THE written form. Change it here and every screen, note and e-mail of
	 * the theme follows. A year outside 2000–2099 keeps its four digits, so "99"
	 * always means 2099 and parse() reads back exactly what this wrote.
	 */
	private static function write( $year, $month, $day ) {
		$yy = ( $year >= 2000 && $year <= 2099 ) ? sprintf( '%02d', $year - 2000 ) : sprintf( '%04d', $year );
		return sprintf( '%02d-%02d-%s', $month, $day, $yy );
	}

	/** [ year, month, day ] of a strict, real YYYY-MM-DD, or null. */
	private static function ymd_parts( $text ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m ) ) {
			return null;
		}
		$year  = (int) $m[1];
		$month = (int) $m[2];
		$day   = (int) $m[3];
		return self::is_real( $year, $month, $day ) ? [ $year, $month, $day ] : null;
	}

	/** A day that exists on the calendar (leap years by the usual rule). */
	private static function is_real( $year, $month, $day ) {
		return $year >= 1 && $month >= 1 && $month <= 12 && $day >= 1 && checkdate( $month, $day, $year );
	}

	/** Scalars as trimmed text; anything else as ''. */
	private static function text( $value ) {
		return ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) ? trim( (string) $value ) : '';
	}
}
