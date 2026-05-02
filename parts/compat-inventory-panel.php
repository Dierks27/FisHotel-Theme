<?php
/**
 * Compatibility Guide — live inventory panel.
 * Fetches in-stock products in categories that are 'C' against every fish
 * currently in My Tank. JS module InventoryPanel handles the actual fetch
 * and rendering. Replaces the prior compat-sample-tanks.php slot.
 *
 * @package FisHotel
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="fh-compat-inventory" data-fh-inventory-panel aria-labelledby="fh-compat-inventory-title">
	<header class="fh-compat-inventory__head">
		<h2 id="fh-compat-inventory-title" class="fh-compat-inventory__title">What You Can Add Right Now</h2>
		<p class="fh-compat-inventory__sub">In-stock fish compatible with your tank.</p>
		<p class="fh-compat-inventory__hint"><em>Tap a fish to view it · Tap + Add to put it on your Considering list.</em></p>
	</header>
	<div class="fh-compat-inventory__grid" data-fh-inventory-grid aria-live="polite"></div>
	<p class="fh-compat-inventory__empty" data-fh-inventory-empty>
		Add a fish to your tank above to see what's available.
	</p>
</section>
