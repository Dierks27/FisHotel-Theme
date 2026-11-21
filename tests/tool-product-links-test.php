<?php
/**
 * Stub-based test for the pure title-matching rules in
 * inc/tool-product-links.php — no WordPress, no database.
 *
 *   php tests/tool-product-links-test.php
 *
 * The catalog rows below are the live fishotel.com Medications tree as
 * fetched on 2026-10-07 (Store API): id, title and product_cat slugs. The
 * medication list is read from the real seed JSON so a renamed or added med
 * is covered automatically. The expected map is the hand-verified result
 * for rule (d) (title match) — the only rule that fires on the live site,
 * which has no dosing anchors and no SKUs.
 *
 * @package FisHotel
 */

// ── Minimal WordPress stubs so the module file loads ────────────────────
if ( ! defined( 'ABSPATH' ) )         define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) define( 'HOUR_IN_SECONDS', 3600 );
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }

require_once dirname( __DIR__ ) . '/inc/tool-product-links.php';

// ── Live Medications-tree catalog (product_id => title, cats) ──────────
$catalog_rows = [
	34269 => [ 'title' => 'Nitrofuracin Green',       'cats' => [ 'antibacterial', 'medications', 'powders' ] ],
	33401 => [ 'title' => 'General Antibiotic Cure',  'cats' => [ 'antibacterial', 'medications', 'powders' ] ],
	33397 => [ 'title' => 'Metro Double Pellet',      'cats' => [ 'antibacterial', 'dewormer', 'medications', 'pellets' ] ],
	33389 => [ 'title' => 'Fenbendazole Powder',      'cats' => [ 'dewormer', 'medications', 'powders' ] ],
	33848 => [ 'title' => 'Anti-Parasite Duo Pellet', 'cats' => [ 'dewormer', 'medications', 'pellets' ] ],
	33829 => [ 'title' => 'Anti-Parasite Quad Pellet','cats' => [ 'dewormer', 'medications', 'pellets' ] ],
	33718 => [ 'title' => 'Fenbendazole Pellet',      'cats' => [ 'dewormer', 'medications', 'pellets' ] ],
	33701 => [ 'title' => 'Erythromycin Pellet',      'cats' => [ 'antibacterial', 'medications', 'pellets' ] ],
	33665 => [ 'title' => 'Chloroquine Pellet',       'cats' => [ 'dewormer', 'medications', 'pellets' ] ],
	33628 => [ 'title' => 'Antibiotic Duo Pellet',    'cats' => [ 'antibacterial', 'medications', 'pellets' ] ],
	33517 => [ 'title' => 'Praziquantel Flake',       'cats' => [ 'dewormer', 'flakes', 'medications' ] ],
	33510 => [ 'title' => 'Nitrofurazone Flake',      'cats' => [ 'antibacterial', 'flakes', 'medications' ] ],
	33503 => [ 'title' => 'Metro Double Flake',       'cats' => [ 'antibacterial', 'dewormer', 'flakes', 'medications' ] ],
	33489 => [ 'title' => 'Kanamycin Flake',          'cats' => [ 'antibacterial', 'flakes', 'medications' ] ],
	33479 => [ 'title' => 'General Trio Flake',       'cats' => [ 'antibacterial', 'dewormer', 'flakes', 'medications' ] ],
	33472 => [ 'title' => 'Fenbendazole Flake',       'cats' => [ 'dewormer', 'flakes', 'medications' ] ],
	33465 => [ 'title' => 'Erythromycin Flake',       'cats' => [ 'antibacterial', 'flakes', 'medications' ] ],
	33458 => [ 'title' => 'Chloroquine Flake',        'cats' => [ 'dewormer', 'flakes', 'medications' ] ],
	33448 => [ 'title' => 'Antibiotic Duo Flake',     'cats' => [ 'antibacterial', 'flakes', 'medications' ] ],
	33438 => [ 'title' => 'Anti-Parasite Quad Flake', 'cats' => [ 'dewormer', 'flakes', 'medications' ] ],
	33428 => [ 'title' => 'Anti-Parasite Duo Flake',  'cats' => [ 'dewormer', 'flakes', 'medications' ] ],
	33413 => [ 'title' => 'Nitrofurazone Powder',     'cats' => [ 'antibacterial', 'medications', 'powders' ] ],
	33409 => [ 'title' => 'Kanamycin Sulfate',        'cats' => [ 'antibacterial', 'medications', 'powders' ] ],
	33405 => [ 'title' => 'General Parasite Cure',    'cats' => [ 'antiparasitic', 'dewormer', 'medications', 'powders' ] ],
	33380 => [ 'title' => 'Copper Power',             'cats' => [ 'antiparasitic', 'liquid', 'medications' ] ], // simple, amazon-mode
	33811 => [ 'title' => 'Praziquantel Pellet',      'cats' => [ 'dewormer', 'medications', 'pellets' ] ],
	33796 => [ 'title' => 'Nitrofurazone Pellet',     'cats' => [ 'antibacterial', 'medications', 'pellets' ] ],
	33749 => [ 'title' => 'Kanamycin Pellet',         'cats' => [ 'antibacterial', 'medications', 'pellets' ] ],
	33732 => [ 'title' => 'General Trio Pellet',      'cats' => [ 'antibacterial', 'dewormer', 'medications', 'pellets' ] ],
	33621 => [ 'title' => 'TMP Sulfa Powder',         'cats' => [ 'antibacterial', 'medications', 'powders' ] ],
	33600 => [ 'title' => 'Metronidazole Powder',     'cats' => [ 'antibacterial', 'antiparasitic', 'medications', 'powders' ] ],
	33578 => [ 'title' => 'General Trio Powder',      'cats' => [ 'antibacterial', 'dewormer', 'medications', 'powders' ] ],
	33556 => [ 'title' => 'Fluconazole Powder',       'cats' => [ 'antiparasitic', 'medications', 'powders' ] ],
	33524 => [ 'title' => 'Enrofloxacin',             'cats' => [ 'antibacterial', 'medications', 'powders' ] ],
	33385 => [ 'title' => 'Praziquantel Powder',      'cats' => [ 'antiparasitic', 'dewormer', 'medications', 'powders' ] ],
	33768 => [ 'title' => 'Levamisole Pellet',        'cats' => [ 'dewormer', 'medications', 'pellets' ] ],
	33496 => [ 'title' => 'Levamisole Flake',         'cats' => [ 'dewormer', 'flakes', 'medications' ] ],
	33417 => [ 'title' => 'Methylene Blue Powder',    'cats' => [ 'antiparasitic', 'medications', 'powders' ] ],
];

