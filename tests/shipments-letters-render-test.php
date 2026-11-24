<?php
/**
 * USPS letters (ShipTracker v1.10.0) on the Shipments meta box. Renders the
 * real class and template against stand-ins for WordPress, WooCommerce,
 * ShipTracker and the FisHotel Misc plugin. No WordPress, no database.
 *
 *   php tests/shipments-letters-render-test.php
 *
 * A letter is a wp_fst_shipments row with carrier `usps_letter` and no
 * order lines. To the box it is no box:
 *   - a fish order with a box and a sticker letter: the box's lines are
 *     shipped, the others stay open (the letter covers nothing); Packing's
 *     next box is still "Box 2 of 2"; the costs line is what it was; the
 *     page is — to the character — the page without the letter, plus one
 *     plain line "Sticker letter · mailed — managed in USPS Letter Tracking";
 *   - a letter before any box: every line open, the form starts on
 *     Packing's only box (Box 1 of 1, so no "Box … of …");
 *   - a letter-only EA order: no box, "How it went out" is offered;
 *   - a combined order: a source order's letter is a line with its source;
 *   - "Sticker letter" or "Letter" is what ShipTracker recorded inside it
 *     (FST_Letters::get_by_shipment()->contents), the order's flag only
 *     when nothing is recorded: a sticker letter on an order without the
 *     flag still reads "Sticker letter";
 *   - the only Re-check and Remove buttons are the boxes': the letter has
 *     none and no tracking link (Remove on a box sends that box's id only);
 *   - with ShipTracker 1.9.18 (no letters) nothing of this shows.
 *
 * Each rendering runs in a child process:
 *
 *   php tests/shipments-letters-render-test.php --scene=fish-letter --letters=yes
 *
 * prints that scene's HTML (scenes: fish-letter, letter-first, letter-only,
 * combined, unflagged; letters: yes, no — "no" is a ShipTracker 1.9.18 site, which has
 * none). `--theme=DIR` renders another copy of the theme (with no scene:
 * runs every check against it — the theme before v1.28.1 fails them).
 *
 * All orders, customers, boxes and fish here are invented.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$opts = [ 'scene' => '', 'letters' => 'yes', 'theme' => dirname( __DIR__ ) ];
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( preg_match( '/^--(scene|letters|theme)=(.+)$/', $arg, $m ) ) {
		$opts[ $m[1] ] = $m[2];
	}
}

// ── The parent: render every case in a child and look at the HTML ───────
if ( '' === $opts['scene'] ) {
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
	if ( ! function_exists( 'exec' ) ) {
		echo "skip (exec() is unavailable)\n";
		exit( 0 );
	}
	/** The HTML of one scene. */
	function render( $scene, $letters ) {
		$output = [];
		$code   = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --scene=' . escapeshellarg( $scene ) . ' --letters=' . escapeshellarg( $letters ) . ' --theme=' . escapeshellarg( $GLOBALS['opts']['theme'] ) . ' 2>&1', $output, $code );
		$html = implode( "\n", $output );
		check( "$scene / letters $letters renders", 0 === $code && false !== strpos( $html, 'class="fishotel-shipments-metabox"' ) && false === strpos( $html, 'Warning' ) && false === strpos( $html, 'Notice' ) && false === strpos( $html, 'Fatal' ), substr( $html, 0, 300 ) );
		return $html;
	}
	/** The part of the HTML from one marker up to another. */
	function part( $html, $from, $to ) {
		$start = strpos( $html, $from );
		if ( false === $start ) {
			return '';
		}
		$end = strpos( $html, $to, $start );
		return false === $end ? substr( $html, $start ) : substr( $html, $start, $end - $start );
	}
	/** The letter lines, as printed. */
	function letter_lines( $html ) {
		preg_match_all( '/<p class="description fhsm-letter">([^<]*)<\/p>/', $html, $m );
		return $m[1];
	}
	/** The page with its letter lines taken out, and its blank lines (only the template's whitespace). */
	function without_letter_lines( $html ) {
		return no_blank_lines( preg_replace( '/<p class="description fhsm-letter">[^<]*<\/p>/', '', $html ) );
	}
	/** The page without its whitespace-only lines. */
	function no_blank_lines( $html ) {
		return implode( "\n", array_filter( explode( "\n", $html ), static function ( $line ) {
			return '' !== trim( $line );
		} ) );
	}
	/** Order line checkbox → disabled? item id => bool. */
	function line_disabled( $html ) {
		preg_match_all( '/<input type="checkbox"[^>]*class="fhsm-line-item-check"[^>]*>/s', $html, $m );
		$out = [];
		foreach ( $m[0] as $tag ) {
			if ( preg_match( '/value="(\d+)"/', $tag, $v ) ) {
				$out[ (int) $v[1] ] = false !== strpos( $tag, 'disabled' );
			}
		}
		ksort( $out );
		return $out;
	}
	/** data-shipment-id of every button of a class. */
	function button_ids( $html, $class ) {
		preg_match_all( '/class="button ' . preg_quote( $class, '/' ) . '" data-shipment-id="(\d+)"/', $html, $m );
		return array_map( 'intval', $m[1] );
	}
	/** data-shipment-id of every box card. */
	function card_ids( $html ) {
		preg_match_all( '/<div class="fhsm-shipment" data-shipment-id="(\d+)"/', $html, $m );
		return array_map( 'intval', $m[1] );
	}
	/** The "From Packing …" words of the Add Shipment form. */
	function add_from( $html ) {
		$from = part( $html, '<p class="fhsm-new-cost-heading">', '<p class="fhsm-cost-field" data-field="weight">' );
		return preg_match( '/<span class="fhsm-box-from-text">([^<]*)<\/span>/', $from, $m ) ? $m[1] : '';
	}

	echo "a fish order with a box and a sticker letter\n";
	$with    = render( 'fish-letter', 'yes' );
	$without = render( 'fish-letter', 'no' );
	check( 'one plain line for the letter', [ 'Sticker letter · mailed — managed in USPS Letter Tracking' ] === letter_lines( $with ), json_encode( letter_lines( $with ) ) );
	check( 'the box is the only card', [ 91 ] === card_ids( $with ), json_encode( card_ids( $with ) ) );
	check( 'the box\'s line is shipped, the line still to go is open (the letter covers nothing)', [ 8001 => true, 8002 => false ] === line_disabled( $with ), json_encode( line_disabled( $with ) ) );
	check( '"Add Shipment" is still there', false !== strpos( $with, 'id="fhsm-save-shipment"' ) && false === strpos( $with, 'fhsm-all-covered' ) );
	check( 'Packing\'s next box is still the second of two', 'From Packing (confirmed) · Box 2 of 2' === add_from( $with ), add_from( $with ) );
	$summary = preg_replace( '/\s+/', ' ', strip_tags( part( $with, '<p class="fhsm-cost-totals', '</p>' ) ) );
	check( 'the costs line is the box\'s: paid $40.00, label $41.20, difference $-1.20, nothing missing', false !== strpos( $summary, 'Shipping paid: $40.00' ) && false !== strpos( $summary, 'Label cost recorded: $41.20' ) && false !== strpos( $summary, 'Difference: $-1.20' ) && false === strpos( $with, 'no cost yet' ), $summary );
	check( 'Re-check and Remove are the box\'s only', [ 91 ] === button_ids( $with, 'fhsm-recheck' ) && [ 91 ] === button_ids( $with, 'fhsm-remove' ) );
	check( 'nothing on the page points at the letter\'s row', false === strpos( $with, 'data-shipment-id="92"' ) && false === strpos( $with, 'fhsm-edit-92' ) );
	check( 'no tracking link and no barcode number for the letter', false === strpos( $with, '0070012345' ) && false === strpos( $with, 'USPS_LETTER' ) );
	check( 'apart from that line the page is — to the character, blank lines aside — the one without the letter', without_letter_lines( $with ) === no_blank_lines( $without ), 'diff in ' . strlen( without_letter_lines( $with ) ) . ' vs ' . strlen( $without ) );

	echo "a letter before any box\n";
	$with    = render( 'letter-first', 'yes' );
	$without = render( 'letter-first', 'no' );
	check( 'a line saying it is not mailed yet', [ 'Sticker letter · label printed, not mailed yet — managed in USPS Letter Tracking' ] === letter_lines( $with ), json_encode( letter_lines( $with ) ) );
	check( 'no card, every line open', [] === card_ids( $with ) && [ 8021 => false, 8022 => false ] === line_disabled( $with ), json_encode( line_disabled( $with ) ) );
	check( 'the form starts on Packing\'s only box (Box 1 of 1: no number)', 'From Packing (suggested)' === add_from( $with ) && false !== strpos( part( $with, '<p class="fhsm-new-cost-heading">', '<p class="fhsm-cost-field" data-field="weight">' ), 'data-on="1"' ), add_from( $with ) );
	check( '"How it went out" is offered, as with no letter', false !== strpos( $with, '<div class="fhsm-ship-mode"' ) );
	check( 'no "box has no cost yet"', false === strpos( $with, 'no cost yet' ) );
	check( 'apart from that line, the page without the letter', without_letter_lines( $with ) === no_blank_lines( $without ) );

	echo "a letter-only EA order\n";
	$with    = render( 'letter-only', 'yes' );
	$without = render( 'letter-only', 'no' );
	check( 'a line saying it is likely delivered', [ 'Sticker letter · likely delivered — managed in USPS Letter Tracking' ] === letter_lines( $with ), json_encode( letter_lines( $with ) ) );
	check( 'no box', [] === card_ids( $with ) && [] === button_ids( $with, 'fhsm-remove' ) && false === strpos( $with, 'Covers all items' ) );
	check( '"How it went out" is not blocked: offered with its Save', false !== strpos( $with, '<div class="fhsm-ship-mode"' ) && false !== strpos( $with, 'fhsm-ship-mode-save' ) );
	check( 'its line is not shipped', [ 8031 => false ] === line_disabled( $with ) && false !== strpos( $with, 'id="fhsm-save-shipment"' ) );
	check( 'apart from that line, the page without the letter', without_letter_lines( $with ) === no_blank_lines( $without ) );

	echo "a combined order\n";
	$with    = render( 'combined', 'yes' );
	$without = render( 'combined', 'no' );
	check( 'a source order\'s letter: one line, with its source, not a sticker letter', [ 'Letter · mailed, USPS reports a problem — managed in USPS Letter Tracking (source: #6005)' ] === letter_lines( $with ), json_encode( letter_lines( $with ) ) );
	check( 'the shared box\'s two cards only', [ 94, 95 ] === card_ids( $with ) );
	check( 'the source\'s line that is not in the box stays open', [ 8041 => true, 8051 => true, 8052 => false ] === line_disabled( $with ), json_encode( line_disabled( $with ) ) );
	check( 'apart from that line, the page without the letter', without_letter_lines( $with ) === no_blank_lines( $without ) );

	echo "a sticker letter on an order without the flag\n";
	$with    = render( 'unflagged', 'yes' );
	$without = render( 'unflagged', 'no' );
	check( 'ShipTracker recorded stickers inside: "Sticker letter", not "Letter"', [ 'Sticker letter · mailed — managed in USPS Letter Tracking' ] === letter_lines( $with ), json_encode( letter_lines( $with ) ) );
	check( 'its box is the only card, its lines as the box says', [ 99 ] === card_ids( $with ) && [ 8061 => true, 8062 => false ] === line_disabled( $with ), json_encode( line_disabled( $with ) ) );
	check( 'apart from that line, the page without the letter', without_letter_lines( $with ) === no_blank_lines( $without ) );

	echo "ShipTracker 1.9.18 (no letters)\n";
	foreach ( [ 'fish-letter', 'letter-first', 'letter-only', 'combined', 'unflagged' ] as $scene ) {
		check( "$scene: no letter line, nothing of letters", [] === letter_lines( render( $scene, 'no' ) ) && false === strpos( render( $scene, 'no' ), 'fhsm-letter' ) );
	}

	echo "\n" . ( $failures ? "$failures FAILED\n" : "all passed\n" );
	exit( $failures ? 1 : 0 );
}

