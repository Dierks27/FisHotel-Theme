<?php
/**
 * Renders the Shipments meta box — the real class and template — against
 * stand-ins for WordPress, WooCommerce, ShipTracker and the FisHotel Misc
 * plugin, and checks what Packing's box does to it. No WordPress, no
 * database.
 *
 *   php tests/shipments-prefill-render-test.php
 *
 * Covers: the Add Shipment form starts on Packing's box (by the standard
 * box's name when two share a size; "Custom…" with the size typed in when
 * it is not a standard box) and says "From Packing (…)" with a "clear";
 * the weight, the label cost and the tracking number stay empty; an order
 * in several boxes starts on the first one not used up ("Box 3 of 3"); a
 * recorded box keeps its record, and one recorded without a size is only
 * offered "Use Packing's box"; a source order packed with a combined box
 * links to it; a combined order is asked by its own id; the fish bag
 * numbers on the order lines are Packing's bags; the form is handed the
 * order lines Packing put in the box; a kit that went ahead in a box of its
 * own does not use up the fish box; and what the box answers when the
 * Packing box changed (the piece for the Add Shipment form, the offer for a
 * box recorded without a size).
 *
 * And the guard rails: with the Misc plugin missing, without a plan,
 * answering nonsense or throwing, the box is — to the character — the same.
 *
 * Each rendering runs in a child process (the Misc function either exists
 * or does not):
 *
 *   php tests/shipments-prefill-render-test.php --scene=split --misc=answers
 *
 * prints that scene's HTML (scenes: one-box, split, custom, folded,
 * combined, kit-first; misc: absent, answers, noplan, garbage, throws).
 * `--theme=DIR` renders another copy of the theme with the same stand-ins,
 * to compare two versions; `--data=1` prints, as JSON, what the box answers
 * when the Packing box says it changed (packing_box_data()) instead.
 *
 * All orders, customers, boxes and fish here are invented.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$opts = [ 'scene' => '', 'misc' => 'absent', 'theme' => dirname( __DIR__ ), 'data' => '' ];
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( preg_match( '/^--(scene|misc|theme|data)=(.+)$/', $arg, $m ) ) {
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
	function render( $scene, $misc ) {
		$output = [];
		$code   = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --scene=' . escapeshellarg( $scene ) . ' --misc=' . escapeshellarg( $misc ) . ' 2>&1', $output, $code );
		$html = implode( "\n", $output );
		check( "$scene / Misc $misc renders", 0 === $code && false !== strpos( $html, 'class="fishotel-shipments-metabox"' ) && false === strpos( $html, 'Warning' ) && false === strpos( $html, 'Fatal' ), substr( $html, 0, 300 ) );
		return $html;
	}
	/** What the box answers when Packing's box is asked for again, for one scene. */
	function box_data( $scene, $misc ) {
		$output = [];
		$code   = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --scene=' . escapeshellarg( $scene ) . ' --misc=' . escapeshellarg( $misc ) . ' --data=1 2>&1', $output, $code );
		$data = json_decode( implode( "\n", $output ), true );
		check( "$scene / Misc $misc answers", 0 === $code && is_array( $data ) && [ 'add', 'cards', 'bag_note' ] === array_keys( $data ), substr( implode( "\n", $output ), 0, 300 ) );
		return is_array( $data ) ? $data : [ 'add' => '', 'cards' => [], 'bag_note' => '' ];
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
	/** The selected option of a box size dropdown: [ value, name ]. */
	function selected_box( $html, $id ) {
		$select = part( $html, '<select id="' . $id . '-box"', '</select>' );
		if ( ! preg_match_all( '/<option value="([^"]*)"(?: data-name="([^"]*)")?\s+selected=/', $select, $m, PREG_SET_ORDER ) || 1 !== count( $m ) ) {
			return [ '', '' ];
		}
		return [ $m[0][1], isset( $m[0][2] ) ? $m[0][2] : '' ];
	}
	/** The value of an input, by id. */
	function input_value( $html, $id ) {
		return preg_match( '/<input type="text" id="' . preg_quote( $id, '/' ) . '"[^>]*\svalue="([^"]*)"/s', $html, $m ) ? $m[1] : null;
	}
	/** data-fish of every order line checkbox: item id => number. */
	function line_fish( $html ) {
		preg_match_all( '/value="(\d+)"\s+data-source-id="\d+"\s+data-available-qty="\d+"\s+data-fish="([^"]*)"/', $html, $m, PREG_SET_ORDER );
		$fish = [];
		foreach ( $m as $hit ) {
			$fish[ (int) $hit[1] ] = $hit[2];
		}
		return $fish;
	}
	/** What the page hands admin-shipments.js. */
	function packing_data( $html ) {
		return preg_match( '/data-packing="([^"]*)"/', $html, $m ) ? json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true ) : null;
	}

	echo "without the Misc plugin\n";
	$plain = render( 'one-box', 'absent' );
	check( 'the Add Shipment form is on "— Not recorded —"', [ '', '' ] === selected_box( $plain, 'fhsm-new' ) );
	check( 'nothing of Packing is on the page', false === strpos( $plain, 'fhsm-box-packing' ) && false === strpos( $plain, 'From Packing' ) && false === strpos( $plain, 'Use Packing' ) && false === strpos( $plain, 'fhsm-packed-with' ) );
	check( 'the script is told nothing about bags', ! array_key_exists( 'bagNote', (array) packing_data( $plain ) ) );
	check( 'an order line carries its fish', [ 7001 => '2', 7002 => '3' ] === line_fish( $plain ), json_encode( line_fish( $plain ) ) );
	foreach ( [ 'noplan' => 'has no plan for the order', 'garbage' => 'answers something that is not a plan', 'throws' => 'throws' ] as $misc => $what ) {
		// The bag numbers are ShipTracker's to hand over (its fish count, which
		// Misc answers); with no plan they are the fish, as without Misc.
		check( "Misc $what: the box is, to the character, the one without Misc", $plain === render( 'one-box', $misc ) );
	}
	foreach ( [ 'split', 'custom', 'folded', 'combined', 'kit-first' ] as $scene ) {
		$html = render( $scene, 'absent' );
		check( "$scene without Misc: nothing of Packing", false === strpos( $html, 'fhsm-box-packing' ) && false === strpos( $html, 'fhsm-packed-with' ) && ! array_key_exists( 'bagNote', (array) packing_data( $html ) ) );
	}

	echo "one box\n";
	$html = render( 'one-box', 'answers' );
	check( 'the Add Shipment form starts on Packing\'s box — the one of that NAME, not the first of that size', [ '12x12x12', 'Tall 12' ] === selected_box( $html, 'fhsm-new' ), json_encode( selected_box( $html, 'fhsm-new' ) ) );
	$from = part( $html, '<span class="fhsm-box-packing"', '</p>' );
	check( 'it says where the box comes from, with a "clear"', false !== strpos( $from, '>From Packing (suggested)</span>' ) && false !== strpos( $from, 'class="button-link fhsm-box-clear">clear</button>' ), $from );
	check( 'the form is on it; the bags of that box go along', false !== strpos( $from, 'data-on="1"' ) && false !== strpos( $from, 'data-name="Tall 12"' ) && false !== strpos( $from, 'data-value="12x12x12"' ) && false !== strpos( $from, 'data-layers="12"' ), $from );
	check( '"Use Packing\'s box" waits, hidden, for after a clear', 1 === preg_match( '/class="button-link fhsm-box-use" hidden>Use Packing&#039;s box: Tall 12 \(12x12x12\)<\/button>/', $from ) && 1 === preg_match( '/<span class="fhsm-box-from">/', $from ), $from );
	check( 'the custom size input stays hidden and empty', 1 === preg_match( '/id="fhsm-new-dims"[^>]*value=""\s*style="display:none;"/s', $html ) );
	check( 'the label cost is not filled in', '' === input_value( $html, 'fhsm-new-cost' ) );
	check( 'the weight is not filled in', '' === input_value( $html, 'fhsm-new-weight' ) );
	check( 'the tracking number is not filled in', 1 === preg_match( '/<input type="text" id="fhsm-new-tracking" class="widefat" placeholder="1Z999AA1\.\.\.">/', $html ) );
	check( 'no item is ticked', 0 === preg_match( '/class="fhsm-line-item-check"[^>]*\schecked/s', $html ) );
	check( 'an order line carries Packing\'s bags: 2 tangs × 3, 3 clownfish × 2', [ 7001 => '6', 7002 => '6' ] === line_fish( $html ), json_encode( line_fish( $html ) ) );
	$data = packing_data( $html );
	check( 'the script is told what the bag line says', 'Double-bagged, triple for spiny fish — from Packing' === ( isset( $data['bagNote'] ) ? $data['bagNote'] : null ) );
	check( 'the form is handed the order lines Packing put in the box', false !== strpos( $from, 'data-items="5001-7001 5001-7002"' ), $from );
	check( 'the word for "the items ticked are not in this box" waits, hidden', 1 === preg_match( '/<span class="fhsm-box-why" hidden>None of the items ticked is in Packing&#039;s box, so no box is filled in\.<\/span>/', $from ), $from );
	check( 'the usual packing was asked for the bags, not the fish', isset( $data['defaults']['12|heat_pack'] ), json_encode( array_keys( (array) $data['defaults'] ) ) );
	check( 'apart from Packing\'s box the form is the one without Misc', false !== strpos( $html, '<option value="20x14x12" data-name="Long 20" >' ) && false === strpos( $html, 'fhsm-packed-with' ) );

	echo "an order in several boxes\n";
	$html = render( 'split', 'answers' );
	check( 'the form starts on the first box not used up: the third', [ '20x14x12', 'Long 20' ] === selected_box( $html, 'fhsm-new' ), json_encode( selected_box( $html, 'fhsm-new' ) ) );
	$from = part( $html, '<p class="fhsm-new-cost-heading">', '<p class="fhsm-cost-field" data-field="weight">' );
	check( 'and says which one, confirmed', false !== strpos( $from, '>From Packing (confirmed) · Box 3 of 3</span>' ) && false !== strpos( $from, 'data-layers="11"' ), $from );
	check( 'no item is ticked for it', 0 === preg_match( '/class="fhsm-line-item-check"[^>]*\schecked/s', $html ) );
	$sized = part( $html, 'id="fhsm-edit-81"', 'id="fhsm-edit-82"' );
	check( 'a box recorded with its size keeps it', [ '12x12x12', 'Cube 12' ] === selected_box( $sized, 'fhsm-edit-81' ) && '53.73' === input_value( $sized, 'fhsm-edit-81-cost' ) );
	check( '— and is offered nothing', false === strpos( $sized, 'fhsm-box-packing' ) );
	$unsized = part( $html, 'id="fhsm-edit-82"', '<div class="fhsm-add-shipment">' );
	check( 'a box recorded without a size is not put on a box', [ '', '' ] === selected_box( $unsized, 'fhsm-edit-82' ), json_encode( selected_box( $unsized, 'fhsm-edit-82' ) ) );
	check( '— it gets the link, not the mark', 1 === preg_match( '/class="button-link fhsm-box-use">Use Packing&#039;s box: Cube 12 \(12x12x12\)<\/button>/', $unsized ) && false !== strpos( $unsized, 'data-on="0"' ) && 1 === preg_match( '/<span class="fhsm-box-from" hidden>/', $unsized ), part( $unsized, '<span class="fhsm-box-packing"', '</p>' ) );
	check( '— for the second box, the one left of that size', false !== strpos( $unsized, '>From Packing (confirmed) · Box 2 of 3</span>' ) );
	check( 'what the recorded boxes show is what it was', false !== strpos( $html, 'Label cost' ) && false !== strpos( $html, 'Cube 12 (12x12x12)' ) );
	check( 'the lines still to ship carry Packing\'s bags', [ 7013 => '8', 7014 => '3' ] === array_intersect_key( line_fish( $html ), [ 7013 => 1, 7014 => 1 ] ), json_encode( line_fish( $html ) ) );

	echo "dry goods sent ahead of the fish\n";
	$html = render( 'kit-first', 'answers' );
	check( 'the kit went out in a box of its own, recorded with the fish box\'s size', false !== strpos( $html, 'id="fhsm-edit-83"' ) && 1 === preg_match( '/value="7052"[^>]*disabled/s', $html ) );
	check( 'the fish box is not used up by it: the form still starts on Packing\'s box', [ '12x12x12', 'Tall 12' ] === selected_box( $html, 'fhsm-new' ) && false !== strpos( part( $html, '<p class="fhsm-new-cost-heading">', '<p class="fhsm-cost-field" data-field="weight">' ), 'data-on="1"' ), json_encode( selected_box( $html, 'fhsm-new' ) ) );
	check( 'which holds the fish line only', false !== strpos( $html, 'data-items="5030-7051"' ) );
	check( 'the kit takes no bag', [ 7051 => '4', 7052 => '0' ] === line_fish( $html ), json_encode( line_fish( $html ) ) );

	echo "the Packing box changed: its box, asked for again\n";
	$data = box_data( 'one-box', 'answers' );
	check( 'the piece for the Add Shipment form: on Packing\'s box, with its name, bags and lines', 0 === strpos( $data['add'], '<span class="fhsm-box-packing" data-on="1"' ) && false !== strpos( $data['add'], 'data-name="Tall 12"' ) && false !== strpos( $data['add'], 'data-layers="12"' ) && false !== strpos( $data['add'], 'data-items="5001-7001 5001-7002"' ) && '</span>' === substr( $data['add'], -7 ), $data['add'] );
	check( 'it is the piece the page was drawn with', false !== strpos( render( 'one-box', 'answers' ), $data['add'] ) );
	check( 'no card is offered anything; the bag line\'s words go along', [] === $data['cards'] && 'Double-bagged, triple for spiny fish — from Packing' === $data['bag_note'] );
	$data = box_data( 'split', 'answers' );
	check( 'an order in several boxes: the next box for the form', false !== strpos( $data['add'], 'From Packing (confirmed) · Box 3 of 3' ) && false !== strpos( $data['add'], 'data-value="20x14x12"' ), $data['add'] );
	check( 'the box recorded without a size is offered its box, by the id of its form — the one recorded with a size nothing', [ 'fhsm-edit-82' ] === array_keys( $data['cards'] ) && false !== strpos( $data['cards']['fhsm-edit-82'], 'data-on="0"' ) && false !== strpos( $data['cards']['fhsm-edit-82'], 'Use Packing&#039;s box: Cube 12 (12x12x12)' ), json_encode( $data['cards'] ) );
	$data = box_data( 'folded', 'answers' );
	check( 'a source order packed with a combined box: no box, the bags still Packing\'s', '' === $data['add'] && [] === $data['cards'] && '' !== $data['bag_note'] );
	foreach ( [ 'absent', 'noplan', 'garbage', 'throws' ] as $misc ) {
		check( "Misc $misc: nothing at all", [ 'add' => '', 'cards' => [], 'bag_note' => '' ] === box_data( 'one-box', $misc ) );
	}

	echo "a box that is not a standard box\n";
	$html = render( 'custom', 'answers' );
	check( 'the form starts on "Custom…"', [ 'custom', '' ] === selected_box( $html, 'fhsm-new' ), json_encode( selected_box( $html, 'fhsm-new' ) ) );
	check( 'with Packing\'s size typed in, and shown', '12.5x9x6' === input_value( $html, 'fhsm-new-dims' ) && 0 === preg_match( '/id="fhsm-new-dims"[^>]*display:none/s', $html ) );
	check( 'and a word saying so', false !== strpos( $html, 'From Packing (suggested) — “Fish 12” is not on your standard box list, so its size is typed in</span>' ) );

	echo "a source order packed with a combined box\n";
	$html = render( 'folded', 'answers' );
	check( 'one line that links to the combined order', false !== strpos( $html, '<p class="description fhsm-packed-with">Packed with combined box <a href="https://shop.test/wp-admin/admin.php?page=wc-orders&#038;action=edit&#038;id=5900">#5900</a>.</p>' ), part( $html, 'fhsm-packed-with', '</p>' ) );
	check( 'nothing is filled in', [ '', '' ] === selected_box( $html, 'fhsm-new' ) && false === strpos( $html, 'fhsm-box-packing' ) );
	check( 'its bags are still Packing\'s', [ 7031 => '4' ] === line_fish( $html ) && isset( packing_data( $html )['bagNote'] ), json_encode( line_fish( $html ) ) );

	echo "a combined order\n";
	$html = render( 'combined', 'answers' );
	check( 'asked by its own id: the form starts on its box', [ '20x14x12', 'Long 20' ] === selected_box( $html, 'fhsm-new' ) && false !== strpos( $html, '>From Packing (confirmed)</span>' ) );
	check( 'the lines of both source orders carry their bags', [ 7031 => '4', 7041 => '3' ] === line_fish( $html ), json_encode( line_fish( $html ) ) );
	check( 'no "packed with" line on the combined order itself', false === strpos( $html, 'fhsm-packed-with' ) );

	echo "\n" . ( $failures ? "$failures FAILED\n" : "all passed\n" );
	exit( $failures ? 1 : 0 );
}

