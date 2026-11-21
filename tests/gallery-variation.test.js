/**
 * Product gallery — what a picked variation does to the picture.
 *
 * Run: `node tests/gallery-variation.test.js` from the theme root. No
 * dependencies, exits non-zero on failure.
 *
 * assets/js/main.js exports its one pure decision:
 *   variationImagePlan( image, mainFull, thumbFulls, variationShowing )
 *     → { action: 'none' }
 *     | { action: 'restore' }
 *     | { action: 'thumb', index, alt }
 *     | { action: 'hero', src, alt }
 *
 * The rules it pins down: a variation with a picture of its own moves the
 * gallery — onto the thumb that carries the picture when there is one, onto
 * the hero alone when there is none; a variation that only has the product's
 * main image (every size of a fish) leaves the gallery alone, unless the
 * gallery is showing an earlier variation's picture, and then it goes back
 * to what the page showed when it loaded.
 *
 * All products, file names and URLs below are invented.
 *
 * @package fishotel-theme
 */
'use strict';

var assert = require('assert');
var path = require('path');

// The file must load without a DOM and without jQuery.
var mod = require(path.join(__dirname, '..', 'assets/js/main.js'));
assert.strictEqual(typeof mod.variationImagePlan, 'function', 'variationImagePlan exported');
var plan = mod.variationImagePlan;

var checks = 0;
function same(actual, expected, label) {
  assert.deepStrictEqual(actual, expected, label);
  checks++;
}

var UP = 'https://shop.example/wp-content/uploads/2026/11/';

// variation.image as WooCommerce's wc_get_product_attachment_props() builds it.
function wcImage(file, extra) {
  var base = file.replace(/\.jpg$/, '');
  var image = {
    title: base,
    caption: '',
    url: UP + file,
    alt: base, // WooCommerce falls back to the attachment title
    src: UP + base + '-800x600.jpg',
    srcset: UP + base + '-800x600.jpg 800w, ' + UP + base + '-1536x1152.jpg 1536w, ' + UP + file + ' 2000w',
    sizes: '(max-width: 800px) 100vw, 800px',
    full_src: UP + file,
    full_src_w: 2000,
    full_src_h: 1500,
    gallery_thumbnail_src: UP + base + '-100x100.jpg',
    thumb_src: UP + base + '-600x600.jpg',
    src_w: 800,
    src_h: 600
  };
  Object.keys(extra || {}).forEach(function (k) { image[k] = extra[k]; });
  return image;
}

// What WooCommerce sends when neither the variation nor the product has a picture.
var NO_IMAGE = { title: '', caption: '', url: '', alt: '', src: '', srcset: false, sizes: false };

var MAIN   = UP + 'coral-main.jpg';
var THUMBS = [MAIN, UP + 'coral-green.jpg', UP + 'coral-orange.jpg', UP + 'coral-purple.jpg'];

var NONE    = { action: 'none' };
var RESTORE = { action: 'restore' };

// ── A variation with no picture of its own (WooCommerce sends the parent's) ──
same(plan(wcImage('coral-main.jpg'), MAIN, THUMBS, false), NONE, 'main image, nothing from a variation showing: gallery left alone');
same(plan(wcImage('coral-main.jpg'), MAIN, [], false), NONE, 'same on a product without thumbs');
same(plan(wcImage('coral-main.jpg'), MAIN, THUMBS, true), RESTORE, 'main image after a variation picture: back to how the page loaded');
same(plan(wcImage('coral-main.jpg'), MAIN, [], true), RESTORE, 'same on a product without thumbs');

// ── A variation whose picture sits on a thumb ──────────────────────────
same(plan(wcImage('coral-green.jpg'), MAIN, THUMBS, false), { action: 'thumb', index: 1, alt: '' }, 'second thumb carries it');
same(plan(wcImage('coral-purple.jpg'), MAIN, THUMBS, false), { action: 'thumb', index: 3, alt: '' }, 'fourth thumb carries it');
same(plan(wcImage('coral-orange.jpg'), MAIN, THUMBS, true), { action: 'thumb', index: 2, alt: '' }, 'from one variation picture to the next');
same(plan(wcImage('coral-green.jpg'), '', [UP + 'coral-orange.jpg', UP + 'coral-green.jpg'], false), { action: 'thumb', index: 1, alt: '' },
  'a product with gallery images but no main image');

// ── A variation whose picture no thumb carries ─────────────────────────
var blue = wcImage('coral-blue.jpg');
var BLUE = { action: 'hero', src: UP + 'coral-blue.jpg', alt: '' };
same(plan(blue, MAIN, THUMBS, false), BLUE, 'a fifth variation: hero only, the full-size file — never the single size, never a thumbnail');
same(plan(blue, MAIN, [], false), BLUE, 'a product with no thumbs at all');
same(plan(blue, '', [], false), BLUE, 'the product has no main image (placeholder hero)');
same(plan(blue, MAIN, THUMBS, true), BLUE, 'from a thumb variation to the fifth one');
same(plan(wcImage('coral-blue.jpg', { srcset: false, sizes: false }), MAIN, THUMBS, false), BLUE, 'an image without srcset');
same(plan(wcImage('coral-blue.jpg', { full_src: UP + 'coral-blue-1536x1152.jpg' }), MAIN, THUMBS, false).src, UP + 'coral-blue-1536x1152.jpg',
  'a filtered full size is the one shown');
same(plan(wcImage('coral-blue.jpg', { full_src: '' }), MAIN, THUMBS, false), BLUE, 'no full_src: `url`');
same(plan({ src: UP + 'coral-blue-800x600.jpg' }, MAIN, THUMBS, false), { action: 'hero', src: UP + 'coral-blue-800x600.jpg', alt: '' },
  'an image with nothing but src still shows');

