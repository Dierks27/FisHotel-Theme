/*
 * FisHotel Medication Store — frontend
 *
 * Disclaimer modal gating. Runs on:
 *   - Medications category archives (auto-show on load).
 *   - Medication PDPs (auto-show on load — strictest reading of the
 *     spec: the modal should also fire on direct PDP entry).
 * Modal is locked: clicking the backdrop or pressing Escape does NOT
 * dismiss it. Only "I Agree" (sets cookie) or "Decline" (redirects
 * to home) exit. Cookie name + days come from fishotelMedData.
 */
( function () {
	'use strict';

	if ( typeof window.fishotelMedData === 'undefined' ) return;
	var cfg = window.fishotelMedData;

	function getCookie( name ) {
		var match = document.cookie.match(
			new RegExp( '(^| )' + name.replace( /[-\/\\^$*+?.()|[\]{}]/g, '\\$&' ) + '=([^;]+)' )
		);
		return match ? decodeURIComponent( match[2] ) : '';
	}

	function setCookie( name, value, days ) {
		var expires = '';
		if ( days ) {
			var d = new Date();
			d.setTime( d.getTime() + days * 864e5 );
			expires = '; expires=' + d.toUTCString();
		}
		var secure = ( location.protocol === 'https:' ) ? '; Secure' : '';
		document.cookie = name + '=' + encodeURIComponent( value ) + expires + '; path=/; SameSite=Lax' + secure;
	}

	function shouldShowModal() {
		if ( getCookie( cfg.cookieName ) === '1' ) return false;
		return !! ( cfg.isArchive || cfg.isMedPdp );
	}

	function lockScroll() { document.body.classList.add( 'fishotel-med-modal-open' ); }
	function unlockScroll() { document.body.classList.remove( 'fishotel-med-modal-open' ); }

	function init() {
		var modal = document.getElementById( 'fishotel-med-modal' );
		if ( ! modal ) return;
		if ( ! shouldShowModal() ) return;

		modal.hidden = false;
		lockScroll();
		var card = modal.querySelector( '.fishotel-med-modal__card' );
		if ( card ) {
			// Move focus inside for keyboard users.
			var firstBtn = card.querySelector( '.fishotel-med-modal__btn--agree' );
			if ( firstBtn ) { firstBtn.focus(); }
		}

		// Backdrop click and Escape are deliberately NOT bound — modal is locked.

		var agree = modal.querySelector( '.fishotel-med-modal__btn--agree' );
		if ( agree ) {
			agree.addEventListener( 'click', function () {
				setCookie( cfg.cookieName, '1', cfg.cookieDays || 30 );
				modal.hidden = true;
				unlockScroll();
			} );
		}

		var decline = modal.querySelector( '.fishotel-med-modal__btn--decline' );
		if ( decline ) {
			decline.addEventListener( 'click', function () {
				// No cookie set — coming back triggers the modal again.
				window.location.href = cfg.homeUrl || '/';
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
