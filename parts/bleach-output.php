<?php
/**
 * Bleach Calculator — output panel.
 *
 * 4 step cards + timeline graph + actions. All numeric content is filled
 * in by assets/js/bleach-calculator.js on input change. Server-side defaults
 * are 75 gal @ 8.25%, Between QT Fish, Sodium Thiosulfate.
 *
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="fh-bleach__output" aria-live="polite" aria-label="Calculated dose">

	<div class="fh-bleach__steps">

		<article class="fh-bleach__step fh-bleach__step--bleach">
			<div class="fh-bleach__step-num">1</div>
			<h3 class="fh-bleach__step-name">Bleach Dose</h3>

			<div class="fh-bleach__cup-wrap" aria-hidden="true">
				<svg class="fh-bleach__cup-svg" viewBox="0 0 320 320" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Vintage glass measuring cup">
					<defs>
						<pattern id="fh-bleach-cup-hatch" patternUnits="userSpaceOnUse" width="6" height="6" patternTransform="rotate(45)">
							<line x1="0" y1="0" x2="0" y2="6" stroke="currentColor" stroke-width="0.6" opacity="0.55"/>
						</pattern>
					</defs>

					<path d="M70 90 Q40 100 40 150 Q40 200 70 210" fill="none" stroke="currentColor" stroke-width="2"/>
					<path d="M70 102 Q52 110 52 150 Q52 190 70 198" fill="none" stroke="currentColor" stroke-width="1.1" opacity="0.7"/>

					<path d="M90 40 L215 40 Q228 40 232 52 L222 64 L218 280 Q218 286 212 286 L98 286 Q92 286 92 280 L88 52 Q90 40 90 40 Z"
						  fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>

					<rect class="fh-bleach__cup-fill" data-fh="cup_fill"
						  x="92" y="280" width="126" height="0"
						  fill="url(#fh-bleach-cup-hatch)" stroke="none"/>

					<ellipse cx="156" cy="40" rx="65" ry="3.5" fill="none" stroke="currentColor" stroke-width="1" opacity="0.5"/>

					<g stroke="currentColor" stroke-width="1" fill="none">
						<line x1="92" y1="40"  x2="102" y2="40"/>
						<line x1="92" y1="70"  x2="100" y2="70"/>
						<line x1="92" y1="100" x2="102" y2="100"/>
						<line x1="92" y1="130" x2="100" y2="130"/>
						<line x1="92" y1="160" x2="102" y2="160"/>
						<line x1="92" y1="190" x2="100" y2="190"/>
						<line x1="92" y1="220" x2="102" y2="220"/>
						<line x1="92" y1="250" x2="100" y2="250"/>
						<line x1="92" y1="265" x2="98"  y2="265"/>
					</g>

					<g font-family="Playfair Display, Georgia, serif" font-size="11" fill="currentColor" text-anchor="end">
						<text x="86" y="44">4</text>
						<text x="86" y="74">3&frac12;</text>
						<text x="86" y="104">3</text>
						<text x="86" y="134">2&frac12;</text>
						<text x="86" y="164">2</text>
						<text x="86" y="194">1&frac12;</text>
						<text x="86" y="224">1</text>
						<text x="86" y="254">&frac12;</text>
						<text x="86" y="269" font-size="9">&frac14;</text>
					</g>

					<g stroke="currentColor" stroke-width="1" fill="none">
						<line x1="218" y1="40"  x2="226" y2="40"/>
						<line x1="218" y1="100" x2="226" y2="100"/>
						<line x1="218" y1="160" x2="226" y2="160"/>
						<line x1="218" y1="220" x2="226" y2="220"/>
					</g>

					<g font-family="Playfair Display, Georgia, serif" font-size="10" fill="currentColor" text-anchor="start">
						<text x="230" y="44">1000</text>
						<text x="230" y="104">750</text>
						<text x="230" y="164">500</text>
						<text x="230" y="224">250</text>
						<text x="252" y="14" font-style="italic" font-size="9" opacity="0.6">ml</text>
						<text x="84"  y="14" font-style="italic" font-size="9" opacity="0.6" text-anchor="end">cups</text>
					</g>

					<text x="156" y="232" text-anchor="middle" font-family="Playfair Display, Georgia, serif"
						  font-size="9" font-style="italic" letter-spacing="2" fill="currentColor" opacity="0.45">APOTHECARY</text>
				</svg>

				<div class="fh-bleach__cup-readout">
					<div class="fh-bleach__cup-label" data-fh="cup_label">&mdash;</div>
					<div class="fh-bleach__cup-repeat" data-fh="cup_repeat" hidden>&mdash;</div>
				</div>
			</div>

			<div class="fh-bleach__big">
				<span class="fh-bleach__big-num" data-fh="bleach_ml">&mdash;</span>
				<span class="fh-bleach__big-unit">ml</span>
			</div>
			<p class="fh-bleach__sub" data-fh="bleach_sub">&mdash;</p>
			<p class="fh-bleach__math" data-fh="bleach_math">&mdash;</p>
		</article>

		<article class="fh-bleach__step">
			<div class="fh-bleach__step-num">2</div>
			<h3 class="fh-bleach__step-name">Contact Time</h3>
			<div class="fh-bleach__big">
				<span class="fh-bleach__big-num" data-fh="contact_min">&mdash;</span>
				<span class="fh-bleach__big-unit">min</span>
			</div>
			<p class="fh-bleach__sub">Let it sit. Don&rsquo;t agitate. Cover if possible.</p>

			<div class="fh-bleach__timer">
				<div class="fh-bleach__timer-label">Countdown Timer</div>
				<div class="fh-bleach__timer-display" data-fh="timer_display" role="timer" aria-live="off">00:00</div>
				<div class="fh-bleach__timer-controls" data-fh="timer_controls">
					<!-- buttons rendered by JS to match running/paused/completed state -->
				</div>
				<div class="fh-bleach__timer-complete" data-fh="timer_complete" hidden>Soak complete &mdash; add neutralizer now.</div>
				<p class="fh-bleach__timer-note">Timer never auto-starts; begin after the bleach is added.</p>
			</div>
		</article>

		<article class="fh-bleach__step" data-fh-step="neutralize">
			<div class="fh-bleach__step-num">3</div>
			<h3 class="fh-bleach__step-name">Neutralize</h3>
			<div class="fh-bleach__big" data-fh="neut_box">
				<span class="fh-bleach__big-num" data-fh="neut_amount">&mdash;</span>
				<span class="fh-bleach__big-unit" data-fh="neut_unit">g sodium thiosulfate</span>
			</div>
			<p class="fh-bleach__warning" data-fh="neut_warn" hidden>Above Prime&rsquo;s effective range &mdash; switch to sodium thiosulfate.</p>
			<p class="fh-bleach__math" data-fh="neut_math">&mdash;</p>
			<p class="fh-bleach__sub">Stir gently. Wait 5 minutes. Test with a chlorine test strip if you have one.</p>
		</article>

		<article class="fh-bleach__step">
			<div class="fh-bleach__step-num">4</div>
			<h3 class="fh-bleach__step-name">Rinse</h3>
			<p class="fh-bleach__sub">2&ndash;3 fresh-water rinses recommended after neutralization. Test final water with a chlorine test strip to confirm zero before adding livestock.</p>
		</article>

	</div>

	<div class="fh-bleach__actions">
		<button type="button" class="fh-bleach__btn" data-fh="action_print">Print Schedule</button>
		<button type="button" class="fh-bleach__btn" data-fh="action_ics">Save to Calendar (.ics)</button>
	</div>

	<div class="fh-bleach__print-only" data-fh="print_payload">
		<h2>Bleach-Out Schedule</h2>
		<ul class="fh-bleach__print-checks">
			<li>&#9744; Empty tank, remove all livestock and live rock</li>
			<li>&#9744; Add <span data-fh="print_bleach">&mdash;</span> ml bleach (<span data-fh="print_conc">&mdash;</span>%)</li>
			<li>&#9744; Wait <span data-fh="print_contact">&mdash;</span> minutes (start: ____________)</li>
			<li>&#9744; Add <span data-fh="print_neut">&mdash;</span></li>
			<li>&#9744; Wait 5 minutes</li>
			<li>&#9744; Rinse 1 (____) &nbsp; Rinse 2 (____) &nbsp; Rinse 3 (____)</li>
			<li>&#9744; Test final water with chlorine strip &mdash; confirm 0 ppm before reuse</li>
		</ul>
	</div>

</section>
