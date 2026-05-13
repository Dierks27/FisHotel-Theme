<?php
/**
 * Medication store — two-layer disclaimer wall.
 *
 * Layer 1: cookie-stored entry modal. Triggers on first visit to any
 * Medications category page, or on the first single-product page when
 * the product is a medication. JS reads the cookie and reveals the
 * modal; cookie value is set on Accept. Decline routes to home.
 *
 * Layer 2: required checkout checkbox shown when the cart contains
 * any med. Validated server-side. Acceptance is logged as a WC order
 * note for legal trail.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Disclaimer {

	const COOKIE_NAME = 'fishotel_med_disclaimer_accepted';
	const FIELD_NAME  = 'fishotel_med_ack';

	public static function init() {
		// Layer 1 — render the modal HTML at the bottom of pages where
		// it can fire. JS gates display via cookie.
		add_action( 'wp_footer', [ __CLASS__, 'render_modal_markup' ] );

		// Layer 2 — checkout checkbox + validation + order note.
		add_action( 'woocommerce_review_order_before_submit', [ __CLASS__, 'render_checkout_field' ] );
		add_action( 'woocommerce_checkout_process',           [ __CLASS__, 'validate_checkout' ] );
		add_action( 'woocommerce_checkout_create_order',      [ __CLASS__, 'attach_order_note' ], 10, 2 );
	}

	/** Drop the modal HTML in the footer when it might be needed. */
	public static function render_modal_markup() {
		$needed = false;
		if ( fishotel_med_is_med_archive() ) {
			$needed = true;
		} elseif ( is_product() && fishotel_med_is_med_product( get_queried_object_id() ) ) {
			$needed = true;
		}
		if ( ! $needed ) {
			return;
		}
		$body  = FisHotel_Med_Settings::get_disclaimer_body();
		$body_html = wp_kses_post( wpautop( $body ) );
		?>
		<div class="fishotel-med-modal" id="fishotel-med-modal" role="dialog" aria-modal="true" aria-labelledby="fishotel-med-modal-title" hidden>
			<div class="fishotel-med-modal__backdrop"></div>
			<div class="fishotel-med-modal__card">
				<h2 class="fishotel-med-modal__title" id="fishotel-med-modal-title">FOR ORNAMENTAL USE ONLY</h2>
				<div class="fishotel-med-modal__body"><?php echo $body_html; ?></div>
				<div class="fishotel-med-modal__actions">
					<button type="button" class="fishotel-med-modal__btn fishotel-med-modal__btn--agree">I Agree — Show Me the Pharmacy</button>
					<button type="button" class="fishotel-med-modal__btn fishotel-med-modal__btn--decline">Decline</button>
				</div>
			</div>
		</div>
		<?php
	}

	/** Layer 2: required acknowledgment checkbox at checkout. */
	public static function render_checkout_field() {
		if ( ! fishotel_med_cart_has_meds() ) {
			return;
		}
		$label = FisHotel_Med_Settings::get_checkout_label();
		?>
		<div class="fishotel-med-checkout-ack" id="fishotel-med-checkout-ack">
			<label class="fishotel-med-checkout-ack__label">
				<input type="checkbox" name="<?php echo esc_attr( self::FIELD_NAME ); ?>" value="1" required>
				<span><?php echo esc_html( $label ); ?></span>
			</label>
		</div>
		<?php
	}

	public static function validate_checkout() {
		if ( ! fishotel_med_cart_has_meds() ) {
			return;
		}
		$ack = isset( $_POST[ self::FIELD_NAME ] ) && (string) $_POST[ self::FIELD_NAME ] === '1';
		if ( ! $ack ) {
			wc_add_notice(
				__( 'You must acknowledge the ornamental-use-only terms before placing your order.', 'fishotel' ),
				'error'
			);
		}
	}

	/** Add an explicit acceptance line to the order's notes. */
	public static function attach_order_note( $order, $data ) {
		if ( ! $order instanceof WC_Order ) return;
		if ( ! fishotel_med_cart_has_meds() ) return;

		$label = FisHotel_Med_Settings::get_checkout_label();
		$note  = sprintf(
			/* translators: %s = checkbox label */
			__( 'Medication disclaimer acknowledged: "%s"', 'fishotel' ),
			wp_strip_all_tags( $label )
		);
		$order->add_order_note( $note );
		$order->update_meta_data( '_fishotel_med_disclaimer_accepted', '1' );
		$order->update_meta_data( '_fishotel_med_disclaimer_accepted_at', time() );
	}
}
