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

    // PHP `fishotel_stock_badge_label()` mirror — keep thresholds + copy in sync.
    function computeStockBadge(inStock, maxQty) {
        var q = parseInt(maxQty, 10);
        if (!inStock || q === 0) return { state: 'soldout', text: 'Sold Out' };
        if (!isNaN(q) && q > 0) {
            if (q === 1) return { state: 'last', text: 'Last one — Just 1 left' };
            if (q < 5)   return { state: 'low',  text: 'Only ' + q + ' left in stock' };
        }
        return { state: 'in-stock', text: 'In Stock — Ready to Ship' };
    }

    function applyStockState($panel, badge, $cta) {
        $panel.find('.fh-stock-badge')
            .attr('data-state', badge.state)
            .find('.fh-stock-badge__text').text(badge.text);
        var soldout = badge.state === 'soldout';
        var addLabel    = $cta.attr('data-fh-label-add')    || 'Add to Cart';
        var notifyLabel = $cta.attr('data-fh-label-notify') || 'Notify Me';
        $cta.toggleClass('fh-btn--notify', soldout)
            .text(soldout ? notifyLabel : addLabel)
            .prop('disabled', soldout);
    }

    // WC variation events are bound at IIFE time (NOT inside $(document).ready)
    // so we catch the first `found_variation` WC fires when the form initializes
    // with admin-set default attributes. If we deferred to ready, that initial
    // event could fire from WC's own ready callback before ours and the headline
    // price would never update to the default variation's specific price.
    $(document).on('found_variation.wc-variation-form', '.fh-purchase__form', function(event, variation) {
        var $form   = $(this);
        var $panel  = $form.closest('.fh-purchase');
        var $cta    = $form.find('.single_add_to_cart_button');
        var isMulti = $form.attr('data-fh-multi-variations') === '1';
        // Eyebrow: multi shows "Selected" once a combo lands; single/simple
        // keep the slot reserved with a non-breaking space so layout doesn't
        // shift between modes.
        $panel.find('.fh-purchase__from').html(isMulti ? 'Selected' : '&nbsp;');
        if (variation && variation.price_html) {
            $panel.find('.fh-purchase__price').html(variation.price_html);
        }
        var maxQty = (variation && (variation.max_qty !== undefined && variation.max_qty !== ''))
            ? variation.max_qty : -1;
        applyStockState($panel, computeStockBadge(!!(variation && variation.is_in_stock), maxQty), $cta);
    });

    $(document).on('reset_data.wc-variation-form', '.fh-purchase__form', function() {
        var $form  = $(this);
        var $panel = $form.closest('.fh-purchase');
        var $cta   = $form.find('.single_add_to_cart_button');
        var initial = $panel.data('fh-initial');
        if (!initial) return; // snapshot not taken yet (pre-ready); skip
        $panel.find('.fh-purchase__from').html(initial.eyebrow || '&nbsp;');
        $panel.find('.fh-purchase__price').html(initial.price || '');
        $panel.find('.fh-stock-badge')
            .attr('data-state', initial.stockState)
            .find('.fh-stock-badge__text').text(initial.stockText);
        var soldout = initial.stockState === 'soldout';
        var addLabel    = $cta.attr('data-fh-label-add')    || 'Add to Cart';
        var notifyLabel = $cta.attr('data-fh-label-notify') || 'Notify Me';
        $cta.toggleClass('fh-btn--notify', soldout)
            .text(soldout ? notifyLabel : addLabel)
            .prop('disabled', soldout);
    });

    // Snapshot each purchase panel's server-rendered state so reset_data
    // can restore the initial eyebrow / price / badge without a flicker.
    function snapshotInitialPurchaseState() {
        $('.fh-purchase').each(function() {
            var $panel = $(this);
            var $badge = $panel.find('.fh-stock-badge');
            $panel.data('fh-initial', {
                eyebrow:    $panel.find('.fh-purchase__from').html(),
                price:      $panel.find('.fh-purchase__price').html(),
                stockState: $badge.attr('data-state') || 'in-stock',
                stockText:  $badge.find('.fh-stock-badge__text').text() || ''
            });
        });
    }

    // For variable products with exactly one variation, click the matching
    // variation button on load so variation_id is populated and Add-to-Cart
    // is immediately functional. Multi-variation products are left alone —
    // the customer must pick a combo.
    function initVariationAutoSelect() {
        $('.fh-purchase__form').each(function() {
            var $form = $(this);
            if ($form.attr('data-fh-multi-variations') !== '0') return;
            var raw = $form.attr('data-product_variations');
            if (!raw) return;
            var variations;
            try { variations = JSON.parse(raw); } catch (e) { return; }
            if (!variations || variations.length !== 1) return;
            var attrs = variations[0].attributes || {};
            Object.keys(attrs).forEach(function(attrKey) {
                var val = attrs[attrKey];
                if (val === '' || val == null) return;
                var $btn = $form.find('.fh-var-buttons[data-attribute="' + attrKey + '"] .fh-var-btn[data-value="' + val + '"]');
                if ($btn.length) {
                    $btn.trigger('click');
                } else {
                    // Fallback: set the hidden select directly so WC's
                    // variation form still resolves a match.
                    $form.find('[name="' + attrKey + '"]').val(val).trigger('change');
                }
            });
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

    // Header scroll-shrink — hysteresis prevents the toggle feedback loop:
    // shrinking the header lifts content, which can drop scrollY back below
    // a single threshold and re-expand the header. The 20–60px dead zone
    // (40px > the ~35px height delta) breaks that oscillation.
    function initHeaderScroll() {
        var $header = $('#masthead');
        var COMPACT_ON  = 60;
        var COMPACT_OFF = 20;
        $(window).on('scroll', function() {
            var scrollY = window.scrollY;
            var isCompact = $header.hasClass('site-header--compact');
            if (scrollY > COMPACT_ON && !isCompact) {
                $header.addClass('site-header--compact');
            } else if (scrollY < COMPACT_OFF && isCompact) {
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

    // Cart page — quantity stepper. The visible − / + buttons drive the
    // numeric input next to them; change events bubble up to the auto-
    // submit handler below so the cart syncs without an explicit Update
    // Cart click.
    function initCartQtyStepper() {
        $(document).on('click', '.fh-cart-qty__btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $input = $btn.parent().find('.fh-cart-qty__input');
            if (!$input.length) return;
            var cur = parseInt($input.val(), 10);
            if (isNaN(cur)) cur = 0;
            var max = parseInt($input.attr('max'), 10);
            if (isNaN(max) || max < 0) max = 99;
            var min = parseInt($input.attr('min'), 10);
            if (isNaN(min)) min = 0;
            cur = $btn.data('action') === 'inc'
                ? Math.min(cur + 1, max)
                : Math.max(cur - 1, min);
            $input.val(cur).trigger('change');
        });
    }

    // Cart page — debounced auto-submit on qty change. The Update Cart
    // button is hidden in CSS; clicking it triggers WC's standard update
    // flow with the nonce already in the form.
    function initCartQtyAutoSubmit() {
        var timer = null;
        $(document).on('change input', '.fh-cart-qty__input', function() {
            if (timer) clearTimeout(timer);
            var $form = $(this).closest('.fh-cart-form');
            if (!$form.length) return;
            timer = setTimeout(function() {
                var $update = $form.find('.fh-cart-update-button');
                if ($update.length) {
                    $update.prop('disabled', false).trigger('click');
                } else {
                    $form.trigger('submit');
                }
            }, 600);
        });
    }

    $(document).ready(function() {
        // Snapshot purchase state before initVariationButtons binds, and
        // before initVariationAutoSelect dispatches the auto-click so
        // reset_data restores the true server-rendered values.
        snapshotInitialPurchaseState();
        initVariationButtons();
        initVariationAutoSelect();
        initGallery();
        initMobileNav();
        initNavDropdowns();
        initQty();
        initCartQtyStepper();
        initCartQtyAutoSubmit();
        initHeaderScroll();
        initTestimonialRotator();
        initArrivalCountdown();
    });

})(jQuery);
