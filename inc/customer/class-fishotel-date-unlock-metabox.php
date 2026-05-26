<?php
/**
 * FisHotel — Admin "Date Change Override" meta box.
 *
 * One checkbox on each order edit page that lets a human operator bypass the
 * customer self-serve 48hr cutoff on a per-order basis. Use sparingly — for
 * one-off exceptions where someone calls in late asking to move a fish
 * delivery. The other eligibility rules (status, shipments, fulfillment
 * membership) still apply; only the cutoff is overridden.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Date_Unlock_Metabox {

	const NONCE_ACTION = 'fishotel_unlock_save';
	const NONCE_FIELD  = 'fishotel_unlock_nonce';
	const FIELD_NAME   = '_fishotel_admin_unlock_date_change';

	public static function init() {
		add_action( 'add_meta_boxes_shop_order', [ __CLASS__, 'register' ] );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', [ __CLASS__, 'register' ] );
		add_action( 'woocommerce_process_shop_order_meta', [ __CLASS__, 'save' ], 20, 1 );
		add_action( 'woocommerce_update_order', [ __CLASS__, 'save' ], 20, 1 );
	}

	private static function screen_id() {
		if ( function_exists( 'wc_get_page_screen_id' ) && function_exists( 'get_current_screen' ) ) {
			$hpos    = wc_get_page_screen_id( 'shop-order' );
			$current = get_current_screen();
			if ( $current && $hpos && $current->id === $hpos ) {
				return $hpos;
			}
		}
		return 'shop_order';
	}

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

	public static function register( $post_or_order = null ) {
		$order = self::resolve_order( $post_or_order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// Don't show on fulfillment entities — they don't carry the
		// customer-facing delivery date in the same way.
		if ( function_exists( 'fishotel_is_fulfillment' ) && fishotel_is_fulfillment( $order ) ) {
			return;
		}
		add_meta_box(
			'fishotel-date-change-unlock',
			__( 'Date Change Override', 'fishotel' ),
			[ __CLASS__, 'render' ],
			self::screen_id(),
			'side',
			'low'
		);
	}

	public static function render( $post_or_order ) {
		$order = self::resolve_order( $post_or_order );
		if ( ! $order instanceof WC_Order ) {
			echo '<p style="margin:0;color:#666;">' . esc_html__( 'Order not loaded yet.', 'fishotel' ) . '</p>';
			return;
		}
		$unlocked = 'yes' === (string) $order->get_meta( self::FIELD_NAME );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<p style="margin:0 0 8px;">
			<label style="font-weight:600;">
				<input type="checkbox" name="<?php echo esc_attr( self::FIELD_NAME ); ?>" value="yes" <?php checked( $unlocked ); ?>>
				<?php esc_html_e( 'Allow customer to change delivery date past 48hr cutoff', 'fishotel' ); ?>
			</label>
		</p>
		<p class="description" style="margin:0;">
			<?php esc_html_e( 'Use sparingly — for one-off customer exceptions. Status / shipment / fulfillment rules still apply.', 'fishotel' ); ?>
		</p>
		<?php
	}

	public static function save( $order_id_or_order ) {
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

		static $saved = [];
		if ( isset( $saved[ $order->get_id() ] ) ) {
			return;
		}
		$saved[ $order->get_id() ] = true;

		$want    = ! empty( $_POST[ self::FIELD_NAME ] ) ? 'yes' : '';
		$current = (string) $order->get_meta( self::FIELD_NAME );
		if ( $want === $current ) {
			return;
		}
		if ( '' === $want ) {
			$order->delete_meta_data( self::FIELD_NAME );
		} else {
			$order->update_meta_data( self::FIELD_NAME, 'yes' );
		}
		$order->add_order_note( 'yes' === $want
			? __( 'Admin override: customer may change delivery date past 48hr cutoff.', 'fishotel' )
			: __( 'Admin override revoked: customer can no longer change delivery date past cutoff.', 'fishotel' )
		);
		$order->save();
	}
}
