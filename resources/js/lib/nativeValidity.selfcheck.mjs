/**
 * Node selfcheck for the Indonesian text of native validity bubbles (`lib/nativeValidity.ts`).
 * Run: node --test resources/js/lib/nativeValidity.selfcheck.mjs
 *
 * Expected texts are literals, never computed with the code under test.
 */
import assert from 'node:assert/strict';
import test from 'node:test';

const { validityMessage } = await import('./nativeValidity.ts');

test('each constraint has its own Indonesian text', () => {
    assert.equal(validityMessage({ validity: { valueMissing: true } }), 'Wajib diisi.');
    assert.equal(validityMessage({ validity: { typeMismatch: true } }), 'Format tidak sesuai.');
    assert.equal(validityMessage({ validity: { patternMismatch: true } }), 'Format tidak sesuai.');
    assert.equal(
        validityMessage({ validity: { rangeOverflow: true }, max: '9' }),
        'Nilai paling besar 9.',
    );
    assert.equal(
        validityMessage({ validity: { rangeUnderflow: true }, min: '3' }),
        'Nilai paling kecil 3.',
    );
    assert.equal(
        validityMessage({ validity: { tooShort: true }, minLength: 4 }),
        'Paling sedikit 4 karakter.',
    );
    assert.equal(
        validityMessage({ validity: { tooLong: true }, maxLength: 8 }),
        'Paling banyak 8 karakter.',
    );
    assert.equal(validityMessage({ validity: { badInput: true } }), 'Nilai tidak sesuai.');
});

test('a missing value is reported before any other constraint', () => {
    assert.equal(
        validityMessage({ validity: { valueMissing: true, typeMismatch: true, tooShort: true } }),
        'Wajib diisi.',
    );
});

test('a field that breaks nothing the module knows, or only a custom rule, gets no text', () => {
    assert.equal(validityMessage({ validity: {} }), null);
    assert.equal(validityMessage({ validity: { customError: true } }), null);
});
