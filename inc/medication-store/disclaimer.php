<?php
/**
 * Medication store — two-layer disclaimer wall + terms-acceptance record.
 *
 * Layer 1: cookie-stored entry modal. Triggers on first visit to any
 * Medications category page, or on the first single-product page when
 * the product is a medication. JS reads the cookie and reveals the
 * modal; cookie value is set on Accept. Decline routes to home.
 *
 * Layer 2: required checkout checkbox shown when the cart contains
 * any med. Validated server-side. Acceptance is logged as WC order
 * notes for legal trail — written on woocommerce_checkout_order_created
 * (after the order is saved), because WC_Order::add_order_note() is a
 * no-op on the unsaved order woocommerce_checkout_create_order hands out.
 *
 * ── Terms acceptance record (C2) ─────────────────────────────────────
 *
 * On woocommerce_checkout_create_order the order gets, besides the two
 * legacy keys `_fishotel_med_disclaimer_accepted` = '1' and
 * `_fishotel_med_disclaimer_accepted_at` = time() (both kept), the full
 * `_fishotel_med_terms` array built by FisHotel_Med_Terms::build_record()
 * (inc/medication-store/terms-record.php, pure PHP, unit-tested):
 * accepted, accepted_at_utc/local, ip, user_agent, the exact checkbox
 * label, terms_version, terms_hash (md5 of the normalised disclaimer body
 * + label), every medication line in the cart (products: id/name/qty,
 * EA-fulfilled ones included), the extra acknowledgements accepted and
 * source = 'checkout'. The order notes ("Medication disclaimer
 * acknowledged: …" and, when any, "Additional medication
 * acknowledgement(s) accepted: …") are written from that stored record
 * once the order has an id, on woocommerce_checkout_order_created.
 *
 * Where it shows (inc/medication-store/terms-display.php):
 *   - Admin order screen (legacy post + HPOS wc-orders): "Medication terms"
 *     box with accepted-at, IP, version, label, extra acks, products. An
 *     order with medication lines and no record shows a red NOT RECORDED
 *     state with a "Record manual acknowledgement" button (admin-post,
 *     nonce + manage_woocommerce) that stores a record with source
 *     'manual', the admin's id/name, the time and an optional note. Orders
 *     that only carry the legacy keys show "Accepted (legacy record, no
 *     details)" with the stored timestamp. A combined-box fulfillment
 *     wrapper lists one read-only row per source order (the records live
 *     there) and never takes a manual record itself.
 *   - Emails (customer processing/completed/invoice, admin new order):
 *     "Medication terms accepted <local date> · terms v<version>". The
 *     customer copy of a manual record reads "Medication terms
 *     acknowledgement recorded <local date> · terms v<version>" (no staff
 *     name); the admin new-order email keeps the "(recorded manually by …)"
 *     marker.
 *   - WPO PDF invoice + packing slip (via inc/pdf-invoice.php): "Medication
 *     terms accepted <date> · IP <ip> · v<version>". The EA packing slip
 *     (packing-slip.php) prints the same line without the IP — it is
 *     emailed to a third party.
 *   - My Account order view: "You accepted the medication terms on <date>."
 *     with a "Read the terms" link.
 *   - Checkout: a "Read the terms" link next to the checkbox that opens
 *     the disclaimer modal in read-only mode (no cookie, no redirect) or
 *     goes to the public Terms page when one is set.
 *
 * Terms version: FisHotel Theme → Medication Store → "Terms version"
 * (default '2026-10'). Bump it when the wording changes; every order
 * records the version it accepted. [fishotel_med_terms] prints the body,
 * version and label for a public Medication Terms page; pick that page in
 * the "Terms page" setting to send the "Read the terms" links there.
 *
 * Extra acknowledgement (chloroquine and friends): product meta
 * `_fishotel_med_extra_ack` ("Extra checkout acknowledgement" textarea on
 * the FisHotel Medication tab). Each distinct text among the medication
 * lines in the cart renders one more required checkbox named
 * fishotel_med_ack_extra[<md5 of the text>] under the main one, prefixed
 * with the product name(s). Validation recomputes the required set from
 * the live cart and requires every hash to be present and checked —
 * posted indexes are never trusted. Accepted texts land in extra_acks.
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

		// Layer 2 — checkout checkbox + validation + record + order notes.
		add_action( 'woocommerce_review_order_before_submit', [ __CLASS__, 'render_checkout_field' ] );
		add_action( 'woocommerce_checkout_process',           [ __CLASS__, 'validate_checkout' ] );
		add_action( 'woocommerce_checkout_create_order',      [ __CLASS__, 'attach_order_note' ], 10, 2 );
		add_action( 'woocommerce_checkout_order_created',     [ __CLASS__, 'add_order_notes' ], 10, 1 );
	}

	/** True on the classic checkout page (not the order-received endpoint). */
	protected static function is_checkout_page() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return false;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return false;
		}
		return true;
	}

	/**
	 * True on My Account → view order / the thank-you page when the viewed
	 * order carries a terms record — that is where the account "Read the
	 * terms" link renders, so the read-only modal has to exist there too.
	 */
	public static function is_order_view_with_record() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order_id = 0;
		if ( is_wc_endpoint_url( 'view-order' ) ) {
			$order_id = absint( get_query_var( 'view-order' ) );
		} elseif ( is_wc_endpoint_url( 'order-received' ) ) {
			$order_id = absint( get_query_var( 'order-received' ) );
		}
		if ( ! $order_id || ! class_exists( 'FisHotel_Med_Terms_Display' ) ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order && FisHotel_Med_Terms_Display::get_record( $order ) !== null;
	}

	/**
	 * Posted extra-acknowledgement values as hash => scalar string. Nested
	 * arrays and non-scalar junk are dropped before sanitising.
	 *
	 * @param array|null $values Request array to read ($_POST when null).
	 * @return array<string,string>
	 */
	protected static function posted_extra_acks( $values = null ) {
		if ( $values === null ) {
			$values = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verifies the checkout nonce.
		}
		if ( ! isset( $values[ FisHotel_Med_Terms::EXTRA_FIELD ] ) || ! is_array( $values[ FisHotel_Med_Terms::EXTRA_FIELD ] ) ) {
			return [];
		}
		$out = [];
		foreach ( wp_unslash( $values[ FisHotel_Med_Terms::EXTRA_FIELD ] ) as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$key = preg_replace( '/[^a-f0-9]/', '', (string) $key );
			if ( $key === '' ) {
				continue;
			}
			$out[ $key ] = sanitize_text_field( (string) $value );
		}
		return $out;
	}

	/**
	 * Checkout values the rendered checkboxes should reflect. A plain submit
	 * (JS off, or a reload after a validation error) carries them in $_POST;
	 * on WooCommerce's AJAX order-review refresh — every address / shipping
	 * change re-renders the payment box this field sits in — the whole form
	 * arrives serialised in $_POST['post_data'] instead, so parse that.
	 * Display state only: validation and the record read $_POST directly.
	 *
	 * @return array
	 */
	protected static function rendered_form_values() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- display state only; WC verifies its own nonces.
		if ( isset( $_POST[ self::FIELD_NAME ] ) || isset( $_POST[ FisHotel_Med_Terms::EXTRA_FIELD ] ) ) {
			return $_POST;
		}
		if ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
			parse_str( wp_unslash( $_POST['post_data'] ), $values );
			return is_array( $values ) ? $values : [];
		}
		// phpcs:enable
		return [];
	}

	/**
	 * Drop the modal HTML in the footer when it might be needed.
	 *
	 * Gate mode (archive / med PDP): locked modal, Agree sets the cookie,
	 * Decline routes home. Read-only mode (checkout with meds in the cart
	 * and no public Terms page): opened on demand by the "Read the terms"
	 * link, a single Close button, no cookie, no redirect.
	 */
	public static function render_modal_markup() {
		$mode = '';
		if ( fishotel_med_is_med_archive() ) {
			$mode = 'gate';
		} elseif ( is_product() && fishotel_med_is_med_product( get_queried_object_id() ) ) {
			$mode = 'gate';
		} elseif ( FisHotel_Med_Settings::get_terms_page_url() === ''
			&& ( ( self::is_checkout_page() && fishotel_med_cart_has_meds() ) || self::is_order_view_with_record() ) ) {
			$mode = 'readonly';
		}
		if ( $mode === '' ) {
			return;
		}
		$body      = FisHotel_Med_Settings::get_disclaimer_body();
		$body_html = wp_kses_post( wpautop( $body ) );
		$version   = FisHotel_Med_Settings::get_terms_version();
		?>
		<div class="fishotel-med-modal" id="fishotel-med-modal" role="dialog" aria-modal="true" aria-labelledby="fishotel-med-modal-title" data-mode="<?php echo esc_attr( $mode ); ?>" hidden>
			<div class="fishotel-med-modal__backdrop"></div>
			<div class="fishotel-med-modal__card">
				<h2 class="fishotel-med-modal__title" id="fishotel-med-modal-title">FOR ORNAMENTAL USE ONLY</h2>
				<div class="fishotel-med-modal__body"><?php echo $body_html; ?></div>
				<?php if ( $mode === 'readonly' ) : ?>
					<p class="fishotel-med-modal__version"><?php echo esc_html( sprintf( /* translators: %s = terms version */ __( 'Terms version %s', 'fishotel' ), $version ) ); ?></p>
					<div class="fishotel-med-modal__actions">
						<button type="button" class="fishotel-med-modal__btn fishotel-med-modal__btn--agree fishotel-med-modal__btn--close"><?php esc_html_e( 'Close', 'fishotel' ); ?></button>
					</div>
				<?php else : ?>
					<div class="fishotel-med-modal__actions">
						<button type="button" class="fishotel-med-modal__btn fishotel-med-modal__btn--agree">I Agree — Show Me the Pharmacy</button>
						<button type="button" class="fishotel-med-modal__btn fishotel-med-modal__btn--decline">Decline</button>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * "Read the terms" anchor: the public Terms page when set, else a
	 * same-page link the JS intercepts to open the read-only modal.
	 */
	public static function terms_link_html( $text = '' ) {
		$text = $text !== '' ? $text : __( 'Read the terms', 'fishotel' );
		$url  = FisHotel_Med_Settings::get_terms_page_url();
		if ( $url !== '' ) {
			return '<a class="fishotel-med-terms-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $text ) . '</a>';
		}
		return '<a class="fishotel-med-terms-link" href="#fishotel-med-modal" data-fishotel-med-terms-open="1">' . esc_html( $text ) . '</a>';
	}

	/** Layer 2: required acknowledgment checkbox(es) at checkout. */
	public static function render_checkout_field() {
		if ( ! fishotel_med_cart_has_meds() ) {
			return;
		}
		$label    = FisHotel_Med_Settings::get_checkout_label();
		$required = FisHotel_Med_Terms::extra_ack_required_set( fishotel_med_cart_med_lines() );
		$values   = self::rendered_form_values();
		$posted   = self::posted_extra_acks( $values );
		$main_on  = isset( $values[ self::FIELD_NAME ] ) && is_scalar( $values[ self::FIELD_NAME ] ) && sanitize_text_field( wp_unslash( $values[ self::FIELD_NAME ] ) ) === '1';
		?>
		<div class="fishotel-med-checkout-ack" id="fishotel-med-checkout-ack">
			<label class="fishotel-med-checkout-ack__label">
				<input type="checkbox" name="<?php echo esc_attr( self::FIELD_NAME ); ?>" value="1" required <?php checked( $main_on ); ?>>
				<span><?php echo esc_html( $label ); ?></span>
			</label>
			<p class="fishotel-med-checkout-ack__terms">
				<?php echo self::terms_link_html(); // Escaped inside. ?>
			</p>
			<?php foreach ( $required as $hash => $ack ) : ?>
				<?php
				$field_name = FisHotel_Med_Terms::EXTRA_FIELD . '[' . $hash . ']';
				$is_on      = isset( $posted[ $hash ] ) && $posted[ $hash ] === '1';
				?>
				<label class="fishotel-med-checkout-ack__label fishotel-med-checkout-ack__label--extra">
					<input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>" value="1" required <?php checked( $is_on ); ?>>
					<span>
						<?php if ( ! empty( $ack['products'] ) ) : ?>
							<strong><?php echo esc_html( implode( ', ', $ack['products'] ) ); ?>:</strong>
						<?php endif; ?>
						<?php echo esc_html( $ack['text'] ); ?>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public static function validate_checkout() {
		if ( ! fishotel_med_cart_has_meds() ) {
			return;
		}
		$raw = isset( $_POST[ self::FIELD_NAME ] ) && is_scalar( $_POST[ self::FIELD_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_NAME ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verifies the checkout nonce before this hook.
		$ack = ( $raw === '1' );
		if ( ! $ack ) {
			wc_add_notice(
				__( 'You must acknowledge the ornamental-use-only terms before placing your order.', 'fishotel' ),
				'error'
			);
		}

		// Extra acknowledgements: recompute the required set from the live
		// cart; every hash must be present and checked. Posted keys that are
		// not in the set are ignored.
		$required = FisHotel_Med_Terms::extra_ack_required_set( fishotel_med_cart_med_lines() );
		if ( empty( $required ) ) {
			return;
		}
		$missing = FisHotel_Med_Terms::validate_extra_acks( $required, self::posted_extra_acks() );
		foreach ( $missing as $hash ) {
			$names = isset( $required[ $hash ]['products'] ) ? implode( ', ', $required[ $hash ]['products'] ) : '';
			wc_add_notice(
				$names !== ''
					? sprintf(
						/* translators: %s = product name(s) */
						__( 'Please confirm the additional acknowledgement for %s before placing your order.', 'fishotel' ),
						$names
					)
					: __( 'Please confirm every additional medication acknowledgement before placing your order.', 'fishotel' ),
				'error'
			);
		}
	}

	/**
	 * Add an explicit acceptance line to the order's notes and store the
	 * full terms record. The order is saved by WooCommerce right after this
	 * hook, so no save() here.
	 */
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
		$order->update_meta_data( FisHotel_Med_Terms::LEGACY_ACCEPTED_KEY, '1' );
		$order->update_meta_data( FisHotel_Med_Terms::LEGACY_ACCEPTED_AT_KEY, time() );

		$record = FisHotel_Med_Terms::build_record( self::gather_checkout_inputs( $order, $label ) );
		$order->update_meta_data( FisHotel_Med_Terms::META_KEY, $record );

		if ( ! empty( $record['extra_acks'] ) ) {
			$order->add_order_note( sprintf(
				/* translators: %s = the extra acknowledgement texts */
				__( 'Additional medication acknowledgement(s) accepted: %s', 'fishotel' ),
				implode( ' | ', $record['extra_acks'] )
			) );
		}
	}

	/**
	 * Collect the record inputs from the request, the order and the cart.
	 * Only this method touches WordPress / WooCommerce state; the builder
	 * itself is pure.
	 */
	protected static function gather_checkout_inputs( WC_Order $order, $label ) {
		$ip = (string) $order->get_customer_ip_address();
		if ( $ip === '' && class_exists( 'WC_Geolocation' ) ) {
			$ip = (string) WC_Geolocation::get_ip_address();
		}
		$ua = (string) $order->get_customer_user_agent();
		if ( $ua === '' && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		$lines    = fishotel_med_cart_med_lines();
		$required = FisHotel_Med_Terms::extra_ack_required_set( $lines );
		$posted   = self::posted_extra_acks();
		$accepted = [];
		foreach ( $required as $hash => $ack ) {
			if ( isset( $posted[ $hash ] ) && $posted[ $hash ] === '1' ) {
				$accepted[] = $ack['text'];
			}
		}

		return [
			'timestamp'     => time(),
			'timezone'      => wp_timezone(),
			'ip'            => $ip,
			'user_agent'    => $ua,
			'label'         => wp_strip_all_tags( $label ),
			'terms_version' => FisHotel_Med_Settings::get_terms_version(),
			'terms_body'    => FisHotel_Med_Settings::get_disclaimer_body(),
			'products'      => $lines,
			'extra_acks'    => $accepted,
			'source'        => 'checkout',
		];
	}
}
