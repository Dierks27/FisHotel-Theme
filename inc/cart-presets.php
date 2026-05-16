<?php
/**
 * FisHotel — Cart Page Presets
 *
 * Five presets (default, fish, meds, food, merch). Each one bundles the
 * subtitle template, shipping line, and 4-column trust strip copy. The
 * preset for the current cart is resolved by inspecting product_cat
 * slugs of every item — when exactly one preset matches, that preset
 * wins; multiple matches OR zero matches fall through to `default`.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Map of product_cat slug → preset key. Multiple slugs can resolve to
 * the same preset (e.g. all medications sub-categories → "meds"). The
 * preset keys themselves are stable identifiers; the slug map is the
 * piece that gets edited if Jeff adds or renames a category.
 */
function fishotel_cart_preset_slug_map() {
	return [
		// Fish
		'quarantined-fish'   => 'fish',
		// Medications (Phase 3.4 form / treats children fold in)
		'medications'        => 'meds',
		'flakes'             => 'meds',
		'pellets'            => 'meds',
		'powders'            => 'meds',
		'liquid'             => 'meds',
		'antibacterial'      => 'meds',
		'antiparasitic'      => 'meds',
		'dewormer'           => 'meds',
		// Foods
		'freeze-dried-foods' => 'food',
		'fish-food'          => 'food',
		'medicated-food'     => 'food',
		// Merchandise — slug candidates. Adjust here if Jeff sets up
		// a different slug; the preset key stays "merch".
		'merchandise'        => 'merch',
		'apparel'            => 'merch',
		'swag'               => 'merch',
	];
}

/**
 * Resolve which preset the current cart should render. Returns one of
 * `default`, `fish`, `meds`, `food`, `merch`.
 *
 * @return string
 */
function fishotel_cart_resolve_preset() {
	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
		return 'default';
	}
	$slug_map = fishotel_cart_preset_slug_map();
	$matched  = [];
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		if ( ! $product_id ) {
			continue;
		}
		$cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $cats ) || empty( $cats ) ) {
			continue;
		}
		foreach ( (array) $cats as $slug ) {
			if ( isset( $slug_map[ $slug ] ) ) {
				$matched[ $slug_map[ $slug ] ] = true;
			}
		}
	}
	if ( count( $matched ) === 1 ) {
		return (string) array_key_first( $matched );
	}
	return 'default';
}

/**
 * Read a preset field. Always returns a string — falls back to the
 * registered default when the option is empty so cart copy never
 * renders blank.
 *
 * @param string $preset One of: default | fish | meds | food | merch.
 * @param string $field  e.g. `subtitle_template`, `shipping_label`,
 *                       `trust_2_body`.
 * @return string
 */
function fishotel_cart_preset_get( $preset, $field ) {
	$preset = (string) $preset;
	$field  = (string) $field;
	if ( $preset === '' || $field === '' ) {
		return '';
	}
	if ( ! class_exists( 'FisHotel_Admin_Settings' ) ) {
		return '';
	}
	return (string) FisHotel_Admin_Settings::get( "fh_cart_{$preset}_{$field}" );
}

/**
 * Per-preset noun used in non-template strings (e.g. the Subtotal
 * ledger row). Plural-aware where the language requires it.
 *
 * @param string $preset
 * @param int    $count
 * @return string
 */
function fishotel_cart_preset_noun( $preset, $count ) {
	$count = (int) $count;
	$one   = $count === 1;
	switch ( $preset ) {
		case 'fish':
			return __( 'fish', 'fishotel' ); // singular and plural identical
		case 'meds':
			return $one ? __( 'medication', 'fishotel' ) : __( 'medications', 'fishotel' );
		case 'food':
		case 'merch':
		case 'default':
		default:
			return $one ? __( 'item', 'fishotel' ) : __( 'items', 'fishotel' );
	}
}

/**
 * Resolve placeholder tokens in a preset subtitle template. Supported
 * tokens:
 *   {count}                        cart item count
 *   {items_or_items}               item / items (plural-aware)
 *   {fish_or_fishes}               fish / fish (singular = plural, kept
 *                                  for explicit copy that wants it)
 *   {medications_or_medications}   medication / medications
 *
 * @param string $template Raw template string.
 * @param int    $count    Cart item count.
 * @return string
 */
