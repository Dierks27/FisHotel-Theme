<?php
/**
 * FisHotel — PDF Invoices & Packing Slips (WP Overnight) integration.
 *
 * Fixes two things Jeff noticed on the admin "Invoice" / "Packing Slip"
 * buttons, using only the plugin's public hooks (no template override):
 *
 * 1. Refunds were invisible on the invoice. This is NOT a cache or a stale
 *    snapshot: the free plugin never stores a rendered PDF — every click of
 *    the Invoice button re-renders the HTML from the live order
 *    (OrderDocument::get_pdf() → get_html()), and "Regenerate" only re-saves
 *    the invoice number/date and adds an order note. The plugin strips the
 *    refund rows ON PURPOSE: OrderDocumentMethods::get_woocommerce_totals()
 *    is gated by `apply_filters( 'wpo_wcpdf_remove_refund_totals', true, $doc )`;
 *    when that is true (the default) it overwrites the `order_total` row with
 *    the gross `wc_price( $order->get_total() )` and unsets every `refund_*`
 *    row WooCommerce had added, then fires `wpo_wcpdf_woocommerce_totals`.
 *    We leave that filter at its default so WooCommerce's own rows are
 *    already gone, and rebuild exactly the rows we want on the later filter
 *    (no duplicates): a "Total before refunds" row, one row per refund with
 *    date + reason, and a final `order_total` row with the NET amount so the
 *    template's bold `tr.order_total` styling lands on the figure the
 *    customer actually paid.
 *
 * 2. Customer notes were missing from some documents. get_shipping_notes()
 *    zeroes the note when the document setting `display_customer_notes` is
 *    off — and for invoices that setting may come from a settings snapshot
 *    stored in order meta (`_wcpdf_invoice_settings`) the first time the
 *    invoice was created, so re-enabling it today does not fix old orders.
 *    The zeroing happens BEFORE the `wpo_wcpdf_shipping_notes` filter fires,
 *    so re-supplying the note on that filter wins over both the live setting
 *    and any stale snapshot. For a FisHotel fulfillment (combined box) the
 *    wrapper order has no note of its own, so we append each source order's
 *    note labelled with its order number.
 *
 * Hooks used (all verified against plugin 5.16.4):
 *   - wpo_wcpdf_woocommerce_totals   ( array $totals, $order, string $type )
 *   - wpo_wcpdf_after_document_label ( string $type, $order )          [action]
 *   - wpo_wcpdf_order_item_data      ( array $data, $order, string $type )
 *   - wpo_wcpdf_shipping_notes       ( string $notes, $document )
 *   - wpo_wcpdf_customer_notes_title ( string $title, $document )
 *   - wpo_wcpdf_custom_styles        ( string $type, $document )       [action]
 *
 * Escaping note: the Simple template wraps totals labels/values and the
 * quantity cell in esc_html(), then get_html() runs htmlspecialchars_decode()
 * over the whole document, which is why wc_price()'s <span> markup renders
 * today. We rely on that only where the plugin already does (price cells);
 * the quantity suffix is plain text so it is correct either way.
 *
 * Every hook here is a plugin-specific filter/action, so this file is inert
 * when the plugin is not active; no plugin class or function is ever called.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_PDF_Invoice {

	const DOC_INVOICE      = 'invoice';
	const DOC_PACKING_SLIP = 'packing-slip';

	/**
	 * Per-request cache of the rendered source-order notes, keyed by
	 * fulfillment order id. The template calls get_shipping_notes() twice
	 * per render (once in the `if`, once to print), so this avoids loading
	 * every source order a second time.
	 */
	private static $source_notes = [];

	public static function init() {
		// 1. Refund rows + net total on the invoice.
		add_filter( 'wpo_wcpdf_woocommerce_totals', [ __CLASS__, 'refund_totals' ], 20, 3 );
		// 2. "PARTIALLY REFUNDED" / "REFUNDED" marker under the document title.
		add_action( 'wpo_wcpdf_after_document_label', [ __CLASS__, 'refund_marker' ], 10, 2 );
		// 3. Per-line "(N refunded)" + "-$X refunded" on invoice item rows.
		add_filter( 'wpo_wcpdf_order_item_data', [ __CLASS__, 'refunded_line' ], 10, 3 );
		// 4. Customer note always printed (+ source-order notes on a fulfillment).
		add_filter( 'wpo_wcpdf_shipping_notes', [ __CLASS__, 'customer_note' ], 10, 2 );
		// 5. Heading + boxed styling for the note, marker and refund rows.
		add_filter( 'wpo_wcpdf_customer_notes_title', [ __CLASS__, 'customer_note_title' ], 10, 2 );
		add_action( 'wpo_wcpdf_custom_styles', [ __CLASS__, 'custom_styles' ], 10, 2 );
	}

	// ── 1. Totals ────────────────────────────────────────────────────

	/**
	 * Rebuild the totals block for a refunded invoice.
	 *
	 * Runs at priority 20 so it sees the plugin's already-stripped list. The
	 * original `order_total` row is kept (it already carries the plugin's
	 * "(includes tax)" suffix) but relabelled and moved to `order_total_gross`
	 * so the bold `tr.order_total` style applies to the net row instead.
	 * Rows are re-emitted in place so WooCommerce's (CSS-hidden)
	 * `payment_method` row stays last.
	 *
	 * @param array  $totals        Rows keyed by CSS class: [ label, value ].
	 * @param object $order         WC_Order or WC_Order_Refund.
	 * @param string $document_type 'invoice', 'packing-slip', ...
	 * @return array
	 */
	public static function refund_totals( $totals, $order, $document_type ) {
		if ( self::DOC_INVOICE !== $document_type || ! is_array( $totals ) || ! self::is_parent_order( $order ) ) {
			return $totals;
		}
		// Same `> 0` gate as refund_marker(): a zero-amount refund (restock-only
		// / line re-add) must not produce three identical totals rows.
		$refunds  = $order->get_refunds();
		$refunded = (float) $order->get_total_refunded();
		if ( empty( $refunds ) || $refunded <= 0 ) {
			return $totals;
		}

		$net = self::net_after_refunds( $order, $refunded );

		$refund_rows = [];
		foreach ( $refunds as $refund ) {
			if ( ! $refund instanceof WC_Order_Refund || (float) $refund->get_amount() <= 0 ) {
				continue;
			}
			$label = __( 'Refund', 'fishotel' );
			$date  = self::format_date( $refund->get_date_created() );
			if ( '' !== $date ) {
				$label .= ' ' . $date;
			}
			$reason = trim( wp_strip_all_tags( (string) $refund->get_reason() ) );
			if ( '' !== $reason ) {
				$label .= ' – ' . $reason;
			}
			$refund_rows[ 'refund_' . (int) $refund->get_id() ] = [
				'label' => $label,
				'value' => self::price( -1 * (float) $refund->get_amount(), $order ),
			];
		}

		$net_row = [
			'label' => self::is_fully_refunded( $order, $refunded )
				? __( 'Total after refunds (REFUNDED)', 'fishotel' )
				: __( 'Total after refunds', 'fishotel' ),
			'value' => self::price( $net, $order ),
		];

		$out     = [];
		$emitted = false;
		foreach ( $totals as $key => $row ) {
			if ( 'order_total' === $key ) {
				$gross          = $row;
				$gross['label'] = __( 'Total before refunds', 'fishotel' );
				$out['order_total_gross'] = $gross;
				$out = array_merge( $out, $refund_rows );
				$out['order_total'] = $net_row;
				$emitted = true;
				continue;
			}
			$out[ $key ] = $row;
		}
		if ( ! $emitted ) {
			// No order_total row to anchor on (another filter removed it):
			// still show the refunds and the net figure at the bottom.
			$out = array_merge( $out, $refund_rows );
			$out['order_total'] = $net_row;
		}
		return $out;
	}

	// ── 2. Marker ────────────────────────────────────────────────────

	/**
	 * Print a REFUNDED / PARTIALLY REFUNDED block under the document title
	 * on invoices and packing slips. Deliberately NOT a title filter:
	 * get_title() also labels the admin sidebar button and the
	 * "regenerated" order note, and this action only fires inside the
	 * rendered template.
	 *
	 * @param string $document_type
	 * @param object $order
	 */
	public static function refund_marker( $document_type, $order ) {
		if ( ! self::is_supported_type( $document_type ) || ! self::is_parent_order( $order ) ) {
			return;
		}
		$refunded = (float) $order->get_total_refunded();
		if ( $refunded <= 0 ) {
			return;
		}
		$status = self::is_fully_refunded( $order, $refunded )
			? __( 'REFUNDED', 'fishotel' )
			: __( 'PARTIALLY REFUNDED', 'fishotel' );
		$date   = self::latest_refund_date( $order );
		if ( '' !== $date ) {
			/* translators: 1: formatted amount, 2: date of the latest refund */
			$detail = sprintf( __( '%1$s refunded on %2$s', 'fishotel' ), self::price( $refunded, $order ), esc_html( $date ) );
		} else {
			/* translators: %s: formatted amount */
			$detail = sprintf( __( '%s refunded', 'fishotel' ), self::price( $refunded, $order ) );
		}
		echo '<div class="fishotel-refund-marker"><strong>' . esc_html( $status ) . '</strong> — ' . wp_kses_post( $detail ) . '</div>';
	}

	// ── 3. Item rows ─────────────────────────────────────────────────

	/**
	 * Mark refunded quantities/amounts on invoice line items. The packing
	 * slip is left untouched (it has no price column and lists what ships).
	 *
	 * WooCommerce stores refund item quantities as negative numbers, hence
	 * abs(); get_total_refunded_for_item() already returns a positive,
	 * ex-tax line amount. Both compare `_refunded_item_id` strictly against
	 * an int, so the item id is cast.
	 *
	 * @param array  $data          Template data for one line (keys: quantity, order_price, ...).
	 * @param object $order
	 * @param string $document_type
	 * @return array
	 */
	public static function refunded_line( $data, $order, $document_type ) {
		if ( self::DOC_INVOICE !== $document_type || ! is_array( $data ) || empty( $data['item_id'] ) || ! self::is_parent_order( $order ) ) {
			return $data;
		}
		$item_id = (int) $data['item_id'];

		$qty_refunded = abs( (int) $order->get_qty_refunded_for_item( $item_id ) );
		if ( $qty_refunded > 0 && isset( $data['quantity'] ) ) {
			// Plain text: the template esc_html()s this cell.
			/* translators: %d: number of units refunded */
			$data['quantity'] = (string) $data['quantity'] . ' ' . sprintf( __( '(%d refunded)', 'fishotel' ), $qty_refunded );
		}

		$amount_refunded = (float) $order->get_total_refunded_for_item( $item_id );
		if ( $amount_refunded > 0 && isset( $data['order_price'] ) ) {
			$data['order_price'] .= '<br><span class="fishotel-refunded">'
				. wp_kses_post( self::price( -1 * $amount_refunded, $order ) )
				. ' ' . esc_html__( 'refunded', 'fishotel' ) . '</span>';
		}
		return $data;
	}

	// ── 4. Customer note ─────────────────────────────────────────────

	/**
	 * Always print the order's customer note on invoices and packing slips,
	 * regardless of the `display_customer_notes` setting or a stale
	 * historical-settings snapshot (see file header). The plugin's own
	 * output wins when it is already non-empty. On a fulfillment order the
	 * source orders' notes are appended, one paragraph each.
	 *
	 * Output is sanitised by the plugin with wp_kses (br/em/strong/p) in
	 * shipping_notes(); we emit only those tags.
	 *
	 * @param string $notes    HTML the plugin computed (possibly '').
	 * @param object $document WPO\IPS\Documents\OrderDocument.
	 * @return string
	 */
	public static function customer_note( $notes, $document ) {
		if ( ! self::is_supported_type( self::document_type( $document ) ) ) {
			return $notes;
		}
		$order = self::document_order( $document );
		if ( ! self::is_parent_order( $order ) ) {
			return $notes;
		}

		$notes = is_string( $notes ) ? $notes : '';
		if ( '' === trim( $notes ) ) {
			$note  = trim( (string) $order->get_customer_note() );
			$notes = '' === $note ? '' : wpautop( wptexturize( $note ) );
		}

		return $notes . self::source_order_notes( $order );
	}

	/**
	 * '<p><strong>Order #N:</strong> note</p>' for every source order of a
	 * fulfillment that carries a customer note; '' for a regular order.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	private static function source_order_notes( $order ) {
		if ( ! class_exists( 'FisHotel_Fulfillment' ) || ! function_exists( 'wc_get_order' ) ) {
			return '';
		}
		if ( ! FisHotel_Fulfillment::is_fulfillment( $order ) ) {
			return '';
		}
		$ff_id = (int) $order->get_id();
		if ( isset( self::$source_notes[ $ff_id ] ) ) {
			return self::$source_notes[ $ff_id ];
		}

		$allowed = [ 'br' => [], 'em' => [], 'strong' => [], 'p' => [] ];
		$out     = '';
		foreach ( FisHotel_Fulfillment::get_sources( $order ) as $source_id ) {
			$source = wc_get_order( (int) $source_id );
			if ( ! $source instanceof WC_Order ) {
				continue;
			}
			$note = trim( (string) $source->get_customer_note() );
			if ( '' === $note ) {
				continue;
			}
			/* translators: %s: order number */
			$heading = sprintf( __( 'Order #%s:', 'fishotel' ), $source->get_order_number() );
			$out    .= '<p><strong>' . esc_html( $heading ) . '</strong> '
				. nl2br( wp_kses( wptexturize( $note ), $allowed ) ) . '</p>';
		}

		self::$source_notes[ $ff_id ] = $out;
		return $out;
	}

	// ── 5. Heading + styles ──────────────────────────────────────────

	/**
	 * "Customer Notes:" → "Customer note" on both documents.
	 *
	 * @param string $title
	 * @param object $document
	 * @return string
	 */
	public static function customer_note_title( $title, $document ) {
		if ( ! self::is_supported_type( self::document_type( $document ) ) ) {
			return $title;
		}
		return __( 'Customer note', 'fishotel' );
	}

	/**
	 * Extra CSS inside the document's <style> block. dompdf-safe only (no
	 * flex/grid). Kept free of quotes and angle brackets on purpose: the
	 * plugin runs this buffer through esc_html() and relies on its later
	 * htmlspecialchars_decode() pass to restore it; plain selectors and
	 * hex colours survive either way.
	 *
	 * @param string $document_type
	 * @param object $document
	 */
	public static function custom_styles( $document_type, $document ) {
		if ( ! self::is_supported_type( $document_type ) ) {
			return;
		}
		echo "\n"
			. '.fishotel-refund-marker { margin: 2mm 0 4mm; padding: 2mm 3mm; border: 2px solid #b00000; color: #b00000; font-size: 10pt; }' . "\n"
			. '.fishotel-refund-marker strong { text-transform: uppercase; letter-spacing: 1px; }' . "\n"
			. '.fishotel-refunded { color: #b00000; font-size: 85%; white-space: nowrap; }' . "\n"
			. 'table.totals tr.order_total_gross th, table.totals tr.order_total_gross td { font-weight: normal; }' . "\n"
			. '.customer-notes { border: 1px solid #333333; padding: 3mm 4mm; margin-top: 5mm; }' . "\n"
			. '.customer-notes h3 { margin: 0 0 2mm; font-weight: bold; }' . "\n";
	}

	// ── Helpers ──────────────────────────────────────────────────────

	/** True for the two documents we touch. */
	private static function is_supported_type( $type ) {
		return in_array( (string) $type, [ self::DOC_INVOICE, self::DOC_PACKING_SLIP ], true );
	}

	/**
	 * A real parent order — never a WC_Order_Refund (the Pro "Credit Note"
	 * document renders those; the plugin prints the refund reason there).
	 */
	private static function is_parent_order( $order ) {
		return $order instanceof WC_Order && ! $order instanceof WC_Order_Refund;
	}

	/** Document type string, or '' when the object is not a document. */
	private static function document_type( $document ) {
		return ( is_object( $document ) && method_exists( $document, 'get_type' ) ) ? (string) $document->get_type() : '';
	}

	/** The document's public $order property, or null. */
	private static function document_order( $document ) {
		return ( is_object( $document ) && isset( $document->order ) ) ? $document->order : null;
	}

	/** Nothing left to pay, or the order is in the Refunded status. */
	private static function is_fully_refunded( $order, $refunded ) {
		return self::net_after_refunds( $order, $refunded ) <= 0 || 'refunded' === $order->get_status();
	}

	/**
	 * Order total minus refunds, rounded to the store's price decimals.
	 * get_total_refunded() is a SUM of stored decimals, so three partial
	 * refunds (3.33 + 3.33 + 3.34 on a $10 order) can leave a 1e-15 float
	 * residue that reads as "not fully refunded" — and as "-$0.00" on the net
	 * row — unless compared at currency precision.
	 */
	private static function net_after_refunds( $order, $refunded ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		return round( (float) $order->get_total() - (float) $refunded, $decimals );
	}

	/** wc_price() in the order's currency (HTML). */
	private static function price( $amount, $order ) {
		if ( ! function_exists( 'wc_price' ) ) {
			return (string) $amount;
		}
		return wc_price( $amount, [ 'currency' => $order->get_currency() ] );
	}

	/** WC_DateTime → site date format, '' when unavailable. */
	private static function format_date( $datetime ) {
		if ( ! is_object( $datetime ) || ! method_exists( $datetime, 'date_i18n' ) ) {
			return '';
		}
		$format = function_exists( 'wc_date_format' ) ? wc_date_format() : (string) get_option( 'date_format', 'F j, Y' );
		return (string) $datetime->date_i18n( $format );
	}

	/** Formatted date of the most recent refund on the order, '' when none. */
	private static function latest_refund_date( $order ) {
		$latest = null;
		foreach ( $order->get_refunds() as $refund ) {
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}
			$created = $refund->get_date_created();
			if ( ! is_object( $created ) || ! method_exists( $created, 'getTimestamp' ) ) {
				continue;
			}
			if ( null === $latest || $created->getTimestamp() > $latest->getTimestamp() ) {
				$latest = $created;
			}
		}
		return self::format_date( $latest );
	}
}

FisHotel_PDF_Invoice::init();
