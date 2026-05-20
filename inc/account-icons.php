<?php
/**
 * FisHotel — My Account sidebar / quick-action icons.
 *
 * Nine inline-SVG icons in a single retro-deco line-art language. Stroke is
 * `currentColor` so each icon inherits its parent nav-item color (essential
 * for the hover / active gold transitions). Size is controlled via CSS on the
 * <svg> element, so these functions take no size argument — only an optional
 * extra class.
 *
 * @package FisHotel
 */

defined( 'ABSPATH' ) || exit;

/**
 * Open an <svg> wrapper with the shared deco line-art attributes.
 *
 * @param string $class Extra class appended to the base `fh-acct-icon`.
 * @return string
 */
function fishotel_icon_open( $class = '' ) {
	$cls = trim( 'fh-acct-icon ' . $class );
	return '<svg class="' . esc_attr( $cls ) . '" viewBox="0 0 24 24" fill="none" '
		. 'stroke="currentColor" stroke-width="1.5" stroke-linecap="round" '
		. 'stroke-linejoin="round" aria-hidden="true" focusable="false">';
}

/** 1. The Lobby — art-deco hotel facade. */
function fishotel_icon_lobby( $class = '' ) {
	return fishotel_icon_open( $class )
		. '<path d="M5 21V6.5l7-3.5 7 3.5V21" />'
		. '<path d="M3.5 21h17" />'
		. '<path d="M9 9.5h2M13 9.5h2M9 12.5h2M13 12.5h2" />'
		. '<path d="M10 21v-3.5h4V21" />'
		. '<path d="M12 3V1.5" />'
		. '</svg>';
}

/** 2. Past Stays — vintage hard-shell suitcase. */
function fishotel_icon_past_stays( $class = '' ) {
	return fishotel_icon_open( $class )
		. '<rect x="3.5" y="7.5" width="17" height="12" rx="1.5" />'
		. '<path d="M9 7.5V5.5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5.5v2" />'
		. '<path d="M8 10.5h1.5M14.5 10.5H16" />'
		. '<path d="M3.5 15.5h17" />'
		. '</svg>';
}

/** 3. Special Requests — concierge call bell. */
function fishotel_icon_special_requests( $class = '' ) {
	return fishotel_icon_open( $class )
		. '<path d="M4.5 16.5h15" />'
		. '<path d="M6 16.5a6 6 0 0 1 12 0" />'
		. '<path d="M12 7.5V6" />'
		. '<circle cx="12" cy="5" r="1" fill="currentColor" stroke="none" />'
		. '<path d="M5 19h14" />'
		. '</svg>';
}

/** 4. Guest Profile — open guest book. */
function fishotel_icon_guest_profile( $class = '' ) {
	return fishotel_icon_open( $class )
		. '<path d="M12 6.5C9.5 5 6.5 5 4 6v12c2.5-1 5.5-1 8 .5 2.5-1.5 5.5-1.5 8-.5V6c-2.5-1-5.5-1-8 .5Z" />'
		. '<path d="M12 6.5V19" />'
		. '<path d="M6.5 10h3M14.5 10h3" />'
		. '</svg>';
}

/** 5. Forwarding Addresses — vintage envelope w/ postmark. */
function fishotel_icon_forwarding_addresses( $class = '' ) {
	return fishotel_icon_open( $class )
		. '<rect x="3.5" y="6.5" width="17" height="12" rx="1.5" />'
		. '<path d="M4 7.5l8 5.5 8-5.5" />'
		. '<rect x="15.5" y="8" width="3.5" height="3.5" rx="0.5" fill="currentColor" stroke="none" opacity="0.5" />'
		. '</svg>';
}

/** 6. Billing — fountain pen over a ledger. */
function fishotel_icon_billing( $class = '' ) {
	return fishotel_icon_open( $class )
		. '<rect x="3.5" y="13" width="11" height="7" rx="1" />'
		. '<path d="M6 16h6" />'
		. '<path d="M14 11.5l5.5-5.5a1.4 1.4 0 0 1 2 2L16 13.5l-2.7.7.7-2.7Z" />'
		. '<path d="M14.3 11.8l1.9 1.9" />'
		. '</svg>';
}

/** 7. Gift Cards — wrapped gift box. */
function fishotel_icon_gift_cards( $class = '' ) {
	return fishotel_icon_open( $class )
		. '<rect x="4" y="9.5" width="16" height="10.5" rx="1.5" />'
		. '<path d="M4 13h16" />'
		. '<path d="M12 9.5V20" />'
		. '<path d="M12 9.5C12 9.5 10 9.5 8.8 8.3 8 7.5 8 6.3 8.8 5.8 9.7 5.2 12 9.5 12 9.5Z" />'
		. '<path d="M12 9.5C12 9.5 14 9.5 15.2 8.3 16 7.5 16 6.3 15.2 5.8 14.3 5.2 12 9.5 12 9.5Z" />'
		. '</svg>';
}

/** 8. House Account — bound leather ledger, spine view. */
function fishotel_icon_house_account( $class = '' ) {
	return fishotel_icon_open( $class )
		. '<rect x="6.5" y="3.5" width="11" height="17" rx="1.5" />'
		. '<path d="M6.5 8h11M6.5 12h11" />'
		. '<path d="M11 20.5v2.5l1.5-1.2 1.5 1.2v-2.5" fill="currentColor" stroke="none" opacity="0.6" />'
		. '</svg>';
}

/** 9. Check Out — ornate vintage skeleton key (metaphor-clinching). */
function fishotel_icon_check_out( $class = '' ) {
	return fishotel_icon_open( $class )
		. '<circle cx="7.5" cy="7.5" r="3.5" />'
		. '<circle cx="7.5" cy="7.5" r="1" fill="currentColor" stroke="none" />'
		. '<path d="M5.5 4.2 4 2.7M11 5.5 12.5 4" />'
		. '<path d="M10 10l8 8" />'
		. '<path d="M18 18l1.5 1.5M16 16l1.5 1.5M14.2 14.2l1.5 1.5" />'
		. '</svg>';
}
