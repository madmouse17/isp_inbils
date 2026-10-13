import type { Locator, Page, TestInfo } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { EMPTY_CHART_TEXT, chartCard, chartSvg, expectChartSettled } from './support/charts';
import { typeDateRange } from './support/dateRange';
import { inertiaProps, loginAs, logout } from './support/demo';

// Fixtures: E2eFixtureSeeder::seedSalesKpiFixtures writes customers, subscriptions, invoices and
// payments on fixed February 2025 days (see the comment block above that method), so the Business
// and Sales charts of that month always have something to draw. Nothing, fixture or journey, is
// ever dated in January 2000, so that month is empty everywhere.
const february = { from: '2025-02-01', to: '2025-02-28' };
const empty = { from: '2000-01-01', to: '2000-01-31' };

const range = ({ from, to }: { from: string; to: string }) => `date_from=${from}&date_to=${to}`;

/** Admin must pick a technician; the demo technician is the only eligible one in the fixtures. */
async function technicianQuery(page: Page): Promise<string> {
    const { props } = await inertiaProps(page, '/admin/reports/technician');
    const list = props!.technicians as
        { data?: Array<{ id: number; name: string }> } | Array<{ id: number; name: string }>;
    const rows = Array.isArray(list) ? list : (list.data ?? []);
    const demo = rows.find((row) => row.name === 'Demo Technician') ?? rows[0];
    if (!demo) throw new Error('no eligible technician in the fixtures');
    return `technician_id=${demo.id}&`;
}

/** A chart is drawn when its payload holds something and shows the empty message otherwise. */
async function expectDrawnWhen(card: Locator, hasData: boolean) {
    if (hasData) {
        await expect(chartSvg(card)).toBeVisible();
        await expect(card.getByText(EMPTY_CHART_TEXT)).toHaveCount(0);
    } else {
        await expect(card.getByText(EMPTY_CHART_TEXT)).toBeVisible();
        await expect(chartSvg(card)).toHaveCount(0);
    }
}

/**
 * Every chart fits the viewport in both themes: no horizontal scroll of the document, of main or
 * of a card (a legend or an axis that spills out widens its card), and a screenshot per theme and
 * width is attached for the legend and colour review.
 */
async function expectChartsFit(
    page: Page,
    testInfo: TestInfo,
    name: string,
    path: string,
    titles: Array<string | RegExp>,
) {
    for (const width of [1280, 375]) {
        for (const dark of [false, true]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(path);
            // The reload below would abort a chart chunk that is still loading.
            await expectChartSettled(chartCard(page, titles[0]));
            await page.evaluate((value) => localStorage.setItem('darkMode', String(value)), dark);
            await page.reload();
            await expect(page.locator('html')).toHaveClass(dark ? /\bdark\b/ : /^(?!.*\bdark\b)/);

            for (const title of titles) {
                const card = chartCard(page, title);
                await expectChartSettled(card);

                const box = await card.boundingBox();
                expect(box, `${name} ${title} has a box`).not.toBeNull();
                expect(
                    box!.x,
                    `${name} ${title} starts inside the viewport`,
                ).toBeGreaterThanOrEqual(0);
                expect(
                    box!.x + box!.width,
                    `${name} ${title} ends inside ${width}px`,
                ).toBeLessThanOrEqual(width + 0.5);
                expect(
                    await card.evaluate((el) => el.scrollWidth <= el.clientWidth + 1),
                    `${name} ${title} spills out of its card at ${width}px`,
                ).toBe(true);
            }

            expect(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= window.innerWidth,
                ),
                `${name} scrolls the document at ${width}px ${dark ? 'dark' : 'light'}`,
            ).toBe(true);
            expect(
                await page.getByRole('main').evaluate((el) => el.scrollWidth <= el.clientWidth),
                `${name} scrolls main at ${width}px ${dark ? 'dark' : 'light'}`,
            ).toBe(true);

            await testInfo.attach(`${name}-${width}-${dark ? 'dark' : 'light'}`, {
                body: await page.screenshot({ fullPage: true }),
                contentType: 'image/png',
            });
        }
    }
}

