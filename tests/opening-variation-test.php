<?php
/**
 * Stub-based test for inc/variation-gallery.php — no WordPress, no
 * WooCommerce, no database.
 *
 *   php tests/opening-variation-test.php
 *
 * Covers the two decisions behind "the product page opens on the picture of
 * the variation it opens on":
 *   fishotel_opening_variation()         which variation the purchase form
 *                                        opens on (default attributes, the
 *                                        only choice in stock), or none;
 *   fishotel_opening_variation_picture() what that does to the gallery: a
 *                                        picture of its own is printed in
 *                                        the hero (and its thumb marked),
 *                                        the product's main image changes
 *                                        nothing — every size of a fish.
 *
 * All products, attributes and ids below are invented.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

$failures = 0;
$checks   = 0;
function check( $label, $actual, $expected ) {
	global $failures, $checks;
	$checks++;
	if ( $actual === $expected ) {
		echo "  ok   $label\n";
	} else {
		$failures++;
		echo "  FAIL $label — got " . var_export( $actual, true ) . ', wanted ' . var_export( $expected, true ) . "\n";
	}
}

/* ---- WordPress, as far as these two functions go --------------------- */

// Close enough to sanitize_title() for attribute values: lower case,
// anything that is not a letter or a digit becomes a dash.
function sanitize_title( $s ) {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' );
}

require __DIR__ . '/../inc/variation-gallery.php';

/* ---- Invented products ---------------------------------------------- */

function variation( $id, array $attributes, $image_id, $in_stock = true, array $image = [] ) {
	return [
		'variation_id' => $id,
		'attributes'   => $attributes,
		'is_in_stock'  => $in_stock,
		'image_id'     => $image_id,
		'image'        => $image + [ 'title' => 'file-' . $image_id, 'alt' => 'file-' . $image_id ],
	];
}
function id_of( $v ) { return is_array( $v ) ? $v['variation_id'] : null; }

// Test Stickers: custom attribute Colour, a picture per variation. Main
// image 10 is the purple one's; the gallery holds 11, 12, 13.
$MAIN     = 10;
$GALLERY  = [ 11, 12, 13 ];
$stickers = [
	variation( 101, [ 'attribute_colour' => 'Red' ], 11 ),
	variation( 102, [ 'attribute_colour' => 'Green' ], 12 ),
	variation( 103, [ 'attribute_colour' => 'Blue' ], 13 ),
	variation( 104, [ 'attribute_colour' => 'Purple' ], 10 ),
	variation( 105, [ 'attribute_colour' => 'Gold' ], 14 ),
];
$colours = [ 'Red', 'Green', 'Blue', 'Purple', 'Gold' ];
function colour( $selected, $options = null ) {
	global $colours;
	return [ 'attribute_colour' => [ 'options' => $options ?: $colours, 'selected' => $selected ] ];
}

echo "Which variation the form opens on\n";
check( 'nothing preselected: none', id_of( fishotel_opening_variation( $stickers, colour( '' ) ) ), null );
check( 'default attribute Green', id_of( fishotel_opening_variation( $stickers, colour( 'Green' ) ) ), 102 );
check( 'default attribute Gold', id_of( fishotel_opening_variation( $stickers, colour( 'Gold' ) ) ), 105 );
check( 'a default that is no option (wrong case): the <select> stays empty', id_of( fishotel_opening_variation( $stickers, colour( 'green' ) ) ), null );
check( 'a default that is not a string', id_of( fishotel_opening_variation( $stickers, colour( [ 'Green' ] ) ) ), null );
check( 'no variations', fishotel_opening_variation( [], colour( 'Green' ) ), null );
check( 'no attributes', fishotel_opening_variation( $stickers, [] ), null );

// Out of stock: single-product.php hands over in-stock variations only,
// but the function must not pick a sold-out one if it is handed one.
$one_left = [
	variation( 101, [ 'attribute_colour' => 'Red' ], 11, false ),
	variation( 102, [ 'attribute_colour' => 'Green' ], 12, false ),
	variation( 103, [ 'attribute_colour' => 'Blue' ], 13 ),
];
$in_stock_only = [ $one_left[2] ];
check( 'only one colour in stock: main.js picks it', id_of( fishotel_opening_variation( $in_stock_only, colour( '' ) ) ), 103 );
check( 'only one in stock, the default is a sold-out one: its option is not printed, the one left is picked',
	id_of( fishotel_opening_variation( $in_stock_only, colour( 'Red' ) ) ), 103 );
