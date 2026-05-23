<?php
/**
 * FisHotel Order Fulfillment — Phase 1 partial fulfillment.
 *
 * Live fish orders that also carry EA-fulfilled items (medications, foods)
 * occasionally ship in two pieces: the EA half leaves before the fish, or
 * vice versa. That's rare (~5% of orders), so the default stays exactly as
 * it is today — everything ships together via the normal flow. Granular
 * per-line controls only appear when an admin opts in on a *mixed* order
 * (at least one EA-fulfilled line AND at least one non-EA line).
 *
 * Surface:
 *   - A "Fulfillment Status" meta box on the order screen (legacy + HPOS).
 *     Non-mixed orders just get a one-line note. Mixed orders get a
 *     "Split fulfillment" toggle; flipping it on reveals a per-line table
 *     (Product | Qty | Status | Tracking) without a page reload.
 *   - Save runs on the normal order Update. Unchecking the toggle wipes the
 *     split meta and reverts the order to default behavior.
 *   - When split is on and every line is shipped/na (with at least one
 *     shipped), the order auto-completes.
 *   - A best-effort gate stops an external ShipTracker integration from
 *     completing a split order before all portions have shipped.
 *
 * Meta keys:
 *   _fishotel_split_fulfillment  order meta   '1' or unset
 *   _fishotel_line_status        item meta    pending|shipped|backordered|na
 *   _fishotel_line_tracking      item meta    free text
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is this a mixed order — at least one EA-fulfilled line item AND at least
 * one non-EA line item? Only `line_item` rows count; fees, shipping, and
 * coupons are ignored.
 *
 * @param WC_Order|int $order
 * @return bool
 */
function fishotel_order_is_mixed( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	$has_ea     = false;
	$has_non_ea = false;

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$product_id = (int) $item->get_product_id();
		if ( ! $product_id ) {
			continue;
		}
		$variation_id = (int) $item->get_variation_id();

		if ( fishotel_is_ea_fulfilled_product( $product_id, $variation_id ) ) {
			$has_ea = true;
		} else {
			$has_non_ea = true;
		}
		if ( $has_ea && $has_non_ea ) {
			return true;
		}
	}

	return false;
}

/**
 * If this order is a *secondary* (combined into another), return the
 * primary order ID. Standalone and primary orders return null.
 *
 * @param int|WC_Order $order_id
 * @return int|null
 */
function fishotel_order_get_primary( $order_id ) {
	$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( (int) $order_id );
	if ( ! $order instanceof WC_Order ) {
		return null;
	}
	$primary = (int) $order->get_meta( '_fishotel_combined_into' );
	return $primary > 0 ? $primary : null;
}

/**
 * IDs of the secondary orders combined into this one — i.e. orders whose
 * _fishotel_combined_into meta points at $order_id.
 *
 * Hardened after the v1.14.0 incident, where the previous wc_get_orders()
 * meta_query returned the entire order table (and a refund object) on this
 * store, producing the "690 combined orders" fatal. Two independent
 * safeguards now apply:
 *
 *   1. The lookup is scoped at the SQL level against the correct meta table
 *      (HPOS orders-meta table when HPOS is authoritative, otherwise
 *      wp_postmeta joined to wp_posts WHERE post_type = 'shop_order'). This
 *      keeps it O(matches) — never a full-table scan on every page render.
 *   2. Each candidate is re-loaded and re-verified: it must be a real
 *      WC_Order (never a WC_Order_Refund), of type shop_order, whose
 *      _fishotel_combined_into actually equals $order_id. So even if the
 *      query ever returned a stray row, it could not leak through.
 *
 * On a standalone order (no secondaries) this returns []. Guaranteed.
 *
 * @param int|WC_Order $order_id
 * @return int[]
 */
function fishotel_order_get_secondaries( $order_id ) {
	global $wpdb;

	$primary_id = $order_id instanceof WC_Order ? $order_id->get_id() : (int) $order_id;
	if ( $primary_id <= 0 ) {
		return [];
	}

	$key = '_fishotel_combined_into';

	if ( fishotel_order_hpos_active() ) {
		$table = $wpdb->prefix . 'wc_orders_meta';
		$candidate_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT order_id FROM {$table} WHERE meta_key = %s AND meta_value = %d",
				$key,
				$primary_id
			)
		);
	} else {
		$candidate_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value = %d AND p.post_type = 'shop_order'",
				$key,
				$primary_id
			)
		);
	}

	$secondaries = [];
	foreach ( (array) $candidate_ids as $id ) {
		$order = wc_get_order( (int) $id );
		// Refund-safe: WC_Order_Refund is NOT a WC_Order, so it's excluded
		// here; the type + meta re-check makes a false positive impossible.
		if ( ! $order instanceof WC_Order ) {
			continue;
		}
		if ( 'shop_order' !== $order->get_type() ) {
			continue;
		}
		if ( (int) $order->get_meta( $key ) !== $primary_id ) {
			continue;
		}
		$secondaries[] = $order->get_id();
	}

	return $secondaries;
}

