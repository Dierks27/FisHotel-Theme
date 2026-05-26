<?php
/**
 * FisHotel Shipments Meta Box (Phase 3).
 *
 * Renders a single "Shipments" main-column meta box on every shop_order edit
 * page (standalone AND fulfillment), replacing the disconnected Phase 1 per-
 * line tracking UI + the default ShipTracker sidebar widget.
 *
 * The meta box does NOT write to ShipTracker's wp_fst_shipments directly. The
 * "Add Shipment" form POSTs to ShipTracker's existing wp_ajax_fst_add_tracking
 * with a line_item_ids[] array; ShipTracker v1.9.1+ handles fan-out for
 * fulfillments, status transitions, and customer emails (subject + items
 * filtered per shipment).
 *
 * Kill switch: define( 'FISHOTEL_NEW_SHIPMENTS_UI_OFF', true ) in wp-config.php
 * disables this UI and restores Phase 1 + the default ShipTracker widget.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Shipments_Metabox {

	const NONCE_ACTION = 'fst_nonce';
	const NONCE_FIELD  = 'fhsm_nonce';

	public static function init() {
		if ( self::kill_switch_on() ) {
			return;
		}
		add_action( 'add_meta_boxes_shop_order', [ __CLASS__, 'register' ], 30 );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', [ __CLASS__, 'register' ], 30 );
		add_action( 'add_meta_boxes_shop_order', [ __CLASS__, 'hide_default_shiptracker_box' ], 35 );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', [ __CLASS__, 'hide_default_shiptracker_box' ], 35 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	public static function kill_switch_on() {
		return defined( 'FISHOTEL_NEW_SHIPMENTS_UI_OFF' ) && FISHOTEL_NEW_SHIPMENTS_UI_OFF;
	}

	/** Resolve the order arg passed to add_meta_boxes_{screen} (WP_Post on CPT, WC_Order on HPOS). */
	private static function resolve_order( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			$o = wc_get_order( $post_or_order->ID );
			return $o instanceof WC_Order ? $o : null;
		}
		return null;
	}

	/** The current order edit screen id (HPOS-aware). */
	private static function screen_id() {
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$hpos = wc_get_page_screen_id( 'shop-order' );
			if ( function_exists( 'get_current_screen' ) ) {
				$current = get_current_screen();
				if ( $current && $current->id === $hpos ) {
					return $hpos;
				}
			}
		}
		return 'shop_order';
	}

	public static function register( $screen_or_post = null ) {
		$order = self::resolve_order( $screen_or_post );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// Render on every shop_order, including source orders folded into a
		// fulfillment. After ShipTracker fan-out, each source carries its own
		// wp_fst_shipments row — viewing the source order needs to surface
		// that shipment + its tracking link, not hide the box entirely.
		add_meta_box(
			'fishotel-shipments',
			__( 'Shipments', 'fishotel' ),
			[ __CLASS__, 'render' ],
			self::screen_id(),
			'normal',
			'high'
		);
	}

	/** Hide ShipTracker's default sidebar widget when the new UI is active. */
	public static function hide_default_shiptracker_box() {
		remove_meta_box( 'fst-shipment-tracking', self::screen_id(), 'side' );
	}

	public static function enqueue_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$relevant = in_array( $screen->id, [ 'shop_order' ], true )
			|| ( function_exists( 'wc_get_page_screen_id' ) && $screen->id === wc_get_page_screen_id( 'shop-order' ) );
		if ( ! $relevant ) {
			return;
		}
		$ver = function_exists( 'fishotel_asset_version' )
			? fishotel_asset_version( 'assets/js/admin-shipments.js' )
			: FISHOTEL_THEME_VERSION;
		wp_enqueue_script(
			'fishotel-admin-shipments',
			FISHOTEL_THEME_URI . '/assets/js/admin-shipments.js',
			[ 'jquery' ],
			$ver,
			true
		);
		$css_ver = function_exists( 'fishotel_asset_version' )
			? fishotel_asset_version( 'assets/css/admin-shipments.css' )
			: FISHOTEL_THEME_VERSION;
		wp_enqueue_style(
			'fishotel-admin-shipments',
			FISHOTEL_THEME_URI . '/assets/css/admin-shipments.css',
			[],
			$css_ver
		);
	}

	public static function render( $post_or_order ) {
		$order = self::resolve_order( $post_or_order );
		if ( ! $order instanceof WC_Order ) {
			echo '<p>' . esc_html__( 'Could not load order.', 'fishotel' ) . '</p>';
			return;
		}

		$is_fulfillment   = function_exists( 'fishotel_is_fulfillment' ) && fishotel_is_fulfillment( $order );
		$items_by_source  = self::resolve_displayable_items( $order, $is_fulfillment );
		$shipments        = self::resolve_shipments( $order, $is_fulfillment );
		$legacy_shipments = self::resolve_phase1_legacy_shipments( $order, $is_fulfillment );
		$covered_ids      = self::get_covered_item_ids( $order, $is_fulfillment );

		$tpl = FISHOTEL_THEME_DIR . '/inc/order-fulfillment/templates/shipments-metabox.php';
		if ( file_exists( $tpl ) ) {
			include $tpl;
		}
	}

	/** Items to show grouped by source order. Standalone → just this order. */
	public static function resolve_displayable_items( WC_Order $order, $is_fulfillment ) {
		if ( $is_fulfillment ) {
			$source_ids = function_exists( 'fishotel_fulfillment_get_sources' )
				? fishotel_fulfillment_get_sources( $order )
				: [];
			$grouped = [];
			foreach ( $source_ids as $sid ) {
				$source = wc_get_order( (int) $sid );
				if ( ! $source instanceof WC_Order ) {
					continue;
				}
				$grouped[ (int) $sid ] = [
					'order' => $source,
					'items' => $source->get_items( 'line_item' ),
				];
			}
			return $grouped;
		}
		return [
			$order->get_id() => [
				'order' => $order,
				'items' => $order->get_items( 'line_item' ),
			],
		];
	}

	/** Resolve existing wp_fst_shipments rows for this view. */
	public static function resolve_shipments( WC_Order $order, $is_fulfillment ) {
		if ( ! class_exists( 'FST_Shipment' ) ) {
			return [];
		}
		if ( $is_fulfillment ) {
			$source_ids = function_exists( 'fishotel_fulfillment_get_sources' )
				? fishotel_fulfillment_get_sources( $order )
				: [];
			$all = [];
			foreach ( $source_ids as $sid ) {
				$rows = self::call_fst_shipment_get_by_order( (int) $sid );
				foreach ( $rows as $row ) {
					$all[] = $row;
				}
			}
			return $all;
		}
		return self::call_fst_shipment_get_by_order( $order->get_id() );
	}

	private static function call_fst_shipment_get_by_order( $order_id ) {
		if ( ! method_exists( 'FST_Shipment', 'get_by_order' ) ) {
			return [];
		}
		$rows = FST_Shipment::get_by_order( (int) $order_id );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Detect Phase 1 per-line tracking metadata and synthesize virtual
	 * legacy shipments. Phase 1 stored:
	 *   - line item meta: _fishotel_line_status (e.g. "shipped")
	 *   - line item meta: _fishotel_line_tracking (the tracking number)
	 * Groups items by tracking number to produce virtual shipment records that
	 * can be displayed read-only.
	 *
	 * Skips any tracking number already represented by a real wp_fst_shipments
	 * row on this order — the real shipment renders that tracking, with items
	 * reconciled via the Phase 1 metadata (see resolve_shipment_items()), so a
	 * separate "LEGACY (Phase 1)" block would duplicate it.
	 */
	public static function resolve_phase1_legacy_shipments( WC_Order $order, $is_fulfillment ) {
		$orders_to_scan = [];
		if ( $is_fulfillment ) {
			$source_ids = function_exists( 'fishotel_fulfillment_get_sources' )
				? fishotel_fulfillment_get_sources( $order )
				: [];
			foreach ( $source_ids as $sid ) {
				$src = wc_get_order( (int) $sid );
				if ( $src instanceof WC_Order ) {
					$orders_to_scan[] = $src;
				}
			}
		} else {
			$orders_to_scan = [ $order ];
		}

		// Tracking numbers already represented by real ShipTracker rows.
		$real_tracking = [];
		foreach ( self::resolve_shipments( $order, $is_fulfillment ) as $s ) {
			$tn = isset( $s->tracking_number ) ? (string) $s->tracking_number : '';
			if ( '' !== $tn ) {
				$real_tracking[ $tn ] = true;
			}
		}

		$by_tracking = [];
		foreach ( $orders_to_scan as $o ) {
			foreach ( $o->get_items( 'line_item' ) as $item_id => $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$status   = (string) $item->get_meta( '_fishotel_line_status' );
				$tracking = (string) $item->get_meta( '_fishotel_line_tracking' );
				if ( 'shipped' !== $status || '' === $tracking ) {
					continue;
				}
				// Skip when a real shipment already carries this tracking number —
				// it'll be rendered (and item-reconciled) by the real-shipment branch.
				if ( isset( $real_tracking[ $tracking ] ) ) {
					continue;
				}
				if ( ! isset( $by_tracking[ $tracking ] ) ) {
					$by_tracking[ $tracking ] = [
						'tracking_number' => $tracking,
						'order_id'        => $o->get_id(),
						'item_ids'        => [],
						'items'           => [],
					];
				}
				$by_tracking[ $tracking ]['item_ids'][] = (int) $item_id;
				$by_tracking[ $tracking ]['items'][]   = $item;
			}
		}

		return array_values( $by_tracking );
	}

	/**
	 * Phase 1 per-line shipped items on a specific order matching a given
	 * tracking number. Used to reconcile legacy NULL-line_item_ids shipments
	 * with Phase 1 metadata so the UI can disable already-shipped checkboxes
	 * accurately without falsely covering items that physically did NOT ship.
	 *
	 * @return int[] order item IDs.
	 */
	public static function get_phase1_shipped_items_for_tracking( WC_Order $order, $tracking_number ) {
		$out      = [];
		$tracking = (string) $tracking_number;
		if ( '' === $tracking ) {
			return $out;
		}
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$status = (string) $item->get_meta( '_fishotel_line_status' );
			$track  = (string) $item->get_meta( '_fishotel_line_tracking' );
			if ( 'shipped' === $status && $track === $tracking ) {
				$out[] = (int) $item_id;
			}
		}
		return $out;
	}

	/**
	 * All line item IDs already covered by ANY shipment (real or legacy).
	 * Used to disable checkboxes in the "Add Shipment" form so the same item
	 * can't be put in two shipments by accident.
	 *
	 * Hybrid reconciliation: when a real shipment has NULL line_item_ids
	 * (created before ShipTracker v1.9.0), the strict "covers all" reading
	 * over-counts on orders where only a subset physically shipped under
	 * that tracking number. Phase 1 per-line metadata on the same order with
	 * the same tracking number is the more precise source of truth, so we
	 * use it when it exists. Pure legacy orders (NULL ids, no Phase 1 meta)
	 * still fall back to "covers all".
	 */
	public static function get_covered_item_ids( WC_Order $order, $is_fulfillment ) {
		$covered = [];

		foreach ( self::resolve_shipments( $order, $is_fulfillment ) as $s ) {
			$line_ids = isset( $s->line_item_ids ) ? $s->line_item_ids : null;
			if ( ! empty( $line_ids ) ) {
				$ids = is_array( $line_ids ) ? $line_ids : json_decode( (string) $line_ids, true );
				if ( is_array( $ids ) ) {
					foreach ( $ids as $cid ) {
						$covered[] = (int) $cid;
					}
				}
				continue;
			}

			$source_id     = isset( $s->order_id ) ? (int) $s->order_id : 0;
			$covered_order = $source_id ? wc_get_order( $source_id ) : null;
			if ( ! $covered_order instanceof WC_Order ) {
				continue;
			}

			$tracking     = isset( $s->tracking_number ) ? (string) $s->tracking_number : '';
			$phase1_items = self::get_phase1_shipped_items_for_tracking( $covered_order, $tracking );
			if ( ! empty( $phase1_items ) ) {
				foreach ( $phase1_items as $cid ) {
					$covered[] = (int) $cid;
				}
			} else {
				// Pure legacy: no Phase 1 reconciliation available. Treat as
				// "covers all" — the historical NULL semantic.
				foreach ( $covered_order->get_items( 'line_item' ) as $cid => $citem ) {
					$covered[] = (int) $cid;
				}
			}
		}

		foreach ( self::resolve_phase1_legacy_shipments( $order, $is_fulfillment ) as $legacy ) {
			foreach ( $legacy['item_ids'] as $cid ) {
				$covered[] = (int) $cid;
			}
		}

		return array_values( array_unique( $covered ) );
	}

	/** Filter $items_by_source to just the EA-fulfilled items, flattened. */
	public static function get_ea_items( array $items_by_source ) {
		$ea = [];
		if ( ! function_exists( 'fishotel_is_ea_fulfilled_product' ) ) {
			return $ea;
		}
		foreach ( $items_by_source as $sid => $group ) {
			foreach ( $group['items'] as $item_id => $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$product_id   = (int) $item->get_product_id();
				$variation_id = (int) $item->get_variation_id();
				if ( ! $product_id ) {
					continue;
				}
				if ( fishotel_is_ea_fulfilled_product( $product_id, $variation_id ) ) {
					$ea[] = [
						'source_id' => (int) $sid,
						'item_id'   => (int) $item_id,
						'item'      => $item,
					];
				}
			}
		}
		return $ea;
	}

	/**
	 * Best-effort tracking URL for a shipment row.
	 *
	 * FST_Carrier is abstract — get_tracking_url() is an instance method on a
	 * concrete subclass (FST_Carrier_UPS / FST_Carrier_USPS / ...). Calling
	 * it statically on the base class fatals. Mirror ShipTracker's own
	 * pattern (see class-fst-order.php) and instantiate the right subclass.
	 */
	public static function get_tracking_url( $shipment ) {
		$carrier_slug    = isset( $shipment->carrier ) ? (string) $shipment->carrier : '';
		$tracking_number = isset( $shipment->tracking_number ) ? (string) $shipment->tracking_number : '';
		if ( '' === $tracking_number ) {
			return '#';
		}
		$carrier = self::get_carrier_instance( $carrier_slug );
		if ( ! $carrier ) {
			return '#';
		}
		try {
			$url = $carrier->get_tracking_url( $tracking_number );
		} catch ( \Throwable $e ) {
			return '#';
		}
		return $url ? (string) $url : '#';
	}

	/** Instantiate the concrete FST_Carrier_* subclass for a carrier slug, or null. */
	private static function get_carrier_instance( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		switch ( $slug ) {
			case 'ups':
				return class_exists( 'FST_Carrier_UPS' ) ? new FST_Carrier_UPS() : null;
			case 'usps':
				return class_exists( 'FST_Carrier_USPS' ) ? new FST_Carrier_USPS() : null;
			case 'fedex':
				return class_exists( 'FST_Carrier_FedEx' ) ? new FST_Carrier_FedEx() : null;
			default:
				return null;
		}
	}

	/**
	 * Resolve the WC_Order_Item_Product objects covered by a shipment.
	 *
	 * Same hybrid reconciliation as get_covered_item_ids():
	 *   - line_item_ids JSON present  → use it (modern shipment).
	 *   - line_item_ids NULL + Phase 1 meta on this order with same tracking#
	 *                                 → use the Phase 1 items (reconciliation).
	 *   - line_item_ids NULL, no Phase 1 → return [] so the template renders
	 *                                       the "Covers all items on this
	 *                                       order" note (historical semantic).
	 */
	public static function resolve_shipment_items( $shipment ) {
		$source_id = isset( $shipment->order_id ) ? (int) $shipment->order_id : 0;
		$source    = $source_id ? wc_get_order( $source_id ) : null;
		if ( ! $source instanceof WC_Order ) {
			return [];
		}
		$line_ids = isset( $shipment->line_item_ids ) ? $shipment->line_item_ids : null;

		if ( ! empty( $line_ids ) ) {
			$ids = is_array( $line_ids ) ? $line_ids : json_decode( (string) $line_ids, true );
			if ( ! is_array( $ids ) ) {
				return [];
			}
			$ids   = array_map( 'intval', $ids );
			$items = [];
			foreach ( $source->get_items( 'line_item' ) as $iid => $item ) {
				if ( in_array( (int) $iid, $ids, true ) ) {
					$items[] = $item;
				}
			}
			return $items;
		}

		// NULL line_item_ids: try Phase 1 hybrid reconciliation first.
		$tracking   = isset( $shipment->tracking_number ) ? (string) $shipment->tracking_number : '';
		$phase1_ids = self::get_phase1_shipped_items_for_tracking( $source, $tracking );
		if ( empty( $phase1_ids ) ) {
			return []; // Pure legacy — template renders "Covers all" note.
		}
		$items = [];
		foreach ( $source->get_items( 'line_item' ) as $iid => $item ) {
			if ( in_array( (int) $iid, $phase1_ids, true ) ) {
				$items[] = $item;
			}
		}
		return $items;
	}
}
