import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { csrfToken, inertiaProps, loginAs, logout } from './support/demo';

/**
 * Head company lane (.scratch/head-company/spec.md): the company switcher and the consolidated
 * Business report. E2eFixtureSeeder puts an active subsidiary under the demo company and makes the
 * demo admin a member of both. The subsidiary is written by this spec alone, so its figures are
 * exact; the Head's figures are shared with other specs and are only ever added up, never fixed.
 */

const head = 'Inbils Demo Company';
const subsidiary = 'E2E Subsidiary Company';
const switcherName = /^Switch company, current:/;

// MRR, Revenue (Paid), Outstanding, Active Subscriptions, Suspended Subscriptions.
const addableMetrics = [
    'MRR',
    'Revenue (Paid)',
    'Outstanding',
    'Active Subscriptions',
    'Suspended Subscriptions',
] as const;

type Membership = { id: number; name: string; is_current: boolean; is_default: boolean };

type SharedProps = {
    canSwitch: boolean;
    companies: Membership[];
    companyHierarchy: { can_consolidate: boolean } | null;
    auth: { user: { company_id: number } };
};

async function sharedProps(page: Page) {
    // The page object is the server's own JSON: a wrong shape fails the assertions that read it.
    const props = (await inertiaProps(page, '/admin/dashboard')).props as SharedProps;

    return {
        canSwitch: props.canSwitch,
        companies: props.companies,
        canConsolidate: props.companyHierarchy?.can_consolidate === true,
        companyId: props.auth.user.company_id,
    };
}

async function useTheme(page: Page, width: number, dark: boolean) {
    await page.setViewportSize({ width, height: 800 });
    await page.evaluate((value) => localStorage.setItem('darkMode', String(value)), dark);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(dark ? /\bdark\b/ : /^(?!.*\bdark\b)/);
}

async function expectNoHorizontalOverflow(page: Page) {
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
    expect(await page.getByRole('main').evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(
        true,
    );
}

async function expectInsideViewport(locator: Locator, width: number) {
    const box = await locator.boundingBox();

    expect(box?.x).toBeGreaterThanOrEqual(0);
    expect((box?.x ?? 0) + (box?.width ?? 0)).toBeLessThanOrEqual(width);
}

/** Below the lg breakpoint the sidebar, and the switcher in it, is a drawer behind the menu button. */
async function openSidebarIfDrawer(page: Page) {
    const toggle = page.getByRole('button', { name: 'Toggle sidebar' });

    if (await toggle.isVisible()) {
        await toggle.click();
        // Fully inside, not merely touching: the drawer slides in over a transition.
        await expect(page.locator('aside')).toBeInViewport({ ratio: 1 });
    }
}

/** A group opens only around the active item, and a link in a closed group is hidden from role queries. */
async function expandSidebarGroups(page: Page) {
    const closed = page.locator('aside details:not([open]) > summary');

    while ((await closed.count()) > 0) {
        await closed.first().click();
    }
}

function consolidatedLink(page: Page) {
    return page.locator('aside').getByRole('link', { name: 'Consolidated Metrics', exact: true });
}

async function switchTo(page: Page, company: string) {
    await openSidebarIfDrawer(page);
    await page.getByRole('button', { name: switcherName }).click();
    await page.getByRole('menuitemradio', { name: new RegExp(`^${company}`) }).click();
    await expect(
        page.getByRole('status').filter({ hasText: `Now working in ${company}.` }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: `Switch company, current: ${company}` }),
    ).toBeVisible();
}

function brand(page: Page, company: string) {
    return page.locator('aside p').filter({ hasText: new RegExp(`^${company}$`) });
}

async function expectCustomer(page: Page, name: string, shown: boolean) {
    await page.goto(`/admin/customers?search=${encodeURIComponent(name)}`);
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Customers', level: 1 }),
    ).toBeVisible();
    await expect(page.locator('tbody tr').filter({ hasText: name })).toHaveCount(shown ? 1 : 0);
}

const digits = (text: string) => Number(text.replace(/\D/g, ''));

/** The five addable figures of a table row, in `addableMetrics` order (the SLA percentage is pooled, not added). */
async function figures(row: Locator) {
    // Cells: company, MRR, Revenue (Paid), Outstanding, Active, Suspended, SLA Compliance.
    const cells = await row.getByRole('cell').allInnerTexts();

    return cells.slice(1, 6).map(digits);
}

