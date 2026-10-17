/**
 * Medication-store modal mode helpers (C2 read-only "Read the terms").
 *
 * Run: `node tests/med-terms.test.js` from the theme root. No dependencies,
 * exits non-zero on failure.
 *
 * assets/js/medication-store.js exports two pure helpers:
 *   modalPlan( cfg, mode, hasCookie )  → { mode, autoShow, locked }
 *   modalAction( mode, action )        → { close, setCookie, redirect }
 *
 * The checkout modal is rendered with data-mode="readonly". It must never
 * auto-show, never set the acceptance cookie and never route Decline to
 * home — every exit path just closes it. The archive / PDP gate keeps its
 * locked behaviour.
 *
 * @package fishotel-theme
 */
'use strict';

var assert = require('assert');
var path = require('path');

// The module must load without a DOM (window/document undefined) and
// without fishotelMedData.
var mod = require(path.join(__dirname, '..', 'assets/js/medication-store.js'));
assert.strictEqual(typeof mod.modalPlan, 'function', 'modalPlan exported');
assert.strictEqual(typeof mod.modalAction, 'function', 'modalAction exported');

// ── modalPlan ──────────────────────────────────────────────────────────
var checkoutCfg = { isArchive: false, isMedPdp: false, isCheckout: true };
var archiveCfg  = { isArchive: true,  isMedPdp: false, isCheckout: false };
var pdpCfg      = { isArchive: false, isMedPdp: true,  isCheckout: false };

assert.deepStrictEqual(mod.modalPlan(checkoutCfg, 'readonly', false), { mode: 'readonly', autoShow: false, locked: false }, 'readonly never auto-shows');
assert.deepStrictEqual(mod.modalPlan(checkoutCfg, 'readonly', true),  { mode: 'readonly', autoShow: false, locked: false }, 'readonly ignores the cookie');
assert.deepStrictEqual(mod.modalPlan(archiveCfg,  'readonly', false), { mode: 'readonly', autoShow: false, locked: false }, 'readonly wins even on an archive cfg');

assert.deepStrictEqual(mod.modalPlan(archiveCfg, 'gate', false), { mode: 'gate', autoShow: true,  locked: true }, 'archive without cookie gates');
assert.deepStrictEqual(mod.modalPlan(pdpCfg,     'gate', false), { mode: 'gate', autoShow: true,  locked: true }, 'med PDP without cookie gates');
assert.deepStrictEqual(mod.modalPlan(archiveCfg, 'gate', true),  { mode: 'gate', autoShow: false, locked: true }, 'cookie suppresses the gate');
assert.deepStrictEqual(mod.modalPlan(checkoutCfg, 'gate', false), { mode: 'gate', autoShow: false, locked: true }, 'gate markup on a non-gated page stays hidden');
assert.deepStrictEqual(mod.modalPlan(undefined, undefined, false), { mode: 'gate', autoShow: false, locked: true }, 'missing cfg/mode defaults to a hidden gate');
assert.deepStrictEqual(mod.modalPlan(archiveCfg, 'bogus', false), { mode: 'gate', autoShow: true, locked: true }, 'unknown mode is treated as gate');

// ── modalAction: read-only ─────────────────────────────────────────────
var justClose = { close: true, setCookie: false, redirect: false };
['agree', 'decline', 'close', 'escape', 'backdrop', 'anything'].forEach(function (action) {
  assert.deepStrictEqual(mod.modalAction('readonly', action), justClose, 'readonly ' + action + ' just closes');
});

// ── modalAction: locked gate ───────────────────────────────────────────
assert.deepStrictEqual(mod.modalAction('gate', 'agree'),   { close: true,  setCookie: true,  redirect: false }, 'gate agree sets cookie + closes');
assert.deepStrictEqual(mod.modalAction('gate', 'decline'), { close: false, setCookie: false, redirect: true },  'gate decline redirects, no cookie');
['escape', 'backdrop', 'close'].forEach(function (action) {
  assert.deepStrictEqual(mod.modalAction('gate', action), { close: false, setCookie: false, redirect: false }, 'gate ' + action + ' is a no-op (locked)');
});

// Read-only mode must never produce a cookie or a redirect, whatever the action.
['agree', 'decline', 'escape', 'backdrop'].forEach(function (action) {
  var r = mod.modalAction('readonly', action);
  assert.strictEqual(r.setCookie, false, 'readonly never sets the cookie (' + action + ')');
  assert.strictEqual(r.redirect, false, 'readonly never redirects (' + action + ')');
});

console.log('med-terms: OK (read-only modal never auto-shows, never sets the cookie, never redirects; gate unchanged)');
