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

    // Mobile nav
    function initMobileNav() {
        $('.nav-toggle').on('click', function() {
            $('body').toggleClass('nav-mobile-open');
        });
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.site-header').length) {
                $('body').removeClass('nav-mobile-open');
            }
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

    $(document).ready(function() {
        initVariationButtons();
        initGallery();
        initMobileNav();
        initQty();
    });

})(jQuery);
