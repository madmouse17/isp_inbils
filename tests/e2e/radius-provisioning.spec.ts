/**
 * @lifecycle FreeRADIUS provisioning and subscription block/unblock UI
 *
 * Two describes in one file, picked by E2E_RADIUS_LAB:
 *
 * 1. CI (default, DEMO has no RADIUS integration row): every server-side RADIUS call is skipped, so the pages show
 *    the honest state (routers "RADIUS belum dikonfigurasi", accounts "Menunggu" with the missing integration as the
 *    reason, toasts that say a change was saved but not sent). The engine against a fake RADIUS database is covered by
 *    tests/Feature/NetworkAsset; this drives the UI.
 *    Flow: register NAS (secret never shown again) -> router page -> edit keeps the stored secret -> account
 *    with realm suffix and one-time secret -> reveal -> Block/Unblock on Subscriptions/Show -> Block/Unblock
 *    next to Isolir on Receivables -> public isolir page -> role gates (403 from the server) -> layouts at
 *    375 and 1280 px in both themes. The last test reactivates the subscription for the specs that follow.
 *
 * 2. Lab (opt-in, `E2E_RADIUS_LAB=1`): the real wire against ~/labs/freeradius (FreeRADIUS + nas-sim).
 *    Flow: register the router as the lab NAS -> account lands in the speed group -> Block (Disconnect, the CPE redials
 *    into isolir) -> Unblock -> a static IP from IPAM becomes the session's Framed-IP-Address and a release hands the
 *    NAS pool back (the IPAM event queues the apply job, which also cuts the session that holds the old address).
 *    Preconditions:
 *      - lab up: `docker compose ps` in ~/labs/freeradius shows radlab-freeradius, radlab-db, radlab-nas-sim healthy;
 *      - light reset from the lab README (and no leftover `e2e-lab@demo` rows in the lab database);
 *      - DEMO needs an enabled RADIUS integration row that points at the lab. tests/e2e/setup.php writes it with
 *        `inbils:integrations:import-env` from RADIUS_ENABLED=true and RADIUS_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD of
 *        the lab (host 127.0.0.1, port 13306); the served app reads the row, never those variables. QUEUE_CONNECTION=sync
 *        is already in .env.e2e. The runner below exports the variables from ~/labs/freeradius/.env, never printing
 *        them. The local .env.e2e needs no RADIUS lines (without the row the NAS page says "Gagal ditulis");
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
const SWITCH_OFF_REASON =
    'RADIUS belum dikonfigurasi untuk perusahaan ini (Pengaturan → Integrasi).';
const SWITCH_OFF_BADGE = 'RADIUS belum dikonfigurasi';
const SAVED_NOT_SENT_TO_RADIUS =
    'Tersimpan, tetapi belum dikirim ke RADIUS karena RADIUS belum dikonfigurasi untuk perusahaan ini.';
// The customer app listing tests/e2e/setup.php gives the demo company; the isolir page links to it.
const CUSTOMER_PLAY_STORE_URL = 'https://play.google.com/store/apps/details?id=id.inbils.customer';
// Contact fields of the company profile form: the isolir page test fills them and then puts the fixture's own back.
const CONTACT_FIELDS = ['Telepon', 'Email', 'Situs web', 'WhatsApp', 'Jam layanan'] as const;
const ORIGIN = 'http://127.0.0.1:8010';
const FAKE_NAS_SECRET = 'e2e-fake-nas-secret';

const state: {
    routerId: string;
    subscriptionId: string;
    isolirUrl: string;
    accountSecret: string;
} = { routerId: '', subscriptionId: '', isolirUrl: '', accountSecret: '' };

const mainOf = (page: Page) => page.getByRole('main');

/** The `<dd>` that belongs to a `<dt>` label (the detail lists of the router and account pages). */
const detail = (page: Page, label: string) =>
    page
        .locator('dt')
        .filter({ hasText: new RegExp(`^${label}$`) })
        .locator('xpath=following-sibling::dd[1]');

const secretPanel = (page: Page) =>
    page.getByRole('alert').filter({ hasText: 'Kata sandi hanya tampil sekali' });

