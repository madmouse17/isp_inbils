import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const selectPath = new URL('./Select.tsx', import.meta.url);
const nativePath = new URL('../composite/NativeSelect.tsx', import.meta.url);
const invoiceIndexPath = new URL('../../Pages/Admin/Billing/Invoices/Index.tsx', import.meta.url);
const ticketIndexPath = new URL('../../Pages/Admin/Tickets/Index.tsx', import.meta.url);
const networkAssetIndexPath = new URL('../../Pages/Admin/NetworkAssets/Index.tsx', import.meta.url);

async function read(path) {
    return readFile(path, 'utf8');
}

test('Select is canonical Radix and legacy native stays isolated', async () => {
    const [select, native, invoiceIndex, ticketIndex, networkAssetIndex] = await Promise.all([
        read(selectPath),
        read(nativePath),
        read(invoiceIndexPath),
        read(ticketIndexPath),
        read(networkAssetIndexPath),
    ]);

    assert.match(select, /const Select = SelectPrimitive\.Root;/);
    assert.match(
        select,
        /export type SelectProps = React\.ComponentPropsWithoutRef<typeof SelectPrimitive\.Root>;/,
    );
    assert.doesNotMatch(select, /SelectHTMLAttributes<HTMLSelectElement>/);
    assert.doesNotMatch(select, /onValueChange\s*\?/);
    assert.doesNotMatch(select, /<select\b/);
    assert.doesNotMatch(select, /NativeSelect/);

    assert.match(native, /React\.SelectHTMLAttributes<HTMLSelectElement>/);
    assert.match(native, /<select\b/);

    for (const [name, source] of [
        ['Invoices', invoiceIndex],
        ['Tickets', ticketIndex],
        ['NetworkAssets', networkAssetIndex],
    ]) {
        assert.match(source, /<Select\b/);
        assert.match(source, /onValueChange=/);
        assert.doesNotMatch(source, /<NativeSelect\b/);
        assert.doesNotMatch(source, /from '\/\@\/Components\/ui'[^]*NativeSelect/);
        void name;
    }
});
