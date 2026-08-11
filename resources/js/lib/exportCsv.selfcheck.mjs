/**
 * Node assert self-check for exportCsv foundation (no vitest required).
 * Run: node --test resources/js/lib/exportCsv.selfcheck.mjs
 */
import assert from 'node:assert/strict';
import test from 'node:test';

function escapeCell(value) {
    if (value === null || value === undefined) {
        return '';
    }
    const raw = String(value);
    if (/[",\n\r]/.test(raw)) {
        return `"${raw.replace(/"/g, '""')}"`;
    }
    return raw;
}

function buildCsv(rows, columns) {
    const header = columns.map((c) => escapeCell(c.label)).join(',');
    const lines = rows.map((row) =>
        columns
            .map((col) => {
                const value = col.accessor
                    ? col.accessor(row)
                    : row[col.key];
                return escapeCell(value);
            })
            .join(','),
    );
    return [header, ...lines].join('\r\n');
}

test('buildCsv escapes commas quotes and newlines', () => {
    const csv = buildCsv(
        [{ name: 'Ada, Lovelace', note: 'said "hello"\nnext' }],
        [
            { key: 'name', label: 'Name' },
            { key: 'note', label: 'Note' },
        ],
    );
    assert.equal(
        csv,
        'Name,Note\r\n"Ada, Lovelace","said ""hello""\nnext"',
    );
});

test('buildCsv uses accessor', () => {
    const csv = buildCsv(
        [{ n: 2 }],
        [{ key: 'n', label: 'N', accessor: (r) => r.n * 2 }],
    );
    assert.equal(csv, 'N\r\n4');
});

test('escapeCell empty for nullish', () => {
    assert.equal(escapeCell(null), '');
    assert.equal(escapeCell(undefined), '');
});
