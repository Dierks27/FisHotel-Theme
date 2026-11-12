/**
 * Shipments meta box — packing block and "how it went out" helpers.
 *
 * Run: `node tests/admin-shipments-packing.test.js` from the theme root. No
 * dependencies, exits non-zero on failure.
 *
 * assets/js/admin-shipments.js exports its pure helpers:
 *   toHundredths / qtyText / toRate / toCents / centsText / rateText / lineCents
 *   supplyForRole( supplies, role, boxName )
 *   usualFor( defaults, boxName )        → ShipTracker's usual packing for a box
 *   recordRows( record )                 → a recorded packing as (touched) rows
 *   packingRows( { supplies, usual, boxName, fish, prev } )
 *   packingTotal( rows, foam, paper )    → whole cents
 *   packingPayload( rows, foam, paper )  → the `materials` to send
 *   packingIsEmpty( payload )
 *   costText( row, currency ) / stockText( supply ) / sumFish( values )
 *   weatherNotes( { action, label, rows, supplies } )
 *   shipModeFields( { mode, withOrder, materials } )
 *   sharesRecordedBox( found, orderId ) / sharedBoxQuestion( orderIds )
 *   errorField( message )                → also 'foam' | 'paper' | 'packing'
 *
 * The rules they pin down: a box picked fills the block with what that box
 * usually takes; changing the box (or the items ticked) re-fills only the
 * lines nobody touched; a custom box starts with foam and paper at 0 and no
 * box supply; a recorded packing is kept as recorded; the running total is
 * ShipTracker's own sum (quantity × unit cost, half up, per line); a line
 * at 0 is not sent and a quantity that is not a number is sent as typed;
 * the weather note says when a pack was added because of the weather check;
 * a tracking number that is already a box with its packing recorded is
 * asked about first, and its record is not replaced.
 *
 * @package fishotel-theme
 */
'use strict';

var assert = require('assert');
var path = require('path');

// The module must load without a DOM and without jQuery.
var mod = require(path.join(__dirname, '..', 'assets/js/admin-shipments.js'));
['toHundredths', 'qtyText', 'toRate', 'toCents', 'centsText', 'rateText', 'lineCents', 'supplyForRole', 'usualFor', 'recordRows',
  'packingRows', 'packingTotal', 'packingPayload', 'packingIsEmpty', 'costText', 'stockText', 'sumFish', 'weatherNotes',
  'shipModeFields', 'sharesRecordedBox', 'sharedBoxQuestion', 'errorField'].forEach(function (name) {
  assert.strictEqual(typeof mod[name], 'function', name + ' exported');
});

var checks = 0;
function same(actual, expected, label) {
  assert.deepStrictEqual(actual, expected, label);
  checks++;
}
/** The rows of a block as "id:qty" (a trailing * marks a touched line). */
function brief(rows) {
  return rows.map(function (row) { return row.id + ':' + row.qty + (row.touched ? '*' : ''); });
}

