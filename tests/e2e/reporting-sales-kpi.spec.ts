import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { loginAs, logout } from './support/demo';

// Fixtures: E2eFixtureSeeder::seedSalesKpiFixtures (E2E-KPI-* customers, subscriptions, invoices
// and payments with fixed dates in February and March 2025). Every expected number below is
// derived row by row in the comment block above that method.
// Not asserted exactly: Active MRR and Outstanding (snapshot figures other specs may change).

type Figures = {
    customers: number;
    subscriptions: number;
    invoiced: number;
    collected: number;
    rate: string;
};

const manager = 'Demo Manager';
const staff = 'Demo Staff';
const febUrl = '/admin/reports/sales?date_from=2025-02-01&date_to=2025-02-28&per_page=50';

const febManager: Figures = {
    customers: 2,
    subscriptions: 2,
    invoiced: 2_500_000,
    collected: 2_300_000,
    rate: '92.00%',
};
const febStaff: Figures = {
    customers: 1,
    subscriptions: 1,
    invoiced: 600_000,
    collected: 400_000,
    rate: '66.67%',
};
const febUnassigned: Figures = {
    customers: 1,
    subscriptions: 1,
    invoiced: 300_000,
    collected: 300_000,
    rate: '100.00%',
};
const febTotal: Figures = {
    customers: 4,
    subscriptions: 4,
    invoiced: 3_400_000,
    collected: 3_000_000,
    rate: '88.24%',
};
const none: Figures = { customers: 0, subscriptions: 0, invoiced: 0, collected: 0, rate: 'n/a' };

const metricLabels = [
    'Customers Acquired',
    'Subscriptions Activated',
    'Active MRR',
    'Invoiced',
    'Collected',
    'Outstanding',
    'Collection Rate',
] as const;

const hubRoles = ['admin', 'manager'] as const;
const deniedRoles = ['staff', 'technician', 'customer'] as const;

const squash = (text: string) => text.replace(/\s+/g, ' ').trim();
const digits = (text: string) => Number(text.replace(/\D/g, ''));
// Intl id-ID currency: "Rp" + NBSP + dot-grouped digits; \s covers the NBSP.
const rupiah = /^Rp ?\d{1,3}(\.\d{3})*$/;

function query(page: Page, key: string) {
    return new URL(page.url()).searchParams.get(key);
}

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

async function expectFocusIndicator(locator: Locator) {
    await locator.page().keyboard.press('Tab');
    await locator.focus();
    await expect(locator).toBeFocused();
    expect(
        await locator.evaluate((el) => {
            const style = getComputedStyle(el);

            return style.outlineStyle !== 'none' || style.boxShadow !== 'none';
        }),
    ).toBe(true);
}

const bodyRow = (page: Page, name: string) =>
    page
        .locator('tbody')
        .getByRole('row')
        .filter({ has: page.getByRole('cell', { name, exact: true }) });
const footRow = (page: Page, name: 'Unassigned' | 'Total') =>
    page
        .locator('tfoot')
        .getByRole('row')
        .filter({ has: page.getByRole('cell', { name, exact: true }) });

async function cellsOf(row: Locator): Promise<string[]> {
    return (await row.getByRole('cell').allInnerTexts()).map(squash);
}

function figuresOf(cells: string[]): Figures {
    return {
        customers: Number(cells[1]),
        subscriptions: Number(cells[2]),
        invoiced: digits(cells[4] ?? ''),
        collected: digits(cells[5] ?? ''),
        rate: cells[7] ?? '',
    };
}

async function expectFigures(row: Locator, expected: Figures) {
    await expect(row).toHaveCount(1);
    await expect.poll(async () => figuresOf(await cellsOf(row))).toEqual(expected);

    // Snapshot figures are only checked for shape: other specs may change them.
    const cells = await cellsOf(row);
    expect(cells, 'Acquirer + 7 metric cells').toHaveLength(8);
    expect(cells[3]).toMatch(rupiah);
    expect(cells[6]).toMatch(rupiah);
}

const totalCard = (page: Page, label: string) =>
    page
        .getByRole('region', { name: 'Totals' })
        .locator(':scope > div > div')
        .filter({ has: page.getByRole('heading', { name: label, exact: true }) });

async function bodyNames(page: Page): Promise<string[]> {
    return (await page.locator('tbody tr td:first-child').allInnerTexts()).map(squash);
}

