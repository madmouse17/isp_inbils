import type { Locator, Page, TestInfo } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { loginAs } from './support/demo';

type TableCase = {
    name: string;
    path: string;
    heading: string;
    search?: { label: string; value: string; key?: string };
    submit?: string;
    sortHeader: string;
    sortKey: string;
    sortParam: string;
    directionParam: string;
    pageParam?: string;
    sizeParam?: string;
    first: string;
    last: string;
    exportPath?: string;
    setup?: (page: Page) => Promise<void>;
};

async function expectQuery(page: Page, key: string, value: string) {
    await expect
        .poll(() => new URL(page.url()).searchParams.get(key), {
            message: `Preserved query parameter ${key}`,
        })
        .toBe(value);
}

async function expectQuerySnapshot(page: Page, expected: URLSearchParams) {
    for (const [key, value] of expected) await expectQuery(page, key, value);
}

async function keyboardFocus(page: Page, control: Locator) {
    await control.focus();
    await page.keyboard.press('Tab');
    await page.keyboard.press('Shift+Tab');
    await expect(control).toBeFocused();
    expect(
        await control.evaluate((element) => {
            const style = getComputedStyle(element);
            return (
                (style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0) ||
                (style.boxShadow !== 'none' && style.boxShadow !== '')
            );
        }),
        'Keyboard focus has a visible outline or ring',
    ).toBe(true);
}

async function keyboardSort(page: Page, c: TableCase, direction: 'asc' | 'desc') {
    const header = page.getByRole('columnheader', { name: c.sortHeader, exact: true });
    await keyboardFocus(page, header.getByRole('button'));
    await page.keyboard.press(direction === 'asc' ? 'Enter' : 'Space');
    await expectQuery(page, c.sortParam, c.sortKey);
    await expectQuery(page, c.directionParam, direction);
    await expect(header).toHaveAttribute(
        'aria-sort',
        direction === 'asc' ? 'ascending' : 'descending',
    );
    await expect(page.locator('tbody:visible tr').first()).toContainText(
        direction === 'asc' ? c.first : c.last,
    );
}

async function exportParity(page: Page, c: TableCase) {
    if (!c.exportPath) {
        await expect(page.getByRole('button', { name: 'Export', exact: true })).toHaveCount(0);
        return;
    }
    await keyboardFocus(page, page.getByRole('button', { name: 'Export', exact: true }));
    await page.keyboard.press('Enter');
    await expect(page.getByRole('menuitem', { name: 'Export CSV', exact: true })).toBeVisible();
    await expect(page.getByRole('menuitem', { name: 'Export PDF', exact: true })).toBeVisible();
    await expect(page.getByRole('menuitem', { name: 'Export Excel', exact: true })).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(page.getByRole('menuitem', { name: 'Export CSV', exact: true })).toBeHidden();
    // Attachment navigations emit ERR_ABORTED in Chromium; the canonical guarded
    // fixture has no download exception. Match A+B's authenticated HTTP proof.
    const exportQuery = new URL(page.url()).searchParams;
    exportQuery.delete(c.pageParam ?? 'page');
    exportQuery.set('format', 'csv');
    const response = await page.request.get(`${c.exportPath}?${exportQuery}`);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('text/csv');
    expect(response.headers()['content-disposition']).toContain('attachment');
    const csv = await response.text();
    expect(csv).toContain(c.first);
    expect(csv).toContain(c.last);
    expect(csv.indexOf(c.last)).toBeLessThan(csv.indexOf(c.first));
    const prefix = c.first.replace(/01$/, '');
    const exportedRows = csv.trim().split(/\r?\n/).slice(1);
    expect(exportedRows).toHaveLength(11);
    for (let index = 0; index < 11; index += 1) {
        expect(exportedRows[index]).toContain(`${prefix}${String(11 - index).padStart(2, '0')}`);
    }
    exportQuery.set('format', 'pdf');
    const pdf = await page.request.get(`${c.exportPath}?${exportQuery}`);
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect(pdf.headers()['content-disposition']).toContain('attachment');
}

