<?php
/**
 * Tool ↔ Product links — wire the Medication Dosing Calculator and the QT
 * Scheduler to real WooCommerce medication products, and the medication
 * product page back to the calculator.
 *
 * WHY: both tools were built before the medication catalog existed, so their
 * "buy this med" links were stubs (href="#" + a guessed SKU). The catalog is
 * now live but we cannot know from code which products exist, what they are
 * called, or how Jeff keyed them. Resolution is therefore data-driven with a
 * safe fallback, in this order (first hit wins):
 *
 *   (a) manual override  — option fh_tool_link_<med_id> (int product ID,
 *                          0 = auto). Must be a published product in the
 *                          Medications category tree (the pool the admin
 *                          select offers); amazon-mode products are allowed
 *                          here because an explicit choice is Jeff's call.
 *   (b) dosing anchor    — a published product whose _fishotel_dosing_anchor,
 *                          normalised (#praziquantel-powder → praziquantel_powder),
 *                          equals the med_id, else where the med_id is a
 *                          plain prefix of it (longest med_id wins; a prefix
 *                          hit must be unique across products).
 *   (c) SKU              — wc_get_product_id_by_sku() over a small per-med
 *                          candidate list (the five SKUs the scheduler used
 *                          to guess, plus med_id variants).
 *   (d) title            — a published product in the Medications category
 *                          tree whose normalised title starts with the med's
 *                          normalised generic name; exactly one product may
 *                          match, and the generic name must be unique in the
 *                          seed (the two Fenbendazole entries share one, so
 *                          they need an anchor or an override).
 *   (e) fallback         — the Medications category archive (or the shop).
 *
 * (b)–(d) never pick unpublished or amazon-mode products. Ambiguous matches
 * are recorded so the settings screen can say why Auto found nothing.
 *
 * Product page → calculator (intentional deviation from the first spec):
 * a product that resolves to a med links to ?med=<med_id>; a product whose
 * anchor maps to a known med (e.g. an amazon-mode product Auto skips) also
 * links to ?med=<med_id> rather than appending the anchor verbatim, because
 * the calculator reads the hash as a category tab, not a med; only an
 * anchor that maps to no med is appended verbatim.
 *
 * The whole map is cached in a transient for 12 hours and cleared whenever a
 * product, its stock, an fh_tool_link_* option or the medication dataset
 * changes. Resolution only runs where it is needed: the two tool pages, a
 * medication product page (reverse lookup) and the FisHotel Settings →
 * Product Page screen. The medication dataset itself (a ~90 KB
 * non-autoloaded option) is only loaded on those pages and on non-AJAX admin
 * requests, never on ordinary front-end requests.
 *
 * Depends on FisHotel_Medication_Data (inc/calculator) for the med catalog
 * and on inc/medication-store/helpers.php for fishotel_med_get_mode().
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Tool_Product_Links {

	const TRANSIENT       = 'fishotel_tool_med_links_v2';
	const TTL             = 12 * HOUR_IN_SECONDS;
	const OPTION_PREFIX   = 'fh_tool_link_';
	const FALLBACK_OPTION = 'fh_tool_link_fallback_url';
	const ANCHOR_META     = '_fishotel_dosing_anchor';

	/** Per-request memo of the resolved map. */
	protected static $memo = null;

	/** Per-request memo of the admin product option list. */
	protected static $product_options = null;

	public static function init() {
		// Cache invalidation — products.
		add_action( 'save_post_product',           [ __CLASS__, 'flush_cache' ] );
		add_action( 'woocommerce_update_product',  [ __CLASS__, 'flush_cache' ] );
		add_action( 'woocommerce_delete_product',  [ __CLASS__, 'flush_cache' ] );
		add_action( 'woocommerce_trash_product',   [ __CLASS__, 'flush_cache' ] );
		add_action( 'deleted_post',                [ __CLASS__, 'flush_cache_on_deleted_post' ], 10, 2 );
		// Stock changes don't always pass through woocommerce_update_product
		// (wc_update_product_stock writes straight to the data store), and
		// in_stock is part of the cached payload.
		add_action( 'woocommerce_product_set_stock',            [ __CLASS__, 'flush_cache' ] );
		add_action( 'woocommerce_variation_set_stock',          [ __CLASS__, 'flush_cache' ] );
		add_action( 'woocommerce_product_set_stock_status',     [ __CLASS__, 'flush_cache' ] );
		add_action( 'woocommerce_variation_set_stock_status',   [ __CLASS__, 'flush_cache' ] );
		// Cache invalidation — manual overrides and the medication dataset
		// (the catalog is an input to the map: renamed / added meds must show
		// up). update_option() on a key that does not exist yet fires
		// added_option, not updated_option, so both are needed.
		add_action( 'updated_option', [ __CLASS__, 'flush_cache_on_option' ] );
		add_action( 'added_option',   [ __CLASS__, 'flush_cache_on_option' ] );
		add_action( 'deleted_option', [ __CLASS__, 'flush_cache_on_option' ] );
	}

	// ─────────────────────────────────────────
	// CATALOG
	// ─────────────────────────────────────────

	/** med_id => name_generic from the seed (via the stored option). */
	public static function catalog() {
		static $catalog = null;
		if ( $catalog !== null ) return $catalog;

		$catalog = [];
		if ( ! class_exists( 'FisHotel_Medication_Data' ) ) return $catalog;

		$data = FisHotel_Medication_Data::get_all();
		if ( empty( $data['medications'] ) || ! is_array( $data['medications'] ) ) return $catalog;

		foreach ( $data['medications'] as $med ) {
			if ( empty( $med['med_id'] ) ) continue;
			$catalog[ (string) $med['med_id'] ] = isset( $med['name_generic'] ) ? (string) $med['name_generic'] : (string) $med['med_id'];
		}
		return $catalog;
	}

	/**
	 * True on a regular (non-AJAX, non-cron) admin request — the only kind
	 * that renders or saves the settings screen. The per-med option keys
	 * are only enumerated there, so ordinary front-end requests (which call
	 * FisHotel_Admin_Settings::defaults() for the tagline etc.) never load
	 * the medication dataset.
	 */
	protected static function is_settings_request() {
		return is_admin() && ! wp_doing_ajax() && ! wp_doing_cron();
	}

	// ─────────────────────────────────────────
	// NORMALISATION
	// ─────────────────────────────────────────

	/**
	 * Normalise a dosing anchor or product title to med_id shape:
	 * strip a leading '#', lowercase, turn '-' / ' ' (and any other
	 * non-alphanumeric run) into '_', trim stray underscores.
	 */
	public static function normalise( $value ) {
		$value = ltrim( trim( (string) $value ), '#' );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value );
		return trim( (string) $value, '_' );
	}

	/**
	 * Normalise a seed name_generic for title matching: drop
	 * parentheticals, keep only the part before a ' / ' alternative,
	 * drop percentage tokens, then normalise.
	 *   'Copper Power (ionic/chelated copper)' → 'copper_power'
	 *   'Formalin 37%'                        → 'formalin'
	 *   'Coppersafe / Kordon Copper Aid'      → 'coppersafe'
	 */
	public static function normalise_generic( $name ) {
		$name = preg_replace( '/\([^)]*\)/', ' ', (string) $name );
		$parts = preg_split( '/\s+\/\s+/', $name );
		$name = is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : $name;
		$name = preg_replace( '/\b\d+(\.\d+)?\s*%/', ' ', $name );
		return self::normalise( $name );
	}

	/**
	 * True when $needle is $haystack or a prefix of it. With $word_boundary
	 * the next character must be '_' (used for titles, where
	 * 'copper' must not claim 'coppersafe_8oz'); anchors use a plain
	 * prefix because they are explicit intent ('#praziquantel100g').
	 */
	protected static function is_prefix( $needle, $haystack, $word_boundary = true ) {
		if ( $needle === '' || $haystack === '' ) return false;
		if ( $needle === $haystack ) return true;
		$len = strlen( $needle );
		if ( strncmp( $haystack, $needle, $len ) !== 0 ) return false;
		return ! $word_boundary || substr( $haystack, $len, 1 ) === '_';
	}

	/**
	 * Map a normalised anchor to a med_id: exact match first, else the
	 * longest med_id that is a prefix. Returns '' when none.
	 */
	public static function med_id_for_anchor( $normalised_anchor ) {
		$catalog = self::catalog();
		if ( $normalised_anchor === '' ) return '';
		if ( isset( $catalog[ $normalised_anchor ] ) ) return $normalised_anchor;

		$best = '';
		foreach ( array_keys( $catalog ) as $med_id ) {
			if ( self::is_prefix( $med_id, $normalised_anchor, false ) && strlen( $med_id ) > strlen( $best ) ) {
				$best = $med_id;
			}
		}
		return $best;
	}

	// ─────────────────────────────────────────
	// URLS
	// ─────────────────────────────────────────

	/** Permalink of a published page by slug, else a home_url() path. */
	protected static function page_url( $slug ) {
		$page = get_page_by_path( $slug );
		if ( $page && $page->post_status === 'publish' ) {
			$link = get_permalink( $page );
			if ( $link ) return $link;
		}
		return home_url( '/' . $slug . '/' );
	}

	public static function calculator_url() {
		return self::page_url( 'quarantine-help' );
	}

	public static function scheduler_url() {
		return self::page_url( 'qt-scheduler' );
	}

	/** Fallback URL: the option, else the Medications archive, else the shop. */
	public static function fallback_url() {
		$custom = trim( (string) get_option( self::FALLBACK_OPTION, '' ) );
		if ( $custom !== '' ) return $custom;

		$slug = defined( 'FISHOTEL_MED_PARENT_SLUG' ) ? FISHOTEL_MED_PARENT_SLUG : 'medications';
		$link = get_term_link( $slug, 'product_cat' );
		if ( $link && ! is_wp_error( $link ) ) return $link;

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );
			if ( $shop ) return $shop;
		}
		return home_url( '/shop/' );
	}

	// ─────────────────────────────────────────
	// RESOLUTION
	// ─────────────────────────────────────────

	/** SKU candidates per med. The first five are the scheduler's old guesses. */
	public static function sku_candidates( $med_id ) {
		$known = [
			'copper_power'          => [ 'copper-power-2oz' ],
			'formalin_37'           => [ 'formalin-37-pint' ],
			'metronidazole'         => [ 'metronidazole-100g' ],
			'praziquantel'          => [ 'praziquantel-100g' ],
			'chloroquine_phosphate' => [ 'chloroquine-phosphate-25g' ],
		];
		$list = isset( $known[ $med_id ] ) ? $known[ $med_id ] : [];
		$list[] = $med_id;
		$list[] = str_replace( '_', '-', $med_id );
		// WC's SKU lookup compares with the table collation (case-insensitive
		// on every stock WordPress install), so no case variants.
		return array_values( array_unique( array_map( 'strtolower', $list ) ) );
	}

	/**
	 * Resolved map, memoised per request and cached in a transient.
	 *
	 * @return array med_id => [ product_id, url, name, source, in_stock,
	 *                           auto_product_id, auto_source, auto_reason,
	 *                           auto_candidates, override_ignored ]
	 */
	public static function links( $force = false ) {
		if ( self::$memo !== null && ! $force ) return self::$memo;

		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) && isset( $cached['links'] ) ) {
				self::$memo = $cached['links'];
				return self::$memo;
			}
		}

		self::$memo = self::resolve();
		// An empty map means the medication dataset was missing (fresh
		// staging copy, mid-reseed). Don't pin that for 12 hours.
		if ( self::$memo ) {
			set_transient( self::TRANSIENT, [ 'links' => self::$memo, 'built' => time() ], self::TTL );
		}
		return self::$memo;
	}

	/**
	 * Build the map from scratch: one meta_query (anchors), one tax_query
	 * (Medications-tree titles), plus cached SKU lookups for the meds the
	 * anchors did not cover.
	 */
	protected static function resolve() {
		$catalog = self::catalog();
		if ( empty( $catalog ) ) return [];

		// med_id => [ product_id, source, reason, candidates ]. product_id 0
		// with a reason means "Auto looked, but the match was ambiguous".
		$auto = [];

		// ── (b) anchors: one meta_query over published products ─────────
		$anchor_ids = get_posts( [
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => [ [
				'key'     => self::ANCHOR_META,
				'value'   => '',
				'compare' => '!=',
			] ],
		] );
		$anchor_ids = array_map( 'intval', (array) $anchor_ids );

		if ( $anchor_ids ) {
			update_meta_cache( 'post', $anchor_ids ); // one query primes anchor + fulfillment meta

			$exact  = []; // med_id => [ product_id, ... ]
			$prefix = []; // med_id => [ product_id, ... ]
			foreach ( $anchor_ids as $pid ) {
				if ( self::is_excluded_mode( $pid ) ) continue;
				$norm = self::normalise( get_post_meta( $pid, self::ANCHOR_META, true ) );
				if ( $norm === '' ) continue;
				if ( isset( $catalog[ $norm ] ) ) {
					$exact[ $norm ][] = $pid;
					continue;
				}
				$med_id = self::med_id_for_anchor( $norm );
				if ( $med_id !== '' ) $prefix[ $med_id ][] = $pid;
			}
			foreach ( array_keys( $catalog ) as $med_id ) {
				if ( ! empty( $exact[ $med_id ] ) ) {
					// Exact anchors are explicit intent; when several carry
					// the same one, prefer in-stock, then the lowest ID.
					$auto[ $med_id ] = self::auto_entry( self::pick_preferred( $exact[ $med_id ] ), 'anchor', count( $exact[ $med_id ] ) > 1 ? 'multi_anchor' : '', $exact[ $med_id ] );
				} elseif ( ! empty( $prefix[ $med_id ] ) ) {
					$auto[ $med_id ] = count( $prefix[ $med_id ] ) === 1
						? self::auto_entry( (int) $prefix[ $med_id ][0], 'anchor' )
						: self::auto_entry( 0, 'fallback', 'ambiguous_anchor', $prefix[ $med_id ] );
				}
			}
		}

		// ── (c) SKUs: wc_get_product_id_by_sku() per candidate, only for
		//     meds the anchors did not settle (resolved or ambiguous) ──────
		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			foreach ( array_keys( array_diff_key( $catalog, $auto ) ) as $med_id ) {
				foreach ( self::sku_candidates( $med_id ) as $sku ) {
					$pid = self::product_id_by_sku( $sku );
					if ( ! $pid || self::is_excluded_mode( $pid ) ) continue;
					$auto[ $med_id ] = self::auto_entry( $pid, 'sku' );
					break;
				}
			}
		}

		// ── (d) titles: one query over the Medications category tree ────
		$unresolved = array_diff_key( $catalog, $auto );
		if ( $unresolved ) {
			$slug      = defined( 'FISHOTEL_MED_PARENT_SLUG' ) ? FISHOTEL_MED_PARENT_SLUG : 'medications';
			$med_posts = get_posts( [
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,  // fulfillment mode, in one query
				'update_post_term_cache' => true,  // product type, for wc_get_product()
				'tax_query'              => [ [
					'taxonomy'         => 'product_cat',
					'field'            => 'slug',
					'terms'            => $slug,
					'include_children' => true,
				] ],
			] );

			$titles = []; // product_id => normalised title
			foreach ( (array) $med_posts as $post ) {
				if ( self::is_excluded_mode( $post->ID ) ) continue;
				$titles[ (int) $post->ID ] = self::normalise( $post->post_title );
			}

			// Generic names shared by several meds (the two Fenbendazole
			// entries) cannot be told apart by title: a single product would
			// be claimed by both and the product page could only deep-link
			// one of them. Leave those to the anchor / override.
			$needles = [];
			$sharers = []; // needle => [ med_id, ... ]
			foreach ( $catalog as $med_id => $name_generic ) {
				$needle = self::normalise_generic( $name_generic );
				$needles[ $med_id ] = $needle;
				if ( $needle !== '' ) $sharers[ $needle ][] = $med_id;
			}

			foreach ( array_keys( $unresolved ) as $med_id ) {
				$needle = $needles[ $med_id ];
				if ( $needle === '' ) continue;
				$matches = [];
				foreach ( $titles as $pid => $title ) {
					if ( self::is_prefix( $needle, $title ) ) $matches[] = $pid;
				}
				if ( ! $matches ) continue;

				if ( count( $sharers[ $needle ] ) > 1 ) {
					$others = array_values( array_diff( $sharers[ $needle ], [ $med_id ] ) );
					$auto[ $med_id ] = self::auto_entry( 0, 'fallback', 'shared_name', $matches, $others );
				} elseif ( count( $matches ) === 1 ) {
					$auto[ $med_id ] = self::auto_entry( $matches[0], 'title' );
				} else {
					$auto[ $med_id ] = self::auto_entry( 0, 'fallback', 'ambiguous_title', $matches );
				}
			}
		}

		// ── (a) overrides on top, then (e) fallback — build the entries ──
		$fallback_url = self::fallback_url();
		$links        = [];
		foreach ( $catalog as $med_id => $name_generic ) {
			$a     = isset( $auto[ $med_id ] ) ? $auto[ $med_id ] : self::auto_entry( 0, 'fallback' );
			$entry = [
				'product_id'       => 0,
				'url'              => $fallback_url,
				'name'             => $name_generic,
				'source'           => 'fallback',
				'in_stock'         => false,
				'auto_product_id'  => $a['product_id'],
				'auto_source'      => $a['source'],
				'auto_reason'      => $a['reason'],
				'auto_candidates'  => self::titles_for( $a['candidates'] ),
				'auto_shared_with' => array_map( function ( $id ) use ( $catalog ) { return $catalog[ $id ]; }, $a['shared_with'] ),
				'override_ignored' => false,
			];

			$override = absint( get_option( self::OPTION_PREFIX . $med_id, 0 ) );
			$chosen   = 0;
			$source   = 'fallback';
			if ( $override ) {
				// Only the pool the select offers: published products in
				// the Medications tree. Anything else (a hidden invoice
				// product, a deleted ID) is ignored, never linked.
				if ( self::is_override_candidate( $override ) ) {
					$chosen = $override;
					$source = 'manual';
				} else {
					$entry['override_ignored'] = true;
				}
			}
			if ( ! $chosen && $a['product_id'] ) {
				$chosen = (int) $a['product_id'];
				$source = $a['source'];
			}

			if ( $chosen ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $chosen ) : null;
				if ( $product instanceof WC_Product ) {
					$entry['product_id'] = $chosen;
					$entry['url']        = (string) $product->get_permalink();
					$entry['name']       = (string) $product->get_name();
					$entry['source']     = $source;
					$entry['in_stock']   = (bool) $product->is_in_stock();
				}
			}
			$links[ $med_id ] = $entry;
		}

		return $links;
	}

	/** Shape of one Auto result (see resolve()). */
	protected static function auto_entry( $product_id, $source, $reason = '', array $candidates = [], array $shared_with = [] ) {
		return [
			'product_id'  => (int) $product_id,
			'source'      => $source,
			'reason'      => $reason,
			'candidates'  => array_values( array_unique( array_map( 'intval', $candidates ) ) ),
			'shared_with' => $shared_with,
		];
	}

	/** product_id => title for a candidate list (stored so the admin can name them). */
	protected static function titles_for( array $ids ) {
		$out = [];
		foreach ( $ids as $pid ) {
			$post = get_post( $pid );
			$out[ (int) $pid ] = $post ? (string) $post->post_title : '#' . (int) $pid;
		}
		return $out;
	}

	/** Prefer an in-stock product, then the lowest ID. */
	protected static function pick_preferred( array $ids ) {
		$ids = array_map( 'intval', $ids );
		sort( $ids );
		if ( count( $ids ) === 1 || ! function_exists( 'wc_get_product' ) ) return $ids[0];
		foreach ( $ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( $product instanceof WC_Product && $product->is_in_stock() ) return $pid;
		}
		return $ids[0];
	}

	/** (b)–(d) never link amazon-mode products. */
	protected static function is_excluded_mode( $product_id ) {
		return function_exists( 'fishotel_med_get_mode' ) && fishotel_med_get_mode( $product_id ) === 'amazon';
	}

	protected static function is_published_product( $product_id ) {
		$post = get_post( (int) $product_id );
		return $post && $post->post_type === 'product' && $post->post_status === 'publish';
	}

	/** A manual override is valid only for a published Medications-tree product. */
	protected static function is_override_candidate( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! $product_id ) return false;
		$options = self::product_options();
		return isset( $options[ $product_id ] );
	}

	/**
	 * Parent product ID (variations resolve to their parent) for a SKU on a
	 * published product, else 0. wc_get_product_id_by_sku() excludes only
	 * trashed posts and caches per SKU.
	 */
	protected static function product_id_by_sku( $sku ) {
		$pid = (int) wc_get_product_id_by_sku( $sku );
		if ( ! $pid ) return 0;
		if ( get_post_type( $pid ) === 'product_variation' ) {
			$pid = (int) wp_get_post_parent_id( $pid );
		}
		return $pid && self::is_published_product( $pid ) ? $pid : 0;
	}

	// ─────────────────────────────────────────
	// REVERSE MAP (product → med_id)
	// ─────────────────────────────────────────

	/** product_id => med_id for every resolved (non-fallback) link. */
	public static function reverse_map() {
		$out = [];
		foreach ( self::links() as $med_id => $entry ) {
			if ( $entry['source'] === 'fallback' || empty( $entry['product_id'] ) ) continue;
			if ( ! isset( $out[ (int) $entry['product_id'] ] ) ) {
				$out[ (int) $entry['product_id'] ] = $med_id;
			}
		}
		return $out;
	}

	/**
	 * Calculator URL for a product page, or '' when nothing applies:
	 *   1. the reverse map            → calculator?med=<med_id>
	 *   2. an anchor that maps to a   → calculator?med=<med_id>
	 *      known med (e.g. an amazon-mode product the auto rules skip)
	 *   3. any other non-empty anchor → calculator + anchor verbatim
	 */
	public static function calculator_link_for_product( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! $product_id ) return '';

		$reverse = self::reverse_map();
		$base    = self::calculator_url();

		if ( isset( $reverse[ $product_id ] ) ) {
			return add_query_arg( 'med', $reverse[ $product_id ], $base );
		}

		$anchor = trim( (string) get_post_meta( $product_id, self::ANCHOR_META, true ) );
		if ( $anchor === '' ) return '';

		$med_id = self::med_id_for_anchor( self::normalise( $anchor ) );
		if ( $med_id !== '' ) {
			return add_query_arg( 'med', $med_id, $base );
		}
		if ( $anchor[0] !== '#' ) $anchor = '#' . $anchor;
		return $base . $anchor;
	}

	// ─────────────────────────────────────────
	// JS PAYLOAD
	// ─────────────────────────────────────────

	public static function payload_for_js() {
		$links = [];
		foreach ( self::links() as $med_id => $entry ) {
			$links[ $med_id ] = [
				'url'      => $entry['url'],
				'name'     => $entry['name'],
				'source'   => $entry['source'],
				'in_stock' => (bool) $entry['in_stock'],
			];
		}
		return [
			'links'          => $links,
			'fallback_url'   => self::fallback_url(),
			'calculator_url' => self::calculator_url(),
			'scheduler_url'  => self::scheduler_url(),
		];
	}

	// ─────────────────────────────────────────
	// CACHE
	// ─────────────────────────────────────────

	public static function flush_cache() {
		self::$memo = null;
		delete_transient( self::TRANSIENT );
	}

	public static function flush_cache_on_deleted_post( $post_id, $post = null ) {
		if ( $post && isset( $post->post_type ) && ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			return;
		}
		self::flush_cache();
	}

	public static function flush_cache_on_option( $option ) {
		$option   = (string) $option;
		$data_key = class_exists( 'FisHotel_Medication_Data' ) ? FisHotel_Medication_Data::OPTION_KEY : 'fishotel_medication_data';
		if ( strpos( $option, self::OPTION_PREFIX ) === 0 || $option === $data_key ) {
			self::flush_cache();
		}
	}

	// ─────────────────────────────────────────
	// ADMIN (FisHotel Settings → Product Page → Tool Links)
	// ─────────────────────────────────────────

	/**
	 * Option defaults for every key this module owns. The per-med keys are
	 * only enumerated on settings requests (see is_settings_request()):
	 * nothing else reads them through FisHotel_Admin_Settings::get(), and
	 * enumerating them costs a load of the medication dataset.
	 */
	public static function option_defaults() {
		$defaults = [ self::FALLBACK_OPTION => '' ];
		if ( ! self::is_settings_request() ) return $defaults;
		foreach ( array_keys( self::catalog() ) as $med_id ) {
			$defaults[ self::OPTION_PREFIX . $med_id ] = 0;
		}
		return $defaults;
	}

	/** Field definitions for the settings section (one select per med). */
	public static function settings_fields() {
		$fields = [
			self::FALLBACK_OPTION => [
				'label'       => 'Fallback shop URL',
				'type'        => 'url',
				'placeholder' => 'Leave blank for the Medications category',
				'description' => 'How these links work: each medication below is set to Auto, which picks the published product whose Dosing calculator anchor (Products → edit → FisHotel Medication tab) matches the med id, else a matching SKU, else a unique title match. Choose a product in a row only to override Auto. Amazon-mode products are skipped by Auto but allowed as an override. This URL is where "Browse medications" links go when a medication has no product: absolute or site-relative (e.g. /product-category/medications/); blank = the Medications category.',
			],
		];
		if ( ! self::is_settings_request() ) return $fields;
		foreach ( self::catalog() as $med_id => $name_generic ) {
			$fields[ self::OPTION_PREFIX . $med_id ] = [
				'label'  => esc_html( $name_generic ),
				'type'   => 'product_select',
				'med_id' => $med_id,
			];
		}
		return $fields;
	}

	/** '— Auto —' plus every published product in the Medications tree. */
	public static function product_options() {
		if ( self::$product_options !== null ) return self::$product_options;

		$slug  = defined( 'FISHOTEL_MED_PARENT_SLUG' ) ? FISHOTEL_MED_PARENT_SLUG : 'medications';
		$posts = get_posts( [
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => [ [
				'taxonomy'         => 'product_cat',
				'field'            => 'slug',
				'terms'            => $slug,
				'include_children' => true,
			] ],
		] );

		$options = [ 0 => '— Auto —' ];
		foreach ( (array) $posts as $post ) {
			$options[ (int) $post->ID ] = $post->post_title . ' #' . $post->ID;
		}
		self::$product_options = $options;
		return $options;
	}

	/** Render one med's override select + the read-only resolved status. */
	public static function render_product_select( $key, array $args ) {
		$med_id  = isset( $args['med_id'] ) ? (string) $args['med_id'] : '';
		$current = absint( get_option( $key, 0 ) );
		$options = self::product_options();

		// A stale override (product deleted / unpublished / outside the
		// Medications tree) must stay visible so Jeff can see it and reset
		// it to Auto.
		if ( $current && ! isset( $options[ $current ] ) ) {
			$post = get_post( $current );
			$options[ $current ] = ( $post ? $post->post_title : 'Unknown product' ) . ' #' . $current . ' (not a published Medications product)';
		}

		echo '<select name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current, (int) $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		$links = self::links();
		$entry = isset( $links[ $med_id ] ) ? $links[ $med_id ] : null;
		if ( ! $entry ) return;

		$status = self::auto_status_text( $med_id, $entry );
		if ( $entry['source'] === 'manual' ) {
			$status .= ' — override active: ' . $entry['name'];
		} elseif ( ! empty( $entry['override_ignored'] ) ) {
			$status .= ' — override ignored (not a published Medications product)';
		}
		if ( $entry['product_id'] && ! $entry['in_stock'] ) {
			$status .= ' · out of stock';
		}
		echo ' <span class="description fh-tool-link-status" style="margin-left:8px;">' . esc_html( $status ) . '</span>';
	}

	/** The "Auto → …" sentence for one entry, naming ambiguous candidates. */
	protected static function auto_status_text( $med_id, array $entry ) {
		$candidates = isset( $entry['auto_candidates'] ) && is_array( $entry['auto_candidates'] ) ? $entry['auto_candidates'] : [];
		$reason     = isset( $entry['auto_reason'] ) ? (string) $entry['auto_reason'] : '';
		$names      = implode( ', ', $candidates );

		if ( ! empty( $entry['auto_product_id'] ) ) {
			$auto_post = get_post( $entry['auto_product_id'] );
			$status    = sprintf(
				'Auto → %s (matched by %s)',
				$auto_post ? $auto_post->post_title : '#' . $entry['auto_product_id'],
				$entry['auto_source']
			);
			if ( $reason === 'multi_anchor' && count( $candidates ) > 1 ) {
				$status .= sprintf( '; %d products carry this anchor (%s) — in-stock first, then the lowest ID', count( $candidates ), $names );
			}
			return $status;
		}

		switch ( $reason ) {
			case 'ambiguous_anchor':
				return sprintf( 'Auto → %d products match by anchor prefix (%s); pick one above or give the right one the exact anchor #%s', count( $candidates ), $names, $med_id );
			case 'ambiguous_title':
				return sprintf( 'Auto → %d products match by title (%s); pick one above or set the dosing anchor #%s on the right product', count( $candidates ), $names, $med_id );
			case 'shared_name':
				$shared = ! empty( $entry['auto_shared_with'] ) ? implode( ', ', (array) $entry['auto_shared_with'] ) : 'another medication';
				return sprintf( 'Auto → title match skipped: this name is shared with %s (found %s); set the dosing anchor #%s on the right product or pick one above', $shared, $names, $med_id );
		}
		return 'Auto → no product yet; links fall back to the Medications shop';
	}
}