function before(names: string[], first: string, second: string): boolean {
    const a = names.indexOf(first);
    const b = names.indexOf(second);

    return a >= 0 && b >= 0 && a < b;
}

test.describe('@smoke @kpi-sales sales KPI report', () => {
    test('manager reads the February figures per acquirer, Unassigned and Total', async ({
        page,
    }) => {
        await loginAs(page, 'manager');
        await page.goto(febUrl);
        const main = page.getByRole('main');

        await expect(main.getByRole('heading', { name: 'Sales KPI', level: 1 })).toBeVisible();
        await expect(
            main.getByText(
                'Customers acquired, invoiced, collected and outstanding per acquirer.',
                { exact: true },
            ),
        ).toBeVisible();
        await expect(main.getByText('Formula version sales.v1')).toBeVisible();
        await expect(
            main.getByText('Period: 2025-02-01 to 2025-02-28, both days included.', {
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            main.getByText(
                "Each figure follows the customer's current acquirer. Correcting an acquirer moves that customer's whole history, so past periods can change.",
                { exact: true },
            ),
        ).toBeVisible();
        await expect(
            main.getByText(
                'Collected counts every non-reversed payment by payment date. It does not match Business Metrics Revenue (Paid), which counts fully paid invoices by issue date.',
                { exact: true },
            ),
        ).toBeVisible();
        await expect(page.getByRole('button', { name: /export/i })).toHaveCount(0);

        // Seven total cards, each with its definition, basis and version.
        const totals = main.getByRole('region', { name: 'Totals' });
        for (const label of metricLabels) {
            await expect(totals.getByRole('heading', { name: label, exact: true })).toBeVisible();
        }
        await expect(totals.getByRole('heading')).toHaveCount(metricLabels.length);
        await expect(totals.getByText('Period · v1', { exact: true })).toHaveCount(5);
        await expect(totals.getByText('Snapshot · v1', { exact: true })).toHaveCount(2);
        const summaries = totals.getByText('Formula and sources', { exact: true });
        await expect(summaries).toHaveCount(metricLabels.length);

        await expect(totalCard(page, 'Customers Acquired').locator('p').first()).toHaveText('4');
        await expect(totalCard(page, 'Subscriptions Activated').locator('p').first()).toHaveText(
            '4',
        );
        await expect(totalCard(page, 'Collection Rate').locator('p').first()).toHaveText('88.24%');
        for (const [label, amount] of [
            ['Invoiced', febTotal.invoiced],
            ['Collected', febTotal.collected],
        ] as const) {
            const value = squash(await totalCard(page, label).locator('p').first().innerText());
            expect(value, label).toMatch(rupiah);
            expect(digits(value), label).toBe(amount);
        }
        for (const label of ['Active MRR', 'Outstanding']) {
            expect(squash(await totalCard(page, label).locator('p').first().innerText())).toMatch(
                rupiah,
            );
        }

        // Formula and sources open by keyboard and show the date column of the metric.
        const invoiced = totalCard(page, 'Invoiced');
        const source = invoiced.locator('code', { hasText: 'invoices.issue_date' });
        await expect(source).toBeHidden();
        const summary = invoiced.getByText('Formula and sources', { exact: true });
        await summary.focus();
        await expect(summary).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(source).toBeVisible();
        await page.keyboard.press('Enter');
        await expect(source).toBeHidden();

        // Rows: exact period figures per acquirer and for Unassigned.
        await expect(
            page.getByRole('columnheader', { name: 'Acquirer', exact: true }),
        ).toHaveAttribute('aria-sort', 'ascending');
        await expectFigures(bodyRow(page, manager), febManager);
        await expectFigures(bodyRow(page, staff), febStaff);
        await expectFigures(footRow(page, 'Unassigned'), febUnassigned);
        await expectFigures(footRow(page, 'Total'), febTotal);

        // Total reconciles with every visible row plus Unassigned.
        await expect(page.getByText(/^Showing /)).toBeVisible();
        const visible = await page.locator('tbody tr').count();
        const summed: Figures = {
            customers: 0,
            subscriptions: 0,
            invoiced: 0,
            collected: 0,
            rate: '',
        };
        const cells = [
            ...(await Promise.all(
                Array.from({ length: visible }, (_, index) =>
                    cellsOf(page.locator('tbody tr').nth(index)),
                ),
            )),
            await cellsOf(footRow(page, 'Unassigned')),
        ];
        for (const row of cells) {
            const figures = figuresOf(row);
            summed.customers += figures.customers;
            summed.subscriptions += figures.subscriptions;
            summed.invoiced += figures.invoiced;
            summed.collected += figures.collected;
        }
        const total = figuresOf(await cellsOf(footRow(page, 'Total')));
        expect({ ...summed, rate: total.rate }).toEqual(total);
        expect(total).toEqual(febTotal);
    });

    test('acquirer filter narrows the table and drops the Unassigned row', async ({ page }) => {
        await loginAs(page, 'manager');
        await page.goto(febUrl);
        const select = page.getByRole('combobox', { name: 'Acquirer' });
        const run = page.getByRole('button', { name: 'Run', exact: true });
        await expect(bodyRow(page, staff)).toHaveCount(1);

        await select.selectOption({ label: staff });
        await run.click();
        await expect.poll(() => query(page, 'acquirer_id')).toMatch(/^\d+$/);
        expect(query(page, 'date_from')).toBe('2025-02-01');
        expect(query(page, 'date_to')).toBe('2025-02-28');
        await expect(page.locator('tbody tr')).toHaveCount(1);
        await expect(bodyRow(page, staff)).toHaveCount(1);
        await expect(bodyRow(page, manager)).toHaveCount(0);
        await expect(footRow(page, 'Unassigned')).toHaveCount(0);
        await expectFigures(bodyRow(page, staff), febStaff);
        await expectFigures(footRow(page, 'Total'), febStaff);

        const rowCells = (await cellsOf(bodyRow(page, staff))).slice(1);
        expect((await cellsOf(footRow(page, 'Total'))).slice(1)).toEqual(rowCells);
        await expect(totalCard(page, 'Customers Acquired').locator('p').first()).toHaveText('1');

        await select.selectOption({ label: 'All acquirers' });
        await run.click();
        await expect.poll(() => query(page, 'acquirer_id')).toBeNull();
        await expect(bodyRow(page, manager)).toHaveCount(1);
        await expect(bodyRow(page, staff)).toHaveCount(1);
        await expectFigures(footRow(page, 'Unassigned'), febUnassigned);
        await expectFigures(footRow(page, 'Total'), febTotal);
    });

    test('period form changes the figures, a lone From means all time, a reversed range is rejected', async ({
        page,
    }) => {
        await loginAs(page, 'manager');
        await page.goto(febUrl);
        const from = page.getByLabel('From', { exact: true });
        const to = page.getByLabel('To', { exact: true });
        const run = page.getByRole('button', { name: 'Run', exact: true });
        const main = page.getByRole('main');
        await expectFigures(bodyRow(page, manager), febManager);

        // March: collected (payments dated in March) can exceed invoiced; no invoices means n/a.
        await from.fill('2025-03-01');
        await to.fill('2025-03-31');
        await run.click();
        await expect.poll(() => query(page, 'date_from')).toBe('2025-03-01');
        expect(query(page, 'date_to')).toBe('2025-03-31');
        await expect(
            main.getByText('Period: 2025-03-01 to 2025-03-31, both days included.', {
                exact: true,
            }),
        ).toBeVisible();
        await expectFigures(bodyRow(page, manager), {
            customers: 1,
            subscriptions: 1,
            invoiced: 250_000,
            collected: 750_000,
            rate: '300.00%',
        });
        await expectFigures(bodyRow(page, staff), { ...none, collected: 200_000 });

        // First and last day of February are inclusive down to the first and last second.
        const edgeDays: [string, Figures][] = [
            [
                '2025-02-01',
                {
                    customers: 1,
                    subscriptions: 1,
                    invoiced: 1_000_000,
                    collected: 600_000,
                    rate: '60.00%',
                },
            ],
            [
                '2025-02-28',
                {
                    customers: 1,
                    subscriptions: 1,
                    invoiced: 500_000,
                    collected: 400_000,
                    rate: '80.00%',
                },
            ],
        ];
        for (const [day, expected] of edgeDays) {
            await page.goto(`/admin/reports/sales?date_from=${day}&date_to=${day}&per_page=50`);
            await expectFigures(bodyRow(page, manager), expected);
            await expectFigures(bodyRow(page, staff), none);
        }

        // A lone From is ignored: same figures as the unfiltered report.
        await page.goto('/admin/reports/sales?per_page=50');
        await expect(
            main.getByText('Period: all time. Set both From and To to limit the period.', {
                exact: true,
            }),
        ).toBeVisible();
        await expect(bodyRow(page, manager)).toHaveCount(1);
        const allTime = await cellsOf(bodyRow(page, manager));
        await from.fill('2025-03-01');
        await run.click();
        await expect.poll(() => query(page, 'date_from')).toBe('2025-03-01');
        expect(query(page, 'date_to')).toBeNull();
        await expect(
            main.getByText('Period: all time. Set both From and To to limit the period.', {
                exact: true,
            }),
        ).toBeVisible();
        expect(await cellsOf(bodyRow(page, manager))).toEqual(allTime);

        // To before From: the server message sits under To and the figures stay.
        await to.fill('2025-02-01');
        await run.click();
        await expect(to).toHaveAttribute('aria-invalid', 'true');
        await expect(to).toHaveAccessibleDescription(/after or equal to date from/i);
        await expect(from).not.toHaveAttribute('aria-invalid', 'true');
        expect(await cellsOf(bodyRow(page, manager))).toEqual(allTime);
    });

    test('sorting and rows per page are server side and keep the filters', async ({ page }) => {
        await loginAs(page, 'manager');
        await page.goto(febUrl);
        await expect(bodyRow(page, manager)).toHaveCount(1);

        // Collected: Demo Staff (400000) before Demo Manager (2300000) ascending.
        const collected = page.getByRole('columnheader', { name: 'Collected', exact: true });
        await collected.getByRole('button').click();
        await expect.poll(() => query(page, 'sort')).toBe('collected');
        expect(query(page, 'direction')).toBe('asc');
        await expect(collected).toHaveAttribute('aria-sort', 'ascending');
        await expect.poll(async () => before(await bodyNames(page), staff, manager)).toBe(true);

        await collected.getByRole('button').click();
        await expect.poll(() => query(page, 'direction')).toBe('desc');
        await expect(collected).toHaveAttribute('aria-sort', 'descending');
        await expect.poll(async () => before(await bodyNames(page), manager, staff)).toBe(true);

        // Acquirer name: ascending puts Demo Manager first, descending Demo Staff.
        const acquirer = page.getByRole('columnheader', { name: 'Acquirer', exact: true });
        await acquirer.getByRole('button').click();
        await expect.poll(() => query(page, 'sort')).toBe('name');
        await expect.poll(() => query(page, 'direction')).toBe('asc');
        await expect(acquirer).toHaveAttribute('aria-sort', 'ascending');
        await expect(collected).not.toHaveAttribute('aria-sort', /./);
        await expect.poll(async () => before(await bodyNames(page), manager, staff)).toBe(true);
        await acquirer.getByRole('button').click();
        await expect.poll(() => query(page, 'direction')).toBe('desc');
        await expect.poll(async () => before(await bodyNames(page), staff, manager)).toBe(true);

        // Rows per page: the URL follows, sort and period survive, the footer still reconciles.
        const perPage = page.getByLabel('Rows per page');
        await expect(perPage).toHaveValue('50');
        await perPage.selectOption('25');
        await expect.poll(() => query(page, 'per_page')).toBe('25');
        expect(query(page, 'sort')).toBe('name');
        expect(query(page, 'direction')).toBe('desc');
        expect(query(page, 'date_from')).toBe('2025-02-01');
        expect(query(page, 'date_to')).toBe('2025-02-28');
        await expect(perPage).toHaveValue('25');
        await expect(acquirer).toHaveAttribute('aria-sort', 'descending');
        await expectFigures(footRow(page, 'Total'), febTotal);

        await page.reload();
        await expect(page.getByLabel('Rows per page')).toHaveValue('25');
        await expect(
            page.getByRole('columnheader', { name: 'Acquirer', exact: true }),
        ).toHaveAttribute('aria-sort', 'descending');
        await expect(page.getByLabel('From', { exact: true })).toHaveValue('2025-02-01');
        await expect(page.getByLabel('To', { exact: true })).toHaveValue('2025-02-28');
    });

    test('admin and manager open it from the hub, everyone else gets 403', async ({
        page,
        expectHttpError,
    }) => {
        for (const role of hubRoles) {
            await loginAs(page, role);
            await page.goto('/admin/reports');
            const main = page.getByRole('main');
            await main.getByRole('link', { name: 'Open Sales KPI', exact: true }).click();
            await expect(page).toHaveURL(/\/admin\/reports\/sales$/);
            await expect(main.getByRole('heading', { name: 'Sales KPI', level: 1 })).toBeVisible();
            await logout(page);
        }

        for (const role of deniedRoles) {
            await loginAs(page, role);
            // One distinct query per role keeps every 403 attributable to its own expectation.
            const deniedUrl = `/admin/reports/sales?as=${role}`;
            expectHttpError({ url: deniedUrl, method: 'GET', status: 403 });
            const response = await page.goto(deniedUrl);
            expect(response?.status(), role).toBe(403);
            await expect(page.getByRole('heading', { name: 'Sales KPI' })).toHaveCount(0);

            if (role === 'staff') {
                // Staff keeps the hub but is not offered the Sales KPI card.
                await page.goto('/admin/reports');
                const main = page.getByRole('main');
                await expect(
                    main.getByRole('heading', { name: 'Reports', level: 1 }),
                ).toBeVisible();
                await expect(
                    main.getByRole('link', { name: 'Open Sales KPI', exact: true }),
                ).toHaveCount(0);
            }
            await logout(page);
        }
    });

    test('an acquirer id outside the report is refused, not shown as empty', async ({
        page,
        expectHttpError,
    }) => {
        await loginAs(page, 'manager');
        const path = '/admin/reports/sales?acquirer_id=999999999';
        expectHttpError({ url: path, method: 'GET', status: 403 });
        const response = await page.goto(path);
        expect(response?.status()).toBe(403);
        await expect(page.getByRole('heading', { name: 'Sales KPI' })).toHaveCount(0);
    });

    test('stays usable without overflow, keyboard reachable and focus visible across widths and themes', async ({
        page,
    }) => {
        await loginAs(page, 'manager');
        await page.goto(febUrl);

        try {
            for (const width of [1280, 320]) {
                for (const dark of [false, true]) {
                    await useTheme(page, width, dark);
                    await expect(
                        page
                            .getByRole('main')
                            .getByRole('heading', { name: 'Sales KPI', level: 1 }),
                    ).toBeVisible();
                    await expect(bodyRow(page, manager)).toHaveCount(1);

                    // Keyboard: focus ring on Run and on a sort header, then every control in order.
                    const run = page.getByRole('button', { name: 'Run', exact: true });
                    await expectFocusIndicator(run);
                    const bounds = await run.boundingBox();
                    expect(bounds).not.toBeNull();
                    expect(bounds!.x).toBeGreaterThanOrEqual(0);
                    expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width);
                    await expectFocusIndicator(
                        page
                            .getByRole('columnheader', { name: 'Collected', exact: true })
                            .getByRole('button'),
                    );

                    await page.getByLabel('From', { exact: true }).focus();
                    const reached = new Set<string>();
                    for (let step = 0; step < 40; step += 1) {
                        await page.keyboard.press('Tab');
                        reached.add(
                            await page.evaluate(() => {
                                const el = document.activeElement as HTMLElement | null;
                                if (!el) return '';
                                const labelled =
                                    (el as HTMLInputElement).labels?.[0]?.textContent ??
                                    el.getAttribute('aria-label') ??
                                    el.innerText;

                                return `${el.tagName}:${(labelled ?? '').replace(/\s+/g, ' ').trim()}`;
                            }),
                        );
                    }
                    for (const expected of [
                        'INPUT:To',
                        'SELECT:Acquirer',
                        'BUTTON:Run',
                        'BUTTON:Acquirer',
                        'BUTTON:Customers Acquired',
                        'BUTTON:Collected',
                        'BUTTON:Collection Rate',
                        'SELECT:Rows per page',
                    ]) {
                        expect([...reached], expected).toContain(expected);
                    }

                    // Worst case: every definition expanded.
                    const closed = page.locator('main details:not([open]) > summary');
                    while ((await closed.count()) > 0) {
                        await closed.first().click();
                    }
                    await expectNoHorizontalOverflow(page);

                    // The table scrolls inside its own container, never the document.
                    const scroller = page.getByRole('table').first().locator('xpath=..');
                    const box = await scroller.evaluate((el) => ({
                        overflowX: getComputedStyle(el).overflowX,
                        scrollWidth: el.scrollWidth,
                        clientWidth: el.clientWidth,
                    }));
                    expect(['auto', 'scroll']).toContain(box.overflowX);
                    if (width === 320) {
                        expect(box.scrollWidth).toBeGreaterThan(box.clientWidth);
                    }
                }
            }
        } finally {
            await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
        }
    });
});