// ── What ShipTracker hands over ────────────────────────────────────────
var supplies = [
  { id: 1, name: 'Heat pack', unit: 'each', role: 'heat_pack', box_name: '', price_each: '0.6000', on_hand: '7', low: true, recount_needed: false },
  { id: 2, name: 'Ice pack', unit: 'each', role: 'ice_pack', box_name: '', price_each: '0.7500', on_hand: '24', low: false, recount_needed: false },
  { id: 3, name: 'Fish bag', unit: 'each', role: 'fish_bag', box_name: '', price_each: '0.1100', on_hand: '500', low: false, recount_needed: false },
  { id: 4, name: 'Foam sheet', unit: 'sheets', role: 'foam', box_name: '', price_each: '12.0000', on_hand: null, low: false, recount_needed: false },
  { id: 5, name: 'Packing paper', unit: 'feet', role: 'paper', box_name: '', price_each: '0.0500', on_hand: null, low: false, recount_needed: false },
  { id: 6, name: 'Small box', unit: 'each', role: 'box', box_name: 'Small', price_each: '1.2500', on_hand: '0', low: true, recount_needed: true },
  { id: 7, name: 'Large box', unit: 'each', role: 'box', box_name: 'Large', price_each: '2.2000', on_hand: '10', low: false, recount_needed: false },
  { id: 8, name: 'Tape', unit: 'rolls', role: '', box_name: '', price_each: null, on_hand: null, low: false, recount_needed: false }
];
function line(id, qty, unitCost) { return { supply_id: id, qty: qty, unit_cost: unitCost }; }
// The usual packing for 6 fish when the weather check says "heat_pack".
var heat = {
  boxes: [
    { name: 'Small', dims: '10x10x10', materials: { lines: [line(6, '1', '1.2500'), line(3, '6', '0.1100'), line(1, '1', '0.6000')], foam_cost: '1.80', paper_cost: '0.40' } },
    { name: 'Large', dims: '16x16x16', materials: { lines: [line(7, '1', '2.2000'), line(3, '6', '0.1100'), line(1, '1', '0.6000')], foam_cost: '3.10', paper_cost: '0.60' } },
    { name: 'Tall', dims: '10x10x10', materials: { lines: [line(3, '6', '0.1100'), line(1, '1', '0.6000')], foam_cost: '2.00', paper_cost: '0.50' } }
  ],
  custom: { lines: [line(3, '6', '0.1100'), line(1, '1', '0.6000')], foam_cost: '0.00', paper_cost: '0.00' }
};
// … and when it says "cooling": the box's own number of ice packs, none for a custom size.
var cooling = {
  boxes: [{ name: 'Small', dims: '10x10x10', materials: { lines: [line(6, '1', '1.2500'), line(3, '6', '0.1100'), line(2, '2', '0.7500')], foam_cost: '1.80', paper_cost: '0.40' } }],
  custom: { lines: [line(3, '6', '0.1100')], foam_cost: '0.00', paper_cost: '0.00' }
};
// … and with no weather data on the order.
var plain = {
  boxes: [{ name: 'Small', dims: '10x10x10', materials: { lines: [line(6, '1', '1.2500'), line(3, '6', '0.1100')], foam_cost: '1.80', paper_cost: '0.40' } }],
  custom: { lines: [line(3, '6', '0.1100')], foam_cost: '0.00', paper_cost: '0.00' }
};

// ── Numbers ────────────────────────────────────────────────────────────
same(mod.toHundredths('6'), 600, 'a whole quantity');
same(mod.toHundredths(' 2.5 '), 250, 'a half, trimmed');
same(mod.toHundredths('0.05'), 5, 'two decimals');
same(mod.toHundredths('.5'), 50, 'no leading zero');
same(mod.toHundredths('5.'), 500, 'a trailing point');
same(mod.toHundredths(''), 0, 'empty is 0');
same(mod.toHundredths(undefined), 0, 'a missing input is 0');
same(mod.toHundredths('1.005'), null, 'three decimals is a slip, not 1.01');
same(mod.toHundredths('-1'), null, 'negative is not a quantity');
same(mod.toHundredths('two'), null, 'text is not a quantity');
same(mod.toHundredths('.'), null, 'a lone point is not a quantity');
same(mod.toHundredths('1000000'), null, 'a million is out of range');
same(mod.qtyText(600), '6', '600 → "6"');
same(mod.qtyText(250), '2.5', '250 → "2.5"');
same(mod.qtyText(5), '0.05', '5 → "0.05"');
same(mod.qtyText(125), '1.25', '125 → "1.25"');
same(mod.qtyText(0), '0', '0 → "0"');
same(mod.toRate('0.1100'), 1100, 'a unit cost in ten-thousandths');
same(mod.toRate('12'), 120000, 'a whole unit cost');
same(mod.toRate(null), 0, 'no price yet is 0');
same(mod.toRate('abc'), 0, 'not a unit cost is 0');
same(mod.toCents('1.80'), 180, 'an amount');
same(mod.toCents('$1,018.4'), 101840, '"$" and thousands separators tolerated');
same(mod.toCents('1.005'), 101, 'a third decimal rounds half up');
same(mod.toCents('1.004'), 100, '… or down');
same(mod.toCents('19.99'), 1999, 'no float drift');
same(mod.toCents(''), 0, 'empty is 0');
same(mod.toCents('$'), null, '"$" alone is not an amount');
same(mod.toCents('-2'), null, 'negative is not an amount');
same(mod.toCents('free'), null, 'text is not an amount');
same(mod.centsText(610), '6.10', '610 → "6.10"');
same(mod.centsText(5), '0.05', '5 → "0.05"');
same(mod.centsText(0), '0.00', '0 → "0.00"');
same(mod.rateText('0.1100'), '0.11', 'a unit cost reads with two decimals');
same(mod.rateText('0.1319'), '0.1319', '… or four when it has them');
same(mod.rateText('2.2000'), '2.20', 'trailing zeros beyond two are dropped');
same(mod.lineCents(600, 1100), 66, '6 × $0.11 = $0.66');
same(mod.lineCents(600, 1319), 79, '6 × $0.1319 = $0.7914 → $0.79');
same(mod.lineCents(100, 1250), 13, '1 × $0.125 rounds half up to $0.13');
same(mod.lineCents(250, 7500), 188, '2.5 × $0.75 = $1.875 → $1.88');
same(mod.lineCents(0, 1100), 0, 'nothing of it costs nothing');
same(mod.sumFish(['2', '3', '1']), 600, 'the fish of the ticked lines');
same(mod.sumFish(['2.5', '', 'x', undefined]), 250, 'what cannot be read counts as none');
same(mod.sumFish([]), 0, 'nothing ticked');

