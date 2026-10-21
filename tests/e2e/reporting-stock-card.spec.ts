import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { inertiaProps, loginAs, selectSearchOption } from './support/demo';
import { companyDateFormat, formatIsoAs, typeDateRange } from './support/dateRange';

const movementNotes = Array.from(
    { length: 11 },
    (_, index) => `E2E Stock Card Movement ${String(index + 1).padStart(2, '0')}`,
);

function query(page: Page, key: string) {
    return new URL(page.url()).searchParams.get(key);
}

async function expectRowsToContainExactly(page: Page, notes: string[]) {
    const rows = page.locator('tbody tr');
    await expect(rows).toHaveCount(notes.length);
    const actual = await rows.locator('td:nth-child(6)').allTextContents();
    expect(actual.map((note) => note.trim()).sort()).toEqual([...notes].sort());
}

async function seededProductId(page: Page) {
    const { props } = await inertiaProps(page, '/admin/products?search=E2E-STOCK-PRODUCT');
    const products = (props?.products?.data ?? []) as { id: string; sku: string }[];
    const product = products.find((item) => item.sku === 'E2E-STOCK-PRODUCT');
    expect(product, 'E2E-STOCK-PRODUCT fixture').toBeTruthy();
    return String(product!.id);
}

test.describe('@smoke stock card reporting', () => {
    test('filters, sorts, paginates, preserves query, and stays usable across themes and widths', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        const productId = await seededProductId(page);

        await page.goto('/admin/reports/stock-card');
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'Kartu Stok', exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: /ekspor/i })).toHaveCount(0);
        await expect(page.getByRole('combobox', { name: 'Lokasi' })).toBeVisible();
        await expect(page.locator('tbody tr')).toHaveCount(0);
        await expect(
            page.getByText('Pilih barang lalu tekan Tampilkan untuk melihat pergerakan stok.'),
        ).toBeVisible();
        // Before a product is chosen the page offers the ones whose stock moved last.
        await expect(page.getByRole('heading', { name: 'Barang terakhir bergerak' })).toBeVisible();

        const productPicker = page.getByRole('combobox', { name: 'Barang' });
        await selectSearchOption(page, 'Barang', 'E2E-STOCK-PRODUCT');
        await typeDateRange(
            page,
            page.getByLabel('Periode', { exact: true }),
            '2026-08-01',
            '2026-08-11',
        );
        await page.getByRole('button', { name: 'Tampilkan', exact: true }).click();
        await expect.poll(() => query(page, 'product_id')).toBe(productId);
        await expect.poll(() => query(page, 'date_from')).toBe('2026-08-01');
        await expect.poll(() => query(page, 'date_to')).toBe('2026-08-11');

        // Default page size 10 over 11 movements: second page holds the remainder.
        const perPage = page.getByLabel('Baris per halaman');
        await expect(perPage).toHaveValue('10');
        await expect(page.locator('tbody tr')).toHaveCount(10);

        const qtyHeader = page.getByRole('columnheader', { name: 'Jumlah' });
        const qty = qtyHeader.getByRole('button');
        await qty.focus();
        await expect(qty).toBeFocused();
        await page.keyboard.press('Enter');
        await expect.poll(() => query(page, 'sort')).toBe('quantity');
        await expect.poll(() => query(page, 'direction')).toBe('asc');
        await expect(qtyHeader).toHaveAttribute('aria-sort', 'ascending');
        await expect(page.locator('tbody tr').first().locator('td').nth(1)).toHaveText('1.00');
        await expect(page.locator('tbody tr').first().locator('td').nth(5)).toHaveText(
            movementNotes[0],
        );

        await qty.focus();
        await page.keyboard.press('Enter');
        await expect.poll(() => query(page, 'direction')).toBe('desc');
        await expect(qtyHeader).toHaveAttribute('aria-sort', 'descending');
        await expect(page.locator('tbody tr').first().locator('td').nth(1)).toHaveText('11.00');
        await expect(page.locator('tbody tr').first().locator('td').nth(5)).toHaveText(
            movementNotes[10],
        );

        await perPage.selectOption('25');
        await expect.poll(() => query(page, 'per_page')).toBe('25');
        await expectRowsToContainExactly(page, movementNotes);

        await page.reload();
        await expect(productPicker).toHaveValue('E2E Stock Product');
        const format = await companyDateFormat(page);
        await expect(page.getByLabel('Periode', { exact: true })).toHaveValue(
            `${formatIsoAs('2026-08-01', format)} – ${formatIsoAs('2026-08-11', format)}`,
        );
        await expect(page.getByLabel('Baris per halaman')).toHaveValue('25');
        await expect(qtyHeader).toHaveAttribute('aria-sort', 'descending');
        await expectRowsToContainExactly(page, movementNotes);

        // A location filter must survive client sort visits.
        const toLocationName = (
            await page.locator('tbody tr').first().locator('td').nth(4).textContent()
        )?.trim();
        // The id comes from the Lokasi picker, as a person would choose it, and rides in the URL.
        await selectSearchOption(page, 'Lokasi', toLocationName ?? '');
        await page.getByRole('button', { name: 'Tampilkan', exact: true }).click();
        await expect.poll(() => query(page, 'location_id')).not.toBeNull();
        const locationId = query(page, 'location_id') ?? '';
        expect(locationId, `location id for ${toLocationName}`).not.toBe('');
        await page.goto(
            `/admin/reports/stock-card?product_id=${productId}&location_id=${locationId}&date_from=2026-08-01&date_to=2026-08-11&per_page=25`,
        );
        await expectRowsToContainExactly(page, movementNotes);
        const balance = page.getByRole('columnheader', { name: 'Saldo' }).getByRole('button');
        await balance.focus();
        await page.keyboard.press('Enter');
        await expect.poll(() => query(page, 'sort')).toBe('balance_after');
        expect(query(page, 'location_id')).toBe(locationId);
        await expectRowsToContainExactly(page, movementNotes);

        // A single-day range yields only that day's movement, proving both inclusive bounds.
        await typeDateRange(
            page,
            page.getByLabel('Periode', { exact: true }),
            '2026-08-06',
            '2026-08-06',
        );
        await page.getByRole('button', { name: 'Tampilkan', exact: true }).click();
        await expect.poll(() => query(page, 'date_from')).toBe('2026-08-06');
        await expect.poll(() => query(page, 'date_to')).toBe('2026-08-06');
        await expectRowsToContainExactly(page, [movementNotes[5]]);

        for (const width of [1280, 320]) {
            for (const dark of [false, true]) {
                await page.setViewportSize({ width, height: 800 });
                await page.evaluate(
                    (value) => localStorage.setItem('darkMode', String(value)),
                    dark,
                );
                await page.reload();
                if (dark) {
                    await expect(page.locator('html')).toHaveClass(/\bdark\b/);
                } else {
                    await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);
                }
                await expect(
                    main.getByRole('heading', { name: 'Kartu Stok', exact: true }),
                ).toBeVisible();
                await expectRowsToContainExactly(page, [movementNotes[5]]);

                const run = page.getByRole('button', { name: 'Tampilkan', exact: true });
                await run.focus();
                await expect(run).toBeFocused();
                const hasFocusIndicator = await run.evaluate((el) => {
                    const style = getComputedStyle(el);
                    return style.outlineStyle !== 'none' || style.boxShadow !== 'none';
                });
                expect(hasFocusIndicator).toBe(true);

                const bounds = await run.boundingBox();
                expect(bounds).not.toBeNull();
                expect(bounds!.x).toBeGreaterThanOrEqual(0);
                expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width);
                expect(
                    await page.evaluate(
                        () => document.documentElement.scrollWidth <= window.innerWidth,
                    ),
                ).toBe(true);
            }
        }
        await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
    });
});