async function exerciseTable(page: Page, c: TableCase, testInfo: TestInfo) {
    const initial = new URL(c.path, 'http://localhost');
    initial.searchParams.set(c.sortParam, c.sortKey);
    initial.searchParams.set(c.directionParam, 'desc');
    await page.goto(`${initial.pathname}${initial.search}`);
    await expect(
        page.getByRole('main').getByRole('heading', { name: c.heading, exact: true }),
    ).toBeVisible();
    if (c.setup) await c.setup(page);
    if (c.search) {
        await page.getByLabel(c.search.label, { exact: true }).fill(c.search.value);
        if (c.submit) await page.getByRole('button', { name: c.submit, exact: true }).click();
        await expectQuery(page, c.search.key ?? 'search', c.search.value);
    }
    const filterLabels: Record<string, string[]> = {
        Products: ['Category', 'Active'],
        Stocks: ['Location', 'Product'],
        'Stock Movements': ['Type'],
        'Inventory Requests': ['Status permintaan'],
        Returns: ['Status pengembalian'],
    };
    const filters = [];
    for (const label of filterLabels[c.name] ?? []) {
        filters.push({ label, value: await page.getByLabel(label, { exact: true }).inputValue() });
    }
    await keyboardSort(page, c, 'asc');
    await keyboardSort(page, c, 'desc');
    await page
        .getByLabel('Rows per page', { exact: true })
        .locator('visible=true')
        .selectOption('10');
    await expectQuery(page, c.sizeParam ?? 'per_page', '10');
    await expect(page.locator('tbody:visible tr')).toHaveCount(10);
    const preserved = new URL(page.url()).searchParams;
    preserved.delete(c.pageParam ?? 'page');
    await keyboardFocus(page, page.getByRole('button', { name: 'Next page', exact: true }));
    await page.keyboard.press('Enter');
    await expectQuery(page, c.pageParam ?? 'page', '2');
    await expectQuerySnapshot(page, preserved);
    await expect(page.locator('tbody:visible tr')).toHaveCount(1);
    await expect(page.locator('tbody:visible tr').first()).toContainText(c.first);
    await page.reload();
    await expectQuerySnapshot(page, preserved);
    await expectQuery(page, c.pageParam ?? 'page', '2');
    await expect(page.locator('tbody:visible tr').first()).toContainText(c.first);
    await exportParity(page, c);
    await keyboardFocus(page, page.getByRole('button', { name: 'Previous page', exact: true }));
    await page.keyboard.press('Enter');
    await expectQuery(page, c.pageParam ?? 'page', '1');
    await expect(page.locator('tbody:visible tr').first()).toContainText(c.last);
    await page
        .getByLabel('Rows per page', { exact: true })
        .locator('visible=true')
        .selectOption('25');
    await expectQuery(page, c.sizeParam ?? 'per_page', '25');
    await expect(page.locator('tbody:visible tr')).toHaveCount(11);
    for (let index = 0; index < 11; index += 1) {
        await expect(page.locator('tbody:visible tr').nth(index)).toContainText(
            `${c.first.replace(/01$/, '')}${String(11 - index).padStart(2, '0')}`,
        );
    }
    preserved.set(c.sizeParam ?? 'per_page', '25');
    await expectQuerySnapshot(page, preserved);
    for (const width of [1280, 320]) {
        for (const dark of [false, true]) {
            await page.setViewportSize({ width, height: 800 });
            await page.evaluate((value) => localStorage.setItem('darkMode', String(value)), dark);
            await page.reload();
            await expect(page.locator('html')).toHaveClass(dark ? /\bdark\b/ : /^(?!.*\bdark\b)/);
            await expectQuerySnapshot(page, preserved);
            if (c.search)
                await expect(page.getByLabel(c.search.label, { exact: true })).toHaveValue(
                    c.search.value,
                );
            for (const filter of filters) {
                await expect(page.getByLabel(filter.label, { exact: true })).toHaveValue(
                    filter.value,
                );
            }
            await expect(
                page.getByLabel('Rows per page', { exact: true }).locator('visible=true'),
            ).toHaveValue('25');
            await expect(page.locator('tbody:visible tr')).toHaveCount(11);
            await expect(page.locator('tbody:visible tr').first()).toContainText(c.last);
            await keyboardFocus(
                page,
                page
                    .getByRole('columnheader', { name: c.sortHeader, exact: true })
                    .getByRole('button'),
            );
            const controls = [
                page.getByLabel('Rows per page', { exact: true }).locator('visible=true'),
            ];
            if (c.search) controls.push(page.getByLabel(c.search.label, { exact: true }));
            for (const control of controls) {
                await control.scrollIntoViewIfNeeded();
                const box = await control.boundingBox();
                expect(box).not.toBeNull();
                expect(box!.x).toBeGreaterThanOrEqual(0);
                expect(box!.x + box!.width).toBeLessThanOrEqual(width);
            }
            expect(
                await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                `${c.name}: no page overflow at ${width}px ${dark ? 'dark' : 'light'}`,
            ).toBe(true);
            await page
                .getByRole('columnheader', { name: c.sortHeader, exact: true })
                .scrollIntoViewIfNeeded();
            await testInfo.attach(`${c.name}-${width}-${dark ? 'dark' : 'light'}`, {
                body: await page.screenshot({ fullPage: true }),
                contentType: 'image/png',
            });
        }
    }
}

