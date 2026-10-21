import type { Page, Route } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { csrfToken, e2eCustomerName, inertiaProps, loginAs, unique } from './support/demo';

/**
 * Loading and progress feedback (lane `loading-progress`, UI card t_b18f253d).
 *
 * Contract: .scratch/loading-progress/spec.md ("Approved decisions"). Every case holds the
 * Inertia request with `page.route` until the assertions ran, so the loading state is observed
 * deterministically instead of racing the server:
 *  - navigation: top bar, `aria-busy` on <main> and a polite 'Memuat halaman…' status, only for
 *    navigations (never for state-preserving list refetches);
 *  - DataTable (both table hooks): skeleton rows from the first keystroke, row count follows the
 *    page size up to 10, no stale rows or empty state, 'Memuat data…' / 'Data dimuat' status;
 *  - Inventory Requests on desktop and 320px mobile;
 *  - submit and filter pages: spinning button plus a loading placeholder instead of results;
 *  - reduced motion, 320/1280 in light and dark, progress bar contrast;
 *  - upload: determinate percentage from Inertia `onProgress` on the ticket attachment form.
 *
 * Seed data comes from DatabaseSeeder + DemoUserSeeder (Users). The upload case creates one
 * owned ticket through the application route, as ticket-lifecycle.spec.ts does.
 */

const BUSY_PAGE = 'Memuat halaman…';
const BUSY_DATA = 'Memuat data…';
const DONE_DATA = 'Data dimuat';

type Hold = {
    /** Resolves once a held request reached the browser. */
    seen: Promise<void>;
    requests: () => number;
    /** Releases held requests and removes the route. Safe to call twice. */
    dispose: () => Promise<void>;
};

/** Holds every Inertia GET matching `matches` until `dispose()`. Document loads pass through. */
async function holdInertiaGet(page: Page, matches: (url: URL) => boolean): Promise<Hold> {
    const released = Promise.withResolvers<void>();
    const seen = Promise.withResolvers<void>();
    let requests = 0;

    let handled = false;
    const handler = async (route: Route) => {
        const request = route.request();
        if (request.method() !== 'GET' || request.headers()['x-inertia'] !== 'true') {
            await route.continue().catch(() => {});
            return;
        }
        requests += 1;
        seen.resolve();
        await released.promise;
        if (!handled) {
            handled = true;
            await route.continue().catch(() => {});
        }
    };
    await page.route(matches, handler);

    return {
        seen: seen.promise,
        requests: () => requests,
        dispose: async () => {
            released.resolve();
            await page.unroute(matches, handler).catch(() => {});
        },
    };
}

/**
 * Waits until every already-started code-split script finished and no new one starts for 300ms.
 * The fail-closed request watcher treats an aborted lazy chunk as journey-breaking, so a page is
 * settled before a hard goto(). Same helper as sidebar-ia.spec.ts and ticket-lifecycle.spec.ts.
 */
async function settleAppChunks(page: Page) {
    await page.waitForFunction(
        () => {
            const scripts = performance
                .getEntriesByType('resource')
                .filter(
                    (entry) =>
                        (entry as PerformanceResourceTiming).initiatorType === 'script' &&
                        entry.name.includes('/build/assets/'),
                );
            return (
                scripts.length > 0 &&
                scripts.every((entry) => (entry as PerformanceResourceTiming).responseEnd > 0)
            );
        },
        undefined,
        { timeout: 20_000 },
    );
    await page.waitForFunction(
        (ms) => {
            const scriptCount = () =>
                performance
                    .getEntriesByType('resource')
                    .filter(
                        (entry) =>
                            (entry as PerformanceResourceTiming).initiatorType === 'script' &&
                            entry.name.includes('/build/assets/'),
                    ).length;
            const { promise, resolve } = Promise.withResolvers<boolean>();
            let previous = scriptCount();
            let stableSince = performance.now();
            const tick = () => {
                const now = scriptCount();
                if (now !== previous) {
                    previous = now;
                    stableSince = performance.now();
                } else if (performance.now() - stableSince >= ms) {
                    resolve(true);
                    return;
                }
                setTimeout(tick, 50);
            };
            tick();
            return promise;
        },
        300,
        { timeout: 20_000 },
    );
    await page.waitForLoadState('networkidle');
}

