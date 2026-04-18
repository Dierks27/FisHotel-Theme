<?php
/**
 * Template Name: FAQ Page
 *
 * Renders the FAQ page entirely from FisHotel Settings
 * (see Products → FisHotel Settings → FAQ Page). Ignores
 * the post's editor content so legacy blocks on /faq-2/
 * don't leak through.
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

$concierge  = $get( 'faq_concierge_label', 'The Concierge Desk' );
$page_title = $get( 'faq_page_title', 'Frequently Asked Questions' );
$intro      = $get( 'faq_page_intro', '' );
$qt_title   = $get( 'quarantine_section_title', 'A Stay at The FisHotel' );
$qt_sub     = $get( 'quarantine_section_subtitle', 'How does a stay at The FisHotel work?' );

$stages = class_exists( 'FisHotel_Admin_Settings' )
	? FisHotel_Admin_Settings::get_quarantine_stages()
	: [];

$faqs = class_exists( 'FisHotel_Admin_Settings' )
	? FisHotel_Admin_Settings::get_faq_items()
	: [];
?>

<main class="fh-faq-page">

	<section class="fh-faq-hero" aria-labelledby="fh-faq-title">
		<div class="fh-faq-hero__inner">
			<?php if ( $concierge ) : ?>
				<span class="fh-faq-eyebrow"><?php echo esc_html( $concierge ); ?></span>
			<?php endif; ?>
			<h1 id="fh-faq-title" class="fh-faq-hero__title"><?php echo esc_html( $page_title ); ?></h1>
			<span class="fh-faq-hero__rule" aria-hidden="true"></span>
			<?php if ( $intro ) : ?>
				<p class="fh-faq-hero__intro"><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( ! empty( $stages ) ) : ?>
	<section class="fh-qt-section" aria-labelledby="fh-qt-title">
		<div class="fh-qt-section__inner">
			<?php if ( $qt_title ) : ?>
				<h2 id="fh-qt-title" class="fh-qt-title"><?php echo esc_html( $qt_title ); ?></h2>
			<?php endif; ?>
			<?php if ( $qt_sub ) : ?>
				<p class="fh-qt-subtitle"><?php echo esc_html( $qt_sub ); ?></p>
			<?php endif; ?>

			<ol class="fh-qt-timeline">
				<?php foreach ( $stages as $i => $stage ) : ?>
					<li class="fh-qt-stage">
						<span class="fh-qt-dot" aria-hidden="true"></span>
						<?php if ( ! empty( $stage['duration'] ) ) : ?>
							<span class="fh-qt-duration"><?php echo esc_html( $stage['duration'] ); ?></span>
						<?php endif; ?>
						<h3 class="fh-qt-label"><?php echo esc_html( $stage['label'] ); ?></h3>
						<?php if ( ! empty( $stage['sublabel'] ) ) : ?>
							<p class="fh-qt-sublabel"><?php echo esc_html( $stage['sublabel'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $stage['description'] ) ) : ?>
							<p class="fh-qt-desc"><?php echo nl2br( esc_html( $stage['description'] ) ); ?></p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( ! empty( $faqs ) ) : ?>
	<section class="fh-faq-section" aria-label="Frequently asked questions">
		<div class="fh-faq-section__inner">
			<?php foreach ( $faqs as $item ) :
				$q = isset( $item['question'] ) ? $item['question'] : '';
				$a = isset( $item['answer'] )   ? $item['answer']   : '';
				$c = isset( $item['category'] ) ? $item['category'] : '';
				if ( $q === '' && trim( wp_strip_all_tags( $a ) ) === '' ) continue;
				?>
				<article class="fh-faq-card">
					<?php if ( $c ) : ?>
						<span class="fh-faq-card__cat"><?php echo esc_html( $c ); ?></span>
					<?php endif; ?>
					<?php if ( $q ) : ?>
						<h3 class="fh-faq-card__q"><?php echo esc_html( $q ); ?></h3>
					<?php endif; ?>
					<div class="fh-faq-card__a">
						<?php echo wp_kses_post( $a ); ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

</main>

<?php
get_footer();
