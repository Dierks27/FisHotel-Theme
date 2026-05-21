/**
 * FisHotel Theme — shop archive Load More.
 *
 * AJAX-appends the next batch of products to the grid (.fh-product-grid)
 * and hides the no-JS pagination fallback. Sort context rides in the
 * request (data-orderby) so ordering is preserved across loads.
 *
 * @package FisHotel
 */
(function () {
    'use strict';

    var wrap = document.querySelector('.fishotel-load-more-wrap');
    if (!wrap || typeof FishotelLoadMore === 'undefined') {
        return;
    }

    var btn   = wrap.querySelector('.fishotel-load-more-btn');
    var label = btn.querySelector('.fishotel-load-more-label');
    var grid  = document.querySelector('.fh-product-grid');
    if (!btn || !label || !grid) {
        return;
    }

    var orderby       = wrap.dataset.orderby || '';
    var endText       = wrap.dataset.endText || '';
    var originalLabel = label.textContent;
    var loading       = false;

    // JS is active — reveal the button and hide the pagination fallback.
    wrap.classList.add('is-ready');
    var fallback = document.querySelector('.fishotel-pagination-fallback');
    if (fallback) {
        fallback.style.display = 'none';
    }

    // IDs of every product currently in the grid. Sent with each request so
    // the handler excludes them and returns the next batch — guaranteeing no
    // duplicates and no skipped products regardless of sort.
    function loadedIds() {
        return Array.prototype.slice
            .call(grid.querySelectorAll('.fh-fish-card-wrap[data-product-id]'))
            .map(function (el) { return el.dataset.productId; })
            .filter(Boolean);
    }

    function setIdle() {
        btn.classList.remove('is-loading');
        btn.disabled = false;
        label.textContent = originalLabel;
    }

    function setExhausted() {
        btn.classList.remove('is-loading');
        btn.classList.add('is-exhausted');
        btn.disabled = true;
        label.textContent = endText;
    }

    btn.addEventListener('click', function () {
        if (loading || btn.classList.contains('is-exhausted')) {
            return;
        }
        loading = true;
        btn.classList.add('is-loading');
        btn.disabled = true;

        var formData = new FormData();
        formData.append('action', 'fishotel_load_more_products');
        formData.append('nonce', FishotelLoadMore.nonce);
        formData.append('orderby', orderby);
        formData.append('taxonomy', FishotelLoadMore.taxonomy);
        formData.append('term', FishotelLoadMore.term);
        formData.append('per_page', FishotelLoadMore.per_page);
        loadedIds().forEach(function (id) {
            formData.append('loaded_ids[]', id);
        });

        fetch(FishotelLoadMore.ajax_url, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = data.html;
                    Array.prototype.slice.call(tmp.children).forEach(function (node) {
                        node.classList.add('fishotel-fade-in');
                        grid.appendChild(node);
                    });
                }
                loading = false;
                // No more remaining → exhausted end state; otherwise return to
                // idle/clickable so the next batch is reachable.
                if (!data.has_more) {
                    setExhausted();
                } else {
                    setIdle();
                }
            })
            .catch(function () {
                loading = false;
                btn.classList.remove('is-loading');
                btn.disabled = false;
                label.textContent = "Couldn't load more — try again";
            });
    });
})();
