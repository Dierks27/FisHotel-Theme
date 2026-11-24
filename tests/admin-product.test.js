/**
 * Product edit screen helpers (theme v1.28.0).
 *
 * Run: `node tests/admin-product.test.js` from the theme root. No
 * dependencies, exits non-zero on failure.
 *
 * assets/js/admin-product.js exports:
 *   medFieldsShown( termIds, medTermIds, mode, asin ) → same rule as
 *     FisHotel_Admin_Product_Screen::med_fields_shown() (tests/product-screen-test.php)
 *   factsToFill( plan, current ) → { fill, elsewhere } — only EMPTY boxes on
 *     screen are filled; facts with no box on screen are handed on
 *   handedOn( elsewhere, taken, hasListener ) → { taken, missed } — which
 *     facts handed to another box (event `fishotel-copy-facts`) it took
 *   copyMessage( filled, elsewhere, missed )
 *
 * @package fishotel-theme
 */
'use strict';

var assert = require('assert');
var path = require('path');

var mod = require(path.join(__dirname, '..', 'assets/js/admin-product.js'));
['medFieldsShown', 'factsToFill', 'handedOn', 'copyMessage'].forEach(function (name) {
  assert.strictEqual(typeof mod[name], 'function', name + ' exported');
});

var checks = 0;
function same(actual, expected, label) {
  assert.deepStrictEqual(actual, expected, label);
  checks++;
}

// ── medFieldsShown ─────────────────────────────────────────────────────
same(mod.medFieldsShown([], [5, 6], '', ''), true, 'no category ticked: shown (nothing hidden)');
same(mod.medFieldsShown([3, 6], [5, 6], '', ''), true, 'a medication / food category ticked: shown');
same(mod.medFieldsShown([3, 4], [5, 6], '', ''), false, 'fish categories only: hidden');
same(mod.medFieldsShown(['5'], [5], '', ''), true, 'checkbox values are strings');
same(mod.medFieldsShown([3], [5], 'ea', ''), true, '"Who ships it" set: shown');
same(mod.medFieldsShown([3], [5], '', 'B000TEST01'), true, 'Amazon ASIN set: shown');
same(mod.medFieldsShown([3], [5], ' ', '  '), false, 'blank mode / ASIN do not count');
same(mod.medFieldsShown([NaN, 0], [5], '', ''), true, 'nothing real ticked counts as no category');
same(mod.medFieldsShown(undefined, undefined, undefined, undefined), true, 'missing data: shown (never hides by accident)');

// ── factsToFill ────────────────────────────────────────────────────────
var plan = { scientific_name: 'Testus oldus', temperament: 'Peaceful', reef_safe: 'yes', max_length: '2 in' };
same(
  mod.factsToFill(plan, { temperament: 'Shy', reef_safe: '', max_length: '  ' }),
  { fill: { reef_safe: 'yes', max_length: '2 in' }, elsewhere: { scientific_name: 'Testus oldus' } },
  'typed box kept, empty boxes filled, a fact with no box here is handed on'
);
same(mod.factsToFill(plan, { scientific_name: 'Typed', temperament: 'x', reef_safe: 'no', max_length: '3 in' }),
  { fill: {}, elsewhere: {} }, 'everything already typed: nothing filled');
same(mod.factsToFill({ common_names: '  ' }, { common_names: '' }), { fill: {}, elsewhere: {} }, 'blank planned value is never written');
same(mod.factsToFill({}, {}), { fill: {}, elsewhere: {} }, 'empty plan');
same(mod.factsToFill(null, null), { fill: {}, elsewhere: {} }, 'no plan');

// ── copyMessage ────────────────────────────────────────────────────────
same(mod.copyMessage(0, 0), 'Nothing new could be read from the description — copy by hand.', 'nothing copied');
same(mod.copyMessage(1, 0), 'Copied 1 fact — press Update to keep them.', 'one');
same(mod.copyMessage(2, 1), 'Copied 3 facts — press Update to keep them.', 'several');


// ── handedOn: a fact handed to another box is only "copied" if a box took it
// (the scientific name was counted as copied and dropped when nothing
// listened for fishotel-copy-facts).
var away = { scientific_name: 'Testus oldus' };
same(mod.handedOn(away, {}, false), { taken: [], missed: ['scientific_name'] }, 'nothing listens: not copied');
same(mod.handedOn(away, {}, true), { taken: ['scientific_name'], missed: [] }, 'a listener that says nothing is trusted');
same(mod.handedOn(away, { scientific_name: true }, true), { taken: ['scientific_name'], missed: [] }, 'a listener that says it filled it');
same(mod.handedOn({ scientific_name: 'A', region: 'B' }, { region: true }, true), { taken: ['region'], missed: ['scientific_name'] }, 'a listener that filled only one (the other was already typed)');
same(mod.handedOn({}, {}, false), { taken: [], missed: [] }, 'nothing handed on');
same(mod.handedOn(null, null, true), { taken: [], missed: [] }, 'no data');
same(mod.copyMessage(1, 0, ['Scientific Name']), 'Copied 1 fact — press Update to keep them. Scientific Name was not copied — type it in by hand.', 'the dropped name is named, not counted');
same(mod.copyMessage(0, 0, ['Scientific Name', 'Region']), 'Nothing could be filled here. Scientific Name, Region were not copied — type them in by hand.', 'nothing filled, two dropped');
same(mod.copyMessage(1, 1, []), 'Copied 2 facts — press Update to keep them.', 'nothing dropped: as before');

console.log('admin-product.test.js: ' + checks + ' checks passed');
