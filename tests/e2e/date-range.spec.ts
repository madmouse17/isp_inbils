import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { loginAs } from './support/demo';
import { companyDateFormat, formatIsoAs, typeDateRange } from './support/dateRange';

const AUDIT_LOG = '/admin/reports/audit-log';

function query(page: Page, key: string) {
    return new URL(page.url()).searchParams.get(key);
}

/** Today as ISO in the company timezone, then shifted by whole days. */
async function companyToday(page: Page, shiftDays = 0): Promise<string> {
    const timezone = await page.evaluate(() => {
        const raw = document.getElementById('app')?.getAttribute('data-page');
        const props = raw ? JSON.parse(raw).props : {};
        return (props?.company?.timezone as string | undefined) ?? 'Asia/Jakarta';
    });
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date());
    const shifted = new Date(`${today}T00:00:00Z`);
    shifted.setUTCDate(shifted.getUTCDate() + shiftDays);
    return shifted.toISOString().slice(0, 10);
}

async function openAuditLog(page: Page) {
    await loginAs(page, 'admin');
    await page.goto(AUDIT_LOG);
    await expect(page.getByRole('main').getByRole('heading', { name: 'Log audit' })).toBeVisible();
    return page.getByLabel('Periode', { exact: true });
}

test.describe('@smoke date range picker (Log audit)', () => {
    test('a typed range in the company format reaches the URL as ISO', async ({ page }) => {
        const field = await openAuditLog(page);

        await typeDateRange(page, field, '2026-09-01', '2026-09-10');
        await page.getByRole('button', { name: 'Terapkan filter' }).click();

        await expect.poll(() => query(page, 'date_from')).toBe('2026-09-01');
        expect(query(page, 'date_to')).toBe('2026-09-10');
        const format = await companyDateFormat(page);
        await expect(field).toHaveValue(
            `${formatIsoAs('2026-09-01', format)} – ${formatIsoAs('2026-09-10', format)}`,
        );
    });

    test('a half range is sent as it is: from only, then to only', async ({ page }) => {
        const field = await openAuditLog(page);

        await typeDateRange(page, field, '2026-09-05', null);
        await page.getByRole('button', { name: 'Terapkan filter' }).click();
        await expect.poll(() => query(page, 'date_from')).toBe('2026-09-05');
        expect(query(page, 'date_to')).toBeNull();

        await typeDateRange(page, field, null, '2026-09-20');
        await page.getByRole('button', { name: 'Terapkan filter' }).click();
        await expect.poll(() => query(page, 'date_to')).toBe('2026-09-20');
        expect(query(page, 'date_from')).toBeNull();
    });

    test('two clicks on the calendar pick a range', async ({ page }) => {
        const field = await openAuditLog(page);

        await page.getByRole('button', { name: 'Buka kalender' }).click();
        const calendar = page.getByRole('dialog', { name: 'Pilih rentang tanggal' });
        await expect(calendar).toBeVisible();

        const month = (await companyToday(page)).slice(0, 7);
        await calendar.locator(`[data-day="${month}-05"] button`).first().click();
        // The first click only starts the range; the popover stays open and nothing is applied.
        await expect(calendar).toBeVisible();
        await calendar.locator(`[data-day="${month}-12"] button`).first().click();
        await expect(calendar).toBeHidden();

        await page.getByRole('button', { name: 'Terapkan filter' }).click();
        await expect.poll(() => query(page, 'date_from')).toMatch(/^\d{4}-\d{2}-05$/);
        expect(query(page, 'date_to')).toMatch(/^\d{4}-\d{2}-12$/);
        const format = await companyDateFormat(page);
        await expect(field).toHaveValue(
            `${formatIsoAs(query(page, 'date_from')!, format)} – ${formatIsoAs(query(page, 'date_to')!, format)}`,
        );
    });

    test('a preset fills the range in the company timezone', async ({ page }) => {
        await openAuditLog(page);

        await page.getByRole('button', { name: 'Buka kalender' }).click();
        await page
            .getByRole('dialog', { name: 'Pilih rentang tanggal' })
            .getByRole('button', { name: '7 hari terakhir', exact: true })
            .click();
        await page.getByRole('button', { name: 'Terapkan filter' }).click();

        await expect.poll(() => query(page, 'date_to')).toBe(await companyToday(page));
        expect(query(page, 'date_from')).toBe(await companyToday(page, -6));
    });

    test('an invalid or inverted entry is flagged and never applied', async ({ page }) => {
        const field = await openAuditLog(page);

        await field.fill('bukan tanggal');
        await field.press('Tab');
        await expect(field).toHaveAttribute('aria-invalid', 'true');
        await expect(
            page.getByRole('alert').filter({ hasText: 'Format tanggal tidak valid.' }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Terapkan filter' }).click();
        await expect(page.getByRole('button', { name: 'Terapkan filter' })).toBeEnabled();
        expect(query(page, 'date_from')).toBeNull();

        await typeDateRange(page, field, '2026-09-10', '2026-09-01');
        await expect(
            page
                .getByRole('alert')
                .filter({ hasText: 'Tanggal akhir tidak boleh sebelum tanggal mulai.' }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Terapkan filter' }).click();
        expect(query(page, 'date_from')).toBeNull();
        expect(query(page, 'date_to')).toBeNull();
    });

    test('keyboard: Enter opens the calendar, Escape closes it and returns focus', async ({
        page,
    }) => {
        await openAuditLog(page);

        const trigger = page.getByRole('button', { name: 'Buka kalender' });
        await trigger.focus();
        await page.keyboard.press('Enter');
        const calendar = page.getByRole('dialog', { name: 'Pilih rentang tanggal' });
        await expect(calendar).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(calendar).toBeHidden();
        await expect(trigger).toBeFocused();
    });

    test('the clear button empties the range', async ({ page }) => {
        const field = await openAuditLog(page);

        await typeDateRange(page, field, '2026-09-01', '2026-09-10');
        await page.getByRole('button', { name: 'Hapus rentang tanggal' }).click();

        await expect(field).toHaveValue('');
        await expect(page.getByRole('button', { name: 'Hapus rentang tanggal' })).toHaveCount(0);
    });

    test('the calendar shows two months on desktop and one on a phone', async ({ page }) => {
        await openAuditLog(page);
        const calendar = page.getByRole('dialog', { name: 'Pilih rentang tanggal' });

        await page.setViewportSize({ width: 1280, height: 800 });
        await page.getByRole('button', { name: 'Buka kalender' }).click();
        await expect(calendar.getByRole('grid')).toHaveCount(2);
        await page.keyboard.press('Escape');

        await page.setViewportSize({ width: 375, height: 800 });
        await page.getByRole('button', { name: 'Buka kalender' }).click();
        await expect(calendar.getByRole('grid')).toHaveCount(1);
        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );
        expect(overflow).toBeLessThanOrEqual(0);
    });
});
