<?php
/**
 * FisHotel WooCommerce integration
 * TODO: Build full templates in Phase 2
 *
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;

// Remove default WooCommerce wrappers — we handle layout
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content',  'woocommerce_output_content_wrapper_end', 10 );
remove_action( 'woocommerce_sidebar',             'woocommerce_get_sidebar', 10 );

// Add our wrappers
add_action( 'woocommerce_before_main_content', function() {
    echo '<div class="fh-woocommerce-wrap">';
} );
add_action( 'woocommerce_after_main_content', function() {
    echo '</div>';
} );

// Product loop columns
add_filter( 'loop_shop_columns', function() { return 4; } );

// Products per page
add_filter( 'loop_shop_per_page', function() { return 16; } );

/*
 * Strip PayPal Pay Later / Pay-in-4 messaging from the PDP summary hook.
 * The WooCommerce PayPal Payments plugin registers a message-renderer
 * callback against woocommerce_single_product_summary. We don't want
 * that line above the purchase panel. Run on template_redirect so the
 * plugin has finished registering its hooks before we strip them.
 */
add_action( 'template_redirect', function () {
	if ( ! is_product() ) {
		return;
	}
	if ( empty( $GLOBALS['wp_filter']['woocommerce_single_product_summary'] ) ) {
		return;
	}
	$hook = $GLOBALS['wp_filter']['woocommerce_single_product_summary'];
	if ( ! is_object( $hook ) || empty( $hook->callbacks ) ) {
		return;
	}
	foreach ( $hook->callbacks as $priority => $cbs ) {
		foreach ( $cbs as $key => $cb ) {
			$fn = isset( $cb['function'] ) ? $cb['function'] : null;
			$class  = '';
			$method = '';
			if ( is_array( $fn ) ) {
				$class  = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
				$method = isset( $fn[1] ) ? (string) $fn[1] : '';
			} elseif ( is_string( $fn ) ) {
				$method = $fn;
			}
			$is_paypal = ( stripos( $class, 'PayPal' ) !== false );
			$is_message = (
				stripos( $class, 'Message' ) !== false ||
				stripos( $method, 'message' ) !== false ||
				stripos( $method, 'pay_later' ) !== false ||
				stripos( $method, 'paylater' ) !== false
			);
			if ( $is_paypal && $is_message ) {
				unset( $hook->callbacks[ $priority ][ $key ] );
			}
		}
	}
}, 1 );

/*
 * Suppress the WC default "Your cart is currently empty." notice. WC
 * (7.0+) renders it via `wc_empty_cart_message` hooked to the
 * `woocommerce_cart_is_empty` action. Our cart-empty.php template
 * provides its own headline + body + CTA, and the default line was
 * stacking under that card with a stray gold accent bar.
 */
add_action( 'init', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	remove_action( 'woocommerce_cart_is_empty', 'wc_empty_cart_message', 10 );
}, 20 );

/*
 * Checkout — "Ship to a different address" defaults to UNCHECKED. The
 * WC default is on, which forces the shipping form open even when the
 * customer just wants delivery to the billing address. Most carts have
 * the same delivery + billing address, so off is the saner default.
 */
add_filter( 'woocommerce_ship_to_different_address_checked', '__return_false' );

/*
 * Checkout — strip WC's default "Returning customer? Click here to
 * login." and "Have a coupon? Click here to enter your code." notices.
 * Our form-checkout.php template renders both as collapsed accordions
 * (matching the cart's pattern), so the default top-of-page notices
 * would duplicate.
 */
add_action( 'init', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10 );
	remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
}, 20 );

/*
 * Checkout — Place Order button label comes from FisHotel Settings
 * (Checkout Page → CTAs & Labels → fh_checkout_cta_label). Trailing
 * arrow appended automatically.
 */
add_filter( 'woocommerce_order_button_text', function ( $default ) {
	if ( ! class_exists( 'FisHotel_Admin_Settings' ) ) {
		return $default;
	}
	$label = (string) FisHotel_Admin_Settings::get( 'fh_checkout_cta_label' );
	return $label !== '' ? $label . ' →' : $default;
} );

