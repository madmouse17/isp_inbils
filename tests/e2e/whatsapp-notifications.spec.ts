/**
 * @lifecycle WhatsApp notifications: consent, message panel and company settings (Meta is never called)
 *
 * .env.e2e has no WHATSAPP_* credentials, so the feature is unconfigured: nothing is sent and the settings
 * page says so. This spec drives the staff surfaces only: consent on the customer forms, the WhatsApp card
 * on the customer page (fixture customer "E2E WhatsApp Customer": consented, three log rows) and the
 * WhatsApp section of the company settings. Sending, quiet hours, caps and the webhook are covered by
 * tests/Feature/WhatsApp.
 *
 * The consent tests use the datatable fixture customer "Aria Fiber", which starts without consent and with
 * an unreachable number (0800...), and end with the consent withdrawn again.
 */
import type { Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import {
    datatableCustomerNames,
    inertiaProps,
    loginAs,
    logout,
    selectFirstSearchOption,
    selectSearchOption,
    unique,
} from './support/demo';

const WA_CUSTOMER = 'E2E WhatsApp Customer';
// A datatable fixture customer: no consent, an unreachable number (0800...) and, because no spec sends it
// anything, no WhatsApp rows.
const PLAIN_CUSTOMER = datatableCustomerNames[1];
const CONSENT = /Pelanggan setuju menerima notifikasi WhatsApp/;
const MAIN_PHONE_DIGITS = '12345600';

const mainOf = (page: Page) => page.getByRole('main');

async function customerIdOf(page: Page, name: string) {
    const data = await inertiaProps(page, `/admin/customers?search=${encodeURIComponent(name)}`);
    const rows = data.props?.customers?.data as Array<{ id: string; name: string }> | undefined;
    const found = rows?.find((row) => row.name === name);
    if (!found) throw new Error(`Customer fixture ${name} not found`);

    return String(found.id);
}

const whatsappCard = (page: Page) =>
    page.getByRole('heading', { name: 'WhatsApp', exact: true }).locator('xpath=../..');

async function expectNoHorizontalOverflow(page: Page, message: string) {
    const sizes = await page.evaluate(() => ({
        viewport: window.innerWidth,
        content: document.documentElement.scrollWidth,
    }));
    expect(sizes.content, message).toBeLessThanOrEqual(sizes.viewport);
}

async function setTheme(page: Page, width: number, dark: boolean) {
    await page.setViewportSize({ width, height: 900 });
    await page.evaluate((enabled) => localStorage.setItem('darkMode', String(enabled)), dark);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(dark ? /\bdark\b/ : /^(?!.*\bdark\b)/);
}

/** Saves the customer edit form and returns the JSON body of the PUT, to see what was sent. */
async function saveCustomer(page: Page) {
    const request = page.waitForRequest(
        (candidate) =>
            candidate.method() === 'PUT' &&
            /\/admin\/customers\/[0-9A-HJKMNP-TV-Z]{26}$/i.test(candidate.url()),
    );
    await page.getByRole('button', { name: 'Simpan pelanggan' }).click();
    await expect(page).toHaveURL(/\/admin\/customers\/?$/);

    return (await request).postDataJSON() as Record<string, unknown>;
}

test.describe('@lifecycle whatsapp notifications', () => {
    test.describe.configure({ mode: 'serial' });

    let plainId = '';
    let waId = '';

    test('the customer page shows consent, the last messages with a status word and only the last four digits', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        waId = await customerIdOf(page, WA_CUSTOMER);
        plainId = await customerIdOf(page, PLAIN_CUSTOMER);

        await page.goto(`/admin/customers/${waId}`);
        const card = whatsappCard(page);
        await expect(card).toBeVisible();
        await expect(card).toContainText('Setuju sejak');
        await expect(card.getByRole('row')).toHaveCount(4);

        const rows = card.getByRole('row');
        await expect(rows.filter({ hasText: 'Terkirim' })).toContainText('Tagihan terbit');
        await expect(rows.filter({ hasText: 'Diterima' })).toContainText('Tagihan terbit');
        await expect(rows.filter({ hasText: 'Dilewati' })).toContainText('Pelanggan belum setuju');
        for (const header of ['Pesan', 'Subjek', 'Status', 'Keterangan', 'Nomor', 'Waktu']) {
            await expect(card.getByRole('columnheader', { name: header })).toBeVisible();
        }
        // Consent is visible and reachable: no warning for a valid mobile number.
        await expect(card).not.toContainText('bukan nomor seluler Indonesia yang valid');

        // The card carries the masked number only; the full one stays in the details above it.
        await expect(card).toContainText('••••0001');
        expect(await card.innerText()).not.toContain(MAIN_PHONE_DIGITS);
        await expect(mainOf(page)).toContainText('081234560001');
    });

    test('a customer without consent shows the empty state, the unreachable warning and a consent round trip on edit', async ({
        page,
    }) => {
        test.setTimeout(90_000);
        await loginAs(page, 'staff');

        await page.goto(`/admin/customers/${plainId}`);
        const card = whatsappCard(page);
        await expect(card).toContainText('Belum setuju');
        await expect(card).toContainText('Belum ada pesan WhatsApp');
        await expect(card).toContainText('bukan nomor seluler Indonesia yang valid');

        await page.goto(`/admin/customers/${plainId}/edit`);
        await expect(mainOf(page).getByRole('heading', { name: 'Ubah Pelanggan' })).toBeVisible();
        await expect(page.locator('form')).toHaveCount(1);
        const consent = page.getByRole('checkbox', { name: CONSENT });
        await expect(consent).not.toBeChecked();
        await expect(
            page.getByText(/dapat berhenti kapan saja dengan membalas STOP/),
        ).toBeVisible();
        await expect(page.getByText('Tercatat: Belum ada persetujuan tercatat')).toBeVisible();
        await expect(page.getByText(/bukan nomor seluler Indonesia yang valid/)).toBeVisible();

        // Saving without touching consent does not send it: a stale form never re-opts anyone in.
        await page.getByLabel('Catatan', { exact: true }).fill('E2E consent: unchanged');
        expect(await saveCustomer(page)).not.toHaveProperty('whatsapp_opt_in');

        // Ticking it records consent.
        await page.goto(`/admin/customers/${plainId}/edit`);
        await page.getByRole('checkbox', { name: CONSENT }).check();
        expect(await saveCustomer(page)).toHaveProperty('whatsapp_opt_in', true);
        await page.goto(`/admin/customers/${plainId}`);
        await expect(whatsappCard(page)).toContainText('Setuju sejak');

        // The edit form shows what was recorded; unticking withdraws it.
        await page.goto(`/admin/customers/${plainId}/edit`);
        await expect(page.getByRole('checkbox', { name: CONSENT })).toBeChecked();
        await expect(page.getByText(/Tercatat: Setuju sejak/)).toBeVisible();
        await page.getByRole('checkbox', { name: CONSENT }).uncheck();
        expect(await saveCustomer(page)).toHaveProperty('whatsapp_opt_in', false);
        await page.goto(`/admin/customers/${plainId}`);
        await expect(whatsappCard(page)).toContainText('Berhenti sejak');

        // Ticking again after a withdrawal records a new consent.
        await page.goto(`/admin/customers/${plainId}/edit`);
        await expect(page.getByText(/Tercatat: Berhenti sejak/)).toBeVisible();
        await page.getByRole('checkbox', { name: CONSENT }).check();
        expect(await saveCustomer(page)).toHaveProperty('whatsapp_opt_in', true);
        await page.goto(`/admin/customers/${plainId}`);
        await expect(whatsappCard(page)).toContainText('Setuju sejak');

        // Leave the shared fixture without consent again, and with its notes as they were.
        await page.goto(`/admin/customers/${plainId}/edit`);
        await page.getByRole('checkbox', { name: CONSENT }).uncheck();
        await page.getByLabel('Catatan', { exact: true }).fill('');
        await saveCustomer(page);
    });

    test('a read-only edit page shows the consent checkbox disabled and offers no Save', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        // Whoever may open the edit page may also update (the server answers 403 to the rest), so the
        // read-only state is shown by serving the same page with `can.update` false.
        await page.route(/\/admin\/customers\/[0-9A-HJKMNP-TV-Z]{26}\/edit$/i, async (route) => {
            const response = await route.fetch();
            const body = (await response.text())
                .replaceAll('&quot;update&quot;:true', '&quot;update&quot;:false')
                .replaceAll('"update":true', '"update":false');
            return route.fulfill({
                response,
                body,
                headers: {
                    ...response.headers(),
                    'content-length': String(Buffer.byteLength(body)),
                },
            });
        });
        await page.goto(`/admin/customers/${plainId}/edit`);
        await expect(mainOf(page).getByText(/Tampilan baca saja/)).toBeVisible();
        await expect(page.getByRole('checkbox', { name: CONSENT })).toBeDisabled();
        await expect(page.getByRole('button', { name: 'Simpan pelanggan' })).toHaveCount(0);
        await page.unroute(/\/admin\/customers\/[0-9A-HJKMNP-TV-Z]{26}\/edit$/i);
    });

    test('consent is recorded when a customer is created', async ({ page }) => {
        test.setTimeout(120_000);
        const suffix = unique('wa').toLowerCase();
        const name = `E2E Consent Customer ${suffix}`;

        await loginAs(page, 'admin');
        await page.goto('/admin/customers/create');
        await expect(mainOf(page).getByRole('heading', { name: 'Buat Pelanggan' })).toBeVisible();
        const consent = page.getByRole('checkbox', { name: CONSENT });
        await expect(consent).not.toBeChecked();

        await page.getByLabel('Nama').fill(name);
        await page.getByLabel('Email').fill(`${suffix}@example.test`);
        await page.getByLabel('Telepon').fill('081234560002');
        await consent.check();

        await page.getByRole('tab', { name: /Alamat \(\d+\)/ }).click();
        await page.getByLabel('Label').fill(`E2E Install ${suffix}`);
        await page.getByLabel('Alamat *', { exact: true }).fill('9 E2E Consent Street');
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

        await page.getByRole('tab', { name: /Kontak \(\d+\)/ }).click();
        await page.getByLabel('Nama').fill(`${name} Contact`);
        await page.getByLabel('Telepon').fill('081234560003');
        await page.getByLabel('Email').fill(`contact.${suffix}@example.test`);

        await page.getByRole('tab', { name: 'Langganan & SPK' }).click();
        await selectFirstSearchOption(page, 'Paket layanan');
        await selectSearchOption(page, 'POP pelayan', 'POP Utama');

        await page.getByRole('tab', { name: 'Data pelanggan' }).click();
        await expect(page.getByRole('checkbox', { name: CONSENT })).toBeChecked();
        await page.getByRole('button', { name: 'Buat pelanggan dan SPK' }).click();

        await expect(page).toHaveURL(/\/admin\/customers\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        await expect(mainOf(page).getByRole('heading', { name })).toBeVisible();
        await expect(whatsappCard(page)).toContainText('Setuju sejak');
    });

    test('the company admin sees the credentials banner, saves the WhatsApp settings and finds them after a reload', async ({
        page,
    }) => {
        test.setTimeout(90_000);
        await loginAs(page, 'admin');
        await page.goto('/admin/company/settings');
        await expect(
            mainOf(page).getByRole('heading', { name: 'Pengaturan Perusahaan' }),
        ).toBeVisible();

        const section = page
            .getByRole('heading', { name: 'WhatsApp', exact: true })
            .locator('xpath=../..');
        await expect(section).toContainText('WhatsApp belum dikonfigurasi untuk perusahaan ini.');
        await expect(
            section.getByRole('link', { name: 'Atur di Pengaturan → Integrasi' }),
        ).toBeVisible();
        await expect(section).toContainText('Kredensial terkonfigurasi: Tidak');
        // No credential value ever reaches the page.
        await expect(section.locator('input[type="password"]')).toHaveCount(0);

        // The WhatsApp keys have their own section; the generic list keeps the rest.
        const enabled = page.getByRole('switch', { name: 'Aktifkan notifikasi WhatsApp' });
        await expect(enabled).toHaveCount(1);
        const dueDays = await page
            .getByLabel('Jatuh tempo tagihan (hari)', { exact: true })
            .inputValue();

        // Defaults: off, every message type on, quiet 21:00-07:00, cap 1000.
        await expect(enabled).toHaveAttribute('aria-checked', 'false');
        const events = section.getByRole('group', { name: 'Jenis pesan yang dikirim' });
        await expect(events.getByRole('checkbox')).toHaveCount(9);
        await expect(events.getByRole('checkbox', { checked: true })).toHaveCount(9);
        await expect(page.getByLabel('Jam tenang mulai', { exact: true })).toHaveValue('21:00');
        await expect(page.getByLabel('Jam tenang selesai', { exact: true })).toHaveValue('07:00');
        await expect(page.getByLabel('Batas pesan per bulan')).toHaveValue('1000');

        // This month's usage comes from the fixture rows: two sent, one of them delivered.
        const usage = section.getByRole('region', { name: 'Pemakaian bulan ini' });
        for (const label of ['Terkirim', 'Diterima', 'Gagal', 'Berbayar']) {
            await expect(usage.getByText(label, { exact: true })).toBeVisible();
        }
        await expect(
            usage.locator('dt', { hasText: 'Terkirim' }).locator('xpath=following-sibling::dd'),
        ).toHaveText('2');
        await expect(
            usage.locator('dt', { hasText: 'Diterima' }).locator('xpath=following-sibling::dd'),
        ).toHaveText('1');

        await enabled.click();
        await expect(enabled).toHaveAttribute('aria-checked', 'true');
        await events.getByRole('checkbox', { name: /Pengingat jatuh tempo/ }).uncheck();
        await page.getByLabel('Jam tenang mulai', { exact: true }).fill('20:30');
        await page.getByLabel('Batas pesan per bulan').fill('2500');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page.getByText('Pengaturan perusahaan diperbarui.')).toBeVisible();

        await page.reload();
        await expect(
            page.getByRole('switch', { name: 'Aktifkan notifikasi WhatsApp' }),
        ).toHaveAttribute('aria-checked', 'true');
        const reloaded = page.getByRole('group', { name: 'Jenis pesan yang dikirim' });
        await expect(reloaded.getByRole('checkbox', { checked: true })).toHaveCount(8);
        await expect(
            reloaded.getByRole('checkbox', { name: /Pengingat jatuh tempo/ }),
        ).not.toBeChecked();
        await expect(page.getByLabel('Jam tenang mulai', { exact: true })).toHaveValue('20:30');
        await expect(page.getByLabel('Batas pesan per bulan')).toHaveValue('2500');
        // Saving the section did not touch the other settings.
        await expect(page.getByLabel('Jatuh tempo tagihan (hari)', { exact: true })).toHaveValue(
            dueDays,
        );

        // An emptied cap is a validation error, never a silent 0 (which would send nothing).
        await page.getByLabel('Batas pesan per bulan').fill('');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page.getByText(/batas WhatsApp bulanan wajib diisi/i)).toBeVisible();
        await expect(page.getByLabel('Batas pesan per bulan')).toHaveValue('');
        await page.reload();
        await expect(page.getByLabel('Batas pesan per bulan')).toHaveValue('2500');

        // Restore the defaults for the specs that follow.
        await page.getByRole('switch', { name: 'Aktifkan notifikasi WhatsApp' }).click();
        await page
            .getByRole('group', { name: 'Jenis pesan yang dikirim' })
            .getByRole('checkbox', { name: /Pengingat jatuh tempo/ })
            .check();
        await page.getByLabel('Jam tenang mulai', { exact: true }).fill('21:00');
        await page.getByLabel('Batas pesan per bulan').fill('1000');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page.getByText('Pengaturan perusahaan diperbarui.')).toBeVisible();
    });

    test('a read-only view disables every WhatsApp control and hides Save, and the server refuses a manager', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        // The seeded roles that may open the page can also change it, so the read-only state is shown
        // by serving the same page with `can.update` false. The server stays authoritative.
        await page.route('**/admin/company/settings', async (route) => {
            const response = await route.fetch();
            const body = (await response.text())
                .replaceAll('&quot;update&quot;:true', '&quot;update&quot;:false')
                .replaceAll('"update":true', '"update":false');
            return route.fulfill({
                response,
                body,
                headers: {
                    ...response.headers(),
                    'content-length': String(Buffer.byteLength(body)),
                },
            });
        });
        await page.goto('/admin/company/settings');
        await expect(page.getByRole('heading', { name: 'WhatsApp', exact: true })).toBeVisible();

        await expect(
            page.getByRole('switch', { name: 'Aktifkan notifikasi WhatsApp' }),
        ).toBeDisabled();
        const events = page
            .getByRole('group', { name: 'Jenis pesan yang dikirim' })
            .getByRole('checkbox');
        await expect(events).toHaveCount(9);
        for (const checkbox of await events.all()) {
            await expect(checkbox).toBeDisabled();
        }
        await expect(page.getByLabel('Jam tenang mulai', { exact: true })).toBeDisabled();
        await expect(page.getByLabel('Jam tenang selesai', { exact: true })).toBeDisabled();
        await expect(page.getByLabel('Batas pesan per bulan')).toBeDisabled();
        await expect(page.getByLabel('Batas pesan per bulan')).toHaveValue('1000');
        await expect(page.getByRole('button', { name: 'Simpan', exact: true })).toHaveCount(0);
        await page.unroute('**/admin/company/settings');

        await logout(page);
        await loginAs(page, 'manager');
        expect((await page.request.get('/admin/company/settings')).status()).toBe(403);
    });

    test('the WhatsApp card, the consent checkbox and the settings hold at 375 and 1280 px in both themes', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await loginAs(page, 'admin');
        const paths = [
            `/admin/customers/${waId}`,
            `/admin/customers/${plainId}/edit`,
            '/admin/company/settings',
        ];
        for (const width of [375, 1280]) {
            for (const dark of [false, true]) {
                for (const path of paths) {
                    await page.goto(path);
                    await setTheme(page, width, dark);
                    await expect(mainOf(page).getByRole('heading').first()).toBeVisible();
                    await expectNoHorizontalOverflow(
                        page,
                        `${path} at ${width}px ${dark ? 'dark' : 'light'}`,
                    );
                }
            }
        }
        await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
    });
});
