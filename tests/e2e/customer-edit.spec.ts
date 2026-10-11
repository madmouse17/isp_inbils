import { expect, test } from './support/fixtures';
import { e2eCustomerName, inertiaProps, loginAs, unique } from './support/demo';

async function linkedCustomerId(page: Parameters<typeof inertiaProps>[0]) {
    const data = await inertiaProps(
        page,
        `/admin/customers?search=${encodeURIComponent(e2eCustomerName)}`,
    );
    const rows = data.props?.customers?.data as Array<{ id: number; name: string }> | undefined;
    const customer = rows?.find((row) => row.name === e2eCustomerName);
    if (!customer) throw new Error('E2E linked customer fixture not found');
    return customer.id;
}

async function openEdit(page: Parameters<typeof inertiaProps>[0]) {
    await loginAs(page, 'admin');
    const id = await linkedCustomerId(page);
    await page.goto(`/admin/customers/${id}/edit`);
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Edit Customer' }),
    ).toBeVisible();
    return id;
}

test('@customer-edit tabs are accessible, forms never nest, scalar save is isolated, validation retains draft, and cancel discards it', async ({
    page,
}) => {
    const id = await openEdit(page);
    const tabs = page.getByRole('tablist', { name: 'Customer sections' });
    await expect(tabs.getByRole('tab')).toHaveCount(3);
    await expect(page.locator('form form')).toHaveCount(0);
    await expect(page.locator('form')).toHaveCount(1);

    const customerTab = tabs.getByRole('tab', { name: 'Customer Data' });
    const addressesTab = tabs.getByRole('tab', { name: /Addresses/ });
    await customerTab.focus();
    await page.keyboard.press('ArrowRight');
    await expect(addressesTab).toBeFocused();
    await expect(addressesTab).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('Home');
    await expect(customerTab).toBeFocused();

    const name = unique('Edited Customer');
    await page.getByLabel('Name').fill(name);
    await page.getByLabel('Email').fill('not-an-email');
    await page.getByRole('button', { name: 'Save Customer' }).click();
    await expect(page.getByLabel('Name')).toHaveValue(name);
    await expect(page.getByText(/valid email/i)).toBeVisible();

    await page.getByLabel('Email').fill(`${unique('customer').toLowerCase()}@example.test`);
    const save = page.getByRole('button', { name: 'Save Customer' });
    await save.dblclick();
    await expect(page).toHaveURL(/\/admin\/customers\/?$/);
    await page.goto(`/admin/customers/${id}/edit`);
    await expect(page.getByLabel('Name')).toHaveValue(name);
    await expect(page.getByRole('tab', { name: /Addresses/ })).toContainText('(1)');

    const discarded = unique('Discarded Draft');
    await page.getByLabel('Name').fill(discarded);
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/customers/${id}$`));
    await page.goto(`/admin/customers/${id}/edit`);
    await expect(page.getByLabel('Name')).toHaveValue(name);

    // Restore the shared E2E fixture name so subsequent specs in this worker
    // (which look the customer up by `E2E Linked Customer`) still resolve it.
    await page.getByLabel('Name').fill(e2eCustomerName);
    await page.getByRole('button', { name: 'Save Customer' }).click();
    await expect(page).toHaveURL(/\/admin\/customers\/?$/);
});

test('@customer-edit address and contact CRUD preserve invariant A and support map coordinate/current-location interactions', async ({
    page,
    context,
}) => {
    const id = await openEdit(page);

    await page.getByRole('tab', { name: /Addresses/ }).click();
    await expect(page.locator('form form')).toHaveCount(0);
    const addressLabel = unique('QA Address');
    await page.getByLabel('Label').fill(addressLabel);
    // Chromium computes the textarea's accessible name as "Address *": the
    // required marker is a distinct text node (aria-hidden does not exclude
    // the label element's own subtree from accName), so exact 'Address'
    // matches 0 elements. 'Address *' exact resolves exclusively to the
    // textarea (the map mode-toggle "Address" is a button, not a label).
    await page.getByLabel('Address *', { exact: true }).fill('QA disposable address');

    await page.getByRole('button', { name: 'Lat / Long' }).click();
    // Editable coordinates: -6.210 / 106.820 (Jakarta)
    await page.getByLabel('Latitude', { exact: true }).fill('-6.2100000');
    await page.getByLabel('Longitude', { exact: true }).fill('106.8200000');
    await page.getByRole('button', { name: 'Find' }).click();
    await expect(page.getByLabel('Latitude *', { exact: true })).toHaveValue('-6.2100000');

    await context.grantPermissions(['geolocation'], { origin: 'http://127.0.0.1:8010' });
    await context.setGeolocation({ latitude: -6.22, longitude: 106.83 });
    await page.getByRole('button', { name: 'Current Location' }).click();
    await expect(page.getByLabel('Latitude *', { exact: true })).toHaveValue('-6.2200000');

    const map = page.getByLabel('Map for selecting customer address coordinates');
    await map.click({ position: { x: 300, y: 220 } });
    await expect(page.getByLabel('Latitude *', { exact: true })).not.toHaveValue('-6.2200000');

    // Region cascade: Province -> City -> District -> Village. Each SearchSelect
    // is a combobox that lists options while focused; children are disabled
    // until their parent is chosen.
    const regionCombobox = (label: string) => page.getByLabel(label, { exact: true });
    await regionCombobox('Province *').click();
    await page
        .getByRole('button', { name: /jakarta/i })
        .first()
        .click();
    await regionCombobox('City / Regency *').click();
    await page
        .getByRole('button', { name: /jakarta pusat/i })
        .first()
        .click();
    await regionCombobox('District *').click();
    await page
        .getByRole('button', { name: /menteng/i })
        .first()
        .click();
    await regionCombobox('Village / Subdistrict *').click();
    await page
        .getByRole('button', { name: /menteng/i })
        .first()
        .click();

    // Invariant A: at most one primary + one installation point. The fixture
    // already seeds a primary address, so a second address is NOT primary by
    // default — promote it explicitly to assert the badges render.
    await page.getByLabel('Primary address').click();
    await page.getByLabel('Installation address').click();

    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(page.getByText(addressLabel)).toBeVisible();
    await expect(page.getByText(addressLabel).locator('..')).toContainText('(primary)');
    await expect(page.getByText(addressLabel).locator('..')).toContainText('(installation)');

    const addressRow = page.locator('div.rounded-lg.border.p-3').filter({ hasText: addressLabel });
    await addressRow.getByRole('button', { name: 'Edit' }).click();
    const updatedAddress = `${addressLabel} Updated`;
    await page.getByLabel('Label').fill(updatedAddress);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText(updatedAddress)).toBeVisible();
    await page
        .locator('div.rounded-lg.border.p-3')
        .filter({ hasText: updatedAddress })
        .getByRole('button', { name: 'Delete' })
        .click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByText(updatedAddress)).toHaveCount(0);

    await page.getByRole('tab', { name: /Contacts/ }).click();
    const contactName = unique('QA Contact');
    await page.getByLabel('Name').fill(contactName);
    await page.getByLabel('Position').fill('QA Lead');
    await page.getByLabel('Phone').fill('081234567890');
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(page.getByText(contactName)).toBeVisible();
    await expect(page.getByText(contactName).locator('..')).toContainText('(primary)');

    const contactRow = page.locator('div.rounded-lg.border.p-3').filter({ hasText: contactName });
    await contactRow.getByRole('button', { name: 'Edit' }).click();
    const updatedContact = `${contactName} Updated`;
    await page.getByLabel('Name').fill(updatedContact);
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText(updatedContact)).toBeVisible();
    await page
        .locator('div.rounded-lg.border.p-3')
        .filter({ hasText: updatedContact })
        .getByRole('button', { name: 'Delete' })
        .click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByText(updatedContact)).toHaveCount(0);

    await page.goto(`/admin/customers/${id}/edit`);
    await expect(page.getByRole('tab', { name: /Addresses \(1\)/ })).toBeVisible();
    await expect(page.getByRole('tab', { name: /Contacts \(0\)/ })).toBeVisible();
});

test('@customer-edit responsive dark mode smoke keeps tabs and focus usable', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.emulateMedia({ colorScheme: 'dark' });
    await openEdit(page);
    const tabs = page.getByRole('tablist', { name: 'Customer sections' });
    await expect(tabs).toBeVisible();
    await tabs.getByRole('tab', { name: 'Customer Data' }).focus();
    await page.keyboard.press('End');
    await expect(tabs.getByRole('tab', { name: /Contacts/ })).toBeFocused();
    await expect(page.getByRole('tabpanel')).toBeVisible();
});
