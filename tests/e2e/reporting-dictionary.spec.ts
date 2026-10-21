import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { loginAs, logout } from './support/demo';

// Version v1 records every formula exactly as implemented (Modules/Reporting/app/Registry).
// Counts and permissions below mirror MetricRegistry::dictionary(); a new metric or a version
// bump has to update this table together with MetricRegistryTest.
const dictionaryReports = [
    {
        key: 'business',
        title: 'Metrik Bisnis',
        permission: 'report.business.view',
        version: 'business.v1',
        metrics: 13,
    },
    {
        key: 'sales',
        title: 'KPI Penjualan',
        permission: 'report.sales.view',
        version: 'sales.v1',
        metrics: 7,
    },
    {
        key: 'sla',
        title: 'Kepatuhan SLA',
        permission: 'report.sla.view',
        version: 'sla.v1',
        metrics: 16,
    },
    {
        key: 'asset',
        title: 'Pemanfaatan Aset',
        permission: 'report.asset.view',
        version: 'asset.v1',
        metrics: 5,
    },
    {
        key: 'technician',
        title: 'Kinerja Teknisi',
        permission: 'report.technician',
        version: 'technician.v1',
        metrics: 11,
    },
    {
        key: 'stock_card',
        title: 'Kartu Stok',
        permission: 'report.stock_card.view',
        version: 'stock_card.v1',
        metrics: 3,
    },
] as const;

const businessCards = [
    'MRR',
    'Pendapatan terbayar',
    'Piutang',
    'Langganan aktif',
    'Langganan ditangguhkan',
    'Kepatuhan SLA',
] as const;

// Returned by BusinessMetricsQuery but never rendered as a card (owner decision: keep 6 cards).
const hiddenBusinessMetrics = [
    'Churn',
    'Langganan baru',
    'Pendapatan berulang',
    'Pendapatan sekali bayar',
    'Langganan berhenti',
    'Pemasangan selesai',
    'Sebaran aset',
] as const;

// Seeded capability matrix (RolePermissionSeeder): effective report access is unchanged
// except that the audit-log card is no longer offered to roles without report.audit.view.
const hubRoles = {
    admin: {
        shown: [
            'Metrik Bisnis',
            'KPI Penjualan',
            'Kinerja Teknisi',
            'Pemanfaatan Aset',
            'Kepatuhan SLA',
            'Kartu Stok',
            'Log Audit',
            'Kamus Data',
            // Demo company is a Head with an active subsidiary in the E2E fixture.
            'Metrik Konsolidasi',
        ],
        hidden: [],
    },
    manager: {
        shown: [
            'Metrik Bisnis',
            'KPI Penjualan',
            'Kinerja Teknisi',
            'Pemanfaatan Aset',
            'Kepatuhan SLA',
            'Kartu Stok',
            'Kamus Data',
        ],
        hidden: ['Log Audit'],
    },
    staff: {
        shown: ['Metrik Bisnis', 'Pemanfaatan Aset', 'Kepatuhan SLA', 'Kartu Stok', 'Kamus Data'],
        hidden: ['KPI Penjualan', 'Kinerja Teknisi', 'Log Audit'],
    },
} as const;

const legacyReportPaths = [
    '/admin/reports/business',
    '/admin/reports/sla',
    '/admin/reports/asset',
    '/admin/reports/stock-card',
] as const;

async function useTheme(page: Page, width: number, dark: boolean) {
    await page.setViewportSize({ width, height: 800 });
    await page.evaluate((value) => localStorage.setItem('darkMode', String(value)), dark);
    await page.reload();
    if (dark) {
        await expect(page.locator('html')).toHaveClass(/\bdark\b/);
    } else {
        await expect(page.locator('html')).not.toHaveClass(/\bdark\b/);
    }
}

async function expectNoHorizontalOverflow(page: Page) {
    const width = page.viewportSize()!.width;
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);

    // AdminLayout clips the document, so also check that the report content itself stays
    // inside the viewport (a clipped definition would be unreadable on a phone).
    const escaping = await page.evaluate((viewportWidth) => {
        const selector = 'main h1, main h2, main h3, main p, main dl, main code, main summary';

        return Array.from(document.querySelectorAll(selector))
            .filter((element) => {
                const box = element.getBoundingClientRect();

                return box.width > 0 && (box.left < -0.5 || box.right > viewportWidth + 0.5);
            })
            .map((element) => `${element.tagName}: ${(element.textContent ?? '').slice(0, 60)}`);
    }, width);
    expect(escaping, 'elements wider than the viewport').toEqual([]);

    expect(await page.getByRole('main').evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(
        true,
    );
}

