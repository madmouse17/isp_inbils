/**
 * @package-categories Service package categories (.scratch/package-categories/spec.md)
 *
 * Coverage:
 * - Kategori Paket: create, rename, a used category refuses deletion with the server's message, an unused one is deleted
 * - a package must take a category; the package list shows it and filters by it
 * - the package picker of customer create groups packages by category and puts the category of the customer type first
 * - a deactivated category leaves the picker
 * - Sales KPI and Business Metrics offer the "Kategori paket" filter and keep it after Run
 *
 * Deterministic: unique records per run. A category that holds a package cannot be deleted, so the run
 * leaves one inactive category and one package behind; e2e-setup resets them.
 */
import { expect, test } from './support/fixtures';
import { loginAs, selectFirstSearchOption, selectSearchOption, unique } from './support/demo';

const runId = unique('PKGCAT');
const suffix = runId.split('-').slice(1).join('').toUpperCase();
const categoryCode = `E2E${suffix}`.slice(0, 30);
const categoryName = `E2E Kategori ${suffix}`;
const renamedName = `${categoryName} Baru`;
const spareCode = `SP${suffix}`.slice(0, 30);
const spareName = `E2E Cadangan ${suffix}`;
const packageCode = `E2E-PKG-${suffix}`;
const packageName = `E2E Paket ${suffix}`;

