<?php
/**
 * FisHotel — Gift Card ↔ PayPal (PPCP) checkout safeguards.
 *
 * Two independent protections against the "PayPal charges the pre-gift-card
 * total" double-charge (order #34494):
 *
 *  1. PRIMARY (client-side, paired with woocommerce.css + main.js): when a
 *     gift card balance is applied at checkout, the PPCP smart / express
 *     buttons are suppressed so the customer completes through the standard
 *     place-order → PayPal redirect, which builds the PayPal order from the
 *     WC order (post-gift-card total) rather than from the cart line-item
 *     breakdown (which omits the gift-card deduction — a cart-total-level
 *     adjustment that is neither a line item, coupon, nor fee, so PPCP's
 *     purchase unit never sees it and captures the full pre-gift-card sum).
 *
 *  2. DEFENSE IN DEPTH (this file): at payment completion, compare the
 *     amount PayPal actually captured against the WC order total. If they
 *     diverge beyond a rounding tolerance the order is NOT silently
 *     completed — it is placed on-hold with an explanatory admin note so a
 *     human reconciles it. This is source-agnostic: for any correctly
 *     charged order the gross capture equals the order total, so it also
 *     catches future regressions of this bug class regardless of origin.
 *
 *  3. GATEWAY REMOVAL (this file): the smart-button suppression above only
 *     covers PPCP's top-of-checkout express buttons, not its "Debit &
 *     Credit Cards" hosted-card gateway in the payment-methods radio list.
 *     That gateway hits the same itemization mismatch AND leaves the gift
 *     card debited on a failed return (order #34530). When a gift card
 *     balance is applied we unset PPCP's hosted-card gateways server-side
 *     (authoritative), backed by CSS + JS as defense in depth.
 *
 *  4. AUTO-REVERSE (this file): WC Gift Cards debits the card at
 *     checkout_order_processed, before payment completes. When an order
 *     transitions to failed / cancelled / trash without ever reaching a
 *     paid status, we credit the debited amount back to the card so a
 *     failed payment attempt can't silently drain a customer's balance.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rounding tolerance (major currency units) for the capture/total match.
 * Filterable so it can be relaxed per-order without a code change.
 */
function fishotel_gc_capture_tolerance( $order = null ) {
	return (float) apply_filters( 'fishotel_gc_capture_tolerance', 0.01, $order );
}

/**
 * Normalise a PPCP fee-breakdown value to a float.
 *
 * PPCP has stored these as plain floats, as `['currency_code'=>…,'value'=>…]`
 * arrays, and as Money value objects across versions — handle all three.
 *
 * @param mixed $value
 * @return float|null Null when the value can't be read as money.
 */
function fishotel_gc_money_to_float( $value ) {
	if ( is_numeric( $value ) ) {
		return (float) $value;
	}
	if ( is_array( $value ) && isset( $value['value'] ) && is_numeric( $value['value'] ) ) {
		return (float) $value['value'];
	}
	if ( is_object( $value ) ) {
		foreach ( [ 'value', 'get_value' ] as $method ) {
			if ( method_exists( $value, $method ) ) {
				$out = $value->{$method}();
				if ( is_numeric( $out ) ) {
					return (float) $out;
				}
			}
		}
	}
	return null;
}

/**
 * Best-effort read of the gross amount PayPal captured for a PPCP order.
 *
 * The customer is charged the GROSS (payout + PayPal fee); the fee is netted
 * out of the merchant payout, not the buyer's card. PPCP stores the seller
 * breakdown in `_ppcp_paypal_fees`. Extraction is intentionally defensive and
 * short-circuits to a filter override so the exact meta shape can be pinned
 * once confirmed against a live order (see PR QA).
 *
 * @param WC_Order $order
 * @return float|null Null when the captured amount can't be determined — the
 *                    caller then takes NO action, so an unreadable breakdown
 *                    never produces a false on-hold.
 */
function fishotel_gc_gateway_captured_amount( $order ) {
	$override = apply_filters( 'fishotel_gc_gateway_captured_amount', null, $order );
	if ( is_numeric( $override ) ) {
		return (float) $override;
	}

	$fees = $order->get_meta( '_ppcp_paypal_fees' );
	if ( is_string( $fees ) ) {
		$decoded = json_decode( $fees, true );
		if ( is_array( $decoded ) ) {
			$fees = $decoded;
		}
	}
	if ( ! is_array( $fees ) ) {
		return null;
	}

	// Prefer an explicit gross field when present.
	foreach ( [ 'gross', 'gross_amount', 'total', 'amount' ] as $key ) {
		if ( isset( $fees[ $key ] ) ) {
			$gross = fishotel_gc_money_to_float( $fees[ $key ] );
			if ( $gross !== null ) {
				return round( $gross, 2 );
			}
		}
	}

	// Otherwise reconstruct gross = fee + net (payout).
	$fee = null;
	foreach ( [ 'paypal_fee', 'fee', 'paypal_fees' ] as $key ) {
		if ( isset( $fees[ $key ] ) ) {
			$fee = fishotel_gc_money_to_float( $fees[ $key ] );
			if ( $fee !== null ) {
				break;
			}
		}
	}
	$net = null;
	foreach ( [ 'net', 'net_amount', 'paypal_payout', 'payout' ] as $key ) {
		if ( isset( $fees[ $key ] ) ) {
			$net = fishotel_gc_money_to_float( $fees[ $key ] );
			if ( $net !== null ) {
				break;
			}
		}
	}
	if ( $fee !== null && $net !== null ) {
		return round( $fee + $net, 2 );
	}

	return null;
}

