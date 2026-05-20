<?php
/**
 * FisHotel — Guest Profile (edit account form).
 *
 * @package FisHotel
 * @var WP_User $user
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_edit_account_form' );

$hf_username = get_user_meta( $user->ID, 'humble_fish_username', true );
?>

<form class="fh-account-form woocommerce-EditAccountForm edit-account" action="" method="post" <?php do_action( 'woocommerce_edit_account_form_tag' ); ?>>

	<?php do_action( 'woocommerce_edit_account_form_start' ); ?>

	<div class="fh-form-grid">

		<p class="fh-field fh-field--half">
			<label for="account_first_name"><?php esc_html_e( 'FIRST NAME', 'fishotel' ); ?> <span class="req">*</span></label>
			<input type="text" class="fh-input" name="account_first_name" id="account_first_name" autocomplete="given-name" value="<?php echo esc_attr( $user->first_name ); ?>" />
		</p>

		<p class="fh-field fh-field--half">
			<label for="account_last_name"><?php esc_html_e( 'LAST NAME', 'fishotel' ); ?> <span class="req">*</span></label>
			<input type="text" class="fh-input" name="account_last_name" id="account_last_name" autocomplete="family-name" value="<?php echo esc_attr( $user->last_name ); ?>" />
		</p>

		<p class="fh-field fh-field--half">
			<label for="account_display_name"><?php esc_html_e( 'DISPLAY NAME', 'fishotel' ); ?> <span class="req">*</span></label>
			<input type="text" class="fh-input" name="account_display_name" id="account_display_name" value="<?php echo esc_attr( $user->display_name ); ?>" />
			<span class="fh-field__hint"><?php esc_html_e( 'This will be how your name will be displayed in the account section and in reviews', 'fishotel' ); ?></span>
		</p>

		<p class="fh-field fh-field--half">
			<label for="account_email"><?php esc_html_e( 'EMAIL ADDRESS', 'fishotel' ); ?> <span class="req">*</span></label>
			<input type="email" class="fh-input" name="account_email" id="account_email" autocomplete="email" value="<?php echo esc_attr( $user->user_email ); ?>" />
		</p>

		<p class="fh-field fh-field--half">
			<label for="humble_fish_username"><?php esc_html_e( 'HUMBLE.FISH USERNAME', 'fishotel' ); ?></label>
			<input type="text" class="fh-input" name="humble_fish_username" id="humble_fish_username" value="<?php echo esc_attr( $hf_username ); ?>" />
			<span class="fh-field__hint"><?php esc_html_e( 'Leave blank if you are not a Humble.Fish member', 'fishotel' ); ?></span>
		</p>

	</div>

	<?php do_action( 'woocommerce_edit_account_form_fields' ); ?>

	<fieldset class="fh-password-block">
		<legend><?php esc_html_e( 'PASSWORD CHANGE', 'fishotel' ); ?></legend>

		<p class="fh-field">
			<label for="password_current"><?php esc_html_e( 'CURRENT PASSWORD', 'fishotel' ); ?></label>
			<input type="password" class="fh-input" name="password_current" id="password_current" autocomplete="off" />
			<span class="fh-field__hint"><?php esc_html_e( 'Leave blank to leave unchanged.', 'fishotel' ); ?></span>
		</p>

		<p class="fh-field">
			<label for="password_1"><?php esc_html_e( 'NEW PASSWORD', 'fishotel' ); ?></label>
			<input type="password" class="fh-input" name="password_1" id="password_1" autocomplete="off" />
			<span class="fh-field__hint"><?php esc_html_e( 'Leave blank to leave unchanged.', 'fishotel' ); ?></span>
		</p>

		<p class="fh-field">
			<label for="password_2"><?php esc_html_e( 'CONFIRM NEW PASSWORD', 'fishotel' ); ?></label>
			<input type="password" class="fh-input" name="password_2" id="password_2" autocomplete="off" />
		</p>
	</fieldset>

	<?php
	/**
	 * NOTE: WooCommerce's `woocommerce_edit_account_form` action is deliberately
	 * NOT fired here. A pre-v1.10 site customization hooks a second (unstyled)
	 * "Humble.Fish Username" field onto it, which rendered below the password
	 * block and duplicated the themed field above (the one this template adds
	 * after Email Address, saved via inc/account-relabels.php). Suppressing the
	 * action removes that duplicate at the source. The earlier
	 * `woocommerce_edit_account_form_fields` / `_start` / `_end` injection
	 * points are still fired above for legitimate plugin compatibility.
	 */
	?>

	<div class="fh-form-actions">
		<?php wp_nonce_field( 'save_account_details', 'save-account-details-nonce' ); ?>
		<button type="submit" class="fh-btn fh-btn--primary" name="save_account_details" value="<?php esc_attr_e( 'Save changes', 'fishotel' ); ?>">
			<?php esc_html_e( 'SAVE CHANGES', 'fishotel' ); ?>
		</button>
		<input type="hidden" name="action" value="save_account_details" />
	</div>

	<?php do_action( 'woocommerce_edit_account_form_end' ); ?>
</form>

<?php do_action( 'woocommerce_after_edit_account_form' ); ?>
