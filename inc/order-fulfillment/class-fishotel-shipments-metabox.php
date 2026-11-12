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
 * Packing (ShipTracker v1.9.18+, FisHotel_Shipment_Cost::packing_supported()):
 * choosing a box in the Add Shipment form opens a "Packing" block pre-filled
 * with ShipTracker's usual materials for that box and this order (the box
 * itself, a bag per fish, a heat pack or ice packs when the weather check
 * asks for them, the foam and paper amounts), sent as `materials`; each
 * shipment shows its materials and full cost (label + materials) and edits
 * them in the same inline form; and an order with no shipment gets "How it
 * went out": with a label, as a local pickup, or in another order's box —
 * saved through wp_ajax_fst_set_ship_mode. The only request the theme
 * answers itself is the read-only order lookup behind that last choice
 * (wp_ajax_fishotel_ship_with_lookup). Against ShipTracker v1.9.17 the box
 * is the v1.26.0 label cost box, unchanged.
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
		add_action( 'wp_ajax_fishotel_ship_with_lookup', [ __CLASS__, 'ajax_ship_with_lookup' ] );
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
		$view_order_ids = [];
		$order_boxes    = [];
		if ( $cost_supported ) {
			try {
				$view_order_ids = self::view_order_ids( $order, $is_fulfillment );
				$order_boxes    = FisHotel_Shipment_Cost::order_boxes( $view_order_ids );
				$cost_boxes     = FisHotel_Shipment_Cost::standard_boxes();
				$cost_sources   = FisHotel_Shipment_Cost::label_sources();
				$cost_cards     = FisHotel_Shipment_Cost::plan_cards( $shipments, $order_boxes );
			} catch ( \Throwable $e ) {
				$cost_supported = false;
			}
		}

		// Packing and "How it went out" (ShipTracker v1.9.18+). A failure
		// here leaves the label cost box of v1.26.0 standing.
		$packing = null;
		if ( $cost_supported && FisHotel_Shipment_Cost::packing_supported() ) {
			try {
				$packing = self::packing_view( $order, $is_fulfillment, $items_by_source, $shipments, $cost_cards, $covered_ids, $order_boxes );
			} catch ( \Throwable $e ) {
				$packing = null;
			}
		}

		if ( $cost_supported ) {
			try {
				$cost_summary = FisHotel_Shipment_Cost::view_summary( $view_order_ids, $order_boxes, $packing ? $packing['riders'] : [] );
			} catch ( \Throwable $e ) {
				$cost_supported = false;
				$packing        = null;
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

	/**
	 * What the packing blocks and "How it went out" show for this view.
	 *
	 * The usual packing comes from ShipTracker — its defaults for a number
	 * of fish and a weather action — and is asked for once per combination:
	 * the Add Shipment form (the items not shipped yet, the order's weather
	 * action), each box already on the order (its own fish, its order's
	 * weather action) and a local pickup (every item, no pack).
	 *
	 * @return array {
	 *     @type array[]  $supplies  FisHotel_Shipment_Cost::supplies().
	 *     @type array    $defaults  Key => FisHotel_Shipment_Cost::default_materials().
	 *     @type string[] $item_fish Order item id => the fish that line puts in a box ("2").
	 *     @type array    $add       { key, action, label, record } for the Add Shipment form.
	 *     @type array    $cards     Shipment id => the same, for the first card of each box.
	 *     @type array    $pickup    The same, for a local pickup ([] when the order has shipments).
	 *     @type array    $ship_mode FisHotel_Shipment_Cost::ship_mode() of the order on screen.
	 *     @type array    $with      describe_host() of the order it went with, else [].
	 *     @type int[]    $riders    Orders that went in a box of this view.
	 * }
	 *
	 * @param WC_Order $order           The order on screen.
	 * @param bool     $is_fulfillment  It is a fulfillment order.
	 * @param array    $items_by_source resolve_displayable_items().
	 * @param array    $shipments       resolve_shipments().
	 * @param array    $cost_cards      FisHotel_Shipment_Cost::plan_cards().
	 * @param int[]    $covered_ids     get_covered_item_ids().
	 * @param array    $order_boxes     FisHotel_Shipment_Cost::order_boxes() for the view.
	 */
	public static function packing_view( WC_Order $order, $is_fulfillment, array $items_by_source, array $shipments, array $cost_cards, array $covered_ids, array $order_boxes ) {
		$view = [
			'supplies'  => FisHotel_Shipment_Cost::supplies(),
			'defaults'  => [],
			'item_fish' => [],
			'add'       => [],
			'cards'     => [],
			'pickup'    => [],
			'ship_mode' => FisHotel_Shipment_Cost::ship_mode( $order ),
			'with'      => [],
			'riders'    => [],
		];

		// The fish each order line puts in a box, and how many are still to be boxed.
		$open_fish = 0.0;
		$all_fish  = 0.0;
		foreach ( $items_by_source as $group ) {
			$source = isset( $group['order'] ) && $group['order'] instanceof WC_Order ? $group['order'] : null;
			if ( ! $source ) {
				continue;
			}
			foreach ( $group['items'] as $item_id => $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$item_id   = (int) $item_id;
				$original  = (int) $item->get_quantity();
				$available = max( 0, $original - abs( (int) $source->get_qty_refunded_for_item( $item_id ) ) );
				$fish      = FisHotel_Shipment_Cost::item_fish(
					FisHotel_Shipment_Cost::fish_count( $source, [ $item_id ] ),
					$original,
					$available
				);

				$view['item_fish'][ $item_id ] = FisHotel_Shipment_Cost::fish_text( $fish );
				$all_fish                     += $fish;
				if ( ! in_array( $item_id, $covered_ids, true ) ) {
					$open_fish += $fish;
				}
			}
		}

		$view['add'] = self::packing_context( $view['defaults'], $open_fish, FisHotel_Shipment_Cost::pack_action( $order ) );

		foreach ( $shipments as $shipment ) {
			$shipment_id = is_object( $shipment ) && isset( $shipment->id ) ? (int) $shipment->id : 0;
			if ( ! $shipment_id || empty( $cost_cards[ $shipment_id ]['primary'] ) ) {
				continue;
			}
			$card      = $cost_cards[ $shipment_id ];
			$source_id = isset( $shipment->order_id ) ? (int) $shipment->order_id : 0;
			$order_ids = ! empty( $card['order_ids'] ) ? $card['order_ids'] : [ $source_id ];
			$tracking  = isset( $shipment->tracking_number ) ? (string) $shipment->tracking_number : '';

			$context           = self::packing_context(
				$view['defaults'],
				FisHotel_Shipment_Cost::box_fish_count( $tracking, $order_ids ),
				FisHotel_Shipment_Cost::pack_action( $source_id ? wc_get_order( $source_id ) : null )
			);
			$context['record'] = FisHotel_Shipment_Cost::clean_materials( isset( $card['box']['materials'] ) ? $card['box']['materials'] : null );

			$view['cards'][ $shipment_id ] = $context;
		}

		// A pickup is handed over: bags, no heat or ice pack.
		if ( empty( $shipments ) ) {
			$view['pickup']           = self::packing_context( $view['defaults'], $all_fish, '' );
			$view['pickup']['record'] = $view['ship_mode']['materials'];
		}

		if ( 'with_order' === $view['ship_mode']['mode'] ) {
			$view['with'] = self::describe_host( $view['ship_mode']['with_order_id'], $order->get_id() );
		}

		// Orders that went in a box of this view: marked against the order
		// on screen, an order sharing one of its boxes, one of its source
		// orders, or the fulfillment any of those is in.
		$host_ids       = FisHotel_Shipment_Cost::view_host_ids( self::view_order_ids( $order, $is_fulfillment ), $order_boxes );
		$host_ids[]     = (int) $order->get_id();
		$view['riders'] = FisHotel_Shipment_Cost::rider_ids( array_values( array_unique( $host_ids ) ) );

		return $view;
	}

	/**
	 * One packing block's settings: which of ShipTracker's defaults it is
	 * filled from (asked for once per fish count + weather action, kept in
	 * $defaults under `key`) and the weather action behind them.
	 *
	 * @return array { key, action, label, record (null — set by the caller when one exists) }
	 */
	private static function packing_context( array &$defaults, $fish, $action ) {
		$action = (string) $action;
		$key    = FisHotel_Shipment_Cost::fish_text( $fish ) . '|' . $action;
		if ( ! isset( $defaults[ $key ] ) ) {
			$defaults[ $key ] = FisHotel_Shipment_Cost::default_materials( $fish, $action );
		}
		return [
			'key'    => $key,
			'action' => $action,
			'label'  => FisHotel_Shipment_Cost::weather_label( $action ),
			'record' => null,
		];
	}

	/**
	 * The order another order went with (or is about to): who it is for,
	 * the boxes found on it, and why it cannot be picked when it cannot.
	 *
	 * @param int $host_id The order whose box it went in.
	 * @param int $own_id  The order being marked.
	 * @return array { order_id, number, customer, url, boxes: [ { tracking_number, size, status } ],
	 *               problem ('' when it can be picked), summary }
	 */
	public static function describe_host( $host_id, $own_id ) {
		$host_id = (int) $host_id;
		$host    = $host_id > 0 ? wc_get_order( $host_id ) : null;
		$exists  = $host instanceof WC_Order;
		$state   = FisHotel_Shipment_Cost::ship_mode( $exists ? $host : null );

		$out = [
			'order_id' => $host_id,
			'number'   => (string) $host_id,
			'customer' => '',
			'url'      => '',
			'boxes'    => [],
			'problem'  => FisHotel_Shipment_Cost::host_problem( [
				'own_id'            => (int) $own_id,
				'host_id'           => $host_id,
				'exists'            => $exists,
				'mode'              => $state['mode'],
				'with_order_number' => $state['with_order_number'],
			] ),
			'summary'  => '',
		];
		if ( ! $exists ) {
			return $out;
		}

		$out['number'] = (string) $host->get_order_number();
		$out['url']    = (string) $host->get_edit_order_url();
		foreach ( [ 'get_formatted_billing_full_name', 'get_formatted_shipping_full_name' ] as $getter ) {
			if ( '' === $out['customer'] && method_exists( $host, $getter ) ) {
				$out['customer'] = trim( (string) $host->$getter() );
			}
		}

		$standard = FisHotel_Shipment_Cost::standard_boxes();
		foreach ( FisHotel_Shipment_Cost::order_boxes( FisHotel_Shipment_Cost::host_order_ids( $host ) ) as $box ) {
			$status = isset( $box['status'] ) ? (string) $box['status'] : '';
			if ( '' !== $status && class_exists( 'FST_Carrier' ) && method_exists( 'FST_Carrier', 'get_status_label' ) ) {
				$status = (string) FST_Carrier::get_status_label( $status );
			}
			$out['boxes'][] = [
				'tracking_number' => isset( $box['tracking_number'] ) ? (string) $box['tracking_number'] : '',
				'size'            => FisHotel_Shipment_Cost::size_label( isset( $box['box_dims'] ) ? $box['box_dims'] : '', $standard ),
				'status'          => $status,
			];
		}

		$out['summary'] = FisHotel_Shipment_Cost::host_summary( $out );
		return $out;
	}

	/**
	 * AJAX: look up the order typed into "Went in another order's box" —
	 * read-only, so the customer and the box can be checked before saving.
	 *
	 * POST: nonce (fst_nonce), order_id (the order being marked), with_order
	 * (what was typed). Success data: describe_host(); an order that cannot
	 * be picked is an error with the reason as its message.
	 */
	public static function ajax_ship_with_lookup() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied', 'fishotel' ), 403 );
		}
		if ( ! FisHotel_Shipment_Cost::packing_supported() ) {
			wp_send_json_error( __( 'ShipTracker cannot record this yet — update it first.', 'fishotel' ) );
		}

		$own_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$typed  = isset( $_POST['with_order'] ) && is_scalar( $_POST['with_order'] ) ? sanitize_text_field( wp_unslash( $_POST['with_order'] ) ) : '';

		$found = self::describe_host( FisHotel_Shipment_Cost::order_ref_id( $typed ), $own_id );
		if ( '' !== $found['problem'] ) {
			wp_send_json_error( $found['problem'] );
		}
		wp_send_json_success( $found );
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
	 * follows a save. Each standard box option also carries its name
	 * (data-name): two boxes may share a size, and the packing block is
	 * filled from the one that was picked.
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
						<option value="<?php echo esc_attr( $standard_box['dims'] ); ?>" data-name="<?php echo esc_attr( $standard_box['name'] ); ?>" <?php selected( $is_current ); ?>>
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
	 * Print the cost block of one shipment card: "Label cost $53.73 ·
	 * 10x10x10 · Partner A" (or "No cost recorded" with an Add link) and the
	 * inline form that saves through ShipTracker's fst_set_label_cost.
	 *
	 * With packing (ShipTracker v1.9.18+) the line is the full cost — "Label
	 * $53.73 + materials $6.10 = $59.83" — followed by what went into the
	 * box, and the inline form also holds the packing block.
	 *
	 * A box shared by several orders says so, and when the view lists more
	 * than one card for it (a fulfillment) only the first shows the cost.
	 *
	 * @param int        $shipment_id
	 * @param array      $card    FisHotel_Shipment_Cost::plan_cards() entry.
	 * @param array      $boxes   FisHotel_Shipment_Cost::standard_boxes().
	 * @param array      $sources FisHotel_Shipment_Cost::label_sources().
	 * @param array|null $packing This card's packing_view() entry; null = no packing support.
	 */
	public static function render_shipment_cost( $shipment_id, array $card, array $boxes, array $sources, $packing = null ) {
		$shipment_id = (int) $shipment_id;
		if ( empty( $card['primary'] ) ) {
			echo '<p class="fhsm-cost fhsm-cost-same-box">' . esc_html__( 'Same box as the shipment above — its label cost is shown once.', 'fishotel' ) . '</p>';
			return;
		}

		$box     = isset( $card['box'] ) && is_array( $card['box'] ) ? $card['box'] : [];
		$about   = FisHotel_Shipment_Cost::describe_box( $box, $boxes );
		$form_id = 'fhsm-edit-' . $shipment_id;
		$orders  = isset( $card['order_ids'] ) && is_array( $card['order_ids'] ) ? $card['order_ids'] : [];
		$record  = is_array( $packing ) && isset( $packing['record'] ) && is_array( $packing['record'] ) ? $packing['record'] : null;
		$full    = FisHotel_Shipment_Cost::full_cost( $box );
		?>
		<div class="fhsm-cost">
			<p class="fhsm-cost-line">
				<?php if ( null !== $about['cost'] && null !== $record ) : ?>
					<span class="fhsm-cost-label"><?php esc_html_e( 'Label', 'fishotel' ); ?></span>
					<strong class="fhsm-cost-value"><?php echo self::money( $about['cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
					<span class="fhsm-cost-label"><?php esc_html_e( '+ materials', 'fishotel' ); ?></span>
					<strong class="fhsm-cost-materials"><?php echo self::money( $record['total'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
					<span class="fhsm-cost-label">=</span>
					<strong class="fhsm-cost-full"><?php echo self::money( $full['total'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
				<?php elseif ( null !== $about['cost'] ) : ?>
					<span class="fhsm-cost-label"><?php esc_html_e( 'Label cost', 'fishotel' ); ?></span>
					<strong class="fhsm-cost-value"><?php echo self::money( $about['cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
				<?php elseif ( null !== $record ) : ?>
					<span class="fhsm-cost-none"><?php esc_html_e( 'No label cost recorded', 'fishotel' ); ?></span>
					<span class="fhsm-cost-detail"><span class="fhsm-cost-sep" aria-hidden="true">&middot;</span> <?php esc_html_e( 'materials', 'fishotel' ); ?></span>
					<strong class="fhsm-cost-materials"><?php echo self::money( $record['total'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
				<?php else : ?>
					<span class="fhsm-cost-none"><?php esc_html_e( 'No cost recorded', 'fishotel' ); ?></span>
				<?php endif; ?>
				<?php foreach ( $about['details'] as $detail ) : ?>
					<span class="fhsm-cost-detail"><span class="fhsm-cost-sep" aria-hidden="true">&middot;</span> <?php echo esc_html( $detail ); ?></span>
				<?php endforeach; ?>
				<button type="button" class="button-link fhsm-cost-edit" aria-expanded="false" aria-controls="<?php echo esc_attr( $form_id ); ?>">
					<?php echo null !== $about['cost'] || null !== $record ? esc_html__( 'Edit', 'fishotel' ) : esc_html__( 'Add', 'fishotel' ); ?>
				</button>
			</p>
			<?php if ( is_array( $packing ) ) : ?>
				<p class="fhsm-materials-line<?php echo null === $record ? ' fhsm-materials-none' : ''; ?>">
					<?php self::render_materials_text( $record ); ?>
				</p>
			<?php endif; ?>
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
				<?php if ( is_array( $packing ) ) : ?>
					<?php self::render_packing_block( $form_id, 'edit', $packing ); ?>
				<?php endif; ?>
				<p class="fhsm-cost-form-actions">
					<button type="button" class="button button-primary fhsm-cost-save" data-shipment-id="<?php echo esc_attr( $shipment_id ); ?>">
						<?php echo is_array( $packing ) ? esc_html__( 'Save box cost', 'fishotel' ) : esc_html__( 'Save label cost', 'fishotel' ); ?>
					</button>
					<button type="button" class="button-link fhsm-cost-cancel"><?php esc_html_e( 'Cancel', 'fishotel' ); ?></button>
					<span class="fhsm-message fhsm-cost-message" aria-live="polite"></span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Print what went into a box (or what a local pickup used) in words:
	 * "Packing: 1 × Small box · 6 × Fish bag · foam $1.80 · paper $0.40".
	 *
	 * @param array|null $record FisHotel_Shipment_Cost::clean_materials() record.
	 */
	public static function render_materials_text( $record ) {
		if ( ! is_array( $record ) ) {
			esc_html_e( 'No packing recorded.', 'fishotel' );
			return;
		}

		$parts = FisHotel_Shipment_Cost::materials_parts( $record );
		$words = array_map( 'esc_html', $parts['lines'] );
		if ( null !== $parts['foam'] ) {
			$words[] = esc_html__( 'foam', 'fishotel' ) . ' ' . self::money( $parts['foam'] );
		}
		if ( null !== $parts['paper'] ) {
			$words[] = esc_html__( 'paper', 'fishotel' ) . ' ' . self::money( $parts['paper'] );
		}

		echo '<span class="fhsm-cost-label">' . esc_html__( 'Packing:', 'fishotel' ) . '</span> ';
		if ( empty( $words ) ) {
			esc_html_e( 'nothing used.', 'fishotel' );
			return;
		}
		echo implode( ' <span class="fhsm-cost-sep" aria-hidden="true">&middot;</span> ', $words ); // phpcs:ignore WordPress.Security.EscapeOutput -- every part is escaped above.
	}

	/**
	 * Print a packing block: one line per supply with a quantity, the foam
	 * and the paper cost, and a running total. admin-shipments.js builds the
	 * lines — from the record when there is one, else from ShipTracker's
	 * usual packing for the box picked in the same form — and sends them as
	 * `materials`. Like the rest of the box, no input here has a name.
	 *
	 * @param string $prefix  Id prefix: "fhsm-new", "fhsm-edit-{shipment id}" or "fhsm-pickup".
	 * @param string $kind    add (hidden until a box is picked), edit (closed until asked
	 *                        for when nothing is recorded) or pickup (no box).
	 * @param array  $context packing_view() entry: key, action, label, record.
	 */
	public static function render_packing_block( $prefix, $kind, array $context ) {
		$record   = isset( $context['record'] ) && is_array( $context['record'] ) ? $context['record'] : null;
		$closed   = 'edit' === $kind && null === $record;
		$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '$';
		?>
		<div class="fhsm-packing" data-kind="<?php echo esc_attr( $kind ); ?>" data-prefix="<?php echo esc_attr( $prefix ); ?>"
			data-defaults="<?php echo esc_attr( isset( $context['key'] ) ? $context['key'] : '' ); ?>"
			data-action="<?php echo esc_attr( isset( $context['action'] ) ? $context['action'] : '' ); ?>"
			data-action-label="<?php echo esc_attr( isset( $context['label'] ) ? $context['label'] : '' ); ?>"
			<?php if ( null !== $record ) : ?>
				data-record="<?php echo esc_attr( wp_json_encode( $record ) ); ?>"
			<?php endif; ?>
			<?php echo 'add' === $kind ? 'hidden' : ''; ?>>
			<p class="fhsm-packing-heading">
				<strong><?php esc_html_e( 'Packing', 'fishotel' ); ?></strong>
				<span class="description">
					<?php
					if ( 'pickup' === $kind ) {
						esc_html_e( '(what was used for the pickup — leave it at 0 when nothing was)', 'fishotel' );
					} else {
						esc_html_e( '(what went into the box besides the label)', 'fishotel' );
					}
					?>
				</span>
			</p>
			<?php if ( $closed ) : ?>
				<p class="fhsm-packing-closed">
					<?php esc_html_e( 'No packing recorded for this box.', 'fishotel' ); ?>
					<button type="button" class="button-link fhsm-packing-open"><?php esc_html_e( 'Add packing', 'fishotel' ); ?></button>
				</p>
			<?php endif; ?>
			<div class="fhsm-packing-body"<?php echo $closed ? ' hidden' : ''; ?>>
				<div class="fhsm-cost-field fhsm-packing-lines-field" data-field="packing">
					<table class="fhsm-packing-lines">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Supply', 'fishotel' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Quantity', 'fishotel' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Cost', 'fishotel' ); ?></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
					<span class="fhsm-field-error" aria-live="polite"></span>
				</div>
				<p class="fhsm-packing-add" hidden>
					<label class="screen-reader-text" for="<?php echo esc_attr( $prefix . '-supply' ); ?>"><?php esc_html_e( 'Add a supply to the packing', 'fishotel' ); ?></label>
					<select id="<?php echo esc_attr( $prefix . '-supply' ); ?>" class="fhsm-packing-supply" autocomplete="off">
						<option value=""><?php esc_html_e( 'Add supply…', 'fishotel' ); ?></option>
					</select>
				</p>
				<div class="fhsm-packing-flat">
					<p class="fhsm-cost-field" data-field="foam">
						<label for="<?php echo esc_attr( $prefix . '-foam' ); ?>">
							<?php
							printf(
								/* translators: %s currency symbol */
								esc_html__( 'Foam cost (%s):', 'fishotel' ),
								esc_html( $currency )
							);
							?>
						</label>
						<input type="text" id="<?php echo esc_attr( $prefix . '-foam' ); ?>" class="fhsm-packing-foam" inputmode="decimal" autocomplete="off" placeholder="0.00">
						<span class="fhsm-field-error" aria-live="polite"></span>
					</p>
					<p class="fhsm-cost-field" data-field="paper">
						<label for="<?php echo esc_attr( $prefix . '-paper' ); ?>">
							<?php
							printf(
								/* translators: %s currency symbol */
								esc_html__( 'Paper cost (%s):', 'fishotel' ),
								esc_html( $currency )
							);
							?>
						</label>
						<input type="text" id="<?php echo esc_attr( $prefix . '-paper' ); ?>" class="fhsm-packing-paper" inputmode="decimal" autocomplete="off" placeholder="0.00">
						<span class="fhsm-field-error" aria-live="polite"></span>
					</p>
				</div>
				<p class="fhsm-packing-notes" aria-live="polite"></p>
				<p class="fhsm-packing-foot">
					<span class="fhsm-packing-total"><?php esc_html_e( 'Packing total:', 'fishotel' ); ?> <strong></strong></span>
					<button type="button" class="button-link fhsm-packing-usual">
						<?php echo 'pickup' === $kind ? esc_html__( 'Use the usual', 'fishotel' ) : esc_html__( 'Use the usual for this box', 'fishotel' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Print "How it went out" for an order with no shipment: with a label
	 * (the default — nothing is stored), as a local pickup (with an
	 * optional packing block) or in another order's box (an order number
	 * with a lookup). Saved through ShipTracker's fst_set_ship_mode; what
	 * is stored is shown plainly above the control.
	 *
	 * @param WC_Order $order
	 * @param array    $packing packing_view() result.
	 */
	public static function render_ship_mode( WC_Order $order, array $packing ) {
		$state = $packing['ship_mode'];
		$mode  = $state['mode'];
		$with  = isset( $packing['with'] ) && is_array( $packing['with'] ) ? $packing['with'] : [];
		$when  = '' !== $state['date'] && strtotime( $state['date'] ) ? date_i18n( get_option( 'date_format' ), strtotime( $state['date'] ) ) : '';
		$paid  = FisHotel_Shipment_Cost::shipping_paid( $order );
		?>
		<div class="fhsm-ship-mode" data-mode="<?php echo esc_attr( $mode ); ?>">
			<?php if ( 'local' === $mode ) : ?>
				<?php $pickup = FisHotel_Shipment_Cost::pickup_summary( $paid, $state['materials_total'] ); ?>
				<p class="fhsm-ship-mode-status">
					<strong><?php esc_html_e( 'Local pickup', 'fishotel' ); ?></strong>
					<?php esc_html_e( '— no label, so no label cost.', 'fishotel' ); ?>
					<?php if ( '' !== $when ) : ?>
						<span class="fhsm-ship-mode-when">
							<?php
							printf(
								/* translators: %s date */
								esc_html__( 'Marked %s.', 'fishotel' ),
								esc_html( $when )
							);
							?>
						</span>
					<?php endif; ?>
				</p>
				<p class="fhsm-materials-line<?php echo null === $state['materials'] ? ' fhsm-materials-none' : ''; ?>">
					<?php self::render_materials_text( $state['materials'] ); ?>
				</p>
				<p class="fhsm-cost-totals<?php echo $pickup['loss'] ? ' fhsm-cost-totals-loss' : ''; ?>">
					<span class="fhsm-cost-total">
						<?php esc_html_e( 'Shipping paid:', 'fishotel' ); ?>
						<strong><?php echo self::money( $pickup['paid'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
					</span>
					<span class="fhsm-cost-total">
						<?php esc_html_e( 'Cost (packing only):', 'fishotel' ); ?>
						<strong><?php echo self::money( $pickup['cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
					</span>
					<?php if ( null !== $pickup['difference'] ) : ?>
						<span class="fhsm-cost-total fhsm-cost-diff<?php echo $pickup['loss'] ? ' fhsm-cost-diff-loss' : ''; ?>">
							<?php esc_html_e( 'Difference:', 'fishotel' ); ?>
							<strong><?php echo ( (float) $pickup['difference'] > 0 ? '+' : '' ) . self::money( $pickup['difference'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
						</span>
					<?php endif; ?>
				</p>
			<?php elseif ( 'with_order' === $mode ) : ?>
				<p class="fhsm-ship-mode-status">
					<strong>
						<?php
						printf(
							/* translators: %s order number */
							esc_html__( 'Went in the box of order #%s', 'fishotel' ),
							esc_html( $state['with_order_number'] )
						);
						?>
					</strong>
					<?php esc_html_e( '— no label of its own.', 'fishotel' ); ?>
					<?php if ( '' !== $when ) : ?>
						<span class="fhsm-ship-mode-when">
							<?php
							printf(
								/* translators: %s date */
								esc_html__( 'Marked %s.', 'fishotel' ),
								esc_html( $when )
							);
							?>
						</span>
					<?php endif; ?>
					<?php if ( ! empty( $with['url'] ) ) : ?>
						<a href="<?php echo esc_url( $with['url'] ); ?>"><?php esc_html_e( 'Open that order', 'fishotel' ); ?></a>
					<?php endif; ?>
				</p>
				<p class="fhsm-cost-totals">
					<span class="fhsm-cost-total">
						<?php esc_html_e( 'Shipping paid:', 'fishotel' ); ?>
						<strong><?php echo self::money( null === $paid ? 0 : $paid ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
						<?php
						printf(
							/* translators: %s order number */
							esc_html__( '— counted towards what the box of order #%s cost.', 'fishotel' ),
							esc_html( $state['with_order_number'] )
						);
						?>
					</span>
				</p>
			<?php else : ?>
				<p class="fhsm-empty"><?php esc_html_e( 'No shipments yet.', 'fishotel' ); ?></p>
			<?php endif; ?>

			<p class="fhsm-ship-mode-pick">
				<label for="fhsm-ship-mode"><strong><?php esc_html_e( 'How it went out:', 'fishotel' ); ?></strong></label>
				<select id="fhsm-ship-mode" class="fhsm-ship-mode-select" autocomplete="off">
					<option value="label" <?php selected( $mode, 'label' ); ?>><?php esc_html_e( 'Shipped with a label — add the shipment below', 'fishotel' ); ?></option>
					<option value="local" <?php selected( $mode, 'local' ); ?>><?php esc_html_e( 'Local pickup — no label', 'fishotel' ); ?></option>
					<option value="with_order" <?php selected( $mode, 'with_order' ); ?>><?php esc_html_e( 'Went in another order\'s box', 'fishotel' ); ?></option>
				</select>
			</p>
			<div class="fhsm-ship-mode-local"<?php echo 'local' === $mode ? '' : ' hidden'; ?>>
				<?php if ( ! empty( $packing['pickup'] ) ) : ?>
					<?php self::render_packing_block( 'fhsm-pickup', 'pickup', $packing['pickup'] ); ?>
				<?php endif; ?>
			</div>
			<div class="fhsm-ship-mode-with"<?php echo 'with_order' === $mode ? '' : ' hidden'; ?>>
				<p class="fhsm-cost-field" data-field="with">
					<label for="fhsm-ship-with"><?php esc_html_e( 'Order number of the box it went in:', 'fishotel' ); ?></label>
					<input type="text" id="fhsm-ship-with" class="fhsm-ship-with-order" inputmode="numeric" autocomplete="off" value="<?php echo esc_attr( 'with_order' === $mode ? $state['with_order_id'] : '' ); ?>">
					<button type="button" class="button fhsm-ship-with-find"><?php esc_html_e( 'Find', 'fishotel' ); ?></button>
					<span class="fhsm-field-error" aria-live="polite"></span>
				</p>
				<p class="fhsm-ship-with-result" aria-live="polite"><?php echo esc_html( isset( $with['summary'] ) ? $with['summary'] : '' ); ?></p>
			</div>
			<p class="fhsm-ship-mode-actions"<?php echo 'label' === $mode ? ' hidden' : ''; ?>>
				<button type="button" class="button button-primary fhsm-ship-mode-save"><?php esc_html_e( 'Save', 'fishotel' ); ?></button>
				<span class="fhsm-message fhsm-ship-mode-message" aria-live="polite"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Print the orders that went in a box of this view without a label of
	 * their own — what they paid for shipping counts towards it.
	 *
	 * @param int[] $rider_ids FisHotel_Shipment_Cost::rider_ids() for the view.
	 */
	public static function render_riders( array $rider_ids ) {
		$links = [];
		foreach ( $rider_ids as $rider_id ) {
			$rider = wc_get_order( (int) $rider_id );
			if ( ! $rider instanceof WC_Order ) {
				continue;
			}
			$links[] = '<a href="' . esc_url( $rider->get_edit_order_url() ) . '">#' . esc_html( $rider->get_order_number() ) . '</a>';
		}
		if ( empty( $links ) ) {
			return;
		}
		?>
		<p class="fhsm-riders">
			<?php
			printf(
				/* translators: %s list of linked order numbers */
				esc_html( _n( 'Also in this order\'s box, with no label of its own: %s.', 'Also in this order\'s box, with no label of their own: %s.', count( $links ), 'fishotel' ) ),
				implode( ', ', $links ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			);
			?>
		</p>
		<?php
	}

	/**
	 * Print the line under the shipments list: the shipping paid, what the
	 * boxes cost, the difference (red when they cost more) and how many
	 * boxes have no cost yet.
	 *
	 * The cost is the label cost; once a box has packing recorded it is the
	 * full cost, with its two parts named ("label $53.73 + materials $6.10").
	 * When nothing was paid towards the boxes the line says so instead of
	 * showing a loss: "Shipped free: this box cost $59.83".
	 *
	 * @param array $summary FisHotel_Shipment_Cost::view_summary() result.
	 */
	public static function render_cost_summary( array $summary ) {
		$missing = (int) $summary['boxes_without_cost'];
		$boxes   = isset( $summary['boxes'] ) ? (int) $summary['boxes'] : 0;
		$free    = ! empty( $summary['free'] );
		$split   = ! empty( $summary['boxes_with_materials'] ) && null !== $summary['cost'];
		$loss    = $summary['loss'] && ! $free;
		$waiting = empty( $summary['materials_without_cost'] ) ? 0.0 : (float) $summary['materials_without_cost'];
		?>
		<p class="fhsm-cost-totals<?php echo $loss ? ' fhsm-cost-totals-loss' : ''; ?>">
			<?php if ( $free ) : ?>
				<span class="fhsm-cost-total fhsm-cost-free">
					<?php
					if ( null === $summary['cost'] ) {
						esc_html_e( 'Shipped free — no label cost recorded yet.', 'fishotel' );
					} elseif ( ! $summary['complete'] ) {
						printf(
							/* translators: %s amount */
							esc_html__( 'Shipped free: the boxes cost %s so far', 'fishotel' ),
							'<strong>' . self::money( $summary['cost'] ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money().
						);
					} else {
						printf(
							/* translators: 1: amount, 2: number of boxes */
							esc_html( _n( 'Shipped free: this box cost %1$s', 'Shipped free: these %2$d boxes cost %1$s', $boxes, 'fishotel' ) ),
							'<strong>' . self::money( $summary['cost'] ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money().
							(int) $boxes
						);
					}
					?>
					<?php if ( $split ) : ?>
						<small><?php self::render_cost_split( $summary ); ?></small>
					<?php endif; ?>
				</span>
			<?php else : ?>
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
					<?php echo $split ? esc_html__( 'Cost:', 'fishotel' ) : esc_html__( 'Label cost recorded:', 'fishotel' ); ?>
					<?php if ( null !== $summary['cost'] ) : ?>
						<strong><?php echo self::money( $summary['cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money(). ?></strong>
						<?php if ( $split ) : ?>
							<small><?php self::render_cost_split( $summary ); ?></small>
						<?php endif; ?>
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
					<?php if ( $waiting > 0 ) : ?>
						<small>
							<?php
							printf(
								/* translators: %s amount */
								esc_html__( '(%s of materials recorded — counted once the label cost is in)', 'fishotel' ),
								self::money( $summary['materials_without_cost'] ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money().
							);
							?>
						</small>
					<?php endif; ?>
				</span>
			<?php endif; ?>
		</p>
		<?php
	}

	/** Print the two parts of a full cost: "(label $53.73 + materials $6.10)". */
	private static function render_cost_split( array $summary ) {
		printf(
			/* translators: 1: label cost, 2: materials cost */
			esc_html__( '(label %1$s + materials %2$s)', 'fishotel' ),
			self::money( $summary['label_cost'] ), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money().
			self::money( $summary['materials'] ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in money().
		);
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
