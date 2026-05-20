<?php
/**
 * FisHotel — Check In / Reserve a Room (login + register).
 *
 * Logged-out view of /my-account/. WooCommerce loads this directly (it never
 * runs my-account.php for guests), so we render the shared page header here
 * too, then two side-by-side cards.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_customer_login_form' );

$registration_enabled = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' );

if ( function_exists( 'fishotel_account_render_page_header' ) ) {
	fishotel_account_render_page_header();
}
?>

<div class="fh-account-shell fh-account-shell--auth<?php echo $registration_enabled ? '' : ' is-single'; ?>">

	<section class="fh-card fh-auth-card fh-auth-card--login">
		<header class="fh-card__header fh-auth-card__header">
			<span class="fh-account-eyebrow fh-account-eyebrow--sm"><?php echo esc_html( fishotel_account_text( 'fh_account_login_eyebrow' ) ); ?></span>
			<h2 class="fh-card__title"><?php echo esc_html( fishotel_account_text( 'fh_account_login_title' ) ); ?></h2>
		</header>

		<form class="fh-account-form woocommerce-form woocommerce-form-login login" method="post">

			<?php do_action( 'woocommerce_login_form_start' ); ?>

			<p class="fh-field">
				<label for="username"><?php esc_html_e( 'EMAIL OR USERNAME', 'fishotel' ); ?> <span class="req">*</span></label>
				<input type="text" class="fh-input" name="username" id="username" autocomplete="username" value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" /><?php // phpcs:ignore WordPress.Security.NonceVerification ?>
			</p>

			<p class="fh-field">
				<label for="password"><?php esc_html_e( 'PASSWORD', 'fishotel' ); ?> <span class="req">*</span></label>
				<input class="fh-input" type="password" name="password" id="password" autocomplete="current-password" />
			</p>

			<?php do_action( 'woocommerce_login_form' ); ?>

			<div class="fh-auth-card__row">
				<label class="fh-checkbox">
					<input class="fh-checkbox__input" name="rememberme" type="checkbox" id="rememberme" value="forever" />
					<span class="fh-checkbox__box" aria-hidden="true"></span>
					<span class="fh-checkbox__label"><?php esc_html_e( 'Remember me', 'fishotel' ); ?></span>
				</label>
			</div>

			<div class="fh-form-actions">
				<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
				<button type="submit" class="fh-btn fh-btn--primary" name="login" value="<?php esc_attr_e( 'Log in', 'fishotel' ); ?>">
					<?php esc_html_e( 'CHECK IN', 'fishotel' ); ?>
				</button>
			</div>

			<a class="fh-auth-card__lost" href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'FORGOT PASSWORD?', 'fishotel' ); ?></a>

			<?php do_action( 'woocommerce_login_form_end' ); ?>

		</form>
	</section>

	<?php if ( $registration_enabled ) : ?>
		<section class="fh-card fh-auth-card fh-auth-card--register">
			<header class="fh-card__header fh-auth-card__header">
				<span class="fh-account-eyebrow fh-account-eyebrow--sm"><?php echo esc_html( fishotel_account_text( 'fh_account_register_eyebrow' ) ); ?></span>
				<h2 class="fh-card__title"><?php echo esc_html( fishotel_account_text( 'fh_account_register_title' ) ); ?></h2>
			</header>

			<form method="post" class="fh-account-form woocommerce-form woocommerce-form-register register" <?php do_action( 'woocommerce_register_form_tag' ); ?>>

				<?php do_action( 'woocommerce_register_form_start' ); ?>

				<?php if ( 'no' === get_option( 'woocommerce_registration_generate_username' ) ) : ?>
					<p class="fh-field">
						<label for="reg_username"><?php esc_html_e( 'USERNAME', 'fishotel' ); ?> <span class="req">*</span></label>
						<input type="text" class="fh-input" name="username" id="reg_username" autocomplete="username" value="<?php echo ( ! empty( $_POST['username'] ) ) ? esc_attr( wp_unslash( $_POST['username'] ) ) : ''; ?>" /><?php // phpcs:ignore WordPress.Security.NonceVerification ?>
					</p>
				<?php endif; ?>

				<p class="fh-field">
					<label for="reg_email"><?php esc_html_e( 'EMAIL ADDRESS', 'fishotel' ); ?> <span class="req">*</span></label>
					<input type="email" class="fh-input" name="email" id="reg_email" autocomplete="email" value="<?php echo ( ! empty( $_POST['email'] ) ) ? esc_attr( wp_unslash( $_POST['email'] ) ) : ''; ?>" /><?php // phpcs:ignore WordPress.Security.NonceVerification ?>
				</p>

				<?php if ( 'no' === get_option( 'woocommerce_registration_generate_password' ) ) : ?>
					<p class="fh-field">
						<label for="reg_password"><?php esc_html_e( 'PASSWORD', 'fishotel' ); ?> <span class="req">*</span></label>
						<input type="password" class="fh-input" name="password" id="reg_password" autocomplete="new-password" />
					</p>
				<?php else : ?>
					<p class="fh-field__hint fh-field__hint--block"><?php esc_html_e( 'A password will be sent to your email address.', 'fishotel' ); ?></p>
				<?php endif; ?>

				<?php do_action( 'woocommerce_register_form' ); ?>

				<div class="fh-form-actions">
					<?php wp_nonce_field( 'woocommerce-register', 'woocommerce-register-nonce' ); ?>
					<button type="submit" class="fh-btn fh-btn--primary" name="register" value="<?php esc_attr_e( 'Register', 'fishotel' ); ?>">
						<?php esc_html_e( 'REGISTER', 'fishotel' ); ?>
					</button>
				</div>

				<?php do_action( 'woocommerce_register_form_end' ); ?>

			</form>
		</section>
	<?php endif; ?>

</div>

<?php do_action( 'woocommerce_after_customer_login_form' ); ?>
