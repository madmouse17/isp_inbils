import type { Page } from '@playwright/test';

import {
    e2eCustomerName,
    inertiaProps,
    loginAs,
    logout,
    refreshSession,
    unique,
    type DemoRole,
} from './support/demo';
import { expect, test } from './support/fixtures';

/**
 * IPAM and ODN (.scratch/ipam-odn): the journeys the manager walks through the UI, what staff and the
 * technician may and may not do, and the role x route matrix. Fixtures come from E2eFixtureSeeder: the
 * address plan 10.99.x.x (VLAN 999 and three prefixes), the OLT E2E-OLT-001 with an ODC and the ODP
 * E2E-ODP-001 (every port free), and the active subscription E2E-LINKED-SUB with neither a static
 * address nor a drop.
 */

const popName = 'Default POP';
const odpCode = 'E2E-ODP-001';
const oltCode = 'E2E-OLT-001';

async function linkedSubscriptionId(page: Page) {
    const customers = (
        await inertiaProps(page, `/admin/customers?search=${encodeURIComponent(e2eCustomerName)}`)
    ).props?.customers?.data as Array<{ id: number; name: string }> | undefined;
    const customer = customers?.find((row) => row.name === e2eCustomerName);
    if (!customer) throw new Error('E2E linked customer fixture not found');

    const subscriptions = (
        await inertiaProps(page, `/admin/customers/${customer.id}/subscriptions`)
    ).props?.subscriptions?.data as Array<{ id: number; code: string }> | undefined;
    const subscription = subscriptions?.find((row) => row.code === 'E2E-LINKED-SUB');
    if (!subscription) throw new Error('E2E-LINKED-SUB fixture not found');

    return subscription.id;
}

/** The id of an ODP (by code) or of the OLT behind a PON port (by code), read from the ODN overview. */
async function odnAssetId(page: Page, kind: 'odp' | 'olt', code: string) {
    const props = (await inertiaProps(page, '/admin/odn')).props as {
        odps: { data: Array<{ asset_id: number; code: string }> };
        ponPorts: { data: Array<{ olt_asset_id: number; olt_code: string }> };
    };
    const id =
        kind === 'odp'
            ? props.odps.data.find((row) => row.code === code)?.asset_id
            : props.ponPorts.data.find((row) => row.olt_code === code)?.olt_asset_id;
    if (id === undefined) throw new Error(`${code} is not in the ODN register`);

    return id;
}

/** The ODP row of the ODN overview: capacity, used and free ports. */
async function odpCounts(page: Page) {
    await page.goto('/admin/odn');
    const row = page
        .getByRole('row')
        .filter({ has: page.getByRole('link', { name: odpCode, exact: true }) });
    await expect(row).toBeVisible();
    const cells = row.getByRole('cell');

    return {
        capacity: Number(await cells.nth(3).innerText()),
        used: Number(await cells.nth(4).innerText()),
        // The free cell carries a "Full" badge when it reads zero.
        free: Number((await cells.nth(5).innerText()).replace(/\D+/g, '')),
    };
}

async function openNetworkGroup(page: Page) {
    const group = page
        .locator('aside details')
        .filter({ has: page.locator('summary', { hasText: 'Jaringan' }) });

    if (!(await group.evaluate((details: HTMLDetailsElement) => details.open))) {
        await group.locator('summary').click();
    }

    await expect(group).toHaveJSProperty('open', true);

    return group;
}

