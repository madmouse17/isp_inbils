/**
 * Node selfcheck for the number formats of the web UI: rupiah (cents only when the stored value has them),
 * the compact rupiah of the report summary cards, percent and decimal, and the typed money of `MoneyInput`.
 * Run: node --test resources/js/lib/format.selfcheck.mjs
 *
 * Expected values are literals, never computed with the code under test.
 */
import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import test from 'node:test';

// The libs import each other without extensions, the way Vite resolves them; teach Node the .ts.
registerHooks({
    resolve: (specifier, context, next) =>
        next(/^\.\.?\/[^.]+$/.test(specifier) ? `${specifier}.ts` : specifier, context),
});

const { formatDecimal, formatPercent, formatRupiah, formatRupiahCompact, typedRupiah } =
    await import('./format.ts');

// Intl separates "Rp" and the number with a no-break space; the texts are compared with plain spaces.
const plain = (text) => text.replace(/\s/g, ' ');
const compact = (...args) => plain(formatRupiahCompact(...args));
const rupiah = (...args) => plain(formatRupiah(...args));
const typed = (raw, company) => {
    const { value, text } = typedRupiah(raw, company);

    return [value, plain(text)];
};

test('thousands, millions, billions and trillions read in the Indonesian short scale', () => {
    assert.equal(compact(950), 'Rp 950');
    assert.equal(compact(950_000), 'Rp 950 rb');
    assert.equal(compact(1_200_000), 'Rp 1,2 jt');
    assert.equal(compact(3_400_000_000), 'Rp 3,4 M');
    assert.equal(compact(1_500_000_000_000), 'Rp 1,5 T');
});

test('one decimal at most, and a whole number loses its decimal', () => {
    assert.equal(compact(1_000_000), 'Rp 1 jt');
    assert.equal(compact(9_549_000), 'Rp 9,5 jt');
    assert.equal(compact('48535038.82'), 'Rp 48,5 jt');
});

test('zero, a decimal string from the API and a figure that is not a number', () => {
    assert.equal(compact(0), 'Rp 0');
    assert.equal(compact('9549000.00'), 'Rp 9,5 jt');
    assert.equal(compact('abc'), 'Rp 0');
});

test('a debt keeps its minus sign and another currency uses its own short form', () => {
    assert.equal(compact(-1_250_000), '-Rp 1,3 jt');
    assert.equal(compact(1_200_000, { currency: 'USD' }), '$1.2M');
});

test('rupiah shows cents only when the stored value has them, and never rounds a stored amount', () => {
    assert.equal(rupiah(147702), 'Rp 147.702');
    assert.equal(rupiah('147702.00'), 'Rp 147.702');
    assert.equal(rupiah('147701.62'), 'Rp 147.701,62');
    assert.equal(rupiah('147701.50'), 'Rp 147.701,50');
    assert.equal(rupiah(0.5), 'Rp 0,50');
    assert.equal(rupiah(-1234.5), '-Rp 1.234,50');
    assert.equal(rupiah(0), 'Rp 0');
});

test('a sum that is whole up to float noise stays whole', () => {
    // 147701.62 + 147701.38 is 295403.00000000006 in floating point.
    assert.equal(rupiah(147701.62 + 147701.38), 'Rp 295.403');
});

test('a figure that is not a number reads as zero; another currency keeps its two decimals', () => {
    assert.equal(rupiah('abc'), 'Rp 0');
    assert.equal(rupiah(1234, { currency: 'USD' }), '$1,234.00');
    assert.equal(rupiah(1234.5, { currency: 'USD' }), '$1,234.50');
});

