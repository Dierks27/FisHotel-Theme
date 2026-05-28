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

/**
 * Effective delivery date for an order, falling back to the order's
 * fulfillment when the source order's own _fishotel_shipping_date has been
 * cleared (the normal state for a source folded into a fulfillment).
 *
 * Customer-facing displays should always go through this helper so a customer
 * looking at their source order in My Account still sees the fulfillment's
 * scheduled delivery date — the fulfillment is invisible to them.
 *
 * @param WC_Order|int $order
 * @return string '' if no date is set on the order OR any fulfillment.
 */
function fishotel_get_effective_delivery_date( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( (int) $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return '';
	}
	$own = (string) $order->get_meta( '_fishotel_shipping_date' );
	if ( '' !== $own ) {
		return $own;
	}
	$ff_id = (int) $order->get_meta( '_fishotel_fulfilled_by_order' );
	if ( $ff_id > 0 ) {
		$ff = wc_get_order( $ff_id );
		if ( $ff instanceof WC_Order ) {
			$ff_date = (string) $ff->get_meta( '_fishotel_shipping_date' );
			if ( '' !== $ff_date ) {
				return $ff_date;
			}
		}
	}
	return '';
}

/**
 * All orders that share a delivery date through fulfillment grouping.
 *
 * - Standalone order → [self]
 * - Source order folded into a fulfillment → [fulfillment, source #1, source #2, ...]
 * - Fulfillment entity → [fulfillment, source #1, source #2, ...]
 *
 * Used so eligibility checks (shipments, Phase 1 shipped lines) cover every
 * order in the same physical delivery, and the save flow can write to the
 * fulfillment + propagate notes to every sibling source.
 *
 * @param WC_Order|int $order
 * @return WC_Order[]
 */
function fishotel_get_fulfillment_group_orders( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( (int) $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return [];
	}

	// The fulfillment entity itself: self + all sources.
	if ( function_exists( 'fishotel_is_fulfillment' ) && fishotel_is_fulfillment( $order ) ) {
		$out     = [ $order ];
		$sources = function_exists( 'fishotel_fulfillment_get_sources' )
			? fishotel_fulfillment_get_sources( $order )
			: [];
		foreach ( $sources as $sid ) {
			$src = wc_get_order( (int) $sid );
			if ( $src instanceof WC_Order ) {
				$out[] = $src;
			}
		}
		return $out;
	}

	// Source order: resolve up to the fulfillment, then expand again.
	$ff_id = (int) $order->get_meta( '_fishotel_fulfilled_by_order' );
	if ( $ff_id > 0 ) {
		$ff = wc_get_order( $ff_id );
		if ( $ff instanceof WC_Order ) {
			return fishotel_get_fulfillment_group_orders( $ff );
		}
	}

	// Standalone.
	return [ $order ];
}

class FisHotel_Self_Serve_Date {

	const META_DELIVERY    = '_fishotel_shipping_date';
	const META_FULFILLED   = '_fishotel_fulfilled_by_order';
	const META_LINE_STATUS = '_fishotel_line_status';
	const META_UNLOCK      = '_fishotel_admin_unlock_date_change';
	const NONCE_ACTION     = 'fishotel_change_date';
	const NONCE_FIELD      = 'fishotel_change_date_nonce';
	// 48hr cutoff for whether changes are allowed at all (separate from
	// Batch Plugin's shipping_min_advance, which gates the new date itself).
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

		// Status gate (on the order being viewed — its status, not the fulfillment's).
		if ( ! $order->has_status( [ 'processing', 'on-hold' ] ) ) {
			return false;
		}

		// Effective delivery date — own meta OR fulfillment fallback.
		$delivery = fishotel_get_effective_delivery_date( $order );
		if ( '' === $delivery ) {
			return false;
		}

		// All orders that physically ship together — for a source order, that's
		// the fulfillment + every sibling source. Eligibility must hold across
		// the whole group (one shipped sibling locks the date for everyone).
		$group = fishotel_get_fulfillment_group_orders( $order );

