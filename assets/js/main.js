/**
 * FisHotel Theme — main.js
 * @package FisHotel
 * @version 1.0.0
 */
(function($) {
    'use strict';

    // Variation button selectors — sync visual buttons with WooCommerce hidden selects
    function initVariationButtons() {
        $(document).on('click', '.fh-var-btn:not(.unavailable)', function() {
            var $btn = $(this);
            var $group = $btn.closest('.fh-var-buttons');
            var attribute = $group.data('attribute');
            var value = $btn.data('value');
            $group.find('.fh-var-btn').removeClass('selected');
            $btn.addClass('selected');
            $('[name="' + attribute + '"]').val(value).trigger('change');
            var $label = $btn.closest('.fh-variation-group').find('.fh-variation-selected');
            if ($label.length) $label.text('— ' + value + ' selected');
        });
    }

    // Gallery thumb switcher
    function initGallery() {
        $(document).on('click', '.fh-gallery__thumb', function() {
            var imgSrc = $(this).find('img').attr('src');
            $('.fh-gallery__thumb').removeClass('active');
            $(this).addClass('active');
            if (imgSrc) $('.fh-gallery__main img').attr('src', imgSrc);
        });
    }

    // Mobile nav — toggle drawer and manage ARIA
    function initMobileNav() {
        $('.site-header__toggle').on('click', function() {
            var $drawer = $('#mobile-nav');
            var isOpen = $drawer.hasClass('is-open');
            $drawer.toggleClass('is-open');
            $(this).attr('aria-expanded', !isOpen);
            $drawer.attr('aria-hidden', isOpen);
        });
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.site-header').length) {
                $('#mobile-nav').removeClass('is-open');
                $('.site-header__toggle').attr('aria-expanded', 'false');
                $('#mobile-nav').attr('aria-hidden', 'true');
            }
        });
    }

    // Nav dropdowns — desktop is pure CSS (:hover / :focus-within); JS only
    // handles the mobile drawer accordion and the ESC-to-close affordance.
    function initNavDropdowns() {
        // Mobile: tap a parent <a> to toggle the accordion instead of navigating.
        $(document).on('click', '.site-header__drawer-menu .menu-item-has-children > a', function(e) {
            e.preventDefault();
            $(this).parent('.menu-item-has-children').toggleClass('is-open');
        });

        // ESC closes any open dropdown — desktop (blur the focused descendant
        // so :focus-within releases) and mobile (collapse all open accordions).
        $(document).on('keydown', function(e) {
            if (e.key !== 'Escape' && e.keyCode !== 27) return;
            var active = document.activeElement;
            if (active && $(active).closest('.site-header__menu .menu-item-has-children').length) {
                active.blur();
            }
            $('.site-header__drawer-menu .menu-item-has-children.is-open').removeClass('is-open');
        });
    }

    // Qty buttons
    function initQty() {
        $(document).on('click', '.fh-qty__up', function() {
            var $n = $(this).closest('.fh-qty').find('.fh-qty__num');
            var v = parseInt($n.text(), 10) || 1;
            $n.text(v + 1);
            $(this).closest('form').find('input.qty').val(v + 1).trigger('change');
        });
        $(document).on('click', '.fh-qty__down', function() {
            var $n = $(this).closest('.fh-qty').find('.fh-qty__num');
            var v = parseInt($n.text(), 10) || 1;
            if (v > 1) { $n.text(v - 1); $(this).closest('form').find('input.qty').val(v - 1).trigger('change'); }
        });
    }

    // Header scroll-shrink
    function initHeaderScroll() {
        var $header = $('#masthead');
        var threshold = 60;
        $(window).on('scroll', function() {
            if (window.scrollY > threshold) {
                $header.addClass('site-header--compact');
            } else {
                $header.removeClass('site-header--compact');
            }
        });
    }

    // Arrival Panel countdown — minute precision is calm; no seconds.
    // Reads release timestamp (UTC) from data-fh-countdown on the panel
    // and updates child spans tagged data-d / data-h / data-m. Once the
    // release passes the panel reloads to fall through to the live
    // purchase UI on next render.
    function initArrivalCountdown() {
        var els = document.querySelectorAll('.fh-arrival-panel[data-fh-countdown]');
        if (!els.length) return;
        function tick() {
            var now = Math.floor(Date.now() / 1000);
            els.forEach(function (el) {
                var ts = parseInt(el.getAttribute('data-fh-countdown'), 10) || 0;
                var diff = ts - now;
                if (diff <= 0) {
                    // Reload once when the release moment passes so the
                    // server-rendered panel switches to the purchase UI.
                    if (!el.dataset.expired) {
                        el.dataset.expired = '1';
                        window.location.reload();
                    }
                    return;
                }
                var d = Math.floor(diff / 86400);
                var h = Math.floor((diff % 86400) / 3600);
                var m = Math.floor((diff % 3600) / 60);
                var dEl = el.querySelector('[data-d]'); if (dEl) dEl.textContent = d;
                var hEl = el.querySelector('[data-h]'); if (hEl) hEl.textContent = h;
                var mEl = el.querySelector('[data-m]'); if (mEl) mEl.textContent = m;
            });
        }
        tick();
        setInterval(tick, 30000);
    }

    // Homepage testimonial rotator — vanilla cross-fade. Skips entirely on
    // single-quote sections (no [data-fh-testimonials="1"] flag).
    function initTestimonialRotator() {
        var section = document.querySelector('.fh-quote-section[data-fh-testimonials="1"]');
        if (!section) return;
        var slides = section.querySelectorAll('.fh-quote-slide');
        var dots   = section.querySelectorAll('.fh-quote-dot');
        if (slides.length < 2) return;

        var idx = 0;
        var timer = null;
        var INTERVAL = 8000;

        function show(next) {
            if (next === idx) return;
            slides[idx].classList.remove('is-active');
            slides[idx].setAttribute('aria-hidden', 'true');
            dots[idx].classList.remove('is-active');
            dots[idx].setAttribute('aria-selected', 'false');
            idx = (next + slides.length) % slides.length;
            slides[idx].classList.add('is-active');
            slides[idx].setAttribute('aria-hidden', 'false');
            dots[idx].classList.add('is-active');
            dots[idx].setAttribute('aria-selected', 'true');
        }

        function start() { stop(); timer = setInterval(function () { show(idx + 1); }, INTERVAL); }
        function stop()  { if (timer) { clearInterval(timer); timer = null; } }

        // Pause on hover anywhere within the section.
        section.addEventListener('mouseenter', stop);
        section.addEventListener('mouseleave', start);
        // Pause when the tab is hidden so we don't burn cycles.
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) stop(); else start();
        });

        for (var i = 0; i < dots.length; i++) {
            (function (i) {
                dots[i].addEventListener('click', function () {
                    show(i);
                    start(); // restart timer on manual jump
                });
            })(i);
        }

        start();
    }

    $(document).ready(function() {
        initVariationButtons();
        initGallery();
        initMobileNav();
        initNavDropdowns();
        initQty();
        initHeaderScroll();
        initTestimonialRotator();
        initArrivalCountdown();
    });

})(jQuery);
