<?php
/**
 * Test for inc/product-facts.php — the facts typed in a description
 * ("old style"): the product-page text, the old-style test, the reading
 * the Tools migration does, and the per-product copy that never
 * overwrites. No WordPress, no database (a few stubs for the edge).
 *
 *   php tests/product-facts-test.php
 *
 * All products and text below are invented.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

// ── Stubs for the WordPress edge ────────────────────────────────────────
$GLOBALS['posts'] = [];
$GLOBALS['meta']  = [];
$GLOBALS['wrote'] = [];
function get_post_field( $field, $id ) { return $GLOBALS['posts'][ $id ][ $field ] ?? ''; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['meta'][ $id ][ $key ] = $value;
	$GLOBALS['wrote'][]             = [ $id, $key, $value ];
	return true;
}
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }

require_once dirname( __DIR__ ) . '/inc/product-facts.php';

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
function j( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE ); }

$F = 'FisHotel_Product_Facts';

// An old-style description as typed years ago (invented fish).
$old = "<p>Description: A bright little test fish that settles in fast.</p>\n"
	. "<p>Scientific Name: Testus brightus<br>\n"
	. "Common Names: Bright Test Fish, Testie<br>\n"
	. "Maximum Length: 3 inches (7.5 cm)<br>\n"
	. "Minimum Aquarium Size: 30 gallons<br>\n"
	. "Reef Safety: Yes<br>\n"
	. "Temperament: Peaceful</p>\n"
	. "<p>Foods and Feeding Habits: Frozen mysis twice a day.</p>\n"
	. "<p>Habitat and Behavior: Hovers near rockwork.</p>\n"
	. "<p>Fun Facts: It changes colour at night.</p>\n"
	. "<p>Ships in a breather bag.</p>";

// ── normalise() ─────────────────────────────────────────────────────────
echo "normalise()\n";
check( '<br> and </p> become line breaks', "a\nb\n\nc" === $F::normalise( '<p>a<br>b</p>' . "\n" . '<p>c</p>' ), j( $F::normalise( '<p>a<br>b</p>' . "\n" . '<p>c</p>' ) ) );
check( 'paragraphs stored without newlines are still split', "Scientific Name: X\nTemperament: Calm" === $F::normalise( '<p>Scientific Name: X</p><p>Temperament: Calm</p>' ) );
check( 'entities decoded, NBSP made plain', 'Foods & Feeding: krill' === $F::normalise( 'Foods &amp; Feeding:&nbsp;krill' ) );
check( 'script and style contents dropped', 'ok' === $F::normalise( '<style>.x{}</style><script>alert(1)</script>ok' ) );
check( 'list items and table cells split', "one\ntwo\nA\nB" === $F::normalise( '<ul><li>one</li><li>two</li></ul><table><tr><td>A</td><td>B</td></tr></table>' ), j( $F::normalise( '<ul><li>one</li><li>two</li></ul><table><tr><td>A</td><td>B</td></tr></table>' ) ) );

// ── labels ──────────────────────────────────────────────────────────────
echo "labels\n";
$cases = [
	'Scientific Name: X'          => 'scientific_name',
	'Common Name - Y'             => 'common_names',
	'Max. Length: 3"'             => 'max_length',
	'Maximum Lengths – 4 in'      => 'max_length',
	'Min Tank Size: 20 g'         => 'min_tank_size',
	'Minimum Aquarium Size: 30'   => 'min_tank_size',
	'Temperament | calm'          => 'temperament',
	'Reef-Safe: yes'              => 'reef_safe',
	'Reef Safety: with caution'   => 'reef_safe',
	'Region: Red Sea'             => 'region',
	'Foods & Feeding: mysis'      => 'foods_feeding',
	'Food and Feeding Habits:'    => 'foods_feeding',
	'Habitat & Behaviour: caves'  => 'habitat',
	'Habitat'                     => 'habitat',
	'Habits: shy'                 => 'habitat',
	'Fun Facts:'                  => 'fun_facts',
	'  • Scientific Name: bullet' => 'scientific_name',
	"Temperament\tPeaceful"       => 'temperament',
];
foreach ( $cases as $line => $want ) {
	check( "line '" . str_replace( "\t", '\t', $line ) . "' → $want", $want === $F::line_field( $line ), $F::line_field( $line ) );
}
check( 'plain sentence starting with a label word is not a label', '' === $F::line_field( 'Habitat loss is a real problem.' ) );
check( 'label mid-line is not a label', '' === $F::line_field( 'This fish has a calm Temperament: really.' ) );
check( 'labels_found: distinct, first-seen order', [ 'scientific_name', 'temperament' ] === $F::labels_found( "Scientific Name: a\nTemperament: b\nScientific Name: c" ) );

// ── is_filled / filled_fields ───────────────────────────────────────────
echo "filled\n";
check( 'blank text is not filled', ! $F::is_filled( 'common_names', '   ' ) );
check( 'text is filled', $F::is_filled( 'common_names', 'Testie' ) );
check( 'reef safe must be yes / no / caution', $F::is_filled( 'reef_safe', 'caution' ) && $F::is_filled( 'reef_safe', 'Yes' ) && ! $F::is_filled( 'reef_safe', 'maybe' ) );
check( 'filled_fields lists only filled', [ 'scientific_name', 'reef_safe' ] === $F::filled_fields( [ 'scientific_name' => 'X', 'common_names' => '', 'reef_safe' => 'no' ] ) );

// ── is_old_style ────────────────────────────────────────────────────────
echo "is_old_style()\n";
$text = $F::normalise( $old );
check( 'labels typed, fields empty → old style', $F::is_old_style( $text, [] ) );
$all = [ 'scientific_name', 'common_names', 'max_length', 'min_tank_size', 'temperament', 'reef_safe', 'foods_feeding', 'habitat' ];
check( 'labels typed, every one of their fields filled → not old style (harmless)', ! $F::is_old_style( $text, $all ) );
check( 'one found field empty → old style', $F::is_old_style( $text, array_diff( $all, [ 'habitat' ] ) ) );
check( 'a single label is not enough', ! $F::is_old_style( "Scientific Name: X\nLovely fish.", [] ) );
check( 'Fun Facts counts as evidence, not as an empty field', ! $F::is_old_style( "Scientific Name: X\nFun Facts: yes", [ 'scientific_name' ] ) );
check( 'Fun Facts + an empty field label → old style', $F::is_old_style( "Temperament: calm\nFun Facts: yes", [] ) );

// ── prose() ─────────────────────────────────────────────────────────────
echo "prose()\n";
$p = $F::prose( $old, [] );
check( 'nothing filled: every fact stays readable', false !== strpos( $p, 'Scientific Name: Testus brightus' ) && false !== strpos( $p, 'Temperament: Peaceful' ) && false !== strpos( $p, 'Habitat and Behavior: Hovers' ), j( $p ) );
check( 'Fun Facts and what follows are kept', false !== strpos( $p, 'Fun Facts: It changes colour at night.' ) && false !== strpos( $p, 'Ships in a breather bag.' ), j( $p ) );
check( 'leading "Description:" dropped', 0 === strpos( $p, 'A bright little test fish' ), j( $p ) );
$p = $F::prose( $old, [ 'scientific_name', 'temperament' ] );
check( 'filled facts: their lines go', false === strpos( $p, 'Scientific Name' ) && false === strpos( $p, 'Temperament' ), j( $p ) );
check( 'unfilled facts: their lines stay', false !== strpos( $p, 'Common Names: Bright Test Fish' ) && false !== strpos( $p, 'Maximum Length: 3 inches' ), j( $p ) );
$p = $F::prose( $old, $all );
check( 'all filled: only the prose and Fun Facts are left', "A bright little test fish that settles in fast.\n\nFun Facts: It changes colour at night.\n\nShips in a breather bag." === $p, j( $p ) );
$squashed = '<p>Scientific Name: Testus</p><p>Lovely to watch.</p><p>Temperament: Calm</p>';
check( 'paragraphs without newlines: only the labelled paragraph goes, not the rest', "Lovely to watch.\nTemperament: Calm" === $F::prose( $squashed, [ 'scientific_name' ] ), j( $F::prose( $squashed, [ 'scientific_name' ] ) ) );
check( 'html entities are text, not markup', 'Rocks & caves' === $F::prose( 'Rocks &amp; caves', [] ) );
check( 'three blank lines shrink to one', "a\n\nb" === $F::prose( "a\n\n\n\n\nb", [] ) );

// ── shown_elsewhere() ───────────────────────────────────────────────────
echo "shown_elsewhere()\n";
$vals = [ 'scientific_name' => 'X', 'region' => '', 'foods_feeding' => 'mysis', 'habitat' => '', 'reef_safe' => 'yes' ];
check( 'fish: filled table facts and dossier facts', [ 'scientific_name', 'foods_feeding', 'reef_safe' ] === $F::shown_elsewhere( $vals, true, false ), j( $F::shown_elsewhere( $vals, true, false ) ) );
check( 'fish with a region tag: Region shows too', in_array( 'region', $F::shown_elsewhere( $vals, true, true ), true ) );
check( 'no table (inverts, dry goods): only the dossier facts', [ 'foods_feeding' ] === $F::shown_elsewhere( $vals, false, true ), j( $F::shown_elsewhere( $vals, false, true ) ) );

// ── extract() — the Tools migration's reading ───────────────────────────
echo "extract()\n";
$x = $F::extract( $text );
check( 'scientific name', 'Testus brightus' === ( $x['scientific_name'] ?? '' ), j( $x ) );
check( 'common names', 'Bright Test Fish, Testie' === ( $x['common_names'] ?? '' ) );
check( 'max length', '3 inches (7.5 cm)' === ( $x['max_length'] ?? '' ) );
check( 'min tank size', '30 gallons' === ( $x['min_tank_size'] ?? '' ) );
check( 'temperament', 'Peaceful' === ( $x['temperament'] ?? '' ) );
check( 'reef safety yes', 'yes' === ( $x['reef_safe'] ?? '' ) );
check( 'foods block', 'Frozen mysis twice a day.' === ( $x['foods_feeding'] ?? '' ), $x['foods_feeding'] ?? '' );
check( 'habitat block stops at Fun Facts', 'Hovers near rockwork.' === ( $x['habitat'] ?? '' ), $x['habitat'] ?? '' );
check( 'no region typed → none', ! isset( $x['region'] ) );
check( 'keys in field order', array_keys( $x ) === array_values( array_intersect( array_keys( FisHotel_Product_Facts::META ), array_keys( $x ) ) ) );
$x = $F::extract( "Max Length - 5 in\nMin. Tank Size: 55 gallons\nReef Safe: with caution\nRegion: Indo-Pacific\nFoods and Feeding:\nMeaty foods.\nNori too.\nHabitat: open water" );
check( 'short spellings', '5 in' === $x['max_length'] && '55 gallons' === $x['min_tank_size'] && 'Indo-Pacific' === $x['region'], j( $x ) );
check( 'caution', 'caution' === $x['reef_safe'] );
check( 'multi-line block joined', 'Meaty foods. Nori too.' === $x['foods_feeding'], $x['foods_feeding'] );
check( 'Reef Safe: No → no', 'no' === $F::extract( 'Reef Safe: No' )['reef_safe'] );
check( 'a value running into the next label is cut there', 'Testus' === $F::extract( 'Scientific Name: Testus Common Names: Tester' )['scientific_name'] );
check( 'plain text → nothing', [] === $F::extract( 'A lovely fish that eats everything.' ) );

// ── copy_plan() never overwrites ────────────────────────────────────────
echo "copy_plan()\n";
$plan = $F::copy_plan( $text, [ 'scientific_name' => 'Already typed', 'reef_safe' => 'maybe', 'temperament' => '  ' ] );
check( 'filled field left out', ! isset( $plan['scientific_name'] ) );
check( 'any stored value counts (even one the table cannot show)', ! isset( $plan['reef_safe'] ) );
check( 'blank field is filled', 'Peaceful' === ( $plan['temperament'] ?? '' ) );
check( 'missing field is filled', 'Bright Test Fish, Testie' === ( $plan['common_names'] ?? '' ) );

// ── WordPress edge: copy_from_description / product_is_old_style ────────
echo "copy_from_description()\n";
$GLOBALS['posts'][501] = [ 'post_content' => $old, 'post_excerpt' => 'Testus brightus' ];
$GLOBALS['meta'][501]  = [ '_fh_scientific_name' => 'Testus brightus', '_fh_temperament' => 'Shy' ];
check( 'old-style fish is old style', fishotel_fish_facts_old_style( 501 ) );
$written = fishotel_copy_facts_from_description( 501 );
check( 'returns the meta keys written', [ '_fh_common_names', '_fh_max_length', '_fh_min_tank_size', '_fh_reef_safe', '_fh_foods_feeding', '_fh_habitat' ] === $written, j( $written ) );
check( 'never overwrote the typed temperament', 'Shy' === $GLOBALS['meta'][501]['_fh_temperament'] );
check( 'wrote the max length', '3 inches (7.5 cm)' === $GLOBALS['meta'][501]['_fh_max_length'] );
check( 'after the copy it is no longer old style', ! fishotel_fish_facts_old_style( 501 ) );
$GLOBALS['wrote'] = [];
check( 'second copy writes nothing', [] === fishotel_copy_facts_from_description( 501 ) && [] === $GLOBALS['wrote'] );
check( 'no product → nothing', [] === fishotel_copy_facts_from_description( 0 ) && ! fishotel_fish_facts_old_style( 0 ) );
$GLOBALS['posts'][502] = [ 'post_content' => 'Just a nice fish.', 'post_excerpt' => '' ];
check( 'prose-only fish is not old style and copies nothing', ! fishotel_fish_facts_old_style( 502 ) && [] === fishotel_copy_facts_from_description( 502 ) );

echo "\n" . ( $failures ? "$failures FAILED" : 'all passed' ) . "\n";
exit( $failures ? 1 : 0 );
