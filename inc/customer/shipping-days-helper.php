<?php
/**
 * FisHotel — Shipping settings helper.
 *
 * Single source of truth for the operational shipping rules a customer
 * picker must respect:
 *
 *   - fishotel_shipping_days         string[]  allowed weekday names ('Monday','Tuesday','Wednesday')
 *   - fishotel_shipping_min_advance  int       min days from today to the earliest shippable date
 *   - fishotel_shipping_max_ahead    int       max days from today to the latest shippable date
 *   - fishotel_shipping_max_per_day  int       capacity cap per delivery date (0 = unlimited)
 *   - fishotel_shipping_blacklist    string[]  Y-m-d strings of explicitly-blocked dates (holidays etc.)
 *
 * These are stored as WordPress options by the Fishotel Batch Plugin's
 * Delivery settings page (admin.php?page=fishotel-shipping) — see
 * class-woocommerce.php fishotel_get_available_shipping_dates() for the
 * plugin-side reader. Reading them here lets the customer self-serve
 * picker (v1.18.0) line up with the shipping admin's actual
 * configuration — Wendi (#34161) was able to pick a Monday in v1.18.5
 * because the self-serve picker had Mon/Tue/Wed hardcoded while Batch
 * Plugin was set to Tue/Wed/Thu.
 *
 * WHY the keys carry the fishotel_ prefix: earlier builds read un-prefixed
 * keys ('shipping_days', …) that nothing ever writes, so the self-serve
 * picker silently ran on hard-coded defaults and ignored Jeff's blocked
 * dates, per-day cap and weekday configuration.
 *
 * Defaults below match the Batch Plugin's own get_option() fallbacks
 * (Mon/Tue/Wed, 1, 30, 6, []) so both readers agree when nothing is set.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Effective shipping settings. Reads Batch Plugin options with sensible
 * defaults for when nothing is configured.
 *
 * @return array{days:string[],min_advance:int,max_days_ahead:int,max_per_day:int,blocked_dates:string[]}
 */
function fishotel_get_allowed_shipping_settings() {
	$days = get_option( 'fishotel_shipping_days', [ 'Monday', 'Tuesday', 'Wednesday' ] );
	if ( ! is_array( $days ) ) {
		$days = [ 'Monday', 'Tuesday', 'Wednesday' ];
	}
	// Normalize to ucfirst weekday names — Batch Plugin form posts uppercase
	// first letter; defensive in case anything ever drifts.
	$days = array_values( array_filter( array_map( static function ( $d ) {
		return ucfirst( strtolower( (string) $d ) );
	}, $days ) ) );

	$blocked = get_option( 'fishotel_shipping_blacklist', [] );
	if ( ! is_array( $blocked ) ) {
		$blocked = [];
	}
	$blocked = array_values( array_filter( array_map( static function ( $d ) {
		$d = (string) $d;
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
	}, $blocked ) ) );

	return [
		'days'           => $days,
		'min_advance'    => max( 0, (int) get_option( 'fishotel_shipping_min_advance', 1 ) ),
		'max_days_ahead' => max( 1, (int) get_option( 'fishotel_shipping_max_ahead', 30 ) ),
		'max_per_day'    => max( 0, (int) get_option( 'fishotel_shipping_max_per_day', 6 ) ),
		'blocked_dates'  => $blocked,
	];
}

/**
 * True when $date_ymd is a legitimate delivery date per Batch Plugin
 * shipping rules. Pure rules check — does NOT consider capacity (which
 * the self-serve picker checks separately via count_orders_for_date so
 * the "is_current" exemption can still apply).
 *
 * @param string $date_ymd Y-m-d.
 * @return array{0:bool,1:string} [valid, reason-if-not]
 */
function fishotel_is_valid_delivery_date( $date_ymd ) {
	$settings = fishotel_get_allowed_shipping_settings();

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date_ymd ) ) {
		return [ false, __( 'Invalid date format.', 'fishotel' ) ];
	}
	// Site timezone, not UTC: WP pins PHP's default zone to UTC, so a bare
	// strtotime('today') flipped "today" at 7pm Central and disagreed with
	// the Batch Plugin's America/Chicago checkout picker by a day near the
	// edges. Local midnights on both sides keep the day arithmetic exact.
	$tz   = wp_timezone();
	$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $date_ymd, $tz );
	if ( ! $date || $date->format( 'Y-m-d' ) !== $date_ymd ) {
		return [ false, __( 'Invalid date.', 'fishotel' ) ];
	}
	$today    = new DateTimeImmutable( 'today', $tz );
	// round(), not floor(): a DST change between the two local midnights
	// makes the gap 23h/25h, which floor() would miscount by a day.
	$days_out = (int) round( ( $date->getTimestamp() - $today->getTimestamp() ) / DAY_IN_SECONDS );

	if ( $days_out < $settings['min_advance'] ) {
		return [ false, sprintf(
			/* translators: %d minimum advance days */
			_n( 'Earliest delivery is %d day from today.', 'Earliest delivery is %d days from today.', $settings['min_advance'], 'fishotel' ),
			$settings['min_advance']
		) ];
	}
	if ( $days_out > $settings['max_days_ahead'] ) {
		return [ false, sprintf(
			/* translators: %d max days ahead */
			__( 'We schedule deliveries up to %d days ahead.', 'fishotel' ),
			$settings['max_days_ahead']
		) ];
	}
	if ( in_array( $date_ymd, $settings['blocked_dates'], true ) ) {
		return [ false, __( 'That date is unavailable.', 'fishotel' ) ];
	}
	$weekday = $date->format( 'l' );
	if ( ! in_array( $weekday, $settings['days'], true ) ) {
		return [ false, sprintf(
			/* translators: %s comma-joined weekday names */
			__( 'FisHotel ships %s only.', 'fishotel' ),
			fishotel_format_day_list( $settings['days'] )
		) ];
	}
	return [ true, '' ];
}

/**
 * Pretty-print a list of weekday names for messaging.
 *  - 1: "Tuesday"
 *  - 2: "Tuesday and Wednesday"
 *  - 3+: "Tuesday, Wednesday, and Thursday"
 *
 * @param string[] $days
 */
function fishotel_format_day_list( array $days ) {
	$days = array_values( array_filter( $days ) );
	$n    = count( $days );
	if ( 0 === $n ) {
		return '';
	}
	if ( 1 === $n ) {
		return $days[0];
	}
	if ( 2 === $n ) {
		return sprintf(
			/* translators: 1: first day, 2: second day */
			__( '%1$s and %2$s', 'fishotel' ),
			$days[0],
			$days[1]
		);
	}
	$last = array_pop( $days );
	return sprintf(
		/* translators: 1: comma-joined day list, 2: final day — Oxford-comma join */
		__( '%1$s, and %2$s', 'fishotel' ),
		implode( ', ', $days ),
		$last
	);
}
