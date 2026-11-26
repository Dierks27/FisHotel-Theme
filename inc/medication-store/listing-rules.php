<?php
/**
 * Medication store — plain product listings (v1.29.0).
 *
 * Every medication and medicated-food page is a plain product listing:
 * what it is, what is in it, size, price, storage, who ships it. The
 * storefront no longer prints treatment claims that come from the theme's
 * own code or from the claim-shaped product fields:
 *
 *   - Eyebrow tagline, Treats, Use in, Dosing calculator anchor: kept in
 *     wp-admin and in the database, never printed (private_fields()).
 *   - "Best for" is not printed on a medication (it is on a plain food).
 *   - The extra checkout acknowledgement is not printed when its text
 *     talks about dosing, treatment, quarantine or a disease
 *     (carries_treatment_text()).
 *   - Copy the theme used to ship as a default ("The same meds we use in
 *     QT", "Therapeutic-grade", "DOSING CALCULATOR" …) is reset once when
 *     the stored setting still holds that old default word for word
 *     (stale_copy() / maybe_reset_stale_copy()).
 *   - The Medication Dosing and QT Scheduler links leave the footer on
 *     medication pages (footer_tool_defs()).
 *
 * The rules below are pure PHP (no WordPress) so tests/med-listings-test.php
 * can run them without a site. The few WordPress-facing helpers at the end
 * guard every call.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Listing {

	/** Neutral line under the cart button on EA-shipped products. */
	const SHIPPING_LINE = 'Ships direct from our supplier.';

	/** Title of the badge above the price on medication pages (was "From the Pharmacy"). */
	const BADGE_TITLE = 'From the Supply Room';

	/** Once-gate for maybe_reset_stale_copy(). Bump VERSION to run it again. */
	const VERSION_OPTION = 'fishotel_med_listing_v';
	const VERSION        = '2';

	/** The entry pop-up's body until v1.29.0 (it named dosing). */
	const OLD_DISCLAIMER_BODY = 'All medications sold by FisHotel are intended exclusively for ornamental aquarium fish. They are not for human consumption, not for food-producing animals, and not for use in any tank stocked with fish intended for human consumption. By proceeding, you acknowledge that you are purchasing these medications for ornamental aquarium use only, that you understand proper dosing and handling, and that you accept full responsibility for safe application.';

	/** Footer brand line on every page; on medication pages a version with no treatment words (v1.29.0). */
	const FOOTER_TAGLINE     = 'Premium saltwater fish quarantine. Every fish checked in, observed, treated, and cleared before it ever reaches your tank.';
	const FOOTER_TAGLINE_MED = 'Premium saltwater fish. Every fish checked in, observed and cleared before it ever reaches your tank.';

	/** Footer tool pages that are not linked from medication pages. */
	const HIDDEN_TOOL_TEMPLATES = [ 'page-quarantine-help.php', 'page-qt-scheduler.php' ];

	/**
	 * Product fields kept in wp-admin and in the database but never printed
	 * on the storefront: each one is a treatment claim by nature.
	 *
	 * @return string[] meta key => what it held
	 */
	public static function private_fields() {
		return [
			'_fishotel_med_eyebrow'   => 'Eyebrow tagline',
			'_fishotel_treats'        => 'Treats',
			'_fishotel_use_in'        => 'Use in',
			'_fishotel_dosing_anchor' => 'Dosing calculator anchor',
		];
	}

	/**
	 * Words that make a sentence a treatment claim: dosing, treatment and
	 * quarantine words, therapeutic labels and disease / parasite names.
	 * Matched as whole words, any case. "kills" is deliberately absent:
	 * "Copper kills invertebrates and corals" is a handling warning.
	 *
	 * @return string[]
	 */
	public static function treatment_words() {
		return [
			// Dosing and treatment.
			'dose', 'doses', 'dosed', 'dosing', 'dosage', 'overdose',
			'treat', 'treats', 'treated', 'treating', 'treatment', 'treatments',
			'cure', 'cures', 'cured', 'curing',
			'prevent', 'prevents', 'prevention', 'heal', 'heals', 'healing',
			'remedy', 'therapy', 'therapeutic', 'medicate', 'medicating',
			'effective against', 'broad-spectrum', 'broad spectrum',
			// Where a treatment happens.
			'quarantine', 'qt', 'hospital tank',
			// Therapeutic labels.
			'antibiotic', 'antibiotics', 'antibacterial', 'antiparasitic',
			'anti-parasitic', 'antiprotozoal', 'anti-protozoal', 'antifungal',
			'dewormer', 'deworm', 'deworming', 'anthelmintic',
			// Diseases, parasites, symptoms.
			'disease', 'diseases', 'infection', 'infections', 'infected',
			'parasite', 'parasites', 'parasitic', 'pathogen', 'pathogens',
			'bacterial', 'fungal', 'fungus',
			'ich', 'cryptocaryon', 'velvet', 'amyloodinium', 'brooklynella',
			'brook', 'uronema', 'flukes', 'fluke', 'monogeneans',
			'hexamita', 'spironucleus', 'hith', 'hlle', 'bloat',
			'flagellate', 'flagellates', 'nematode', 'nematodes',
			'tapeworm', 'tapeworms', 'worms', 'camallanus', 'columnaris',
			'fin rot', 'saprolegnia', 'dropsy', 'pop-eye', 'popeye',
		];
	}

	/**
	 * True when the text reads as a treatment claim (any treatment_words()
	 * entry as a whole word, any case).
	 *
	 * @param string $text
	 * @return bool
	 */
	public static function carries_treatment_text( $text ) {
		$text = trim( (string) $text );
		if ( $text === '' ) {
			return false;
		}
		static $pattern = null;
		if ( $pattern === null ) {
			$parts = array_map( function ( $w ) {
				return str_replace( ' ', '\s+', preg_quote( $w, '/' ) );
			}, self::treatment_words() );
			$pattern = '/\b(?:' . implode( '|', $parts ) . ')\b/iu';
		}
		return (bool) preg_match( $pattern, $text );
	}

	/**
	 * The extra checkout acknowledgement as the customer sees it: '' when
	 * the stored text talks about dosing, treatment, quarantine or a
	 * disease (no checkbox is shown, required or stored for it).
	 *
	 * @param string $text Stored _fishotel_med_extra_ack.
	 * @return string
	 */
	public static function shown_extra_ack( $text ) {
		$text = trim( (string) $text );
		return self::carries_treatment_text( $text ) ? '' : $text;
	}

	/**
	 * Rows of the "About This …" table for a non-fish product. Treats and
	 * Use in are never printed; "Best for" is printed on a plain food only.
	 *
	 * @param array $fields      active_ingredient, reef_safe, food_source,
	 *                           best_for, nutrition, additives (missing = '').
	 *                           Any treats / use_in key is ignored.
	 * @param bool  $is_med      Medication or Amazon (Tier 2) product.
	 * @param bool  $is_food     In a food category.
	 * @param bool  $is_med_food Medication sold as a flake or pellet.
	 * @param array $reef_labels reef_safe value => HTML badge.
	 * @return array[] [ label, html ] pairs; html is already escaped.
	 */
	public static function spec_rows( array $fields, $is_med, $is_food, $is_med_food, array $reef_labels = [] ) {
		$get = function ( $key ) use ( $fields ) {
			return isset( $fields[ $key ] ) ? trim( (string) $fields[ $key ] ) : '';
		};
		$esc = function ( $text ) {
			return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		};
		$rows = [];
		if ( $is_med ) {
			if ( $get( 'active_ingredient' ) !== '' ) {
				$rows[] = [ 'Contains', $esc( $get( 'active_ingredient' ) ) ];
			}
			$reef = $get( 'reef_safe' );
			if ( $reef !== '' ) {
				$rows[] = [ 'Reef Safe', isset( $reef_labels[ $reef ] ) ? $reef_labels[ $reef ] : $esc( $reef ) ];
			}
		}
		if ( $is_food || $is_med_food ) {
			if ( $is_food && $get( 'food_source' ) !== '' ) {
				$rows[] = [ 'Food Source', $esc( $get( 'food_source' ) ) ];
			}
			if ( ! $is_med && $get( 'best_for' ) !== '' ) {
				$rows[] = [ 'Best For', $esc( $get( 'best_for' ) ) ];
			}
			if ( $get( 'nutrition' ) !== '' ) {
				$rows[] = [ 'Nutrition Value', $esc( $get( 'nutrition' ) ) ];
			}
			if ( $get( 'additives' ) !== '' ) {
				$rows[] = [ 'Additives & Enrichments', $esc( $get( 'additives' ) ) ];
			}
		}
		return $rows;
	}

	/**
	 * Last word of the "About This …" headings for a non-fish product:
	 * "Food" for a food or a medicated flake/pellet, "Product" for any
	 * other medication, '' for everything else (no heading).
	 *
	 * @return string
	 */
	public static function about_word( $is_med, $is_food, $is_med_food ) {
		if ( $is_med ) {
			return $is_med_food ? 'Food' : 'Product';
		}
		return $is_food ? 'Food' : '';
	}

	/**
	 * Settings whose OLD default was a claim. A stored value that still
	 * equals the old default word for word is deleted once, so the new
	 * neutral default (FisHotel_Admin_Settings::defaults()) shows instead.
	 * Anything the owner typed differently is left alone.
	 *
	 * @return string[] option name => old default
	 */
	public static function stale_copy() {
		return [
			'fh_med_trust_1'             => 'The same meds we use in QT',
			'fh_med_trust_2'             => 'Trusted partner fulfillment',
			'fh_tier2_trust_1'           => 'The same meds we use in QT',
			'fh_pharmacy_badge_subtitle' => 'Sourced direct · Therapeutic-grade',
			'fh_cart_meds_trust_1_label' => 'FROM THE PHARMACY',
			'fh_cart_meds_trust_1_body'  => 'The same meds we use in-house',
			'fh_cart_meds_trust_3_label' => 'EXPERT-CURATED',
			'fh_cart_meds_trust_3_body'  => 'Hand-picked for reef and quarantine use',
			'fh_cart_meds_trust_4_label' => 'DOSING CALCULATOR',
			'fh_cart_meds_trust_4_body'  => 'Free FisHotel calculator for every medication',
			// The entry pop-up's body as the theme shipped it until v1.29.0
			// ("… you understand proper dosing and handling …"). A saved copy of
			// it is reset so the neutral default shows.
			'fishotel_med_disclaimer_body' => self::OLD_DISCLAIMER_BODY,
		];
	}

	/**
	 * Which stored settings to delete: those still holding their old claim
	 * default (spacing and case ignored).
	 *
	 * @param array $stored option name => stored value (missing = not stored).
	 * @return string[] option names
	 */
	public static function stale_keys( array $stored ) {
		$norm = function ( $s ) {
			return strtolower( trim( preg_replace( '/\s+/u', ' ', (string) $s ) ) );
		};
		$out = [];
		foreach ( self::stale_copy() as $key => $old ) {
			if ( array_key_exists( $key, $stored ) && is_string( $stored[ $key ] ) && $norm( $stored[ $key ] ) === $norm( $old ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Stored settings (same list) that the owner wrote differently and that
	 * still carry treatment wording — shown as a notice, never changed.
	 *
	 * @param array $stored option name => stored value.
	 * @return string[] option names
	 */
	public static function custom_claim_keys( array $stored ) {
		$stale = self::stale_keys( $stored );
		$out   = [];
		foreach ( array_keys( self::stale_copy() ) as $key ) {
			if ( in_array( $key, $stale, true ) || ! isset( $stored[ $key ] ) || ! is_string( $stored[ $key ] ) ) {
				continue;
			}
			if ( self::carries_treatment_text( $stored[ $key ] ) || stripos( $stored[ $key ], 'pharmacy' ) !== false ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Footer Tools entries for this page: the Medication Dosing and QT
	 * Scheduler entries are dropped on medication pages. The other tools
	 * stay, so the column (and the four-column footer) keeps its shape.
	 *
	 * @param array $defs [ template, slug, label ] triples.
	 * @param bool  $on_med_page
	 * @return array
	 */
	public static function footer_tool_defs( array $defs, $on_med_page ) {
		if ( ! $on_med_page ) {
			return $defs;
		}
		return array_values( array_filter( $defs, function ( $def ) {
			return ! in_array( (string) $def[0], self::HIDDEN_TOOL_TEMPLATES, true );
		} ) );
	}

	/**
	 * Whether a product description already says the supplier line ("Ships
	 * direct from our supplier."), so the line under the cart button is not
	 * printed a second time.
	 *
	 * @param string $description post_content (HTML allowed).
	 * @return bool
	 */
	public static function says_shipping_line( $description ) {
		$text = html_entity_decode( strip_tags( (string) $description ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return stripos( (string) $text, rtrim( self::SHIPPING_LINE, '.' ) ) !== false;
	}

	/**
	 * Shop copy shown on a medication page (badge subtitle, trust strip,
	 * cart preset): a setting the owner typed that still reads as a claim
	 * (treatment / dosing / quarantine / disease words, or "pharmacy") is
	 * not printed — the neutral default is, and the admin notice
	 * (custom_claim_notice()) names the setting so it can be reworded.
	 *
	 * @param string $text    The stored setting as resolved.
	 * @param string $default The neutral code default ('' = print nothing).
	 * @return string
	 */
	public static function plain_copy( $text, $default ) {
		$text = (string) $text;
		if ( self::carries_treatment_text( $text ) || stripos( $text, 'pharmacy' ) !== false ) {
			return self::carries_treatment_text( (string) $default ) ? '' : (string) $default;
		}
		return $text;
	}

	/**
	 * The line under the product name on a medication page: '' when the
	 * active ingredient is shown in the "Contains" row already (it was printed
	 * three times: here, in the row and in the description), else the short
	 * description.
	 *
	 * @param string $ingredient _fishotel_active_ingredient.
	 * @param string $short      Short description.
	 * @return string
	 */
	public static function med_subtitle( $ingredient, $short ) {
		return trim( (string) $ingredient ) !== '' ? '' : (string) $short;
	}

	/**
	 * The footer brand line: on a medication page without "quarantine" and "treated" (a
	 * treatment word next to a product listing), everywhere else as before.
	 *
	 * @param bool $on_med_page
	 * @return string
	 */
	public static function footer_tagline( $on_med_page ) {
		return $on_med_page ? self::FOOTER_TAGLINE_MED : self::FOOTER_TAGLINE;
	}

	// ─────────────────────────────────────────
	// WordPress side
	// ─────────────────────────────────────────

	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_reset_stale_copy' ] );
		add_action( 'admin_notices', [ __CLASS__, 'custom_claim_notice' ] );
		// WooCommerce's own product JSON-LD carries up to five review texts;
		// on a medication page they can name diseases or doses. The rating
		// summary stays.
		add_filter( 'woocommerce_structured_data_product', [ __CLASS__, 'drop_review_text' ], 10, 2 );
	}

	/** True on a medication product page or a Medications category page. */
	public static function on_med_page() {
		if ( function_exists( 'fishotel_med_is_med_archive' ) && fishotel_med_is_med_archive() ) {
			return true;
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			$pid = (int) get_queried_object_id();
			return $pid && self::is_med( $pid );
		}
		return false;
	}

	/** Medication (either rule) or Amazon (Tier 2) product. */
	public static function is_med( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! $product_id ) {
			return false;
		}
		return ( function_exists( 'fishotel_med_is_med_product' ) && fishotel_med_is_med_product( $product_id ) )
			|| ( function_exists( 'fishotel_is_medication_product' ) && fishotel_is_medication_product( $product_id ) )
			|| ( function_exists( 'fishotel_is_amazon_affiliate_product' ) && fishotel_is_amazon_affiliate_product( $product_id ) );
	}

	/**
	 * Once per VERSION, on a normal wp-admin load: delete stored settings
	 * that still hold an old claim default, then empty the page cache so
	 * product pages are built again with the new copy.
	 */
	public static function maybe_reset_stale_copy() {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}
		$stored = [];
		foreach ( array_keys( self::stale_copy() ) as $key ) {
			$value = get_option( $key, null );
			if ( $value !== null ) {
				$stored[ $key ] = $value;
			}
		}
		foreach ( self::stale_keys( $stored ) as $key ) {
			delete_option( $key );
		}
		update_option( self::VERSION_OPTION, self::VERSION, true );

		if ( class_exists( 'FisHotel_Tool_Product_Links' ) && method_exists( 'FisHotel_Tool_Product_Links', 'flush_cache' ) ) {
			FisHotel_Tool_Product_Links::flush_cache();
		}
		// Breeze (Cloudways): files + Varnish. Silent when Breeze is absent.
		if ( has_action( 'breeze_clear_all_cache' ) ) {
			do_action( 'breeze_clear_all_cache' );
		}
	}

	/** Notice on the FisHotel Theme screens naming settings that still read as claims. */
	public static function custom_claim_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		if ( strpos( $page, 'fishotel' ) !== 0 ) {
			return;
		}
		$stored = [];
		foreach ( array_keys( self::stale_copy() ) as $key ) {
			$value = get_option( $key, null );
			if ( $value !== null ) {
				$stored[ $key ] = $value;
			}
		}
		$keys = self::custom_claim_keys( $stored );
		if ( empty( $keys ) ) {
			return;
		}
		$lines = [];
		foreach ( $keys as $key ) {
			$lines[] = '<code>' . esc_html( $key ) . '</code>: “' . esc_html( (string) $stored[ $key ] ) . '”';
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Medication pages: these settings still read like treatment claims.', 'fishotel' ) . '</strong> '
			. esc_html__( 'Product page and cart copy should say what the product is, not what it treats. Reword or empty them (empty brings back the plain default).', 'fishotel' )
			. '</p><p>' . implode( '<br>', $lines ) . '</p></div>'; // Escaped above.
	}

	/**
	 * Drop review texts from WooCommerce's product JSON-LD on medication
	 * pages. aggregateRating (stars and count) is kept.
	 *
	 * @param array      $markup
	 * @param WC_Product $product
	 * @return array
	 */
	public static function drop_review_text( $markup, $product = null ) {
		if ( ! is_array( $markup ) || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $markup;
		}
		if ( self::is_med( (int) $product->get_id() ) ) {
			unset( $markup['review'] );
		}
		return $markup;
	}
}
