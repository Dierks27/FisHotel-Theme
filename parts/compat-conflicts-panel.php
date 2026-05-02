<?php
/**
 * Compatibility Guide — conflicts panel partial.
 * Sticky on desktop; collapsible drawer on mobile (toggled via JS).
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="fh-compat-conflicts" aria-labelledby="fh-compat-conflicts-title" data-fh-conflicts-panel>
	<header class="fh-compat-conflicts__head">
		<h2 id="fh-compat-conflicts-title" class="fh-compat-conflicts__title">Conflicts <span class="fh-compat-conflicts__count" data-fh-conflicts-count>(0)</span></h2>
		<button type="button" class="fh-compat-conflicts__toggle" data-fh-conflicts-toggle aria-expanded="true" aria-controls="fh-compat-conflicts-list">
			<span class="fh-compat-conflicts__toggle-text">Hide</span>
		</button>
	</header>
	<ul class="fh-compat-conflicts__list" id="fh-compat-conflicts-list" data-fh-conflicts-list></ul>
	<p class="fh-compat-conflicts__empty" data-fh-conflicts-empty>All clear! Your stocking plan looks compatible.</p>
</section>
