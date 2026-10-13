/**
 * Assert-based self-check for useMapDefault pure helpers.
 * Run: node resources/js/hooks/mapDefault.helpers.test.mjs
 */
import assert from 'node:assert/strict';
import { INDONESIA_MAP_DEFAULT, resolveMapDefault } from './mapDefault.helpers.ts';

// No company, no prop, or a broken prop: all of Indonesia.
assert.deepEqual(resolveMapDefault(undefined), INDONESIA_MAP_DEFAULT);
assert.deepEqual(resolveMapDefault(null), INDONESIA_MAP_DEFAULT);
assert.deepEqual(resolveMapDefault({}), INDONESIA_MAP_DEFAULT);
assert.deepEqual(resolveMapDefault({ lat: -7.5579, lng: 110.8231 }), INDONESIA_MAP_DEFAULT);
assert.deepEqual(
    resolveMapDefault({ lat: Number.NaN, lng: 110.8231, zoom: 12 }),
    INDONESIA_MAP_DEFAULT,
);
assert.deepEqual(
    resolveMapDefault({ lat: '-7.5', lng: 110.8231, zoom: 12 }),
    INDONESIA_MAP_DEFAULT,
);

// A resolved company map wins, label fields are ignored.
assert.deepEqual(
    resolveMapDefault({
        lat: -7.5579,
        lng: 110.8231,
        zoom: 12,
        city_code: '3372',
        city_name: 'KOTA SURAKARTA',
    }),
    { center: { lat: -7.5579, lng: 110.8231 }, zoom: 12 },
);

// Zero is a real coordinate, not a missing one.
assert.deepEqual(resolveMapDefault({ lat: 0, lng: 0, zoom: 3 }), {
    center: { lat: 0, lng: 0 },
    zoom: 3,
});

console.log('mapDefault.helpers.test.mjs: all assertions passed');
