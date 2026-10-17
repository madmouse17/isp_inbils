/**
 * Node selfcheck for `lib/contrast`: white-text contrast of a brand colour.
 * Run: node --test resources/js/lib/contrast.selfcheck.mjs
 *
 * Expected ratios are literals from the WCAG formula (checked with the antislop contrast tool).
 */
import assert from 'node:assert/strict';
import test from 'node:test';

const { MIN_TEXT_CONTRAST, contrastWithWhite, isHexColor } = await import('./contrast.ts');

test('black and white are the two ends of the scale', () => {
    assert.equal(contrastWithWhite('#000000'), 21);
    assert.equal(contrastWithWhite('#FFFFFF'), 1);
});

test('the preset swatches land where the WCAG formula puts them', () => {
    const expected = {
        '#2563EB': 5.17,
        '#0F766E': 5.47,
        '#7C3AED': 5.7,
        '#DB2777': 4.6,
        '#EA580C': 3.56,
        '#16A34A': 3.3,
        '#0891B2': 3.68,
        '#334155': 10.35,
    };
    for (const [hex, ratio] of Object.entries(expected)) {
        assert.ok(
            Math.abs(contrastWithWhite(hex) - ratio) < 0.05,
            `${hex}: ${contrastWithWhite(hex)}`,
        );
    }
});

test('the floor is 4.5 and lower case reads like upper case', () => {
    assert.equal(MIN_TEXT_CONTRAST, 4.5);
    assert.equal(contrastWithWhite('#2563eb'), contrastWithWhite('#2563EB'));
});

test('anything but a full #RRGGBB has no ratio', () => {
    for (const value of ['', '#fff', '2563EB', '#2563E', '#2563EBB', '#GGGGGG', 'red']) {
        assert.equal(isHexColor(value), false, value);
        assert.equal(contrastWithWhite(value), null, value);
    }
});
