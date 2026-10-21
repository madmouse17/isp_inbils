import { expect, test } from './support/fixtures';
import { loginAs } from './support/demo';

test('@regression admin billing and spk indexes render without page errors', async ({ page }) => {
    const pageErrors: Error[] = [];
    page.on('pageerror', (error) => pageErrors.push(error));

    await loginAs(page, 'admin');

    await page.goto('/admin/spk');
    await expect(page.getByRole('heading', { name: 'SPK' })).toBeVisible();

    await page.goto('/admin/invoices');
    await expect(page.getByRole('heading', { name: 'Tagihan', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Buat tagihan bulanan' })).toBeVisible();

    expect(pageErrors).toEqual([]);
});

test('@regression a native validity bubble speaks Indonesian and lets go of the field once it is edited', async ({
    page,
}) => {
    await loginAs(page, 'admin');
    // A bare form on the page: the listener is on the document, so no form of the app is needed to see it.
    await page.evaluate(() => {
        document.body.insertAdjacentHTML(
            'beforeend',
            '<form id="validity-probe"><input name="a" required><input name="b" type="email"><input name="c" type="number" min="3" max="9"><input name="d" minlength="4"></form>',
        );
    });
    const probe = (name: string) => page.locator(`#validity-probe [name="${name}"]`);
    const messages = () =>
        page.evaluate(() => {
            const form = document.querySelector<HTMLFormElement>('#validity-probe')!;
            // A person leaves the field before sending the form, and the browser fires the field's pending `change` on
            // that blur. reportValidity() would fire it only after the bubble was worded, and the release of the text
            // on change would then clear the one field still being edited.
            (document.activeElement as HTMLElement | null)?.blur();
            form.reportValidity();

            return Object.fromEntries(
                [...form.elements].map((el) => [
                    (el as HTMLInputElement).name,
                    (el as HTMLInputElement).validationMessage,
                ]),
            );
        });

    await probe('b').fill('bukan-email');
    await probe('c').fill('2');
    await probe('d').fill('ab');
    expect(await messages()).toEqual({
        a: 'Wajib diisi.',
        b: 'Format tidak sesuai.',
        c: 'Nilai paling kecil 3.',
        d: 'Paling sedikit 4 karakter.',
    });

    await probe('c').fill('10');
    expect((await messages()).c).toBe('Nilai paling besar 9.');

    // A custom message blocks a form until it is cleared, so editing the field must clear it at once.
    await probe('a').fill('isi');
    await expect(probe('a')).toHaveJSProperty('validationMessage', '');
});

test('@regression the first Tab offers a skip link that lands in the page', async ({ page }) => {
    await loginAs(page, 'admin');

    await page.keyboard.press('Tab');
    const skip = page.getByRole('link', { name: 'Langsung ke konten' });
    await expect(skip).toBeFocused();
    expect((await skip.boundingBox())?.width).toBeGreaterThan(40);

    await page.keyboard.press('Enter');
    await expect(page.getByRole('main')).toBeFocused();
});

test('@regression the toaster opens below a bar pinned to the top, not over it', async ({
    page,
}) => {
    await loginAs(page, 'admin');
    const region = page.locator('[data-toast-region]');
    const top = () => region.evaluate((el) => el.getBoundingClientRect().top);

    expect(await top()).toBe(16);

    // The support banner and the subsidiary ribbon publish their height under these names.
    await page.evaluate(() => {
        document.documentElement.style.setProperty('--support-banner-height', '90px');
        document.documentElement.style.setProperty('--parent-ribbon-height', '40px');
    });
    expect(await top()).toBe(146);
});
