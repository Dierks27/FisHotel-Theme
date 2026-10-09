/**
 * FisHotel Theme — main.js
 * @package FisHotel
 * @version 1.0.0
 */
(function($) {
    'use strict';

    // Variation button selectors — sync visual buttons with WooCommerce
    // hidden selects. The visible "— X selected" label uses the button's
    // text content (term display name) rather than data-value (slug) so
    // taxonomy attributes like "0.5oz" don't surface as "0-5OZ".
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
            if ($label.length) $label.text('— ' + $.trim($btn.text()) + ' selected');
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

        // Cap the quantity input at the variation's stock so the up-arrow
        // can't overshoot. Clamp the current value down if it exceeds the
        // new variation's max.
        var $hidden  = $panel.find('#fh-qty-input, input[name="quantity"]').first();
        var $display = $panel.find('.fh-qty__num').first();
        if ($hidden.length) {
            if (parseInt(maxQty, 10) > 0) {
                $hidden.attr('max', parseInt(maxQty, 10));
                var cur = parseInt($hidden.val(), 10) || 1;
                if (cur > maxQty) {
                    $hidden.val(maxQty);
                    if ($display.length) $display.text(maxQty);
                }
            } else {
                $hidden.removeAttr('max');
            }
        }
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

        // Clear the variation-driven max + reset the qty back to 1 when the
        // user un-selects a variation (display and hidden input both).
        var $hidden  = $panel.find('#fh-qty-input, input[name="quantity"]').first();
        var $display = $panel.find('.fh-qty__num').first();
        if ($hidden.length) {
            $hidden.removeAttr('max').val(1);
        }
        if ($display.length) {
            $display.text(1);
        }
    });

    // Variation-selection guard. WC's add-to-cart-variation.js only toggles
    // the button's selection-needed state through `.single_variation`
    // show/hide events, which this custom form never renders — so on a
    // multi-variation PDP the button stayed live with nothing chosen and
    // the click did a full POST (reload + WC's "Please choose product
    // options" notice). Bound AFTER the two handlers above so it sees the
    // sold-out `disabled` property they set.
    function variationSelectionNeeded($form) {
        if (!$form.hasClass('variations_form')) return false;
        var $selects = $form.find('select[data-attribute_name]');
        if (!$selects.length) return false;
        var missing = false;
        $selects.each(function() {
            if (!$(this).val()) { missing = true; return false; }
        });
        // WC only fills variation_id once an in-stock match resolves.
        return missing || !$form.find('input[name="variation_id"]').val();
    }

    function toggleVariationHint($form, show) {
        var $hint = $form.find('.fh-purchase__hint').first();
        if (!$hint.length) return;
        if (show) {
            var params = window.wc_add_to_cart_variation_params;
            if (params && params.i18n_make_a_selection_text) {
                $hint.text(params.i18n_make_a_selection_text);
            }
        }
        $hint.prop('hidden', !show);
    }

    function syncVariationSelectionState($form) {
        var $cta = $form.find('.single_add_to_cart_button');
        if (!$cta.length) return;
        // Sold out owns the button (disabled property + Notify Me); never
        // layer the selection-needed state on top of it. Classes + aria
        // only, NOT the disabled property, so the click still lands and
        // can surface the hint.
        var needed = !$cta.prop('disabled') && variationSelectionNeeded($form);
        $cta.toggleClass('disabled wc-variation-selection-needed', needed);
        if (needed) {
            $cta.attr('aria-disabled', 'true');
        } else {
            $cta.removeAttr('aria-disabled');
            toggleVariationHint($form, false);
        }
    }

    $(document).on('found_variation.wc-variation-form reset_data.wc-variation-form', '.fh-purchase__form', function() {
        syncVariationSelectionState($(this));
    });

    function initVariationSelectionGuard() {
        // Capture-phase listener: WC's own delegated click handler on the
        // form window.alert()s the same message when the button carries
        // `.disabled`; running first (and stopping propagation) swaps that
        // for the inline hint. Only intercepts while a selection is needed.
        document.addEventListener('click', function(e) {
            var $btn = $(e.target).closest('.fh-purchase__form .single_add_to_cart_button');
            if (!$btn.length || $btn.prop('disabled')) return;
            var $form = $btn.closest('.fh-purchase__form');
            if (!variationSelectionNeeded($form)) return;
            e.preventDefault();
            e.stopPropagation();
            toggleVariationHint($form, true);
        }, true);

        // Belt and braces: anything that reaches submit with no variation
        // resolved (Enter in a field, a plugin's programmatic submit) stays
        // on the page with the hint instead of round-tripping to WC's error.
        $(document).on('submit', '.fh-purchase__form.variations_form', function(e) {
            var $form = $(this);
            if (!variationSelectionNeeded($form)) return;
            e.preventDefault();
            toggleVariationHint($form, true);
        });

        $('.fh-purchase__form').each(function() { syncVariationSelectionState($(this)); });
    }

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

    // Auto-select any variation attribute that only has ONE distinct
    // in-stock value across the product's variation matrix. Covers two
    // cases:
    //   1. Single-variation products (1 total variation × N attributes,
    //      each with 1 value) — every attribute auto-resolves.
    //   2. Multi-variation products where one attribute is degenerate
    //      (e.g. Bloodworms: Type="Worms" × Bag Size={0.25oz,0.5oz,1oz}).
    //      The Type attribute auto-selects so the customer only sees
    //      Bag Size as something to choose.
    function initVariationAutoSelect() {
        $('.fh-purchase__form').each(function() {
            var $form = $(this);
            var raw = $form.attr('data-product_variations');
            if (!raw) return;
            var variations;
            try { variations = JSON.parse(raw); } catch (e) { return; }
            if (!variations || !variations.length) return;
            // Distinct in-stock values per attribute.
            var valuesByAttr = {};
            variations.forEach(function(v) {
                if (!v || !v.is_in_stock || !v.attributes) return;
                Object.keys(v.attributes).forEach(function(attrKey) {
                    var val = v.attributes[attrKey];
                    if (val === '' || val == null) return;
                    if (!valuesByAttr[attrKey]) valuesByAttr[attrKey] = {};
                    valuesByAttr[attrKey][val] = true;
                });
            });
            Object.keys(valuesByAttr).forEach(function(attrKey) {
                var values = Object.keys(valuesByAttr[attrKey]);
                if (values.length !== 1) return;
                var val = values[0];
                var $btn = $form.find('.fh-var-buttons[data-attribute="' + attrKey + '"] .fh-var-btn[data-value="' + val + '"]');
                if ($btn.length && !$btn.hasClass('selected')) {
                    $btn.trigger('click');
                } else if (!$btn.length) {
                    // Fallback: set the hidden select directly so WC's
                    // variation form still resolves a match.
                    $form.find('[name="' + attrKey + '"]').val(val).trigger('change');
                }
            });
        });
    }

    // Dim attribute values that have no valid in-stock variation given
    // the customer's current selections. Recomputes on every change to
    // the form's hidden selects (WC fires `change` on those whenever a
    // visible button is clicked, when a default attribute resolves on
    // init, and when the customer clears their selection).
    function initVariationAvailability() {
        function recompute($form) {
            var raw = $form.attr('data-product_variations');
            if (!raw) return;
            var variations;
            try { variations = JSON.parse(raw); } catch (e) { return; }
            if (!variations) return;

            // Snapshot current selections from the hidden selects.
            var selections = {};
            $form.find('select[data-attribute_name]').each(function() {
                selections[$(this).attr('data-attribute_name')] = $(this).val() || '';
            });

            $form.find('.fh-var-buttons').each(function() {
                var $group  = $(this);
                var attrKey = $group.data('attribute');
                $group.find('.fh-var-btn').each(function() {
                    var $btn  = $(this);
                    var value = String($btn.data('value'));
                    // Hypothetical: customer just clicked this button on
                    // top of the current selections. If any in-stock
                    // variation matches the resulting combo, the value
                    // stays available.
                    var hypothetical = $.extend({}, selections);
                    hypothetical[attrKey] = value;
                    var available = false;
                    for (var i = 0; i < variations.length; i++) {
                        var v = variations[i];
                        if (!v || !v.is_in_stock || !v.attributes) continue;
                        var match = true;
                        for (var k in hypothetical) {
                            var sel = hypothetical[k];
                            if (!sel) continue;
                            var vv = v.attributes[k];
                            if (vv === undefined) continue;
                            if (vv === '' || vv === null) continue; // "any" matches anything
                            if (vv !== sel) { match = false; break; }
                        }
                        if (match) { available = true; break; }
                    }
                    $btn.toggleClass('unavailable', !available);
                    // Mirror to the hidden <option> so keyboard / a11y
                    // users can't pick it either.
                    $group.closest('.fh-variation-group')
                        .find('select option')
                        .filter(function() { return $(this).val() === value; })
                        .prop('disabled', !available);
                });
            });
        }

        $(document).on('change', '.fh-purchase__form select[data-attribute_name]', function() {
            recompute($(this).closest('.fh-purchase__form'));
        });
        $('.fh-purchase__form').each(function() { recompute($(this)); });
    }

    // Gallery thumb switcher + lightbox
    function initGallery() {
        var $main = $('.fh-gallery__main');
        if (!$main.length) return;
        var $mainImg = $main.find('img');

        // Full-resolution URL for a thumb. Thumbs carry the full image in
        // data-full; the rendered <img> is only the 120px crop, so reading
        // its src would swap in a blurry low-res image.
        function fullSrc($thumb) {
            return $thumb.attr('data-full') || $thumb.find('img').attr('src');
        }

        // Swap the hero image. wp_get_attachment_image() emits srcset/sizes,
        // so the browser keeps painting a srcset candidate even after src
        // changes — clearing them is what makes the swap actually visible.
        function showImage(src) {
            if (!src) return;
            $mainImg.attr('src', src).removeAttr('srcset').removeAttr('sizes');
        }

        $(document).on('click', '.fh-gallery__thumb', function() {
            var $thumb = $(this);
            $('.fh-gallery__thumb').removeClass('active').removeAttr('aria-current');
            $thumb.addClass('active').attr('aria-current', 'true');
            showImage(fullSrc($thumb));
        });

        // Ordered list of full-size URLs for the lightbox. Mirrors the thumb
        // order; falls back to the hero image's own data-full when a product
        // has a single image (no thumbs rendered).
        function gallerySrcs() {
            var $thumbs = $('.fh-gallery__thumb');
            if ($thumbs.length) {
                return $thumbs.map(function() { return fullSrc($(this)); }).get();
            }
            return [$mainImg.attr('data-full') || $mainImg.attr('src')];
        }

        function currentIndex() {
            var i = $('.fh-gallery__thumb').index($('.fh-gallery__thumb.active'));
            return i < 0 ? 0 : i;
        }

        $main.on('click', function() { initLightbox(gallerySrcs(), currentIndex()); });
    }

    // Lightweight fullscreen lightbox — no PhotoSwipe dependency. Styled in
    // main.css (.fh-lightbox*) with FisHotel tokens. Supports prev/next,
    // keyboard arrows + ESC, click-out to close, and touch swipe on mobile.
    function initLightbox(srcs, startIndex) {
        if (!srcs || !srcs.length) return;
        var idx = startIndex || 0;

        var $box = $(
            '<div class="fh-lightbox" role="dialog" aria-modal="true" aria-label="Product image viewer">' +
                '<button type="button" class="fh-lightbox__close" aria-label="Close">&times;</button>' +
                '<button type="button" class="fh-lightbox__nav fh-lightbox__nav--prev" aria-label="Previous image">&#8249;</button>' +
                '<img class="fh-lightbox__img" alt="">' +
                '<button type="button" class="fh-lightbox__nav fh-lightbox__nav--next" aria-label="Next image">&#8250;</button>' +
                '<div class="fh-lightbox__counter"></div>' +
            '</div>'
        );

        var multiple = srcs.length > 1;
        if (!multiple) $box.find('.fh-lightbox__nav, .fh-lightbox__counter').remove();

        function render() {
            $box.find('.fh-lightbox__img').attr('src', srcs[idx]);
            if (multiple) $box.find('.fh-lightbox__counter').text((idx + 1) + ' / ' + srcs.length);
        }
        function go(step) {
            idx = (idx + step + srcs.length) % srcs.length;
            render();
        }
        // Focus management: remember what opened the viewer (thumb / hero
        // button), move focus inside on open, keep Tab cycling among the
        // viewer's own buttons, and hand focus back on close.
        var opener = document.activeElement;

        function close() {
            $box.remove();
            $(document).off('keydown.fhlightbox');
            $('body').css('overflow', '');
            if (opener && typeof opener.focus === 'function' && $.contains(document.documentElement, opener)) {
                opener.focus();
            }
        }

        function trapTab(e) {
            var controls = $box.find('button').get();
            if (!controls.length) return;
            var first = controls[0];
            var last  = controls[controls.length - 1];
            var active = document.activeElement;
            var inside = $.contains($box[0], active);
            if (e.shiftKey) {
                if (!inside || active === first) { e.preventDefault(); last.focus(); }
            } else if (!inside || active === last) {
                e.preventDefault(); first.focus();
            }
        }

        $box.on('click', '.fh-lightbox__close', close);
        $box.on('click', '.fh-lightbox__nav--prev', function(e) { e.stopPropagation(); go(-1); });
        $box.on('click', '.fh-lightbox__nav--next', function(e) { e.stopPropagation(); go(1); });
        $box.find('.fh-lightbox__img').on('click', function(e) { e.stopPropagation(); });
        // Click anywhere outside the image (the backdrop) closes.
        $box.on('click', close);

        $(document).on('keydown.fhlightbox', function(e) {
            if (e.key === 'Escape') close();
            else if (e.key === 'Tab') trapTab(e);
            else if (multiple && e.key === 'ArrowLeft') go(-1);
            else if (multiple && e.key === 'ArrowRight') go(1);
        });

        // Touch swipe — horizontal drag past threshold flips images.
        var touchX = null;
        $box.on('touchstart', function(e) { touchX = e.originalEvent.touches[0].clientX; });
        $box.on('touchend', function(e) {
            if (touchX === null || !multiple) return;
            var dx = e.originalEvent.changedTouches[0].clientX - touchX;
            if (Math.abs(dx) > 40) go(dx < 0 ? 1 : -1);
            touchX = null;
        });

        render();
        $('body').css('overflow', 'hidden').append($box);
        $box.find('.fh-lightbox__close').trigger('focus');
    }

    // Shop sort <select> — option values are full URLs (archive-product.php).
    // Mouse / touch picks fire one `change`, so navigate right away. Keyboard
    // stepping (arrows, Home/End, type-ahead letters) fires `change` on every
    // step in a closed select, so for those wait for Enter or blur instead of
    // loading a page per keypress — with a debounced fallback, because on
    // macOS Chrome/Safari an arrow key OPENS the native popup and the Enter
    // that commits inside it never reaches the page as a keydown (only as
    // `change`), so without the fallback that path never navigated at all.
    function initShopSort() {
        var keyboardStepping = false;
        var stepTimer = null;

        function go(sel) {
            var opt = sel.options[sel.selectedIndex];
            // Unchanged from the server-rendered selection → nothing to do.
            if (!opt || !opt.value || opt.defaultSelected) return;
            window.location.href = opt.value;
        }

        $(document).on('keydown', '.fh-shop-sort', function(e) {
            var k = e.key;
            if (k === 'Enter') {
                var sel = this;
                keyboardStepping = false;
                clearTimeout(stepTimer);
                // Defer so the browser applies a highlighted option (open
                // popup case) before we read the value.
                setTimeout(function() { go(sel); }, 0);
            } else if (!e.altKey && k !== 'F4' && k !== 'Tab' && k !== 'Escape' && k !== ' ' && k !== 'Shift' && k !== 'Alt' && k !== 'Control' && k !== 'Meta') {
                // Alt+Arrow / F4 open the native list (Windows) rather than
                // step it — the commit inside that list only fires `change`,
                // which must navigate right away, so they don't arm the flag.
                keyboardStepping = true;
            }
        });
        $(document).on('mousedown touchstart', '.fh-shop-sort', function() {
            keyboardStepping = false;
        });
        $(document).on('change', '.fh-shop-sort', function() {
            var sel = this;
            if (!keyboardStepping) { go(sel); return; }
            // Keyboard stepping: collapse a run of steps into one load once
            // the user pauses (Enter / blur still navigate immediately).
            clearTimeout(stepTimer);
            stepTimer = setTimeout(function() {
                keyboardStepping = false;
                go(sel);
            }, 900);
        });
        $(document).on('blur', '.fh-shop-sort', function(e) {
            clearTimeout(stepTimer);
            if (!keyboardStepping) return;
            keyboardStepping = false;
            // Focus left for a link / button (the user clicked a card, Load
            // More, a filter…): let that click navigate instead of racing it.
            var to = e.originalEvent && e.originalEvent.relatedTarget;
            if (to && $(to).closest('a,button').length) return;
            go(this);
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

    // Qty buttons — keep the visible #fh-qty-display and the hidden
    // #fh-qty-input in lockstep (the old selector .find('input.qty') silently
    // missed the actual hidden input, so the form submitted whatever the
    // server-rendered default was — usually 1 — regardless of clicks).
    // Also respect the input's min/max so the customer can't overshoot stock.
    function initQty() {
        function findHidden($btn) {
            var $scope = $btn.closest('.fh-add-to-cart');
            if (!$scope.length) $scope = $btn.closest('form');
            return $scope.find('#fh-qty-input, input[name="quantity"]').first();
        }
        function parseLimit($h, attr, fallback) {
            if (!$h.length) return fallback;
            var raw = $h.attr(attr);
            if (raw === undefined || raw === '') return fallback;
            var n = parseInt(raw, 10);
            return isNaN(n) ? fallback : n;
        }
        $(document).on('click', '.fh-qty__up', function() {
            var $n = $(this).closest('.fh-qty').find('.fh-qty__num');
            var $h = findHidden($(this));
            var cur = parseInt($n.text(), 10) || 1;
            var max = parseLimit($h, 'max', Infinity);
            var next = Math.min(cur + 1, max);
            if (next === cur) return;
            $n.text(next);
            if ($h.length) $h.val(next).trigger('change');
        });
        $(document).on('click', '.fh-qty__down', function() {
            var $n = $(this).closest('.fh-qty').find('.fh-qty__num');
            var $h = findHidden($(this));
            var cur = parseInt($n.text(), 10) || 1;
            var min = parseLimit($h, 'min', 1);
            var next = Math.max(cur - 1, min);
            if (next === cur) return;
            $n.text(next);
            if ($h.length) $h.val(next).trigger('change');
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

    // Checkout — "Ship to a different address?" toggle. The shipping card
    // is hidden by default (CSS `:has()` rule keys off WC collapsing the
    // .shipping_address fields). This external toggle, rendered outside the
    // hidden card, drives WC's own #ship-to-different-address-checkbox: a
    // programmatic .click() toggles the box AND fires the change event
    // WC's checkout.js listens for to slide the fields open/closed. When
    // the fields un-collapse, the `:has()` rule stops matching and the card
    // becomes visible — no separate "force open" class needed.
    function initShippingToggle() {
        var $toggle = $('.fh-checkout-shipping-toggle');
        if (!$toggle.length) return;

        function checkbox() {
            return document.getElementById('ship-to-different-address-checkbox');
        }
        function sync() {
            var cb = checkbox();
            var open = !!(cb && cb.checked);
            $toggle.attr('aria-expanded', open ? 'true' : 'false')
                   .toggleClass('is-open', open)
                   .find('.fh-checkout-shipping-toggle__icon').text(open ? '−' : '+');
        }

        $toggle.on('click', function(e) {
            e.preventDefault();
            var cb = checkbox();
            if (!cb) return;
            cb.click(); // toggles checked + fires the change WC binds to
            sync();
        });

        // WC re-renders the checkout fragment on AJAX update_checkout; if the
        // box state changes underneath us (or it's pre-checked on a
        // validation reload), keep the toggle label honest.
        $(document.body).on('change', '#ship-to-different-address input', sync);
        $(document.body).on('updated_checkout', sync);
        sync();
    }

    // Checkout — "Use gift card balance" toggle wiring. WC Gift Cards renders
    // its balance checkbox on `woocommerce_review_order_before_order_total`,
    // which our Folio layout fires OUTSIDE #order_review (in form-checkout.php)
    // so WC's update_order_review AJAX can't clone it into the refreshed table
    // fragment. The plugin's own change→update_checkout binding is scoped to
    // the order-review container, so once the checkbox lives outside it the
    // toggle goes completely inert: flipping it fires no AJAX, the ledger
    // totals freeze, and the payment section never re-evaluates. Because the
    // checkbox never talks to the server, the WC session's "use balance" flag
    // from page load is what gets used at submit regardless of what the box
    // shows — a fully-covered $0 order gets stuck behind hidden gateways, or
    // balance a customer unchecked gets consumed anyway.
    //
    // Re-wire it here (same pattern as the shipping toggle above): a delegated
    // change handler on document.body triggers WC's update_checkout. That
    // re-posts the checkbox state through update_order_review — letting the
    // plugin sync the session flag in BOTH directions — and refreshes the
    // review-order + payment fragments so totals, gateways, and the native
    // place-order button re-render to match. Delegated on document.body so it
    // keeps working across every fragment replacement.
    function initGiftCardBalanceToggle() {
        if (!$('form.checkout').length) return;
        $(document.body).on(
            'change',
            '#use_gift_card_balance, input[id^="apply_gift_card_balance"]',
            function() {
                $(document.body).trigger('update_checkout');
            }
        );
    }

    // Checkout — suppress PPCP smart / express buttons when ANY gift-card
    // value is on the order: the account BALANCE checkbox is ticked OR a
    // gift-card CODE has been applied to the cart. PPCP builds its
    // smart-button PayPal order from the cart line-item breakdown, which does
    // NOT include the WC Gift Cards deduction (a cart-total-level adjustment,
    // not a line item / coupon / fee) — and that is true for both paths. So a
    // partial-coverage smart-button checkout captures the FULL pre-gift-card
    // total at PayPal while the card is also debited — a real-money
    // double-charge (order #34494). The standard place-order → PayPal
    // redirect, by contrast, builds the PayPal order from the WC order and
    // charges the correct post-gift-card total.
    //
    // Applied CODES are detected server-side: review-order.php emits a hidden
    // `.fh-gc-applied` marker inside the review table whenever the plugin
    // reports an applied card, and that table is re-rendered with every
    // update_order_review fragment, so the marker appears/disappears as codes
    // are applied/removed. The balance checkbox is still read live.
    //
    // When either is true we hide PPCP's smart-button + Pay Later message
    // containers (via the .fh-gc-balance-applied class → woocommerce.css;
    // the class name predates the code-path fix and is kept so the CSS
    // stays untouched) and force the native place-order button visible by
    // stripping PPCP's `ppcp-hidden` class, routing the customer through the
    // standard flow. Re-evaluated on every fragment refresh (updated_checkout)
    // and on checkbox toggle, and paired with a server-side capture-amount
    // guard (inc/gift-card-checkout.php) as defense in depth.
    function initGiftCardSmartButtonGuard() {
        var $form = $('form.checkout');
        if (!$form.length) return;

        function giftCardApplied() {
            // Code applied — marker from review-order.php.
            if ($('.fh-gc-applied').length > 0) return true;
            // Account balance — checkbox state.
            var $box = $('#use_gift_card_balance, input[id^="apply_gift_card_balance"]');
            return $box.length > 0 && $box.filter(':checked').length > 0;
        }

        function sync() {
            var applied = giftCardApplied();
            $form.toggleClass('fh-gc-balance-applied', applied);
            if (applied) {
                // PPCP hides the native button behind `ppcp-hidden` when its
                // smart buttons own the checkout; reveal it so the standard
                // place-order flow is usable.
                $('#place_order').removeClass('ppcp-hidden');
            }
        }

        // Re-run after PPCP has re-rendered its buttons on fragment refresh —
        // this is also what picks up the .fh-gc-applied marker after a code
        // is applied or removed (both end in update_checkout → updated_checkout).
        $(document.body).on('updated_checkout', function() { setTimeout(sync, 0); });
        $(document.body).on(
            'change',
            '#use_gift_card_balance, input[id^="apply_gift_card_balance"]',
            sync
        );
        sync();
    }

    $(document).ready(function() {
        // Snapshot purchase state before initVariationButtons binds, and
        // before initVariationAutoSelect dispatches the auto-click so
        // reset_data restores the true server-rendered values.
        // Availability binds its change listener BEFORE the auto-click
        // so the first auto-select recompute already runs through it.
        snapshotInitialPurchaseState();
        initVariationButtons();
        initVariationAvailability();
        initVariationAutoSelect();
        // After auto-select so a single-variation product is already
        // resolved when the initial selection-state sync runs.
        initVariationSelectionGuard();
        initGallery();
        initShopSort();
        initMobileNav();
        initNavDropdowns();
        initQty();
        initCartQtyStepper();
        initCartQtyAutoSubmit();
        initHeaderScroll();
        initTestimonialRotator();
        initArrivalCountdown();
        initShippingToggle();
        initGiftCardBalanceToggle();
        initGiftCardSmartButtonGuard();
    });

})(jQuery);
