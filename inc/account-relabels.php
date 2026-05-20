<?php
/**
 * FisHotel — My Account relabels, copy, and page-header lookup.
 *
 * Single home for every visible string in the My Account section:
 *  - Hotel-metaphor menu label renames (filter + shared label map).
 *  - gettext overrides for greeting / empty-order / browse strings.
 *  - The per-endpoint page-header title + subtitle lookup.
 *  - Admin-editable copy defaults (mirrored into FisHotel Settings).
 *
 * Endpoint URLs are never touched — only display labels — so existing
 * bookmarks and order-confirmation emails keep working.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize a registered endpoint/menu slug to our canonical slug so plugin
 * endpoints (my-wallet, gift_cards, …) line up with our labels, icons, and
 * groups.
 *
 * @param string $slug
 * @return string
 */
function fishotel_account_normalize_slug( $slug ) {
	$aliases = [
		'my-wallet'     => 'wallet',
		'house-account' => 'wallet',
		'gift_cards'    => 'gift-cards',
	];
	return $aliases[ $slug ] ?? $slug;
}

/**
 * Hotel-metaphor labels keyed by WooCommerce endpoint slug. Used both by the
 * `woocommerce_account_menu_items` filter and by our custom navigation.php so
 * the copy lives in exactly one place.
 *
 * @return array<string,string>
 */
function fishotel_account_labels() {
	return [
		'dashboard'       => __( 'The Lobby', 'fishotel' ),
		'orders'          => __( 'Past Stays', 'fishotel' ),
		'view-order'      => __( 'Reservation Details', 'fishotel' ),
		'edit-account'    => __( 'Guest Profile', 'fishotel' ),
		'edit-address'    => __( 'Forwarding Addresses', 'fishotel' ),
		'payment-methods' => __( 'Billing', 'fishotel' ),
		'customer-logout' => __( 'Check Out', 'fishotel' ),
		// Plugin endpoints (aliases handled in the filter below).
		'gift-cards'      => __( 'Gift Cards', 'fishotel' ),
		'wallet'          => __( 'House Account', 'fishotel' ),
	];
}

/**
 * Rename native WooCommerce account-menu items to the hotel metaphor. Plugin
 * endpoints register their own item keys; we relabel any that match by slug
 * or by a small alias map.
 */
add_filter( 'woocommerce_account_menu_items', function ( $items ) {
	$labels = fishotel_account_labels();
	foreach ( $items as $key => $label ) {
		$lookup = fishotel_account_normalize_slug( $key );
		if ( isset( $labels[ $lookup ] ) ) {
			$items[ $key ] = $labels[ $lookup ];
		}
	}
	return $items;
}, 20 );

/**
 * gettext overrides for the dashboard greeting / empty-order / browse copy.
 * Scoped to the account area so we don't rewrite these strings store-wide.
 */
add_filter( 'gettext', function ( $translated, $text, $domain ) {
	if ( $domain !== 'woocommerce' || is_admin() ) {
		return $translated;
	}

	switch ( $text ) {
		case 'Hello %1$s (not %1$s? %2$sLog out%3$s)':
			return str_replace( 'Hello', fishotel_account_text( 'fh_account_greeting_prefix' ), $translated );
		case 'No order has been made yet.':
			return __( "No reservations yet — let's change that.", 'fishotel' );
		case 'Browse products':
			return __( 'Browse the Lobby', 'fishotel' );
	}
	return $translated;
}, 20, 3 );

/**
 * Persist the optional Humble.Fish username from the Guest Profile form.
 * Stored as user meta only — no external sync.
 */
add_action( 'woocommerce_save_account_details', function ( $user_id ) {
	if ( ! isset( $_POST['humble_fish_username'] ) ) {
		return;
	}
	// Nonce already verified by WooCommerce's save_account_details handler.
	$username = sanitize_text_field( wp_unslash( $_POST['humble_fish_username'] ) );
	update_user_meta( $user_id, 'humble_fish_username', $username );
} );

/**
 * Per-endpoint page-header title strings. Titles stay in code (not admin-
 * editable per spec §11); subtitles are editable via FisHotel Settings.
 *
 * @return array<string,string>
 */
