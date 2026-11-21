/*
 * FisHotel Medication Store — frontend
 *
 * Disclaimer modal gating. Two modes, read from the modal's data-mode:
 *
 *   gate     — Medications category archives and medication PDPs. Shown
 *              automatically on load unless the accepted cookie is set.
 *              Locked: clicking the backdrop or pressing Escape does NOT
 *              dismiss it. Only "I Agree" (sets cookie) or "Decline"
 *              (redirects to home) exit.
 *   readonly — Checkout "Read the terms" link (C2). Opened on demand,
 *              never automatically. Close / backdrop / Escape just close
 *              it; no cookie is set and nothing redirects.
 *
 * The decision helpers (modalPlan / modalAction) are pure and exported
 * for tests/med-terms.test.js when a CommonJS `module` exists.
 */
( function () {
	'use strict';

	/**
	 * What to do with the modal on load.
	 *
	 * @param {Object}  cfg        fishotelMedData (isArchive, isMedPdp, isCheckout).
	 * @param {string}  mode       The modal's data-mode ('gate' | 'readonly').
	 * @param {boolean} hasCookie  Whether the accepted cookie is '1'.
	 * @return {{ mode: string, autoShow: boolean, locked: boolean }}
	 */
	function modalPlan( cfg, mode, hasCookie ) {
		cfg  = cfg || {};
		mode = ( mode === 'readonly' ) ? 'readonly' : 'gate';
		if ( mode === 'readonly' ) {
			return { mode: 'readonly', autoShow: false, locked: false };
		}
		var gated = !! ( cfg.isArchive || cfg.isMedPdp );
		return { mode: 'gate', autoShow: gated && ! hasCookie, locked: true };
	}

	/**
	 * Effect of a user action on the modal.
	 *
	 * @param {string} mode   'gate' | 'readonly'.
	 * @param {string} action 'agree' | 'decline' | 'close' | 'escape' | 'backdrop'.
	 * @return {{ close: boolean, setCookie: boolean, redirect: boolean }}
	 */
	function modalAction( mode, action ) {
		if ( mode === 'readonly' ) {
			// Every exit path just closes: never a cookie, never a redirect.
			return { close: true, setCookie: false, redirect: false };
		}
		if ( action === 'agree' ) {
			return { close: true, setCookie: true, redirect: false };
		}
		if ( action === 'decline' ) {
			return { close: false, setCookie: false, redirect: true };
		}
		// Locked gate: Escape / backdrop / close do nothing.
		return { close: false, setCookie: false, redirect: false };
	}

	if ( typeof module === 'object' && module && module.exports ) {
		module.exports = { modalPlan: modalPlan, modalAction: modalAction };
	}

	if ( typeof window === 'undefined' || typeof document === 'undefined' ) return;
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

	function lockScroll() { document.body.classList.add( 'fishotel-med-modal-open' ); }
	function unlockScroll() { document.body.classList.remove( 'fishotel-med-modal-open' ); }

	// While the locked modal is open, the rest of the page (its sibling
	// elements: header, main, footer, admin bar) is made inert so Tab and
	// screen-reader cursors can't wander behind the backdrop. aria-hidden
	// is the fallback where `inert` is unsupported. Restored on close.
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

	var lastFocus = null;

	function openModal( modal ) {
		if ( ! modal.hidden ) return;
		lastFocus = document.activeElement;
		modal.hidden = false;
		lockScroll();
		setPageInert( modal );
		var firstBtn = modal.querySelector( '.fishotel-med-modal__btn--agree' );
		if ( firstBtn ) { firstBtn.focus(); }
	}

	function closeModal( modal ) {
		modal.hidden = true;
		unlockScroll();
		clearPageInert();
		if ( lastFocus && typeof lastFocus.focus === 'function' ) {
			try { lastFocus.focus(); } catch ( e ) { /* detached */ }
		}
		lastFocus = null;
	}

	function apply( modal, mode, action ) {
		var effect = modalAction( mode, action );
		if ( effect.setCookie ) {
			setCookie( cfg.cookieName, '1', cfg.cookieDays || 30 );
		}
		if ( effect.close ) {
			closeModal( modal );
		}
		if ( effect.redirect ) {
			// No cookie set — coming back triggers the modal again.
			window.location.href = cfg.homeUrl || '/';
		}
	}

	function init() {
		var modal = document.getElementById( 'fishotel-med-modal' );
		if ( ! modal ) return;

		var mode = modal.getAttribute( 'data-mode' ) === 'readonly' ? 'readonly' : 'gate';
		var plan = modalPlan( cfg, mode, getCookie( cfg.cookieName ) === '1' );

		var agree    = modal.querySelector( '.fishotel-med-modal__btn--agree' );
		var decline  = modal.querySelector( '.fishotel-med-modal__btn--decline' );
		var backdrop = modal.querySelector( '.fishotel-med-modal__backdrop' );

		// Focus trap: Tab / Shift+Tab cycle between the buttons only.
		modal.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' || e.key === 'Esc' ) {
				if ( ! plan.locked ) { e.preventDefault(); apply( modal, mode, 'escape' ); }
				return;
			}
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
			agree.addEventListener( 'click', function () { apply( modal, mode, 'agree' ); } );
		}
		if ( decline ) {
			decline.addEventListener( 'click', function () { apply( modal, mode, 'decline' ); } );
		}
		if ( backdrop && ! plan.locked ) {
			backdrop.addEventListener( 'click', function () { apply( modal, mode, 'backdrop' ); } );
		}

		// "Read the terms" links (checkout checkbox, My Account) open the
		// read-only modal. Delegated: the checkout payment box is re-rendered
		// by WooCommerce on every address change.
		document.addEventListener( 'click', function ( e ) {
			var t = e.target;
			while ( t && t !== document ) {
				if ( t.getAttribute && t.getAttribute( 'data-fishotel-med-terms-open' ) ) {
					e.preventDefault();
					openModal( modal );
					return;
				}
				t = t.parentNode;
			}
		} );

		if ( plan.autoShow ) {
			openModal( modal );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
