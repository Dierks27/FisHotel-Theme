<?php
/**
 * Test for inc/product-layout.php — where each fact of a product goes on
 * its page (the "at a glance" row, the entries beside the description),
 * and what is printed when fields are empty: no lonely cell, no heading
 * with nothing under it, no empty band. Also the few things the product
 * page itself must keep doing. No WordPress, no database.
 *
 *   php tests/product-layout-test.php
 *
 * All products and facts below are invented.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/inc/product-layout.php';
require_once dirname( __DIR__ ) . '/inc/medication-store/listing-rules.php';

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
function j( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
/** Labels of a list of [ label, html ] rows. */
function labels( array $rows ) { return array_column( $rows, 0 ); }

$L    = 'FisHotel_Product_Layout';
$yes  = '<span class="fh-spec-badge fh-spec-badge--green">&#10003; Yes</span>';
$warn = '<span class="fh-spec-badge fh-spec-badge--amber">&#9888; With Caution</span>';

// A fish with every field filled, as single-product.php builds its rows.
$full = [
	[ 'Scientific Name', 'Pseudolampris ignifer' ],
	[ 'Common Names',    'Ember Wrasse, Lantern Wrasse, Glowfin' ],
	[ 'Max Length',      '4.5&quot;' ],
	[ 'Min Tank Size',   '55 Gallons' ],
	[ 'Temperament',     'Peaceful' ],
	[ 'Reef Safe',       $yes ],
	[ 'Region',          'Red Sea' ],
];

// ── split() ─────────────────────────────────────────────────────────────
echo "split()\n";
$s = $L::split( $full, 'Pseudolampris ignifer' );
check( 'short facts are the glance cells, in row order', [ 'Max Length', 'Min Tank Size', 'Temperament', 'Reef Safe', 'Region' ] === labels( $s['glance'] ), j( labels( $s['glance'] ) ) );
check( 'Scientific Name equal to the line under the name is not printed again', [] === $s['before'], j( $s['before'] ) );
check( 'Common Names is the last entry, labelled "Also Known As"', [ [ 'Also Known As', 'Ember Wrasse, Lantern Wrasse, Glowfin' ] ] === $s['after'], j( $s['after'] ) );

$s = $L::split( $full, '<em>PSEUDOLAMPRIS   ignifer</em> ' );
check( 'the comparison ignores tags, capitals and spacing', [] === $s['before'] );
$s = $L::split( $full, 'A lovely wrasse' );
check( 'a Scientific Name that differs is the first entry, never a glance cell', [ 'Scientific Name' ] === labels( $s['before'] ) && ! in_array( 'Scientific Name', labels( $s['glance'] ), true ), j( $s ) );
$s = $L::split( $full, '' );
check( 'no line under the name → Scientific Name is an entry', [ 'Scientific Name' ] === labels( $s['before'] ) );

$c28 = str_repeat( 'x', 28 );
$c29 = str_repeat( 'x', 29 );
$s   = $L::split( [ [ 'Temperament', $c28 ], [ 'Max Length', '3&quot;' ], [ 'Min Tank Size', $c29 ] ] );
check( '28 characters fit the glance row, 29 do not', [ 'Temperament', 'Max Length' ] === labels( $s['glance'] ) && [ 'Min Tank Size' ] === labels( $s['before'] ), j( $s ) );
check( 'characters are counted, not bytes or entities', $L::is_short( [ 'Region', str_repeat( 'é', 28 ) ] ) && $L::is_short( [ 'Max Length', str_repeat( '&quot;', 28 ) ] ) && ! $L::is_short( [ 'Region', str_repeat( 'é', 29 ) ] ) );
check( 'the Reef Safe badge always counts as short', $L::is_short( [ 'Reef Safe', $warn ] ) );