async function expectTotalIsTheSum(page: Page) {
    await expect(page.locator('tbody tr')).toHaveCount(2);

    const headFigures = await figures(page.getByRole('row', { name: /^Inbils Demo Company/ }));
    const subsidiaryFigures = await figures(
        page.getByRole('row', { name: /^E2E Subsidiary Company/ }),
    );
    const totalFigures = await figures(page.getByRole('row', { name: /^Consolidated total/ }));

    expect(totalFigures).toEqual(headFigures.map((value, i) => value + subsidiaryFigures[i]));

    return { headFigures, subsidiaryFigures, totalFigures };
}

/** The cells of one CSV line; the writer quotes any text with a space, a comma or a quote. */
function csvCells(line: string) {
    const cells: string[] = [];
    let cell = '';
    let quoted = false;

    for (let i = 0; i < line.length; i++) {
        const char = line[i];

        if (quoted && char === '"' && line[i + 1] === '"') {
            cell += '"';
            i++;
        } else if (char === '"') {
            quoted = !quoted;
        } else if (char === ',' && !quoted) {
            cells.push(cell);
            cell = '';
        } else {
            cell += char;
        }
    }

    return [...cells, cell];
}

async function exportedFigures(page: Page, query: string) {
    const response = await page.request.get(
        `/admin/reports/consolidated/export?format=csv${query}`,
    );

    expect(response.status()).toBe(200);
    expect(response.headers()['content-type'] ?? '').toContain('text/csv');
    expect(response.headers()['content-disposition'] ?? '').toContain('attachment');

    const text = (await response.body()).toString('utf8');
    const lines = text
        .slice(1)
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean)
        .map(csvCells);

    expect(text.charCodeAt(0)).toBe(0xfeff);
    expect(lines[0]).toEqual([
        'Company',
        'Metric',
        'Breakdown',
        'Value',
        'Unit',
        'Scope',
        'Filters',
        'Version',
    ]);

    return (company: string) =>
        addableMetrics.map((metric) => {
            const line = lines.find((cells) => cells[0] === company && cells[1] === metric);

            expect(line, `${company}: ${metric} is in the file`).toBeDefined();

            return Number(line?.[3]);
        });
}

