<?php
/**
 * Template Name: Bleach Calculator
 *
 * /bleach-calculator/ — tank teardown math: bleach dose, contact time,
 * neutralizer. v1 spec: docs/BLEACH-CALCULATOR-SPEC.md. The page intentionally
 * ignores its post body so the layout stays canonical; logic lives in
 * parts/, the conditional CSS, and assets/js/bleach-calculator.js.
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

<main class="fh-bleach" role="main" aria-labelledby="fh-bleach-title">
	<div class="fh-bleach__wrap">

		<?php $include( 'bleach-hero.php' ); ?>
		<?php $include( 'bleach-inputs.php' ); ?>
		<?php $include( 'bleach-illustration.php' ); ?>
		<?php $include( 'bleach-output.php' ); ?>
		<?php $include( 'bleach-warnings.php' ); ?>

	</div>
</main>

<?php
get_footer();