// ── A child: one scene, printed ─────────────────────────────────────────

define( 'ABSPATH', __DIR__ . '/' );
define( 'FISHOTEL_THEME_DIR', rtrim( $opts['theme'], '/' ) );
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

// WordPress.
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return str_replace( [ '&', "'" ], [ '&#038;', '&#039;' ], (string) $url ); }
function __( $text, $domain = '' ) { return $text; }
function _n( $single, $plural, $number, $domain = '' ) { return 1 === (int) $number ? $single : $plural; }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = '' ) { return esc_attr( $text ); }
function esc_html_e( $text, $domain = '' ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = '' ) { echo esc_attr( $text ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_kses_post( $html ) { return $html; }
function selected( $selected, $current = true, $display = true ) {
	$out = (string) $selected === (string) $current ? " selected='selected'" : '';
	if ( $display ) {
		echo $out;
	}
	return $out;
}
function disabled( $disabled, $current = true, $display = true ) {
	$out = (string) $disabled === (string) $current ? " disabled='disabled'" : '';
	if ( $display ) {
		echo $out;
	}
	return $out;
}
function wp_nonce_field( $action, $name ) { echo '<input type="hidden" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="nonce">'; }
function admin_url( $path = '' ) { return 'https://shop.test/wp-admin/' . $path; }
function get_option( $name, $default = false ) { return 'date_format' === $name ? 'F j, Y' : $default; }
function date_i18n( $format, $time ) { return gmdate( $format, $time ); }
function human_time_diff( $from ) { return '2 hours'; }
function get_woocommerce_currency_symbol() { return '&#36;'; }
function wc_price( $amount ) { return '<span class="amount">$' . number_format( (float) $amount, 2 ) . '</span>'; }

// WooCommerce.
class WC_Order_Item_Product {
	private $name;
	private $qty;
	public function __construct( $name, $qty ) { $this->name = $name; $this->qty = $qty; }
	public function get_name() { return $this->name; }
	public function get_quantity() { return $this->qty; }
	public function get_meta( $key ) { return ''; }
	public function get_product_id() { return 0; }
	public function get_variation_id() { return 0; }
}
class WC_Order {
	private $id;
	private $items = [];
	private $meta;
	public function __construct( $id, array $items, array $meta = [] ) {
		$this->id   = $id;
		$this->meta = $meta;
		foreach ( $items as $item_id => $item ) {
			$this->items[ $item_id ] = new WC_Order_Item_Product( $item[0], $item[1] );
		}
	}
	public function get_id() { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_items( $type = 'line_item' ) { return $this->items; }
	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function get_qty_refunded_for_item( $item_id ) { return 0; }
	public function get_shipping_total() { return isset( $this->meta['shipping'] ) ? $this->meta['shipping'] : '0.00'; }
	public function get_total_shipping_refunded() { return 0; }
	public function get_edit_order_url() { return 'https://shop.test/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->id; }
	public function get_formatted_billing_full_name() { return 'Sam Example'; }
}

$orders = [
	// A fish order: one box sent, one still to go; its stickers go by letter.
	6001 => new WC_Order( 6001, [ 8001 => [ 'Yellow Tang', 2 ], 8002 => [ 'Ocellaris Clownfish', 1 ] ], [ 'shipping' => '40.00', '_fishotel_sticker_mail_letter' => 1 ] ),
	// A fish order whose sticker letter was printed before its box.
	6003 => new WC_Order( 6003, [ 8021 => [ 'Royal Gramma', 2 ], 8022 => [ 'Firefish Goby', 1 ] ], [ 'shipping' => '35.00', '_fishotel_sticker_mail_letter' => 2 ] ),
	// An EA-only order: the stickers are all that goes from here.
	6002 => new WC_Order( 6002, [ 8031 => [ 'Reef Food (EA)', 1 ] ], [ 'shipping' => '0.00', '_fishotel_sticker_mail_letter' => 1 ] ),
	// A combined order of two source orders; 6005 also had a letter (not a sticker one).
	6004 => new WC_Order( 6004, [ 8041 => [ 'Banggai Cardinalfish', 2 ] ], [ 'shipping' => '25.00', '_fishotel_fulfilled_by_order' => 6900 ] ),
	6005 => new WC_Order( 6005, [ 8051 => [ 'Coral Beauty Angelfish', 1 ], 8052 => [ 'Six Line Wrasse', 1 ] ], [ 'shipping' => '25.00', '_fishotel_fulfilled_by_order' => 6900 ] ),
	6900 => new WC_Order( 6900, [], [ 'sources' => [ 6004, 6005 ] ] ),
	// A fish order without the flag; a sticker letter went out for it all the same.
	6006 => new WC_Order( 6006, [ 8061 => [ 'Lawnmower Blenny', 1 ], 8062 => [ 'Scooter Dragonet', 1 ] ], [ 'shipping' => '30.00' ] ),
];
const SCENES = [ 'fish-letter' => 6001, 'letter-first' => 6003, 'letter-only' => 6002, 'combined' => 6900, 'unflagged' => 6006 ];

function wc_get_order( $id ) {
	global $orders;
	return isset( $orders[ (int) $id ] ) ? $orders[ (int) $id ] : false;
}
function fishotel_is_fulfillment( $order ) { return [] !== fishotel_fulfillment_get_sources( $order ); }
function fishotel_fulfillment_get_sources( $order ) {
	$sources = $order->get_meta( 'sources' );
	return is_array( $sources ) ? $sources : [];
}

// ShipTracker (v1.10.0 with --letters=yes, v1.9.18 with --letters=no).
class FST_Settings {
	public static function get_standard_boxes() {
		return [
			[ 'name' => 'Cube 12', 'length' => '12', 'width' => '12', 'height' => '12', 'dims' => '12x12x12', 'foam_cost' => '1.80', 'paper_cost' => '0.40' ],
			[ 'name' => 'Long 20', 'length' => '20', 'width' => '14', 'height' => '12', 'dims' => '20x14x12', 'foam_cost' => '3.10', 'paper_cost' => '0.60' ],
		];
	}
	public static function get_label_sources() { return [ 'Partner A', 'Partner B' ]; }
}
class FST_Shipment {
	/**
	 * id => [ order id, tracking number, item ids (null = none listed), box size, label cost, carrier, status ].
	 * The letters' rows are ShipTracker 1.10.0's: the barcode as the
	 * tracking number, no lines, no cost.
	 */
	public static function rows() {
		$rows = [
			91 => [ 6001, '1ZLTR0001', [ 8001 ], '12x12x12', '41.20', 'ups', 'in_transit' ],
			94 => [ 6004, '1ZCMB0001', [ 8041 ], '20x14x12', '38.00', 'ups', 'in_transit' ],
			95 => [ 6005, '1ZCMB0001', [ 8051 ], null, null, 'ups', 'in_transit' ],
			99 => [ 6006, '1ZUNF0001', [ 8061 ], '12x12x12', '30.00', 'ups', 'in_transit' ],
		];
		if ( 'yes' === $GLOBALS['opts']['letters'] ) {
			$rows += [
				92 => [ 6001, '0070012345600000001155555123456', null, null, null, 'usps_letter', 'in_transit' ],
				93 => [ 6002, '0070012345600000002155555123456', null, null, null, 'usps_letter', 'likely_delivered' ],
				96 => [ 6005, '0070012345600000003155555123456', null, null, null, 'usps_letter', 'exception' ],
				97 => [ 6003, '0070012345600000004155555123456', null, null, null, 'usps_letter', 'label_created' ],
				98 => [ 6006, '0070012345600000005155555123456', null, null, null, 'usps_letter', 'in_transit' ],
			];
		}
		ksort( $rows );
		return $rows;
	}
	public static function get_by_order( $order_id ) {
		$rows = [];
		foreach ( self::rows() as $id => $r ) {
			if ( $r[0] === (int) $order_id ) {
				$rows[] = (object) [ 'id' => $id, 'order_id' => $r[0], 'tracking_number' => $r[1], 'line_item_ids' => null === $r[2] ? null : json_encode( $r[2] ), 'status' => $r[6], 'carrier' => $r[5] ];
			}
		}
		return $rows;
	}
	/** As 1.10.0 (234617a) answers it: one entry per tracking number, letters included. */
	public static function get_order_boxes( $order_ids ) {
		$boxes = [];
		$ids   = array_map( 'intval', (array) $order_ids );
		foreach ( self::rows() as $id => $r ) {
			if ( ! in_array( $r[0], $ids, true ) ) {
				continue;
			}
			$key = strtoupper( $r[1] );
			if ( isset( $boxes[ $key ] ) ) {
				continue;
			}
			$order_ids_of = [];
			$cost         = null;
			$dims         = null;
			$shipment_ids = [];
			foreach ( self::rows() as $oid => $o ) {
				if ( strtoupper( $o[1] ) === $key ) {
					$order_ids_of[] = $o[0];
					$shipment_ids[] = $oid;
					$cost           = null !== $o[4] ? $o[4] : $cost;
					$dims           = null !== $o[3] ? $o[3] : $dims;
				}
			}
			$boxes[ $key ] = [
				'tracking_number' => $r[1], 'label_cost' => $cost, 'box_weight' => null, 'box_dims' => $dims, 'label_source' => null,
				'shared_box' => count( $order_ids_of ) > 1, 'order_ids' => $order_ids_of, 'shipment_ids' => $shipment_ids,
				'shipment_id' => $id, 'carrier' => $r[5], 'status' => $r[6], 'ship_date' => null,
				'materials' => null, 'materials_total' => null, 'total_cost' => $cost,
			];
		}
		return array_values( $boxes );
	}
	public static function get_box_cost( $tracking_number ) { return null; }
}
if ( 'yes' === $opts['letters'] ) {
	/**
	 * ShipTracker 1.10.0's letters table, as FST_Letters::get_by_shipment()
	 * reads it: the current letter of a shipment row. Row 92 has no record
	 * here, so its order's flag decides; 93 and 97 hold stickers as their
	 * flagged orders say; 96 a plain letter; 98 stickers on an order
	 * without the flag.
	 */
	class FST_Letters {
		public static function get_by_shipment( $shipment_id ) {
			$contents = [ 93 => 'sticker', 96 => 'letter', 97 => 'sticker', 98 => 'sticker' ];
			return isset( $contents[ (int) $shipment_id ] ) ? (object) [ 'id' => 500 + (int) $shipment_id, 'shipment_id' => (int) $shipment_id, 'contents' => $contents[ (int) $shipment_id ], 'state' => 'active' ] : null;
		}
	}
}
class FST_Carrier {
	public static function get_status_color( $status ) { return '#2271b1'; }
	public static function get_status_label( $status ) { return ucwords( str_replace( '_', ' ', $status ) ); }
}
class FST_Carrier_UPS {
	public function get_tracking_url( $tracking ) { return 'https://carrier.test/track?n=' . $tracking; }
}
class FST_Order {
	public function set_ship_mode( $order_id, array $args ) { return []; }
}
class FST_Ship_Mode {
	public static function describe( $order ) {
		return [ 'mode' => 'label', 'with_order_id' => null, 'with_order_number' => null, 'date' => null, 'materials' => null, 'materials_total' => null ];
	}
	public static function get_riders_of( $order_id ) { return []; }
	public static function host_order_ids( $order ) { return [ $order->get_id() ]; }
}
class FST_Supplies {
	const SUPPLIES = [
		[ 'id' => 3, 'name' => 'Fish bag', 'unit' => 'each', 'counted' => true, 'role' => 'fish_bag', 'box_name' => '', 'price_each' => '0.1100', 'on_hand' => '412.00', 'recount_needed' => false, 'low' => false ],
		[ 'id' => 6, 'name' => 'Cube 12 box', 'unit' => 'each', 'counted' => true, 'role' => 'box', 'box_name' => 'Cube 12', 'price_each' => '1.2500', 'on_hand' => '18.00', 'recount_needed' => false, 'low' => false ],
		[ 'id' => 8, 'name' => 'Long 20 box', 'unit' => 'each', 'counted' => true, 'role' => 'box', 'box_name' => 'Long 20', 'price_each' => '2.2000', 'on_hand' => '10.00', 'recount_needed' => false, 'low' => false ],
	];
	public static function get_supplies() { return self::SUPPLIES; }
	public static function order_pack_action( $order ) { return ''; }
	public static function get_box_materials( $tracking_number ) { return null; }
	public static function fish_count( $order, $item_ids = null ) {
		$count = 0;
		foreach ( $order instanceof WC_Order ? $order->get_items() : [] as $item_id => $item ) {
			if ( null === $item_ids || in_array( (int) $item_id, array_map( 'intval', $item_ids ), true ) ) {
				$count += $item->get_quantity();
			}
		}
		return $count;
	}
	public static function get_default_materials( $fish_count, $action ) {
		$boxes = [];
		foreach ( FST_Settings::get_standard_boxes() as $box ) {
			$boxes[] = [ 'name' => $box['name'], 'dims' => $box['dims'], 'materials' => [ 'lines' => [], 'foam_cost' => $box['foam_cost'], 'paper_cost' => $box['paper_cost'] ] ];
		}
		return [ 'boxes' => $boxes, 'custom' => [ 'lines' => [], 'foam_cost' => '0.00', 'paper_cost' => '0.00' ] ];
	}
}

// The FisHotel Misc plugin's Packing plan (v0.46.15).
function fishotel_packing_shipment_plan( $order_id ) {
	$empty = [ 'available' => false, 'reason' => 'no_plan', 'order_id' => $order_id, 'owner_id' => $order_id, 'packed_with' => 0, 'from' => '', 'split' => false, 'boxes' => [], 'lines' => [], 'totals' => [ 'fish' => 0, 'bags' => 0, 'layers' => 0 ] ];
	$box   = static function ( $index, $name, $dims, $items ) {
		return [ 'index' => $index, 'name' => $name, 'dims' => $dims, 'standard' => [ 'name' => $name, 'dims' => $dims ], 'match' => 'name', 'fish' => 0, 'bags' => 0, 'layers' => 4, 'by_bag' => [], 'items' => $items ];
	};
	$plans = [
		6001 => [ 'from' => 'confirmed', 'boxes' => [ $box( 0, 'Cube 12', '12x12x12', [ '6001-8001' => 2 ] ), $box( 1, 'Long 20', '20x14x12', [ '6001-8002' => 1 ] ) ] ],
		6003 => [ 'from' => 'suggestion', 'boxes' => [ $box( 0, 'Cube 12', '12x12x12', [ '6003-8021' => 2, '6003-8022' => 1 ] ) ] ],
	];
	if ( ! isset( $plans[ $order_id ] ) ) {
		return $empty;
	}
	return array_merge( $empty, [ 'available' => true, 'reason' => '', 'split' => count( $plans[ $order_id ]['boxes'] ) > 1 ], $plans[ $order_id ] );
}

if ( ! isset( SCENES[ $opts['scene'] ] ) ) {
	fwrite( STDERR, "unknown scene\n" );
	exit( 2 );
}

require_once FISHOTEL_THEME_DIR . '/inc/order-fulfillment/class-fishotel-shipment-cost.php';
require_once FISHOTEL_THEME_DIR . '/inc/order-fulfillment/class-fishotel-packing-prefill.php';
require_once FISHOTEL_THEME_DIR . '/inc/order-fulfillment/class-fishotel-shipments-metabox.php';

FisHotel_Shipments_Metabox::render( $orders[ SCENES[ $opts['scene'] ] ] );