		// Real shipments anywhere in the group → lock.
		if ( class_exists( 'FST_Shipment' ) && method_exists( 'FST_Shipment', 'get_by_order' ) ) {
			foreach ( $group as $member ) {
				$rows = FST_Shipment::get_by_order( $member->get_id() );
				if ( ! empty( $rows ) ) {
					return false;
				}
			}
		}

		// Phase 1 legacy shipped line items anywhere in the group → lock.
		foreach ( $group as $member ) {
			foreach ( $member->get_items( 'line_item' ) as $item ) {
				if ( 'shipped' === (string) $item->get_meta( self::META_LINE_STATUS ) ) {
					return false;
				}
			}
		}

		// 48hr cutoff — admin override on the viewed order skips this.
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
	 * Mirrors can_change() — shipments/Phase 1 are evaluated across the whole
	 * fulfillment group, not just the viewed order.
	 *
	 * @return string 'cutoff', 'shipped', 'status', 'none' or '' (eligible)
	 */
	public static function lock_reason( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return 'none';
		}
		if ( self::can_change( $order ) ) {
			return '';
		}
		if ( '' === fishotel_get_effective_delivery_date( $order ) ) {
			return 'none';
		}
		if ( ! $order->has_status( [ 'processing', 'on-hold' ] ) ) {
			return 'status';
		}
		$group = fishotel_get_fulfillment_group_orders( $order );
		if ( class_exists( 'FST_Shipment' ) && method_exists( 'FST_Shipment', 'get_by_order' ) ) {
			foreach ( $group as $member ) {
				$rows = FST_Shipment::get_by_order( $member->get_id() );
				if ( ! empty( $rows ) ) {
					return 'shipped';
				}
			}
		}
		foreach ( $group as $member ) {
			foreach ( $member->get_items( 'line_item' ) as $item ) {
				if ( 'shipped' === (string) $item->get_meta( self::META_LINE_STATUS ) ) {
					return 'shipped';
				}
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
	 * Build the picker. Authoritative on the customer-facing rules:
	 *
	 *   - Allowed weekdays, min_advance, max_days_ahead, blocked dates,
	 *     and per-day capacity all come from Batch Plugin's shipping
	 *     options via fishotel_get_allowed_shipping_settings().
	 *   - The customer's CURRENT date is always returned (even if it
	 *     now violates a Batch Plugin rule — e.g. admin disabled
	 *     Mondays after the customer was booked) so the UI can show
	 *     "(current)" disabled and the customer can see what they have.
	 *   - The class-level CUTOFF_HOURS=48 is layered on top of
	 *     min_advance: a customer can't change a date that's already
	 *     within 48 hours, and they can't pick a new date that's <48 hr
	 *     away either. min_advance is enforced via Batch Plugin's
	 *     settings (typically 1+ days).
	 *
	 * @param string $current_date Y-m-d the customer is currently booked on, or ''.
	 * @return array<int,array{date:string,label:string,count:int,is_current:bool,capacity_full:bool}>
	 */
	public static function get_available_dates( $current_date = '' ) {
		$settings = function_exists( 'fishotel_get_allowed_shipping_settings' )
			? fishotel_get_allowed_shipping_settings()
			: [
				'days'           => [ 'Tuesday', 'Wednesday', 'Thursday' ],
				'min_advance'    => 1,
				'max_days_ahead' => 21,
				'max_per_day'    => 0,
				'blocked_dates'  => [],
			];

		$out         = [];
		$today       = strtotime( 'today' );
		$hour_cutoff = time() + ( self::CUTOFF_HOURS * HOUR_IN_SECONDS );
		$min_advance = (int) $settings['min_advance'];
		$max_ahead   = (int) $settings['max_days_ahead'];
		$allowed     = (array) $settings['days'];
		$blocked     = (array) $settings['blocked_dates'];
		$max_per_day = (int) $settings['max_per_day'];

		for ( $i = 0; $i <= $max_ahead; $i++ ) {
			$ts = strtotime( "+{$i} days", $today );
			if ( ! $ts ) {
				continue;
			}
			$date_str = date( 'Y-m-d', $ts );
			$weekday  = date( 'l', $ts );
			$is_current_slot = ( '' !== $current_date && $current_date === $date_str );

			if ( $is_current_slot ) {
				// Customer's own slot — always present, regardless of rule
				// changes, so the picker can render "(current)" disabled.
				$count = self::count_orders_for_date( $date_str );
				$out[] = [
					'date'          => $date_str,
					'label'         => date_i18n( 'l, F j', $ts ),
					'count'         => $count,
					'is_current'    => true,
					'capacity_full' => $max_per_day > 0 && $count >= $max_per_day,
				];
				continue;
			}

			// Batch Plugin rule gates.
			if ( $i < $min_advance ) {
				continue;
			}
			if ( ! in_array( $weekday, $allowed, true ) ) {
				continue;
			}
			if ( in_array( $date_str, $blocked, true ) ) {
				continue;
			}
			// Theme-level: must clear the 48hr cutoff (measured from "now",
			// not midnight) — protects against a Tue morning request picking
			// Wed when there's <48hr between them.
			if ( $ts < $hour_cutoff ) {
				continue;
			}
			// Capacity (0 = unlimited).
			$count = self::count_orders_for_date( $date_str );
			if ( $max_per_day > 0 && $count >= $max_per_day ) {
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
		$current   = fishotel_get_effective_delivery_date( $order );
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

		// Authoritative server-side validation against Batch Plugin's
		// shipping settings (weekdays, min_advance, max_days_ahead, blocked
		// dates). Capacity is enforced separately via the available-list
		// re-check below so the "is_current" exemption can still apply.
		if ( function_exists( 'fishotel_is_valid_delivery_date' ) ) {
			list( $valid, $reason ) = fishotel_is_valid_delivery_date( $new_date );
			if ( ! $valid ) {
				wp_send_json_error( $reason );
			}
		} else {
			// Defensive fallback if the helper file failed to load: at least
			// gate on shape so we don't write garbage.
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_date ) ) {
				wp_send_json_error( __( 'Invalid date format.', 'fishotel' ) );
			}
		}
		$new_ts = strtotime( $new_date );
		if ( ! $new_ts ) {
			wp_send_json_error( __( 'Invalid date.', 'fishotel' ) );
		}

		// Effective current (own meta OR fulfillment's date).
		$current = fishotel_get_effective_delivery_date( $order );
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

		// Resolve the AUTHORITATIVE order to write to. For a source order in a
		// fulfillment, that's the fulfillment entity — the date lives there,
		// not on sources. For everything else, it's the order itself.
		$ff_id         = (int) $order->get_meta( self::META_FULFILLED );
		$authoritative = $order;
		$is_in_group   = false;
		if ( $ff_id > 0 ) {
			$ff = wc_get_order( $ff_id );
			if ( $ff instanceof WC_Order ) {
				$authoritative = $ff;
				$is_in_group   = true;
			}
		} elseif ( function_exists( 'fishotel_is_fulfillment' ) && fishotel_is_fulfillment( $order ) ) {
			// Customer shouldn't reach a fulfillment view, but if they do, treat
			// it as the authoritative order.
			$is_in_group = true;
		}

		// Commit the date to the authoritative order.
		$authoritative->update_meta_data( self::META_DELIVERY, $new_date );
		$authoritative->add_order_note( sprintf(
			/* translators: 1: old date, 2: new date */
			__( 'Customer self-served delivery date change: %1$s → %2$s.', 'fishotel' ),
			'' !== $current ? $current : __( '(unset)', 'fishotel' ),
			$new_date
		), false );
		$authoritative->save();

		// Propagate a note to each sibling source so audit trail is complete
		// per-order (the source orders still don't carry the date itself).
		$group = $is_in_group ? fishotel_get_fulfillment_group_orders( $authoritative ) : [ $authoritative ];
		if ( $is_in_group ) {
			foreach ( $group as $sibling ) {
				if ( $sibling->get_id() === $authoritative->get_id() ) {
					continue;
				}
				$sibling->add_order_note( sprintf(
					/* translators: 1: fulfillment id, 2: old date, 3: new date */
					__( 'Delivery date changed via combined fulfillment FF-%1$d: %2$s → %3$s.', 'fishotel' ),
					$authoritative->get_id(),
					'' !== $current ? $current : __( '(unset)', 'fishotel' ),
					$new_date
				), false );
				$sibling->save();
			}
		}

		self::notify_admin( $order, $authoritative, $is_in_group, $group, $current, $new_date );
		self::notify_customer( $order, $new_date );

		wp_send_json_success( [
			'new_date'           => $new_date,
			'new_date_formatted' => date_i18n( 'l, F j, Y', $new_ts ),
		] );
	}

	/**
	 * Admin email — single-order body for standalone, combined-orders body when
	 * the change writes to a fulfillment. $viewed_order is what the customer was
	 * looking at (for billing fields); $authoritative is where the date lives;
	 * $group is every order in the same physical delivery.
	 *
	 * @param WC_Order   $viewed_order
	 * @param WC_Order   $authoritative
	 * @param bool       $is_in_group
	 * @param WC_Order[] $group
	 * @param string     $old
	 * @param string     $new
	 */
	private static function notify_admin( WC_Order $viewed_order, WC_Order $authoritative, $is_in_group, array $group, $old, $new ) {
		$to = get_option( 'admin_email' );
		if ( $is_in_group ) {
			$sources = array_filter( $group, function ( $o ) use ( $authoritative ) {
				return $o->get_id() !== $authoritative->get_id();
			} );
			$source_labels = array_map( function ( $o ) {
				return '#' . $o->get_id();
			}, $sources );
			$subject = sprintf(
				/* translators: %d fulfillment ID */
				__( '[FisHotel] Delivery date changed (combined): FF-%d', 'fishotel' ),
				$authoritative->get_id()
			);
			$body  = "Customer self-served a delivery date change on a combined order.\n\n";
			$body .= sprintf( "Fulfillment: FF-%d\n", $authoritative->get_id() );
			$body .= sprintf( "Affected source orders: %s\n", implode( ', ', $source_labels ) );
			$body .= sprintf( "Customer: %s %s (%s)\n",
				$viewed_order->get_billing_first_name(),
				$viewed_order->get_billing_last_name(),
				$viewed_order->get_billing_email()
			);
			$body .= sprintf( "Old date: %s\n", '' !== $old ? $old : '(unset)' );
			$body .= sprintf( "New date: %s\n\n", $new );
			$body .= sprintf( "View fulfillment: %s\n", admin_url( 'post.php?post=' . $authoritative->get_id() . '&action=edit' ) );
		} else {
			$subject = sprintf(
				/* translators: %d order ID */
				__( '[FisHotel] Delivery date changed: Order #%d', 'fishotel' ),
				$authoritative->get_id()
			);
			$body  = "Customer self-served a delivery date change.\n\n";
			$body .= sprintf( "Order: #%d\n", $authoritative->get_id() );
			$body .= sprintf( "Customer: %s %s (%s)\n",
				$viewed_order->get_billing_first_name(),
				$viewed_order->get_billing_last_name(),
				$viewed_order->get_billing_email()
			);
			$body .= sprintf( "Old date: %s\n", '' !== $old ? $old : '(unset)' );
			$body .= sprintf( "New date: %s\n\n", $new );
			$body .= sprintf( "View order: %s\n", admin_url( 'post.php?post=' . $authoritative->get_id() . '&action=edit' ) );
		}
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
