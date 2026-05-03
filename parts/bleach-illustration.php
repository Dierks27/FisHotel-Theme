<?php
/**
 * Bleach Calculator — hero illustration.
 *
 * Inline SVG bottle + tank, themable via CSS variables. The fill rects'
 * `y` and `height` attributes are tweened by JS (fhBleach.updateIllustration)
 * with a CSS transition so preset changes feel hand-poured.
 *
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="fh-bleach__illustration" aria-hidden="true">
	<svg class="fh-bleach__svg" viewBox="0 0 600 280" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Bleach bottle pouring into an empty tank">

		<defs>
			<pattern id="fh-bleach-hatch" patternUnits="userSpaceOnUse" width="6" height="6" patternTransform="rotate(45)">
				<line x1="0" y1="0" x2="0" y2="6" stroke="currentColor" stroke-width="0.6" opacity="0.55"/>
			</pattern>
			<pattern id="fh-bleach-hatch-tank" patternUnits="userSpaceOnUse" width="8" height="8" patternTransform="rotate(45)">
				<line x1="0" y1="0" x2="0" y2="8" stroke="currentColor" stroke-width="0.6" opacity="0.45"/>
			</pattern>
		</defs>

		<g class="fh-bleach__bottle" transform="translate(40,40)">
			<rect x="46" y="0" width="48" height="22" rx="3" fill="none" stroke="currentColor" stroke-width="1.6"/>
			<rect x="38" y="22" width="64" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.6"/>
			<path d="M30 36 Q24 50 24 70 L24 188 Q24 200 36 200 L104 200 Q116 200 116 188 L116 70 Q116 50 110 36 Z"
				  fill="none" stroke="currentColor" stroke-width="1.8"/>

			<rect class="fh-bleach__bottle-fill" x="28" y="200" width="84" height="0"
				  fill="url(#fh-bleach-hatch)" stroke="none"/>

			<g class="fh-bleach__ticks" stroke="currentColor" stroke-width="1" fill="none">
				<line x1="28" y1="60"  x2="40" y2="60"/>
				<line x1="28" y1="90"  x2="36" y2="90"/>
				<line x1="28" y1="120" x2="40" y2="120"/>
				<line x1="28" y1="150" x2="36" y2="150"/>
				<line x1="28" y1="180" x2="40" y2="180"/>
			</g>

			<rect x="42" y="100" width="56" height="40" rx="2" fill="var(--fh-bleach-cream, #EDE0C0)" stroke="currentColor" stroke-width="1"/>
			<text x="70" y="118" text-anchor="middle" font-family="Playfair Display, Georgia, serif"
				  font-size="11" font-style="italic" fill="currentColor">BLEACH</text>
			<text x="70" y="132" text-anchor="middle" font-family="Playfair Display, Georgia, serif"
				  font-size="7" letter-spacing="1.5" fill="currentColor">— APOTHECARY —</text>
		</g>

		<path class="fh-bleach__pour" d="M180 70 Q230 90 260 130 Q290 170 320 200"
			  fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity="0.7"/>

		<g class="fh-bleach__tank" transform="translate(330,40)">
			<rect x="0" y="20" width="220" height="160" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/>
			<line x1="0" y1="30" x2="220" y2="30" stroke="currentColor" stroke-width="0.8" opacity="0.5"/>

			<rect class="fh-bleach__tank-fill" x="2" y="180" width="216" height="0"
				  fill="url(#fh-bleach-hatch-tank)" stroke="none"/>

			<g class="fh-bleach__fish" transform="translate(110,100)" stroke="currentColor" stroke-width="1.4" fill="none">
				<path d="M-22 0 Q-10 -10 6 -10 Q22 -10 28 0 Q22 10 6 10 Q-10 10 -22 0 Z"/>
				<path d="M28 0 L40 -8 L40 8 Z"/>
				<circle cx="0" cy="-2" r="1.2" fill="currentColor"/>
				<line x1="-30" y1="-20" x2="50" y2="20" stroke="var(--fh-bleach-danger, #d46a5a)" stroke-width="2.5"/>
				<line x1="50"  y1="-20" x2="-30" y2="20" stroke="var(--fh-bleach-danger, #d46a5a)" stroke-width="2.5"/>
			</g>

			<rect x="20" y="180" width="180" height="14" fill="none" stroke="currentColor" stroke-width="1.4"/>
			<line x1="40"  y1="194" x2="32"  y2="220" stroke="currentColor" stroke-width="1.4"/>
			<line x1="180" y1="194" x2="188" y2="220" stroke="currentColor" stroke-width="1.4"/>
			<line x1="20"  y1="220" x2="200" y2="220" stroke="currentColor" stroke-width="1.4"/>

			<text x="110" y="14" text-anchor="middle" font-family="Playfair Display, Georgia, serif"
				  font-size="9" font-style="italic" letter-spacing="2" fill="currentColor" opacity="0.7">NO LIVESTOCK</text>
		</g>

	</svg>
</div>
