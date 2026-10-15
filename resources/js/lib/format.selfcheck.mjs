/**
 * Node selfcheck for the compact rupiah of the report summary cards (`formatRupiahCompact`).
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

const { formatRupiahCompact } = await import('./format.ts');

// Intl separates "Rp" and the number with a no-break space; the card text is compared with plain spaces.
const compact = (...args) => formatRupiahCompact(...args).replace(/\s/g, ' ');

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
