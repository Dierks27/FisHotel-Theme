<?php
/**
 * FisHotel Native Contact Form
 *
 * Replaces the prior CF7 shortcode path with a built-in handler.
 * No plugin dependency; spam defenses are honeypot + 3-second time
 * gate + WP nonce. reCAPTCHA can be layered on top later — see
 * RECAPTCHA-CHECKLIST.md.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

class FisHotel_Contact_Form {

	const NONCE_ACTION = 'fh_contact_submit';
	const FORM_ACTION  = 'fh_contact_submit';
	const MIN_SECONDS  = 3;

	public static function init() {
		add_action( 'init', [ __CLASS__, 'handle_submission' ] );
	}

	/**
	 * Stateless per-visitor key for transient flash storage.
	 * Stable across the PRG redirect (same IP + UA + salt) but unique
	 * enough that flash messages don't bleed between users.
	 */
	protected static function visitor_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )    ? (string) $_SERVER['REMOTE_ADDR']    : '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		return 'fh_contact_msg_' . substr( md5( $ip . '|' . $ua . '|' . wp_salt( 'nonce' ) ), 0, 16 );
	}

	protected static function store_message( $type, $text, $old = [] ) {
		set_transient(
			self::visitor_key(),
			[ 'type' => $type, 'text' => $text, 'old' => $old ],
			60
		);
	}

	/** Pop the visitor's flash message (or null). One-shot. */
	public static function consume_message() {
		$key = self::visitor_key();
		$msg = get_transient( $key );
		if ( $msg ) {
			delete_transient( $key );
		}
		return $msg ?: null;
	}

	public static function handle_submission() {
		if ( empty( $_POST['action'] ) || $_POST['action'] !== self::FORM_ACTION ) {
			return;
		}
		if ( ! isset( $_POST['fh_contact_nonce'] ) || ! wp_verify_nonce( $_POST['fh_contact_nonce'], self::NONCE_ACTION ) ) {
			return;
		}

		$referer = wp_get_referer();
		if ( ! $referer ) {
			$referer = home_url( '/contacts/' );
		}

		// Honeypot — bots fill this; real users never see it. Fake-success
		// so the bot doesn't learn it was caught.
		if ( ! empty( $_POST['fh_contact_website'] ) ) {
			self::store_message( 'success', "Thanks — your message has been sent." );
			wp_safe_redirect( $referer );
			exit;
		}

		// Time gate — anything submitted under MIN_SECONDS is bot-likely.
		$loaded = isset( $_POST['fh_contact_loaded'] ) ? (int) $_POST['fh_contact_loaded'] : 0;
		if ( $loaded > 0 && ( time() - $loaded ) < self::MIN_SECONDS ) {
			self::store_message( 'success', "Thanks — your message has been sent." );
			wp_safe_redirect( $referer );
			exit;
		}

		$name    = sanitize_text_field( wp_unslash( $_POST['fh_contact_name']    ?? '' ) );
		$email   = sanitize_email(      wp_unslash( $_POST['fh_contact_email']   ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['fh_contact_message'] ?? '' ) );

		$errors = [];
		if ( $name === '' )         $errors[] = 'Name is required.';
		if ( ! is_email( $email ) ) $errors[] = 'Please enter a valid email.';
		if ( $message === '' )      $errors[] = 'Message is required.';

		if ( $errors ) {
			self::store_message(
				'error',
				implode( ' ', $errors ),
				[ 'name' => $name, 'email' => $email, 'message' => $message ]
			);
			wp_safe_redirect( $referer );
			exit;
		}

		$to = '';
		if ( class_exists( 'FisHotel_Admin_Settings' ) ) {
			$to = (string) FisHotel_Admin_Settings::get( 'contacts_email' );
		}
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}

		$subject = sprintf( '[FisHotel Contact] Message from %s', $name );
		$body    = "From: {$name} <{$email}>\n\n{$message}";
		$headers = [ 'Reply-To: ' . $name . ' <' . $email . '>' ];

		wp_mail( $to, $subject, $body, $headers );

		self::store_message( 'success', "Thanks — your message has been sent. We'll be in touch shortly." );
		wp_safe_redirect( $referer );
		exit;
	}

	/** Render the form, including any flash message. Echoes HTML. */
	public static function render() {
		$msg  = self::consume_message();
		$old  = ( $msg && ! empty( $msg['old'] ) ) ? $msg['old'] : [];
		$name = isset( $old['name'] )    ? (string) $old['name']    : '';
		$em   = isset( $old['email'] )   ? (string) $old['email']   : '';
		$body = isset( $old['message'] ) ? (string) $old['message'] : '';
		?>
		<?php if ( $msg ) : ?>
			<div class="fh-contacts-form__notice fh-contacts-form__notice--<?php echo esc_attr( $msg['type'] ); ?>" role="status">
				<?php echo esc_html( $msg['text'] ); ?>
			</div>
		<?php endif; ?>

		<form class="fh-contacts-form" method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, 'fh_contact_nonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::FORM_ACTION ); ?>">
			<input type="hidden" name="fh_contact_loaded" value="<?php echo esc_attr( time() ); ?>">

			<p class="fh-contacts-form__honeypot" aria-hidden="true">
				<label>Leave this field empty
					<input type="text" name="fh_contact_website" tabindex="-1" autocomplete="off">
				</label>
			</p>

			<p class="fh-contacts-form__field">
				<label for="fh-contact-name">Name</label>
				<input type="text" id="fh-contact-name" name="fh_contact_name" value="<?php echo esc_attr( $name ); ?>" required>
			</p>

			<p class="fh-contacts-form__field">
				<label for="fh-contact-email">Email</label>
				<input type="email" id="fh-contact-email" name="fh_contact_email" value="<?php echo esc_attr( $em ); ?>" required>
			</p>

			<p class="fh-contacts-form__field">
				<label for="fh-contact-message">Message</label>
				<textarea id="fh-contact-message" name="fh_contact_message" rows="6" required><?php echo esc_textarea( $body ); ?></textarea>
			</p>

			<p class="fh-contacts-form__submit">
				<button type="submit">Send Message</button>
			</p>
		</form>
		<?php
	}
}

FisHotel_Contact_Form::init();
