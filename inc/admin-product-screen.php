<?php
/**
 * Product edit screen — what the theme's own fields show for which kind
 * of product (v1.28.0).
 *
 * The "Medication & food" tab (inc/medication-store/product-meta.php) and
 * the EA Wholesale / EA Retail boxes on each variation only matter for a
 * medication or food. They are shown when:
 *   - the product has no category yet (nothing is hidden), or
 *   - one of its categories is a medication or food category (or sits
 *     under one), or
 *   - "Who ships it" is set (EA / Amazon / us), or an Amazon ASIN is set.
 * Otherwise (a live fish, a dry good, an invoice) they are hidden. Ticking
 * a category switches them at once (assets/js/admin-product.js).
 *
 * Hidden fields are still on the page and still posted: nothing saves
 * differently.
 *
 * The same script fills the fish facts box from the description when
 * "Copy them into these fields" is clicked (inc/hotel-data.php).
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Admin_Product_Screen {

	/** Category slugs whose terms (and every term under them) are medication / food. */
	const MED_FOOD_ROOTS = [
		'medications', 'flakes', 'pellets', 'powders', 'liquid', 'antibacterial', 'antiparasitic', 'dewormer',
		'foods', 'freeze-dried-foods', 'fish-food', 'medicated-food',
	];

	const BODY_CLASS = 'fh-hide-med';

	public static function init() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_filter( 'admin_body_class',      [ __CLASS__, 'body_class' ] );
	}

	// ── Pure ───────────────────────────────────────────────────────────

	/**
	 * Should the Medication & food fields show?
	 *
	 * @param int[]  $term_ids     The product's category ids.
	 * @param int[]  $med_term_ids Medication / food category ids.
	 * @param string $mode         Raw "Who ships it" value.
	 * @param string $asin         Amazon ASIN.
	 * @return bool
	 */
	public static function med_fields_shown( array $term_ids, array $med_term_ids, $mode, $asin ) {
		if ( '' !== trim( (string) $mode ) || '' !== trim( (string) $asin ) ) {
			return true;
		}
		$term_ids = array_map( 'intval', $term_ids );
		if ( ! $term_ids ) {
			return true;
		}
		return (bool) array_intersect( $term_ids, array_map( 'intval', $med_term_ids ) );
	}

	// ── WordPress edge ─────────────────────────────────────────────────

	/** Medication / food category ids: the roots and everything under them. */
	public static function med_term_ids() {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}
		$ids = [];
		foreach ( self::MED_FOOD_ROOTS as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$ids[] = (int) $term->term_id;
			$children = get_term_children( (int) $term->term_id, 'product_cat' );
			if ( is_array( $children ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $children ) );
			}
		}
		$ids = array_values( array_unique( $ids ) );
		return $ids;
	}

	/** The product being edited, or 0. */
	private static function current_product_id() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id || 'post' !== $screen->base ) {
			return -1; // Not the product edit screen.
		}
		$post = get_post();
		return $post ? (int) $post->ID : 0;
	}

	public static function shown_for( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return true;
		}
		$terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
		$terms = is_wp_error( $terms ) ? [] : (array) $terms;
		// A brand-new (auto-draft) product has no category ticked yet.
		if ( 'auto-draft' === get_post_status( $product_id ) ) {
			$terms = [];
		}
		return self::med_fields_shown(
			$terms,
			self::med_term_ids(),
			(string) get_post_meta( $product_id, '_fishotel_fulfillment', true ),
			(string) get_post_meta( $product_id, '_fishotel_amazon_asin', true )
		);
	}

	public static function body_class( $classes ) {
		$pid = self::current_product_id();
		if ( $pid < 0 ) {
			return $classes;
		}
		if ( ! self::shown_for( $pid ) ) {
			$classes .= ' ' . self::BODY_CLASS;
		}
		return $classes;
	}

	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) || self::current_product_id() < 0 ) {
			return;
		}
		wp_enqueue_script(
			'fishotel-admin-product',
			FISHOTEL_THEME_URI . '/assets/js/admin-product.js',
			[ 'jquery' ],
			fishotel_asset_version( 'assets/js/admin-product.js' ),
			true
		);
		wp_localize_script( 'fishotel-admin-product', 'fishotelAdminProduct', [
			'medTermIds' => self::med_term_ids(),
			'bodyClass'  => self::BODY_CLASS,
		] );
	}
}

FisHotel_Admin_Product_Screen::init();
