<?php
/**
 * Medication store — manual EA stock sync.
 *
 * Phase 1 ships with a manual "Sync stock from EA" button rendered in
 * the product editor (see product-meta.php → render_panel). This file
 * handles the AJAX endpoint that fetches the EA product, updates each
 * local variation's stock_status + stock_quantity, and writes a
 * last-synced timestamp on the parent.
 *
 * Spec §5 → "If sync fails (API down, key invalid, product not found):
 * show admin notice, do NOT change stock status, log error. Stock stays
 * at last-known value."
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Stock_Sync {

	const NONCE_ACTION = 'fishotel_med_sync_stock';

	public static function init() {
		add_action( 'wp_ajax_fishotel_med_sync_stock', [ __CLASS__, 'ajax_sync' ] );
	}

	public static function ajax_sync() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$pid = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		if ( ! $pid ) {
			wp_send_json_error( 'Missing product_id' );
		}
		$result = self::sync_product( $pid );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			] );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Sync stock for a single FisHotel product from EA.
	 *
	 * Variable products: for now we update each variation's stock
	 * status to the parent's status, since the EA API exposes parent
	 * stock at the product level. Quantity is mirrored to the parent's
	 * post meta as a reference; per-variation quantity stays at its
	 * existing value unless future Phase work maps EA variations 1:1.
	 *
	 * @return array|WP_Error
	 */
	public static function sync_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'fishotel_no_product', 'Product not found.' );
		}
		if ( fishotel_med_get_mode( $product_id ) !== 'ea' ) {
			return new WP_Error( 'fishotel_not_ea', 'Stock sync only applies to EA-mode products.' );
		}

		$ea_id  = (int) get_post_meta( $product_id, '_fishotel_ea_product_id', true );
		$ea_sku = (string) get_post_meta( $product_id, '_fishotel_ea_sku', true );
		if ( $ea_id <= 0 && $ea_sku === '' ) {
			return new WP_Error( 'fishotel_no_ea_key', 'Set the EA product ID or SKU on this product first.' );
		}

		$payload = FisHotel_Med_EA_REST::fetch_product( $ea_id, $ea_sku );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$stock = FisHotel_Med_EA_REST::extract_stock( $payload );
		if ( $stock['status'] === '' ) {
			return new WP_Error( 'fishotel_ea_no_stock_field', 'EA payload missing stock_status.' );
		}

		// Apply to parent (simple products and as a reference on variable parents).
		$product->set_stock_status( $stock['status'] );
		if ( $stock['quantity'] !== null && ! $product->is_type( 'variable' ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock['quantity'] );
		}
		$product->save();

		// Apply status to all variations so out-of-stock parents
		// correctly disable Add-to-Cart per variation as well.
		$variation_count = 0;
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$variation = wc_get_product( $vid );
				if ( ! $variation ) continue;
				$variation->set_stock_status( $stock['status'] );
				$variation->save();
				$variation_count++;
			}
		}

		update_post_meta( $product_id, '_fishotel_ea_last_sync',     time() );
		update_post_meta( $product_id, '_fishotel_ea_last_status',   $stock['status'] );
		if ( $stock['quantity'] !== null ) {
			update_post_meta( $product_id, '_fishotel_ea_last_qty',  (int) $stock['quantity'] );
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}

		return [
			'status'           => $stock['status'],
			'quantity'         => $stock['quantity'],
			'variations'       => $variation_count,
			'synced_at'        => time(),
			'synced_at_label'  => wp_date( 'M j, Y g:i A' ),
		];
	}
}
