<?php
/**
 * Test for the theme's boxes on the product edit screen (v1.28.0):
 *  - "Medication & food" tab: name, place, and when its fields show
 *    (inc/admin-product-screen.php + inc/medication-store/product-meta.php);
 *  - EA Wholesale / EA Retail on a variation carry the med-only class;
 *  - "Fish facts — the table on the product page" and "Staff notes — never
 *    shown on the website" (inc/hotel-data.php): titles, fields, the
 *    fields filter, the old-style line + copy plan, the hints, and the
 *    save rules (each box saves only its own fields, only with its nonce).
 * No WordPress, no database. Invented data.
 *
 *   php tests/product-screen-test.php
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['boxes']   = [];
$GLOBALS['filters'] = [];
$GLOBALS['meta']    = [];
$GLOBALS['posts']   = [];
$GLOBALS['inputs']  = [];
function add_action() {}
function add_filter() {}
function apply_filters( $hook, $value ) {
	return isset( $GLOBALS['filters'][ $hook ] ) ? call_user_func( $GLOBALS['filters'][ $hook ], $value ) : $value;
}
function add_meta_box( $id, $title, $cb, $screen, $ctx, $prio ) { $GLOBALS['boxes'][ $id ] = compact( 'title', 'screen', 'ctx', 'prio' ); }
function __( $t, $d = '' ) { return $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function checked( $a, $b = true ) { if ( (string) $a === (string) $b ) echo ' checked="checked"'; }
function wp_nonce_field( $action, $name ) { echo '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">'; }
function wp_verify_nonce( $value, $action ) { return $value === 'nonce-' . $action; }
function current_user_can() { return true; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS['meta'][ $id ] ) && array_key_exists( $key, $GLOBALS['meta'][ $id ] ); }
function wp_unslash( $v ) { return $v; }
function esc_url_raw( $v ) { return trim( (string) $v ); }
function absint( $v ) { return abs( (int) $v ); }
function wc_format_decimal( $v ) { return trim( (string) $v ); }
function wp_get_post_parent_id( $id ) { return 0; }
function get_post_field( $field, $id ) { return $GLOBALS['posts'][ $id ][ $field ] ?? ''; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function fishotel_is_medication_product( $id ) { return 900 === (int) $id; }
function fishotel_is_food_product( $id ) { return false; }
function woocommerce_wp_text_input( $args ) { $GLOBALS['inputs'][] = $args; }

require_once dirname( __DIR__ ) . '/inc/product-facts.php';
require_once dirname( __DIR__ ) . '/inc/hotel-data.php';
require_once dirname( __DIR__ ) . '/inc/admin-product-screen.php';
require_once dirname( __DIR__ ) . '/inc/medication-store/product-meta.php';

$failures = 0;
function check( $label, $ok, $detail = '' ) {
	global $failures;
	if ( $ok ) {
		echo "  ok   $label\n";
	} else {
		$failures++;
		echo "  FAIL $label" . ( $detail !== '' ? " — $detail" : '' ) . "\n";
	}
}
function render( $cb, $id ) {
	ob_start();
	call_user_func( $cb, (object) [ 'ID' => $id ] );
	return ob_get_clean();
}

// ── Medication & food tab ───────────────────────────────────────────────
echo "Medication & food\n";
$S = 'FisHotel_Admin_Product_Screen';
check( 'no category yet → shown (nothing hidden)', $S::med_fields_shown( [], [ 5, 6 ], '', '' ) );
check( 'a medication category → shown', $S::med_fields_shown( [ 3, 6 ], [ 5, 6 ], '', '' ) );
check( 'only fish categories → hidden', ! $S::med_fields_shown( [ 3, 4 ], [ 5, 6 ], '', '' ) );
check( '"Who ships it" set → shown whatever the category', $S::med_fields_shown( [ 3 ], [ 5 ], 'self', '' ) );
check( 'Amazon ASIN set → shown', $S::med_fields_shown( [ 3 ], [ 5 ], '', 'B000TEST01' ) );
check( 'ids compared as numbers', $S::med_fields_shown( [ '5' ], [ 5 ], '', '' ) );
check( 'blank mode / ASIN do not count', ! $S::med_fields_shown( [ 3 ], [ 5 ], '  ', ' ' ) );
check( 'the roots include medications and foods', in_array( 'medications', $S::MED_FOOD_ROOTS, true ) && in_array( 'freeze-dried-foods', $S::MED_FOOD_ROOTS, true ) && in_array( 'medicated-food', $S::MED_FOOD_ROOTS, true ) );

$tabs = FisHotel_Med_Product_Meta::add_data_tab( [] );
check( 'tab renamed "Medication & food"', 'Medication & food' === $tabs['fishotel_med']['label'] );
check( 'tab right after General (10) and before Inventory (20)', 15 === $tabs['fishotel_med']['priority'] );
check( 'tab target unchanged (saving unchanged)', 'fishotel_med_panel' === $tabs['fishotel_med']['target'] );
$src = file_get_contents( dirname( __DIR__ ) . '/inc/medication-store/product-meta.php' );
check( '"Fulfillment mode" renamed "Who ships it (EA / Amazon / us)"', false !== strpos( $src, "'Who ships it (EA / Amazon / us)'" ) && false === strpos( $src, "'Fulfillment mode'" ) );

$GLOBALS['inputs'] = [];
FisHotel_Med_Product_Meta::render_variation_fields( 0, [], (object) [ 'ID' => 4101 ] );
check( 'two EA fields drawn on a variation', 2 === count( $GLOBALS['inputs'] ) );
check( 'both carry fh-med-only (hidden unless med / food)', 2 === count( array_filter( $GLOBALS['inputs'], function ( $a ) { return false !== strpos( $a['wrapper_class'], 'fh-med-only' ); } ) ) );
check( 'their names are unchanged (saving unchanged)', '_fishotel_wholesale[0]' === $GLOBALS['inputs'][0]['name'] && '_fishotel_ea_retail[0]' === $GLOBALS['inputs'][1]['name'] );
$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/medication-store-admin.css' );
check( 'CSS hides the tab, its panel and fh-med-only under body.fh-hide-med', false !== strpos( $css, 'body.fh-hide-med #woocommerce-product-data ul.product_data_tabs li.fishotel_med_options' ) && false !== strpos( $css, 'body.fh-hide-med #fishotel_med_panel' ) && false !== strpos( $css, 'body.fh-hide-med #woocommerce-product-data .fh-med-only' ) );

// ── Fish facts + Staff notes boxes ──────────────────────────────────────
echo "boxes\n";
FisHotel_Hotel_Data::add_meta_box();
check( 'fish box renamed', 'Fish facts — the table on the product page' === $GLOBALS['boxes']['fishotel_hotel_data']['title'] );
check( 'fish box keeps its id and place', 'normal' === $GLOBALS['boxes']['fishotel_hotel_data']['ctx'] && 'high' === $GLOBALS['boxes']['fishotel_hotel_data']['prio'] );
check( 'Staff notes box exists', 'Staff notes — never shown on the website' === ( $GLOBALS['boxes']['fishotel_staff_notes']['title'] ?? '' ) );
check( 'Staff notes box is low in the main column', 'normal' === $GLOBALS['boxes']['fishotel_staff_notes']['ctx'] && 'low' === $GLOBALS['boxes']['fishotel_staff_notes']['prio'] );

$GLOBALS['meta'][701]  = [ '_fh_scientific_name' => 'Testus brightus', '_fh_notes' => 'Feeds well', '_fh_reef_safe' => 'yes' ];
$GLOBALS['posts'][701] = [ 'post_content' => 'A nice fish.', 'post_excerpt' => '' ];
$facts = render( [ 'FisHotel_Hotel_Data', 'render_meta_box' ], 701 );
check( 'fish box draws the facts', false !== strpos( $facts, 'name="fh_scientific_name"' ) && false !== strpos( $facts, 'name="fh_habitat"' ) && false !== strpos( $facts, 'value="Testus brightus"' ) );
check( 'fish box no longer draws Notes', false === strpos( $facts, 'name="fh_notes"' ) && false === strpos( $facts, 'Feeds well' ) );
check( 'fish box has its own nonce', false !== strpos( $facts, 'name="fishotel_hotel_nonce"' ) );
check( 'Region hint', false !== strpos( $facts, 'A region tag such as Indo-Pacific overrides this.' ) );
check( 'Foods / Habitat hint', 2 === substr_count( $facts, 'Empty = the site&#039;s default text' ) );
check( 'no old-style line on a prose-only fish', false === strpos( $facts, 'class="fh-facts-oldstyle"' ) );

$notes = render( [ 'FisHotel_Hotel_Data', 'render_notes_box' ], 701 );
check( 'Staff notes box draws Notes with their value', false !== strpos( $notes, 'name="fh_notes"' ) && false !== strpos( $notes, 'Feeds well' ) );
check( 'Staff notes box has its own nonce', false !== strpos( $notes, 'name="fishotel_staff_notes_nonce"' ) );

$GLOBALS['filters']['fishotel_fish_facts_box_fields'] = function ( $keys ) { return array_values( array_diff( $keys, [ 'scientific_name' ] ) ); };
$facts = render( [ 'FisHotel_Hotel_Data', 'render_meta_box' ], 701 );
check( 'filter can take Scientific name out of the box', false === strpos( $facts, 'name="fh_scientific_name"' ) && false !== strpos( $facts, 'name="fh_common_names"' ) );
$GLOBALS['filters']['fishotel_fish_facts_box_fields'] = function ( $keys ) { return [ 'habitat', 'bogus', 'notes' ]; };
check( 'filter cannot add unknown fields or Notes', [ 'habitat' ] === FisHotel_Hotel_Data::box_field_keys() );
unset( $GLOBALS['filters']['fishotel_fish_facts_box_fields'] );

// Old-style fish.
$GLOBALS['meta'][702]  = [ '_fh_temperament' => 'Shy' ];
$GLOBALS['posts'][702] = [ 'post_content' => "Scientific Name: Testus oldus\nTemperament: Peaceful\nMax Length: 2 in", 'post_excerpt' => '' ];
$facts = render( [ 'FisHotel_Hotel_Data', 'render_meta_box' ], 702 );
check( 'old-style fish gets the yellow line', false !== strpos( $facts, "This fish&#039;s facts are still typed in the description." ) || false !== strpos( $facts, "This fish's facts are still typed in the description." ) );
check( 'button says "Copy them into these fields"', false !== strpos( $facts, 'Copy them into these fields' ) );
preg_match( '/data-facts="([^"]*)"/', $facts, $m );
$plan = json_decode( html_entity_decode( $m[1] ?? '', ENT_QUOTES ), true );
check( 'the plan on the button holds only empty fields', [ 'scientific_name' => 'Testus oldus', 'max_length' => '2 in' ] === $plan, json_encode( $plan ) );
preg_match( '/data-labels="([^"]*)"/', $facts, $m );
$labels = json_decode( html_entity_decode( $m[1] ?? '', ENT_QUOTES ), true );
check( 'the button carries the fact names (to name a fact that was not copied)', 'Scientific Name' === ( $labels['scientific_name'] ?? '' ) && ! isset( $labels['notes'] ) || 'Scientific Name' === ( $labels['scientific_name'] ?? '' ), json_encode( $labels ) );
check( 'nothing was written by drawing the box', ! isset( $GLOBALS['meta'][702]['_fh_scientific_name'] ) );
$GLOBALS['posts'][900] = $GLOBALS['posts'][702];
check( 'no old-style line on a medication', false === strpos( render( [ 'FisHotel_Hotel_Data', 'render_meta_box' ], 900 ), 'class="fh-facts-oldstyle"' ) );

// ── Saving ──────────────────────────────────────────────────────────────
echo "save\n";
$GLOBALS['meta'][703] = [ '_fh_notes' => 'old note', '_fh_temperament' => 'old' ];
$_POST = [ 'fishotel_hotel_nonce' => 'nonce-fishotel_save_hotel_data', 'fh_temperament' => 'Calm', 'fh_notes' => 'forged' ];
FisHotel_Hotel_Data::save_meta( 703 );
check( 'facts nonce saves facts', 'Calm' === $GLOBALS['meta'][703]['_fh_temperament'] );
check( 'facts nonce does not save Notes', 'old note' === $GLOBALS['meta'][703]['_fh_notes'] );
$_POST = [ 'fishotel_staff_notes_nonce' => 'nonce-fishotel_save_staff_notes', 'fh_notes' => 'new note', 'fh_temperament' => 'forged' ];
FisHotel_Hotel_Data::save_meta( 703 );
check( 'notes nonce saves Notes', 'new note' === $GLOBALS['meta'][703]['_fh_notes'] );
check( 'notes nonce does not save facts', 'Calm' === $GLOBALS['meta'][703]['_fh_temperament'] );
$_POST = [ 'fishotel_hotel_nonce' => 'nonce-fishotel_save_hotel_data', 'fishotel_staff_notes_nonce' => 'nonce-fishotel_save_staff_notes', 'fh_common_names' => 'Testie' ];
FisHotel_Hotel_Data::save_meta( 703 );
check( 'a field not posted is left alone', 'Calm' === $GLOBALS['meta'][703]['_fh_temperament'] && 'new note' === $GLOBALS['meta'][703]['_fh_notes'] && 'Testie' === $GLOBALS['meta'][703]['_fh_common_names'] );
$_POST = [ 'fishotel_hotel_nonce' => 'bad', 'fh_temperament' => 'x' ];
FisHotel_Hotel_Data::save_meta( 703 );
check( 'bad nonce saves nothing', 'Calm' === $GLOBALS['meta'][703]['_fh_temperament'] );

// A save never writes an empty fact onto a product that never had one
// (a dry good, or a fish whose fact was never typed): blank boxes posted
// with nothing stored are skipped. Clearing a stored fact still clears it.
$GLOBALS['meta'][704] = [ '_fh_temperament' => 'Calm' ];
$blank = [ 'fishotel_hotel_nonce' => 'nonce-fishotel_save_hotel_data', 'fishotel_staff_notes_nonce' => 'nonce-fishotel_save_staff_notes' ];
foreach ( [ 'scientific_name', 'common_names', 'max_length', 'min_tank_size', 'temperament', 'reef_safe', 'region', 'foods_feeding', 'habitat', 'notes' ] as $k ) {
	$blank[ 'fh_' . $k ] = '';
}
$_POST = $blank;
$_POST['fh_common_names'] = 'Testie';
FisHotel_Hotel_Data::save_meta( 704 );
check( 'blank boxes with nothing stored write nothing', [ '_fh_temperament', '_fh_common_names' ] === array_keys( $GLOBALS['meta'][704] ), json_encode( $GLOBALS['meta'][704] ) );
check( 'a typed fact is still saved', 'Testie' === $GLOBALS['meta'][704]['_fh_common_names'] );
check( 'a stored fact cleared on screen is cleared', '' === $GLOBALS['meta'][704]['_fh_temperament'] );
$GLOBALS['meta'][705] = [];
$_POST = $blank;
FisHotel_Hotel_Data::save_meta( 705 );
check( 'a dry good saved with every box blank gets no fish facts at all', [] === $GLOBALS['meta'][705], json_encode( $GLOBALS['meta'][705] ) );
check( 'rule: blank + nothing stored → skip', FisHotel_Hotel_Data::skip_blank( '  ', false ) && ! FisHotel_Hotel_Data::skip_blank( '', true ) && ! FisHotel_Hotel_Data::skip_blank( 'x', false ) );

// The Medication & food tab is posted on every product (hidden on fish):
// saving a fish must not add empty medication fields to it.
$GLOBALS['meta'][706] = [ '_fishotel_treats' => 'Ich' ];
$_POST = [ '_fishotel_med_nonce' => 'nonce-' . FisHotel_Med_Product_Meta::NONCE ];
foreach ( [ '_fishotel_med_eyebrow', '_fishotel_ea_url', '_fishotel_ea_product_id', '_fishotel_ea_sku', '_fishotel_amazon_asin', '_fishotel_dosing_anchor', '_fishotel_active_ingredient', '_fishotel_treats', '_fishotel_use_in', '_fishotel_reef_safe', '_fishotel_food_source', '_fishotel_best_for', '_fishotel_nutrition', '_fishotel_additives' ] as $k ) {
	$_POST[ $k ] = '';
}
$_POST['_fishotel_use_in'] = 'Quarantine tank';
FisHotel_Med_Product_Meta::save_product_meta( 706 );
check( 'medication tab: blanks with nothing stored write nothing', [ '_fishotel_treats', '_fishotel_use_in' ] === array_keys( $GLOBALS['meta'][706] ), json_encode( $GLOBALS['meta'][706] ) );
check( 'medication tab: a stored value cleared on screen is cleared', '' === $GLOBALS['meta'][706]['_fishotel_treats'] );
$GLOBALS['meta'][707] = [];
unset( $_POST['_fishotel_ea_product_id'] );
$_POST['_fishotel_use_in'] = '';
FisHotel_Med_Product_Meta::save_product_meta( 707 );
check( 'medication tab: a fish saved with the tab untouched gets nothing (not even EA product id 0)', [] === $GLOBALS['meta'][707], json_encode( $GLOBALS['meta'][707] ) );
$_POST['_fishotel_ea_product_id'] = '123';
FisHotel_Med_Product_Meta::save_product_meta( 707 );
check( 'medication tab: a typed EA product id is saved', 123 === ( $GLOBALS['meta'][707]['_fishotel_ea_product_id'] ?? null ) );
$_POST['_fishotel_ea_product_id'] = '';
FisHotel_Med_Product_Meta::save_product_meta( 707 );
check( 'medication tab: clearing a stored EA product id still clears it', 0 === ( $GLOBALS['meta'][707]['_fishotel_ea_product_id'] ?? null ) );

$GLOBALS['meta'][708] = [];
$_POST = [];
FisHotel_Med_Product_Meta::save_variation_fields( 708, 0 );
check( 'variation EA boxes blank, nothing stored → no EA Wholesale / Retail rows', ! array_key_exists( '_fishotel_wholesale', $GLOBALS['meta'][708] ) && ! array_key_exists( '_fishotel_ea_retail', $GLOBALS['meta'][708] ), json_encode( $GLOBALS['meta'][708] ) );
$_POST = [ '_fishotel_wholesale' => [ '4.50' ], '_fishotel_ea_retail' => [ '' ] ];
$GLOBALS['meta'][708]['_fishotel_ea_retail'] = '9.99';
FisHotel_Med_Product_Meta::save_variation_fields( 708, 0 );
check( 'variation: a typed wholesale is saved, a cleared retail is cleared', '4.50' === $GLOBALS['meta'][708]['_fishotel_wholesale'] && '' === $GLOBALS['meta'][708]['_fishotel_ea_retail'] );
$_POST = [];

// The medication / food categories are read in ONE query (the product screen
// asked once per category slug, 12 queries on every product opened).
$GLOBALS['term_reads'] = [];
function get_term_by( $field, $value, $tax ) { $GLOBALS['term_reads'][] = "by:$value"; return false; }
function get_terms( $args ) {
	$GLOBALS['term_reads'][] = 'terms:' . implode( ',', (array) ( $args['slug'] ?? [] ) );
	$known = [ 'medications' => 76, 'foods' => 80, 'dewormer' => 471 ];
	return array_values( array_intersect_key( $known, array_flip( (array) $args['slug'] ) ) );
}
function get_term_children( $id, $tax ) { return 76 === $id ? [ 468, 471 ] : []; }
function is_wp_error( $v ) { return false; }
$ids = FisHotel_Admin_Product_Screen::med_term_ids();
sort( $ids );
check( 'medication / food categories: one read for all the slugs', 1 === count( $GLOBALS['term_reads'] ) && 0 === strpos( $GLOBALS['term_reads'][0], 'terms:medications,' ), json_encode( $GLOBALS['term_reads'] ) );
check( '— the roots found and everything under them', [ 76, 80, 468, 471 ] === $ids, json_encode( $ids ) );
check( '— asked again in the same request: no second read', FisHotel_Admin_Product_Screen::med_term_ids() && 1 === count( $GLOBALS['term_reads'] ) );

echo "\n" . ( $failures ? "$failures FAILED" : 'all passed' ) . "\n";
exit( $failures ? 1 : 0 );
