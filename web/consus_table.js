/*
 * Consus: filter- en sorteerlogica van de tabel "Verbruik per jaar".
 * Puur (geen DOM), zodat tests/consus_table_test.js het met node kan testen.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.ConsusTable = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /**
     * Getal uit een celwaarde. Leeg, null en onleesbaar tellen als 0.
     * Tekst in Nederlandse notatie wordt ook gelezen: "1.234,5", "0,00",
     * "1.234" (duizendtallen) en "-12,5".
     */
    function toNumber(value) {
        if (value === null || value === undefined || value === false) { return 0; }
        if (typeof value === 'number') { return isFinite(value) ? value : 0; }
        var text = String(value).replace(/[\s\u00a0\u202f]/g, '').replace(/\u2212/g, '-');
        if (text === '') { return 0; }
        if (text.indexOf(',') !== -1) {
            text = text.replace(/\./g, '').replace(',', '.');
        } else if (/^-?\d{1,3}(\.\d{3})+$/.test(text)) {
            text = text.replace(/\./g, '');
        }
        var number = Number(text);
        return isFinite(number) ? number : 0;
    }

    /** 0 op twee decimalen (dus ook 0,00 en -0,001). */
    function isZero(value) {
        return Math.round(Math.abs(toNumber(value)) * 100) === 0;
    }

    /** Alle numerieke kolommen (voorraad, verbruik, maanden, kwartalen, ...) 0. */
    function isZeroRow(values) {
        if (!values || typeof values.length !== 'number') { return true; }
        for (var index = 0; index < values.length; index++) {
            if (!isZero(values[index])) { return false; }
        }
        return true;
    }

    function compareText(left, right) {
        return String(left === null || left === undefined ? '' : left)
            .localeCompare(String(right === null || right === undefined ? '' : right), 'nl', { numeric: true, sensitivity: 'base' });
    }

    function valuesFor(article, year) {
        return (article && article.values && article.values[year]) || [];
    }

    /**
     * Volgorde van twee artikelen. col null = standaard (artikelnummer, dan
     * bedrijf); col 0 = artikelnummer, col 1 = omschrijving (tekst);
     * col n >= 2 = (n-1)-de getal van het jaar.
     * Bij gelijke waarden artikelnummer en bedrijf, in dezelfde richting.
     */
    function compareArticles(left, right, col, dir, year) {
        var result = 0;
        if (col === null || col === undefined) {
            result = compareText(left.item, right.item);
            if (result === 0) { result = compareText(left.company, right.company); }
            return result;
        }
        if (col === 0) {
            result = compareText(left.item, right.item);
        } else if (col === 1) {
            result = compareText(left.description, right.description);
        } else {
            var leftNumber = toNumber(valuesFor(left, year)[col - 2]);
            var rightNumber = toNumber(valuesFor(right, year)[col - 2]);
            result = leftNumber < rightNumber ? -1 : (leftNumber > rightNumber ? 1 : 0);
        }
        if (result === 0) { result = compareText(left.item, right.item); }
        if (result === 0) { result = compareText(left.company, right.company); }
        return dir < 0 ? -result : result;
    }

    /**
     * Zoekterm (hoofdletterongevoelig) in artikelnummer of omschrijving.
     * Een lege term past op alles.
     */
    function matchesQuery(article, query) {
        var needle = String(query === null || query === undefined ? '' : query).trim().toLowerCase();
        if (needle === '') { return true; }
        var item = String((article && article.item) || '').toLowerCase();
        var description = String((article && article.description) || '').toLowerCase();
        return item.indexOf(needle) !== -1 || description.indexOf(needle) !== -1;
    }

    /**
     * Zichtbare artikelen (indexen in articles), gesorteerd, en het aantal
     * verborgen nul-regels voor dit jaar.
     */
    function visibleIndexes(articles, options) {
        var year = String(options.year);
        var hideZero = !!options.hideZero;
        var indexes = [];
        var hidden = 0;
        var matched = 0;
        (articles || []).forEach(function (article, index) {
            if (!matchesQuery(article, options.query)) { return; }
            matched++;
            if (hideZero && isZeroRow(valuesFor(article, year))) {
                hidden++;
                return;
            }
            indexes.push(index);
        });
        indexes.sort(function (leftIndex, rightIndex) {
            var result = compareArticles(articles[leftIndex], articles[rightIndex], options.col, options.dir, year);
            return result !== 0 ? result : leftIndex - rightIndex;
        });
        return { indexes: indexes, hidden: hidden, matched: matched };
    }

    /** Volgende sorteerstand na een klik op kolom col. */
    function nextSort(current, col) {
        if (current && current.col === col) {
            return { col: col, dir: current.dir < 0 ? 1 : -1 };
        }
        return { col: col, dir: 1 };
    }

    function hiddenLabel(count) {
        if (!count) { return ''; }
        return count + (count === 1 ? ' nul-regel verborgen' : ' nul-regels verborgen');
    }

    return {
        toNumber: toNumber,
        isZero: isZero,
        isZeroRow: isZeroRow,
        compareText: compareText,
        compareArticles: compareArticles,
        matchesQuery: matchesQuery,
        visibleIndexes: visibleIndexes,
        nextSort: nextSort,
        hiddenLabel: hiddenLabel
    };
}));
