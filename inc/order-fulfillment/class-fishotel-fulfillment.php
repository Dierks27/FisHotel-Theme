<?php
/**
 * FisHotel Fulfillment Orders — Phase 2.5.
 *
 * Replaces the Phase 2 primary/secondary linking (_fishotel_combined_into)
 * with a dedicated *fulfillment order* entity that aggregates line items
 * from one or more source orders. Source orders stay untouched — their
 * payment data, customer history, and refund target are preserved — while
 * the fulfillment is the single working surface for shipping operations.
 *
 * Entity:
 *   - A regular WC order with custom status `wc-fulfillment`.
 *   - _fishotel_is_fulfillment      = '1'           (fast lookup flag)
 *   - _fishotel_fulfillment_sources = "34168,34159" (source order IDs)
 *   - customer_id = 0, total = 0, no payment data.
 *
 * Source side (single source of truth for the link):
 *   - _fishotel_fulfilled_by_order  = {fulfillment_id}
 *   - _fishotel_fulfillment_orig_ship_date = backup of the source's own
 *     shipping date (cleared while fulfilled so the batch counter only
 *     sees the fulfillment's slot; restored on release).
 *
 * Auto-create runs on woocommerce_payment_complete and is wrapped so it can
 * NEVER fatal the checkout/payment flow; it also honors a kill switch
 * (FISHOTEL_AUTO_FULFILLMENT_OFF constant or the
 * `fishotel_auto_fulfillment` option = '0').
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

/** True when an order is a fulfillment entity. */
function fishotel_is_fulfillment( $order ) {
	return FisHotel_Fulfillment::is_fulfillment( $order );
}

/** Fulfillment ID a source order belongs to, or null when standalone. */
function fishotel_order_get_fulfillment( $order ) {
	return FisHotel_Fulfillment::get_fulfillment( $order );
}

/** Source order IDs aggregated into a fulfillment. */
function fishotel_fulfillment_get_sources( $fulfillment ) {
	return FisHotel_Fulfillment::get_sources( $fulfillment );
}

/** Aggregated fulfillment total — sum of source orders' item subtotals. */
function fishotel_fulfillment_calculate_aggregate_total( $fulfillment ) {
	return FisHotel_Fulfillment::calculate_aggregate_total( $fulfillment );
}

/**
 * Unrefunded shipping still sitting on a source order: total shipping minus
 * any shipping already refunded (summed across all shipping lines + refunds).
 * Returns 0 for non-orders or fully-refunded shipping.
 *
 * @param WC_Order $source_order
 * @return float
 */
function fishotel_source_unrefunded_shipping( $source_order ) {
	if ( ! $source_order instanceof WC_Order ) {
		return 0;
	}
	$shipping_total = (float) $source_order->get_shipping_total();
	if ( $shipping_total <= 0 ) {
		return 0;
	}
	$shipping_refunded = 0.0;
	foreach ( $source_order->get_refunds() as $refund ) {
		if ( ! $refund instanceof WC_Order_Refund ) {
			continue;
		}
		foreach ( $refund->get_items( 'shipping' ) as $refund_item ) {
			$shipping_refunded += abs( (float) $refund_item->get_total() );
		}
	}
	return max( 0, $shipping_total - $shipping_refunded );
}

class FisHotel_Fulfillment {

	const STATUS              = 'wc-fulfillment';
	const STATUS_BARE         = 'fulfillment';
	const STATUS_COLOR        = '#7d6035';

	const META_IS_FULFILLMENT = '_fishotel_is_fulfillment';
	const META_SOURCES        = '_fishotel_fulfillment_sources';
	const META_TOTAL          = '_fishotel_fulfillment_total';
	const META_FULFILLED_BY   = '_fishotel_fulfilled_by_order';
	const META_ORIG_SHIP      = '_fishotel_fulfillment_orig_ship_date';
	const META_SHIP_DATE      = '_fishotel_shipping_date';
	const META_SOURCE_ORDER   = '_fishotel_source_order'; // on copied line items
	const META_MIGRATED       = 'fishotel_fulfillment_migrated_v1';
	// Bumped to v2 in 1.15.2 — totals now use item subtotals, so existing
	// fulfillments must be recalculated.
	const META_TOTALS_BACKFILLED = 'fishotel_fulfillment_totals_backfilled_v2';
	const META_FLAG_DISMISSED = '_fishotel_shipping_flag_dismissed';

	const ACTION_DELETE          = 'fishotel_delete_fulfillment';
	const ACTION_COMBINE_SELECTED = 'fishotel_combine_selected';
	const ACTION_DISMISS_FLAG    = 'fishotel_dismiss_shipping_flag';
	const PAGE_SCAN              = 'fishotel-scan-combinable';
	const COUNT_TRANSIENT        = 'fishotel_open_orders_count';
	// Sortable Delivery Date column. The column itself ("Delivery Date") is
	// registered by the fishotel-shiptracker plugin under the id
	// 'fst_ship_date', reading the _fishotel_shipping_date order meta. We only
	// add the sort affordance + orderby here.
	const DELIVERY_COL           = 'fst_ship_date';

