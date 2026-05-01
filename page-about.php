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

$body = class_exists( 'FisHotel_Admin_Settings' )
	? FisHotel_Admin_Settings::get_about_body()
	: '';

// Split body into pre-quote and post-quote halves so the pull quote can
// physically interrupt the two-column flow. We break after the second <h2>
// section ("What's broken in this hobby") if it exists; otherwise we put
// the quote at the midpoint between top-level blocks.
$body_top    = $body;
$body_bottom = '';

if ( $pull_quote !== '' && $body !== '' ) {
	// Split on <h2> boundaries, keep delimiters. Lookahead at offset 0 emits
	// an empty leading part, so $parts[0] is any prelude before the first
	// H2, $parts[1] is the first H2 section, etc.
	$parts = preg_split( '/(?=<h2[\s>])/i', $body );
	if ( is_array( $parts ) && count( $parts ) >= 4 ) {
		// Pull quote drops in after the second H2 section.
		$body_top    = implode( '', array_slice( $parts, 0, 3 ) );
		$body_bottom = implode( '', array_slice( $parts, 3 ) );
	}
}
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

		<?php if ( $body !== '' ) : ?>
			<div class="fh-gazette__body">
				<div class="fh-gazette__columns">
					<?php echo wp_kses_post( $body_top ); ?>
				</div>

				<?php if ( $pull_quote !== '' ) : ?>
					<blockquote class="fh-gazette__pullquote">
						<span class="fh-gazette__pullquote-mark fh-gazette__pullquote-mark--open" aria-hidden="true">&#10077;</span>
						<p><?php echo esc_html( $pull_quote ); ?></p>
						<span class="fh-gazette__pullquote-mark fh-gazette__pullquote-mark--close" aria-hidden="true">&#10078;</span>
					</blockquote>
				<?php endif; ?>

				<?php if ( $body_bottom !== '' ) : ?>
					<div class="fh-gazette__columns">
						<?php echo wp_kses_post( $body_bottom ); ?>
					</div>
				<?php endif; ?>
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
