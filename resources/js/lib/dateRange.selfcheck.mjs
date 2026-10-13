/**
 * Node selfcheck for the date range helpers and the company date format (no test runner dependency).
 * Run: node resources/js/lib/dateRange.selfcheck.mjs   (any TZ: the helpers must not depend on it)
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

const { FALLBACK_DATE_FORMAT, localeId, phpToDateFns } = await import('./dateFormat.ts');
const {
    DATE_RANGE_PRESETS,
    formatRange,
    isoToLocalDate,
    localDateToIso,
    parseRange,
    presetRange,
    todayInZone,
} = await import('./dateRange.ts');
const { formatDate, formatDateTime } = await import('./format.ts');
const { format } = await import('date-fns');

test('phpToDateFns maps the supported tokens', () => {
    const table = {
        'd M Y': 'dd MMM yyyy',
        'd/m/Y': 'dd/MM/yyyy',
        'Y-m-d': 'yyyy-MM-dd',
        'd.m.Y': 'dd.MM.yyyy',
        'j F Y': 'd MMMM yyyy',
        'n/j/y': 'M/d/yy',
        'D, d M Y': 'EEE, dd MMM yyyy',
        'l, j F Y': 'EEEE, d MMMM yyyy',
        'd-m-Y': 'dd-MM-yyyy',
    };
    for (const [php, dateFns] of Object.entries(table)) {
        assert.equal(phpToDateFns(php), dateFns, php);
    }
});

test('phpToDateFns falls back on anything it cannot map', () => {
    assert.equal(FALLBACK_DATE_FORMAT, 'dd/MM/yyyy');
    for (const unsupported of [
        'd M Y H:i', // time token
        'jS F Y', // ordinal suffix
        "d 'M' Y", // a quote would open a date-fns literal
        'd \\o\\f M Y', // PHP escape
        'Y', // no day or month
        'd/m', // no year
        '',
        '   ',
        undefined,
        null,
        42,
    ]) {
        assert.equal(phpToDateFns(unsupported), FALLBACK_DATE_FORMAT, String(unsupported));
    }
});

test('the Indonesian locale writes and reads "Agu"', () => {
    const month = (index, token) => format(new Date(2026, index, 1), token, { locale: localeId });
    assert.equal(month(7, 'MMM'), 'Agu');
    assert.equal(month(7, 'MMMM'), 'Agustus');
    assert.equal(month(9, 'MMM'), 'Okt');
    assert.equal(month(4, 'MMM'), 'Mei');
    for (const text of ['13 Agu 2026', '13 Agt 2026', '13 agu 2026']) {
        assert.deepEqual(parseRange(text, 'dd MMM yyyy'), {
            ok: true,
            range: { from: '2026-08-13', to: '2026-08-13' },
        });
    }
    assert.deepEqual(parseRange('13 Agustus 2026', 'dd MMM yyyy'), {
        ok: true,
        range: { from: '2026-08-13', to: '2026-08-13' },
    });
});

test('ISO days: calendar days, no zone arithmetic', () => {
    assert.equal(localDateToIso(isoToLocalDate('2026-10-13')), '2026-10-13');
    assert.equal(localDateToIso(isoToLocalDate('2028-02-29')), '2028-02-29');
    for (const bad of [
        '2026-02-31',
        '2026-13-01',
        '2026-10-32',
        '26-10-13',
        '2026-1-3',
        '',
        null,
    ]) {
        assert.equal(isoToLocalDate(bad), null, String(bad));
    }
    assert.equal(isoToLocalDate(undefined), null);
});

test('todayInZone follows the company clock near midnight UTC', () => {
    // 17:30 UTC on the 12th is 00:30 on the 13th in Jakarta (UTC+7).
    const instant = new Date('2026-10-12T17:30:00Z');
    assert.equal(todayInZone('Asia/Jakarta', instant), '2026-10-13');
    assert.equal(todayInZone('UTC', instant), '2026-10-12');
    assert.equal(todayInZone('America/Los_Angeles', instant), '2026-10-12');
    assert.equal(todayInZone('Asia/Jakarta', new Date('2026-10-13T16:59:59Z')), '2026-10-13');
    assert.equal(todayInZone('Asia/Jakarta', new Date('2026-10-13T17:00:00Z')), '2026-10-14');
    assert.equal(todayInZone('Asia/Jayapura', new Date('2026-10-13T14:59:59Z')), '2026-10-13');
    assert.equal(todayInZone('Asia/Jayapura', new Date('2026-10-13T15:00:00Z')), '2026-10-14');
    // A zone name the runtime does not know falls back to the browser clock instead of throwing.
    assert.equal(todayInZone('Not/AZone', new Date(2026, 9, 13, 12)), '2026-10-13');
    assert.equal(todayInZone(null, new Date(2026, 9, 13, 12)), '2026-10-13');
});

test('presets, from a Jakarta instant near midnight UTC', () => {
    const today = todayInZone('Asia/Jakarta', new Date('2026-10-12T17:30:00Z'));
    assert.equal(today, '2026-10-13');
    const expected = {
        today: { from: '2026-10-13', to: '2026-10-13' },
        last7: { from: '2026-10-07', to: '2026-10-13' },
        last30: { from: '2026-09-14', to: '2026-10-13' },
        thisMonth: { from: '2026-10-01', to: '2026-10-13' },
        lastMonth: { from: '2026-09-01', to: '2026-09-30' },
        thisYear: { from: '2026-01-01', to: '2026-10-13' },
    };
    assert.deepEqual(
        DATE_RANGE_PRESETS.map((preset) => preset.id),
        Object.keys(expected),
    );
    assert.deepEqual(
        DATE_RANGE_PRESETS.map((preset) => preset.label),
        ['Hari ini', '7 hari terakhir', '30 hari terakhir', 'Bulan ini', 'Bulan lalu', 'Tahun ini'],
    );
    for (const [id, range] of Object.entries(expected)) {
        assert.deepEqual(presetRange(id, today), range, id);
    }
});

test('presets across month, leap-day and year boundaries', () => {
    assert.deepEqual(presetRange('lastMonth', '2026-03-01'), {
        from: '2026-02-01',
        to: '2026-02-28',
    });
    assert.deepEqual(presetRange('lastMonth', '2028-03-05'), {
        from: '2028-02-01',
        to: '2028-02-29',
    });
    assert.deepEqual(presetRange('lastMonth', '2026-01-15'), {
        from: '2025-12-01',
        to: '2025-12-31',
    });
    assert.deepEqual(presetRange('last7', '2026-01-03'), { from: '2025-12-28', to: '2026-01-03' });
    assert.deepEqual(presetRange('last30', '2028-03-01'), { from: '2028-02-01', to: '2028-03-01' });
    assert.deepEqual(presetRange('thisMonth', '2026-01-01'), {
        from: '2026-01-01',
        to: '2026-01-01',
    });
});

test('formatRange writes an en dash and leaves an open end empty', () => {
    const full = { from: '2026-10-13', to: '2026-10-20' };
    assert.equal(formatRange(full, 'dd MMM yyyy'), '13 Okt 2026 – 20 Okt 2026');
    assert.equal(formatRange(full, 'dd/MM/yyyy'), '13/10/2026 – 20/10/2026');
    assert.equal(formatRange(full, 'yyyy-MM-dd'), '2026-10-13 – 2026-10-20');
    assert.equal(formatRange({ from: '2026-10-13', to: null }, 'dd/MM/yyyy'), '13/10/2026 –');
    assert.equal(formatRange({ from: null, to: '2026-10-20' }, 'dd/MM/yyyy'), '– 20/10/2026');
    assert.equal(formatRange({ from: null, to: null }, 'dd/MM/yyyy'), '');
    assert.equal(formatRange({ from: 'oops', to: '2026-02-31' }, 'dd/MM/yyyy'), '');
});

test('typed input in the company format emits ISO and round-trips', () => {
    const ranges = [
        { from: '2026-10-13', to: '2026-10-20' },
        { from: '2026-10-13', to: '2026-10-13' },
        { from: '2026-10-13', to: null },
        { from: null, to: '2026-10-20' },
        { from: '2028-02-29', to: '2028-03-01' },
        { from: '2025-12-31', to: '2026-01-01' },
    ];
    for (const dateFnsFormat of [
        'dd/MM/yyyy',
        'dd MMM yyyy',
        'yyyy-MM-dd',
        'd MMMM yyyy',
        'M/d/yyyy',
        'EEE, dd MMM yyyy',
    ]) {
        for (const range of ranges) {
            const text = formatRange(range, dateFnsFormat);
            assert.deepEqual(
                parseRange(text, dateFnsFormat),
                { ok: true, range },
                `${dateFnsFormat}: ${text}`,
            );
        }
    }
});

test('the typed form from the ticket, and the ways people write it', () => {
    const range = { from: '2026-10-13', to: '2026-10-20' };
    const ok = (text, dateFnsFormat, expected) =>
        assert.deepEqual(parseRange(text, dateFnsFormat), { ok: true, range: expected }, text);

    ok('13/10/2026 - 20/10/2026', 'dd/MM/yyyy', range);
    ok('13/10/2026 – 20/10/2026', 'dd/MM/yyyy', range);
    ok('13/10/2026—20/10/2026', 'dd/MM/yyyy', range);
    ok('  13/10/2026   -   20/10/2026  ', 'dd/MM/yyyy', range);
    ok('3/10/2026 - 20/10/2026', 'dd/MM/yyyy', { from: '2026-10-03', to: '2026-10-20' });
    ok('2026-10-13 - 2026-10-20', 'yyyy-MM-dd', range);
    ok('2026-10-13 – 2026-10-20', 'yyyy-MM-dd', range);
    ok('13 Okt 2026 - 20 Oktober 2026', 'dd MMM yyyy', range);
    ok('13 oktober 2026 - 20 okt 2026', 'dd MMM yyyy', range);
    ok('13 Okt 2026 – 20 Okt 2026', 'dd MMMM yyyy', range);
    // One day is that day; a dangling separator opens an end; blank clears.
    ok('13/10/2026', 'dd/MM/yyyy', { from: '2026-10-13', to: '2026-10-13' });
    ok('2026-10-13', 'yyyy-MM-dd', { from: '2026-10-13', to: '2026-10-13' });
    ok('13/10/2026 -', 'dd/MM/yyyy', { from: '2026-10-13', to: null });
    ok('13/10/2026 –', 'dd/MM/yyyy', { from: '2026-10-13', to: null });
    ok('2026-10-13 -', 'yyyy-MM-dd', { from: '2026-10-13', to: null });
    ok('- 20/10/2026', 'dd/MM/yyyy', { from: null, to: '2026-10-20' });
    ok('– 2026-10-20', 'yyyy-MM-dd', { from: null, to: '2026-10-20' });
    ok('', 'dd/MM/yyyy', { from: null, to: null });
    ok('   ', 'dd/MM/yyyy', { from: null, to: null });
    ok('–', 'dd/MM/yyyy', { from: null, to: null });
});

test('invalid and inverted entries are rejected and name the reason', () => {
    const bad = (text, reason, dateFnsFormat = 'dd/MM/yyyy') =>
        assert.deepEqual(parseRange(text, dateFnsFormat), { ok: false, reason }, text);

    bad('bukan tanggal', 'format');
    bad('13/10', 'format');
    bad('32/10/2026', 'format');
    bad('31/02/2026', 'format');
    bad('13/13/2026', 'format');
    bad('13/10/26', 'format'); // two-digit year would be year 26 AD
    bad('13/10/0026', 'format');
    bad('13/10/20266', 'format');
    bad('13/10/2026 - bukan', 'format');
    bad('bukan - 20/10/2026', 'format');
    bad('13/10/2026 - 20/10/2026 - 21/10/2026', 'format');
    bad('2026/10/13', 'format');
    bad('13 Foo 2026', 'format', 'dd MMM yyyy');
    bad('20/10/2026 - 13/10/2026', 'order');
    bad('2026-10-20 - 2026-10-13', 'order', 'yyyy-MM-dd');
    bad('20/10/2026 – 13/10/2026', 'order');
    bad('2027-01-01 – 2026-12-31', 'order', 'yyyy-MM-dd');
});

test('formatDate and formatDateTime honour the company date_format', () => {
    const company = (dateFormat) => ({ settings: { date_format: dateFormat } });

    assert.equal(formatDate('2026-10-13', company('d M Y')), '13 Okt 2026');
    assert.equal(formatDate('2026-08-01', company('d M Y')), '01 Agu 2026');
    assert.equal(formatDate('2026-10-13', company('d/m/Y')), '13/10/2026');
    assert.equal(formatDate('2026-10-03', company('j F Y')), '3 Oktober 2026');
    assert.equal(formatDate('2026-10-13', company('Y-m-d')), '2026-10-13');
    assert.equal(
        formatDate(new Date(2026, 9, 13, 23, 59), company('l, d F Y')),
        'Selasa, 13 Oktober 2026',
    );
    // No company, no setting or an unsupported token: dd/MM/yyyy, as before.
    assert.equal(formatDate('2026-10-13'), '13/10/2026');
    assert.equal(formatDate('2026-10-13', null), '13/10/2026');
    assert.equal(formatDate('2026-10-13', { settings: {} }), '13/10/2026');
    assert.equal(formatDate('2026-10-13', company('d M Y H:i')), '13/10/2026');
    // Empty and unreadable values stay a dash.
    assert.equal(formatDate(null), '-');
    assert.equal(formatDate(''), '-');
    assert.equal(formatDate('bukan tanggal'), '-');
    assert.equal(formatDate('2026-02-31'), '-');

    const afternoon = new Date(2026, 9, 13, 8, 5);
    assert.equal(formatDateTime(afternoon, company('d M Y')), '13 Okt 2026 08:05');
    assert.equal(formatDateTime(afternoon), '13/10/2026 08:05');
    assert.equal(formatDateTime('2026-10-13T08:05:00', company('Y-m-d')), '2026-10-13 08:05');
    assert.equal(formatDateTime(afternoon.getTime(), company('d/m/Y')), '13/10/2026 08:05');
    assert.equal(formatDateTime(null, company('d M Y')), '-');
    assert.equal(formatDateTime('', company('d M Y')), '-');
    assert.equal(formatDateTime('bukan tanggal'), '-');
});