$s = $L::split( [ [ 'Max Length', '12&quot;' ], [ 'Temperament', 'Peaceful; nervous for the first weeks, and best kept with calm tankmates.' ], [ 'Common Names', 'Sandtile' ] ] );
check( 'one short fact only → no glance row; it is an entry, in row order', [] === $s['glance'] && [ 'Max Length', 'Temperament' ] === labels( $s['before'] ) && [ 'Also Known As' ] === labels( $s['after'] ), j( $s ) );
$s = $L::split( [ [ 'Max Length', '12&quot;' ], [ 'Min Tank Size', '125 Gallons' ], [ 'Temperament', 'Peaceful; nervous for the first weeks, and best kept with calm tankmates.' ], [ 'Reef Safe', $warn ] ] );
check( 'a long fact is an entry, the short ones are the row', [ 'Max Length', 'Min Tank Size', 'Reef Safe' ] === labels( $s['glance'] ) && [ 'Temperament' ] === labels( $s['before'] ), j( $s ) );
$seen = array_merge( labels( $s['glance'] ), labels( $s['before'] ), labels( $s['after'] ) );
check( 'every fact is printed exactly once', 4 === count( $seen ) && 4 === count( array_unique( $seen ) ) );

check( 'no rows → nothing', [ 'glance' => [], 'before' => [], 'after' => [] ] === $L::split( [] ) );
check( 'a row with no value is skipped', [ 'glance' => [], 'before' => [], 'after' => [] ] === $L::split( [ [ 'Max Length', '' ], [ 'Region', '  ' ] ] ) );

// A food: its source is the line under the name, the rest are long.
$food = FisHotel_Med_Listing::spec_rows( [
	'food_source' => 'Whole krill and kelp',
	'best_for'    => 'Small community fish that feed at the surface and in mid water',
	'nutrition'   => 'Protein 46%, fat 9%, fibre 3%, moisture 8%',
	'additives'   => 'Vitamin C, garlic',
], false, true, false );
$s = $L::split( $food, 'Whole krill and kelp' );
check( 'food: Food Source under the name is not repeated; one short row is an entry', [] === $s['glance'] && [ 'Best For', 'Nutrition Value', 'Additives & Enrichments' ] === labels( $s['before'] ), j( $s ) );

// A medication: only what spec_rows() gives — Contains and Reef Safe.
$med = FisHotel_Med_Listing::spec_rows( [ 'active_ingredient' => 'Samplazole 99%', 'reef_safe' => 'no', 'best_for' => 'Flukes', 'treats' => 'Ich', 'use_in' => 'QT' ], true, false, false, [ 'no' => '<span class="fh-spec-badge fh-spec-badge--amber">&#10007; No</span>' ] );
$s   = $L::split( $med, '' );
check( 'medication: Contains and Reef Safe, nothing else, as the glance row', [ 'Contains', 'Reef Safe' ] === labels( $s['glance'] ) && [] === $s['before'] && [] === $s['after'], j( $s ) );

// ── care_notes() ────────────────────────────────────────────────────────
echo "care_notes()\n";
check( 'both notes, in order', [ [ 'Foods & Feeding', 'Mysis.' ], [ 'Habitat & Behavior', 'Rubble.' ] ] === $L::care_notes( 'Mysis.', 'Rubble.', false ) );
check( 'one note', [ [ 'Habitat & Behavior', 'Rubble.' ] ] === $L::care_notes( '', 'Rubble.', false ) );
check( 'none, and blank text is none', [] === $L::care_notes( '', "  \n", false ) );
check( 'never on a medication page, whatever is typed or defaulted', [] === $L::care_notes( 'Feed twice a day.', 'Reef.', true ) );

