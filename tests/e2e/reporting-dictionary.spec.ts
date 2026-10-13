import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { loginAs, logout } from './support/demo';

// Version v1 records every formula exactly as implemented (Modules/Reporting/app/Registry).
// Counts and permissions below mirror MetricRegistry::dictionary(); a new metric or a version
// bump has to update this table together with MetricRegistryTest.
const dictionaryReports = [
    {
        key: 'business',
        title: 'Business Metrics',
        permission: 'report.business.view',
        version: 'business.v1',
        metrics: 13,
    },
    {
        key: 'sales',
        title: 'Sales KPI',
        permission: 'report.sales.view',
        version: 'sales.v1',
        metrics: 7,
    },
    {
        key: 'sla',
        title: 'SLA Compliance',
        permission: 'report.sla.view',
        version: 'sla.v1',
        metrics: 7,
    },
    {
        key: 'asset',
        title: 'Asset Utilization',
        permission: 'report.asset.view',
        version: 'asset.v1',
        metrics: 5,
    },
    {
        key: 'technician',
        title: 'Technician Performance',
        permission: 'report.technician',
        version: 'technician.v1',
        metrics: 11,
    },
    {
        key: 'stock_card',
        title: 'Stock Card',
        permission: 'report.stock_card.view',
        version: 'stock_card.v1',
        metrics: 3,
    },
] as const;

const businessCards = [
    'MRR',
    'Revenue (Paid)',
    'Outstanding',
    'Active Subscriptions',
    'Suspended Subscriptions',
    'SLA Compliance',
] as const;

// Returned by BusinessMetricsQuery but never rendered as a card (owner decision: keep 6 cards).
const hiddenBusinessMetrics = [
    'Churn',
    'New Subscriptions',
    'Recurring Revenue',
    'One-Time Revenue',
    'Terminated Subscriptions',
    'Installations Completed',
    'Asset Distribution',
] as const;

