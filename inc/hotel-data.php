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
		add_action( 'wp_ajax_fishotel_run_migration', [ __CLASS__, 'handle_migration' ] );
	}

	/**
	 * Tools panels — the body of what used to be the standalone
	 * "FisHotel Tools" sub-page. Now invoked from the top-level
	 * FisHotel Theme → Tools sub-page so all admin tools share one home.
	 */
	public static function render_tools_panels() {
		?>
			<div style="background:#fff; border:1px solid #ccd0d4; border-left:4px solid #c9963a; padding:20px 24px; margin:20px 0; max-width:600px;">
				<h2 style="margin-top:0; font-size:16px;">Check for Theme Updates</h2>
				<p style="color:#666;">Clears the update cache and checks GitHub for a new version right now.</p>

				<button id="fh-check-update" class="button button-primary" style="background:#c9963a; border-color:#b8862f; font-size:13px; padding:6px 20px;">
					Check for Updates
				</button>
				<div id="fh-update-result" style="margin-top:12px;"></div>
			</div>

			<?php
			// Invoice → fish lines backfill for orders placed before theme 1.25 (inc/invoice-lines.php).
			if ( class_exists( 'FisHotel_Invoice_Lines' ) ) {
				FisHotel_Invoice_Lines::render_backfill_panel();
			}
			?>

			<div style="background:#fff; border:1px solid #ccd0d4; border-left:4px solid #c9963a; padding:20px 24px; margin:20px 0; max-width:600px;">
				<h2 style="margin-top:0; font-size:16px;">Migrate Product Descriptions &rarr; Custom Fields</h2>
				<p style="color:#666;">Reads every product description and auto-fills the Species Info and Care Guide custom fields. Safe to run multiple times &mdash; never overwrites fields you've already filled in.</p>

				<button id="fh-run-migration" class="button button-primary" style="background:#c9963a; border-color:#b8862f; font-size:13px; padding:6px 20px;">
					Run Migration Now
				</button>
				<div id="fh-migration-result" style="margin-top:12px;"></div>
			</div>

		<script>
		document.getElementById('fh-check-update').addEventListener('click', function() {
			var btn = this;
			var resultDiv = document.getElementById('fh-update-result');
			btn.disabled = true;
			btn.textContent = 'Checking...';
			resultDiv.innerHTML = '';

			fetch(ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=fishotel_check_update&_wpnonce=<?php echo wp_create_nonce( "fishotel_check_update" ); ?>'
			})
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.success) {
					var cls = data.data.update ? 'notice-warning' : 'notice-success';
					resultDiv.innerHTML = '<div class="notice ' + cls + '" style="padding:10px 14px;"><p>' + data.data.message + '</p></div>';
				} else {
					resultDiv.innerHTML = '<div class="notice notice-error" style="padding:10px 14px;"><p>Error: ' + (data.data || 'Unknown error') + '</p></div>';
				}
				btn.disabled = false;
				btn.textContent = 'Check for Updates';
			})
			.catch(function(err) {
				resultDiv.innerHTML = '<div class="notice notice-error" style="padding:10px 14px;"><p>Error: ' + err + '</p></div>';
				btn.disabled = false;
				btn.textContent = 'Check for Updates';
			});
		});

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

		// The same per-product copy the fish facts box offers
		// (inc/product-facts.php) — reads the labelled lines, fills only
		// empty fields.
		$ids      = wc_get_products( [ 'limit' => -1, 'status' => 'publish', 'return' => 'ids' ] );
		$migrated = 0;
		$skipped  = 0;
		foreach ( $ids as $id ) {
			if ( FisHotel_Product_Facts::copy_from_description( $id ) ) {
				$migrated++;
			} else {
				$skipped++;
			}
		}

		wp_send_json_success( [
			'message' => "Migration complete: {$migrated} products updated, {$skipped} skipped.",
		] );
	}

	/**
	 * Two boxes since v1.28.0:
	 *  - "Fish facts — the table on the product page" (the facts fields);
	 *  - "Staff notes — never shown on the website" (Notes only). Until
	 *    v1.28.0 the Notes were printed on the product page's quarantine
	 *    certificate although the box said they were not shown; they are
	 *    now read nowhere on the storefront (see inc/fish-notes.php for the
	 *    one-time list of fish whose note was shown).
	 */
	public static function add_meta_box() {
		add_meta_box(
			'fishotel_hotel_data',
			'Fish facts — the table on the product page',
			[ __CLASS__, 'render_meta_box' ],
			'product',
			'normal',
			'high'
		);
		add_meta_box(
			'fishotel_staff_notes',
			'Staff notes — never shown on the website',
			[ __CLASS__, 'render_notes_box' ],
			'product',
			'normal',
			'low'
		);
	}

	/**
	 * Fact fields drawn in the fish facts box, in order. Filter
	 * `fishotel_fish_facts_box_fields` (array of field keys) lets another
	 * box draw a field instead — the FisHotel Misc plugin's "Fish
	 * essentials" box takes scientific_name. A field not drawn is not
	 * posted, so this box's save leaves it alone.
	 *
	 * @return string[]
	 */
	public static function box_field_keys() {
		$keys = [];
		foreach ( self::fields() as $key => $field ) {
			if ( 'notes' !== $field['group'] ) {
				$keys[] = $key;
			}
		}
		$filtered = apply_filters( 'fishotel_fish_facts_box_fields', $keys );
		return is_array( $filtered ) ? array_values( array_intersect( $keys, $filtered ) ) : $keys;
	}

	/** Field hints shown under a few facts. */
	private static function hints() {
		return [
			'region'        => 'A region tag such as Indo-Pacific overrides this.',
			'foods_feeding' => 'Empty = the site\'s default text (FisHotel Theme settings).',
			'habitat'       => 'Empty = the site\'s default text (FisHotel Theme settings).',
		];
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'fishotel_save_hotel_data', 'fishotel_hotel_nonce' );

		$fields = self::fields();
		$keys   = self::box_field_keys();
		$hints  = self::hints();
		$groups = [
			'species' => 'Species Info',
			'care'    => 'Care Guide (Fish Dossier)',
		];

		self::print_box_styles();
		self::render_old_style_line( $post );

		foreach ( $groups as $group_key => $group_label ) :
			$group_fields = [];
			foreach ( $keys as $key ) {
				if ( $fields[ $key ]['group'] === $group_key ) {
					$group_fields[ $key ] = $fields[ $key ];
				}
			}
			if ( empty( $group_fields ) ) continue;
		?>
			<p class="fh-meta-section"><?php echo esc_html( $group_label ); ?></p>
			<div class="fh-meta-grid">
				<?php foreach ( $group_fields as $key => $field ) :
					$value = get_post_meta( $post->ID, self::PREFIX . $key, true );
					self::render_field( $key, $field, $value, $hints[ $key ] ?? '' );
				endforeach; ?>
			</div>
		<?php endforeach;
	}

	/** The Staff notes box: one textarea, its own nonce. */
	public static function render_notes_box( $post ) {
		wp_nonce_field( 'fishotel_save_staff_notes', 'fishotel_staff_notes_nonce' );
		$fields = self::fields();
		self::print_box_styles();
		?>
		<div class="fh-meta-field fh-meta-field--wide">
			<label for="fh_notes">Notes for the team</label>
			<textarea id="fh_notes" name="fh_notes" rows="4" placeholder="<?php echo esc_attr( $fields['notes']['placeholder'] ); ?>"><?php echo esc_textarea( get_post_meta( $post->ID, self::PREFIX . 'notes', true ) ); ?></textarea>
			<p class="fh-meta-hint">Only people who can edit products see this. Anything customers should read belongs in the description.</p>
		</div>
		<?php
	}

	private static function print_box_styles() {
		static $done = false;
		if ( $done ) return;
		$done = true;
		?>
		<style>
			.fh-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 12px 0 0; }
			.fh-meta-field label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; color: #555; }
			.fh-meta-field input[type="text"], .fh-meta-field textarea {
				width: 100%; padding: 7px 10px; border: 1px solid #ddd; border-radius: 3px; font-size: 13px;
			}
			.fh-meta-field textarea { min-height: 80px; resize: vertical; }
			.fh-meta-hint { margin: 4px 0 0; font-size: 12px; color: #757575; }
			.fh-meta-section { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: #999; border-bottom: 1px solid #eee; padding-bottom: 6px; margin: 24px 0 8px; }
			.fh-meta-section:first-of-type { margin-top: 8px; }
			.fh-radio-group { display: flex; gap: 16px; margin-top: 4px; }
			.fh-radio-group label { font-weight: 400; font-size: 13px; color: #333; cursor: pointer; display: flex; align-items: center; gap: 4px; }
			.fh-radio-group input[type="radio"] { margin: 0; }
			.fh-facts-oldstyle { margin: 8px 0 4px; padding: 8px 12px; background: #fcf9e8; border-left: 4px solid #dba617; display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
			.fh-facts-oldstyle p { margin: 0; }
			.fh-facts-copy-result { font-style: italic; }
		</style>
		<?php
	}

	private static function render_field( $key, $field, $value, $hint ) {
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
			<?php if ( '' !== $hint ) : ?>
				<p class="fh-meta-hint"><?php echo esc_html( $hint ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The yellow line on a fish whose facts are still typed in the
	 * description. The button fills the EMPTY boxes on this screen from
	 * the description (assets/js/admin-product.js); Update saves them.
	 * Writing straight to the database would be undone by the next Update,
	 * which posts these boxes as they are on screen.
	 */
	private static function render_old_style_line( $post ) {
		$pid = (int) $post->ID;
		if ( ! $pid || ! class_exists( 'FisHotel_Product_Facts' ) ) return;
		// Medications and foods are never old style (product-facts.php).
		if ( ! FisHotel_Product_Facts::product_is_old_style( $pid ) ) return;
		$plan   = FisHotel_Product_Facts::plan_for( $pid );
		$labels = [];
		foreach ( self::fields() as $key => $field ) {
			$labels[ $key ] = $field['label'];
		}
		?>
		<div class="fh-facts-oldstyle">
			<p>This fish's facts are still typed in the description.</p>
			<button type="button" class="button fh-facts-copy" data-facts="<?php echo esc_attr( wp_json_encode( (object) $plan ) ); ?>" data-labels="<?php echo esc_attr( wp_json_encode( $labels ) ); ?>">Copy them into these fields</button>
			<span class="fh-facts-copy-result" aria-live="polite"></span>
		</div>
		<?php
	}

	/**
	 * A blank box posted for a fact that was never stored is skipped, so a
	 * save never adds empty fish facts to a product (every dry good got ten
	 * empty `_fh_*` rows on each save before v1.28.0). A stored fact that
	 * was cleared on screen is still cleared.
	 *
	 * @param mixed $posted The posted value.
	 * @param bool  $exists True when the product already has this meta row.
	 * @return bool
	 */
	public static function skip_blank( $posted, $exists ) {
		return ! $exists && '' === trim( (string) $posted );
	}

	public static function save_meta( $product_id ) {
		if ( ! current_user_can( 'edit_post', $product_id ) ) return;
		$facts_ok = isset( $_POST['fishotel_hotel_nonce'] ) && wp_verify_nonce( $_POST['fishotel_hotel_nonce'], 'fishotel_save_hotel_data' );
		$notes_ok = isset( $_POST['fishotel_staff_notes_nonce'] ) && wp_verify_nonce( $_POST['fishotel_staff_notes_nonce'], 'fishotel_save_staff_notes' );
		if ( ! $facts_ok && ! $notes_ok ) return;

		foreach ( self::fields() as $key => $field ) {
			$name     = 'fh_' . $key;
			$meta_key = self::PREFIX . $key;
			$is_notes = 'notes' === $field['group'];

			if ( $is_notes ? ! $notes_ok : ! $facts_ok ) continue;
			if ( ! isset( $_POST[ $name ] ) ) continue;

			$value = $field['type'] === 'textarea'
				? sanitize_textarea_field( $_POST[ $name ] )
				: sanitize_text_field( $_POST[ $name ] );
			if ( self::skip_blank( $value, metadata_exists( 'post', $product_id, $meta_key ) ) ) continue;
			update_post_meta( $product_id, $meta_key, $value );
		}
	}

	/**
	 * The facts of a product, for the storefront. Since v1.28.0 the staff
	 * Notes are NOT included — they are private; read `_fh_notes` directly
	 * in admin code.
	 */
	public static function get_all( $product_id ) {
		$data = [];
		foreach ( self::fields() as $key => $field ) {
			if ( 'notes' === $field['group'] ) continue;
			$data[ $key ] = get_post_meta( $product_id, self::PREFIX . $key, true );
		}
		return $data;
	}
}

FisHotel_Hotel_Data::init();