// ── Supplies and the usual packing ─────────────────────────────────────
same(mod.supplyForRole(supplies, 'fish_bag').id, 3, 'the fish bag');
same(mod.supplyForRole(supplies, 'box', 'Small').id, 6, 'the box supply of the Small box');
same(mod.supplyForRole(supplies, 'box', 'large').id, 7, 'box names match without regard to case');
same(mod.supplyForRole(supplies, 'box', 'Tall'), null, 'a standard box without a supply of its own');
same(mod.supplyForRole(supplies, 'box', ''), null, 'no box, no box supply');
same(mod.supplyForRole([], 'heat_pack'), null, 'nothing saved yet');
same(mod.usualFor(heat, 'Small').foam_cost, '1.80', 'the usual of the Small box');
same(mod.usualFor(heat, 'Tall').foam_cost, '2.00', 'two boxes of one size: the one picked by name');
same(mod.usualFor(heat, ' large ').foam_cost, '3.10', 'name trimmed, any case');
same(mod.usualFor(heat, '').foam_cost, '0.00', 'a custom size: foam starts at 0');
same(mod.usualFor(heat, '').paper_cost, '0.00', '… and paper');
same(mod.usualFor(heat, 'Gone').foam_cost, '0.00', 'a box no longer saved is packed like a custom one');
same(mod.usualFor(null, 'Small'), null, 'nothing from ShipTracker: nothing usual');

// ── packingRows: a box is picked ───────────────────────────────────────
var rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Small'), boxName: 'Small', fish: 600 });
same(brief(rows), ['6:1', '3:6', '1:1', '2:0'], 'Small box: the box itself, a bag per fish, the heat pack — and ice packs at 0');
same(rows[0].name + ' / ' + rows[0].unit + ' / ' + rows[0].rate, 'Small box / each / 1.2500', 'name, unit and unit cost come with the line');
same(mod.packingTotal(rows, '1.80', '0.40'), 471, 'running total: 1.25 + 0.66 + 0.60 + foam 1.80 + paper 0.40 = 4.71');

rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, ''), boxName: '', fish: 600 });
same(brief(rows), ['3:6', '1:1', '2:0'], 'a custom box: no box supply');
same(mod.packingTotal(rows, mod.usualFor(heat, '').foam_cost, mod.usualFor(heat, '').paper_cost), 126, '… and foam and paper at 0: 0.66 + 0.60');

rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(cooling, 'Small'), boxName: 'Small', fish: 600 });
same(brief(rows), ['6:1', '3:6', '1:0', '2:2'], 'weather says cooling: the box\'s ice packs, no heat pack');
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(cooling, ''), boxName: '', fish: 600 });
same(brief(rows), ['3:6', '1:0', '2:0'], 'cooling on a custom box: ice packs are left to be entered');
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(plain, 'Small'), boxName: 'Small', fish: 600 });
same(brief(rows), ['6:1', '3:6', '1:0', '2:0'], 'no weather data: no pack');

// The fish bags follow the items ticked.
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Small'), boxName: 'Small', fish: 300 });
same(brief(rows), ['6:1', '3:3', '1:1', '2:0'], 'three fish ticked: three bags');
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Small'), boxName: 'Small', fish: 250 });
same(brief(rows)[1], '3:2.5', 'a part quantity is kept');
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Small'), boxName: 'Small', fish: null });
same(brief(rows)[1], '3:6', 'no fish count given: ShipTracker\'s own number of bags');
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Small'), boxName: 'Small', fish: 0 });
same(brief(rows)[1], '3:0', 'no fish in it: no bags');

// Changing the box re-fills only what was not touched.
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Small'), boxName: 'Small', fish: 600 });
rows[1].qty = '8'; rows[1].touched = true;                       // the owner used 8 bags
rows.push({ id: 8, name: 'Tape', unit: 'rolls', qty: '1', rate: '', touched: true });   // … and added tape
var large = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Large'), boxName: 'Large', fish: 600, prev: rows });
same(brief(large), ['7:1', '3:8*', '1:1', '2:0', '8:1*'], 'Small → Large: the box line swaps, the 8 bags and the tape stay');
var custom = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, ''), boxName: '', fish: 300, prev: large });
same(brief(custom), ['3:8*', '1:1', '2:0', '8:1*'], 'Large → custom: no box line; ticking fewer fish does not undo the 8 bags');
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Small'), boxName: 'Small', fish: 600 });
rows[0].qty = '2'; rows[0].touched = true;                       // two Small boxes (double boxed)
large = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Large'), boxName: 'Large', fish: 600, prev: rows });
same(brief(large), ['7:1', '3:6', '1:1', '2:0', '6:2*'], 'a touched box line is kept beside the new box');
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Small'), boxName: 'Small', fish: 600 });
rows[2].qty = '0'; rows[2].touched = true;                       // no heat pack after all
same(brief(mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Large'), boxName: 'Large', fish: 600, prev: rows })), ['7:1', '3:6', '1:0*', '2:0'],
  'a line set to 0 stays at 0 through a box change');
same(brief(mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Large'), boxName: 'Large', fish: 600, prev: null })), ['7:1', '3:6', '1:1', '2:0'],
  '"use the usual": nothing kept');

// No supplies saved yet: only the two amounts.
same(mod.packingRows({ supplies: [], usual: { lines: [], foam_cost: '1.80', paper_cost: '0.40' }, boxName: 'Small', fish: 600 }), [], 'no supplies saved: no lines');
same(mod.packingRows(), [], 'nothing at all: no lines');

// ── A recorded packing ─────────────────────────────────────────────────
var record = {
  lines: [
    { supply_id: 6, name: 'Small box', role: 'box', unit: 'each', qty: '1', unit_cost: '1.1000', cost: '1.10' },
    { supply_id: 3, name: 'Fish bag', role: 'fish_bag', unit: 'each', qty: '5', unit_cost: '0.1000', cost: '0.50' },
    { supply_id: 99, name: 'Old liner', role: '', unit: 'each', qty: '1', unit_cost: '0.4000', cost: '0.40' }
  ],
  foam_cost: '1.50', paper_cost: '0.30', total: '3.80'
};
var recorded = mod.recordRows(record);
same(brief(recorded), ['6:1*', '3:5*', '99:1*'], 'every recorded line is kept as recorded');
rows = mod.packingRows({ supplies: supplies, usual: null, boxName: 'Small', prev: recorded });
same(brief(rows), ['6:1*', '3:5*', '1:0', '2:0', '99:1*'], 'in the edit form: the record, plus the pack lines at 0');
same(rows[1].rate, '0.1000', 'a recorded line keeps the unit cost it was recorded at, not today\'s price');
same(rows[4].name + ' / ' + rows[4].rate, 'Old liner / 0.4000', 'a supply since removed from the list keeps its recorded name and cost');
same(mod.packingTotal(rows, record.foam_cost, record.paper_cost), 380, 'the total is the recorded one: 1.10 + 0.50 + 0.40 + 1.50 + 0.30');
rows = mod.packingRows({ supplies: supplies, usual: null, boxName: 'Large', prev: rows });
same(brief(rows), ['7:0', '3:5*', '1:0', '2:0', '6:1*', '99:1*'], 'changing the box of a recorded packing adds nothing by itself');
same(mod.recordRows(null), [], 'no record: no rows');

// ── packingTotal / packingPayload / packingIsEmpty ─────────────────────
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Small'), boxName: 'Small', fish: 600 });
same(mod.packingTotal(rows, '', ''), 251, 'no foam or paper typed: the lines alone');
same(mod.packingTotal(rows, 'abc', '$'), 251, 'amounts that cannot be read count as 0 in the total');
same(mod.packingTotal([{ id: 8, qty: '2', rate: '' }], '0', '0'), 0, 'a supply with no price yet adds nothing');
same(mod.packingTotal([{ id: 3, qty: 'six', rate: '0.1100' }], '0', '0'), 0, 'a quantity that cannot be read adds nothing');
same(mod.packingTotal([], '1.80', '0.40'), 220, 'no lines: foam + paper');

same(mod.packingPayload(rows, ' 1.80 ', '0.40'),
  { lines: [{ supply_id: 6, qty: '1' }, { supply_id: 3, qty: '6' }, { supply_id: 1, qty: '1' }], foam_cost: '1.80', paper_cost: '0.40' },
  'the materials sent: supply id + quantity, foam and paper as typed — the ice pack at 0 is left out');
rows[1].qty = '1.005';
same(mod.packingPayload(rows, '', '').lines[1], { supply_id: 3, qty: '1.005' }, 'a quantity that is not valid is sent as typed, for ShipTracker to refuse by name');
rows[1].qty = '';
same(mod.packingPayload(rows, '', '').lines.length, 2, 'an emptied quantity removes the line');
same(mod.packingPayload(rows, '', ''), { lines: [{ supply_id: 6, qty: '1' }, { supply_id: 1, qty: '1' }], foam_cost: '', paper_cost: '' }, 'emptied amounts are sent empty (ShipTracker reads them as 0.00)');
same(mod.packingPayload([], 'abc', '0.40').foam_cost, 'abc', 'an amount is never cleaned up here — ShipTracker is the one validator');
same(Object.keys(mod.packingPayload(rows, '1', '2').lines[0]), ['supply_id', 'qty'], 'no unit cost is sent: ShipTracker snapshots its own');

same(mod.packingIsEmpty(mod.packingPayload([], '', '')), true, 'no lines, no amounts: nothing to record');
same(mod.packingIsEmpty(mod.packingPayload([{ id: 3, qty: '0' }], '0.00', '0')), true, 'zeros only: nothing to record');
same(mod.packingIsEmpty(mod.packingPayload([{ id: 3, qty: '1' }], '', '')), false, 'a line is something');
same(mod.packingIsEmpty(mod.packingPayload([], '1.80', '')), false, 'a foam amount is something');
same(mod.packingIsEmpty(mod.packingPayload([], '', 'abc')), false, 'an amount that cannot be read is sent, so ShipTracker can say so');
same(mod.packingIsEmpty(null), true, 'no block at all');

// ── costText / stockText ───────────────────────────────────────────────
same(mod.costText({ qty: '6', rate: '0.1100' }, '$'), '× $0.11 = $0.66', 'a line reads "× price = cost"');
same(mod.costText({ qty: '0', rate: '0.7500' }, '$'), '× $0.75', 'at 0 only the price');
same(mod.costText({ qty: '2', rate: '' }, '$'), 'no price yet', 'no purchase logged for the supply');
same(mod.costText({ qty: '0', rate: '' }, '$'), '', 'nothing of a supply with no price');
same(mod.costText({ qty: 'six', rate: '0.1100' }, '$'), '', 'a quantity that cannot be read has no cost');
same(mod.costText({ qty: '1', rate: '2.2000' }, '£'), '× £2.20 = £2.20', 'the shop\'s currency symbol');
same(mod.stockText(supplies[2]), '500 on hand', 'a counted supply shows what is left');
same(mod.stockText(supplies[0]), '7 on hand — time to reorder', 'at or under its reorder level');
same(mod.stockText(supplies[5]), '0 on hand — recount needed', 'the books say less than none');
same(mod.stockText(supplies[7]), '', 'a supply that is not counted shows nothing');
same(mod.stockText(null), '', 'a supply no longer on the list shows nothing');

// ── weatherNotes ───────────────────────────────────────────────────────
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Small'), boxName: 'Small', fish: 600 });
same(mod.weatherNotes({ action: 'heat_pack', label: 'Heat pack', rows: rows, supplies: supplies }), ['Heat pack added: the weather check says Heat pack.'],
  'a heat pack added because of the weather check is said so');
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(cooling, 'Small'), boxName: 'Small', fish: 600 });
same(mod.weatherNotes({ action: 'cooling', label: 'Cooling packs', rows: rows, supplies: supplies }), ['Ice pack added (2): the weather check says Cooling packs.'],
  'ice packs: with how many');
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(cooling, ''), boxName: '', fish: 600 });
same(mod.weatherNotes({ action: 'cooling', label: 'Cooling packs', rows: rows, supplies: supplies }), ['The weather check says Cooling packs — enter the quantity of Ice pack for this box.'],
  'cooling on a box with no usual number of ice packs: asked for');
