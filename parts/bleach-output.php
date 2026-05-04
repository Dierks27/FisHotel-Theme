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
				<svg class="fh-bleach__cup-defs" width="0" height="0" aria-hidden="true">
					<defs>
						<pattern id="fh-bleach-cup-hatch" patternUnits="userSpaceOnUse" width="5" height="5" patternTransform="rotate(45)">
							<line x1="0" y1="0" x2="0" y2="5" stroke="currentColor" stroke-width="0.6" opacity="0.55"/>
						</pattern>
					</defs>
				</svg>

				<div class="fh-bleach__cup-row" data-fh="cup_row">
					<!-- cup icons rendered by JS based on cups_needed -->
				</div>

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
				<div class="fh-bleach__timer-display" data-fh="timer_display" role="timer" aria-live="off">30:00</div>
				<div class="fh-bleach__timer-controls">
					<button type="button" class="fh-bleach__timer-btn fh-bleach__timer-btn--primary" data-fh-timer="begin">Begin Soak</button>
					<button type="button" class="fh-bleach__timer-btn" data-fh-timer="pause" hidden>Pause</button>
					<button type="button" class="fh-bleach__timer-btn fh-bleach__timer-btn--primary" data-fh-timer="resume" hidden>Resume</button>
					<button type="button" class="fh-bleach__timer-btn fh-bleach__timer-btn--abort" data-fh-timer="reset" hidden>Reset</button>
					<button type="button" class="fh-bleach__timer-btn fh-bleach__timer-btn--primary" data-fh-timer="restart" hidden>Start New Cycle</button>
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