async function selectFilter(
    page: Page,
    label: string,
    option: string,
    key: string,
    submit?: string,
) {
    const select = page.getByLabel(label, { exact: true });
    await select.selectOption({ label: option });
    if (submit) await page.getByRole('button', { name: submit, exact: true }).click();
    await expectQuery(page, key, await select.inputValue());
}

const cases: TableCase[] = [
    {
        name: 'Products',
        path: '/admin/products',
        heading: 'Barang',
        search: { label: 'Search', value: 'DTC-P' },
        submit: 'Filter',
        sortHeader: 'SKU',
        sortKey: 'sku',
        sortParam: 'sort',
        directionParam: 'direction',
        first: 'DTC-P01',
        last: 'DTC-P11',
        exportPath: '/admin/products/export',
        setup: async (page) => {
            await selectFilter(page, 'Category', 'DTC Category 01', 'category_id', 'Filter');
            await selectFilter(page, 'Active', 'Active', 'is_active', 'Filter');
        },
    },
    {
        name: 'Categories',
        path: '/admin/categories',
        heading: 'Kategori',
        search: { label: 'Search', value: 'DTC Category' },
        submit: 'Filter',
        sortHeader: 'Code',
        sortKey: 'code',
        sortParam: 'sort',
        directionParam: 'direction',
        first: 'DTC-CAT-01',
        last: 'DTC-CAT-11',
        exportPath: '/admin/categories/export',
    },
    {
        // Units has an existing backend search parameter, but no search control.
        name: 'Units',
        path: '/admin/units?search=DTC%20Unit',
        heading: 'Satuan',
        sortHeader: 'Name',
        sortKey: 'name',
        sortParam: 'sort',
        directionParam: 'direction',
        first: 'DTC Unit 01',
        last: 'DTC Unit 11',
        exportPath: '/admin/units/export',
    },
    {
        name: 'Stocks',
        path: '/admin/stocks?low_stock=1',
        heading: 'Stok',
        search: { label: 'Search', value: 'DTC Product' },
        sortHeader: 'Quantity',
        sortKey: 'quantity',
        sortParam: 'sort_by',
        directionParam: 'sort_dir',
        first: 'DTC Product 01',
        last: 'DTC Product 11',
        exportPath: '/admin/stocks/export',
        setup: async (page) => {
            const location = page.getByLabel('Location', { exact: true });
            const value = await location
                .locator('option')
                .filter({ hasText: 'DTC Warehouse' })
                .getAttribute('value');
            expect(value).toBeTruthy();
            await location.selectOption(value!);
            await expectQuery(page, 'location_id', value!);
            const product = page.getByLabel('Product', { exact: true });
            const productId = await product
                .locator('option')
                .filter({ hasText: 'DTC-P01' })
                .getAttribute('value');
            expect(productId).toBeTruthy();
            await product.selectOption(productId!);
            await expectQuery(page, 'product_id', productId!);
            await expect(page.locator('tbody tr')).toHaveCount(1);
            await expect(page.locator('tbody tr').first()).toContainText('DTC Product 01');
            await product.selectOption('');
            await expect
                .poll(() => new URL(page.url()).searchParams.get('product_id') ?? '')
                .toBe('');
        },
    },
    {
        name: 'Stock Movements',
        path: '/admin/stock-movements',
        heading: 'Mutasi Stok',
        search: { label: 'Search', value: 'DTC Movement' },
        submit: 'Filter',
        sortHeader: 'Qty',
        sortKey: 'quantity',
        sortParam: 'sort_by',
        directionParam: 'sort_dir',
        first: 'DTC Movement 01',
        last: 'DTC Movement 11',
        exportPath: '/admin/stock-movements/export',
        setup: async (page) => selectFilter(page, 'Type', 'receive', 'movement_type', 'Filter'),
    },
    {
        name: 'Inventory Requests',
        path: '/admin/inventory/requests?tab=all',
        heading: 'Permintaan Barang',
        search: { label: 'Cari permintaan', value: 'DTC-REQ-' },
        sortHeader: 'No. Permintaan',
        sortKey: 'code',
        sortParam: 'sort',
        directionParam: 'direction',
        first: 'DTC-REQ-01',
        last: 'DTC-REQ-11',
        setup: async (page) => {
            await page.getByLabel('Status permintaan', { exact: true }).selectOption('draft');
            await expectQuery(page, 'status', 'draft');
        },
    },
    {
        name: 'Returns',
        path: '/admin/inventory/requests?tab=returns&search=DTC-REQ-&status=draft&sort=code&direction=desc&per_page=10&page=2',
        heading: 'Permintaan Barang',
        search: { label: 'Cari pengembalian', value: 'DTC-RET-', key: 'return_search' },
        sortHeader: 'No. Pengembalian',
        sortKey: 'id',
        sortParam: 'return_sort',
        directionParam: 'return_direction',
        pageParam: 'return_page',
        sizeParam: 'return_per_page',
        first: 'DTC-RET-01',
        last: 'DTC-RET-11',
        setup: async (page) => {
            await page
                .getByLabel('Status pengembalian', { exact: true })
                .selectOption('awaiting_receipt');
            await expectQuery(page, 'return_status', 'awaiting_receipt');
        },
    },
];