test.describe('@smoke @head-company head company', () => {
    test('only a user who belongs to several companies is offered the switcher', async ({
        page,
    }) => {
        await loginAs(page, 'admin');

        const shared = await sharedProps(page);
        expect(shared.canSwitch).toBe(true);
        expect(shared.companies.map((company) => [company.name, company.is_current])).toEqual([
            [head, true],
            [subsidiary, false],
        ]);

        const switcher = page.getByRole('button', { name: `Switch company, current: ${head}` });
        await expect(switcher).toBeVisible();
        await switcher.click();
        await expect(
            page.getByRole('menuitemradio', { name: new RegExp(`^${head}`) }),
        ).toHaveAttribute('aria-checked', 'true');
        await expect(
            page.getByRole('menuitemradio', { name: new RegExp(`^${subsidiary}`) }),
        ).toHaveAttribute('aria-checked', 'false');
        await page.keyboard.press('Escape');
        await expect(page.getByRole('menu')).toHaveCount(0);
        await expect(switcher).toBeFocused();
        await logout(page);

        // The manager may switch (company.switch) but belongs to one company only.
        for (const role of ['manager', 'staff', 'technician', 'customer'] as const) {
            await loginAs(page, role);

            const other = await sharedProps(page);
            expect(other.canSwitch, role).toBe(false);
            expect(other.companies, role).toEqual([]);
            await expect(page.getByRole('button', { name: /Switch company/ }), role).toHaveCount(0);
            await logout(page);
        }
    });

    test('switching moves the sidebar and the customer list to the other company', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await expect(brand(page, head)).toBeVisible();
        await expectCustomer(page, 'E2E Linked Customer', true);
        await expectCustomer(page, 'E2E Subsidiary Customer', false);

        await switchTo(page, subsidiary);
        await expect(page).toHaveURL(/\/admin\/dashboard$/);
        await expect(brand(page, subsidiary)).toBeVisible();
        await expect(brand(page, head)).toHaveCount(0);
        await expectCustomer(page, 'E2E Subsidiary Customer', true);
        await expectCustomer(page, 'E2E Linked Customer', false);

        await switchTo(page, head);
        await expect(brand(page, head)).toBeVisible();
        await expectCustomer(page, 'E2E Linked Customer', true);
        await expectCustomer(page, 'E2E Subsidiary Customer', false);
    });

    test('the consolidated report lists both companies and a total that adds up, on screen and in the file', async ({
        page,
    }) => {
        await loginAs(page, 'admin');

        await page.goto('/admin/reports');
        const main = page.getByRole('main');
        await main.getByRole('link', { name: 'Open Consolidated Metrics', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/reports\/consolidated$/);
        await expect(
            main.getByRole('heading', { name: 'Consolidated Metrics', level: 1 }),
        ).toBeVisible();
        await expect(
            page.locator('aside').getByRole('link', { name: 'Consolidated Metrics', exact: true }),
        ).toHaveAttribute('aria-current', 'page');
        await expect(
            main.getByText(
                `Consolidated business metrics of ${head} and 1 subsidiary. Amounts in IDR.`,
            ),
        ).toBeVisible();

        const headRow = page.getByRole('row', { name: /^Inbils Demo Company/ });
        await expect(headRow.getByText('Head', { exact: true })).toBeVisible();
        await expect(page.getByRole('row', { name: /^E2E Subsidiary Company/ })).toHaveCount(1);
        await expect(page.getByRole('row', { name: /^Consolidated total/ })).toHaveCount(1);

        const open = await expectTotalIsTheSum(page);
        // MRR 75000 (one active subscription) and outstanding 250000 (one sent invoice), seeded for this spec.
        expect(open.subsidiaryFigures).toEqual([75_000, 0, 250_000, 1, 0]);
        await expect(page.getByRole('row', { name: /^E2E Subsidiary Company/ })).toContainText(
            '0 of 0 on time',
        );

        // The date filter reaches every company and the total still adds up.
        await page.getByLabel('From', { exact: true }).fill('2026-01-01');
        await page.getByLabel('To', { exact: true }).fill('2026-01-31');
        await page.getByRole('button', { name: 'Run' }).click();
        await expect(page).toHaveURL(/date_from=2026-01-01&date_to=2026-01-31/);
        await expect(
            main.getByText('Period: 2026-01-01 to 2026-01-31, both days included.'),
        ).toBeVisible();
        const filtered = await expectTotalIsTheSum(page);

        // Only CSV is offered, and the file holds the figures on screen.
        await page.getByRole('button', { name: 'Export', exact: true }).click();
        await expect(page.getByRole('menuitem')).toHaveCount(1);
        await expect(page.getByRole('menuitem', { name: 'Export CSV' })).toBeVisible();
        await page.keyboard.press('Escape');

        const exported = await exportedFigures(page, '&date_from=2026-01-01&date_to=2026-01-31');
        expect(exported(head)).toEqual(filtered.headFigures);
        expect(exported(subsidiary)).toEqual(filtered.subsidiaryFigures);
        expect(exported('Consolidated')).toEqual(filtered.totalFigures);

        // The registry definition of each figure is on the page.
        await expect(
            main.getByText(
                'Monthly recurring revenue: the monthly charge of every subscription that is active now.',
            ),
        ).toBeVisible();
    });

    test('the consolidated report and the switch stay closed to users without the permission, to a subsidiary and to a forged id', async ({
        page,
    }) => {
        const switchRequest = async (companyId: number) =>
            page.request.post('/admin/company/switch', {
                headers: { 'X-XSRF-TOKEN': await csrfToken(page) },
                form: { company_id: String(companyId) },
                maxRedirects: 0,
            });

        await loginAs(page, 'admin');
        const home = await sharedProps(page);
        const subsidiaryId = home.companies.find((company) => company.name === subsidiary)?.id ?? 0;
        expect(home.canConsolidate).toBe(true);
        await expandSidebarGroups(page);
        await expect(consolidatedLink(page)).toBeVisible();

        // A forged id changes nothing.
        expect((await switchRequest(999_999_999)).status()).toBe(403);
        expect((await sharedProps(page)).companyId).toBe(home.companyId);

        // Inside the subsidiary the report is gone: it is no Head.
        await switchTo(page, subsidiary);
        expect((await sharedProps(page)).canConsolidate).toBe(false);
        await expandSidebarGroups(page);
        await expect(consolidatedLink(page)).toHaveCount(0);
        for (const path of [
            '/admin/reports/consolidated',
            '/admin/reports/consolidated/export?format=csv',
        ]) {
            expect((await page.request.get(path)).status(), `subsidiary: ${path}`).toBe(403);
        }
        await page.goto('/admin/reports');
        await expect(
            page.getByRole('main').getByRole('link', { name: /^Open Consolidated/ }),
        ).toHaveCount(0);
        await logout(page);

        // The other roles lack report.consolidated.view; the manager may switch but is no member of the subsidiary.
        for (const role of ['manager', 'staff', 'technician', 'customer'] as const) {
            await loginAs(page, role);
            const before = await sharedProps(page);

            expect(before.canConsolidate, role).toBe(false);
            await expandSidebarGroups(page);
            await expect(consolidatedLink(page), role).toHaveCount(0);
            for (const path of [
                '/admin/reports/consolidated',
                '/admin/reports/consolidated/export?format=csv',
            ]) {
                expect((await page.request.get(path)).status(), `${role}: ${path}`).toBe(403);
            }
            expect((await switchRequest(subsidiaryId)).status(), `${role}: switch`).toBe(403);
            expect((await sharedProps(page)).companyId, role).toBe(before.companyId);
            await logout(page);
        }
    });

    test('the report keeps its layout at 320 and 1280, light and dark', async ({ page }) => {
        await loginAs(page, 'admin');

        for (const width of [1280, 320]) {
            for (const dark of [false, true]) {
                await page.goto('/admin/reports/consolidated');
                await useTheme(page, width, dark);
                await expect(
                    page
                        .getByRole('main')
                        .getByRole('heading', { name: 'Consolidated Metrics', level: 1 }),
                ).toBeVisible();

                // The table needs about 840px beside the sidebar: below 1200px each company is a card.
                if (width < 1200) {
                    await expect(page.getByRole('table')).toBeHidden();
                    await expect(
                        page.getByRole('listitem').filter({ hasText: 'Consolidated total' }),
                    ).toBeVisible();
                    await expect(
                        page.getByRole('listitem').filter({ hasText: 'E2E Subsidiary Company' }),
                    ).toBeVisible();
                } else {
                    await expect(page.getByRole('table')).toBeVisible();
                    await expect(page.locator('tbody tr')).toHaveCount(2);
                }

                // Worst case: every definition expanded.
                const closed = page.locator('main details:not([open]) > summary');
                while ((await closed.count()) > 0) {
                    await closed.first().click();
                }
                await expectNoHorizontalOverflow(page);
            }
        }
    });

    test('the switcher holds at 320 and 1280, light and dark, with a touch-sized target', async ({
        page,
    }) => {
        await loginAs(page, 'admin');

        for (const width of [1280, 320]) {
            for (const dark of [false, true]) {
                await useTheme(page, width, dark);
                await openSidebarIfDrawer(page);

                const switcher = page.getByRole('button', { name: switcherName });
                await expect(switcher).toBeInViewport();
                await expectInsideViewport(switcher, width);
                expect((await switcher.boundingBox())?.height).toBeGreaterThanOrEqual(44);

                const resting = await switcher.evaluate((el) => getComputedStyle(el).boxShadow);

                // Keyboard modality first: :focus-visible only paints after a key press.
                await page.keyboard.press('Tab');
                await switcher.focus();
                await expect(switcher).toBeFocused();
                // The outline button rests on a shadow already, so the ring is what changes.
                expect(await switcher.evaluate((el) => getComputedStyle(el).boxShadow)).not.toBe(
                    resting,
                );

                await switcher.click();
                const items = page.getByRole('menuitemradio');
                await expect(items).toHaveCount(2);
                for (const item of await items.all()) {
                    await expectInsideViewport(item, width);
                    expect((await item.boundingBox())?.height).toBeGreaterThanOrEqual(44);
                }
                await page.keyboard.press('Escape');
                await expect(items).toHaveCount(0);
                await expect(switcher).toBeFocused();
                await expectNoHorizontalOverflow(page);
            }
        }
    });

    test('a keyboard user switches company inside the mobile drawer, which then closes', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.setViewportSize({ width: 320, height: 800 });
        await openSidebarIfDrawer(page);

        const switcher = page.getByRole('button', { name: `Switch company, current: ${head}` });
        await switcher.focus();
        await page.keyboard.press('Enter');

        const target = page.getByRole('menuitemradio', { name: new RegExp(`^${subsidiary}`) });
        await expect(target).toBeVisible();
        for (
            let i = 0;
            i < 3 && !(await target.evaluate((el) => el === document.activeElement));
            i++
        ) {
            await page.keyboard.press('ArrowDown');
        }
        await expect(target).toBeFocused();
        await page.keyboard.press('Enter');

        await expect(
            page.getByRole('status').filter({ hasText: `Now working in ${subsidiary}.` }),
        ).toBeVisible();
        // The dashboard was already open, so the layout stays mounted: the switcher closes the drawer itself.
        await expect(page.locator('aside')).not.toBeInViewport();
        await page.getByRole('button', { name: 'Toggle sidebar' }).click();
        await expect(brand(page, subsidiary)).toBeVisible();
        await expect(
            page.getByRole('button', { name: `Switch company, current: ${subsidiary}` }),
        ).toBeVisible();
    });
});
