import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { loginAs } from './support/demo';
import { companyDateFormat, formatIsoAs } from './support/dateRange';

/*
 * The new pickers (.scratch/ui-consistency, ticket 02): DatePicker, DateTimePicker, MonthPicker,
 * TimeInput, SearchSelect, RemoteSearchSelect and EnumBadge, on the component gallery. The gallery
 * route is open in the e2e environment and sends the optional `demoOptions` prop (field `demo`)
 * that the remote select searches.
 */

const GALLERY = '/admin/components';

const MONTH_NAMES = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

async function openGallery(page: Page) {
    await loginAs(page, 'admin');
    await page.goto(GALLERY);
    await expect(page.locator('#dates')).toBeVisible();
}

/** Today as ISO on the company clock, which is what the pickers mark as today. */
async function companyToday(page: Page): Promise<string> {
    const timezone = await page.evaluate(() => {
        const raw = document.getElementById('app')?.getAttribute('data-page');
        const props = raw ? JSON.parse(raw).props : {};
        return (props?.company?.timezone as string | undefined) ?? 'Asia/Jakarta';
    });

    return new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date());
}

/** What the gallery shows under a field: the value the form would send. */
function sent(page: Page, label: string): Locator {
    return page.getByRole('status', { name: `Nilai ${label}` });
}

/** Type into a picker and commit with Tab, the way a person leaves the field. */
async function typeInto(field: Locator, text: string) {
    await field.fill(text);
    await field.press('Tab');
}

async function horizontalOverflow(page: Page) {
    return page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
}

test.describe('@ui-pickers DatePicker', () => {
    test('a typed day in the company format is sent as ISO and written back in that format', async ({
        page,
    }) => {
        await openGallery(page);
        const field = page.getByLabel('Tanggal terbit', { exact: true });
        const format = await companyDateFormat(page);

        await typeInto(field, formatIsoAs('2026-09-10', format));

        await expect(sent(page, 'Tanggal terbit')).toHaveText('Nilai terkirim: 2026-09-10');
        await expect(field).toHaveValue(formatIsoAs('2026-09-10', format));
        await expect(field).not.toHaveAttribute('aria-invalid', 'true');
    });

    test('Enter commits the typed day', async ({ page }) => {
        await openGallery(page);
        const field = page.getByLabel('Tanggal terbit', { exact: true });
        const format = await companyDateFormat(page);

        await field.fill(formatIsoAs('2027-01-05', format));
        await field.press('Enter');

        await expect(sent(page, 'Tanggal terbit')).toHaveText('Nilai terkirim: 2027-01-05');
    });

    test('a text that is not a day is flagged and never sent', async ({ page }) => {
        await openGallery(page);
        const field = page.getByLabel('Tanggal terbit', { exact: true });
        const format = await companyDateFormat(page);

        await typeInto(field, formatIsoAs('2026-09-10', format));
        await typeInto(field, 'bukan tanggal');

        await expect(field).toHaveAttribute('aria-invalid', 'true');
        await expect(
            page.getByRole('alert').filter({ hasText: 'Format tanggal tidak valid.' }),
        ).toBeVisible();
        await expect(sent(page, 'Tanggal terbit')).toHaveText('Nilai terkirim: 2026-09-10');

        // Typing again clears the flag; blank empties the date.
        await typeInto(field, '');
        await expect(field).not.toHaveAttribute('aria-invalid', 'true');
        await expect(sent(page, 'Tanggal terbit')).toHaveText('Nilai terkirim: -');
    });

    test('a day picked on the calendar is sent and the popover closes', async ({ page }) => {
        await openGallery(page);
        const month = (await companyToday(page)).slice(0, 7);

        await page.getByRole('button', { name: 'Buka kalender: Tanggal terbit' }).click();
        const calendar = page.getByRole('dialog', { name: 'Pilih tanggal: Tanggal terbit' });
        await expect(calendar).toBeVisible();
        await calendar.locator(`[data-day="${month}-15"] button`).first().click();

        await expect(calendar).toBeHidden();
        await expect(sent(page, 'Tanggal terbit')).toHaveText(`Nilai terkirim: ${month}-15`);
        const format = await companyDateFormat(page);
        await expect(page.getByLabel('Tanggal terbit', { exact: true })).toHaveValue(
            formatIsoAs(`${month}-15`, format),
        );
    });

    test('"Hari ini" picks today on the company clock', async ({ page }) => {
        await openGallery(page);

        await page.getByRole('button', { name: 'Buka kalender: Tanggal terbit' }).click();
        await page
            .getByRole('dialog', { name: 'Pilih tanggal: Tanggal terbit' })
            .getByRole('button', { name: 'Hari ini', exact: true })
            .click();

        await expect(sent(page, 'Tanggal terbit')).toHaveText(
            `Nilai terkirim: ${await companyToday(page)}`,
        );
    });

    test('keyboard: Enter opens the calendar, Escape closes it and returns focus', async ({
        page,
    }) => {
        await openGallery(page);
        const trigger = page.getByRole('button', { name: 'Buka kalender: Tanggal terbit' });

        await trigger.focus();
        await page.keyboard.press('Enter');
        const calendar = page.getByRole('dialog', { name: 'Pilih tanggal: Tanggal terbit' });
        await expect(calendar).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(calendar).toBeHidden();
        await expect(trigger).toBeFocused();
    });

    test('the clear button empties the date', async ({ page }) => {
        await openGallery(page);
        const field = page.getByLabel('Tanggal terbit', { exact: true });
        const format = await companyDateFormat(page);

        await typeInto(field, formatIsoAs('2026-09-10', format));
        await page.getByRole('button', { name: 'Hapus tanggal: Tanggal terbit' }).click();

        await expect(field).toHaveValue('');
        await expect(sent(page, 'Tanggal terbit')).toHaveText('Nilai terkirim: -');
        await expect(
            page.getByRole('button', { name: 'Hapus tanggal: Tanggal terbit' }),
        ).toHaveCount(0);
    });

    test('a server error shows under the field and a disabled field cannot be opened', async ({
        page,
    }) => {
        await openGallery(page);

        const withError = page.getByRole('textbox', { name: 'Dengan galat', exact: true });
        await expect(withError).toHaveAttribute('aria-invalid', 'true');
        await expect(page.getByText('Tanggal wajib diisi.')).toBeVisible();

        await expect(page.getByRole('textbox', { name: 'Nonaktif', exact: true })).toBeDisabled();
        await expect(page.getByRole('button', { name: 'Buka kalender: Nonaktif' })).toBeDisabled();
    });
});