// ── A child: one scene, printed ─────────────────────────────────────────

define( 'ABSPATH', __DIR__ . '/' );
define( 'FISHOTEL_THEME_DIR', rtrim( $opts['theme'], '/' ) );

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
	public function get_formatted_billing_full_name() { return 'Robin Example'; }
}

// The shop: every fish line is [ name, quantity ]; LAYERS is what Packing
// plans per fish of a line (3 = a fish with spines).
$orders = [
	5001 => new WC_Order( 5001, [ 7001 => [ 'Yellow Tang', 2 ], 7002 => [ 'Ocellaris Clownfish', 3 ] ], [ 'shipping' => '45.00', '_fh_weather_action' => 'heat_pack' ] ),
	5010 => new WC_Order( 5010, [ 7011 => [ 'Yellow Tang', 2 ], 7012 => [ 'Ocellaris Clownfish', 3 ], 7013 => [ 'Royal Gramma', 4 ], 7014 => [ 'Foxface Rabbitfish', 1 ] ], [ 'shipping' => '90.00' ] ),
	5020 => new WC_Order( 5020, [ 7021 => [ 'Firefish Goby', 3 ] ], [ 'shipping' => '35.00' ] ),
	5002 => new WC_Order( 5002, [ 7031 => [ 'Banggai Cardinalfish', 2 ] ], [ 'shipping' => '25.00', '_fishotel_fulfilled_by_order' => 5900 ] ),
	5003 => new WC_Order( 5003, [ 7041 => [ 'Coral Beauty Angelfish', 1 ] ], [ 'shipping' => '25.00', '_fishotel_fulfilled_by_order' => 5900 ] ),
	5900 => new WC_Order( 5900, [], [ 'sources' => [ 5002, 5003 ] ] ),
	5030 => new WC_Order( 5030, [ 7051 => [ 'Firefish Goby', 2 ], 7052 => [ 'Acclimation Kit', 1 ] ], [ 'shipping' => '30.00' ] ),
];
const LAYERS = [ 7001 => 3, 7002 => 2, 7011 => 3, 7012 => 2, 7013 => 2, 7014 => 3, 7021 => 2, 7031 => 2, 7041 => 3, 7051 => 2, 7052 => 0 ];
const SCENES = [ 'one-box' => 5001, 'split' => 5010, 'custom' => 5020, 'folded' => 5002, 'combined' => 5900, 'kit-first' => 5030 ];