test('percent and decimal use the Indonesian comma and trim trailing zeros', () => {
    assert.equal(formatPercent(63.6), '63,6%');
    assert.equal(formatPercent(63.64), '63,6%');
    assert.equal(formatPercent('63.60'), '63,6%');
    assert.equal(formatPercent(50), '50%');
    assert.equal(formatPercent(0), '0%');
    assert.equal(formatPercent(12.346, 2), '12,35%');
    assert.equal(formatDecimal(4.8), '4,8');
    assert.equal(formatDecimal(4), '4');
    assert.equal(formatDecimal(4.84), '4,8');
    assert.equal(formatDecimal(1234.5), '1.234,5');
    assert.equal(formatDecimal(-4.8), '-4,8');
    assert.equal(formatDecimal(-0.04), '0');
});

test('no value, a blank or text that is not a number print a dash, never 0', () => {
    for (const missing of [null, undefined, '', '  ', 'abc', Number.NaN]) {
        assert.equal(formatPercent(missing), '-', String(missing));
        assert.equal(formatDecimal(missing), '-', String(missing));
    }
});

test('typed money: dots group thousands, a comma starts the cents, two cents at most', () => {
    assert.deepEqual(typed('275000'), [275000, 'Rp 275.000']);
    assert.deepEqual(typed('Rp 275.000'), [275000, 'Rp 275.000']);
    assert.deepEqual(typed('275000,5'), [275000.5, 'Rp 275.000,5']);
    assert.deepEqual(typed('275000,50'), [275000.5, 'Rp 275.000,50']);
    assert.deepEqual(typed('Rp 147.701,'), [147701, 'Rp 147.701,']);
    assert.deepEqual(typed(',5'), [0.5, 'Rp 0,5']);
    assert.deepEqual(typed('007'), [7, 'Rp 7']);
    assert.deepEqual(typed('a1b2'), [12, 'Rp 12']);
});

test('typed money with nothing to read is no amount', () => {
    for (const raw of ['', 'Rp', ',', 'abc']) assert.deepEqual(typed(raw), [null, ''], raw);
});

test('editing an amount that has cents never moves its comma', () => {
    // The field shows "Rp 99.999,99". A third cent is dropped, a deleted digit is only deleted.
    assert.deepEqual(typed('Rp 99.999,995'), [99999.99, 'Rp 99.999,99']);
    assert.deepEqual(typed('Rp 99.999,9'), [99999.9, 'Rp 99.999,9']);
    assert.deepEqual(typed('Rp 99.999,'), [99999, 'Rp 99.999,']);
    assert.deepEqual(typed('Rp 9.999,99'), [9999.99, 'Rp 9.999,99']);
    assert.deepEqual(typed('Rp 199.999,99'), [199999.99, 'Rp 199.999,99']);
});

test('a dot is always a thousands mark in rupiah, so a backspace on a grouped number stays whole', () => {
    assert.deepEqual(typed('Rp 1.23'), [123, 'Rp 123']);
    assert.deepEqual(typed('12.34.56'), [123456, 'Rp 123.456']);
    assert.deepEqual(typed('275000.'), [275000, 'Rp 275.000']);
});

test('a column holds 13 whole digits; typing more is ignored', () => {
    assert.deepEqual(typed('99999999999999999'), [9999999999999, 'Rp 9.999.999.999.999']);
});

test('another currency reads the other way round', () => {
    const usd = { currency: 'USD' };
    assert.deepEqual(typed('$1,234.5', usd), [1234.5, '$1,234.5']);
    assert.deepEqual(typed('1,234.', usd), [1234, '$1,234.']);
});

test('formatting an amount and typing that text back gives the same amount and text', () => {
    for (const amount of [
        0, 5, 950, 275000, 147702, 147701.62, 99999.99, 1234567.5, 9999999999999.99,
    ]) {
        const text = formatRupiah(amount);
        assert.deepEqual(typedRupiah(text), { value: amount, text }, String(amount));
    }

    const usd = { currency: 'USD' };
    for (const amount of [0, 1234, 1234.5]) {
        const text = formatRupiah(amount, usd);
        assert.deepEqual(typedRupiah(text, usd), { value: amount, text }, `USD ${amount}`);
    }
});
