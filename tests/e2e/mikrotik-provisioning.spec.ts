/**
 * @lifecycle MikroTik provisioning UI (the router is faked: it is never reached)
 *
 * .env.e2e leaves ROUTEROS_PROVISIONING_ENABLED off, so every server-side router call is skipped and the
 * pages show the honest state: "never checked", accounts "pending" with the switch as the reason. This
 * spec drives the UI states only; the engine against a fake router is covered by tests/Feature/NetworkAsset.
 *
 * Flow: admin registers the unregistered fixture router E2E-ROUTER-001 (writes off) -> router page (never
 * checked, isolir URL, switch banner) -> edit keeps the stored password -> the network account is created
 * on E2E-LINKED-SUB with a generated password shown once -> reveal shows it again -> Receivables "Isolir"
 * sends source "overdue" -> the account page shows "Isolated" and "Pending" -> the public isolir page ->
 * UI gates and read-only role -> layouts at 375 and 1280 px in both themes. The last test reactivates the
 * subscription so the shared fixture is back as other specs expect it.
 *
 * Fake credentials only; nothing here is a real router address, API secret or subscriber password.
 */
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { inertiaProps, loginAs, logout } from './support/demo';

const ROUTER_ASSET = 'E2E-ROUTER-001';
const REGISTERED_ROUTER_ASSET = 'E2E-ROUTER-002';
const SUBSCRIPTION = 'E2E-LINKED-SUB';
const SWITCH_OFF_REASON = 'Router provisioning is switched off for this installation.';
const ORIGIN = 'http://127.0.0.1:8010';

const state: { routerId: string; subscriptionId: number; isolirUrl: string; password: string } = {
    routerId: '',
    subscriptionId: 0,
    isolirUrl: '',
    password: '',
};

const mainOf = (page: Page) => page.getByRole('main');

/** The `<dd>` that belongs to a `<dt>` label (the detail lists of the router and account pages). */
const detail = (page: Page, label: string) =>
    page
        .locator('dt')
        .filter({ hasText: new RegExp(`^${label}$`) })
        .locator('xpath=following-sibling::dd');

const passwordPanel = (page: Page) =>
    page.getByRole('alert').filter({ hasText: 'Password shown once' });

async function subscriptionIdOf(page: Page, code: string) {
    const data = await inertiaProps(page, '/admin/billing/receivables?per_page=25');
    const rows = data.props?.rows?.data as
        Array<{ subscriptions: Array<{ id: number; code: string }> }> | undefined;
    const found = rows?.flatMap((row) => row.subscriptions).find((item) => item.code === code);
    if (!found) throw new Error(`${code} is not on the receivables page`);

    return found.id;
}

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

/** Tab from the page start until `target` has focus, then prove the ring is visible. */
async function reachByKeyboard(page: Page, target: Locator, maxPresses = 150) {
    await page.locator('body').click({ position: { x: 1, y: 1 } });
    for (let press = 0; press < maxPresses; press += 1) {
        await page.keyboard.press('Tab');
        if (await target.evaluate((element) => element === document.activeElement)) {
            const ring = await target.evaluate((element) => {
                const style = getComputedStyle(element);
                return (
                    (style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0) ||
                    (style.boxShadow !== 'none' && style.boxShadow !== '')
                );
            });
            expect(ring, 'the focused control shows a visible ring').toBe(true);
            return;
        }
    }
    throw new Error('Keyboard focus never reached the control');
}

