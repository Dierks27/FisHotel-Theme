<?php
/**
 * FisHotel — Reset password form.
 *
 * @package FisHotel
 * @var array $args { @type string $key @type string $login }
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() && function_exists( 'fishotel_account_render_page_header' ) ) {
	fishotel_account_render_page_header( [
		'title'    => __( 'SET A NEW PASSWORD', 'fishotel' ),
		'subtitle' => __( 'Choose a new key for your room.', 'fishotel' ),
	] );
}
?>

<div class="fh-account-shell fh-account-shell--auth is-single">
	<section class="fh-card fh-auth-card">
		<?php
		/**
		 * WC prints its notices (password mismatch, empty password,
		 * invalid/expired key) on this hook — without it the form just
		 * silently re-renders. Fired inside the card so the notice picks
		 * up the card styling.
		 */
		do_action( 'woocommerce_before_reset_password_form' );
		?>
		<form method="post" class="fh-account-form woocommerce-ResetPassword lost_reset_password">

			<p class="fh-field__hint fh-field__hint--block"><?php echo wp_kses_post( apply_filters( 'woocommerce_reset_password_message', esc_html__( 'Enter a new password below.', 'fishotel' ) ) ); ?></p>

			<p class="fh-field">
				<label for="password_1"><?php esc_html_e( 'NEW PASSWORD', 'fishotel' ); ?> <span class="req">*</span></label>
				<input type="password" class="fh-input" name="password_1" id="password_1" autocomplete="new-password" required aria-required="true" />
			</p>

			<p class="fh-field">
				<label for="password_2"><?php esc_html_e( 'CONFIRM NEW PASSWORD', 'fishotel' ); ?> <span class="req">*</span></label>
				<input type="password" class="fh-input" name="password_2" id="password_2" autocomplete="new-password" required aria-required="true" />
			</p>

			<input type="hidden" name="reset_key" value="<?php echo esc_attr( $args['key'] ); ?>" />
			<input type="hidden" name="reset_login" value="<?php echo esc_attr( $args['login'] ); ?>" />

			<?php do_action( 'woocommerce_resetpassword_form' ); ?>

			<div class="fh-form-actions">
				<input type="hidden" name="wc_reset_password" value="true" />
				<?php wp_nonce_field( 'reset_password', 'woocommerce-reset-password-nonce' ); ?>
				<button type="submit" class="fh-btn fh-btn--primary" value="<?php esc_attr_e( 'Save', 'fishotel' ); ?>">
					<?php esc_html_e( 'SAVE NEW PASSWORD', 'fishotel' ); ?>
				</button>
			</div>
		</form>
		<?php do_action( 'woocommerce_after_reset_password_form' ); ?>
	</section>
</div>