async function openAsAdmin(page: Page, path: string) {
    await loginAs(page, 'admin');
    await settleAppChunks(page);
    await page.goto(path);
}

function busyTable(page: Page) {
    return page.locator('table[aria-busy="true"]:visible');
}

/** A busy table shows exactly `rows` skeleton rows and no data or empty-state row. */
async function expectSkeleton(page: Page, rows: number) {
    const table = busyTable(page);
    await expect(table).toHaveCount(1);
    await expect(table.locator('tbody tr[aria-hidden="true"]')).toHaveCount(rows);
    await expect(table.locator('tbody tr:not([aria-hidden="true"])')).toHaveCount(0);
}

async function expectNoHorizontalOverflow(page: Page, context: string) {
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
        `${context}: horizontal overflow`,
    ).toBe(true);
}

type LoadingWindow = Window & { __loadingTimeline?: { busyAt?: number; startAt?: number } };

/** Stamps when a table first turns busy and when the first Inertia visit starts. */
async function recordLoadingTimeline(page: Page) {
    await page.evaluate(() => {
        const timeline: NonNullable<LoadingWindow['__loadingTimeline']> = {};
        (window as LoadingWindow).__loadingTimeline = timeline;
        document.addEventListener('inertia:start', () => {
            timeline.startAt ??= performance.now();
        });
        new MutationObserver(() => {
            if (
                timeline.busyAt === undefined &&
                document.querySelector('table[aria-busy="true"]')
            ) {
                timeline.busyAt = performance.now();
            }
        }).observe(document.body, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['aria-busy'],
        });
    });
}

/** Search is debounced 300ms: the skeleton must precede the request by most of that window. */
async function expectBusyBeforeRequest(page: Page) {
    const timeline = await page.evaluate(() => (window as LoadingWindow).__loadingTimeline);
    expect(timeline?.busyAt, 'table turned busy').toBeDefined();
    expect(timeline?.startAt, 'visit started').toBeDefined();
    expect(
        timeline!.startAt! - timeline!.busyAt!,
        'busy since the first keystroke',
    ).toBeGreaterThan(200);
}

/** Applies the Inertia progress bar delay, then a frame, so a missing busy state would show. */
async function waitForProgressBar(page: Page) {
    await expect(page.locator('#nprogress .bar')).toBeVisible();
    await page.evaluate(() => {
        const { promise, resolve } = Promise.withResolvers<number>();
        requestAnimationFrame(resolve);
        return promise;
    });
}

function contrastRatio(foreground: string, background: string): number {
    const luminance = (css: string) => {
        const [red, green, blue] = (css.match(/[\d.]+/g) ?? [])
            .slice(0, 3)
            .map((value) => Number(value) / 255)
            .map((value) => (value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4));
        return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
    };
    const [lighter, darker] = [luminance(foreground), luminance(background)].sort((a, b) => b - a);

    return (lighter + 0.05) / (darker + 0.05);
}

test.describe('@loading-progress navigation', () => {
    test('@loading-progress busy status, aria-busy and progress bar until the next page renders', async ({
        page,
    }) => {
        await openAsAdmin(page, '/admin/reports');
        const card = page
            .getByRole('main')
            .getByRole('link', { name: 'Metrik Bisnis', exact: true })
            .first();
        await expect(card).toBeVisible();
        const target = new URL((await card.getAttribute('href')) ?? '', page.url()).pathname;
        const busyStatus = page.getByRole('status').filter({ hasText: BUSY_PAGE });
        const hold = await holdInertiaGet(page, (url) => url.pathname === target);

        try {
            await card.click();
            await hold.seen;

            // The status appears with the progress bar (250ms), not before.
            await expect(busyStatus).toHaveCount(1);
            await expect(page.getByRole('main')).toHaveAttribute('aria-busy', 'true');
            await expect(page.locator('#nprogress .bar')).toBeVisible();
            await expect(page).toHaveURL(/\/admin\/reports$/);
            await expect(card, 'busy state keeps keyboard focus').toBeFocused();
            // The live region sits outside the busy region, so assistive tech still announces it.
            await expect(page.getByRole('main').getByRole('status')).toHaveCount(0);
        } finally {
            await hold.dispose();
        }

        await expect(page).toHaveURL(new RegExp(`${target}$`));
        await expect(page.getByRole('main')).not.toHaveAttribute('aria-busy', 'true');
        await expect(busyStatus).toHaveCount(0);
        await settleAppChunks(page);
    });
});