async function subscriptionIdOf(page: Page, code: string) {
    const data = await inertiaProps(page, '/admin/billing/receivables?per_page=25');
    const rows = data.props?.rows?.data as
        Array<{ subscriptions: Array<{ id: string; code: string }> }> | undefined;
    const found = rows?.flatMap((row) => row.subscriptions).find((item) => item.code === code);
    if (!found) throw new Error(`${code} is not on the receivables page`);

    return String(found.id);
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
    test.skip(LAB, 'The lab run gives DEMO a RADIUS integration; see the lab describe below.');
    test.describe.configure({ mode: 'serial' });

    test('admin registers the unregistered router asset as a NAS router with a secret that is never shown again', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        state.subscriptionId = await subscriptionIdOf(page, SUBSCRIPTION);

        await page.goto('/admin/nas-devices');
        await expect(mainOf(page).getByRole('heading', { name: 'Router NAS' })).toBeVisible();
        const fixture = page.getByRole('row').filter({ hasText: REGISTERED_ROUTER_ASSET });
        await expect(fixture).toContainText('10.99.9.2');
        await expect(fixture).toContainText(SWITCH_OFF_BADGE);
        await expect(page.getByRole('row').filter({ hasText: ROUTER_ASSET })).toHaveCount(0);

        await page.getByLabel('Cari', { exact: true }).fill('10.99.9.2');
        await expect(
            page.getByRole('row').filter({ hasText: REGISTERED_ROUTER_ASSET }),
        ).toHaveCount(1);
        await page.getByLabel('Cari', { exact: true }).fill('no-such-router');
        await expect(page.getByText('Tidak ada router NAS yang cocok')).toBeVisible();
        await page.getByLabel('Cari', { exact: true }).fill('');

        await page.getByRole('link', { name: 'Daftarkan router' }).click();
        await expect(
            mainOf(page).getByRole('heading', { name: 'Daftarkan Router NAS' }),
        ).toBeVisible();

        // The router is picked by search; one that is already registered is not offered again.
        await page.getByLabel('Aset router').fill('E2E-ROUTER');
        await expect(page.getByRole('option', { name: new RegExp(ROUTER_ASSET) })).toBeVisible();
        await expect(
            page.getByRole('option', { name: new RegExp(REGISTERED_ROUTER_ASSET) }),
        ).toHaveCount(0);
        await page.getByRole('option', { name: new RegExp(ROUTER_ASSET) }).click();

        // The NAS address is prefilled from the management IP of the asset.
        await expect(page.getByLabel('Alamat NAS')).toHaveValue('10.99.9.1');
        await expect(page.getByLabel('Port CoA')).toHaveValue('1700');
        await expect(page.getByLabel('Realm')).toHaveValue(REALM);
        await expect(page.getByLabel('Realm')).toHaveAttribute('readonly', '');
        const secret = page.getByLabel('Secret RADIUS');
        await expect(secret).toHaveAttribute('type', 'password');
        await expect(secret).toHaveValue('');

        // A server-side rule shows inline and keeps what was typed; the secret input is reset after submit.
        await page.getByLabel('Alamat NAS').fill('10.99.9.2');
        await secret.fill(FAKE_NAS_SECRET);
        await page.getByRole('button', { name: 'Daftarkan router' }).click();
        await expect(page.getByText('Alamat ini tidak tersedia.')).toBeVisible();
        await expect(page.getByLabel('Alamat NAS')).toHaveValue('10.99.9.2');
        await expect(secret).toHaveValue('');

        await page.getByLabel('Alamat NAS').fill('10.99.9.1');
        await secret.fill('short');
        await page.getByRole('button', { name: 'Daftarkan router' }).click();
        await expect(page.getByText(/minimal 8 karakter/)).toBeVisible();

        await secret.fill(FAKE_NAS_SECRET);
        await page.getByRole('button', { name: 'Daftarkan router' }).click();
        await expect(page).toHaveURL(/\/admin\/nas-devices\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        state.routerId = page.url().match(/\/admin\/nas-devices\/([0-9A-HJKMNP-TV-Z]{26})$/i)![1];
        await expect(
            mainOf(page).getByRole('heading', { name: `Router NAS ${ROUTER_ASSET}` }),
        ).toBeVisible();
        await expect(page.getByText('Router NAS didaftarkan.')).toBeVisible();
        await expect(page.getByText(SAVED_NOT_SENT_TO_RADIUS)).toBeVisible();
    });

    test('the router page shows the restart notice, the switch banner and the isolir URL, never the secret', async ({
        page,
        context,
    }) => {
        await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: ORIGIN });
        await loginAs(page, 'admin');
        await page.goto(`/admin/nas-devices/${state.routerId}`);

        // The row is saved, but nothing reached RADIUS: the page says so and why.
        await expect(detail(page, 'Klien RADIUS')).toContainText(SWITCH_OFF_BADGE);
        await expect(detail(page, 'Terakhir ditulis')).toHaveText('Belum pernah');
        await expect(
            page.getByRole('alert').filter({ hasText: 'Kesalahan terakhir' }),
        ).toContainText(SWITCH_OFF_REASON);
        await expect(detail(page, 'Alamat NAS')).toHaveText('10.99.9.1');
        await expect(detail(page, 'Target CoA')).toHaveText('10.99.9.1:1700');
        await expect(detail(page, 'Realm')).toHaveText(REALM);
        await expect(detail(page, 'Secret RADIUS')).toHaveText('Tersimpan');
        await expect(
            page.getByText(
                'RADIUS belum dikonfigurasi untuk perusahaan ini. Perubahan disimpan tetapi tidak dikirim ke FreeRADIUS.',
            ),
        ).toBeVisible();
        // The admin holds company.integrations.manage, so the alert points to the page that fixes it.
        await expect(
            page.getByRole('link', { name: 'Atur di Pengaturan → Integrasi' }),
        ).toBeVisible();
        await expect(
            page.getByText(/Mulai ulang FreeRADIUS setelah menambah NAS ini/),
        ).toBeVisible();
        // The shared secret is write-only: no page shows it.
        await expect(page.getByText(FAKE_NAS_SECRET)).toHaveCount(0);

        const isolir = page.locator('code').filter({ hasText: '/isolir/' });
        state.isolirUrl = (await isolir.innerText()).trim();
        expect(state.isolirUrl).toMatch(/\/isolir\/DEMO$/);
        await page.getByRole('button', { name: 'Salin URL' }).click();
        await expect(page.getByRole('button', { name: 'Tersalin' })).toBeVisible();
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(state.isolirUrl);

        await expect(page.getByText('Tidak ada sesi terbuka')).toBeVisible();

        // Removing asks first and can be cancelled.
        await page.getByRole('button', { name: 'Hapus router' }).click();
        const dialog = page.getByRole('alertdialog');
        await expect(dialog.getByText('Hapus router NAS ini?')).toBeVisible();
        await dialog.getByRole('button', { name: 'Batal' }).click();
        await expect(dialog).toHaveCount(0);
    });

    test('edit keeps the stored secret and saves the change', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/nas-devices/${state.routerId}`);
        await page.getByRole('link', { name: 'Ubah', exact: true }).click();
        await expect(mainOf(page).getByRole('heading', { name: 'Ubah Router NAS' })).toBeVisible();

        const secret = page.getByLabel('Secret RADIUS');
        await expect(secret).toHaveValue('');
        await expect(secret).toHaveAttribute('placeholder', 'Tersimpan');
        await expect(
            page.getByText('Secret sudah tersimpan. Kosongkan untuk mempertahankannya.'),
        ).toBeVisible();
        await expect(page.getByLabel('Alamat NAS')).toHaveValue('10.99.9.1');

        await page.getByLabel('Port CoA').fill('3799');
        await page.getByRole('button', { name: 'Simpan router' }).click();
        await expect(page).toHaveURL(new RegExp(`/admin/nas-devices/${state.routerId}$`));
        await expect(page.getByText('Router NAS diperbarui.')).toBeVisible();
        await expect(page.getByText(SAVED_NOT_SENT_TO_RADIUS)).toBeVisible();
        await expect(detail(page, 'Target CoA')).toHaveText('10.99.9.1:3799');
        await expect(detail(page, 'Secret RADIUS')).toHaveText('Tersimpan');
    });

    test('the account is created with the realm suffix and a generated secret shown once', async ({
        page,
        context,
    }) => {
        await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: ORIGIN });
        await loginAs(page, 'admin');
        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await page.getByRole('link', { name: 'Akses jaringan' }).click();
        await expect(page).toHaveURL(
            new RegExp(`/admin/subscriptions/${state.subscriptionId}/network-account$`),
        );
        await expect(mainOf(page).getByRole('heading', { name: 'Akses jaringan' })).toBeVisible();
        await expect(page.getByText(`Langganan ${SUBSCRIPTION}`).first()).toBeVisible();
        await expect(
            page.getByText(
                'RADIUS belum dikonfigurasi untuk perusahaan ini. Perubahan disimpan tetapi tidak dikirim ke FreeRADIUS.',
            ),
        ).toBeVisible();
        await expect(detail(page, 'Status yang diharapkan')).toContainText('Aktif');
        await expect(detail(page, 'Profil kecepatan')).toContainText('grup RADIUS');
        // There is no NAS select: a subscriber may log in on any router of the company.
        await expect(page.getByLabel('Router NAS')).toHaveCount(0);

        // The username field carries the fixed realm suffix; a username the server rejects shows inline.
        await expect(page.locator('#account-username')).toBeVisible();
        await expect(page.getByText(`@${REALM}`, { exact: true })).toBeVisible();
        await page.locator('#account-username').fill('X!');
        await page.getByRole('button', { name: 'Buat akun jaringan' }).click();
        await expect(
            page.getByText(
                'Gunakan 3 sampai 40 huruf kecil, angka, titik, tanda hubung, atau garis bawah.',
            ),
        ).toBeVisible();

        await page.locator('#account-username').fill('e2e-linked');
        await expect(page.getByLabel(/^Kata sandi( \*)?$/)).toHaveValue('');
        await page.getByRole('button', { name: 'Buat akun jaringan' }).click();

        const panel = secretPanel(page);
        await expect(panel).toBeVisible();
        state.accountSecret = (await panel.locator('code').innerText()).trim();
        expect(state.accountSecret).toHaveLength(12);
        await expect(panel).toContainText('Kata sandi tidak disimpan setelah pesan ini ditutup');

        await panel.getByRole('button', { name: 'Salin kata sandi' }).click();
        await expect(panel.getByRole('button', { name: 'Tersalin' })).toBeVisible();
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(state.accountSecret);

        // The account exists now and is pending, with the switch as the reason.
        await expect(
            mainOf(page).getByRole('heading', { name: 'Akun jaringan', exact: true }),
        ).toBeVisible();
        await expect(detail(page, 'Nama pengguna').first()).toHaveText(`e2e-linked@${REALM}`);
        await expect(detail(page, 'Status sinkronisasi')).toContainText('Menunggu');
        await expect(detail(page, 'Status sinkronisasi')).toContainText(SWITCH_OFF_REASON);
        await expect(detail(page, 'Grup RADIUS')).toHaveText('Belum ada di RADIUS');
        await expect(detail(page, 'Online')).toHaveText('Offline');
        await expect(page.getByRole('heading', { name: 'Buat akun jaringan' })).toHaveCount(0);

        // Dismissing removes it from the page, storage, history and URL.
        await panel.getByRole('button', { name: 'Tutup' }).click();
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
        await expect(page.getByLabel(/^Kata sandi( \*)?$/)).toHaveValue('');
    });

    test('reveal asks for confirmation, shows the secret again once, and the edit form never carries it', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);

        const reveal = page.getByRole('button').filter({ hasText: 'Tampilkan kata sandi' });
        await reachByKeyboard(page, reveal);

        await reveal.click();
        const dialog = page.getByRole('alertdialog');
        await expect(dialog.getByText('Tampilkan kata sandi?')).toBeVisible();
        await expect(dialog).toContainText('dicatat di log audit');
        await dialog.getByRole('button', { name: 'Batal' }).click();
        await expect(dialog).toHaveCount(0);
        await expect(secretPanel(page)).toHaveCount(0);

        await reveal.click();
        await dialog.getByRole('button', { name: 'Tampilkan', exact: true }).click();
        const panel = secretPanel(page);
        await expect(panel).toBeVisible();
        await expect(panel.locator('code')).toHaveText(state.accountSecret);
        await expect(dialog).toHaveCount(0);
        await panel.getByRole('button', { name: 'Tutup' }).click();
        await expect(page.getByText(state.accountSecret)).toHaveCount(0);

        // The edit form starts with the stored local part and a blank secret.
        await expect(page.locator('#account-username')).toHaveValue('e2e-linked');
        await expect(page.getByLabel(/^Kata sandi( \*)?$/)).toHaveValue('');
        await expect(
            page.getByText('Kosongkan untuk mempertahankan kata sandi saat ini.'),
        ).toBeVisible();

        // Renaming keeps the secret: no new one is shown.
        await page.locator('#account-username').fill('e2e-linked-2');
        await page.getByRole('button', { name: 'Simpan perubahan' }).click();
        await expect(page.getByText('Akun jaringan diperbarui.')).toBeVisible();
        await expect(detail(page, 'Nama pengguna').first()).toHaveText(`e2e-linked-2@${REALM}`);
        await expect(secretPanel(page)).toHaveCount(0);

        await page.getByRole('button', { name: 'Sinkron ulang' }).click();
        const resync = page.getByRole('alertdialog', { name: /Sinkron ulang akun/ });
        await expect(resync).toContainText(
            'RADIUS belum dikonfigurasi. Perubahan disimpan dan dikirim ke router setelah RADIUS aktif.',
        );
        await resync.getByRole('button', { name: 'Sinkron ulang' }).click();
        await expect(
            page.getByText(
                'Perubahan disimpan, tetapi belum dikirim ke router karena RADIUS belum dikonfigurasi untuk perusahaan ini.',
            ),
        ).toBeVisible();
    });

    test('Block and Unblock on Subscriptions need a reason, show the badge and isolate the account', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await expect(page.getByText('Diblokir', { exact: true })).toHaveCount(0);

        // Opening by keyboard works and Cancel returns focus to the button.
        const block = page.getByRole('button', { name: 'Blokir internet', exact: true });
        await reachByKeyboard(page, block);
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByText(`Blokir internet ${SUBSCRIPTION}?`)).toBeVisible();
        await expect(dialog).toContainText(
            'RADIUS belum dikonfigurasi. Blokir disimpan dan dikirim ke router setelah RADIUS aktif.',
        );
        // The reason is required: nothing is sent while it is empty.
        const submit = dialog.getByRole('button', { name: 'Blokir internet', exact: true });
        await expect(submit).toBeDisabled();
        await dialog.locator('#subscription-block-reason').fill('   ');
        await expect(submit).toBeDisabled();
        await dialog.getByRole('button', { name: 'Batal' }).click();
        await expect(dialog).toHaveCount(0);
        await expect(block).toBeFocused();

        await block.click();
        const request = page.waitForRequest(
            (candidate) => candidate.method() === 'POST' && candidate.url().endsWith('/block'),
        );
        await submitBlockModal(
            page,
            `Blokir internet ${SUBSCRIPTION}?`,
            'E2E abuse report',
            'Blokir internet',
        );
        expect((await request).postDataJSON()).toMatchObject({ reason: 'E2E abuse report' });
        await expect(
            page.getByText(
                'Blokir disimpan, tetapi belum dikirim ke router karena RADIUS belum dikonfigurasi.',
            ),
        ).toBeVisible();

        // Reason, author and time are on the page; the status itself is untouched.
        await expect(detail(page, 'Alasan blokir')).toContainText('E2E abuse report');
        await expect(detail(page, 'Diblokir oleh')).toContainText(/Demo Admin,/);
        await expect(page.getByText('Diblokir', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Tangguhkan', exact: true })).toBeVisible();

        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);
        await expect(detail(page, 'Status yang diharapkan')).toContainText('Diisolir');
        await expect(detail(page, 'Status yang diharapkan')).toContainText(
            'Diisolir karena diblokir.',
        );

        // The audit log has the entry too, worded as a sentence and found by the word the row shows.
        await page.goto('/admin/reports/audit-log');
        await page.getByPlaceholder('Cari deskripsi, modul, aksi, pelaku...').fill('diblokir');
        await page.getByRole('button', { name: 'Terapkan filter' }).click();
        await expect(
            page
                .getByRole('row')
                .filter({ hasText: 'Langganan' })
                .filter({ hasText: 'Demo Admin' })
                .first(),
        ).toContainText('Langganan diblokir');

        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await page.getByRole('button', { name: 'Buka blokir', exact: true }).click();
        await expect(page.getByRole('dialog')).toContainText(
            'RADIUS belum dikonfigurasi. Pembukaan blokir disimpan dan dikirim ke router setelah RADIUS aktif.',
        );
        // The note is optional.
        await submitBlockModal(page, `Buka blokir internet ${SUBSCRIPTION}?`, '', 'Buka blokir');
        await expect(
            page.getByText(
                'Blokir dibuka, tetapi belum dikirim ke router karena RADIUS belum dikonfigurasi.',
            ),
        ).toBeVisible();
        await expect(detail(page, 'Alasan blokir')).toHaveCount(0);
        await expect(
            page.getByRole('button', { name: 'Blokir internet', exact: true }),
        ).toBeVisible();

        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);
        await expect(detail(page, 'Status yang diharapkan')).toContainText('Aktif');
        await expect(detail(page, 'Status yang diharapkan')).not.toContainText('Diisolir karena');
    });

    test('Receivables: Block and Unblock sit next to Isolir and Isolir keeps sending source overdue', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/billing/receivables?per_page=25');
        const row = page.getByRole('row').filter({ hasText: CUSTOMER });
        await expect(row.getByRole('button', { name: `Isolir ${SUBSCRIPTION}` })).toBeVisible();

        await row.getByRole('button', { name: `Blokir ${SUBSCRIPTION}` }).click();
        await submitBlockModal(
            page,
            `Blokir internet ${SUBSCRIPTION}?`,
            'E2E fraud check',
            'Blokir internet',
        );
        await expect(row.getByText('Diblokir', { exact: true })).toBeVisible();
        await expect(
            row.getByRole('button', { name: `Buka blokir ${SUBSCRIPTION}` }),
        ).toBeVisible();
        await expect(row.getByRole('button', { name: `Isolir ${SUBSCRIPTION}` })).toBeVisible();

        // Unblock from the same row while the subscription is still active.
        await row.getByRole('button', { name: `Buka blokir ${SUBSCRIPTION}` }).click();
        // The unblocks of this test carry a note: the previous test sent a note-less one seconds ago, and the
        // duplicate-submit guard refuses an identical write (same body) inside 10 seconds.
        await submitBlockModal(
            page,
            `Buka blokir internet ${SUBSCRIPTION}?`,
            'E2E fraud check cleared',
            'Buka blokir',
        );
        await expect(row.getByRole('button', { name: `Blokir ${SUBSCRIPTION}` })).toBeVisible();
        await expect(row.getByText('Diblokir', { exact: true })).toHaveCount(0);

        await row.getByRole('button', { name: `Blokir ${SUBSCRIPTION}` }).click();
        await submitBlockModal(
            page,
            `Blokir internet ${SUBSCRIPTION}?`,
            'E2E second block',
            'Blokir internet',
        );
        await expect(row.getByText('Diblokir', { exact: true })).toBeVisible();

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
        await expect(detail(page, 'Status').first()).toContainText('Ditangguhkan');
        await expect(detail(page, 'Status yang diharapkan')).toContainText(
            'Diisolir karena ditangguhkan dan diblokir.',
        );
        await expect(detail(page, 'Status sinkronisasi')).toContainText(SWITCH_OFF_REASON);

        // Unblocking a suspended subscription keeps it isolated until it is reactivated, and the modal says so.
        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await page.getByRole('button', { name: 'Buka blokir', exact: true }).click();
        await expect(page.getByRole('dialog')).toContainText(
            'Langganan sedang ditangguhkan, jadi layanan tetap diisolir sampai diaktifkan kembali.',
        );
        await submitBlockModal(
            page,
            `Buka blokir internet ${SUBSCRIPTION}?`,
            'E2E unblocked while suspended',
            'Buka blokir',
        );

        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);
        await expect(detail(page, 'Status yang diharapkan')).toContainText(
            'Diisolir karena ditangguhkan.',
        );
    });

    test('the public isolir page shows the company, both explanations and the ways out, and no login link', async ({
        page,
    }) => {
        test.setTimeout(90_000);
        // Give the company contact fields through the profile form, as staff would.
        await loginAs(page, 'admin');
        await page.goto('/admin/company/profile');
        const contacts = await Promise.all(
            CONTACT_FIELDS.map(
                async (label) => [label, await page.getByLabel(label).inputValue()] as const,
            ),
        );
        await page.getByLabel('Telepon').fill('(021)5550100');
        await page.getByLabel('Email').fill('support@demo.inbils.test');
        await page.getByLabel('Situs web').fill('https://demo.inbils.test');
        await page.getByLabel('WhatsApp').fill('+6281234567890');
        await page.getByLabel('Jam layanan').fill('Senin sampai Sabtu, 08.00 sampai 17.00');
        // A preset fills the hex field; a colour under 4,5:1 against white text warns.
        await page.getByTitle('Biru #2563EB').click();
        await expect(page.getByRole('radio', { name: 'Biru #2563EB' })).toBeChecked();
        await expect(page.getByLabel('Kode warna')).toHaveValue('#2563EB');
        await expect(page.getByText('Teks putih terbaca jelas.')).toBeVisible();
        await page.getByLabel('Kode warna').fill('#16A34A');
        await expect(page.getByRole('radio', { name: 'Hijau #16A34A' })).toBeChecked();
        await expect(page.getByText('Di bawah 4,5:1, teks putih sulit dibaca.')).toBeVisible();
        await page.getByLabel('Kode warna').fill('#2563eb');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page.getByText('Profil perusahaan diperbarui.').first()).toBeVisible();
        await page.reload();
        await expect(page.getByLabel('WhatsApp')).toHaveValue('+6281234567890');
        await expect(page.getByLabel('Jam layanan')).toHaveValue(
            'Senin sampai Sabtu, 08.00 sampai 17.00',
        );
        await expect(page.getByLabel('Kode warna')).toHaveValue('#2563eb');
        await expect(page.getByRole('radio', { name: 'Biru #2563EB' })).toBeChecked();

        await logout(page);
        const response = await page.goto(state.isolirUrl);
        expect(response?.status()).toBe(200);
        await expect(page).toHaveTitle(/Layanan dibatasi/);
        await expect(
            page.getByRole('heading', { name: 'Layanan internet Anda sedang dibatasi', level: 1 }),
        ).toBeVisible();
        await expect(page.getByText('Inbils Demo Company').first()).toBeVisible();

        // One page for both causes. Unpaid bills: pay in the app, which the customer app listing opens.
        const bills = page.getByRole('region', { name: 'Tagihan belum dibayar' });
        await expect(bills).toContainText(
            'Ada tagihan yang belum dibayar? Bayar lewat aplikasi Inbils Demo Company. Layanan aktif kembali otomatis setelah pembayaran diterima.',
        );
        const app = bills.getByRole('link', { name: 'Buka aplikasi Inbils Demo Company' });
        await expect(app).toHaveAttribute('href', CUSTOMER_PLAY_STORE_URL);
        const box = await app.boundingBox();
        expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);

        // Any other cause: call or write. A landline has no WhatsApp, so no button is guessed.
        const other = page.getByRole('region', { name: 'Dibatasi karena alasan lain' });
        await expect(other).toContainText(
            'Dibatasi oleh Inbils Demo Company karena alasan lain? Hubungi kami.',
        );
        await expect(other.getByRole('link', { name: /^Telepon/ })).toHaveAttribute(
            'href',
            'tel:0215550100',
        );
        await expect(other.getByRole('link', { name: 'WhatsApp' })).toHaveCount(0);
        await expect(other.getByRole('link', { name: 'support@demo.inbils.test' })).toHaveAttribute(
            'href',
            'mailto:support@demo.inbils.test',
        );
        const website = other.getByRole('link', { name: 'https://demo.inbils.test' });
        await expect(website).toHaveAttribute('rel', /noopener/);
        await expect(website).toHaveAttribute('target', '_blank');

        // Customers use the app, not the web: there is no login link.
        await expect(page.getByRole('link', { name: /Masuk/ })).toHaveCount(0);

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

        // Restore the shared fixture: the contact fields as the fixture had them, and no brand colour.
        await loginAs(page, 'admin');
        await page.goto('/admin/company/profile');
        for (const [label, value] of contacts) await page.getByLabel(label).fill(value);
        await page.getByLabel('Kode warna').fill('');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page.getByText('Profil perusahaan diperbarui.').first()).toBeVisible();
        await page.reload();
        await expect(page.getByLabel('Kode warna')).toHaveValue('');
        await expect(page.getByRole('radio', { checked: true })).toHaveCount(0);
    });

    test('a manager reads routers, manages accounts and blocks, but cannot manage routers', async ({
        page,
    }) => {
        await loginAs(page, 'manager');
        await page.goto('/admin/nas-devices');
        await expect(mainOf(page).getByRole('heading', { name: 'Router NAS' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Daftarkan router' })).toHaveCount(0);
        await page.goto(`/admin/nas-devices/${state.routerId}`);
        await expect(
            mainOf(page).getByRole('heading', { name: `Router NAS ${ROUTER_ASSET}` }),
        ).toBeVisible();
        for (const name of ['Ubah', 'Hapus router']) {
            await expect(page.getByRole('button', { name })).toHaveCount(0);
            await expect(page.getByRole('link', { name, exact: true })).toHaveCount(0);
        }
        // The alert is for everyone who reads the page; the link only for who may open the integrations page.
        await expect(
            page.getByText('RADIUS belum dikonfigurasi untuk perusahaan ini.'),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Atur di Pengaturan → Integrasi' }),
        ).toHaveCount(0);
        // Managing is refused by the server, not just hidden.
        expect((await page.request.get('/admin/nas-devices/create')).status()).toBe(403);
        expect((await page.request.get(`/admin/nas-devices/${state.routerId}/edit`)).status()).toBe(
            403,
        );

        // Managers hold the account permissions: they can reveal.
        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);
        await expect(
            page.getByRole('button').filter({ hasText: 'Tampilkan kata sandi' }),
        ).toBeVisible();

        // And they hold customer.subscription.block.
        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await expect(
            page.getByRole('button', { name: 'Blokir internet', exact: true }),
        ).toBeVisible();
    });

    test('staff cannot register routers or block: the server answers 403 and the buttons are absent', async ({
        page,
    }) => {
        await loginAs(page, 'staff');
        await page.goto('/admin/nas-devices');
        await expect(mainOf(page).getByRole('heading', { name: 'Router NAS' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Daftarkan router' })).toHaveCount(0);
        expect((await page.request.get('/admin/nas-devices/create')).status()).toBe(403);

        await page.goto(`/admin/subscriptions/${state.subscriptionId}`);
        await expect(
            mainOf(page).getByRole('heading', { name: `Langganan ${SUBSCRIPTION}` }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Blokir internet', exact: true }),
        ).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Buka blokir', exact: true })).toHaveCount(0);

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
        await expect(page.getByRole('link', { name: 'Akses jaringan' })).toBeVisible();
        // The item sits in a collapsed sidebar group; open it to see the link.
        const navLink = page.locator('aside a', { hasText: 'Router NAS' });
        await expect(navLink).toHaveCount(1);
        await page
            .locator('aside details')
            .filter({ has: page.locator('summary', { hasText: 'Jaringan' }) })
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
            mainOf(page).getByRole('heading', { name: `Langganan ${SUBSCRIPTION}` }),
        ).toBeVisible();
        await expect(page.getByRole('link', { name: 'Akses jaringan' })).toHaveCount(0);
        await expect(page.locator('aside a', { hasText: 'Router NAS' })).toHaveCount(0);
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
        await page.getByRole('button', { name: 'Blokir internet', exact: true }).click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await expectNoHorizontalOverflow(page, 'block modal at 375px dark');
        await page.getByRole('button', { name: 'Batal' }).click();

        await logout(page);
        for (const width of [375, 1280]) {
            for (const dark of [false, true]) {
                await page.goto(state.isolirUrl);
                await setTheme(page, width, dark);
                await expect(
                    page.getByRole('link', { name: 'Buka aplikasi Inbils Demo Company' }),
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
        await page.getByRole('button', { name: 'Aktifkan kembali' }).click();
        await page
            .getByRole('alertdialog', { name: /Aktifkan kembali langganan/ })
            .getByRole('button', { name: 'Aktifkan kembali' })
            .click();
        await expect(page.getByRole('button', { name: 'Tangguhkan', exact: true })).toBeVisible();
        await page.goto(`/admin/subscriptions/${state.subscriptionId}/network-account`);
        await expect(detail(page, 'Status yang diharapkan')).toContainText('Aktif');
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
    framed_ip: string;
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
    const lab = { routerId: '', subscriptionId: '', speedGroup: '' };

    test.afterAll(() => {
        scenario?.kill();
    });

    test('the router is registered as the lab NAS and FreeRADIUS restarts to read it', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        lab.subscriptionId = await subscriptionIdOf(page, SUBSCRIPTION);

        // The CoA target is the management IP of the asset: the lab NAS listens on the host loopback.
        const assets = await inertiaProps(page, `/admin/network-assets?search=${ROUTER_ASSET}`);
        const assetId = (assets.props?.assets?.data as Array<{ id: string; code: string }>).find(
            (item) => item.code === ROUTER_ASSET,
        )!.id;
        await page.goto(`/admin/network-assets/${assetId}/edit`);
        await page.getByLabel('IP manajemen').fill('127.0.0.1');
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page.getByText('Aset jaringan diperbarui.')).toBeVisible();

        await page.goto('/admin/nas-devices/create');
        await page.getByLabel('Aset router').fill(ROUTER_ASSET);
        await page.getByRole('option', { name: new RegExp(ROUTER_ASSET) }).click();
        await expect(page.getByLabel('Alamat NAS')).toHaveValue('127.0.0.1');
        await page.getByLabel('Alamat NAS').fill(LAB_NAS_ADDRESS);
        await page.getByLabel('Port CoA').fill(LAB_COA_PORT);
        await page.getByLabel('Secret RADIUS').fill(labSecret('LAB_RADIUS_SECRET'));
        await page.getByRole('button', { name: 'Daftarkan router' }).click();
        await expect(page).toHaveURL(/\/admin\/nas-devices\/[0-9A-HJKMNP-TV-Z]{26}$/i);
        lab.routerId = page.url().match(/\/admin\/nas-devices\/([0-9A-HJKMNP-TV-Z]{26})$/i)![1];

        // The client row is in the lab database and the page says so.
        await expect(detail(page, 'Klien RADIUS')).toContainText('Tertulis ke RADIUS');
        await expect(detail(page, 'Alamat NAS')).toHaveText(LAB_NAS_ADDRESS);
        await expect(detail(page, 'Target CoA')).toHaveText(`127.0.0.1:${LAB_COA_PORT}`);
        await expect(detail(page, 'Realm')).toHaveText(REALM);
        expect(await page.locator('body').innerText()).not.toContain(
            labSecret('LAB_RADIUS_SECRET'),
        );
        await expect(
            page.getByText('RADIUS belum dikonfigurasi untuk perusahaan ini.'),
        ).toHaveCount(0);

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
        const speed = await detail(page, 'Profil kecepatan').innerText();
        lab.speedGroup = speed.match(/grup RADIUS (\S+)/)![1];

        await page.locator('#account-username').fill(LAB_USERNAME);
        await page.getByLabel(/^Kata sandi( \*)?$/).fill(labSecret('LAB_USER_SECRET'));
        await page.getByRole('button', { name: 'Buat akun jaringan' }).click();

        await expect(detail(page, 'Nama pengguna').first()).toHaveText(`${LAB_USERNAME}@${REALM}`);
        await expect(detail(page, 'Status sinkronisasi')).toContainText('Tersinkron');
        await expect(detail(page, 'Grup RADIUS')).toContainText(`${REALM}/${lab.speedGroup}`);
        await expect(detail(page, 'Status yang diharapkan')).toContainText('Aktif');
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
        await page.getByRole('button', { name: 'Blokir internet', exact: true }).click();
        await submitBlockModal(
            page,
            `Blokir internet ${SUBSCRIPTION}?`,
            'E2E lab abuse test',
            'Blokir internet',
        );
        await expect(
            page.getByText('Blokir disimpan. Sesi pelanggan diputus setelah router menjawab.'),
        ).toBeVisible();
        await expect(page.getByText('Diblokir', { exact: true })).toBeVisible();

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
        await expect(detail(page, 'Status yang diharapkan')).toContainText(
            'Diisolir karena diblokir.',
        );
        await expect(detail(page, 'Grup RADIUS')).toContainText(`${REALM}/isolir`);
        await expect(detail(page, 'Status sinkronisasi')).toContainText('Tersinkron');
        await expect(detail(page, 'Online')).toHaveText('Online');
    });

    test('Unblock disconnects the isolated session and the next login gets the speed profile', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto(`/admin/subscriptions/${lab.subscriptionId}`);
        await page.getByRole('button', { name: 'Buka blokir', exact: true }).click();
        await submitBlockModal(
            page,
            `Buka blokir internet ${SUBSCRIPTION}?`,
            'E2E lab unblock',
            'Buka blokir',
        );
        await expect(
            page.getByText(
                'Blokir dibuka. Sesi pelanggan diputus setelah router menjawab dan tersambung ulang dengan layanan normal.',
            ),
        ).toBeVisible();

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
        await expect(detail(page, 'Status yang diharapkan')).toContainText('Aktif');
        await expect(detail(page, 'Status yang diharapkan')).not.toContainText('Diisolir karena');
        await expect(detail(page, 'Grup RADIUS')).toContainText(`${REALM}/${lab.speedGroup}`);
    });

    test('a static IP from IPAM is the Framed-IP-Address of the session and a release hands the NAS pool back', async ({
        page,
    }) => {
        const user = `${LAB_USERNAME}@${REALM}`;
        await loginAs(page, 'admin');

        // Lowest free address of the seeded static pool (the only one, so the dialog preselects it); QUEUE_CONNECTION=sync
        // runs the apply job inside the request.
        await page.goto(`/admin/subscriptions/${lab.subscriptionId}`);
        await page.getByRole('button', { name: 'Berikan IP statis' }).click();
        const dialog = page.getByRole('dialog', { name: `Berikan IP statis ke ${SUBSCRIPTION}` });
        await expect(dialog.getByRole('combobox', { name: 'Pool' })).toHaveValue('10.99.0.0/24');
        await dialog.getByRole('button', { name: 'Berikan alamat' }).click();
        await expect(dialog).toBeHidden();

        const held = (await inertiaProps(page, `/admin/subscriptions/${lab.subscriptionId}`)).props
            ?.ipAddresses as Array<{ address: string }>;
        expect(held).toHaveLength(1);
        const staticIp = held[0].address;
        expect(staticIp).toMatch(/^10\.99\.0\.\d+$/);

        // The CPE dials in: the reply carries the IPAM address next to the speed profile.
        const connected = JSON.parse(sim('connect', user)) as SimSession;
        expect(connected.framed_ip).toBe(staticIp);
        expect(connected.attributes['Framed-IP-Address']).toEqual([staticIp]);
        expect(connected.attributes['Mikrotik-Group']).toEqual([lab.speedGroup]);

        await page.goto(`/admin/subscriptions/${lab.subscriptionId}/network-account`);
        await expect(detail(page, 'Online')).toHaveText('Online');
        await expect(detail(page, 'Alamat')).toHaveText(staticIp);

        // Release: the open session is cut and the next login gets an address of the NAS pool.
        const cutsBefore = labEvents().length;
        await page.goto(`/admin/subscriptions/${lab.subscriptionId}`);
        await page.getByRole('button', { name: `Lepas ${staticIp}` }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Lepas alamat' }).click();
        await expect(page.getByRole('alertdialog')).toBeHidden();
        await expect(page.getByText('Dinamis (diberikan router)')).toBeVisible();

        await expect.poll(() => labEvents().length).toBe(cutsBefore + 1);
        await expect
            .poll(() => simJson<SimSession[]>('list', '--state', 'active', '--user', user))
            .toHaveLength(0);

        const redialled = JSON.parse(sim('connect', user)) as SimSession;
        expect(redialled.framed_ip).not.toBe(staticIp);
        expect(redialled.framed_ip).toMatch(/^10\.99\.1\.\d+$/);
        expect(redialled.attributes['Framed-IP-Address']).toBeUndefined();
        expect(redialled.attributes['Mikrotik-Group']).toEqual([lab.speedGroup]);
        sim('disconnect', redialled.id);
    });
});
