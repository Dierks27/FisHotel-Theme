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
 * safe fallback, in this order (first HIT wins; a non-unique match is not a
 * hit and resolution falls through to the next rule):
 *
 *   (a) manual override  — option fh_tool_link_<med_id>: int product ID,
 *                          0 = auto, -1 = none (hide the link). A product
 *                          must be published and inside the Medications
 *                          category tree — the pool the admin select offers,
 *                          deliberately narrower than "any product" so a
 *                          hidden invoice product can never be linked.
 *   (b) dosing anchor    — a published product whose _fishotel_dosing_anchor,
 *                          normalised (#praziquantel-powder → praziquantel_powder),
 *                          equals the med_id, else where the med_id is a
 *                          plain prefix of it (longest med_id wins). A prefix
 *                          hit must be unique across products; otherwise the
 *                          ambiguity is recorded for the admin and (c)/(d)
 *                          still run.
 *   (c) SKU              — one lookup over a small per-med candidate list
 *                          (the five SKUs the scheduler used to guess, plus
 *                          med_id variants). The live catalog has no SKUs,
 *                          so this is a harmless step kept for the future.
 *   (d) title            — published products in the Medications category
 *                          tree whose normalised title starts with one of
 *                          the med's title aliases (title_aliases(), else
 *                          the normalised generic name). Several products
 *                          may match (Praziquantel Powder / Flake / Pellet);
 *                          they are RANKED, not rejected: bath form first
 *                          by product_cat slug (powders > liquid > flakes >
 *                          pellets > anything else), then the shortest
 *                          normalised title, then the lowest ID.
 *   (e) fallback         — the Medications category archive (or the shop).
 *
 * Meds in the seed category 'protocol' (Hyposalinity) are hidden by default:
 * there is nothing to buy, so the tools render the name as plain text unless
 * Jeff picks a product for them.
 *
 * Amazon-mode products ARE eligible for (b)–(d): the product page is where
 * the Buy-on-Amazon button lives. Each entry exposes 'mode' (ea|amazon|self)
 * so the tools can say "View <name>" instead of "Shop <name>".
 *
 * Product page → calculator: a product that resolves to a med links to
 * ?med=<med_id>; a product whose anchor maps to a known med also links to
 * ?med=<med_id> rather than appending the anchor verbatim, because the
 * calculator reads the hash as a category tab, not a med; only an anchor
 * that maps to no med is appended verbatim.
 *
 * Caching: the map is stored in a transient for 12 hours as product IDs
 * (never permalinks — a production DB copied to staging must not serve
 * fishotel.com links); URLs are computed at read time. It is cleared when a
 * medication product (or a product currently in the map) is saved, stocked,
 * trashed or deleted, when an fh_tool_link_* option or the medication
 * dataset changes, and when one of the two tool pages is saved. The ~90 KB
 * medication dataset option is only loaded where it is needed: the two tool
 * pages, a medication product page (reverse lookup), options.php saves and
 * the FisHotel Settings → Product Page screen.
 *
 * Tool page URLs resolve by template first (one published page whose
 * _wp_page_template is page-quarantine-help.php / page-qt-scheduler.php;
 * the page ID is cached in the same transient), then by slug
 * (medication-dosing, then the legacy quarantine-help; qt-scheduler), then
 * home_url('/medication-dosing/').
 *
 * The title-matching rules are pure static methods (no WordPress calls) so
 * tests/tool-product-links-test.php can assert them against the live
 * catalog with plain `php`.
 *
 * Depends on FisHotel_Medication_Data (inc/calculator) for the med catalog
 * and on inc/medication-store/helpers.php for fishotel_med_get_mode() and
 * fishotel_med_is_med_product().
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Tool_Product_Links {

	const TRANSIENT       = 'fishotel_tool_med_links_v3';
	const TTL             = 12 * HOUR_IN_SECONDS;
	const OPTION_PREFIX   = 'fh_tool_link_';
	const FALLBACK_OPTION = 'fh_tool_link_fallback_url';
	const ANCHOR_META     = '_fishotel_dosing_anchor';
	const NONE            = -1;   // override value: hide the link
	const SETTINGS_PAGE   = 'fishotel-product';

	const CALCULATOR_TEMPLATE = 'page-quarantine-help.php';
	const SCHEDULER_TEMPLATE  = 'page-qt-scheduler.php';

	/** Per-request memo of the cached payload: [ links (raw), pages, built ]. */
	protected static $cache = null;

	/** Per-request memo of the hydrated map (raw + computed URLs). */
	protected static $links = null;

	/** Per-request memo of the published Medications-tree products. */
	protected static $tree = null;

	/** Per-request memo of the tool page IDs. */
	protected static $page_ids = [];

	public static function init() {
		// Cache invalidation — products. Every callback resolves the product
		// (int or WC_Product, variation → parent) and only flushes when it is
		// a medication product or one already in the cached map, so fish
		// sales and food stock syncs leave the 12-hour cache alone.
		add_action( 'save_post_product',           [ __CLASS__, 'flush_for_product' ] );
		add_action( 'woocommerce_new_product',     [ __CLASS__, 'flush_for_product' ] );
		add_action( 'woocommerce_update_product',  [ __CLASS__, 'flush_for_product' ] );
		add_action( 'woocommerce_trash_product',   [ __CLASS__, 'flush_for_product' ] );
		add_action( 'trashed_post',                [ __CLASS__, 'flush_for_product' ] );
		add_action( 'untrashed_post',              [ __CLASS__, 'flush_for_product' ] );
		// Stock changes don't always pass through woocommerce_update_product
		// (wc_update_product_stock writes straight to the data store), and
		// in_stock is part of the cached payload.
		add_action( 'woocommerce_product_set_stock',            [ __CLASS__, 'flush_for_product' ] );
		add_action( 'woocommerce_variation_set_stock',          [ __CLASS__, 'flush_for_product' ] );
		add_action( 'woocommerce_product_set_stock_status',     [ __CLASS__, 'flush_for_product' ] );
		add_action( 'woocommerce_variation_set_stock_status',   [ __CLASS__, 'flush_for_product' ] );
		// A deleted product has already lost its terms, so the med check
		// cannot run; deletion is rare, flush unconditionally for products.
		add_action( 'woocommerce_delete_product',  [ __CLASS__, 'flush_cache' ] );
		add_action( 'deleted_post',                [ __CLASS__, 'flush_cache_on_deleted_post' ], 10, 2 );
		// The two tool pages (their IDs are cached).
		add_action( 'save_post_page',              [ __CLASS__, 'flush_for_page' ] );
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

	/** med_id => [ name, category ] from the seed (via the stored option). */
	public static function catalog_rows() {
		static $rows = null;
		if ( $rows !== null ) return $rows;

		$rows = [];
		if ( ! class_exists( 'FisHotel_Medication_Data' ) ) return $rows;

		$data = FisHotel_Medication_Data::get_all();
		if ( empty( $data['medications'] ) || ! is_array( $data['medications'] ) ) return $rows;

		foreach ( $data['medications'] as $med ) {
			if ( empty( $med['med_id'] ) ) continue;
			$med_id = (string) $med['med_id'];
			$rows[ $med_id ] = [
				'name'     => isset( $med['name_generic'] ) ? (string) $med['name_generic'] : $med_id,
				'category' => isset( $med['category'] ) ? (string) $med['category'] : '',
			];
		}
		return $rows;
	}

	/** med_id => name_generic from the seed. */
	public static function catalog() {
		$out = [];
		foreach ( self::catalog_rows() as $med_id => $row ) {
			$out[ $med_id ] = $row['name'];
		}
		return $out;
	}

	/** Seed categories whose meds have nothing to buy (link hidden by default). */
	public static function hidden_categories() {
		return [ 'protocol' ];
	}

	/**
	 * True only on the two admin requests that render or save the settings
	 * screen: an options.php save and the Product Page subpage. The per-med
	 * option keys are only enumerated there, so every other admin screen
	 * (Orders, product edit…) and every front-end request that calls
	 * FisHotel_Admin_Settings::defaults() never loads the ~90 KB medication
	 * dataset.
	 */
	protected static function is_settings_request() {
		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) return false;
		global $pagenow;
		if ( isset( $pagenow ) && $pagenow === 'options.php' ) return true;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $page === self::SETTINGS_PAGE;
	}

	// ─────────────────────────────────────────
	// NORMALISATION (pure — no WordPress calls)
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
	public static function is_prefix( $needle, $haystack, $word_boundary = true ) {
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
	public static function med_id_for_anchor( $normalised_anchor, ?array $med_ids = null ) {
		if ( $med_ids === null ) $med_ids = array_keys( self::catalog_rows() );
		if ( $normalised_anchor === '' ) return '';
		if ( in_array( $normalised_anchor, $med_ids, true ) ) return $normalised_anchor;

		$best = '';
		foreach ( $med_ids as $med_id ) {
			if ( self::is_prefix( $med_id, $normalised_anchor, false ) && strlen( $med_id ) > strlen( $best ) ) {
				$best = $med_id;
			}
		}
		return $best;
	}

	// ─────────────────────────────────────────
	// TITLE MATCHING (pure — no WordPress calls; see tests/)
	// ─────────────────────────────────────────

	/**
	 * Title prefixes per med_id, checked instead of the generic name. Each
	 * is normalised before use ('methylene blue' → 'methylene_blue'). An
	 * empty list means the med never auto-links by title.
	 */
	public static function title_aliases() {
		return [
			'praziquantel'                => [ 'praziquantel' ],
			'metronidazole'               => [ 'metronidazole' ],
			'chloroquine_phosphate'       => [ 'chloroquine' ],
			'methylene_blue'              => [ 'methylene blue' ],
			'copper_power'                => [ 'copper power' ],
			'fenbendazole'                => [ 'fenbendazole' ],
			'fenbendazole_internal'       => [ 'fenbendazole' ],
			'enrofloxacin'                => [ 'enrofloxacin' ],
			'kanamycin_sulfate'           => [ 'kanamycin' ],
			'nitrofurazone'               => [ 'nitrofurazone' ],
			'nitrofuracin_green'          => [ 'nitrofuracin green' ],
			'sulfa_tmp'                   => [ 'tmp sulfa', 'sulfa tmp' ],
			'erythromycin'                => [ 'erythromycin' ],
			'formalin_37'                 => [ 'formalin' ],
			'sulfa_drugs'                 => [ 'sulfa' ],
			'acriflavine'                 => [ 'acriflavine' ],
			'hydrogen_peroxide_3'         => [ 'hydrogen peroxide' ],
			'cupramine'                   => [ 'cupramine' ],
			'cuprion'                     => [ 'cuprion' ],
			'coppersafe'                  => [ 'coppersafe', 'copper aid' ],
			'copper_sulfate_pentahydrate' => [ 'copper sulfate' ],
			'ciprofloxacin'               => [ 'ciprofloxacin' ],
			'neomycin_sulfate'            => [ 'neomycin' ],
			'minocycline'                 => [ 'minocycline' ],
			'hyposalinity'                => [],
		];
	}

	/** Normalised title prefixes for one med: its aliases, else its generic name. */
	public static function title_needles( $med_id, $name_generic ) {
		$aliases = self::title_aliases();
		if ( isset( $aliases[ $med_id ] ) ) {
			$needles = array_map( [ __CLASS__, 'normalise' ], $aliases[ $med_id ] );
		} else {
			$needles = [ self::normalise_generic( $name_generic ) ];
		}
		return array_values( array_filter( array_unique( $needles ), 'strlen' ) );
	}

	/** Form rank from product_cat slugs: powders 0 > liquid 1 > flakes 2 > pellets 3 > other 4. */
	public static function form_priority( array $cat_slugs ) {
		$order = [ 'powders' => 0, 'liquid' => 1, 'flakes' => 2, 'pellets' => 3 ];
		$best  = 4;
		foreach ( $cat_slugs as $slug ) {
			if ( isset( $order[ $slug ] ) && $order[ $slug ] < $best ) $best = $order[ $slug ];
		}
		return $best;
	}

	/** The form slug a product carries ('' when none). */
	public static function form_slug( array $cat_slugs ) {
		foreach ( [ 'powders', 'liquid', 'flakes', 'pellets' ] as $form ) {
			if ( in_array( $form, $cat_slugs, true ) ) return $form;
		}
		return '';
	}

	/**
	 * Product IDs whose normalised title starts with one of $needles, best
	 * first: form priority, then shortest normalised title, then lowest ID.
	 *
	 * @param string[] $needles normalised prefixes
	 * @param array    $rows    product_id => [ 'title' => raw, 'norm' => normalised (optional), 'cats' => [ slugs ] ]
	 * @return int[]
	 */
	public static function rank_title_candidates( array $needles, array $rows ) {
		if ( ! $needles ) return [];
		$hits = [];
		foreach ( $rows as $pid => $row ) {
			$title = isset( $row['norm'] ) ? (string) $row['norm'] : self::normalise( isset( $row['title'] ) ? $row['title'] : '' );
			foreach ( $needles as $needle ) {
				if ( self::is_prefix( $needle, $title ) ) {
					$cats   = isset( $row['cats'] ) && is_array( $row['cats'] ) ? $row['cats'] : [];
					$hits[] = [ (int) $pid, self::form_priority( $cats ), strlen( $title ) ];
					break;
				}
			}
		}
		usort( $hits, function ( $a, $b ) {
			return [ $a[1], $a[2], $a[0] ] <=> [ $b[1], $b[2], $b[0] ];
		} );
		return array_map( function ( $hit ) { return $hit[0]; }, $hits );
	}

	/**
	 * Rule (d) for a whole catalog: med_id => best product ID (0 = none).
	 *
	 * @param array $catalog med_id => name_generic
	 * @param array $rows    see rank_title_candidates()
	 */
	public static function resolve_titles( array $catalog, array $rows ) {
		$out = [];
		foreach ( $catalog as $med_id => $name_generic ) {
			$ranked         = self::rank_title_candidates( self::title_needles( $med_id, $name_generic ), $rows );
			$out[ $med_id ] = $ranked ? $ranked[0] : 0;
		}
		return $out;
	}

	// ─────────────────────────────────────────
	// URLS
	// ─────────────────────────────────────────

	/** Published page ID by template meta, then by slug(s); 0 when none. */
	protected static function lookup_page_id( $template, array $slugs ) {
		$found = get_posts( [
			'post_type'              => 'page',
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'meta_key'               => '_wp_page_template',
			'meta_value'             => $template,
		] );
		if ( ! empty( $found ) ) return (int) $found[0];

		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && $page->post_status === 'publish' ) return (int) $page->ID;
		}
		return 0;
	}

	/** Tool page ID from the per-request memo, the transient, else a fresh lookup. */
	protected static function page_id( $which, $template, array $slugs ) {
		if ( isset( self::$page_ids[ $which ] ) ) return self::$page_ids[ $which ];

		$cache = self::cache();
		$id    = isset( $cache['pages'][ $which ] ) ? (int) $cache['pages'][ $which ] : 0;
		if ( $id && get_post_status( $id ) !== 'publish' ) $id = 0;
		if ( ! $id ) $id = self::lookup_page_id( $template, $slugs );

		self::$page_ids[ $which ] = $id;
		return $id;
	}

	protected static function tool_page_url( $which, $template, array $slugs ) {
		$id = self::page_id( $which, $template, $slugs );
		if ( $id ) {
			$link = get_permalink( $id );
			if ( $link ) return $link;
		}
		return home_url( '/' . $slugs[0] . '/' );
	}

	/** Medication Dosing calculator: template, then /medication-dosing/, then the legacy /quarantine-help/. */
	public static function calculator_url() {
		return self::tool_page_url( 'calculator', self::CALCULATOR_TEMPLATE, [ 'medication-dosing', 'quarantine-help' ] );
	}

	public static function scheduler_url() {
		return self::tool_page_url( 'scheduler', self::SCHEDULER_TEMPLATE, [ 'qt-scheduler' ] );
	}

	/**
	 * Sanitize the fallback URL option: trim; accept http(s)://, //host and
	 * site-relative paths, prefixing '/' when a bare path was typed so
	 * esc_url_raw() cannot turn 'product-category/medications' into
	 * http://product-category/medications.
	 */
	public static function sanitize_fallback_url( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) return '';
		if ( ! preg_match( '#^(https?:)?//#i', $value ) && $value[0] !== '/' ) {
			$value = '/' . $value;
		}
		return (string) esc_url_raw( $value );
	}

	/** Fallback URL: the option, else the Medications archive, else the shop. */
	public static function fallback_url() {
		$custom = self::sanitize_fallback_url( get_option( self::FALLBACK_OPTION, '' ) );
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
	 * Cached payload (per request, then transient). The links inside are
	 * RAW: product IDs and names, no URLs. See links() for hydration.
	 */
	protected static function cache( $force = false ) {
		if ( self::$cache !== null && ! $force ) return self::$cache;

		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) && isset( $cached['links'] ) && is_array( $cached['links'] ) ) {
				self::$cache = $cached;
				return self::$cache;
			}
		}

		$links = self::resolve();
		self::$cache = [
			'links' => $links,
			'pages' => [
				'calculator' => self::lookup_page_id( self::CALCULATOR_TEMPLATE, [ 'medication-dosing', 'quarantine-help' ] ),
				'scheduler'  => self::lookup_page_id( self::SCHEDULER_TEMPLATE, [ 'qt-scheduler' ] ),
			],
			'built' => time(),
		];
		// An empty map means the medication dataset was missing (fresh
		// staging copy, mid-reseed). Don't pin that for 12 hours.
		if ( $links ) {
			set_transient( self::TRANSIENT, self::$cache, self::TTL );
		}
		return self::$cache;
	}

	/**
	 * Resolved map with URLs computed for THIS host at read time.
	 *
	 * @return array med_id => [ product_id, url, name, source, in_stock,
	 *                           mode, form, hidden, auto_product_id,
	 *                           auto_source, auto_reason, auto_candidates,
	 *                           override_ignored ]
	 */
	public static function links( $force = false ) {
		if ( self::$links !== null && ! $force ) return self::$links;

		$cache = self::cache( $force );
		$raw   = $cache['links'];

		$ids = [];
		foreach ( $raw as $entry ) {
			if ( ! empty( $entry['product_id'] ) ) $ids[] = (int) $entry['product_id'];
		}
		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_unique( $ids ), true, false ); // posts + terms (product_cat is in the permalink)
		}

		$fallback_url = $raw ? self::fallback_url() : '';
		$links        = [];
		foreach ( $raw as $med_id => $entry ) {
			$url = '';
			if ( empty( $entry['hidden'] ) ) {
				if ( ! empty( $entry['product_id'] ) ) {
					$url = (string) get_permalink( (int) $entry['product_id'] );
				}
				if ( $url === '' ) $url = $fallback_url;
			}
			$entry['url']      = $url;
			$links[ $med_id ]  = $entry;
		}
		self::$links = $links;
		return self::$links;
	}

	/**
	 * Build the raw map from scratch: one meta_query (anchors), one SKU
	 * lookup, one tax_query (Medications-tree products, reused for the
	 * title rule, override validation and the admin select).
	 */
	protected static function resolve() {
		$rows = self::catalog_rows();
		if ( empty( $rows ) ) return [];
		$catalog = self::catalog();
		$med_ids = array_keys( $rows );

		$auto    = []; // med_id => auto_entry, only real hits (product_id > 0)
		$reasons = []; // med_id => [ reason, candidates ] for a rule that looked but could not settle

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
				$norm = self::normalise( get_post_meta( $pid, self::ANCHOR_META, true ) );
				if ( $norm === '' ) continue;
				if ( isset( $rows[ $norm ] ) ) {
					$exact[ $norm ][] = $pid;
					continue;
				}
				$med_id = self::med_id_for_anchor( $norm, $med_ids );
				if ( $med_id !== '' ) $prefix[ $med_id ][] = $pid;
			}
			foreach ( $med_ids as $med_id ) {
				if ( ! empty( $exact[ $med_id ] ) ) {
					// Exact anchors are explicit intent; when several carry
					// the same one, prefer in-stock, then the lowest ID.
					$auto[ $med_id ] = self::auto_entry( self::pick_preferred( $exact[ $med_id ] ), 'anchor', count( $exact[ $med_id ] ) > 1 ? 'multi_anchor' : '', $exact[ $med_id ] );
				} elseif ( ! empty( $prefix[ $med_id ] ) ) {
					if ( count( $prefix[ $med_id ] ) === 1 ) {
						$auto[ $med_id ] = self::auto_entry( (int) $prefix[ $med_id ][0], 'anchor' );
					} else {
						// Not a hit: remember why, let (c) and (d) try.
						$reasons[ $med_id ] = [ 'ambiguous_anchor', $prefix[ $med_id ] ];
					}
				}
			}
		}

		// ── (c) SKUs: one lookup over every candidate of every unresolved med ──
		$unresolved = array_diff_key( $rows, $auto );
		if ( $unresolved ) {
			$wanted = [];
			foreach ( array_keys( $unresolved ) as $med_id ) {
				$wanted[ $med_id ] = self::sku_candidates( $med_id );
			}
			$by_sku = self::products_by_sku( array_merge( ...array_values( $wanted ) ) );
			if ( $by_sku ) {
				foreach ( $wanted as $med_id => $candidates ) {
					foreach ( $candidates as $sku ) {
						if ( empty( $by_sku[ $sku ] ) ) continue;
						$pid = self::parent_product_id( $by_sku[ $sku ] );
						if ( ! $pid || ! self::is_published_product( $pid ) ) continue;
						$auto[ $med_id ] = self::auto_entry( $pid, 'sku' );
						break;
					}
				}
			}
		}

		// ── (d) titles: ranked over the Medications category tree ───────
		$tree       = self::tree_products();
		$unresolved = array_diff_key( $rows, $auto );
		if ( $unresolved && $tree ) {
			foreach ( $unresolved as $med_id => $row ) {
				$ranked = self::rank_title_candidates( self::title_needles( $med_id, $row['name'] ), $tree );
				if ( ! $ranked ) continue;
				$auto[ $med_id ] = self::auto_entry( $ranked[0], 'title', count( $ranked ) > 1 ? 'ranked_title' : '', $ranked );
			}
		}

		// ── (a) overrides on top, protocol meds hidden, (e) fallback ────
		$hidden_cats = self::hidden_categories();
		$links       = [];
		foreach ( $rows as $med_id => $row ) {
			$a = isset( $auto[ $med_id ] ) ? $auto[ $med_id ] : self::auto_entry( 0, 'fallback' );
			if ( ! $a['product_id'] && isset( $reasons[ $med_id ] ) ) {
				$a['reason']     = $reasons[ $med_id ][0];
				$a['candidates'] = array_values( array_unique( array_map( 'intval', $reasons[ $med_id ][1] ) ) );
			} elseif ( $a['product_id'] && isset( $reasons[ $med_id ] ) && $a['reason'] === '' ) {
				// Resolved by a later rule after an ambiguous anchor: keep
				// the note so the admin can see the anchor was skipped.
				$a['reason']     = 'skipped_anchor';
				$a['candidates'] = array_values( array_unique( array_map( 'intval', $reasons[ $med_id ][1] ) ) );
			}

			$entry = [
				'product_id'       => 0,
				'name'             => $row['name'],
				'source'           => 'fallback',
				'in_stock'         => false,
				'mode'             => '',
				'form'             => '',
				'hidden'           => false,
				'auto_product_id'  => $a['product_id'],
				'auto_source'      => $a['source'],
				'auto_reason'      => $a['reason'],
				'auto_candidates'  => self::titles_for( $a['candidates'], $tree ),
				'override_ignored' => false,
			];

			$override = (int) get_option( self::OPTION_PREFIX . $med_id, 0 );
			$chosen   = 0;
			$source   = 'fallback';
			if ( $override === self::NONE ) {
				$entry['hidden'] = true;
				$source          = 'none';
			} elseif ( $override > 0 ) {
				// Only the pool the select offers: published products in
				// the Medications tree. Anything else (a hidden invoice
				// product, a deleted ID) is ignored, never linked.
				if ( isset( $tree[ $override ] ) ) {
					$chosen = $override;
					$source = 'manual';
				} else {
					$entry['override_ignored'] = true;
				}
			}
			if ( ! $chosen && ! $entry['hidden'] ) {
				if ( in_array( $row['category'], $hidden_cats, true ) ) {
					$entry['hidden'] = true;
					$source          = 'none';
				} elseif ( $a['product_id'] ) {
					$chosen = (int) $a['product_id'];
					$source = $a['source'];
				}
			}

			if ( $chosen ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $chosen ) : null;
				if ( $product instanceof WC_Product ) {
					$entry['product_id'] = $chosen;
					$entry['name']       = (string) $product->get_name();
					$entry['source']     = $source;
					$entry['in_stock']   = (bool) $product->is_in_stock();
					$entry['mode']       = function_exists( 'fishotel_med_get_mode' ) ? fishotel_med_get_mode( $chosen ) : '';
					$entry['form']       = isset( $tree[ $chosen ] ) ? self::form_slug( $tree[ $chosen ]['cats'] ) : '';
				}
			} elseif ( $entry['hidden'] ) {
				$entry['source'] = 'none';
			}
			$links[ $med_id ] = $entry;
		}

		return $links;
	}

	/** Shape of one Auto result (see resolve()). */
	protected static function auto_entry( $product_id, $source, $reason = '', array $candidates = [] ) {
		return [
			'product_id' => (int) $product_id,
			'source'     => $source,
			'reason'     => $reason,
			'candidates' => array_values( array_unique( array_map( 'intval', $candidates ) ) ),
		];
	}

	/** product_id => title for a candidate list (stored so the admin can name them). */
	protected static function titles_for( array $ids, array $tree = [] ) {
		$out = [];
		foreach ( $ids as $pid ) {
			$pid = (int) $pid;
			if ( isset( $tree[ $pid ] ) ) {
				$out[ $pid ] = (string) $tree[ $pid ]['title'];
				continue;
			}
			$post        = get_post( $pid );
			$out[ $pid ] = $post ? (string) $post->post_title : '#' . $pid;
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

	protected static function is_published_product( $product_id ) {
		$post = get_post( (int) $product_id );
		return $post && $post->post_type === 'product' && $post->post_status === 'publish';
	}

	/** Variations resolve to their parent product ID. */
	protected static function parent_product_id( $product_id ) {
		$pid = (int) $product_id;
		if ( $pid && get_post_type( $pid ) === 'product_variation' ) {
			$pid = (int) wp_get_post_parent_id( $pid );
		}
		return $pid;
	}

	/**
	 * sku => post ID (product or variation, any status) for a list of SKUs
	 * in ONE query against WooCommerce's product meta lookup table. Falls
	 * back to wc_get_product_id_by_sku() per SKU when the table is absent.
	 */
	protected static function products_by_sku( array $skus ) {
		global $wpdb;
		$skus = array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'strval', $skus ) ), 'strlen' ) ) );
		if ( ! $skus ) return [];

		$out = [];
		if ( isset( $wpdb->wc_product_meta_lookup ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $skus ), '%s' ) );
			$results      = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, sku FROM {$wpdb->wc_product_meta_lookup} WHERE sku IN ($placeholders)", $skus ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $results as $r ) {
				$sku = strtolower( (string) $r->sku );
				if ( $sku !== '' && ! isset( $out[ $sku ] ) ) $out[ $sku ] = (int) $r->product_id;
			}
		} elseif ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			foreach ( $skus as $sku ) {
				$pid = (int) wc_get_product_id_by_sku( $sku );
				if ( $pid ) $out[ $sku ] = $pid;
			}
		}
		return $out;
	}

	/**
	 * Published products in the Medications category tree, one query:
	 * product_id => [ title, norm, cats ]. Shared by the title rule,
	 * override validation and the admin select.
	 */
	protected static function tree_products() {
		if ( self::$tree !== null ) return self::$tree;

		$slug  = defined( 'FISHOTEL_MED_PARENT_SLUG' ) ? FISHOTEL_MED_PARENT_SLUG : 'medications';
		$posts = get_posts( [
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,  // fulfillment mode, in one query
			'update_post_term_cache' => true,  // product_cat slugs + product type, in one query
			'tax_query'              => [ [
				'taxonomy'         => 'product_cat',
				'field'            => 'slug',
				'terms'            => $slug,
				'include_children' => true,
			] ],
		] );

		$tree = [];
		foreach ( (array) $posts as $post ) {
			$cats  = [];
			$terms = get_the_terms( $post->ID, 'product_cat' );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) $cats[] = (string) $term->slug;
			}
			$tree[ (int) $post->ID ] = [
				'title' => (string) $post->post_title,
				'norm'  => self::normalise( $post->post_title ),
				'cats'  => $cats,
			];
		}
		self::$tree = $tree;
		return $tree;
	}

	// ─────────────────────────────────────────
	// REVERSE MAP (product → med_id)
	// ─────────────────────────────────────────

	/** product_id => med_id for every resolved (non-fallback, non-hidden) link. */
	public static function reverse_map() {
		$out = [];
		foreach ( self::links() as $med_id => $entry ) {
			if ( in_array( $entry['source'], [ 'fallback', 'none' ], true ) || empty( $entry['product_id'] ) ) continue;
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
	 *      known med
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
				'mode'     => (string) $entry['mode'],
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
		self::$cache    = null;
		self::$links    = null;
		self::$tree     = null;
		self::$page_ids = [];
		delete_transient( self::TRANSIENT );
	}

	/** Product hooks: flush only for a medication product or one in the cached map. */
	public static function flush_for_product( $product ) {
		$id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) $product;
		if ( $id && self::is_relevant_product( $id ) ) {
			self::flush_cache();
		}
	}

	protected static function is_relevant_product( $id ) {
		$type = get_post_type( $id );
		if ( $type === 'product_variation' ) {
			$id = (int) wp_get_post_parent_id( $id );
			if ( ! $id ) return false;
		} elseif ( $type !== 'product' ) {
			return false;
		}
		if ( function_exists( 'fishotel_med_is_med_product' ) && fishotel_med_is_med_product( $id ) ) return true;

		// A product moved OUT of the Medications tree is no longer a med
		// product but may still be in the map: check the cache cheaply.
		$cached = self::$cache !== null ? self::$cache : get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached['links'] ) && is_array( $cached['links'] ) ) {
			foreach ( $cached['links'] as $entry ) {
				if ( isset( $entry['product_id'] ) && (int) $entry['product_id'] === $id ) return true;
			}
		}
		return false;
	}

	/** Tool page saved (template or ID we cache): flush so the URL follows. */
	public static function flush_for_page( $post_id ) {
		$post_id = (int) $post_id;
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;

		$template = (string) get_page_template_slug( $post_id );
		if ( in_array( $template, [ self::CALCULATOR_TEMPLATE, self::SCHEDULER_TEMPLATE ], true ) ) {
			self::flush_cache();
			return;
		}
		$cached = self::$cache !== null ? self::$cache : get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached['pages'] ) && in_array( $post_id, array_map( 'intval', (array) $cached['pages'] ), true ) ) {
			self::flush_cache();
		}
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
		foreach ( array_keys( self::catalog_rows() ) as $med_id ) {
			$defaults[ self::OPTION_PREFIX . $med_id ] = 0;
		}
		return $defaults;
	}

	/** Section-level explanation rendered above the fields. */
	public static function section_description() {
		return 'How these links work: each medication below is set to Auto, which picks the published product whose Dosing calculator anchor (Products → edit → FisHotel Medication tab) matches the med id, else a matching SKU, else the best title match (bath powder first, then liquid, then flake, then pellet). Choose a product in a row only to override Auto; the list offers published products in the Medications category tree only, so a product filed elsewhere must be moved into that tree before it can be linked. "None (hide link)" shows the medication name with no link; Hyposalinity is hidden by default because there is nothing to buy. Amazon-mode products link to their product page (the "Buy on Amazon" button lives there) and the tools say "View" instead of "Shop". '
			. 'The Medication Dosing calculator page is at /medication-dosing/ — it is not in the header menu yet.';
	}

	/** Field definitions for the settings section (one select per med). */
	public static function settings_fields() {
		$fields = [
			self::FALLBACK_OPTION => [
				'label'       => 'Fallback shop URL',
				'type'        => 'url',
				'label_for'   => self::FALLBACK_OPTION,
				'placeholder' => 'Leave blank for the Medications category',
				'description' => 'Where "Browse medications" links go when a medication has no product. Must start with / (site-relative, e.g. /product-category/medications/) or https://. Blank = the Medications category.',
			],
		];
		if ( ! self::is_settings_request() ) return $fields;
		foreach ( self::catalog_rows() as $med_id => $row ) {
			$key            = self::OPTION_PREFIX . $med_id;
			$fields[ $key ] = [
				'label'     => esc_html( $row['name'] ),
				'type'      => 'product_select',
				'label_for' => $key,
				'med_id'    => $med_id,
			];
		}
		return $fields;
	}

	/** Select sanitizer: 0 = Auto, -1 = None, else a product ID. */
	public static function sanitize_override( $value ) {
		$n = (int) $value;
		return $n < 0 ? self::NONE : $n;
	}

	/** '— Auto —', '— None (hide link) —', then every published product in the Medications tree. */
	public static function product_options() {
		$options = [ 0 => '— Auto —', self::NONE => '— None (hide link) —' ];

		$tree = self::tree_products();
		uasort( $tree, function ( $a, $b ) { return strcasecmp( $a['title'], $b['title'] ); } );
		foreach ( $tree as $pid => $row ) {
			$options[ (int) $pid ] = $row['title'] . ' #' . $pid;
		}
		return $options;
	}

	/** Render one med's override select + the read-only resolved status. */
	public static function render_product_select( $key, array $args ) {
		$med_id  = isset( $args['med_id'] ) ? (string) $args['med_id'] : '';
		$current = self::sanitize_override( get_option( $key, 0 ) );
		$options = self::product_options();

		// A stale override (product deleted / unpublished / outside the
		// Medications tree) must stay visible so Jeff can see it and reset
		// it to Auto.
		if ( $current > 0 && ! isset( $options[ $current ] ) ) {
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

		if ( $entry['source'] === 'none' ) {
			$status = $current === self::NONE
				? 'Hidden — the calculator and QT Scheduler show the name with no link'
				: 'Hidden by default (protocol, nothing to buy); pick a product above to link one anyway';
		} else {
			$status = self::auto_status_text( $med_id, $entry );
			if ( $entry['source'] === 'manual' ) {
				$status .= ' — override active: ' . $entry['name'];
			} elseif ( ! empty( $entry['override_ignored'] ) ) {
				$status .= ' — override ignored (not a published Medications product)';
			}
			if ( $entry['product_id'] ) {
				if ( $entry['mode'] === 'amazon' ) $status .= ' · Amazon-mode: links to the product page, tools say "View"';
				if ( in_array( $entry['form'], [ 'flakes', 'pellets' ], true ) ) $status .= ' · medicated food (' . rtrim( $entry['form'], 's' ) . '), not a bath powder';
				if ( ! $entry['in_stock'] ) $status .= ' · out of stock';
			}
		}
		echo ' <span class="description fh-tool-link-status">' . esc_html( $status ) . '</span>';
	}

	/** The "Auto → …" sentence for one entry, naming the candidates it weighed. */
	protected static function auto_status_text( $med_id, array $entry ) {
		$candidates = isset( $entry['auto_candidates'] ) && is_array( $entry['auto_candidates'] ) ? $entry['auto_candidates'] : [];
		$reason     = isset( $entry['auto_reason'] ) ? (string) $entry['auto_reason'] : '';
		$names      = implode( ', ', $candidates );

		if ( ! empty( $entry['auto_product_id'] ) ) {
			$auto_id   = (int) $entry['auto_product_id'];
			$auto_name = isset( $candidates[ $auto_id ] ) ? $candidates[ $auto_id ] : null;
			if ( $auto_name === null ) {
				$auto_post = get_post( $auto_id );
				$auto_name = $auto_post ? $auto_post->post_title : '#' . $auto_id;
			}
			$status = sprintf( 'Auto → %s (matched by %s)', $auto_name, $entry['auto_source'] );
			switch ( $reason ) {
				case 'multi_anchor':
					if ( count( $candidates ) > 1 ) {
						$status .= sprintf( '; %d products carry this anchor (%s) — in-stock first, then the lowest ID', count( $candidates ), $names );
					}
					break;
				case 'ranked_title':
					$others = array_diff_key( $candidates, [ $auto_id => true ] );
					if ( $others ) {
						$status .= sprintf( '; also matched %s — powder is preferred over liquid, flake and pellet, then the shortest title', implode( ', ', $others ) );
					}
					break;
				case 'skipped_anchor':
					$status .= sprintf( '; the anchor prefix was skipped because %d products share it (%s)', count( $candidates ), $names );
					break;
			}
			return $status;
		}

		if ( $reason === 'ambiguous_anchor' ) {
			return sprintf( 'Auto → %d products match by anchor prefix (%s) and nothing matched by SKU or title; pick one above or give the right one the exact anchor #%s', count( $candidates ), $names, $med_id );
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
 * med_id => { product_id, url, name, source, in_stock, mode } with source
 * one of manual | anchor | sku | title | fallback | none (url '' when none).
 * Cached; see the file docblock.
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
			'mode'       => (string) $entry['mode'],
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

/** Section description for the admin settings framework (sections()). */
function fishotel_tool_link_section_description() {
	return FisHotel_Tool_Product_Links::section_description();
}
