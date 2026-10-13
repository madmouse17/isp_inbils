import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { e2eCustomerName, inertiaProps, loginAs, refreshSession, unique } from './support/demo';

/**
 * DataTable A+B replacement contract (approved 11-index matrix).
 *
 * Per page: search reaches the server, a sortable header works from the
 * keyboard in both directions against the backend allowlist, page size
 * round-trips and survives reload with the rest of the query, the export
 * download (only where an endpoint exists) carries the same query and rows,
 * and the page holds at 320px/1280px in light and dark mode.
 *
 * Seed data comes from DatabaseSeeder + DemoUserSeeder + E2eFixtureSeeder.
 * Organizations and Invoices have no seed rows, so those cases create owned
 * rows through the application routes and delete them afterwards.
 */

type SortParams = { sort: string; direction: string };

type Case = {
    name: string;
    path: string;
    /** Initial query string (without `?`) that scopes the page to owned rows. */
    query?: string;
    heading: string;
    search: string;
    /** Customers submits filters with an explicit button instead of debounce. */
    submit?: string;
    sortHeader: string;
    sortKey: string;
    params: SortParams;
    ascFirst: string;
    descFirst: string;
    exportable: boolean;
};

const SERVER: SortParams = { sort: 'sort', direction: 'direction' };
const QUERY: SortParams = { sort: 'sort_by', direction: 'sort_dir' };

const seededCases: Case[] = [
    {
        name: 'Users',
        path: '/admin/users',
        heading: 'Pengguna',
        search: 'Demo',
        sortHeader: 'Name',
        sortKey: 'name',
        params: SERVER,
        ascFirst: 'Demo Admin',
        descFirst: 'Demo Technician',
        exportable: true,
    },
    {
        name: 'Roles',
        path: '/admin/roles',
        heading: 'Peran',
        search: 'a',
        sortHeader: 'Name',
        sortKey: 'name',
        params: SERVER,
        ascFirst: 'admin',
        descFirst: 'technician',
        exportable: true,
    },
    {
        name: 'Permissions',
        path: '/admin/permissions',
        heading: 'Hak Akses',
        search: 'billing.',
        sortHeader: 'Name',
        sortKey: 'name',
        params: SERVER,
        ascFirst: 'billing.cancel',
        descFirst: 'billing.view',
        exportable: false,
    },
    {
        name: 'Customers',
        path: '/admin/customers',
        heading: 'Pelanggan',
        search: 'Fiber',
        submit: 'Filter',
        sortHeader: 'Name',
        sortKey: 'name',
        params: SERVER,
        ascFirst: 'Alto Fiber',
        descFirst: 'Bima Fiber',
        exportable: true,
    },
    {
        name: 'Employees',
        path: '/admin/employees',
        heading: 'Karyawan',
        search: 'DEMO-',
        sortHeader: 'Emp No',
        sortKey: 'employee_number',
        params: SERVER,
        ascFirst: 'DEMO-MANAGER',
        descFirst: 'DEMO-TECHNICIAN',
        exportable: true,
    },
    {
        name: 'Service Packages',
        path: '/admin/service-packages',
        heading: 'Paket Layanan',
        search: 'PKG-BIZ',
        sortHeader: 'Monthly',
        sortKey: 'price_mrc',
        params: SERVER,
        ascFirst: 'Business 100Mbps',
        descFirst: 'Business 200Mbps',
        exportable: true,
    },
    {
        name: 'Bandwidth Profiles',
        path: '/admin/bandwidth-profiles',
        heading: 'Profil Bandwidth',
        search: 'Home',
        sortHeader: 'Download (Mbps)',
        sortKey: 'download_mbps',
        params: SERVER,
        ascFirst: 'Home 10Mbps',
        descFirst: 'Home 50Mbps',
        exportable: true,
    },
    {
        name: 'Speed Profiles',
        path: '/admin/speed-profiles',
        heading: 'Profil Kecepatan',
        search: 'SP-Home',
        sortHeader: 'Download (Mbps)',
        sortKey: 'download_max_mbps',
        params: SERVER,
        ascFirst: 'SP-Home-10',
        descFirst: 'SP-Home-50',
        exportable: true,
    },
    {
        name: 'SLA Tiers',
        path: '/admin/sla-tiers',
        heading: 'Tingkat SLA',
        search: 'l',
        sortHeader: 'Response (h)',
        sortKey: 'response_time_hours',
        params: SERVER,
        ascFirst: 'Gold',
        descFirst: 'Silver',
        exportable: true,
    },
];

