import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { inertiaProps, loginAs } from './support/demo';

/**
 * P2 report export and drill-down (owner-approved contract in .scratch/p2-export-drilldown/spec.md).
 * Files are fetched through page.request, which shares the browser session; the UI is checked for the
 * export menu (CSV and PDF only), the drill links, the tie-out and layout at 1280/320 in light and dark.
 */

const summaryHeader = 'Metrik,Rincian,Nilai,Satuan,Cakupan,Filter,Versi';

async function download(page: Page, path: string, maxRedirects = 20) {
    const response = await page.request.get(path, { maxRedirects });
    return {
        status: response.status(),
        type: response.headers()['content-type'] ?? '',
        disposition: response.headers()['content-disposition'] ?? '',
        body: await response.body(),
    };
}

async function expectNoHorizontalOverflow(page: Page) {
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
    expect(await page.getByRole('main').evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(
        true,
    );
}

test.describe('@smoke @reporting-export report export', () => {
    test('admin exports Business as CSV and PDF, never as Excel', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/reports/business?date_from=2026-01-01&date_to=2026-01-31');

        const menu = page.getByRole('button', { name: 'Ekspor', exact: true });
        await menu.click();
        await expect(page.getByRole('menuitem', { name: 'Ekspor CSV' })).toBeVisible();
        await expect(page.getByRole('menuitem', { name: 'Ekspor PDF' })).toBeVisible();
        await expect(page.getByRole('menuitem', { name: /excel/i })).toHaveCount(0);
        await page.keyboard.press('Escape');

        const csv = await download(
            page,
            '/admin/reports/business/export?format=csv&date_from=2026-01-01&date_to=2026-01-31',
        );
        expect(csv.status).toBe(200);
        expect(csv.type).toContain('text/csv');
        expect(csv.disposition).toContain('attachment');
        const text = csv.body.toString('utf8');
        expect(text.charCodeAt(0)).toBe(0xfeff);
        expect(text.slice(1).split('\n')[0].trim()).toBe(summaryHeader);
        expect(text).toContain('Periode: 2026-01-01 sampai 2026-01-31');
        expect(text).toContain('Posisi saat ini: filter periode tidak berlaku');

        const pdf = await download(page, '/admin/reports/business/export?format=pdf');
        expect(pdf.status).toBe(200);
        expect(pdf.body.subarray(0, 5).toString()).toBe('%PDF-');

        // Only CSV and PDF exist: an Excel request fails validation on `format` and goes back to the report with
        // that error, never a file (ReportExportAccessTest::test_only_csv_and_pdf_are_offered).
        const reportUrl = new URL('/admin/reports/business', page.url()).href;
        const excel = await page.request.get('/admin/reports/business/export?format=xlsx', {
            maxRedirects: 0,
            headers: { Referer: reportUrl },
        });
        expect(excel.status()).toBe(302);
        expect(excel.headers()['location']).toBe(reportUrl);
        expect(excel.headers()['content-disposition'] ?? '').not.toContain('attachment');
        expect(excel.headers()['content-type'] ?? '').not.toContain('spreadsheet');
        const refused = await inertiaProps(page, '/admin/reports/business');
        expect(refused.props?.errors?.format, 'validation error on format').toBeTruthy();
    });

    test('a viewer without report.export sees no export control and gets 403 on the URL', async ({
        page,
    }) => {
        await loginAs(page, 'staff');
        await page.goto('/admin/reports/business');
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Metrik Bisnis', level: 1 }),
        ).toBeVisible();
        await expect(page.getByRole('button', { name: /ekspor/i })).toHaveCount(0);

        for (const url of [
            '/admin/reports/business/export',
            '/admin/reports/sla/export',
            '/admin/reports/asset/export',
            '/admin/reports/stock-card/export?product_id=1',
        ]) {
            expect((await download(page, url)).status, url).toBe(403);
        }
    });

    test('a manager exports the aggregate reports but not the audit log', async ({ page }) => {
        await loginAs(page, 'manager');

        for (const slug of ['business', 'sla', 'asset']) {
            const response = await download(page, `/admin/reports/${slug}/export?format=csv`);
            expect(response.status, slug).toBe(200);
        }
        expect((await download(page, '/admin/reports/audit-log/export')).status).toBe(403);
    });

    test('stock card exports the whole ledger and offers the menu only for a chosen product', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        const { props } = await inertiaProps(page, '/admin/products?search=E2E-STOCK-PRODUCT');
        const product = ((props?.products?.data ?? []) as { id: number; sku: string }[]).find(
            (item) => item.sku === 'E2E-STOCK-PRODUCT',
        );
        expect(product, 'E2E-STOCK-PRODUCT fixture').toBeTruthy();

        await page.goto('/admin/reports/stock-card');
        await expect(page.getByRole('button', { name: /ekspor/i })).toHaveCount(0);

        await page.goto(
            `/admin/reports/stock-card?product_id=${product!.id}&date_from=2026-08-01&date_to=2026-08-11&per_page=10`,
        );
        await expect(page.getByRole('button', { name: 'Ekspor', exact: true })).toBeVisible();
        await expect(page.locator('tbody tr')).toHaveCount(10);

        const csv = await download(
            page,
            `/admin/reports/stock-card/export?product_id=${product!.id}&date_from=2026-08-01&date_to=2026-08-11&sort_by=quantity&sort_dir=asc`,
        );
        expect(csv.status).toBe(200);
        const rows = csv.body.toString('utf8').trim().split('\n');
        // Header plus all 11 fixture movements: the file is not the 10 rows of the page.
        expect(rows.length).toBe(1 + 11);
        expect(rows[1]).toContain('E2E Stock Card Movement 01');
    });

    test('audit log exports from the server in CSV and PDF only', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/reports/audit-log');

        await page.getByRole('button', { name: 'Ekspor log' }).click();
        await expect(page.getByRole('menuitem', { name: 'Ekspor CSV' })).toBeVisible();
        await expect(page.getByRole('menuitem', { name: 'Ekspor PDF' })).toBeVisible();
        await expect(page.getByRole('menuitem', { name: /excel/i })).toHaveCount(0);
        await page.keyboard.press('Escape');

        const csv = await download(page, '/admin/reports/audit-log/export?format=csv');
        expect(csv.status).toBe(200);
        expect(csv.body.toString('utf8').slice(1).split('\n')[0]).toContain('Properti');
        const pdf = await download(page, '/admin/reports/audit-log/export?format=pdf');
        expect(pdf.body.subarray(0, 5).toString()).toBe('%PDF-');
    });
});