test('@ipam-odn manager registers a /29 static pool, gives a subscription a static IP and releases it', async ({
    page,
}) => {
    const cidr = `10.77.${100 + Math.floor(Math.random() * 100)}.0/29`;
    const network = cidr.split('/')[0].replace(/\.0$/, '');
    const firstAddress = `${network}.1`;
    const secondAddress = `${network}.2`;

    await loginAs(page, 'manager');
    const subscriptionId = await linkedSubscriptionId(page);

    await test.step('register the pool; a duplicate is refused under the field and keeps the input', async () => {
        await page.goto('/admin/ipam/prefixes');
        const newPrefix = page.getByRole('button', { name: 'New prefix' });
        const dialog = page.getByRole('dialog', { name: 'New prefix' });

        await newPrefix.click();
        await dialog.getByLabel('CIDR').fill(cidr);
        await dialog.getByLabel('Usage').selectOption('customer_static');
        await dialog.getByRole('button', { name: 'Register prefix' }).click();
        await expect(dialog).toBeHidden();

        const row = page
            .getByRole('row')
            .filter({ has: page.getByRole('link', { name: cidr, exact: true }) });
        await expect(row).toContainText('Customer static');
        // A /29 is 8 addresses with 6 usable; nothing is assigned yet.
        await expect(row.getByRole('cell').nth(4)).toHaveText('8');
        await expect(row.getByRole('cell').nth(6)).toHaveText('6');
        await expect(row.getByRole('meter', { name: `${cidr} addresses in use` })).toHaveAttribute(
            'aria-valuenow',
            '0',
        );

        await newPrefix.click();
        await dialog.getByLabel('CIDR').fill(cidr);
        await dialog.getByLabel('Usage').selectOption('customer_static');
        await dialog.getByRole('button', { name: 'Register prefix' }).click();
        await expect(dialog.getByText(`${cidr} is already registered.`)).toBeVisible();
        await expect(dialog.getByLabel('CIDR')).toHaveValue(cidr);

        // Escape closes the dialog and focus returns to the button that opened it.
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(newPrefix).toBeFocused();
    });

    await test.step('assign the lowest free address from the subscription page', async () => {
        await page.goto(`/admin/subscriptions/${subscriptionId}`);
        await expect(page.getByRole('heading', { name: 'IP address' })).toBeVisible();
        await expect(page.getByText('Dynamic (assigned by router)')).toBeVisible();

        await page.getByRole('button', { name: 'Assign static IP' }).click();
        const dialog = page.getByRole('dialog', { name: 'Assign a static IP to E2E-LINKED-SUB' });
        await dialog.getByLabel('Pool').selectOption({ label: `${cidr} (6 free)` });
        await dialog.getByRole('button', { name: 'Assign address' }).click();
        await expect(dialog).toBeHidden();

        await expect(page.getByText(firstAddress, { exact: true })).toBeVisible();
        await expect(page.getByText(`IPv4 · ${cidr}`)).toBeVisible();
        await expect(page.getByText('Dynamic (assigned by router)')).toHaveCount(0);
    });

    await test.step('the prefix page lists the holder; a reservation is taken and given back', async () => {
        await page.goto('/admin/ipam/prefixes');
        await page.getByRole('link', { name: cidr, exact: true }).click();
        await expect(page.getByRole('main').getByRole('heading', { name: cidr })).toBeVisible();

        const held = page.getByRole('row').filter({ hasText: firstAddress });
        await expect(held).toContainText('Assigned');
        await expect(held.getByRole('link', { name: /E2E-LINKED-SUB/ })).toBeVisible();

        // The held address cannot be reserved: the server names the holder under the field.
        await page.getByRole('button', { name: 'Reserve address' }).click();
        const dialog = page.getByRole('dialog', { name: `Reserve an address in ${cidr}` });
        await dialog.getByLabel('Address').fill(firstAddress);
        await dialog.getByRole('button', { name: 'Reserve address' }).click();
        await expect(dialog.getByText(new RegExp(`${firstAddress} is already`))).toBeVisible();
        await expect(dialog.getByLabel('Address')).toHaveValue(firstAddress);

        await dialog.getByLabel('Address').fill(secondAddress);
        await dialog.getByLabel('Note').fill('Gateway');
        await dialog.getByRole('button', { name: 'Reserve address' }).click();
        await expect(dialog).toBeHidden();

        const reserved = page.getByRole('row').filter({ hasText: secondAddress });
        await expect(reserved).toContainText('Reserved');
        await expect(reserved).toContainText('Gateway');

        await reserved.getByRole('button', { name: `Release ${secondAddress}` }).click();
        await page
            .getByRole('alertdialog')
            .getByRole('button', { name: 'Release address' })
            .click();
        // The page behind an open dialog is hidden from the accessibility tree, so wait for it to close first.
        await expect(page.getByRole('alertdialog')).toBeHidden();
        await expect(page.getByRole('row').filter({ hasText: secondAddress })).toHaveCount(0);
    });

    await test.step('release from the subscription page; the address leaves the prefix', async () => {
        await page.goto(`/admin/subscriptions/${subscriptionId}`);
        await page.getByRole('button', { name: `Release ${firstAddress}` }).click();
        await page
            .getByRole('alertdialog')
            .getByRole('button', { name: 'Release address' })
            .click();
        await expect(page.getByRole('alertdialog')).toBeHidden();

        await expect(page.getByText('Dynamic (assigned by router)')).toBeVisible();
        await expect(page.getByText(firstAddress, { exact: true })).toHaveCount(0);

        await page.goto('/admin/ipam/prefixes');
        await page.getByRole('link', { name: cidr, exact: true }).click();
        await expect(page.getByRole('row').filter({ hasText: firstAddress })).toHaveCount(0);
        await expect(page.getByText('No addresses in this block')).toBeVisible();
    });

    await test.step('a prefix with no address and no child can be deleted', async () => {
        await page.getByRole('button', { name: 'Delete', exact: true }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Delete prefix' }).click();
        await expect(page).toHaveURL(/\/admin\/ipam\/prefixes$/);
        await expect(page.getByRole('link', { name: cidr, exact: true })).toHaveCount(0);
    });
});

test('@ipam-odn manager adds PON ports and a splitter, onboards onto the ODP, then terminating frees the port', async ({
    page,
}) => {
    test.setTimeout(240_000);

    const suffix = unique('odn');
    const splitterLabel = `E2E 1:8 ${suffix}`;
    const portChoice = `${odpCode} · ${splitterLabel} · port 1`;
    const customerCode = `E2E-ODN-${suffix}`;
    const customerName = `E2E ODN Customer ${suffix}`;
    let customerId = '';

    await loginAs(page, 'manager');
    const before = await odpCounts(page);

    await test.step('add two PON ports to the OLT', async () => {
        const oltId = await odnAssetId(page, 'olt', oltCode);
        await page.goto(`/admin/odn/assets/${oltId}`);
        await expect(page.getByRole('main').getByRole('heading', { name: oltCode })).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: 'PON 4' })).toBeVisible();

        await page.getByRole('button', { name: 'Add PON ports' }).click();
        const dialog = page.getByRole('dialog', { name: `Add PON ports to ${oltCode}` });
        await dialog.getByLabel('Number of ports').fill('2');
        await dialog.getByRole('button', { name: 'Add ports' }).click();
        await expect(dialog).toBeHidden();

        await expect(page.getByRole('row').filter({ hasText: 'PON 5' })).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: 'PON 6' })).toBeVisible();
    });

    await test.step('add a 1:8 splitter to the ODP, connect it to PON 5, disconnect and reconnect it', async () => {
        const odpId = await odnAssetId(page, 'odp', odpCode);
        await page.goto(`/admin/odn/assets/${odpId}`);
        await expect(page.getByRole('main').getByRole('heading', { name: odpCode })).toBeVisible();
        const freePorts = page.getByRole('group', { name: /^Port \d+, free$/ });
        const portsBefore = await freePorts.count();

        await page.getByRole('button', { name: 'Add splitter' }).click();
        const dialog = page.getByRole('dialog', { name: `Add a splitter to ${odpCode}` });
        await dialog.getByLabel('Ratio').selectOption('8');
        await dialog.getByLabel('Upstream port').selectOption({ label: `${oltCode} · PON 5` });
        await dialog.getByLabel('Label').fill(splitterLabel);
        await dialog.getByRole('button', { name: 'Add splitter' }).click();
        await expect(dialog).toBeHidden();

        // The pick list of every card names this splitter's outputs too, so a card is found by its heading.
        const card = page.locator('div[id^="splitter-"]').filter({
            has: page.getByRole('heading', { name: `Splitter 1:8 — ${splitterLabel}` }),
        });
        await expect(card).toHaveCount(1);
        await expect(card.getByText(`Input: ${oltCode} · PON 5`)).toBeVisible();
        await expect(freePorts).toHaveCount(portsBefore + 8);

        await card.getByLabel('Upstream port').selectOption({ label: 'Not connected' });
        await card.getByRole('button', { name: 'Save connection' }).click();
        await expect(card.getByText('Input: not connected')).toBeVisible();

        await card.getByLabel('Upstream port').selectOption({ label: `${oltCode} · PON 5` });
        await card.getByRole('button', { name: 'Save connection' }).click();
        await expect(card.getByText(`Input: ${oltCode} · PON 5`)).toBeVisible();
    });

    const afterSplitter = await odpCounts(page);
    expect(afterSplitter.capacity).toBe(before.capacity + 8);
    expect(afterSplitter.used).toBe(before.used);

    await test.step('onboard a customer and choose a free port on that ODP', async () => {
        await page.goto('/admin/customers/create');
        await page.getByLabel('Code').fill(customerCode);
        await page.getByLabel('Name').fill(customerName);
        await page.getByLabel('Email').fill(`${customerCode.toLowerCase()}@example.test`);
        await page.getByLabel('Phone').fill('0800000000');

        await page.getByRole('tab', { name: /Addresses \(\d+\)/ }).click();
        await page.getByLabel('Label').fill(`E2E ODN address ${suffix}`);
        await page.getByLabel('Address *', { exact: true }).fill('9 E2E Fiber Street');
        await page.getByLabel('Province *', { exact: true }).click();
        await page
            .getByRole('button', { name: /jakarta/i })
            .first()
            .click();
        await page.getByLabel('City / Regency *', { exact: true }).click();
        await page
            .getByRole('button', { name: /jakarta pusat/i })
            .first()
            .click();
        await page.getByLabel('District *', { exact: true }).click();
        await page
            .getByRole('button', { name: /menteng/i })
            .first()
            .click();
        await page.getByLabel('Village / Subdistrict *', { exact: true }).click();
        await page
            .getByRole('button', { name: /menteng/i })
            .first()
            .click();
        await page.getByRole('button', { name: 'Lat / Long' }).click();
        await page.getByLabel('Latitude', { exact: true }).fill('-6.2100000');
        await page.getByLabel('Longitude', { exact: true }).fill('106.8200000');
        await page.getByRole('button', { name: 'Find' }).click();

        await page.getByRole('tab', { name: /Contacts \(\d+\)/ }).click();
        await page.getByLabel('Name').fill(`${customerName} Contact`);
        await page.getByLabel('Phone').fill('081200000000');
        await page.getByLabel('Email').fill(`contact.${customerCode.toLowerCase()}@example.test`);

        await page.getByRole('tab', { name: 'Subscription & SPK' }).click();
        await page.getByLabel('Service Package').selectOption({ index: 1 });

        // No serving POP yet: the picker waits for one.
        const ports = page.getByLabel('Drop port (ODP)');
        await expect(ports).toBeDisabled();
        await expect(
            page.getByText('Choose the serving POP to list its free ports.'),
        ).toBeVisible();

        const popSelect = page.getByLabel('Serving POP / Location');
        const popValue = await popSelect
            .locator('option')
            .filter({ hasText: popName })
            .first()
            .getAttribute('value');
        expect(popValue).toBeTruthy();
        await popSelect.selectOption(popValue!);

        // The ports under that POP arrive by a partial reload; only free ports of an ODP are offered.
        await expect(ports).toBeEnabled();
        await expect(ports.locator('option').filter({ hasText: portChoice })).toHaveCount(1);
        await ports.selectOption({ label: portChoice });
        await expect(ports).not.toHaveValue('');

        // Changing the POP drops the chosen port; choosing the POP again lists the ports again.
        await popSelect.selectOption('');
        await expect(ports).toBeDisabled();
        await popSelect.selectOption(popValue!);
        await expect(ports).toBeEnabled();
        await expect(ports).toHaveValue('');
        await ports.selectOption({ label: portChoice });

        await page.getByRole('tab', { name: 'Customer Data' }).click();
        await page.getByRole('button', { name: 'Create Customer & SPK' }).click();
        await expect(page).toHaveURL(/\/admin\/customers\/\d+$/);
        customerId = page.url().match(/\/admin\/customers\/(\d+)$/)![1];
    });

    await test.step('the ODN overview shows the ODP one port fuller', async () => {
        const after = await odpCounts(page);
        expect(after.capacity).toBe(afterSplitter.capacity);
        expect(after.used).toBe(afterSplitter.used + 1);
        expect(after.free).toBe(afterSplitter.free - 1);
    });

    let subscriptionUrl = '';

    await test.step('the subscription page and the installation SPK show the reserved port', async () => {
        await page.goto(`/admin/customers/${customerId}/subscriptions`);
        await page
            .getByRole('row')
            .filter({ hasText: 'pending' })
            .getByRole('link', { name: 'Show' })
            .click();
        await expect(page.getByRole('heading', { name: 'Drop port' })).toBeVisible();
        await expect(page.getByText('Port 1 on')).toBeVisible();
        await expect(page.getByRole('link', { name: odpCode })).toBeVisible();
        subscriptionUrl = page.url();

        const workOrders = (
            await inertiaProps(page, `/admin/spk?search=${encodeURIComponent(customerName)}`)
        ).props?.workOrders?.data as Array<{ id: number; type: string }> | undefined;
        const spk = workOrders?.find((row) => row.type === 'installation');
        expect(spk, 'installation SPK of the new customer').toBeTruthy();

        await page.goto(`/admin/spk/${spk!.id}`);
        await expect(page.getByText('Drop port:')).toBeVisible();
        await expect(page.getByRole('link', { name: odpCode })).toBeVisible();
    });

    await test.step('activate then terminate: the drop port is free again', async () => {
        await page.goto(subscriptionUrl);
        await page.getByRole('button', { name: 'Activate' }).click();
        await expect(page.getByRole('button', { name: 'Suspend' })).toBeVisible();

        await page.getByRole('button', { name: 'Terminate', exact: true }).click();
        const dialog = page.getByRole('dialog', { name: 'Terminate Subscription' });
        await dialog.getByLabel('Reason').fill('E2E ODN lifecycle');
        await dialog.getByRole('button', { name: 'Terminate' }).click();
        await expect(dialog).toBeHidden();

        await expect(page.getByText('No drop port recorded.')).toBeVisible();
        await expect(page.getByText('Dynamic (assigned by router)')).toBeVisible();

        const freed = await odpCounts(page);
        expect(freed.used).toBe(afterSplitter.used);
        expect(freed.free).toBe(afterSplitter.free);
    });
});

