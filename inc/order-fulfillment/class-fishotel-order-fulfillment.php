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

class FisHotel_Order_Fulfillment {

	const META_SPLIT    = '_fishotel_split_fulfillment';
	const META_STATUS   = '_fishotel_line_status';
	const META_TRACKING = '_fishotel_line_tracking';
	const NONCE_ACTION  = 'fishotel_ff_save';
	const NONCE_FIELD   = 'fishotel_ff_nonce';

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

		// ShipTracker completion gate. See gate_completion() for why this is
		// hooked on the real transition action rather than the (non-core)
		// woocommerce_order_status_changing named in the spec.
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'gate_completion' ], 5, 4 );
	}

	/** Register the "Fulfillment Status" meta box. $screen_or_post varies by screen. */
	public static function register_meta_box( $screen_or_post = null ) {
		$order = self::resolve_order( $screen_or_post );
		// Defensive: suppress the box entirely on orders with no line items.
		if ( $order instanceof WC_Order && ! $order->get_items( 'line_item' ) ) {
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

		// Only mixed orders ever show the toggle; ignore the rest.
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

		$statuses = isset( $_POST['fishotel_ff_status'] ) && is_array( $_POST['fishotel_ff_status'] )
			? wp_unslash( $_POST['fishotel_ff_status'] )
			: [];
		$tracking = isset( $_POST['fishotel_ff_tracking'] ) && is_array( $_POST['fishotel_ff_tracking'] )
			? wp_unslash( $_POST['fishotel_ff_tracking'] )
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

		self::maybe_auto_complete( $order );
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
	 * ShipTracker completion gate.
	 *
	 * Spec intent: when an external ShipTracker integration tries to flip a
	 * split order to "completed" before every portion has shipped, block it.
	 *
	 * The spec names woocommerce_order_status_changing, but that hook does
	 * not exist in WooCommerce core — WC_Order::set_status() only sets a
	 * prop, and the actual status_transition() (which fires the
	 * woocommerce_order_status_* actions) runs inside save(), with no
	 * pre-commit veto point. So we hook the real, reliable
	 * woocommerce_order_status_changed action and enforce by reverting the
	 * status when the transition was driven by ShipTracker and the order
	 * isn't actually fully shipped. ShipTracker not being present in this
	 * codebase, this gate simply never fires until that integration lands.
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
	 * initiated by the ShipTracker integration (class-fst-tracker.php).
	 * Scans the call stack for an FST/ShipTracker frame.
	 */
	private static function transition_is_from_shiptracker() {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		foreach ( $frames as $frame ) {
			$class = isset( $frame['class'] ) ? strtolower( (string) $frame['class'] ) : '';
			$file  = isset( $frame['file'] ) ? strtolower( (string) $frame['file'] ) : '';
			if ( false !== strpos( $class, 'fst' )
				|| false !== strpos( $class, 'shiptracker' )
				|| false !== strpos( $file, 'fst-tracker' )
				|| false !== strpos( $file, 'shiptracker' ) ) {
				return true;
			}
		}
		return false;
	}
}