test.describe('@datatable-c replacement matrix', () => {
    test.describe.configure({ mode: 'serial' });
    test('@datatable-c Returns default page size matches fetched rows', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto(
            '/admin/inventory/requests?tab=returns&return_search=DTC-RET-&return_status=awaiting_receipt',
        );
        await expect(page.getByLabel('Rows per page', { exact: true })).toHaveValue('10');
        await expect(page.locator('tbody:visible tr')).toHaveCount(10);
        await expect(page.locator('tbody:visible tr').first()).toContainText('DTC-RET-11');
        const next = page.getByRole('button', { name: 'Next page', exact: true });
        await expect(next).toBeEnabled();
        await keyboardFocus(page, next);
        await page.keyboard.press('Enter');
        await expectQuery(page, 'return_page', '2');
        await expectQuery(page, 'return_search', 'DTC-RET-');
        await expectQuery(page, 'return_status', 'awaiting_receipt');
        await expect(page.locator('tbody:visible tr')).toHaveCount(1);
        await expect(page.locator('tbody:visible tr').first()).toContainText('DTC-RET-01');
        await page.reload();
        await expect(page.getByLabel('Rows per page', { exact: true })).toHaveValue('10');
        await expect(page.locator('tbody:visible tr')).toHaveCount(1);
        await expect(page.locator('tbody:visible tr').first()).toContainText('DTC-RET-01');
    });

    test('@datatable-c Requests default page size matches fetched rows', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto(
            '/admin/inventory/requests?tab=all&search=DTC-REQ-&status=draft&sort=code&direction=desc',
        );
        const size = page.getByLabel('Rows per page', { exact: true }).locator('visible=true');
        await expect(size).toHaveValue('10');
        await expect(page.locator('tbody:visible tr')).toHaveCount(10);
        await expect(page.locator('tbody:visible tr').first()).toContainText('DTC-REQ-11');
        const next = page.getByRole('button', { name: 'Next page', exact: true });
        await expect(next).toBeEnabled();
        await keyboardFocus(page, next);
        await page.keyboard.press('Enter');
        await expectQuery(page, 'page', '2');
        await expectQuery(page, 'search', 'DTC-REQ-');
        await expectQuery(page, 'status', 'draft');
        await expectQuery(page, 'sort', 'code');
        await expectQuery(page, 'direction', 'desc');
        await expect(page.locator('tbody:visible tr')).toHaveCount(1);
        await expect(page.locator('tbody:visible tr').first()).toContainText('DTC-REQ-01');
        await page.reload();
        await expect(size).toHaveValue('10');
        await expect(page.locator('tbody:visible tr')).toHaveCount(1);
        await expect(page.locator('tbody:visible tr').first()).toContainText('DTC-REQ-01');
    });

    for (const c of cases) {
        test(`@datatable-c ${c.name}`, async ({ page }, testInfo) => {
            await loginAs(page, 'admin');
            await exerciseTable(page, c, testInfo);
            if (c.name === 'Returns') {
                const returnState = new URL(page.url()).searchParams;
                returnState.delete('tab');
                await page
                    .getByRole('tab', { name: 'Semua Permintaan', exact: true })
                    .press('Enter');
                await expectQuery(page, 'tab', 'all');
                await expectQuerySnapshot(page, returnState);
                await expect(page.locator('tbody:visible tr')).toHaveCount(1);
                await expect(page.locator('tbody:visible tr').first()).toContainText('DTC-REQ-01');
                await expect(
                    page.getByLabel('Rows per page', { exact: true }).locator('visible=true'),
                ).toHaveValue('10');
                await page.getByRole('tab', { name: /Antrean Pengembalian/ }).press('Enter');
                await expectQuery(page, 'tab', 'returns');
                await expectQuerySnapshot(page, returnState);
                await expect(page.locator('tbody:visible tr')).toHaveCount(11);
                await expect(page.locator('tbody:visible tr').first()).toContainText(c.last);
            }
        });
    }
});
