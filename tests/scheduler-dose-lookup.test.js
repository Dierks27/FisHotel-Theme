/**
 * QT scheduler dose lookup check (audit theme-tools-6).
 *
 * Run: `node tests/*.test.js` (or `node tests/scheduler-dose-lookup.test.js`)
 * from the theme root. No dependencies, exits non-zero on failure.
 *
 * formalinMl / metroDoseMg / praziDoseMg used to hard-code 1 ml/10 gal,
 * 18.75 mg/gal and 9.5 mg/gal. They now read dose_pure_low/high_mg_per_gal
 * from window.FISHOTEL_MEDS (the same seed the calculator is fed) and keep
 * those literals only as fallbacks. This asserts, with the real seed:
 *   - every displayed number and label is byte-identical to the old literals
 *     for every tank size the slider allows (3..500 gal)
 *   - a changed seed value is picked up (the lookup is live, not decorative)
 *   - a missing med / missing fields fall back to the literals, never to 0
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
assert.ok(Array.isArray(seedMeds) && seedMeds.length, 'seed JSON must carry a medications array');

// The scheduler reads window.FISHOTEL_MEDS at call time, so swapping the
// global between assertions exercises the fallback paths without a reload.
global.window = global.window || {};
global.document = global.document || { addEventListener: function () {} };
global.window.FISHOTEL_MEDS = { medications: seedMeds };

var sched = require(path.join(ROOT, 'assets/js/scheduler/qt-scheduler.js'));
['formalinMl', 'formalinLabel', 'metroDoseMg', 'metroLabel', 'praziDoseMg', 'praziLabel'].forEach(function (fn) {
  assert.strictEqual(typeof sched[fn], 'function', 'scheduler must export ' + fn);
});

// Mirrors formatMl / formatDoseMg in qt-scheduler.js — what the customer sees.
function formatMl(ml) { return ml.toFixed(1) + ' ml'; }
function formatDoseMg(mg) {
  if (mg >= 1000) return (mg / 1000).toFixed(2) + ' g';
  if (mg >= 100)  return Math.round(mg) + ' mg';
  return mg.toFixed(1) + ' mg';
}

// 1. Real seed: identical to the pre-lookup literals for every tank size.
for (var gal = 3; gal <= 500; gal++) {
  assert.strictEqual(formatMl(sched.formalinMl(gal)), formatMl(gal / 10), 'formalin ' + gal + ' gal');
  assert.strictEqual(formatDoseMg(sched.metroDoseMg(gal)), formatDoseMg(gal * 18.75), 'metro ' + gal + ' gal');
  assert.strictEqual(formatDoseMg(sched.praziDoseMg(gal)), formatDoseMg(gal * 9.5), 'prazi ' + gal + ' gal');
}
assert.strictEqual(sched.formalinLabel(), '(1 ml per 10 gal)');
assert.strictEqual(sched.metroLabel(), '(≈18.75 mg/gal, range 12.5–25)');
assert.strictEqual(sched.praziLabel(), '(9.5 mg/gal)');

// 2. Changed seed: the lookup is live.
function withMeds(meds, fn) {
  var prev = global.window.FISHOTEL_MEDS;
  global.window.FISHOTEL_MEDS = meds;
  try { fn(); } finally { global.window.FISHOTEL_MEDS = prev; }
}
withMeds({ medications: [
  { med_id: 'metronidazole', dose_pure_low_mg_per_gal: 10, dose_pure_high_mg_per_gal: 20 },
  { med_id: 'praziquantel',  dose_pure_low_mg_per_gal: 8,  dose_pure_high_mg_per_gal: 8 },
  { med_id: 'formalin_37',   dose_pure_low_mg_per_gal: 0.2, dose_pure_high_mg_per_gal: 0.2, dose_unit_override: 'ml/gal' }
] }, function () {
  assert.strictEqual(sched.metroDoseMg(10), 150, 'metro follows a changed seed midpoint');
  assert.strictEqual(sched.metroLabel(), '(≈15 mg/gal, range 10–20)');
  assert.strictEqual(sched.praziDoseMg(10), 80, 'prazi follows a changed seed');
  assert.strictEqual(sched.praziLabel(), '(8 mg/gal)');
  assert.strictEqual(formatMl(sched.formalinMl(10)), '2.0 ml', 'formalin follows a changed seed');
  assert.strictEqual(sched.formalinLabel(), '(2 ml per 10 gal)');
});

// 3. Missing med, missing fields, wrong formalin unit, no data at all → literals, never 0.
function assertLiterals(label) {
  assert.strictEqual(sched.metroDoseMg(10), 187.5, label + ': metro fallback');
  assert.strictEqual(sched.praziDoseMg(10), 95, label + ': prazi fallback');
  assert.strictEqual(formatMl(sched.formalinMl(10)), '1.0 ml', label + ': formalin fallback');
  assert.strictEqual(sched.metroLabel(), '(≈18.75 mg/gal, range 12.5–25)', label + ': metro label fallback');
  assert.strictEqual(sched.praziLabel(), '(9.5 mg/gal)', label + ': prazi label fallback');
  assert.strictEqual(sched.formalinLabel(), '(1 ml per 10 gal)', label + ': formalin label fallback');
}
withMeds({ medications: [] }, function () { assertLiterals('empty medications'); });
withMeds(undefined, function () { assertLiterals('no FISHOTEL_MEDS'); });
withMeds({ medications: [
  { med_id: 'metronidazole' },
  { med_id: 'praziquantel', dose_pure_low_mg_per_gal: null, dose_pure_high_mg_per_gal: 0 },
  { med_id: 'formalin_37', dose_pure_low_mg_per_gal: 5, dose_pure_high_mg_per_gal: 5 } // mg/gal, not ml/gal → ignored
] }, function () { assertLiterals('fields missing'); });

// One-sided range: the present bound is used for both ends.
withMeds({ medications: [ { med_id: 'metronidazole', dose_pure_high_mg_per_gal: 30 } ] }, function () {
  assert.strictEqual(sched.metroDoseMg(1), 30, 'single bound becomes the dose');
  assert.strictEqual(sched.metroLabel(), '(≈30 mg/gal, range 30–30)');
});

console.log('scheduler-dose-lookup: OK (seed matches literals for 3-500 gal; live lookup + fallbacks verified)');