test('@ipam-odn staff sees the menu entries but no manage control', async ({ page }) => {
    await loginAs(page, 'staff');

    const group = await openNetworkGroup(page);
    await expect(group.getByRole('link', { name: 'Alamat IP' })).toBeVisible();
    await expect(group.getByRole('link', { name: 'ODN' })).toBeVisible();

    await group.getByRole('link', { name: 'Alamat IP' }).click();
    await expect(page.getByRole('main').getByRole('heading', { name: 'Alamat IP' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'New prefix' })).toHaveCount(0);

    await page.getByRole('link', { name: '10.99.0.0/24', exact: true }).click();
    await expect(
        page.getByRole('main').getByRole('heading', { name: '10.99.0.0/24' }),
    ).toBeVisible();
    for (const name of ['Edit', 'Delete', 'Reserve address']) {
        await expect(page.getByRole('button', { name, exact: true })).toHaveCount(0);
    }
    await expect(page.getByRole('button', { name: /^Release / })).toHaveCount(0);

    await page.goto('/admin/ipam/vlans');
    await expect(page.getByRole('main').getByRole('heading', { name: 'VLANs' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'New VLAN' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^(Edit|Delete) VLAN/ })).toHaveCount(0);

    await page.goto(`/admin/odn/assets/${await odnAssetId(page, 'odp', odpCode)}`);
    await expect(page.getByRole('main').getByRole('heading', { name: odpCode })).toBeVisible();
    await expect(page.getByRole('group', { name: /^Port \d+, free$/ }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add splitter' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^Delete splitter/ })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Save connection' })).toHaveCount(0);

    await page.goto(`/admin/odn/assets/${await odnAssetId(page, 'olt', oltCode)}`);
    await expect(page.getByRole('main').getByRole('heading', { name: oltCode })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add PON ports' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^Delete PON/ })).toHaveCount(0);

    // Staff holds odn.assign but not ipam.assign: the drop control stays, the IP control goes.
    await page.goto(`/admin/subscriptions/${await linkedSubscriptionId(page)}`);
    await expect(page.getByRole('heading', { name: 'IP address' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Assign static IP' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Set drop port' })).toBeVisible();
});

