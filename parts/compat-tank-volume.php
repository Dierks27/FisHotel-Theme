<?php
/**
 * Compatibility Guide — tank volume input partial.
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="fh-compat-volume" aria-label="Tank volume">
	<div class="fh-compat-volume__inner">
		<label class="fh-compat-volume__label" for="fh-compat-volume">Tank Volume <span>(gallons)</span></label>
		<input
			class="fh-compat-volume__input"
			type="number"
			id="fh-compat-volume"
			name="tank_volume"
			min="20"
			max="500"
			step="1"
			placeholder="Enter your tank volume"
			inputmode="numeric"
			autocomplete="off"
		>
		<p class="fh-compat-volume__hint">Volume affects compatibility — bigger tanks tolerate more.</p>
	</div>
</section>
