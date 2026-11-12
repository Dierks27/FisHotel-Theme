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
 * v1.26.0: when the active ShipTracker records what a box cost to ship
 * (v1.9.17+, detected by capability — FisHotel_Shipment_Cost::supported()),
 * the form also sends the optional label_cost / box_dims / box_weight /
 * label_source, each shipment shows its box's cost with an inline edit that
 * posts to wp_ajax_fst_set_label_cost, and a line under the list compares
 * the shipping paid with the label cost. Against an older ShipTracker none
 * of that is rendered and the box behaves exactly as before.
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

		// Label cost (ShipTracker v1.9.17+). Any failure here only hides the
		// cost controls — the rest of the box must keep working.
		$cost_supported = FisHotel_Shipment_Cost::supported();
		$cost_boxes     = [];
		$cost_sources   = [];
		$cost_cards     = [];
		$cost_summary   = null;
		if ( $cost_supported ) {
			try {
				$view_order_ids = self::view_order_ids( $order, $is_fulfillment );
				$order_boxes    = FisHotel_Shipment_Cost::order_boxes( $view_order_ids );
				$cost_boxes     = FisHotel_Shipment_Cost::standard_boxes();
				$cost_sources   = FisHotel_Shipment_Cost::label_sources();
				$cost_cards     = FisHotel_Shipment_Cost::plan_cards( $shipments, $order_boxes );
				$cost_summary   = FisHotel_Shipment_Cost::view_summary( $view_order_ids, $order_boxes );
			} catch ( \Throwable $e ) {
				$cost_supported = false;
			}
		}

		$tpl = FISHOTEL_THEME_DIR . '/inc/order-fulfillment/templates/shipments-metabox.php';
		if ( file_exists( $tpl ) ) {
			include $tpl;
		}
	}

	/**
	 * The orders whose shipments this view lists: the order itself, or a
	 * fulfillment's source orders (same set as resolve_shipments()).
	 *
	 * @return int[]
	 */
	public static function view_order_ids( WC_Order $order, $is_fulfillment ) {
		if ( $is_fulfillment ) {
			$source_ids = function_exists( 'fishotel_fulfillment_get_sources' )
				? fishotel_fulfillment_get_sources( $order )
				: [];
			return array_values( array_filter( array_map( 'intval', (array) $source_ids ) ) );
		}
		return [ (int) $order->get_id() ];
	}

	/** A decimal amount as WooCommerce price markup, ready to echo. */
	private static function money( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			return wp_kses_post( wc_price( $amount ) );
		}
		return esc_html( number_format( (float) $amount, 2, '.', ',' ) );
	}

	/** "#101, #102" for a list of order ids. */
	private static function order_refs( array $order_ids ) {
		$refs = [];
		foreach ( $order_ids as $oid ) {
			$refs[] = '#' . (int) $oid;
		}
		return implode( ', ', $refs );
	}

	/**
	 * Print the four optional label cost inputs: cost, box size (a dropdown
	 * of ShipTracker's standard boxes plus "Custom…", which reveals a free
	 * LxWxH input), weight and where the label was bought (a dropdown of
	 * ShipTracker's label sources, left out when that list is empty).
	 *
	 * The same markup serves the Add Shipment form (ids fhsm-new-cost,
	 * fhsm-new-box, fhsm-new-dims, fhsm-new-weight, fhsm-new-source) and each
	 * existing shipment's inline edit form; admin-shipments.js reads the
	 * inputs by class from the enclosing .fhsm-cost-fields. Like the rest of
	 * the box they deliberately carry no name attributes, so they are never
	 * posted with the order form itself. autocomplete="off" keeps a browser
	 * from putting the previous box's values back after the reload that
	 * follows a save.
	 *
	 * @param string $prefix  Id prefix: "fhsm-new" or "fhsm-edit-{shipment id}".
	 * @param array  $values  Recorded label_cost / box_dims / box_weight / label_source.
	 * @param array  $boxes   FisHotel_Shipment_Cost::standard_boxes().
	 * @param array  $sources FisHotel_Shipment_Cost::label_sources().
	 */
	public static function render_cost_fields( $prefix, array $values, array $boxes, array $sources ) {
		$is_add = 'fhsm-new' === $prefix;
		$cost   = isset( $values['label_cost'] ) ? (string) $values['label_cost'] : '';
		$dims   = isset( $values['box_dims'] ) ? (string) $values['box_dims'] : '';
		$weight = isset( $values['box_weight'] ) ? FisHotel_Shipment_Cost::trim_decimal( $values['box_weight'] ) : '';
		$source = isset( $values['label_source'] ) ? (string) $values['label_source'] : '';

		// No standard boxes saved yet: the size is typed, so the dropdown
		// stays out of sight on "Custom…".
		$choice     = empty( $boxes ) ? FisHotel_Shipment_Cost::CUSTOM_BOX : FisHotel_Shipment_Cost::box_choice( $dims, $boxes );
		$is_custom  = FisHotel_Shipment_Cost::CUSTOM_BOX === $choice;
		$has_source = ! empty( $sources ) || '' !== $source;
		$currency   = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '$';
		?>
		<div class="fhsm-cost-fields">
			<p class="fhsm-cost-field" data-field="cost">
				<label for="<?php echo esc_attr( $prefix . '-cost' ); ?>">
					<?php
					printf(
						/* translators: %s currency symbol */
						esc_html__( 'Label cost (%s):', 'fishotel' ),
						esc_html( $currency )
					);
					?>
				</label>
				<input type="text" id="<?php echo esc_attr( $prefix . '-cost' ); ?>" class="fhsm-cost-amount" inputmode="decimal" autocomplete="off" placeholder="0.00" value="<?php echo esc_attr( $cost ); ?>">
				<span class="fhsm-field-error" aria-live="polite"></span>
			</p>
			<p class="fhsm-cost-field" data-field="box">
				<label for="<?php echo esc_attr( $prefix . ( empty( $boxes ) ? '-dims' : '-box' ) ); ?>"><?php esc_html_e( 'Box size:', 'fishotel' ); ?></label>
				<select id="<?php echo esc_attr( $prefix . '-box' ); ?>" class="fhsm-cost-box" autocomplete="off"<?php echo empty( $boxes ) ? ' style="display:none;"' : ''; ?>>
					<option value=""><?php esc_html_e( '— Not recorded —', 'fishotel' ); ?></option>
					<?php
					// Two standard boxes of the same size: only the first is preselected.
					$preselected = false;
					foreach ( $boxes as $standard_box ) :
						$is_current  = ! $preselected && $standard_box['dims'] === $choice;
						$preselected = $preselected || $is_current;
						?>
						<option value="<?php echo esc_attr( $standard_box['dims'] ); ?>" <?php selected( $is_current ); ?>>
							<?php echo esc_html( $standard_box['name'] . ' (' . $standard_box['dims'] . ')' ); ?>
						</option>
					<?php endforeach; ?>
					<option value="<?php echo esc_attr( FisHotel_Shipment_Cost::CUSTOM_BOX ); ?>" <?php selected( $is_custom ); ?>><?php esc_html_e( 'Custom…', 'fishotel' ); ?></option>
				</select>
				<input type="text" id="<?php echo esc_attr( $prefix . '-dims' ); ?>" class="fhsm-cost-dims" autocomplete="off"
					placeholder="<?php esc_attr_e( 'L x W x H in inches, e.g. 10x10x10', 'fishotel' ); ?>"
					aria-label="<?php esc_attr_e( 'Custom box size: length x width x height in inches', 'fishotel' ); ?>"
					value="<?php echo esc_attr( $is_custom ? $dims : '' ); ?>"<?php echo $is_custom ? '' : ' style="display:none;"'; ?>>
				<span class="fhsm-field-error" aria-live="polite"></span>
			</p>
			<p class="fhsm-cost-field" data-field="weight">
				<label for="<?php echo esc_attr( $prefix . '-weight' ); ?>"><?php esc_html_e( 'Weight (lb):', 'fishotel' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $prefix . '-weight' ); ?>" class="fhsm-cost-weight" inputmode="decimal" autocomplete="off" value="<?php echo esc_attr( $weight ); ?>">
				<span class="fhsm-field-error" aria-live="polite"></span>
			</p>
			<?php if ( $has_source ) : ?>
				<p class="fhsm-cost-field" data-field="source">
					<label for="<?php echo esc_attr( $prefix . '-source' ); ?>"><?php esc_html_e( 'Bought through:', 'fishotel' ); ?></label>
					<select id="<?php echo esc_attr( $prefix . '-source' ); ?>" class="fhsm-cost-source" autocomplete="off">
						<option value=""><?php esc_html_e( '— Not recorded —', 'fishotel' ); ?></option>
						<?php foreach ( $sources as $saved_source ) : ?>
							<option value="<?php echo esc_attr( $saved_source ); ?>" <?php selected( $saved_source, $source ); ?>><?php echo esc_html( $saved_source ); ?></option>
						<?php endforeach; ?>
						<?php if ( '' !== $source && ! in_array( $source, $sources, true ) ) : ?>
							<?php // Recorded earlier as free text that is not on the list — keep it selectable. ?>
							<option value="<?php echo esc_attr( $source ); ?>" selected><?php echo esc_html( $source ); ?></option>
						<?php endif; ?>
					</select>
					<?php if ( $is_add ) : ?>
						<span class="fhsm-source-hint" hidden><?php esc_html_e( 'Same as last time — change it if this label came from somewhere else.', 'fishotel' ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Print the label cost block of one shipment card: "Label cost $53.73 ·
	 * 10x10x10 · Partner A" (or "No cost recorded" with an Add link) and the
	 * inline form that saves through ShipTracker's fst_set_label_cost.
	 *
	 * A box shared by several orders says so, and when the view lists more
	 * than one card for it (a fulfillment) only the first shows the cost.
	 *
	 * @param int   $shipment_id
	 * @param array $card    FisHotel_Shipment_Cost::plan_cards() entry.
	 * @param array $boxes   FisHotel_Shipment_Cost::standard_boxes().
	 * @param array $sources FisHotel_Shipment_Cost::label_sources().
	 */
	public static function render_shipment_cost( $shipment_id, array $card, array $boxes, array $sources ) {
		$shipment_id = (int) $shipment_id;
		if ( empty( $card['primary'] ) ) {
			echo '<p class="fhsm-cost fhsm-cost-same-box">' . esc_html__( 'Same box as the shipment above — its label cost is shown once.', 'fishotel' ) . '</p>';
			return;
		}

		$box     = isset( $card['box'] ) && is_array( $card['box'] ) ? $card['box'] : [];
		$about   = FisHotel_Shipment_Cost::describe_box( $box, $boxes );
		$form_id = 'fhsm-edit-' . $shipment_id;
		$orders  = isset( $card['order_ids'] ) && is_array( $card['order_ids'] ) ? $card['order_ids'] : [];
		?>
		<div class="fhsm-cost">
			<p class="fhsm-cost-line">
				<?php if ( null !== $about['cost'] ) : ?>
					<span class="fhsm-cost-label"><?php esc_html_e( 'Label cost', 'fishotel' ); ?></span>
					<strong class="fhsm-cost-value"><?php echo self::money( $about['cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
				<?php else : ?>
					<span class="fhsm-cost-none"><?php esc_html_e( 'No cost recorded', 'fishotel' ); ?></span>
				<?php endif; ?>
				<?php foreach ( $about['details'] as $detail ) : ?>
					<span class="fhsm-cost-detail"><span class="fhsm-cost-sep" aria-hidden="true">&middot;</span> <?php echo esc_html( $detail ); ?></span>
				<?php endforeach; ?>
				<button type="button" class="button-link fhsm-cost-edit" aria-expanded="false" aria-controls="<?php echo esc_attr( $form_id ); ?>">
					<?php echo null !== $about['cost'] ? esc_html__( 'Edit', 'fishotel' ) : esc_html__( 'Add', 'fishotel' ); ?>
				</button>
			</p>
			<?php if ( count( $orders ) > 1 ) : ?>
				<p class="fhsm-cost-shared">
					<?php
					printf(
						/* translators: 1: number of orders, 2: list of order ids like "#101, #102" */
						esc_html__( 'One box for %1$d orders (%2$s) — this is the cost of the whole box.', 'fishotel' ),
						count( $orders ),
						esc_html( self::order_refs( $orders ) )
					);
					?>
				</p>
			<?php endif; ?>
			<div class="fhsm-cost-form" id="<?php echo esc_attr( $form_id ); ?>" hidden>
				<?php self::render_cost_fields( $form_id, $box, $boxes, $sources ); ?>
				<p class="fhsm-cost-form-actions">
					<button type="button" class="button button-primary fhsm-cost-save" data-shipment-id="<?php echo esc_attr( $shipment_id ); ?>"><?php esc_html_e( 'Save label cost', 'fishotel' ); ?></button>
					<button type="button" class="button-link fhsm-cost-cancel"><?php esc_html_e( 'Cancel', 'fishotel' ); ?></button>
					<span class="fhsm-message fhsm-cost-message" aria-live="polite"></span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Print the line under the shipments list: the shipping paid, the label
	 * cost recorded, the difference (red when the labels cost more) and how
	 * many boxes have no cost yet.
	 *
	 * @param array $summary FisHotel_Shipment_Cost::view_summary() result.
	 */
	public static function render_cost_summary( array $summary ) {
		$missing = (int) $summary['boxes_without_cost'];
		?>
		<p class="fhsm-cost-totals<?php echo $summary['loss'] ? ' fhsm-cost-totals-loss' : ''; ?>">
			<span class="fhsm-cost-total">
				<?php esc_html_e( 'Shipping paid:', 'fishotel' ); ?>
				<strong><?php echo self::money( $summary['paid'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
				<?php if ( ! empty( $summary['other_order_ids'] ) ) : ?>
					<small>
						<?php
						printf(
							/* translators: 1: amount, 2: list of order ids like "#101, #102" */
							esc_html__( '(includes %1$s paid on %2$s, in the same box)', 'fishotel' ),
							self::money( $summary['paid_others'] ), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money().
							esc_html( self::order_refs( $summary['other_order_ids'] ) )
						);
						?>
					</small>
				<?php endif; ?>
			</span>
			<span class="fhsm-cost-total">
				<?php esc_html_e( 'Label cost recorded:', 'fishotel' ); ?>
				<?php if ( null !== $summary['cost'] ) : ?>
					<strong><?php echo self::money( $summary['cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
				<?php else : ?>
					<em><?php esc_html_e( 'none yet', 'fishotel' ); ?></em>
				<?php endif; ?>
			</span>
			<?php if ( null !== $summary['difference'] ) : ?>
				<span class="fhsm-cost-total fhsm-cost-diff<?php echo $summary['loss'] ? ' fhsm-cost-diff-loss' : ''; ?>">
					<?php
					// While a box still has no cost the difference is only what
					// is left so far — a missing cost is never counted as 0.
					echo $summary['complete'] ? esc_html__( 'Difference:', 'fishotel' ) : esc_html__( 'Difference so far:', 'fishotel' );
					?>
					<strong><?php echo ( (float) $summary['difference'] > 0 ? '+' : '' ) . self::money( $summary['difference'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
				</span>
			<?php endif; ?>
			<?php if ( $missing > 0 ) : ?>
				<span class="fhsm-cost-total fhsm-cost-missing">
					<?php
					printf(
						/* translators: %d number of boxes */
						esc_html( _n( '%d box has no cost yet', '%d boxes have no cost yet', $missing, 'fishotel' ) ),
						(int) $missing
					);
					?>
				</span>
			<?php endif; ?>
		</p>
		<?php
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

	/**
	 * Instantiate the concrete FST_Carrier_* subclass for a carrier slug, or
	 * null. ShipTracker ships UPS + USPS only — it cannot poll, route or link
	 * anything else, so the Add Shipment form offers just those (plus auto).
	 */
	private static function get_carrier_instance( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		switch ( $slug ) {
			case 'ups':
				return class_exists( 'FST_Carrier_UPS' ) ? new FST_Carrier_UPS() : null;
			case 'usps':
				return class_exists( 'FST_Carrier_USPS' ) ? new FST_Carrier_USPS() : null;
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
