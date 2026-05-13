<?php
/**
 * Medication store — pricing function wrapper.
 *
 * The math itself lives in fishotel_calculate_med_price() (helpers.php).
 * This file exposes a public class entry point + a recompute-all admin
 * action that re-walks every EA-mode variation after a wholesale or
 * retail change, so margins can be re-flowed without re-editing each
 * variation by hand.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Pricing {

	public static function init() {
		add_action( 'wp_ajax_fishotel_med_recompute_prices', [ __CLASS__, 'ajax_recompute' ] );
	}

	/**
	 * Recompute the WC price meta for every variation on a given parent
	 * product. Honors EA retail as floor. Skips non-EA modes.
	 *
	 * @return array { updated:int, skipped:int, errors:string[] }
	 */
	public static function recompute_for_product( $product_id ) {
		$out = [ 'updated' => 0, 'skipped' => 0, 'errors' => [] ];

		$mode = fishotel_med_get_mode( $product_id );
		if ( $mode !== 'ea' ) {
			$out['skipped']++;
			$out['errors'][] = sprintf( 'Product %d is in %s mode — no recompute.', $product_id, $mode );
			return $out;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			$out['skipped']++;
			$out['errors'][] = sprintf( 'Product %d is not a variable product.', $product_id );
			return $out;
		}

		$children = $product->get_children();
		$count    = count( $children );
		foreach ( $children as $i => $vid ) {
			$wholesale = (float) get_post_meta( $vid, '_fishotel_wholesale', true );
			$retail    = (float) get_post_meta( $vid, '_fishotel_ea_retail', true );
			if ( $wholesale <= 0 || $retail <= 0 ) {
				$out['skipped']++;
				continue;
			}
			$price = fishotel_calculate_med_price( $wholesale, $retail, (int) $i, $count );
			update_post_meta( $vid, '_fishotel_size_index', (int) $i );
			update_post_meta( $vid, '_fishotel_size_count', (int) $count );
			update_post_meta( $vid, '_regular_price',       $price );
			update_post_meta( $vid, '_price',               $price );
			$out['updated']++;
		}

		// Bust WC's cached price-range so the parent variable price
		// HTML refreshes on the next request.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		return $out;
	}

	public static function ajax_recompute() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		check_ajax_referer( 'fishotel_med_recompute', 'nonce' );
		$pid = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		if ( ! $pid ) {
			wp_send_json_error( 'Missing product_id' );
		}
		$result = self::recompute_for_product( $pid );
		wp_send_json_success( $result );
	}
}