// ── link_tags() ─────────────────────────────────────────────────────────
echo "link_tags()\n";
$glance = $L::split( $full, 'Pseudolampris ignifer' )['glance'];
$plan   = $L::link_tags( $glance, [ 4 => 'Carnivore', 7 => 'peaceful', 9 => 'Reef Safe', 12 => 'RED SEA' ] );
check( 'Temperament, Reef Safe (Yes) and Region link to their tags', [ 2 => 7, 3 => 9, 4 => 12 ] === $plan['links'], j( $plan ) );
check( 'those pills are not printed; the others stay', [ 7, 9, 12 ] === $plan['drop'] );
$plan = $L::link_tags( $glance, [ 1 => 'Carnivore', 2 => 'Semi-aggressive', 3 => 'Caribbean' ] );
check( 'no tag says the same → nothing linked, nothing dropped', [ 'links' => [], 'drop' => [] ] === $plan );
$caution = [ [ 'Max Length', '3&quot;' ], [ 'Reef Safe', $warn ] ];
check( 'Reef Safe "With Caution" keeps the Reef Safe pill', [ 'links' => [], 'drop' => [] ] === $L::link_tags( $caution, [ 'Reef Safe' ] ) );
check( 'Max Length never matches a tag', [ 'links' => [], 'drop' => [] ] === $L::link_tags( [ [ 'Max Length', 'Peaceful' ], [ 'Min Tank Size', 'Red Sea' ] ], [ 'Peaceful', 'Red Sea' ] ) );
check( 'no glance row → every pill stays', [ 'links' => [], 'drop' => [] ] === $L::link_tags( [], [ 'Peaceful', 'Reef Safe' ] ) );
$plan = $L::link_tags( [ [ 'Temperament', 'Peaceful' ], [ 'Region', 'Peaceful' ] ], [ 'Peaceful' ] );
check( 'one tag is used for one cell only', [ 0 => 0 ] === $plan['links'] && [ 0 ] === $plan['drop'], j( $plan ) );

// ── top area ────────────────────────────────────────────────────────────
echo "top area\n";
check( 'Coming Soon has no buy form', true === $L::no_buy_form( true, false, false, false ) );
check( 'a fish that cannot be bought has no buy form', true === $L::no_buy_form( false, true, false, false ) );
check( 'a gift card and an Amazon medication print their own', false === $L::no_buy_form( false, true, true, false ) && false === $L::no_buy_form( false, true, false, true ) );
check( 'a product that can be bought has one', false === $L::no_buy_form( false, false, false, false ) );
check( 'glance row under the photo and buy box', 'fh-product-layout--facts-below' === $L::top_modifier( 5, false ) );
check( 'glance row beside the photo when there is no buy form', 'fh-product-layout--facts-beside' === $L::top_modifier( 2, true ) );
check( 'no glance row → no class', '' === $L::top_modifier( 1, false ) && '' === $L::top_modifier( 0, true ) );

// ── story heading ───────────────────────────────────────────────────────
echo "story_word()\n";
check( 'fish', 'Fish' === $L::story_word( true, false, '', false ) );
check( 'medication', 'Product' === $L::story_word( false, true, FisHotel_Med_Listing::about_word( true, false, false ), false ) );
check( 'medicated flake or pellet', 'Food' === $L::story_word( false, true, FisHotel_Med_Listing::about_word( true, true, true ), true ) );
check( 'in the Medications category with no other sign', 'Product' === $L::story_word( false, true, '', false ) );
check( 'food', 'Food' === $L::story_word( false, false, 'Food', true ) );
check( 'anything else → "Description"', '' === $L::story_word( false, false, '', false ) );
check( 'never "Medication"', ! in_array( 'Medication', [ $L::story_word( false, true, '', false ), $L::story_word( false, true, 'Product', false ) ], true ) );

// ── body(): every combination of filled and empty ───────────────────────
echo "body()\n";
$b = $L::body( [ 'has_text' => true, 'facts' => 3, 'before' => 0, 'care' => 2, 'after' => 1, 'has_tools' => true, 'is_fish' => true ] );
check( 'everything filled: two columns, both headings', $b['band'] && $b['story'] && $b['prose'] && $b['card'] && $b['details'] && $b['dossier_title'] && 'fh-pdp-body' === $b['band_class'] && 'fh-dossier' === $b['dossier_class'], j( $b ) );
check( 'entries are h3 under "Fish Dossier", and so is the quarantine label', 'h3' === $b['entry_tag'] && 'h3' === $b['plan_tag'] );