// ── Medication list from the real seed ──────────────────────────────────
$seed_path = dirname( __DIR__ ) . '/inc/calculator/medication-data-seed.json';
$seed      = json_decode( (string) file_get_contents( $seed_path ), true );
if ( empty( $seed['medications'] ) ) {
	fwrite( STDERR, "Could not read medications from $seed_path\n" );
	exit( 1 );
}
$catalog = [];
foreach ( $seed['medications'] as $med ) {
	$catalog[ $med['med_id'] ] = isset( $med['name_generic'] ) ? $med['name_generic'] : $med['med_id'];
}

// ── Expected rule-(d) result on the live catalog (0 = fallback) ─────────
$expected = [
	'copper_power'                => 33380, // Copper Power (amazon-mode is allowed)
	'praziquantel'                => 33385, // Praziquantel Powder, over Flake 33517 / Pellet 33811
	'metronidazole'               => 33600, // Metronidazole Powder; 'metro double' never matches
	'chloroquine_phosphate'       => 33458, // Chloroquine Flake (no powder sold), over Pellet 33665
	'methylene_blue'              => 33417, // Methylene Blue Powder
	'fenbendazole'                => 33389, // Fenbendazole Powder, over Flake 33472 / Pellet 33718
	'fenbendazole_internal'       => 33389, // same product — shared name is allowed now
	'enrofloxacin'                => 33524,
	'kanamycin_sulfate'           => 33409, // Kanamycin Sulfate (powder), over Flake 33489 / Pellet 33749
	'nitrofurazone'               => 33413, // Nitrofurazone Powder, over Flake 33510 / Pellet 33796
	'nitrofuracin_green'          => 34269,
	'sulfa_tmp'                   => 33621, // TMP Sulfa Powder
	'erythromycin'                => 33465, // Erythromycin Flake (no powder sold), over Pellet 33701
	'formalin_37'                 => 0,
	'sulfa_drugs'                 => 0,     // 'sulfa' is not a title prefix of 'tmp_sulfa_powder'
	'acriflavine'                 => 0,
	'hydrogen_peroxide_3'         => 0,
	'cupramine'                   => 0,
	'cuprion'                     => 0,
	'coppersafe'                  => 0,
	'copper_sulfate_pentahydrate' => 0,
	'ciprofloxacin'               => 0,
	'neomycin_sulfate'            => 0,
	'minocycline'                 => 0,
	'hyposalinity'                => 0,     // never auto-links
];

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

echo "resolve_titles() against the live catalog\n";
$actual = FisHotel_Tool_Product_Links::resolve_titles( $catalog, $catalog_rows );

