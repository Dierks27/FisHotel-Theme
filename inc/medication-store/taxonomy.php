<?php
/**
 * Medication store — taxonomy bootstrap.
 *
 * Idempotently creates the Medications parent category, its 6 purpose-
 * based subcategories, and the 4 product attributes (form, treats, reef
 * safe, use in) with sample terms. Safe to call repeatedly — every
 * term/attribute is created only when missing, so this also acts as a
 * "repair" for hand-deleted entries.
 *
 * Spec §3 — flake/pellet/powder is captured as the Form attribute, NOT
 * a category, so customers browsing for an antibiotic see flakes,
 * pellets, and powders together.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Taxonomy {

	/** Subcategory slugs (under "Medications"). */
	public static function subcategories() {
		return [
			'antiparasitic'         => 'Antiparasitic',
			'antibacterial'         => 'Antibacterial',
			'antifungal'            => 'Antifungal',
			'dewormer'              => 'Dewormer',
			'medicated-food'        => 'Medicated Food',
			'quarantine-essentials' => 'Quarantine Essentials',
		];
	}

	/** Product attributes (taxonomy slug => [label, terms]). */
	public static function attributes() {
		return [
			'med_form' => [
				'label' => 'Form',
				'terms' => [ 'Powder', 'Liquid', 'Flake', 'Pellet', 'Medicated Food' ],
			],
			'med_treats' => [
				'label' => 'Treats',
				'terms' => [
					'Ich', 'Velvet', 'Brooklynella', 'Uronema', 'Flukes',
					'Internal Worms', 'Bacterial Infection', 'Fin Rot', 'Fungal Infection',
				],
			],
			'med_reef_safe' => [
				'label' => 'Reef Safe',
				'terms' => [ 'Yes', 'No', 'With Caution' ],
			],
			'med_use_in' => [
				'label' => 'Use In',
				'terms' => [ 'Display Tank', 'Quarantine Tank Only', 'Hospital Tank Only' ],
			],
		];
	}

	public static function init() {
		// Categories rely on product_cat being registered (Woo init).
		add_action( 'admin_init', [ __CLASS__, 'ensure_categories' ] );
		add_action( 'admin_init', [ __CLASS__, 'ensure_attributes' ] );
	}

	/** Create the Medications parent + all subcategories if missing. */
	public static function ensure_categories() {
		if ( ! taxonomy_exists( 'product_cat' ) ) return;

		$parent = get_term_by( 'slug', FISHOTEL_MED_PARENT_SLUG, 'product_cat' );
		if ( ! $parent ) {
			$res = wp_insert_term( 'Medications', 'product_cat', [
				'slug'        => FISHOTEL_MED_PARENT_SLUG,
				'description' => 'All medications. Use the Form attribute to filter by powder, liquid, flake, pellet, or medicated food.',
			] );
			if ( is_wp_error( $res ) ) return;
			$parent_id = (int) $res['term_id'];
		} else {
			$parent_id = (int) $parent->term_id;
		}

		foreach ( self::subcategories() as $slug => $name ) {
			if ( get_term_by( 'slug', $slug, 'product_cat' ) ) continue;
			wp_insert_term( $name, 'product_cat', [
				'slug'   => $slug,
				'parent' => $parent_id,
			] );
		}
	}

	/**
	 * Create global WC product attributes + their terms. WC stores
	 * attributes in wp_woocommerce_attribute_taxonomies, then registers
	 * each as its own pa_{slug} taxonomy on init.
	 */
	public static function ensure_attributes() {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) return;

		global $wpdb;

		$existing = wc_get_attribute_taxonomies();
		$existing_slugs = [];
		foreach ( $existing as $a ) {
			$existing_slugs[ $a->attribute_name ] = (int) $a->attribute_id;
		}

		foreach ( self::attributes() as $slug => $info ) {
			if ( ! isset( $existing_slugs[ $slug ] ) ) {
				$inserted = $wpdb->insert(
					$wpdb->prefix . 'woocommerce_attribute_taxonomies',
					[
						'attribute_name'    => $slug,
						'attribute_label'   => $info['label'],
						'attribute_type'    => 'select',
						'attribute_orderby' => 'menu_order',
						'attribute_public'  => 1,
					],
					[ '%s', '%s', '%s', '%s', '%d' ]
				);
				if ( ! $inserted ) continue;

				// Flush WC's cached list of attribute taxonomies so the
				// freshly-inserted row is registered as `pa_{slug}` on
				// the next request and we can seed terms below.
				delete_transient( 'wc_attribute_taxonomies' );
				wp_cache_delete( 'attribute_taxonomies', 'woocommerce-attributes' );

				// Register the taxonomy in-process so wp_insert_term
				// works in the same request.
				$taxonomy = wc_attribute_taxonomy_name( $slug );
				if ( ! taxonomy_exists( $taxonomy ) ) {
					register_taxonomy(
						$taxonomy,
						[ 'product', 'product_variation' ],
						[ 'hierarchical' => false, 'show_ui' => false ]
					);
				}
			}

			$taxonomy = wc_attribute_taxonomy_name( $slug );
			if ( ! taxonomy_exists( $taxonomy ) ) continue;

			foreach ( $info['terms'] as $term ) {
				$term_slug = sanitize_title( $term );
				if ( ! get_term_by( 'slug', $term_slug, $taxonomy ) ) {
					wp_insert_term( $term, $taxonomy, [ 'slug' => $term_slug ] );
				}
			}
		}
	}
}