test.describe('@report-charts report charts', () => {
    test('Business draws revenue, MRR and invoice status below the metric cards', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/reports/business?${range(february)}`);

        // 28 days: one point per day.
        const trend = chartCard(page, 'Pendapatan terbayar dan MRR per hari');
        await expect(chartSvg(trend)).toBeVisible();
        await expect(chartSvg(trend)).toHaveAttribute(
            'aria-label',
            'Grafik garis pendapatan terbayar dan MRR per hari',
        );
        await expect(trend.getByText('Pendapatan terbayar', { exact: true })).toBeVisible();
        await expect(trend.getByText('MRR (akhir periode)', { exact: true })).toBeVisible();

        // The fixture invoices of February are paid, with one draft and one cancelled beside them.
        const status = chartCard(page, 'Tagihan menurut status');
        await expect(chartSvg(status)).toHaveAttribute(
            'aria-label',
            'Diagram donat jumlah tagihan menurut status',
        );
        await expect(status.getByText('Lunas', { exact: true })).toBeVisible();

        // Charts sit under the cards, not beside or above them.
        const lastCard = await page
            .getByRole('main')
            .getByRole('heading', { name: 'SLA Compliance', exact: true })
            .boundingBox();
        const chart = await trend.boundingBox();
        expect(lastCard).not.toBeNull();
        expect(chart).not.toBeNull();
        expect(chart!.y).toBeGreaterThan(lastCard!.y + lastCard!.height);
    });

    test('Business shows the empty message without data and names the bucket size', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/reports/business?${range(empty)}`);

        for (const title of ['Pendapatan terbayar dan MRR per hari', 'Tagihan menurut status']) {
            await expectDrawnWhen(chartCard(page, title), false);
        }

        // Up to 31 days a bucket is a day, up to 184 days a week, beyond that a month.
        await page.goto('/admin/reports/business?date_from=2025-01-01&date_to=2025-03-31');
        await expectChartSettled(chartCard(page, 'Pendapatan terbayar dan MRR per minggu'));
        await page.goto('/admin/reports/business?date_from=2024-10-01&date_to=2025-04-30');
        await expectChartSettled(chartCard(page, 'Pendapatan terbayar dan MRR per bulan'));
        // No range at all is every month since the first row.
        await page.goto('/admin/reports/business');
        await expectChartSettled(chartCard(page, 'Pendapatan terbayar dan MRR per bulan'));
    });

    test('the period field and Run redraw the charts and keep ISO dates in the URL', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/reports/business');
        const period = page.getByLabel('Periode', { exact: true });
        await expect(period).toHaveValue('');

        await typeDateRange(page, period, february.from, february.to);
        await page.getByRole('button', { name: 'Run', exact: true }).click();
        await expect(page).toHaveURL(
            new RegExp(`date_from=${february.from}&date_to=${february.to}`),
        );
        await expect(
            chartSvg(chartCard(page, 'Pendapatan terbayar dan MRR per hari')),
        ).toBeVisible();
        await expect(period).not.toHaveAttribute('aria-invalid', 'true');

        // The drawing follows the next range too, not only the first one.
        await typeDateRange(page, period, empty.from, empty.to);
        await page.getByRole('button', { name: 'Run', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`date_from=${empty.from}&date_to=${empty.to}`));
        await expectDrawnWhen(chartCard(page, 'Pendapatan terbayar dan MRR per hari'), false);
    });

    test('Sales draws new customers per day and per acquirer, and the payments they brought', async ({
        page,
    }) => {
        await loginAs(page, 'manager');
        await page.goto(`/admin/reports/sales?${range(february)}&per_page=50`);

        await expect(chartSvg(chartCard(page, 'Pelanggan baru per hari'))).toBeVisible();

        const customers = chartCard(page, 'Pelanggan baru per acquirer');
        await expect(chartSvg(customers)).toHaveAttribute(
            'aria-label',
            'Grafik batang pelanggan baru per acquirer',
        );
        await expect(customers).toContainText('Demo Manager');
        await expect(customers).toContainText('Demo Staff');

        const payments = chartCard(page, 'Pembayaran diterima per acquirer');
        await expect(chartSvg(payments)).toBeVisible();
        await expect(payments).toContainText('Demo Manager');

        // The charts read every acquirer, not the table page: page 2 of the table holds no row,
        // yet both acquirers are still drawn.
        await page.goto(`/admin/reports/sales?${range(february)}&per_page=10&page=2`);
        await expect(page.getByRole('cell', { name: 'Demo Manager', exact: true })).toHaveCount(0);
        await expect(chartCard(page, 'Pelanggan baru per acquirer')).toContainText('Demo Staff');

        // The filter moves the charts along with the table: outside the fixtures nothing is drawn.
        await page.goto(`/admin/reports/sales?${range(empty)}&per_page=10`);
        for (const title of [
            'Pelanggan baru per hari',
            'Pelanggan baru per acquirer',
            'Pembayaran diterima per acquirer',
        ]) {
            await expectDrawnWhen(chartCard(page, title), false);
        }
    });

    test('SLA draws compliance and categories as the server counted them', async ({ page }) => {
        await loginAs(page, 'admin');

        const { props } = await inertiaProps(page, '/admin/reports/sla');
        const { compliant, breached } = props!.charts.compliance as {
            compliant: number;
            breached: number;
        };
        const categories = props!.data.by_category as unknown[];

        await page.goto('/admin/reports/sla');
        await expectDrawnWhen(
            chartCard(page, 'Tiket selesai menurut SLA'),
            compliant + breached > 0,
        );
        await expectDrawnWhen(chartCard(page, 'Tiket selesai per kategori'), categories.length > 0);

        await page.goto(`/admin/reports/sla?${range(empty)}`);
        await expectDrawnWhen(chartCard(page, 'Tiket selesai menurut SLA'), false);
        await expectDrawnWhen(chartCard(page, 'Tiket selesai per kategori'), false);
    });

    test('Asset draws status and location of the fixture assets and names them in Indonesian', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/reports/asset');

        const status = chartCard(page, 'Aset jaringan menurut status');
        await expect(chartSvg(status)).toHaveAttribute(
            'aria-label',
            'Diagram donat jumlah aset jaringan menurut status',
        );
        await expect(status.getByText(/^(Tersedia|Terpasang)$/).first()).toBeVisible();

        await expect(chartSvg(chartCard(page, 'Aset jaringan per lokasi'))).toBeVisible();

        // The table below is the accessible twin of the donut and says the same words.
        await expect(
            page.getByRole('cell', { name: /^(Tersedia|Terpasang)$/ }).first(),
        ).toBeVisible();

        // A snapshot: the period does not empty it.
        await page.goto(`/admin/reports/asset?${range(empty)}`);
        await expectDrawnWhen(chartCard(page, 'Aset jaringan menurut status'), true);
    });

    test('Technician names the bucket size and shows the empty message without finished work', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        const tech = await technicianQuery(page);
        await page.goto(`/admin/reports/technician?${tech}${range(empty)}`);

        for (const title of [
            'SPK selesai per hari',
            'SPK selesai menurut jenis',
            'Tiket selesai menurut kategori',
        ]) {
            await expectDrawnWhen(chartCard(page, title), false);
        }

        // A whole year: months. Each chart is drawn exactly when the server found something.
        const year = new Date().getFullYear();
        const wholeYear = `date_from=${year}-01-01&date_to=${year}-12-31`;
        const { props } = await inertiaProps(page, `/admin/reports/technician?${tech}${wholeYear}`);
        const points = props!.charts.series.points as Array<{ spk_completed: number }>;
        const hasKeys = (map: unknown) => Object.keys((map ?? {}) as object).length > 0;

        await page.goto(`/admin/reports/technician?${tech}${wholeYear}`);
        await expectDrawnWhen(
            chartCard(page, 'SPK selesai per bulan'),
            points.some((point) => point.spk_completed > 0),
        );
        await expectDrawnWhen(
            chartCard(page, 'SPK selesai menurut jenis'),
            hasKeys(props!.data.spk_by_type),
        );
        await expectDrawnWhen(
            chartCard(page, 'Tiket selesai menurut kategori'),
            hasKeys(props!.data.tickets_by_category),
        );
    });

    test('the five report pages draw charts without horizontal scroll in both themes', async ({
        page,
    }, testInfo) => {
        test.setTimeout(300_000);
        const business = ['Pendapatan terbayar dan MRR per hari', 'Tagihan menurut status'];
        const sales = [
            'Pelanggan baru per hari',
            'Pelanggan baru per acquirer',
            'Pembayaran diterima per acquirer',
        ];
        const sla = ['Tiket selesai menurut SLA', 'Tiket selesai per kategori'];
        const asset = ['Aset jaringan menurut status', 'Aset jaringan per lokasi'];
        const technician = [
            'SPK selesai per hari',
            'SPK selesai menurut jenis',
            'Tiket selesai menurut kategori',
        ];

        try {
            await loginAs(page, 'admin');
            await expectChartsFit(
                page,
                testInfo,
                'business',
                `/admin/reports/business?${range(february)}`,
                business,
            );
            await expectChartsFit(
                page,
                testInfo,
                'sales',
                `/admin/reports/sales?${range(february)}`,
                sales,
            );
            await expectChartsFit(
                page,
                testInfo,
                'sla',
                `/admin/reports/sla?${range(february)}`,
                sla,
            );
            await expectChartsFit(
                page,
                testInfo,
                'asset',
                `/admin/reports/asset?${range(february)}`,
                asset,
            );
            await logout(page);

            await loginAs(page, 'admin');
            const tech = await technicianQuery(page);
            await expectChartsFit(
                page,
                testInfo,
                'technician',
                `/admin/reports/technician?${tech}${range(february)}`,
                technician,
            );
        } finally {
            await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
        }
    });
});
