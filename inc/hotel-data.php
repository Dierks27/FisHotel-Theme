<?php
/**
 * FisHotel Hotel Data
 *
 * Adds the quarantine meta fields to WooCommerce products:
 * - Arrival / Check-in Date
 * - Days In Quarantine (auto-calculated)
 * - QT Stage (Check-In / Observation / Treatment / Souvenir Shop / Flying Home)
 * - Health Grade (A / B / C / Pending)
 * - Importer
 * - Treatments (repeatable)
 * - Short health note
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Hotel_Data {

	const META_PREFIX = '_fishotel_';

	public static function init() {
		// Admin meta box
		add_action( 'add_meta_boxes',            [ __CLASS__, 'add_meta_box' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_meta' ] );

		// Product page display hooks (theme template handles rendering,
		// but these helpers make data available)
		add_action( 'wp_ajax_fishotel_add_journal_entry',    [ __CLASS__, 'ajax_add_journal_entry' ] );
		add_action( 'wp_ajax_fishotel_delete_journal_entry', [ __CLASS__, 'ajax_delete_journal_entry' ] );
	}

	// ── Meta box ──────────────────────────────
	public static function add_meta_box() {
		add_meta_box(
			'fishotel_hotel_data',
			'🏨 FisHotel — Hotel Data',
			[ __CLASS__, 'render_meta_box' ],
			'product',
			'normal',
			'high'
		);
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'fishotel_save_hotel_data', 'fishotel_hotel_nonce' );

		$arrival      = get_post_meta( $post->ID, self::META_PREFIX . 'arrival_date',  true );
		$stage        = get_post_meta( $post->ID, self::META_PREFIX . 'qt_stage',      true );
		$health       = get_post_meta( $post->ID, self::META_PREFIX . 'health_grade',  true );
		$importer     = get_post_meta( $post->ID, self::META_PREFIX . 'importer',      true );
		$treatments   = get_post_meta( $post->ID, self::META_PREFIX . 'treatments',    true );
		$health_note  = get_post_meta( $post->ID, self::META_PREFIX . 'health_note',   true );
		$foods_eating = get_post_meta( $post->ID, self::META_PREFIX . 'foods_eating',  true );

		$stages = [
			''               => '— Select Stage —',
			'checkin'        => '🛎 Check-In',
			'observation'    => '👀 Observation',
			'treatment'      => '💊 Treatment',
			'souvenir-shop'  => '🐠 The Souvenir Shop (Available)',
			'flying-home'    => '✈ Flying Home (Shipped)',
		];

		$grades = [ '' => '—', 'A' => 'A — Excellent', 'B' => 'B — Good', 'C' => 'C — Fair', 'pending' => 'Pending' ];

		?>
		<style>
			.fh-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 16px 0; }
			.fh-meta-field label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 4px; color: #555; }
			.fh-meta-field input, .fh-meta-field select, .fh-meta-field textarea {
				width: 100%; padding: 7px 10px; border: 1px solid #ddd; border-radius: 3px; font-size: 13px;
			}
			.fh-days-badge {
				background: #1c1c1c; color: #c9963a;
				font-size: 28px; font-weight: 700; text-align: center;
				padding: 16px; border-radius: 4px; font-family: Georgia, serif;
			}
			.fh-days-label { font-size: 10px; color: #888; text-align: center; margin-top: 4px; letter-spacing: 2px; text-transform: uppercase; }
			#fishotel_treatments_list { list-style: none; padding: 0; margin: 0 0 8px; }
			#fishotel_treatments_list li { display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px solid #f0f0f0; }
			#fishotel_treatments_list li span { flex: 1; font-size: 13px; }
			.fh-remove-treatment { background: none; border: 1px solid #ccc; color: #999; cursor: pointer; padding: 2px 8px; font-size: 11px; }
			.fh-section-head { font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: #999; border-bottom: 1px solid #eee; padding-bottom: 6px; margin: 20px 0 12px; }
		</style>

		<div class="fh-meta-grid">
			<div class="fh-meta-field">
				<label><?php esc_html_e( 'Check-In Date', 'fishotel' ); ?></label>
				<input type="date" name="fishotel_arrival_date" value="<?php echo esc_attr( $arrival ); ?>">
			</div>
			<div class="fh-meta-field">
				<div class="fh-days-badge"><?php echo esc_html( self::get_days_in_qt( $post->ID ) ); ?></div>
				<div class="fh-days-label"><?php esc_html_e( 'Days In Quarantine', 'fishotel' ); ?></div>
			</div>
		</div>

		<div class="fh-meta-grid">
			<div class="fh-meta-field">
				<label><?php esc_html_e( 'QT Stage', 'fishotel' ); ?></label>
				<select name="fishotel_qt_stage">
					<?php foreach ( $stages as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $stage, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="fh-meta-field">
				<label><?php esc_html_e( 'Health Grade', 'fishotel' ); ?></label>
				<select name="fishotel_health_grade">
					<?php foreach ( $grades as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $health, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<div class="fh-meta-grid">
			<div class="fh-meta-field">
				<label><?php esc_html_e( 'Importer', 'fishotel' ); ?></label>
				<input type="text" name="fishotel_importer" value="<?php echo esc_attr( $importer ); ?>" placeholder="e.g. EMark Aquatics (New York)">
			</div>
			<div class="fh-meta-field">
				<label><?php esc_html_e( 'Foods Eating', 'fishotel' ); ?></label>
				<input type="text" name="fishotel_foods_eating" value="<?php echo esc_attr( $foods_eating ); ?>" placeholder="e.g. Mysis, Nori, Pellets">
			</div>
		</div>

		<div class="fh-meta-field" style="margin-bottom:16px;">
			<label><?php esc_html_e( 'Health Status Note', 'fishotel' ); ?></label>
			<input type="text" name="fishotel_health_note" value="<?php echo esc_attr( $health_note ); ?>" placeholder="e.g. Cleared for purchase — 42-day QT complete">
		</div>

		<p class="fh-section-head"><?php esc_html_e( 'Treatments Completed', 'fishotel' ); ?></p>
		<ul id="fishotel_treatments_list">
			<?php if ( is_array( $treatments ) ) :
				foreach ( $treatments as $i => $t ) : ?>
					<li>
						<span><?php echo esc_html( $t['name'] ); ?></span>
						<span style="color:#aaa; font-size:11px;"><?php echo esc_html( $t['date'] ?? '' ); ?></span>
						<input type="hidden" name="fishotel_treatments[<?php echo $i; ?>][name]" value="<?php echo esc_attr( $t['name'] ); ?>">
						<input type="hidden" name="fishotel_treatments[<?php echo $i; ?>][date]" value="<?php echo esc_attr( $t['date'] ?? '' ); ?>">
						<button type="button" class="fh-remove-treatment" data-index="<?php echo $i; ?>">✕</button>
					</li>
			<?php endforeach; endif; ?>
		</ul>
		<div style="display:flex; gap:8px; margin-bottom:8px;">
			<input type="text" id="fh_new_treatment_name" placeholder="Treatment name (e.g. Praziquantel)" style="flex:1; padding:6px 10px; border:1px solid #ddd; font-size:13px;">
			<input type="date" id="fh_new_treatment_date" style="width:140px; padding:6px 10px; border:1px solid #ddd; font-size:13px;">
			<button type="button" id="fh_add_treatment" style="padding:6px 14px; background:#c9963a; color:#fff; border:none; cursor:pointer; font-size:13px; font-weight:600;">+ Add</button>
		</div>

		<script>
		(function($){
			var treatmentCount = <?php echo count( (array) $treatments ); ?>;

			$('#fh_add_treatment').on('click', function(){
				var name = $('#fh_new_treatment_name').val().trim();
				var date = $('#fh_new_treatment_date').val();
				if ( !name ) return;
				var li = '<li>' +
					'<span>' + $('<span>').text(name).html() + '</span>' +
					'<span style="color:#aaa; font-size:11px;">' + date + '</span>' +
					'<input type="hidden" name="fishotel_treatments[' + treatmentCount + '][name]" value="' + $('<span>').text(name).html() + '">' +
					'<input type="hidden" name="fishotel_treatments[' + treatmentCount + '][date]" value="' + date + '">' +
					'<button type="button" class="fh-remove-treatment">✕</button>' +
				'</li>';
				$('#fishotel_treatments_list').append(li);
				$('#fh_new_treatment_name').val('');
				treatmentCount++;
			});
			$(document).on('click', '.fh-remove-treatment', function(){
				$(this).closest('li').remove();
			});
		})(jQuery);
		</script>
		<?php
	}

	public static function save_meta( $product_id ) {
		if ( ! isset( $_POST['fishotel_hotel_nonce'] ) || ! wp_verify_nonce( $_POST['fishotel_hotel_nonce'], 'fishotel_save_hotel_data' ) ) return;
		if ( ! current_user_can( 'edit_post', $product_id ) ) return;

		$fields = [
			'fishotel_arrival_date' => '_fishotel_arrival_date',
			'fishotel_qt_stage'     => '_fishotel_qt_stage',
			'fishotel_health_grade' => '_fishotel_health_grade',
			'fishotel_importer'     => '_fishotel_importer',
			'fishotel_health_note'  => '_fishotel_health_note',
			'fishotel_foods_eating' => '_fishotel_foods_eating',
		];

		foreach ( $fields as $post_key => $meta_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				update_post_meta( $product_id, $meta_key, sanitize_text_field( $_POST[ $post_key ] ) );
			}
		}

		// Treatments array
		if ( isset( $_POST['fishotel_treatments'] ) && is_array( $_POST['fishotel_treatments'] ) ) {
			$treatments = array_map( function( $t ) {
				return [
					'name' => sanitize_text_field( $t['name'] ?? '' ),
					'date' => sanitize_text_field( $t['date'] ?? '' ),
				];
			}, $_POST['fishotel_treatments'] );
			update_post_meta( $product_id, '_fishotel_treatments', array_filter( $treatments, function( $t ) { return ! empty( $t['name'] ); } ) );
		} else {
			update_post_meta( $product_id, '_fishotel_treatments', [] );
		}
	}

	// ── Data helpers ──────────────────────────
	public static function get_days_in_qt( $product_id ) {
		$arrival = get_post_meta( $product_id, '_fishotel_arrival_date', true );
		if ( ! $arrival ) return '—';
		$days = (int) floor( ( time() - strtotime( $arrival ) ) / DAY_IN_SECONDS );
		return max( 0, $days );
	}

	public static function get_stage_label( $product_id ) {
		$stages = [
			'checkin'       => 'Check-In',
			'observation'   => 'Observation',
			'treatment'     => 'In Treatment',
			'souvenir-shop' => 'Available',
			'flying-home'   => 'Shipped',
		];
		$stage = get_post_meta( $product_id, '_fishotel_qt_stage', true );
		return $stages[ $stage ] ?? 'Quarantine';
	}

	public static function is_available( $product_id ) {
		$stage = get_post_meta( $product_id, '_fishotel_qt_stage', true );
		return in_array( $stage, [ 'souvenir-shop' ] );
	}

	public static function get_all( $product_id ) {
		return [
			'arrival_date'  => get_post_meta( $product_id, '_fishotel_arrival_date',  true ),
			'days_in_qt'    => self::get_days_in_qt( $product_id ),
			'qt_stage'      => get_post_meta( $product_id, '_fishotel_qt_stage',      true ),
			'stage_label'   => self::get_stage_label( $product_id ),
			'health_grade'  => get_post_meta( $product_id, '_fishotel_health_grade',  true ),
			'importer'      => get_post_meta( $product_id, '_fishotel_importer',      true ),
			'treatments'    => get_post_meta( $product_id, '_fishotel_treatments',    true ) ?: [],
			'health_note'   => get_post_meta( $product_id, '_fishotel_health_note',   true ),
			'foods_eating'  => get_post_meta( $product_id, '_fishotel_foods_eating',  true ),
			'is_available'  => self::is_available( $product_id ),
		];
	}

	// AJAX handlers for journal (used with inc/hotel-journal.php)
	public static function ajax_add_journal_entry() { /* handled in hotel-journal.php */ }
	public static function ajax_delete_journal_entry() { /* handled in hotel-journal.php */ }
}

FisHotel_Hotel_Data::init();
