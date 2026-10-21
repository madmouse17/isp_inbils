import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import {
    demoPassword,
    e2eCustomerName,
    inertiaProps,
    loginAs,
    logout,
    refreshSession,
    selectSearchOption,
    unique,
} from './support/demo';

const CREATOR_EMAIL = 'billing-creator@qa.inbils.test';

async function openInvoice(page: Page, number: string) {
    await page.goto(`/admin/invoices?search=${encodeURIComponent(number)}`);
    await page.getByRole('link', { name: number, exact: true }).click();
    await expect(page.getByRole('heading', { name: `Tagihan ${number}`, level: 1 })).toBeVisible();
}

/** The newest draft of a customer, as the invoices index lists it: the one a spec has just made. */
async function newestDraftOf(page: Page, customerName: string) {
    const { props } = await inertiaProps(page, '/admin/invoices?per_page=50&status=draft');
    const rows: Array<{ id: string; number: string; customer?: { name: string } }> =
        props?.invoices?.data ?? [];
    const draft = rows
        .filter((invoice) => invoice.customer?.name === customerName)
        .sort((a, b) => a.number.localeCompare(b.number))
        .at(-1);
    if (!draft) throw new Error('The draft just made was not among the drafts.');

    return draft;
}

async function loginAsBillingCreator(page: Page) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(CREATOR_EMAIL);
    await page.getByLabel(/^Kata sandi( \*)?$/).fill(demoPassword);
    await page.getByRole('button', { name: 'Masuk' }).click();
    await expect(page.getByRole('heading', { name: 'Dasbor', level: 1 })).toBeVisible();
}

