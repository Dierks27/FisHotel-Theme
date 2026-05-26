<?php
/**
 * FisHotel EA Status — per-line "Sent to EA" tracking.
 *
 * Records whether each EA-fulfilled line item has been forwarded to and
 * confirmed by EverythingAquatic. Status is per order line item, stored in
 * item meta so it travels with the line whether the order is standalone or
 * has been pulled into a fulfillment.
 *
 * Storage:
 *   _fishotel_ea_status     not_sent | sent | confirmed
 *   _fishotel_ea_status_at  MySQL datetime of last status change
 *
 * Surface:
 *   - Dropdown + timestamp inside the Shipments meta box (rendered by the
 *     EA Status panel of that meta box's template).
 *   - "EA" column on the orders list — single-character badge whose color
 *     summarizes the order's overall EA status.
 *   - Bulk actions wire via the meta box JS to the AJAX endpoint here.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_EA_Status {

	const META_STATUS    = '_fishotel_ea_status';
	const META_STATUS_AT = '_fishotel_ea_status_at';
	const VALID_STATUSES = [ 'not_sent', 'sent', 'confirmed' ];

	public static function init() {
		add_action( 'wp_ajax_fishotel_ea_status_update', [ __CLASS__, 'ajax_update' ] );

		// Orders list "EA" column — legacy CPT + HPOS list tables.
		add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'add_column' ], 25 );
		add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_column_cpt' ], 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ __CLASS__, 'add_column' ], 25 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', [ __CLASS__, 'render_column_hpos' ], 10, 2 );
	}

	/** AJAX: update one item's EA status. */
	public static function ajax_update() {
		check_ajax_referer( 'fst_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( __( 'Permission denied', 'fishotel' ), 403 );
		}
		$item_id = isset( $_POST['item_id'] ) ? absint( wp_unslash( $_POST['item_id'] ) ) : 0;
		$status  = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! $item_id || ! in_array( $status, self::VALID_STATUSES, true ) ) {
			wp_send_json_error( __( 'Invalid input', 'fishotel' ), 400 );
		}
		wc_update_order_item_meta( $item_id, self::META_STATUS, $status );
		wc_update_order_item_meta( $item_id, self::META_STATUS_AT, current_time( 'mysql' ) );
		wp_send_json_success( [
			'item_id' => $item_id,
			'status'  => $status,
			'at'      => current_time( 'mysql' ),
		] );
	}

	/**
	 * Compute the overall EA badge color for an order:
	 *   '' (no EA items), 'green' (all confirmed), 'yellow' (some sent), 'red' (any not_sent).
	 */
	public static function compute_order_badge( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( (int) $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return '';
		}
		// For a fulfillment, aggregate over its source orders. For a source
		// folded into a fulfillment, defer to the fulfillment (don't show its
		// own column badge — it'd duplicate the fulfillment's).
		if ( function_exists( 'fishotel_is_fulfillment' ) && fishotel_is_fulfillment( $order ) ) {
			$sources = function_exists( 'fishotel_fulfillment_get_sources' )
				? fishotel_fulfillment_get_sources( $order )
				: [];
			$orders = [];
			foreach ( $sources as $sid ) {
				$o = wc_get_order( (int) $sid );
				if ( $o instanceof WC_Order ) {
					$orders[] = $o;
				}
			}
		} else {
			$orders = [ $order ];
		}

		$statuses = [];
		foreach ( $orders as $o ) {
			foreach ( $o->get_items( 'line_item' ) as $item ) {
				if ( ! self::is_ea_item( $item ) ) {
					continue;
				}
				$s = (string) $item->get_meta( self::META_STATUS );
				if ( ! in_array( $s, self::VALID_STATUSES, true ) ) {
					$s = 'not_sent';
				}
				$statuses[] = $s;
			}
		}

		if ( empty( $statuses ) ) {
			return '';
		}
		if ( in_array( 'not_sent', $statuses, true ) ) {
			return 'red';
		}
		if ( in_array( 'sent', $statuses, true ) ) {
			return 'yellow';
		}
		return 'green';
	}

	/** True when the line item is EA-fulfilled (via the medication-store helper). */
	public static function is_ea_item( $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return false;
		}
		if ( ! function_exists( 'fishotel_is_ea_fulfilled_product' ) ) {
			return false;
		}
		$product_id   = (int) $item->get_product_id();
		$variation_id = (int) $item->get_variation_id();
		if ( ! $product_id ) {
			return false;
		}
		return fishotel_is_ea_fulfilled_product( $product_id, $variation_id );
	}

	// ── Orders list "EA" column ──────────────────────────────────────

	public static function add_column( $columns ) {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new['fishotel_ea'] = __( 'EA', 'fishotel' );
			}
		}
		if ( ! isset( $new['fishotel_ea'] ) ) {
			$new['fishotel_ea'] = __( 'EA', 'fishotel' );
		}
		return $new;
	}

	public static function render_column_cpt( $column, $post_id ) {
		if ( 'fishotel_ea' !== $column ) {
			return;
		}
		self::echo_badge( wc_get_order( (int) $post_id ) );
	}

	public static function render_column_hpos( $column, $order ) {
		if ( 'fishotel_ea' !== $column ) {
			return;
		}
		self::echo_badge( $order instanceof WC_Order ? $order : wc_get_order( (int) $order ) );
	}

	private static function echo_badge( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// Don't show on a source folded into a fulfillment — its fulfillment
		// row carries the aggregated badge.
		if ( function_exists( 'fishotel_order_get_fulfillment' ) && fishotel_order_get_fulfillment( $order ) ) {
			return;
		}
		$color = self::compute_order_badge( $order );
		if ( '' === $color ) {
			return;
		}
		$bg = [
			'red'    => '#c1483a',
			'yellow' => '#d4903a',
			'green'  => '#5aaa78',
		];
		$tip = [
			'red'    => __( 'One or more EA items have not been sent.', 'fishotel' ),
			'yellow' => __( 'EA items sent, awaiting confirmation.', 'fishotel' ),
			'green'  => __( 'All EA items confirmed by EverythingAquatic.', 'fishotel' ),
		];
		printf(
			'<span title="%s" style="display:inline-block;width:14px;height:14px;border-radius:50%%;background:%s;vertical-align:middle;"></span>',
			esc_attr( $tip[ $color ] ),
			esc_attr( $bg[ $color ] )
		);
	}
}