function fishotel_account_titles() {
	return [
		'dashboard'       => __( 'MY ACCOUNT', 'fishotel' ),
		'orders'          => __( 'PAST STAYS', 'fishotel' ),
		'view-order'      => __( 'RESERVATION DETAILS', 'fishotel' ),
		'edit-account'    => __( 'GUEST PROFILE', 'fishotel' ),
		'edit-address'    => __( 'FORWARDING ADDRESSES', 'fishotel' ),
		'payment-methods' => __( 'BILLING', 'fishotel' ),
		'gift-cards'      => __( 'GIFT CARDS', 'fishotel' ),
		'wallet'          => __( 'HOUSE ACCOUNT', 'fishotel' ),
	];
}

/**
 * Endpoint slug → subtitle option key. Drives both the FisHotel Settings
 * fields and the page-header lookup.
 *
 * @return array<string,string>
 */
function fishotel_account_subtitle_keys() {
	return [
		'dashboard'       => 'fh_account_subtitle_dashboard',
		'orders'          => 'fh_account_subtitle_orders',
		'edit-account'    => 'fh_account_subtitle_edit_account',
		'edit-address'    => 'fh_account_subtitle_edit_address',
		'payment-methods' => 'fh_account_subtitle_payment_methods',
		'gift-cards'      => 'fh_account_subtitle_gift_cards',
		'wallet'          => 'fh_account_subtitle_house_account',
	];
}

/**
 * Canonical defaults for every admin-editable My Account string. Merged into
 * FisHotel_Admin_Settings::defaults() so there is a single source of truth.
 *
 * @return array<string,string>
 */
function fishotel_account_text_defaults() {
	return [
		// Page subtitles
		'fh_account_subtitle_dashboard'       => 'Manage your reservations, profile, and concierge requests. Everything checked in for your stay.',
		'fh_account_subtitle_orders'          => 'Your complete reservation history at The FisHotel.',
		'fh_account_subtitle_edit_account'    => 'The details we keep on file for your stay.',
		'fh_account_subtitle_edit_address'    => 'Where to ship your healthy, quarantined fish.',
		'fh_account_subtitle_payment_methods' => 'Manage how you settle the bill.',
		'fh_account_subtitle_gift_cards'      => 'Manage gift cards and check balances.',
		'fh_account_subtitle_house_account'   => 'Your running tab at The FisHotel.',
		// Gift Cards sidebar link (manual, non-endpoint). Empty default → the
		// helper falls back to the gift-card product permalink at runtime.
		'fishotel_account_gift_cards_url'     => '',
		// Greeting
		'fh_account_greeting_prefix'          => 'Welcome back,',
		// Empty states
		'fh_account_empty_orders_title'       => 'No reservations yet.',
		'fh_account_empty_orders_subtitle'    => 'Your first stay is one click away.',
		'fh_account_empty_lobby_subtitle'     => 'No reservations yet — your reef awaits.',
		'fh_account_empty_payment_line'       => 'No payment methods saved.',
		// Login / Register cards
		'fh_account_login_eyebrow'            => 'EXISTING GUEST',
		'fh_account_login_title'              => 'Check In',
		'fh_account_register_eyebrow'         => 'NEW GUEST',
		'fh_account_register_title'           => 'Reserve a Room',
		// Quick Actions descriptions
		'fh_account_qa_1_desc'                => 'Your full reservation history.',
		'fh_account_qa_2_desc'                => 'Update your details on file.',
		'fh_account_qa_3_desc'                => 'Manage your shipping suites.',
	];
}

/**
 * Read an admin-editable My Account string. Prefers FisHotel Settings (so
 * edits persist), falling back to the canonical default.
 *
 * @param string $key Option key.
 * @return string
 */
function fishotel_account_text( $key ) {
	$defaults = fishotel_account_text_defaults();
	$default  = $defaults[ $key ] ?? '';
	if ( class_exists( 'FisHotel_Admin_Settings' ) ) {
		$val = FisHotel_Admin_Settings::get( $key );
		if ( is_string( $val ) && trim( $val ) !== '' ) {
			return $val;
		}
	}
	$stored = get_option( $key, $default );
	return ( is_string( $stored ) && trim( $stored ) !== '' ) ? $stored : $default;
}

/**
 * URL the Gift Cards sidebar link points to. Editable via FisHotel Settings →
 * My Account; falls back to the gift-card product permalink when unset.
 *
 * @return string
 */
function fishotel_account_gift_cards_url() {
	$url = fishotel_account_text( 'fishotel_account_gift_cards_url' );
	if ( trim( (string) $url ) === '' ) {
		$url = home_url( '/my-account/gift-cards/' );
	}
	return $url;
}

