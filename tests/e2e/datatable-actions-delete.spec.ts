import { expect, test } from './support/fixtures';
import { loginAs } from './support/demo';

/**
 * QA-ISP t_2121a815 remediation proof — async delete lifecycle on the
 * canonical DataTableActions component (resources/js/Components/composite/
 * DataTableActions.tsx).
 *
 * The canonical contract under test:
 *   1. The delete request is OWNED by DataTableActions (deleteHref), not a
 *      fire-and-forget consumer callback.
 *   2. Exactly ONE request is issued per confirm — double-clicks during the
 *      in-flight window must not double-submit.
 *   3. While the request is in flight the dialog stays open and BOTH Cancel
 *      and Delete stay disabled (deleteInFlight guard + processing state).
 *   4. On success the dialog closes (and the server-side table reloads).
 *   5. On error the dialog STAYS OPEN and the buttons re-enable so the user
 *      can retry — the dialog must not go stale.
 *
 * Target consumer: Admin/Users index (server-side DataTable + DataTableActions
 * with deleteHref). A dedicated user row is created via the admin UI so the
 * assertions never touch the seeded demo users that the login relies on.
 *
 * Both positive and error path tests run under the strict support/fixtures
 * wrapper. The error path test registers its expected HTTP 500 via
 * `expectHttpError` so the failure audit allows the intentional failure evidence.
 */

const DELETE_URL_PATTERN = /\/admin\/users\/[0-9A-HJKMNP-TV-Z]{26}$/i;

async function openUsersIndex(page: import('@playwright/test').Page) {
    await loginAs(page, 'admin');
    await page.goto('/admin/users');
    await expect(page.getByRole('main').getByRole('heading', { name: 'Pengguna' })).toBeVisible({
        timeout: 15_000,
    });
}

