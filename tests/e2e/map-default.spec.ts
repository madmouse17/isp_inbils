import type { Locator, Page } from '@playwright/test';

import { expect, test } from './support/fixtures';
import { inertiaProps, loginAs, selectSearchOption } from './support/demo';

const SAVED = 'Pengaturan perusahaan diperbarui.';

type MapSettings = {
    map_city_code?: string | null;
    map_lat?: number | string | null;
    map_lng?: number | string | null;
    map_zoom?: number | string | null;
};

type City = { code: string; name: string };

async function centerOf(map: Locator): Promise<[number, number]> {
    const [lat, lng] = ((await map.getAttribute('data-center')) ?? 'NaN,NaN')
        .split(',')
        .map(Number);

    return [lat, lng];
}

async function save(page: Page) {
    await page.getByRole('button', { name: 'Simpan', exact: true }).click();
    await expect(page.getByText(SAVED)).toBeVisible();
}

async function expectCenterNear(map: Locator, lat: number, lng: number) {
    await expect
        .poll(async () => {
            const [mapLat, mapLng] = await centerOf(map);

            return Math.abs(mapLat - lat) < 0.01 && Math.abs(mapLng - lng) < 0.01;
        })
        .toBe(true);
}

test('@map-default a company default region moves every map, and a saved point still wins', async ({
    page,
}) => {
    test.setTimeout(120_000);
    await loginAs(page, 'admin');

    const props = (await inertiaProps(page, '/admin/company/settings')).props ?? {};
    const previous = (props.settings ?? {}) as MapSettings;
    const cities = (props.cities ?? []) as City[];
    const previousCity = cities.find((city) => city.code === previous.map_city_code);

    const settingsPreview = page.getByLabel('Peta untuk memilih lintang dan bujur');
    const latitude = page.getByLabel('Lintang', { exact: true });
    const longitude = page.getByLabel('Bujur', { exact: true });
    const zoom = page.getByLabel('Zoom', { exact: true });
    // By role: the clear button of a chosen field is also labelled "Hapus pilihan: Wilayah default".
    const city = page.getByRole('combobox', { name: 'Wilayah default' });

    try {
        await page.goto('/admin/company/settings');
        await expect(page.getByRole('heading', { name: 'Peta', exact: true })).toBeVisible();
        // The map keys have their own card; the generic list does not repeat them.
        await expect(latitude).toHaveCount(1);

        await test.step('picking a region fills the point and zoom and the preview follows', async () => {
            await selectSearchOption(page, 'Wilayah default', 'KOTA SURAKARTA');
            await expect(city).toHaveValue(/KOTA SURAKARTA/i);
            await expect(zoom).toHaveValue('12');
            const pickedLat = Number(await latitude.inputValue());
            const pickedLng = Number(await longitude.inputValue());
            expect(pickedLat).toBeGreaterThan(-8);
            expect(pickedLat).toBeLessThan(-7);
            expect(pickedLng).toBeGreaterThan(110);
            expect(pickedLng).toBeLessThan(111.5);
            await expectCenterNear(settingsPreview, pickedLat, pickedLng);
        });

        await test.step('a hand-typed point drops the region label and moves the preview', async () => {
            await latitude.fill('-6.2726');
            await longitude.fill('106.81');
            await expect(city).toHaveValue('');
            await expectCenterNear(settingsPreview, -6.2726, 106.81);
        });

        await test.step('an out-of-range point is refused with the field error', async () => {
            await latitude.fill('40');
            await page.getByRole('button', { name: 'Simpan', exact: true }).click();
            await expect(page.getByText('Lintang harus di antara -11 dan 6.')).toBeVisible();
            await expect(page.getByText(SAVED)).toHaveCount(0);
        });

        await test.step('the saved region opens the customer address map', async () => {
            await selectSearchOption(page, 'Wilayah default', 'KOTA SURAKARTA');
            const savedLat = Number(await latitude.inputValue());
            const savedLng = Number(await longitude.inputValue());
            await save(page);

            await page.reload();
            await expect(city).toHaveValue(/KOTA SURAKARTA/i);
            await expect(zoom).toHaveValue('12');

            await page.goto('/admin/customers/create');
            await page.getByRole('tab', { name: /Alamat \(\d+\)/ }).click();
            const picker = page.getByLabel('Peta untuk memilih koordinat alamat').first();
            await expect(picker).toBeVisible();
            await expectCenterNear(picker, savedLat, savedLng);

            // A point on the address wins over the company default.
            await picker.click({ position: { x: 120, y: 300 } });
            const pointLat = Number(
                await page.getByRole('textbox', { name: /^Lintang/ }).inputValue(),
            );
            const pointLng = Number(
                await page.getByRole('textbox', { name: /^Bujur/ }).inputValue(),
            );
            expect(Number.isFinite(pointLat)).toBe(true);
            await expect
                .poll(async () => {
                    const [mapLat, mapLng] = await centerOf(picker);

                    return (
                        Math.abs(mapLat - pointLat) < 0.0005 && Math.abs(mapLng - pointLng) < 0.0005
                    );
                })
                .toBe(true);
        });
    } finally {
        // Put the company back as it was: its own region, its own point, or no map keys at all.
        await page.goto('/admin/company/settings');
        if (previousCity) {
            await selectSearchOption(page, 'Wilayah default', previousCity.name);
        } else {
            await latitude.fill(previous.map_lat == null ? '' : String(previous.map_lat));
            await longitude.fill(previous.map_lng == null ? '' : String(previous.map_lng));
        }
        await zoom.fill(previous.map_zoom == null ? '' : String(previous.map_zoom));
        await save(page);
    }
});
