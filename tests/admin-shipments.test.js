/**
 * Shipments meta box — label cost helpers.
 *
 * Run: `node tests/admin-shipments.test.js` from the theme root. No
 * dependencies, exits non-zero on failure.
 *
 * assets/js/admin-shipments.js exports its pure helpers:
 *   boxDims( box, dims )               → the size to post (never "custom")
 *   addCostFields( values )            → fields sent with fst_add_tracking
 *   editCostFields( values )           → fields sent with fst_set_label_cost
 *   errorField( message )              → 'cost' | 'box' | 'weight' | ''
 *   responseError( response, fallback )
 *   rememberedSource( storage, options ) / rememberSource( storage, source )
 *
 * The rules they pin down: an untouched Add Shipment form posts nothing new
 * (so it works unchanged against an older ShipTracker); the remembered
 * "bought through" default never records itself; an emptied field of the
 * edit form is sent empty (ShipTracker clears it) while a field the form
 * does not have is not sent (ShipTracker leaves it); ShipTracker's three
 * validation messages land on the right field.
 *
 * @package fishotel-theme
 */
'use strict';

var assert = require('assert');
var path = require('path');

// The module must load without a DOM and without jQuery.
var mod = require(path.join(__dirname, '..', 'assets/js/admin-shipments.js'));
['boxDims', 'addCostFields', 'editCostFields', 'errorField', 'responseError', 'rememberedSource', 'rememberSource'].forEach(function (name) {
  assert.strictEqual(typeof mod[name], 'function', name + ' exported');
});

var checks = 0;
function same(actual, expected, label) {
  assert.deepStrictEqual(actual, expected, label);
  checks++;
}

// ── boxDims ────────────────────────────────────────────────────────────
same(mod.boxDims('10x10x10', ''), '10x10x10', 'a standard box posts its size');
same(mod.boxDims('10x10x10', '12x9x6'), '10x10x10', 'leftover custom text is ignored once a standard box is picked');
same(mod.boxDims('custom', ' 12 x 9 x 6 '), '12 x 9 x 6', 'Custom posts the typed size, trimmed (ShipTracker normalises the spelling)');
same(mod.boxDims('custom', ''), '', 'Custom with nothing typed posts no size — never the word "custom"');
same(mod.boxDims('', '12x9x6'), '', 'no box picked: no size');
same(mod.boxDims(undefined, undefined), '', 'no inputs at all: no size');

// ── addCostFields ──────────────────────────────────────────────────────
same(mod.addCostFields({}), {}, 'nothing filled in: nothing added to the request');
same(mod.addCostFields(undefined), {}, 'no cost inputs on the page (older ShipTracker): nothing added');
same(mod.addCostFields({ cost: undefined, box: undefined, dims: undefined, weight: undefined, source: null, sourceTouched: false }), {}, 'values read from missing inputs: nothing added');
same(mod.addCostFields({ cost: '', box: '', dims: '', weight: '', source: '' }), {}, 'empty inputs: nothing added');
same(mod.addCostFields({ cost: ' 53.73 ', box: '10x10x10', dims: '', weight: '11', source: 'Partner A' }),
  { label_cost: '53.73', box_dims: '10x10x10', box_weight: '11', label_source: 'Partner A' }, 'all four fields, trimmed');
same(mod.addCostFields({ cost: '$1,018.40', box: 'custom', dims: '12x9x6' }),
  { label_cost: '$1,018.40', box_dims: '12x9x6' }, 'the cost is sent as typed — ShipTracker is the one validator');
same(mod.addCostFields({ cost: '18.40', box: 'custom', dims: '  ' }), { label_cost: '18.40' }, 'Custom left blank adds no box_dims');
same(mod.addCostFields({ cost: '0' }), { label_cost: '0' }, 'a typed 0 is a cost and is sent');

// The remembered "bought through" default.
same(mod.addCostFields({ source: 'Partner A', sourceTouched: false }), {}, 'remembered source alone is not sent — it must not record itself');
same(mod.addCostFields({ source: 'Partner A', sourceTouched: true }), { label_source: 'Partner A' }, 'a source picked in this page view is sent on its own');
same(mod.addCostFields({ cost: '53.73', source: 'Partner A', sourceTouched: false }),
  { label_cost: '53.73', label_source: 'Partner A' }, 'remembered source goes with a typed cost');
same(mod.addCostFields({ box: '10x10x10', source: 'Partner B', sourceTouched: false }),
  { box_dims: '10x10x10', label_source: 'Partner B' }, 'remembered source goes with a chosen box');
same(mod.addCostFields({ weight: '11', source: 'Partner B' }), { box_weight: '11', label_source: 'Partner B' }, 'remembered source goes with a typed weight');
same(mod.addCostFields({ cost: '53.73', source: '', sourceTouched: true }), { label_cost: '53.73' }, 'source set back to "not recorded" is not sent');
same(mod.addCostFields({ cost: '53.73', source: null }), { label_cost: '53.73' }, 'no source dropdown (empty list): no label_source');