test.describe('@loading-progress DataTable', () => {
    test('@loading-progress useServerTable: skeleton from the first keystroke, no stale rows', async ({
        page,
    }) => {
        await openAsAdmin(page, '/admin/users');
        const search = page.getByLabel('Cari', { exact: true });
        await expect(search).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: 'Demo Admin' })).toBeVisible();

        await recordLoadingTimeline(page);
        const hold = await holdInertiaGet(page, (url) => url.pathname === '/admin/users');

        try {
            await search.fill('Demo');

            await expectSkeleton(page, 10);
            await expect(page.getByRole('status').filter({ hasText: BUSY_DATA })).toHaveCount(1);
            await hold.seen;
            expect(hold.requests()).toBe(1);
            await expectBusyBeforeRequest(page);

            // A list refetch is not a navigation: no second busy state on top of the table's.
            await waitForProgressBar(page);
            await expect(page.getByRole('main')).not.toHaveAttribute('aria-busy', 'true');
            await expect(page.getByRole('status').filter({ hasText: BUSY_PAGE })).toHaveCount(0);

            // Typing never disables the input: that would drop focus mid-word.
            await expect(search).toBeEnabled();
            await expect(search).toBeFocused();
        } finally {
            await hold.dispose();
        }

        await expect(busyTable(page)).toHaveCount(0);
        await expect(page.getByRole('row').filter({ hasText: 'Demo Admin' })).toBeVisible();
        await expect(page.getByRole('status').filter({ hasText: DONE_DATA })).toHaveCount(1);
        await settleAppChunks(page);
    });

    test('@loading-progress skeleton rows follow per_page and stay bounded at 10', async ({
        page,
    }) => {
        await openAsAdmin(page, '/admin/users');
        const sortByName = page.getByRole('columnheader', { name: 'Nama' }).getByRole('button');
        await expect(sortByName).toBeVisible();

        // Page sizes are 10, 25 or 50 on the server: 10 fills the placeholder, the rest are capped.
        for (const perPage of ['10', '25', '50']) {
            await page.getByLabel('Baris per halaman').selectOption(perPage);
            await expect(busyTable(page)).toHaveCount(0);
            await expect(page.getByLabel('Baris per halaman')).toHaveValue(perPage);

            const hold = await holdInertiaGet(page, (url) => url.pathname === '/admin/users');
            try {
                await sortByName.click();
                await hold.seen;
                await expectSkeleton(page, 10);
            } finally {
                await hold.dispose();
            }
            await expect(busyTable(page)).toHaveCount(0);
        }
        await settleAppChunks(page);
    });

    test('@loading-progress useTableQuery: skeleton replaces rows and the empty state while loading', async ({
        page,
    }) => {
        await openAsAdmin(page, '/admin/tickets');
        const search = page.getByLabel('Cari', { exact: true });
        await expect(search).toBeVisible();

        await recordLoadingTimeline(page);
        const hold = await holdInertiaGet(page, (url) => url.pathname === '/admin/tickets');

        try {
            await search.fill('zz-no-such-ticket-loading-progress');

            await expectSkeleton(page, 10);
            await expect(
                page.getByText('Tidak ada tiket yang cocok', { exact: true }),
            ).toBeHidden();
            await expect(page.getByRole('status').filter({ hasText: BUSY_DATA })).toHaveCount(1);
            await hold.seen;
            await expectBusyBeforeRequest(page);
            await expect(search).toBeEnabled();
        } finally {
            await hold.dispose();
        }

        await expect(busyTable(page)).toHaveCount(0);
        await expect(page.getByText('Tidak ada tiket yang cocok', { exact: true })).toBeVisible();
        await settleAppChunks(page);
    });

    test('@loading-progress live filters: choosing one shows the skeleton until the list answers', async ({
        page,
    }) => {
        await openAsAdmin(page, '/admin/evaluations');
        const reference = page.getByLabel('Jenis referensi', { exact: true });
        await expect(reference).toBeVisible();
        await expect(busyTable(page)).toHaveCount(0);

        const hold = await holdInertiaGet(page, (url) => url.pathname === '/admin/evaluations');
        try {
            await reference.selectOption({ label: 'Tiket' });
            await hold.seen;

            await expectSkeleton(page, 10);
            await expect(page.getByRole('status').filter({ hasText: BUSY_DATA })).toHaveCount(1);
            // A list refetch is not a navigation: no second busy state on top of the table's.
            await expect(page.getByRole('main')).not.toHaveAttribute('aria-busy', 'true');
            await expect(reference).toBeEnabled();
        } finally {
            await hold.dispose();
        }

        await expect(busyTable(page)).toHaveCount(0);
        await expect(page.getByRole('status').filter({ hasText: DONE_DATA })).toHaveCount(1);
        await settleAppChunks(page);
    });

    for (const viewport of [
        { name: 'desktop', width: 1280 },
        { name: 'mobile', width: 320 },
    ]) {
        test(`@loading-progress Inventory Requests index shows the skeleton on ${viewport.name}`, async ({
            page,
        }) => {
            await page.setViewportSize({ width: viewport.width, height: 800 });
            await openAsAdmin(page, '/admin/inventory/requests');
            const search = page.getByLabel('Cari permintaan');
            await expect(search).toBeVisible();

            const hold = await holdInertiaGet(
                page,
                (url) => url.pathname === '/admin/inventory/requests',
            );
            try {
                await search.fill('zz-loading-progress');

                // Desktop table and mobile cards are both DataTables: only the visible one counts.
                await expectSkeleton(page, 10);
                await expect(page.getByRole('status').filter({ hasText: BUSY_DATA })).toHaveCount(
                    1,
                );
                await hold.seen;
                await expectNoHorizontalOverflow(page, `${viewport.name} loading`);
            } finally {
                await hold.dispose();
            }

            await expect(busyTable(page)).toHaveCount(0);
            await settleAppChunks(page);
        });
    }
});

