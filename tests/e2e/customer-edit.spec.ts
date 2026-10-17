import { expect, test } from './support/fixtures';
import { e2eCustomerName, inertiaProps, loginAs, unique } from './support/demo';

async function linkedCustomerId(page: Parameters<typeof inertiaProps>[0]) {
    const data = await inertiaProps(
        page,
        `/admin/customers?search=${encodeURIComponent(e2eCustomerName)}`,
    );
    const rows = data.props?.customers?.data as Array<{ id: string; name: string }> | undefined;
    const customer = rows?.find((row) => row.name === e2eCustomerName);
    if (!customer) throw new Error('E2E linked customer fixture not found');
    return customer.id;
}

async function openEdit(page: Parameters<typeof inertiaProps>[0]) {
    await loginAs(page, 'admin');
    const id = await linkedCustomerId(page);
    await page.goto(`/admin/customers/${id}/edit`);
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Ubah Pelanggan' }),
    ).toBeVisible();
    return id;
}

test('@customer-edit tabs are accessible, forms never nest, scalar save is isolated, validation retains draft, and cancel discards it', async ({
    page,
}) => {
    const id = await openEdit(page);
    const tabs = page.getByRole('tablist', { name: 'Bagian pelanggan' });
    await expect(tabs.getByRole('tab')).toHaveCount(3);
    await expect(page.locator('form form')).toHaveCount(0);
    await expect(page.locator('form')).toHaveCount(1);

    const customerTab = tabs.getByRole('tab', { name: 'Data pelanggan' });
    const addressesTab = tabs.getByRole('tab', { name: /Alamat/ });
    await customerTab.focus();
    await page.keyboard.press('ArrowRight');
    await expect(addressesTab).toBeFocused();
    await expect(addressesTab).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('Home');
    await expect(customerTab).toBeFocused();

    const name = unique('Edited Customer');
    await page.getByLabel('Nama').fill(name);
    await page.getByLabel('Email').fill('not-an-email');
    await page.getByRole('button', { name: 'Simpan pelanggan' }).click();
    await expect(page.getByLabel('Nama')).toHaveValue(name);
    await expect(page.getByText(/alamat email yang valid/i)).toBeVisible();

    await page.getByLabel('Email').fill(`${unique('customer').toLowerCase()}@example.test`);
    const save = page.getByRole('button', { name: 'Simpan pelanggan' });
    await save.dblclick();
    await expect(page).toHaveURL(/\/admin\/customers\/?$/);
    await page.goto(`/admin/customers/${id}/edit`);
    await expect(page.getByLabel('Nama')).toHaveValue(name);
    await expect(page.getByRole('tab', { name: /Alamat/ })).toContainText('(1)');

    const discarded = unique('Discarded Draft');
    await page.getByLabel('Nama').fill(discarded);
    await page.getByRole('button', { name: 'Batal' }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/customers/${id}$`));
    await page.goto(`/admin/customers/${id}/edit`);
    await expect(page.getByLabel('Nama')).toHaveValue(name);

    // Restore the shared E2E fixture name so subsequent specs in this worker
    // (which look the customer up by `E2E Linked Customer`) still resolve it.
    await page.getByLabel('Nama').fill(e2eCustomerName);
    await page.getByRole('button', { name: 'Simpan pelanggan' }).click();
    await expect(page).toHaveURL(/\/admin\/customers\/?$/);
});

test('@customer-edit address and contact CRUD preserve invariant A and support map coordinate/current-location interactions', async ({
    page,
    context,
}) => {
    const id = await openEdit(page);

    await page.getByRole('tab', { name: /Alamat/ }).click();
    await expect(page.locator('form form')).toHaveCount(0);
    const addressLabel = unique('QA Address');
    await page.getByLabel('Label').fill(addressLabel);
    // The textarea's accessible name is "Alamat *": the required marker is a distinct text node,
    // so exact 'Alamat' matches 0 elements, and 'Alamat *' exact resolves only to the textarea.
    await page.getByLabel('Alamat *', { exact: true }).fill('QA disposable address');

    await page.getByRole('button', { name: 'Lintang / Bujur' }).click();
    // Editable coordinates: -6.210 / 106.820 (Jakarta)
    await page.getByLabel('Lintang', { exact: true }).fill('-6.2100000');
    await page.getByLabel('Bujur', { exact: true }).fill('106.8200000');
    await page.getByRole('button', { name: 'Cari', exact: true }).click();
    await expect(page.getByLabel('Lintang *', { exact: true })).toHaveValue('-6.2100000');

    await context.grantPermissions(['geolocation'], { origin: 'http://127.0.0.1:8010' });
    await context.setGeolocation({ latitude: -6.22, longitude: 106.83 });
    await page.getByRole('button', { name: 'Lokasi saat ini' }).click();
    await expect(page.getByLabel('Lintang *', { exact: true })).toHaveValue('-6.2200000');

    const map = page.getByLabel('Peta untuk memilih koordinat alamat');
    await map.click({ position: { x: 300, y: 220 } });
    await expect(page.getByLabel('Lintang *', { exact: true })).not.toHaveValue('-6.2200000');

    // Region cascade: Provinsi -> Kota / Kabupaten -> Kecamatan -> Kelurahan / Desa. Each
    // SearchSelect is a combobox that opens on click and lists role=option items in a popover;
    // children are disabled until their parent is chosen.
    const regionCombobox = (label: string) => page.getByLabel(label, { exact: true });
    await regionCombobox('Provinsi *').click();
    await page
        .getByRole('option', { name: /jakarta/i })
        .first()
        .click();
    await regionCombobox('Kota / Kabupaten *').click();
    await page
        .getByRole('option', { name: /jakarta pusat/i })
        .first()
        .click();
    await regionCombobox('Kecamatan *').click();
    await page
        .getByRole('option', { name: /menteng/i })
        .first()
        .click();
    await regionCombobox('Kelurahan / Desa *').click();
    await page
        .getByRole('option', { name: /menteng/i })
        .first()
        .click();

    // Invariant A: at most one primary + one installation point. The fixture
    // already seeds a primary address, so a second address is NOT primary by
    // default — promote it explicitly to assert the badges render.
    await page.getByLabel('Alamat utama').click();
    await page.getByLabel('Alamat pemasangan').click();

    await page.getByRole('button', { name: 'Tambah', exact: true }).click();
    await expect(page.getByText(addressLabel)).toBeVisible();
    await expect(page.getByText(addressLabel).locator('..')).toContainText('(utama)');
    await expect(page.getByText(addressLabel).locator('..')).toContainText('(pemasangan)');

    const addressRow = page.locator('div.rounded-lg.border.p-3').filter({ hasText: addressLabel });
    await addressRow.getByRole('button', { name: 'Ubah' }).click();
    const updatedAddress = `${addressLabel} Updated`;
    await page.getByLabel('Label').fill(updatedAddress);
    await page.getByRole('button', { name: 'Simpan', exact: true }).click();
    await expect(page.getByText(updatedAddress)).toBeVisible();
    await page
        .locator('div.rounded-lg.border.p-3')
        .filter({ hasText: updatedAddress })
        .getByRole('button', { name: 'Hapus' })
        .click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Hapus' }).click();
    await expect(page.getByText(updatedAddress)).toHaveCount(0);

    await page.getByRole('tab', { name: /Kontak/ }).click();
    const contactName = unique('QA Contact');
    await page.getByLabel('Nama').fill(contactName);
    await page.getByLabel('Jabatan').fill('QA Lead');
    await page.getByLabel('Telepon').fill('081234567890');
    await page.getByRole('button', { name: 'Tambah', exact: true }).click();
    await expect(page.getByText(contactName)).toBeVisible();
    await expect(page.getByText(contactName).locator('..')).toContainText('(utama)');

    const contactRow = page.locator('div.rounded-lg.border.p-3').filter({ hasText: contactName });
    await contactRow.getByRole('button', { name: 'Ubah' }).click();
    const updatedContact = `${contactName} Updated`;
    await page.getByLabel('Nama').fill(updatedContact);
    await page.getByRole('button', { name: 'Simpan', exact: true }).click();
    await expect(page.getByText(updatedContact)).toBeVisible();
    await page
        .locator('div.rounded-lg.border.p-3')
        .filter({ hasText: updatedContact })
        .getByRole('button', { name: 'Hapus' })
        .click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Hapus' }).click();
    await expect(page.getByText(updatedContact)).toHaveCount(0);

    await page.goto(`/admin/customers/${id}/edit`);
    await expect(page.getByRole('tab', { name: /Alamat \(1\)/ })).toBeVisible();
    await expect(page.getByRole('tab', { name: /Kontak \(0\)/ })).toBeVisible();
});

test('@customer-edit responsive dark mode smoke keeps tabs and focus usable', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.emulateMedia({ colorScheme: 'dark' });
    await openEdit(page);
    const tabs = page.getByRole('tablist', { name: 'Bagian pelanggan' });
    await expect(tabs).toBeVisible();
    await tabs.getByRole('tab', { name: 'Data pelanggan' }).focus();
    await page.keyboard.press('End');
    await expect(tabs.getByRole('tab', { name: /Kontak/ })).toBeFocused();
    await expect(page.getByRole('tabpanel')).toBeVisible();
});