// ── editCostFields ─────────────────────────────────────────────────────
same(mod.editCostFields({ cost: '19.25', box: '10x10x10', dims: '', weight: '11', source: 'Partner B' }),
  { label_cost: '19.25', box_dims: '10x10x10', box_weight: '11', label_source: 'Partner B' }, 'edit sends every field');
same(mod.editCostFields({ cost: '', box: '', dims: '', weight: '', source: '' }),
  { label_cost: '', box_dims: '', box_weight: '', label_source: '' }, 'emptied fields are sent empty, so ShipTracker clears them');
same(mod.editCostFields({ cost: '19.25', box: 'custom', dims: '12x9x6', weight: '', source: null }),
  { label_cost: '19.25', box_dims: '12x9x6', box_weight: '' }, 'no source dropdown: label_source is not sent, so it is left alone');
same(Object.prototype.hasOwnProperty.call(mod.editCostFields({ cost: '1' }), 'label_source'), false, 'source undefined: key absent');
same(mod.editCostFields({ cost: '19.25', box: 'custom', dims: '' }).box_dims, '', 'Custom left blank clears the size — never posts "custom"');

// ── errorField: ShipTracker's own validation messages ──────────────────
same(mod.errorField('The label cost must be an amount of 0 or more, for example 18.40.'), 'cost', 'label cost message → cost field');
same(mod.errorField('The box size must be three sizes in inches such as 10x10x10, or the name of a standard box.'), 'box', 'box size message → box field');
same(mod.errorField('The box weight must be a number of pounds above 0, for example 11.'), 'weight', 'box weight message → weight field');
same(mod.errorField('  The label cost must be an amount of 0 or more, for example 18.40.'), 'cost', 'leading space tolerated');
same(mod.errorField('This tracking number is already on this order.'), '', 'duplicate tracking is not a cost field error');
same(mod.errorField('Nothing to save: send label_cost, box_weight, box_dims or label_source.'), '', 'a message that only names the fields is not about one of them');
same(mod.errorField('Permission denied.'), '', 'permission error → no field');
same(mod.errorField(''), '', 'empty → no field');
same(mod.errorField(undefined), '', 'undefined → no field');

// ── responseError ──────────────────────────────────────────────────────
same(mod.responseError({ success: false, data: 'The label cost must be an amount of 0 or more, for example 18.40.' }, 'x'),
  'The label cost must be an amount of 0 or more, for example 18.40.', 'string data is the message');
same(mod.responseError({ success: false, data: { message: 'Nope.' } }, 'x'), 'Nope.', 'data.message is the message');
same(mod.responseError({ success: false, data: {} }, 'fallback'), 'fallback', 'no message → fallback');
same(mod.responseError({ success: false }, 'fallback'), 'fallback', 'no data → fallback');
same(mod.responseError(null, 'fallback'), 'fallback', 'no response → fallback');
same(mod.responseError(0, 'fallback'), 'fallback', 'admin-ajax "0" → fallback');

// ── remembered label source ────────────────────────────────────────────
function fakeStorage() {
  var data = {};
  return {
    getItem: function (k) { return Object.prototype.hasOwnProperty.call(data, k) ? data[k] : null; },
    setItem: function (k, v) { data[k] = String(v); }
  };
}
var blocked = {
  getItem: function () { throw new Error('blocked'); },
  setItem: function () { throw new Error('blocked'); }
};
var options = ['', 'Partner A', 'Partner B'];
var store = fakeStorage();

same(mod.rememberedSource(store, options), '', 'nothing remembered yet');
same(mod.rememberSource(store, 'Partner B'), true, 'a used source is stored');
same(store.getItem(mod.SOURCE_KEY), 'Partner B', 'under the documented key');
same(mod.rememberedSource(store, options), 'Partner B', 'and offered next time');
same(mod.rememberSource(store, ''), false, 'saving without a source keeps the last one');
same(mod.rememberedSource(store, options), 'Partner B', 'still the last one used');
same(mod.rememberSource(store, ' Partner A '), true, 'a newer source replaces it');
same(mod.rememberedSource(store, options), 'Partner A', 'the last one used wins');
same(mod.rememberedSource(store, ['', 'Partner B']), '', 'a source no longer on the list is not offered');
same(mod.rememberedSource(store, []), '', 'no options: nothing offered');
same(mod.rememberedSource(null, options), '', 'no storage: nothing offered');
same(mod.rememberedSource(blocked, options), '', 'blocked storage reads as nothing');
same(mod.rememberSource(blocked, 'Partner A'), false, 'blocked storage does not throw on save');
same(mod.rememberSource(null, 'Partner A'), false, 'no storage does not throw on save');

console.log('admin-shipments: OK (' + checks + ' checks — untouched form posts nothing new, remembered source never records itself, cleared vs left alone, ShipTracker messages land on their field)');
