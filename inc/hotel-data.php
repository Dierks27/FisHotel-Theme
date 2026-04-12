<?php
/**
 * FisHotel Hotel Data
 *
 * Simplified meta box for products — Region and Notes only.
 * Jeff lists fish AFTER QT is complete. No progress tracking needed.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Hotel_Data {

	const META_PREFIX = '_fishotel_';

	public static function init() {
		add_action( 'add_meta_boxes',                       [ __CLASS__, 'add_meta_box' ] );
		add_action( 'woocommerce_process_product_meta',     [ __CLASS__, 'save_meta' ] );
	}

	public static function add_meta_box() {
		add_meta_box(
			'fishotel_hotel_data',
			'FisHotel — Product Data',
			[ __CLASS__, 'render_meta_box' ],
			'product',
			'normal',
			'high'
		);
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'fishotel_save_hotel_data', 'fishotel_hotel_nonce' );

		$region = get_post_meta( $post->ID, self::META_PREFIX . 'region', true );
		$notes  = get_post_meta( $post->ID, self::META_PREFIX . 'notes',  true );
		?>
		<style>
			.fh-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 16px 0; }
			.fh-meta-field label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; color: #555; }
			.fh-meta-field input, .fh-meta-field textarea {
				width: 100%; padding: 7px 10px; border: 1px solid #ddd; border-radius: 3px; font-size: 13px;
			}
			.fh-meta-field textarea { min-height: 80px; resize: vertical; }
		</style>

		<div class="fh-meta-grid">
			<div class="fh-meta-field">
				<label><?php esc_html_e( 'Region', 'fishotel' ); ?></label>
				<input type="text" name="fishotel_region" value="<?php echo esc_attr( $region ); ?>" placeholder="e.g. Indo-Pacific, Caribbean, Red Sea">
			</div>
			<div class="fh-meta-field">
				<label><?php esc_html_e( 'Notes', 'fishotel' ); ?></label>
				<textarea name="fishotel_notes" placeholder="Optional notes shown below the QT certificate"><?php echo esc_textarea( $notes ); ?></textarea>
			</div>
		</div>
		<?php
	}

	public static function save_meta( $product_id ) {
		if ( ! isset( $_POST['fishotel_hotel_nonce'] ) || ! wp_verify_nonce( $_POST['fishotel_hotel_nonce'], 'fishotel_save_hotel_data' ) ) return;
		if ( ! current_user_can( 'edit_post', $product_id ) ) return;

		if ( isset( $_POST['fishotel_region'] ) ) {
			update_post_meta( $product_id, '_fishotel_region', sanitize_text_field( $_POST['fishotel_region'] ) );
		}
		if ( isset( $_POST['fishotel_notes'] ) ) {
			update_post_meta( $product_id, '_fishotel_notes', sanitize_textarea_field( $_POST['fishotel_notes'] ) );
		}
	}

	public static function get_all( $product_id ) {
		return [
			'region' => get_post_meta( $product_id, '_fishotel_region', true ),
			'notes'  => get_post_meta( $product_id, '_fishotel_notes',  true ),
		];
	}
}

FisHotel_Hotel_Data::init();
