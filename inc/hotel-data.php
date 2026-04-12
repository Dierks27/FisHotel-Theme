<?php
/**
 * FisHotel Hotel Data
 *
 * Full custom fields meta box — every piece of product page data
 * comes from a dedicated field. No parsing, no regex, no guessing.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Hotel_Data {

	const PREFIX = '_fh_';

	/** All field definitions */
	private static function fields() {
		return [
			// Species Info
			'scientific_name' => [ 'label' => 'Scientific Name',    'type' => 'text',     'placeholder' => 'e.g. Sphaeramia nematoptera',           'group' => 'species' ],
			'common_names'    => [ 'label' => 'Common Names',       'type' => 'text',     'placeholder' => 'e.g. Pajama Cardinalfish, PJ Cardinal', 'group' => 'species' ],
			'max_length'      => [ 'label' => 'Max Length',         'type' => 'text',     'placeholder' => 'e.g. 3.3 inches (8.5 cm)',              'group' => 'species' ],
			'min_tank_size'   => [ 'label' => 'Min Tank Size',      'type' => 'text',     'placeholder' => 'e.g. 30 gallons',                       'group' => 'species' ],
			'temperament'     => [ 'label' => 'Temperament',        'type' => 'text',     'placeholder' => 'e.g. Peaceful, schooling',              'group' => 'species' ],
			'reef_safe'       => [ 'label' => 'Reef Safe',          'type' => 'radio',    'options' => [ 'yes' => 'Yes', 'no' => 'No', 'caution' => 'With Caution' ], 'group' => 'species' ],
			'region'          => [ 'label' => 'Region',             'type' => 'text',     'placeholder' => 'e.g. Indo-Pacific, Caribbean, Red Sea', 'group' => 'species' ],
			// Care Guide
			'foods_feeding'   => [ 'label' => 'Foods & Feeding',    'type' => 'textarea', 'placeholder' => 'What this fish eats, feeding frequency, recommended foods.', 'group' => 'care' ],
			'habitat'         => [ 'label' => 'Habitat & Behavior', 'type' => 'textarea', 'placeholder' => 'Tank requirements, temperament, compatible tankmates.',      'group' => 'care' ],
			// Internal
			'notes'           => [ 'label' => 'Notes',              'type' => 'textarea', 'placeholder' => 'Internal notes — not shown on the website.', 'group' => 'notes' ],
		];
	}

	public static function init() {
		add_action( 'add_meta_boxes',                   [ __CLASS__, 'add_meta_box' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_meta' ] );
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

		$fields = self::fields();
		$groups = [
			'species' => 'Species Info',
			'care'    => 'Care Guide (Fish Dossier)',
			'notes'   => 'Notes (not shown on website)',
		];

		?>
		<style>
			.fh-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 12px 0 0; }
			.fh-meta-field label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; color: #555; }
			.fh-meta-field input[type="text"], .fh-meta-field textarea {
				width: 100%; padding: 7px 10px; border: 1px solid #ddd; border-radius: 3px; font-size: 13px;
			}
			.fh-meta-field textarea { min-height: 80px; resize: vertical; }
			.fh-meta-section { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: #999; border-bottom: 1px solid #eee; padding-bottom: 6px; margin: 24px 0 8px; }
			.fh-meta-section:first-of-type { margin-top: 8px; }
			.fh-radio-group { display: flex; gap: 16px; margin-top: 4px; }
			.fh-radio-group label { font-weight: 400; font-size: 13px; color: #333; cursor: pointer; display: flex; align-items: center; gap: 4px; }
			.fh-radio-group input[type="radio"] { margin: 0; }
		</style>

		<?php foreach ( $groups as $group_key => $group_label ) :
			$group_fields = array_filter( $fields, function( $f ) use ( $group_key ) { return $f['group'] === $group_key; } );
			if ( empty( $group_fields ) ) continue;
		?>
			<p class="fh-meta-section"><?php echo esc_html( $group_label ); ?></p>
			<div class="fh-meta-grid">
				<?php foreach ( $group_fields as $key => $field ) :
					$meta_key = self::PREFIX . $key;
					$value = get_post_meta( $post->ID, $meta_key, true );
					$name = 'fh_' . $key;
				?>
				<div class="fh-meta-field">
					<label><?php echo esc_html( $field['label'] ); ?></label>
					<?php if ( $field['type'] === 'text' ) : ?>
						<input type="text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>">
					<?php elseif ( $field['type'] === 'textarea' ) : ?>
						<textarea name="<?php echo esc_attr( $name ); ?>" rows="4" placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
					<?php elseif ( $field['type'] === 'radio' ) : ?>
						<div class="fh-radio-group">
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="" <?php checked( $value, '' ); ?>> Not set</label>
							<?php foreach ( $field['options'] as $opt_val => $opt_label ) : ?>
								<label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $opt_val ); ?>" <?php checked( $value, $opt_val ); ?>> <?php echo esc_html( $opt_label ); ?></label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		<?php
	}

	public static function save_meta( $product_id ) {
		if ( ! isset( $_POST['fishotel_hotel_nonce'] ) || ! wp_verify_nonce( $_POST['fishotel_hotel_nonce'], 'fishotel_save_hotel_data' ) ) return;
		if ( ! current_user_can( 'edit_post', $product_id ) ) return;

		foreach ( self::fields() as $key => $field ) {
			$name     = 'fh_' . $key;
			$meta_key = self::PREFIX . $key;

			if ( ! isset( $_POST[ $name ] ) ) continue;

			if ( $field['type'] === 'textarea' ) {
				update_post_meta( $product_id, $meta_key, sanitize_textarea_field( $_POST[ $name ] ) );
			} else {
				update_post_meta( $product_id, $meta_key, sanitize_text_field( $_POST[ $name ] ) );
			}
		}
	}

	public static function get_all( $product_id ) {
		$data = [];
		foreach ( self::fields() as $key => $field ) {
			$data[ $key ] = get_post_meta( $product_id, self::PREFIX . $key, true );
		}
		return $data;
	}
}

FisHotel_Hotel_Data::init();
