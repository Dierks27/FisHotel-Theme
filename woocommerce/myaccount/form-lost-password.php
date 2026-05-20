<?php
/**
 * FisHotel — Lost password form.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() && function_exists( 'fishotel_account_render_page_header' ) ) {
	fishotel_account_render_page_header( [
		'title'    => __( 'PASSWORD RECOVERY', 'fishotel' ),
		'subtitle' => __( 'Lost the key to your room? We can issue a new one.', 'fishotel' ),
	] );
}
?>

<div class="fh-account-shell fh-account-shell--auth is-single">
	<section class="fh-card fh-auth-card">
		<form method="post" class="fh-account-form woocommerce-ResetPassword lost_reset_password">

			<p class="fh-field__hint fh-field__hint--block"><?php echo apply_filters( 'woocommerce_lost_password_message', esc_html__( 'Lost your password? Please enter your username or email address. You will receive a link to create a new password via email.', 'fishotel' ) ); ?></p>

			<p class="fh-field">
				<label for="user_login"><?php esc_html_e( 'USERNAME OR EMAIL', 'fishotel' ); ?> <span class="req">*</span></label>
				<input class="fh-input" type="text" name="user_login" id="user_login" autocomplete="username" />
			</p>

			<?php do_action( 'woocommerce_lostpassword_form' ); ?>

			<div class="fh-form-actions">
				<input type="hidden" name="wc_reset_password" value="true" />
				<?php wp_nonce_field( 'lost_password', 'woocommerce-lost-password-nonce' ); ?>
				<button type="submit" class="fh-btn fh-btn--primary" value="<?php esc_attr_e( 'Reset password', 'fishotel' ); ?>">
					<?php esc_html_e( 'SEND RESET LINK', 'fishotel' ); ?>
				</button>
			</div>
		</form>
	</section>
</div>
