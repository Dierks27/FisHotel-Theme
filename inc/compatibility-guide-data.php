<?php
/**
 * Compatibility Guide — server-side data helpers.
 *
 * V1 reads JSON fixtures from /assets/data/. The actual conflict-detection
 * loop runs in the browser (assets/js/compatibility-guide.js); this file
 * exposes a couple of helpers for any server-side consumer (e.g. future
 * REST endpoints, admin reporting) and documents the canonical volume
 * modifier rules in PHP for parity with the JS port.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read a data file from /assets/data/ and decode it. Returns null on failure.
 */
function fishotel_compat_load_data( $name ) {
	$allowed = [ 'categories', 'matrix', 'cirrhilabrus', 'species', 'sample-tanks' ];
	if ( ! in_array( $name, $allowed, true ) ) {
		return null;
	}
	$path = FISHOTEL_THEME_DIR . '/assets/data/' . $name . '.json';
	if ( ! file_exists( $path ) ) {
		return null;
	}
	$raw = file_get_contents( $path );
	if ( $raw === false ) {
		return null;
	}
	$decoded = json_decode( $raw, true );
	return is_array( $decoded ) ? $decoded : null;
}

/**
 * Resolve the public URL for a data file, with filemtime cache-busting.
 */
function fishotel_compat_data_url( $name ) {
	$path = FISHOTEL_THEME_DIR . '/assets/data/' . $name . '.json';
	$url  = FISHOTEL_THEME_URI . '/assets/data/' . $name . '.json';
	if ( file_exists( $path ) ) {
		$url .= '?v=' . filemtime( $path );
	}
	return $url;
}

/**
 * Volume modifier — canonical PHP form, mirroring the JS implementation.
 *
 *   Rule 1: <  75g — 'W' between aggressive families tightens to 'N'
 *   Rule 2: ≥ 250g — 'N' on cross-genus tangs/angels softens to 'W'
 *   Rule 3: ≥ 180g — 'W' on cross-complex aggressive Cirrhilabrus softens to 'C'
 *   Rule 4: <  50g — most non-clownfish/goby pairings tighten one tier
 *
 * Verdict tiers (worst → best): N > 1 > O > W > C.
 */
function fishotel_compat_apply_volume_modifier( $verdict, $cat_a, $cat_b, $volume ) {
	if ( ! $volume || $volume <= 0 ) {
		return $verdict;
	}

	$peaceful_pair_categories = [
		'clownfish', 'cardinalfish', 'gobies_cryptocentrus', 'gobies_elacatinus',
		'royal_gramma', 'firefish', 'blennies_salarias',
	];
	$aggressive_pair_categories = [
		'tangs_acanthurus', 'tangs_zebrasoma',
		'dwarf_angels_centropyge',
		'large_angels_pomacanthus', 'large_angels_holacanthus',
		'triggerfish', 'puffers',
	];
	$tangs_or_angels = [
		'tangs_acanthurus', 'tangs_zebrasoma', 'tangs_ctenochaetus', 'tangs_naso',
		'tangs_paracanthurus',
		'dwarf_angels_centropyge',
		'large_angels_pomacanthus', 'large_angels_holacanthus',
		'genicanthus_angels',
	];

	$tighten_one = [ 'C' => 'W', 'W' => 'O', 'O' => 'N', '1' => 'N', 'N' => 'N' ];

	// Rule 4 — small tank, generally rougher
	if ( $volume < 50 ) {
		$peaceful = in_array( $cat_a, $peaceful_pair_categories, true ) && in_array( $cat_b, $peaceful_pair_categories, true );
		if ( ! $peaceful && isset( $tighten_one[ $verdict ] ) ) {
			$verdict = $tighten_one[ $verdict ];
		}
	}

	// Rule 1 — under 75g, aggressive families that were 'W' become 'N'
	if ( $volume < 75 && $verdict === 'W' ) {
		if ( in_array( $cat_a, $aggressive_pair_categories, true ) && in_array( $cat_b, $aggressive_pair_categories, true ) ) {
			$verdict = 'N';
		}
	}

	// Rule 2 — 250g+ softens cross-genus tang/angel 'N' to 'W'
	if ( $volume >= 250 && $verdict === 'N' && $cat_a !== $cat_b ) {
		if ( in_array( $cat_a, $tangs_or_angels, true ) && in_array( $cat_b, $tangs_or_angels, true ) ) {
			$verdict = 'W';
		}
	}

	return $verdict;
}
