<?php
/**
 * Template Name: Compatibility Guide
 *
 * /compatibility-guide/ — the Build-a-Tank tool. v1 spec lives at
 * docs/COMPATIBILITY-GUIDE-SPEC.md. The page intentionally ignores its
 * post body so the layout stays canonical; all logic + data lives in
 * the parts/ partials, the conditional CSS, and assets/js/compatibility-guide.js.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

get_header();

$parts_dir = FISHOTEL_THEME_DIR . '/parts';
$include   = function ( $file ) use ( $parts_dir ) {
	$full = $parts_dir . '/' . $file;
	if ( file_exists( $full ) ) {
		include $full;
	}
};
?>

<main class="fh-compat" role="main" aria-labelledby="fh-compat-title">

	<?php $include( 'compat-hero.php' ); ?>

	<div class="fh-compat__inner">

		<?php $include( 'compat-tank-volume.php' ); ?>
		<?php $include( 'compat-tank-zones.php' ); ?>

		<aside class="fh-compat__legend-strip" aria-label="Verdict legend">
			<ul class="fh-compat__legend fh-compat__legend--inline">
				<li><span class="fh-compat__legend-dot fh-compat__legend-dot--c" aria-hidden="true"></span> Compatible</li>
				<li><span class="fh-compat__legend-dot fh-compat__legend-dot--w" aria-hidden="true"></span> Watch / Caution</li>
				<li><span class="fh-compat__legend-dot fh-compat__legend-dot--o" aria-hidden="true"></span> Order matters</li>
				<li><span class="fh-compat__legend-dot fh-compat__legend-dot--1" aria-hidden="true"></span> Same-genus / Single only</li>
				<li><span class="fh-compat__legend-dot fh-compat__legend-dot--n" aria-hidden="true"></span> Not recommended</li>
			</ul>
		</aside>

		<div class="fh-compat__panels">
			<?php $include( 'compat-conflicts-panel.php' ); ?>
		</div>

		<?php /* Tested Stocking Plans replaced by the live inventory panel — see v1.1.
		         The compat-sample-tanks partial and assets/data/sample-tanks.json
		         are intentionally kept in-tree for possible v2 reuse.
		   $include( 'compat-sample-tanks.php' ); */ ?>
		<?php $include( 'compat-inventory-panel.php' ); ?>
		<?php $include( 'compat-matrix-view.php' ); ?>

		<footer class="fh-compat__footer">
			<p class="fh-compat__disclaimer">
				Compatibility is a guideline, not a guarantee. Tank size, individual temperament, and order of introduction affect outcomes. When in doubt, <a href="<?php echo esc_url( home_url( '/contacts/' ) ); ?>">contact us</a>.
			</p>
			<p class="fh-compat__credits">
				Compatibility data informed by H. Hammond's Cirrhilabrus phylogram, Humble.Fish community contributors, and FisHotel quarantine experience.
			</p>
		</footer>

	</div>

	<?php $include( 'compat-add-modal.php' ); ?>

</main>

<?php
get_footer();