test.describe('@loading-progress submit and filter pages', () => {
    const pages = [
        { name: 'Item Finder', path: '/admin/inventory/find', submit: 'Cari' },
        { name: 'Asset Trace', path: '/admin/network-assets/trace', submit: 'Lacak' },
        { name: 'Asset Utilization', path: '/admin/reports/asset', submit: 'Tampilkan' },
        { name: 'Business Metrics', path: '/admin/reports/business', submit: 'Tampilkan' },
        { name: 'SLA Compliance', path: '/admin/reports/sla', submit: 'Tampilkan' },
    ];

    for (const { name, path, submit } of pages) {
        test(`@loading-progress ${name}: button spins and results give way to a placeholder`, async ({
            page,
        }) => {
            await openAsAdmin(page, path);
            const button = page.getByRole('button', { name: submit, exact: true });
            await expect(button).toBeEnabled();
            const placeholder = page.getByRole('status').filter({ hasText: BUSY_DATA });
            await expect(placeholder).toHaveCount(0);

            const hold = await holdInertiaGet(page, (url) => url.pathname === path);
            try {
                await button.click();
                await hold.seen;

                await expect(button).toBeDisabled();
                await expect(button).toHaveAttribute('aria-busy', 'true');
                await expect(placeholder).toHaveCount(1);
            } finally {
                await hold.dispose();
            }

            await expect(button).toBeEnabled();
            await expect(button).not.toHaveAttribute('aria-busy', 'true');
            await expect(placeholder).toHaveCount(0);
            await settleAppChunks(page);
        });
    }
});