/*
 * Checkout — kill WC core's duplicate `wc_checkout_payment()` invocation.
 *
 * WC core wires the payment box (#payment + Place Order + nonce) to the
 * `woocommerce_checkout_order_review` action at priority 20:
 *
 *   add_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );
 *
 * Our Folio layout (PR #26) splits payment out of the right-column order
 * review into its own card in the LEFT column. form-checkout.php calls
 * `wc_checkout_payment()` directly inside the payment card AND still
 * fires `do_action( 'woocommerce_checkout_order_review' )` in the right
 * column so plugins hooked to that canonical injection point (gift
 * cards, store credit, etc.) still see it. Without this remove_action,
 * `wc_checkout_payment()` runs TWICE per request — once in the left
 * column, once via the priority-20 hook in the right column.
 *
 * Two consecutive invocations re-run every gateway's `payment_fields()`
 * and duplicate `<button id="place_order">` / the
 * `woocommerce-process-checkout-nonce` hidden field. PayPal Payments,
 * gift card gateways, and other payment plugins that set up per-render
 * state (cart token, fraud session id, button SDK init) assume a single
 * call per request and fatal on the second pass — which is the root
 * cause of the "There has been a critical error" the checkout has been
 * throwing since 1.8.4. Visually it presents as the page rendering
 * cleanly through the first gateway's payment box / gift card form,
 * then dying before the Place Order button (which lives inside the
 * second, fatal-time invocation).
 *
 * Removing the priority-20 hook lets review-order.php (the priority-10
 * hook) still fire so the itemized statement renders, while the payment
 * box only renders once — in the left-column card where we want it.
 *
 * Restricted to `is_checkout()` so the unhook doesn't leak to any other
 * caller of the `woocommerce_checkout_order_review` action.
 */
add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}
	remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );
}, 1 );

/*
 * ─────────────────────────────────────────
 * STANDARD WC HOOK COMPATIBILITY
 *
 * Our custom PDP and loop templates (woocommerce/single-product.php,
 * archive-product.php, front-page.php, search.php) build their own
 * markup. They still fire the canonical WC hooks so plugins (e.g.
 * FisHotel Misc Coming Soon) can inject UI at the standard injection
 * points.
 *
 * To keep our layout clean, we remove the default WC callbacks that
 * would otherwise re-render duplicate title/price/add-to-cart/image
 * blocks. Plugins hooking in at any priority still run normally.
 *
 * Runs late on init so WC has registered its callbacks first.
 * ─────────────────────────────────────────
 */
add_action( 'init', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    // Single product summary defaults — title, rating, price, excerpt,
    // add-to-cart, meta, sharing.
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title',       5  );
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating',      10 );
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price',       10 );
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt',     20 );
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta',        40 );
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_sharing',     50 );

    // Before/after summary — sale flash, gallery images, tabs, upsells,
    // related products. We render our own gallery and a custom related
    // row, so suppress these.
    remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );
    remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images',     20 );
    remove_action( 'woocommerce_after_single_product_summary',  'woocommerce_output_product_data_tabs', 10 );
    remove_action( 'woocommerce_after_single_product_summary',  'woocommerce_upsell_display',           15 );
    remove_action( 'woocommerce_after_single_product_summary',  'woocommerce_output_related_products',  20 );

    // Loop card defaults — anchor open/close, sale flash, thumbnail,
    // title, rating, price, add-to-cart link. Our card builds all of
    // this manually inside its own anchor.
    remove_action( 'woocommerce_before_shop_loop_item',       'woocommerce_template_loop_product_link_open',  10 );
    remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash',     10 );
    remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail',  10 );
    remove_action( 'woocommerce_shop_loop_item_title',        'woocommerce_template_loop_product_title',      10 );
    remove_action( 'woocommerce_after_shop_loop_item_title',  'woocommerce_template_loop_rating',             5  );
    remove_action( 'woocommerce_after_shop_loop_item_title',  'woocommerce_template_loop_price',              10 );
    remove_action( 'woocommerce_after_shop_loop_item',        'woocommerce_template_loop_product_link_close', 5  );
    remove_action( 'woocommerce_after_shop_loop_item',        'woocommerce_template_loop_add_to_cart',        10 );
}, 20 );

/**
 * TEMPORARY — diagnostic dump for the WC Gift Cards balance-checkbox
 * bug. Logged-in customers with a positive account balance should see
 * a "Use $XX.XX from your Gift Cards balance" checkbox at checkout;
 * the code-entry form (`.add_gift_card_form`) renders, but the
 * balance checkbox doesn't. Working theory is execution-order: our
 * two-column layout calls `woocommerce_checkout_payment()` (which
 * fires `woocommerce_review_order_before_payment`) BEFORE the right
 * column fires `woocommerce_checkout_order_review`, so the plugin's
 * balance callback bails on a `did_action()` check the code-entry
 * callback doesn't run.
 *
 * Behind `?fhdebug_gc=1` for site admins only — output is an HTML
 * comment block, view-source on `/checkout/?fhdebug_gc=1` to read it.
 * Remove once we've identified the failing condition.
 */
