<?php
/**
 * Stub-based test for the pure invoice helpers in inc/invoice-lines.php —
 * no WordPress, no database.
 *
 *   php tests/invoice-lines-test.php
 *
 * Covers: the line parser (x / X / ×, — / – / -, missing price, the three
 * negative-amount forms, "Credit…" lines, notes, entities, list-less
 * descriptions), the `_fh_invoice_lines` JSON decoding get_lines() relies
 * on, the summary text, the invoice classification rule, the EXPAND /
 * FALLBACK decision, split_money() with plain lines, a coupon discount with
 * a rounding remainder, credits, two tax rates and a mismatch, the
 * whole-cent apportionment (split_amount() never hands a line a negative
 * share, and a row's tax totals are the sums of its rate parts), and the
 * rows_balance() money invariant.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

// ── Minimal WordPress stubs so the module file loads ────────────────────
if ( ! defined( 'ABSPATH' ) )         define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }

require_once dirname( __DIR__ ) . '/inc/invoice-lines.php';

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
function line( $name, $qty, $price, $kind, $product_id = 0 ) {
	return [ 'name' => $name, 'qty' => $qty, 'price' => $price, 'kind' => $kind, 'product_id' => $product_id ];
}
function same( $a, $b ) {
	return abs( (float) $a - (float) $b ) < 0.000001;
}
function one( $text ) {
	$lines = FisHotel_Invoice_Lines::parse_lines( '<ul><li>' . $text . '</li></ul>' );
	return isset( $lines[0] ) ? $lines[0] : null;
}

// ── parse_lines() ───────────────────────────────────────────────────────
echo "parse_lines()\n";

$html  = "<ul>\n<li>Blue Tang x2 — $90</li>\n<li>Firefish x1 — $12</li>\n<li>Credit: lost Cleaner Wrasse — -$82</li>\n</ul>";
$lines = FisHotel_Invoice_Lines::parse_lines( $html );
check( 'invoice example: three lines', 3 === count( $lines ), json_encode( $lines ) );
check( 'fish line: name / qty / unit price / kind', $lines[0] === line( 'Blue Tang', 2, 90.0, 'fish' ), json_encode( $lines[0] ) );
check( 'second fish line',                       $lines[1] === line( 'Firefish', 1, 12.0, 'fish' ), json_encode( $lines[1] ) );
check( 'credit: name without the amount, qty 1, negative price', $lines[2] === line( 'Credit: lost Cleaner Wrasse', 1, -82.0, 'credit' ), json_encode( $lines[2] ) );

check( 'capital X + en dash',       one( 'Goby X3 – $39' ) === line( 'Goby', 3, 39.0, 'fish' ) );
check( '× + hyphen + decimals',     one( 'Wrasse ×1 - $45.50' ) === line( 'Wrasse', 1, 45.5, 'fish' ) );
check( 'dash touching the price',   one( 'Goby x1-$39' ) === line( 'Goby', 1, 39.0, 'fish' ) );
check( 'dash touching on one side', one( 'Goby x1 -$39' ) === line( 'Goby', 1, 39.0, 'fish' ) );
check( 'spaces + thousands comma',  one( 'Tang x 2 — $ 1,250.50' ) === line( 'Tang', 2, 1250.5, 'fish' ) );
check( 'missing price → null',      one( 'Clown x1' ) === line( 'Clown', 1, null, 'fish' ) );
check( 'dash but no price → null',  one( 'Clown x1 —' ) === line( 'Clown', 1, null, 'fish' ) );
check( 'qty 0 is lifted to 1',      one( 'Clown x0 — $5' ) === line( 'Clown', 1, 5.0, 'fish' ) );

check( 'credit "$-12"',                 one( 'Credit: DOA Firefish — $-12' ) === line( 'Credit: DOA Firefish', 1, -12.0, 'credit' ) );
check( 'credit "−$5" (unicode minus)',  one( 'Credit: DOA — −$5' ) === line( 'Credit: DOA', 1, -5.0, 'credit' ) );
check( 'credit with a positive amount is still a reduction', one( 'Credit: lost fish — $82' ) === line( 'Credit: lost fish', 1, -82.0, 'credit' ) );
check( 'credit without an amount',      one( 'Credit for the lost Goby' ) === line( 'Credit for the lost Goby', 1, null, 'credit' ) );
check( 'credit with the amount mid-line', one( 'Credit −$5 for the Goby' ) === line( 'Credit for the Goby', 1, -5.0, 'credit' ) );
check( 'negative amount without "Credit" is a credit', one( 'Discount — -$10' ) === line( 'Discount', 1, -10.0, 'credit' ) );
check( 'negative amount on an "xN" line is a credit, not a fish', one( 'Lost Goby x1 — -$39' ) === line( 'Lost Goby x1', 1, -39.0, 'credit' ) );

check( 'plain text is a note',          one( 'Free shipping included' ) === line( 'Free shipping included', 1, null, 'note' ) );
check( 'price without "xN" is a note',  one( 'Shipping — $20' ) === line( 'Shipping — $20', 1, null, 'note' ) );

check( 'nested tags, &nbsp; and &#8212; are cleaned', one( '<b>Blue&nbsp;Tang</b> x2 &#8212; $90' ) === line( 'Blue Tang', 2, 90.0, 'fish' ) );
check( 'empty <li> is skipped', 1 === count( FisHotel_Invoice_Lines::parse_lines( '<ul><li></li><li>Goby x1 — $39</li><li> &nbsp; </li></ul>' ) ) );

$no_list = FisHotel_Invoice_Lines::parse_lines( "Blue Tang x2 — $90<br>Firefish x1 — $12\nCredit: DOA — -$1" );
check( 'no <ul>: <br> and newlines split lines', 3 === count( $no_list ) && 'Firefish' === $no_list[1]['name'] && 'credit' === $no_list[2]['kind'], json_encode( $no_list ) );
check( 'paragraphs split lines', 2 === count( FisHotel_Invoice_Lines::parse_lines( '<p>Blue Tang x2 — $90</p><p>Firefish x1 — $12</p>' ) ) );
check( 'empty description → []',      [] === FisHotel_Invoice_Lines::parse_lines( '' ) );
check( 'whitespace-only → []',        [] === FisHotel_Invoice_Lines::parse_lines( "<p>&nbsp;</p>\n" ) );
check( 'clean_text()',                'Blue Tang x2' === FisHotel_Invoice_Lines::clean_text( "  <em>Blue</em>\xC2\xA0Tang \n x2  " ) );

// ── lines_from_json() (what get_lines() decodes from _fh_invoice_lines) ──
echo "\nlines_from_json()\n";

$json  = '[{"name":"Blue Tang","qty":2,"price":90},{"name":"Firefish","qty":"1","price":"12","product_id":4711},'
	. '{"name":"Credit: lost Cleaner Wrasse","price":-82},{"name":"Heat pack included","kind":"note","price":5},'
	. '{"name":"","qty":1,"price":1},"junk",{"qty":2},{"name":"Goby","kind":"credit","price":10,"qty":3}]';
$lines = FisHotel_Invoice_Lines::lines_from_json( $json );
check( 'entries without a name and non-objects are dropped', 5 === count( $lines ), json_encode( $lines ) );
check( 'fish entry: kind defaults to fish, product_id 0',  $lines[0] === line( 'Blue Tang', 2, 90.0, 'fish' ), json_encode( $lines[0] ) );
check( 'numeric strings + product_id are accepted',        $lines[1] === line( 'Firefish', 1, 12.0, 'fish', 4711 ), json_encode( $lines[1] ) );
check( 'negative price → credit, qty 1',                   $lines[2] === line( 'Credit: lost Cleaner Wrasse', 1, -82.0, 'credit' ), json_encode( $lines[2] ) );
check( 'note: price dropped',                              $lines[3] === line( 'Heat pack included', 1, null, 'note' ), json_encode( $lines[3] ) );
check( 'explicit credit: price negated, qty forced to 1',  $lines[4] === line( 'Goby', 1, -10.0, 'credit' ), json_encode( $lines[4] ) );
check( 'already-decoded array is accepted', 1 === count( FisHotel_Invoice_Lines::lines_from_json( [ [ 'name' => 'Goby', 'price' => 39 ] ] ) ) );
check( 'not JSON → []',        [] === FisHotel_Invoice_Lines::lines_from_json( 'not json' ) );
check( 'JSON object → []',     [] === FisHotel_Invoice_Lines::lines_from_json( '{"name":"x"}' ) );
check( 'empty list → []',      [] === FisHotel_Invoice_Lines::lines_from_json( '[]' ) );
check( 'null / "" → []',       [] === FisHotel_Invoice_Lines::lines_from_json( null ) && [] === FisHotel_Invoice_Lines::lines_from_json( '' ) );

// ── summary() + classify_invoice() ──────────────────────────────────────
echo "\nsummary() / classify_invoice()\n";

$example = [ line( 'Blue Tang', 2, 90.0, 'fish' ), line( 'Firefish', 1, 12.0, 'fish' ), line( 'Credit: lost Cleaner Wrasse', 1, -82.0, 'credit' ) ];
check( 'summary text', 'Blue Tang × 2, Firefish × 1, Credit: lost Cleaner Wrasse' === FisHotel_Invoice_Lines::summary( $example ), FisHotel_Invoice_Lines::summary( $example ) );
check( 'summary skips nameless lines, keeps notes plain', 'Goby × 1, Free shipping' === FisHotel_Invoice_Lines::summary( [ line( 'Goby', 1, 1.0, 'fish' ), line( '', 1, null, 'note' ), line( 'Free shipping', 1, null, 'note' ) ] ) );
check( 'summary of nothing is empty', '' === FisHotel_Invoice_Lines::summary( [] ) );

check( 'invoices slug → invoice',            FisHotel_Invoice_Lines::classify_invoice( [ 'quarantined-fish', 'invoices' ], 'Blue Tang' ) );
check( 'slug match is case-insensitive',      FisHotel_Invoice_Lines::classify_invoice( [ ' INVOICES ' ], '' ) );
check( '"Invoice" in the name → invoice',    FisHotel_Invoice_Lines::classify_invoice( [], 'jpreef Invoice (7/3/26)' ) );
check( 'whole word only: "Invoices Guide"',  ! FisHotel_Invoice_Lines::classify_invoice( [], 'Invoices Guide' ) );
check( 'a fish is not an invoice',           ! FisHotel_Invoice_Lines::classify_invoice( [ 'quarantined-fish' ], 'Blue Tang' ) );

// ── decide_expand() ─────────────────────────────────────────────────────
echo "\ndecide_expand()\n";

$d = FisHotel_Invoice_Lines::decide_expand( $example, 1, 110.0 );
check( 'example: 2×90 + 12 − 82 = 110 → EXPAND', $d['expand'] && '' === $d['reason'] && same( $d['sum'], 110 ), json_encode( $d ) );
check( 'subtotal within a cent still expands',    FisHotel_Invoice_Lines::decide_expand( $example, 1, 110.01 )['expand'] );
$d = FisHotel_Invoice_Lines::decide_expand( $example, 1, 110.02 );
check( 'two cents off → mismatch (sum reported)', ! $d['expand'] && 'mismatch' === $d['reason'] && same( $d['sum'], 110 ), json_encode( $d ) );
check( 'quantity 2 → quantity',                   'quantity' === FisHotel_Invoice_Lines::decide_expand( $example, 2, 220.0 )['reason'] );
check( 'quantity "1" (string) is fine',           FisHotel_Invoice_Lines::decide_expand( $example, '1', '110' )['expand'] );
check( 'only credits / notes → no_fish',          'no_fish' === FisHotel_Invoice_Lines::decide_expand( [ line( 'Credit: x', 1, -5.0, 'credit' ), line( 'Hi', 1, null, 'note' ) ], 1, -5.0 )['reason'] );
check( 'empty lines → no_fish',                   'no_fish' === FisHotel_Invoice_Lines::decide_expand( [], 1, 10.0 )['reason'] );
$d = FisHotel_Invoice_Lines::decide_expand( [ line( 'Goby', 1, 39.0, 'fish' ), line( 'Clown', 1, null, 'fish' ) ], 1, 39.0 );
check( 'an unpriced fish → unpriced (names it)',  'unpriced' === $d['reason'] && 'Clown' === $d['line'], json_encode( $d ) );
check( 'a zero-priced fish → unpriced',           'unpriced' === FisHotel_Invoice_Lines::decide_expand( [ line( 'Goby', 1, 0.0, 'fish' ) ], 1, 0.0 )['reason'] );
check( 'notes never block',                       FisHotel_Invoice_Lines::decide_expand( array_merge( $example, [ line( 'Heat pack included', 1, null, 'note' ) ] ), 1, 110.0 )['expand'] );
check( 'a credit without an amount counts as 0',  FisHotel_Invoice_Lines::decide_expand( [ line( 'Blue Tang', 2, 90.0, 'fish' ), line( 'Credit: later', 1, null, 'credit' ) ], 1, 180.0 )['expand'] );

// ── split_money() ───────────────────────────────────────────────────────
echo "\nsplit_money()\n";
$no_tax = [ 'subtotal' => [], 'total' => [] ];

// Plain lines, no discount, no tax.
$rows = FisHotel_Invoice_Lines::split_money( [ line( 'A', 1, 90.0, 'fish' ), line( 'B', 2, 6.0, 'fish' ) ], 102.0, 102.0, 0.0, 0.0, $no_tax );
check( 'plain: one row per fish line', 2 === count( $rows ), json_encode( $rows ) );
check( 'plain: subtotal = qty × unit price', same( $rows[0]['subtotal'], 90 ) && same( $rows[1]['subtotal'], 12 ) && 2 === $rows[1]['qty'] );
check( 'plain: total = subtotal, no tax',    same( $rows[0]['total'], 90 ) && same( $rows[1]['total'], 12 ) && same( $rows[0]['total_tax'], 0 ) && $rows[0]['taxes'] === $no_tax );
check( 'plain: rows balance',                FisHotel_Invoice_Lines::rows_balance( $rows, 102.0, 102.0, 0.0, 0.0, $no_tax ) );

// Coupon discount with a rounding remainder: $1 over three $10 lines.
$rows = FisHotel_Invoice_Lines::split_money( [ line( 'A', 1, 10.0, 'fish' ), line( 'B', 1, 10.0, 'fish' ), line( 'C', 1, 10.0, 'fish' ) ], 30.0, 29.0, 0.0, 0.0, $no_tax );
check( 'discount: 0.33 / 0.33 / 0.34 (remainder on the last line)', same( $rows[0]['total'], 9.67 ) && same( $rows[1]['total'], 9.67 ) && same( $rows[2]['total'], 9.66 ), json_encode( array_column( $rows, 'total' ) ) );
check( 'discount: totals sum to the line total exactly', same( $rows[0]['total'] + $rows[1]['total'] + $rows[2]['total'], 29 ) );
check( 'discount: rows balance', FisHotel_Invoice_Lines::rows_balance( $rows, 30.0, 29.0, 0.0, 0.0, $no_tax ) );
check( 'split_amount() helper', [ 0.33, 0.33, 0.34 ] === FisHotel_Invoice_Lines::split_amount( 1.0, [ 10, 10, 10 ] ) && [ 0.0, 0.0 ] === FisHotel_Invoice_Lines::split_amount( 0, [ 1, 2 ] ) && [] === FisHotel_Invoice_Lines::split_amount( 5, [] ) );

// Credits: the invoice example, then the same with an $11 coupon.
$rows = FisHotel_Invoice_Lines::split_money( $example, 110.0, 110.0, 0.0, 0.0, $no_tax );
check( 'credits: 2 fish rows + 1 credit row', 3 === count( $rows ) && 'credit' === $rows[2]['kind'], json_encode( $rows ) );
check( 'credits: fish subtotals 180 / 12, credit −82', same( $rows[0]['subtotal'], 180 ) && same( $rows[1]['subtotal'], 12 ) && same( $rows[2]['total'], -82 ) && 'Credit: lost Cleaner Wrasse' === $rows[2]['name'] );
check( 'credits: sum(fish totals) + credit == 110', same( $rows[0]['total'] + $rows[1]['total'] + $rows[2]['total'], 110 ) );
check( 'credits: rows balance', FisHotel_Invoice_Lines::rows_balance( $rows, 110.0, 110.0, 0.0, 0.0, $no_tax ) );
$rows = FisHotel_Invoice_Lines::split_money( $example, 110.0, 99.0, 0.0, 0.0, $no_tax );
check( 'credits + coupon: discount only on fish lines (169.69 / 11.31 / −82)', same( $rows[0]['total'], 169.69 ) && same( $rows[1]['total'], 11.31 ) && same( $rows[2]['total'], -82 ), json_encode( array_column( $rows, 'total' ) ) );
check( 'credits + coupon: sum == 99', same( $rows[0]['total'] + $rows[1]['total'] + $rows[2]['total'], 99 ) && FisHotel_Invoice_Lines::rows_balance( $rows, 110.0, 99.0, 0.0, 0.0, $no_tax ) );
check( 'an amount-less credit makes no row', 1 === count( FisHotel_Invoice_Lines::split_money( [ line( 'A', 1, 10.0, 'fish' ), line( 'Credit: later', 1, null, 'credit' ) ], 10.0, 10.0, 0.0, 0.0, $no_tax ) ) );

// Two tax rates.
$taxes = [ 'subtotal' => [ 1 => 6.60, 2 => 1.10 ], 'total' => [ 1 => 6.60, 2 => 1.10 ] ];
$rows  = FisHotel_Invoice_Lines::split_money( [ line( 'A', 1, 90.0, 'fish' ), line( 'B', 1, 20.0, 'fish' ) ], 110.0, 110.0, 7.70, 7.70, $taxes );
check( 'taxes: rate amounts split pro rata (5.40 / 1.20 and 0.90 / 0.20)',
	same( $rows[0]['taxes']['total'][1], 5.40 ) && same( $rows[1]['taxes']['total'][1], 1.20 )
	&& same( $rows[0]['taxes']['subtotal'][2], 0.90 ) && same( $rows[1]['taxes']['subtotal'][2], 0.20 ), json_encode( $rows ) );
check( 'taxes: tax totals split the same way (6.30 / 1.40)', same( $rows[0]['subtotal_tax'], 6.30 ) && same( $rows[1]['total_tax'], 1.40 ) );
check( 'taxes: rows balance', FisHotel_Invoice_Lines::rows_balance( $rows, 110.0, 110.0, 7.70, 7.70, $taxes ) );
$rows = FisHotel_Invoice_Lines::split_money( [ line( 'A', 1, 10.0, 'fish' ), line( 'B', 1, 10.0, 'fish' ), line( 'C', 1, 10.0, 'fish' ) ], 30.0, 30.0, 1.0, 1.0, [ 'subtotal' => [ 7 => 1.0 ], 'total' => [ 7 => 1.0 ] ] );
check( 'taxes: rounding remainder lands on the last line', same( $rows[2]['taxes']['total'][7], 0.34 ) && same( $rows[2]['total_tax'], 0.34 ) && same( $rows[0]['total_tax'], 0.33 ) );
check( 'taxes: discounted + taxed rows balance', FisHotel_Invoice_Lines::rows_balance(
	FisHotel_Invoice_Lines::split_money( $example, 110.0, 99.0, 7.70, 6.93, [ 'subtotal' => [ 1 => 7.70 ], 'total' => [ 1 => 6.93 ] ] ),
	110.0, 99.0, 7.70, 6.93, [ 'subtotal' => [ 1 => 7.70 ], 'total' => [ 1 => 6.93 ] ] ) );

// Residual and edge cases.
$rows = FisHotel_Invoice_Lines::split_money( [ line( 'A', 1, 90.0, 'fish' ), line( 'B', 1, 12.0, 'fish' ) ], 102.01, 102.01, 0.0, 0.0, $no_tax );
check( 'a 1¢ residual goes to the last fish line', same( $rows[1]['subtotal'], 12.01 ) && same( $rows[0]['subtotal'] + $rows[1]['subtotal'], 102.01 ) );
$rows = FisHotel_Invoice_Lines::split_money( [ line( 'A', 1, 1.0, 'fish' ), line( 'B', 1, 1.0, 'fish' ) ], 2.0, 0.0, 0.0, 0.0, $no_tax );
check( '100% coupon: zero totals, no negative zero', same( $rows[0]['total'], 0 ) && same( $rows[1]['total'], 0 ) && '0' === (string) $rows[1]['total'] );

// Apportionment never pushes a share below zero: six $100 fish and a $1 fish sharing a 10¢
// coupon used to round to 2¢ × 6 and leave −2¢ on the $1 line, i.e. a total above its subtotal.
$skew  = [ 100, 100, 100, 100, 100, 100, 1 ];
$parts = FisHotel_Invoice_Lines::split_amount( 0.10, $skew );
check( 'split_amount: skewed weights — no negative share, cents add up', [ 0.01, 0.01, 0.02, 0.02, 0.02, 0.02, 0.0 ] === $parts && same( array_sum( $parts ), 0.10 ), json_encode( $parts ) );
$parts = FisHotel_Invoice_Lines::split_amount( 0.05, [ 1, 1, 1, 1, 1, 1, 1 ] );
check( 'split_amount: 5¢ over seven equal lines → five 1¢ shares, last lines first', [ 0.0, 0.0, 0.01, 0.01, 0.01, 0.01, 0.01 ] === $parts, json_encode( $parts ) );
$parts = FisHotel_Invoice_Lines::split_amount( 0.97, [ 3, 7, 11 ] );
check( 'split_amount: every share within a cent of its exact value (0.14 / 0.32 / 0.51)', [ 0.14, 0.32, 0.51 ] === $parts, json_encode( $parts ) );
$parts = FisHotel_Invoice_Lines::split_amount( 0.855, [ 90, 12 ] );
check( 'split_amount: a sub-cent amount keeps its dust on the last share, sum exact', same( $parts[0], 0.75 ) && same( $parts[1], 0.105 ) && same( array_sum( $parts ), 0.855 ), json_encode( $parts ) );
$lines = [];
foreach ( [ 'A', 'B', 'C', 'D', 'E', 'F' ] as $n ) {
	$lines[] = line( $n, 1, 100.0, 'fish' );
}
$lines[] = line( 'Cheap', 1, 1.0, 'fish' );
$rows    = FisHotel_Invoice_Lines::split_money( $lines, 601.0, 600.90, 0.0, 0.0, $no_tax );
$over    = array_filter( $rows, function ( $r ) { return $r['total'] > $r['subtotal'] + 0.000001; } );
check( 'skewed coupon: no fish total above its subtotal, the $1 line keeps $1.00, totals sum to 600.90', [] === $over && same( $rows[6]['total'], 1.0 ) && same( array_sum( array_column( $rows, 'total' ) ), 600.90 ), json_encode( array_column( $rows, 'total' ) ) );
check( 'skewed coupon: rows balance', FisHotel_Invoice_Lines::rows_balance( $rows, 601.0, 600.90, 0.0, 0.0, $no_tax ) );

// Two rates over three equal lines: a row's tax totals are the sums of its rate parts (what
// WC_Order_Item_Product::set_taxes() stores), not an independent split of the line's totals.
$taxes = [ 'subtotal' => [ 1 => 1.0, 2 => 1.0 ], 'total' => [ 1 => 1.0, 2 => 1.0 ] ];
$rows  = FisHotel_Invoice_Lines::split_money( [ line( 'A', 1, 10.0, 'fish' ), line( 'B', 1, 10.0, 'fish' ), line( 'C', 1, 10.0, 'fish' ) ], 30.0, 30.0, 2.0, 2.0, $taxes );
$consistent = true;
foreach ( $rows as $r ) {
	$consistent = $consistent && same( $r['total_tax'], array_sum( $r['taxes']['total'] ) ) && same( $r['subtotal_tax'], array_sum( $r['taxes']['subtotal'] ) );
}
check( 'two rates: row tax totals equal their rate parts (0.66 / 0.66 / 0.68) and still sum to 2.00', $consistent && same( $rows[2]['total_tax'], 0.68 ) && same( array_sum( array_column( $rows, 'total_tax' ) ), 2.0 ), json_encode( array_column( $rows, 'total_tax' ) ) );
check( 'two rates: rows balance', FisHotel_Invoice_Lines::rows_balance( $rows, 30.0, 30.0, 2.0, 2.0, $taxes ) );
$rows = FisHotel_Invoice_Lines::split_money( [ line( 'A', 1, 10.0, 'fish' ) ], 10.0, 10.0, 0.86, 0.86, [ 'subtotal' => [ 1 => 0.855 ], 'total' => [ 1 => 0.855 ] ] );
check( 'no rate data → the tax totals themselves are split; with rate data they follow the rates', same( $rows[0]['total_tax'], 0.855 ) && same( FisHotel_Invoice_Lines::split_money( [ line( 'A', 1, 10.0, 'fish' ) ], 10.0, 10.0, 0.86, 0.86, $no_tax )[0]['total_tax'], 0.86 ) );

// Mismatch → nothing to apply.
check( 'mismatch: lines do not reach the subtotal → []', [] === FisHotel_Invoice_Lines::split_money( [ line( 'A', 1, 90.0, 'fish' ), line( 'B', 1, 12.0, 'fish' ) ], 120.0, 120.0, 0.0, 0.0, $no_tax ) );
check( 'mismatch: unpriced fish → []',                     [] === FisHotel_Invoice_Lines::split_money( [ line( 'A', 1, null, 'fish' ) ], 10.0, 10.0, 0.0, 0.0, $no_tax ) );
check( 'mismatch: no fish lines → []',                     [] === FisHotel_Invoice_Lines::split_money( [ line( 'Credit: x', 1, -5.0, 'credit' ) ], -5.0, -5.0, 0.0, 0.0, $no_tax ) );

// rows_balance() rejects anything that moves money.
$rows = FisHotel_Invoice_Lines::split_money( $example, 110.0, 110.0, 0.0, 0.0, $no_tax );
$bad  = $rows; $bad[0]['total'] += 1;
check( 'rows_balance: a tampered total fails',      ! FisHotel_Invoice_Lines::rows_balance( $bad, 110.0, 110.0, 0.0, 0.0, $no_tax ) );
$bad  = $rows; $bad[1]['taxes']['total'][1] = 0.5;
check( 'rows_balance: a stray tax rate fails',      ! FisHotel_Invoice_Lines::rows_balance( $bad, 110.0, 110.0, 0.0, 0.0, $no_tax ) );
check( 'rows_balance: missing tax rate fails',      ! FisHotel_Invoice_Lines::rows_balance( $rows, 110.0, 110.0, 0.0, 0.0, [ 'subtotal' => [ 1 => 1.0 ], 'total' => [ 1 => 1.0 ] ] ) );
check( 'rows_balance: wrong subtotal fails',        ! FisHotel_Invoice_Lines::rows_balance( $rows, 111.0, 110.0, 0.0, 0.0, $no_tax ) );
check( 'rows_balance: no rows fails',               ! FisHotel_Invoice_Lines::rows_balance( [], 0.0, 0.0, 0.0, 0.0, $no_tax ) );
check( 'rows_balance: credit-only rows fail',       ! FisHotel_Invoice_Lines::rows_balance( [ $rows[2] ], -82.0, -82.0, 0.0, 0.0, $no_tax ) );
$bad  = $rows; $bad[0]['total'] += 0.02; $bad[1]['total'] -= 0.02;
check( 'rows_balance: a fish total above its subtotal fails even though the sums still match', ! FisHotel_Invoice_Lines::rows_balance( $bad, 110.0, 110.0, 0.0, 0.0, $no_tax ) );

// End to end on the real description: parse → decide → split → balance.
$lines = FisHotel_Invoice_Lines::parse_lines( $html );
$d     = FisHotel_Invoice_Lines::decide_expand( $lines, 1, 110.0 );
$rows  = FisHotel_Invoice_Lines::split_money( $lines, 110.0, 110.0, 0.0, 0.0, $no_tax );
check( 'end to end: the example description itemises and balances', $d['expand'] && 3 === count( $rows ) && FisHotel_Invoice_Lines::rows_balance( $rows, 110.0, 110.0, 0.0, 0.0, $no_tax ) );
check( 'end to end: a mispriced invoice falls back', 'mismatch' === FisHotel_Invoice_Lines::decide_expand( $lines, 1, 20.0 )['reason'] );

echo "\n" . ( $failures ? "$failures FAILED\n" : "all passed\n" );
exit( $failures ? 1 : 0 );