/**
 * Completion guard — hold, don't silently complete, on a capture/total
 * mismatch. Runs on `woocommerce_payment_complete`, which fires AFTER the
 * paid status has been set and saved, so flipping to on-hold here is a clean
 * post-transition change (no mid-transition update_status re-entrancy). PPCP
 * writes its capture fee/breakdown meta synchronously before this point.
 * Idempotent via a marker meta so a later completed→… transition can't
 * re-hold a manually released order.
 *
 * @param int $order_id
 */
function fishotel_gc_guard_capture_amount( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	// Run at most once.
	if ( $order->get_meta( '_fishotel_capture_guard_done' ) === 'yes' ) {
		return;
	}

	// Only gateways that build their own capture amount (PayPal / PPCP).
	$method = strtolower( (string) $order->get_payment_method() );
	if ( strpos( $method, 'ppcp' ) === false && strpos( $method, 'paypal' ) === false ) {
		return;
	}

	$captured = fishotel_gc_gateway_captured_amount( $order );
	if ( $captured === null ) {
		// Undeterminable — do not guess, do not false-flag. (Authorize-then-
		// capture-later flows that write the breakdown via a webhook aren't
		// covered here; the primary smart-button suppression is the guard for
		// those.)
		return;
	}

	// Mark checked now so neither hook re-evaluates (an on-hold transition
	// below must not re-enter).
	$order->update_meta_data( '_fishotel_capture_guard_done', 'yes' );
	$order->save();

	$total     = round( (float) $order->get_total(), 2 );
	$tolerance = fishotel_gc_capture_tolerance( $order );

	if ( abs( $captured - $total ) <= $tolerance ) {
		return; // Amounts reconcile — nothing to do.
	}

	$note = sprintf(
		/* translators: 1: captured amount, 2: order total, 3: difference */
		__( 'FisHotel safeguard: payment gateway captured %1$s but the order total is %2$s (difference %3$s). Order placed on-hold instead of completing so the charge can be reconciled — verify the gift-card deduction was reflected in the gateway charge before releasing. See order #34494 for the double-charge this guard protects against.', 'fishotel' ),
		wc_price( $captured ),
		wc_price( $total ),
		wc_price( round( $captured - $total, 2 ) )
	);

	// update_status persists the order (incl. the marker meta set above).
	$order->update_status( 'on-hold', $note );
}
add_action( 'woocommerce_payment_complete', 'fishotel_gc_guard_capture_amount', 999 );

/**
 * Remove PPCP hosted-card gateways from the checkout gateway list when a
 * gift card balance is applied. PPCP's Card and Advanced Card gateways
 * both charge from PayPal's itemized purchase-unit breakdown, which omits
 * WC Gift Cards' cart-total-level deduction — so a partial-coverage card
 * checkout ends up either double-charging (order #34494) or failing on
 * return with "payment gateway currently unavailable" (order #34530).
 *
 * Server-side is authoritative: CSS-hiding leaves the gateway selectable
 * for anyone bypassing our JS/CSS. The standard PayPal redirect gateway
 * (`ppcp-gateway`) stays available — it builds its purchase unit from the
 * WC order total (post-gift-card) and captures correctly.
 *
 * @param array $gateways
 * @return array
 */
function fishotel_gc_maybe_remove_ppcp_card_gateway( $gateways ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return $gateways;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return $gateways;
	}
	if ( ! fishotel_gc_balance_applied_now() ) {
		return $gateways;
	}

	// PPCP's hosted card gateway IDs across recent PPCP versions.
	// Kept as an allowlist for filterability.
	$blocked = apply_filters( 'fishotel_gc_blocked_gateways', array(
		'ppcp-card-button-gateway',       // "Debit & Credit Cards" (primary — the one that broke #34530)
		'ppcp-credit-card-gateway',       // Older Advanced Card Payments ID
		'ppcp-axo-gateway',               // Fastlane / Axo
	) );

	foreach ( $blocked as $id ) {
		if ( isset( $gateways[ $id ] ) ) {
			unset( $gateways[ $id ] );
		}
	}
	return $gateways;
}
add_filter( 'woocommerce_available_payment_gateways', 'fishotel_gc_maybe_remove_ppcp_card_gateway', 20 );

