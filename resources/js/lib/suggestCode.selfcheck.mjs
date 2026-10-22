import assert from 'node:assert/strict';
import test from 'node:test';

const { suggestCode } = await import('./suggestCode.ts');

test('a name becomes a capital dash code', () => {
    assert.equal(suggestCode('Home 20Mbps', 'PKG'), 'PKG-HOME-20MBPS');
    assert.equal(suggestCode('  Kantor Pusat (Bandung) '), 'KANTOR-PUSAT-BANDUNG');
    assert.equal(suggestCode('Cabang Pékalongan'), 'CABANG-PEKALONGAN');
});

test('a name without letters or digits gives no code', () => {
    assert.equal(suggestCode('  --  ', 'PKG'), null);
    assert.equal(suggestCode(''), null);
});

test('the code stays within the length and never ends in a dash', () => {
    const code = suggestCode('Aaaa Bbbb', 'PKG', 9);
    assert.equal(code, 'PKG-AAAA');
    assert.ok(suggestCode('x'.repeat(80), '', 50)?.length === 50);
});