function wc_get_order( $id ) {
	global $orders;
	return isset( $orders[ (int) $id ] ) ? $orders[ (int) $id ] : false;
}
function fishotel_is_fulfillment( $order ) { return [] !== fishotel_fulfillment_get_sources( $order ); }
function fishotel_fulfillment_get_sources( $order ) {
	$sources = $order->get_meta( 'sources' );
	return is_array( $sources ) ? $sources : [];
}

// ShipTracker (v1.9.18): boxes, label sources, supplies, shipments.
class FST_Settings {
	public static function get_standard_boxes() {
		return [
			[ 'name' => 'Cube 12', 'length' => '12', 'width' => '12', 'height' => '12', 'dims' => '12x12x12', 'foam_cost' => '1.80', 'paper_cost' => '0.40' ],
			[ 'name' => 'Tall 12', 'length' => '12', 'width' => '12', 'height' => '12', 'dims' => '12x12x12', 'foam_cost' => '2.00', 'paper_cost' => '0.50' ],
			[ 'name' => 'Long 20', 'length' => '20', 'width' => '14', 'height' => '12', 'dims' => '20x14x12', 'foam_cost' => '3.10', 'paper_cost' => '0.60' ],
		];
	}
	public static function get_label_sources() { return [ 'Partner A', 'Partner B' ]; }
}
class FST_Shipment {
	/** @var array[] id => [ order id, tracking number, item ids, box size, label cost ] */
	const ROWS = [
		81 => [ 5010, '1ZAAA', [ 7011 ], '12x12x12', '53.73' ],
		82 => [ 5010, '1ZBBB', [ 7012 ], null, null ],
		83 => [ 5030, '1ZKIT', [ 7052 ], '12x12x12', null ],
	];
	public static function get_by_order( $order_id ) {
		$rows = [];
		foreach ( self::ROWS as $id => $r ) {
			if ( $r[0] === (int) $order_id ) {
				$rows[] = (object) [ 'id' => $id, 'order_id' => $r[0], 'tracking_number' => $r[1], 'line_item_ids' => $r[2], 'status' => 'in_transit', 'carrier' => 'ups' ];
			}
		}
		return $rows;
	}
	public static function get_order_boxes( $order_ids ) {
		$boxes = [];
		foreach ( self::ROWS as $id => $r ) {
			if ( in_array( $r[0], array_map( 'intval', (array) $order_ids ), true ) ) {
				$boxes[] = [
					'tracking_number' => $r[1], 'label_cost' => $r[4], 'box_weight' => null, 'box_dims' => $r[3], 'label_source' => null,
					'shared_box' => false, 'order_ids' => [ $r[0] ], 'shipment_ids' => [ $id ], 'materials' => null, 'materials_total' => null, 'total_cost' => null,
				];
			}
		}
		return $boxes;
	}
	public static function get_box_cost( $tracking_number ) { return null; }
}
class FST_Carrier {
	public static function get_status_color( $status ) { return '#2271b1'; }
	public static function get_status_label( $status ) { return 'In Transit'; }
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
		[ 'id' => 1, 'name' => 'Heat pack', 'unit' => 'each', 'counted' => true, 'role' => 'heat_pack', 'box_name' => '', 'price_each' => '0.6000', 'on_hand' => '7.00', 'recount_needed' => false, 'low' => true ],
		[ 'id' => 2, 'name' => 'Ice pack', 'unit' => 'each', 'counted' => true, 'role' => 'ice_pack', 'box_name' => '', 'price_each' => '0.7500', 'on_hand' => '24.00', 'recount_needed' => false, 'low' => false ],
		[ 'id' => 6, 'name' => 'Cube 12 box', 'unit' => 'each', 'counted' => true, 'role' => 'box', 'box_name' => 'Cube 12', 'price_each' => '1.2500', 'on_hand' => '18.00', 'recount_needed' => false, 'low' => false ],
		[ 'id' => 7, 'name' => 'Tall 12 box', 'unit' => 'each', 'counted' => true, 'role' => 'box', 'box_name' => 'Tall 12', 'price_each' => '1.4000', 'on_hand' => '9.00', 'recount_needed' => false, 'low' => false ],
		[ 'id' => 8, 'name' => 'Long 20 box', 'unit' => 'each', 'counted' => true, 'role' => 'box', 'box_name' => 'Long 20', 'price_each' => '2.2000', 'on_hand' => '10.00', 'recount_needed' => false, 'low' => false ],
		[ 'id' => 9, 'name' => 'Tape', 'unit' => 'rolls', 'counted' => false, 'role' => '', 'box_name' => '', 'price_each' => '3.0000', 'on_hand' => null ],
	];
	public static function get_supplies() { return self::SUPPLIES; }
	public static function order_pack_action( $order ) { return $order instanceof WC_Order ? (string) $order->get_meta( '_fh_weather_action' ) : ''; }
	public static function get_box_materials( $tracking_number ) { return null; }

	/**
	 * ShipTracker's count: the quantities added up — then its filter
	 * `fst_box_fish_count`, which the Misc plugin answers with the bags to
	 * pull (here: when Misc has a plan for the order).
	 */
	public static function fish_count( $order, $item_ids = null ) {
		$layered = function_exists( 'fishotel_packing_shipment_plan' ) && 'answers' === $GLOBALS['opts']['misc'];
		$count   = 0;
		foreach ( $order instanceof WC_Order ? $order->get_items() : [] as $item_id => $item ) {
			if ( null === $item_ids || in_array( (int) $item_id, array_map( 'intval', $item_ids ), true ) ) {
				$count += $item->get_quantity() * ( $layered ? LAYERS[ $item_id ] : 1 );
			}
		}
		return $count;
	}

	/** The usual packing of each standard box: the box, the bags, a heat pack when asked for. */
	public static function get_default_materials( $fish_count, $action ) {
		$usual = static function ( $box ) use ( $fish_count, $action ) {
			$lines = [];
			foreach ( self::SUPPLIES as $supply ) {
				$qty = 0;
				if ( 'box' === $supply['role'] && $box && $supply['box_name'] === $box['name'] ) {
					$qty = 1;
				} elseif ( 'fish_bag' === $supply['role'] ) {
					$qty = $fish_count;
				} elseif ( 'heat_pack' === $supply['role'] && 'heat_pack' === $action ) {
					$qty = 1;
				}
				if ( $qty > 0 ) {
					$lines[] = [ 'supply_id' => $supply['id'], 'name' => $supply['name'], 'role' => $supply['role'], 'unit' => $supply['unit'], 'qty' => number_format( $qty, 2, '.', '' ), 'unit_cost' => $supply['price_each'], 'cost' => number_format( $qty * (float) $supply['price_each'], 2, '.', '' ) ];
				}
			}
			return [ 'lines' => $lines, 'foam_cost' => $box ? $box['foam_cost'] : '0.00', 'paper_cost' => $box ? $box['paper_cost'] : '0.00' ];
		};
		$boxes = [];
		foreach ( FST_Settings::get_standard_boxes() as $box ) {
			$boxes[] = [ 'name' => $box['name'], 'dims' => $box['dims'], 'materials' => $usual( $box ) ];
		}
		return [ 'boxes' => $boxes, 'custom' => $usual( null ) ];
	}
}

