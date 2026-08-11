/**
 * Assert-based self-check for LatLngMapPicker pure helpers.
 * Run: node resources/js/Components/composite/latLngMapPicker.helpers.test.mjs
 */
import assert from 'node:assert/strict';
import {
    formatCoord,
    parseCoord,
    resolveCenter,
    toLatLngStrings,
    JAKARTA_CENTER,
} from './latLngMapPicker.helpers.ts';

// RED target: coords must emit decimal(10,7) strings
assert.equal(formatCoord(-6.2088), '-6.2088000');
assert.equal(formatCoord(106.8456), '106.8456000');
assert.equal(formatCoord(0), '0.0000000');

assert.equal(parseCoord(''), null);
assert.equal(parseCoord(null), null);
assert.equal(parseCoord(undefined), null);
assert.equal(parseCoord('not-a-number'), null);
assert.equal(parseCoord('-6.2088'), -6.2088);
assert.equal(parseCoord('106.8456'), 106.8456);

// Empty/invalid falls back to Jakarta
assert.deepEqual(resolveCenter('', ''), JAKARTA_CENTER);
assert.deepEqual(resolveCenter(null, null), JAKARTA_CENTER);
assert.deepEqual(resolveCenter('bad', '1'), JAKARTA_CENTER);
assert.deepEqual(resolveCenter('-6.2', '106.8'), { lat: -6.2, lng: 106.8 });

const custom = { lat: 1, lng: 2 };
assert.deepEqual(resolveCenter('', '', custom), custom);

assert.deepEqual(toLatLngStrings(-6.2088, 106.8456), {
    lat: '-6.2088000',
    lng: '106.8456000',
});

console.log('latLngMapPicker.helpers.test.mjs: all assertions passed');