foreach ( [ 0, 1, 2 ] as $care ) {
	$b = $L::body( [ 'has_text' => true, 'facts' => 0, 'before' => 0, 'care' => $care, 'after' => 0, 'has_tools' => true, 'is_fish' => true ] );
	if ( 0 === $care ) {
		check( '0 care notes: no "Fish Dossier" heading; the quarantine label heads the column', $b['details'] && ! $b['dossier_title'] && 'fh-dossier fh-dossier--tools-only' === $b['dossier_class'] && 'h2' === $b['plan_tag'], j( $b ) );
	} else {
		check( "$care care note(s): the same plain list under \"Fish Dossier\"", $b['details'] && $b['dossier_title'] && 'fh-dossier' === $b['dossier_class'] && $care === $b['entries'], j( $b ) );
	}
}

$b = $L::body( [ 'has_text' => false, 'facts' => 0, 'before' => 0, 'care' => 0, 'after' => 0, 'has_tools' => false, 'is_fish' => false ] );
check( 'nothing filled in: no band at all', false === $b['band'] && false === $b['story'] && false === $b['details'], j( $b ) );

$b = $L::body( [ 'has_text' => true, 'facts' => 0, 'before' => 0, 'care' => 0, 'after' => 0, 'has_tools' => false, 'is_fish' => false ] );
check( 'a description only (sticker, gift card): one column, no details', $b['band'] && $b['story'] && ! $b['details'] && 'fh-pdp-body fh-pdp-body--solo' === $b['band_class'] && ! $b['card'], j( $b ) );

$b = $L::body( [ 'has_text' => false, 'facts' => 2, 'before' => 0, 'care' => 0, 'after' => 0, 'has_tools' => false, 'is_fish' => false ] );
check( 'fun facts and no other text: the story has the card and no empty text block', $b['story'] && $b['card'] && ! $b['prose'] );

$b = $L::body( [ 'has_text' => false, 'facts' => 0, 'before' => 1, 'care' => 2, 'after' => 0, 'has_tools' => true, 'is_fish' => true ] );
check( 'facts and care notes but no description: details alone, across the page', $b['band'] && ! $b['story'] && 'fh-pdp-body fh-pdp-body--solo fh-pdp-body--care-only' === $b['band_class'] && $b['dossier_title'] && 'h3' === $b['entry_tag'], j( $b ) );

$b = $L::body( [ 'has_text' => true, 'facts' => 0, 'before' => 3, 'care' => 0, 'after' => 0, 'has_tools' => false, 'is_fish' => false ] );
check( 'food or medication rows: a plain list, no "Fish Dossier" over it', $b['details'] && ! $b['lede'] && ! $b['dossier_title'] && 'fh-dossier fh-dossier--untitled' === $b['dossier_class'], j( $b ) );
check( '… printed BEFORE the description in the HTML, so a phone shows "Contains" above the text, not last', true === $b['details_first'] && 'fh-pdp-body fh-pdp-body--details-first' === $b['band_class'] && 'h2' === $b['entry_tag'], j( $b ) );

// One or two rows alone in a column look lost (a medicated food with only
// its Food Source): they go above the description instead, in one column.
foreach ( [ 1, 2 ] as $rows ) {
	$b = $L::body( [ 'has_text' => true, 'facts' => 0, 'before' => $rows, 'care' => 0, 'after' => 0, 'has_tools' => false, 'is_fish' => false ] );
	check( "$rows row(s) on a food or medication: above the description, no second column", $b['lede'] && ! $b['details'] && ! $b['details_first'] && $b['story'] && 'fh-pdp-body fh-pdp-body--solo' === $b['band_class'] && $rows === $b['entries'], j( $b ) );
}
$b = $L::body( [ 'has_text' => true, 'facts' => 0, 'before' => 0, 'care' => 1, 'after' => 0, 'has_tools' => true, 'is_fish' => true ] );
check( 'a fish keeps its Dossier column with one care note (the quarantine buttons sit under it)', ! $b['lede'] && $b['details'] && ! $b['details_first'] && $b['dossier_title'], j( $b ) );
$b = $L::body( [ 'has_text' => true, 'facts' => 0, 'before' => 1, 'care' => 0, 'after' => 0, 'has_tools' => false, 'is_fish' => true ] );
check( 'one entry under "Fish Dossier" stays a column: it has its heading', ! $b['lede'] && $b['details'] && $b['dossier_title'] && ! $b['details_first'], j( $b ) );
$b = $L::body( [ 'has_text' => false, 'facts' => 0, 'before' => 1, 'care' => 0, 'after' => 0, 'has_tools' => false, 'is_fish' => false ] );
check( 'one row and no description: the row alone, nothing to sit above', ! $b['lede'] && $b['details'] && ! $b['details_first'] && 'h2' === $b['entry_tag'], j( $b ) );