rows = mod.packingRows({ supplies: supplies, usual: mod.usualFor(heat, 'Small'), boxName: 'Small', fish: 600 });
rows[2].qty = '2'; rows[2].touched = true;
same(mod.weatherNotes({ action: 'heat_pack', label: 'Heat pack', rows: rows, supplies: supplies }), ['The weather check says Heat pack.'],
  'once the owner changed the line the note only says what the check says');
same(mod.weatherNotes({ action: 'heat_pack', label: 'Heat pack', rows: [], supplies: supplies.slice(1) }), ['The weather check says Heat pack, but no supply is set as the heat pack yet.'],
  'no supply plays the heat pack');
same(mod.weatherNotes({ action: 'cooling', label: 'Cooling packs', rows: [], supplies: [] }), ['The weather check says Cooling packs, but no supply is set as the ice pack yet.'],
  'no supply plays the ice pack');
same(mod.weatherNotes({ action: 'none', label: '', rows: rows, supplies: supplies }), [], 'nothing needed: no note');
same(mod.weatherNotes({ action: 'hold_cold', label: '', rows: rows, supplies: supplies }), [], 'an action that packs nothing: no note');
same(mod.weatherNotes({ action: '', label: '', rows: rows, supplies: supplies }), [], 'no weather data: no note');
same(mod.weatherNotes(), [], 'nothing at all: no note');