test.describe('@ui-pickers DateTimePicker', () => {
    test('a typed day and time is sent as Y-m-d H:i', async ({ page }) => {
        await openGallery(page);
        const field = page.getByLabel('Jadwal kunjungan', { exact: true });
        const format = await companyDateFormat(page);

        await typeInto(field, `${formatIsoAs('2026-09-10', format)} 8:30`);

        await expect(sent(page, 'Jadwal kunjungan')).toHaveText('Nilai terkirim: 2026-09-10 08:30');
        await expect(field).toHaveValue(`${formatIsoAs('2026-09-10', format)} 08:30`);
    });

    test('a day without its time is flagged', async ({ page }) => {
        await openGallery(page);
        const field = page.getByLabel('Jadwal kunjungan', { exact: true });
        const format = await companyDateFormat(page);

        await typeInto(field, formatIsoAs('2026-09-10', format));

        await expect(field).toHaveAttribute('aria-invalid', 'true');
        await expect(
            page.getByRole('alert').filter({ hasText: 'Format tanggal dan jam tidak valid.' }),
        ).toBeVisible();
        await expect(sent(page, 'Jadwal kunjungan')).toHaveText('Nilai terkirim: -');
    });

    test('a day starts at 00:00 and a time slot picks the hour and closes the popover', async ({
        page,
    }) => {
        await openGallery(page);
        const month = (await companyToday(page)).slice(0, 7);

        await page.getByRole('button', { name: 'Buka kalender: Jadwal kunjungan' }).click();
        const popover = page.getByRole('dialog', {
            name: 'Pilih tanggal dan jam: Jadwal kunjungan',
        });
        await popover.locator(`[data-day="${month}-15"] button`).first().click();

        await expect(popover).toBeVisible();
        await expect(sent(page, 'Jadwal kunjungan')).toHaveText(
            `Nilai terkirim: ${month}-15 00:00`,
        );

        await popover.getByRole('button', { name: '09:30', exact: true }).click();

        await expect(popover).toBeHidden();
        await expect(sent(page, 'Jadwal kunjungan')).toHaveText(
            `Nilai terkirim: ${month}-15 09:30`,
        );
    });

    test('"Selesai" closes the popover', async ({ page }) => {
        await openGallery(page);

        await page.getByRole('button', { name: 'Buka kalender: Jadwal kunjungan' }).click();
        const popover = page.getByRole('dialog', {
            name: 'Pilih tanggal dan jam: Jadwal kunjungan',
        });
        await popover.getByRole('button', { name: 'Selesai' }).click();

        await expect(popover).toBeHidden();
    });

    test('on a phone the popover fits the screen and the controls are 44px', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 800 });
        await openGallery(page);

        const field = page.getByLabel('Jadwal kunjungan', { exact: true });
        const trigger = page.getByRole('button', { name: 'Buka kalender: Jadwal kunjungan' });
        expect((await field.boundingBox())?.height).toBeGreaterThanOrEqual(44);
        expect((await trigger.boundingBox())?.height).toBeGreaterThanOrEqual(44);
        expect((await trigger.boundingBox())?.width).toBeGreaterThanOrEqual(44);

        await trigger.click();
        const popover = page.getByRole('dialog', {
            name: 'Pilih tanggal dan jam: Jadwal kunjungan',
        });
        await expect(popover).toBeVisible();
        const box = await popover.boundingBox();
        expect(box?.x).toBeGreaterThanOrEqual(0);
        expect((box?.x ?? 0) + (box?.width ?? 0)).toBeLessThanOrEqual(375);
        expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    });
});

