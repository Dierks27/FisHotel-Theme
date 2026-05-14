/**
 * FisHotel — Medications archive dual-axis filter chips
 *
 * Reads the .fh-fish-card-wrap[data-fh-cats] elements and toggles their
 * visibility based on the active Form + Treats chip pair. URL state is
 * mirrored to ?form=&treats= via history.replaceState so a filtered view
 * is bookmarkable + back-button-survivable.
 *
 * Enqueued only on /product-category/medications/.
 */
(function () {
    var strip = document.querySelector('[data-fh-filter-strip]');
    if (!strip) {
        return;
    }
    // The archive uses .fh-product-grid as the card container; fall back
    // to .products in case WC's default loop wrapper is restored later.
    var grid = document.querySelector('.fh-product-grid') || document.querySelector('ul.products') || document.querySelector('.products');
    if (!grid) {
        return;
    }
    var emptyState = strip.querySelector('.fh-med-filters__empty');
    var countDisplay = document.querySelector('[data-fh-result-count]');
    // Stash the original count text so we can restore it on full-reset.
    var initialCountHTML = countDisplay ? countDisplay.innerHTML : '';

    function activeSlug(axis) {
        var active = strip.querySelector('[data-axis="' + axis + '"] .is-active');
        return active ? active.getAttribute('data-slug') : '';
    }

    function applyFilters() {
        var form = activeSlug('form');
        var treats = activeSlug('treats');
        var cards = grid.querySelectorAll('[data-fh-cats]');
        var total = cards.length;
        var visible = 0;

        cards.forEach(function (card) {
            var raw = card.getAttribute('data-fh-cats') || '';
            var cats = raw ? raw.split(',') : [];
            var okForm = !form || cats.indexOf(form) !== -1;
            var okTreats = !treats || cats.indexOf(treats) !== -1;
            var show = okForm && okTreats;
            card.style.display = show ? '' : 'none';
            if (show) {
                visible++;
            }
        });

        if (emptyState) {
            emptyState.hidden = visible > 0;
        }

        if (countDisplay) {
            if (!form && !treats) {
                // No filters applied — restore the server-rendered count line.
                countDisplay.innerHTML = initialCountHTML;
            } else {
                countDisplay.textContent = 'Showing ' + visible + ' of ' + total + ' medications';
            }
        }

        // Mirror state into the URL without scrolling or pushing history.
        var params = new URLSearchParams();
        if (form) {
            params.set('form', form);
        }
        if (treats) {
            params.set('treats', treats);
        }
        var qs = params.toString();
        var url = qs ? location.pathname + '?' + qs : location.pathname;
        history.replaceState(null, '', url);
    }

    strip.querySelectorAll('.fh-med-filters__chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var row = chip.closest('[data-axis]');
            if (!row) {
                return;
            }
            row.querySelectorAll('.fh-med-filters__chip').forEach(function (c) {
                c.classList.remove('is-active');
            });
            chip.classList.add('is-active');
            applyFilters();
        });
    });

    var clearLink = strip.querySelector('[data-fh-clear-filters]');
    if (clearLink) {
        clearLink.addEventListener('click', function (e) {
            e.preventDefault();
            strip.querySelectorAll('.fh-med-filters__row').forEach(function (row) {
                row.querySelectorAll('.fh-med-filters__chip').forEach(function (c) {
                    c.classList.remove('is-active');
                });
                var allChip = row.querySelector('[data-slug=""]');
                if (allChip) {
                    allChip.classList.add('is-active');
                }
            });
            applyFilters();
        });
    }

    // Initialise chips from the URL so a deep-linked filter combo
    // (?form=powders&treats=antiparasitic) renders correctly on load.
    var initParams = new URLSearchParams(location.search);
    ['form', 'treats'].forEach(function (axis) {
        var slug = initParams.get(axis);
        if (!slug) {
            return;
        }
        var chip = strip.querySelector('[data-axis="' + axis + '"] [data-slug="' + slug + '"]');
        if (!chip) {
            return;
        }
        strip.querySelectorAll('[data-axis="' + axis + '"] .fh-med-filters__chip').forEach(function (c) {
            c.classList.remove('is-active');
        });
        chip.classList.add('is-active');
    });

    applyFilters();
})();