// ── Alt text ───────────────────────────────────────────────────────────
same(plan(wcImage('coral-blue.jpg', { alt: 'Blue coral frag on a plug' }), MAIN, THUMBS, false).alt, 'Blue coral frag on a plug',
  'the image\'s own alt is used');
same(plan(wcImage('coral-blue.jpg', { alt: 'coral-blue', title: 'coral-blue' }), MAIN, THUMBS, false).alt, '',
  'WooCommerce\'s fallback to the attachment title is not an alt of its own');
same(plan(wcImage('coral-blue.jpg', { alt: '' }), MAIN, THUMBS, false).alt, '', 'no alt: keep the product\'s name');
// The same on a thumb: its picture shown because of a variation carries the
// image's own alt; shown by a click it keeps the product's name (main.js).
same(plan(wcImage('coral-green.jpg', { alt: 'Green coral frag, polyps open' }), MAIN, THUMBS, false),
  { action: 'thumb', index: 1, alt: 'Green coral frag, polyps open' }, 'a thumb\'s picture: the image\'s own alt comes along');
same(plan(wcImage('coral-green.jpg', { alt: 'coral-green', title: 'coral-green' }), MAIN, THUMBS, true).alt, '',
  'a thumb\'s picture without an alt of its own');
same(plan(wcImage('coral-main.jpg', { alt: 'Main picture of the coral' }), MAIN, THUMBS, true), RESTORE,
  'back to the product\'s own picture: no alt to carry');

// ── No picture anywhere ────────────────────────────────────────────────
same(plan(NO_IMAGE, '', [], false), NONE, 'no picture, nothing showing: left alone');
same(plan(NO_IMAGE, '', [], true), RESTORE, 'no picture after a variation picture: back to how the page loaded');
same(plan(undefined, MAIN, THUMBS, false), NONE, 'a variation without image data');
same(plan(null, MAIN, THUMBS, true), RESTORE, 'null image after a variation picture');
same(plan(false, MAIN, undefined, false), NONE, 'no thumbs list at all');

// ── Comparing URLs ─────────────────────────────────────────────────────
same(plan(wcImage('coral-main.jpg', { url: MAIN.replace('https:', 'http:'), full_src: MAIN.replace('https:', 'http:') }), MAIN, THUMBS, false), NONE,
  'http vs https is the same picture');
same(plan(wcImage('coral-main.jpg', { url: MAIN + '?ver=3', full_src: MAIN + '?ver=3' }), MAIN, THUMBS, false), NONE,
  'a query string does not split a match');
same(plan(wcImage('coral-green.jpg'), MAIN, [MAIN, ' ' + UP + 'coral-green.jpg?x=1 '], false), { action: 'thumb', index: 1, alt: '' },
  'thumb URL with spaces and a query string');
same(plan(wcImage('coral-green.jpg', { full_src: UP + 'coral-green-1536x1152.jpg' }), MAIN, THUMBS, false), { action: 'thumb', index: 1, alt: '' },
  'a filtered full size: `url` still names the picture');
same(plan(wcImage('coral-main.jpg', { url: '' }), MAIN, THUMBS, false), NONE, 'no `url`: full_src names the picture');
same(plan(wcImage('coral-main-2.jpg'), MAIN, THUMBS, false).action, 'hero', 'a similar file name is another picture');

// A plugin that serves the page's pictures from another host (a CDN) rewrites
// data-full but not WooCommerce's variation data. Host and port do not name
// the picture, the path does: a size pick on a fish must still be "the main
// image", not a picture of its own that throws the customer off their thumb.
var CDN = 'https://cdn.example.net/wp-content/uploads/2026/11/';
var CDN_THUMBS = [CDN + 'coral-main.jpg', CDN + 'coral-green.jpg', CDN + 'coral-orange.jpg', CDN + 'coral-purple.jpg'];
same(plan(wcImage('coral-main.jpg'), CDN + 'coral-main.jpg', CDN_THUMBS, false), NONE, 'page pictures on another host: still the main image');
same(plan(wcImage('coral-main.jpg'), CDN + 'coral-main.jpg', CDN_THUMBS, true), RESTORE, 'same, after a variation picture');
same(plan(wcImage('coral-orange.jpg'), CDN + 'coral-main.jpg', CDN_THUMBS, false), { action: 'thumb', index: 2, alt: '' },
  'page pictures on another host: the thumb is still found');
same(plan(wcImage('coral-blue.jpg'), CDN + 'coral-main.jpg', CDN_THUMBS, false), BLUE, 'and a picture no thumb carries is still shown from the shop\'s own address');
same(plan(wcImage('coral-main.jpg'), '//shop.example:8443/wp-content/uploads/2026/11/coral-main.jpg', [], false), NONE,
  'no scheme, a port: same picture');
same(plan(wcImage('coral-main.jpg'), '/wp-content/uploads/2026/11/coral-main.jpg', [], false), NONE, 'a path alone: same picture');
same(plan(wcImage('coral-main.jpg'), 'https://shop.example/wp-content/uploads/2026/10/coral-main.jpg', [], false).action, 'hero',
  'same file name in another folder is another picture');
same(plan(wcImage('coral-green.jpg'), MAIN, ['', '', UP + 'coral-green.jpg'], false), { action: 'thumb', index: 2, alt: '' },
  'a thumb without data-full never matches, positions are kept');

// The single size (`src`) is not the picture's name when a full URL exists:
// a main image passed at single size must not look like a variation picture.
same(plan(wcImage('coral-main.jpg'), UP + 'coral-main.jpg', [], false), NONE, 'main image again, compared at full size');

console.log('gallery-variation: OK (' + checks + ' checks — own picture moves the gallery, the main image only after a variation picture)');
