import assert from 'node:assert/strict';
import test from 'node:test';

const {
    classBasename,
    formatBytes,
    formatPropertyValue,
    isThrottleError,
    throttleMessage,
    totalRows,
} = await import('./platform.ts');

test('byte totals use Indonesian decimal formatting and binary units', () => {
    assert.equal(formatBytes(0), '0 B');
    assert.equal(formatBytes(1023), '1.023 B');
    assert.equal(formatBytes(1536), '1,5 KB');
    assert.equal(formatBytes(3 * 1024 * 1024), '3 MB');
    assert.equal(formatBytes(Number.NaN), '0 B');
});

test('export row totals ignore a missing map', () => {
    assert.equal(totalRows(null), 0);
    assert.equal(totalRows({ users: 3, invoices: 7 }), 10);
});

test('class names and throttle messages have small safe fallbacks', () => {
    assert.equal(classBasename('App\\Jobs\\BuildExport'), 'BuildExport');
    assert.equal(classBasename(null), '-');
    assert.equal(isThrottleError('Terlalu banyak percobaan masuk.'), true);
    assert.equal(isThrottleError('Email atau kata sandi salah.'), false);
});

test('a throttled answer names the wait when the server gave one', () => {
    assert.equal(throttleMessage('37'), 'Terlalu banyak percobaan. Coba lagi dalam 37 detik.');
    assert.equal(throttleMessage(0.4), 'Terlalu banyak percobaan. Coba lagi dalam 1 detik.');
    for (const none of [undefined, null, '', 'soon', 0, -3]) {
        assert.equal(
            throttleMessage(none),
            'Terlalu banyak percobaan. Coba lagi nanti.',
            String(none),
        );
    }
    assert.equal(isThrottleError(throttleMessage('5')), true);
});

test('audit properties read as one line each', () => {
    assert.equal(formatPropertyValue('Pemeriksaan akses'), 'Pemeriksaan akses');
    assert.equal(formatPropertyValue(1800), '1800');
    assert.equal(formatPropertyValue(0), '0');
    assert.equal(formatPropertyValue(true), 'Ya');
    assert.equal(formatPropertyValue(false), 'Tidak');
    assert.equal(formatPropertyValue({ a: [1, 2] }), '{"a":[1,2]}');
    assert.equal(formatPropertyValue([3, 4]), '[3,4]');
    for (const none of [null, undefined, '']) {
        assert.equal(formatPropertyValue(none), '-', String(none));
    }
});