test.describe('@loading-progress motion and themes', () => {
    for (const reduced of [false, true]) {
        test(`@loading-progress ${reduced ? 'reduced' : 'full'} motion: skeleton and spinner`, async ({
            page,
        }) => {
            await page.emulateMedia({ reducedMotion: reduced ? 'reduce' : 'no-preference' });
            await openAsAdmin(page, '/admin/users');
            const sortByName = page.getByRole('columnheader', { name: 'Nama' }).getByRole('button');
            await expect(sortByName).toBeVisible();

            const tableHold = await holdInertiaGet(page, (url) => url.pathname === '/admin/users');
            try {
                await sortByName.click();
                await tableHold.seen;
                const skeleton = busyTable(page).locator('tbody tr .animate-pulse').first();
                await expect(skeleton).toBeVisible();
                expect(
                    await skeleton.evaluate((element) => getComputedStyle(element).animationName),
                    'skeleton pulse',
                ).toBe(reduced ? 'none' : 'pulse');
            } finally {
                await tableHold.dispose();
            }

            await settleAppChunks(page);
            await page.goto('/admin/inventory/find');
            const search = page.getByRole('button', { name: 'Cari', exact: true });
            const spinnerHold = await holdInertiaGet(
                page,
                (url) => url.pathname === '/admin/inventory/find',
            );
            try {
                await search.click();
                await spinnerHold.seen;
                const spinner = page.locator('button[aria-busy="true"] svg.animate-spin');
                await expect(spinner).toBeVisible();
                expect(
                    await spinner.evaluate(
                        (element) => getComputedStyle(element).animationDuration,
                    ),
                    'spinner rotation',
                ).toBe(reduced ? '2s' : '1s');
            } finally {
                await spinnerHold.dispose();
            }
            await settleAppChunks(page);
        });
    }

    test('@loading-progress loading states hold at 320 and 1280 in light and dark', async ({
        page,
    }) => {
        test.setTimeout(150_000);
        await loginAs(page, 'admin');

        for (const width of [1280, 320]) {
            for (const dark of [false, true]) {
                const context = `${width}px ${dark ? 'dark' : 'light'}`;
                await page.setViewportSize({ width, height: 800 });
                await page.evaluate(
                    (value) => localStorage.setItem('darkMode', String(value)),
                    dark,
                );

                // Table skeleton: visible, inside its scroll container, no page overflow.
                await settleAppChunks(page);
                await page.goto('/admin/users');
                await expect(page.locator('html')).toHaveClass(
                    dark ? /\bdark\b/ : /^(?!.*\bdark\b)/,
                );
                const sortByName = page
                    .getByRole('columnheader', { name: 'Nama' })
                    .getByRole('button');
                await expect(sortByName).toBeVisible();
                const tableHold = await holdInertiaGet(
                    page,
                    (url) => url.pathname === '/admin/users',
                );
                try {
                    await sortByName.click();
                    await tableHold.seen;
                    await expectSkeleton(page, 10);
                    await expect(
                        busyTable(page).locator('tbody tr .animate-pulse').first(),
                    ).toBeVisible();
                    await expectNoHorizontalOverflow(page, `${context} skeleton`);
                } finally {
                    await tableHold.dispose();
                }

                // Progress bar: follows the theme token and keeps 3:1 against the page.
                await settleAppChunks(page);
                await page.goto('/admin/reports');
                const card = page
                    .getByRole('main')
                    .getByRole('link', { name: 'Metrik Bisnis', exact: true })
                    .first();
                await expect(card).toBeVisible();
                const target = new URL((await card.getAttribute('href')) ?? '', page.url())
                    .pathname;
                const navigationHold = await holdInertiaGet(page, (url) => url.pathname === target);
                try {
                    await card.click();
                    await navigationHold.seen;
                    await expect(page.locator('#nprogress .bar')).toBeVisible();
                    await expect(
                        page.getByRole('status').filter({ hasText: BUSY_PAGE }),
                    ).toHaveCount(1);
                    await expectNoHorizontalOverflow(page, `${context} navigation`);

                    const colors = await page.evaluate(() => {
                        const bar = document.querySelector('#nprogress .bar') as HTMLElement;
                        const probe = document.createElement('div');
                        probe.style.backgroundColor = 'hsl(var(--primary))';
                        document.body.append(probe);
                        const primary = getComputedStyle(probe).backgroundColor;
                        probe.remove();

                        return {
                            bar: getComputedStyle(bar).backgroundColor,
                            primary,
                            page: getComputedStyle(document.body).backgroundColor,
                        };
                    });
                    expect(colors.bar, `${context} bar follows the primary token`).toBe(
                        colors.primary,
                    );
                    expect(
                        contrastRatio(colors.bar, colors.page),
                        `${context} bar contrast against the page`,
                    ).toBeGreaterThanOrEqual(3);
                } finally {
                    await navigationHold.dispose();
                }
            }
        }

        await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
        await settleAppChunks(page);
    });
});