test.describe('@ui-pickers MonthPicker', () => {
    test('a typed month is sent as Y-m, however it is written', async ({ page }) => {
        await openGallery(page);
        const field = page.getByLabel('Periode', { exact: true });

        await typeInto(field, 'Oktober 2026');
        await expect(sent(page, 'Periode')).toHaveText('Nilai terkirim: 2026-10');
        await expect(field).toHaveValue('Oktober 2026');

        await typeInto(field, '3/2027');
        await expect(sent(page, 'Periode')).toHaveText('Nilai terkirim: 2027-03');
        await expect(field).toHaveValue('Maret 2027');

        await typeInto(field, '2026-12');
        await expect(sent(page, 'Periode')).toHaveText('Nilai terkirim: 2026-12');

        await typeInto(field, 'bukan bulan');
        await expect(field).toHaveAttribute('aria-invalid', 'true');
        await expect(
            page.getByRole('alert').filter({ hasText: 'Format bulan tidak valid.' }),
        ).toBeVisible();
        await expect(sent(page, 'Periode')).toHaveText('Nilai terkirim: 2026-12');
    });

    test('the year buttons change the year and a month button picks the month', async ({
        page,
    }) => {
        await openGallery(page);
        const nextYear = Number((await companyToday(page)).slice(0, 4)) + 1;

        await page.getByRole('button', { name: 'Buka pilihan bulan: Periode' }).click();
        const popover = page.getByRole('dialog', { name: 'Pilih bulan: Periode' });
        await popover.getByRole('button', { name: 'Tahun berikutnya' }).click();
        await expect(popover.getByText(String(nextYear), { exact: true })).toBeVisible();
        await popover.getByRole('button', { name: `Maret ${nextYear}` }).click();

        await expect(popover).toBeHidden();
        await expect(sent(page, 'Periode')).toHaveText(`Nilai terkirim: ${nextYear}-03`);
        await expect(page.getByLabel('Periode', { exact: true })).toHaveValue(`Maret ${nextYear}`);
    });

    test('keyboard: the popover opens on this month and Home then Enter picks January', async ({
        page,
    }) => {
        await openGallery(page);
        const today = await companyToday(page);
        const year = today.slice(0, 4);
        const thisMonth = MONTH_NAMES[Number(today.slice(5, 7)) - 1];

        const trigger = page.getByRole('button', { name: 'Buka pilihan bulan: Periode' });
        await trigger.focus();
        await page.keyboard.press('Enter');
        const popover = page.getByRole('dialog', { name: 'Pilih bulan: Periode' });
        await expect(popover.getByRole('button', { name: `${thisMonth} ${year}` })).toBeFocused();

        await page.keyboard.press('Home');
        await expect(popover.getByRole('button', { name: `Januari ${year}` })).toBeFocused();
        await page.keyboard.press('Enter');

        await expect(popover).toBeHidden();
        await expect(sent(page, 'Periode')).toHaveText(`Nilai terkirim: ${year}-01`);
    });
});