$b = $L::body( [ 'has_text' => false, 'facts' => 0, 'before' => 2, 'care' => 0, 'after' => 0, 'has_tools' => false, 'is_fish' => false ] );
check( 'rows with no heading above them at all are h2, so no heading level is skipped', ! $b['dossier_title'] && 'h2' === $b['entry_tag'], j( $b ) );

$b = $L::body( [ 'has_text' => true, 'facts' => 0, 'before' => 0, 'care' => 1, 'after' => 0, 'has_tools' => false, 'is_fish' => false ] );
check( 'a care note typed on a product that is not a fish still gets "Fish Dossier"', $b['dossier_title'] );

// No combination ever gives a heading with nothing under it or an empty column.
$bad = [];
foreach ( [ true, false ] as $text ) foreach ( [ 0, 2 ] as $facts ) foreach ( [ 0, 1 ] as $before ) foreach ( [ 0, 1, 2 ] as $care ) foreach ( [ 0, 1 ] as $after ) foreach ( [ true, false ] as $tools ) foreach ( [ true, false ] as $fish ) {
	$in = [ 'has_text' => $text, 'facts' => $facts, 'before' => $before, 'care' => $care, 'after' => $after, 'has_tools' => $tools, 'is_fish' => $fish ];
	$b  = $L::body( $in );
	$n  = $before + $care + $after;
	if ( $b['story'] !== ( $text || $facts > 0 ) )                       $bad[] = 'story ' . j( $in );
	$lede = ( $text || $facts > 0 ) && $n > 0 && $n <= 2 && ! $tools && ! $fish && 0 === $care;
	if ( $b['lede'] !== $lede )                                          $bad[] = 'lede ' . j( $in );
	if ( $b['details'] !== ( ( $n > 0 || $tools ) && ! $lede ) )         $bad[] = 'details ' . j( $in );
	if ( $b['details_first'] && ( ! $b['details'] || ! $b['story'] || $b['dossier_title'] || $tools ) ) $bad[] = 'details first ' . j( $in );
	if ( $b['details_first'] && 'h2' !== $b['entry_tag'] )               $bad[] = 'rows before the story heading must be h2 ' . j( $in );
	if ( $b['band'] !== ( $b['story'] || $b['details'] ) )               $bad[] = 'band ' . j( $in );
	if ( $b['dossier_title'] && 0 === $n )                               $bad[] = 'title over nothing ' . j( $in );
	if ( $b['card'] && 0 === $facts )                                    $bad[] = 'card over nothing ' . j( $in );
	if ( $b['prose'] && ! $text )                                        $bad[] = 'empty text block ' . j( $in );
	if ( ( false !== strpos( $b['band_class'], '--solo' ) ) !== ( $b['story'] xor $b['details'] ) ) $bad[] = 'solo ' . j( $in );
	if ( 'h3' === $b['entry_tag'] && ! $b['story'] && ! $b['dossier_title'] && $n > 0 )             $bad[] = 'h3 under no h2 ' . j( $in );
	if ( 'h3' === $b['entry_tag'] && $b['details_first'] )                                          $bad[] = 'h3 before the first h2 ' . j( $in );
	if ( $tools && 'h3' === $b['plan_tag'] && 0 === $n )                 $bad[] = 'quarantine label h3 with no heading above it ' . j( $in );
}
check( 'no empty heading, column or band in any of 192 combinations', [] === $bad, implode( ' | ', array_slice( $bad, 0, 3 ) ) );

