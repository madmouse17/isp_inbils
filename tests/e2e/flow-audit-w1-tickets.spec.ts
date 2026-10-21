import { Buffer } from 'node:buffer';
import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { expectDetail, loginAs, logout } from './support/demo';

async function openTicket(page: Page, code: string, title: string) {
    await page.goto(`/admin/tickets?search=${encodeURIComponent(code)}`);
    await page.getByRole('link', { name: code, exact: true }).click();
    await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
}

test.describe('@flow-audit-w1 tickets', () => {
    test.use({ timezoneId: 'Asia/Jakarta' });
    test.describe.configure({ mode: 'serial' });

    test('removes the comment action from a closed ticket', async ({ page }) => {
        await loginAs(page, 'admin');
        await openTicket(page, 'E2E-W1-TICKET-CLOSED', 'E2E W1 Tiket ditutup');

        const main = page.getByRole('main');
        await expect(
            main.getByText('Tiket sudah ditutup. Komentar baru tidak bisa ditambahkan.', {
                exact: true,
            }),
        ).toBeVisible();
        await expect(main.getByRole('button', { name: 'Tambah komentar' })).toHaveCount(0);
        await expectDetail(page, 'Status', 'Ditutup');
    });

    test('shows the reopened badge and both first-response sources', async ({ page }) => {
        await loginAs(page, 'admin');
        await openTicket(page, 'E2E-W1-TICKET-REOPENED', 'E2E W1 Tiket dibuka kembali');

        const main = page.getByRole('main');
        await expect(
            main.getByText('Dibuka kembali 20/10/2026 10:00', { exact: true }),
        ).toBeVisible();
        await expect(main.getByText('(lewat balasan)', { exact: true })).toBeVisible();
        await expect(main.getByText('Ditugaskan', { exact: true })).toBeVisible();

        await openTicket(page, 'E2E-W1-TICKET-STARTED', 'E2E W1 Respons lewat Mulai');
        await expect(main.getByText('(lewat tombol Mulai)', { exact: true })).toBeVisible();
        await expect(main.getByText('Dikerjakan', { exact: true })).toBeVisible();
    });

    test('hides Selesaikan from a non-handler', async ({ page }) => {
        await loginAs(page, 'admin');
        await openTicket(page, 'E2E-W1-TICKET-STARTED', 'E2E W1 Respons lewat Mulai');

        await expect(
            page.getByRole('main').getByRole('button', { name: 'Selesaikan', exact: true }),
        ).toHaveCount(0);
    });

    test('keeps the ticket page and shows one toast when an action is refused', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await openTicket(page, 'E2E-W1-TICKET-STARTED', 'E2E W1 Respons lewat Mulai');
        const detailUrl = page.url();

        await logout(page);
        await loginAs(page, 'manager');
        await page.goto(detailUrl);
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'E2E W1 Respons lewat Mulai' }),
        ).toBeVisible();

        await page
            .getByRole('button', { name: 'Seret berkas ke sini atau telusuri' })
            .setInputFiles({
                name: 'forbidden-ticket-upload.pdf',
                mimeType: 'application/pdf',
                buffer: Buffer.from('%PDF-1.4\ndenied E2E attachment\n%%EOF\n'),
            });
        await page.getByRole('button', { name: 'Unggah', exact: true }).click();

        const denied = page
            .getByLabel('Notifikasi')
            .getByRole('alert')
            .filter({ hasText: 'Anda tidak punya izin untuk tindakan ini.' });
        await expect(denied).toHaveCount(1);
        await expect(denied).toBeVisible();
        await expect(page).toHaveURL(detailUrl);
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'E2E W1 Respons lewat Mulai' }),
        ).toBeVisible();
    });
});