test.describe('@smoke @reporting-export report drill-down', () => {
    test('a figure opens its source rows with a visible tie-out and the file of that list', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/reports/business');

        const link = page.getByRole('link', { name: 'Lihat data di balik MRR' });
        await link.focus();
        await expect(link).toBeFocused();
        await page.keyboard.press('Enter');

        await expect(page).toHaveURL(/\/admin\/reports\/business\/drill\/mrr/);
        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'MRR', level: 1 })).toBeVisible();
        await expect(main.getByText('Baris ditampilkan')).toBeVisible();
        await expect(main.getByText('Angka di Metrik Bisnis')).toBeVisible();
        await expect(page.getByRole('status').filter({ hasText: 'Cocok' })).toBeVisible();
        await expect(main.getByText('Posisi saat ini · v1')).toBeVisible();

        const file = await download(page, '/admin/reports/business/drill/mrr/export?format=csv');
        expect(file.status).toBe(200);
        expect(file.body.toString('utf8').slice(1).split('\n')[0]).toContain('Kode,Pelanggan');

        await main.getByRole('link', { name: 'Kembali ke Metrik Bisnis' }).click();
        await expect(page).toHaveURL(/\/admin\/reports\/business(\?|$)/);
    });

    test('a breakdown row of the SLA and Asset reports drills into its group', async ({ page }) => {
        await loginAs(page, 'admin');

        await page.goto('/admin/reports/asset');
        // The fixtures hold assets, so the status breakdown always has a row to drill into.
        const statusLink = page.getByRole('link', { name: /^Lihat data di balik aset .+$/ });
        await expect(statusLink.first()).toBeVisible();
        await statusLink.first().click();
        await expect(page).toHaveURL(/\/drill\/status_distribution\?.*group=/);
        await expect(page.getByRole('status').filter({ hasText: 'Cocok' })).toBeVisible();

        await page.goto('/admin/reports/sla');
        await page.getByRole('link', { name: 'Lihat data di balik Tiket selesai' }).click();
        await expect(page).toHaveURL(/\/admin\/reports\/sla\/drill\/total_resolved/);
        await expect(page.getByRole('status').filter({ hasText: 'Cocok' })).toBeVisible();
    });

    test('export menu, drill links and tie-out stay usable across widths and themes', async ({
        page,
    }) => {
        await loginAs(page, 'admin');

        for (const path of ['/admin/reports/business', '/admin/reports/business/drill/mrr']) {
            await page.goto(path);
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
                        page.getByRole('main').getByRole('heading', { level: 1 }),
                    ).toBeVisible();
                    await expectNoHorizontalOverflow(page);

                    const trigger = page.getByRole('button', { name: 'Ekspor', exact: true });
                    await trigger.focus();
                    await expect(trigger).toBeFocused();
                    const bounds = await trigger.boundingBox();
                    expect(bounds).not.toBeNull();
                    expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width);
                }
            }
        }
        await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
    });
});