test.describe('@flow-audit-w1 billing', () => {
    test.describe.configure({ mode: 'serial' });

    test('shows whole rupiah and the installation draft dashboard card', async ({ page }) => {
        await loginAs(page, 'admin');

        const finance = page.getByRole('region', { name: 'Keuangan' });
        const card = finance.getByRole('link', { name: /Tagihan draf menunggu dikirim/ });
        await expect(card).toContainText('3');
        await expect(card).toContainText('Nilai');
        await expect(card).toContainText('Rp 1.146.702');
        await expect(card).toHaveAttribute('href', /\/admin\/invoices\?status=draft$/);

        await openInvoice(page, 'E2E-W1-WHOLE');
        await expect(page.getByText(/^Total:\s*Rp\s*147\.702$/)).toBeVisible();
        await expect(
            page.getByRole('row').filter({ hasText: 'E2E whole rupiah line' }),
        ).toContainText(/Rp\s*147\.702/);
        await expect(page.getByText(/Rp\s*147\.702,00/)).toHaveCount(0);
    });

    test('refuses a future billing period', async ({ page, expectHttpError }) => {
        expectHttpError({
            url: '/admin/invoices/generate-preview',
            method: 'POST',
            status: 422,
        });
        await loginAs(page, 'admin');
        await page.goto('/admin/invoices');
        await page.getByRole('button', { name: 'Buat tagihan bulanan', exact: true }).click();

        const dialog = page.getByRole('dialog', { name: 'Buat tagihan bulanan' });
        const period = dialog.getByLabel('Periode', { exact: true });
        await period.fill('9999-12');
        await period.press('Tab');
        await dialog.getByRole('button', { name: 'Lihat pratinjau' }).click();

        await expect(
            dialog
                .getByRole('alert')
                .filter({ hasText: 'Periode tagihan paling lambat bulan berjalan.' }),
        ).toBeVisible();
        await expect(dialog.getByText(/tagihan akan dibuat/)).toHaveCount(0);
    });

    test('keeps a numeric date and blocks an invalid date from saving', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/invoices/create');
        await selectSearchOption(page, 'Pelanggan', 'E2E Linked Customer');

        const issueDate = page.getByLabel('Tanggal terbit', { exact: true });
        await issueDate.fill('20/10/2026');
        await issueDate.press('Tab');
        await expect(issueDate).toHaveValue('20/10/2026');
        await expect(issueDate).not.toHaveAttribute('aria-invalid', 'true');

        await issueDate.fill('31/02/2026');
        await issueDate.press('Tab');
        await expect(issueDate).toHaveAttribute('aria-invalid', 'true');
        await expect(
            page.getByRole('alert').filter({ hasText: 'Format tanggal tidak valid.' }),
        ).toBeVisible();

        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/invoices\/create(?:\?.*)?$/);
        await expect(issueDate).toBeFocused();
        await expect(issueDate).toHaveValue('31/02/2026');
    });

    test('limits draft controls to the billing creator and reports an empty send', async ({
        page,
    }) => {
        await loginAsBillingCreator(page);
        await openInvoice(page, 'E2E-W1-WHOLE');

        await expect(page.getByRole('button', { name: 'Tambah item' })).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Hapus item E2E whole rupiah line' }),
        ).toBeVisible();
        await expect(page.getByRole('button', { name: 'Kirim tagihan' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Batalkan tagihan' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Catat pembayaran' })).toHaveCount(0);

        await logout(page);
        await loginAs(page, 'admin');
        await openInvoice(page, 'E2E-W1-EMPTY');
        const path = new URL(page.url()).pathname;
        const send = page.getByRole('button', { name: 'Kirim tagihan' });
        await expect(send).toBeDisabled();
        await expect(send).toHaveAccessibleDescription(
            'Tambahkan minimal satu item sebelum mengirim.',
        );

        const props = await inertiaProps(page, path);
        const companyId = String(props.props?.company?.id ?? '');
        expect(companyId).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/i);
        const response = await page.request.post(`${path}/send`, {
            headers: {
                'X-XSRF-TOKEN': await refreshSession(page, path),
                'X-Inertia': 'true',
                'X-Inbils-Company': companyId,
            },
            maxRedirects: 0,
        });
        expect(response.status()).toBe(302);

        await page.reload();
        const denied = page
            .getByLabel('Notifikasi')
            .getByRole('alert')
            .filter({ hasText: 'Tagihan tanpa item atau bernilai Rp 0 tidak bisa dikirim.' });
        await expect(denied).toHaveCount(1);
        await expect(denied).toBeVisible();
        await expect(page).toHaveURL(path);
    });

    test('opens the refund dialog from both finance views and records the refund', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/billing/receivables');

        const exceptions = page
            .getByRole('alert')
            .filter({ hasText: 'pembayaran online perlu dicek' });
        await expect(exceptions.getByRole('link', { name: 'E2E-W1-REFUND' })).toBeVisible();
        await exceptions
            .getByRole('button', {
                name: 'Tandai dikembalikan untuk tagihan E2E-W1-REFUND',
            })
            .click();

        let dialog = page.getByRole('dialog', { name: 'Tandai pembayaran dikembalikan' });
        await expect(dialog).toContainText(
            'Isi setelah uang dikembalikan lewat dasbor Midtrans atau transfer bank. Tindakan ini dicatat di log audit.',
        );
        await expect(dialog.getByLabel('Nomor referensi pengembalian')).toBeVisible();
        await expect(dialog.getByLabel('Catatan')).toBeVisible();
        await dialog.getByRole('button', { name: 'Batal' }).click();

        await openInvoice(page, 'E2E-W1-REFUND');
        const attempt = page.getByRole('row').filter({ hasText: 'E2E-W1-REFUND-TRX' });
        await expect(attempt).toContainText('Perlu dicek');
        await attempt.getByRole('button', { name: 'Tandai dikembalikan' }).click();

        dialog = page.getByRole('dialog', { name: 'Tandai pembayaran dikembalikan' });
        await dialog.getByLabel('Nomor referensi pengembalian').fill('E2E-W1-REF-001');
        await dialog.getByLabel('Catatan').fill('Dana dikembalikan melalui dasbor Midtrans.');
        await dialog.getByRole('button', { name: 'Simpan', exact: true }).click();

        await expect(dialog).toBeHidden();
        await expect(
            page.getByRole('status').filter({ hasText: 'Pembayaran ditandai dikembalikan.' }),
        ).toBeVisible();
        await expect(attempt).toContainText('Dikembalikan');
        await expect(attempt).toContainText('oleh Demo Admin');
        await expect(attempt).toContainText('Ref E2E-W1-REF-001');
        await expect(attempt.getByRole('button', { name: 'Tandai dikembalikan' })).toHaveCount(0);
    });

    test('makes a manual invoice and its items in one form, then edits the draft', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        const { props } = await inertiaProps(page, '/admin/invoices/create');
        const rate = Number(props?.tax_rate);
        const rupiah = (amount: number) =>
            new RegExp(`Rp\\s*${new Intl.NumberFormat('id-ID').format(amount)}(?!\\d)`);
        const tax = Math.round((300000 * rate) / 100) + Math.round((3333 * rate) / 100);

        await page.goto('/admin/invoices/create');
        await selectSearchOption(page, 'Pelanggan', e2eCustomerName);
        await page.getByLabel('Catatan').fill(unique('W3 satu formulir'));

        const first = page.getByRole('group', { name: 'Item 1' });
        await first.getByLabel('Deskripsi').fill('Kabel fiber 2 m');
        await first.getByLabel('Kuantitas').fill('2');
        await first.getByLabel('Harga satuan').fill('150000');
        await expect(first).toContainText(rupiah(300000));

        await page.getByRole('button', { name: 'Tambah item' }).click();
        const second = page.getByRole('group', { name: 'Item 2' });
        await second.getByLabel('Deskripsi').fill('Konektor');
        await second.getByLabel('Harga satuan').fill('3333');
        await page.getByRole('button', { name: 'Tambah item' }).click();
        await page.getByRole('button', { name: 'Hapus item 3' }).click();
        await expect(page.getByRole('group', { name: 'Item 3' })).toHaveCount(0);

        const summary = page.locator('dl').filter({ hasText: 'Subtotal' });
        await expect(summary).toContainText(rupiah(303333));
        await expect(summary).toContainText(rupiah(303333 + tax));

        await page.getByRole('button', { name: '7 hari', exact: true }).click();
        await expect(page.getByLabel('Jatuh tempo', { exact: true })).not.toHaveValue('');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/invoices$/);

        const draft = await newestDraftOf(page, e2eCustomerName);
        await page.goto(`/admin/invoices/${draft.id}`);
        const cable = page.getByRole('row').filter({ hasText: 'Kabel fiber 2 m' });
        await expect(cable).toContainText(rupiah(300000));
        await expect(cable).toContainText(
            `${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(rate)}%`,
        );
        await expect(page.getByText(/^Total:\s*Rp\s*[\d.]+$/)).toContainText(rupiah(303333 + tax));

        await page.getByRole('link', { name: 'Ubah', exact: true }).click();
        await expect(
            page.getByRole('heading', { name: `Ubah tagihan ${draft.number}`, level: 1 }),
        ).toBeVisible();
        await page.getByLabel('Diskon').fill('10000');
        await page.getByLabel('Catatan').fill(unique('W3 ubah'));
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/invoices$/);
        await expect(
            page.getByRole('status').filter({ hasText: 'Tagihan diperbarui.' }),
        ).toBeVisible();

        await page.goto(`/admin/invoices/${draft.id}`);
        await expect(page.getByText(/^Diskon:\s*Rp\s*10\.000$/)).toBeVisible();
    });

    test('an issued invoice has no edit page: it goes back to its own page with the reason', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        const { props } = await inertiaProps(page, '/admin/invoices?search=E2E-REC-LINKED');
        const sent = props?.invoices?.data?.[0];
        if (!sent) throw new Error('E2E-REC-LINKED is not among the invoices.');

        await page.goto(`/admin/invoices/${sent.id}/edit`);

        await expect(page).toHaveURL(new RegExp(`/admin/invoices/${sent.id}$`));
        await expect(
            page
                .getByLabel('Notifikasi')
                .getByRole('alert')
                .filter({ hasText: 'Hanya tagihan draf yang bisa diubah.' }),
        ).toBeVisible();
        await expect(page.getByRole('link', { name: 'Ubah', exact: true })).toHaveCount(0);
    });

    test('Tunggakan lists the open invoices one by one, narrows by age and reminds a customer once a day', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/billing/receivables');
        await expect(page.getByRole('tab', { name: 'Per tagihan', selected: true })).toBeVisible();

        const linked = page.getByRole('row').filter({ hasText: 'E2E-REC-LINKED' });
        await expect(linked).toContainText(/Lewat 1[56] hari/);
        await expect(linked).toContainText('Lewat jatuh tempo');
        await expect(linked).toContainText(/Rp\s*120\.000/);

        await page.getByLabel('Umur').selectOption('b90_plus');
        await expect(page.getByRole('row').filter({ hasText: 'E2E-REC-LINKED' })).toHaveCount(0);
        await expect(
            page
                .getByRole('row')
                .filter({ hasText: /Lewat 10\d hari/ })
                .first(),
        ).toBeVisible();
        await page.getByLabel('Umur').selectOption('');
        await expect(linked).toBeVisible();

        // Reruns on one day find the invoices of the first run already reminded: take one that is still free.
        const free = page
            .getByRole('row')
            .filter({ has: page.getByRole('button', { name: /^Kirim pengingat/ }) })
            .first();
        const number = (await free.getByRole('link').first().innerText()).trim();
        await free.getByRole('button', { name: /^Kirim pengingat/ }).click();
        const dialog = page.getByRole('alertdialog', { name: `Kirim pengingat untuk ${number}?` });
        await expect(dialog).toContainText('Satu tagihan hanya bisa diingatkan sekali per hari.');
        await dialog.getByRole('button', { name: 'Kirim pengingat', exact: true }).click();

        await expect(
            page.getByRole('status').filter({ hasText: `Pengingat dikirim untuk ${number}.` }),
        ).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: number })).toContainText(
            'Pengingat terkirim hari ini',
        );

        // The customer view is still there, on its own tab and in its own address.
        await page.getByRole('tab', { name: 'Per pelanggan' }).click();
        await expect(page).toHaveURL(/tab=customer/);
        await expect(page.getByRole('button', { name: 'Pelanggan' })).toBeVisible();

        await page.goto('/admin/billing/receivables');
        await page.getByRole('button', { name: 'Ekspor', exact: true }).click();
        await expect(page.getByRole('menuitem', { name: 'Ekspor CSV' })).toBeVisible();
        await expect(page.getByRole('menuitem', { name: 'Ekspor PDF' })).toBeVisible();
        await page.keyboard.press('Escape');

        const file = await page.request.get('/admin/billing/receivables/export?format=csv');
        expect(file.status()).toBe(200);
        expect(file.headers()['content-type']).toContain('text/csv');
        expect(await file.text()).toContain('E2E-REC-LINKED');
    });
});
