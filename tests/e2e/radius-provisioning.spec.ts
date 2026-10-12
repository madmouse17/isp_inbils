/**
 * @lifecycle FreeRADIUS provisioning and subscription block/unblock UI
 *
 * Two describes in one file, picked by E2E_RADIUS_LAB:
 *
 * 1. CI (default, RADIUS_ENABLED off in .env.e2e): every server-side RADIUS call is skipped, so the pages show
 *    the honest state ("Waiting to be written", accounts "Pending" with the switch as the reason). The engine
 *    against a fake RADIUS database is covered by tests/Feature/NetworkAsset; this drives the UI.
 *    Flow: register NAS (secret never shown again) -> router page -> edit keeps the stored secret -> account
 *    with realm suffix and one-time secret -> reveal -> Block/Unblock on Subscriptions/Show -> Block/Unblock
 *    next to Isolir on Receivables -> public isolir page -> role gates (403 from the server) -> layouts at
 *    375 and 1280 px in both themes. The last test reactivates the subscription for the specs that follow.
 *
 * 2. Lab (opt-in, `E2E_RADIUS_LAB=1`): the real wire against ~/labs/freeradius (FreeRADIUS + nas-sim).
 *    Preconditions:
 *      - lab up: `docker compose ps` in ~/labs/freeradius shows radlab-freeradius, radlab-db, radlab-nas-sim healthy;
 *      - light reset from the lab README (and no leftover `e2e-lab@demo` rows in the lab database);
 *      - the served app needs RADIUS_ENABLED=true and RADIUS_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD of the lab (host
 *        127.0.0.1, port 13306) plus QUEUE_CONNECTION=sync (already in .env.e2e). The runner below exports them from
 *        ~/labs/freeradius/.env, never printing them; scripts/e2e-server-env.mjs forwards them only while
 *        E2E_RADIUS_LAB=1. The local .env.e2e needs no RADIUS lines (without them the NAS page says "Not written");
 *      - `E2E_RADIUS_LAB=1` (set by the runner; the server env builder also allowlists LAB_RADIUS_SECRET and
 *        LAB_USER_SECRET) or the same line in .env.e2e;
 *      - LAB_RADIUS_SECRET (shared secret) and LAB_USER_SECRET (secret of the lab users) are read at run time from the
 *        process env, else from ~/labs/freeradius/.env (RADIUS_SECRET, LAB_USER_PASSWORD), typed on steps with
 *        trace/screenshot/video off and never logged.
 *    Run: scripts/e2e-radius-lab.sh   (docker context `default`: the lab runs under the root dockerd, not Docker Desktop)
 *
 * Fake credentials only in this file; nothing here is a real router address, shared secret or subscriber secret.
 */
import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/fixtures';
import { inertiaProps, loginAs, logout } from './support/demo';

const LAB_DIR = join(homedir(), 'labs', 'freeradius');

/** The first `KEY=value` line of an env-style file, or null. Values are never logged. */
function envFileValue(file: string, key: string): string | null {
    if (!existsSync(file)) return null;
    const line = readFileSync(file, 'utf8')
        .split('\n')
        .find((candidate) => candidate.startsWith(`${key}=`));

    return line ? line.slice(key.length + 1).trim() : null;
}

// scripts/e2e-server-env.mjs allowlists E2E_RADIUS_LAB, LAB_RADIUS_SECRET and LAB_USER_SECRET; the switch is also read
// from the operator's local, gitignored .env.e2e (line `E2E_RADIUS_LAB=1`) as a fallback.
const LAB =
    process.env.E2E_RADIUS_LAB === '1' ||
    envFileValue(join(process.cwd(), '.env.e2e'), 'E2E_RADIUS_LAB') === '1';

// Secrets are typed on the lab pages: no trace, screenshot or video then (top level: `use` forces a worker).
if (LAB) test.use({ trace: 'off', screenshot: 'off', video: 'off' });

const ROUTER_ASSET = 'E2E-ROUTER-001';
const REGISTERED_ROUTER_ASSET = 'E2E-ROUTER-002';
const SUBSCRIPTION = 'E2E-LINKED-SUB';
const CUSTOMER = 'E2E Linked Customer';
const REALM = 'demo';
const SWITCH_OFF_REASON = 'RADIUS is switched off.';
const ORIGIN = 'http://127.0.0.1:8010';
const FAKE_NAS_SECRET = 'e2e-fake-nas-secret';