/** True when HPOS (custom orders table) is the authoritative order store. */
function fishotel_order_hpos_active() {
	return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * True when an order can be combined: it's processing or on-hold and not
 * already a secondary of another order.
 *
 * @param int|WC_Order $order_id
 * @return bool
 */
function fishotel_order_is_combinable( $order_id ) {
	$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( (int) $order_id );
	if ( ! $order instanceof WC_Order ) {
		return false;
	}
	if ( fishotel_order_get_primary( $order ) ) {
		return false; // Already a secondary.
	}
	return $order->has_status( [ 'processing', 'on-hold' ] );
}

class FisHotel_Order_Fulfillment {

	const META_SPLIT    = '_fishotel_split_fulfillment';
	const META_STATUS   = '_fishotel_line_status';
	const META_TRACKING = '_fishotel_line_tracking';
	const NONCE_ACTION  = 'fishotel_ff_save';
	const NONCE_FIELD   = 'fishotel_ff_nonce';

	// Phase 2 — order combining.
	const META_COMBINED_INTO = '_fishotel_combined_into';
	const META_ORIG_SHIP     = '_fishotel_combined_orig_ship_date';
	const META_SHIP_DATE     = '_fishotel_shipping_date';
	const META_ORPHAN_WARNED = '_fishotel_combined_orphan_warned';
	const ACTION_COMBINE     = 'fishotel_combine_orders';
	const ACTION_UNCOMBINE   = 'fishotel_uncombine_order';
	const ACTION_SEARCH      = 'fishotel_search_combinable_orders';

	/** Allowed per-line status values. */
	public static function statuses() {
		return [
			'pending'     => __( 'Pending', 'fishotel' ),
			'shipped'     => __( 'Shipped', 'fishotel' ),
			'backordered' => __( 'Backordered', 'fishotel' ),
			'na'          => __( 'N/A', 'fishotel' ),
		];
	}

	public static function init() {
		// Register the meta box on both order screens. Using the
		// screen-specific add_meta_boxes_{screen} hooks (rather than the
		// generic add_meta_boxes) is the HPOS-safe way to target the
		// wc-orders page, and these fire after the generic hook so the box
		// lands below the EA Packing Slip box in the side column.
		add_action( 'add_meta_boxes_shop_order', [ __CLASS__, 'register_meta_box' ] );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', [ __CLASS__, 'register_meta_box' ] );

		// Save handler — fires on the admin order Update for both legacy and
		// HPOS. woocommerce_update_order is included per spec for the HPOS
		// path; the nonce guard + per-request dedupe keep it from running
		// twice or firing on programmatic/REST saves.
		add_action( 'woocommerce_process_shop_order_meta', [ __CLASS__, 'save' ], 20, 1 );
		add_action( 'woocommerce_update_order', [ __CLASS__, 'save' ], 20, 1 );

		// ShipTracker completion gate (two layers — see the gate methods).
		// Layer 1 (preferred): intercept before the save commits so the
		// premature transition — and its customer "completed" email — never
		// fire. Layer 2 (guaranteed net): revert after the transition on the
		// rock-solid woocommerce_order_status_changed action.
		add_action( 'woocommerce_before_order_object_save', [ __CLASS__, 'gate_completion_pre_save' ], 5, 1 );
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'gate_completion' ], 5, 4 );

		// ── Phase 2: order combining ───────────────────────────────────
		add_action( 'add_meta_boxes_shop_order', [ __CLASS__, 'register_combine_meta_box' ] );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', [ __CLASS__, 'register_combine_meta_box' ] );

		add_action( 'admin_post_' . self::ACTION_COMBINE, [ __CLASS__, 'handle_combine' ] );
		add_action( 'admin_post_' . self::ACTION_UNCOMBINE, [ __CLASS__, 'handle_uncombine' ] );
		add_action( 'wp_ajax_' . self::ACTION_SEARCH, [ __CLASS__, 'ajax_search_combinable' ] );

		// When a primary dies (refunded/cancelled/failed), free its secondaries.
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'cleanup_dead_primary' ], 20, 4 );

		// Order list "linked" badge — legacy (CPT) + HPOS list tables.
		add_filter( 'manage_edit-shop_order_columns', [ __CLASS__, 'add_list_column' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ __CLASS__, 'render_list_column_cpt' ], 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ __CLASS__, 'add_list_column' ] );
		add_action( 'woocommerce_shop_order_list_table_custom_column', [ __CLASS__, 'render_list_column_hpos' ], 10, 2 );
	}

	/** Register the "Fulfillment Status" meta box. $screen_or_post varies by screen. */
	public static function register_meta_box( $screen_or_post = null ) {
		$order = self::resolve_order( $screen_or_post );
		// Defensive: suppress the box entirely on orders with no line items.
		if ( $order instanceof WC_Order && ! $order->get_items( 'line_item' ) ) {
			return;
		}
		// On a source order that's been folded into a fulfillment, the
		// operational state lives on the fulfillment — don't show this box.
		if ( $order instanceof WC_Order && fishotel_order_get_fulfillment( $order ) ) {
			return;
		}

		$screen = self::screen_id();
		add_meta_box(
			'fishotel-order-fulfillment',
			__( 'Fulfillment Status', 'fishotel' ),
			[ __CLASS__, 'render_meta_box' ],
			$screen,
			'side',
			'low'
		);
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

	/**
	 * Normalize the meta-box callback argument (WP_Post on legacy, WC_Order
	 * on HPOS) into a WC_Order, or null.
	 *
	 * @param mixed $post_or_order
	 * @return WC_Order|null
	 */
	private static function resolve_order( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
			return $order instanceof WC_Order ? $order : null;
		}
		return null;
	}

	/** Render the meta box body. */
	public static function render_meta_box( $post_or_order ) {
		$order = self::resolve_order( $post_or_order );
		if ( ! $order instanceof WC_Order ) {
			echo '<p style="margin:0;color:#666;">' . esc_html__( 'Order not loaded yet.', 'fishotel' ) . '</p>';
			return;
		}

		$items = $order->get_items( 'line_item' );
		if ( empty( $items ) ) {
			return; // Defensive: shouldn't render with no line items.
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		// Fulfillments flow through the same mixed-detection as regular orders
		// (their copied line items keep product references): mixed → split
		// toggle + per-line table; non-mixed → the single-fulfillment notice.

		// Non-mixed: single-fulfillment note only, no toggle.
		if ( ! fishotel_order_is_mixed( $order ) ) {
			?>
			<div class="fishotel-ff-note notice notice-info inline" style="margin:0;padding:8px 10px;position:relative;">
				<button type="button" class="notice-dismiss" style="padding:0;"
					onclick="this.closest('.fishotel-ff-note').style.display='none';return false;">
					<span class="screen-reader-text"><?php esc_html_e( 'Dismiss', 'fishotel' ); ?></span>
				</button>
				<p style="margin:0;padding-right:18px;">
					<?php esc_html_e( 'This order is single-fulfillment (all items ship together via the normal flow).', 'fishotel' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		$split = ( '1' === (string) $order->get_meta( self::META_SPLIT ) );
		?>
		<div class="fishotel-ff" data-fishotel-ff>
			<p style="margin:0 0 8px;">
				<label style="font-weight:600;">
					<input type="checkbox" name="fishotel_ff_split" value="1"
						<?php checked( $split ); ?> data-fishotel-ff-toggle>
					<?php esc_html_e( 'Split fulfillment', 'fishotel' ); ?>
				</label>
			</p>
			<p class="description" style="margin:0 0 10px;">
				<?php esc_html_e( 'Track EA and live-fish portions separately. Leave off to ship everything together.', 'fishotel' ); ?>
			</p>

			<div class="fishotel-ff-table" data-fishotel-ff-panel
				style="<?php echo $split ? '' : 'display:none;'; ?>overflow-x:auto;">
				<table class="widefat striped" style="font-size:12px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'fishotel' ); ?></th>
							<th style="width:34px;"><?php esc_html_e( 'Qty', 'fishotel' ); ?></th>
							<th><?php esc_html_e( 'Status', 'fishotel' ); ?></th>
							<th><?php esc_html_e( 'Tracking', 'fishotel' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $items as $item_id => $item ) {
							if ( ! $item instanceof WC_Order_Item_Product ) {
								continue;
							}
							self::render_line_row( (int) $item_id, $item );
						}
						?>
					</tbody>
				</table>
			</div>
		</div>
		<script>
		( function () {
			var box = document.querySelector( '#fishotel-order-fulfillment [data-fishotel-ff]' );
			if ( ! box ) { return; }
			var toggle = box.querySelector( '[data-fishotel-ff-toggle]' );
			var panel  = box.querySelector( '[data-fishotel-ff-panel]' );
			if ( ! toggle || ! panel ) { return; }
			toggle.addEventListener( 'change', function () {
				panel.style.display = toggle.checked ? '' : 'none';
			} );
		} )();
		</script>
		<?php
	}

	/** Render one per-line table row. */
	private static function render_line_row( $item_id, WC_Order_Item_Product $item ) {
		$name   = self::line_label( $item );
		$qty    = (int) $item->get_quantity();
		$status = (string) wc_get_order_item_meta( $item_id, self::META_STATUS, true );
		if ( ! array_key_exists( $status, self::statuses() ) ) {
			$status = 'pending';
		}
		$tracking = (string) wc_get_order_item_meta( $item_id, self::META_TRACKING, true );
		?>
		<tr>
			<td><?php echo esc_html( $name ); ?></td>
			<td><?php echo esc_html( (string) $qty ); ?></td>
			<td>
				<select name="fishotel_ff_status[<?php echo esc_attr( $item_id ); ?>]" style="width:100%;">
					<?php foreach ( self::statuses() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<input type="text" name="fishotel_ff_tracking[<?php echo esc_attr( $item_id ); ?>]"
					value="<?php echo esc_attr( $tracking ); ?>"
					placeholder="1Z999AA1..." style="width:100%;">
			</td>
		</tr>
		<?php
	}

	/**
	 * Product label with variation info inline, e.g.
	 * "Freeze Dried Arctic Copepods — 1oz".
	 */
	private static function line_label( WC_Order_Item_Product $item ) {
		$product_id   = (int) $item->get_product_id();
		$variation_id = (int) $item->get_variation_id();

		$parent = $product_id ? wc_get_product( $product_id ) : null;
		$name   = $parent ? $parent->get_name() : $item->get_name();

		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation instanceof WC_Product ) {
				$attrs = $variation->get_attributes();
				if ( is_array( $attrs ) && ! empty( $attrs ) ) {
					$suffix = implode( ' / ', array_map( static function ( $v ) {
						return wp_strip_all_tags( (string) $v );
					}, $attrs ) );
					$suffix = trim( $suffix );
					if ( '' !== $suffix ) {
						$name .= ' — ' . $suffix;
					}
				}
			}
		}

		return $name;
	}

	/**
	 * Save handler. Idempotent per request (dedupe set) and gated on the
	 * meta-box nonce so programmatic / REST saves don't trip it.
	 *
	 * @param int|WC_Order $order_id_or_order
	 */
	public static function save( $order_id_or_order ) {
		// Only act on a real admin order Update carrying our nonce. This also
		// keeps woocommerce_update_order (which fires on any save) inert for
		// programmatic and REST writes.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$order = $order_id_or_order instanceof WC_Order
			? $order_id_or_order
			: wc_get_order( (int) $order_id_or_order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order_id = $order->get_id();

		static $saved = [];
		if ( isset( $saved[ $order_id ] ) ) {
			return;
		}
		$saved[ $order_id ] = true;

		// Only mixed orders ever show the toggle; ignore the rest. (Fulfillments
		// included — a mixed fulfillment uses the same split mechanic.)
		if ( ! fishotel_order_is_mixed( $order ) ) {
			return;
		}

		$checked = ! empty( $_POST['fishotel_ff_split'] );

		if ( ! $checked ) {
			// Toggling off cleanly reverts to default behavior.
			$order->delete_meta_data( self::META_SPLIT );
			$order->save();
			return;
		}

		$order->update_meta_data( self::META_SPLIT, '1' );
		$order->save();

		self::save_line_statuses( $order );
		self::maybe_auto_complete( $order );
	}

	/** Persist each line item's posted status + tracking for an order. */
	private static function save_line_statuses( WC_Order $order ) {
		$statuses = isset( $_POST['fishotel_ff_status'] ) && is_array( $_POST['fishotel_ff_status'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? wp_unslash( $_POST['fishotel_ff_status'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: [];
		$tracking = isset( $_POST['fishotel_ff_tracking'] ) && is_array( $_POST['fishotel_ff_tracking'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? wp_unslash( $_POST['fishotel_ff_tracking'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: [];

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$item_id = (int) $item_id;

			$status = isset( $statuses[ $item_id ] ) ? sanitize_key( $statuses[ $item_id ] ) : 'pending';
			if ( ! array_key_exists( $status, self::statuses() ) ) {
				$status = 'pending';
			}
			$track = isset( $tracking[ $item_id ] ) ? sanitize_text_field( $tracking[ $item_id ] ) : '';

			wc_update_order_item_meta( $item_id, self::META_STATUS, $status );
			wc_update_order_item_meta( $item_id, self::META_TRACKING, $track );
		}
	}

	/**
	 * Auto-complete: when split is on and every line is shipped/na with at
	 * least one shipped, mark the order completed.
	 */
	private static function maybe_auto_complete( WC_Order $order ) {
		if ( '1' !== (string) $order->get_meta( self::META_SPLIT ) ) {
			return;
		}
		if ( $order->has_status( 'completed' ) ) {
			return;
		}
		if ( ! self::all_lines_shipped_or_na( $order, /*require_one_shipped=*/ true ) ) {
			return;
		}
		$order->update_status( 'completed', __( 'FisHotel: all portions shipped.', 'fishotel' ) );
	}

	/**
	 * True when every line item's status is shipped or na. When
	 * $require_one_shipped is true, at least one must be shipped (so an
	 * all-"na" order doesn't auto-complete on nothing).
	 */
	private static function all_lines_shipped_or_na( WC_Order $order, $require_one_shipped = false ) {
		$any_line     = false;
		$any_shipped  = false;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$any_line = true;
			$status   = (string) wc_get_order_item_meta( (int) $item_id, self::META_STATUS, true );
			if ( '' === $status ) {
				$status = 'pending';
			}
			if ( 'shipped' === $status ) {
				$any_shipped = true;
			} elseif ( 'na' !== $status ) {
				return false; // pending or backordered → not done.
			}
		}

		if ( ! $any_line ) {
			return false;
		}
		if ( $require_one_shipped && ! $any_shipped ) {
			return false;
		}
		return true;
	}

	/**
	 * ShipTracker completion gate — Layer 1 (pre-commit).
	 *
	 * ShipTracker (FST_Tracker::process_status_change) calls
	 * $order->set_status( $target ) then $order->save(); it only fires its
	 * own fst_status_changed action *after* the save, so it offers no
	 * pre-commit veto. WC core has none either — set_status() just sets a
	 * prop and the status_transition() (which fires the
	 * woocommerce_order_status_* actions, including the customer "completed"
	 * email) runs inside save().
	 *
	 * woocommerce_before_order_object_save fires inside save() *before* the
	 * persist + transition, so neutralizing the pending "completed" status
	 * here stops the premature completion AND its customer email outright.
	 * The order is held at "processing" with a note. If this hook isn't
	 * available on a given WC build, Layer 2 (gate_completion) still catches
	 * it after the fact. ShipTracker not being installed alongside the
	 * theme, this gate stays inert until that plugin is active.
	 *
	 * @param WC_Order $order
	 */
	public static function gate_completion_pre_save( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( 'completed' !== $order->get_status() ) {
			return;
		}
		if ( '1' !== (string) $order->get_meta( self::META_SPLIT ) ) {
			return;
		}
		if ( self::all_lines_shipped_or_na( $order ) ) {
			return; // Legitimately complete.
		}
		if ( ! self::transition_is_from_shiptracker() ) {
			return; // Only gate ShipTracker-driven completions.
		}

		// Neutralize the pending completion before it commits. Held at
		// processing — the sane state for a split order still awaiting a
		// portion. set_status() here doesn't re-enter this hook.
		$order->set_status( 'processing' );
		$order->add_order_note(
			__( 'FisHotel: ShipTracker completion blocked — not all portions have shipped.', 'fishotel' )
		);
	}

	/**
	 * ShipTracker completion gate — Layer 2 (post-transition safety net).
	 *
	 * Guaranteed-to-fire fallback for WC builds where the pre-save hook
	 * doesn't run: when a split order is transitioned to "completed" by a
	 * ShipTracker-driven call and isn't actually fully shipped, revert it.
	 *
	 * @param int      $order_id
	 * @param string   $status_from
	 * @param string   $status_to
	 * @param WC_Order $order
	 */
	public static function gate_completion( $order_id, $status_from, $status_to, $order ) {
		if ( 'completed' !== $status_to ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( '1' !== (string) $order->get_meta( self::META_SPLIT ) ) {
			return;
		}
		if ( self::all_lines_shipped_or_na( $order ) ) {
			return; // Legitimately complete.
		}
		if ( ! self::transition_is_from_shiptracker() ) {
			return; // Only gate ShipTracker-driven completions.
		}

		// Recursion guard: update_status() below re-enters this action.
		static $reverting = [];
		if ( isset( $reverting[ $order_id ] ) ) {
			return;
		}
		$reverting[ $order_id ] = true;

		$revert_to = $status_from ? $status_from : 'processing';
		$order->update_status(
			$revert_to,
			__( 'FisHotel: ShipTracker completion blocked — not all portions have shipped.', 'fishotel' )
		);

		unset( $reverting[ $order_id ] );
	}

	/**
	 * Best-effort detection of whether the current status transition was
	 * initiated by the ShipTracker plugin. ShipTracker drives completions
	 * through FST_Tracker::process_status_change() (class-fst-tracker.php),
	 * so we scan the call stack for that frame, falling back to any FST_* /
	 * ShipTracker frame.
	 */
	private static function transition_is_from_shiptracker() {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		foreach ( $frames as $frame ) {
			$class    = isset( $frame['class'] ) ? strtolower( (string) $frame['class'] ) : '';
			$function = isset( $frame['function'] ) ? strtolower( (string) $frame['function'] ) : '';
			$file     = isset( $frame['file'] ) ? strtolower( (string) $frame['file'] ) : '';

			if ( 'fst_tracker' === $class || 'process_status_change' === $function ) {
				return true;
			}
			if ( 0 === strpos( $class, 'fst_' )
				|| false !== strpos( $class, 'shiptracker' )
				|| false !== strpos( $file, 'fst-tracker' )
				|| false !== strpos( $file, 'shiptracker' ) ) {
				return true;
			}
		}
		return false;
	}

	// ════════════════════════════════════════════════════════════════
	//  Phase 2 — Order combining
	// ════════════════════════════════════════════════════════════════

	/** Register the "Combine Orders" meta box (side column, default priority). */
	public static function register_combine_meta_box( $screen_or_post = null ) {
		add_meta_box(
			'fishotel-combine-orders',
			__( 'Combine Orders', 'fishotel' ),
			[ __CLASS__, 'render_combine_meta_box' ],
			self::screen_id(),
			'side',
			'default'
		);
	}

	/** Render the "Combine Orders" meta box body — content varies by role. */
	public static function render_combine_meta_box( $post_or_order ) {
		$order = self::resolve_order( $post_or_order );
		if ( ! $order instanceof WC_Order ) {
			echo '<p style="margin:0;color:#666;">' . esc_html__( 'Order not loaded yet.', 'fishotel' ) . '</p>';
			return;
		}

		self::maybe_warn_orphaned_backup( $order );
		self::render_combine_notice();

		$order_id = $order->get_id();

		// Role: this order IS a fulfillment — managed via the Fulfillment box.
		if ( fishotel_is_fulfillment( $order ) ) {
			echo '<p style="margin:0;color:#666;">'
				. esc_html__( 'This is a fulfillment order. Use the Fulfillment box to release its source orders.', 'fishotel' )
				. '</p>';
			return;
		}

		// Role: source folded into a fulfillment.
		$ff_id = fishotel_order_get_fulfillment( $order );
		if ( $ff_id ) {
			$ff   = wc_get_order( $ff_id );
			$link = $ff instanceof WC_Order ? $ff->get_edit_order_url() : '#';
			?>
			<p style="margin:0;">
				<?php
				printf(
					/* translators: %s = fulfillment order link */
					wp_kses_post( __( 'This order is part of fulfillment %s — manage shipping and the EA slip from there.', 'fishotel' ) ),
					'<a href="' . esc_url( $link ) . '"><strong>#FF-' . esc_html( (string) $ff_id ) . '</strong></a>'
				);
				?>
			</p>
			<?php
			return;
		}

		// Role: standalone — offer combine, but only if this order itself is
		// in a combinable state.
		if ( ! fishotel_order_is_combinable( $order ) ) {
			echo '<p style="margin:0;color:#666;">'
				. esc_html__( 'Only processing or on-hold orders can be combined.', 'fishotel' )
				. '</p>';
			return;
		}

		self::render_combine_form( $order );
	}

	/** Standalone-order combine UI: search input + hidden combine form. */
	private static function render_combine_form( WC_Order $order ) {
		$order_id = $order->get_id();
		?>
		<div class="fishotel-combine" data-fishotel-combine>
			<p style="margin:0 0 6px;font-weight:600;"><?php esc_html_e( 'Combine into this order', 'fishotel' ); ?></p>
			<p class="description" style="margin:0 0 8px;">
				<?php esc_html_e( 'Search this customer’s other open orders to ship them together.', 'fishotel' ); ?>
			</p>
			<input type="text" data-combine-search autocomplete="off"
				placeholder="<?php esc_attr_e( 'Search orders…', 'fishotel' ); ?>" style="width:100%;">
			<ul data-combine-results style="margin:6px 0 0;max-height:180px;overflow:auto;display:none;
				border:1px solid #dcdcde;border-radius:3px;list-style:none;padding:0;"></ul>

			<!-- NOT a <form>: this meta box renders inside WP's #post form, and
			     the browser flattens nested forms — a nested <form>'s submit
			     button ends up posting #post to post.php instead. So we keep a
			     plain container and submit via a detached form built in JS
			     (see below), which posts cleanly to admin-post.php. -->
			<div data-combine-form style="margin-top:10px;display:none;">
				<input type="hidden" name="secondary_id" data-combine-secondary value="">
				<p style="margin:0 0 8px;">
					<?php esc_html_e( 'Selected:', 'fishotel' ); ?>
					<strong data-combine-selected-label></strong>
				</p>
				<button type="button" class="button button-primary" data-combine-submit>
					<?php esc_html_e( 'Combine', 'fishotel' ); ?>
				</button>
			</div>
		</div>
		<script>
		( function () {
			var box = document.querySelector( '#fishotel-combine-orders [data-fishotel-combine]' );
			if ( ! box ) { return; }
			var input    = box.querySelector( '[data-combine-search]' );
			var results  = box.querySelector( '[data-combine-results]' );
			var form     = box.querySelector( '[data-combine-form]' );
			var hidden   = box.querySelector( '[data-combine-secondary]' );
			var selLabel = box.querySelector( '[data-combine-selected-label]' );
			var submit   = box.querySelector( '[data-combine-submit]' );
			var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var adminPost = <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;
			var nonce    = <?php echo wp_json_encode( wp_create_nonce( self::ACTION_SEARCH ) ); ?>;
			var combineNonce = <?php echo wp_json_encode( wp_create_nonce( self::ACTION_COMBINE . '_' . $order_id ) ); ?>;
			var combineAction = <?php echo wp_json_encode( self::ACTION_COMBINE ); ?>;
			var orderId  = <?php echo (int) $order_id; ?>;
			var timer    = null;

			function render( items ) {
				results.innerHTML = '';
				if ( ! items.length ) { results.style.display = 'none'; return; }
				items.forEach( function ( it ) {
					var li = document.createElement( 'li' );
					li.textContent = it.label;
					li.style.cssText = 'padding:6px 8px;cursor:pointer;border-bottom:1px solid #f0f0f1;';
					li.addEventListener( 'click', function () {
						hidden.value = it.id;
						selLabel.textContent = it.label;
						form.style.display = '';
						results.style.display = 'none';
						input.value = it.label;
					} );
					results.appendChild( li );
				} );
				results.style.display = '';
			}

			function search() {
				var body = new URLSearchParams();
				body.set( 'action', <?php echo wp_json_encode( self::ACTION_SEARCH ); ?> );
				body.set( 'nonce', nonce );
				body.set( 'order_id', orderId );
				body.set( 'term', input.value );
				fetch( ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) { render( ( res && res.data ) ? res.data : [] ); } )
					.catch( function () { results.style.display = 'none'; } );
			}

			input.addEventListener( 'input', function () {
				form.style.display = 'none';
				clearTimeout( timer );
				timer = setTimeout( search, 250 );
			} );
			input.addEventListener( 'focus', search );

			function addHidden( f, name, value ) {
				var i = document.createElement( 'input' );
				i.type = 'hidden';
				i.name = name;
				i.value = value;
				f.appendChild( i );
			}

			submit.addEventListener( 'click', function () {
				if ( ! hidden.value ) { return; }
				if ( ! window.confirm( 'Combine order ' + selLabel.textContent.split( ' ' )[0] + ' into this order? The shipping date will be transferred.' ) ) {
					return;
				}
				// Build a detached form appended to <body> — outside #post — so
				// it actually posts to admin-post.php instead of being swallowed
				// by the surrounding order-edit form.
				var f = document.createElement( 'form' );
				f.method = 'post';
				f.action = adminPost;
				f.style.display = 'none';
				addHidden( f, 'action', combineAction );
				addHidden( f, 'primary_id', String( orderId ) );
				addHidden( f, 'secondary_id', hidden.value );
				addHidden( f, '_wpnonce', combineNonce );
				document.body.appendChild( f );
				f.submit();
			} );
		} )();
		</script>
		<?php
	}

	/** A nonce-protected GET action button (used for Uncombine). */
	private static function render_action_button( $action, $order_id, $label, $class = 'button-secondary' ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . $action . '&order_id=' . (int) $order_id ),
			$action . '_' . (int) $order_id
		);
		printf(
			'<a class="button %1$s" href="%2$s">%3$s</a>',
			esc_attr( $class ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/** Render the success/error notice after a combine/uncombine redirect. */
	private static function render_combine_notice() {
		$msg = isset( $_GET['fishotel_combine_msg'] ) ? sanitize_key( wp_unslash( $_GET['fishotel_combine_msg'] ) ) : '';
		if ( '' === $msg ) {
			return;
		}
		$map = [
			'combined'     => [ 'success', __( 'Orders combined.', 'fishotel' ) ],
			'uncombined'   => [ 'success', __( 'Order uncombined.', 'fishotel' ) ],
			'err_customer' => [ 'error', __( 'Those orders belong to different customers — cannot combine.', 'fishotel' ) ],
			'err_chain'    => [ 'error', __( 'The target order is itself combined into another — chains are not allowed.', 'fishotel' ) ],
			'err_nested'   => [ 'error', __( 'That order already has combined orders of its own — cannot nest.', 'fishotel' ) ],
			'err_status'   => [ 'error', __( 'Only processing or on-hold orders can be combined.', 'fishotel' ) ],
			'err_generic'  => [ 'error', __( 'Could not combine those orders.', 'fishotel' ) ],
		];
		if ( ! isset( $map[ $msg ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s inline" style="margin:0 0 10px;padding:6px 10px;"><p style="margin:0;">%2$s</p></div>',
			esc_attr( $map[ $msg ][0] ),
			esc_html( $map[ $msg ][1] )
		);
	}

	/**
	 * Combine handler. Folds the selected order and the current order into a
	 * fulfillment (Phase 2.5): if the current order already belongs to one,
	 * the selection is added to it; otherwise a new fulfillment is created
	 * from both. Redirects to the fulfillment — the working surface.
	 */
	public static function handle_combine() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'fishotel' ), '', [ 'response' => 403 ] );
		}
		$primary_id   = isset( $_POST['primary_id'] ) ? absint( wp_unslash( $_POST['primary_id'] ) ) : 0;
		$secondary_id = isset( $_POST['secondary_id'] ) ? absint( wp_unslash( $_POST['secondary_id'] ) ) : 0;
		check_admin_referer( self::ACTION_COMBINE . '_' . $primary_id );

		$current  = wc_get_order( $primary_id );
		$redirect = $current instanceof WC_Order ? $current->get_edit_order_url() : admin_url();

		$error = self::validate_combine( $primary_id, $secondary_id );
		if ( '' !== $error ) {
			self::redirect_with_msg( $redirect, $error );
		}

		$existing = fishotel_order_get_fulfillment( $primary_id );
		if ( $existing ) {
			$ok    = FisHotel_Fulfillment::add_source( $existing, $secondary_id );
			$ff_id = $ok ? $existing : 0;
		} else {
			$ff_id = (int) FisHotel_Fulfillment::create_fulfillment( [ $primary_id, $secondary_id ] );
		}

		if ( ! $ff_id ) {
			self::redirect_with_msg( $redirect, 'err_generic' );
		}

		$ff       = wc_get_order( $ff_id );
		$redirect = $ff instanceof WC_Order ? $ff->get_edit_order_url() : $redirect;
		self::redirect_with_msg( $redirect, 'combined' );
	}

	/**
	 * Validate a combine request. Returns '' when valid, otherwise an error
	 * code understood by render_combine_notice().
	 */
	private static function validate_combine( $primary_id, $secondary_id ) {
		$primary   = wc_get_order( $primary_id );
		$secondary = wc_get_order( $secondary_id );
		if ( ! $primary instanceof WC_Order || ! $secondary instanceof WC_Order || $primary_id === $secondary_id ) {
			return 'err_generic';
		}
		// Same customer: customer_id OR billing_email.
		$same_id    = $primary->get_customer_id() && $primary->get_customer_id() === $secondary->get_customer_id();
		$p_email    = strtolower( trim( (string) $primary->get_billing_email() ) );
		$s_email    = strtolower( trim( (string) $secondary->get_billing_email() ) );
		$same_email = '' !== $p_email && $p_email === $s_email;
		if ( ! $same_id && ! $same_email ) {
			return 'err_customer';
		}
		// Neither order may itself be a fulfillment entity.
		if ( fishotel_is_fulfillment( $primary ) || fishotel_is_fulfillment( $secondary ) ) {
			return 'err_generic';
		}
		// Status: both must be processing/on-hold.
		if ( ! $primary->has_status( [ 'processing', 'on-hold' ] ) || ! $secondary->has_status( [ 'processing', 'on-hold' ] ) ) {
			return 'err_status';
		}
		// The selected order must not already belong to a fulfillment. (The
		// current order may — handle_combine adds the selection to it.)
		if ( fishotel_order_get_fulfillment( $secondary ) ) {
			return 'err_nested';
		}
		return '';
	}

	/** Uncombine handler — initiated from a secondary order's screen. */
	public static function handle_uncombine() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'fishotel' ), '', [ 'response' => 403 ] );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		check_admin_referer( self::ACTION_UNCOMBINE . '_' . $order_id );

		$secondary = wc_get_order( $order_id );
		$redirect  = $secondary instanceof WC_Order ? $secondary->get_edit_order_url() : admin_url();

		self::do_uncombine( $order_id );
		self::redirect_with_msg( $redirect, 'uncombined' );
	}

	/**
	 * Detach a secondary from its primary: restore the backed-up shipping
	 * date, clear the combine metas, and note both orders. Shared by the
	 * manual Uncombine action and the dead-primary auto-cleanup.
	 */
	public static function do_uncombine( $secondary_id ) {
		$secondary = wc_get_order( (int) $secondary_id );
		if ( ! $secondary instanceof WC_Order ) {
			return;
		}
		$primary_id = fishotel_order_get_primary( $secondary );

		$orig = $secondary->get_meta( self::META_ORIG_SHIP );
		if ( '' !== $orig && null !== $orig ) {
			$secondary->update_meta_data( self::META_SHIP_DATE, $orig );
		}
		$secondary->delete_meta_data( self::META_COMBINED_INTO );
		$secondary->delete_meta_data( self::META_ORIG_SHIP );
		$secondary->add_order_note( $primary_id
			? sprintf( /* translators: %s = primary order number */ __( 'Uncombined from #%s.', 'fishotel' ), $primary_id )
			: __( 'Uncombined.', 'fishotel' )
		);
		$secondary->save();

		if ( $primary_id ) {
			$primary = wc_get_order( $primary_id );
			if ( $primary instanceof WC_Order ) {
				$primary->add_order_note( sprintf(
					/* translators: %s = secondary order number */
					__( 'Order #%s uncombined from this one.', 'fishotel' ),
					$secondary->get_order_number()
				) );
				$primary->save();
			}
		}
	}

	/** Free all secondaries when a primary transitions to a dead status. */
	public static function cleanup_dead_primary( $order_id, $status_from, $status_to, $order ) {
		if ( ! in_array( $status_to, [ 'refunded', 'cancelled', 'failed' ], true ) ) {
			return;
		}
		foreach ( fishotel_order_get_secondaries( $order_id ) as $sid ) {
			self::do_uncombine( $sid );
		}
	}

	/**
	 * One-time order note when a shipping-date backup is left orphaned —
	 * i.e. someone cleared _fishotel_combined_into manually (via custom
	 * fields) without going through Uncombine.
	 */
	private static function maybe_warn_orphaned_backup( WC_Order $order ) {
		$has_backup = '' !== (string) $order->get_meta( self::META_ORIG_SHIP );
		$has_link   = '' !== (string) $order->get_meta( self::META_COMBINED_INTO );
		if ( ! $has_backup || $has_link ) {
			return;
		}
		if ( '1' === (string) $order->get_meta( self::META_ORPHAN_WARNED ) ) {
			return;
		}
		$order->add_order_note( __( 'FisHotel: orphaned shipping-date backup found (combine link missing). Use Uncombine to restore cleanly, or clear the backup meta.', 'fishotel' ) );
		$order->update_meta_data( self::META_ORPHAN_WARNED, '1' );
		$order->save();
	}

	/** Redirect back to the order screen carrying a notice code, then exit. */
	private static function redirect_with_msg( $url, $msg ) {
		wp_safe_redirect( add_query_arg( 'fishotel_combine_msg', $msg, $url ) );
		exit;
	}

	/**
	 * AJAX: return up to 20 of this customer's other combinable orders,
	 * optionally filtered by a free-text term against the result label.
	 */
	public static function ajax_search_combinable() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [], 403 );
		}
		check_ajax_referer( self::ACTION_SEARCH, 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$term     = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_success( [] );
		}

		$out = [];
		foreach ( self::customer_combinable_orders( $order ) as $id ) {
			$candidate = wc_get_order( $id );
			if ( ! $candidate instanceof WC_Order ) {
				continue;
			}
			$label = self::order_search_label( $candidate );
			if ( '' !== $term && false === stripos( $label, $term ) ) {
				continue;
			}
			$out[] = [ 'id' => $id, 'label' => $label ];
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		wp_send_json_success( $out );
	}

	/**
	 * Candidate secondary orders for combining into $order: same customer,
	 * processing/on-hold, not this order, and not already part of any
	 * combine relationship. Newest first.
	 *
	 * @return int[]
	 */
	private static function customer_combinable_orders( WC_Order $order ) {
		$base = [
			'limit'   => 40,
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
		}
		$email = (string) $order->get_billing_email();
		if ( '' !== $email ) {
			foreach ( (array) wc_get_orders( $base + [ 'billing_email' => $email ] ) as $id ) {
				$found[ (int) $id ] = true;
			}
		}

		$out = [];
		foreach ( array_keys( $found ) as $id ) {
			// Exclude fulfillment entities and orders already folded into one.
			if ( fishotel_is_fulfillment( $id ) || fishotel_order_get_fulfillment( $id ) ) {
				continue;
			}
			$out[] = $id;
		}
		return $out;
	}

	/** Autocomplete label: "#34195 — May 21, $475.00 — 4 items (Foo, Bar, …)". */
	private static function order_search_label( WC_Order $order ) {
		$date = $order->get_date_created();
		$date = $date ? $date->date_i18n( 'M j' ) : '';

		// get_formatted_order_total() returns HTML with entities (e.g. the
		// currency &#36; and &nbsp;); wp_strip_all_tags() removes the tags but
		// LEAVES the entities, which the JS autocomplete renders literally via
		// textContent ("&#36;475.00"). Decode them so the label reads cleanly.
		$total = self::plain_text( $order->get_formatted_order_total() );

		$names = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$names[] = self::plain_text( $item->get_name() );
		}
		$count   = count( $names );
		$summary = implode( ', ', array_slice( $names, 0, 2 ) );
		if ( $count > 2 ) {
			$summary .= ', …';
		}

		return sprintf(
			'#%s — %s, %s — %d %s (%s)',
			$order->get_order_number(),
			$date,
			$total,
			$count,
			_n( 'item', 'items', $count, 'fishotel' ),
			$summary
		);
	}

	/** Strip tags AND decode HTML entities to a clean plain-text string. */
	private static function plain_text( $html ) {
		return trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	// ── Order list "linked" badge ───────────────────────────────────

	/** Add a compact "🔗" column to the orders list (legacy + HPOS). */
	public static function add_list_column( $columns ) {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_number' === $key ) {
				$new['fishotel_linked'] = '🔗';
			}
		}
		if ( ! isset( $new['fishotel_linked'] ) ) {
			$new['fishotel_linked'] = '🔗';
		}
		return $new;
	}

	/** Legacy (CPT) list-table column renderer. */
	public static function render_list_column_cpt( $column, $post_id ) {
		if ( 'fishotel_linked' === $column ) {
			self::render_list_badge( (int) $post_id );
		}
	}

	/** HPOS list-table column renderer. */
	public static function render_list_column_hpos( $column, $order ) {
		if ( 'fishotel_linked' !== $column ) {
			return;
		}
		$id = $order instanceof WC_Order ? $order->get_id() : (int) $order;
		self::render_list_badge( $id );
	}

	/** Echo the 🔗 badge for an order in the list table. */
	/**
	 * True when a fulfillment has genuine duplicate shipping (2+ unrefunded
	 * sources). One unrefunded source is the legitimate single-box charge
	 * and does NOT light the ⚠️ badge.
	 */
	private static function fulfillment_has_shipping_flag( $fulfillment_id ) {
		if ( ! class_exists( 'FisHotel_Fulfillment' ) ) {
			return false;
		}
		$ff = wc_get_order( (int) $fulfillment_id );
		if ( ! $ff instanceof WC_Order ) {
			return false;
		}
		return ! empty( FisHotel_Fulfillment::duplicate_shipping_sources( $ff ) );
	}

	private static function render_list_badge( $order_id ) {
		// This row IS a fulfillment. The Order column already shows #id, the
		// status badge says "Fulfillment", and the Total column shows the
		// aggregate — so here we just surface the bundle size at a glance,
		// with the bundled order numbers in the tooltip.
		if ( fishotel_is_fulfillment( $order_id ) ) {
			$sources = fishotel_fulfillment_get_sources( $order_id );
			$count   = count( $sources );
			$nums    = [];
			foreach ( $sources as $sid ) {
				$src    = wc_get_order( (int) $sid );
				$nums[] = '#' . ( $src instanceof WC_Order ? $src->get_order_number() : $sid );
			}
			$tip = sprintf(
				/* translators: %s = order numbers */
				__( 'Fulfillment of orders %s', 'fishotel' ),
				implode( ', ', $nums )
			);
			printf(
				'<strong title="%s">🔗 %d %s</strong>',
				esc_attr( $tip ),
				(int) $count,
				esc_html( _n( 'source', 'sources', $count, 'fishotel' ) )
			);

			// Bright-flag indicator: only when there are genuine duplicates
			// (2+ unrefunded sources). One unrefunded source is the legitimate
			// single-box shipping charge → no badge.
			if ( self::fulfillment_has_shipping_flag( (int) $order_id ) ) {
				$ff    = wc_get_order( (int) $order_id );
				$ffurl = $ff instanceof WC_Order ? $ff->get_edit_order_url() : admin_url();
				printf(
					' <a href="%s" title="%s" style="text-decoration:none;">⚠️</a>',
					esc_url( $ffurl ),
					esc_attr__( 'Shipping refund pending on a source order', 'fishotel' )
				);
			}
			return;
		}

		// This row is a source folded into a fulfillment (only visible with
		// "Show fulfilled sources" on): link to its fulfillment.
		$ff_id = fishotel_order_get_fulfillment( $order_id );
		if ( $ff_id ) {
			$ff  = wc_get_order( $ff_id );
			$url = $ff instanceof WC_Order ? $ff->get_edit_order_url() : admin_url();
			printf(
				'<a href="%s" title="%s">🔗 →FF-%s</a>',
				esc_url( $url ),
				esc_attr__( 'Part of this fulfillment', 'fishotel' ),
				esc_html( (string) $ff_id )
			);
		}
	}
}
