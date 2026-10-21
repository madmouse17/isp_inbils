import { expect, test } from './support/fixtures';
import { inertiaProps, loginAs } from './support/demo';

/**
 * Pengaturan Perusahaan and the account prompt of the admin shell (F1-U03, F1-U05, F1-U06, K24).
 *
 * None of these tests saves a setting that works. The first successful save of the form stamps the billing marker
 * that the dashboard checklist reads, and every spec after this one shares the company, so a save here would change
 * what they start from. What is saved is only refused: the 2FA policy without a second factor of one's own.
 */

const UNSAVED = 'Ada perubahan yang belum disimpan';
const SETTINGS_URL = '/admin/company/settings';

test('@company-settings Simpan stays in view, unsaved changes are marked, and the section links jump', async ({
    page,
}) => {
    await loginAs(page, 'admin');
    await page.goto(SETTINGS_URL);
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Pengaturan Perusahaan', exact: true }),
    ).toBeVisible();

    const save = page.getByRole('button', { name: 'Simpan', exact: true });
    const unsaved = page.getByText(UNSAVED);

    await test.step('Simpan is in view at the top of the long page, and after scrolling to the end', async () => {
        await expect(save).toBeInViewport();
        await page.getByRole('heading', { name: 'Keamanan', exact: true }).scrollIntoViewIfNeeded();
        await expect(save).toBeInViewport();
    });

    await test.step('a change is marked as unsaved, and undoing it clears the mark', async () => {
        const whatsapp = page.getByRole('switch', { name: 'Aktifkan notifikasi WhatsApp' });
        await expect(unsaved).toBeHidden();

        await whatsapp.click();
        await expect(unsaved).toBeVisible();
        await whatsapp.click();
        await expect(unsaved).toBeHidden();
    });

    await test.step('a section link brings its card into view and names it in the address', async () => {
        const nav = page.getByRole('navigation', { name: 'Bagian pengaturan' });
        // "Format tanggal" is listed only while the company has date formats stored, so it is not named here.
        for (const name of [
            'Tagihan',
            'Penangguhan otomatis',
            'Lapangan',
            'Peta',
            'Jam layanan',
            'WhatsApp',
            'Keamanan',
        ]) {
            await expect(nav.getByRole('link', { name, exact: true })).toBeVisible();
        }
        // No link points at a card that is not on the page.
        const targets = await nav
            .getByRole('link')
            .evaluateAll((links) => links.map((link) => link.getAttribute('href') ?? ''));
        for (const target of targets) {
            await expect(page.locator(target)).toHaveCount(1);
        }

        await page.getByRole('main').evaluate((main) => main.scrollTo({ top: 0 }));
        await nav.getByRole('link', { name: 'Peta', exact: true }).click();
        await expect(page).toHaveURL(/#peta$/);
        await expect(page.getByRole('heading', { name: 'Peta', exact: true })).toBeInViewport();
    });
});