/**
 * True when a customer has a gift card balance applied to the current cart.
 *
 * WC Gift Cards v2.7.x doesn't expose a single documented session key we
 * can lean on, so this function checks in order of preference:
 *   1. The plugin's public cart API (WC_GC()->cart) if the object exposes
 *      an applied-amount / is-using-balance method.
 *   2. Known WC session key candidates observed in the wild
 *      (wc_gc_use_balance, wc_gc_applied_amount, wc_gc_giftcards_amount).
 *   3. Cart-total delta — WC Gift Cards stores its total on the totals
 *      array under `gift_cards_total` (v2.7.x). Coupon totals live under
 *      `discount_total` and aren't included here, so this doesn't
 *      false-positive on coupons.
 *
 * @return bool
 */
function fishotel_gc_balance_applied_now() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return false;
	}

	// 1. Plugin's public API (if available).
	if ( function_exists( 'WC_GC' ) ) {
		$gc = WC_GC();
		if ( is_object( $gc ) && isset( $gc->cart ) && is_object( $gc->cart ) ) {
			foreach ( array( 'get_applied_amount', 'get_used_amount', 'get_giftcards_total' ) as $m ) {
				if ( method_exists( $gc->cart, $m ) ) {
					$amount = (float) $gc->cart->{$m}();
					if ( $amount > 0 ) {
						return true;
					}
				}
			}
			foreach ( array( 'is_using_balance', 'has_applied_giftcards' ) as $m ) {
				if ( method_exists( $gc->cart, $m ) && (bool) $gc->cart->{$m}() ) {
					return true;
				}
			}
		}
	}

	// 2. Session key candidates.
	if ( WC()->session ) {
		$candidates = apply_filters( 'fishotel_gc_session_key_candidates', array(
			'wc_gc_use_balance',
			'wc_gc_applied_amount',
			'wc_gc_giftcards_amount',
			'wc_gc_applied_giftcards',
		) );
		foreach ( $candidates as $key ) {
			$val = WC()->session->get( $key );
			if ( is_numeric( $val ) && (float) $val > 0 ) {
				return true;
			}
			if ( 'on' === $val || true === $val || '1' === $val ) {
				return true;
			}
			if ( is_array( $val ) && ! empty( $val ) ) {
				return true;
			}
		}
	}

	// 3. Cart totals delta — see docblock note on coupon isolation.
	$totals = WC()->cart->get_totals();
	if ( is_array( $totals ) ) {
		foreach ( array( 'gift_cards_total', 'giftcards_total', 'gift_card_total' ) as $k ) {
			if ( isset( $totals[ $k ] ) && (float) $totals[ $k ] > 0 ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Restore gift-card balance for cards debited on this order when the order
 * transitions to a non-completing state (failed, cancelled, trash) without
 * ever reaching a paid status. WC Gift Cards debits at
 * checkout_order_processed (see the "Used" activity type), before payment
 * completes, so a payment-gateway failure leaves the card drained — the
 * plugin does not auto-reverse.
 *
 * Idempotent via a marker meta so re-transitioning the order doesn't
 * double-credit.
 *
 * @param int    $order_id
 * @param string $old_status
 * @param string $new_status
 */
function fishotel_gc_restore_on_payment_failure( $order_id, $old_status, $new_status ) {
	$reversible = array( 'failed', 'cancelled', 'trash' );
	if ( ! in_array( $new_status, $reversible, true ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	if ( $order->get_meta( '_fishotel_gc_reversed' ) === 'yes' ) {
		return;
	}
	// Guard: only reverse if the order never reached a paid status.
	// "completed", "processing", "on-hold" all imply we shouldn't roll back.
	if ( in_array( $old_status, array( 'completed', 'processing', 'on-hold', 'fulfillment' ), true ) ) {
		return;
	}
	if ( ! class_exists( 'WC_GC_Gift_Card' ) ) {
		return;
	}

	// Iterate the activity log for this order to find `used`-type debits.
	global $wpdb;
	$activity_table = $wpdb->prefix . 'woocommerce_gc_activity';
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT gc_id, amount FROM `$activity_table`
		 WHERE object_id = %d AND type = %s",
		$order_id,
		'used'
	) );
	if ( empty( $rows ) ) {
		return;
	}

	$any_credited = false;
	foreach ( $rows as $row ) {
		$gc = new WC_GC_Gift_Card( (int) $row->gc_id );
		if ( ! $gc->get_id() ) {
			continue;
		}
		$amt = (float) $row->amount;
		if ( $amt <= 0 ) {
			continue;
		}
		// credit() logs a 'refunded' activity row, matching how the plugin
		// logs a customer refund. Same reversal semantics.
		if ( $gc->credit( $amt, $order, true ) ) {
			$any_credited = true;
			$order->add_order_note( sprintf(
				/* translators: 1: amount, 2: code, 3: old status, 4: new status */
				__( 'FisHotel safeguard: reversed $%1$s debit on gift card %2$s (order transitioned %3$s → %4$s without payment).', 'fishotel' ),
				number_format_i18n( $amt, 2 ),
				$gc->get_code(),
				$old_status,
				$new_status
			) );
		}
	}

	if ( $any_credited ) {
		$order->update_meta_data( '_fishotel_gc_reversed', 'yes' );
		$order->save();
	}
}
add_action( 'woocommerce_order_status_changed', 'fishotel_gc_restore_on_payment_failure', 20, 3 );
