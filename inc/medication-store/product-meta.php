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

		$mode      = fishotel_med_get_mode( $pid );
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
					<button type="button" class="button button-secondary fishotel-med-sync-btn" data-product-id="<?php echo esc_attr( $pid ); ?>">
						<?php esc_html_e( 'Sync stock from EA', 'fishotel' ); ?>
					</button>
					<span class="fishotel-med-sync-status" data-status="<?php echo esc_attr( $sync_label ); ?>"><?php echo esc_html( $sync_label ); ?></span>
				</p>
			</div>

			<div class="options_group fishotel-med-fields-amazon">
				<p class="form-field">
					<label for="_fishotel_amazon_asin"><?php esc_html_e( 'Amazon ASIN', 'fishotel' ); ?></label>
					<input type="text" id="_fishotel_amazon_asin" name="_fishotel_amazon_asin" value="<?php echo esc_attr( $asin ); ?>" placeholder="B07XXXXXXX" maxlength="10" pattern="[A-Z0-9]{10}">
					<span class="description"><?php esc_html_e( '10-character ASIN. The affiliate URL is built as https://www.amazon.com/dp/{ASIN}?tag={your-tag}.', 'fishotel' ); ?></span>
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

		$mode_in = isset( $_POST['_fishotel_fulfillment'] ) ? sanitize_text_field( wp_unslash( $_POST['_fishotel_fulfillment'] ) ) : 'ea';
		$mode    = in_array( $mode_in, [ 'ea', 'amazon', 'self' ], true ) ? $mode_in : 'ea';

		update_post_meta( $post_id, '_fishotel_fulfillment',        $mode );
		update_post_meta( $post_id, '_fishotel_med_eyebrow',        sanitize_text_field( wp_unslash( $_POST['_fishotel_med_eyebrow']        ?? '' ) ) );
		update_post_meta( $post_id, '_fishotel_ea_url',             esc_url_raw(           wp_unslash( $_POST['_fishotel_ea_url']           ?? '' ) ) );
		update_post_meta( $post_id, '_fishotel_ea_product_id',      absint(                wp_unslash( $_POST['_fishotel_ea_product_id']    ?? 0  ) ) );
		update_post_meta( $post_id, '_fishotel_ea_sku',             sanitize_text_field( wp_unslash( $_POST['_fishotel_ea_sku']             ?? '' ) ) );
		// ASIN: 10 chars, uppercase letters and digits.
		$asin = strtoupper( preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) ( $_POST['_fishotel_amazon_asin'] ?? '' ) ) ) );
		$asin = substr( $asin, 0, 10 );
		update_post_meta( $post_id, '_fishotel_amazon_asin',        $asin );
		update_post_meta( $post_id, '_fishotel_dosing_anchor',      sanitize_text_field( wp_unslash( $_POST['_fishotel_dosing_anchor']      ?? '' ) ) );
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
		$mode      = $parent_id ? fishotel_med_get_mode( $parent_id ) : 'ea';
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
