<?php
/**
 * Medication store — universal product meta box + per-variation meta.
 *
 * Every medication is a WC variable product with one universal meta
 * box ("FisHotel Medication") that drives all conditional behavior.
 * The fulfillment mode field is the master switch — flipping it
 * rewires the product page (Amazon CTA vs. Add to Cart), stock-sync
 * eligibility, and packing-slip inclusion.
 *
 * Per-variation fields (wholesale, EA retail, size index/count) are
 * the pricing-function inputs and are only required for `ea` mode.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Product_Meta {

	const NONCE = 'fishotel_med_product_meta';

	public static function init() {
		// Render universal product meta panel as its own tab in the
		// Product Data box. Cleaner than a separate side meta box and
		// keeps variation-level data adjacent.
		add_filter( 'woocommerce_product_data_tabs',  [ __CLASS__, 'add_data_tab' ] );
		add_action( 'woocommerce_product_data_panels',[ __CLASS__, 'render_panel' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_product_meta' ] );

		// Per-variation fields appear inside each variation row.
		add_action( 'woocommerce_variation_options_pricing', [ __CLASS__, 'render_variation_fields' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation',    [ __CLASS__, 'save_variation_fields' ], 10, 2 );
	}

	/** Add a new tab on the Product Data tabs strip. */
	public static function add_data_tab( $tabs ) {
		$tabs['fishotel_med'] = [
			'label'    => __( 'FisHotel Medication', 'fishotel' ),
			'target'   => 'fishotel_med_panel',
			'class'    => [ 'show_if_simple', 'show_if_variable' ],
			'priority' => 65,
		];
		return $tabs;
	}

	public static function render_panel() {
		global $post;
		$pid = (int) $post->ID;

		// Read the RAW fulfillment meta — NOT fishotel_med_get_mode(), which
		// defaults unset/empty to 'ea'. A product that was never opted in
		// (e.g. a live fish) must show the empty "Not a FisHotel-managed
		// product" option, not silently pre-select EA.
		$mode      = (string) get_post_meta( $pid, '_fishotel_fulfillment', true );
		$eyebrow   = (string) get_post_meta( $pid, '_fishotel_med_eyebrow', true );
		$ea_url    = (string) get_post_meta( $pid, '_fishotel_ea_url', true );
		$ea_pid    = (string) get_post_meta( $pid, '_fishotel_ea_product_id', true );
		$ea_sku    = (string) get_post_meta( $pid, '_fishotel_ea_sku', true );
		$asin      = (string) get_post_meta( $pid, '_fishotel_amazon_asin', true );
		$dose_anchor = (string) get_post_meta( $pid, '_fishotel_dosing_anchor', true );

		$last_sync  = (int) get_post_meta( $pid, '_fishotel_ea_last_sync', true );
		$sync_label = $last_sync ? sprintf(
			/* translators: %s formatted timestamp */
			__( 'Last synced: %s', 'fishotel' ),
			esc_html( wp_date( 'M j, Y g:i A', $last_sync ) )
		) : __( 'Never synced from EA.', 'fishotel' );
		?>
		<div id="fishotel_med_panel" class="panel woocommerce_options_panel">
			<?php wp_nonce_field( self::NONCE, '_fishotel_med_nonce' ); ?>

			<div class="options_group">
				<p class="form-field _fishotel_fulfillment_field">
					<label for="_fishotel_fulfillment"><?php esc_html_e( 'Fulfillment mode', 'fishotel' ); ?></label>
					<select id="_fishotel_fulfillment" name="_fishotel_fulfillment" class="fishotel-med-mode">
						<option value=""       <?php selected( $mode, '' ); ?>>— Not a FisHotel-managed product —</option>
						<option value="ea"     <?php selected( $mode, 'ea' ); ?>>EA — synced from EverythingAquatic</option>
						<option value="amazon" <?php selected( $mode, 'amazon' ); ?>>Amazon — affiliate buy-out</option>
						<option value="self"   <?php selected( $mode, 'self' ); ?>>Self — Jeff stocks &amp; ships</option>
					</select>
					<span class="description"><?php esc_html_e( 'Flipping this rewires the product page. EA + Self show Add to Cart; Amazon shows a "Buy on Amazon" button.', 'fishotel' ); ?></span>
				</p>

				<p class="form-field">
					<label for="_fishotel_med_eyebrow"><?php esc_html_e( 'Eyebrow tagline', 'fishotel' ); ?></label>
					<input type="text" id="_fishotel_med_eyebrow" name="_fishotel_med_eyebrow" value="<?php echo esc_attr( $eyebrow ); ?>" placeholder="The Persistent Worm Specialist" style="width:100%;">
				</p>

				<p class="form-field">
					<label for="_fishotel_dosing_anchor"><?php esc_html_e( 'Dosing calculator anchor', 'fishotel' ); ?></label>
					<input type="text" id="_fishotel_dosing_anchor" name="_fishotel_dosing_anchor" value="<?php echo esc_attr( $dose_anchor ); ?>" placeholder="#praziquantel" style="width:100%;">
					<span class="description"><?php esc_html_e( 'Slug into the Medication Dosing Calculator (e.g. #praziquantel).', 'fishotel' ); ?></span>
				</p>
			</div>

			<div class="options_group fishotel-med-fields-ea">
				<p class="form-field">
					<label for="_fishotel_ea_url"><?php esc_html_e( 'EA product URL', 'fishotel' ); ?></label>
					<input type="url" id="_fishotel_ea_url" name="_fishotel_ea_url" value="<?php echo esc_attr( $ea_url ); ?>" placeholder="https://everythingaquatic.net/product/praziquantel-powder/" style="width:100%;">
				</p>
				<p class="form-field">
					<label for="_fishotel_ea_product_id"><?php esc_html_e( 'EA product ID', 'fishotel' ); ?></label>
					<input type="number" id="_fishotel_ea_product_id" name="_fishotel_ea_product_id" value="<?php echo esc_attr( $ea_pid ); ?>" min="0" step="1" placeholder="12345">
					<span class="description"><?php esc_html_e( 'Numeric WC product ID on EA. Primary lookup key for REST sync.', 'fishotel' ); ?></span>
				</p>
				<p class="form-field">
					<label for="_fishotel_ea_sku"><?php esc_html_e( 'EA SKU', 'fishotel' ); ?></label>
					<input type="text" id="_fishotel_ea_sku" name="_fishotel_ea_sku" value="<?php echo esc_attr( $ea_sku ); ?>" placeholder="PRAZI-PWD-3G">
					<span class="description"><?php esc_html_e( 'Fallback lookup key when the EA product ID isn\'t set.', 'fishotel' ); ?></span>
				</p>

				<p class="form-field">
					<label><?php esc_html_e( 'Stock sync', 'fishotel' ); ?></label>
					<?php if ( defined( 'FISHOTEL_MED_EA_API_ENABLED' ) && FISHOTEL_MED_EA_API_ENABLED ) : ?>
						<button type="button" class="button button-secondary fishotel-med-sync-btn" data-product-id="<?php echo esc_attr( $pid ); ?>">
							<?php esc_html_e( 'Sync stock from EA', 'fishotel' ); ?>
						</button>
						<span class="fishotel-med-sync-status" data-status="<?php echo esc_attr( $sync_label ); ?>"><?php echo esc_html( $sync_label ); ?></span>
					<?php else : ?>
						<span class="description" style="display:block;">
							<?php esc_html_e( 'Stock sync from EA is not available — EA\'s REST API is wholesale-role-gated for this account and returns 403 on every list endpoint. See /docs/specs/ea-api-status.md for details. Define FISHOTEL_MED_EA_API_ENABLED to re-enable if the gate ever lifts.', 'fishotel' ); ?>
						</span>
					<?php endif; ?>
				</p>
			</div>

			<div class="options_group fishotel-med-fields-amazon">
				<p class="form-field">
					<label for="_fishotel_amazon_asin"><?php esc_html_e( 'Amazon ASIN', 'fishotel' ); ?></label>
					<input type="text" id="_fishotel_amazon_asin" name="_fishotel_amazon_asin" value="<?php echo esc_attr( $asin ); ?>" placeholder="B07XXXXXXX" maxlength="10" pattern="[A-Z0-9]{10}">
					<span class="description"><?php esc_html_e( '10-character ASIN. The affiliate URL is built as https://www.amazon.com/dp/{ASIN}?tag={your-tag}.', 'fishotel' ); ?></span>
				</p>
			</div>

			<?php
			// Phase 3.6 — per-product data fields that drive the italic
			// subtitle line under the title + the "About This Medication"
			// / "About This Food" data table on the PDP. Both sections
			// render unconditionally so a single product can be both a
			// medication and a food (medicated foods like Praziquantel
			// Pellet); the frontend skips any field that's left empty.
			$active_ingredient = (string) get_post_meta( $pid, '_fishotel_active_ingredient', true );
			$treats_text       = (string) get_post_meta( $pid, '_fishotel_treats',             true );
			$use_in_text       = (string) get_post_meta( $pid, '_fishotel_use_in',             true );
			$reef_safe_val     = (string) get_post_meta( $pid, '_fishotel_reef_safe',          true );
			$food_source       = (string) get_post_meta( $pid, '_fishotel_food_source',        true );
			$food_best_for     = (string) get_post_meta( $pid, '_fishotel_best_for',           true );
			$food_nutrition    = (string) get_post_meta( $pid, '_fishotel_nutrition',          true );
			$food_additives    = (string) get_post_meta( $pid, '_fishotel_additives',          true );
			?>

			<div class="options_group">
				<h4 style="margin:8px 12px 0;"><?php esc_html_e( 'Medication details (table + subtitle)', 'fishotel' ); ?></h4>
				<p class="form-field">
					<label for="_fishotel_active_ingredient"><?php esc_html_e( 'Active ingredient(s)', 'fishotel' ); ?></label>
					<input type="text" id="_fishotel_active_ingredient" name="_fishotel_active_ingredient" value="<?php echo esc_attr( $active_ingredient ); ?>" placeholder="Praziquantel" style="width:100%;">
					<span class="description"><?php esc_html_e( 'Comma-separated for combo products. Renders as the italic subtitle under the title and as the first row of the data table.', 'fishotel' ); ?></span>
				</p>
				<p class="form-field">
					<label for="_fishotel_treats"><?php esc_html_e( 'Treats', 'fishotel' ); ?></label>
					<input type="text" id="_fishotel_treats" name="_fishotel_treats" value="<?php echo esc_attr( $treats_text ); ?>" placeholder="Gill flukes, intestinal tapeworms" style="width:100%;">
				</p>
				<p class="form-field">
					<label for="_fishotel_use_in"><?php esc_html_e( 'Use in', 'fishotel' ); ?></label>
					<input type="text" id="_fishotel_use_in" name="_fishotel_use_in" value="<?php echo esc_attr( $use_in_text ); ?>" placeholder="QT tank, hospital tank" style="width:100%;">
				</p>
				<p class="form-field">
					<label for="_fishotel_reef_safe"><?php esc_html_e( 'Reef safe', 'fishotel' ); ?></label>
					<select id="_fishotel_reef_safe" name="_fishotel_reef_safe">
						<option value=""        <?php selected( $reef_safe_val, '' ); ?>>— No selection —</option>
						<option value="yes"     <?php selected( $reef_safe_val, 'yes' ); ?>>Yes</option>
						<option value="no"      <?php selected( $reef_safe_val, 'no' ); ?>>No</option>
						<option value="caution" <?php selected( $reef_safe_val, 'caution' ); ?>>With caution</option>
					</select>
				</p>
			</div>

			<div class="options_group">
				<h4 style="margin:8px 12px 0;"><?php esc_html_e( 'Food details (table + subtitle)', 'fishotel' ); ?></h4>
				<p class="form-field">
					<label for="_fishotel_food_source"><?php esc_html_e( 'Food source', 'fishotel' ); ?></label>
					<input type="text" id="_fishotel_food_source" name="_fishotel_food_source" value="<?php echo esc_attr( $food_source ); ?>" placeholder="Mysis relicta" style="width:100%;">
					<span class="description"><?php esc_html_e( 'Source species. Renders as the italic subtitle on freeze-dried foods (medicated foods like Praziquantel Pellet keep their active ingredient as the subtitle).', 'fishotel' ); ?></span>
				</p>
				<p class="form-field">
					<label for="_fishotel_best_for"><?php esc_html_e( 'Best for', 'fishotel' ); ?></label>
					<input type="text" id="_fishotel_best_for" name="_fishotel_best_for" value="<?php echo esc_attr( $food_best_for ); ?>" placeholder="LPS corals, planktivores, finicky eaters" style="width:100%;">
				</p>
				<p class="form-field">
					<label for="_fishotel_nutrition"><?php esc_html_e( 'Nutrition value', 'fishotel' ); ?></label>
					<input type="text" id="_fishotel_nutrition" name="_fishotel_nutrition" value="<?php echo esc_attr( $food_nutrition ); ?>" placeholder="Protein 65% min · Fat 8% min · Fiber 2% max" style="width:100%;">
				</p>
				<p class="form-field">
					<label for="_fishotel_additives"><?php esc_html_e( 'Additives &amp; enrichments', 'fishotel' ); ?></label>
					<input type="text" id="_fishotel_additives" name="_fishotel_additives" value="<?php echo esc_attr( $food_additives ); ?>" placeholder="Garlic, spirulina, vitamin C" style="width:100%;">
				</p>
			</div>
		</div>
		<?php
	}

	public static function save_product_meta( $post_id ) {
		if ( ! isset( $_POST['_fishotel_med_nonce'] ) ) return;
		$nonce = sanitize_text_field( wp_unslash( $_POST['_fishotel_med_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) return;
		if ( ! current_user_can( 'edit_product', $post_id ) ) return;

		// Default to '' (not 'ea'): this panel renders on EVERY simple/
		// variable product, so saving an unrelated product (a live fish)
		// without touching the tab must NOT tag it as EA.
		$mode_in = isset( $_POST['_fishotel_fulfillment'] ) ? sanitize_text_field( wp_unslash( $_POST['_fishotel_fulfillment'] ) ) : '';
		$mode    = in_array( $mode_in, [ 'ea', 'amazon', 'self' ], true ) ? $mode_in : '';

		if ( $mode === '' ) {
			// Remove the row entirely rather than storing '' — keeps the
			// catalog clean and ensures fishotel_is_ea_fulfilled_product()
			// (raw === 'ea') can never match an opted-out product.
			delete_post_meta( $post_id, '_fishotel_fulfillment' );
		} else {
			update_post_meta( $post_id, '_fishotel_fulfillment', $mode );
		}
		update_post_meta( $post_id, '_fishotel_med_eyebrow',        sanitize_text_field( wp_unslash( $_POST['_fishotel_med_eyebrow']        ?? '' ) ) );
		update_post_meta( $post_id, '_fishotel_ea_url',             esc_url_raw(           wp_unslash( $_POST['_fishotel_ea_url']           ?? '' ) ) );
		update_post_meta( $post_id, '_fishotel_ea_product_id',      absint(                wp_unslash( $_POST['_fishotel_ea_product_id']    ?? 0  ) ) );
		update_post_meta( $post_id, '_fishotel_ea_sku',             sanitize_text_field( wp_unslash( $_POST['_fishotel_ea_sku']             ?? '' ) ) );
		// ASIN: 10 chars, uppercase letters and digits.
		$asin = strtoupper( preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) ( $_POST['_fishotel_amazon_asin'] ?? '' ) ) ) );
		$asin = substr( $asin, 0, 10 );
		update_post_meta( $post_id, '_fishotel_amazon_asin',        $asin );
		update_post_meta( $post_id, '_fishotel_dosing_anchor',      sanitize_text_field( wp_unslash( $_POST['_fishotel_dosing_anchor']      ?? '' ) ) );

		// Phase 3.6 — medication + food data fields driving the subtitle
		// and the "About This Medication / Food" data table on the PDP.
		// Plain text fields; the Reef Safe select is constrained to its
		// own allowlist.
		update_post_meta( $post_id, '_fishotel_active_ingredient', sanitize_text_field( wp_unslash( $_POST['_fishotel_active_ingredient'] ?? '' ) ) );
		update_post_meta( $post_id, '_fishotel_treats',            sanitize_text_field( wp_unslash( $_POST['_fishotel_treats']            ?? '' ) ) );
		update_post_meta( $post_id, '_fishotel_use_in',            sanitize_text_field( wp_unslash( $_POST['_fishotel_use_in']            ?? '' ) ) );
		$reef_safe_in = sanitize_text_field( wp_unslash( $_POST['_fishotel_reef_safe'] ?? '' ) );
		$reef_safe    = in_array( $reef_safe_in, [ '', 'yes', 'no', 'caution' ], true ) ? $reef_safe_in : '';
		update_post_meta( $post_id, '_fishotel_reef_safe',         $reef_safe );
		update_post_meta( $post_id, '_fishotel_food_source',       sanitize_text_field( wp_unslash( $_POST['_fishotel_food_source']       ?? '' ) ) );
		update_post_meta( $post_id, '_fishotel_best_for',          sanitize_text_field( wp_unslash( $_POST['_fishotel_best_for']          ?? '' ) ) );
		update_post_meta( $post_id, '_fishotel_nutrition',         sanitize_text_field( wp_unslash( $_POST['_fishotel_nutrition']         ?? '' ) ) );
		update_post_meta( $post_id, '_fishotel_additives',         sanitize_text_field( wp_unslash( $_POST['_fishotel_additives']         ?? '' ) ) );
	}

	/**
	 * Per-variation fields shown inside each WC variation row. Only the
	 * three numeric pricing inputs and a read-only EA-retail-as-floor
	 * note; size_index/size_count are computed automatically on save
	 * (they're just position-based) so admins don't have to manage them.
	 */
	public static function render_variation_fields( $loop, $variation_data, $variation ) {
		$vid       = (int) $variation->ID;
		$wholesale = get_post_meta( $vid, '_fishotel_wholesale', true );
		$ea_retail = get_post_meta( $vid, '_fishotel_ea_retail', true );

		// WC's woocommerce_wp_text_input emits its own <p class="form-row">
		// — let them stack naturally inside the variation row rather
		// than wrapping in a flex container that fights WC's layout.
		woocommerce_wp_text_input( [
			'id'            => "_fishotel_wholesale_{$loop}",
			'name'          => "_fishotel_wholesale[{$loop}]",
			'value'         => $wholesale,
			'label'         => __( 'EA Wholesale', 'fishotel' ),
			'data_type'     => 'price',
			'desc_tip'      => true,
			'description'   => __( 'Wholesale cost from EA for this size.', 'fishotel' ),
			'wrapper_class' => 'form-row form-row-first',
		] );

		woocommerce_wp_text_input( [
			'id'            => "_fishotel_ea_retail_{$loop}",
			'name'          => "_fishotel_ea_retail[{$loop}]",
			'value'         => $ea_retail,
			'label'         => __( 'EA Retail (price floor)', 'fishotel' ),
			'data_type'     => 'price',
			'desc_tip'      => true,
			'description'   => __( 'EA\'s retail price for this size — acts as the FisHotel price floor.', 'fishotel' ),
			'wrapper_class' => 'form-row form-row-last',
		] );
	}

	public static function save_variation_fields( $variation_id, $loop ) {
		if ( ! current_user_can( 'edit_product', $variation_id ) ) return;

		$wholesale_arr = isset( $_POST['_fishotel_wholesale'] ) && is_array( $_POST['_fishotel_wholesale'] ) ? $_POST['_fishotel_wholesale'] : [];
		$retail_arr    = isset( $_POST['_fishotel_ea_retail'] ) && is_array( $_POST['_fishotel_ea_retail'] ) ? $_POST['_fishotel_ea_retail'] : [];

		$wholesale = isset( $wholesale_arr[ $loop ] ) ? wc_format_decimal( wp_unslash( $wholesale_arr[ $loop ] ) ) : '';
		$retail    = isset( $retail_arr[ $loop ] )    ? wc_format_decimal( wp_unslash( $retail_arr[ $loop ] ) )    : '';

		update_post_meta( $variation_id, '_fishotel_wholesale', $wholesale );
		update_post_meta( $variation_id, '_fishotel_ea_retail', $retail );
		// size_index = $loop (0-based position), size_count = total
		// number of variation rows on this submit. Recompute below.
		update_post_meta( $variation_id, '_fishotel_size_index', (int) $loop );

		$count = is_array( $wholesale_arr ) ? count( $wholesale_arr ) : 1;
		update_post_meta( $variation_id, '_fishotel_size_count', $count );

		// Update WC price meta as well (regular_price + price) so the
		// product page renders the calculated FisHotel price.
		$parent_id = wp_get_post_parent_id( $variation_id );
		// No-parent fallback is '' rather than 'ea' for consistency with the
		// opt-in fix above. This does NOT change EA pricing: a variation
		// always has a parent (so the real mode comes from
		// fishotel_med_get_mode()), and the calc below only runs when the
		// mode is 'ea' AND wholesale/retail are numeric — non-EA products
		// carry no such pricing meta, so they're never repriced here.
		$mode      = $parent_id ? fishotel_med_get_mode( $parent_id ) : '';
		if ( $mode === 'ea' && is_numeric( $wholesale ) && is_numeric( $retail ) ) {
			$price = fishotel_calculate_med_price(
				(float) $wholesale,
				(float) $retail,
				(int) $loop,
				$count
			);
			update_post_meta( $variation_id, '_regular_price', $price );
			update_post_meta( $variation_id, '_price',         $price );
		}
	}
}
