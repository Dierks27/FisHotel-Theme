/**
 * Compatibility-matrix coverage check.
 *
 * Run: `node tests/*.test.js` (or `node tests/compat-matrix-coverage.test.js`)
 * from the theme root. No dependencies.
 *
 * Cross-checks assets/data/categories.json against assets/data/matrix.json:
 * every unordered category pair (including self-pairs) should have a cell in
 * matrix.json, because matrixCell() in compatibility-guide.js silently
 * returns 'C' (Compatible) for an undefined pair.
 *
 * Exit status:
 *   - 1 when matrix.json references a category key that categories.json does
 *     not define (a typo — the cell can never be reached).
 *   - 0 otherwise, printing a WARNING list of missing pairs. Missing pairs are
 *     deliberately NOT a failure yet: the known gap is the 30 rabbitfish_siganus
 *     pairings Jeff still has to supply verdicts for (audit theme-tools-10).
 *
 * TODO(theme-tools-10): once Jeff fills in the rabbitfish_siganus row, flip
 * `MISSING_IS_FAILURE` to true so any future gap goes red in CI.
 *
 * @package fishotel-theme
 */
'use strict';

var path = require('path');

var MISSING_IS_FAILURE = false;

var ROOT = path.join(__dirname, '..');
var categories = require(path.join(ROOT, 'assets/data/categories.json'));
var matrix = require(path.join(ROOT, 'assets/data/matrix.json'));

var keys = categories.map(function (c) { return c.key; });
var known = {};
keys.forEach(function (k) { known[k] = true; });

// 1. Hard failure: matrix rows/columns that point at unknown categories.
var unknown = [];
Object.keys(matrix).forEach(function (rowKey) {
  if (!known[rowKey]) unknown.push(rowKey + ' (row)');
  Object.keys(matrix[rowKey] || {}).forEach(function (colKey) {
    if (!known[colKey]) unknown.push(rowKey + ' -> ' + colKey + ' (column)');
  });
});

// 2. Coverage: every unordered pair needs a cell in either orientation.
function hasCell(a, b) {
  return !!((matrix[a] && matrix[a][b]) || (matrix[b] && matrix[b][a]));
}
var missing = [];
for (var i = 0; i < keys.length; i++) {
  for (var j = i; j < keys.length; j++) {
    if (!hasCell(keys[i], keys[j])) missing.push(keys[i] + ' x ' + keys[j]);
  }
}

var totalPairs = (keys.length * (keys.length + 1)) / 2;
console.log('compat-matrix-coverage: ' + keys.length + ' categories, ' + totalPairs + ' pairs, '
  + (totalPairs - missing.length) + ' covered, ' + missing.length + ' missing');

if (unknown.length) {
  console.error('FAIL: matrix.json references ' + unknown.length + ' category key(s) not in categories.json:');
  unknown.forEach(function (u) { console.error('  - ' + u); });
  process.exitCode = 1;
}

if (missing.length) {
  var byCat = {};
  missing.forEach(function (m) {
    m.split(' x ').forEach(function (k) { byCat[k] = (byCat[k] || 0) + 1; });
  });
  console.warn('WARNING: ' + missing.length + ' category pair(s) have no matrix cell and silently read as Compatible:');
  missing.forEach(function (m) { console.warn('  - ' + m); });
  console.warn('Per category: ' + Object.keys(byCat).sort(function (a, b) { return byCat[b] - byCat[a]; })
    .map(function (k) { return k + '=' + byCat[k]; }).join(', '));
  console.warn('TODO(theme-tools-10): Jeff to supply verdicts for the rabbitfish_siganus row in assets/data/matrix.json, then set MISSING_IS_FAILURE = true in this test.');
  if (MISSING_IS_FAILURE) process.exitCode = 1;
}

if (!process.exitCode) {
  console.log('compat-matrix-coverage: OK' + (missing.length ? ' (with warnings)' : ''));
}