// ── crumb_order(): every category is one link in the breadcrumb ─────────
echo "crumb_order()\n";
$cat = function ( $id, $name, $count, $parent = 0 ) {
	return [ 'id' => $id, 'name' => $name, 'count' => $count, 'parent' => $parent ];
};
$order = function ( array $cats ) use ( $L ) {
	$names = [];
	foreach ( $L::crumb_order( $cats ) as $key ) {
		$names[] = $cats[ $key ]['name'];
	}
	return $names;
};
check( 'the wide category first, whichever name sorts first',
	[ 'Quarantined Fish', 'Gobies & Dartfish' ] === $order( [ $cat( 80, 'Gobies & Dartfish', 6 ), $cat( 73, 'Quarantined Fish', 40 ) ] )
	&& [ 'Quarantined Fish', 'Wrasses' ] === $order( [ $cat( 73, 'Quarantined Fish', 40 ), $cat( 81, 'Wrasses', 9 ) ] ),
	j( $order( [ $cat( 80, 'Gobies & Dartfish', 6 ), $cat( 73, 'Quarantined Fish', 40 ) ] ) )
);
check( 'a parent before its child, whatever their sizes',
	[ 'Foods', 'Medicated Food' ] === $order( [ $cat( 5, 'Medicated Food', 12, 4 ), $cat( 4, 'Foods', 3 ) ] )
);
check( 'a child follows its own parent, not somebody else\'s',
	[ 'Medications', 'Foods', 'Pellets' ] === $order( [ $cat( 9, 'Pellets', 2, 4 ), $cat( 76, 'Medications', 30 ), $cat( 4, 'Foods', 8 ) ] ),
	j( $order( [ $cat( 9, 'Pellets', 2, 4 ), $cat( 76, 'Medications', 30 ), $cat( 4, 'Foods', 8 ) ] ) )
);
check( 'same size: by name, then by id — always the same order',
	[ 'Alpha', 'beta', 'Beta' ] === $order( [ $cat( 3, 'Beta', 5 ), $cat( 2, 'beta', 5 ), $cat( 1, 'Alpha', 5 ) ] ),
	j( $order( [ $cat( 3, 'Beta', 5 ), $cat( 2, 'beta', 5 ), $cat( 1, 'Alpha', 5 ) ] ) )
);
check( 'every category exactly once; one category and none are fine',
	[ 0 ] === $L::crumb_order( [ $cat( 73, 'Quarantined Fish', 40 ) ] ) && [] === $L::crumb_order( [] )
	&& 3 === count( array_unique( $L::crumb_order( [ $cat( 1, 'A', 1, 2 ), $cat( 2, 'B', 1, 1 ), $cat( 3, 'C', 1 ) ] ) ) )
);
check( 'the keys given are the keys returned', [ 'x', 'y' ] === $L::crumb_order( [ 'y' => $cat( 81, 'Wrasses', 9 ), 'x' => $cat( 73, 'Quarantined Fish', 40 ) ] ) );

