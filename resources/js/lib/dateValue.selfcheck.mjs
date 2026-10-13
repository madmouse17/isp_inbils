/**
 * Node selfcheck for the single-value helpers behind the pickers: time, date with time, month.
 * Run: node resources/js/lib/dateValue.selfcheck.mjs   (any TZ: the helpers must not depend on it)
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

const {
    MONTH_NAMES,
    TIME_SLOTS,
    formatDayTime,
    formatMonth,
    isoToMonthDate,
    joinDayTime,
    monthToIso,
    parseDayTime,
    parseMonth,
    parseTime,
    slotAtOrBefore,
    splitDayTime,
} = await import('./dateValue.ts');
const { formatDay, parseDay } = await import('./dateRange.ts');

test('parseTime takes HH:mm as typed or stored and writes it back padded', () => {
    const ok = {
        '08:30': '08:30',
        '8:30': '08:30',
        '08.30': '08:30',
        '00:00': '00:00',
        '23:59': '23:59',
        ' 21:00 ': '21:00',
        '21:00:00': '21:00',
        '7:05:59': '07:05',
    };
    for (const [text, expected] of Object.entries(ok)) {
        assert.equal(parseTime(text), expected, text);
    }
});

test('parseTime rejects anything that is not a time of day', () => {
    for (const bad of [
        '24:00',
        '12:60',
        '8',
        '830',
        '08:3',
        '8:30 PM',
        'abc',
        '08:30:',
        '-1:30',
        '',
        '   ',
        null,
        undefined,
    ]) {
        assert.equal(parseTime(bad), null, String(bad));
    }
});

test('the slot list is every half hour of the day, in order', () => {
    assert.equal(TIME_SLOTS.length, 48);
    assert.deepEqual(TIME_SLOTS.slice(0, 3), ['00:00', '00:30', '01:00']);
    assert.deepEqual(TIME_SLOTS.slice(-2), ['23:00', '23:30']);
    assert.ok(TIME_SLOTS.includes('08:30'));
    assert.ok(TIME_SLOTS.every((slot) => /^\d\d:(00|30)$/.test(slot)));
    assert.deepEqual([...TIME_SLOTS].sort(), [...TIME_SLOTS]);
});

test('a time lands on the slot at or before it', () => {
    assert.equal(slotAtOrBefore('08:15'), '08:00');
    assert.equal(slotAtOrBefore('08:30'), '08:30');
    assert.equal(slotAtOrBefore('08:59'), '08:30');
    assert.equal(slotAtOrBefore('23:59'), '23:30');
    assert.equal(slotAtOrBefore('00:00'), '00:00');
});

test('splitDayTime reads Y-m-d H:i and the shapes a database adds to it', () => {
    const expected = { day: '2026-10-13', time: '08:30' };
    for (const value of [
        '2026-10-13 08:30',
        '2026-10-13 08:30:00',
        '2026-10-13T08:30',
        '2026-10-13T08:30:00',
        '2026-10-13 08:30:00.000000',
    ]) {
        assert.deepEqual(splitDayTime(value), expected, value);
    }
    for (const bad of [
        '2026-02-31 08:30',
        '2026-10-13 24:00',
        '2026-10-13 8:30',
        '2026-10-13',
        '08:30',
        '13/10/2026 08:30',
        '',
        null,
        undefined,
    ]) {
        assert.equal(splitDayTime(bad), null, String(bad));
    }
    assert.equal(joinDayTime('2026-10-13', '08:30'), '2026-10-13 08:30');
});

test('a day with a time is written in the company format, the time last', () => {
    assert.equal(formatDayTime('2026-10-13 08:30', 'dd MMM yyyy'), '13 Okt 2026 08:30');
    assert.equal(formatDayTime('2026-10-13 08:30', 'dd/MM/yyyy'), '13/10/2026 08:30');
    assert.equal(formatDayTime('2026-10-13 21:05', 'yyyy-MM-dd'), '2026-10-13 21:05');
    assert.equal(formatDayTime('2026-08-01T00:00:00', 'd MMMM yyyy'), '1 Agustus 2026 00:00');
    assert.equal(formatDayTime('bukan tanggal', 'dd/MM/yyyy'), '');
    assert.equal(formatDayTime('2026-10-13', 'dd/MM/yyyy'), '');
    assert.equal(formatDayTime(null, 'dd/MM/yyyy'), '');
});

test('typed date with time emits Y-m-d H:i and round-trips', () => {
    const values = ['2026-10-13 08:30', '2026-10-13 00:00', '2028-02-29 23:59', '2025-12-31 12:05'];
    for (const dateFnsFormat of [
        'dd/MM/yyyy',
        'dd MMM yyyy',
        'yyyy-MM-dd',
        'd MMMM yyyy',
        'M/d/yyyy',
        'EEE, dd MMM yyyy',
    ]) {
        for (const value of values) {
            const text = formatDayTime(value, dateFnsFormat);
            assert.equal(parseDayTime(text, dateFnsFormat), value, `${dateFnsFormat}: ${text}`);
        }
    }
    const ok = (text, dateFnsFormat, expected) =>
        assert.equal(parseDayTime(text, dateFnsFormat), expected, text);

    ok('13/10/2026 8:30', 'dd/MM/yyyy', '2026-10-13 08:30');
    ok('13/10/2026   08.30', 'dd/MM/yyyy', '2026-10-13 08:30');
    ok('  13/10/2026 21:05  ', 'dd/MM/yyyy', '2026-10-13 21:05');
    ok('13 oktober 2026 21:05', 'dd MMM yyyy', '2026-10-13 21:05');
    ok('13 Agt 2026 07:00', 'dd MMM yyyy', '2026-08-13 07:00');
});

test('a date with time that is not whole is rejected', () => {
    for (const [text, dateFnsFormat] of [
        ['13/10/2026', 'dd/MM/yyyy'],
        ['08:30', 'dd/MM/yyyy'],
        ['13/10/2026 25:00', 'dd/MM/yyyy'],
        ['13/10/2026 12:60', 'dd/MM/yyyy'],
        ['32/10/2026 08:30', 'dd/MM/yyyy'],
        ['31/02/2026 08:30', 'dd/MM/yyyy'],
        ['13/10/26 08:30', 'dd/MM/yyyy'],
        ['bukan tanggal 08:30', 'dd/MM/yyyy'],
        ['13/10/2026 bukan jam', 'dd/MM/yyyy'],
        ['2026-10-13 08:30', 'dd/MM/yyyy'],
        ['', 'dd/MM/yyyy'],
        ['   ', 'dd/MM/yyyy'],
    ]) {
        assert.equal(parseDayTime(text, dateFnsFormat), null, text);
    }
});

test('single days: formatDay writes the company format, parseDay reads it back', () => {
    assert.equal(formatDay('2026-10-13', 'dd MMM yyyy'), '13 Okt 2026');
    assert.equal(formatDay('2026-08-01', 'dd MMM yyyy'), '01 Agu 2026');
    assert.equal(formatDay('2026-10-13', 'EEEE, d MMMM yyyy'), 'Selasa, 13 Oktober 2026');
    assert.equal(formatDay('2026-02-31', 'dd/MM/yyyy'), '');
    assert.equal(formatDay(null, 'dd/MM/yyyy'), '');
    assert.equal(formatDay(undefined, 'dd/MM/yyyy'), '');

    assert.equal(parseDay('13/10/2026', 'dd/MM/yyyy'), '2026-10-13');
    assert.equal(parseDay('3/10/2026', 'dd/MM/yyyy'), '2026-10-03');
    assert.equal(parseDay('13 Agt 2026', 'dd MMM yyyy'), '2026-08-13');
    assert.equal(parseDay('13 Oktober 2026', 'dd MMM yyyy'), '2026-10-13');
    for (const bad of [
        '',
        'bukan tanggal',
        '31/02/2026',
        '13/10/26',
        '13/10/20266',
        '2026-10-13',
    ]) {
        assert.equal(parseDay(bad, 'dd/MM/yyyy'), null, bad);
    }
});

test('ISO months: four-digit year, month 1 to 12', () => {
    const date = isoToMonthDate('2026-10');
    assert.deepEqual([date.getFullYear(), date.getMonth(), date.getDate()], [2026, 9, 1]);
    assert.equal(monthToIso(2026, 3), '2026-03');
    assert.equal(monthToIso(2026, 12), '2026-12');
    assert.equal(monthToIso(999, 1), '0999-01');
    for (const bad of [
        '2026-13',
        '2026-00',
        '26-10',
        '2026-1',
        '2026-10-01',
        '0999-10',
        '',
        null,
        undefined,
    ]) {
        assert.equal(isoToMonthDate(bad), null, String(bad));
    }
});

test('a month is written with its full name, whatever the company day format is', () => {
    assert.equal(formatMonth('2026-10'), 'Oktober 2026');
    assert.equal(formatMonth('2026-08'), 'Agustus 2026');
    assert.equal(formatMonth('2026-05'), 'Mei 2026');
    assert.equal(formatMonth('2026-01'), 'Januari 2026');
    assert.equal(formatMonth('2026-13'), '');
    assert.equal(formatMonth(null), '');
    for (let month = 1; month <= 12; month += 1) {
        const iso = monthToIso(2026, month);
        assert.equal(parseMonth(formatMonth(iso)), iso, iso);
    }
});

test('typed months: names, short names, and numbers either way round', () => {
    const ok = {
        'Oktober 2026': '2026-10',
        'oktober 2026': '2026-10',
        'Okt 2026': '2026-10',
        ' Oktober 2026 ': '2026-10',
        'Agu 2026': '2026-08',
        'Agt 2026': '2026-08',
        'Agustus 2026': '2026-08',
        'Mei 2026': '2026-05',
        '10/2026': '2026-10',
        '1/2026': '2026-01',
        '01/2026': '2026-01',
        '10-2026': '2026-10',
        10.2026: '2026-10',
        '2026-10': '2026-10',
        '2026/10': '2026-10',
        '2026-1': '2026-01',
    };
    for (const [text, expected] of Object.entries(ok)) {
        assert.equal(parseMonth(text), expected, text);
    }
});

test('typed months that are not a month in a four-digit year are rejected', () => {
    for (const bad of [
        '13/2026',
        '0/2026',
        '2026-13',
        '2026-00',
        'bukan bulan',
        'Foo 2026',
        'Oktober',
        '10',
        '2026',
        '10/26',
        'Oktober 26',
        'Oktober 20266',
        '2026-10-13',
        '',
        '   ',
    ]) {
        assert.equal(parseMonth(bad), null, bad);
    }
});

test('the month names, in the order of the year', () => {
    assert.deepEqual(
        [...MONTH_NAMES],
        [
            'Januari',
            'Februari',
            'Maret',
            'April',
            'Mei',
            'Juni',
            'Juli',
            'Agustus',
            'September',
            'Oktober',
            'November',
            'Desember',
        ],
    );
});
