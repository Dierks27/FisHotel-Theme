/**
 * The Medication Dosing calculator and the QT Scheduler are educational
 * pages since v1.29.0: they name medications but never link to or embed a
 * product (no "Shop … at FisHotel", no buy links, no fallback to the
 * Medications shop).
 *
 * Run: `node tests/med-listings-tools.test.js` from the theme root. No
 * dependencies, exits non-zero on failure.
 *
 * Checks, with a window.FISHOTEL_MED_LINKS payload deliberately present
 * (an old cached page could still carry one):
 *   - the scheduler's medLink() returns a plain <span>, never an <a>
 *   - "Meds for this protocol" renders names in spans, no anchors, no href
 *   - neither script reads FISHOTEL_MED_LINKS or knows the shop URL
 *   - the calculator has no "Shop … at FisHotel" / "Browse medications" copy
 *
 * @package fishotel-theme
 */
'use strict';

var assert = require('assert');
var path = require('path');
var fs = require('fs');

var ROOT = path.join(__dirname, '..');
var seed = JSON.parse(fs.readFileSync(path.join(ROOT, 'inc/calculator/medication-data-seed.json'), 'utf8'));
var seedMeds = Array.isArray(seed) ? seed : seed.medications;

// Tiny DOM: enough for el() / appendChild / setAttribute.
function Node(tag) {
  this.tagName = String(tag).toUpperCase();
  this.className = '';
  this.textContent = '';
  this.children = [];
  this.attrs = {};
  this.href = undefined;
}
Node.prototype.appendChild = function (c) { this.children.push(c); return c; };
Node.prototype.setAttribute = function (k, v) { this.attrs[k] = String(v); };
function walk(node, fn) { fn(node); node.children.forEach(function (c) { walk(c, fn); }); }

global.window = global.window || {};
global.document = { addEventListener: function () {}, createElement: function (t) { return new Node(t); } };
global.window.FISHOTEL_MEDS = { medications: seedMeds };
global.window.FISHOTEL_MED_LINKS = {
  links: { praziquantel: { url: 'https://example.test/product/test-prazi/', name: 'Test Prazi Powder', source: 'anchor', in_stock: true, mode: 'ea' } },
  fallback_url: 'https://example.test/product-category/medications/'
};

var sched = require(path.join(ROOT, 'assets/js/scheduler/qt-scheduler.js'));
var failures = 0;
function check(label, fn) {
  try { fn(); console.log('  ok   ' + label); }
  catch (e) { failures++; console.log('  FAIL ' + label + ' — ' + e.message); }
}

console.log('scheduler');
check('medLink() is a plain span with the medication name', function () {
  var html = sched.medLink('praziquantel');
  assert.strictEqual(html, '<span class="fst-med-name">Praziquantel</span>');
});
check('medLink() never links, for every known id and an unknown one', function () {
  ['copper_power', 'formalin_37', 'metronidazole', 'praziquantel', 'chloroquine_phosphate', 'something_new'].forEach(function (id) {
    var html = sched.medLink(id);
    assert.ok(!/<a\b|href=/i.test(html), id + ': ' + html);
  });
});
check('medLink() escapes the display name', function () {
  assert.strictEqual(sched.medLink('x', '<b>'), '<span class="fst-med-name">&lt;b&gt;</span>');
});
check('"Meds for this protocol" lists names only — no anchor, no href', function () {
  var block = sched.renderMedsBlock([
    { med_id: 'praziquantel', name: 'Praziquantel' },
    { med_id: 'copper_power', name: 'Copper Power' }
  ], null);
  var names = [];
  walk(block, function (n) {
    assert.notStrictEqual(n.tagName, 'A', 'an <a> was rendered');
    assert.strictEqual(n.href, undefined, 'an href was set');
    if (n.className === 'fst-med-name') names.push(n.textContent);
  });
  assert.deepStrictEqual(names, ['Praziquantel', 'Copper Power']);
});

console.log('source');
var schedSrc = fs.readFileSync(path.join(ROOT, 'assets/js/scheduler/qt-scheduler.js'), 'utf8');
var calcSrc = fs.readFileSync(path.join(ROOT, 'assets/js/calculator/fishotel-calculator.js'), 'utf8');
check('neither script reads window.FISHOTEL_MED_LINKS', function () {
  assert.ok(!/window\.FISHOTEL_MED_LINKS/.test(schedSrc), 'scheduler');
  assert.ok(!/window\.FISHOTEL_MED_LINKS/.test(calcSrc), 'calculator');
});
check('neither script knows the Medications shop URL', function () {
  assert.ok(schedSrc.indexOf('/product-category/') === -1, 'scheduler');
  assert.ok(calcSrc.indexOf('/product-category/') === -1, 'calculator');
});
check('calculator has no Shop / Browse product copy', function () {
  assert.ok(!/at FisHotel/.test(calcSrc), '"at FisHotel"');
  assert.ok(!/Browse medications/.test(calcSrc), '"Browse medications"');
  assert.ok(!/renderShopLink/.test(calcSrc), 'renderShopLink');
});

if (failures) {
  console.log('\n' + failures + ' FAILED');
  process.exit(1);
}
console.log('\nAll tool-page checks passed.');