check( 'two in stock, the default is a sold-out one: nothing picked',
	id_of( fishotel_opening_variation( [ $stickers[1], $stickers[2] ], colour( 'Red' ) ) ), null );
check( 'sold-out variations in the list are not counted as choices',
	id_of( fishotel_opening_variation( $one_left, colour( '' ) ) ), 103 );

// Two attributes: Colour × Finish.
$two = [
	variation( 201, [ 'attribute_colour' => 'Red', 'attribute_finish' => 'Matte' ], 21 ),
	variation( 202, [ 'attribute_colour' => 'Red', 'attribute_finish' => 'Gloss' ], 22 ),
	variation( 203, [ 'attribute_colour' => 'Blue', 'attribute_finish' => 'Matte' ], 23 ),
];
function two_attrs( $colour, $finish ) {
	return [
		'attribute_colour' => [ 'options' => [ 'Red', 'Blue' ], 'selected' => $colour ],
		'attribute_finish' => [ 'options' => [ 'Matte', 'Gloss' ], 'selected' => $finish ],
	];
}
check( 'two attributes, both default', id_of( fishotel_opening_variation( $two, two_attrs( 'Red', 'Gloss' ) ) ), 202 );
check( 'two attributes, one default: still to choose', id_of( fishotel_opening_variation( $two, two_attrs( 'Red', '' ) ) ), null );
check( 'two defaults that no variation has together', id_of( fishotel_opening_variation( $two, two_attrs( 'Blue', 'Gloss' ) ) ), null );

// One attribute with a single value (Type), one to choose (Bag Size).
$food = [
	variation( 301, [ 'attribute_type' => 'Worms', 'attribute_bag-size' => '1oz' ], 30 ),
	variation( 302, [ 'attribute_type' => 'Worms', 'attribute_bag-size' => '2oz' ], 31 ),
];
function food_attrs( $type, $size ) {
	return [
		'attribute_type'     => [ 'options' => [ 'Worms' ], 'selected' => $type ],
		'attribute_bag-size' => [ 'options' => [ '1oz', '2oz' ], 'selected' => $size ],
	];
}
check( 'single-value attribute is picked for the customer, the other is not', id_of( fishotel_opening_variation( $food, food_attrs( '', '' ) ) ), null );
check( 'single-value attribute + a default for the other', id_of( fishotel_opening_variation( $food, food_attrs( '', '2oz' ) ) ), 302 );
check( 'single-value attribute whose button is printed selected but whose <select> is not (default "worms"): main.js leaves it',
	id_of( fishotel_opening_variation( $food, food_attrs( 'worms', '2oz' ) ) ), null );

// "Any" on a variation: Size any, Colour fixed.
$any = [
	variation( 401, [ 'attribute_colour' => 'Red', 'attribute_size' => '' ], 41 ),
	variation( 402, [ 'attribute_colour' => 'Blue', 'attribute_size' => '' ], 42 ),
];
$any_attrs = function ( $colour, $size ) {
	return [
		'attribute_colour' => [ 'options' => [ 'Red', 'Blue' ], 'selected' => $colour ],
		'attribute_size'   => [ 'options' => [ 'S', 'L' ], 'selected' => $size ],
	];
};
check( '"any size" variation, both chosen', id_of( fishotel_opening_variation( $any, $any_attrs( 'Blue', 'L' ) ) ), 402 );
check( '"any size" variation, size still to choose', id_of( fishotel_opening_variation( $any, $any_attrs( 'Blue', '' ) ) ), null );

// An attribute with nothing in stock prints no group: it is not waited for.
$no_group = [ variation( 501, [ 'attribute_colour' => 'Red' ], 51 ), variation( 502, [ 'attribute_colour' => 'Blue' ], 52 ) ];
check( 'an attribute no in-stock variation uses is not waited for',
	id_of( fishotel_opening_variation( $no_group, [
		'attribute_colour' => [ 'options' => [ 'Red', 'Blue' ], 'selected' => 'Blue' ],
		'attribute_finish' => [ 'options' => [ 'Matte' ], 'selected' => '' ],
	] ) ), 502 );