/**
 * Resolve the current account endpoint slug, normalizing the empty
 * (dashboard) case and a couple of plugin aliases.
 *
 * @return string
 */
function fishotel_account_current_endpoint() {
	$endpoint = '';
	if ( function_exists( 'WC' ) && WC()->query ) {
		$endpoint = (string) WC()->query->get_current_endpoint();
	}
	if ( $endpoint === '' ) {
		return 'dashboard';
	}
	return fishotel_account_normalize_slug( $endpoint );
}

/**
 * Wrap plugin-driven endpoints (e.g. House Account, or any non-core endpoint)
 * in a styled card so they inherit the section's panel treatment. Core
 * endpoints render their own cards, so they're skipped.
 */
function fishotel_account_is_core_endpoint( $endpoint ) {
	$core = [
		'dashboard', 'orders', 'view-order', 'downloads', 'edit-account',
		'edit-address', 'payment-methods', 'add-payment-method',
		'set-default-payment-method', 'customer-logout', 'lost-password',
	];
	return in_array( $endpoint, $core, true );
}

add_action( 'woocommerce_account_content', function () {
	$endpoint = fishotel_account_current_endpoint();
	if ( fishotel_account_is_core_endpoint( $endpoint ) ) {
		return;
	}
	echo '<div class="fh-card fh-account-plugin-wrap">';
}, 5 );

add_action( 'woocommerce_account_content', function () {
	$endpoint = fishotel_account_current_endpoint();
	if ( fishotel_account_is_core_endpoint( $endpoint ) ) {
		return;
	}
	echo '</div>';
}, 50 );

/**
 * Render a status pill for an order. Colors are driven by a per-status CSS
 * class; the label uses WooCommerce's own status name so custom statuses
 * (e.g. a future "In Quarantine") flow through automatically.
 *
 * @param WC_Order $order
 * @return string
 */
function fishotel_account_status_pill( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return '';
	}
	$status = $order->get_status();                       // e.g. "processing"
	$label  = wc_get_order_status_name( $status );        // e.g. "Processing"
	return sprintf(
		'<span class="fh-pill fh-pill--%1$s"><span class="fh-pill__dot" aria-hidden="true"></span>%2$s</span>',
		esc_attr( $status ),
		esc_html( $label )
	);
}

/**
 * Render the shared page header (breadcrumb · eyebrow · title · rule ·
 * subtitle). Title + subtitle are looked up by endpoint unless explicitly
 * overridden (used by view-order for its dynamic subtitle).
 *
 * @param array $args { @type string $title @type string $subtitle }
 */
function fishotel_account_render_page_header( $args = [] ) {
	$endpoint = fishotel_account_current_endpoint();
	$titles   = fishotel_account_titles();
	$sub_keys = fishotel_account_subtitle_keys();

	$title = $args['title'] ?? ( $titles[ $endpoint ] ?? __( 'MY ACCOUNT', 'fishotel' ) );

	if ( isset( $args['subtitle'] ) ) {
		$subtitle = $args['subtitle'];
	} elseif ( $endpoint === 'view-order' ) {
		// Dynamic: "{date} · #{order_number}".
		$subtitle = '';
		$order_id = absint( get_query_var( 'view-order' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( $order ) {
			$subtitle = sprintf(
				'%1$s · #%2$s',
				wc_format_datetime( $order->get_date_created() ),
				$order->get_order_number()
			);
		}
	} elseif ( isset( $sub_keys[ $endpoint ] ) ) {
		$subtitle = fishotel_account_text( $sub_keys[ $endpoint ] );
	} else {
		$subtitle = fishotel_account_text( 'fh_account_subtitle_dashboard' );
	}

	$crumb_current = $titles[ $endpoint ] ?? $title;
	?>
	<header class="fh-account-pagehead">
		<nav class="fh-account-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'fishotel' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'HOME', 'fishotel' ); ?></a>
			<span class="sep">/</span>
			<span class="current"><?php echo esc_html( $crumb_current ); ?></span>
		</nav>
		<div class="fh-account-eyebrow"><?php esc_html_e( 'GUEST SERVICES', 'fishotel' ); ?></div>
		<h1 class="fh-account-title"><?php echo esc_html( $title ); ?></h1>
		<div class="fh-account-rule" aria-hidden="true"></div>
		<?php if ( $subtitle !== '' ) : ?>
			<p class="fh-account-subtitle"><?php echo esc_html( $subtitle ); ?></p>
		<?php endif; ?>
	</header>
	<?php
}