async function createDisposableUser(page: import('@playwright/test').Page) {
    const name = `QA Delete ${Date.now()}`;
    await page.goto('/admin/users/create');
    await expect(
        page.getByRole('main').getByRole('heading', { name: 'Buat Pengguna', level: 1 }),
    ).toBeVisible({
        timeout: 15_000,
    });
    await page.getByLabel('Nama').fill(name);
    await page.getByLabel('Email').fill(`qa-delete-${Date.now()}@example.test`);
    await page.getByLabel(/^Kata sandi( \*)?$/).fill('Password123!');
    await page.getByRole('button', { name: 'Buat', exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/users\/?$/);
    await expect(page.getByRole('main').getByRole('heading', { name: 'Pengguna' })).toBeVisible();
    return name;
}

async function findRow(page: import('@playwright/test').Page, name: string) {
    const searchInput = page.getByLabel('Cari', { exact: true });
    await searchInput.fill(name);
    await expect(page).toHaveURL(
        new RegExp(`search=${encodeURIComponent(name).replace(/\+/g, '%20')}`),
    );
    const row = page.getByRole('row').filter({ hasText: name }).first();
    await expect(row).toBeVisible({ timeout: 15_000 });
    return row;
}

test('@datatable-actions-delete lifecycle: single request, disabled while pending, dialog closes on success', async ({
    page,
}) => {
    await openUsersIndex(page);

    // Assert server-authoritative self-row capability: logged-in admin has no Delete action.
    const selfRow = await findRow(page, 'Demo Admin');
    await selfRow.getByRole('button', { name: 'Aksi lainnya' }).click();
    await expect(page.getByRole('menuitem', { name: 'Hapus' })).toHaveCount(0);
    await page.keyboard.press('Escape');

    const name = await createDisposableUser(page);

    // Prove the row's DataTableActions renders the Delete action for authorized other user.
    const row = await findRow(page, name);
    await row.getByRole('button', { name: 'Aksi lainnya' }).click();
    await expect(page.getByRole('menuitem', { name: 'Hapus' })).toBeVisible();
    await page.keyboard.press('Escape');

    // Intercept the destroy request: count DELETE hits, and hold the response
    // open long enough to observe the in-flight disabled state.
    let deleteRequestCount = 0;
    let releaseDelete = (): void => {};
    const deleteHeld = new Promise<void>((resolve) => {
        releaseDelete = resolve;
    });

    await page.route(DELETE_URL_PATTERN, async (route) => {
        if (route.request().method() !== 'DELETE') {
            return route.continue();
        }
        deleteRequestCount += 1;
        await deleteHeld;
        await route.continue();
    });

    // Open the confirm dialog.
    await row.getByRole('button', { name: 'Aksi lainnya' }).click();
    await page.getByRole('menuitem', { name: 'Hapus' }).click();
    const dialog = page.getByRole('alertdialog');
    await expect(dialog).toBeVisible();

    // Double-click the dialog's Delete button rapidly — the sync in-flight
    // guard must collapse the second click into the first request. Dispatch
    // both activations natively in a single evaluate tick: a second Playwright
    // click would wait for the button to become enabled again, which only
    // happens after the held request is released (test deadlock).
    const dialogDelete = dialog.getByRole('button', { name: 'Hapus' });
    await dialogDelete.evaluate((button) => {
        const el = button as HTMLButtonElement;
        el.click();
        el.click();
    });

    // While the request is held: exactly one DELETE issued, buttons disabled.
    await expect.poll(() => deleteRequestCount, { timeout: 5_000 }).toBe(1);
    await expect(dialogDelete).toBeDisabled();
    await expect(dialog.getByRole('button', { name: 'Batal' })).toBeDisabled();

    // Release the request; the dialog must close on success.
    releaseDelete();
    await expect(dialog).not.toBeVisible({ timeout: 15_000 });
    await expect(row).not.toBeVisible({ timeout: 15_000 });
    await expect.poll(() => deleteRequestCount, { timeout: 5_000 }).toBe(1);
});

// Error path: dialog stays open, buttons re-enable, exactly one request.
// Scoped expected error registration informs the strict fixture without bypassing it.
test('@datatable-actions-delete error: dialog stays open and re-enables on failure', async ({
    page,
    expectHttpError,
}) => {
    expectHttpError({ url: DELETE_URL_PATTERN, status: 500, method: 'DELETE' });

    let name: string | null = null;
    try {
        await openUsersIndex(page);
        name = await createDisposableUser(page);
        const row = await findRow(page, name);
        await row.getByRole('button', { name: 'Aksi lainnya' }).click();
        await expect(page.getByRole('menuitem', { name: 'Hapus' })).toBeVisible();
        await page.keyboard.press('Escape');

        let deleteRequestCount = 0;
        await page.route(DELETE_URL_PATTERN, async (route) => {
            if (route.request().method() !== 'DELETE') {
                return route.continue();
            }
            deleteRequestCount += 1;
            await route.fulfill({
                status: 500,
                contentType: 'text/html',
                body: '<html><body>boom</body></html>',
            });
        });

        await row.getByRole('button', { name: 'Aksi lainnya' }).click();
        await page.getByRole('menuitem', { name: 'Hapus' }).click();
        const dialog = page.getByRole('alertdialog');
        await expect(dialog).toBeVisible();

        await dialog.getByRole('button', { name: 'Hapus' }).click();
        await expect.poll(() => deleteRequestCount, { timeout: 5_000 }).toBe(1);

        // Dialog must NOT close after the failed request...
        await expect(dialog).toBeVisible({ timeout: 10_000 });
        // ...and the buttons must re-enable so the user can retry.
        await expect(dialog.getByRole('button', { name: 'Hapus' })).toBeEnabled({
            timeout: 10_000,
        });
        await expect(dialog.getByRole('button', { name: 'Batal' })).toBeEnabled();
        await expect.poll(() => deleteRequestCount, { timeout: 5_000 }).toBe(1);
    } finally {
        if (name) {
            await page.unroute(DELETE_URL_PATTERN);
            await page.goto('/admin/users');
            const searchInput = page.getByLabel('Cari', { exact: true });
            await searchInput.fill(name);
            const residualRow = page.getByRole('row').filter({ hasText: name }).first();
            if (await residualRow.isVisible({ timeout: 5_000 }).catch(() => false)) {
                const menuButton = residualRow.getByRole('button', { name: 'Aksi lainnya' });
                if (await menuButton.isVisible().catch(() => false)) {
                    await menuButton.click();
                    await page.getByRole('menuitem', { name: 'Hapus' }).click();
                    const confirmDialog = page.getByRole('alertdialog');
                    if (await confirmDialog.isVisible({ timeout: 5_000 }).catch(() => false)) {
                        await confirmDialog.getByRole('button', { name: 'Hapus' }).click();
                        await expect(confirmDialog).not.toBeVisible({ timeout: 15_000 });
                    }
                }
            }
        }
    }
});

// A customer with subscriptions makes the user type its code; the test never submits the delete.
test('@datatable-actions-delete customer with subscriptions stays undeletable until its code is typed', async ({
    page,
}) => {
    await loginAs(page, 'admin');
    // E2eFixtureSeeder gives this customer the subscription E2E-LINKED-SUB.
    await page.goto('/admin/customers?search=E2E%20Linked%20Customer');
    await expect(page.getByRole('main').getByRole('heading', { name: 'Pelanggan' })).toBeVisible({
        timeout: 15_000,
    });

    const row = page.getByRole('row').filter({ hasText: 'E2E Linked Customer' }).first();
    await row.getByRole('button', { name: 'Aksi lainnya' }).click();
    await page.getByRole('menuitem', { name: 'Hapus' }).click();
    const dialog = page.getByRole('alertdialog');
    const prompt = await dialog.getByText(/^Ketik \S+ untuk menghapus/).innerText();
    const code = /^Ketik (\S+) untuk/.exec(prompt)![1];

    const confirm = dialog.getByRole('button', { name: 'Hapus', exact: true });
    await expect(confirm).toBeDisabled();
    await dialog.getByLabel(/^Ketik .* untuk menghapus/).fill(code);
    await expect(confirm).toBeEnabled();

    await dialog.getByRole('button', { name: 'Batal' }).click();
    await expect(dialog).not.toBeVisible();
});
