<?php
/**
 * FisHotel Re-Order Checkout Lock — Piece 2a.
 *
 * When a logged-in repeat customer (or a guest matched by billing email)
 * already has an open unshipped order, the new checkout must ship to the
 * SAME destination so the order auto-combines (FisHotel_Fulfillment) and
 * piggybacks on the existing shipping charge (FisHotel_Checkout_Reuse,
 * Piece 2b). This module locks the destination at the source:
 *
 *   - Primes the checkout session shipping address to the open order's
 *     destination, so the very first rate calc / order review already
 *     shows $0 (the reuse zeroing keys off the destination match).
 *   - Forces the native shipping fields to the open order's address and
 *     renders them read-only + hidden, replaced by a friendly summary card
 *     with the inherited destination, delivery date, and the "change it in
 *     My Account" notice.
 *   - Stamps the open order's shipping address onto the saved order in
 *     woocommerce_checkout_create_order — the authoritative guarantee that
 *     holds regardless of what the form posts.
 *
 * Billing stays editable; only the shipping destination is locked. The
 * source of truth for address changes is My Account → Addresses, which
 * propagates to open orders (Piece 3).
 *
 * Kill switch: define FISHOTEL_REORDER_CHECKOUT_LOCK_OFF (also disables the
 * free-shipping piggyback in FisHotel_Checkout_Reuse and, in the Batch
 * Plugin, the checkout date lock).
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Reorder_Checkout_Lock {

	/** Shipping field keys we force + lock. */
	const SHIPPING_KEYS = [
		'shipping_first_name',
		'shipping_last_name',
		'shipping_company',
		'shipping_country',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
	];

	public static function init() {
		// Force the shipping package destination to the open order's address so
		// the rate calc (and the reuse zeroing in FisHotel_Checkout_Reuse) ships
		// to — and matches on — that destination. Priority 9 so it runs before
		// FisHotel_Checkout_Reuse::tag_packages (10) reads the destination.
		//
		// This replaces an earlier woocommerce_checkout_init session-prime that
		// (a) ran AFTER WC had already built packages + pulled rates for the
		// request, so woocommerce_package_rates never saw it, and (b) called
		// WC()->customer->save(), which for a logged-in customer would overwrite
		// their saved profile address. Filtering the package destination needs
		// neither correct timing nor any customer mutation.
		add_filter( 'woocommerce_cart_shipping_packages', [ __CLASS__, 'force_package_destination' ], 9 );
		// Treat shipping separately from billing so a hold-for-pickup address
		// (different from billing) is preserved through save.
		add_filter( 'woocommerce_ship_to_different_address_checked', [ __CLASS__, 'force_ship_to_different' ] );
		// Force + lock the shipping field values.
		add_filter( 'woocommerce_checkout_get_value', [ __CLASS__, 'force_field_value' ], 20, 2 );
		add_filter( 'woocommerce_checkout_fields', [ __CLASS__, 'lock_shipping_fields' ], 20 );
		// Read-only summary card at the top of the customer-details column.
		add_action( 'woocommerce_checkout_before_customer_details', [ __CLASS__, 'render_summary_card' ] );
		// Authoritative guarantee: stamp the open order's shipping address onto
		// the new order no matter what posted.
		add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'enforce_order_shipping' ], 20, 2 );
		// Body class + themed styling for the lock UI.
		add_filter( 'body_class', [ __CLASS__, 'body_class' ] );
		add_action( 'wp_head', [ __CLASS__, 'lock_css' ] );
	}

	/** True when the checkout lock is switched off. */
	public static function disabled() {
		return defined( 'FISHOTEL_REORDER_CHECKOUT_LOCK_OFF' ) && FISHOTEL_REORDER_CHECKOUT_LOCK_OFF;
	}

	/**
	 * The current shopper's anchor order (most recent open unshipped order),
	 * cached per request keyed by the resolved customer key so it recomputes
	 * once a guest's billing email becomes known mid-checkout.
	 *
	 * @return WC_Order|null
	 */
	public static function anchor_order() {
		if ( self::disabled()
			|| ! function_exists( 'WC' ) || ! WC() || ! WC()->customer
			|| ! function_exists( 'fishotel_get_open_unshipped_order_for_customer' ) ) {
			return null;
		}
		$customer_id = (int) WC()->customer->get_id();
		$key         = $customer_id > 0
			? (string) $customer_id
			: strtolower( trim( (string) WC()->customer->get_billing_email() ) );
		if ( '' === $key ) {
			return null;
		}

		static $cache = [];
		if ( array_key_exists( $key, $cache ) ) {
			return $cache[ $key ];
		}
		$cache[ $key ] = fishotel_get_open_unshipped_order_for_customer( $customer_id > 0 ? $customer_id : $key );
		return $cache[ $key ];
	}

	/**
	 * The anchor order's shipping destination as an address array. Falls back
	 * to billing when the order carried no shipping address (shipped to
	 * billing), mirroring the auto-combine ship_hash fallback.
	 *
	 * @return array<string,string>
	 */
	private static function anchor_shipping( WC_Order $order ) {
		$ship     = $order->get_address( 'shipping' );
		$combined = trim( (string) ( $ship['address_1'] ?? '' ) . ( $ship['city'] ?? '' ) . ( $ship['postcode'] ?? '' ) );
		if ( '' !== $combined ) {
			return $ship;
		}
		$bill = $order->get_address( 'billing' );
		unset( $bill['email'], $bill['phone'] ); // shipping carries no email.
		return $bill;
	}

	/** Only act on cart/checkout requests (incl. their AJAX recalcs). */
	private static function in_cart_or_checkout_context() {
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}
		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) {
			return true;
		}
		if ( defined( 'WOOCOMMERCE_CART' ) && WOOCOMMERCE_CART ) {
			return true;
		}
		return false;
	}

	/**
	 * Rewrite every shipping package's destination to the open order's address.
	 * This is what makes the new order calculate rates to — and match on — the
	 * locked destination, so FisHotel_Checkout_Reuse zeros the shipping. No
	 * WC()->customer mutation, so the customer's saved profile is untouched.
	 *
	 * @param array $packages
	 * @return array
	 */
	public static function force_package_destination( $packages ) {
		if ( ! self::in_cart_or_checkout_context() ) {
			return $packages;
		}
		$order = self::anchor_order();
		if ( ! $order instanceof WC_Order ) {
			return $packages;
		}
		$ship = self::anchor_shipping( $order );
		$dest = [
			'country'   => (string) ( $ship['country'] ?? '' ),
			'state'     => (string) ( $ship['state'] ?? '' ),
			'postcode'  => (string) ( $ship['postcode'] ?? '' ),
			'city'      => (string) ( $ship['city'] ?? '' ),
			'address'   => (string) ( $ship['address_1'] ?? '' ),
			'address_1' => (string) ( $ship['address_1'] ?? '' ),
			'address_2' => (string) ( $ship['address_2'] ?? '' ),
		];
		foreach ( $packages as $i => $package ) {
			$packages[ $i ]['destination'] = $dest;
		}
		return $packages;
	}

	/** Force "ship to a different address" on so shipping saves independently. */
	public static function force_ship_to_different( $checked ) {
		return self::anchor_order() instanceof WC_Order ? true : $checked;
	}

	/** Force locked shipping field values from the anchor order. */
	public static function force_field_value( $value, $input ) {
		if ( ! in_array( $input, self::SHIPPING_KEYS, true ) ) {
			return $value;
		}
		$order = self::anchor_order();
		if ( ! $order instanceof WC_Order ) {
			return $value;
		}
		$ship  = self::anchor_shipping( $order );
		$field = substr( $input, strlen( 'shipping_' ) ); // e.g. 'address_1'.
		return array_key_exists( $field, $ship ) ? $ship[ $field ] : $value;
	}

	/** Mark the shipping fields read-only (UX backup to the CSS hide). */
	public static function lock_shipping_fields( $fields ) {
		if ( ! self::anchor_order() instanceof WC_Order ) {
			return $fields;
		}
		if ( empty( $fields['shipping'] ) || ! is_array( $fields['shipping'] ) ) {
			return $fields;
		}
		foreach ( $fields['shipping'] as $key => &$field ) {
			$field['custom_attributes'] = isset( $field['custom_attributes'] ) && is_array( $field['custom_attributes'] )
				? $field['custom_attributes']
				: [];
			$field['custom_attributes']['readonly'] = 'readonly';
			$field['custom_attributes']['tabindex'] = '-1';
			$field['class']                         = array_merge(
				isset( $field['class'] ) ? (array) $field['class'] : [],
				[ 'fh-reorder-locked-field' ]
			);
		}
		unset( $field );
		return $fields;
	}

	/** Authoritatively stamp the anchor's shipping address onto the new order. */
	public static function enforce_order_shipping( $order, $data ) {
		$anchor = self::anchor_order();
		if ( ! $anchor instanceof WC_Order || ! $order instanceof WC_Order ) {
			return;
		}
		$ship = self::anchor_shipping( $anchor );
		foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ] as $k ) {
			if ( ! array_key_exists( $k, $ship ) ) {
				continue;
			}
			$setter = "set_shipping_{$k}";
			if ( method_exists( $order, $setter ) ) {
				$order->{$setter}( (string) $ship[ $k ] );
			}
		}
	}

	/** Add a body class on checkout when the lock is active (drives the CSS). */
	public static function body_class( $classes ) {
		if ( function_exists( 'is_checkout' ) && is_checkout() && self::anchor_order() instanceof WC_Order ) {
			$classes[] = 'fh-reorder-checkout-locked';
		}
		return $classes;
	}

	/** The unified read-only summary card (notice + destination + date). */
	public static function render_summary_card() {
		$order = self::anchor_order();
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$ship = self::anchor_shipping( $order );
		$num  = $order->get_order_number();
		$name = trim( (string) ( $ship['first_name'] ?? '' ) . ' ' . (string) ( $ship['last_name'] ?? '' ) );

		$line2 = trim( implode( ', ', array_filter( [
			(string) ( $ship['city'] ?? '' ),
			trim( (string) ( $ship['state'] ?? '' ) . ' ' . (string) ( $ship['postcode'] ?? '' ) ),
		] ) ) );

		// Inherited delivery date (friendly), if the order carries one.
		$date_label = '';
		if ( function_exists( 'fishotel_get_effective_delivery_date' ) ) {
			$raw = (string) fishotel_get_effective_delivery_date( $order );
			$ts  = '' !== $raw ? strtotime( $raw ) : false;
			if ( $ts ) {
				$date_label = date_i18n( 'l, F j, Y', $ts );
			}
		}
		?>
		<div class="fh-reorder-lock" role="group" aria-label="<?php esc_attr_e( 'Re-order details locked to your open order', 'fishotel' ); ?>">
			<p class="fh-reorder-lock__notice">
				<?php
				echo esc_html( sprintf(
					/* translators: %s = existing order number */
					__( 'ℹ️ We are using the shipping address and delivery date from your existing order #%s. If you need to change either, please visit My Account → Addresses after checkout.', 'fishotel' ),
					$num
				) );
				?>
			</p>
			<div class="fh-reorder-lock__grid">
				<div class="fh-reorder-lock__block">
					<span class="fh-reorder-lock__eyebrow"><?php esc_html_e( 'Shipping to', 'fishotel' ); ?></span>
					<?php if ( '' !== $name ) : ?>
						<span class="fh-reorder-lock__value"><?php echo esc_html( $name ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== (string) ( $ship['address_1'] ?? '' ) ) : ?>
						<span class="fh-reorder-lock__value"><?php echo esc_html( $ship['address_1'] ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== (string) ( $ship['address_2'] ?? '' ) ) : ?>
						<span class="fh-reorder-lock__value"><?php echo esc_html( $ship['address_2'] ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $line2 ) : ?>
						<span class="fh-reorder-lock__value"><?php echo esc_html( $line2 ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( '' !== $date_label ) : ?>
				<div class="fh-reorder-lock__block">
					<span class="fh-reorder-lock__eyebrow"><?php esc_html_e( 'Requested delivery', 'fishotel' ); ?></span>
					<span class="fh-reorder-lock__value"><?php echo esc_html( $date_label ); ?></span>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** Hide the native shipping card + toggle when locked, and theme the card. */
	public static function lock_css() {
		if ( ! ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			return;
		}
		?>
<style id="fh-reorder-lock-css">
body.fh-reorder-checkout-locked .fh-checkout-shipping,
body.fh-reorder-checkout-locked .fh-checkout-shipping-toggle { display:none !important; }
.fh-reorder-lock{background:#0f0f0f;border:1px solid #d4a574;border-radius:6px;padding:16px 18px;margin:0 0 20px;color:#EDE0C0;}
.fh-reorder-lock__notice{margin:0 0 14px;font-weight:600;line-height:1.5;color:#EDE0C0;}
.fh-reorder-lock__grid{display:flex;flex-wrap:wrap;gap:20px 40px;}
.fh-reorder-lock__block{display:flex;flex-direction:column;gap:2px;}
.fh-reorder-lock__eyebrow{font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#a8895f;margin-bottom:4px;}
.fh-reorder-lock__value{color:#d4a574;font-weight:600;}
</style>
		<?php
	}
}
