import type { Locator, Page, TestInfo } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { loginAs } from './support/demo';

type TableCase = {
    name: string;
    path: string;
    sortHeader: string;
    prefix: string;
    filters: { label: string; key: string; value: string }[];
};

const cases: TableCase[] = [
    {
        name: 'Tiket',
        path: '/admin/tickets',
        sortHeader: 'Nomor',
        prefix: 'DTD-TICKET-',
        filters: [
            { label: 'Prioritas', key: 'priority', value: 'high' },
            { label: 'Status', key: 'status', value: 'open' },
        ],
    },
    {
        name: 'SPK',
        path: '/admin/spk',
        sortHeader: 'Nomor',
        prefix: 'DTD-SPK-',
        filters: [
            { label: 'Jenis', key: 'type', value: 'maintenance' },
            { label: 'Status', key: 'status', value: 'generated' },
        ],
    },
    {
        name: 'Aset Jaringan',
        path: '/admin/network-assets',
        sortHeader: 'Kode',
        prefix: 'DTD-ASSET-',
        filters: [
            { label: 'Tipe', key: 'asset_type', value: 'onu_ont' },
            { label: 'Status', key: 'status', value: 'available' },
        ],
    },
];

async function expectQuery(page: Page, key: string, value: string) {
    await expect.poll(() => new URL(page.url()).searchParams.get(key)).toBe(value);
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

async function exportParity(page: Page, c: TableCase) {
    await keyboardFocus(page, page.getByRole('button', { name: 'Ekspor', exact: true }));
    await page.keyboard.press('Enter');
    await expect(page.getByRole('menuitem', { name: 'Ekspor CSV', exact: true })).toBeVisible();
    await expect(page.getByRole('menuitem', { name: 'Ekspor PDF', exact: true })).toBeVisible();
    await expect(page.getByRole('menuitem', { name: 'Ekspor Excel', exact: true })).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(page.getByRole('menuitem', { name: 'Ekspor CSV', exact: true })).toBeHidden();

    // Attachment navigation emits ERR_ABORTED under the guarded browser fixture.
    const query = new URL(page.url()).searchParams;
    query.delete('page');
    query.set('format', 'csv');
    const csv = await page.request.get(`${c.path}/export?${query}`);
    expect(csv.status()).toBe(200);
    expect(csv.headers()['content-type']).toContain('text/csv');
    expect(csv.headers()['content-disposition']).toContain('attachment');
    const rows = (await csv.text()).trim().split(/\r?\n/).slice(1);
    expect(rows).toHaveLength(11);
    for (let index = 0; index < 11; index += 1) {
        expect(rows[index]).toContain(`${c.prefix}${String(11 - index).padStart(2, '0')}`);
    }
    query.set('format', 'pdf');
    const pdf = await page.request.get(`${c.path}/export?${query}`);
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect(pdf.headers()['content-disposition']).toContain('attachment');
    expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');
}

async function exerciseTable(page: Page, c: TableCase, testInfo: TestInfo) {
    await page.goto(`${c.path}?sort_by=code&sort_dir=desc`);
    await expect(
        page.getByRole('main').getByRole('heading', { name: c.name, exact: true }),
    ).toBeVisible();
    const search = page.getByLabel('Cari', { exact: true });
    await search.fill(c.prefix);
    await expectQuery(page, 'search', c.prefix);
    for (const filter of c.filters) {
        await page.getByLabel(filter.label, { exact: true }).selectOption(filter.value);
        await expectQuery(page, filter.key, filter.value);
    }
    const rows = page.locator('tbody:visible tr');
    const size = page.getByLabel('Baris per halaman', { exact: true }).locator('visible=true');
    const header = page.getByRole('columnheader', { name: c.sortHeader, exact: true });
    await expect(size).toHaveValue('10');
    await expect(rows).toHaveCount(10);
    for (const direction of ['asc', 'desc'] as const) {
        await keyboardFocus(page, header.getByRole('button'));
        await page.keyboard.press(direction === 'asc' ? 'Enter' : 'Space');
        await expectQuery(page, 'sort_by', 'code');
        await expectQuery(page, 'sort_dir', direction);
        await expect(header).toHaveAttribute(
            'aria-sort',
            direction === 'asc' ? 'ascending' : 'descending',
        );
        await expect(rows.first()).toContainText(`${c.prefix}${direction === 'asc' ? '01' : '11'}`);
    }
    await size.selectOption('10');
    await expectQuery(page, 'per_page', '10');
    const preserved = new URL(page.url()).searchParams;
    preserved.delete('page');
    await keyboardFocus(
        page,
        page.getByRole('button', { name: 'Halaman berikutnya', exact: true }),
    );
    await page.keyboard.press('Enter');
    await expectQuery(page, 'page', '2');
    await expectQuerySnapshot(page, preserved);
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText(`${c.prefix}01`);
    await page.reload();
    await expectQuerySnapshot(page, preserved);
    await expectQuery(page, 'page', '2');
    await expect(size).toHaveValue('10');
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText(`${c.prefix}01`);
    await exportParity(page, c);
    await keyboardFocus(
        page,
        page.getByRole('button', { name: 'Halaman sebelumnya', exact: true }),
    );
    await page.keyboard.press('Enter');
    await expectQuery(page, 'page', '1');
    await expect(rows.first()).toContainText(`${c.prefix}11`);
    await size.selectOption('25');
    await expectQuery(page, 'per_page', '25');
    await expect(rows).toHaveCount(11);
    for (let index = 0; index < 11; index += 1) {
        await expect(rows.nth(index)).toContainText(
            `${c.prefix}${String(11 - index).padStart(2, '0')}`,
        );
    }
    preserved.set('per_page', '25');
    await expectQuerySnapshot(page, preserved);
    for (const width of [1280, 320]) {
        for (const dark of [false, true]) {
            await page.setViewportSize({ width, height: 800 });
            await page.evaluate((value) => localStorage.setItem('darkMode', String(value)), dark);
            await page.reload();
            await expect(page.locator('html')).toHaveClass(dark ? /\bdark\b/ : /^(?!.*\bdark\b)/);
            await expectQuerySnapshot(page, preserved);
            await expect(search).toHaveValue(c.prefix);
            await expect(size).toHaveValue('25');
            await expect(rows).toHaveCount(11);
            await expect(rows.first()).toContainText(`${c.prefix}11`);
            await keyboardFocus(page, header.getByRole('button'));
            const controls = [search, size];
            for (const filter of c.filters) {
                const control = page.getByLabel(filter.label, { exact: true });
                await expect(control).toHaveValue(filter.value);
                controls.push(control);
            }
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
            await header.scrollIntoViewIfNeeded();
            await testInfo.attach(
                `${c.name.replaceAll('/', '-')}-${width}-${dark ? 'dark' : 'light'}`,
                {
                    body: await page.screenshot({ fullPage: true }),
                    contentType: 'image/png',
                },
            );
        }
    }
}

test.describe('@datatable-d replacement matrix', () => {
    test.describe.configure({ mode: 'serial' });
    for (const c of cases) {
        test(`@datatable-d ${c.name}`, async ({ page }, testInfo) => {
            await loginAs(page, 'admin');
            await exerciseTable(page, c, testInfo);
        });
    }
});
