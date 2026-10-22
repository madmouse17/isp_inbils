import { readFileSync } from 'node:fs';
import type { Page } from '@playwright/test';

import { expect, test } from './support/fixtures';
import { selectFirstSearchOption, selectSearchOption } from './support/demo';

const seederSource = readFileSync('database/seeders/DemoUserSeeder.php', 'utf8');
const demoPassword = seederSource.match(/(?:public|private) const PASSWORD = '([^']+)';/)?.[1];
const adminEmail = seederSource.match(
    /'admin' => \['name' => 'Demo Admin', 'email' => '([^']+)'/,
)?.[1];

if (!demoPassword || !adminEmail) {
    throw new Error('Could not derive DemoUserSeeder admin credentials.');
}

test('@kanban-t_744bf022 admin creates customer, installation address, and pending subscription', async ({
    page,
}) => {
    const suffix = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const customerName = `E2E Customer ${suffix}`;
    const customerEmail = `e2e-${suffix}@example.test`;
    const addressLabel = `E2E Installation ${suffix}`;
    const billingDay = '17';
    // Not the MRC of any seeded package: the onboarding row carries its package's MRC, this one carries the typed value.
    const mrcAmount = '175000';

    await loginAsAdmin(page);
    await page.goto('/admin/customers/create');
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Buat Pelanggan' }),
    ).toBeVisible();

    await page.getByLabel('Nama').fill(customerName);
    await page.getByLabel('Email').fill(customerEmail);
    await page.getByLabel('Telepon').fill('0800000000');

    // The create form requires a complete address (backend validates label,
    // street, region cascade, and coordinates). Fill the default address on
    // the Addresses tab, then submit from the customer tab.
    await page.getByRole('tab', { name: /Alamat \(\d+\)/ }).click();
    await page.getByLabel('Label').fill(addressLabel);
    await page.getByLabel('Alamat *', { exact: true }).fill('17 E2E Fiber Street');
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
    await page.getByLabel('Nama').fill(`${customerName} Contact`);
    await page.getByLabel('Telepon').fill('081200000000');
    await page.getByLabel('Email').fill(`contact.${suffix}@example.test`);

    // Subscription & SPK section: package + serving POP are required; the E2E
    // seeder provisions "Home 10Mbps" and "POP Utama".
    await page.getByRole('tab', { name: 'Langganan & SPK' }).click();
    await selectFirstSearchOption(page, 'Paket layanan');
    await selectSearchOption(page, 'POP pelayan', /POP Utama/);

    await page.getByRole('tab', { name: 'Data pelanggan' }).click();
    await page.getByRole('button', { name: 'Buat pelanggan dan SPK' }).click();

    await expect(page).toHaveURL(/\/admin\/customers\/[0-9A-HJKMNP-TV-Z]{26}$/i);
    await expect(page.getByRole('main').getByRole('heading', { name: customerName })).toBeVisible();

    // The customer show page renders related tables inline (read-only) — the
    // create-address flow now happens on the create form itself (default
    // address seeded there) and subscription creation lives on the dedicated
    // per-customer subscriptions page.
    const customerId = page.url().match(/\/admin\/customers\/([0-9A-HJKMNP-TV-Z]{26})$/i)?.[1];
    if (!customerId) throw new Error('Could not resolve created customer id');

    // Onboarding leaves only a pending subscription, so the derived status is Prospective.
    await expect(page.getByRole('main').getByText('Prospek')).toBeVisible();
    const customerSearch = encodeURIComponent(customerName);
    await page.goto(`/admin/customers?search=${customerSearch}&status=active`);
    await expect(page.getByRole('main').getByText(customerName)).toHaveCount(0);
    await page.goto(`/admin/customers?search=${customerSearch}&status=prospective`);
    await expect(page.getByRole('row').filter({ hasText: customerName })).toContainText('Prospek');
    await page.goto(`/admin/customers/${customerId}`);

    await expect(page.getByRole('tab', { name: /Alamat \(\d+\)/ })).toBeVisible();
    await expect(page.getByRole('tab', { name: /Langganan \(\d+\)/ })).toBeVisible();

    await page.goto(`/admin/customers/${customerId}/subscriptions`);
    await expect(page.getByRole('main').getByRole('heading', { name: 'Langganan' })).toBeVisible();
    await page.getByRole('button', { name: 'Buat Langganan' }).click();

    const subscriptionDialog = page.getByRole('dialog', { name: 'Buat Langganan' });
    const packageName = await selectFirstSearchOption(page, 'Paket layanan');

    expect(packageName).toBeTruthy();
    await selectSearchOption(page, 'Alamat pemasangan', new RegExp(addressLabel));
    await subscriptionDialog.getByLabel('Tanggal tagihan (1-28)').fill(billingDay);
    await subscriptionDialog.getByLabel('Biaya bulanan').fill(mrcAmount);
    await subscriptionDialog.getByLabel('Masa kontrak (bulan)').fill('12');
    await subscriptionDialog.getByRole('button', { name: 'Buat', exact: true }).click();

    // storeForCustomer() redirects back to the per-customer subscriptions index.
    // Onboarding already created one pending subscription with the same
    // package, so both rows share 'Business 100Mbps' + 'pending'. Disambiguate
    // by the MRC that only the dialog-created row carries (onboarding defaults
    // to the package MRC, i.e. the seeded package — this created row is set to
    // mrcAmount explicitly).
    await expect(page).toHaveURL(new RegExp(`/admin/customers/${customerId}/subscriptions$`));
    // The created row's MRC reads "Rp 175.000", not a larger amount that merely starts with it (cells join without a gap).
    const mrcText = /Rp\s*175\.000(?!\.\d)/;
    const subscriptionRow = page
        .getByRole('row')
        .filter({ hasText: packageName! })
        .filter({ hasText: mrcText });
    await expect(subscriptionRow).toBeVisible();
    await expect(subscriptionRow).toContainText('Menunggu');
    await expect(subscriptionRow).toContainText(mrcText);
    await expect(subscriptionRow).toContainText(billingDay);
});

async function loginAsAdmin(page: Page) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(adminEmail!);
    await page.getByLabel(/^Kata sandi( \*)?$/).fill(demoPassword!);
    await page.getByRole('button', { name: 'Masuk' }).click();
    await expect(page).toHaveURL(/\/admin\/dashboard$/);
}
