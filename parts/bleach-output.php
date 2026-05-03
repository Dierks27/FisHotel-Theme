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

		<article class="fh-bleach__step">
			<div class="fh-bleach__step-num">1</div>
			<h3 class="fh-bleach__step-name">Bleach Dose</h3>
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
				<div class="fh-bleach__timer-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
					<div class="fh-bleach__timer-fill" data-fh="timer_fill"></div>
					<span class="fh-bleach__timer-readout" data-fh="timer_readout">0:00 / 30:00</span>
				</div>
				<div class="fh-bleach__timer-ctrls">
					<button type="button" class="fh-bleach__btn" data-fh="timer_start">Start Timer</button>
					<button type="button" class="fh-bleach__btn fh-bleach__btn--ghost" data-fh="timer_reset">Reset</button>
				</div>
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

	<figure class="fh-bleach__timeline">
		<figcaption class="fh-bleach__timeline-title">Chlorine ppm over time</figcaption>
		<svg class="fh-bleach__timeline-svg" viewBox="0 0 600 180" preserveAspectRatio="none" aria-hidden="true">
			<g class="fh-bleach__timeline-grid" stroke="currentColor" stroke-width="0.5" opacity="0.18">
				<line x1="40"  y1="20"  x2="40"  y2="150"/>
				<line x1="40"  y1="150" x2="580" y2="150"/>
				<line x1="40"  y1="50"  x2="580" y2="50"  stroke-dasharray="3 4"/>
				<line x1="40"  y1="100" x2="580" y2="100" stroke-dasharray="3 4"/>
			</g>

			<rect class="fh-bleach__timeline-danger" data-fh="tl_danger" x="40" y="50" width="200" height="100" fill="var(--fh-bleach-amber)" opacity="0.10"/>
			<rect class="fh-bleach__timeline-safe"   data-fh="tl_safe"   x="240" y="20" width="340" height="130" fill="var(--fh-bleach-gold)" opacity="0.06"/>

			<polyline class="fh-bleach__timeline-line" data-fh="tl_line"
				points="40,150 40,50 240,50 240,150 580,150"
				fill="none" stroke="var(--fh-bleach-blue)" stroke-width="2" stroke-linejoin="round"/>

			<g class="fh-bleach__timeline-labels" font-family="Playfair Display, Georgia, serif" font-size="10" fill="currentColor" opacity="0.65">
				<text x="40"  y="170" text-anchor="middle">0</text>
				<text x="240" y="170" text-anchor="middle" data-fh="tl_label_neut">30m</text>
				<text x="580" y="170" text-anchor="middle" data-fh="tl_label_end">45m</text>
				<text x="32"  y="54"  text-anchor="end"  data-fh="tl_label_target">200</text>
				<text x="32"  y="154" text-anchor="end">0</text>
				<text x="32"  y="14"  text-anchor="end" font-style="italic">ppm</text>
			</g>
		</svg>
	</figure>

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