test.describe('@ui-pickers TimeInput', () => {
    test('a typed time is sent as HH:mm and a wrong one is flagged', async ({ page }) => {
        await openGallery(page);
        const field = page.getByLabel('Jam tenang mulai', { exact: true });

        await typeInto(field, '8:30');
        await expect(sent(page, 'Jam tenang mulai')).toHaveText('Nilai terkirim: 08:30');
        await expect(field).toHaveValue('08:30');

        await typeInto(field, '25:00');
        await expect(field).toHaveAttribute('aria-invalid', 'true');
        await expect(
            page.getByRole('alert').filter({ hasText: 'Format jam tidak valid.' }),
        ).toBeVisible();
        await expect(sent(page, 'Jam tenang mulai')).toHaveText('Nilai terkirim: 08:30');
    });

    test('a slot picked from the list is sent and the popover closes', async ({ page }) => {
        await openGallery(page);

        await page.getByRole('button', { name: 'Buka daftar jam: Jam tenang mulai' }).click();
        const popover = page.getByRole('dialog', { name: 'Pilih jam: Jam tenang mulai' });
        await popover.getByRole('button', { name: '21:00', exact: true }).click();

        await expect(popover).toBeHidden();
        await expect(sent(page, 'Jam tenang mulai')).toHaveText('Nilai terkirim: 21:00');
        await expect(page.getByLabel('Jam tenang mulai', { exact: true })).toHaveValue('21:00');
    });

    test('keyboard: the list takes focus, arrows move by a slot and Enter picks', async ({
        page,
    }) => {
        await openGallery(page);

        await page.getByRole('button', { name: 'Buka daftar jam: Jam tenang mulai' }).focus();
        await page.keyboard.press('Enter');
        const popover = page.getByRole('dialog', { name: 'Pilih jam: Jam tenang mulai' });
        await expect(popover.getByRole('button', { name: '00:00', exact: true })).toBeFocused();

        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('ArrowDown');
        await expect(popover.getByRole('button', { name: '01:00', exact: true })).toBeFocused();
        await page.keyboard.press('Enter');

        await expect(popover).toBeHidden();
        await expect(sent(page, 'Jam tenang mulai')).toHaveText('Nilai terkirim: 01:00');
    });
});

test.describe('@ui-pickers SearchSelect', () => {
    test('typing filters the list and a choice is sent', async ({ page }) => {
        await openGallery(page);
        const field = page.getByRole('combobox', { name: 'Provinsi' });

        await field.fill('jawa t');
        await expect(page.getByRole('listbox').getByRole('option')).toHaveText([
            /Jawa Tengah/,
            /Jawa Timur/,
        ]);

        await page.getByRole('option', { name: /Jawa Timur/ }).click();
        await expect(sent(page, 'Provinsi')).toHaveText('Nilai terkirim: jatim');
        await expect(field).toHaveValue('Jawa Timur');
        await expect(page.getByRole('listbox')).toHaveCount(0);
    });

    test('typing never changes the chosen value; Escape puts the label back', async ({ page }) => {
        await openGallery(page);
        const field = page.getByRole('combobox', { name: 'Provinsi' });

        await field.click();
        await page.getByRole('option', { name: /Bali/ }).click();
        await expect(sent(page, 'Provinsi')).toHaveText('Nilai terkirim: bali');

        await field.fill('tengah');
        await expect(page.getByRole('listbox').getByRole('option')).toHaveText([/Jawa Tengah/]);
        await expect(sent(page, 'Provinsi')).toHaveText('Nilai terkirim: bali');

        await page.keyboard.press('Escape');
        await expect(page.getByRole('listbox')).toHaveCount(0);
        await expect(field).toHaveValue('Bali');
        await expect(sent(page, 'Provinsi')).toHaveText('Nilai terkirim: bali');
    });

    test('the clear button removes the choice', async ({ page }) => {
        await openGallery(page);
        const field = page.getByRole('combobox', { name: 'Provinsi' });

        await field.click();
        await page.getByRole('option', { name: /Riau/ }).click();
        await page.getByRole('button', { name: 'Hapus pilihan: Provinsi' }).click();

        await expect(field).toHaveValue('');
        await expect(sent(page, 'Provinsi')).toHaveText('Nilai terkirim: -');
    });

    test('keyboard: arrows move through the options and Enter chooses', async ({ page }) => {
        await openGallery(page);
        const field = page.getByRole('combobox', { name: 'Provinsi' });

        await field.fill('jawa t');
        await expect(field).toHaveAttribute('aria-expanded', 'true');
        await page.keyboard.press('ArrowDown');
        await expect(field).toHaveAttribute(
            'aria-activedescendant',
            (await page.getByRole('option', { name: /Jawa Timur/ }).getAttribute('id')) ?? '',
        );
        await page.keyboard.press('Enter');

        await expect(sent(page, 'Provinsi')).toHaveText('Nilai terkirim: jatim');
        await expect(field).toHaveValue('Jawa Timur');
    });

    test('a text with no match says so', async ({ page }) => {
        await openGallery(page);

        await page.getByRole('combobox', { name: 'Provinsi' }).fill('zzzz');

        await expect(page.getByText('Tidak ada hasil.')).toBeVisible();
    });
});

