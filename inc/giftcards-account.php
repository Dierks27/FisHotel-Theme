<?php
/**
 * FisHotel — My Account Gift Cards tab enhancements.
 *
 * Layered on top of the WooCommerce Gift Cards plugin (v2.7.3):
 *
 *  1. Unmask full codes on the Gift Cards endpoint (Active table + Activity
 *     descriptions) — scoped to the account page so codes are never unmasked
 *     in transactional emails or admin.
 *  2. Provide the shared "code + Copy button" markup consumed by both the
 *     Unclaimed section below and the template override
 *     (woocommerce/myaccount/giftcards.php).
 *  3. Relabel the plugin's "Add a gift card?" / "Add to your account" strings.
 *  4. Render an "Unclaimed Gift Cards" section (above "Your Balance") listing
 *     cards the current user owns — as recipient, or as the buyer (sender
 *     email match AND the card's order was placed by this account) — that
 *     haven't yet been claimed to their balance, each with a nonce'd Redeem
 *     button that runs WC_GC_Gift_Card::redeem(). Full codes are shown on
 *     purpose (saves a support email); the ownership rule is what keeps
 *     them private.
 *
 * Everything that reaches into plugin internals is guarded: a missing class,
 * accessor, DB table, or column degrades to "no section / keep masking"
 * rather than fatalling the customer's Gift Cards page.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Show full gift card codes on the customer's Gift Cards tab only.
 *
 * `wc_gc_mask_codes( $context )` is filterable via `woocommerce_gc_mask_codes`
 * and is honored both by the Active-table Code cell and by
 * `wc_gc_get_activity_description()` (Activity descriptions). We only unmask on
 * the front-end account page — the customer is already logged in and viewing
 * their own cards there — so emails/admin keep the plugin's default masking.
 *
 * @param bool   $mask    Whether codes should be masked.
 * @param string $context Optional masking context (e.g. 'account').
 * @return bool
 */
add_filter( 'woocommerce_gc_mask_codes', function ( $mask, $context = '' ) {
	if ( is_admin() ) {
		return $mask;
	}
	if ( function_exists( 'is_account_page' ) && is_account_page() ) {
		return false;
	}
	return $mask;
}, 20, 2 );

/**
 * Relabel the plugin's redeem-form strings on the Gift Cards tab.
 *
 * The WooCommerce Gift Cards plugin ships its own text domain
 * (`woocommerce-gift-cards`), so this is a separate gettext filter from the
 * `woocommerce`-domain one in inc/account-relabels.php.
 *
 * @param string $translated Translated text.
 * @param string $text       Original text.
 * @param string $domain     Text domain.
 * @return string
 */
add_filter( 'gettext', function ( $translated, $text, $domain ) {
	if ( 'woocommerce-gift-cards' !== $domain || is_admin() ) {
		return $translated;
	}
	switch ( $text ) {
		case 'Add a gift card?':
			return __( 'Redeem a Gift Card', 'fishotel' );
		case 'Add to your account':
			return __( 'Redeem', 'fishotel' );
	}
	return $translated;
}, 20, 3 );

/**
 * Full-code markup. Shared between the Unclaimed section and the plugin
 * template override so the two Code cells render identically.
 *
 * @param string $code Raw gift card code.
 * @return string HTML.
 */
function fishotel_giftcard_code_html( $code ) {
	$code = (string) $code;
	return sprintf(
		'<span class="fh-giftcard-code"><code>%1$s</code></span>',
		esc_html( $code )
	);
}

/**
 * Render the "Unclaimed Gift Cards" section plus any redeem flash notice.
 *
 * Fires on `woocommerce_gc_before_account_giftcards` (line 25 of the plugin
 * template), immediately above the "Your Balance" heading. The flash is
 * rendered independently of whether unclaimed cards remain, because a
 * just-redeemed card is no longer unclaimed — otherwise the success notice
 * would never appear after the redirect.
 */
