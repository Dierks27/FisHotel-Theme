<?php
/**
 * Template Name: About — Founder's Edition
 *
 * Renders the About page as a "Founder's Edition" of The FisHotel Gazette.
 * Content lives in Products → FisHotel Settings → About Page; the post's
 * editor content is intentionally ignored so the layout stays canonical.
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

$masthead     = $get( 'about_masthead',     'THE FISHOTEL GAZETTE' );
$edition_line = $get( 'about_edition_line', "FOUNDER'S EDITION · EST. 1923 · ONE FISH CENT" );
$dateline     = $get( 'about_dateline',     'BLAINE, MINNESOTA' );
$headline     = $get( 'about_headline',     'From a Walkout Closet to 1,000 Gallons' );
$dek          = $get( 'about_dek',          '' );
$byline       = $get( 'about_byline',       '' );
$pull_quote   = $get( 'about_pull_quote',   '' );
$signoff      = $get( 'about_signoff',      '' );
$footer_line  = $get( 'about_footer_line',  '' );

// Image slot meta — IDs of 0 mean "no image attached, hide the figure".
$hero_id          = (int) $get( 'about_hero_image', 0 );
$hero_caption     = $get( 'about_hero_caption', '' );
$hero_credit      = $get( 'about_hero_credit',  '' );
$inline_1_id      = (int) $get( 'about_inline_image_1', 0 );
$inline_1_caption = $get( 'about_inline_image_1_caption', '' );
$inline_1_credit  = $get( 'about_inline_image_1_credit',  '' );
$inline_2_id      = (int) $get( 'about_inline_image_2', 0 );
$inline_2_caption = $get( 'about_inline_image_2_caption', '' );
$inline_2_credit  = $get( 'about_inline_image_2_credit',  '' );

$body = class_exists( 'FisHotel_Admin_Settings' )
	? FisHotel_Admin_Settings::get_about_body()
	: '';

/**
 * Split body into per-H2 sections so we can interleave images and the
 * pull quote between them. preg_split with a leading lookahead emits an
 * empty $parts[0]; we fold any prelude into the first section.
 */
$sections = [];
if ( $body !== '' ) {
	$parts = preg_split( '/(?=<h2[\s>])/i', $body );
	if ( is_array( $parts ) && ! empty( $parts ) ) {
		$prelude  = array_shift( $parts );
		$sections = $parts;
		if ( $prelude !== '' ) {
			if ( ! empty( $sections ) ) {
				$sections[0] = $prelude . $sections[0];
			} else {
				$sections = [ $prelude ];
			}
		}
	}
}

/**
 * Render a <figure> if and only if an attachment ID is set. Empty IDs
 * yield no markup at all — no broken images, no orphan figures.
 */
$render_photo = function ( $attachment_id, $caption, $credit, $modifier ) {
	$attachment_id = (int) $attachment_id;
	if ( ! $attachment_id ) {
		return;
	}
	$alt = $caption !== '' ? $caption : get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
	$img = wp_get_attachment_image(
		$attachment_id,
		'large',
		false,
		[
			'alt'     => $alt,
			'loading' => 'lazy',
		]
	);
	if ( ! $img ) {
		return;
	}
	?>
	<figure class="about-photo about-photo--<?php echo esc_attr( $modifier ); ?>">
		<?php echo $img; // wp_get_attachment_image() output is already escaped. ?>
		<?php if ( $caption !== '' || $credit !== '' ) : ?>
			<figcaption>
				<?php if ( $caption !== '' ) : ?>
					<span class="caption"><?php echo esc_html( $caption ); ?></span>
				<?php endif; ?>
				<?php if ( $credit !== '' ) : ?>
					<span class="credit"><?php echo esc_html( $credit ); ?></span>
				<?php endif; ?>
			</figcaption>
		<?php endif; ?>
	</figure>
	<?php
};
?>

<main class="fh-about" role="main">
	<article class="fh-gazette" aria-labelledby="fh-gazette-headline">

		<header class="fh-gazette__masthead">
			<div class="fh-gazette__rule fh-gazette__rule--double" aria-hidden="true"></div>
			<h1 class="fh-gazette__masthead-text"><?php echo esc_html( $masthead ); ?></h1>
			<div class="fh-gazette__rule fh-gazette__rule--double" aria-hidden="true"></div>
			<?php if ( $edition_line ) : ?>
				<p class="fh-gazette__edition"><?php echo esc_html( $edition_line ); ?></p>
			<?php endif; ?>
		</header>

		<?php if ( $dateline ) : ?>
			<p class="fh-gazette__dateline"><?php echo esc_html( $dateline ); ?></p>
		<?php endif; ?>

		<div class="fh-gazette__ornament" aria-hidden="true">✦</div>

		<h2 id="fh-gazette-headline" class="fh-gazette__headline"><?php echo esc_html( $headline ); ?></h2>

		<?php if ( $dek ) : ?>
			<p class="fh-gazette__dek"><?php echo esc_html( $dek ); ?></p>
		<?php endif; ?>

		<?php if ( $byline ) : ?>
			<p class="fh-gazette__byline"><?php echo esc_html( $byline ); ?></p>
		<?php endif; ?>

		<div class="fh-gazette__rule fh-gazette__rule--heavy" aria-hidden="true"></div>

		<?php $render_photo( $hero_id, $hero_caption, $hero_credit, 'hero' ); ?>

		<?php if ( ! empty( $sections ) ) : ?>
			<div class="fh-gazette__body">
				<?php foreach ( $sections as $i => $section_html ) : ?>

					<div class="fh-gazette__columns">
						<?php echo wp_kses_post( $section_html ); ?>
					</div>

					<?php if ( $i === 0 ) : ?>
						<?php $render_photo( $inline_1_id, $inline_1_caption, $inline_1_credit, 'inline' ); ?>
					<?php elseif ( $i === 1 && $pull_quote !== '' ) : ?>
						<blockquote class="fh-gazette__pullquote">
							<span class="fh-gazette__pullquote-mark fh-gazette__pullquote-mark--open" aria-hidden="true">&#10077;</span>
							<p><?php echo esc_html( $pull_quote ); ?></p>
							<span class="fh-gazette__pullquote-mark fh-gazette__pullquote-mark--close" aria-hidden="true">&#10078;</span>
						</blockquote>
					<?php elseif ( $i === 2 ) : ?>
						<?php $render_photo( $inline_2_id, $inline_2_caption, $inline_2_credit, 'inline' ); ?>
					<?php endif; ?>

				<?php endforeach; ?>
			</div>
		<?php elseif ( $body !== '' ) : ?>
			<div class="fh-gazette__body">
				<div class="fh-gazette__columns">
					<?php echo wp_kses_post( $body ); ?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $signoff ) : ?>
			<p class="fh-gazette__signoff"><?php echo esc_html( $signoff ); ?></p>
		<?php endif; ?>

		<div class="fh-gazette__endmark" aria-hidden="true">✦ &nbsp; ✦ &nbsp; ✦</div>

		<?php if ( $footer_line ) : ?>
			<p class="fh-gazette__footer"><?php echo esc_html( $footer_line ); ?></p>
		<?php endif; ?>

	</article>
</main>

<?php
get_footer();
