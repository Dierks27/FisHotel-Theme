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

class FisHotel_Fulfillment {

	const STATUS              = 'wc-fulfillment';
	const STATUS_BARE         = 'fulfillment';
	const STATUS_COLOR        = '#7d6035';

	const META_IS_FULFILLMENT = '_fishotel_is_fulfillment';
	const META_SOURCES        = '_fishotel_fulfillment_sources';
	const META_FULFILLED_BY   = '_fishotel_fulfilled_by_order';
	const META_ORIG_SHIP      = '_fishotel_fulfillment_orig_ship_date';
	const META_SHIP_DATE      = '_fishotel_shipping_date';
	const META_SOURCE_ORDER   = '_fishotel_source_order'; // on copied line items
	const META_MIGRATED       = 'fishotel_fulfillment_migrated_v1';

	const ACTION_DELETE       = 'fishotel_delete_fulfillment';

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

		// Mirror completion to source orders so each customer's order email
		// fires per WC defaults.
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'mirror_status_to_sources' ], 30, 4 );
		// ShipTracker fires its own action after a shipment-driven transition.
		add_action( 'fst_status_changed', [ __CLASS__, 'on_fst_status_changed' ], 10, 4 );

		// Admin orders list: hide fulfilled sources by default; show toggle.
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_cpt_list_query' ] );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', [ __CLASS__, 'filter_hpos_list_query' ] );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', [ __CLASS__, 'filter_hpos_list_query' ] );
		add_action( 'restrict_manage_posts', [ __CLASS__, 'render_sources_toggle_cpt' ] );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ __CLASS__, 'render_sources_toggle_hpos' ] );

		// Customers never see fulfillments in My Account.
		add_filter( 'woocommerce_my_account_my_orders_query', [ __CLASS__, 'hide_fulfillments_from_account' ] );

		// "Delete Fulfillment" meta box + handler.
		add_action( 'add_meta_boxes_shop_order', [ __CLASS__, 'register_delete_meta_box' ] );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', [ __CLASS__, 'register_delete_meta_box' ] );
		add_action( 'admin_post_' . self::ACTION_DELETE, [ __CLASS__, 'handle_delete' ] );
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
			$f->set_total( 0 );
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
			$f->update_meta_data( self::META_SOURCES, implode( ',', array_values( array_unique( $sources ) ) ) );
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

	/** Legacy CPT orders list: exclude fulfilled sources by default. */
	public static function filter_cpt_list_query( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}
		if ( 'shop_order' !== $query->get( 'post_type' ) ) {
			return;
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

	/** HPOS orders list: exclude fulfilled sources by default. */
	public static function filter_hpos_list_query( $args ) {
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
			printf(
				/* translators: %s = list of order numbers */
				esc_html__( 'This fulfillment bundles orders %s.', 'fishotel' ),
				esc_html( self::numbers_list( $sources ) )
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
}
