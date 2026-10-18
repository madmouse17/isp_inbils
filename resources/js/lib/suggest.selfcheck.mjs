import assert from 'node:assert/strict';
import test from 'node:test';

const {
    addDays,
    haversineKm,
    lowestFreeDropPort,
    lowestLoad,
    nearestLocation,
    parentPrefixOf,
    parseCidr,
    toGeoPoint,
} = await import('./suggest.ts');

test('haversine matches a known distance', () => {
    const jakarta = { lat: -6.2088, lng: 106.8456 };
    const bandung = { lat: -6.9175, lng: 107.6191 };

    assert.ok(Math.abs(haversineKm(jakarta, bandung) - 116) < 2);
    assert.equal(haversineKm(jakarta, jakarta), 0);
});

test('toGeoPoint refuses blanks, text and out-of-range values', () => {
    assert.deepEqual(toGeoPoint('-6.2', '106.8'), { lat: -6.2, lng: 106.8 });
    assert.deepEqual(toGeoPoint(0, 0), { lat: 0, lng: 0 });
    assert.equal(toGeoPoint('', '106.8'), null);
    assert.equal(toGeoPoint(null, 1), null);
    assert.equal(toGeoPoint('abc', '1'), null);
    assert.equal(toGeoPoint('91', '1'), null);
});

test('nearestLocation skips locations without coordinates and keeps the first of a tie', () => {
    const from = { lat: 0, lng: 0 };
    const near = { name: 'near', lat: 0, lng: 1 };
    const twin = { name: 'twin', lat: 1, lng: 0 };

    assert.equal(nearestLocation([{ name: 'none', lat: null, lng: null }], from), null);

    const result = nearestLocation(
        [{ name: 'none', lat: null, lng: null }, { name: 'far', lat: 10, lng: 10 }, near, twin],
        from,
    );

    assert.equal(result?.location.name, 'near');
    assert.ok(Math.abs((result?.km ?? 0) - 111.19) < 0.1);
});

test('lowestFreeDropPort needs one ODP and takes the first port of it', () => {
    const a1 = { value: '1', label: 'ODP-A · 1:8 · port 1' };
    const a2 = { value: '2', label: 'ODP-A · 1:8 · port 2' };
    const b1 = { value: '3', label: 'ODP-B · 1:8 · port 1' };

    assert.equal(lowestFreeDropPort(undefined), null);
    assert.equal(lowestFreeDropPort([]), null);
    assert.equal(lowestFreeDropPort([a1, a2]), a1);
    assert.equal(lowestFreeDropPort([a1, b1]), null);
});

test('lowestLoad takes the fewest open SPK and the first of a tie', () => {
    const people = [
        { name: 'a', open_today: 3 },
        { name: 'b', open_today: 1 },
        { name: 'c', open_today: 1 },
    ];

    assert.equal(lowestLoad(people)?.name, 'b');
    assert.equal(lowestLoad([]), null);
});

test('addDays crosses months and refuses invalid days', () => {
    assert.equal(addDays('2026-10-18', 14), '2026-11-01');
    assert.equal(addDays('2026-12-25', 14), '2027-01-08');
    assert.equal(addDays('2028-02-28', 1), '2028-02-29');
    assert.equal(addDays('2026-02-30', 1), null);
    assert.equal(addDays('', 1), null);
    assert.equal(addDays('2026-10-18', Number.NaN), null);
});

test('parseCidr reads IPv4 and IPv6 and rejects the rest', () => {
    assert.equal(parseCidr('10.20.0.0/22')?.family, 4);
    assert.equal(parseCidr('2001:db8::/48')?.family, 6);
    assert.equal(parseCidr('10.20.0.0'), null);
    assert.equal(parseCidr('10.20.0/22'), null);
    assert.equal(parseCidr('10.20.0.256/22'), null);
    assert.equal(parseCidr('10.20.0.0/33'), null);
    assert.equal(parseCidr('2001:db8:::1/64'), null);
    assert.equal(parseCidr('1:2:3:4:5:6:7:8:9/64'), null);
    assert.equal(parseCidr('1:2:3:4:5:6:7:8/64')?.family, 6);
});

test('parentPrefixOf picks the deepest strictly containing prefix of the same family', () => {
    const rows = [
        { cidr: '10.0.0.0/8', vlan: 'wide' },
        { cidr: '10.20.0.0/16', vlan: 'mid' },
        { cidr: '10.20.4.0/24', vlan: 'other' },
        { cidr: '2001:db8::/32', vlan: 'v6' },
    ];

    assert.equal(parentPrefixOf('10.20.0.0/22', rows)?.vlan, 'mid');
    assert.equal(parentPrefixOf('10.20.4.0/26', rows)?.vlan, 'other');
    assert.equal(parentPrefixOf('10.20.0.0/16', rows)?.vlan, 'wide');
    assert.equal(parentPrefixOf('172.16.0.0/12', rows), null);
    assert.equal(parentPrefixOf('2001:db8:1::/48', rows)?.vlan, 'v6');
    assert.equal(parentPrefixOf('2001:db9::/48', rows), null);
    assert.equal(parentPrefixOf('10.20', rows), null);
});