test('@company-settings on a phone the section links are chips of at least 44 px and Simpan stays in view', async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await loginAs(page, 'admin');
    await page.goto(SETTINGS_URL);

    const chip = page
        .getByRole('navigation', { name: 'Bagian pengaturan' })
        .getByRole('link', { name: 'WhatsApp', exact: true });
    await expect(chip).toBeVisible();
    expect((await chip.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44);
    await expect(page.getByRole('button', { name: 'Simpan', exact: true })).toBeInViewport();

    await chip.click();
    await expect(page).toHaveURL(/#whatsapp$/);
    await expect(page.getByRole('heading', { name: 'WhatsApp', exact: true })).toBeInViewport();
    expect(
        await page.getByRole('main').evaluate((main) => main.scrollWidth <= main.clientWidth),
        'the page does not scroll sideways',
    ).toBe(true);
});

test('@company-settings "Pakai saran" fills the billing defaults in the form and leaves saving to the admin', async ({
    page,
}) => {
    await loginAs(page, 'admin');
    const stored = (await inertiaProps(page, SETTINGS_URL)).props?.settings ?? {};
    test.skip(
        Boolean(stored.billing_defaults_confirmed_at),
        'A save of this company already stamped the billing marker, so the callout has gone for good: reset the E2E database.',
    );

    await page.goto(SETTINGS_URL);
    const callout = page.getByRole('status').filter({ hasText: 'Pilih cara penagihan otomatis' });
    const autoSend = page.getByRole('switch', { name: 'Kirim tagihan pemasangan otomatis' });
    const autoSuspend = page.getByRole('switch', { name: 'Tangguhkan langganan yang menunggak' });
    const days = page.getByLabel(/^Batas keterlambatan/);

    await expect(callout).toContainText(
        'Saran kami: kirim tagihan pemasangan otomatis, dan isolir langganan 7 hari setelah jatuh tempo.',
    );
    await callout.getByRole('button', { name: 'Pakai saran' }).click();

    await expect(autoSend).toBeChecked();
    await expect(autoSuspend).toBeChecked();
    await expect(days).toHaveValue('7');
    await expect(callout).toContainText('Tekan Simpan untuk menyimpannya.');
    await expect(callout.getByRole('button', { name: 'Pakai saran' })).toBeDisabled();

    // Nothing was sent: a reload shows what is stored.
    await page.reload();
    await expect(autoSend).toHaveAttribute(
        'aria-checked',
        String(stored.auto_send_installation_invoice === true),
    );
    await expect(autoSuspend).toHaveAttribute(
        'aria-checked',
        String(stored.auto_suspend_enabled === true),
    );
});

test('@company-settings turning on "Wajib 2FA untuk admin" without a second factor of your own is refused on that field', async ({
    page,
}) => {
    await loginAs(page, 'admin');
    await page.goto(SETTINGS_URL);

    const policy = page.getByRole('switch', { name: 'Wajib 2FA untuk admin' });
    await expect(policy).toHaveAttribute('aria-checked', 'false');
    await policy.click();
    await page.getByRole('button', { name: 'Simpan', exact: true }).click();

    await expect(
        page.getByText('Aktifkan 2FA di profil Anda dulu sebelum mewajibkannya.'),
    ).toBeVisible();
    await expect(page.getByText('Pengaturan perusahaan diperbarui.')).toHaveCount(0);

    await page.reload();
    await expect(page.getByRole('switch', { name: 'Wajib 2FA untuk admin' })).toHaveAttribute(
        'aria-checked',
        'false',
    );
});

test('@company-settings an admin without 2FA is asked to turn it on, can say later, and is not asked on the Profile page', async ({
    page,
}) => {
    await loginAs(page, 'admin');
    const prompt = page.getByRole('region', { name: 'Keamanan akun' });

    await expect(prompt).toBeVisible();
    await expect(prompt).toContainText(
        'Lindungi akun admin dengan 2FA. Satu kata sandi yang bocor cukup untuk membuka data semua pelanggan.',
    );
    await expect(prompt.getByRole('link', { name: 'Aktifkan 2FA' })).toHaveAttribute(
        'href',
        /\/profile$/,
    );

    await test.step('"Nanti saja" hides it for a week, in this browser, for this user', async () => {
        await prompt.getByRole('button', { name: 'Nanti saja' }).click();
        await expect(prompt).toBeHidden();

        const snoozed = await page.evaluate(() =>
            Object.entries(window.localStorage).find(([key]) =>
                key.startsWith('inbils.2faPromptSnoozedUntil.'),
            ),
        );
        expect(snoozed, 'the choice is kept under the key of the user').toBeTruthy();
        expect(Number(snoozed?.[1])).toBeGreaterThan(Date.now() + 6 * 24 * 60 * 60 * 1000);

        await page.reload();
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Dasbor', exact: true }),
        ).toBeVisible();
        await expect(prompt).toBeHidden();
    });

    await test.step('with no postponing it is back, except on the page that holds the 2FA form', async () => {
        await page.evaluate(() => window.localStorage.clear());
        await page.goto('/profile');
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Profil', exact: true }),
        ).toBeVisible();
        await expect(prompt).toBeHidden();

        await page.goto('/admin/dashboard');
        await expect(prompt).toBeVisible();
        await prompt.getByRole('link', { name: 'Aktifkan 2FA' }).click();
        await expect(page).toHaveURL(/\/profile$/);
    });
});

test('@company-settings a user who administers nothing is never asked to turn on 2FA', async ({
    page,
}) => {
    await loginAs(page, 'staff');

    await expect(page.getByRole('region', { name: 'Keamanan akun' })).toHaveCount(0);
});

test('@company-settings the check-in limit and the service hours are on the page, and a bad value is refused on its own field', async ({
    page,
}) => {
    await loginAs(page, 'admin');
    const props = (await inertiaProps(page, SETTINGS_URL)).props ?? {};
    const hoursSet = Object.values(props.serviceHours ?? {}).some(Boolean);

    await page.goto(SETTINGS_URL);
    const limit = page.getByLabel('Jarak check-in maksimal (meter)');
    const monday = page.getByRole('group', { name: 'Senin' });
    // Exact: the clear and open buttons of a time field are named "Hapus jam: Dari" and "Buka daftar jam: Dari", so a substring match finds three elements.
    const opens = monday.getByLabel('Dari', { exact: true });
    const closes = monday.getByLabel('Sampai', { exact: true });

    await test.step('the limit shows what is stored, 500 until one is saved', async () => {
        await expect(limit).toHaveValue(String(props.settings?.checkin_max_distance_m ?? 500));
    });

    await test.step('every day is listed, a closed one hides its times, and the warning names the empty week', async () => {
        for (const day of ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu']) {
            await expect(page.getByRole('group', { name: day })).toBeVisible();
        }
        await expect(
            page.getByText('Jam layanan belum diisi; tenggat dihitung 24 jam.'),
        ).toHaveCount(hoursSet ? 0 : 1);

        const closed = monday.getByRole('checkbox', { name: 'Tutup' });
        if (await closed.isChecked()) {
            await expect(opens).toHaveCount(0);
            await closed.click();
        }
        await expect(opens).toHaveValue('08:00');
        await expect(closes).toHaveValue('17:00');
    });

    await test.step('a limit under 50 metres and a day that closes before it opens are refused, and nothing is saved', async () => {
        await limit.fill('10');
        await closes.fill('07:00');
        await closes.press('Tab');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();

        await expect(page.getByText('Jarak check-in paling dekat 50 meter.')).toBeVisible();
        await expect(page.getByText('Jam tutup harus setelah jam buka.')).toBeVisible();
        await expect(page.getByText('Pengaturan perusahaan diperbarui.')).toHaveCount(0);

        await page.reload();
        await expect(limit).toHaveValue(String(props.settings?.checkin_max_distance_m ?? 500));
    });
});
