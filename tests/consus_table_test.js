// node tests/consus_table_test.js
'use strict';
var assert = require('assert');
var T = require('../web/consus_table.js');

// Getallen, ook in Nederlandse notatie; leeg/null = 0.
assert.strictEqual(T.toNumber(null), 0);
assert.strictEqual(T.toNumber(undefined), 0);
assert.strictEqual(T.toNumber(''), 0);
assert.strictEqual(T.toNumber('0,00'), 0);
assert.strictEqual(T.toNumber('1.234,5'), 1234.5);
assert.strictEqual(T.toNumber('1.234'), 1234);
assert.strictEqual(T.toNumber('-12,5'), -12.5);
assert.strictEqual(T.toNumber('\u221212'), -12);
assert.strictEqual(T.toNumber('1 234'), 1234);
assert.strictEqual(T.toNumber(2.5), 2.5);
assert.strictEqual(T.toNumber('abc'), 0);

// Nul-regels: alle numerieke kolommen 0.
assert.ok(T.isZeroRow([0, 0, '0,00', null, '', -0.001]));
assert.ok(T.isZeroRow([]));
assert.ok(T.isZeroRow(undefined));
assert.ok(!T.isZeroRow([0, 0, 0, 1]));
assert.ok(!T.isZeroRow([0, -2]));
assert.ok(!T.isZeroRow([5, 0, 0]), 'alleen voorraad is geen nul-regel');
assert.ok(!T.isZeroRow([0, 0, '0,01']));

var articles = [
    { item: 'b-10', company: '', values: { 2026: [0, 0, 0], 2025: [0, 3, 1] } },
    { item: 'A-2', company: '', values: { 2026: [5, 1.5, 0] } },
    { item: 'a-10', company: '', values: { 2026: [0, 12, 0] } },
    { item: 'Ä-1', company: '', values: { 2026: [0, 0, 0] } },
    { item: 'c', company: '', values: { 2026: [1, '1.000,5', 0] } }
];
function items(result) { return result.indexes.map(function (i) { return articles[i].item; }); }

// Standaard: artikelnummer, nl en hoofdletterongevoelig, numeriek binnen tekst.
var all = T.visibleIndexes(articles, { year: 2026, hideZero: false, col: null, dir: 1 });
assert.deepStrictEqual(items(all), ['Ä-1', 'A-2', 'a-10', 'b-10', 'c']);
assert.strictEqual(all.hidden, 0);

// Filter aan: nul-regels weg en geteld, per jaar.
var filtered = T.visibleIndexes(articles, { year: 2026, hideZero: true, col: null, dir: 1 });
assert.deepStrictEqual(items(filtered), ['A-2', 'a-10', 'c']);
assert.strictEqual(filtered.hidden, 2);
var otherYear = T.visibleIndexes(articles, { year: '2025', hideZero: true, col: null, dir: 1 });
assert.deepStrictEqual(items(otherYear), ['b-10'], 'zonder data voor het jaar is het een nul-regel');
assert.strictEqual(otherYear.hidden, 4);

// Numeriek sorteren (kolom 2 = tweede getal), met filter, beide richtingen.
var asc = T.visibleIndexes(articles, { year: 2026, hideZero: true, col: 2, dir: 1 });
assert.deepStrictEqual(items(asc), ['A-2', 'a-10', 'c']);
var desc = T.visibleIndexes(articles, { year: 2026, hideZero: true, col: 2, dir: -1 });
assert.deepStrictEqual(items(desc), ['c', 'a-10', 'A-2']);
// Gelijke waarden: op artikelnummer.
var byFirst = T.visibleIndexes(articles, { year: 2026, hideZero: false, col: 1, dir: 1 });
assert.deepStrictEqual(items(byFirst), ['Ä-1', 'a-10', 'b-10', 'c', 'A-2']);
// Tekstkolom aflopend.
var textDesc = T.visibleIndexes(articles, { year: 2026, hideZero: false, col: 0, dir: -1 });
assert.deepStrictEqual(items(textDesc), ['c', 'b-10', 'a-10', 'A-2', 'Ä-1']);

// Klikken: nieuwe kolom oplopend, zelfde kolom keert om.
assert.deepStrictEqual(T.nextSort(null, 3), { col: 3, dir: 1 });
assert.deepStrictEqual(T.nextSort({ col: 3, dir: 1 }, 3), { col: 3, dir: -1 });
assert.deepStrictEqual(T.nextSort({ col: 3, dir: -1 }, 3), { col: 3, dir: 1 });
assert.deepStrictEqual(T.nextSort({ col: 3, dir: -1 }, 0), { col: 0, dir: 1 });

assert.strictEqual(T.hiddenLabel(0), '');
assert.strictEqual(T.hiddenLabel(1), '1 nul-regel verborgen');
assert.strictEqual(T.hiddenLabel(12), '12 nul-regels verborgen');

console.log('OK');