function fishotel_cart_resolve_subtitle( $template, $count ) {
	$count = (int) $count;
	$one   = $count === 1;
	$pairs = [
		'{count}'                      => number_format_i18n( $count ),
		'{items_or_items}'             => $one ? __( 'item', 'fishotel' )       : __( 'items', 'fishotel' ),
		'{item_or_items}'              => $one ? __( 'item', 'fishotel' )       : __( 'items', 'fishotel' ),
		'{fish_or_fishes}'             => $one ? __( 'fish', 'fishotel' )       : __( 'fish', 'fishotel' ),
		'{medications_or_medications}' => $one ? __( 'medication', 'fishotel' ) : __( 'medications', 'fishotel' ),
		'{medication_or_medications}'  => $one ? __( 'medication', 'fishotel' ) : __( 'medications', 'fishotel' ),
	];
	return strtr( (string) $template, $pairs );
}

/**
 * One-shot migration: copy the legacy single-set cart settings (1.8.0–1.8.2)
 * into the new `fh_cart_fish_*` preset keys. Runs once per site; gated by
 * an `fh_cart_preset_migration_v1` option flag.
 *
 * Only writes a preset key when:
 *   - The legacy key is present in the DB (i.e. user customized it), AND
 *   - The new preset key isn't already stored (don't overwrite intentional edits).
 */
add_action( 'init', function () {
	if ( get_option( 'fh_cart_preset_migration_v1' ) === 'done' ) {
		return;
	}
	$map = [
		'fh_cart_hero_subtitle_template' => 'fh_cart_fish_subtitle_template',
		'fh_cart_shipping_label'         => 'fh_cart_fish_shipping_label',
		'fh_cart_shipping_subtext'       => 'fh_cart_fish_shipping_subtext',
		'fh_cart_trust_1_label'          => 'fh_cart_fish_trust_1_label',
		'fh_cart_trust_1_body'           => 'fh_cart_fish_trust_1_body',
		'fh_cart_trust_2_label'          => 'fh_cart_fish_trust_2_label',
		'fh_cart_trust_2_body'           => 'fh_cart_fish_trust_2_body',
		'fh_cart_trust_3_label'          => 'fh_cart_fish_trust_3_label',
		'fh_cart_trust_3_body'           => 'fh_cart_fish_trust_3_body',
		'fh_cart_trust_4_label'          => 'fh_cart_fish_trust_4_label',
		'fh_cart_trust_4_body'           => 'fh_cart_fish_trust_4_body',
	];
	$sentinel = '__FH_NOT_SET__';
	foreach ( $map as $old => $new ) {
		$old_val = get_option( $old, $sentinel );
		if ( $old_val === $sentinel ) {
			continue; // legacy never customized — skip
		}
		$new_val = get_option( $new, $sentinel );
		if ( $new_val !== $sentinel ) {
			continue; // new key already stored — don't clobber
		}
		update_option( $new, $old_val );
	}
	update_option( 'fh_cart_preset_migration_v1', 'done' );
}, 30 );

/**
 * One-shot migration v2: clear the default preset's `shipping_subtext`
 * when it still holds the obsolete "Calculated at checkout" seed value
 * (which is what `defaults()` returned from 1.8.0 through 1.8.3).
 *
 * That value is now a regression: the cart/checkout ledger already
 * renders "Calculated at checkout" inside the shipping VALUE column
 * when no method has been calculated, so the subtext line below the
 * label duplicates the string on mixed-cart preset = `default` carts.
 *
 * Sites whose owner intentionally customized the subtext (anything
 * other than the literal seed) are left alone.
 */
add_action( 'init', function () {
	if ( get_option( 'fh_cart_preset_migration_v2' ) === 'done' ) {
		return;
	}
	$stored = get_option( 'fh_cart_default_shipping_subtext', null );
	if ( 'Calculated at checkout' === $stored ) {
		update_option( 'fh_cart_default_shipping_subtext', '' );
	}
	update_option( 'fh_cart_preset_migration_v2', 'done' );
}, 30 );
