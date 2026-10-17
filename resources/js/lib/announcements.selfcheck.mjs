/**
 * Node selfcheck for `lib/announcements`: the banner ratio rule, the carousel order and the company clock.
 * Run: node --test resources/js/lib/announcements.selfcheck.mjs
 *
 * Expected values are literals. The ratio cases are the edges of `SavePromoBannerRequest::bannerRatio`
 * (`width * 10 < height * 19` or `width * 10 > height * 21` fails), checked by hand.
 */
import assert from 'node:assert/strict';
import test from 'node:test';

const { fieldError, isBannerRatio, nowInZone, placeInCarousel } = await import('./announcements.ts');

test('a banner is 2:1 within 5 percent, edges included', () => {
    for (const [width, height] of [
        [1200, 600],
        [2, 1],
        [1900, 1000],
        [2100, 1000],
        [1000, 500],
    ]) {
        assert.equal(isBannerRatio(width, height), true, `${width}x${height}`);
    }
    for (const [width, height] of [
        [1899, 1000],
        [2101, 1000],
        [1000, 600],
        [600, 600],
        [600, 1200],
        [1200, 0],
        [0, 0],
    ]) {
        assert.equal(isBannerRatio(width, height), false, `${width}x${height}`);
    }
});

const slot = (id, sortOrder) => ({ id, sortOrder });

test('the carousel lists by sort order, then newest first, with an unsaved draft the newest', () => {
    const live = [slot(7, 0), slot(5, 0), slot(9, 2)];

    const first = placeInCarousel(live, slot(null, 0), 5);
    assert.deepEqual(
        first.banners.map((entry) => entry.id),
        [null, 7, 5, 9],
    );
    assert.equal(first.index, 0);

    const edited = placeInCarousel(live, slot(6, 0), 5);
    assert.deepEqual(
        edited.banners.map((entry) => entry.id),
        [7, 6, 5, 9],
    );
    assert.equal(edited.index, 1);

    const last = placeInCarousel(live, slot(null, 3), 5);
    assert.equal(last.index, 3);
});

test('a banner past the cut is not in the carousel and has no slide', () => {
    const live = [slot(1, 0), slot(2, 1), slot(3, 2), slot(4, 3), slot(5, 4)];
    const result = placeInCarousel(live, slot(null, 9), 5);

    assert.deepEqual(
        result.banners.map((entry) => entry.id),
        [1, 2, 3, 4, 5],
    );
    assert.equal(result.index, null);

    const inside = placeInCarousel(live, slot(null, 0), 5);
    assert.deepEqual(
        inside.banners.map((entry) => entry.id),
        [null, 1, 2, 3, 4],
    );
    assert.equal(inside.index, 0);
});

test('placing a draft leaves the live list alone', () => {
    const live = [slot(2, 1), slot(1, 0)];
    placeInCarousel(live, slot(null, 0), 5);

    assert.deepEqual(live, [slot(2, 1), slot(1, 0)]);
});

test('the company clock is the wall time of its zone, to the minute', () => {
    const instant = new Date('2026-10-15T18:30:45Z');

    assert.equal(nowInZone('Asia/Jakarta', instant), '2026-10-16 01:30');
    assert.equal(nowInZone('Asia/Jayapura', instant), '2026-10-16 03:30');
    assert.equal(nowInZone('America/Los_Angeles', instant), '2026-10-15 11:30');
    assert.equal(nowInZone('UTC', instant), '2026-10-15 18:30');
});

test('midnight reads 00, never 24, and a zone the browser does not know falls back to its own', () => {
    assert.equal(nowInZone('UTC', new Date('2026-10-16T00:05:00Z')), '2026-10-16 00:05');
    assert.match(nowInZone('Mars/Olympus', new Date('2026-10-16T00:05:00Z')), /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/);
    assert.match(nowInZone(null), /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/);
});

test('a list field takes the message of the field or of any of its items', () => {
    const errors = {
        audience: 'Pilih minimal satu lokasi atau kategori paket.',
        'location_ids.1': 'Lokasi tidak valid.',
    };

    assert.equal(fieldError(errors, 'audience'), 'Pilih minimal satu lokasi atau kategori paket.');
    assert.equal(fieldError(errors, 'location_ids'), 'Lokasi tidak valid.');
    assert.equal(fieldError({ location_ids: 'Wajib.', 'location_ids.0': 'Lain.' }, 'location_ids'), 'Wajib.');
    assert.equal(fieldError(errors, 'service_package_category_ids'), undefined);
    // A longer field name that only starts the same is another field.
    assert.equal(fieldError({ location_ids_extra: 'x' }, 'location_ids'), undefined);
});