function query(page: Page, key: string) {
    return new URL(page.url()).searchParams.get(key);
}

async function expectQuery(page: Page, key: string, value: string) {
    await expect.poll(() => query(page, key), { message: `query ${key}` }).toBe(value);
}

async function sortWithKeyboard(page: Page, c: Case, direction: 'asc' | 'desc') {
    const header = page.getByRole('columnheader', { name: c.sortHeader }).getByRole('button');
    await header.focus();
    await expect(header).toBeFocused();
    await page.keyboard.press('Enter');
    await expectQuery(page, c.params.sort, c.sortKey);
    await expectQuery(page, c.params.direction, direction);
    await expect(page.getByRole('columnheader', { name: c.sortHeader })).toHaveAttribute(
        'aria-sort',
        direction === 'asc' ? 'ascending' : 'descending',
    );
    await expect(page.locator('tbody tr').first()).toContainText(
        direction === 'asc' ? c.ascFirst : c.descFirst,
    );
}

async function exerciseTable(page: Page, c: Case) {
    await page.goto(c.query ? `${c.path}?${c.query}` : c.path);
    const heading = page.getByRole('main').getByRole('heading', { name: c.heading, exact: true });
    await expect(heading).toBeVisible();

    const search = page.getByLabel('Search', { exact: true });
    await search.fill(c.search);
    if (c.submit) await page.getByRole('button', { name: c.submit, exact: true }).click();
    await expectQuery(page, 'search', c.search);
    await expect(page.locator('tbody tr').filter({ hasText: c.ascFirst }).first()).toBeVisible();

    await sortWithKeyboard(page, c, 'asc');
    await sortWithKeyboard(page, c, 'desc');

    const rowsBefore = await page.locator('tbody tr').count();
    const perPage = page.getByLabel('Rows per page');
    await perPage.selectOption('25');
    await expectQuery(page, 'per_page', '25');
    await expectQuery(page, c.params.sort, c.sortKey);
    await expectQuery(page, 'search', c.search);
    await expect(page.locator('tbody tr').first()).toContainText(c.descFirst);
    expect(await page.locator('tbody tr').count()).toBeGreaterThanOrEqual(rowsBefore);

    await page.reload();
    await expect(page.getByLabel('Rows per page')).toHaveValue('25');
    await expect(page.getByLabel('Search', { exact: true })).toHaveValue(c.search);
    await expect(page.getByRole('columnheader', { name: c.sortHeader })).toHaveAttribute(
        'aria-sort',
        'descending',
    );
    await expect(page.locator('tbody tr').first()).toContainText(c.descFirst);

    if (c.exportable) {
        const menu = page.getByRole('button', { name: 'Export', exact: true });
        await menu.focus();
        await page.keyboard.press('Enter');
        await expect(page.getByRole('menuitem', { name: 'Export CSV' })).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(page.getByRole('menuitem', { name: 'Export CSV' })).toBeHidden();

        // Export parity: the endpoint receives the exact query the index shows.
        const query = new URL(page.url()).searchParams;
        query.delete('page');
        query.set('format', 'csv');
        const response = await page.request.get(`${c.path}/export?${query.toString()}`);
        expect(response.status(), `${c.name} export status`).toBe(200);
        expect(response.headers()['content-type']).toContain('text/csv');
        const csv = await response.text();
        expect(csv).toContain(c.ascFirst);
        expect(csv).toContain(c.descFirst);
        expect(csv.indexOf(c.descFirst)).toBeLessThan(csv.indexOf(c.ascFirst));
    }

    for (const width of [1280, 320]) {
        for (const dark of [false, true]) {
            await page.setViewportSize({ width, height: 800 });
            await page.evaluate((value) => localStorage.setItem('darkMode', String(value)), dark);
            await page.reload();
            await expect(page.locator('html')).toHaveClass(dark ? /\bdark\b/ : /^(?!.*\bdark\b)/);
            await expect(heading).toBeVisible();
            const control = page.getByLabel('Rows per page');
            await control.scrollIntoViewIfNeeded();
            const box = await control.boundingBox();
            expect(box, `${c.name} rows-per-page box at ${width}`).not.toBeNull();
            expect(box!.x).toBeGreaterThanOrEqual(0);
            expect(box!.x + box!.width).toBeLessThanOrEqual(width);
            expect(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= window.innerWidth,
                ),
                `${c.name} horizontal overflow at ${width}px ${dark ? 'dark' : 'light'}`,
            ).toBe(true);
        }
    }
    await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
}

