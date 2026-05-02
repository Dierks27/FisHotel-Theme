<?php
/**
 * Compatibility Guide — full 40×40 matrix partial (collapsible).
 * Grid is rendered client-side once the toggle is opened.
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="fh-compat-matrix" aria-labelledby="fh-compat-matrix-title">
	<button type="button" class="fh-compat-matrix__toggle" aria-expanded="false" aria-controls="fh-compat-matrix-body" data-fh-matrix-toggle>
		<span id="fh-compat-matrix-title">Show Full Compatibility Matrix</span>
		<span class="fh-compat-matrix__chevron" aria-hidden="true">▾</span>
	</button>
	<div class="fh-compat-matrix__body" id="fh-compat-matrix-body" data-fh-matrix-body hidden></div>
</section>