test.describe('@lifecycle mikrotik provisioning', () => {
    test.describe.configure({ mode: 'serial' });

    test('admin registers the unregistered router asset as a NAS router with writes off', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        state.subscriptionId = await subscriptionIdOf(page, SUBSCRIPTION);

        await page.goto('/admin/nas-devices');
        await expect(mainOf(page).getByRole('heading', { name: 'NAS Routers' })).toBeVisible();
        const fixture = page.getByRole('row').filter({ hasText: REGISTERED_ROUTER_ASSET });
        await expect(fixture).toContainText('Writes off');
        await expect(fixture).toContainText('Never checked');
        await expect(page.getByRole('row').filter({ hasText: ROUTER_ASSET })).toHaveCount(0);

        await page.getByLabel('Search').fill('10.99.9.2');
        await expect(
            page.getByRole('row').filter({ hasText: REGISTERED_ROUTER_ASSET }),
        ).toHaveCount(1);
        await page.getByLabel('Search').fill('no-such-router');
        await expect(page.getByText('No NAS routers match your search')).toBeVisible();
        await page.getByLabel('Search').fill('');

        await page.getByRole('link', { name: 'Register router' }).click();
        await expect(
            mainOf(page).getByRole('heading', { name: 'Register NAS router' }),
        ).toBeVisible();

        const asset = page.getByLabel('Router asset');
        // A router that is already registered is not offered again.
        await expect(asset.locator('option', { hasText: REGISTERED_ROUTER_ASSET })).toHaveCount(0);
        const assetValue = await asset
            .locator('option', { hasText: ROUTER_ASSET })
            .getAttribute('value');
        expect(assetValue).toBeTruthy();
        await asset.selectOption(assetValue!);

        await expect(page.getByLabel('API port')).toHaveValue('443');
        await expect(page.getByLabel('Isolir profile')).toHaveValue('isolir');
        await expect(page.getByLabel('API password')).toHaveAttribute('type', 'password');
        const writes = page.getByRole('switch', { name: 'Allow Inbils to write to this router' });
        await expect(writes).toHaveAttribute('aria-checked', 'false');
        await expect(page.getByText(/Writes change the router/)).toBeVisible();

        await page.getByLabel('API username').fill('e2e-api');
        await page.getByLabel('API password').fill('e2e-fake-router-secret');
        // A server-side rule shows inline and keeps what was typed.
        await page.getByLabel('Isolir profile').fill('bad profile!');
        await page.getByRole('button', { name: 'Register router' }).click();
        await expect(
            page.getByText('Use only letters, digits, dots, dashes and underscores'),
        ).toBeVisible();
        await expect(page.getByLabel('API username')).toHaveValue('e2e-api');

        await page.getByLabel('Isolir profile').fill('isolir');
        await page.getByRole('button', { name: 'Register router' }).click();
        await expect(page).toHaveURL(/\/admin\/nas-devices\/\d+$/);
        state.routerId = page.url().match(/\/admin\/nas-devices\/(\d+)$/)![1];
        await expect(
            mainOf(page).getByRole('heading', { name: `NAS router ${ROUTER_ASSET}` }),
        ).toBeVisible();
    });

    test('the router page shows never checked, the isolir URL and the switch banner', async ({
        page,
        context,
    }) => {
        await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: ORIGIN });
        await loginAs(page, 'admin');
        await page.goto(`/admin/nas-devices/${state.routerId}`);

        await expect(detail(page, 'Reachability')).toContainText('Never checked');
        await expect(detail(page, 'Writes')).toContainText('Writes off');
        await expect(detail(page, 'Last check')).toHaveText('Never');
        await expect(detail(page, 'Management address')).toHaveText('10.99.9.1:443');
        await expect(detail(page, 'API user')).toHaveText('e2e-api');
        await expect(
            page.getByText(
                'Provisioning is switched off for this installation. Nothing is sent to routers, so accounts stay pending.',
            ),
        ).toBeVisible();
        // The API password is write-only: no page shows it.
        await expect(page.getByText('e2e-fake-router-secret')).toHaveCount(0);

        const isolir = page.locator('code').filter({ hasText: '/isolir/' });
        state.isolirUrl = (await isolir.innerText()).trim();
        expect(state.isolirUrl).toMatch(/\/isolir\/DEMO$/);
        await page.getByRole('button', { name: 'Copy URL' }).click();
        await expect(page.getByRole('button', { name: 'Copied' })).toBeVisible();
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(state.isolirUrl);

        await expect(page.getByText('No network accounts on this router')).toBeVisible();
        await expect(page.getByText('Not checked yet.')).toBeVisible();

        await page.getByRole('button', { name: 'Check now' }).click();
        await expect(page.getByText('Router check queued.')).toBeVisible();
        // The installation switch is off, so the check reads nothing.
        await expect(detail(page, 'Reachability')).toContainText('Never checked');

        // Removing asks first and can be cancelled.
        await page.getByRole('button', { name: 'Remove router' }).click();
        const dialog = page.getByRole('alertdialog');
        await expect(dialog.getByText('Remove this NAS router?')).toBeVisible();
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toHaveCount(0);
    });

    test('edit keeps the stored API password and saves the change', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/nas-devices/${state.routerId}`);
        await page.getByRole('link', { name: 'Edit', exact: true }).click();
        await expect(mainOf(page).getByRole('heading', { name: 'Edit NAS router' })).toBeVisible();

        const password = page.getByLabel('API password');
        await expect(password).toHaveValue('');
        await expect(password).toHaveAttribute('placeholder', 'Stored');
        await expect(page.getByText('A password is stored. Leave blank to keep it.')).toBeVisible();
        await expect(page.getByLabel('API username')).toHaveValue('e2e-api');

        await page.getByLabel('Isolir profile').fill('isolir-e2e');
        await page.getByRole('button', { name: 'Save router' }).click();
        await expect(page).toHaveURL(new RegExp(`/admin/nas-devices/${state.routerId}$`));
        await expect(page.getByText('NAS router updated.')).toBeVisible();
        await expect(detail(page, 'Isolir profile')).toHaveText('isolir-e2e');
    });

    test('the subscription links to Network access and the account is created with a generated password shown once', async ({
        page,
        context,
    }) => {
        await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: ORIGIN });
        await loginAs(page, 'admin');
        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await page.getByRole('link', { name: 'Network access' }).click();
        await expect(page).toHaveURL(
            new RegExp(`/admin/subscriptions/${state.subscriptionId}/network-account$`),
        );
        await expect(mainOf(page).getByRole('heading', { name: 'Network access' })).toBeVisible();
        await expect(page.getByText(`Subscription ${SUBSCRIPTION}`).first()).toBeVisible();
        await expect(
            page.getByText(
                'Provisioning is switched off for this installation. Changes are saved but not sent to routers.',
            ),
        ).toBeVisible();
        await expect(detail(page, 'Desired state')).toContainText('Enabled');
        await expect(detail(page, 'Speed profile')).toContainText('router profile');

        // The router at the subscription's POP is suggested and preselected.
        const router = page.getByLabel('NAS router');
        await expect(router.locator('option:checked')).toContainText(ROUTER_ASSET);
        await expect(router.locator('option:checked')).toContainText('(suggested: same POP)');

        // A username the server rejects shows inline.
        await page.getByLabel('Username').fill('x!');
        await page.getByRole('button', { name: 'Create network account' }).click();
        await expect(
            page.getByText('Use 3 to 64 letters, digits, dots, dashes, underscores or @.'),
        ).toBeVisible();

        await page.getByLabel('Username').fill('e2e-linked');
        await expect(page.getByLabel('Password')).toHaveValue('');
        await page.getByRole('button', { name: 'Create network account' }).click();

        const panel = passwordPanel(page);
        await expect(panel).toBeVisible();
        state.password = (await panel.locator('code').innerText()).trim();
        expect(state.password).toHaveLength(12);
        await expect(panel).toContainText('It is not kept after you dismiss this message');

        await panel.getByRole('button', { name: 'Copy password' }).click();
        await expect(panel.getByRole('button', { name: 'Copied' })).toBeVisible();
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(state.password);

        // The account exists now and is pending, with the switch as the reason.
        await expect(
            mainOf(page).getByRole('heading', { name: 'Network account', exact: true }),
        ).toBeVisible();
        await expect(detail(page, 'Username').first()).toHaveText('e2e-linked');
        await expect(detail(page, 'Sync status')).toContainText('Pending');
        await expect(detail(page, 'Sync status')).toContainText(SWITCH_OFF_REASON);
        await expect(detail(page, 'Provisioned profile')).toHaveText('Not on the router yet');
        await expect(page.getByRole('heading', { name: 'Create network account' })).toHaveCount(0);

        // Dismissing removes it from the page, storage, history and URL.
        await panel.getByRole('button', { name: 'Dismiss' }).click();
        await expect(passwordPanel(page)).toHaveCount(0);
        await expect(page.getByText(state.password)).toHaveCount(0);
        const kept = await page.evaluate(() =>
            JSON.stringify([{ ...localStorage }, { ...sessionStorage }, window.history.state]),
        );
        expect(kept).not.toContain(state.password);
        expect(page.url()).not.toContain(state.password);

        // Nor does a reload bring it back.
        await page.reload();
        await expect(passwordPanel(page)).toHaveCount(0);
        await expect(page.getByText(state.password)).toHaveCount(0);
        await expect(page.getByLabel('Password')).toHaveValue('');
    });

    test('reveal asks for confirmation, shows the password again once, and the edit form never carries it', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);

        const reveal = page.getByRole('button', { name: 'Reveal password' });
        await reachByKeyboard(page, reveal);

        await reveal.click();
        const dialog = page.getByRole('alertdialog');
        await expect(dialog.getByText('Reveal password?')).toBeVisible();
        await expect(dialog).toContainText('recorded in the audit log');
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toHaveCount(0);
        await expect(passwordPanel(page)).toHaveCount(0);

        await reveal.click();
        await dialog.getByRole('button', { name: 'Reveal', exact: true }).click();
        const panel = passwordPanel(page);
        await expect(panel).toBeVisible();
        await expect(panel.locator('code')).toHaveText(state.password);
        await expect(dialog).toHaveCount(0);
        await panel.getByRole('button', { name: 'Dismiss' }).click();
        await expect(page.getByText(state.password)).toHaveCount(0);

        // The edit form starts with the stored values and a blank password.
        await expect(page.getByLabel('Username')).toHaveValue('e2e-linked');
        await expect(page.getByLabel('Password')).toHaveValue('');
        await expect(page.getByText('Leave blank to keep the current password.')).toBeVisible();

        // Renaming keeps the password: no new one is shown.
        await page.getByLabel('Username').fill('e2e-linked-2');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Network account updated.')).toBeVisible();
        await expect(detail(page, 'Username').first()).toHaveText('e2e-linked-2');
        await expect(passwordPanel(page)).toHaveCount(0);

        await page.getByRole('button', { name: 'Resync' }).click();
        await expect(page.getByText('Resync queued.')).toBeVisible();
    });

    test('Receivables isolir sends source overdue and the account page shows isolated and pending', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/billing/receivables?per_page=25');
        const row = page.getByRole('row').filter({ hasText: 'E2E Linked Customer' });
        await row.getByRole('button', { name: `Isolir ${SUBSCRIPTION}` }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Alasan isolir (wajib)').fill('E2E overdue isolir');

        const request = page.waitForRequest(
            (candidate) => candidate.method() === 'POST' && candidate.url().endsWith('/suspend'),
        );
        await dialog.getByRole('button', { name: 'Isolir', exact: true }).click();
        expect((await request).postDataJSON()).toMatchObject({
            reason: 'E2E overdue isolir',
            source: 'overdue',
        });
        await expect(dialog).toHaveCount(0);

        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);
        await expect(detail(page, 'Status').first()).toContainText('suspended');
        await expect(detail(page, 'Desired state')).toContainText('Isolated');
        await expect(detail(page, 'Desired state')).toContainText('isolir profile');
        await expect(detail(page, 'Sync status')).toContainText('Pending');
        await expect(detail(page, 'Sync status')).toContainText(SWITCH_OFF_REASON);
        await expect(detail(page, 'Provisioned profile')).toHaveText('Not on the router yet');
    });

    test('the router page lists the account with its state', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/nas-devices/${state.routerId}`);
        const row = page.getByRole('row').filter({ hasText: SUBSCRIPTION });
        await expect(row).toContainText('E2E Linked Customer');
        await expect(row).toContainText('PPPoE');
        await expect(row).toContainText('e2e-linked-2');
        await expect(row).toContainText('Pending');
        await expect(row).toContainText('Offline');
        // With an account the router cannot be removed.
        await expect(page.getByRole('button', { name: 'Remove router' })).toBeDisabled();
        await expect(
            page.getByText('Network accounts must be moved to another router first.'),
        ).toBeVisible();
        await row.getByRole('link', { name: SUBSCRIPTION }).click();
        await expect(page).toHaveURL(
            new RegExp(`/admin/subscriptions/${state.subscriptionId}/network-account$`),
        );
    });

    test('the public isolir page shows the company, its contacts and the login link only', async ({
        page,
    }) => {
        test.setTimeout(90_000);
        // Give the company contact fields through the profile form, as staff would.
        await loginAs(page, 'admin');
        await page.goto('/admin/company/profile');
        await page.getByLabel('Phone').fill('(021)5550100');
        await page.getByLabel('Email').fill('support@demo.inbils.test');
        await page.getByLabel('Website').fill('https://demo.inbils.test');
        await page.getByRole('button', { name: 'Save Profile' }).click();
        await expect(page.getByText('Company profile updated.').first()).toBeVisible();

        await logout(page);
        const response = await page.goto(state.isolirUrl);
        expect(response?.status()).toBe(200);
        await expect(page).toHaveTitle(/Layanan diisolir/);
        await expect(
            page.getByRole('heading', { name: 'Layanan internet Anda sedang diisolir', level: 1 }),
        ).toBeVisible();
        await expect(page.getByText('Inbils Demo Company').first()).toBeVisible();
        await expect(page.getByText('karena ada tagihan yang belum dibayar')).toBeVisible();

        const login = page.getByRole('link', { name: 'Masuk untuk melihat dan membayar tagihan' });
        await expect(login).toHaveAttribute('href', /\/login$/);
        const box = await login.boundingBox();
        expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);

        await expect(page.getByRole('link', { name: '(021)5550100' })).toHaveAttribute(
            'href',
            'tel:0215550100',
        );
        await expect(page.getByRole('link', { name: 'support@demo.inbils.test' })).toHaveAttribute(
            'href',
            'mailto:support@demo.inbils.test',
        );
        const website = page.getByRole('link', { name: 'https://demo.inbils.test' });
        await expect(website).toHaveAttribute('rel', /noopener/);
        await expect(website).toHaveAttribute('target', '_blank');

        // Public means public: no admin shell, nothing about a subscriber, a username or a router.
        await expect(page.getByRole('navigation')).toHaveCount(0);
        const text = (await page.locator('body').innerText()).toLowerCase();
        for (const secret of ['e2e-linked', 'e2e linked customer', '10.99.9', state.password]) {
            expect(text).not.toContain(secret.toLowerCase());
        }
        expect((await page.request.get('/isolir/NO-SUCH-COMPANY')).status()).toBe(404);

        // Restore the shared fixture: the profile had no contact fields.
        await loginAs(page, 'admin');
        await page.goto('/admin/company/profile');
        await page.getByLabel('Phone').fill('');
        await page.getByLabel('Email').fill('');
        await page.getByLabel('Website').fill('');
        await page.getByRole('button', { name: 'Save Profile' }).click();
        await expect(page.getByText('Company profile updated.').first()).toBeVisible();
    });

    test('a manager reads routers and accounts but cannot manage them', async ({ page }) => {
        await loginAs(page, 'manager');
        await page.goto('/admin/nas-devices');
        await expect(mainOf(page).getByRole('heading', { name: 'NAS Routers' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Register router' })).toHaveCount(0);
        await page.goto(`/admin/nas-devices/${state.routerId}`);
        await expect(
            mainOf(page).getByRole('heading', { name: `NAS router ${ROUTER_ASSET}` }),
        ).toBeVisible();
        for (const name of ['Edit', 'Check now', 'Remove router']) {
            await expect(page.getByRole('button', { name })).toHaveCount(0);
            await expect(page.getByRole('link', { name, exact: true })).toHaveCount(0);
        }
        // Managing is refused by the server, not just hidden.
        expect((await page.request.get('/admin/nas-devices/create')).status()).toBe(403);

        // Managers hold the account permissions: they can reveal.
        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);
        await expect(page.getByRole('button', { name: 'Reveal password' })).toBeVisible();
    });

    test('the Network access link and the NAS Routers menu item follow the permissions', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await expect(page.getByRole('link', { name: 'Network access' })).toBeVisible();
        // The item sits in a collapsed sidebar group; open it to see the link.
        const navLink = page.locator('aside a', { hasText: 'NAS Routers' });
        await expect(navLink).toHaveCount(1);
        await page
            .locator('aside details')
            .filter({ has: page.locator('summary', { hasText: 'Network/Work Orders' }) })
            .locator('summary')
            .click();
        await expect(navLink).toBeVisible();

        // The server stays authoritative; this proves the UI gates read the shared permissions: the same
        // pages are fetched again with the two permissions renamed in the props.
        await page.route('**/admin/**', async (route) => {
            const request = route.request();
            if (request.resourceType() !== 'document' && !request.headers()['x-inertia']) {
                return route.continue();
            }
            const response = await route.fetch();
            const body = (await response.text())
                .replaceAll('network_asset.account.view', 'network_asset.account.view-off')
                .replaceAll('network_asset.nas.view', 'network_asset.nas.view-off');
            return route.fulfill({
                response,
                body,
                headers: {
                    ...response.headers(),
                    'content-length': String(Buffer.byteLength(body)),
                },
            });
        });
        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await expect(
            mainOf(page).getByRole('heading', { name: `Subscription ${SUBSCRIPTION}` }),
        ).toBeVisible();
        await expect(page.getByRole('link', { name: 'Network access' })).toHaveCount(0);
        await expect(page.locator('aside a', { hasText: 'NAS Routers' })).toHaveCount(0);
        await page.unroute('**/admin/**');
    });

    test('layouts hold at 375 and 1280 px in both themes without horizontal scrolling', async ({
        page,
    }) => {
        test.setTimeout(180_000);
        await loginAs(page, 'admin');
        const paths = [
            '/admin/nas-devices',
            `/admin/nas-devices/${state.routerId}`,
            `/admin/nas-devices/${state.routerId}/edit`,
            `/admin/subscriptions/${state.subscriptionId}/network-account`,
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

        await page.goto('/admin/nas-devices/create');
        await setTheme(page, 375, false);
        await expectNoHorizontalOverflow(page, 'register form at 375px');

        await logout(page);
        for (const width of [375, 1280]) {
            for (const dark of [false, true]) {
                await page.goto(state.isolirUrl);
                await setTheme(page, width, dark);
                await expect(
                    page.getByRole('link', { name: 'Masuk untuk melihat dan membayar tagihan' }),
                ).toBeVisible();
                await expectNoHorizontalOverflow(
                    page,
                    `isolir page at ${width}px ${dark ? 'dark' : 'light'}`,
                );
            }
        }
        await page.evaluate(() => localStorage.setItem('darkMode', 'false'));
    });

    test('cleanup: the subscription is reactivated for the specs that follow', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await page.getByRole('button', { name: 'Reactivate' }).click();
        await expect(page.getByRole('button', { name: 'Suspend' })).toBeVisible();
        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);
        await expect(detail(page, 'Desired state')).toContainText('Enabled');
    });
});