test.describe('@datatable-ab replacement matrix', () => {
    test.describe.configure({ mode: 'serial' });

    for (const c of seededCases) {
        test(`@datatable-ab ${c.name}`, async ({ page }) => {
            await loginAs(page, 'admin');
            await exerciseTable(page, c);
        });
    }

    test('@datatable-ab Organizations', async ({ page }) => {
        await loginAs(page, 'admin');
        const prefix = unique('DTAB').toUpperCase();
        const codes = [`${prefix}-A`, `${prefix}-B`];
        const created: number[] = [];
        try {
            for (const code of codes) {
                const response = await page.request.post('/admin/organizations', {
                    headers: { 'X-XSRF-TOKEN': await refreshSession(page, '/admin/organizations') },
                    maxRedirects: 0,
                    form: {
                        code,
                        name: `DataTable ${code}`,
                        type: 'team',
                        parent_id: '',
                        is_active: '1',
                    },
                });
                expect(response.status(), `Create organization ${code}`).toBe(302);
                const { props } = await inertiaProps(
                    page,
                    `/admin/organizations?search=${encodeURIComponent(code)}`,
                );
                const match = (props?.organizations?.data ?? []).filter(
                    (row: { code: string }) => row.code === code,
                );
                expect(match, `Owned organization ${code}`).toHaveLength(1);
                created.push(match[0].id);
            }

            await exerciseTable(page, {
                name: 'Organizations',
                path: '/admin/organizations',
                heading: 'Organisasi',
                search: prefix,
                sortHeader: 'Code',
                sortKey: 'code',
                params: SERVER,
                ascFirst: codes[0],
                descFirst: codes[1],
                exportable: true,
            });
        } finally {
            for (const id of created.reverse()) {
                const response = await page.request.delete(`/admin/organizations/${id}`, {
                    headers: { 'X-XSRF-TOKEN': await refreshSession(page, '/admin/organizations') },
                    maxRedirects: 0,
                });
                expect(response.status(), `Delete owned organization ${id}`).toBe(302);
            }
        }
    });

    test('@datatable-ab Invoices', async ({ page }) => {
        await loginAs(page, 'admin');
        const index = await inertiaProps(page, '/admin/invoices');
        const customer = (index.props?.customers?.data ?? []).find(
            (row: { name: string }) => row.name === e2eCustomerName,
        );
        expect(customer, `Seeded customer ${e2eCustomerName}`).toBeTruthy();

        // A dedicated far-future issue-date window isolates the owned drafts
        // from invoices other journeys create for the same seeded customer.
        const range = `date_from=2031-01-01&date_to=2031-01-31&customer_id=${customer.id}`;
        const ownedInvoices = async () => {
            const { props } = await inertiaProps(page, `/admin/invoices?${range}&per_page=50`);
            return (props?.invoices?.data ?? []) as Array<{ id: number; number: string }>;
        };
        expect(await ownedInvoices(), 'Owned invoice window starts empty').toHaveLength(0);

        try {
            for (const [issue, due] of [
                ['2031-01-10', '2031-01-24'],
                ['2031-01-11', '2031-01-25'],
            ]) {
                const response = await page.request.post('/admin/invoices', {
                    headers: { 'X-XSRF-TOKEN': await refreshSession(page, '/admin/invoices') },
                    maxRedirects: 0,
                    form: { customer_id: String(customer.id), issue_date: issue, due_date: due },
                });
                expect(response.status(), `Create draft invoice ${issue}`).toBe(302);
            }
            const created = await ownedInvoices();
            expect(created, 'Two owned draft invoices').toHaveLength(2);

            const [first, second] = [...created].sort((a, b) => a.number.localeCompare(b.number));
            await exerciseTable(page, {
                name: 'Invoices',
                path: '/admin/invoices',
                query: range,
                heading: 'Tagihan',
                search: e2eCustomerName,
                sortHeader: 'Number',
                sortKey: 'number',
                params: QUERY,
                ascFirst: first.number,
                descFirst: second.number,
                exportable: true,
            });
        } finally {
            // The window was asserted empty before creation, so every row in it
            // is owned by this test, including any created before a failure.
            for (const invoice of await ownedInvoices()) {
                const response = await page.request.delete(`/admin/invoices/${invoice.id}`, {
                    headers: { 'X-XSRF-TOKEN': await refreshSession(page, '/admin/invoices') },
                    maxRedirects: 0,
                });
                expect(response.status(), `Delete owned draft ${invoice.number}`).toBe(302);
            }
            expect(
                await ownedInvoices(),
                'Owned invoice window is empty after cleanup',
            ).toHaveLength(0);
        }
    });
});