test.describe('@loading-progress upload', () => {
    test('@loading-progress ticket attachment shows a determinate progress bar', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        const title = `E2E loading progress ${unique('UPL')}`;
        await loginAs(page, 'admin');
        const ticketId = await createOwnedTicket(page, title);

        await settleAppChunks(page);
        await page.goto(`/admin/tickets/${ticketId}`);
        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
        await page.locator('input[type="file"]').setInputFiles({
            name: 'loading-progress.pdf',
            mimeType: 'application/pdf',
            buffer: Buffer.concat([Buffer.from('%PDF-1.4\n'), Buffer.alloc(1_000_000, 'x')]),
        });
        const upload = page.getByRole('button', { name: 'Unggah', exact: true });
        await expect(upload).toBeEnabled();

        // Throttled upload: the body needs ~10s, so Inertia reports intermediate percentages.
        const cdp = await page.context().newCDPSession(page);
        await cdp.send('Network.enable');
        await cdp.send('Network.emulateNetworkConditions', {
            offline: false,
            latency: 0,
            downloadThroughput: -1,
            uploadThroughput: 100 * 1024,
        });

        try {
            await upload.click();
            const bar = page.getByRole('progressbar', { name: 'Mengunggah lampiran' });
            await expect(bar).toBeVisible();
            await expect(upload).toBeDisabled();
            await expect(upload).toHaveAttribute('aria-busy', 'true');

            await expect
                .poll(async () => Number(await bar.getAttribute('aria-valuenow')), {
                    message: 'percentage rises while the file is sent',
                    timeout: 30_000,
                })
                .toBeGreaterThan(0);
            const midway = Number(await bar.getAttribute('aria-valuenow'));
            expect(midway).toBeLessThan(100);
            await expect(bar).toHaveAttribute('aria-valuemin', '0');
            await expect(bar).toHaveAttribute('aria-valuemax', '100');

            const indicator = bar.locator(':scope > div');
            expect(
                await indicator.evaluate((element) => getComputedStyle(element).transitionProperty),
                'bar animates the fill',
            ).toMatch(/^transform\b/);
            await page.emulateMedia({ reducedMotion: 'reduce' });
            expect(
                await indicator.evaluate((element) => getComputedStyle(element).transitionProperty),
                'reduced motion removes the fill transition',
            ).toBe('none');

            await expect(page.getByText('Lampiran berhasil diunggah.').first()).toBeVisible({
                timeout: 60_000,
            });
            await expect(bar).toBeHidden();
            await expect(upload).toBeDisabled();
        } finally {
            await cdp.send('Network.emulateNetworkConditions', {
                offline: false,
                latency: 0,
                downloadThroughput: -1,
                uploadThroughput: -1,
            });
            await cdp.detach();
        }
        await settleAppChunks(page);
    });
});

/** Creates one ticket through the application route and returns its id (no fixed ids). */
async function createOwnedTicket(page: Page, title: string): Promise<string> {
    await settleAppChunks(page);
    await page.goto('/admin/tickets/create');
    // The category list is a prop of the form; the customer is found through the customer list, because its
    // picker searches the server.
    const categoryId = (await inertiaProps(page, '/admin/tickets/create')).props?.categories
        ?.data?.[0]?.id;
    const customers = await inertiaProps(
        page,
        `/admin/customers?search=${encodeURIComponent(e2eCustomerName)}`,
    );
    const customerId = (customers.props?.customers?.data ?? []).find(
        (row: { name: string }) => row.name === e2eCustomerName,
    )?.id;
    if (!categoryId || !customerId) throw new Error('No ticket category or customer fixture');

    const response = await page.request.post('/admin/tickets', {
        headers: { 'X-XSRF-TOKEN': await csrfToken(page) },
        maxRedirects: 0,
        form: {
            title,
            source: 'customer',
            category_id: categoryId,
            priority: 'low',
            customer_id: customerId,
            description: 'Owned by loading-progress.spec.ts',
        },
    });
    expect(response.status(), 'Create owned ticket').toBe(302);

    const { props } = await inertiaProps(
        page,
        `/admin/tickets?search=${encodeURIComponent(title)}`,
    );
    const ticket = (props?.tickets?.data ?? []).find(
        (row: { title: string }) => row.title === title,
    );
    expect(ticket, `Owned ticket ${title}`).toBeTruthy();

    return String(ticket.id);
}
