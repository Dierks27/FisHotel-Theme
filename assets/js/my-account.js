/**
 * FisHotel — My Account interactions.
 *
 * Mobile: the Concierge directory collapses behind a "CONCIERGE DIRECTORY"
 * toggle bar. The bar is hidden on desktop via CSS; the nav is always open
 * there. We only manage the aria-expanded + is-open state here.
 */
(function () {
	'use strict';

	function initNavAccordion() {
		var nav = document.querySelector('.fh-account-nav');
		if (!nav) {
			return;
		}
		var toggle = nav.querySelector('.fh-account-nav__mobile-toggle');
		if (!toggle) {
			return;
		}

		toggle.addEventListener('click', function () {
			var open = nav.classList.toggle('is-open');
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	}

	/**
	 * Copy a gift card code to the clipboard. Delegated so it also covers the
	 * Unclaimed table and any rows rendered after load. Falls back silently
	 * when the Clipboard API is unavailable (e.g. non-secure contexts).
	 */
	function initGiftCardCopy() {
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.fh-giftcard-copy');
			if (!btn || !navigator.clipboard) {
				return;
			}
			e.preventDefault();
			var code = btn.getAttribute('data-copy');
			if (!code) {
				return;
			}
			navigator.clipboard.writeText(code).then(function () {
				var original = btn.textContent;
				btn.textContent = 'Copied';
				btn.classList.add('is-copied');
				setTimeout(function () {
					btn.textContent = original;
					btn.classList.remove('is-copied');
				}, 1500);
			});
		});
	}

	function init() {
		initNavAccordion();
		initGiftCardCopy();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