// Every seed med must be covered by the expectation, and vice versa.
check( 'expected map covers every seed med', ! array_diff_key( $catalog, $expected ), 'missing: ' . implode( ', ', array_keys( array_diff_key( $catalog, $expected ) ) ) );
check( 'no stale expectation',               ! array_diff_key( $expected, $catalog ), 'stale: ' . implode( ', ', array_keys( array_diff_key( $expected, $catalog ) ) ) );

foreach ( $expected as $med_id => $pid ) {
	$got   = isset( $actual[ $med_id ] ) ? (int) $actual[ $med_id ] : null;
	$label = isset( $catalog_rows[ $pid ] ) ? $catalog_rows[ $pid ]['title'] . " ($pid)" : 'fallback';
	check( str_pad( $med_id, 28 ) . ' → ' . $label, $got === $pid, 'got ' . var_export( $got, true ) );
}

echo "\nranking rules\n";
$ranked = FisHotel_Tool_Product_Links::rank_title_candidates( [ 'praziquantel' ], $catalog_rows );
check( 'praziquantel ranks powder > flake > pellet', $ranked === [ 33385, 33517, 33811 ], json_encode( $ranked ) );

$tie_rows = [
	10 => [ 'title' => 'Praziquantel Powder 250g', 'cats' => [ 'powders' ] ],
	11 => [ 'title' => 'Praziquantel Powder',      'cats' => [ 'powders' ] ],
	12 => [ 'title' => 'Praziquantel Powder',      'cats' => [ 'powders' ] ],
	13 => [ 'title' => 'Praziquantel',             'cats' => [ 'liquid' ] ],
	14 => [ 'title' => 'Praziquantel',             'cats' => [] ],
];
$ranked = FisHotel_Tool_Product_Links::rank_title_candidates( [ 'praziquantel' ], $tie_rows );
check( 'same form: shortest title, then lowest ID', $ranked === [ 11, 12, 10, 13, 14 ], json_encode( $ranked ) );

check( 'word boundary: copper does not claim coppersafe', FisHotel_Tool_Product_Links::rank_title_candidates( [ 'copper' ], [ 1 => [ 'title' => 'Coppersafe 8oz', 'cats' => [] ] ] ) === [] );
check( 'brand-first titles do not match a prefix',          FisHotel_Tool_Product_Links::rank_title_candidates( [ 'cupramine' ], [ 1 => [ 'title' => 'Seachem Cupramine', 'cats' => [] ] ] ) === [] );
check( 'empty needle list never matches',                   FisHotel_Tool_Product_Links::rank_title_candidates( [], $catalog_rows ) === [] );

echo "\nneedles\n";
check( 'hyposalinity has no needles',            FisHotel_Tool_Product_Links::title_needles( 'hyposalinity', 'Hyposalinity' ) === [] );
check( 'sulfa_tmp aliases are normalised',       FisHotel_Tool_Product_Links::title_needles( 'sulfa_tmp', 'Sulfa TMP (…)' ) === [ 'tmp_sulfa', 'sulfa_tmp' ] );
check( 'coppersafe alias includes copper aid',   FisHotel_Tool_Product_Links::title_needles( 'coppersafe', 'Coppersafe / Kordon Copper Aid' ) === [ 'coppersafe', 'copper_aid' ] );
check( 'unknown med falls back to generic name', FisHotel_Tool_Product_Links::title_needles( 'new_med', 'New Med 5% (brand)' ) === [ 'new_med' ] );
check( 'normalise_generic strips parentheticals and %', FisHotel_Tool_Product_Links::normalise_generic( 'Copper Power (ionic/chelated copper)' ) === 'copper_power' && FisHotel_Tool_Product_Links::normalise_generic( 'Formalin 37%' ) === 'formalin' );

echo "\nanchors\n";
$med_ids = array_keys( $catalog );
check( 'exact anchor',                 FisHotel_Tool_Product_Links::med_id_for_anchor( 'praziquantel', $med_ids ) === 'praziquantel' );
check( 'prefix anchor',                FisHotel_Tool_Product_Links::med_id_for_anchor( FisHotel_Tool_Product_Links::normalise( '#praziquantel-powder' ), $med_ids ) === 'praziquantel' );
check( 'longest med_id wins',          FisHotel_Tool_Product_Links::med_id_for_anchor( 'fenbendazole_internal_food', $med_ids ) === 'fenbendazole_internal' );
check( 'unknown anchor maps to empty', FisHotel_Tool_Product_Links::med_id_for_anchor( 'levamisole', $med_ids ) === '' );

echo "\n" . ( $failures ? "$failures FAILED\n" : "all passed\n" );
exit( $failures ? 1 : 0 );
