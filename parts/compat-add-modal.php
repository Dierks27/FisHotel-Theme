<?php
/**
 * Compatibility Guide — Add Fish modal partial.
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="fh-compat-modal" id="fh-compat-modal" aria-hidden="true" role="dialog" aria-labelledby="fh-compat-modal-title">
	<div class="fh-compat-modal__backdrop" data-fh-modal-close></div>
	<div class="fh-compat-modal__panel" role="document">

		<header class="fh-compat-modal__head">
			<h2 id="fh-compat-modal-title" class="fh-compat-modal__title">Add Fish</h2>
			<button type="button" class="fh-compat-modal__close" data-fh-modal-close aria-label="Close">×</button>
		</header>

		<nav class="fh-compat-modal__tabs" role="tablist">
			<button type="button" class="fh-compat-modal__tab is-active" role="tab" aria-selected="true" data-fh-tab="browse">Browse</button>
			<button type="button" class="fh-compat-modal__tab" role="tab" aria-selected="false" data-fh-tab="search">Search</button>
		</nav>

		<div class="fh-compat-modal__body">

			<section class="fh-compat-modal__pane is-active" data-fh-pane="browse" role="tabpanel">
				<div class="fh-compat-categories" data-fh-categories aria-label="Categories"></div>
				<div class="fh-compat-species" data-fh-species hidden>
					<button type="button" class="fh-compat-species__back" data-fh-species-back>← Back to categories</button>
					<h3 class="fh-compat-species__title" data-fh-species-title></h3>
					<p class="fh-compat-species__desc" data-fh-species-desc></p>
					<ul class="fh-compat-species__list" data-fh-species-list></ul>
				</div>
			</section>

			<section class="fh-compat-modal__pane" data-fh-pane="search" role="tabpanel" hidden>
				<label class="fh-compat-search__label" for="fh-compat-search-input">Search by common or scientific name</label>
				<input type="text" class="fh-compat-search__input" id="fh-compat-search-input" placeholder="e.g. Yellow Tang, Cirrhilabrus..." autocomplete="off">
				<ul class="fh-compat-search__results" data-fh-search-results aria-live="polite"></ul>
			</section>

		</div>

		<footer class="fh-compat-modal__foot">
			<fieldset class="fh-compat-modal__zone-pick">
				<legend>Add to:</legend>
				<label><input type="radio" name="fh-compat-zone" value="my_tank" checked> My Tank</label>
				<label><input type="radio" name="fh-compat-zone" value="considering"> Considering</label>
			</fieldset>
			<button type="button" class="fh-compat-modal__add" data-fh-modal-add disabled>Add Fish</button>
		</footer>

	</div>
</div>