FisHotel_Tool_Product_Links::init();

// ─────────────────────────────────────────
// PUBLIC API (procedural wrappers used by the enqueues, templates and admin)
// ─────────────────────────────────────────

/** med_id => name_generic for every medication in the seed. */
function fishotel_tool_med_catalog() {
	return FisHotel_Tool_Product_Links::catalog();
}

/**
 * med_id => { product_id, url, name, source, in_stock } with source one of
 * manual | anchor | sku | title | fallback. Cached; see the file docblock.
 */
function fishotel_tool_resolve_med_links() {
	$out = [];
	foreach ( FisHotel_Tool_Product_Links::links() as $med_id => $entry ) {
		$out[ $med_id ] = [
			'product_id' => (int) $entry['product_id'],
			'url'        => $entry['url'],
			'name'       => $entry['name'],
			'source'     => $entry['source'],
			'in_stock'   => (bool) $entry['in_stock'],
		];
	}
	return $out;
}

/** The FISHOTEL_MED_LINKS payload for wp_localize_script(). */
function fishotel_tool_links_for_js() {
	return FisHotel_Tool_Product_Links::payload_for_js();
}

/** product_id => med_id reverse map (resolved links only). */
function fishotel_tool_reverse_med_map() {
	return FisHotel_Tool_Product_Links::reverse_map();
}

/** Calculator deep link for a product page ('' when nothing applies). */
function fishotel_tool_calculator_link_for_product( $product_id ) {
	return FisHotel_Tool_Product_Links::calculator_link_for_product( $product_id );
}

/** Defaults for the admin settings framework (merged in defaults()). */
function fishotel_tool_link_defaults() {
	return FisHotel_Tool_Product_Links::option_defaults();
}

/** Field definitions for the admin settings framework (sections()). */
function fishotel_tool_link_settings_fields() {
	return FisHotel_Tool_Product_Links::settings_fields();
}
