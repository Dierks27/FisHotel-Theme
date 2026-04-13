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
		add_action( 'admin_menu',                       [ __CLASS__, 'add_tools_page' ] );
		add_action( 'wp_ajax_fishotel_run_migration', [ __CLASS__, 'handle_migration' ] );
	}

	// ── Tools page ──────────────────────────
	public static function add_tools_page() {
		add_submenu_page(
			'edit.php?post_type=product',
			'FisHotel Tools',
			'FisHotel Tools',
			'manage_woocommerce',
			'fishotel-tools',
			[ __CLASS__, 'render_tools_page' ]
		);
	}

	public static function render_tools_page() {
		?>
		<div class="wrap">
			<h1>FisHotel Tools</h1>

			<div style="background:#fff; border:1px solid #ccd0d4; border-left:4px solid #c9963a; padding:20px 24px; margin:20px 0; max-width:600px;">
				<h2 style="margin-top:0; font-size:16px;">Migrate Product Descriptions &rarr; Custom Fields</h2>
				<p style="color:#666;">Reads every product description and auto-fills the Species Info and Care Guide custom fields. Safe to run multiple times &mdash; never overwrites fields you've already filled in.</p>

				<button id="fh-run-migration" class="button button-primary" style="background:#c9963a; border-color:#b8862f; font-size:13px; padding:6px 20px;">
					Run Migration Now
				</button>
				<div id="fh-migration-result" style="margin-top:12px;"></div>
			</div>
		</div>

		<script>
		document.getElementById('fh-run-migration').addEventListener('click', function() {
			var btn = this;
			var resultDiv = document.getElementById('fh-migration-result');
			btn.disabled = true;
			btn.textContent = 'Running... please wait';
			resultDiv.innerHTML = '';

			fetch(ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=fishotel_run_migration&_wpnonce=<?php echo wp_create_nonce( "fishotel_run_migration" ); ?>'
			})
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.success) {
					resultDiv.innerHTML = '<div class="notice notice-success" style="padding:10px 14px;"><p>' + data.data.message + '</p></div>';
				} else {
					resultDiv.innerHTML = '<div class="notice notice-error" style="padding:10px 14px;"><p>Error: ' + (data.data || 'Unknown error') + '</p></div>';
				}
				btn.disabled = false;
				btn.textContent = 'Run Migration Now';
			})
			.catch(function(err) {
				resultDiv.innerHTML = '<div class="notice notice-error" style="padding:10px 14px;"><p>Error: ' + err + '</p></div>';
				btn.disabled = false;
				btn.textContent = 'Run Migration Now';
			});
		});
		</script>
		<?php
	}

	public static function handle_migration() {
		@ini_set( 'memory_limit', '256M' );
		@set_time_limit( 120 );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}
		check_ajax_referer( 'fishotel_run_migration' );

		// Migration runs inline below
		$products = wc_get_products( [ 'limit' => -1, 'status' => 'publish' ] );
		$migrated = 0;
		$skipped  = 0;

		$extract = function( $patterns, $text ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $text, $m ) ) {
					$val = trim( $m[1] );
					$val = preg_replace( '/\s+(Scientific|Common|Maximum|Minimum|Reef|Temperament|Foods|Habitat|Fun\s+Facts?).*$/is', '', $val );
					return $val;
				}
			}
			return '';
		};

		// Single-line fields — safe regex (no backtracking risk)
		$fields_map = [
			'_fh_scientific_name' => [ '/Scientific Name\s*[:-]\s*([^\n]+)/i' ],
			'_fh_common_names'    => [ '/Common Names?\s*[:-]\s*([^\n]+)/i' ],
			'_fh_max_length'      => [ '/Maximum?\s*Lengths?\s*[:-]\s*([^\n]+)/i', '/Max\.?\s*Lengths?\s*[:-]\s*([^\n]+)/i' ],
			'_fh_min_tank_size'   => [ '/Minimum\s*Aquarium\s*Sizes?\s*[:-]\s*([^\n]+)/i', '/Min\.?\s*Tank\s*Sizes?\s*[:-]\s*([^\n]+)/i' ],
			'_fh_temperament'     => [ '/Temperament\s*[:-]\s*([^\n]+)/i' ],
			'_fh_region'          => [ '/Region\s*[:-]\s*([^\n]+)/i' ],
		];

		// Multi-line fields: find label line, collect following lines until next known label
		// This avoids catastrophic backtracking from [\s\S]+? patterns
		$multiline_map = [
			'_fh_foods_feeding' => [ 'Foods and Feeding Habits', 'Foods and Feeding', 'Feeding' ],
			'_fh_habitat'       => [ 'Habitat and Behavior', 'Habitat & Behavior', 'Habitat', 'Habits' ],
		];
		$stop_at = [ 'Scientific Name', 'Common Names', 'Maximum', 'Minimum Aquarium',
		             'Temperament', 'Reef Safety', 'Reef Safe', 'Description', 'Fun Facts',
		             'Region', 'Foods and Feeding', 'Habitat', 'Habits', 'Feeding' ];

		foreach ( $products as $product ) {
			$id   = $product->get_id();
			$desc = wp_strip_all_tags( $product->get_description() );
			if ( empty( $desc ) ) { $skipped++; continue; }

			$updated = false;

			// Single-line fields
			foreach ( $fields_map as $key => $patterns ) {
				if ( get_post_meta( $id, $key, true ) ) continue;
				$value = $extract( $patterns, $desc );
				if ( $value ) {
					update_post_meta( $id, $key, sanitize_textarea_field( $value ) );
					$updated = true;
				}
			}

			// Multi-line fields: line-by-line parsing (no backtracking risk)
			$lines = explode( "\n", $desc );
			foreach ( $multiline_map as $key => $labels ) {
				if ( get_post_meta( $id, $key, true ) ) continue;
				$collecting = false;
				$collected  = [];
				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( ! $collecting ) {
						foreach ( $labels as $label ) {
							if ( stripos( $line, $label . ':' ) === 0 || stripos( $line, $label . ' -' ) === 0 ) {
								$collecting = true;
								$after = trim( preg_replace( '/^' . preg_quote( $label, '/' ) . '\s*[:\-]\s*/i', '', $line ) );
								if ( $after ) $collected[] = $after;
								break;
							}
						}
					} else {
						// Stop at next known label
						$stop = false;
						foreach ( $stop_at as $s ) {
							if ( stripos( $line, $s . ':' ) === 0 || stripos( $line, $s . ' -' ) === 0 ) {
								$stop = true; break;
							}
						}
						if ( $stop ) break;
						if ( $line ) $collected[] = $line;
					}
				}
				if ( ! empty( $collected ) ) {
					update_post_meta( $id, $key, sanitize_textarea_field( implode( ' ', $collected ) ) );
					$updated = true;
				}
			}

			if ( ! get_post_meta( $id, '_fh_reef_safe', true ) ) {
				if ( preg_match( '/Reef[\s\-]?Safe(?:ty)?\s*[:-]\s*([^\n]+)/i', $desc, $m ) ) {
					$val = strtolower( trim( $m[1] ) );
					$reef = 'yes';
					if ( strpos( $val, 'caution' ) !== false ) $reef = 'caution';
					elseif ( strpos( $val, 'no' ) !== false )  $reef = 'no';
					update_post_meta( $id, '_fh_reef_safe', $reef );
					$updated = true;
				}
			}

			if ( $updated ) $migrated++; else $skipped++;
		}

		wp_send_json_success( [
			'message' => "Migration complete: {$migrated} products updated, {$skipped} skipped.",
		] );
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
