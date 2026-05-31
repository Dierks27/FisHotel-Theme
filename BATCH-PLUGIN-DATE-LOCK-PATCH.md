# Piece 1 — Checkout Delivery-Date Lock (Batch Plugin patch)

**Repo:** `Dierks27/Fishotel-Batch-Plugin`
**File:** `includes/class-woocommerce.php`
**Class:** `FisHotel_WooCommerce`

This piece can't ship from the theme repo (separate repo, and Claude Code's
GitHub access in that session was scoped to `fishotel-theme`). Apply this
patch to the Batch plugin and tag it alongside theme `1.18.9`.

It depends on the theme helper `fishotel_get_open_unshipped_order_for_customer()`
(shipped in theme 1.18.9, `inc/order-fulfillment/reorder-helpers.php`). The
patch guards every call with `function_exists()`, so an older theme simply
renders the normal date picker — no fatal.

Honors the unified kill switch **`FISHOTEL_REORDER_CHECKOUT_LOCK_OFF`** (same
constant that disables the theme's address lock + free-shipping piggyback).

---

## 1. Add a small resolver (anywhere in the class)

```php
/**
 * The current shopper's open unshipped order, or null. Mirrors the theme's
 * re-order lock resolution (customer_id for logged-in, billing email for
 * guests) and honors the unified kill switch. Cached per request.
 */
private function fishotel_reorder_open_order() {
	if ( defined( 'FISHOTEL_REORDER_CHECKOUT_LOCK_OFF' ) && FISHOTEL_REORDER_CHECKOUT_LOCK_OFF ) {
		return null;
	}
	if ( ! function_exists( 'fishotel_get_open_unshipped_order_for_customer' ) ) {
		return null;
	}
	static $cache = [];

	$uid = get_current_user_id();
	$key = $uid > 0
		? (string) $uid
		: ( ( function_exists( 'WC' ) && WC()->customer )
			? strtolower( trim( (string) WC()->customer->get_billing_email() ) )
			: '' );
	if ( '' === $key ) {
		return null;
	}
	if ( ! array_key_exists( $key, $cache ) ) {
		$cache[ $key ] = fishotel_get_open_unshipped_order_for_customer( $uid > 0 ? $uid : $key );
	}
	return $cache[ $key ];
}

/** The inherited delivery date (raw stored key) from the open order, or ''. */
private function fishotel_reorder_inherited_date() {
	$order = $this->fishotel_reorder_open_order();
	if ( ! $order instanceof WC_Order ) {
		return '';
	}
	if ( function_exists( 'fishotel_get_effective_delivery_date' ) ) {
		return (string) fishotel_get_effective_delivery_date( $order );
	}
	return (string) $order->get_meta( '_fishotel_shipping_date' );
}
```

---

## 2. Replace `fishotel_shipping_date_field()`

When the customer has an open order, skip the `<select>` entirely; render a
read-only block (Jeff's exact wording) plus a **hidden input** carrying the
inherited date, so validation + save keep working untouched.

```php
public function fishotel_shipping_date_field( $checkout ) {
	if ( ! $this->fishotel_cart_contains_fish() ) return;

	// ── Re-order lock (Piece 1): inherit the open order's date, read-only. ──
	$inherited = $this->fishotel_reorder_inherited_date();
	if ( '' !== $inherited ) {
		// The theme's re-order lock card (Piece 2a) already renders ONE unified
		// notice with the inherited address AND date. When it's active, just
		// submit the inherited date silently — don't render a duplicate block.
		$theme_card = class_exists( 'FisHotel_Reorder_Checkout_Lock' )
			&& FisHotel_Reorder_Checkout_Lock::anchor_order() instanceof WC_Order;
		if ( $theme_card ) {
			echo '<input type="hidden" name="fishotel_shipping_date" value="' . esc_attr( $inherited ) . '" />';
			return;
		}

		// Standalone block (older theme without the 2a card): full read-only notice.
		$ts      = strtotime( $inherited );
		$pretty  = $ts ? date_i18n( 'l, F j, Y', $ts ) : $inherited;
		echo '<div id="fishotel-shipping-date-field" class="fh-reorder-date-lock" style="background:#0f0f0f;border:1px solid #d4a574;border-radius:6px;padding:14px 16px;margin:0 0 16px;color:#EDE0C0;">';
		echo '<p style="margin:0 0 8px;font-weight:600;color:#EDE0C0;">' . esc_html__( "We will use your current shipping Address and Requested Delivery Date from any previous Order. If you need to change anything please visit 'My Account' after checkout.", 'fishotel' ) . '</p>';
		echo '<p style="margin:0;"><span style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#a8895f;">' . esc_html__( 'Requested delivery', 'fishotel' ) . '</span><br><span style="color:#d4a574;font-weight:600;">' . esc_html( $pretty ) . '</span></p>';
		echo '<input type="hidden" name="fishotel_shipping_date" value="' . esc_attr( $inherited ) . '" />';
		echo '</div>';
		return;
	}

	// ── Normal picker (unchanged) ──
	$available = $this->fishotel_get_available_shipping_dates();
	if ( empty( $available ) ) {
		echo '<div id="fishotel-shipping-date-field"><p style="color:#e74c3c;font-weight:700;">No delivery dates are currently available. Please contact us before placing your order.</p></div>';
		return;
	}

	echo '<div id="fishotel-shipping-date-field">';
	woocommerce_form_field( 'fishotel_shipping_date', [
		'type'     => 'select',
		'class'    => [ 'form-row-wide' ],
		'label'    => 'Select Delivery Day',
		'required' => true,
		'options'  => array_merge( [ '' => '— Choose a delivery date —' ], $available ),
	], $checkout->get_value( 'fishotel_shipping_date' ) );
	echo '</div>';
}
```

---

## 3. Let validation accept the inherited date

The inherited date may fall outside the *currently* available list (older
lead time, a date no longer offered). When locked and the posted date equals
the inherited value, accept it. Add the early-accept to **both** validators.

```php
public function fishotel_shipping_date_validate() {
	if ( ! $this->fishotel_cart_contains_fish() ) return;

	$date = sanitize_text_field( $_POST['fishotel_shipping_date'] ?? '' );

	// Locked re-order: the date is inherited, not chosen — accept it as-is.
	$inherited = $this->fishotel_reorder_inherited_date();
	if ( '' !== $inherited && $date === $inherited ) {
		return;
	}

	if ( empty( $date ) ) {
		wc_add_notice( 'Please select a delivery date.', 'error' );
		return;
	}
	$available = $this->fishotel_get_available_shipping_dates();
	if ( ! isset( $available[ $date ] ) ) {
		wc_add_notice( 'The selected delivery date is not available. Please choose a different date.', 'error' );
	}
}

public function fishotel_shipping_date_validate_backup( $data, $errors ) {
	if ( ! $this->fishotel_cart_contains_fish() ) return;

	$date = sanitize_text_field( $_POST['fishotel_shipping_date'] ?? '' );

	$inherited = $this->fishotel_reorder_inherited_date();
	if ( '' !== $inherited && $date === $inherited ) {
		return;
	}

	if ( empty( $date ) ) {
		$errors->add( 'shipping_date', 'Please select a delivery date.' );
		return;
	}
	$available = $this->fishotel_get_available_shipping_dates();
	if ( ! isset( $available[ $date ] ) ) {
		$errors->add( 'shipping_date', 'The selected delivery date is not available. Please choose a different date.' );
	}
}
```

**`fishotel_shipping_date_save()` is unchanged** — the hidden input still POSTs
`fishotel_shipping_date`, so it saves `_fishotel_shipping_date` exactly as today.

---

## Acceptance checks (from the spec)

- **#2** Repeat customer's second checkout: no date picker; read-only block with
  the existing date + Jeff's notice; hidden input POSTs the inherited date; the
  order saves with the right date.
- **#3** New customer (no open order): picker visible and selectable, as today.
- Food-only second order: no date field shown (the `fishotel_cart_contains_fish()`
  gate is unchanged) — the free-shipping piggyback still applies theme-side.