add_action( 'wp_footer', function () {
	if ( ! current_user_can( 'manage_options' ) ) return;
	if ( ! isset( $_GET['fhdebug_gc'] ) ) return;
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;

	$hooks = [
		'woocommerce_checkout_before_order_review',
		'woocommerce_checkout_order_review',
		'woocommerce_checkout_after_order_review',
		'woocommerce_review_order_before_cart_contents',
		'woocommerce_review_order_after_cart_contents',
		'woocommerce_review_order_before_shipping',
		'woocommerce_review_order_after_shipping',
		'woocommerce_review_order_before_order_total',
		'woocommerce_review_order_after_order_total',
		'woocommerce_review_order_before_payment',
		'woocommerce_review_order_after_payment',
		'woocommerce_review_order_before_submit',
		'woocommerce_review_order_after_submit',
		'woocommerce_checkout_after_customer_details',
		'woocommerce_checkout_before_customer_details',
	];

	echo "\n<!-- FH-GC-DEBUG START -->\n";
	foreach ( $hooks as $h ) {
		echo '<!-- HOOK ' . $h . ' (did_action=' . (int) did_action( $h ) . ') -->' . "\n";
		if ( empty( $GLOBALS['wp_filter'][ $h ] ) ) {
			echo "<!--   (no callbacks) -->\n";
			continue;
		}
		$hook = $GLOBALS['wp_filter'][ $h ];
		if ( ! is_object( $hook ) || empty( $hook->callbacks ) ) continue;
		foreach ( $hook->callbacks as $priority => $cbs ) {
			foreach ( $cbs as $cb ) {
				$fn = $cb['function'] ?? null;
				if ( is_array( $fn ) ) {
					$name = ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . (string) ( $fn[1] ?? '' );
				} elseif ( is_string( $fn ) ) {
					$name = $fn;
				} elseif ( $fn instanceof Closure ) {
					$name = 'Closure';
				} else {
					$name = '(unknown)';
				}
				$is_gc  = (bool) preg_match( '/(wc_gc|giftcard|gift_card|gift-card|gc_card|gc_balance|use_balance)/i', $name );
				$marker = $is_gc ? '  [GC]' : '';
				echo '<!--   p=' . (int) $priority . $marker . ' ' . $name . ' -->' . "\n";
			}
		}
	}

	$cart  = function_exists( 'WC' ) && WC() ? WC()->cart : null;
	$total = $cart ? wp_strip_all_tags( $cart->get_total( '' ) ) : 'no-cart';
	echo '<!-- CART_TOTAL=' . $total . ' -->' . "\n";
	echo '<!-- USER_ID=' . get_current_user_id() . ' -->' . "\n";
	echo '<!-- IS_USER_LOGGED_IN=' . ( is_user_logged_in() ? '1' : '0' ) . ' -->' . "\n";

	$gc_fns = [ 'wc_gc_get_account_balance', 'wc_gc_get_balance', 'wc_gc', 'WC_GC' ];
	foreach ( $gc_fns as $fn ) {
		echo '<!-- FUNCTION_EXISTS ' . $fn . '=' . ( function_exists( $fn ) ? '1' : '0' ) . ' -->' . "\n";
	}
	foreach ( [ 'WC_GC', 'WC_GC_Checkout', 'WC_GC_Cart', 'WC_GC_Account', 'WC_GC_Manager' ] as $cls ) {
		echo '<!-- CLASS_EXISTS ' . $cls . '=' . ( class_exists( $cls ) ? '1' : '0' ) . ' -->' . "\n";
	}

	if ( function_exists( 'wc_gc_get_account_balance' ) ) {
		echo '<!-- GC_ACCOUNT_BALANCE=' . esc_html( (string) wc_gc_get_account_balance( get_current_user_id() ) ) . ' -->' . "\n";
	}
	if ( WC()->session ) {
		foreach ( [ 'wc_gc_use_balance', 'wc_gc_applied_giftcards', 'wc_gc_giftcards' ] as $sk ) {
			$val = WC()->session->get( $sk );
			echo '<!-- SESSION ' . $sk . '=' . esc_html( is_scalar( $val ) ? (string) $val : wp_json_encode( $val ) ) . ' -->' . "\n";
		}
	}

	echo "<!-- FH-GC-DEBUG END -->\n\n";
}, 9999 );

/*
 * Render the full medications catalog on a single page so the client-
 * side dual-axis filter (assets/js/medications-filter.js) doesn't have
 * to coordinate with WC pagination. 50 is a generous ceiling for the
 * current 40-product set — server-side ajax can take over later if
 * the catalog outgrows that.
 */
add_action( 'pre_get_posts', function ( $query ) {
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }
    if ( function_exists( 'is_product_category' ) && is_product_category( 'medications' ) ) {
        $query->set( 'posts_per_page', 50 );
    }
} );

/*
 * Conditional enqueue for the Medications archive filter strip. The
 * CSS already ships in woocommerce.css (loaded site-wide), so only
 * the JS needs the conditional load.
 */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'fishotel_is_medications_archive' ) || ! fishotel_is_medications_archive() ) {
        return;
    }
    wp_enqueue_script(
        'fishotel-medications-filter',
        FISHOTEL_THEME_URI . '/assets/js/medications-filter.js',
        [],
        fishotel_asset_version( 'assets/js/medications-filter.js' ),
        true
    );
}, 20 );