// Taxonomy attribute: the values are slugs on both sides.
$fish = [
	variation( 601, [ 'attribute_pa_size' => '1-2' ], 60 ),
	variation( 602, [ 'attribute_pa_size' => '2-3-5' ], 60 ),
	variation( 603, [ 'attribute_pa_size' => '3-5-5' ], 60 ),
];
$fish_attrs = function ( $selected ) {
	return [ 'attribute_pa_size' => [ 'options' => [ '1-2', '2-3-5', '3-5-5', '5' ], 'selected' => $selected ] ];
};
check( 'a fish with a default size', id_of( fishotel_opening_variation( $fish, $fish_attrs( '2-3-5' ) ) ), 602 );
check( 'numeric-looking values stay strings', id_of( fishotel_opening_variation( [ variation( 701, [ 'attribute_pa_size' => '5' ], 60 ) ], $fish_attrs( '' ) ) ), 701 );

echo "What the gallery opens on\n";
$picture = function ( $v ) use ( $MAIN, $GALLERY ) { return fishotel_opening_variation_picture( $v, $MAIN, $GALLERY ); };
check( 'no variation: the page as always', $picture( null ), null );
check( 'a size of a fish (WooCommerce hands it the main image): the page as always',
	fishotel_opening_variation_picture( $fish[1], 60, [ 61, 62, 63, 64 ] ), null );
check( 'the variation whose picture IS the main image: the page as always', $picture( $stickers[3] ), null );
check( 'a variation without image data', $picture( [ 'variation_id' => 9 ] ), null );
check( 'a variation with image id 0', $picture( variation( 9, [], 0 ) ), null );
check( 'first gallery picture: second thumb', $picture( $stickers[0] ), [ 'image_id' => 11, 'thumb' => 1, 'alt' => '' ] );
check( 'third gallery picture: fourth thumb', $picture( $stickers[2] ), [ 'image_id' => 13, 'thumb' => 3, 'alt' => '' ] );
check( 'a picture no thumb carries: hero only', $picture( $stickers[4] ), [ 'image_id' => 14, 'thumb' => -1, 'alt' => '' ] );
check( 'a fourth gallery picture has no thumb (three are printed)',
	fishotel_opening_variation_picture( $stickers[4], $MAIN, [ 11, 12, 13, 14 ] ), [ 'image_id' => 14, 'thumb' => -1, 'alt' => '' ] );
check( 'a product with no gallery images prints no thumbs',
	fishotel_opening_variation_picture( $stickers[0], $MAIN, [] ), [ 'image_id' => 11, 'thumb' => -1, 'alt' => '' ] );
check( 'no main image: the thumbs are the gallery pictures alone',
	fishotel_opening_variation_picture( $stickers[1], 0, $GALLERY ), [ 'image_id' => 12, 'thumb' => 1, 'alt' => '' ] );
check( 'ids as strings (post meta)',
	fishotel_opening_variation_picture( variation( 9, [], '12' ), '10', [ '11', '12', '13' ] ), [ 'image_id' => 12, 'thumb' => 2, 'alt' => '' ] );
check( 'the image\'s own alt comes along',
	$picture( variation( 9, [], 12, true, [ 'title' => 'sticker-green', 'alt' => 'Green test sticker on a card' ] ) ),
	[ 'image_id' => 12, 'thumb' => 2, 'alt' => 'Green test sticker on a card' ] );
check( 'WooCommerce\'s fallback to the attachment title is not an alt of its own',
	$picture( variation( 9, [], 12, true, [ 'title' => 'sticker-green', 'alt' => 'sticker-green' ] ) ),
	[ 'image_id' => 12, 'thumb' => 2, 'alt' => '' ] );

echo "\n";
if ( $failures ) {
	echo "opening-variation: $failures of $checks checks FAILED\n";
	exit( 1 );
}
echo "opening-variation: OK ($checks checks — the page opens on the picked variation's own picture, never on a size's)\n";
