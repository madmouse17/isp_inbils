/**
 * Node selfcheck for the report chart helpers: status slices, the running bucket, row folding.
 * Run: node --test resources/js/Pages/Admin/Reports/components/chartData.selfcheck.mjs
 *
 * Expected values are literals, never computed with the code under test.
 */
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { registerHooks } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';
import test from 'node:test';

const JS_ROOT = fileURLToPath(new URL('../../../../', import.meta.url));

// The sources import each other the way Vite resolves them: `@/` for resources/js and no extension.
registerHooks({
    resolve: (specifier, context, next) => {
        if (specifier.startsWith('@/')) {
            const target = `${JS_ROOT}${specifier.slice(2)}`;
            const file = [`${target}.ts`, `${target}/index.ts`].find((path) => existsSync(path));
            if (file) return next(pathToFileURL(file).href, context);
        }

        return next(/^\.\.?\/[^.]+$/.test(specifier) ? `${specifier}.ts` : specifier, context);
    },
});

const { countRows, markRunning, periodUnit, statusSlices, topRows } =
    await import('./chartData.ts');
const { INVOICE_STATUS, NETWORK_ASSET_STATUS } = await import('../../../../lib/statuses/index.ts');

const series = (granularity, buckets) => ({
    granularity,
    points: buckets.map(([bucket, label]) => ({ bucket, label, revenue: 1 })),
});
const labels = (points) => points.map((point) => point.label);

test('a status donut is named and coloured by its status map, in payload order', () => {
    assert.deepEqual(statusSlices({ paid: 105, sent: 6, overdue: 2 }, INVOICE_STATUS), [
        { name: 'Lunas', value: 105, color: 'var(--status-success)' },
        { name: 'Belum dibayar', value: 6, color: 'var(--status-info)' },
        { name: 'Lewat jatuh tempo', value: 2, color: 'var(--status-danger)' },
    ]);
    assert.deepEqual(statusSlices({ available: '4' }, NETWORK_ASSET_STATUS), [
        { name: 'Tersedia', value: 4, color: 'var(--status-success)' },
    ]);
});

test('a status the map lacks reads as itself in the neutral colour, no payload is no slice', () => {
    assert.deepEqual(statusSlices({ written_off: 1 }, INVOICE_STATUS), [
        { name: 'written_off', value: 1, color: 'var(--status-neutral)' },
    ]);
    assert.deepEqual(statusSlices(undefined, INVOICE_STATUS), []);
    assert.deepEqual(statusSlices([], INVOICE_STATUS), []);
});

test('count rows follow the payload and take a label function', () => {
    assert.deepEqual(
        countRows({ router: 3, olt: '1' }, (name) => name.toUpperCase()),
        [
            { name: 'ROUTER', value: 3 },
            { name: 'OLT', value: 1 },
        ],
    );
    assert.deepEqual(countRows(undefined), []);
});

test('the smallest rows fold into one named Lainnya past the limit', () => {
    const rows = [
        { name: 'a', value: 5 },
        { name: 'b', value: 9 },
        { name: 'c', value: 1 },
        { name: 'd', value: 2 },
    ];

    assert.deepEqual(
        topRows(rows, 4).map((row) => row.name),
        ['b', 'a', 'd', 'c'],
    );
    assert.deepEqual(topRows(rows, 3), [
        { name: 'b', value: 9 },
        { name: 'a', value: 5 },
        { name: 'Lainnya', value: 3 },
    ]);
});

test('the bucket size is said in words', () => {
    assert.equal(periodUnit({ granularity: 'day', points: [] }), 'hari');
    assert.equal(periodUnit({ granularity: 'week', points: [] }), 'minggu');
    assert.equal(periodUnit({ granularity: 'month', points: [] }), 'bulan');
});

test('the month that holds today is berjalan, the months before it are not', () => {
    const months = series('month', [
        ['2026-09-01', 'Sep 2026'],
        ['2026-10-01', 'Okt 2026'],
    ]);

    assert.deepEqual(labels(markRunning(months, '2026-10-14')), [
        'Sep 2026',
        'Okt 2026 (berjalan)',
    ]);
    assert.deepEqual(labels(markRunning(months, '2026-10-01')), [
        'Sep 2026',
        'Okt 2026 (berjalan)',
    ]);
    // The last day of the month is still a day in progress.
    assert.deepEqual(labels(markRunning(months, '2026-10-31')), [
        'Sep 2026',
        'Okt 2026 (berjalan)',
    ]);
});

test('a period that ended before today has no running bucket', () => {
    const months = series('month', [
        ['2026-08-01', 'Agu 2026'],
        ['2026-09-01', 'Sep 2026'],
    ]);

    assert.deepEqual(labels(markRunning(months, '2026-10-14')), ['Agu 2026', 'Sep 2026']);
    assert.deepEqual(labels(markRunning(months, '2026-07-31')), ['Agu 2026', 'Sep 2026']);
});

test('February knows its own last day, leap years included', () => {
    const feb2024 = series('month', [['2024-02-01', 'Feb 2024']]);
    const feb2026 = series('month', [['2026-02-01', 'Feb 2026']]);

    assert.deepEqual(labels(markRunning(feb2024, '2024-02-29')), ['Feb 2024 (berjalan)']);
    assert.deepEqual(labels(markRunning(feb2024, '2024-03-01')), ['Feb 2024']);
    assert.deepEqual(labels(markRunning(feb2026, '2026-02-28')), ['Feb 2026 (berjalan)']);
    assert.deepEqual(labels(markRunning(feb2026, '2026-03-01')), ['Feb 2026']);
});

test('an ISO week runs Monday to Sunday and a day is its own bucket', () => {
    const weeks = series('week', [
        ['2026-10-05', 'W41 2026'],
        ['2026-10-12', 'W42 2026'],
    ]);
    const days = series('day', [
        ['2026-10-13', '13 Okt'],
        ['2026-10-14', '14 Okt'],
    ]);

    assert.deepEqual(labels(markRunning(weeks, '2026-10-18')), ['W41 2026', 'W42 2026 (berjalan)']);
    assert.deepEqual(labels(markRunning(weeks, '2026-10-19')), ['W41 2026', 'W42 2026']);
    assert.deepEqual(labels(markRunning(days, '2026-10-14')), ['13 Okt', '14 Okt (berjalan)']);
});

test('marking leaves the series alone and keeps every figure of a point', () => {
    const months = series('month', [['2026-10-01', 'Okt 2026']]);
    const marked = markRunning(months, '2026-10-14');

    assert.deepEqual(marked, [{ bucket: '2026-10-01', label: 'Okt 2026 (berjalan)', revenue: 1 }]);
    assert.equal(months.points[0].label, 'Okt 2026');
});