add_action( 'woocommerce_gc_before_account_giftcards', function () {
	if ( ! is_user_logged_in() ) {
		return;
	}

	// Flash first — independent of remaining unclaimed cards.
	if ( ! empty( $_GET['fh_gc_redeemed'] ) ) {
		echo '<div class="fh-flash fh-flash--success">' .
			esc_html__( 'Gift card added to your balance.', 'fishotel' ) . '</div>';
	} elseif ( ! empty( $_GET['fh_gc_error'] ) ) {
		echo '<div class="fh-flash fh-flash--error">' .
			esc_html( fishotel_giftcard_error_text( sanitize_key( wp_unslash( $_GET['fh_gc_error'] ) ) ) ) . '</div>';
	}

	// Never let a plugin-internals mismatch break the page — worst case is an
	// empty list.
	try {
		$cards = fishotel_giftcards_unclaimed_for_current_user();
	} catch ( \Throwable $e ) {
		return;
	}

	if ( empty( $cards ) ) {
		return;
	}

	// The list includes cards the viewer BOUGHT for someone else (sender
	// match). Redeeming one of those claims it to the buyer's balance and
	// the intended recipient can no longer use the code — so foreign cards
	// show the recipient and Redeem asks for confirmation.
	$my_mail = strtolower( trim( (string) wp_get_current_user()->user_email ) );

	echo '<h2>' . esc_html__( 'Unclaimed Gift Cards', 'fishotel' ) . '</h2>';
	echo '<p class="fh-unclaimed-hint">' . esc_html__( "Cards sent to you, or bought by you, that haven't been added to a balance yet. Redeeming a card you bought for someone else adds it to YOUR balance and the recipient can no longer use it.", 'fishotel' ) . '</p>';
	echo '<table class="woocommerce-orders-table woocommerce-giftcards-table fh-giftcards-unclaimed shop_table shop_table_responsive account-orders-table">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Date', 'fishotel' ) . '</th>';
	echo '<th>' . esc_html__( 'Code', 'fishotel' ) . '</th>';
	echo '<th>' . esc_html__( 'Available Balance', 'fishotel' ) . '</th>';
	echo '<th>' . esc_html__( 'Expires', 'fishotel' ) . '</th>';
	echo '<th>&nbsp;</th>';
	echo '</tr></thead><tbody>';

	foreach ( $cards as $gc ) {
		$code    = $gc->get_code();
		$created = $gc->get_date_created();
		$expires = $gc->get_expire_date();
		$url     = wp_nonce_url(
			add_query_arg(
				array(
					'fh_gc_action' => 'redeem',
					'gc_id'        => $gc->get_id(),
				),
				wc_get_account_endpoint_url( 'giftcards' )
			),
			'fh_gc_redeem_' . $gc->get_id(),
			'fh_gc_nonce'
		);

		$recipient  = strtolower( trim( (string) $gc->get_recipient() ) );
		$is_foreign = ( '' !== $recipient && $recipient !== $my_mail );

		echo '<tr>';
		echo '<td data-title="' . esc_attr__( 'Date', 'fishotel' ) . '">' .
			( $created ? esc_html( date_i18n( wc_date_format(), $created ) ) : '&mdash;' ) . '</td>';
		echo '<td data-title="' . esc_attr__( 'Code', 'fishotel' ) . '">' .
			fishotel_giftcard_code_html( $code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
		if ( $is_foreign ) {
			echo '<br><small class="fh-giftcard-recipient">' . sprintf(
				/* translators: %s = recipient email address */
				esc_html__( 'Sent to %s', 'fishotel' ),
				esc_html( $recipient )
			) . '</small>';
		}
		echo '</td>';
		echo '<td data-title="' . esc_attr__( 'Available Balance', 'fishotel' ) . '">' .
			wp_kses_post( wc_price( $gc->get_balance() ) ) . '</td>';
		echo '<td data-title="' . esc_attr__( 'Expires', 'fishotel' ) . '">' .
			( $expires ? esc_html( date_i18n( wc_date_format(), $expires ) ) : esc_html__( 'Never', 'fishotel' ) ) . '</td>';
		$confirm = $is_foreign
			? ' onclick="return confirm(' . esc_attr( wp_json_encode( sprintf(
				/* translators: %s = recipient email address */
				__( 'This card was sent to %s. Redeeming it adds the balance to YOUR account and the recipient will no longer be able to use it. Continue?', 'fishotel' ),
				$recipient
			) ) ) . ');"'
			: '';
		echo '<td><a class="fh-giftcard-redeem-btn" href="' . esc_url( $url ) . '"' . $confirm . '>' . // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $confirm is escaped above.
			esc_html__( 'Redeem', 'fishotel' ) . '</a></td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
} );

/**
 * Handle a Redeem-button click: nonce'd GET → WC_GC_Gift_Card::redeem().
 *
 * Runs on template_redirect so the redirect fires before any output. Ownership
 * is enforced here so a guessed/forged gc_id can't claim someone else's card:
 * the current user must be the card's RECIPIENT by email, or its SENDER by
 * email on a card whose purchase order was placed by this very account
 * (fishotel_giftcard_order_owned_by). A sender-email match alone is not
 * enough — that email is free text at purchase time.
 */
add_action( 'template_redirect', function () {
	if ( ! is_user_logged_in() ) {
		return;
	}
	if ( 'redeem' !== ( $_GET['fh_gc_action'] ?? '' ) ) {
		return;
	}

	$endpoint = wc_get_account_endpoint_url( 'giftcards' );
	$gc_id    = absint( $_GET['gc_id'] ?? 0 );
	$nonce    = isset( $_GET['fh_gc_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['fh_gc_nonce'] ) ) : '';

	// Bad/absent nonce → silently ignore (no flash — matches "silently
	// rejected" for tampered URLs).
	if ( ! $gc_id || ! wp_verify_nonce( $nonce, 'fh_gc_redeem_' . $gc_id ) ) {
		return;
	}

	if ( ! class_exists( 'WC_GC_Gift_Card' ) ) {
		return;
	}

	$gc = new WC_GC_Gift_Card( $gc_id );
	if ( ! $gc->get_id() ) {
		wp_safe_redirect( add_query_arg( 'fh_gc_error', 'notfound', $endpoint ) );
		exit;
	}

	// Ownership: recipient by email, or sender by email whose own account
	// placed the order the card was bought on. Same rule as the listing.
	$me           = wp_get_current_user();
	$my_mail      = strtolower( trim( (string) $me->user_email ) );
	$sender       = strtolower( trim( (string) $gc->get_sender_email() ) );
	$recip        = strtolower( trim( (string) $gc->get_recipient() ) );
	$is_recipient = '' !== $my_mail && $recip === $my_mail;
	$is_buyer     = '' !== $my_mail && $sender === $my_mail && fishotel_giftcard_order_owned_by( $gc, $me->ID );
	if ( ! $is_recipient && ! $is_buyer ) {
		wp_safe_redirect( add_query_arg( 'fh_gc_error', 'forbidden', $endpoint ) );
		exit;
	}

	try {
		$gc->redeem( $me->ID );
		wp_safe_redirect( add_query_arg( 'fh_gc_redeemed', '1', $endpoint ) );
	} catch ( \Exception $e ) {
		wp_safe_redirect( add_query_arg( 'fh_gc_error', 'exception', $endpoint ) );
	}
	exit;
} );

/**
 * Resolve the WooCommerce Gift Cards giftcards table name, verifying it has
 * the columns we query. Returns '' when it can't be confirmed, so the caller
 * bails cleanly instead of issuing a bad-column query.
 *
 * @return string Fully-qualified table name, or '' if unresolved.
 */
function fishotel_giftcards_db_table() {
	static $resolved = null;
	if ( null !== $resolved ) {
		return $resolved;
	}

	global $wpdb;
	$resolved   = '';
	$candidates = array();

	// Prefer a name the plugin's own store object exposes. v2.7.x exposes the
	// store on WC_GC()->db->cards; older builds used ->giftcards.
	if ( function_exists( 'WC_GC' ) ) {
		$gc    = WC_GC();
		$store = null;
		if ( is_object( $gc ) && isset( $gc->db ) ) {
			if ( isset( $gc->db->cards ) && is_object( $gc->db->cards ) ) {
				$store = $gc->db->cards;
			} elseif ( isset( $gc->db->giftcards ) && is_object( $gc->db->giftcards ) ) {
				$store = $gc->db->giftcards;
			}
		}
		if ( $store ) {
			foreach ( array( 'get_table_name', 'get_table' ) as $m ) {
				if ( method_exists( $store, $m ) ) {
					try {
						$t = $store->$m();
						if ( is_string( $t ) && '' !== $t ) {
							$candidates[] = $t;
						}
					} catch ( \Throwable $e ) {
						continue;
					}
				}
			}
			foreach ( array( 'table_name', 'table' ) as $p ) {
				if ( isset( $store->$p ) && is_string( $store->$p ) && '' !== $store->$p ) {
					$candidates[] = $store->$p;
				}
			}
		}
	}

	// Fall back to the plugin's known table names. Plugin v2.7.x uses
	// {prefix}woocommerce_gc_cards (confirmed on the live install); the rest
	// are historical/alternate names kept as fallbacks.
	$candidates[] = $wpdb->prefix . 'woocommerce_gc_cards';
	$candidates[] = $wpdb->prefix . 'wc_gc_giftcards';
	$candidates[] = $wpdb->prefix . 'woocommerce_gc_giftcards';

	foreach ( array_unique( $candidates ) as $cand ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cand ) ) !== $cand ) {
			continue;
		}
		$cols = array_map( 'strtolower', (array) $wpdb->get_col( 'SHOW COLUMNS FROM `' . esc_sql( $cand ) . '`' ) );
		if ( in_array( 'id', $cols, true )
			&& in_array( 'recipient', $cols, true )
			&& in_array( 'sender_email', $cols, true )
			&& in_array( 'redeemed_by', $cols, true ) ) {
			$resolved = $cand;
			break;
		}
	}

	return $resolved;
}