// ── The product page ────────────────────────────────────────────────────
echo "the product page\n";
$pdp = file_get_contents( dirname( __DIR__ ) . '/woocommerce/single-product.php' );
check( 'the glance row is printed only when it has a place', false !== strpos( $pdp, "<?php if ( \$pdp_top_class !== '' ) : ?>\n    <dl class=\"fh-glance\">" ) );
check( 'the body band is printed only when it has something in it', false !== strpos( $pdp, "<?php if ( ! empty( \$pdp_body['band'] ) ) :" ) );
check( 'care notes come from care_notes() alone, which never gives one on a medication page',
	1 === substr_count( $pdp, 'FisHotel_Product_Layout::care_notes(' ) && false !== strpos( $pdp, "\$habitat ? \$habitat : '', \$is_med_or_aff )" )
	&& 1 === substr_count( $pdp, 'foreach ( $pdp_care as' ) && false === strpos( $pdp, '( $foods_feeding || $habitat )' )
);
$fence = strpos( $pdp, "if ( \$is_fish && class_exists( 'FisHotel_Admin_Settings' ) ) {" );
check( 'the site-wide care defaults are read for a fish only (no "Fish Dossier" on a gift card or a sticker)',
	false !== $fence
	&& 1 === substr_count( $pdp, "'fh_default_foods'" ) && strpos( $pdp, "'fh_default_foods'" ) > $fence
	&& 1 === substr_count( $pdp, "'fh_default_habitat'" ) && strpos( $pdp, "'fh_default_habitat'" ) > $fence
	&& strpos( $pdp, "'fh_default_habitat'" ) < strpos( $pdp, 'FisHotel_Product_Layout::care_notes(' )
);
check( 'pills are matched to cells on a fish page only', false !== strpos( $pdp, 'if ( $is_fish && $pdp_glance && $display_tags )' ) );
check( 'the layout rules are always loaded: no fallback that would print a page without its description', false === strpos( $pdp, "class_exists( 'FisHotel_Product_Layout' )" ) && false === strpos( $pdp, "[ 'band' => false ]" ) );
check( '"cannot be bought" is worked out once', 1 === substr_count( $pdp, '! $product->is_purchasable()' ) && 1 === substr_count( $pdp, '$is_unavailable = ' ) );
check( 'every category is a link in the breadcrumb, in crumb_order()', false !== strpos( $pdp, 'FisHotel_Product_Layout::crumb_order(' ) && false === strpos( $pdp, '$product_cats[0]' ) );
check( 'one or two rows go above the description as a list, not as headings', false !== strpos( $pdp, "<?php if ( \$pdp_body['lede'] ) : ?>\n            <dl class=\"fh-story__specs\">" ) );
check( 'the details column is printed once: before the story when its rows have no heading (food, medication), after it otherwise',
	1 === substr_count( $pdp, "if ( \$pdp_body['details_first'] ) { echo \$pdp_details_html; }" )
	&& 1 === substr_count( $pdp, "if ( ! \$pdp_body['details_first'] ) { echo \$pdp_details_html; }" )
	&& strpos( $pdp, "if ( \$pdp_body['details_first'] ) { echo" ) < strpos( $pdp, '<div class="fh-story">' )
	&& strpos( $pdp, '<div class="fh-story">' ) < strpos( $pdp, "if ( ! \$pdp_body['details_first'] ) { echo" )
);
check( 'the old bands are gone', false === strpos( $pdp, 'fh-species' ) && false === strpos( $pdp, 'fh-careguide' ) && false === strpos( $pdp, 'class="fh-plan-qt"' ) );
check( 'Region is no longer passed to the certificate, and no Category row', false === strpos( $pdp, "'region' => \$region" ) && false === strpos( $pdp, 'wc_get_product_category_list' ) );
check( 'the SKU strip is printed only when it has something in it', false !== strpos( $pdp, "if ( trim( \$pdp_meta ) !== '' ) : ?>" ) );
check( 'both product-meta hooks still fire', 1 === substr_count( $pdp, "do_action( 'woocommerce_product_meta_start' )" ) && 1 === substr_count( $pdp, "do_action( 'woocommerce_product_meta_end' )" ) );
$hooks = [ 'woocommerce_before_single_product', 'woocommerce_before_single_product_summary', 'woocommerce_single_product_summary', 'woocommerce_before_add_to_cart_form', 'woocommerce_before_add_to_cart_button', 'woocommerce_after_add_to_cart_button', 'woocommerce_after_add_to_cart_form', 'woocommerce_after_single_product_summary', 'woocommerce_after_single_product' ];
$missing = [];
foreach ( $hooks as $hook ) {
	if ( ! preg_match( "/do_action\(\s*'" . $hook . "'\s*\)/", $pdp ) ) {
		$missing[] = $hook;
	}
}
check( 'every WooCommerce hook of the page still fires', [] === $missing, implode( ', ', $missing ) );
check( 'the body band comes after the summary hook and before "More Available Now"',
	strpos( $pdp, "do_action( 'woocommerce_after_single_product_summary' )" ) < strpos( $pdp, 'class="fh-pdp-body__inner"' )
	&& strpos( $pdp, 'class="fh-pdp-body__inner"' ) < strpos( $pdp, 'class="fh-related"' )
);
// Private things the page must never read.
$private = [ '_fh_notes', 'hotel_notes', '_fishotel_treats', '_fishotel_use_in', 'fishotel_cleanroom', '_fishotel_compat_category', '_cost', 'supplier', 'reserved', 'tank_number', 'batch' ];
$found   = [];
foreach ( $private as $word ) {
	if ( false !== stripos( $pdp, $word ) ) {
		$found[] = $word;
	}
}
check( 'the page reads no note, cost, supplier, batch, tank or customer field', [] === $found, implode( ', ', $found ) );
$read = [];
preg_match_all( "/get_post_meta\( \\\$product_id, '([^']+)'/", $pdp, $m );
$read = array_values( array_unique( $m[1] ) );
sort( $read );
$known = [ '_fh_common_names', '_fh_foods_feeding', '_fh_habitat', '_fh_max_length', '_fh_min_tank_size', '_fh_reef_safe', '_fh_scientific_name', '_fh_temperament', '_fishotel_active_ingredient', '_fishotel_additives', '_fishotel_best_for', '_fishotel_food_source', '_fishotel_nutrition', '_fishotel_reef_safe' ];
check( 'the fields the page reads are the ones v1.29.0 read — nothing new', $known === $read, j( array_merge( array_diff( $read, $known ), array_diff( $known, $read ) ) ) );
check( 'nothing is stored by the page', false === strpos( $pdp, 'update_post_meta' ) && false === strpos( $pdp, 'update_option' ) );