const state: {
    routerId: string;
    subscriptionId: number;
    isolirUrl: string;
    accountSecret: string;
} = { routerId: '', subscriptionId: 0, isolirUrl: '', accountSecret: '' };

const mainOf = (page: Page) => page.getByRole('main');

/** The `<dd>` that belongs to a `<dt>` label (the detail lists of the router and account pages). */
const detail = (page: Page, label: string) =>
    page
        .locator('dt')
        .filter({ hasText: new RegExp(`^${label}$`) })
        .locator('xpath=following-sibling::dd');

const secretPanel = (page: Page) =>
    page.getByRole('alert').filter({ hasText: 'Secret shown once' });

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

/** Block or unblock through the modal both pages share. */
async function submitBlockModal(page: Page, title: string, text: string, submit: string) {
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText(title)).toBeVisible();
    if (text) await dialog.locator('#subscription-block-reason').fill(text);
    await dialog.getByRole('button', { name: submit, exact: true }).click();
    await expect(dialog).toHaveCount(0);
}

test.describe('@lifecycle radius provisioning (switch off)', () => {
    test.skip(LAB, 'The lab run has RADIUS_ENABLED on; see the lab describe below.');
    test.describe.configure({ mode: 'serial' });

    test('admin registers the unregistered router asset as a NAS router with a secret that is never shown again', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        state.subscriptionId = await subscriptionIdOf(page, SUBSCRIPTION);

        await page.goto('/admin/nas-devices');
        await expect(mainOf(page).getByRole('heading', { name: 'NAS Routers' })).toBeVisible();
        const fixture = page.getByRole('row').filter({ hasText: REGISTERED_ROUTER_ASSET });
        await expect(fixture).toContainText('10.99.9.2');
        await expect(fixture).toContainText('Waiting to be written');
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

        // The NAS address is prefilled from the management IP of the asset.
        await expect(page.getByLabel('NAS address')).toHaveValue('10.99.9.1');
        await expect(page.getByLabel('CoA port')).toHaveValue('1700');
        await expect(page.getByLabel('Realm')).toHaveValue(REALM);
        await expect(page.getByLabel('Realm')).toHaveAttribute('readonly', '');
        const secret = page.getByLabel('RADIUS secret');
        await expect(secret).toHaveAttribute('type', 'password');
        await expect(secret).toHaveValue('');

        // A server-side rule shows inline and keeps what was typed; the secret input is reset after submit.
        await page.getByLabel('NAS address').fill('10.99.9.2');
        await secret.fill(FAKE_NAS_SECRET);
        await page.getByRole('button', { name: 'Register router' }).click();
        await expect(page.getByText('This address is not available.')).toBeVisible();
        await expect(page.getByLabel('NAS address')).toHaveValue('10.99.9.2');
        await expect(secret).toHaveValue('');

        await page.getByLabel('NAS address').fill('10.99.9.1');
        await secret.fill('short');
        await page.getByRole('button', { name: 'Register router' }).click();
        await expect(page.getByText(/must be at least 8 characters/)).toBeVisible();

        await secret.fill(FAKE_NAS_SECRET);
        await page.getByRole('button', { name: 'Register router' }).click();
        await expect(page).toHaveURL(/\/admin\/nas-devices\/\d+$/);
        state.routerId = page.url().match(/\/admin\/nas-devices\/(\d+)$/)![1];
        await expect(
            mainOf(page).getByRole('heading', { name: `NAS router ${ROUTER_ASSET}` }),
        ).toBeVisible();
        await expect(page.getByText('NAS router registered.')).toBeVisible();
    });

    test('the router page shows the restart notice, the switch banner and the isolir URL, never the secret', async ({
        page,
        context,
    }) => {
        await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: ORIGIN });
        await loginAs(page, 'admin');
        await page.goto(`/admin/nas-devices/${state.routerId}`);

        // The row is saved, but nothing reached RADIUS: the page says so and why.
        await expect(detail(page, 'RADIUS client')).toContainText('Not written');
        await expect(detail(page, 'Last written')).toHaveText('Never');
        await expect(page.getByRole('alert').filter({ hasText: 'Last error' })).toContainText(
            SWITCH_OFF_REASON,
        );
        await expect(detail(page, 'NAS address')).toHaveText('10.99.9.1');
        await expect(detail(page, 'CoA target')).toHaveText('10.99.9.1:1700');
        await expect(detail(page, 'Realm')).toHaveText(REALM);
        await expect(detail(page, 'RADIUS secret')).toHaveText('Stored');
        await expect(
            page.getByText(
                'RADIUS is switched off for this installation. Nothing is written to FreeRADIUS and no session is listed.',
            ),
        ).toBeVisible();
        await expect(page.getByText(/Restart FreeRADIUS after adding this NAS/)).toBeVisible();
        // The shared secret is write-only: no page shows it.
        await expect(page.getByText(FAKE_NAS_SECRET)).toHaveCount(0);
        await expect(page.getByText('Check now')).toHaveCount(0);

        const isolir = page.locator('code').filter({ hasText: '/isolir/' });
        state.isolirUrl = (await isolir.innerText()).trim();
        expect(state.isolirUrl).toMatch(/\/isolir\/DEMO$/);
        await page.getByRole('button', { name: 'Copy URL' }).click();
        await expect(page.getByRole('button', { name: 'Copied' })).toBeVisible();
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(state.isolirUrl);

        await expect(page.getByText('No open sessions')).toBeVisible();

        // Removing asks first and can be cancelled.
        await page.getByRole('button', { name: 'Remove router' }).click();
        const dialog = page.getByRole('alertdialog');
        await expect(dialog.getByText('Remove this NAS router?')).toBeVisible();
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toHaveCount(0);
    });

    test('edit keeps the stored secret and saves the change', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/nas-devices/${state.routerId}`);
        await page.getByRole('link', { name: 'Edit', exact: true }).click();
        await expect(mainOf(page).getByRole('heading', { name: 'Edit NAS router' })).toBeVisible();

        const secret = page.getByLabel('RADIUS secret');
        await expect(secret).toHaveValue('');
        await expect(secret).toHaveAttribute('placeholder', 'Stored');
        await expect(page.getByText('A secret is stored. Leave blank to keep it.')).toBeVisible();
        await expect(page.getByLabel('NAS address')).toHaveValue('10.99.9.1');

        await page.getByLabel('CoA port').fill('3799');
        await page.getByRole('button', { name: 'Save router' }).click();
        await expect(page).toHaveURL(new RegExp(`/admin/nas-devices/${state.routerId}$`));
        await expect(page.getByText('NAS router updated.')).toBeVisible();
        await expect(detail(page, 'CoA target')).toHaveText('10.99.9.1:3799');
        await expect(detail(page, 'RADIUS secret')).toHaveText('Stored');
    });

    test('the account is created with the realm suffix and a generated secret shown once', async ({
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
                'RADIUS is switched off for this installation. Changes are saved but not sent to FreeRADIUS, and online status and usage are not available.',
            ),
        ).toBeVisible();
        await expect(detail(page, 'Desired state')).toContainText('Enabled');
        await expect(detail(page, 'Speed profile')).toContainText('RADIUS group');
        // There is no NAS select: a subscriber may log in on any router of the company.
        await expect(page.getByLabel('NAS router')).toHaveCount(0);

        // The username field carries the fixed realm suffix; a username the server rejects shows inline.
        await expect(page.locator('#account-username')).toBeVisible();
        await expect(page.getByText(`@${REALM}`, { exact: true })).toBeVisible();
        await page.locator('#account-username').fill('X!');
        await page.getByRole('button', { name: 'Create network account' }).click();
        await expect(
            page.getByText('Use 3 to 40 lower-case letters, digits, dots, dashes or underscores.'),
        ).toBeVisible();

        await page.locator('#account-username').fill('e2e-linked');
        await expect(page.getByLabel('Secret')).toHaveValue('');
        await page.getByRole('button', { name: 'Create network account' }).click();

        const panel = secretPanel(page);
        await expect(panel).toBeVisible();
        state.accountSecret = (await panel.locator('code').innerText()).trim();
        expect(state.accountSecret).toHaveLength(12);
        await expect(panel).toContainText('It is not kept after you dismiss this message');

        await panel.getByRole('button', { name: 'Copy secret' }).click();
        await expect(panel.getByRole('button', { name: 'Copied' })).toBeVisible();
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(state.accountSecret);

        // The account exists now and is pending, with the switch as the reason.
        await expect(
            mainOf(page).getByRole('heading', { name: 'Network account', exact: true }),
        ).toBeVisible();
        await expect(detail(page, 'Username').first()).toHaveText(`e2e-linked@${REALM}`);
        await expect(detail(page, 'Sync status')).toContainText('Pending');
        await expect(detail(page, 'Sync status')).toContainText(SWITCH_OFF_REASON);
        await expect(detail(page, 'RADIUS group')).toHaveText('Not in RADIUS yet');
        await expect(detail(page, 'Online')).toHaveText('Offline');
        await expect(page.getByRole('heading', { name: 'Create network account' })).toHaveCount(0);

        // Dismissing removes it from the page, storage, history and URL.
        await panel.getByRole('button', { name: 'Dismiss' }).click();
        await expect(secretPanel(page)).toHaveCount(0);
        await expect(page.getByText(state.accountSecret)).toHaveCount(0);
        const kept = await page.evaluate(() =>
            JSON.stringify([{ ...localStorage }, { ...sessionStorage }, window.history.state]),
        );
        expect(kept).not.toContain(state.accountSecret);
        expect(page.url()).not.toContain(state.accountSecret);

        // Nor does a reload bring it back.
        await page.reload();
        await expect(secretPanel(page)).toHaveCount(0);
        await expect(page.getByText(state.accountSecret)).toHaveCount(0);
        await expect(page.getByLabel('Secret')).toHaveValue('');
    });

    test('reveal asks for confirmation, shows the secret again once, and the edit form never carries it', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);

        const reveal = page.getByRole('button', { name: 'Reveal secret' });
        await reachByKeyboard(page, reveal);

        await reveal.click();
        const dialog = page.getByRole('alertdialog');
        await expect(dialog.getByText('Reveal secret?')).toBeVisible();
        await expect(dialog).toContainText('recorded in the audit log');
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toHaveCount(0);
        await expect(secretPanel(page)).toHaveCount(0);

        await reveal.click();
        await dialog.getByRole('button', { name: 'Reveal', exact: true }).click();
        const panel = secretPanel(page);
        await expect(panel).toBeVisible();
        await expect(panel.locator('code')).toHaveText(state.accountSecret);
        await expect(dialog).toHaveCount(0);
        await panel.getByRole('button', { name: 'Dismiss' }).click();
        await expect(page.getByText(state.accountSecret)).toHaveCount(0);

        // The edit form starts with the stored local part and a blank secret.
        await expect(page.locator('#account-username')).toHaveValue('e2e-linked');
        await expect(page.getByLabel('Secret')).toHaveValue('');
        await expect(page.getByText('Leave blank to keep the current secret.')).toBeVisible();

        // Renaming keeps the secret: no new one is shown.
        await page.locator('#account-username').fill('e2e-linked-2');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Network account updated.')).toBeVisible();
        await expect(detail(page, 'Username').first()).toHaveText(`e2e-linked-2@${REALM}`);
        await expect(secretPanel(page)).toHaveCount(0);

        await page.getByRole('button', { name: 'Resync' }).click();
        await expect(page.getByText('Resync queued.')).toBeVisible();
    });

    test('Block and Unblock on Subscriptions need a reason, show the badge and isolate the account', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await expect(page.getByText('Blocked', { exact: true })).toHaveCount(0);

        // Opening by keyboard works and Cancel returns focus to the button.
        const block = page.getByRole('button', { name: 'Block internet', exact: true });
        await reachByKeyboard(page, block);
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByText(`Block internet of ${SUBSCRIPTION}?`)).toBeVisible();
        // The reason is required: nothing is sent while it is empty.
        const submit = dialog.getByRole('button', { name: 'Block internet', exact: true });
        await expect(submit).toBeDisabled();
        await dialog.locator('#subscription-block-reason').fill('   ');
        await expect(submit).toBeDisabled();
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toHaveCount(0);
        await expect(block).toBeFocused();

        await block.click();
        const request = page.waitForRequest(
            (candidate) => candidate.method() === 'POST' && candidate.url().endsWith('/block'),
        );
        await submitBlockModal(
            page,
            `Block internet of ${SUBSCRIPTION}?`,
            'E2E abuse report',
            'Block internet',
        );
        expect((await request).postDataJSON()).toMatchObject({ reason: 'E2E abuse report' });
        await expect(page.getByText('Subscription blocked.')).toBeVisible();

        // Reason, author and time are on the page; the status itself is untouched.
        await expect(page.locator('p').filter({ hasText: 'Block reason:' })).toContainText(
            'E2E abuse report',
        );
        await expect(page.getByText(/Blocked by:\s*Demo Admin,/)).toBeVisible();
        await expect(page.getByText('Blocked', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Suspend' })).toBeVisible();

        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);
        await expect(detail(page, 'Desired state')).toContainText('Isolated');
        await expect(detail(page, 'Desired state')).toContainText('Isolated because Blocked.');

        // The audit log has the entry too.
        await page.goto('/admin/reports/audit-log');
        await page.getByPlaceholder('Cari deskripsi, modul, event, pelaku...').fill('blocked');
        await page.getByRole('button', { name: 'Terapkan Filter' }).click();
        await expect(
            page
                .getByRole('row')
                .filter({ hasText: 'service_subscription' })
                .filter({ hasText: 'Demo Admin' })
                .first(),
        ).toContainText('blocked');

        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await page.getByRole('button', { name: 'Unblock', exact: true }).click();
        await expect(page.getByRole('dialog')).toContainText(
            'The subscriber is disconnected and gets the normal service back when the router redials.',
        );
        // The note is optional.
        await submitBlockModal(page, `Unblock internet of ${SUBSCRIPTION}?`, '', 'Unblock');
        await expect(page.getByText('Subscription unblocked.')).toBeVisible();
        await expect(page.getByText('Block reason:')).toHaveCount(0);
        await expect(
            page.getByRole('button', { name: 'Block internet', exact: true }),
        ).toBeVisible();

        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);
        await expect(detail(page, 'Desired state')).toContainText('Enabled');
        await expect(detail(page, 'Desired state')).not.toContainText('Isolated because');
    });

    test('Receivables: Block and Unblock sit next to Isolir and Isolir keeps sending source overdue', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/billing/receivables?per_page=25');
        const row = page.getByRole('row').filter({ hasText: CUSTOMER });
        await expect(row.getByRole('button', { name: `Isolir ${SUBSCRIPTION}` })).toBeVisible();

        await row.getByRole('button', { name: `Block ${SUBSCRIPTION}` }).click();
        await submitBlockModal(
            page,
            `Block internet of ${SUBSCRIPTION}?`,
            'E2E fraud check',
            'Block internet',
        );
        await expect(row.getByText('Blocked', { exact: true })).toBeVisible();
        await expect(row.getByRole('button', { name: `Unblock ${SUBSCRIPTION}` })).toBeVisible();
        await expect(row.getByRole('button', { name: `Isolir ${SUBSCRIPTION}` })).toBeVisible();

        // Unblock from the same row while the subscription is still active.
        await row.getByRole('button', { name: `Unblock ${SUBSCRIPTION}` }).click();
        await submitBlockModal(page, `Unblock internet of ${SUBSCRIPTION}?`, '', 'Unblock');
        await expect(row.getByRole('button', { name: `Block ${SUBSCRIPTION}` })).toBeVisible();
        await expect(row.getByText('Blocked', { exact: true })).toHaveCount(0);

        await row.getByRole('button', { name: `Block ${SUBSCRIPTION}` }).click();
        await submitBlockModal(
            page,
            `Block internet of ${SUBSCRIPTION}?`,
            'E2E second block',
            'Block internet',
        );
        await expect(row.getByText('Blocked', { exact: true })).toBeVisible();

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
        await expect(detail(page, 'Desired state')).toContainText(
            'Isolated because Suspended and Blocked.',
        );
        await expect(detail(page, 'Sync status')).toContainText(SWITCH_OFF_REASON);

        // Unblocking a suspended subscription keeps it isolated until it is reactivated, and the modal says so.
        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await page.getByRole('button', { name: 'Unblock', exact: true }).click();
        await expect(page.getByRole('dialog')).toContainText(
            'The subscription is suspended, so the service stays isolated until it is reactivated.',
        );
        await submitBlockModal(page, `Unblock internet of ${SUBSCRIPTION}?`, '', 'Unblock');

        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);
        await expect(detail(page, 'Desired state')).toContainText('Isolated because Suspended.');
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

        // Public means public: no admin shell, nothing about a subscriber, a username, a router or a secret.
        await expect(page.getByRole('navigation')).toHaveCount(0);
        const text = (await page.locator('body').innerText()).toLowerCase();
        for (const hidden of [
            'e2e-linked',
            'e2e linked customer',
            '10.99.9',
            FAKE_NAS_SECRET,
            state.accountSecret,
        ]) {
            expect(text).not.toContain(hidden.toLowerCase());
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

    test('a manager reads routers, manages accounts and blocks, but cannot manage routers', async ({
        page,
    }) => {
        await loginAs(page, 'manager');
        await page.goto('/admin/nas-devices');
        await expect(mainOf(page).getByRole('heading', { name: 'NAS Routers' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Register router' })).toHaveCount(0);
        await page.goto(`/admin/nas-devices/${state.routerId}`);
        await expect(
            mainOf(page).getByRole('heading', { name: `NAS router ${ROUTER_ASSET}` }),
        ).toBeVisible();
        for (const name of ['Edit', 'Remove router']) {
            await expect(page.getByRole('button', { name })).toHaveCount(0);
            await expect(page.getByRole('link', { name, exact: true })).toHaveCount(0);
        }
        // Managing is refused by the server, not just hidden.
        expect((await page.request.get('/admin/nas-devices/create')).status()).toBe(403);
        expect((await page.request.get(`/admin/nas-devices/${state.routerId}/edit`)).status()).toBe(
            403,
        );

        // Managers hold the account permissions: they can reveal.
        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);
        await expect(page.getByRole('button', { name: 'Reveal secret' })).toBeVisible();

        // And they hold customer.subscription.block.
        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await expect(
            page.getByRole('button', { name: 'Block internet', exact: true }),
        ).toBeVisible();
    });

    test('staff cannot register routers or block: the server answers 403 and the buttons are absent', async ({
        page,
    }) => {
        await loginAs(page, 'staff');
        await page.goto('/admin/nas-devices');
        await expect(mainOf(page).getByRole('heading', { name: 'NAS Routers' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Register router' })).toHaveCount(0);
        expect((await page.request.get('/admin/nas-devices/create')).status()).toBe(403);

        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await expect(
            mainOf(page).getByRole('heading', { name: `Subscription ${SUBSCRIPTION}` }),
        ).toBeVisible();
        await expect(page.getByRole('button', { name: 'Block internet', exact: true })).toHaveCount(
            0,
        );
        await expect(page.getByRole('button', { name: 'Unblock', exact: true })).toHaveCount(0);

        const token = decodeURIComponent(
            (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')!.value,
        );
        for (const action of ['block', 'unblock']) {
            const denied = await page.request.post(
                `/admin/subscriptions/${state.subscriptionId}/${action}`,
                { headers: { 'X-XSRF-TOKEN': token }, data: { reason: 'staff attempt' } },
            );
            expect(denied.status(), `${action} by staff`).toBe(403);
        }
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
        test.setTimeout(240_000);
        await loginAs(page, 'admin');
        const paths = [
            '/admin/nas-devices',
            `/admin/nas-devices/${state.routerId}`,
            `/admin/nas-devices/${state.routerId}/edit`,
            `/admin/subscriptions/${state.subscriptionId}`,
            `/admin/subscriptions/${state.subscriptionId}/network-account`,
            '/admin/billing/receivables?per_page=25',
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

        // The block modal at 375 px, dark.
        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await setTheme(page, 375, true);
        await page.getByRole('button', { name: 'Block internet', exact: true }).click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await expectNoHorizontalOverflow(page, 'block modal at 375px dark');
        await page.getByRole('button', { name: 'Cancel' }).click();

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

// ---------------------------------------------------------------------------------------------------------------
// Lab run: real FreeRADIUS + nas-sim. No trace, screenshot or video: secrets are typed on these pages.
// ---------------------------------------------------------------------------------------------------------------

const SIM = join(LAB_DIR, 'nas-sim', 'sim.py');
const LAB_NAS_ADDRESS = '172.30.77.20';
const LAB_COA_PORT = '13799';
const LAB_USERNAME = 'e2e-lab';

type SimEvent = { type: string; result: string; attributes: Record<string, string[]> };
type SimSession = {
    id: string;
    username: string;
    state: string;
    group: string;
    attributes: Record<string, string[]>;
};

const sim = (...args: string[]) => execFileSync('python3', [SIM, ...args], { encoding: 'utf8' });
const simJson = <T>(...args: string[]) => JSON.parse(sim(...args)) as T;
const labEvents = () =>
    simJson<SimEvent[]>('events').filter(
        (event) =>
            event.type === 'Disconnect-Request' &&
            event.attributes['User-Name']?.[0] === `${LAB_USERNAME}@${REALM}`,
    );

/** Lab secrets come from the process env, else from the lab's own .env at run time; never from this repo. */
function labSecret(name: 'LAB_RADIUS_SECRET' | 'LAB_USER_SECRET'): string {
    const labKey = name === 'LAB_RADIUS_SECRET' ? 'RADIUS_SECRET' : 'LAB_USER_PASSWORD';
    const value = process.env[name] || envFileValue(join(LAB_DIR, '.env'), labKey);
    if (!value) throw new Error(`${name} is not available (see the spec header)`);

    return value;
}

test.describe('@lifecycle radius provisioning (FreeRADIUS lab)', () => {
    test.skip(!LAB, 'Opt-in: set E2E_RADIUS_LAB=1 with the lab up (see the spec header).');
    test.describe.configure({ mode: 'serial' });

    let scenario: ChildProcess | null = null;
    let scenarioOut = '';
    let scenarioExit: Promise<number | null> = Promise.resolve(null);
    const lab = { routerId: '', subscriptionId: 0, speedGroup: '' };

    test.afterAll(() => {
        scenario?.kill();
    });

    test('the router is registered as the lab NAS and FreeRADIUS restarts to read it', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        lab.subscriptionId = await subscriptionIdOf(page, SUBSCRIPTION);

        // The CoA target is the management IP of the asset: the lab NAS listens on the host loopback.
        await page.goto('/admin/nas-devices/create');
        const assetId = await page
            .getByLabel('Router asset')
            .locator('option', { hasText: ROUTER_ASSET })
            .getAttribute('value');
        await page.goto(`/admin/network-assets/${assetId}/edit`);
        await page.getByLabel('Management IP').fill('127.0.0.1');
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await expect(page.getByText('Network asset updated.')).toBeVisible();

        await page.goto('/admin/nas-devices/create');
        const asset = page.getByLabel('Router asset');
        await asset.selectOption(
            (await asset.locator('option', { hasText: ROUTER_ASSET }).getAttribute('value'))!,
        );
        await expect(page.getByLabel('NAS address')).toHaveValue('127.0.0.1');
        await page.getByLabel('NAS address').fill(LAB_NAS_ADDRESS);
        await page.getByLabel('CoA port').fill(LAB_COA_PORT);
        await page.getByLabel('RADIUS secret').fill(labSecret('LAB_RADIUS_SECRET'));
        await page.getByRole('button', { name: 'Register router' }).click();
        await expect(page).toHaveURL(/\/admin\/nas-devices\/\d+$/);
        lab.routerId = page.url().match(/\/admin\/nas-devices\/(\d+)$/)![1];

        // The client row is in the lab database and the page says so.
        await expect(detail(page, 'RADIUS client')).toContainText('Written to RADIUS');
        await expect(detail(page, 'NAS address')).toHaveText(LAB_NAS_ADDRESS);
        await expect(detail(page, 'CoA target')).toHaveText(`127.0.0.1:${LAB_COA_PORT}`);
        await expect(detail(page, 'Realm')).toHaveText(REALM);
        expect(await page.locator('body').innerText()).not.toContain(
            labSecret('LAB_RADIUS_SECRET'),
        );
        await expect(page.getByText('RADIUS is switched off for this installation.')).toHaveCount(
            0,
        );

        // FreeRADIUS reads the `nas` table when it starts; a HUP answers "No files changed. Ignoring" and leaves the
        // clients as they were, so a new NAS needs a restart of the server (proven in the lab, see the ticket).
        execFileSync('docker', ['--context', 'default', 'restart', 'radlab-freeradius']);
        await expect
            .poll(
                () =>
                    execFileSync(
                        'docker',
                        [
                            '--context',
                            'default',
                            'inspect',
                            '-f',
                            '{{.State.Health.Status}}',
                            'radlab-freeradius',
                        ],
                        { encoding: 'utf8' },
                    ).trim(),
                { timeout: 60_000, message: 'FreeRADIUS healthy after the restart' },
            )
            .toBe('healthy');
    });

    test('the account on the active subscription lands in RADIUS in the speed group', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/subscriptions/${lab.subscriptionId}/network-account`);
        const speed = await detail(page, 'Speed profile').innerText();
        lab.speedGroup = speed.match(/RADIUS group (\S+)/)![1];

        await page.locator('#account-username').fill(LAB_USERNAME);
        await page.getByLabel('Secret').fill(labSecret('LAB_USER_SECRET'));
        await page.getByRole('button', { name: 'Create network account' }).click();

        await expect(detail(page, 'Username').first()).toHaveText(`${LAB_USERNAME}@${REALM}`);
        await expect(detail(page, 'Sync status')).toContainText('Synced');
        await expect(detail(page, 'RADIUS group')).toContainText(`${REALM}/${lab.speedGroup}`);
        await expect(detail(page, 'Desired state')).toContainText('Enabled');
        // An explicit secret is never echoed back.
        await expect(secretPanel(page)).toHaveCount(0);
        expect(await page.locator('body').innerText()).not.toContain(labSecret('LAB_USER_SECRET'));
    });

    test('Block disconnects the session and the CPE redials into the isolir group', async ({
        page,
    }) => {
        sim('events', '--clear');
        scenario = spawn(
            'python3',
            [
                SIM,
                'scenario',
                'block-unblock',
                `${LAB_USERNAME}@${REALM}`,
                '--expect-group',
                'isolir',
                '--timeout',
                '60',
            ],
            { stdio: ['ignore', 'pipe', 'ignore'] },
        );
        scenarioOut = '';
        scenario.stdout?.on('data', (chunk: Buffer) => (scenarioOut += chunk.toString()));
        const exited = Promise.withResolvers<number | null>();
        scenario.on('close', (code) => exited.resolve(code));
        scenarioExit = exited.promise;

        // The scenario is online in the speed group before anyone blocks.
        await expect
            .poll(() =>
                simJson<SimSession[]>(
                    'list',
                    '--state',
                    'active',
                    '--user',
                    `${LAB_USERNAME}@${REALM}`,
                ).map((session) => session.attributes['Mikrotik-Group']?.[0]),
            )
            .toEqual([lab.speedGroup]);

        await loginAs(page, 'admin');
        await page.goto(`/admin/subscriptions/${lab.subscriptionId}/network-account`);
        await expect(detail(page, 'Online')).toHaveText('Online');

        await page.goto(`/admin/subscriptions/${lab.subscriptionId}`);
        await page.getByRole('button', { name: 'Block internet', exact: true }).click();
        await submitBlockModal(
            page,
            `Block internet of ${SUBSCRIPTION}?`,
            'E2E lab abuse test',
            'Block internet',
        );
        await expect(page.getByText('Subscription blocked.')).toBeVisible();
        await expect(page.getByText('Blocked', { exact: true })).toBeVisible();

        await expect.poll(() => labEvents().map((event) => event.result)).toEqual(['ACK']);
        expect(
            await scenarioExit,
            'block-unblock scenario exit code (0 = reconnected in isolir)',
        ).toBe(0);
        const result = JSON.parse(scenarioOut) as { before: SimSession; after: SimSession };
        expect(result.before.state).toBe('stopped');
        expect(result.after.group).toBe('isolir');
        expect(result.after.attributes['Mikrotik-Group']).toEqual(['isolir']);
        expect(result.after.attributes['Mikrotik-Address-List']).toEqual(['isolir']);

        await page.goto(`/admin/subscriptions/${lab.subscriptionId}/network-account`);
        await expect(detail(page, 'Desired state')).toContainText('Isolated because Blocked.');
        await expect(detail(page, 'RADIUS group')).toContainText(`${REALM}/isolir`);
        await expect(detail(page, 'Sync status')).toContainText('Synced');
        await expect(detail(page, 'Online')).toHaveText('Online');
    });

    test('Unblock disconnects the isolated session and the next login gets the speed profile', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/subscriptions/${lab.subscriptionId}`);
        await page.getByRole('button', { name: 'Unblock', exact: true }).click();
        await submitBlockModal(
            page,
            `Unblock internet of ${SUBSCRIPTION}?`,
            'E2E lab unblock',
            'Unblock',
        );
        await expect(page.getByText('Subscription unblocked.')).toBeVisible();

        await expect.poll(() => labEvents().map((event) => event.result)).toEqual(['ACK', 'ACK']);
        await expect
            .poll(() =>
                simJson<SimSession[]>(
                    'list',
                    '--state',
                    'active',
                    '--user',
                    `${LAB_USERNAME}@${REALM}`,
                ),
            )
            .toHaveLength(0);

        // The CPE redials: the reply carries the speed profile name again.
        const connected = JSON.parse(sim('connect', `${LAB_USERNAME}@${REALM}`)) as SimSession;
        expect(connected.attributes['Mikrotik-Group']).toEqual([lab.speedGroup]);
        sim('disconnect', connected.id);

        await page.goto(`/admin/subscriptions/${lab.subscriptionId}/network-account`);
        await expect(detail(page, 'Desired state')).toContainText('Enabled');
        await expect(detail(page, 'Desired state')).not.toContainText('Isolated because');
        await expect(detail(page, 'RADIUS group')).toContainText(`${REALM}/${lab.speedGroup}`);
    });
});
