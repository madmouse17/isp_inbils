/**
 * @lifecycle Penomoran: configurable prefix/format for automatic codes.
 *
 * Serial on purpose: the first test rewrites the customer format and the last step restores it,
 * so every later spec sees the original numbering. The "perkiraan" (preview) code is a guess, so
 * code assertions compare against the preview only because the canonical run uses one worker.
 */
import type { Locator, Page } from '@playwright/test';

import { expect, test } from './support/fixtures';
import {
    inertiaProps,
    loginAs,
    selectFirstSearchOption,
    selectSearchOption,
    unique,
} from './support/demo';

type SequenceRow = {
    entity_type: string;
    label: string;
    prefix: string;
    template: string;
    next_number: number;
    reset_period: 'never' | 'yearly' | 'monthly' | 'daily';
    allow_manual: boolean;
};

async function sequenceOf(page: Page, entityType: string): Promise<SequenceRow> {
    const props = await inertiaProps(page, '/admin/number-sequences');
    const row = ((props.props?.sequences ?? []) as SequenceRow[]).find(
        (sequence) => sequence.entity_type === entityType,
    );
    if (!row) throw new Error(`Penomoran has no row for ${entityType}`);
    return row;
}

const rowOf = (page: Page, label: string) =>
    page.getByRole('row').filter({ has: page.getByRole('cell', { name: label, exact: true }) });

async function openEditor(page: Page, label: string) {
    await page.goto('/admin/number-sequences');
    await expect(page.getByRole('main').getByRole('heading', { name: 'Penomoran' })).toBeVisible();
    await page.getByRole('button', { name: `Ubah penomoran ${label}` }).click();
    const dialog = page.getByRole('dialog', { name: `Ubah penomoran ${label}` });
    await expect(dialog).toBeVisible();
    return dialog;
}

async function saveEditor(page: Page, dialog: Locator) {
    await dialog.getByRole('button', { name: 'Simpan' }).click();
    await expect(
        page.getByRole('status').filter({ hasText: 'Penomoran diperbarui.' }),
    ).toBeVisible();
    await expect(dialog).toBeHidden();
}

async function onboardCustomer(page: Page, name: string, slug: string) {
    await page.goto('/admin/customers/create');
    await page.getByLabel('Nama').fill(name);
    await page.getByLabel('Email').fill(`${slug}@example.test`);
    await page.getByLabel('Telepon').fill(`0800${String(Date.now()).slice(-8)}`);

    await page.getByRole('tab', { name: /Alamat \(\d+\)/ }).click();
    await page.getByLabel('Label').fill(`E2E Address ${slug}`);
    await page.getByLabel('Alamat *', { exact: true }).fill('7 E2E Numbering Street');
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
    await page.getByLabel('Telepon').fill('081200000777');
    await page.getByLabel('Email').fill(`contact.${slug}@example.test`);

    await page.getByRole('tab', { name: 'Langganan & SPK' }).click();
    await selectFirstSearchOption(page, 'Paket layanan');
    await selectSearchOption(page, 'POP pelayan', 'POP Utama');

    await page.getByRole('tab', { name: 'Data pelanggan' }).click();
}