test('@ipam-odn technician reads the registers and cannot change them', async ({ page }) => {
    await loginAs(page, 'technician');

    await page.goto('/admin/ipam/prefixes');
    await expect(page.getByRole('main').getByRole('heading', { name: 'Alamat IP' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'New prefix' })).toHaveCount(0);

    await page.goto(`/admin/odn/assets/${await odnAssetId(page, 'odp', odpCode)}`);
    await expect(page.getByRole('main').getByRole('heading', { name: odpCode })).toBeVisible();
    await expect(page.getByRole('group', { name: /^Port \d+, free$/ }).first()).toBeVisible();
    await expect(
        page.getByRole('button', {
            name: /^(Add splitter|Save connection|Delete splitter|Release)/,
        }),
    ).toHaveCount(0);
});

/**
 * Role x route matrix. Writes are sent with an empty body: a role that may write passes authorization
 * and gets 422 from validation, a role that may not gets 403 before validation. Nothing is stored.
 */
test('@ipam-odn role matrix: who may read, who may write', async ({ page }) => {
    test.setTimeout(180_000);

    type Row = Record<DemoRole, number>;
    const read: Row = { admin: 200, manager: 200, staff: 200, technician: 200, customer: 403 };
    const manage: Row = { admin: 422, manager: 422, staff: 403, technician: 403, customer: 403 };
    const assignDrop: Row = {
        admin: 422,
        manager: 422,
        staff: 422,
        technician: 403,
        customer: 403,
    };

    await loginAs(page, 'admin');
    const subscriptionId = await linkedSubscriptionId(page);
    const odpId = await odnAssetId(page, 'odp', odpCode);
    const odp = (await inertiaProps(page, `/admin/odn/assets/${odpId}`)).props as {
        splitters: Array<{ ports: Array<{ id: number }> }>;
    };
    const portId = odp.splitters[0].ports[0].id;

    const reads = [
        '/admin/ipam/prefixes',
        '/admin/ipam/vlans',
        '/admin/odn',
        `/admin/odn/assets/${odpId}`,
    ];
    const writes: Array<{ url: string; expected: Row }> = [
        { url: '/admin/ipam/prefixes', expected: manage },
        { url: '/admin/ipam/vlans', expected: manage },
        { url: `/admin/odn/assets/${odpId}/pon-ports`, expected: manage },
        { url: `/admin/odn/assets/${odpId}/splitters`, expected: manage },
        // A subscription's static address is the NOC's call: ipam.assign, held by admin and manager only.
        { url: `/admin/ipam/subscriptions/${subscriptionId}/addresses`, expected: manage },
        // A drop port is recorded by whoever onboards customers: odn.assign, which staff holds too.
        { url: `/admin/odn/ports/${portId}/drop`, expected: assignDrop },
    ];

    for (const role of ['admin', 'manager', 'staff', 'technician', 'customer'] as DemoRole[]) {
        await logout(page);
        await loginAs(page, role);
        const token = await refreshSession(page, '/admin/ipam/prefixes');

        for (const url of reads) {
            expect((await page.request.get(url)).status(), `${role} GET ${url}`).toBe(read[role]);
        }

        for (const { url, expected } of writes) {
            const response = await page.request.post(url, {
                headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
                data: {},
            });
            expect(response.status(), `${role} POST ${url}`).toBe(expected[role]);
        }
    }
});
