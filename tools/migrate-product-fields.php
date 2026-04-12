<?php
/**
 * One-time migration: parse product descriptions into custom fields.
 *
 * Run via WP-CLI:
 *   wp eval-file tools/migrate-product-fields.php
 *
 * Safe to run multiple times — skips fields that already have values.
 *
 * @package FisHotel
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "This script must be run via WP-CLI: wp eval-file tools/migrate-product-fields.php\n";
	exit( 1 );
}

if ( ! function_exists( 'wc_get_products' ) ) {
	echo "WooCommerce is not active.\n";
	exit( 1 );
}

$products = wc_get_products( [ 'limit' => -1, 'status' => 'publish' ] );
$migrated = 0;
$skipped  = 0;

echo "Found " . count( $products ) . " published products.\n";

foreach ( $products as $product ) {
	$id   = $product->get_id();
	$name = $product->get_name();
	$desc = wp_strip_all_tags( $product->get_description() );

	if ( empty( $desc ) ) {
		echo "  [{$id}] {$name} — no description, skipping.\n";
		$skipped++;
		continue;
	}

	// Helper: try multiple regex patterns, return first match
	$extract = function( $patterns, $text ) {
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text, $m ) ) {
				$val = trim( $m[1] );
				// Clean up trailing labels that bled into capture
				$val = preg_replace( '/\s+(Scientific|Common|Maximum|Minimum|Reef|Temperament|Foods|Habitat|Fun\s+Facts?).*$/is', '', $val );
				return $val;
			}
		}
		return '';
	};

	// Field map: meta_key => regex patterns to try (in priority order)
	$fields = [
		'_fh_scientific_name' => [
			'/Scientific Name\s*[:\-–—]\s*([^\n]+)/i',
		],
		'_fh_common_names' => [
			'/Common Names?\s*[:\-–—]\s*([^\n]+)/i',
		],
		'_fh_max_length' => [
			'/Maximum?\s*Lengths?\s*[:\-–—]\s*([^\n]+)/i',
			'/Max\.?\s*Lengths?\s*[:\-–—]\s*([^\n]+)/i',
		],
		'_fh_min_tank_size' => [
			'/Minimum\s*Aquarium\s*Sizes?\s*[:\-–—]\s*([^\n]+)/i',
			'/Min\.?\s*Tank\s*Sizes?\s*[:\-–—]\s*([^\n]+)/i',
		],
		'_fh_temperament' => [
			'/Temperament\s*[:\-–—]\s*([^\n]+)/i',
		],
		'_fh_region' => [
			'/Region\s*[:\-–—]\s*([^\n]+)/i',
		],
		'_fh_foods_feeding' => [
			'/Foods?\s+and\s+Feeding\s*(?:Habits?)?\s*[:\-–—]\s*([\s\S]+?)(?=(?:Habitat|Habits|Reef|Temperament|Fun\s+Facts?|Description|\z))/i',
			'/Feeding\s*(?:Habits?)?\s*[:\-–—]\s*([\s\S]+?)(?=(?:Habitat|Habits|Reef|\z))/i',
		],
		'_fh_habitat' => [
			'/Habitat\s*(?:and|&)?\s*Behavior\s*[:\-–—]\s*([\s\S]+?)(?=(?:Foods?|Feeding|Reef|Fun\s+Facts?|Description|\z))/i',
			'/Habitat\s*[:\-–—]\s*([\s\S]+?)(?=(?:Foods?|Feeding|Reef|Fun\s+Facts?|\z))/i',
			'/Habits\s*[:\-–—]\s*([\s\S]+?)(?=(?:Foods?|Feeding|Reef|Fun\s+Facts?|\z))/i',
		],
	];

	$updated  = false;
	$field_log = [];

	foreach ( $fields as $key => $patterns ) {
		// Don't overwrite existing values
		if ( get_post_meta( $id, $key, true ) ) {
			continue;
		}

		$value = $extract( $patterns, $desc );
		if ( $value ) {
			update_post_meta( $id, $key, sanitize_textarea_field( $value ) );
			$updated = true;
			$field_log[] = str_replace( '_fh_', '', $key );
		}
	}

	// Reef Safe — special case: parse yes/no/caution from text
	if ( ! get_post_meta( $id, '_fh_reef_safe', true ) ) {
		if ( preg_match( '/Reef[\s\-]?Safe(?:ty)?\s*[:\-–—]\s*([^\n]+)/i', $desc, $m ) ) {
			$val = strtolower( trim( $m[1] ) );
			$reef_safe = 'yes';
			if ( strpos( $val, 'caution' ) !== false ) {
				$reef_safe = 'caution';
			} elseif ( strpos( $val, 'no' ) !== false ) {
				$reef_safe = 'no';
			}
			update_post_meta( $id, '_fh_reef_safe', $reef_safe );
			$updated = true;
			$field_log[] = 'reef_safe=' . $reef_safe;
		}
	}

	if ( $updated ) {
		echo "  [{$id}] {$name} — migrated: " . implode( ', ', $field_log ) . "\n";
		$migrated++;
	} else {
		echo "  [{$id}] {$name} — all fields already set or no matches.\n";
		$skipped++;
	}
}

echo "\nMigration complete: {$migrated} products updated, {$skipped} skipped.\n";