// ── shipModeFields ─────────────────────────────────────────────────────
same(mod.shipModeFields({ mode: 'local' }), { mode: 'local' }, 'local pickup without a packing block');
same(mod.shipModeFields({ mode: 'local', withOrder: '123', materials: { lines: [{ supply_id: 3, qty: '3' }], foam_cost: '', paper_cost: '' } }),
  { mode: 'local', materials: '{"lines":[{"supply_id":3,"qty":"3"}],"foam_cost":"","paper_cost":""}' },
  'local pickup: its packing as a JSON string, never an order number');
same(mod.shipModeFields({ mode: 'with_order', withOrder: ' #34183 ', materials: { lines: [] } }), { mode: 'with_order', with_order_id: '34183' },
  'went in another order\'s box: the order number without "#", never materials');
same(mod.shipModeFields({ mode: 'with_order', withOrder: '' }), { mode: 'with_order', with_order_id: '' }, 'no number typed: sent empty (the form stops it first)');
same(mod.shipModeFields({ mode: 'label', withOrder: '5', materials: { lines: [] } }), { mode: 'label' }, 'shipped with a label: clears the mark, nothing else');
same(mod.shipModeFields({ mode: 'teleport' }), { mode: 'label' }, 'anything else is "label"');
same(mod.shipModeFields(), { mode: 'label' }, 'nothing picked is "label"');