$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/main.css' ) . file_get_contents( dirname( __DIR__ ) . '/assets/css/woocommerce.css' );
check( 'the old bands have no CSS left', ! preg_match( '/\.fh-(species|careguide)[\w-]*\s*[{,]/', $css ) && ! preg_match( '/\.fh-plan-qt(__inner|__label|__links)?\s*[{,]/', $css ) );
foreach ( [ '.fh-glance', '.fh-pdp-body', '.fh-story__prose', '.fh-story__specs', '.fh-story__spec', '.fh-funfacts', '.fh-dossier', '.fh-dossier__plan', '.fh-product-layout--facts-below', '.fh-product-layout--facts-beside', '.fh-pdp-body--details-first' ] as $class ) {
	check( "CSS for $class", false !== strpos( $css, $class . ' ' ) || false !== strpos( $css, $class . ',' ) );
}
// Beside the photo the list sits under the notice and its rows stretch to
// the photo's bottom edge; it is never pinned to the bottom over a gap, and
// up to 1024px it is the ordinary row across the page.
$beside = substr( $css, strpos( $css, '@media (min-width: 1025px) {' ) );
$beside = substr( $beside, 0, strpos( $beside, "\n}\n" ) );
check( 'facts beside the photo: only from 1025px, stretched, not pinned to the bottom',
	false !== strpos( $beside, '.fh-product-layout--facts-beside .fh-glance {' ) && false !== strpos( $beside, 'align-self: stretch;' )
	&& false === strpos( $beside, 'align-self: end' ) && false !== strpos( $beside, 'grid-template-rows: auto 1fr;' )
	&& ! preg_match( '/@media \(min-width: 769px\) \{\s*\.fh-product-layout--facts-beside/', $css ),
	substr( $beside, 0, 120 )
);
check( 'the photo does not follow the scroll over a facts row under it, at any width',
	false !== strpos( $css, '.fh-product-layout--facts-below .fh-gallery { position: static; }' )
	&& (bool) preg_match( '/@media \(max-width: 1024px\) \{\s*\.fh-product-layout--facts-beside \.fh-gallery \{ position: static; \}/', $css )
);
check( 'the breadcrumb wraps on the product page', (bool) preg_match( '/\.fishotel-product-page \.page-hero__breadcrumb \{[^}]*flex-wrap: wrap/', $css ) );
check( 'no new font, image or script in the new CSS', ! preg_match( '/\.fh-(glance|pdp-body|story|funfacts|dossier)[^{}]*\{[^}]*(url\(|@import|font-family:\s*["\'])/', $css ) );

echo "\n" . ( $failures ? "$failures FAILED" : 'all passed' ) . "\n";
exit( $failures ? 1 : 0 );
