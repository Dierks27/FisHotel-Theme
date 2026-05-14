<?php
/**
 * Medication store — WP-CLI bulk importer from EA scrape JSON (Phase 3).
 *
 *     wp fishotel-meds bulk-import --file=<path> [--dry-run] [--update-existing] [--category-foods=ID]
 *
 * Reads a JSON file scraped from EA's wholesale storefront and either
 * creates new draft products or replaces the variation set on existing
 * FisHotel products (matched by `_fishotel_ea_sku` parent meta, then by
 * `_fishotel_ea_url`). Three product shapes are handled by the same
 * code path — the importer is data-driven over the `attrs` keys it
 * finds in each variation, so 1D powders, 2D pellets, and 2D freeze-
 * dried (Cubes/Siftings × bag-size) all flow through the same loop.
 *
 * Attribute strategy:
 *   - Keys prefixed with `attribute_pa_` map to global WC attribute
 *     taxonomies. The taxonomy is created via wc_create_attribute() if
 *     missing; terms are seeded as encountered. Stored on the parent
 *     with is_variation = true so the front-end renders the selector.
 *   - Keys prefixed with just `attribute_` (no pa_) — only `attribute_type`
 *     in this dataset — become per-product CUSTOM attributes. They live
 *     on this product only; values stored verbatim (e.g. "Cubes",
 *     "Siftings").
 *
 * Pricing: per-variation FisHotel price is derived by sorting the
 * product's variations by wholesale ascending, then handing each row
 * to fishotel_calculate_med_price($wholesale, $retail, $i, $count).
 * The pricing fn interpolates the multiplier between 1.75 (smallest) and
 * 1.30 (largest), with EA retail as the price floor.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Bulk_Import_CLI {

	/** Storefront base — same default Phase 1 uses. */
	const EA_BASE = 'https://everythingaquatic.net';

	/** Default upload path Jeff symlinks `_latest.json` into. */
	const DEFAULT_FILE_REL = 'fishotel-ea-data/ea_complete_scrape_latest.json';

	/** Slug fragments that flag a freeze-dried product even without `attribute_type`. */
	const FREEZE_DRIED_SLUG_HINTS = [ 'freeze-dried', 'blackworm', 'tubifex', 'copepod' ];

	/**
	 * Bulk-import medications + freeze-dried foods from a scraped EA JSON file.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Absolute path to the scraped JSON. Defaults to
	 *   wp-content/uploads/fishotel-ea-data/ea_complete_scrape_latest.json.
	 *
	 * [--dry-run]
	 * : Parse + log every action, but persist nothing.
	 *
	 * [--update-existing]
	 * : When a matching FisHotel product is found (by _fishotel_ea_sku
	 *   then _fishotel_ea_url), replace its variation set instead of
	 *   skipping. Post title/content/status/eyebrow/tags stay untouched.
	 *
	 * [--category-foods=<term_id>]
	 * : product_cat term ID to attach freeze-dried products to. When
	 *   omitted, freeze-dried products fall back to the Medications
	 *   parent like everything else.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fishotel-meds bulk-import --file=/var/www/wp-content/uploads/fishotel-ea-data/ea_complete_scrape_2026-05-14.json --update-existing
	 *     wp fishotel-meds bulk-import --dry-run
	 *     wp fishotel-meds bulk-import --category-foods=123
	 *
	 * @when after_wp_load
	 */
	public function bulk_import( $args, $assoc_args ) {
		$path           = $this->resolve_path( $assoc_args['file'] ?? '' );
		$dry_run        = ! empty( $assoc_args['dry-run'] );
		$update_existing = ! empty( $assoc_args['update-existing'] );
		$category_foods = isset( $assoc_args['category-foods'] ) ? (int) $assoc_args['category-foods'] : 0;

		if ( ! is_readable( $path ) ) {
			WP_CLI::error( sprintf( 'JSON file not found or not readable: %s', $path ) );
			return;
		}

		$raw = file_get_contents( $path );
		if ( $raw === false ) {
			WP_CLI::error( sprintf( 'Failed to read %s', $path ) );
			return;
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['products'] ) || ! is_array( $data['products'] ) ) {
			WP_CLI::error( sprintf( 'Malformed JSON — missing top-level "products" array (%s)', json_last_error_msg() ) );
			return;
		}

		// Pre-flight: make sure the Medications parent term exists.
		if ( class_exists( 'FisHotel_Med_Taxonomy' ) ) {
			FisHotel_Med_Taxonomy::ensure_categories();
		}

		$parent_term_id = $this->get_medications_parent_id();
		if ( ! $parent_term_id ) {
			WP_CLI::error( 'Could not resolve the Medications parent category. Run `wp fishotel-meds import` first or create term slug "medications" manually.' );
			return;
		}

		if ( $category_foods > 0 && ! term_exists( $category_foods, 'product_cat' ) ) {
			WP_CLI::error( sprintf( '--category-foods=%d does not match an existing product_cat term.', $category_foods ) );
			return;
		}

		$prefix = $dry_run ? '[DRY RUN] ' : '';
		WP_CLI::log( sprintf(
			'%sBulk-import starting — %d product(s) in JSON, %d total variations declared.',
			$prefix,
			count( $data['products'] ),
			isset( $data['total_variations'] ) ? (int) $data['total_variations'] : 0
		) );

		$summary = [
			'updated'    => 0,
			'created'    => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'variations' => 0,
		];

		foreach ( $data['products'] as $product ) {
			if ( ! is_array( $product ) ) {
				$summary['failed']++;
				WP_CLI::warning( 'Skipped non-object entry in products array.' );
				continue;
			}

			try {
				$result = $this->process_product( $product, [
					'dry_run'         => $dry_run,
					'update_existing' => $update_existing,
					'category_foods'  => $category_foods,
					'parent_term_id'  => $parent_term_id,
				] );

				$summary[ $result['outcome'] ]++;
				$summary['variations'] += (int) ( $result['variations'] ?? 0 );

				if ( ! empty( $result['log'] ) ) {
					WP_CLI::log( $prefix . $result['log'] );
				}
			} catch ( Exception $e ) {
				$summary['failed']++;
				WP_CLI::warning( sprintf(
					'%sFailed: %s — %s',
					$prefix,
					$product['title'] ?? '(untitled)',
					$e->getMessage()
				) );
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( '%sUpdated: %d existing products (with --update-existing)', $prefix, $summary['updated'] ) );
		WP_CLI::log( sprintf( '%sCreated: %d new draft products', $prefix, $summary['created'] ) );
		WP_CLI::log( sprintf( '%sSkipped: %d existing products (without --update-existing)', $prefix, $summary['skipped'] ) );
		WP_CLI::log( sprintf( '%sFailed:  %d', $prefix, $summary['failed'] ) );
		WP_CLI::log( sprintf( '%sTotal variations: %d created/replaced', $prefix, $summary['variations'] ) );

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run complete — no changes persisted.' );
		} else {
			WP_CLI::success( 'Bulk-import complete.' );
		}
	}

	/* ───── per-product flow ───────────────────────────────────────── */

	protected function process_product( $product, $opts ) {
		$title      = isset( $product['title'] ) ? $this->decode_title( $product['title'] ) : '';
		$parent_sku = isset( $product['parent_sku'] ) ? sanitize_text_field( (string) $product['parent_sku'] ) : '';
		$slug_path  = isset( $product['url'] ) ? (string) $product['url'] : '';
		$variations = isset( $product['variations'] ) && is_array( $product['variations'] ) ? $product['variations'] : [];

		if ( $title === '' || empty( $variations ) ) {
			throw new Exception( 'Missing title or variations.' );
		}

		$ea_url = $this->build_ea_url( $slug_path );

		// Match → existing FisHotel product, or 0.
		$existing_id = $this->find_existing( $parent_sku, $ea_url );

		$is_freeze_dried = $this->detect_freeze_dried( $slug_path, $variations );
		$category_term   = ( $is_freeze_dried && $opts['category_foods'] > 0 )
			? $opts['category_foods']
			: $opts['parent_term_id'];

		// Skip path.
		if ( $existing_id && ! $opts['update_existing'] ) {
			return [
				'outcome'    => 'skipped',
				'variations' => 0,
				'log'        => sprintf( 'Skipped: %s — already exists (#%d). Pass --update-existing to refresh variations.', $title, $existing_id ),
			];
		}

		// Update path.
		if ( $existing_id ) {
			$range = $this->price_range_for( $variations );
			if ( ! $opts['dry_run'] ) {
				$this->refresh_product_meta( $existing_id, $parent_sku, $ea_url );
				$this->rebuild_variations( $existing_id, $variations );
			}
			return [
				'outcome'    => 'updated',
				'variations' => count( $variations ),
				'log'        => sprintf(
					'%s (#%d): updated %d variations ($%s → $%s)',
					$title,
					$existing_id,
					count( $variations ),
					number_format( (float) $range['min'], 2 ),
					number_format( (float) $range['max'], 2 )
				),
			];
		}

		// Create path.
		if ( $opts['dry_run'] ) {
			return [
				'outcome'    => 'created',
				'variations' => count( $variations ),
				'log'        => sprintf(
					'%s: would create new draft product with %d variations.',
					$title,
					count( $variations )
				),
			];
		}

		$new_id = $this->create_product( $title, $parent_sku, $ea_url, $category_term );
		$this->rebuild_variations( $new_id, $variations );

		return [
			'outcome'    => 'created',
			'variations' => count( $variations ),
			'log'        => sprintf(
				'%s: created (#%d) with %d variations across %s.',
				$title,
				$new_id,
				count( $variations ),
				$this->describe_attribute_shape( $variations )
			),
		];
	}

	/* ───── create / update helpers ────────────────────────────────── */

	protected function create_product( $title, $parent_sku, $ea_url, $category_term_id ) {
		$post_id = wp_insert_post( [
			'post_title'   => $title,
			'post_status'  => 'draft',
			'post_type'    => 'product',
			'post_content' => '',
			'post_excerpt' => '',
		], true );
		if ( is_wp_error( $post_id ) ) {
			throw new Exception( $post_id->get_error_message() );
		}

		wp_set_object_terms( $post_id, [ 'variable' ], 'product_type' );

		if ( $category_term_id ) {
			wp_set_object_terms( $post_id, [ (int) $category_term_id ], 'product_cat' );
		}

		update_post_meta( $post_id, '_fishotel_fulfillment',  'ea' );
		update_post_meta( $post_id, '_fishotel_med_eyebrow',  '' );
		update_post_meta( $post_id, '_fishotel_ea_sku',       $parent_sku );
		update_post_meta( $post_id, '_fishotel_ea_url',       $ea_url );

		return (int) $post_id;
	}

	/**
	 * Refresh the parent-level meta on an existing product without
	 * touching post fields. Spec: post title/content/status/eyebrow/
	 * tags/gallery/featured-image stay untouched on the update path.
	 * EA SKU + URL are refreshed so subsequent runs match by SKU first.
	 */
	protected function refresh_product_meta( $post_id, $parent_sku, $ea_url ) {
		if ( $parent_sku !== '' ) {
			update_post_meta( $post_id, '_fishotel_ea_sku', $parent_sku );
		}
		if ( $ea_url !== '' ) {
			update_post_meta( $post_id, '_fishotel_ea_url', $ea_url );
		}
		// Ensure fulfillment mode is `ea` so pricing recompute treats it
		// as in-scope. If Jeff has manually flipped it to amazon/self the
		// previous import wouldn't have matched in the first place.
		if ( ! get_post_meta( $post_id, '_fishotel_fulfillment', true ) ) {
			update_post_meta( $post_id, '_fishotel_fulfillment', 'ea' );
		}
	}

	/**
	 * Replace the full variation set on a parent: delete every existing
	 * child variation, register attributes from the JSON, then create
	 * new variations in wholesale-sorted order.
	 */
	protected function rebuild_variations( $parent_id, $variations ) {
		$parent = wc_get_product( $parent_id );
		if ( ! $parent ) {
			throw new Exception( sprintf( 'Parent product #%d not found.', $parent_id ) );
		}

		// Delete existing children.
		foreach ( $parent->get_children() as $vid ) {
			wp_delete_post( (int) $vid, true );
		}

		// Reload — get_children() is cached on the WC_Product instance.
		$parent = wc_get_product( $parent_id );
		$parent->set_attributes( [] );
		$parent->save();

		// Register + attach attributes from the JSON shape.
		$attr_map = $this->build_attribute_map( $variations );
		$this->set_parent_attributes( $parent_id, $attr_map );

		// Sort variations by wholesale ascending — pricing fn relies on
		// position in this list for its 1.75 → 1.30 multiplier.
		$sorted = $this->sort_by_wholesale( $variations );
		$count  = count( $sorted );
		foreach ( $sorted as $i => $v ) {
			$this->create_variation( $parent_id, $i, $count, $v, $attr_map );
		}

		// Rebuild the parent's cached min/max variation price + stock
		// status. WC does NOT do this implicitly on variation save, so
		// the parent's _min_variation_price / _max_variation_price meta
		// (which drives the frontend price-range HTML and the admin
		// product list) stays stale or empty unless we explicitly resync.
		if ( class_exists( 'WC_Product_Variable' ) ) {
			WC_Product_Variable::sync( (int) $parent_id );
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $parent_id );
		}
	}

	/**
	 * Walk every variation's attrs and produce:
	 *   [
	 *     'attribute_pa_jar-size' => [
	 *        'storage_key' => 'pa_jar-size',
	 *        'taxonomy'    => 'pa_jar-size',
	 *        'is_taxonomy' => true,
	 *        'label'       => 'Jar Size',
	 *        'values'      => [ '10gm', '25gm', ... ],   // unique, ordered first-seen
	 *     ],
	 *     'attribute_type' => [
	 *        'storage_key' => 'type',
	 *        'taxonomy'    => '',
	 *        'is_taxonomy' => false,
	 *        'label'       => 'Type',
	 *        'values'      => [ 'Cubes', 'Siftings' ],
	 *     ],
	 *   ]
	 */
	protected function build_attribute_map( $variations ) {
		$map = [];
		foreach ( $variations as $v ) {
			if ( empty( $v['attrs'] ) || ! is_array( $v['attrs'] ) ) {
				continue;
			}
			foreach ( $v['attrs'] as $raw_key => $raw_val ) {
				$raw_key = (string) $raw_key;
				$raw_val = (string) $raw_val;
				if ( $raw_key === '' || $raw_val === '' ) {
					continue;
				}
				if ( strpos( $raw_key, 'attribute_' ) !== 0 ) {
					continue; // ignore stray meta-style keys
				}
				$short = substr( $raw_key, strlen( 'attribute_' ) );
				$is_tax = ( strpos( $short, 'pa_' ) === 0 );

				if ( ! isset( $map[ $raw_key ] ) ) {
					$map[ $raw_key ] = [
						'storage_key' => $short,
						'taxonomy'    => $is_tax ? $short : '',
						'is_taxonomy' => $is_tax,
						'label'       => $this->pretty_label( $short ),
						'values'      => [],
					];
				}
				if ( ! in_array( $raw_val, $map[ $raw_key ]['values'], true ) ) {
					$map[ $raw_key ]['values'][] = $raw_val;
				}
			}
		}
		return $map;
	}

	/**
	 * Ensure every taxonomy attribute in the map exists globally + has
	 * all observed terms, then attach the assembled WC_Product_Attribute
	 * set to the parent.
	 */
	protected function set_parent_attributes( $parent_id, $attr_map ) {
		$wc_attributes = [];
		$position = 0;
		foreach ( $attr_map as $info ) {
			if ( $info['is_taxonomy'] ) {
				$taxonomy = $this->ensure_taxonomy( $info['taxonomy'], $info['label'] );
				if ( $taxonomy === '' ) {
					continue;
				}
				// Seed missing terms; collect their slugs in declaration order.
				$term_slugs = [];
				foreach ( $info['values'] as $val ) {
					$slug = $this->ensure_term( $taxonomy, $val );
					if ( $slug !== '' ) {
						$term_slugs[] = $slug;
					}
				}
				$attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy );
				$attribute = new WC_Product_Attribute();
				$attribute->set_id( (int) $attribute_id );
				$attribute->set_name( $taxonomy );
				$attribute->set_options( $term_slugs );
				$attribute->set_position( $position++ );
				$attribute->set_visible( true );
				$attribute->set_variation( true );
				$wc_attributes[] = $attribute;
			} else {
				$attribute = new WC_Product_Attribute();
				$attribute->set_id( 0 ); // custom
				$attribute->set_name( $info['label'] );
				$attribute->set_options( $info['values'] );
				$attribute->set_position( $position++ );
				$attribute->set_visible( true );
				$attribute->set_variation( true );
				$wc_attributes[] = $attribute;
			}
		}

		$parent = wc_get_product( $parent_id );
		$parent->set_attributes( $wc_attributes );
		$parent->save();
	}

	/**
	 * Idempotently create a global WC attribute taxonomy. Returns the
	 * registered taxonomy name (e.g. `pa_jar-size`) or '' on failure.
	 *
	 * @param string $tax_slug e.g. `pa_jar-size`
	 * @param string $label    e.g. `Jar Size`
	 */
	protected function ensure_taxonomy( $tax_slug, $label ) {
		// wc_create_attribute wants the slug WITHOUT the pa_ prefix.
		$bare = ( strpos( $tax_slug, 'pa_' ) === 0 ) ? substr( $tax_slug, 3 ) : $tax_slug;
		$bare = sanitize_title( $bare );
		if ( $bare === '' ) {
			return '';
		}

		$taxonomy = 'pa_' . $bare;
		if ( taxonomy_exists( $taxonomy ) ) {
			return $taxonomy;
		}

		// Not registered in-process. Check if a row exists in
		// wp_woocommerce_attribute_taxonomies first — wc_create_attribute
		// errors on duplicate slugs.
		$existing_id = wc_attribute_taxonomy_id_by_name( $bare );
		if ( ! $existing_id ) {
			$result = wc_create_attribute( [
				'slug'         => $bare,
				'name'         => $label,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			] );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf( 'Could not create attribute "%s": %s', $bare, $result->get_error_message() ) );
				return '';
			}
		}

		// Register in-process so wp_insert_term works in this request.
		register_taxonomy(
			$taxonomy,
			[ 'product', 'product_variation' ],
			[ 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ]
		);

		delete_transient( 'wc_attribute_taxonomies' );
		wp_cache_delete( 'attribute_taxonomies', 'woocommerce-attributes' );

		return $taxonomy;
	}

	/**
	 * Ensure a term exists in a taxonomy. Returns the term slug (which
	 * is what variations store as their attribute value).
	 */
	protected function ensure_term( $taxonomy, $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return '';
		}
		$slug = sanitize_title( $value );
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term->slug;
		}
		$res = wp_insert_term( $value, $taxonomy, [ 'slug' => $slug ] );
		if ( is_wp_error( $res ) ) {
			// Race: term was inserted between our get_term_by + insert.
			if ( $res->get_error_code() === 'term_exists' ) {
				$existing = get_term_by( 'slug', $slug, $taxonomy );
				return $existing ? $existing->slug : '';
			}
			WP_CLI::warning( sprintf( 'Could not create term "%s" in %s: %s', $value, $taxonomy, $res->get_error_message() ) );
			return '';
		}
		return $slug;
	}

	/**
	 * Create one variation row + write its FisHotel price, EA metadata,
	 * weight, and stock status. $i is the wholesale-sorted index.
	 */
	protected function create_variation( $parent_id, $i, $count, $v, $attr_map ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( (int) $parent_id );
		$variation->set_status( 'publish' );

		// Build the attribute-key map WC expects.
		// For taxonomy attrs: key = `pa_jar-size`, value = term slug.
		// For custom attrs:   key = sanitize_title('Type') = 'type', value = raw display value.
		$attr_payload = [];
		if ( ! empty( $v['attrs'] ) && is_array( $v['attrs'] ) ) {
			foreach ( $v['attrs'] as $raw_key => $raw_val ) {
				if ( ! isset( $attr_map[ $raw_key ] ) ) {
					continue;
				}
				$info = $attr_map[ $raw_key ];
				if ( $info['is_taxonomy'] ) {
					// Re-derive the slug so we match what got registered as a term.
					$slug = sanitize_title( (string) $raw_val );
					$attr_payload[ $info['taxonomy'] ] = $slug;
				} else {
					$attr_payload[ sanitize_title( $info['label'] ) ] = (string) $raw_val;
				}
			}
		}
		$variation->set_attributes( $attr_payload );

		// Stock + weight from JSON.
		$variation->set_stock_status( ! empty( $v['in_stock'] ) ? 'instock' : 'outofstock' );
		if ( isset( $v['weight'] ) && (float) $v['weight'] > 0 ) {
			$variation->set_weight( (float) $v['weight'] );
		}

		// FisHotel price via the existing pricing fn.
		$wholesale = isset( $v['wholesale'] ) ? (float) $v['wholesale'] : 0.0;
		$retail    = isset( $v['retail'] )    ? (float) $v['retail']    : 0.0;
		if ( $wholesale > 0 && $retail > 0 ) {
			$price = fishotel_calculate_med_price( $wholesale, $retail, (int) $i, (int) $count );
			$variation->set_regular_price( (string) $price );
			$variation->set_price( (string) $price );
		}

		// Set WC's standard _sku field so admin search, REST, and the
		// variations table all show the EA SKU. Different field from
		// the _fishotel_ea_sku meta written below — that one is our
		// fulfillment-lookup tag, _sku is WC's canonical product code.
		// WC throws WC_Data_Exception when another product already
		// holds this SKU; we log + drop the SKU on that one variation
		// rather than aborting the whole import.
		$ea_sku = isset( $v['sku'] ) ? sanitize_text_field( (string) $v['sku'] ) : '';
		if ( $ea_sku !== '' ) {
			try {
				$variation->set_sku( $ea_sku );
			} catch ( WC_Data_Exception $e ) {
				WP_CLI::warning( sprintf(
					'SKU "%s" rejected by WC on parent #%d — %s. Variation saved without _sku.',
					$ea_sku,
					(int) $parent_id,
					$e->getMessage()
				) );
			}
		}

		$variation->save();
		$vid = $variation->get_id();

		// Per-spec Phase 3 meta.
		update_post_meta( $vid, '_fishotel_ea_variation_id', isset( $v['variation_id'] ) ? (int) $v['variation_id'] : 0 );
		update_post_meta( $vid, '_fishotel_ea_sku',          $ea_sku );
		update_post_meta( $vid, '_fishotel_ea_wholesale',    $wholesale > 0 ? wc_format_decimal( $wholesale ) : '' );
		update_post_meta( $vid, '_fishotel_ea_retail',       $retail    > 0 ? wc_format_decimal( $retail )    : '' );

		// Mirror to Phase 1 meta keys so the existing variation-edit UI
		// + the FisHotel_Med_Pricing::recompute_for_product helper keep
		// working without a second migration.
		update_post_meta( $vid, '_fishotel_wholesale',  $wholesale > 0 ? wc_format_decimal( $wholesale ) : '' );
		update_post_meta( $vid, '_fishotel_size_index', (int) $i );
		update_post_meta( $vid, '_fishotel_size_count', (int) $count );
	}

	/* ───── lookup + utility helpers ───────────────────────────────── */

	protected function find_existing( $parent_sku, $ea_url ) {
		if ( $parent_sku !== '' ) {
			$by_sku = $this->find_by_meta( '_fishotel_ea_sku', $parent_sku );
			if ( $by_sku ) {
				return $by_sku;
			}
		}
		if ( $ea_url !== '' ) {
			return $this->find_by_meta( '_fishotel_ea_url', $ea_url );
		}
		return 0;
	}

	protected function find_by_meta( $key, $value ) {
		$q = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[
					'key'   => $key,
					'value' => $value,
				],
			],
		] );
		return ! empty( $q->posts ) ? (int) $q->posts[0] : 0;
	}

	protected function get_medications_parent_id() {
		$term = get_term_by( 'slug', FISHOTEL_MED_PARENT_SLUG, 'product_cat' );
		return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
	}

	protected function build_ea_url( $relative_path ) {
		$relative_path = trim( (string) $relative_path );
		if ( $relative_path === '' ) {
			return '';
		}
		if ( strpos( $relative_path, 'http' ) === 0 ) {
			return esc_url_raw( $relative_path );
		}
		$base = untrailingslashit( self::EA_BASE );
		if ( $relative_path[0] !== '/' ) {
			$relative_path = '/' . $relative_path;
		}
		return esc_url_raw( $base . $relative_path );
	}

	/** Resolve the --file flag, falling back to the symlinked default upload path. */
	protected function resolve_path( $flag_value ) {
		if ( $flag_value !== '' ) {
			return $flag_value;
		}
		$upload = wp_get_upload_dir();
		$base   = isset( $upload['basedir'] ) ? rtrim( (string) $upload['basedir'], '/' ) : '';
		if ( $base === '' ) {
			return '';
		}
		return $base . '/' . self::DEFAULT_FILE_REL;
	}

	protected function detect_freeze_dried( $slug_path, $variations ) {
		$slug_path = strtolower( (string) $slug_path );
		foreach ( self::FREEZE_DRIED_SLUG_HINTS as $hint ) {
			if ( strpos( $slug_path, $hint ) !== false ) {
				return true;
			}
		}
		foreach ( $variations as $v ) {
			if ( ! empty( $v['attrs']['attribute_type'] ) ) {
				return true;
			}
		}
		return false;
	}

	protected function sort_by_wholesale( $variations ) {
		$copy = $variations;
		usort( $copy, function ( $a, $b ) {
			$aw = isset( $a['wholesale'] ) ? (float) $a['wholesale'] : 0.0;
			$bw = isset( $b['wholesale'] ) ? (float) $b['wholesale'] : 0.0;
			if ( $aw === $bw ) {
				return 0;
			}
			return ( $aw < $bw ) ? -1 : 1;
		} );
		return $copy;
	}

	/** Min/max FisHotel-calculated price across a wholesale-sorted variation set. */
	protected function price_range_for( $variations ) {
		$sorted = $this->sort_by_wholesale( $variations );
		$count  = count( $sorted );
		$prices = [];
		foreach ( $sorted as $i => $v ) {
			$wholesale = isset( $v['wholesale'] ) ? (float) $v['wholesale'] : 0.0;
			$retail    = isset( $v['retail'] )    ? (float) $v['retail']    : 0.0;
			if ( $wholesale > 0 && $retail > 0 ) {
				$prices[] = fishotel_calculate_med_price( $wholesale, $retail, (int) $i, (int) $count );
			}
		}
		if ( empty( $prices ) ) {
			return [ 'min' => 0.0, 'max' => 0.0 ];
		}
		return [ 'min' => min( $prices ), 'max' => max( $prices ) ];
	}

	/** Human description of the attribute shape — "jar-size", "pellet-size × bag-size", etc. */
	protected function describe_attribute_shape( $variations ) {
		$keys = [];
		foreach ( $variations as $v ) {
			if ( empty( $v['attrs'] ) || ! is_array( $v['attrs'] ) ) {
				continue;
			}
			foreach ( array_keys( $v['attrs'] ) as $k ) {
				$k = (string) $k;
				if ( strpos( $k, 'attribute_' ) !== 0 ) {
					continue;
				}
				$short = substr( $k, strlen( 'attribute_' ) );
				if ( strpos( $short, 'pa_' ) === 0 ) {
					$short = substr( $short, 3 );
				}
				$keys[ $short ] = true;
			}
		}
		$labels = array_keys( $keys );
		return empty( $labels ) ? '(no attributes)' : implode( ' × ', $labels );
	}

	/** "pa_jar-size" → "Jar Size", "type" → "Type". */
	protected function pretty_label( $short_key ) {
		$bare = ( strpos( $short_key, 'pa_' ) === 0 ) ? substr( $short_key, 3 ) : $short_key;
		$bare = str_replace( [ '-', '_' ], ' ', $bare );
		return ucwords( trim( $bare ) );
	}

	/** Decode EA's HTML-entity-laced titles ("Cubes &#038; Siftings" → "Cubes & Siftings"). */
	protected function decode_title( $raw ) {
		$decoded = html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return sanitize_text_field( $decoded );
	}
}