/**
 * Does this gift card's purchase order belong to the given account?
 *
 * The sender-only entitlement rule: a card the viewer did NOT receive is
 * theirs to list/redeem only when it was bought on an order placed by their
 * account — WC_GC_Gift_Card::get_order_id() → wc_get_order() → customer id.
 * A card whose sender_email merely matches (no order, a guest order, another
 * customer's order, or an order id the plugin can't report) is NOT theirs:
 * sender_email is free text at purchase time, so on its own it would let a
 * shared or spoofed address list and claim somebody else's full code.
 *
 * Every plugin touch is guarded; any doubt returns false (excluded).
 *
 * @param WC_GC_Gift_Card $gc      Gift card (already instantiated).
 * @param int             $user_id Account to test against.
 * @return bool
 */
function fishotel_giftcard_order_owned_by( $gc, $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id || ! is_object( $gc ) || ! function_exists( 'wc_get_order' ) ) {
		return false;
	}

	$order_id = 0;
	try {
		if ( method_exists( $gc, 'get_order_id' ) ) {
			$order_id = (int) $gc->get_order_id();
		} elseif ( isset( $gc->data ) && is_object( $gc->data ) && method_exists( $gc->data, 'get_order_id' ) ) {
			// Older builds expose the getters on the wrapped data object only.
			$order_id = (int) $gc->data->get_order_id();
		}
	} catch ( \Throwable $e ) {
		return false;
	}
	if ( ! $order_id ) {
		return false;
	}

	// WC_Order only — wc_get_order() also returns WC_Order_Refund for a refund
	// id, which has no customer of its own.
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return false;
	}
	return (int) $order->get_customer_id() === $user_id;
}