test.describe('@package-categories', () => {
    test.describe.configure({ mode: 'serial' });

    test('a category is created, renamed and listed', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/service-package-categories');

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'Kategori Paket' })).toBeVisible();
        // The seeded defaults.
        for (const name of ['Rumahan', 'Bisnis', 'Korporat']) {
            await expect(main.getByRole('row').filter({ hasText: name }).first()).toBeVisible();
        }

        await main.getByRole('button', { name: 'Buat Kategori Paket' }).first().click();
        const dialog = page.getByRole('dialog', { name: 'Buat Kategori Paket' });
        await dialog.getByLabel('Kode').fill(categoryCode.toLowerCase());
        await dialog.getByLabel('Nama').fill(categoryName);
        await dialog.getByLabel('Urutan').fill('90');
        await dialog.getByRole('button', { name: 'Simpan' }).click();
        await expect(dialog).toBeHidden();

        const row = main.getByRole('row').filter({ hasText: categoryName });
        await expect(row).toBeVisible();
        // The server upper-cases the code.
        await expect(row).toContainText(categoryCode);
        await expect(row).toContainText('Aktif');

        await row.getByRole('button', { name: 'Ubah' }).click();
        const editDialog = page.getByRole('dialog', { name: 'Ubah Kategori Paket' });
        await expect(editDialog.getByLabel('Kode')).toBeDisabled();
        await expect(editDialog.getByLabel('Nama')).toHaveValue(categoryName);
        await editDialog.getByLabel('Nama').fill(renamedName);
        await editDialog.getByRole('button', { name: 'Simpan' }).click();
        await expect(editDialog).toBeHidden();
        await expect(main.getByRole('row').filter({ hasText: renamedName })).toBeVisible();
    });

    test('a duplicate code is refused next to the field', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/service-package-categories');

        await page
            .getByRole('main')
            .getByRole('button', { name: 'Buat Kategori Paket' })
            .first()
            .click();
        const dialog = page.getByRole('dialog', { name: 'Buat Kategori Paket' });
        await dialog.getByLabel('Kode').fill(categoryCode);
        await dialog.getByLabel('Nama').fill('Salinan');
        await dialog.getByRole('button', { name: 'Simpan' }).click();

        await expect(dialog.getByText('Kode kategori sudah dipakai.')).toBeVisible();
        await dialog.getByRole('button', { name: 'Batal' }).click();
        await expect(dialog).toBeHidden();
    });

    test('a package needs a category; the list shows it and filters by it', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/service-packages/create');

        await page.getByLabel('Kode').fill(packageCode);
        await page.getByLabel('Nama *', { exact: true }).fill(packageName);
        await selectFirstSearchOption(page, 'Profil bandwidth');
        await selectFirstSearchOption(page, 'Profil kecepatan');
        await selectFirstSearchOption(page, 'Tingkat SLA');
        await page.getByLabel('Harga bulanan').fill('150000');
        await page.getByLabel('Masa kontrak minimum (bulan)').fill('12');

        // No category chosen: the server refuses the save, names the field, and the form stays.
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/service-packages\/create/);
        await expect(page.getByRole('combobox', { name: 'Kategori' })).toHaveValue('');
        await expect(page.getByText(/kategori wajib diisi/i)).toBeVisible();

        await selectSearchOption(page, 'Kategori', renamedName);
        await page.getByRole('button', { name: 'Simpan', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/service-packages$/);

        await page.goto(`/admin/service-packages?search=${encodeURIComponent(packageCode)}`);
        const main = page.getByRole('main');
        const row = main.getByRole('row').filter({ hasText: packageName });
        await expect(row).toBeVisible();
        await expect(row).toContainText(renamedName);

        // Filter by category: every row left is of that category.
        await page.goto('/admin/service-packages');
        await main.getByRole('combobox', { name: 'Kategori' }).click();
        await page.getByRole('option', { name: renamedName }).click();
        await expect(page).toHaveURL(/service_package_category_id=[0-9A-HJKMNP-TV-Z]{26}/i);
        await expect(main.getByRole('row').filter({ hasText: packageName })).toBeVisible();
        await expect(page.locator('tbody tr').filter({ hasNotText: renamedName })).toHaveCount(0);

        // The category list counts the package and links to this filtered list.
        await page.goto('/admin/service-package-categories');
        const categoryRow = main.getByRole('row').filter({ hasText: renamedName });
        await expect(categoryRow.getByRole('link', { name: '1', exact: true })).toBeVisible();
    });

    test('the customer package picker groups by category and leads with the customer type', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/customers/create');
        await expect(
            page.getByRole('main').getByRole('heading', { name: 'Buat Pelanggan' }),
        ).toBeVisible();

        const firstGroup = async () => {
            await page.getByRole('tab', { name: 'Langganan & SPK' }).click();
            await page.getByRole('combobox', { name: 'Paket layanan' }).click();
            const groups = page.getByRole('listbox').getByRole('group');
            await expect(groups.first()).toBeVisible();

            return groups.first();
        };

        // Perorangan: Rumahan first.
        await expect(await firstGroup()).toHaveAccessibleName('Rumahan');
        // The new category and its package are listed under their own heading.
        const own = page.getByRole('group', { name: renamedName });
        await expect(own.getByRole('option', { name: new RegExp(packageName) })).toBeVisible();
        await page.keyboard.press('Escape');

        // Perusahaan: Bisnis first.
        await page.getByRole('tab', { name: 'Data pelanggan' }).click();
        await page.getByLabel('Tipe', { exact: true }).selectOption('Company');
        await expect(await firstGroup()).toHaveAccessibleName('Bisnis');

        // Keyboard order follows the groups: the first Down lands on the first option of the first group.
        await page.getByRole('combobox', { name: 'Paket layanan' }).press('ArrowDown');
        const active = await page
            .getByRole('combobox', { name: 'Paket layanan' })
            .getAttribute('aria-activedescendant');
        const firstOption = page
            .getByRole('listbox')
            .getByRole('group')
            .first()
            .getByRole('option')
            .first();
        await expect(firstOption).toHaveAttribute('id', active ?? '');

        // Typing narrows the list to the matching options, headed by their category.
        await page.getByRole('combobox', { name: 'Paket layanan' }).fill(packageName);
        await expect(page.getByRole('listbox').getByRole('group')).toHaveCount(1);
        await page.getByRole('option', { name: new RegExp(packageName) }).click();
        await expect(page.getByRole('combobox', { name: 'Paket layanan' })).toHaveValue(
            packageName,
        );
    });

    test('Sales KPI and Business Metrics filter by package category', async ({ page }) => {
        await loginAs(page, 'admin');

        // The filter is a SearchSelect: by role, because the clear button of a chosen field is also labelled
        // "Hapus pilihan: Kategori paket". It shows the chosen category's name.
        await page.goto('/admin/reports/sales');
        await expect(page.getByRole('combobox', { name: 'Kategori paket' })).toBeVisible();
        await selectSearchOption(page, 'Kategori paket', 'Rumahan');
        await page.getByRole('button', { name: 'Tampilkan', exact: true }).click();
        await expect(page).toHaveURL(/service_package_category_id=[0-9A-HJKMNP-TV-Z]{26}/i);
        await expect(page.getByRole('combobox', { name: 'Kategori paket' })).toHaveValue('Rumahan');

        await page.goto('/admin/reports/business');
        await expect(page.getByRole('combobox', { name: 'Kategori paket' })).toBeVisible();
        await selectSearchOption(page, 'Kategori paket', 'Bisnis');
        await page.getByRole('button', { name: 'Tampilkan', exact: true }).click();
        await expect(page).toHaveURL(/service_package_category_id=[0-9A-HJKMNP-TV-Z]{26}/i);
        await expect(page.getByRole('combobox', { name: 'Kategori paket' })).toHaveValue('Bisnis');
        await expect(
            page.getByText('Aset, SLA, dan pelanggan baru tidak terpengaruh.'),
        ).toBeVisible();
    });

    test('a used category cannot be deleted, an unused one can, and a deactivated one leaves the picker', async ({
        page,
    }) => {
        await loginAs(page, 'admin');
        await page.goto('/admin/service-package-categories');
        const main = page.getByRole('main');

        const askDelete = async (name: string) => {
            await main
                .getByRole('row')
                .filter({ hasText: name })
                .getByRole('button', { name: 'Aksi lainnya' })
                .click();
            await page.getByRole('menuitem', { name: 'Hapus' }).click();
        };

        // Used by the package of this run: the server's message stays in the dialog.
        await askDelete(renamedName);
        const confirm = page.getByRole('alertdialog');
        await confirm.getByRole('button', { name: 'Hapus', exact: true }).click();
        await expect(
            confirm.getByText('Kategori masih dipakai 1 paket. Nonaktifkan saja.'),
        ).toBeVisible();
        await confirm.getByRole('button', { name: 'Batal' }).click();
        await expect(confirm).toBeHidden();
        await expect(main.getByRole('row').filter({ hasText: renamedName })).toBeVisible();

        // Unused: deleted.
        await main.getByRole('button', { name: 'Buat Kategori Paket' }).first().click();
        const dialog = page.getByRole('dialog', { name: 'Buat Kategori Paket' });
        await dialog.getByLabel('Kode').fill(spareCode);
        await dialog.getByLabel('Nama').fill(spareName);
        await dialog.getByRole('button', { name: 'Simpan' }).click();
        await expect(dialog).toBeHidden();
        await askDelete(spareName);
        await confirm.getByRole('button', { name: 'Hapus', exact: true }).click();
        await expect(main.getByRole('row').filter({ hasText: spareName })).toHaveCount(0);

        // Deactivated: still listed, no longer offered for packages.
        await main
            .getByRole('row')
            .filter({ hasText: renamedName })
            .getByRole('button', { name: 'Ubah' })
            .click();
        const edit = page.getByRole('dialog', { name: 'Ubah Kategori Paket' });
        await expect(edit.getByLabel('Nama')).toHaveValue(renamedName);
        await edit.getByRole('switch', { name: 'Aktif' }).click();
        await edit.getByRole('button', { name: 'Simpan' }).click();
        await expect(edit).toBeHidden();
        await expect(main.getByRole('row').filter({ hasText: renamedName })).toContainText(
            'Nonaktif',
        );

        await page.goto('/admin/service-packages/create');
        await page.getByRole('combobox', { name: 'Kategori' }).click();
        await expect(page.getByRole('option', { name: renamedName })).toHaveCount(0);
        await page.keyboard.press('Escape');

        await page.goto('/admin/customers/create');
        await page.getByRole('tab', { name: 'Langganan & SPK' }).click();
        await page.getByRole('combobox', { name: 'Paket layanan' }).click();
        await expect(page.getByRole('listbox').getByRole('group').first()).toBeVisible();
        await expect(page.getByRole('group', { name: renamedName })).toHaveCount(0);
    });
});
