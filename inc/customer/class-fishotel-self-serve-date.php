<?php
/**
 * FisHotel — Self-serve customer delivery date change.
 *
 * Lets a logged-in customer reschedule their own order's delivery date from
 * the My Account → view-order page, subject to:
 *
 *   - Status must be processing or on-hold.
 *   - Order must carry a _fishotel_shipping_date (med-only orders skip this UI).
 *   - More than 48 hours before currently selected delivery date.
 *   - Order is not in a fulfillment (delivery date lives on the fulfillment).
 *   - No shipments yet (real wp_fst_shipments rows OR Phase 1 shipped lines).
 *
 * Capacity cap: 5 active orders (processing/on-hold/fulfillment) per
 * delivery date. Customer's own current date is always shown in the picker
 * (so they can see what they have) but disabled (no-op pick).
 *
 * Shipping window: Mon/Tue/Wed only, looking 8 weeks ahead.
 *
 * Admin override: per-order _fishotel_admin_unlock_date_change = 'yes' bypasses
 * the 48hr cutoff (still enforces the other rules) — set via the meta box in
 * class-fishotel-date-unlock-metabox.php.
 *
 * Kill switch: define( 'FISHOTEL_SELF_SERVE_DATES_OFF', true ) in wp-config.php
 * disables button rendering AND short-circuits the AJAX handlers.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Self_Serve_Date {

	const META_DELIVERY    = '_fishotel_shipping_date';
	const META_FULFILLED   = '_fishotel_fulfilled_by_order';
	const META_LINE_STATUS = '_fishotel_line_status';
	const META_UNLOCK      = '_fishotel_admin_unlock_date_change';
	const NONCE_ACTION     = 'fishotel_change_date';
	const NONCE_FIELD      = 'fishotel_change_date_nonce';
	const CAPACITY_PER_DAY = 5;
	const LOOKAHEAD_DAYS   = 56;
	const CUTOFF_HOURS     = 48;

	public static function init() {
		add_action( 'wp_ajax_fishotel_get_available_dates', [ __CLASS__, 'ajax_get_available_dates' ] );
		add_action( 'wp_ajax_fishotel_change_delivery_date', [ __CLASS__, 'ajax_change_delivery_date' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	/** True when the kill switch is set. */
	public static function kill_switch_on() {
		return defined( 'FISHOTEL_SELF_SERVE_DATES_OFF' ) && FISHOTEL_SELF_SERVE_DATES_OFF;
	}

	/**
	 * Centralized eligibility check. ALL rules must hold; admin override
	 * skips only the 48hr cutoff, not the rest.
	 */
	public static function can_change( $order ) {
		if ( self::kill_switch_on() ) {
			return false;
		}
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		// Status gate.
		if ( ! $order->has_status( [ 'processing', 'on-hold' ] ) ) {
			return false;
		}

		// Must have a delivery date.
		$delivery = (string) $order->get_meta( self::META_DELIVERY );
		if ( '' === $delivery ) {
			return false;
		}

		// In a fulfillment → delivery is driven by the fulfillment.
		$fulfilled_by = (string) $order->get_meta( self::META_FULFILLED );
		if ( '' !== $fulfilled_by ) {
			return false;
		}

		// Real shipments already created → lock.
		if ( class_exists( 'FST_Shipment' ) && method_exists( 'FST_Shipment', 'get_by_order' ) ) {
			$rows = FST_Shipment::get_by_order( $order->get_id() );
			if ( ! empty( $rows ) ) {
				return false;
			}
		}

		// Phase 1 legacy shipped line items → lock.
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( 'shipped' === (string) $item->get_meta( self::META_LINE_STATUS ) ) {
				return false;
			}
		}

		// 48hr cutoff — admin override skips this.
		if ( 'yes' === (string) $order->get_meta( self::META_UNLOCK ) ) {
			return true;
		}
		$ts = strtotime( $delivery );
		if ( ! $ts ) {
			return false;
		}
		$cutoff = $ts - ( self::CUTOFF_HOURS * HOUR_IN_SECONDS );
		return time() < $cutoff;
	}

	/**
	 * Distinguish "locked because of the 48hr cutoff" from "locked because the
	 * order has moved on" so the UI can choose the right copy.
	 *
	 * @return string 'cutoff', 'shipped', 'fulfillment', 'status', 'none' or '' (eligible)
	 */
	public static function lock_reason( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 'none';
		}
		if ( self::can_change( $order ) ) {
			return '';
		}
		if ( '' === (string) $order->get_meta( self::META_DELIVERY ) ) {
			return 'none';
		}
		if ( ! $order->has_status( [ 'processing', 'on-hold' ] ) ) {
			return 'status';
		}
		if ( '' !== (string) $order->get_meta( self::META_FULFILLED ) ) {
			return 'fulfillment';
		}
		if ( class_exists( 'FST_Shipment' ) && method_exists( 'FST_Shipment', 'get_by_order' ) ) {
			$rows = FST_Shipment::get_by_order( $order->get_id() );
			if ( ! empty( $rows ) ) {
				return 'shipped';
			}
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( 'shipped' === (string) $item->get_meta( self::META_LINE_STATUS ) ) {
				return 'shipped';
			}
		}
		return 'cutoff';
	}

	/**
	 * Count active orders (processing / on-hold / fulfillment) sitting on the
	 * given delivery date. HPOS-safe via wc_get_orders().
	 */
	public static function count_orders_for_date( $date_str ) {
		$ids = wc_get_orders( [
			'status'     => [ 'processing', 'on-hold', 'fulfillment' ],
			'limit'      => -1,
			'return'     => 'ids',
			'meta_key'   => self::META_DELIVERY,
			'meta_value' => $date_str,
		] );
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/**
	 * Build the picker. Mon/Tue/Wed, > 48hr away, within LOOKAHEAD_DAYS,
	 * skip dates at capacity (except the customer's own current date which
	 * is always returned so the UI can show "(current)" — disabled).
	 *
	 * @param string $current_date Y-m-d the customer is currently booked on, or ''.
	 * @return array<int,array{date:string,label:string,count:int,is_current:bool,capacity_full:bool}>
	 */
	public static function get_available_dates( $current_date = '' ) {
		$out    = [];
		$today  = strtotime( 'today' );
		$cutoff = time() + ( self::CUTOFF_HOURS * HOUR_IN_SECONDS );

		for ( $i = 0; $i < self::LOOKAHEAD_DAYS; $i++ ) {
			$ts = strtotime( "+{$i} days", $today );
			if ( ! $ts ) {
				continue;
			}
			$dow = (int) date( 'w', $ts );
			if ( ! in_array( $dow, [ 1, 2, 3 ], true ) ) {
				continue;
			}
			// Must clear the 48hr cutoff (measured from "now", not "today
			// midnight") so a Tuesday morning request can't pick Wednesday
			// when there's <48hr between them.
			if ( strtotime( $current_date . ' 00:00:00' ) === $ts && $current_date !== '' ) {
				// Customer's own slot — always present, even past cutoff.
				$count = self::count_orders_for_date( date( 'Y-m-d', $ts ) );
				$out[] = [
					'date'          => date( 'Y-m-d', $ts ),
					'label'         => date_i18n( 'l, F j', $ts ),
					'count'         => $count,
					'is_current'    => true,
					'capacity_full' => $count >= self::CAPACITY_PER_DAY,
				];
				continue;
			}
			if ( $ts < $cutoff ) {
				continue;
			}
			$date_str = date( 'Y-m-d', $ts );
			$count    = self::count_orders_for_date( $date_str );
			if ( $count >= self::CAPACITY_PER_DAY ) {
				continue;
			}
			$out[] = [
				'date'          => $date_str,
				'label'         => date_i18n( 'l, F j', $ts ),
				'count'         => $count,
				'is_current'    => false,
				'capacity_full' => false,
			];
		}
		return $out;
	}

	/** Logged-in user owns this order (customer_id match — guests excluded). */
	private static function user_owns_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return false;
		}
		return (int) $order->get_customer_id() === (int) $uid;
	}

	public static function enqueue_assets() {
		if ( self::kill_switch_on() ) {
			return;
		}
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		$js_ver  = function_exists( 'fishotel_asset_version' ) ? fishotel_asset_version( 'assets/js/customer-date-change.js' ) : FISHOTEL_THEME_VERSION;
		$css_ver = function_exists( 'fishotel_asset_version' ) ? fishotel_asset_version( 'assets/css/customer-date-change.css' ) : FISHOTEL_THEME_VERSION;
		wp_enqueue_script(
			'fishotel-date-change',
			FISHOTEL_THEME_URI . '/assets/js/customer-date-change.js',
			[ 'jquery' ],
			$js_ver,
			true
		);
		wp_localize_script( 'fishotel-date-change', 'fishotelDateChange', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
		] );
		wp_enqueue_style(
			'fishotel-date-change',
			FISHOTEL_THEME_URI . '/assets/css/customer-date-change.css',
			[],
			$css_ver
		);
	}

	// ── AJAX ─────────────────────────────────────────────────────────

	public static function ajax_get_available_dates() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( self::kill_switch_on() ) {
			wp_send_json_error( __( 'Self-serve date changes are temporarily disabled. Please contact us.', 'fishotel' ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order || ! self::user_owns_order( $order ) ) {
			wp_send_json_error( __( 'Order not found.', 'fishotel' ) );
		}
		if ( ! self::can_change( $order ) ) {
			wp_send_json_error( __( 'This order can no longer be changed.', 'fishotel' ) );
		}
		$current   = (string) $order->get_meta( self::META_DELIVERY );
		$available = self::get_available_dates( $current );
		wp_send_json_success( [
			'current' => $current,
			'dates'   => $available,
		] );
	}

	public static function ajax_change_delivery_date() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( self::kill_switch_on() ) {
			wp_send_json_error( __( 'Self-serve date changes are temporarily disabled. Please contact us.', 'fishotel' ) );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$new_date = isset( $_POST['new_date'] ) ? sanitize_text_field( wp_unslash( $_POST['new_date'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order || ! self::user_owns_order( $order ) ) {
			wp_send_json_error( __( 'Order not found.', 'fishotel' ) );
		}
		if ( ! self::can_change( $order ) ) {
			wp_send_json_error( __( 'This order can no longer be changed.', 'fishotel' ) );
		}

		// Shape sanity: Y-m-d, real date, parses to a Mon/Tue/Wed.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_date ) ) {
			wp_send_json_error( __( 'Invalid date format.', 'fishotel' ) );
		}
		$new_ts = strtotime( $new_date );
		if ( ! $new_ts ) {
			wp_send_json_error( __( 'Invalid date.', 'fishotel' ) );
		}
		$dow = (int) date( 'w', $new_ts );
		if ( ! in_array( $dow, [ 1, 2, 3 ], true ) ) {
			wp_send_json_error( __( 'Delivery dates are Mon, Tue, or Wed only.', 'fishotel' ) );
		}

		$current = (string) $order->get_meta( self::META_DELIVERY );
		if ( $new_date === $current ) {
			wp_send_json_error( __( 'That is already your delivery date.', 'fishotel' ) );
		}

		// Re-check against fresh available list (admin-override-aware, capacity-aware).
		$available  = self::get_available_dates( $current );
		$valid_keys = array_filter( wp_list_pluck( $available, 'date' ), function ( $d ) use ( $current ) {
			return $d !== $current; // Current is in the list as "disabled".
		} );
		if ( ! in_array( $new_date, $valid_keys, true ) ) {
			wp_send_json_error( __( 'That date is no longer available. Please refresh and try again.', 'fishotel' ) );
		}

		// Commit.
		$order->update_meta_data( self::META_DELIVERY, $new_date );
		$order->add_order_note( sprintf(
			/* translators: 1: old date, 2: new date */
			__( 'Customer self-served delivery date change: %1$s → %2$s.', 'fishotel' ),
			'' !== $current ? $current : __( '(unset)', 'fishotel' ),
			$new_date
		), false );
		$order->save();

		self::notify_admin( $order, $current, $new_date );
		self::notify_customer( $order, $new_date );

		wp_send_json_success( [
			'new_date'           => $new_date,
			'new_date_formatted' => date_i18n( 'l, F j, Y', $new_ts ),
		] );
	}

	private static function notify_admin( WC_Order $order, $old, $new ) {
		$to      = get_option( 'admin_email' );
		$subject = sprintf(
			/* translators: %d order ID */
			__( '[FisHotel] Delivery date changed: Order #%d', 'fishotel' ),
			$order->get_id()
		);
		$body  = sprintf( "Customer self-served a delivery date change.\n\n" );
		$body .= sprintf( "Order: #%d\n", $order->get_id() );
		$body .= sprintf( "Customer: %s %s (%s)\n",
			$order->get_billing_first_name(),
			$order->get_billing_last_name(),
			$order->get_billing_email()
		);
		$body .= sprintf( "Old date: %s\n", '' !== $old ? $old : '(unset)' );
		$body .= sprintf( "New date: %s\n\n", $new );
		$body .= sprintf( "View order: %s\n", admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) );
		wp_mail( $to, $subject, $body );
	}

	private static function notify_customer( WC_Order $order, $new ) {
		$to = $order->get_billing_email();
		if ( empty( $to ) ) {
			return;
		}
		$subject = sprintf(
			/* translators: %d order ID */
			__( 'Delivery date updated for order #%d', 'fishotel' ),
			$order->get_id()
		);
		$body  = sprintf( "Hi %s,\n\n", $order->get_billing_first_name() );
		$body .= sprintf( "Your delivery date for order #%d has been updated to:\n\n", $order->get_id() );
		$body .= sprintf( "%s\n\n", date_i18n( 'l, F j, Y', strtotime( $new ) ) );
		$body .= "If you didn't make this change, please contact us right away.\n\n";
		$body .= "Thanks,\nThe FisHotel\n";
		wp_mail( $to, $subject, $body );
	}
}
