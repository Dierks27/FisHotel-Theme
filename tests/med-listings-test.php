<?php
/**
 * Plain product listings for medications (v1.29.0) — no WordPress, no
 * database.
 *
 *   php tests/med-listings-test.php
 *
 * Part 1 runs the pure rules in inc/medication-store/listing-rules.php
 * with an invented, claim-heavy medicated flake ("Test Trio Flake"):
 *   - the "About This …" table prints Contains / Reef Safe / food rows and
 *     never Treats, Use In, or Best For on a medication;
 *   - the headings say "Food" / "Product", never "Medication";
 *   - an extra checkout acknowledgement with dosing / quarantine / disease
 *     words is not shown, a plain handling warning is;
 *   - stored settings still holding an old claim default are reset, custom
 *     wording is left alone (and reported when it still reads as a claim);
 *   - the footer drops Medication Dosing + QT Scheduler on med pages only.
 *
 * Part 2 reads the theme's own files and checks that no code path still
 * prints a claim: no "medication partner", no calculator link or eyebrow
 * hooked on the product page, no Treats / Use In rows, no "Pharmacy"
 * button, no Treats filter row, no product links sent to the tools, and
 * new neutral defaults with no treatment words.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

$root = dirname( __DIR__ );
require_once $root . '/inc/medication-store/listing-rules.php';

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
function src( $rel ) {
	return (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
}
function labels( array $rows ) {
	return array_map( function ( $r ) { return $r[0]; }, $rows );
}

// ── Invented test product, full of claims ───────────────────────────────
$fields = [
	'active_ingredient' => 'Kanamycin, metronidazole, nitrofurazone',
	'treats'            => 'Flukes, Hexamita, internal flagellates, nematodes',
	'use_in'            => 'QT tank, hospital tank',
	'reef_safe'         => 'no',
	'food_source'       => 'Omnivore flake',
	'best_for'          => 'Fish with HITH and bloat, broad QT coverage',
	'nutrition'         => 'Protein 45% min',
	'additives'         => 'Garlic',
];
$reef = [ 'no' => '<span class="fh-spec-badge">No</span>' ];

echo "spec_rows() — medicated flake\n";
$rows = FisHotel_Med_Listing::spec_rows( $fields, true, true, true, $reef );
$lab  = labels( $rows );
check( 'Contains row first, with the ingredients', isset( $rows[0] ) && $rows[0][0] === 'Contains' && $rows[0][1] === 'Kanamycin, metronidazole, nitrofurazone' );
check( 'no Treats row',                   ! in_array( 'Treats', $lab, true ) );
check( 'no Use In row',                   ! in_array( 'Use In', $lab, true ) );
check( 'no Best For row on a medication', ! in_array( 'Best For', $lab, true ) );
check( 'no "Active Ingredients" label',   ! in_array( 'Active Ingredients', $lab, true ) );
check( 'Reef Safe, Food Source, Nutrition, Additives kept', $lab === [ 'Contains', 'Reef Safe', 'Food Source', 'Nutrition Value', 'Additives & Enrichments' ], implode( ' | ', $lab ) );
$all = implode( ' ', array_map( function ( $r ) { return $r[1]; }, $rows ) );
check( 'no claim text anywhere in the table', ! FisHotel_Med_Listing::carries_treatment_text( strip_tags( $all ) ), $all );

echo "spec_rows() — other shapes\n";
$rows = FisHotel_Med_Listing::spec_rows( $fields, true, false, false, $reef );
check( 'powder: Contains + Reef Safe only', labels( $rows ) === [ 'Contains', 'Reef Safe' ], implode( ' | ', labels( $rows ) ) );
$rows = FisHotel_Med_Listing::spec_rows( [ 'best_for' => 'LPS corals, finicky eaters', 'food_source' => 'Mysis' ], false, true, false );
check( 'plain food keeps Best For', labels( $rows ) === [ 'Food Source', 'Best For' ], implode( ' | ', labels( $rows ) ) );
$rows = FisHotel_Med_Listing::spec_rows( [ 'active_ingredient' => '<b>Prazi</b>' ], true, false, false );
check( 'values are escaped', $rows[0][1] === '&lt;b&gt;Prazi&lt;/b&gt;', $rows[0][1] );
check( 'gift card / merch: no rows', FisHotel_Med_Listing::spec_rows( $fields, false, false, false ) === [] );
$rows = FisHotel_Med_Listing::spec_rows( [ 'active_ingredient' => '' ], true, false, false );
check( 'missing ingredient: no empty Contains row (owner fills it in)', $rows === [] );

echo "about_word()\n";
check( 'medicated flake → Food',   FisHotel_Med_Listing::about_word( true, true, true ) === 'Food' );
check( 'medicated pellet in no food category → Food', FisHotel_Med_Listing::about_word( true, false, true ) === 'Food' );
check( 'powder → Product',         FisHotel_Med_Listing::about_word( true, false, false ) === 'Product' );
check( 'plain food → Food',        FisHotel_Med_Listing::about_word( false, true, false ) === 'Food' );
check( 'other → no heading',       FisHotel_Med_Listing::about_word( false, false, false ) === '' );

echo "carries_treatment_text() / shown_extra_ack()\n";
$claims = [
	'I understand chloroquine phosphate is toxic to invertebrates and will only dose it in a fishless quarantine tank.',
	'Use in QT only.',
	'Treats ich and velvet',
	'Broad-spectrum bacterial + flagellate infections',
	'Effective against flukes',
	'Feed as sole food 7-10 days during treatment',
	'Great for HITH',
	'Antibacterial',
	'Fin rot',
	'Hospital tank use',
];
foreach ( $claims as $c ) {
	check( 'claim: ' . $c, FisHotel_Med_Listing::carries_treatment_text( $c ) );
	check( '  …not shown at checkout', FisHotel_Med_Listing::shown_extra_ack( $c ) === '' );
}
$plain = [
	'I understand chloroquine is toxic to corals and invertebrates.',
	'Copper kills invertebrates and corals.',
	'Keep sealed, store cool and dry. Not for human consumption.',
	'Keep out of reach of children.',
	'Which one is right? Brooklyn ships Monday.', // "ich" / "brook" inside other words
	'Metronidazole 99%+',
	'Flake food containing kanamycin, metronidazole, and nitrofurazone.',
	'Ships direct from our supplier.',
];
foreach ( $plain as $p ) {
	check( 'plain: ' . $p, ! FisHotel_Med_Listing::carries_treatment_text( $p ) );
}
check( 'handling warning shown as typed (trimmed)', FisHotel_Med_Listing::shown_extra_ack( "  Toxic to corals.\n" ) === 'Toxic to corals.' );
check( 'empty stays empty', FisHotel_Med_Listing::shown_extra_ack( '' ) === '' );

echo "extra acks through the terms builder\n";
require_once $root . '/inc/medication-store/terms-record.php';
$cart = [
	[ 'id' => 1, 'name' => 'Test Chloroquine Flake', 'extra_ack' => FisHotel_Med_Listing::shown_extra_ack( 'Only dose in a fishless quarantine tank.' ) ],
	[ 'id' => 2, 'name' => 'Test Copper',            'extra_ack' => FisHotel_Med_Listing::shown_extra_ack( 'Copper kills invertebrates and corals.' ) ],
];
$set = FisHotel_Med_Terms::extra_ack_required_set( $cart );
$texts = array_map( function ( $a ) { return $a['text']; }, array_values( $set ) );
check( 'only the handling warning becomes a required checkbox', $texts === [ 'Copper kills invertebrates and corals.' ], implode( ' | ', $texts ) );

echo "stale_keys() / custom_claim_keys()\n";
$stored = [
	'fh_med_trust_1'             => 'The same meds we use in QT',
	'fh_med_trust_2'             => '  trusted   partner fulfillment ',
	'fh_tier2_trust_1'           => 'The same meds we use in our QT',
	'fh_pharmacy_badge_subtitle' => 'Sourced direct · Therapeutic-grade',
	'fh_cart_meds_trust_4_label' => 'DOSING CALCULATOR',
	'fh_cart_meds_trust_4_body'  => 'Hand-mixed by Jeff',
	'fh_cart_meds_trust_1_label' => 'FROM THE PHARMACY DESK',
];
$stale = FisHotel_Med_Listing::stale_keys( $stored );
sort( $stale );
check( 'old defaults (spacing / case ignored) are reset', $stale === [ 'fh_cart_meds_trust_4_label', 'fh_med_trust_1', 'fh_med_trust_2', 'fh_pharmacy_badge_subtitle' ], implode( ', ', $stale ) );
check( 'custom wording is never reset', ! in_array( 'fh_tier2_trust_1', $stale, true ) && ! in_array( 'fh_cart_meds_trust_4_body', $stale, true ) );
$custom = FisHotel_Med_Listing::custom_claim_keys( $stored );
sort( $custom );
check( 'custom wording that is still a claim is reported', $custom === [ 'fh_cart_meds_trust_1_label', 'fh_tier2_trust_1' ], implode( ', ', $custom ) );
check( 'nothing stored → nothing to do', FisHotel_Med_Listing::stale_keys( [] ) === [] && FisHotel_Med_Listing::custom_claim_keys( [] ) === [] );

echo "footer_tool_defs()\n";
$defs = [
	[ 'page-quarantine-help.php',     'medication-dosing',   'Medication Dosing' ],
	[ 'page-qt-scheduler.php',        'qt-scheduler',        'QT Scheduler' ],
	[ 'page-bleach-calculator.php',   'bleach-calculator',   'Bleach Calculator' ],
	[ 'page-compatibility-guide.php', 'compatibility-guide', 'Build-a-Tank' ],
];
check( 'other pages keep all four tools', FisHotel_Med_Listing::footer_tool_defs( $defs, false ) === $defs );
$med = FisHotel_Med_Listing::footer_tool_defs( $defs, true );
check( 'med pages keep Bleach Calculator + Build-a-Tank only (column stays)', array_column( $med, 2 ) === [ 'Bleach Calculator', 'Build-a-Tank' ], implode( ', ', array_column( $med, 2 ) ) );

echo "neutral constants\n";
check( 'shipping line', FisHotel_Med_Listing::SHIPPING_LINE === 'Ships direct from our supplier.' );
check( 'badge title has no claim', ! FisHotel_Med_Listing::carries_treatment_text( FisHotel_Med_Listing::BADGE_TITLE ) && stripos( FisHotel_Med_Listing::BADGE_TITLE, 'pharmacy' ) === false );
check( 'private fields: eyebrow, treats, use in, dosing anchor', array_keys( FisHotel_Med_Listing::private_fields() ) === [ '_fishotel_med_eyebrow', '_fishotel_treats', '_fishotel_use_in', '_fishotel_dosing_anchor' ] );

// ── Part 2: the theme's own code no longer prints claims ────────────────
echo "theme code\n";
$display = src( 'inc/medication-store/product-display.php' );
check( 'no "medication partner" anywhere in the theme (outside tests / docs)', (function () {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ), FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$rel = ltrim( str_replace( dirname( __DIR__ ), '', $f->getPathname() ), '/' );
		if ( preg_match( '~^(vendor|tests|docs|node_modules|\.git)/~', $rel ) || ! preg_match( '/\.(php|js)$/', $rel ) ) continue;
		$s = file_get_contents( $f->getPathname() );
		// Comments may quote the old wording; printed strings may not.
		if ( preg_match( "/(esc_html_e|esc_html__|__|_e)\(\s*'[^']*medication partner/i", $s ) ) return false;
	}
	return true;
})() );
check( 'EA line prints the neutral shipping line', strpos( $display, 'FisHotel_Med_Listing::SHIPPING_LINE' ) !== false );
check( 'eyebrow not hooked on the product page', ! preg_match( "/add_action\([^;]*'render_purchase_eyebrow'/", $display ) );
check( 'calculator link not hooked on the product page', ! preg_match( "/add_action\([^;]*'render_calculator_link'/", $display ) );
check( 'no "Dose it with our Medication Calculator" string printed', strpos( $display, "esc_html_e( 'Dose it" ) === false );

$pdp = src( 'woocommerce/single-product.php' );
check( 'PDP has no Treats / Use In row', ! preg_match( "/\[\s*'(Treats|Use In)'/", $pdp ) );
check( 'PDP reads no _fishotel_treats / _fishotel_use_in', strpos( $pdp, '_fishotel_treats' ) === false && strpos( $pdp, '_fishotel_use_in' ) === false );
check( 'PDP never says "About This Medication"', stripos( $pdp, 'About This Medication' ) === false && strpos( $pdp, 'Medication</em>' ) === false );
check( 'PDP hides tag pills on medication pages', strpos( $pdp, 'FisHotel_Med_Listing::is_med( $product_id )' ) !== false );
check( 'PDP skips the Care Guide on medication pages', strpos( $pdp, '&& ! $is_med_or_aff ) : ?>' ) !== false );

check( 'modal button no longer says Pharmacy', strpos( src( 'inc/medication-store/disclaimer.php' ), 'Show Me the Pharmacy' ) === false && strpos( src( 'inc/medication-store/init.php' ), 'Show Me the Pharmacy' ) === false );
check( 'badge no longer titled From the Pharmacy', strpos( src( 'inc/template-functions.php' ), "\$title    = 'From the Pharmacy'" ) === false );
check( 'medication rule also accepts any category in the Medications tree', strpos( src( 'inc/template-functions.php' ), "return function_exists( 'fishotel_med_is_med_product' ) && fishotel_med_is_med_product( \$product_id );" ) !== false );
check( 'disease / drug-class tag colours gone', ! preg_match( "/'(ich|flukes|hexamita|aminoglycoside|antibacterial|dewormer)'\s*=>\s*'fh-tag--/", src( 'inc/template-functions.php' ) ) );

$archive = src( 'archive-product.php' );
check( 'Medications archive has no Treats filter row', strpos( $archive, "'label' => 'Treats'" ) === false && strpos( $archive, "'Antibiotics'" ) === false );

$tax = src( 'inc/medication-store/taxonomy.php' );
check( 'taxonomy no longer seeds therapeutic categories', ! preg_match( "/'(antiparasitic|antibacterial|antifungal|dewormer|quarantine-essentials)'\s*=>\s*'/", $tax ) );
check( 'taxonomy no longer seeds Treats / Use In attributes', strpos( $tax, "'med_treats'" ) === false && strpos( $tax, "'med_use_in'" ) === false );

foreach ( [ 'inc/calculator/init.php', 'inc/scheduler/init.php' ] as $f ) {
	check( "$f sends no product links", strpos( src( $f ), "'FISHOTEL_MED_LINKS'" ) === false );
}

check( 'helpers filter the extra acknowledgement', strpos( src( 'inc/medication-store/helpers.php' ), 'FisHotel_Med_Listing::shown_extra_ack( $text )' ) !== false );
check( 'footer filters the tools on medication pages', strpos( src( 'footer.php' ), 'FisHotel_Med_Listing::footer_tool_defs(' ) !== false );

$meta = src( 'inc/medication-store/product-meta.php' );
check( 'admin descriptions say Treats / Use in / eyebrow are not shown', substr_count( $meta, 'not shown on the website since v1.29.0' ) >= 3 );

// New defaults carry no claims, and each old claim default is in stale_copy().
$admin = src( 'inc/admin-settings.php' );
foreach ( [ 'fh_med_trust_1', 'fh_med_trust_2', 'fh_tier2_trust_1', 'fh_pharmacy_badge_subtitle' ] as $key ) {
	$ok = preg_match( "/'" . preg_quote( $key, '/' ) . "'\s*=>\s*'([^']*)'/", $admin, $m );
	check( "default $key has no claim", $ok && ! FisHotel_Med_Listing::carries_treatment_text( $m[1] ), $ok ? $m[1] : 'not found' );
}
if ( preg_match( "/'meds'\s*=>\s*\[(.*?)\],/s", $admin, $m ) ) {
	$bad = [];
	preg_match_all( "/'(trust_\d_(?:label|body))'\s*=>\s*'([^']*)'/", $m[1], $pairs, PREG_SET_ORDER );
	foreach ( $pairs as $p ) {
		if ( FisHotel_Med_Listing::carries_treatment_text( $p[2] ) || stripos( $p[2], 'pharmacy' ) !== false || stripos( $p[2], 'calculator' ) !== false ) {
			$bad[] = $p[1] . '=' . $p[2];
		}
	}
	check( 'cart meds preset has no claim / pharmacy / calculator copy', $bad === [] && count( $pairs ) === 8, implode( '; ', $bad ) );
} else {
	check( 'cart meds preset found', false );
}
$meds_block = preg_match( "/'meds'\s*=>\s*\[(.*?)\],/s", $admin, $mb ) ? $mb[1] : '';
foreach ( FisHotel_Med_Listing::stale_copy() as $key => $old ) {
	if ( strpos( $key, 'fh_cart_meds_' ) === 0 ) {
		$field = substr( $key, strlen( 'fh_cart_meds_' ) );
		$gone  = $meds_block !== '' && ! preg_match( "/'" . preg_quote( $field, '/' ) . "'\s*=>\s*'" . preg_quote( $old, '/' ) . "'/", $meds_block );
	} else {
		$gone = ! preg_match( "/'" . preg_quote( $key, '/' ) . "'\s*=>\s*'" . preg_quote( $old, '/' ) . "'/", $admin );
	}
	check( "old default for $key is no longer the code default", $gone );
}

// ── Review fixes (pop-up wording, supplier line once, subtitle, footer, slugs) ──
$body = '';
if ( preg_match( '/function default_disclaimer_body\(\)\s*\{\s*return\s*"([^"]*)"/', src( 'inc/medication-store/settings.php' ), $m ) ) {
	$body = $m[1];
}
check( 'pop-up default body found', $body !== '' );
check( 'pop-up default body names no dosing', $body !== '' && stripos( $body, 'dosing' ) === false && ! FisHotel_Med_Listing::carries_treatment_text( $body ), $body );
check( 'pop-up default says safe handling and storage', strpos( $body, 'safe handling and storage' ) !== false );
check( 'a saved copy of the old pop-up body is reset', FisHotel_Med_Listing::stale_keys( [ 'fishotel_med_disclaimer_body' => FisHotel_Med_Listing::OLD_DISCLAIMER_BODY ] ) === [ 'fishotel_med_disclaimer_body' ] );
check( 'the old pop-up body itself carried "dosing"', FisHotel_Med_Listing::carries_treatment_text( FisHotel_Med_Listing::OLD_DISCLAIMER_BODY ) );
check( 'a pop-up body the owner wrote with dosing is reported, not reset', FisHotel_Med_Listing::custom_claim_keys( [ 'fishotel_med_disclaimer_body' => 'Know your dosing.' ] ) === [ 'fishotel_med_disclaimer_body' ] );
check( 'the reset runs again on a site that ran the first one', FisHotel_Med_Listing::VERSION === '2' );

check( 'description ending with the supplier line → no second line', FisHotel_Med_Listing::says_shipping_line( "<p>General Trio Flake</p>\n<p>Ships direct from our&nbsp;supplier.</p>" ) );
check( 'description without it → the line is printed', ! FisHotel_Med_Listing::says_shipping_line( '<p>Keep sealed.</p>' ) );
check( 'product-display checks the description before printing the line', strpos( src( 'inc/medication-store/product-display.php' ), 'says_shipping_line' ) !== false );

check( 'ingredient set → no subtitle (it is in the Contains row)', FisHotel_Med_Listing::med_subtitle( 'Metronidazole 99%+', 'Contains: metronidazole.' ) === '' );
check( 'no ingredient → short description under the name', FisHotel_Med_Listing::med_subtitle( '', 'Powder.' ) === 'Powder.' );
check( 'single-product uses med_subtitle()', strpos( src( 'woocommerce/single-product.php' ), 'med_subtitle(' ) !== false );

check( 'footer line on a med page has no "treated"', ! FisHotel_Med_Listing::carries_treatment_text( FisHotel_Med_Listing::footer_tagline( true ) ) );
check( 'footer line elsewhere unchanged', FisHotel_Med_Listing::footer_tagline( false ) === 'Premium saltwater fish quarantine. Every fish checked in, observed, treated, and cleared before it ever reaches your tank.' );
check( 'footer.php prints footer_tagline()', strpos( src( 'footer.php' ), 'footer_tagline(' ) !== false );

$tf = src( 'inc/template-functions.php' );
check( 'Medicated Foods (plural slug) counts as food', substr_count( $tf, "'medicated-foods'" ) >= 2 );
check( 'cart preset knows the neutral categories', strpos( src( 'inc/cart-presets.php' ), "'powders-chemicals'" ) !== false && strpos( src( 'inc/cart-presets.php' ), "'medicated-foods'" ) !== false );
check( 'a product in Medicated Foods reads as a medicated food on its page', strpos( src( 'woocommerce/single-product.php' ), "'medicated-foods'" ) !== false );

echo $failures ? "\n$failures FAILED\n" : "\nAll med-listing checks passed.\n";
exit( $failures ? 1 : 0 );
