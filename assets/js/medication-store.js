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

	// While the locked modal is open, the rest of the page (its sibling
	// elements: header, main, footer, admin bar) is made inert so Tab and
	// screen-reader cursors can't wander behind the backdrop. aria-hidden
	// is the fallback where `inert` is unsupported. Restored on Agree.
	var inertRecords = [];
	function setPageInert( modal ) {
		var parent = modal.parentNode;
		if ( ! parent ) return;
		var supportsInert = ( 'inert' in HTMLElement.prototype );
		var kids = parent.children;
		for ( var i = 0; i < kids.length; i++ ) {
			var el  = kids[ i ];
			var tag = el.tagName;
			if ( el === modal || tag === 'SCRIPT' || tag === 'STYLE' || tag === 'LINK' || tag === 'NOSCRIPT' || tag === 'TEMPLATE' ) continue;
			var rec = { el: el, setInert: false, setAriaHidden: false };
			if ( supportsInert ) {
				if ( ! el.hasAttribute( 'inert' ) ) { el.setAttribute( 'inert', '' ); rec.setInert = true; }
			} else if ( ! el.hasAttribute( 'aria-hidden' ) ) {
				el.setAttribute( 'aria-hidden', 'true' );
				rec.setAriaHidden = true;
			}
			inertRecords.push( rec );
		}
	}
	function clearPageInert() {
		for ( var i = 0; i < inertRecords.length; i++ ) {
			var rec = inertRecords[ i ];
			if ( rec.setInert )      { rec.el.removeAttribute( 'inert' ); }
			if ( rec.setAriaHidden ) { rec.el.removeAttribute( 'aria-hidden' ); }
		}
		inertRecords = [];
	}

	function init() {
		var modal = document.getElementById( 'fishotel-med-modal' );
		if ( ! modal ) return;
		if ( ! shouldShowModal() ) return;

		modal.hidden = false;
		lockScroll();
		setPageInert( modal );
		var card = modal.querySelector( '.fishotel-med-modal__card' );
		if ( card ) {
			// Move focus inside for keyboard users.
			var firstBtn = card.querySelector( '.fishotel-med-modal__btn--agree' );
			if ( firstBtn ) { firstBtn.focus(); }
		}

		// Backdrop click and Escape are deliberately NOT bound — modal is locked.

		var agree = modal.querySelector( '.fishotel-med-modal__btn--agree' );
		var decline = modal.querySelector( '.fishotel-med-modal__btn--decline' );

		// Focus trap: Tab / Shift+Tab cycle between the two buttons only.
		modal.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Tab' ) return;
			var f = [ agree, decline ].filter( Boolean );
			if ( ! f.length ) { e.preventDefault(); return; }
			var first = f[0], last = f[ f.length - 1 ];
			var active = document.activeElement;
			if ( e.shiftKey && ( active === first || ! modal.contains( active ) ) ) {
				e.preventDefault(); last.focus();
			} else if ( ! e.shiftKey && ( active === last || ! modal.contains( active ) ) ) {
				e.preventDefault(); first.focus();
			}
		} );

		if ( agree ) {
			agree.addEventListener( 'click', function () {
				setCookie( cfg.cookieName, '1', cfg.cookieDays || 30 );
				modal.hidden = true;
				unlockScroll();
				clearPageInert();
			} );
		}

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
