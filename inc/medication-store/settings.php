<?php
/**
 * Medication Store — admin Settings sub-page.
 *
 * Registers a new sub-page under the top-level FisHotel Theme menu
 * (alongside Homepage, Shop Page, etc.) for the 10 medication-store
 * fields described in spec §2. Lives in its own option_group so save
 * round-trips don't collide with the main FisHotel_Admin_Settings page.
 *
 * Security note: EA Consumer Key + Secret are stored encrypted-at-rest
 * via the WP options table, displayed as masked after save, and never
 * echoed back into the input field. The "Replace" button blanks the
 * field so re-entry doesn't have to overwrite the visible mask. See
 * spec §2 → "Security notes for API keys".
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Med_Settings {

	const OPTION_GROUP = 'fishotel_med_settings';
	const PAGE_SLUG    = 'fishotel-medication-store';
	const PARENT_SLUG  = 'fishotel-theme';

	/** All field defaults. */
	public static function defaults() {
		return [
			'fishotel_ea_consumer_key'        => '',
			'fishotel_ea_consumer_secret'     => '',
			'fishotel_ea_store_url'           => 'https://everythingaquatic.net',
			'fishotel_ea_fulfillment_email'   => '',
			'fishotel_amazon_tag'             => '',
			'fishotel_med_disclaimer_body'    => '',
			'fishotel_med_disclaimer_days'    => 30,
			'fishotel_med_checkout_label'     => '',
			'fishotel_med_free_ship_threshold'=> 124.99,
			'fishotel_med_ship_flat'          => 0.0,
			'fishotel_med_auto_email_slip'    => 0,
		];
	}

	/** Default body for the entry modal (spec §4). */
	public static function default_disclaimer_body() {
		return "All medications sold by FisHotel are intended exclusively for ornamental aquarium fish. They are not for human consumption, not for food-producing animals, and not for use in any tank stocked with fish intended for human consumption. By proceeding, you acknowledge that you are purchasing these medications for ornamental aquarium use only, that you understand proper dosing and handling, and that you accept full responsibility for safe application.";
	}

	/** Default checkout checkbox label (spec §4). */
	public static function default_checkout_label() {
		return 'I acknowledge that the medications in this order are for ornamental aquarium use only and I accept full responsibility for safe use.';
	}

	/** Fetch a single setting, falling back to its default. */
	public static function get( $key ) {
		$defaults = self::defaults();
		$val = get_option( $key, null );
		if ( $val === null || $val === '' ) {
			return $defaults[ $key ] ?? '';
		}
		return $val;
	}

	/** Get the disclaimer body, falling back to the default copy. */
	public static function get_disclaimer_body() {
		$saved = (string) get_option( 'fishotel_med_disclaimer_body', '' );
		return $saved !== '' ? $saved : self::default_disclaimer_body();
	}

	/** Get the checkout checkbox label, falling back to the default copy. */
	public static function get_checkout_label() {
		$saved = (string) get_option( 'fishotel_med_checkout_label', '' );
		return $saved !== '' ? $saved : self::default_checkout_label();
	}

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 20 );
		add_action( 'admin_init', [ __CLASS__, 'register_fields' ] );
		// Phase 1.6 fix: WP's update_option short-circuits when the
		// submitted value equals the option's registered default (because
		// get_option returns the registered default when the row is
		// missing — so the "value changed?" check fails and no row is
		// ever created). Force-persist every non-credential field on
		// save so wp_options reflects the on-disk state regardless of
		// whether the user actually typed anything new.
		add_action( 'admin_init', [ __CLASS__, 'force_persist_non_credential_fields' ], 20 );
	}

	public static function register_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			'Medication Store',
			'Medication Store',
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_fields() {
		// Each setting registered individually so sanitization can vary.
		register_setting( self::OPTION_GROUP, 'fishotel_ea_consumer_key', [
			'type'              => 'string',
			'sanitize_callback' => [ __CLASS__, 'sanitize_secret_field' ],
			'default'           => '',
		] );
		register_setting( self::OPTION_GROUP, 'fishotel_ea_consumer_secret', [
			'type'              => 'string',
			'sanitize_callback' => [ __CLASS__, 'sanitize_secret_field' ],
			'default'           => '',
		] );
		register_setting( self::OPTION_GROUP, 'fishotel_ea_store_url', [
			'type'              => 'string',
			'sanitize_callback' => [ __CLASS__, 'sanitize_store_url' ],
			'default'           => self::defaults()['fishotel_ea_store_url'],
		] );
		register_setting( self::OPTION_GROUP, 'fishotel_ea_fulfillment_email', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
			'default'           => '',
		] );
		register_setting( self::OPTION_GROUP, 'fishotel_amazon_tag', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );
		register_setting( self::OPTION_GROUP, 'fishotel_med_disclaimer_body', [
			'type'              => 'string',
			'sanitize_callback' => 'wp_kses_post',
			'default'           => self::default_disclaimer_body(),
		] );
		register_setting( self::OPTION_GROUP, 'fishotel_med_disclaimer_days', [
			'type'              => 'integer',
			'sanitize_callback' => [ __CLASS__, 'sanitize_disclaimer_days' ],
			'default'           => 30,
		] );
		register_setting( self::OPTION_GROUP, 'fishotel_med_checkout_label', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => self::default_checkout_label(),
		] );
		register_setting( self::OPTION_GROUP, 'fishotel_med_free_ship_threshold', [
			'type'              => 'number',
			'sanitize_callback' => [ __CLASS__, 'sanitize_money' ],
			'default'           => 124.99,
		] );
		register_setting( self::OPTION_GROUP, 'fishotel_med_ship_flat', [
			'type'              => 'number',
			'sanitize_callback' => [ __CLASS__, 'sanitize_money' ],
			'default'           => 0.0,
		] );
		register_setting( self::OPTION_GROUP, 'fishotel_med_auto_email_slip', [
			'type'              => 'integer',
			'sanitize_callback' => [ __CLASS__, 'sanitize_bool_flag' ],
			'default'           => 0,
		] );
	}

	/** Clamp the disclaimer-cookie-days value to a sane range. */
	public static function sanitize_disclaimer_days( $val ) {
		$n = (int) $val;
		if ( $n < 1 ) return 30;
		return min( $n, 3650 );
	}

	/** Normalize a checkbox / boolean-ish POST value to 0|1. */
	public static function sanitize_bool_flag( $val ) {
		return $val ? 1 : 0;
	}

	/**
	 * Non-credential field map: option key => sanitize callback.
	 * Single source of truth shared by register_fields() and
	 * force_persist_non_credential_fields().
	 */
	protected static function non_credential_fields() {
		return [
			'fishotel_ea_store_url'            => [ __CLASS__, 'sanitize_store_url' ],
			'fishotel_ea_fulfillment_email'    => 'sanitize_email',
			'fishotel_amazon_tag'              => 'sanitize_text_field',
			'fishotel_med_disclaimer_body'     => 'wp_kses_post',
			'fishotel_med_disclaimer_days'     => [ __CLASS__, 'sanitize_disclaimer_days' ],
			'fishotel_med_checkout_label'      => 'sanitize_textarea_field',
			'fishotel_med_free_ship_threshold' => [ __CLASS__, 'sanitize_money' ],
			'fishotel_med_ship_flat'           => [ __CLASS__, 'sanitize_money' ],
			'fishotel_med_auto_email_slip'     => [ __CLASS__, 'sanitize_bool_flag' ],
		];
	}

	/**
	 * Force-persist non-credential fields on save (Phase 1.6 fix).
	 *
	 * Runs on admin_init at priority 20, after register_setting() at
	 * priority 10. Fires only when the form was submitted to options.php
	 * for our option group, gated by nonce + capability checks.
	 *
	 * Sequence:
	 *   1. Nonce + capability + option_page check.
	 *   2. For each non-credential field, sanitize the POST value.
	 *   3. Check wp_options directly (NOT via get_option, which would
	 *      return the registered default) to decide whether to call
	 *      add_option (row missing) or update_option (row exists).
	 *
	 * Credential fields (consumer_key, consumer_secret) are NOT touched
	 * here — their existing "empty input = no change" sanitizer + the
	 * pre_update_option filter handle the masked-secret round-trip.
	 */
	public static function force_persist_non_credential_fields() {
		if ( ! isset( $_POST['option_page'] ) ) return;
		$option_page = sanitize_text_field( wp_unslash( $_POST['option_page'] ) );
		if ( $option_page !== self::OPTION_GROUP ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;
		check_admin_referer( self::OPTION_GROUP . '-options' );

		foreach ( self::non_credential_fields() as $key => $sanitizer ) {
			$raw = array_key_exists( $key, $_POST )
				? wp_unslash( $_POST[ $key ] )
				: '';
			$value = call_user_func( $sanitizer, $raw );
			self::force_persist_option( $key, $value );
		}
	}

	/**
	 * Persist a single option, bypassing WP's "value matches default"
	 * skip. Direct wp_options row check avoids get_option's registered-
	 * default behavior.
	 */
	protected static function force_persist_option( $key, $value ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$key
		) );
		if ( $found === null ) {
			add_option( $key, $value );
		} else {
			update_option( $key, $value );
		}
	}

	/**
	 * Secret-aware sanitizer. The render path shows the stored secret
	 * as a masked `<code>` row with a Replace button — the password
	 * input itself is always `value=""`. That means a normal Save with
	 * the mask still showing submits an empty string. Returning `''`
	 * here would wipe the stored credential on every save, so:
	 *
	 *   - Empty input → look up the current option via current_filter()
	 *     and retain whatever's stored. To clear a credential, the
	 *     admin deletes the wp_options row directly.
	 *   - Masked-bullet sentinel (defensive — shouldn't normally occur
	 *     given the form structure) → same: retain stored value.
	 *   - Real input → sanitize_text_field and overwrite.
	 *
	 * current_filter() during settings-API save is
	 * `sanitize_option_{option_name}`, so we strip the prefix to recover
	 * the option key and re-read its existing value.
	 */
	public static function sanitize_secret_field( $val ) {
		$val = is_string( $val ) ? trim( $val ) : '';
		if ( $val === '' || preg_match( '/^\xE2\x80\xA2{4,}/', $val ) ) {
			$filter = current_filter();
			if ( $filter && strpos( $filter, 'sanitize_option_' ) === 0 ) {
				$option = substr( $filter, strlen( 'sanitize_option_' ) );
				$existing = (string) get_option( $option, '' );
				if ( $existing !== '' ) {
					return $existing;
				}
			}
			return '';
		}
		return sanitize_text_field( $val );
	}

	/**
	 * Defensive pre_update_option filter — if a save somehow reaches
	 * this hook with the masked-bullet sentinel as the new value
	 * (e.g. a future template renders it directly into the input),
	 * fall back to the stored value rather than overwriting.
	 */
	public static function preserve_masked_secret( $new_value, $old_value, $option ) {
		if ( ! is_string( $new_value ) ) return $new_value;
		if ( $new_value === '' ) return $old_value !== '' ? $old_value : $new_value;
		if ( preg_match( '/^\xE2\x80\xA2{4,}/', $new_value ) ) {
			return $old_value;
		}
		return $new_value;
	}

	public static function sanitize_store_url( $val ) {
		$val = esc_url_raw( trim( (string) $val ) );
		// Strip trailing slash — REST helper appends one as needed.
		return untrailingslashit( $val );
	}

	public static function sanitize_money( $val ) {
		$f = (float) $val;
		if ( $f < 0 ) $f = 0.0;
		return round( $f, 2 );
	}

	/**
	 * Mask helper — render a stored secret as a row of bullets with the
	 * last 4 characters appended. Never returns the raw secret.
	 */
	public static function mask_secret( $secret ) {
		$len = strlen( (string) $secret );
		if ( $len === 0 ) return '';
		$visible = $len > 4 ? substr( $secret, -4 ) : str_repeat( '*', $len );
		return str_repeat( '•', 8 ) . esc_html( $visible );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fishotel' ) );
		}

		$key_val    = (string) get_option( 'fishotel_ea_consumer_key', '' );
		$secret_val = (string) get_option( 'fishotel_ea_consumer_secret', '' );
		$store_url  = (string) self::get( 'fishotel_ea_store_url' );
		$email      = (string) self::get( 'fishotel_ea_fulfillment_email' );
		$amz_tag    = (string) self::get( 'fishotel_amazon_tag' );
		$body       = self::get_disclaimer_body();
		$days       = (int) self::get( 'fishotel_med_disclaimer_days' );
		$cb_label   = self::get_checkout_label();
		$threshold  = (float) self::get( 'fishotel_med_free_ship_threshold' );
		$flat       = (float) self::get( 'fishotel_med_ship_flat' );
		$auto_slip  = (int) self::get( 'fishotel_med_auto_email_slip' );

		$has_key    = $key_val    !== '';
		$has_secret = $secret_val !== '';
		?>
		<div class="wrap fishotel-med-settings">
			<h1>Medication Store</h1>
			<p style="color:#555; max-width:780px;">
				Configure the medication catalog: EA REST credentials for stock sync, Amazon Associates tag for affiliate links, disclaimer copy, and shipping. EA keys are masked after save — use <em>Replace</em> to enter new values.
			</p>

			<form method="post" action="options.php" autocomplete="off">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<h2 class="title">EA Integration</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fishotel_ea_consumer_key">EA Consumer Key</label></th>
						<td>
							<div class="fishotel-med-secret" data-name="fishotel_ea_consumer_key">
								<?php if ( $has_key ) : ?>
									<code class="fishotel-med-secret__mask"><?php echo self::mask_secret( $key_val ); ?></code>
									<button type="button" class="button button-secondary fishotel-med-secret__replace">Replace</button>
								<?php endif; ?>
								<input
									type="password"
									id="fishotel_ea_consumer_key"
									name="fishotel_ea_consumer_key"
									value=""
									class="regular-text fishotel-med-secret__input"
									autocomplete="new-password"
									spellcheck="false"
									<?php echo $has_key ? 'hidden' : ''; ?>>
							</div>
							<p class="description">
								WC REST API consumer key from EverythingAquatic.<br>
								<strong>Not currently used</strong> — EA's REST API is wholesale-gated for this account and returns 403 on every list endpoint. Kept for future re-enablement if the gate lifts.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fishotel_ea_consumer_secret">EA Consumer Secret</label></th>
						<td>
							<div class="fishotel-med-secret" data-name="fishotel_ea_consumer_secret">
								<?php if ( $has_secret ) : ?>
									<code class="fishotel-med-secret__mask"><?php echo self::mask_secret( $secret_val ); ?></code>
									<button type="button" class="button button-secondary fishotel-med-secret__replace">Replace</button>
								<?php endif; ?>
								<input
									type="password"
									id="fishotel_ea_consumer_secret"
									name="fishotel_ea_consumer_secret"
									value=""
									class="regular-text fishotel-med-secret__input"
									autocomplete="new-password"
									spellcheck="false"
									<?php echo $has_secret ? 'hidden' : ''; ?>>
							</div>
							<p class="description">
								WC REST API consumer secret. Sent as Basic Auth on every sync.<br>
								<strong>Not currently used</strong> — see the note on the Consumer Key above.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fishotel_ea_store_url">EA Store URL</label></th>
						<td>
							<input type="url" id="fishotel_ea_store_url" name="fishotel_ea_store_url" value="<?php echo esc_attr( $store_url ); ?>" class="regular-text" placeholder="https://everythingaquatic.net">
							<p class="description">No trailing slash. Used as the base for REST calls.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fishotel_ea_fulfillment_email">EA Fulfillment Email</label></th>
						<td>
							<input type="email" id="fishotel_ea_fulfillment_email" name="fishotel_ea_fulfillment_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" placeholder="dena@everythingaquatic.net">
							<p class="description">Where the packing slip is emailed when an EA-mode order ships.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fishotel_med_auto_email_slip">Auto-email packing slip on order</label></th>
						<td>
							<label>
								<input type="checkbox" id="fishotel_med_auto_email_slip" name="fishotel_med_auto_email_slip" value="1" <?php checked( $auto_slip, 1 ); ?>>
								Send to Dena automatically when an EA-mode order moves to Processing.
							</label>
							<p class="description">Phase 1 ships with this <strong>OFF</strong>. Flip ON once you've watched a few manual sends go through.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Amazon Affiliate</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fishotel_amazon_tag">Amazon Associates Tag</label></th>
						<td>
							<input type="text" id="fishotel_amazon_tag" name="fishotel_amazon_tag" value="<?php echo esc_attr( $amz_tag ); ?>" class="regular-text" placeholder="fishotel-20">
							<p class="description">Appended to every Amazon-mode product link as <code>?tag={tag}</code>. Leave blank during testing to skip the tag.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Disclaimer Wall</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fishotel_med_disclaimer_body">Modal Body</label></th>
						<td>
							<?php
							wp_editor(
								$body,
								'fishotel_med_disclaimer_body',
								[
									'textarea_name' => 'fishotel_med_disclaimer_body',
									'textarea_rows' => 8,
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => true,
									'tinymce'       => [
										'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
										'toolbar2' => '',
									],
								]
							);
							?>
							<p class="description">Body of the entry modal shown on first visit to any Medications category page.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fishotel_med_disclaimer_days">Cookie Days</label></th>
						<td>
							<input type="number" id="fishotel_med_disclaimer_days" name="fishotel_med_disclaimer_days" value="<?php echo esc_attr( $days ); ?>" class="small-text" min="1" max="3650" step="1">
							<p class="description">How many days the accepted-disclaimer cookie lasts before the modal re-appears.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fishotel_med_checkout_label">Checkout Checkbox Label</label></th>
						<td>
							<textarea id="fishotel_med_checkout_label" name="fishotel_med_checkout_label" rows="3" class="large-text"><?php echo esc_textarea( $cb_label ); ?></textarea>
							<p class="description">Required-checkbox text shown above the place-order button when meds are in the cart.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Medication Shipping</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fishotel_med_free_ship_threshold">Free Shipping Threshold</label></th>
						<td>
							<input type="number" step="0.01" min="0" id="fishotel_med_free_ship_threshold" name="fishotel_med_free_ship_threshold" value="<?php echo esc_attr( $threshold ); ?>" class="small-text">
							<p class="description">Cart subtotal at which shipping becomes free. Mirrors EA's lower-48 threshold.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fishotel_med_ship_flat">Flat Rate (sub-threshold)</label></th>
						<td>
							<input type="number" step="0.01" min="0" id="fishotel_med_ship_flat" name="fishotel_med_ship_flat" value="<?php echo esc_attr( $flat ); ?>" class="small-text">
							<p class="description">Flat shipping fee charged on med-only orders that don't hit the free-ship threshold.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Save Medication Store Settings' ); ?>
			</form>
		</div>
		<?php
	}
}

// pre_update_option hook is registered globally so it intercepts every
// secret-field save, including from external admin POSTs.
add_filter( 'pre_update_option_fishotel_ea_consumer_key',
	[ 'FisHotel_Med_Settings', 'preserve_masked_secret' ], 10, 3 );
add_filter( 'pre_update_option_fishotel_ea_consumer_secret',
	[ 'FisHotel_Med_Settings', 'preserve_masked_secret' ], 10, 3 );
