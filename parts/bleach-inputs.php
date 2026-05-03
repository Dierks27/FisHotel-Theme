<?php
/**
 * Bleach Calculator — inputs panel.
 *
 * Volume + unit toggle, bleach concentration, use-case preset, neutralizer.
 * Values are populated/persisted by assets/js/bleach-calculator.js from
 * localStorage `fishotel_bleach_v1`. Defaults rendered server-side for
 * non-JS / first-paint correctness.
 *
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="fh-bleach__inputs" aria-label="Inputs">

	<div class="fh-bleach__row fh-bleach__row--volume">
		<label class="fh-bleach__lbl" for="fh-bleach-volume">Volume</label>
		<input id="fh-bleach-volume" class="fh-bleach__num" type="number" min="1" max="1000" step="1" value="75" inputmode="decimal">
		<div class="fh-bleach__unit-toggle" role="tablist" aria-label="Volume unit">
			<button type="button" class="fh-bleach__unit is-on" role="tab" data-unit="gallons" aria-selected="true">gal</button>
			<button type="button" class="fh-bleach__unit"       role="tab" data-unit="liters"  aria-selected="false">L</button>
		</div>
	</div>

	<div class="fh-bleach__row fh-bleach__row--conc">
		<label class="fh-bleach__lbl" for="fh-bleach-conc">Bleach %</label>
		<input id="fh-bleach-conc" class="fh-bleach__num" type="number" min="1" max="15" step="0.25" value="8.25" inputmode="decimal">
		<p class="fh-bleach__help">Household bleach: 3&ndash;8.25%. Liquid pool chlorine: 10&ndash;15%. Liquid sodium hypochlorite only &mdash; no granular pool shock or tablets.</p>
	</div>

	<fieldset class="fh-bleach__row fh-bleach__row--preset">
		<legend class="fh-bleach__lbl">Use case</legend>
		<div class="fh-bleach__presets" role="radiogroup">
			<label class="fh-bleach__preset is-on">
				<input type="radio" name="fh-bleach-preset" value="between_qt_fish" checked>
				<span class="fh-bleach__preset-name">Between QT Fish</span>
				<span class="fh-bleach__preset-desc">Standard sanitize between residents</span>
				<span class="fh-bleach__preset-spec">200 ppm &middot; 30 min</span>
			</label>
			<label class="fh-bleach__preset">
				<input type="radio" name="fh-bleach-preset" value="bleach_bomb">
				<span class="fh-bleach__preset-name">Bleach Bomb</span>
				<span class="fh-bleach__preset-desc">Post-outbreak / Velvet eradication</span>
				<span class="fh-bleach__preset-spec">500 ppm &middot; 60 min</span>
			</label>
			<label class="fh-bleach__preset">
				<input type="radio" name="fh-bleach-preset" value="custom">
				<span class="fh-bleach__preset-name">Custom</span>
				<span class="fh-bleach__preset-desc">Free fields for special situations</span>
				<span class="fh-bleach__preset-spec">user-defined</span>
			</label>
		</div>

		<div class="fh-bleach__custom" hidden>
			<label class="fh-bleach__custom-row">
				<span>Target ppm</span>
				<input id="fh-bleach-custom-ppm" type="number" min="50" max="2000" step="10" value="200">
			</label>
			<label class="fh-bleach__custom-row">
				<span>Contact min</span>
				<input id="fh-bleach-custom-min" type="number" min="5" max="240" step="5" value="30">
			</label>
		</div>
	</fieldset>

	<fieldset class="fh-bleach__row fh-bleach__row--neut">
		<legend class="fh-bleach__lbl">Neutralizer</legend>
		<div class="fh-bleach__neut-toggle" role="radiogroup">
			<label class="fh-bleach__neut is-on">
				<input type="radio" name="fh-bleach-neut" value="thiosulfate" checked>
				<span class="fh-bleach__neut-name">Sodium Thiosulfate</span>
				<span class="fh-bleach__neut-note">Correct stoichiometry for high-bleach scenarios. Buy in bulk for cheap.</span>
			</label>
			<label class="fh-bleach__neut">
				<input type="radio" name="fh-bleach-neut" value="prime">
				<span class="fh-bleach__neut-name">Seachem Prime</span>
				<span class="fh-bleach__neut-note">Familiar but limited. We&rsquo;ll warn you if your dose exceeds Prime&rsquo;s effective range.</span>
			</label>
		</div>
	</fieldset>

</section>
