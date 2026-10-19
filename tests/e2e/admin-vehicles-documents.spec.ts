import type { Page } from '@playwright/test';

import { loginAs } from './support/demo';
import { companyDateFormat, formatIsoAs } from './support/dateRange';
import { expect, test } from './support/fixtures';

/**
 * Regression for the Kendaraan and Dokumen screens (.scratch/ui-consistency, ticket 09): the edit
 * dialog of a vehicle shows the purchase date and notes the row holds, the list carries the
 * server-side page controls, a document type's code is fixed once it exists and the Aktif switch is
 * honoured on create. Each test deletes the record it made.
 */

const stamp = () => Date.now().toString(36).toUpperCase();

/** Finds the row through the page search so a long list cannot hide it on page two. */
async function findRow(page: Page, text: string) {
    await page.getByLabel('Cari', { exact: true }).fill(text);
    const row = page.getByRole('row').filter({ hasText: text });
    await expect(row).toBeVisible({ timeout: 15_000 });

    return row;
}

async function deleteRow(page: Page, text: string) {
    const row = await findRow(page, text);
    await row.getByRole('button', { name: 'Aksi lainnya' }).click();
    await page.getByRole('menuitem', { name: 'Hapus' }).click();
    // A record nobody uses is deleted for good, so the dialog confirms with "Hapus permanen"; the plain list dialog says "Hapus".
    await page
        .getByRole('alertdialog')
        .getByRole('button', { name: /^Hapus( permanen)?$/ })
        .click();
    await expect(row).toBeHidden({ timeout: 15_000 });
}

test('@admin-vehicles-documents a vehicle keeps its purchase date and notes in the edit dialog, and the list pages through the server', async ({
    page,
}) => {
    const plate = `QA-${stamp()}`;

    await loginAs(page, 'admin');
    await page.goto('/admin/vehicles');
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Kendaraan', level: 1 }),
    ).toBeVisible({ timeout: 15_000 });

    const format = await companyDateFormat(page);
    const purchased = formatIsoAs('2025-11-30', format);

    await page.getByRole('button', { name: 'Buat Kendaraan' }).first().click();
    const create = page.getByRole('dialog', { name: 'Buat Kendaraan' });
    await create.getByLabel('Nomor polisi').fill(plate);
    await create.getByLabel('Tanggal pembelian', { exact: true }).fill(purchased);
    await create.getByLabel('Tanggal pembelian', { exact: true }).press('Tab');
    await create.getByLabel('Catatan').fill('Dicatat oleh uji E2E');
    await create.getByRole('button', { name: 'Buat', exact: true }).click();
    await expect(create).toBeHidden({ timeout: 15_000 });

    const row = await findRow(page, plate);
    // The footer with the page-size control only shows once the list holds a row.
    await expect(
        page.getByLabel('Baris per halaman'),
        'the list has its page-size control',
    ).toBeVisible();
    await row.getByRole('button', { name: 'Ubah' }).click();
    const edit = page.getByRole('dialog', { name: 'Ubah Kendaraan' });
    await expect(edit.getByLabel('Nomor polisi')).toHaveValue(plate);
    await expect(
        edit.getByLabel('Tanggal pembelian', { exact: true }),
        'the edit dialog preloads the purchase date',
    ).toHaveValue(purchased);
    await expect(edit.getByLabel('Catatan'), 'and the notes').toHaveValue('Dicatat oleh uji E2E');
    await edit.getByRole('button', { name: 'Batal' }).click();
    await expect(edit).toBeHidden();

    await deleteRow(page, plate);
});

test('@admin-vehicles-documents a document type keeps its code on edit and is created inactive when Aktif is off', async ({
    page,
}) => {
    const code = `QA${stamp()}`;

    await loginAs(page, 'admin');
    await page.goto('/admin/documents');
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Dokumen', level: 1 }),
    ).toBeVisible({ timeout: 15_000 });

    await page.getByRole('button', { name: 'Buat Jenis Dokumen' }).first().click();
    const create = page.getByRole('dialog', { name: 'Buat Jenis Dokumen' });
    await create.getByLabel('Nama').fill(`Jenis uji ${code}`);
    await create.getByLabel('Kode').fill(code);
    await create.getByRole('switch', { name: 'Aktif' }).click();
    await create.getByRole('button', { name: 'Buat', exact: true }).click();
    await expect(create).toBeHidden({ timeout: 15_000 });

    const row = await findRow(page, code);
    await expect(
        row.getByText('Nonaktif', { exact: true }),
        'Aktif off is kept on create',
    ).toBeVisible();

    await row.getByRole('button', { name: 'Ubah' }).click();
    const edit = page.getByRole('dialog', { name: 'Ubah Jenis Dokumen' });
    const codeField = edit.getByLabel('Kode');
    await expect(codeField).toHaveValue(code);
    await expect(codeField, 'the code cannot be edited').toHaveJSProperty('readOnly', true);
    await edit.getByLabel('Nama').fill(`Jenis uji ${code} diubah`);
    await edit.getByRole('button', { name: 'Simpan' }).click();
    await expect(edit).toBeHidden({ timeout: 15_000 });

    const saved = await findRow(page, code);
    await expect(saved).toContainText(`Jenis uji ${code} diubah`);

    await deleteRow(page, code);
});
