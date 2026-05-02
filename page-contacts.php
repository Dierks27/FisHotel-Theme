<?php
/**
 * Template Name: Contacts
 *
 * Two-column contacts layout. Content lives in
 * Products → FisHotel Settings → Contacts Page; the post's editor
 * content is intentionally ignored so the layout stays canonical.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

get_header();

$get = function ( $key, $fallback = '' ) {
	if ( class_exists( 'FisHotel_Admin_Settings' ) ) {
		$v = FisHotel_Admin_Settings::get( $key );
		return $v !== '' ? $v : $fallback;
	}
	return $fallback;
};

$eyebrow        = $get( 'contacts_eyebrow',      'FRONT DESK' );
$heading        = $get( 'contacts_heading',      'Contact' );
$location_text  = $get( 'contacts_location_text', '' );
$map_id         = (int) $get( 'contacts_location_map_image', 0 );
$email          = $get( 'contacts_email',        '' );
$email_label    = $get( 'contacts_email_label',  'Write us' );
$forum_url      = $get( 'contacts_forum_url',    '' );
$forum_label    = $get( 'contacts_forum_label',  'Visit our Humble.Fish forum' );
$form_shortcode = $get( 'contacts_form_shortcode', '' );

$map_url = $map_id ? wp_get_attachment_image_url( $map_id, 'large' ) : '';
$map_alt = $map_id ? get_post_meta( $map_id, '_wp_attachment_image_alt', true ) : '';
if ( $map_alt === '' ) {
	$map_alt = $location_text;
}
?>

<main class="fh-contacts" role="main">
	<div class="fh-contacts__inner">

		<section class="fh-contacts__col fh-contacts__col--left" aria-labelledby="fh-contacts-heading">

			<?php if ( $eyebrow ) : ?>
				<span class="fh-contacts__eyebrow"><?php echo esc_html( $eyebrow ); ?></span>
			<?php endif; ?>

			<?php if ( $heading ) : ?>
				<h1 id="fh-contacts-heading" class="fh-contacts__heading"><?php echo esc_html( $heading ); ?></h1>
			<?php endif; ?>

			<?php if ( $map_url || $location_text ) : ?>
				<div class="fh-contacts__location">
					<?php if ( $map_url ) : ?>
						<div class="fh-contacts__map">
							<img src="<?php echo esc_url( $map_url ); ?>" alt="<?php echo esc_attr( $map_alt ); ?>" loading="lazy">
						</div>
					<?php endif; ?>
					<?php if ( $location_text ) : ?>
						<p class="fh-contacts__location-text"><?php echo esc_html( $location_text ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $email !== '' || ( $forum_url !== '' && $forum_label !== '' ) ) : ?>
				<ul class="fh-contacts__channels">
					<?php if ( $email !== '' ) : ?>
						<li>
							<a href="<?php echo esc_url( 'mailto:' . $email ); ?>" class="fh-contacts__channel">
								<span class="fh-contacts__channel-icon" aria-hidden="true">
									<svg viewBox="0 0 24 24" focusable="false">
										<rect x="3" y="5" width="18" height="14" rx="1.5" />
										<path d="M3.5 6l8.5 7 8.5-7" stroke-linecap="round" stroke-linejoin="round" />
									</svg>
								</span>
								<span class="fh-contacts__channel-text">
									<?php if ( $email_label !== '' ) : ?>
										<span class="fh-contacts__channel-label"><?php echo esc_html( $email_label ); ?></span>
									<?php endif; ?>
									<span class="fh-contacts__channel-value"><?php echo esc_html( $email ); ?></span>
								</span>
							</a>
						</li>
					<?php endif; ?>
					<?php if ( $forum_url !== '' && $forum_label !== '' ) : ?>
						<li>
							<a href="<?php echo esc_url( $forum_url ); ?>" class="fh-contacts__channel" target="_blank" rel="noopener">
								<span class="fh-contacts__channel-icon" aria-hidden="true">
									<svg viewBox="0 0 24 24" focusable="false">
										<path d="M4 5h16v11H8l-4 4z" stroke-linejoin="round" />
										<path d="M8 9h8M8 12h6" stroke-linecap="round" />
									</svg>
								</span>
								<span class="fh-contacts__channel-text">
									<span class="fh-contacts__channel-value"><?php echo esc_html( $forum_label ); ?></span>
								</span>
							</a>
						</li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>

		</section>

		<section class="fh-contacts__col fh-contacts__col--right" aria-labelledby="fh-contacts-form-heading">
			<span class="fh-contacts__eyebrow">Get In Touch</span>
			<h2 id="fh-contacts-form-heading" class="fh-contacts__form-heading">Send us a message</h2>
			<div class="fh-contacts-form">
				<?php
				if ( $form_shortcode !== '' ) {
					echo do_shortcode( $form_shortcode );
				}
				?>
			</div>
		</section>

	</div>
</main>

<?php
get_footer();