/**
 * Gift cards the current user is entitled to claim: they are the RECIPIENT
 * (by email), or the SENDER (by email) on a card whose purchase order was
 * placed by this account (fishotel_giftcard_order_owned_by); and the card is
 * not yet linked to any account, is active, unexpired, and carries a positive
 * balance. A sender-only match with no order, or another customer's order,
 * is excluded — recipient matches are unaffected by that rule.
 *
 * Candidate IDs are pulled by email from the plugin table (recipient OR
 * sender_email); the ownership rule and final eligibility are decided in PHP
 * on the instantiated card, the latter by WC_GC_Gift_Card::is_usable() so the
 * plugin's own rules (active, not expired, not redeemed, balance > 0) are
 * authoritative.
 *
 * @return WC_GC_Gift_Card[]
 */
function fishotel_giftcards_unclaimed_for_current_user() {
	if ( ! is_user_logged_in() || ! class_exists( 'WC_GC_Gift_Card' ) ) {
		return array();
	}

	$table = fishotel_giftcards_db_table();
	if ( '' === $table ) {
		return array();
	}

	$user    = wp_get_current_user();
	$email   = $user->user_email;
	$my_mail = strtolower( trim( (string) $email ) );
	if ( '' === $my_mail ) {
		return array();
	}

	global $wpdb;
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT id FROM `' . esc_sql( $table ) . '`'
			. " WHERE ( recipient = %s OR sender_email = %s )"
			. " AND ( redeemed_by IS NULL OR redeemed_by = '' OR redeemed_by = '0' )",
			$email,
			$email
		)
	);

	$cards = array();
	foreach ( array_map( 'absint', (array) $ids ) as $id ) {
		if ( ! $id ) {
			continue;
		}
		$gc = new WC_GC_Gift_Card( $id );
		if ( ! $gc->get_id() ) {
			continue;
		}
		// is_usable(): active && ! expired && ! redeemed && balance > 0.
		if ( ! $gc->is_usable() ) {
			continue;
		}
		// Not the recipient → this row got here on sender_email alone, so it
		// must also be the buyer's own order (see fishotel_giftcard_order_owned_by).
		$recip = strtolower( trim( (string) $gc->get_recipient() ) );
		if ( $recip !== $my_mail && ! fishotel_giftcard_order_owned_by( $gc, $user->ID ) ) {
			continue;
		}
		$cards[] = $gc;
	}

	return $cards;
}

/**
 * Human-readable text for a redeem error flash key.
 *
 * @param string $key Sanitized error key.
 * @return string
 */
function fishotel_giftcard_error_text( $key ) {
	switch ( $key ) {
		case 'notfound':
			return __( 'That gift card could not be found.', 'fishotel' );
		case 'forbidden':
			return __( 'You are not the sender or recipient of that gift card.', 'fishotel' );
		case 'exception':
			return __( 'That gift card could not be redeemed. It may already be claimed, expired, or empty.', 'fishotel' );
	}
	return __( 'Something went wrong.', 'fishotel' );
}
