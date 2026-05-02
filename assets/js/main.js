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

    $(document).ready(function() {
        initVariationButtons();
        initGallery();
        initMobileNav();
        initNavDropdowns();
        initQty();
        initHeaderScroll();
    });

})(jQuery);
