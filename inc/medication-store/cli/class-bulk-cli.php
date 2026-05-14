<?php
/**
 * Medication store — WP-CLI bulk maintenance commands (Phase 2).
 *
 * Sibling of class-importer-cli.php. Subcommands wired under
 * `wp fishotel-meds`:
 *
 *     wp fishotel-meds flatten-categories   # Strip Medication subcategories, keep parent.
 *     wp fishotel-meds tags-init            # Seed product_tag with the curated label list.
 *
 * Both commands are idempotent and safe to re-run.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Bulk_CLI {

	/** Medications parent term ID — matches the live WP DB. */
	const PARENT_TERM_ID = 76;

	/**
	 * Subcategory term IDs that flatten-categories strips off products.
	 * Term names retained for editorial reference — the IDs are
	 * authoritative.
	 */
	const SUBCATEGORY_TERM_IDS = array(
		468, // Antiparasitic
		469, // Antibacterial
		470, // Antifungal
		471, // Dewormer
		472, // Medicated Food
		473, // Quarantine Essentials
	);

	/**
	 * Curated product_tag list seeded by tags-init.
	 *
	 * Two layers stored in one flat list (WP tags have no parent/child):
	 * casual hobbyist labels first, then technical/clinical. Splitting
	 * them in the editor UI is a future concern — the data stays flat.
	 */
	const SEED_TAGS = array(
		// Common terms (casual)
		'Ich',
		'Velvet',
		'Flukes',
		'Worms',
		'Hydroids',
		'Brooklynella',
		'Uronema',
		'Fungus',
		'Bacterial Infection',
		'Fin Rot',
		'Internal Parasites',
		'External Parasites',
		'Hexamita',
		'Bloat',
		'Camallanus',
		'Ammonia Poisoning',
		'Methemoglobinemia',
		'Dewormer',
		// Technical / clinical
		'Cryptocaryon',
		'Amyloodinium',
		'Monogeneans',
		'Digeneans',
		'Cestodes',
		'Nematodes',
		'Cnidaria',
		'Saprolegnia',
		'Spironucleus',
		'Quinoline',
		'Benzimidazole',
		'Imidazothiazole',
		'Aminoglycoside',
		'Nitrofuran',
		'Phenothiazine',
		'Formalin',
		'Metronidazole',
		'Anthelmintic',
		'Broad-Spectrum',
		'Gram-Positive',
		'Gram-Negative',
	);

	/**
	 * Strip subcategory assignments from every product currently
	 * attached to the Medications parent. The parent assignment and
	 * any non-medication categories (Wallet, Quarantined Fish, etc.)
	 * are left untouched.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fishotel-meds flatten-categories
	 *
	 * @when after_wp_load
	 */
	public function flatten_categories( $args, $assoc_args ) {
		$product_ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array(
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => self::PARENT_TERM_ID,
					'include_children' => false,
				),
			),
		) );

		$flattened = 0;
		$skipped   = 0;

		foreach ( $product_ids as $pid ) {
			$current = wp_get_object_terms( (int) $pid, 'product_cat', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $current ) ) {
				WP_CLI::warning( sprintf( 'Skipped #%d — could not read terms: %s', $pid, $current->get_error_message() ) );
				continue;
			}

			$current_ids = array_map( 'intval', (array) $current );
			$to_remove   = array_values( array_intersect( $current_ids, self::SUBCATEGORY_TERM_IDS ) );

			if ( empty( $to_remove ) ) {
				$skipped++;
				continue;
			}

			$result = wp_remove_object_terms( (int) $pid, $to_remove, 'product_cat' );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf( 'Failed to flatten #%d: %s', $pid, $result->get_error_message() ) );
				continue;
			}

			$flattened++;
		}

		WP_CLI::success( sprintf(
			'Flattened %d products. (%d already had only the parent assigned, skipped.)',
			$flattened,
			$skipped
		) );
	}

	/**
	 * Seed the product_tag taxonomy with the curated label list.
	 *
	 * Uses wp_insert_term, which returns a WP_Error with code
	 * `term_exists` for tags already present. That case is silently
	 * counted as "already existed"; any other WP_Error surfaces as
	 * a warning so spelling collisions don't pass unnoticed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fishotel-meds tags-init
	 *
	 * @when after_wp_load
	 */
	public function tags_init( $args, $assoc_args ) {
		$created = 0;
		$existed = 0;

		foreach ( self::SEED_TAGS as $name ) {
			$result = wp_insert_term( $name, 'product_tag' );

			if ( is_wp_error( $result ) ) {
				if ( $result->get_error_code() === 'term_exists' ) {
					$existed++;
					continue;
				}
				WP_CLI::warning( sprintf( 'Failed to create tag "%s": %s', $name, $result->get_error_message() ) );
				continue;
			}

			$created++;
		}

		WP_CLI::success( sprintf( 'Created %d new tags. %d already existed.', $created, $existed ) );
	}

	/**
	 * Copy `_fishotel_ea_sku` into WooCommerce's standard `_sku` field on
	 * every product variation where `_sku` is empty. One-shot repair for
	 * variations imported before the Phase 3.1 bulk-import fix that now
	 * sets `_sku` directly via WC_Product_Variation::set_sku().
	 *
	 * Idempotent: re-running finds nothing to do once SKUs are filled.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Log the variations that would be backfilled without writing meta.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fishotel-meds backfill-skus
	 *     wp fishotel-meds backfill-skus --dry-run
	 *
	 * @when after_wp_load
	 */
	public function backfill_skus( $args, $assoc_args ) {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$prefix  = $dry_run ? '[DRY RUN] ' : '';

		$variation_ids = get_posts( array(
			'post_type'      => 'product_variation',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		$filled       = 0;
		$already_set  = 0;
		$no_ea_sku    = 0;

		foreach ( $variation_ids as $vid ) {
			$ea_sku = (string) get_post_meta( (int) $vid, '_fishotel_ea_sku', true );
			$wc_sku = (string) get_post_meta( (int) $vid, '_sku', true );

			if ( $wc_sku !== '' ) {
				$already_set++;
				continue;
			}
			if ( $ea_sku === '' ) {
				$no_ea_sku++;
				continue;
			}

			if ( ! $dry_run ) {
				update_post_meta( (int) $vid, '_sku', $ea_sku );
				$parent_id = wp_get_post_parent_id( (int) $vid );
				if ( $parent_id && function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $parent_id );
				}
			}
			$filled++;
		}

		WP_CLI::log( sprintf(
			'%sBackfilled: %d variations | Already had _sku: %d | No EA SKU to copy: %d | Scanned: %d',
			$prefix,
			$filled,
			$already_set,
			$no_ea_sku,
			count( $variation_ids )
		) );
		WP_CLI::success( $dry_run ? 'Dry run complete — no changes persisted.' : 'Backfill complete.' );
	}
}
