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

	function init() {
		initNavAccordion();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