// The FisHotel Misc plugin, Packing section — a stand-in for its
// fishotel_packing_shipment_plan() (Misc v0.46.15).
if ( 'absent' !== $opts['misc'] ) {
	function fishotel_packing_shipment_plan( $order_id ) {
		$mode  = $GLOBALS['opts']['misc'];
		$empty = [ 'available' => false, 'reason' => 'no_plan', 'order_id' => $order_id, 'owner_id' => $order_id, 'packed_with' => 0, 'from' => '', 'split' => false, 'boxes' => [], 'lines' => [], 'totals' => [ 'fish' => 0, 'bags' => 0, 'layers' => 0 ] ];
		if ( 'throws' === $mode ) {
			throw new RuntimeException( 'Packing fell over' );
		}
		if ( 'garbage' === $mode ) {
			return [ 'available' => true, 'boxes' => [ [ 'name' => 'Tall 12', 'dims' => 'tall' ] ] ];
		}
		if ( 'noplan' === $mode ) {
			return $empty;
		}
		$box = static function ( $index, $name, $dims, $standard, $layers, array $items = [] ) {
			return [ 'index' => $index, 'name' => $name, 'dims' => $dims, 'standard' => $standard ? [ 'name' => $name, 'dims' => $dims ] : null, 'match' => $standard ? 'name' : 'none', 'fish' => 0, 'bags' => 0, 'layers' => $layers, 'by_bag' => [], 'items' => $items ];
		};
		$plans = [
			5001 => [ 'from' => 'suggestion', 'boxes' => [ $box( 0, 'Tall 12', '12x12x12', true, 12, [ '5001-7001' => 2, '5001-7002' => 3 ] ) ] ],
			5010 => [ 'from' => 'confirmed', 'boxes' => [ $box( 0, 'Cube 12', '12x12x12', true, 6, [ '5010-7011' => 2 ] ), $box( 1, 'Cube 12', '12x12x12', true, 6, [ '5010-7012' => 3 ] ), $box( 2, 'Long 20', '20x14x12', true, 11, [ '5010-7013' => 4, '5010-7014' => 1 ] ) ] ],
			5020 => [ 'from' => 'suggestion', 'boxes' => [ $box( 0, 'Fish 12', '12.5x9x6', false, 6 ) ] ],
			5002 => [ 'from' => '', 'boxes' => [], 'packed_with' => 5900, 'owner_id' => 5900 ],
			5900 => [ 'from' => 'confirmed', 'boxes' => [ $box( 0, 'Long 20', '20x14x12', true, 7, [ '5002-7031' => 2, '5003-7041' => 1 ] ) ] ],
			5030 => [ 'from' => 'suggestion', 'boxes' => [ $box( 0, 'Tall 12', '12x12x12', true, 4, [ '5030-7051' => 2 ] ) ] ],
		];
		if ( ! isset( $plans[ $order_id ] ) ) {
			return $empty;
		}
		return array_merge( $empty, [ 'available' => true, 'reason' => '', 'split' => count( $plans[ $order_id ]['boxes'] ) > 1 ], $plans[ $order_id ] );
	}
}

if ( ! isset( SCENES[ $opts['scene'] ] ) ) {
	fwrite( STDERR, "unknown scene\n" );
	exit( 2 );
}

require_once FISHOTEL_THEME_DIR . '/inc/order-fulfillment/class-fishotel-shipment-cost.php';
if ( file_exists( FISHOTEL_THEME_DIR . '/inc/order-fulfillment/class-fishotel-packing-prefill.php' ) ) {
	require_once FISHOTEL_THEME_DIR . '/inc/order-fulfillment/class-fishotel-packing-prefill.php';
}
require_once FISHOTEL_THEME_DIR . '/inc/order-fulfillment/class-fishotel-shipments-metabox.php';

if ( '' !== $opts['data'] ) {
	echo json_encode( FisHotel_Shipments_Metabox::packing_box_data( $orders[ SCENES[ $opts['scene'] ] ] ) );
	exit( 0 );
}

FisHotel_Shipments_Metabox::render( $orders[ SCENES[ $opts['scene'] ] ] );