test.describe('@ui-pickers RemoteSearchSelect', () => {
    test('the server is asked only from two characters and answers with the options', async ({
        page,
    }) => {
        await openGallery(page);
        const field = page.getByRole('combobox', { name: 'Lokasi (cari di server)' });

        await field.fill('d');
        await expect(page.getByText('Ketik minimal 2 huruf untuk mencari.')).toBeVisible();
        expect(new URL(page.url()).searchParams.has('demo_search')).toBe(false);

        await field.fill('default');
        await expect(page.getByRole('option', { name: /Default POP/ })).toBeVisible();
        await expect
            .poll(() => new URL(page.url()).searchParams.get('demo_search'))
            .toBe('default');
        // The page keeps its place: the gallery state, such as another picker's value, survives.
        await expect(page.locator('#dates')).toBeVisible();
    });

    test('a choice is sent, and typing a new search keeps it until another is chosen', async ({
        page,
    }) => {
        await openGallery(page);
        const field = page.getByRole('combobox', { name: 'Lokasi (cari di server)' });
        const status = sent(page, 'Lokasi (cari di server)');

        await field.fill('default');
        await page.getByRole('option', { name: /Default POP/ }).click();
        await expect(status).toHaveText(/^Nilai terkirim: \d+$/);
        const chosen = await status.textContent();
        await expect(field).toHaveValue('Default POP');

        await field.fill('zzzz');
        await expect(page.getByText('Tidak ada lokasi yang cocok.')).toBeVisible();
        await expect(status).toHaveText(chosen ?? '');

        await page.keyboard.press('Escape');
        await expect(field).toHaveValue('Default POP');
        await expect(status).toHaveText(chosen ?? '');
    });
});

test.describe('@ui-pickers EnumBadge', () => {
    test('stored values show their Indonesian label, an unknown one shows as itself', async ({
        page,
    }) => {
        await openGallery(page);
        const statuses = page.locator('#statuses');

        for (const label of [
            'Terbuka',
            'Dikerjakan',
            'Diselesaikan',
            'Ditutup',
            'Draf',
            'Belum dibayar',
            'Sebagian',
            'Lunas',
            'Lewat jatuh tempo',
            'Dibatalkan',
            'Rendah',
            'Sedang',
            'Tinggi',
            'Mendesak',
            'Aktif',
            'Nonaktif',
        ]) {
            await expect(statuses.getByText(label, { exact: true }).first()).toBeVisible();
        }
        // A value the map does not list stays readable; no value is a dash.
        await expect(statuses.getByText('escalated', { exact: true })).toBeVisible();
        await expect(statuses.getByText('on_progress')).toHaveCount(0);
        await expect(statuses.getByText('urgent')).toHaveCount(0);
    });

    test('optionsOf feeds a filter with the labels in the map order', async ({ page }) => {
        await openGallery(page);

        await expect(
            page.locator('#statuses').getByLabel('Filter status tagihan').locator('option'),
        ).toHaveText([
            'Semua status',
            'Draf',
            'Belum dibayar',
            'Sebagian',
            'Lunas',
            'Lewat jatuh tempo',
            'Dibatalkan',
        ]);
    });
});