async function expectVisibleFocusRing(locator: Locator) {
    // Keyboard modality first: :focus-visible only paints after a key press, not after script focus alone.
    await locator.page().keyboard.press('Tab');
    await locator.focus();
    await expect(locator).toBeFocused();
    expect(await locator.evaluate((el) => getComputedStyle(el).boxShadow !== 'none')).toBe(true);
}

test.describe('@smoke @reporting-v2 data dictionary and formula definitions', () => {
    test('Reports hub offers each role only the reports it may open', async ({ page }) => {
        for (const role of ['admin', 'manager', 'staff'] as const) {
            await loginAs(page, role);
            await page.goto('/admin/reports');
            const main = page.getByRole('main');
            await expect(main.getByRole('heading', { name: 'Laporan', level: 1 })).toBeVisible();

            for (const name of hubRoles[role].shown) {
                await expect(main.getByRole('link', { name, exact: true })).toBeVisible();
            }
            for (const name of hubRoles[role].hidden) {
                await expect(main.getByRole('link', { name, exact: true })).toHaveCount(0);
            }
            await expect(main.getByRole('list').getByRole('link')).toHaveCount(
                hubRoles[role].shown.length,
            );

            await logout(page);
        }
    });

    test('admin reads the dictionary: every report, formula version v1, definitions and sources', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/reports');
        const main = page.getByRole('main');
        await main.getByRole('link', { name: 'Kamus Data', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/reports\/dictionary$/);
        await expect(main.getByRole('heading', { name: 'Kamus Data', level: 1 })).toBeVisible();
        await expect(page.getByRole('button', { name: /ekspor/i })).toHaveCount(0);

        const toc = main.getByRole('navigation', { name: 'Laporan dalam kamus ini' });
        for (const report of dictionaryReports) {
            await expect(
                toc.getByRole('link', { name: `${report.title} (${report.metrics})`, exact: true }),
            ).toBeVisible();

            const section = page.locator(`#dictionary-${report.key}`);
            await expect(
                section.getByRole('heading', { name: report.title, level: 2, exact: true }),
            ).toBeVisible();
            await expect(section).toContainText(`Versi rumus ${report.version}`);
            await expect(section).toContainText(`Memerlukan ${report.permission}`);
            await expect(section.getByRole('article')).toHaveCount(report.metrics);
            // Each entry shows its own version next to its key (all v1).
            await expect(section.getByText(/· v1$/)).toHaveCount(report.metrics);
        }

        // A definition is complete: meaning, formula, unit, date basis, sources, scope, caveats.
        const revenue = page
            .locator('#dictionary-business')
            .getByRole('article', { name: 'Pendapatan terbayar' });
        await expect(revenue).toHaveCount(1);
        await expect(revenue).toContainText(
            'Jumlah terbayar dari tagihan lunas yang terbit dalam periode terpilih.',
        );
        for (const label of [
            'Rumus',
            'Satuan',
            'Dasar tanggal',
            'Sumber data',
            'Cakupan',
            'Catatan penting',
        ]) {
            await expect(revenue.getByText(label, { exact: true })).toBeVisible();
        }
        await expect(revenue.locator('code', { hasText: 'invoices.issue_date' })).toBeVisible();
        await expect(revenue).toContainText(
            'Dihitung menurut tanggal terbit tagihan, bukan tanggal pembayaran.',
        );
        await expect(revenue).toContainText(
            'Per periode. Menghitung data yang invoices.issue_date jatuh di dalam rentang Dari/Sampai.',
        );

        // Sales KPI entries carry their own caveats (copied from MetricRegistry::sales()).
        const collected = page
            .locator('#dictionary-sales')
            .getByRole('article', { name: 'Terkumpul' });
        await expect(collected).toHaveCount(1);
        await expect(collected).toContainText(
            'Pembayaran yang dibatalkan tidak pernah dihitung, kapan pun pembatalannya.',
        );
        await expect(collected).toContainText(
            'Dihitung menurut tanggal pembayaran, bukan tanggal terbit tagihan: satu pembayaran bisa melunasi tagihan yang terbit sebelum rentang.',
        );

        // The table of contents is real navigation: it moves to the report section.
        const slaLink = toc.getByRole('link', { name: 'Kepatuhan SLA (16)', exact: true });
        await slaLink.focus();
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/\/admin\/reports\/dictionary#dictionary-sla$/);
        await expect(
            page
                .locator('#dictionary-sla')
                .getByRole('heading', { name: 'Kepatuhan SLA', level: 2 }),
        ).toBeInViewport();
    });

    test('Business page shows definition, basis and formula version on its 6 cards only', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/reports/business');
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'Metrik Bisnis', level: 1 })).toBeVisible();
        await expect(main.getByText('Versi rumus business.v1')).toBeVisible();

        for (const title of businessCards) {
            await expect(
                main.getByRole('heading', { name: title, level: 2, exact: true }),
            ).toBeVisible();
        }
        // The two charts under the cards (revenue and MRR, invoice status) are headed like the cards.
        await expect(main.getByRole('heading', { level: 2 })).toHaveCount(businessCards.length + 2);
        for (const title of hiddenBusinessMetrics) {
            await expect(main.getByRole('heading', { name: title, exact: true })).toHaveCount(0);
        }

        await expect(
            main.getByText(
                'Pendapatan berulang bulanan: tagihan bulanan dari semua langganan yang aktif saat ini.',
            ),
        ).toBeVisible();
        await expect(main.getByText('Posisi saat ini · v1', { exact: true })).toHaveCount(5);
        await expect(main.getByText('Per periode · v1', { exact: true })).toHaveCount(1);

        // Formula, sources and caveats are one keyboard action away on every card.
        const summaries = main.getByText('Rumus dan sumber data', { exact: true });
        await expect(summaries).toHaveCount(businessCards.length);
        const caveat = main.getByText(
            'Dihitung menurut tanggal terbit tagihan, bukan tanggal pembayaran.',
        );
        await expect(caveat).toBeHidden();
        await expectVisibleFocusRing(summaries.nth(1));
        await page.keyboard.press('Enter');
        await expect(caveat).toBeVisible();
        await expect(main.locator('code', { hasText: 'invoices.issue_date' })).toBeVisible();
        await expect(
            main.getByText(
                'Tagihan yang dibayar sebagian tidak termasuk: tagihan itu sendiri harus berstatus paid.',
            ),
        ).toBeVisible();
        await page.keyboard.press('Enter');
        await expect(caveat).toBeHidden();
    });

    test('definitions stay readable without overflow across widths and themes', async ({
        page,
    }) => {
        await loginAs(page, 'admin');

        for (const path of ['/admin/reports/business', '/admin/reports/dictionary']) {
            await page.goto(path);
            for (const width of [1280, 320]) {
                for (const dark of [false, true]) {
                    await useTheme(page, width, dark);
                    await expect(
                        page.getByRole('main').getByRole('heading', { level: 1 }),
                    ).toBeVisible();

                    // Worst case: every definition expanded.
                    const closed = page.locator('main details:not([open]) > summary');
                    while ((await closed.count()) > 0) {
                        await closed.first().click();
                    }
                    await expectNoHorizontalOverflow(page);
                }
            }
        }

        await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
    });

    test('staff keeps its report access but not the sales report or the audit log', async ({
        page,
        expectHttpError,
    }) => {
        await loginAs(page, 'staff');
        expectHttpError({ url: '/admin/reports/sales', method: 'GET', status: 403 });
        const sales = await page.goto('/admin/reports/sales');
        expect(sales?.status()).toBe(403);
        for (const path of ['/admin/reports/dictionary', ...legacyReportPaths]) {
            const response = await page.goto(path);
            expect(response?.status(), path).toBe(200);
        }
        expectHttpError({ url: '/admin/reports/audit-log', method: 'GET', status: 403 });
        const audit = await page.goto('/admin/reports/audit-log');
        expect(audit?.status()).toBe(403);
    });
});
