<?php
/**
 * Compatibility Guide — two-zone build-a-tank partial.
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="fh-compat-zones" aria-label="Build a tank">
	<article class="fh-compat-zone" data-zone="my_tank">
		<header class="fh-compat-zone__head">
			<div>
				<h2 class="fh-compat-zone__title">My Tank</h2>
				<p class="fh-compat-zone__sub">Fish you currently keep</p>
			</div>
			<span class="fh-compat-zone__count" data-fh-count="my_tank">0 fish</span>
		</header>
		<button type="button" class="fh-compat-zone__add" data-fh-open-modal="my_tank">
			<span aria-hidden="true">+</span> Add Fish
		</button>
		<ul class="fh-compat-zone__list" data-fh-list="my_tank" aria-live="polite"></ul>
		<p class="fh-compat-zone__empty" data-fh-empty="my_tank">No fish yet — add the species you currently keep.</p>
	</article>

	<article class="fh-compat-zone" data-zone="considering">
		<header class="fh-compat-zone__head">
			<div>
				<h2 class="fh-compat-zone__title">Considering</h2>
				<p class="fh-compat-zone__sub">Fish you're thinking about adding</p>
			</div>
			<span class="fh-compat-zone__count" data-fh-count="considering">0 fish</span>
		</header>
		<button type="button" class="fh-compat-zone__add" data-fh-open-modal="considering">
			<span aria-hidden="true">+</span> Add Fish
		</button>
		<ul class="fh-compat-zone__list" data-fh-list="considering" aria-live="polite"></ul>
		<p class="fh-compat-zone__empty" data-fh-empty="considering">Nothing under consideration. Add a fish to test the pairing.</p>
	</article>
</section>