// Seeded capability matrix (RolePermissionSeeder): effective report access is unchanged
// except that the audit-log card is no longer offered to roles without report.audit.view.
const hubRoles = {
    admin: {
        shown: [
            'Open Business Metrics',
            'Open Sales KPI',
            'Open Technician Performance',
            'Open Asset Utilization',
            'Open SLA Compliance',
            'Open Stock Card',
            'Open Audit Log',
            'Open Data Dictionary',
            // Demo company is a Head with an active subsidiary in the E2E fixture.
            'Open Consolidated Metrics',
        ],
        hidden: [],
    },
    manager: {
        shown: [
            'Open Business Metrics',
            'Open Sales KPI',
            'Open Technician Performance',
            'Open Asset Utilization',
            'Open SLA Compliance',
            'Open Stock Card',
            'Open Data Dictionary',
        ],
        hidden: ['Open Audit Log'],
    },
    staff: {
        shown: [
            'Open Business Metrics',
            'Open Asset Utilization',
            'Open SLA Compliance',
            'Open Stock Card',
            'Open Data Dictionary',
        ],
        hidden: ['Open Sales KPI', 'Open Technician Performance', 'Open Audit Log'],
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
            await expect(main.getByRole('link', { name: /^Open / })).toHaveCount(
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
        await main.getByRole('link', { name: 'Open Data Dictionary', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/reports\/dictionary$/);
        await expect(
            main.getByRole('heading', { name: 'Data Dictionary', level: 1 }),
        ).toBeVisible();
        await expect(page.getByRole('button', { name: /export/i })).toHaveCount(0);

        const toc = main.getByRole('navigation', { name: 'Reports in this dictionary' });
        for (const report of dictionaryReports) {
            await expect(
                toc.getByRole('link', { name: `${report.title} (${report.metrics})`, exact: true }),
            ).toBeVisible();

            const section = page.locator(`#dictionary-${report.key}`);
            await expect(
                section.getByRole('heading', { name: report.title, level: 2, exact: true }),
            ).toBeVisible();
            await expect(section).toContainText(`Formula version ${report.version}`);
            await expect(section).toContainText(`Requires ${report.permission}`);
            await expect(section.getByRole('article')).toHaveCount(report.metrics);
            // Each entry shows its own version next to its key (all v1).
            await expect(section.getByText(/· v1$/)).toHaveCount(report.metrics);
        }

        // A definition is complete: meaning, formula, unit, date basis, sources, scope, caveats.
        const revenue = page
            .locator('#dictionary-business')
            .getByRole('article', { name: 'Revenue (Paid)' });
        await expect(revenue).toHaveCount(1);
        await expect(revenue).toContainText(
            'Paid amount of fully paid invoices issued during the selected period.',
        );
        for (const label of [
            'Formula',
            'Unit',
            'Date basis',
            'Sources',
            'Scope',
            'Known caveats',
        ]) {
            await expect(revenue.getByText(label, { exact: true })).toBeVisible();
        }
        await expect(revenue.locator('code', { hasText: 'invoices.issue_date' })).toBeVisible();
        await expect(revenue).toContainText('Dated by invoice issue date, not by payment date.');
        await expect(revenue).toContainText(
            'Period. Counts records whose invoices.issue_date falls inside the From/To range.',
        );

        // Sales KPI entries carry their own caveats (copied from MetricRegistry::sales()).
        const collected = page
            .locator('#dictionary-sales')
            .getByRole('article', { name: 'Collected' });
        await expect(collected).toHaveCount(1);
        await expect(collected).toContainText(
            'Reversed payments never count, whatever date they were reversed.',
        );
        await expect(collected).toContainText(
            'Dated by payment date, not by invoice issue date: a payment can settle an invoice issued before the range.',
        );

        // The table of contents is real navigation: it moves to the report section.
        const slaLink = toc.getByRole('link', { name: 'SLA Compliance (7)', exact: true });
        await slaLink.focus();
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/\/admin\/reports\/dictionary#dictionary-sla$/);
        await expect(
            page
                .locator('#dictionary-sla')
                .getByRole('heading', { name: 'SLA Compliance', level: 2 }),
        ).toBeInViewport();
    });

    test('Business page shows definition, basis and formula version on its 6 cards only', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/reports/business');
        const main = page.getByRole('main');
        await expect(
            main.getByRole('heading', { name: 'Business Metrics', level: 1 }),
        ).toBeVisible();
        await expect(main.getByText('Formula version business.v1')).toBeVisible();

        for (const title of businessCards) {
            await expect(
                main.getByRole('heading', { name: title, level: 3, exact: true }),
            ).toBeVisible();
        }
        await expect(main.getByRole('heading', { level: 3 })).toHaveCount(businessCards.length);
        for (const title of hiddenBusinessMetrics) {
            await expect(main.getByRole('heading', { name: title, exact: true })).toHaveCount(0);
        }

        await expect(
            main.getByText(
                'Monthly recurring revenue: the monthly charge of every subscription that is active now.',
            ),
        ).toBeVisible();
        await expect(main.getByText('Snapshot · v1', { exact: true })).toHaveCount(5);
        await expect(main.getByText('Period · v1', { exact: true })).toHaveCount(1);

        // Formula, sources and caveats are one keyboard action away on every card.
        const summaries = main.getByText('Formula and sources', { exact: true });
        await expect(summaries).toHaveCount(businessCards.length);
        const caveat = main.getByText('Dated by invoice issue date, not by payment date.');
        await expect(caveat).toBeHidden();
        await expectVisibleFocusRing(summaries.nth(1));
        await page.keyboard.press('Enter');
        await expect(caveat).toBeVisible();
        await expect(main.locator('code', { hasText: 'invoices.issue_date' })).toBeVisible();
        await expect(
            main.getByText(
                'Partially paid invoices are excluded: the invoice itself must have status paid.',
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

    test('roles without the report capability get 403, staff keeps its access but never the audit log', async ({
        page,
        expectHttpError,
    }) => {
        await loginAs(page, 'technician');
        for (const path of [
            '/admin/reports/dictionary',
            '/admin/reports/sales',
            ...legacyReportPaths,
        ]) {
            expectHttpError({ url: path, method: 'GET', status: 403 });
            const response = await page.goto(path);
            expect(response?.status(), path).toBe(403);
        }
        await logout(page);

        await loginAs(page, 'staff');
        // A distinct query keeps this 403 attributable apart from the technician's visit above.
        expectHttpError({ url: '/admin/reports/sales?as=staff', method: 'GET', status: 403 });
        const sales = await page.goto('/admin/reports/sales?as=staff');
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
