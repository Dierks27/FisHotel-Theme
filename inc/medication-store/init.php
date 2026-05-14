<?php
/**
 * FisHotel Medication Store — bootstrap
 *
 * Universal product model: every medication is a WooCommerce variable
 * product with a `_fishotel_fulfillment` meta field that drives all
 * conditional behavior. Modes: `ea` (default — synced from EA wholesale
 * via REST), `amazon` (affiliate buy-out), `self` (Jeff stocks himself).
 *
 * Flipping the mode field instantly rewires the product page — no
 * rebuild, no migration. See FisHotel Medication Store build spec
 * (Phase 1) for the full rationale.
 *
 * Module layout mirrors inc/calculator/ and inc/scheduler/ — a single
 * init.php that loads the rest. Keep all medication-store-specific code
 * inside this directory so it can be ripped out cleanly later.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'FISHOTEL_MED_DIR' ) ) {
	define( 'FISHOTEL_MED_DIR', FISHOTEL_THEME_DIR . '/inc/medication-store' );
}

require_once FISHOTEL_MED_DIR . '/helpers.php';
require_once FISHOTEL_MED_DIR . '/taxonomy.php';
require_once FISHOTEL_MED_DIR . '/settings.php';
require_once FISHOTEL_MED_DIR . '/product-meta.php';
require_once FISHOTEL_MED_DIR . '/pricing.php';
require_once FISHOTEL_MED_DIR . '/ea-rest-client.php';
require_once FISHOTEL_MED_DIR . '/stock-sync.php';
require_once FISHOTEL_MED_DIR . '/disclaimer.php';
require_once FISHOTEL_MED_DIR . '/product-display.php';
require_once FISHOTEL_MED_DIR . '/packing-slip.php';

FisHotel_Med_Taxonomy::init();
FisHotel_Med_Settings::init();
FisHotel_Med_Product_Meta::init();
FisHotel_Med_Pricing::init();
FisHotel_Med_Stock_Sync::init();
FisHotel_Med_Disclaimer::init();
FisHotel_Med_Product_Display::init();
FisHotel_Med_Packing_Slip::init();

// WP-CLI commands (Phase 1.5 importer + Phase 2 bulk helpers) — only
// loaded under wp-cli so the command classes don't sit in memory on
// every web request. The bulk helpers are registered as explicit
// subcommands so they slot in alongside the importer's auto-discovered
// methods under the same `fishotel-meds` namespace.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once FISHOTEL_MED_DIR . '/cli/class-importer-cli.php';
	require_once FISHOTEL_MED_DIR . '/cli/class-bulk-cli.php';
	WP_CLI::add_command( 'fishotel-meds', 'FisHotel_Med_Importer_CLI' );
	WP_CLI::add_command( 'fishotel-meds flatten-categories', array( 'FisHotel_Med_Bulk_CLI', 'flatten_categories' ) );
	WP_CLI::add_command( 'fishotel-meds tags-init', array( 'FisHotel_Med_Bulk_CLI', 'tags_init' ) );
}

/**
 * Enqueue medication-store frontend assets:
 *   - Disclaimer modal CSS + JS on Medications archive and any
 *     single product where the product is a medication.
 *   - Always loaded on cart/checkout when meds are present so the
 *     checkout checkbox styling is applied.
 *
 * Loaded with fishotel_asset_version() so on-disk changes bust caches.
 */
function fishotel_med_enqueue_frontend() {
	$needs = false;
	if ( fishotel_med_is_med_archive() ) {
		$needs = true;
	} elseif ( is_product() ) {
		$pid = get_queried_object_id();
		if ( fishotel_med_is_med_product( $pid ) ) {
			$needs = true;
		}
	} elseif ( ( function_exists( 'is_cart' ) && is_cart() )
		|| ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
		if ( fishotel_med_cart_has_meds() ) {
			$needs = true;
		}
	}
	if ( ! $needs ) {
		return;
	}

	wp_enqueue_style(
		'fishotel-medication-store',
		FISHOTEL_THEME_URI . '/assets/css/medication-store.css',
		[ 'fishotel-style' ],
		fishotel_asset_version( 'assets/css/medication-store.css' )
	);
	wp_enqueue_script(
		'fishotel-medication-store',
		FISHOTEL_THEME_URI . '/assets/js/medication-store.js',
		[],
		fishotel_asset_version( 'assets/js/medication-store.js' ),
		true
	);
	wp_localize_script( 'fishotel-medication-store', 'fishotelMedData', [
		'cookieName'   => 'fishotel_med_disclaimer_accepted',
		'cookieDays'   => (int) FisHotel_Med_Settings::get( 'fishotel_med_disclaimer_days' ),
		'homeUrl'      => home_url( '/' ),
		'modalTitle'   => 'FOR ORNAMENTAL USE ONLY',
		'modalBody'    => FisHotel_Med_Settings::get_disclaimer_body(),
		'agreeLabel'   => 'I Agree — Show Me the Pharmacy',
		'declineLabel' => 'Decline',
		'isArchive'    => fishotel_med_is_med_archive(),
		'isMedPdp'     => is_product() && fishotel_med_is_med_product( get_queried_object_id() ),
	] );
}
add_action( 'wp_enqueue_scripts', 'fishotel_med_enqueue_frontend' );

/**
 * Admin assets — meta box JS (mode-driven field visibility) and CSS.
 * Loaded only on product edit screens and on the medication-store
 * Settings sub-page.
 */
function fishotel_med_enqueue_admin( $hook ) {
	$is_product_edit = in_array( $hook, [ 'post.php', 'post-new.php' ], true )
		&& ( get_post_type() === 'product' || ( isset( $_GET['post_type'] ) && $_GET['post_type'] === 'product' ) );
	$is_settings = isset( $_GET['page'] ) && $_GET['page'] === 'fishotel-medication-store';
	$is_order    = in_array( $hook, [ 'post.php', 'post-new.php' ], true ) && get_post_type() === 'shop_order';
	// WC HPOS order screens
	if ( ! $is_order && isset( $_GET['page'] ) && $_GET['page'] === 'wc-orders' ) {
		$is_order = true;
	}

	if ( ! $is_product_edit && ! $is_settings && ! $is_order ) {
		return;
	}
	wp_enqueue_style(
		'fishotel-medication-store-admin',
		FISHOTEL_THEME_URI . '/assets/css/medication-store-admin.css',
		[],
		fishotel_asset_version( 'assets/css/medication-store-admin.css' )
	);
	wp_enqueue_script(
		'fishotel-medication-store-admin',
		FISHOTEL_THEME_URI . '/assets/js/medication-store-admin.js',
		[ 'jquery' ],
		fishotel_asset_version( 'assets/js/medication-store-admin.js' ),
		true
	);
	wp_localize_script( 'fishotel-medication-store-admin', 'fishotelMedAdmin', [
		'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		'nonceSync' => wp_create_nonce( 'fishotel_med_sync_stock' ),
	] );
}
add_action( 'admin_enqueue_scripts', 'fishotel_med_enqueue_admin' );
