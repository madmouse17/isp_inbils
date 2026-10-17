import type { Page } from '@playwright/test';

import { apiLogin } from './support/api';
import { companyDateFormat, formatIsoAs } from './support/dateRange';
import { expect, test } from './support/fixtures';
import {
    expectDetail,
    expectSpkState,
    inertiaProps,
    loginAs,
    logout,
    selectFirstSearchOption,
    selectSearchOption,
} from './support/demo';

const runId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

test.describe('@kanban-t_49387795 UI journeys', () => {
    test.describe.configure({ mode: 'serial' });

    test('@kanban-t_49387795 creates, assigns, and starts SPK with visible lifecycle states', async ({
        page,
        request,
    }) => {
        const customerName = await createCustomer(page, `SPK-${runId}`);
        const title = 'E2E T49387795 SPK lifecycle';

        await page.goto('/admin/spk/create');
        await page.getByLabel('Jenis').selectOption('maintenance');
        await page.getByLabel('Judul').fill(title);
        await selectSearchOption(page, 'Pelanggan', customerName);
        await selectSearchOption(page, 'Lokasi', 'E2E ODP Site 01');
        await page.getByLabel('Prioritas').selectOption('high');
        await typeDate(page, 'Tanggal jadwal', '2026-07-14');
        await page
            .getByLabel('Deskripsi')
            .fill('Create, assign, start only. PHP tests cover stock, asset, invoice correctness.');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();

        // store() redirects to the index. The customer's onboarding SPK is listed too, so the
        // row is found by the SPK title, then opened through its code link.
        await expect(page).toHaveURL(/\/admin\/spk$/);
        await page.goto(`/admin/spk?search=${encodeURIComponent(title)}`);
        const spkRow = page.getByRole('row').filter({ hasText: title }).first();
        await expect(spkRow).toBeVisible({ timeout: 15_000 });
        await spkRow.getByRole('link', { name: /Buka SPK/ }).click();
        await expect(page).toHaveURL(/\/admin\/spk\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        const spkUrl = page.url();
        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
        await expectSpkState(page, 'draft');
        await expect(page.getByRole('button', { name: 'Tugaskan' })).toBeVisible();

        await page.getByRole('button', { name: 'Tugaskan' }).click();
        const assignDialog = page.getByRole('dialog', { name: 'Tugaskan SPK' });
        // The technician is a SearchSelect: type to filter, pick the option by the employee name.
        // The options are role=option items in a popover outside the dialog.
        await selectSearchOption(page, 'Teknisi', 'Demo Technician');
        await assignDialog.getByRole('button', { name: 'Tugaskan' }).click();

        await expectSpkState(page, 'assigned');
        await expectDetail(page, 'Teknisi', 'Demo Technician');

        // Starting is limited to the assigned technician (WorkOrderPolicy::start), who works in the app.
        await logout(page);
        const api = await apiLogin(request, 'technician');
        const started = await api.post(
            `technician/work-orders/${spkUrl.split('/').pop()}/start`,
            {},
        );
        expect(started.status()).toBe(200);

        await loginAs(page, 'admin');
        await page.goto(spkUrl);
        await expectSpkState(page, 'in_progress');
        await expect(page.getByRole('button', { name: 'Ajukan review' })).toBeVisible();
    });

    test('@kanban-t_49387795 records invoice payment and shows paid balance', async ({ page }) => {
        const customerName = await createCustomer(page, `PAY-${runId}`);
        const invoiceLine = 'E2E T49387795 payment line';
        const reference = `E2E-T49387795-PAY-${runId}`;

        await page.goto('/admin/invoices/create');
        await selectSearchOption(page, 'Pelanggan', customerName);
        await typeDate(page, 'Tanggal terbit', '2026-07-14');
        await typeDate(page, 'Jatuh tempo', '2026-07-28');
        await page.getByLabel('Catatan').fill('Payment recording journey.');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();

        // store() redirects to the index; open the just-created invoice through the search on its
        // customer's name (one invoice per fresh customer in this journey).
        await expect(page).toHaveURL(/\/admin\/invoices$/);
        await page.goto(`/admin/invoices?search=${encodeURIComponent(customerName)}`);
        const invoiceRow = page.getByRole('row').filter({ hasText: customerName }).first();
        await invoiceRow.getByRole('link', { name: 'Lihat', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/invoices\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        await expectDetail(page, 'Status', 'Draf');

        await page.getByRole('button', { name: 'Tambah item' }).click();
        const itemDialog = page.getByRole('dialog', { name: 'Tambah item' });
        await itemDialog.getByLabel('Deskripsi').fill(invoiceLine);
        await itemDialog.getByLabel('Kuantitas').fill('1');
        await itemDialog.getByLabel('Harga satuan').fill('120000');
        await itemDialog.getByLabel('Diskon').fill('0');
        await itemDialog.getByLabel('Pajak (%)').fill('0');
        await itemDialog.getByRole('button', { name: 'Simpan', exact: true }).click();

        await expect(page.getByRole('row').filter({ hasText: invoiceLine })).toContainText(
            /Rp\s*120\.000/,
        );
        await expectDetail(page, 'Total', /Rp\s*120\.000/);

        await page.getByRole('button', { name: 'Kirim tagihan' }).click();
        await expectDetail(page, 'Status', 'Belum dibayar');
        await expect(page.getByRole('button', { name: 'Catat pembayaran' })).toBeVisible();

        await page.getByRole('button', { name: 'Catat pembayaran' }).click();
        const paymentDialog = page.getByRole('dialog', { name: 'Catat pembayaran' });
        await expect(paymentDialog.getByText(/Sisa tagihan:\s*Rp\s*120\.000/)).toBeVisible();
        await paymentDialog.getByLabel('Jumlah').fill('120000');
        await paymentDialog.getByLabel('Metode').selectOption('transfer');
        await paymentDialog.getByLabel('Referensi').fill(reference);
        await paymentDialog.getByLabel('Catatan').fill('Paid by E2E.');
        await paymentDialog.getByRole('button', { name: 'Simpan', exact: true }).click();

        await expectDetail(page, 'Status', 'Lunas');
        await expectDetail(page, 'Terbayar', /Rp\s*120\.000/);
        await expectDetail(page, 'Sisa', /Rp\s*0(?!\.)/);
        const paymentRow = page.getByRole('row').filter({ hasText: reference });
        await expect(paymentRow).toContainText(/Rp\s*120\.000/);
        await expect(paymentRow).toContainText('Transfer');
        await expect(paymentRow).toContainText('Aktif');
    });

    test('@kanban-t_49387795 creates ticket, assigns, starts, comments, and spawns SPK', async ({
        page,
        request,
    }) => {
        const customerName = await createCustomer(page, `TICKET-${runId}`);
        const title = 'E2E T49387795 ticket journey';
        const comment = 'E2E T49387795 internal dispatch note';

        await page.goto('/admin/tickets/create');
        await page.getByLabel('Judul').fill(title);
        await selectSearchOption(page, 'Kategori', 'No Internet');
        await page.getByLabel('Prioritas').selectOption('high');
        await selectSearchOption(page, 'Pelanggan', customerName);
        await page
            .getByLabel('Deskripsi')
            .fill('Ticket assignment, start, comment, and SPK spawn journey.');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();

        // store() redirects to the tickets index (by design): resolve the created ticket's id from
        // the Inertia props of the title-scoped index, then open its detail page.
        await expect(page).toHaveURL(/\/admin\/tickets$/);
        const ticketId = await ticketIdFromIndex(page, title);
        if (!ticketId) throw new Error('Could not resolve created ticket id');
        await page.goto(`/admin/tickets/${ticketId}`);
        await expect(page.getByRole('main').getByRole('heading', { name: title })).toBeVisible();
        await expectDetail(page, 'Status', 'Terbuka');

        await page.getByRole('button', { name: 'Tugaskan' }).click();
        const assignDialog = page.getByRole('dialog', { name: 'Tugaskan tiket' });
        await selectSearchOption(page, 'Petugas', 'Demo Technician');
        await assignDialog.getByRole('button', { name: 'Tugaskan' }).click();

        await expectDetail(page, 'Status', 'Ditugaskan');
        await expectDetail(page, 'Petugas', 'Demo Technician');

        // Starting is limited to the assigned handler (TicketPolicy::start).
        const ticketUrl = page.url();
        await logout(page);
        const api = await apiLogin(request, 'technician');
        const started = await api.post(`technician/tickets/${ticketId}/start`, {});
        expect(started.status()).toBe(200);

        // Internal comments and SPK spawning are staff abilities.
        await loginAs(page, 'admin');
        await page.goto(ticketUrl);
        await expectDetail(page, 'Status', 'Dikerjakan');
        await expect(page.getByRole('button', { name: 'Buat SPK' })).toBeVisible();

        await page.getByRole('button', { name: 'Tambah komentar' }).click();
        const commentDialog = page.getByRole('dialog', { name: 'Tambah komentar' });
        await commentDialog.getByLabel(/^Komentar/).fill(comment);
        await commentDialog.getByLabel('Terlihat oleh').selectOption('internal');
        await commentDialog.getByRole('button', { name: 'Kirim komentar' }).click();

        await expect(page.getByText(comment)).toBeVisible();

        await page.getByRole('button', { name: 'Buat SPK' }).click();
        await page
            .getByRole('dialog', { name: 'Buat SPK dari tiket' })
            .getByRole('button', { name: 'Buat SPK' })
            .click();

        await expect(page).toHaveURL(/\/admin\/spk\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        await expect(
            page.getByRole('main').getByRole('heading', { name: /SPK dari tiket TKT-\d{4}-\d{5}/ }),
        ).toBeVisible();
        await expectSpkState(page, 'generated');
        await expectDetail(page, 'Pelanggan', customerName);
    });
});

/** Type a day into a DatePicker in the company format and commit it with Tab. */
async function typeDate(page: Page, label: string, iso: string) {
    const format = await companyDateFormat(page);
    const field = page.getByLabel(label, { exact: true });
    await field.fill(formatIsoAs(iso, format));
    await field.press('Tab');
}

async function createCustomer(page: Page, key: string) {
    const slug = `E2E-${key}-49387795`;
    const name = `E2E ${key} 49387795`;

    await loginAs(page);
    await page.goto('/admin/customers/create');
    await page.getByLabel('Nama').fill(name);
    await page.getByLabel('Email').fill(`${slug.toLowerCase()}@example.test`);
    await page.getByLabel('Telepon').fill('0800004938');

    // The create form requires a complete address on the Alamat tab.
    await page.getByRole('tab', { name: /Alamat \(\d+\)/ }).click();
    await page.getByLabel('Label').fill(`E2E Address ${key}`);
    await page.getByLabel('Alamat *', { exact: true }).fill('8 E2E Fiber Street');
    await page.getByLabel('Provinsi *', { exact: true }).click();
    await page
        .getByRole('option', { name: /jakarta/i })
        .first()
        .click();
    await page.getByLabel('Kota / Kabupaten *', { exact: true }).click();
    await page
        .getByRole('option', { name: /jakarta pusat/i })
        .first()
        .click();
    await page.getByLabel('Kecamatan *', { exact: true }).click();
    await page
        .getByRole('option', { name: /menteng/i })
        .first()
        .click();
    await page.getByLabel('Kelurahan / Desa *', { exact: true }).click();
    await page
        .getByRole('option', { name: /menteng/i })
        .first()
        .click();
    await page.getByRole('button', { name: 'Lintang / Bujur' }).click();
    await page.getByLabel('Lintang', { exact: true }).fill('-6.2100000');
    await page.getByLabel('Bujur', { exact: true }).fill('106.8200000');
    await page.getByRole('button', { name: 'Cari', exact: true }).click();

    // The create form also requires a primary contact.
    await page.getByRole('tab', { name: /Kontak \(\d+\)/ }).click();
    await page.getByLabel('Nama').fill(`${name} Contact`);
    await page.getByLabel('Telepon').fill('081200004938');
    await page.getByLabel('Email').fill(`contact.${slug.toLowerCase()}@example.test`);

    // Langganan & SPK: the package and the serving POP are required, and the E2E seeder
    // provisions "Home 10Mbps" and "Default POP". Both are SearchSelects, the POP is matched
    // by substring as in customer-subscription.spec.ts.
    await page.getByRole('tab', { name: 'Langganan & SPK' }).click();
    await selectFirstSearchOption(page, 'Paket layanan');
    await selectSearchOption(page, 'POP pelayan', /Default POP/);

    await page.getByRole('tab', { name: 'Data pelanggan' }).click();
    await page.getByRole('button', { name: 'Buat pelanggan dan SPK' }).click();

    await expect(page).toHaveURL(/\/admin\/customers\/[0-9A-HJKMNP-TV-Z]{26}$/i);
    await expect(page.getByRole('main').getByRole('heading', { name })).toBeVisible();

    return name;
}

/**
 * Resolve a ticket id by its unique title via the Tickets index Inertia props (canonical
 * parsing helper: support/demo.ts inertiaProps). The server-side search matches the title, so
 * the title-scoped index lists the ticket the form just created.
 */
async function ticketIdFromIndex(page: Page, title: string) {
    const { props } = await inertiaProps(
        page,
        `/admin/tickets?search=${encodeURIComponent(title)}`,
    );
    const tickets = props?.['tickets'] as
        { data?: Array<{ id: string; title: string }> } | undefined;
    const ticket = tickets?.data?.find((entry) => entry.title === title);

    return ticket ? String(ticket.id) : null;
}