// ── A tracking number that is already a box ────────────────────────────
same(mod.sharesRecordedBox({ exists: true, order_ids: [100], has_materials: true }, '104'), true, 'another order\'s box with its packing recorded: ask first');
same(mod.sharesRecordedBox({ exists: true, order_ids: ['201', '202'], has_materials: true }, 300), true, 'a fulfillment adding more of its sources\' items to a recorded box: ask first');
same(mod.sharesRecordedBox({ exists: true, order_ids: [100], has_materials: false }, '104'), false, 'that box has no packing recorded: this order\'s packing may be sent');
same(mod.sharesRecordedBox({ exists: false, order_ids: [], has_materials: false }, '104'), false, 'a new tracking number: nothing to ask');
same(mod.sharesRecordedBox({ exists: true, order_ids: [100, 104], has_materials: true }, '104'), false, 'already on this very order: ShipTracker refuses the duplicate, no question');
same(mod.sharesRecordedBox(null, '104'), false, 'the lookup gave nothing: nothing to ask');
same(mod.sharesRecordedBox({ exists: true, has_materials: true }, '104'), true, 'no order list given: still someone else\'s box');
var question = mod.sharedBoxQuestion([100, '102']);
same(question.indexOf('This tracking number is already a box on order #100, #102, and its packing is recorded.') === 0, true, 'the question names the orders the box is on');
same(/The packing on record is left as it is/.test(question) && /Add this order to that box\?$/.test(question), true, 'and says the record is kept');
same(mod.sharedBoxQuestion([]).indexOf('This tracking number is already a box, and its packing is recorded.') === 0, true, 'no orders named when none are known');

// ── errorField: ShipTracker's materials messages ───────────────────────
same(mod.errorField('The foam cost must be an amount of 0 or more, for example 1.80.'), 'foam', 'foam message → foam field');
same(mod.errorField('The paper cost must be an amount of 0 or more, for example 1.80.'), 'paper', 'paper message → paper field');
same(mod.errorField('The quantity of Fish bag must be 0 or more with at most 2 decimals.'), 'packing', 'a quantity → the packing lines');
same(mod.errorField('The unit cost of Fish bag must be an amount of 0 or more, for example 0.60.'), 'packing', 'a unit cost → the packing lines');
same(mod.errorField('Unknown supply: 99. Supplies are listed under Settings > Boxes & label costs.'), 'packing', 'an unknown supply → the packing lines');
same(mod.errorField('Every materials line needs a supply (supply_id or supply).'), 'packing', 'a line without a supply → the packing lines');
same(mod.errorField('materials must hold lines (supply_id or supply, and qty), foam_cost and paper_cost.'), 'packing', 'malformed materials → the packing lines');
same(mod.errorField('The label cost must be an amount of 0 or more, for example 18.40.'), 'cost', 'the label cost message still lands on the label cost');
same(mod.errorField('This order has a label of its own. Remove its tracking first.'), '', 'a ship mode refusal is not a field error');
same(mod.errorField('Order #100 is a local pickup, so it has no box.'), '', '… nor is a refusal of the other order');

console.log('admin-shipments-packing: OK (' + checks + ' checks — a box fills the block, a box change keeps what was touched, custom starts at 0, records stay as recorded, total = ShipTracker\'s sum, 0 lines not sent, weather note, ship mode fields)');
