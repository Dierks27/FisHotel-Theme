<?php
/**
 * Medication store — WP-CLI product importer (Phase 1.5).
 *
 * Two subcommands wired under `wp fishotel-meds`:
 *
 *     wp fishotel-meds reset              # Trash every product under the Medications tree.
 *     wp fishotel-meds import             # Idempotent import of the 12 spec products.
 *     wp fishotel-meds import --force     # Re-import even when a previous import is found.
 *
 * Typical workflow:  wp fishotel-meds reset && wp fishotel-meds import
 *
 * Idempotency: each spec product is tagged on import with the meta key
 * `_fishotel_med_import_id` set to the spec-row index (1-12). The
 * import command finds existing imports by that meta, so renaming a
 * product after import does NOT cause a duplicate on re-run.
 *
 * EA wholesale strategy (spec §4 note):
 *   1. Live API. For the FIRST ea-mode product, log the raw meta_data
 *      so we can see whether wholesale comes through the WC REST API.
 *      If wholesale-detection returns a value, use it on every variation.
 *   2. Range fallback. If detection fails, distribute the spec's
 *      wholesale + retail ranges proportionally across the variation
 *      count, smallest at the low end, largest at the high end.
 *   3. Manual. If no live data AND no range (EA returned no match),
 *      create 3 placeholder variations (Small / Medium / Large) and
 *      warn the operator.
 *
 * All products created in `draft` status — Jeff publishes after
 * adding descriptions / photos / per-product attributes.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Importer_CLI {

	/** Meta key tagging which spec row a product came from. */
	const IMPORT_META_KEY = '_fishotel_med_import_id';

	/**
	 * Static catalog — the source of truth for the importer. Mirrors
	 * Phase 1 spec §6 + §6 "Source data" tables. Keys are the spec row
	 * index (1-12); values describe what to create.
	 *
	 *   wholesale_range / retail_range:
	 *     [low, high]. When low === high the product has a single
	 *     price (e.g. Forma Green) — placeholders still use 3 variations
	 *     per spec, all at the same price.
	 */
	public static function catalog() {
		return [
			1 => [
				'title'      => 'Copper Power',
				'eyebrow'    => 'The Original Copper Concierge',
				'category'   => 'antiparasitic',
				'mode'       => 'amazon',
				'ea_slug'    => '',
				'wholesale_range' => null,
				'retail_range'    => null,
			],
			2 => [
				'title'      => 'Chloroquine Phosphate Powder',
				'eyebrow'    => 'The Quiet Specialist',
				'category'   => 'antiparasitic',
				'mode'       => 'ea',
				'ea_slug'    => 'chloroquine',
				'wholesale_range' => [ 33.50, 765.00 ],
				'retail_range'    => [ 50.00, 900.00 ],
			],
			3 => [
				'title'      => 'Praziquantel Powder',
				'eyebrow'    => 'The Deworming Service',
				'category'   => 'dewormer',
				'mode'       => 'ea',
				'ea_slug'    => 'praziquantel-powder',
				'wholesale_range' => [ 14.00, 600.00 ],
				'retail_range'    => [ 20.00, 675.00 ],
			],
			4 => [
				'title'      => 'Fenbendazole Powder',
				'eyebrow'    => 'The Persistent Worm Specialist',
				'category'   => 'dewormer',
				'mode'       => 'ea',
				'ea_slug'    => 'fenbendazole-powder',
				'wholesale_range' => [ 16.40, 590.00 ],
				'retail_range'    => [ 25.00, 750.00 ],
			],
			5 => [
				'title'      => 'Forma Green',
				'eyebrow'    => 'The Deep Clean Treatment',
				'category'   => 'antiparasitic',
				'mode'       => 'ea',
				'ea_slug'    => 'forma-green',
				'wholesale_range' => [ 75.00, 75.00 ],
				'retail_range'    => [ 195.00, 195.00 ],
			],
			6 => [
				'title'      => 'Metro Double Pellet',
				'eyebrow'    => 'Room Service: Stage 4',
				'category'   => 'medicated-food',
				'mode'       => 'ea',
				'ea_slug'    => 'metro-double-pellet',
				'wholesale_range' => [ 3.50, 138.00 ],
				'retail_range'    => [ 6.25, 200.00 ],
			],
			7 => [
				'title'      => 'General Antibiotic Cure',
				'eyebrow'    => 'The Broad-Spectrum Concierge',
				'category'   => 'antibacterial',
				'mode'       => 'ea',
				'ea_slug'    => 'general-antibiotic-cure',
				'wholesale_range' => [ 30.70, 645.00 ],
				'retail_range'    => [ 45.00, 825.00 ],
			],
			8 => [
				'title'      => 'General Parasite Cure',
				'eyebrow'    => 'The Eviction Notice',
				'category'   => 'antiparasitic',
				'mode'       => 'ea',
				'ea_slug'    => 'general-parasite-cure',
				'wholesale_range' => [ 8.00, 230.00 ],
				'retail_range'    => [ 12.00, 300.00 ],
			],
			9 => [
				'title'      => 'Kanamycin Sulfate',
				'eyebrow'    => 'The Heavy Artillery',
				'category'   => 'antibacterial',
				'mode'       => 'ea',
				'ea_slug'    => 'kanamycin-sulfate',
				'wholesale_range' => [ 14.60, 515.00 ],
				'retail_range'    => [ 22.00, 625.00 ],
			],
			10 => [
				'title'      => 'Nitrofurazone Powder',
				'eyebrow'    => 'The Fin Restoration Service',
				'category'   => 'antibacterial',
				'mode'       => 'ea',
				'ea_slug'    => 'nitrofurazone-powder',
				'wholesale_range' => [ 10.25, 242.00 ],
				'retail_range'    => [ 15.00, 300.00 ],
			],
			11 => [
				'title'      => 'Methylene Blue Powder',
				'eyebrow'    => 'The Travel Insurance',
				'category'   => 'quarantine-essentials',
				'mode'       => 'ea',
				'ea_slug'    => 'meth-blue',
				'wholesale_range' => [ 10.20, 350.00 ],
				'retail_range'    => [ 15.00, 425.00 ],
			],
			12 => [
				'title'      => 'Levamisole Powder',
				'eyebrow'    => 'The Internal Cleansing Service',
				'category'   => 'dewormer',
				'mode'       => 'ea',
				'ea_slug'    => 'levamisole-powder',
				'wholesale_range' => [ 9.50, 495.00 ],
				'retail_range'    => [ 15.00, 650.00 ],
			],
		];
	}

	/**
	 * Trash every product under the Medications category tree.
	 *
	 * ## OPTIONS
	 *
	 * (none)
	 *
	 * ## EXAMPLES
	 *
	 *     wp fishotel-meds reset
	 *
	 * @when after_wp_load
	 */
	public function reset( $args, $assoc_args ) {
		$ids = $this->find_medications_products();
		if ( empty( $ids ) ) {
			WP_CLI::log( 'No products in the Medications category tree — nothing to trash.' );
			return;
		}

		$trashed = 0;
		foreach ( $ids as $pid ) {
			$title = get_the_title( $pid );
			$deleted = wp_delete_post( (int) $pid, true );
			if ( $deleted ) {
				WP_CLI::log( sprintf( 'Trashed: %s (#%d)', $title !== '' ? $title : '(no title)', $pid ) );
				$trashed++;
			} else {
				WP_CLI::warning( sprintf( 'Failed to delete product #%d', $pid ) );
			}
		}
		WP_CLI::success( sprintf( 'Reset complete — %d product(s) deleted.', $trashed ) );
	}

	/**
	 * Import the 12 spec products. Idempotent by default — re-running
	 * without --force skips spec rows that already have a tagged import.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Re-import even when a previous import is found. Deletes the
	 *   matched product first, then re-creates it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fishotel-meds import
	 *     wp fishotel-meds import --force
	 *
	 * @when after_wp_load
	 */
	public function import( $args, $assoc_args ) {
		$force = ! empty( $assoc_args['force'] );

		// Pre-flight: make sure the parent + subcategories exist. Phase 1's
		// taxonomy bootstrap runs on admin_init only, so a fresh CLI session
		// may not have created them yet. Call directly.
		if ( class_exists( 'FisHotel_Med_Taxonomy' ) ) {
			FisHotel_Med_Taxonomy::ensure_categories();
			FisHotel_Med_Taxonomy::ensure_attributes();
		}

		$catalog       = self::catalog();
		$summary       = [
			'created'      => 0,
			'skipped'      => 0,
			'failed'       => 0,
			'needs_manual' => [], // titles flagged for follow-up.
		];
		$ea_log_sample_done = false;

		foreach ( $catalog as $import_id => $row ) {
			$existing = $this->find_by_import_id( (int) $import_id );
			if ( $existing && ! $force ) {
				WP_CLI::log( sprintf( 'Skipped: %s — already imported (#%d). Use --force to replace.', $row['title'], $existing ) );
				$summary['skipped']++;
				continue;
			}
			if ( $existing && $force ) {
				WP_CLI::log( sprintf( 'Replacing: %s (#%d) — force flag set.', $row['title'], $existing ) );
				wp_delete_post( (int) $existing, true );
			}

			try {
				if ( $row['mode'] === 'amazon' ) {
					$new_id = $this->create_amazon_product( (int) $import_id, $row );
					WP_CLI::log( sprintf( 'Created: %s (#%d) — amazon mode, no variations.', $row['title'], $new_id ) );
					WP_CLI::warning( sprintf( 'Set ASIN at /wp-admin/post.php?post=%d&action=edit before publishing.', $new_id ) );
					$summary['created']++;
					continue;
				}

				$inspect_sample = ( ! $ea_log_sample_done );
				$result = $this->create_ea_product( (int) $import_id, $row, $inspect_sample );
				if ( $inspect_sample ) {
					$ea_log_sample_done = true;
				}
				if ( ! empty( $result['needs_manual'] ) ) {
					$summary['needs_manual'][] = $row['title'];
				}
				WP_CLI::log( sprintf(
					'Created: %s (#%d) with %d variations — wholesale source: %s.',
					$row['title'],
					$result['product_id'],
					$result['variation_count'],
					$result['wholesale_source']
				) );
				$summary['created']++;
			} catch ( Exception $e ) {
				WP_CLI::warning( sprintf( 'Failed: %s — %s', $row['title'], $e->getMessage() ) );
				$summary['failed']++;
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Summary: %d created, %d skipped, %d failed.', $summary['created'], $summary['skipped'], $summary['failed'] ) );
		if ( ! empty( $summary['needs_manual'] ) ) {
			WP_CLI::log( 'Needs manual wholesale entry / placeholder review:' );
			foreach ( $summary['needs_manual'] as $t ) {
				WP_CLI::log( '  - ' . $t );
			}
		}
		WP_CLI::success( 'Import complete.' );
	}

	/* ───── product creation ───────────────────────────────────────── */

	/** Create an amazon-mode product (simple product, no variations). */
	protected function create_amazon_product( $import_id, $row ) {
		$post_id = wp_insert_post( [
			'post_title'   => $row['title'],
			'post_status'  => 'draft',
			'post_type'    => 'product',
			'post_content' => '',
			'post_excerpt' => '',
		], true );
		if ( is_wp_error( $post_id ) ) {
			throw new Exception( $post_id->get_error_message() );
		}

		wp_set_object_terms( $post_id, [ 'simple' ], 'product_type' );
		$this->assign_categories( $post_id, $row['category'] );
		$this->set_universal_meta( $post_id, $import_id, $row );

		// Amazon products have no FisHotel price — explicit empty so
		// any inherited defaults don't leak in.
		update_post_meta( $post_id, '_regular_price', '' );
		update_post_meta( $post_id, '_price',         '' );
		update_post_meta( $post_id, '_virtual',       'no' );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $post_id );
		}
		return $post_id;
	}

	/**
	 * Create an ea-mode product. Fetches the EA payload + variations,
	 * creates the variable product, and seeds each variation with the
	 * computed FisHotel price (using the existing pricing function).
	 *
	 * Falls back to spec-range distribution if the EA lookup fails,
	 * and to "Small / Medium / Large" placeholders if there are no
	 * EA variations to mirror.
	 *
	 * @param bool $inspect_sample When true, log the EA payload's
	 *                             meta_data so we can verify whether
	 *                             wholesale comes through the API.
	 * @return array { product_id:int, variation_count:int, wholesale_source:string, needs_manual:bool }
	 */
	protected function create_ea_product( $import_id, $row, $inspect_sample ) {
		$post_id = wp_insert_post( [
			'post_title'   => $row['title'],
			'post_status'  => 'draft',
			'post_type'    => 'product',
			'post_content' => '',
			'post_excerpt' => '',
		], true );
		if ( is_wp_error( $post_id ) ) {
			throw new Exception( $post_id->get_error_message() );
		}

		wp_set_object_terms( $post_id, [ 'variable' ], 'product_type' );
		$this->assign_categories( $post_id, $row['category'] );
		$this->set_universal_meta( $post_id, $import_id, $row );

		$ea_url  = $this->build_ea_url( $row['ea_slug'] );
		if ( $ea_url !== '' ) {
			update_post_meta( $post_id, '_fishotel_ea_url', $ea_url );
		}

		// Try the live API first.
		$ea_payload = FisHotel_Med_EA_REST::fetch_by_slug( $row['ea_slug'] );
		$wholesale_source = 'placeholder';
		$variation_specs  = []; // [ ['size'=>str, 'wholesale'=>float, 'retail'=>float, 'stock_status'=>str] ]
		$needs_manual = false;

		if ( is_wp_error( $ea_payload ) ) {
			WP_CLI::warning( sprintf(
				'EA lookup failed for %s (%s): %s — falling back to placeholders.',
				$row['title'],
				$row['ea_slug'],
				$ea_payload->get_error_message()
			) );
			$variation_specs  = $this->placeholder_variations( $row );
			$wholesale_source = $variation_specs ? 'range' : 'placeholder';
			$needs_manual     = true;
		} else {
			// Save the canonical EA IDs from the live payload.
			if ( isset( $ea_payload['id'] ) ) {
				update_post_meta( $post_id, '_fishotel_ea_product_id', (int) $ea_payload['id'] );
			}
			if ( isset( $ea_payload['sku'] ) && $ea_payload['sku'] !== '' ) {
				update_post_meta( $post_id, '_fishotel_ea_sku', sanitize_text_field( (string) $ea_payload['sku'] ) );
			}
			if ( $inspect_sample ) {
				$this->log_ea_sample( $ea_payload );
			}

			$variations_raw = FisHotel_Med_EA_REST::fetch_variations( (int) ( $ea_payload['id'] ?? 0 ) );
			if ( is_wp_error( $variations_raw ) || empty( $variations_raw ) ) {
				// Variation fetch failed or EA has no variations on this
				// product. Use range fallback so we still get sized rows.
				if ( is_wp_error( $variations_raw ) ) {
					WP_CLI::warning( sprintf(
						'EA variation fetch failed for %s: %s — using range fallback.',
						$row['title'],
						$variations_raw->get_error_message()
					) );
				}
				$variation_specs  = $this->placeholder_variations( $row );
				$wholesale_source = 'range';
				$needs_manual     = true;
			} else {
				// Build specs from the EA variations array. wholesale = detected
				// from meta_data if available; otherwise range-distributed.
				$variation_specs  = $this->variations_from_ea( $variations_raw, $row, $wholesale_source );
				if ( $wholesale_source === 'live' ) {
					$needs_manual = false;
				} else {
					$needs_manual = true;
				}
			}
		}

		// Build the parent's Size attribute from the resolved variation list.
		$size_labels = wp_list_pluck( $variation_specs, 'size' );
		$this->set_size_attribute( $post_id, $size_labels );

		// Create child variations + prices.
		$count = count( $variation_specs );
		foreach ( $variation_specs as $i => $spec ) {
			$this->create_variation( $post_id, $i, $count, $spec );
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $post_id );
		}

		return [
			'product_id'       => $post_id,
			'variation_count'  => $count,
			'wholesale_source' => $wholesale_source,
			'needs_manual'     => $needs_manual,
		];
	}

	/* ───── data helpers ───────────────────────────────────────────── */

	/** Set the universal meta fields shared across all modes. */
	protected function set_universal_meta( $post_id, $import_id, $row ) {
		update_post_meta( $post_id, self::IMPORT_META_KEY,    (int) $import_id );
		update_post_meta( $post_id, '_fishotel_fulfillment',  $row['mode'] );
		update_post_meta( $post_id, '_fishotel_med_eyebrow',  $row['eyebrow'] );
		update_post_meta( $post_id, '_fishotel_dosing_anchor','#' . sanitize_title( $row['title'] ) );
		// Defensive: blank ASIN field so admin shows an empty input.
		if ( $row['mode'] !== 'amazon' ) {
			update_post_meta( $post_id, '_fishotel_amazon_asin', '' );
		}
	}

	/** Assign BOTH the parent Medications term AND the subcategory term. */
	protected function assign_categories( $post_id, $subcategory_slug ) {
		$term_ids = [];
		$parent = get_term_by( 'slug', FISHOTEL_MED_PARENT_SLUG, 'product_cat' );
		if ( $parent && ! is_wp_error( $parent ) ) {
			$term_ids[] = (int) $parent->term_id;
		}
		$sub = get_term_by( 'slug', $subcategory_slug, 'product_cat' );
		if ( $sub && ! is_wp_error( $sub ) ) {
			$term_ids[] = (int) $sub->term_id;
		}
		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $post_id, $term_ids, 'product_cat' );
		}
	}

	/** Build the full EA product URL from a slug, using stored Settings. */
	protected function build_ea_url( $slug ) {
		$slug = trim( (string) $slug );
		if ( $slug === '' ) return '';
		$base = (string) get_option( 'fishotel_ea_store_url', 'https://everythingaquatic.net' );
		$base = untrailingslashit( $base );
		return $base . '/product/' . rawurlencode( $slug ) . '/';
	}

	/**
	 * Resolved variation specs from EA's variations array. Determines
	 * wholesale source by reference — sets to 'live' when detected on
	 * any variation, otherwise 'range' (proportional distribution).
	 *
	 * @param array  $variations_raw     EA payload variations.
	 * @param array  $row                Catalog row.
	 * @param string $wholesale_source   By-ref output: 'live' or 'range'.
	 * @return array
	 */
	protected function variations_from_ea( $variations_raw, $row, &$wholesale_source ) {
		// Stable order: sort by menu_order, then variation id, so the
		// pricing function's size_index matches EA's display order.
		usort( $variations_raw, function ( $a, $b ) {
			$ma = isset( $a['menu_order'] ) ? (int) $a['menu_order'] : 0;
			$mb = isset( $b['menu_order'] ) ? (int) $b['menu_order'] : 0;
			if ( $ma !== $mb ) return $ma <=> $mb;
			return (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
		} );

		$any_wholesale_live = false;
		$specs = [];
		$count = count( $variations_raw );

		foreach ( $variations_raw as $i => $var ) {
			$size = $this->extract_size_label( $var );
			if ( $size === '' ) {
				$size = sprintf( 'Size %d', $i + 1 );
			}

			$retail = isset( $var['regular_price'] ) && is_numeric( $var['regular_price'] )
				? (float) $var['regular_price']
				: 0.0;
			if ( $retail <= 0 && isset( $var['price'] ) && is_numeric( $var['price'] ) ) {
				$retail = (float) $var['price'];
			}

			$wholesale = FisHotel_Med_EA_REST::extract_wholesale( $var );
			if ( $wholesale !== null ) {
				$any_wholesale_live = true;
			}

			$specs[] = [
				'size'         => $size,
				'wholesale'    => $wholesale, // null = needs range fallback
				'retail'       => $retail,
				'stock_status' => $this->stock_status_from_ea( $var ),
				'ea_sku'       => isset( $var['sku'] ) ? (string) $var['sku'] : '',
			];
		}

		if ( $any_wholesale_live ) {
			$wholesale_source = 'live';
			// Fill any nulls with range-distributed values to keep the
			// pricing function happy on partial responses.
			foreach ( $specs as $i => $spec ) {
				if ( $spec['wholesale'] === null && $row['wholesale_range'] ) {
					$specs[ $i ]['wholesale'] = $this->interpolate_range( $row['wholesale_range'], $i, $count );
				}
			}
		} else {
			$wholesale_source = 'range';
			foreach ( $specs as $i => $spec ) {
				$specs[ $i ]['wholesale'] = $row['wholesale_range']
					? $this->interpolate_range( $row['wholesale_range'], $i, $count )
					: 0.0;
			}
		}

		// If retail came back as 0 from EA for some reason, fall back to
		// the spec range too so the pricing function still has a floor.
		foreach ( $specs as $i => $spec ) {
			if ( $spec['retail'] <= 0 && $row['retail_range'] ) {
				$specs[ $i ]['retail'] = $this->interpolate_range( $row['retail_range'], $i, $count );
			}
		}

		return $specs;
	}

	/**
	 * Three placeholder variations (Small / Medium / Large) using the
	 * spec's low / mid / high. Used when EA returns no match OR when
	 * the variation fetch fails after a successful parent lookup.
	 */
	protected function placeholder_variations( $row ) {
		$wr = $row['wholesale_range'] ?: [ 0.0, 0.0 ];
		$rr = $row['retail_range']    ?: [ 0.0, 0.0 ];
		$labels = [ 'Small', 'Medium', 'Large' ];
		$specs  = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$specs[] = [
				'size'         => $labels[ $i ],
				'wholesale'    => $this->interpolate_range( $wr, $i, 3 ),
				'retail'       => $this->interpolate_range( $rr, $i, 3 ),
				'stock_status' => 'instock',
				'ea_sku'       => '',
			];
		}
		return $specs;
	}

	/** Linear interpolation across a [low, high] range. */
	protected function interpolate_range( $range, $i, $count ) {
		$low  = (float) $range[0];
		$high = (float) $range[1];
		if ( $count <= 1 ) {
			return round( ( $low + $high ) / 2, 2 );
		}
		$t = $i / max( 1, $count - 1 );
		return round( $low + ( $high - $low ) * $t, 2 );
	}

	/**
	 * Pull the size label from an EA variation's attributes array. EA
	 * names size attributes inconsistently ("Size", "Weight", "Volume")
	 * — fall back to the first attribute when nothing matches.
	 */
	protected function extract_size_label( $var ) {
		if ( ! isset( $var['attributes'] ) || ! is_array( $var['attributes'] ) ) return '';
		// Prefer attribute named like "size", "weight", "volume".
		$size_like = [ 'size', 'weight', 'volume' ];
		foreach ( $var['attributes'] as $attr ) {
			$name = isset( $attr['name'] ) ? strtolower( (string) $attr['name'] ) : '';
			foreach ( $size_like as $needle ) {
				if ( $name !== '' && strpos( $name, $needle ) !== false ) {
					return isset( $attr['option'] ) ? (string) $attr['option'] : '';
				}
			}
		}
		// Otherwise pick the first attribute's option.
		$first = $var['attributes'][0] ?? null;
		return ( is_array( $first ) && isset( $first['option'] ) ) ? (string) $first['option'] : '';
	}

	/** Normalize EA's stock_status to our local enum. */
	protected function stock_status_from_ea( $var ) {
		$s = isset( $var['stock_status'] ) ? (string) $var['stock_status'] : 'instock';
		if ( ! in_array( $s, [ 'instock', 'outofstock', 'onbackorder' ], true ) ) {
			$s = 'instock';
		}
		return $s;
	}

	/**
	 * Set a custom "Size" attribute on the parent variable product
	 * with the resolved variation labels as options. Required for WC
	 * to display the variations selector on the PDP.
	 */
	protected function set_size_attribute( $parent_id, $labels ) {
		if ( empty( $labels ) ) return;
		$labels = array_values( array_unique( array_map( 'strval', $labels ) ) );

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 ); // 0 = custom (non-taxonomy) attribute
		$attribute->set_name( 'Size' );
		$attribute->set_options( $labels );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = wc_get_product( $parent_id );
		if ( ! $parent ) return;
		$parent->set_attributes( [ $attribute ] );
		$parent->save();
	}

	/** Create a single variation row under $parent_id. */
	protected function create_variation( $parent_id, $size_index, $size_count, $spec ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		// Attribute key must be the lowercase custom-attribute name.
		$variation->set_attributes( [ 'size' => $spec['size'] ] );
		$variation->set_status( 'publish' );

		// Stock
		$variation->set_stock_status( $spec['stock_status'] );

		// Compute the FisHotel price using the existing pricing fn.
		// EA retail acts as the floor.
		$wholesale = (float) ( $spec['wholesale'] ?? 0 );
		$retail    = (float) ( $spec['retail']    ?? 0 );
		if ( $wholesale > 0 && $retail > 0 ) {
			$price = fishotel_calculate_med_price( $wholesale, $retail, (int) $size_index, (int) $size_count );
			$variation->set_regular_price( (string) $price );
			$variation->set_price( (string) $price );
		}

		$variation->save();
		$vid = $variation->get_id();

		// FisHotel-side meta the pricing function expects to read on a
		// later recompute. Save AFTER the variation's first save() so
		// the post row exists.
		update_post_meta( $vid, '_fishotel_wholesale',   $wholesale > 0 ? wc_format_decimal( $wholesale ) : '' );
		update_post_meta( $vid, '_fishotel_ea_retail',   $retail    > 0 ? wc_format_decimal( $retail )    : '' );
		update_post_meta( $vid, '_fishotel_size_index',  (int) $size_index );
		update_post_meta( $vid, '_fishotel_size_count',  (int) $size_count );
		if ( ! empty( $spec['ea_sku'] ) ) {
			update_post_meta( $vid, '_fishotel_ea_sku', sanitize_text_field( (string) $spec['ea_sku'] ) );
		}
	}

	/* ───── lookup helpers ─────────────────────────────────────────── */

	/** Find an existing imported product by its catalog import id. */
	protected function find_by_import_id( $import_id ) {
		$query = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => [
				[
					'key'   => self::IMPORT_META_KEY,
					'value' => (int) $import_id,
				],
			],
		] );
		$ids = $query->posts;
		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/** Find every product attached to the Medications category tree. */
	protected function find_medications_products() {
		$parent = get_term_by( 'slug', FISHOTEL_MED_PARENT_SLUG, 'product_cat' );
		if ( ! $parent || is_wp_error( $parent ) ) return [];

		$term_ids = [ (int) $parent->term_id ];
		$children = get_term_children( (int) $parent->term_id, 'product_cat' );
		if ( ! is_wp_error( $children ) ) {
			foreach ( $children as $cid ) {
				$term_ids[] = (int) $cid;
			}
		}

		$query = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $term_ids,
				],
			],
		] );
		return array_map( 'intval', $query->posts );
	}

	/**
	 * One-time diagnostic: log a slice of the EA payload's meta_data
	 * so the operator can see whether wholesale prices come through
	 * the API or live in a meta key we haven't accounted for.
	 *
	 * We log only meta keys + a redacted value (length only) — never
	 * full values, so EA's internal meta doesn't get spilled to logs.
	 */
	protected function log_ea_sample( $payload ) {
		WP_CLI::log( '--- EA payload diagnostic (first ea-mode product) ---' );
		$keys = [];
		if ( ! empty( $payload['meta_data'] ) && is_array( $payload['meta_data'] ) ) {
			foreach ( $payload['meta_data'] as $meta ) {
				if ( ! is_array( $meta ) || empty( $meta['key'] ) ) continue;
				$keys[] = (string) $meta['key'];
			}
		}
		if ( ! empty( $keys ) ) {
			WP_CLI::log( 'meta_data keys: ' . implode( ', ', $keys ) );
		} else {
			WP_CLI::log( 'meta_data: (empty or missing)' );
		}
		// Plus a quick summary of the top-level price fields so we can
		// see whether `price` and `regular_price` differ for this user.
		$summary = [];
		foreach ( [ 'price', 'regular_price', 'sale_price' ] as $k ) {
			if ( isset( $payload[ $k ] ) ) {
				$summary[] = $k . '=' . (string) $payload[ $k ];
			}
		}
		WP_CLI::log( 'top-level prices: ' . implode( ' · ', $summary ) );
		WP_CLI::log( '------------------------------------------------------' );
	}
}