	public static function init() {
		// Custom order status (CPT + HPOS).
		add_action( 'init', [ __CLASS__, 'register_status' ] );
		add_filter( 'wc_order_statuses', [ __CLASS__, 'add_wc_status' ] );
		add_action( 'admin_head', [ __CLASS__, 'status_badge_css' ] );

		// Auto-create on payment — hard-guarded; runs late so the order is
		// fully saved. Priority 99.
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'maybe_auto_create' ], 99, 1 );

		// One-time, idempotent migration of Phase 2 combined orders.
		add_action( 'admin_init', [ __CLASS__, 'maybe_migrate' ] );
		// One-time backfill of aggregated totals onto pre-1.15.1 fulfillments
		// (which were created with a $0 total).
		add_action( 'admin_init', [ __CLASS__, 'maybe_backfill_totals' ] );

		// Keep fulfillments out of WC Analytics so their aggregated total
		// (now the order total, for the list column) doesn't double-count
		// revenue already attributed to the source orders.
		add_filter( 'woocommerce_analytics_excluded_order_statuses', [ __CLASS__, 'exclude_from_analytics' ] );

		// "Scan for Combinable Orders" tool under the FisHotel Theme menu.
		add_action( 'admin_menu', [ __CLASS__, 'register_scan_tool' ], 20 );
		add_action( 'admin_post_' . self::ACTION_COMBINE_SELECTED, [ __CLASS__, 'handle_combine_selected' ] );

		// Mirror completion to source orders so each customer's order email
		// fires per WC defaults.
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'mirror_status_to_sources' ], 30, 4 );
		// ShipTracker fires its own action after a shipment-driven transition.
		add_action( 'fst_status_changed', [ __CLASS__, 'on_fst_status_changed' ], 10, 4 );

		// Admin orders list: hide fulfilled sources by default; show toggle.
		// The Processing tab is the unified "Open Orders" view — standalones
		// plus the fulfillments themselves (see filter methods).
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_cpt_list_query' ] );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', [ __CLASS__, 'filter_hpos_list_query' ] );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', [ __CLASS__, 'filter_hpos_list_query' ] );
		add_action( 'restrict_manage_posts', [ __CLASS__, 'render_sources_toggle_cpt' ] );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ __CLASS__, 'render_sources_toggle_hpos' ] );

		// Make the Processing count badge match the unified Open Orders view.
		add_filter( 'views_edit-shop_order', [ __CLASS__, 'override_processing_count' ] );
		add_filter( 'views_woocommerce_page_wc-orders', [ __CLASS__, 'override_processing_count' ] );

		// Customers never see fulfillments in My Account.
		add_filter( 'woocommerce_my_account_my_orders_query', [ __CLASS__, 'hide_fulfillments_from_account' ] );

		// Suppress WC customer-facing emails for the fulfillment entity itself.
		// (It now carries a billing email for label/display, but completing it
		// must not email the customer about a "$X fulfillment" — the per-source
		// completion emails fire via mirror_status_to_sources instead.)
		foreach ( [ 'customer_completed_order', 'customer_processing_order', 'customer_on_hold_order', 'customer_refunded_order', 'customer_invoice', 'customer_note' ] as $eid ) {
			add_filter( 'woocommerce_email_enabled_' . $eid, [ __CLASS__, 'suppress_fulfillment_email' ], 10, 2 );
		}

		// "Delete Fulfillment" meta box + handler.
		add_action( 'add_meta_boxes_shop_order', [ __CLASS__, 'register_delete_meta_box' ] );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', [ __CLASS__, 'register_delete_meta_box' ] );
		add_action( 'admin_post_' . self::ACTION_DELETE, [ __CLASS__, 'handle_delete' ] );

		// Bright-flag fallback: shipping-refund-pending banner on fulfillment +
		// source order edit pages, with manual (admin-confirmed) refund.
		add_action( 'add_meta_boxes_shop_order', [ __CLASS__, 'register_flag_meta_box' ] );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', [ __CLASS__, 'register_flag_meta_box' ] );
		add_action( 'admin_post_' . self::ACTION_DISMISS_FLAG, [ __CLASS__, 'handle_dismiss_flag' ] );

		// Linkify the _fishotel_source_order line-item meta on the order edit
		// screen (admin order items table). Renames the label and turns the
		// raw ID into a link to the source order's edit page.
		add_filter( 'woocommerce_order_item_display_meta_key', [ __CLASS__, 'linkify_source_meta_key' ], 10, 3 );
		add_filter( 'woocommerce_order_item_display_meta_value', [ __CLASS__, 'linkify_source_meta_value' ], 10, 3 );

		// Sidebar "Orders" badge: match the in-page Open Orders count.
		// v1.16.0 used woocommerce_menu_order_count, which didn't apply on
		// this build. Two reliable paths instead:
		//   A) Filter wp_count_posts('shop_order') so anything reading the
		//      raw post count gets our Open Orders number.
		//   B) Late-priority admin_menu hook that rewrites the bubble HTML
		//      on the Orders menu item directly (covers HPOS, where the
		//      menu doesn't necessarily read wp_count_posts).
		add_filter( 'wp_count_posts', [ __CLASS__, 'filter_count_posts' ], 10, 2 );
		add_action( 'admin_menu', [ __CLASS__, 'rewrite_menu_badge' ], 999 );
		// Bust the cached count when orders change or fulfillments mutate.
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'bust_count_cache' ] );

		// Sortable "Delivery Date" column (legacy + HPOS). The column itself
		// is registered by fishotel-shiptracker under the id 'fst_ship_date';
		// we only add the sort affordance. Priority 20 to run after the
		// plugin's default-priority registration, and both the
		// manage_{screen}_sortable_columns (WP convention) and
		// woocommerce_shop_order_list_table_sortable_columns (HPOS API)
		// filters so we cover whichever WC actually uses.
		add_filter( 'manage_edit-shop_order_sortable_columns', [ __CLASS__, 'add_sortable_delivery_column' ], 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_sortable_columns', [ __CLASS__, 'add_sortable_delivery_column' ], 20 );
		add_filter( 'woocommerce_shop_order_list_table_sortable_columns', [ __CLASS__, 'add_sortable_delivery_column' ], 20 );
		add_action( 'pre_get_posts', [ __CLASS__, 'sort_delivery_cpt' ] );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', [ __CLASS__, 'sort_delivery_hpos' ] );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', [ __CLASS__, 'sort_delivery_hpos' ] );
	}

	// ── Helpers ──────────────────────────────────────────────────────

	private static function to_order( $order ) {
		if ( $order instanceof WC_Order ) {
			return $order;
		}
		$o = wc_get_order( (int) $order );
		return $o instanceof WC_Order ? $o : null;
	}

	public static function is_fulfillment( $order ) {
		$o = self::to_order( $order );
		return $o ? '1' === (string) $o->get_meta( self::META_IS_FULFILLMENT ) : false;
	}

	public static function get_fulfillment( $order ) {
		$o = self::to_order( $order );
		if ( ! $o ) {
			return null;
		}
		$id = (int) $o->get_meta( self::META_FULFILLED_BY );
		return $id > 0 ? $id : null;
	}

	public static function get_sources( $fulfillment ) {
		$o = self::to_order( $fulfillment );
		if ( ! $o ) {
			return [];
		}
		$raw = (string) $o->get_meta( self::META_SOURCES );
		if ( '' === $raw ) {
			return [];
		}
		$ids = array_filter( array_map( 'intval', explode( ',', $raw ) ) );
		return array_values( array_unique( $ids ) );
	}

	/** Human "#A, #B" list of order numbers for the given order IDs. */
	private static function numbers_list( array $ids ) {
		$nums = [];
		foreach ( $ids as $id ) {
			$o = wc_get_order( (int) $id );
			$nums[] = '#' . ( $o instanceof WC_Order ? $o->get_order_number() : (string) $id );
		}
		return implode( ', ', $nums );
	}

	// ── Custom status ────────────────────────────────────────────────

	public static function register_status() {
		register_post_status( self::STATUS, [
			'label'                     => _x( 'Fulfillment', 'Order status', 'fishotel' ),
			'public'                    => false,
			'internal'                  => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: count */
			'label_count'               => _n_noop( 'Fulfillment <span class="count">(%s)</span>', 'Fulfillment <span class="count">(%s)</span>', 'fishotel' ),
		] );
	}

	public static function add_wc_status( $statuses ) {
		$statuses[ self::STATUS ] = _x( 'Fulfillment', 'Order status', 'fishotel' );
		return $statuses;
	}

	public static function status_badge_css() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$relevant = in_array( $screen->id, [ 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders' ], true )
			|| ( isset( $screen->post_type ) && 'shop_order' === $screen->post_type );
		if ( ! $relevant ) {
			return;
		}
		printf(
			'<style>.order-status.status-%1$s,mark.order-status.status-%1$s,.status-%1$s>span{background:%2$s !important;color:#fff !important;}</style>',
			esc_attr( self::STATUS_BARE ),
			esc_attr( self::STATUS_COLOR )
		);
	}

	// ── Create / mutate ──────────────────────────────────────────────

	/**
	 * Create a fulfillment aggregating the given source orders. Returns the
	 * fulfillment ID, or null if fewer than one valid source remains.
	 *
	 * @param int[] $source_ids
	 * @return int|null
	 */
	public static function create_fulfillment( array $source_ids ) {
		$sources = [];
		foreach ( array_unique( array_map( 'intval', $source_ids ) ) as $sid ) {
			$o = wc_get_order( $sid );
			if ( $o instanceof WC_Order
				&& 'shop_order' === $o->get_type()
				&& ! self::is_fulfillment( $o )
				&& ! self::get_fulfillment( $o ) ) {
				$sources[ $sid ] = $o;
			}
		}
		if ( count( $sources ) < 1 ) {
			return null;
		}

		try {
			$f = new WC_Order();
			$f->set_created_via( 'fishotel_fulfillment' );
			$f->set_customer_id( 0 );
			foreach ( $sources as $sid => $o ) {
				self::copy_line_items( $o, $f, (int) $sid );
			}
			// Display-only customer fields from the first source so Jeff can
			// see who the package belongs to and print labels. Refunds still
			// run through each source order's own payment data.
			$first = reset( $sources );
			if ( $first instanceof WC_Order ) {
				self::copy_customer_fields( $first, $f );
			}
			// Aggregated total becomes the fulfillment's order total so the
			// native orders-list Total column shows it; cached in meta too.
			$total = self::aggregate_total( $sources );
			$f->set_total( $total );
			$f->update_meta_data( self::META_TOTAL, wc_format_decimal( $total ) );
			$f->update_meta_data( self::META_IS_FULFILLMENT, '1' );
			$f->update_meta_data( self::META_SOURCES, implode( ',', array_keys( $sources ) ) );
			$earliest = self::earliest_ship_date( $sources );
			if ( '' !== $earliest ) {
				$f->update_meta_data( self::META_SHIP_DATE, $earliest );
			}
			$f->set_status( self::STATUS_BARE );
			$f->save();
			$ff_id = $f->get_id();

			foreach ( $sources as $o ) {
				self::attach_source( $o, $ff_id );
			}
			$f->add_order_note( sprintf(
				/* translators: %s = list of order numbers */
				__( 'Created from orders %s.', 'fishotel' ),
				self::numbers_list( array_keys( $sources ) )
			) );
			$f->save();
			self::bust_count_cache();
			return $ff_id;
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Fulfillment create failed: ' . $e->getMessage(), [ 'source' => 'fishotel-fulfillment' ] );
			}
			return null;
		}
	}

	/**
	 * Add one more source order to an existing fulfillment. Returns true on
	 * success.
	 */
	public static function add_source( $fulfillment_id, $source_id ) {
		$f = wc_get_order( (int) $fulfillment_id );
		$o = wc_get_order( (int) $source_id );
		if ( ! $f instanceof WC_Order || ! self::is_fulfillment( $f ) ) {
			return false;
		}
		if ( ! $o instanceof WC_Order || 'shop_order' !== $o->get_type() || self::is_fulfillment( $o ) || self::get_fulfillment( $o ) ) {
			return false;
		}
		try {
			self::copy_line_items( $o, $f, (int) $source_id );
			$sources   = self::get_sources( $f );
			$sources[] = (int) $source_id;
			$sources   = array_values( array_unique( $sources ) );
			$f->update_meta_data( self::META_SOURCES, implode( ',', $sources ) );

			// Customer fields: copy when the fulfillment has none yet; warn (but
			// don't overwrite) if the new source's customer differs.
			$existing_email = strtolower( trim( (string) $f->get_billing_email() ) );
			$new_email      = strtolower( trim( (string) $o->get_billing_email() ) );
			if ( '' === $existing_email && '' === trim( (string) $f->get_billing_last_name() ) ) {
				self::copy_customer_fields( $o, $f );
			} elseif ( '' !== $new_email && '' !== $existing_email && $new_email !== $existing_email ) {
				$f->add_order_note( sprintf(
					/* translators: 1: order number, 2: new email, 3: existing email */
					__( 'Warning: added order #%1$s belongs to a different customer (%2$s) than this fulfillment (%3$s).', 'fishotel' ),
					$o->get_order_number(),
					$new_email,
					$existing_email
				) );
			}

			// Recompute the aggregated total across all current sources.
			$total = self::aggregate_total_from_ids( $sources );
			$f->set_total( $total );
			$f->update_meta_data( self::META_TOTAL, wc_format_decimal( $total ) );
			$f->save();

			self::attach_source( $o, $f->get_id() );
			$f->add_order_note( sprintf(
				/* translators: %s = order number */
				__( 'Added order #%s to this fulfillment.', 'fishotel' ),
				$o->get_order_number()
			) );
			$f->save();
			return true;
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Fulfillment add_source failed: ' . $e->getMessage(), [ 'source' => 'fishotel-fulfillment' ] );
			}
			return false;
		}
	}

	/** Copy a source order's product line items into the fulfillment. */
	private static function copy_line_items( WC_Order $from, WC_Order $to, $source_id ) {
		foreach ( $from->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$new = new WC_Order_Item_Product();
			$new->set_name( $item->get_name() );
			$new->set_product_id( $item->get_product_id() );
			$new->set_variation_id( $item->get_variation_id() );
			$new->set_quantity( $item->get_quantity() );
			$new->set_tax_class( $item->get_tax_class() );
			$new->set_subtotal( $item->get_subtotal() );
			$new->set_total( $item->get_total() );
			// Preserve variation attribute meta (e.g. "Size: 1oz") for display.
			foreach ( $item->get_meta_data() as $meta ) {
				$data = $meta->get_data();
				if ( isset( $data['key'], $data['value'] ) && 0 !== strpos( (string) $data['key'], '_' ) ) {
					$new->add_meta_data( $data['key'], $data['value'] );
				}
			}
			$new->add_meta_data( self::META_SOURCE_ORDER, (int) $source_id );
			$to->add_item( $new );
		}
	}

	/** Copy display-only billing + shipping fields from a source onto the fulfillment. */
	private static function copy_customer_fields( WC_Order $from, WC_Order $to ) {
		$to->set_address( $from->get_address( 'billing' ), 'billing' );
		$to->set_address( $from->get_address( 'shipping' ), 'shipping' );
	}

	/**
	 * Aggregated fulfillment total = sum of the source orders' item subtotals
	 * (what's actually in the box: no shipping, tax, refunds, or gift cards).
	 * This is the operationally meaningful number and matches the items
	 * subtotal the WC edit page shows natively.
	 */
	private static function aggregate_total( array $source_orders ) {
		$total = 0.0;
		foreach ( $source_orders as $o ) {
			if ( $o instanceof WC_Order ) {
				$total += (float) $o->get_subtotal();
			}
		}
		return $total;
	}

	/** Sum of the given source order IDs' item subtotals. */
	private static function aggregate_total_from_ids( array $ids ) {
		$orders = [];
		foreach ( $ids as $id ) {
			$o = wc_get_order( (int) $id );
			if ( $o instanceof WC_Order ) {
				$orders[] = $o;
			}
		}
		return self::aggregate_total( $orders );
	}

	/**
	 * Public aggregate-total calculator for a fulfillment: sum of its source
	 * orders' item subtotals.
	 *
	 * @param int|WC_Order $fulfillment
	 * @return float
	 */
	public static function calculate_aggregate_total( $fulfillment ) {
		$ff = self::to_order( $fulfillment );
		if ( ! $ff instanceof WC_Order ) {
			return 0.0;
		}
		return self::aggregate_total_from_ids( self::get_sources( $ff ) );
	}

	/** Earliest source shipping date (Y-m-d string), or '' when none set. */
	private static function earliest_ship_date( array $sources ) {
		$dates = [];
		foreach ( $sources as $o ) {
			$d = (string) $o->get_meta( self::META_SHIP_DATE );
			if ( '' !== $d ) {
				$dates[] = $d;
			}
		}
		if ( empty( $dates ) ) {
			return '';
		}
		sort( $dates );
		return $dates[0];
	}

	/** Tag a source: back up + clear its ship date, link it, note it. */
	private static function attach_source( WC_Order $o, $ff_id ) {
		$orig = $o->get_meta( self::META_SHIP_DATE );
		if ( '' !== $orig && null !== $orig ) {
			$o->update_meta_data( self::META_ORIG_SHIP, $orig );
		}
		$o->delete_meta_data( self::META_SHIP_DATE );
		$o->update_meta_data( self::META_FULFILLED_BY, (int) $ff_id );
		$o->add_order_note( sprintf(
			/* translators: %d = fulfillment ID */
			__( 'Added to fulfillment #FF-%d.', 'fishotel' ),
			(int) $ff_id
		) );
		$o->save();
	}

	/** Untag a source: restore its ship date, clear link metas, note it. */
	private static function detach_source( WC_Order $o, $ff_id, $reason ) {
		$orig = $o->get_meta( self::META_ORIG_SHIP );
		if ( '' !== $orig && null !== $orig ) {
			$o->update_meta_data( self::META_SHIP_DATE, $orig );
		}
		$o->delete_meta_data( self::META_FULFILLED_BY );
		$o->delete_meta_data( self::META_ORIG_SHIP );
		$o->add_order_note( $reason );
		$o->save();
	}

	/** Delete a fulfillment and release all its sources. */
	public static function delete_fulfillment( $fulfillment_id ) {
		$f = wc_get_order( (int) $fulfillment_id );
		if ( ! $f instanceof WC_Order || ! self::is_fulfillment( $f ) ) {
			return false;
		}
		$ff_id = $f->get_id();
		foreach ( self::get_sources( $f ) as $sid ) {
			$o = wc_get_order( (int) $sid );
			if ( $o instanceof WC_Order ) {
				self::detach_source( $o, $ff_id, sprintf(
					/* translators: %d = fulfillment ID */
					__( 'Released from deleted fulfillment #FF-%d.', 'fishotel' ),
					$ff_id
				) );
			}
		}
		$f->delete( true );
		self::bust_count_cache();
		return true;
	}

	// ── Auto-create on payment ───────────────────────────────────────

	/** True when auto-combine is disabled via constant or option. */
	private static function auto_disabled() {
		if ( defined( 'FISHOTEL_AUTO_FULFILLMENT_OFF' ) && FISHOTEL_AUTO_FULFILLMENT_OFF ) {
			return true;
		}
		return '0' === (string) get_option( 'fishotel_auto_fulfillment', '1' );
	}

	/**
	 * On payment complete, fold the new order into an existing fulfillment
	 * for the same customer, or create one from a matching open order.
	 *
	 * Wrapped end-to-end so a failure can never break the payment flow.
	 */
	public static function maybe_auto_create( $order_id ) {
		if ( self::auto_disabled() ) {
			return;
		}
		try {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
				return;
			}
			if ( self::is_fulfillment( $order ) || self::get_fulfillment( $order ) ) {
				return;
			}

			$others = self::customer_open_orders( $order );
			if ( empty( $others ) ) {
				return; // Standalone — nothing to combine with.
			}

			// Shipping-address constraint: only combine same-destination orders.
			$matching = [];
			foreach ( $others as $oid ) {
				$candidate = wc_get_order( $oid );
				if ( ! $candidate instanceof WC_Order ) {
					continue;
				}
				if ( self::shipping_matches( $order, $candidate ) ) {
					$matching[] = $oid;
				} else {
					$order->add_order_note( sprintf(
						/* translators: %s = order number */
						__( 'Auto-combine skipped: shipping address differs from order #%s.', 'fishotel' ),
						$candidate->get_order_number()
					) );
				}
			}
			if ( empty( $matching ) ) {
				return;
			}

			// If any match already belongs to a fulfillment, join it.
			foreach ( $matching as $oid ) {
				$existing = self::get_fulfillment( $oid );
				if ( $existing ) {
					self::add_source( $existing, $order->get_id() );
					return;
				}
			}

			// Otherwise create a fresh fulfillment from the matches + this order.
			$matching[] = $order->get_id();
			self::create_fulfillment( $matching );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Fulfillment auto-create failed: ' . $e->getMessage(), [ 'source' => 'fishotel-fulfillment' ] );
			}
			// Never rethrow — checkout must not break.
		}
	}

	/**
	 * Other pre-ship orders for the same customer, excluding $order, any
	 * fulfillment, and any already-fulfilled source. Newest first, capped.
	 *
	 * @return int[]
	 */
	private static function customer_open_orders( WC_Order $order ) {
		$base = [
			'limit'   => 20,
			'status'  => [ 'wc-processing', 'wc-on-hold' ],
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'ids',
			'exclude' => [ $order->get_id() ],
		];

		$found       = [];
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id ) {
			foreach ( (array) wc_get_orders( $base + [ 'customer_id' => $customer_id ] ) as $id ) {
				$found[ (int) $id ] = true;
			}
		} else {
			$email = (string) $order->get_billing_email();
			if ( '' !== $email ) {
				foreach ( (array) wc_get_orders( $base + [ 'billing_email' => $email ] ) as $id ) {
					$found[ (int) $id ] = true;
				}
			}
		}

		$out = [];
		foreach ( array_keys( $found ) as $id ) {
			if ( self::is_fulfillment( $id ) || self::get_fulfillment( $id ) ) {
				continue;
			}
			$out[] = $id;
		}
		return $out;
	}

	/** Compare the two orders' shipping destinations. */
	private static function shipping_matches( WC_Order $a, WC_Order $b ) {
		return self::ship_hash( $a ) === self::ship_hash( $b );
	}

	private static function ship_hash( WC_Order $o ) {
		$parts = [
			$o->get_shipping_first_name(),
			$o->get_shipping_last_name(),
			$o->get_shipping_address_1(),
			$o->get_shipping_address_2(),
			$o->get_shipping_city(),
			$o->get_shipping_state(),
			$o->get_shipping_postcode(),
			$o->get_shipping_country(),
		];
		$norm = array_map( static function ( $p ) {
			return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $p ) ) );
		}, $parts );
		// Fall back to billing when no shipping address is present at all.
		if ( '' === implode( '', $norm ) ) {
			return 'billing:' . strtolower( trim( (string) $o->get_billing_postcode() . '|' . $o->get_billing_address_1() ) );
		}
		return implode( '|', $norm );
	}

	// ── Status mirroring ─────────────────────────────────────────────

	/** When a fulfillment completes, complete its source orders too. */
	public static function mirror_status_to_sources( $order_id, $from, $to, $order ) {
		if ( 'completed' !== $to ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order || ! self::is_fulfillment( $order ) ) {
			return;
		}
		foreach ( self::get_sources( $order ) as $sid ) {
			$src = wc_get_order( (int) $sid );
			if ( $src instanceof WC_Order && ! $src->has_status( 'completed' ) ) {
				$src->update_status( 'completed', sprintf(
					/* translators: %d = fulfillment ID */
					__( 'FisHotel: completed via fulfillment #FF-%d.', 'fishotel' ),
					$order->get_id()
				) );
			}
		}
	}

	/** ShipTracker fired its post-transition action on the fulfillment. */
	public static function on_fst_status_changed( $shipment, $old_status, $new_status, $order ) {
		if ( ! $order instanceof WC_Order || ! self::is_fulfillment( $order ) ) {
			return;
		}
		if ( $order->has_status( 'completed' ) ) {
			self::mirror_status_to_sources( $order->get_id(), $old_status, 'completed', $order );
		}
	}

	// ── Admin list filtering ─────────────────────────────────────────

	private static function show_sources_requested() {
		return isset( $_GET['fishotel_show_sources'] ) && '1' === $_GET['fishotel_show_sources']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Legacy CPT orders list. Two behaviors:
	 *   - Processing tab → unified Open Orders: expand the status filter to
	 *     also include fulfillments (so it's standalones + fulfillments).
	 *   - Always (unless "show fulfilled sources") → hide fulfilled sources.
	 */
	public static function filter_cpt_list_query( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}
		if ( 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}

		// Open Orders: Processing also surfaces the fulfillments themselves.
		if ( 'wc-processing' === $query->get( 'post_status' ) ) {
			$query->set( 'post_status', [ 'wc-processing', self::STATUS ] );
		}

		if ( self::show_sources_requested() ) {
			return;
		}
		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = [
			'key'     => self::META_FULFILLED_BY,
			'compare' => 'NOT EXISTS',
		];
		$query->set( 'meta_query', $meta_query );
	}

	/** HPOS orders list — same Open Orders expansion + fulfilled-source hiding. */
	public static function filter_hpos_list_query( $args ) {
		// Open Orders: when the status filter is exactly Processing, also
		// include fulfillments. (The Fulfillment tab — status wc-fulfillment —
		// and the All view are left untouched.)
		if ( isset( $args['status'] ) ) {
			$statuses = array_values( (array) $args['status'] );
			if ( [ 'wc-processing' ] === $statuses ) {
				$args['status'] = [ 'wc-processing', self::STATUS ];
			}
		}

		if ( self::show_sources_requested() ) {
			return $args;
		}
		if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			$args['meta_query'] = [];
		}
		$args['meta_query'][] = [
			'key'     => self::META_FULFILLED_BY,
			'compare' => 'NOT EXISTS',
		];
		return $args;
	}

	/**
	 * Count badge for the Processing view = visible Open Orders:
	 * standalones in processing (not fulfilled sources) + all fulfillments.
	 */
	public static function override_processing_count( $views ) {
		$count = self::processing_open_count();
		$label = '(' . number_format_i18n( $count ) . ')';
		foreach ( [ 'wc-processing', 'processing' ] as $key ) {
			if ( isset( $views[ $key ] ) ) {
				$views[ $key ] = preg_replace( '/\((\d[\d,]*)\)/', $label, $views[ $key ], 1 );
			}
		}
		return $views;
	}

	/** Visible Open Orders count: processing standalones + fulfillments. */
	public static function processing_open_count() {
		$standalones = 0;
		$processing  = wc_get_orders( [
			'status' => 'wc-processing',
			'limit'  => -1,
			'return' => 'ids',
		] );
		foreach ( (array) $processing as $id ) {
			if ( ! self::get_fulfillment( $id ) ) {
				$standalones++;
			}
		}
		$fulfillments = wc_get_orders( [
			'status' => self::STATUS,
			'limit'  => -1,
			'return' => 'ids',
		] );
		return $standalones + count( (array) $fulfillments );
	}

	/**
	 * Cached Open Orders count. The menu and wp_count_posts filter both call
	 * this on every admin page load, so we cache briefly and bust on order /
	 * fulfillment changes.
	 */
	public static function cached_open_count() {
		$cached = get_transient( self::COUNT_TRANSIENT );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$open = self::processing_open_count();
		set_transient( self::COUNT_TRANSIENT, $open, 2 * MINUTE_IN_SECONDS );
		return $open;
	}

	/** Drop the cached Open Orders count. */
	public static function bust_count_cache() {
		delete_transient( self::COUNT_TRANSIENT );
	}

	/**
	 * Filter wp_count_posts('shop_order') so the badge — and anything else
	 * reading the raw processing count — reflects Open Orders: processing
	 * standalones (not fulfilled sources) + fulfillments.
	 */
	public static function filter_count_posts( $counts, $type ) {
		if ( 'shop_order' !== $type || ! is_object( $counts ) ) {
			return $counts;
		}
		$open = self::cached_open_count();
		$counts->{'wc-processing'} = $open;
		return $counts;
	}

	/**
	 * Late-priority safety net for HPOS, where the Orders menu bubble can be
	 * rendered without consulting wp_count_posts. Rewrites the count inside
	 * the menu title HTML, preserving structure.
	 */
	public static function rewrite_menu_badge() {
		global $menu;
		if ( ! is_array( $menu ) ) {
			return;
		}
		$open = self::cached_open_count();
		foreach ( $menu as $i => $item ) {
			if ( ! isset( $item[2] ) ) {
				continue;
			}
			$slug = (string) $item[2];
			if ( false === strpos( $slug, 'shop_order' ) && false === strpos( $slug, 'wc-orders' ) ) {
				continue;
			}
			if ( ! isset( $item[0] ) || false === strpos( (string) $item[0], 'count-' ) ) {
				continue;
			}
			// Replace the count number inside the existing pending-count span
			// (preserves "Orders " label + WC's span structure).
			$updated = preg_replace(
				'/(<span class="pending-count">)\d+(<\/span>)/',
				'${1}' . (int) $open . '${2}',
				(string) $item[0]
			);
			$updated = preg_replace( '/(count-)\d+/', '${1}' . (int) $open, $updated );
			$menu[ $i ][0] = $updated;
		}
	}

	public static function render_sources_toggle_cpt( $post_type ) {
		if ( 'shop_order' !== $post_type ) {
			return;
		}
		self::render_sources_toggle();
	}

	public static function render_sources_toggle_hpos() {
		self::render_sources_toggle();
	}

	private static function render_sources_toggle() {
		$on = self::show_sources_requested();
		printf(
			'<label style="margin:0 6px;"><input type="checkbox" name="fishotel_show_sources" value="1" %s onchange="this.form.submit()"> %s</label>',
			checked( $on, true, false ),
			esc_html__( 'Show fulfilled sources', 'fishotel' )
		);
	}

	/** Disable a WC customer email when the order is a fulfillment entity. */
	public static function suppress_fulfillment_email( $enabled, $order ) {
		if ( $order instanceof WC_Order && self::is_fulfillment( $order ) ) {
			return false;
		}
		return $enabled;
	}

	/** Customers never see fulfillment orders in My Account → Orders. */
	public static function hide_fulfillments_from_account( $args ) {
		// Fulfillments have customer_id 0 so they won't match a logged-in
		// query anyway, but exclude the status explicitly as a belt.
		if ( empty( $args['status'] ) ) {
			$args['status'] = array_keys( wc_get_order_statuses() );
		}
		$args['status'] = array_values( array_diff( (array) $args['status'], [ self::STATUS ] ) );
		return $args;
	}

	// ── Delete Fulfillment meta box ──────────────────────────────────

	public static function register_delete_meta_box( $screen_or_post = null ) {
		$order = $screen_or_post instanceof WC_Order
			? $screen_or_post
			: ( $screen_or_post instanceof WP_Post ? wc_get_order( $screen_or_post->ID ) : null );
		if ( ! $order instanceof WC_Order || ! self::is_fulfillment( $order ) ) {
			return;
		}
		$screen = $order instanceof WC_Order && function_exists( 'wc_get_page_screen_id' ) && self::on_hpos_screen()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
		add_meta_box(
			'fishotel-fulfillment-delete',
			__( 'Fulfillment', 'fishotel' ),
			[ __CLASS__, 'render_delete_meta_box' ],
			$screen,
			'side',
			'high'
		);
	}

	private static function on_hpos_screen() {
		if ( ! function_exists( 'get_current_screen' ) || ! function_exists( 'wc_get_page_screen_id' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen && $screen->id === wc_get_page_screen_id( 'shop-order' );
	}

	public static function render_delete_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order
			? $post_or_order
			: ( $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : null );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order_id = $order->get_id();
		$sources  = self::get_sources( $order );
		?>
		<p style="margin:0 0 8px;">
			<?php
			$source_links = [];
			foreach ( $sources as $sid ) {
				$src = wc_get_order( (int) $sid );
				$num = $src instanceof WC_Order ? $src->get_order_number() : (string) (int) $sid;
				$url = $src instanceof WC_Order
					? $src->get_edit_order_url()
					: admin_url( 'post.php?post=' . (int) $sid . '&action=edit' );
				$source_links[] = sprintf(
					'<a href="%s">#%s</a>',
					esc_url( $url ),
					esc_html( $num )
				);
			}
			printf(
				/* translators: %s = comma-separated source order links */
				esc_html__( 'This fulfillment bundles orders %s.', 'fishotel' ),
				implode( ', ', $source_links ) // already escaped above
			);
			?>
		</p>
		<p class="description" style="margin:0 0 10px;">
			<?php esc_html_e( 'Deleting releases every source order: their shipping dates are restored and they reappear in the orders list.', 'fishotel' ); ?>
		</p>
		<button type="button" class="button button-link-delete" data-fishotel-delete-fulfillment>
			<?php esc_html_e( 'Delete Fulfillment', 'fishotel' ); ?>
		</button>
		<script>
		( function () {
			var btn = document.querySelector( '[data-fishotel-delete-fulfillment]' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				if ( ! window.confirm( <?php echo wp_json_encode( __( 'Delete this fulfillment and release its source orders? This cannot be undone.', 'fishotel' ) ); ?> ) ) {
					return;
				}
				var f = document.createElement( 'form' );
				f.method = 'post';
				f.action = <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;
				f.style.display = 'none';
				function add( n, v ) { var i = document.createElement( 'input' ); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild( i ); }
				add( 'action', <?php echo wp_json_encode( self::ACTION_DELETE ); ?> );
				add( 'fulfillment_id', <?php echo (int) $order_id; ?> );
				add( '_wpnonce', <?php echo wp_json_encode( wp_create_nonce( self::ACTION_DELETE . '_' . $order_id ) ); ?> );
				document.body.appendChild( f );
				f.submit();
			} );
		} )();
		</script>
		<?php
	}

	public static function handle_delete() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'fishotel' ), '', [ 'response' => 403 ] );
		}
		$ff_id = isset( $_POST['fulfillment_id'] ) ? absint( wp_unslash( $_POST['fulfillment_id'] ) ) : 0;
		check_admin_referer( self::ACTION_DELETE . '_' . $ff_id );

		self::delete_fulfillment( $ff_id );

		// The fulfillment is gone — send Jeff back to the orders list.
		$list = self::on_hpos_screen()
			? admin_url( 'admin.php?page=wc-orders' )
			: admin_url( 'edit.php?post_type=shop_order' );
		wp_safe_redirect( add_query_arg( 'fishotel_fulfillment_deleted', '1', $list ) );
		exit;
	}

	// ── Line-item source-order linkifier ─────────────────────────────

	/** Replace the raw '_fishotel_source_order' meta label with "Source order". */
	public static function linkify_source_meta_key( $display_key, $meta, $item ) {
		if ( isset( $meta->key ) && self::META_SOURCE_ORDER === $meta->key ) {
			return __( 'Source order', 'fishotel' );
		}
		return $display_key;
	}

	/** Render the _fishotel_source_order meta value as a link to that order's edit page. */
	public static function linkify_source_meta_value( $display_value, $meta, $item ) {
		if ( ! isset( $meta->key ) || self::META_SOURCE_ORDER !== $meta->key ) {
			return $display_value;
		}
		$source_id = isset( $meta->value ) ? (int) $meta->value : 0;
		if ( $source_id <= 0 ) {
			return $display_value;
		}
		$src = wc_get_order( $source_id );
		$num = $src instanceof WC_Order ? $src->get_order_number() : (string) $source_id;
		$url = $src instanceof WC_Order
			? $src->get_edit_order_url()
			: admin_url( 'post.php?post=' . $source_id . '&action=edit' );
		return sprintf( '<a href="%s">#%s</a>', esc_url( $url ), esc_html( $num ) );
	}

	// ── Bright-flag fallback (shipping refund pending) ───────────────

	/** True when this source order still has unrefunded shipping and isn't dismissed. */
	private static function source_flagged( WC_Order $source ) {
		if ( '1' === (string) $source->get_meta( self::META_FLAG_DISMISSED ) ) {
			return false;
		}
		return fishotel_source_unrefunded_shipping( $source ) > 0;
	}

	/** Source orders of a fulfillment that currently carry an active flag. */
	private static function flagged_sources( WC_Order $fulfillment ) {
		$out = [];
		foreach ( self::get_sources( $fulfillment ) as $sid ) {
			$o = wc_get_order( (int) $sid );
			if ( $o instanceof WC_Order && self::source_flagged( $o ) ) {
				$out[] = $o;
			}
		}
		return $out;
	}

	public static function register_flag_meta_box( $screen_or_post = null ) {
		$order = $screen_or_post instanceof WC_Order
			? $screen_or_post
			: ( $screen_or_post instanceof WP_Post ? wc_get_order( $screen_or_post->ID ) : null );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Show on a fulfillment with ≥1 flagged source, or on a flagged source
		// order that belongs to a fulfillment.
		$show = false;
		if ( self::is_fulfillment( $order ) ) {
			$show = ! empty( self::flagged_sources( $order ) );
		} elseif ( self::get_fulfillment( $order ) && self::source_flagged( $order ) ) {
			$show = true;
		}
		if ( ! $show ) {
			return;
		}

		$screen = self::on_hpos_screen() && function_exists( 'wc_get_page_screen_id' )
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
		add_meta_box(
			'fishotel-shipping-flag',
			__( '⚠️ Shipping refund pending', 'fishotel' ),
			[ __CLASS__, 'render_flag_meta_box' ],
			$screen,
			'normal',
			'high'
		);
	}

	public static function render_flag_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order
			? $post_or_order
			: ( $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : null );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		echo '<div style="background:#fcf3cd;border:2px solid #d4a017;border-radius:4px;padding:12px 14px;color:#3a2f00;">';

		if ( self::is_fulfillment( $order ) ) {
			$ff_id = $order->get_id();
			foreach ( self::flagged_sources( $order ) as $src ) {
				self::render_flag_row( $src, $ff_id, /*on_source_page=*/ false );
			}
		} else {
			$ff_id = (int) self::get_fulfillment( $order );
			self::render_flag_row( $order, $ff_id, /*on_source_page=*/ true );
		}

		echo '</div>';
	}

	/** One flagged-source row inside the banner. */
	private static function render_flag_row( WC_Order $source, $ff_id, $on_source_page ) {
		$amount   = fishotel_source_unrefunded_shipping( $source );
		$amount_s = html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		$reason   = sprintf(
			/* translators: %d = fulfillment ID */
			__( 'Combined into fulfillment #FF-%d — duplicate shipping', 'fishotel' ),
			(int) $ff_id
		);
		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS_FLAG . '&order_id=' . $source->get_id() ),
			self::ACTION_DISMISS_FLAG . '_' . $source->get_id()
		);
		?>
		<div style="margin:0 0 10px;">
			<p style="margin:0 0 8px;font-weight:600;">
				<?php
				printf(
					/* translators: 1: source order number, 2: amount */
					esc_html__( 'Source order #%1$s has a %2$s shipping charge. This fulfillment ships as one box, so the duplicate shipping is likely refundable.', 'fishotel' ),
					esc_html( $source->get_order_number() ),
					esc_html( $amount_s )
				);
				?>
			</p>
			<p style="margin:0;">
				<?php if ( $on_source_page ) : ?>
					<button type="button" class="button button-primary" data-fishotel-refund
						data-amount="<?php echo esc_attr( wc_format_decimal( $amount ) ); ?>"
						data-reason="<?php echo esc_attr( $reason ); ?>">
						<?php
						/* translators: %s = amount */
						printf( esc_html__( 'Refund %s', 'fishotel' ), esc_html( $amount_s ) );
						?>
					</button>
				<?php else : ?>
					<a class="button button-primary"
						href="<?php echo esc_url( add_query_arg( 'fishotel_refund_shipping', '1', $source->get_edit_order_url() ) ); ?>">
						<?php
						/* translators: 1: amount, 2: order number */
						printf( esc_html__( 'Refund %1$s (order #%2$s)', 'fishotel' ), esc_html( $amount_s ), esc_html( $source->get_order_number() ) );
						?>
					</a>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'fishotel' ); ?></a>
			</p>
		</div>
		<?php

		if ( $on_source_page ) {
			$auto = isset( $_GET['fishotel_refund_shipping'] ) && '1' === $_GET['fishotel_refund_shipping']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<script>
			( function () {
				function prefill( amount, reason ) {
					var open = document.querySelector( 'button.refund-items' );
					if ( open ) { open.click(); }
					setTimeout( function () {
						var reasonEl = document.querySelector( '#refund_reason' );
						if ( reasonEl ) { reasonEl.value = reason; }
						var ship = document.querySelector( 'input[name^="refund_shipping["]' );
						if ( ship ) {
							ship.value = amount;
							ship.dispatchEvent( new Event( 'change', { bubbles: true } ) );
						} else {
							var amt = document.querySelector( '#refund_amount' );
							if ( amt ) { amt.value = amount; amt.dispatchEvent( new Event( 'change', { bubbles: true } ) ); }
						}
						var items = document.querySelector( '#woocommerce-order-items' );
						if ( items ) { items.scrollIntoView( { behavior: 'smooth' } ); }
					}, 350 );
				}
				var btn = document.querySelector( '[data-fishotel-refund]' );
				if ( btn ) {
					btn.addEventListener( 'click', function () {
						prefill( btn.getAttribute( 'data-amount' ), btn.getAttribute( 'data-reason' ) );
					} );
					<?php if ( $auto ) : ?>
					prefill( btn.getAttribute( 'data-amount' ), btn.getAttribute( 'data-reason' ) );
					<?php endif; ?>
				}
			} )();
			</script>
			<?php
		}
	}

	public static function handle_dismiss_flag() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'fishotel' ), '', [ 'response' => 403 ] );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		check_admin_referer( self::ACTION_DISMISS_FLAG . '_' . $order_id );

		$order    = wc_get_order( $order_id );
		$redirect = $order instanceof WC_Order ? $order->get_edit_order_url() : admin_url();
		if ( $order instanceof WC_Order ) {
			$order->update_meta_data( self::META_FLAG_DISMISSED, '1' );
			$order->add_order_note( __( 'Shipping refund flag dismissed.', 'fishotel' ) );
			$order->save();
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	// ── Sortable Delivery Date column ────────────────────────────────

	public static function add_sortable_delivery_column( $columns ) {
		$columns[ self::DELIVERY_COL ] = self::DELIVERY_COL;
		return $columns;
	}

	public static function sort_delivery_cpt( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( self::DELIVERY_COL === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', self::META_SHIP_DATE );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	public static function sort_delivery_hpos( $args ) {
		if ( isset( $args['orderby'] ) && self::DELIVERY_COL === $args['orderby'] ) {
			$args['meta_key'] = self::META_SHIP_DATE; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = 'meta_value';
		}
		return $args;
	}

	// ── Migration ────────────────────────────────────────────────────

	/**
	 * One-time, idempotent migration of Phase 2 combined orders (primary +
	 * _fishotel_combined_into secondaries) into fulfillments. Version-gated
	 * via an option so it survives self-updates of the theme (where the
	 * after_switch_theme activation hook never re-fires).
	 */
	public static function maybe_migrate() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( '1' === (string) get_option( self::META_MIGRATED, '0' ) ) {
			return;
		}
		try {
			self::run_migration();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Fulfillment migration failed: ' . $e->getMessage(), [ 'source' => 'fishotel-fulfillment' ] );
			}
		}
		// Mark done regardless: a partial migration shouldn't loop on every
		// admin request. Any stragglers can be combined manually.
		update_option( self::META_MIGRATED, '1', false );
	}

	private static function run_migration() {
		global $wpdb;

		// Find every primary referenced by a Phase 2 secondary.
		if ( fishotel_order_hpos_active() ) {
			$table     = $wpdb->prefix . 'wc_orders_meta';
			$primaries = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$table} WHERE meta_key = %s",
				'_fishotel_combined_into'
			) );
		} else {
			$primaries = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_fishotel_combined_into'
			) );
		}

		foreach ( (array) $primaries as $primary_id ) {
			$primary_id = (int) $primary_id;
			$primary    = wc_get_order( $primary_id );
			if ( ! $primary instanceof WC_Order || 'shop_order' !== $primary->get_type() ) {
				continue;
			}
			if ( self::get_fulfillment( $primary ) || self::is_fulfillment( $primary ) ) {
				continue; // Already migrated.
			}

			$secondaries = function_exists( 'fishotel_order_get_secondaries' )
				? fishotel_order_get_secondaries( $primary_id )
				: [];
			$source_ids  = array_merge( [ $primary_id ], array_map( 'intval', $secondaries ) );

			$ff_id = self::create_fulfillment( $source_ids );
			if ( ! $ff_id ) {
				continue;
			}

			// Clear obsolete Phase 2 metas from all sources. First preserve any
			// Phase 2 ship-date backup: a Phase 2 secondary already had its
			// _fishotel_shipping_date cleared (so attach_source above backed up
			// nothing), but its true original date lives in the old backup key.
			// Carry it into the new key so Delete Fulfillment can still restore.
			foreach ( $source_ids as $sid ) {
				$o = wc_get_order( (int) $sid );
				if ( ! $o instanceof WC_Order ) {
					continue;
				}
				$old_backup = (string) $o->get_meta( '_fishotel_combined_orig_ship_date' );
				if ( '' !== $old_backup && '' === (string) $o->get_meta( self::META_ORIG_SHIP ) ) {
					$o->update_meta_data( self::META_ORIG_SHIP, $old_backup );
				}
				$o->delete_meta_data( '_fishotel_combined_into' );
				$o->delete_meta_data( '_fishotel_combined_orig_ship_date' );
				$o->delete_meta_data( '_fishotel_combined_orphan_warned' );
				$o->save();
			}
		}
	}

	// ── Analytics + totals backfill ──────────────────────────────────

	/** Keep fulfillment orders out of WC Analytics revenue. */
	public static function exclude_from_analytics( $statuses ) {
		$statuses   = (array) $statuses;
		$statuses[] = self::STATUS_BARE;
		$statuses[] = self::STATUS;
		return array_values( array_unique( $statuses ) );
	}

	/**
	 * One-time backfill: pre-1.15.1 fulfillments were created with a $0
	 * order total. Recompute each from its sources so the orders-list Total
	 * column is accurate. Version-gated + idempotent.
	 */
	public static function maybe_backfill_totals() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( '1' === (string) get_option( self::META_TOTALS_BACKFILLED, '0' ) ) {
			return;
		}
		try {
			$ids = wc_get_orders( [
				'limit'  => -1,
				'status' => self::STATUS,
				'return' => 'ids',
			] );
			foreach ( (array) $ids as $ff_id ) {
				$f = wc_get_order( (int) $ff_id );
				if ( ! $f instanceof WC_Order || ! self::is_fulfillment( $f ) ) {
					continue;
				}
				$total = self::aggregate_total_from_ids( self::get_sources( $f ) );
				$f->set_total( $total );
				$f->update_meta_data( self::META_TOTAL, wc_format_decimal( $total ) );
				// Backfill display customer fields if missing.
				if ( '' === trim( (string) $f->get_billing_last_name() ) ) {
					$sources = self::get_sources( $f );
					$first   = $sources ? wc_get_order( (int) $sources[0] ) : null;
					if ( $first instanceof WC_Order ) {
						self::copy_customer_fields( $first, $f );
					}
				}
				$f->save();
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Fulfillment totals backfill failed: ' . $e->getMessage(), [ 'source' => 'fishotel-fulfillment' ] );
			}
		}
		update_option( self::META_TOTALS_BACKFILLED, '1', false );
	}

	// ── Scan for Combinable Orders tool ──────────────────────────────

	public static function register_scan_tool() {
		add_submenu_page(
			'fishotel-theme',
			__( 'Scan for Combinable Orders', 'fishotel' ),
			__( 'Scan Combinable', 'fishotel' ),
			'manage_woocommerce',
			self::PAGE_SCAN,
			[ __CLASS__, 'render_scan_page' ]
		);
	}

	/**
	 * Group same-customer processing orders that aren't already fulfilled.
	 * Returns groups with 2+ orders, each tagged with whether all their
	 * shipping addresses match.
	 *
	 * @return array<int,array{key:string,name:string,orders:int[],address_match:bool}>
	 */
	public static function scan_combinable_groups() {
		$ids = wc_get_orders( [
			'limit'   => 200,
			'status'  => [ 'wc-processing' ],
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'ids',
		] );

		$by_customer = [];
		foreach ( (array) $ids as $id ) {
			$o = wc_get_order( (int) $id );
			if ( ! $o instanceof WC_Order || 'shop_order' !== $o->get_type() ) {
				continue;
			}
			if ( self::is_fulfillment( $o ) || self::get_fulfillment( $o ) ) {
				continue;
			}
			$cid = (int) $o->get_customer_id();
			$key = $cid > 0 ? 'cid:' . $cid : 'email:' . strtolower( trim( (string) $o->get_billing_email() ) );
			if ( 'email:' === $key ) {
				continue; // No way to group a guest with no email.
			}
			$by_customer[ $key ][] = $o;
		}

		$groups = [];
		foreach ( $by_customer as $key => $orders ) {
			if ( count( $orders ) < 2 ) {
				continue;
			}
			$hashes = [];
			foreach ( $orders as $o ) {
				$hashes[] = self::ship_hash( $o );
			}
			$first = $orders[0];
			$name  = trim( $first->get_formatted_shipping_full_name() );
			if ( '' === $name ) {
				$name = trim( $first->get_formatted_billing_full_name() );
			}
			$groups[] = [
				'key'           => $key,
				'name'          => $name,
				'orders'        => array_map( static function ( $o ) {
					return $o->get_id();
				}, $orders ),
				'address_match' => 1 === count( array_unique( $hashes ) ),
			];
		}
		return $groups;
	}

	public static function render_scan_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'fishotel' ) );
		}
		$groups   = self::scan_combinable_groups();
		$eligible = array_filter( $groups, static function ( $g ) {
			return $g['address_match'];
		} );
		$differs  = array_filter( $groups, static function ( $g ) {
			return ! $g['address_match'];
		} );
		$done = isset( $_GET['fishotel_combined_count'] ) ? absint( wp_unslash( $_GET['fishotel_combined_count'] ) ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Scan for Combinable Orders', 'fishotel' ); ?></h1>
			<?php if ( $done >= 0 ) : ?>
				<div class="notice notice-success"><p>
					<?php
					printf(
						/* translators: %d = number of fulfillments created */
						esc_html( _n( 'Created %d fulfillment.', 'Created %d fulfillments.', $done, 'fishotel' ) ),
						(int) $done
					);
					?>
				</p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Same-customer processing orders that are not yet part of a fulfillment. Review and combine — same customer does not always mean same shipment.', 'fishotel' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_COMBINE_SELECTED ); ?>">
				<?php wp_nonce_field( self::ACTION_COMBINE_SELECTED ); ?>

				<h2><?php esc_html_e( 'Eligible (matching shipping address)', 'fishotel' ); ?></h2>
				<?php if ( empty( $eligible ) ) : ?>
					<p><em><?php esc_html_e( 'No eligible groups found.', 'fishotel' ); ?></em></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:760px;">
						<thead><tr>
							<td style="width:28px;"></td>
							<th><?php esc_html_e( 'Customer', 'fishotel' ); ?></th>
							<th><?php esc_html_e( 'Orders', 'fishotel' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $eligible as $g ) : ?>
							<tr>
								<td><input type="checkbox" name="groups[]" value="<?php echo esc_attr( implode( ',', $g['orders'] ) ); ?>" checked></td>
								<td><?php echo esc_html( $g['name'] ); ?></td>
								<td>
									<?php
									$nums = array_map( static function ( $id ) {
										$o = wc_get_order( (int) $id );
										return '#' . ( $o instanceof WC_Order ? $o->get_order_number() : $id );
									}, $g['orders'] );
									echo esc_html( implode( ', ', $nums ) );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Combine Selected', 'fishotel' ); ?></button></p>
				<?php endif; ?>
			</form>

			<?php if ( ! empty( $differs ) ) : ?>
				<h2><?php esc_html_e( 'Address differs — review manually', 'fishotel' ); ?></h2>
				<table class="widefat striped" style="max-width:760px;">
					<thead><tr>
						<th><?php esc_html_e( 'Customer', 'fishotel' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'fishotel' ); ?></th>
						<th><?php esc_html_e( 'Note', 'fishotel' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $differs as $g ) : ?>
						<tr>
							<td><?php echo esc_html( $g['name'] ); ?></td>
							<td>
								<?php
								$nums = array_map( static function ( $id ) {
									$o = wc_get_order( (int) $id );
									return '#' . ( $o instanceof WC_Order ? $o->get_order_number() : $id );
								}, $g['orders'] );
								echo esc_html( implode( ', ', $nums ) );
								?>
							</td>
							<td style="color:#b32d2e;"><?php esc_html_e( 'Shipping addresses differ', 'fishotel' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_combine_selected() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'fishotel' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION_COMBINE_SELECTED );

		$groups  = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? wp_unslash( $_POST['groups'] ) : [];
		$created = 0;
		foreach ( $groups as $group ) {
			$ids = array_filter( array_map( 'intval', explode( ',', (string) $group ) ) );
			if ( count( $ids ) < 2 ) {
				continue;
			}
			if ( self::create_fulfillment( $ids ) ) {
				$created++;
			}
		}

		$redirect = add_query_arg(
			[ 'page' => self::PAGE_SCAN, 'fishotel_combined_count' => $created ],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}
}
