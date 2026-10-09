/**
 * ICS line-folding check for the medication calculator + QT scheduler.
 *
 * Run: `node tests/*.test.js` (or `node tests/calculator-ics-fold.test.js`)
 * from the theme root. No dependencies, exits non-zero on failure.
 *
 * Both scripts are browser IIFEs that only register a DOMContentLoaded
 * handler at load, so a stub `document` is enough to require() them and pull
 * the Node-only `module.exports` they expose.
 *
 * Asserts, for the real 289 gal Cu+Metro Day-1 description (the audit's
 * mojibake repro) and then every tank size 3..500:
 *   - no physical line exceeds 75 octets (RFC 5545 §3.1)
 *   - continuation lines start with a single space
 *   - unfolding gives back the exact input (no multi-byte char was split)
 *
 * @package fishotel-theme
 */
'use strict';

var assert = require('assert');
var path = require('path');

global.window = global.window || {};
global.document = global.document || { addEventListener: function () {} };

var ROOT = path.join(__dirname, '..');
var calc = require(path.join(ROOT, 'assets/js/calculator/fishotel-calculator.js'));
var sched = require(path.join(ROOT, 'assets/js/scheduler/qt-scheduler.js'));

assert.strictEqual(typeof calc.icsFold, 'function', 'calculator must export icsFold');
assert.strictEqual(typeof sched.icsFold, 'function', 'scheduler must export icsFold');

// Mirrors generateCopperMetro() Day 1 (standard 5-day ramp, 2.5 ppm, Copper
// Power 0.692 ml/gal/ppm, metro 18.75 mg/gal) after stripHtml + the
// '• ' bullet join the scheduler's handleIcs() applies.
function cuMetroDay1Description(tankGal) {
  var cuMl = (tankGal * 0.692 * (2.5 / 5)).toFixed(1) + ' ml';
  var metroMg = tankGal * 18.75;
  var metro = metroMg >= 1000 ? (metroMg / 1000).toFixed(2) + ' g'
            : metroMg >= 100 ? Math.round(metroMg) + ' mg'
            : metroMg.toFixed(1) + ' mg';
  return [
    '• Bare-bottom QT. Carbon, Purigen, UV all OFF. Lights dimmed during metro dosing.',
    '• Dose Copper Power to 0.50 ppm — ' + cuMl,
    '• Dose Metronidazole — ' + metro + ' (≈18.75 mg/gal, range 12.5–25)'
  ].join('\n');
}

function checkFolded(label, fold, escape, raw) {
  var line = 'DESCRIPTION:' + escape(raw);
  var folded = fold(line);
  var physical = folded.split('\r\n');

  physical.forEach(function (p, i) {
    var octets = Buffer.byteLength(p, 'utf8');
    assert.ok(octets <= 75, label + ': physical line ' + (i + 1) + ' is ' + octets + ' octets: ' + JSON.stringify(p));
    if (i > 0) assert.strictEqual(p.charAt(0), ' ', label + ': continuation line ' + (i + 1) + ' must start with a space');
  });

  var unfolded = folded.replace(/\r\n /g, '');
  assert.strictEqual(unfolded, line, label + ': unfolding must restore the exact input');
  assert.strictEqual(folded.indexOf('�'), -1, label + ': replacement char means a multi-byte char was split');
  // Every multi-byte char from the input survives in the output verbatim.
  ['•', '—', '≈', '–'].forEach(function (ch) {
    if (raw.indexOf(ch) !== -1) assert.ok(folded.indexOf(ch) !== -1, label + ': ' + JSON.stringify(ch) + ' lost');
  });
  return physical.length;
}

var impls = [
  { name: 'calculator', fold: calc.icsFold, escape: calc.icsEscape },
  { name: 'scheduler',  fold: sched.icsFold, escape: sched.icsEscape }
];

impls.forEach(function (impl) {
  // The audit's exact repro.
  var lines289 = checkFolded(impl.name + ' 289 gal', impl.fold, impl.escape, cuMetroDay1Description(289));
  assert.ok(lines289 >= 2, impl.name + ': 289 gal description must actually need folding');

  // Then every tank size the slider allows.
  for (var gal = 3; gal <= 500; gal++) {
    checkFolded(impl.name + ' ' + gal + ' gal', impl.fold, impl.escape, cuMetroDay1Description(gal));
  }

  // Edge cases: short line untouched, surrogate pair kept intact, empty ok.
  assert.strictEqual(impl.fold('SUMMARY:short'), 'SUMMARY:short', impl.name + ': short lines must not change');
  var emoji = 'SUMMARY:' + new Array(70).join('a') + '🐟' + 'zz'; // 🐟 at the boundary
  var ef = impl.fold(emoji).split('\r\n');
  ef.forEach(function (p) { assert.ok(Buffer.byteLength(p, 'utf8') <= 75, impl.name + ': emoji line too long'); });
  assert.strictEqual(impl.fold(emoji).replace(/\r\n /g, ''), emoji, impl.name + ': surrogate pair must not split');
  assert.strictEqual(impl.fold(''), '', impl.name + ': empty input');
});

console.log('calculator-ics-fold: OK (' + impls.length + ' implementations, tank sizes 3-500, 289 gal repro)');