test.describe('@lifecycle number sequences', () => {
    test.describe.configure({ mode: 'serial' });

    test('admin edits the customer format and new customers follow it', async ({ page }) => {
        await loginAs(page, 'admin');
        const original = await sequenceOf(page, 'customer');
        expect(original.allow_manual).toBe(false);

        const dialog = await openEditor(page, original.label);
        await expect(dialog.getByText('Hanya kode yang dibuat setelah ini')).toBeVisible();
        await expect(
            page.getByText('Perubahan format hanya berlaku untuk data baru.'),
        ).toBeVisible();

        // Token chips are real buttons: keyboard Enter on a focused chip inserts its token.
        const template = dialog.getByRole('textbox', { name: 'Format' });
        await template.fill('');
        const prefixChip = dialog.getByRole('button', { name: 'Sisipkan {PREFIX}' });
        await prefixChip.focus();
        await page.keyboard.press('Enter');
        await expect(template).toHaveValue('{PREFIX}');
        await dialog.getByRole('button', { name: /^Sisipkan \{SEQ/ }).click();
        await expect(template).toHaveValue(/^\{PREFIX\}\{SEQ:\d+\}$/);

        await template.fill('{PREFIX}-{SEQ:6}');
        await dialog.getByLabel('Awalan').fill('E2E');
        await dialog.getByLabel('Nomor berikutnya').fill('123');
        await expect(dialog.getByText('E2E-000123', { exact: true })).toBeVisible();

        // Invalid format: the live check flags the template field before anything is saved.
        await template.fill('{PREFIX}-{SEQ:0}');
        await expect(dialog.getByText(/tidak dikenal/)).toBeVisible();
        await template.fill('{PREFIX}-{SEQ:6}');
        await saveEditor(page, dialog);

        const row = rowOf(page, original.label);
        await expect(row).toContainText('{PREFIX}-{SEQ:6}');
        await expect(row).toContainText('E2E-000123');

        // Locked field: read-only, preview in the hint, and the code is not part of the request.
        const suffix = unique('seq').toLowerCase();
        const name = `E2E Numbering ${suffix}`;
        await onboardCustomer(page, name, suffix);
        const code = page.getByLabel('Kode', { exact: true });
        await expect(code).toHaveAttribute('readonly', '');
        await expect(
            page.getByText('Dibuat otomatis saat disimpan, perkiraan: E2E-000123'),
        ).toBeVisible();

        const created = page.waitForRequest(
            (request) => request.method() === 'POST' && request.url().endsWith('/admin/customers'),
        );
        await page.getByRole('button', { name: 'Buat pelanggan dan SPK' }).click();
        const createdBody = (await created).postDataJSON() as Record<string, unknown>;
        expect(createdBody).not.toHaveProperty('code');

        await expect(page).toHaveURL(/\/admin\/customers\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        await expect(page.getByRole('main').getByText('E2E-000123').first()).toBeVisible();
        const customerId = page.url().match(/\/admin\/customers\/([0-9A-HJKMNP-TV-Z]{26})$/i)![1];

        // Edit keeps the stored code, shows it read-only and never submits it.
        await page.goto(`/admin/customers/${customerId}/edit`);
        await expect(page.getByLabel('Kode', { exact: true })).toHaveValue('E2E-000123');
        await expect(page.getByLabel('Kode', { exact: true })).toHaveAttribute('readonly', '');
        const updated = page.waitForRequest(
            (request) =>
                ['PUT', 'POST'].includes(request.method()) &&
                request.url().endsWith(`/admin/customers/${customerId}`),
        );
        await page.getByRole('button', { name: 'Simpan pelanggan' }).click();
        expect(((await updated).postDataJSON() as Record<string, unknown>).code).toBeUndefined();
        await expect(page).toHaveURL(/\/admin\/customers(?:\/[0-9A-HJKMNP-TV-Z]{26})?$/i);
        await expect(page.getByRole('main').getByText('E2E-000123').first()).toBeVisible();

        // Restore the original numbering for every later spec.
        const restore = await openEditor(page, original.label);
        await restore.getByLabel('Awalan').fill(original.prefix);
        await restore.getByRole('textbox', { name: 'Format' }).fill(original.template);
        await restore.getByLabel('Nomor berikutnya').fill(String(original.next_number));
        await restore.getByLabel('Reset nomor').selectOption(original.reset_period);
        await saveEditor(page, restore);
        await expect(rowOf(page, original.label)).toContainText(original.template);
    });

    test('product SKU is optional: typed SKU is kept, empty SKU is generated', async ({ page }) => {
        await loginAs(page, 'admin');
        const product = await sequenceOf(page, 'product');
        expect(product.allow_manual).toBe(true);

        const suffix = unique('sku').toLowerCase();
        const typedSku = `E2E-SKU-${suffix}`.toUpperCase();
        const typedName = `E2E Typed ${suffix}`;
        const autoName = `E2E Auto ${suffix}`;

        await page.goto('/admin/products/create');
        const sku = page.getByLabel('SKU');
        await expect(sku).toBeEditable();
        await expect(page.getByText('Kosongkan untuk dibuat otomatis')).toBeVisible();
        await sku.fill(typedSku);
        await page.getByLabel(/^Nama\s*\*?$/).fill(typedName);
        await selectFirstSearchOption(page, 'Kategori');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/products\/?$/);

        await page.goto(`/admin/products/create`);
        const generated = await page.getByLabel('SKU').getAttribute('placeholder');
        expect(generated).toBeTruthy();
        await page.getByLabel(/^Nama\s*\*?$/).fill(autoName);
        await selectFirstSearchOption(page, 'Kategori');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/products\/?$/);

        await page.goto(`/admin/products?search=${encodeURIComponent(typedName)}`);
        await expect(page.getByRole('row').filter({ hasText: typedName })).toContainText(typedSku);
        await page.goto(`/admin/products?search=${encodeURIComponent(autoName)}`);
        await expect(page.getByRole('row').filter({ hasText: autoName })).toContainText(generated!);
    });
});
