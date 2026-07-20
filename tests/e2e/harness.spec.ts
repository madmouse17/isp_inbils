import { expect, test } from './support/fixtures';

test('@smoke serves built Laravel app without browser failures', async ({ page, baseURL }) => {
    const response = await page.goto('/');

    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).not.toBeEmpty();
    await assertBuiltAssets(page, baseURL!);
});

async function assertBuiltAssets(page: import('@playwright/test').Page, baseURL: string) {
    const appOrigin = new URL(baseURL).origin;
    const assets = await page
        .locator('script[src], link[rel="stylesheet"][href], link[rel="modulepreload"][href]')
        .evaluateAll((elements) =>
            elements.map((element) => ({
                type: element instanceof HTMLScriptElement ? 'script' : 'link',
                url:
                    element instanceof HTMLScriptElement
                        ? element.src
                        : (element as HTMLLinkElement).href,
            })),
        );

    expect(assets.length).toBeGreaterThan(0);

    for (const { url } of assets) {
        expect(url, `asset URL must not use Vite client: ${url}`).not.toContain('@vite/client');
        expect(url, `asset URL must not use Vite dev server: ${url}`).not.toContain(':5173');
        expect(url, `asset URL must not use public/hot: ${url}`).not.toContain('/hot');
    }

    const appAssets = assets.filter(({ url }) => new URL(url).origin === appOrigin);
    const appScripts = appAssets.filter(({ type }) => type === 'script');

    expect(appScripts.length, 'page must include at least one app-origin script').toBeGreaterThan(
        0,
    );
    expect(
        appAssets.some(({ url }) => new URL(url).pathname.includes('/build/')),
        'page must include at least one app-origin built asset',
    ).toBe(true);

    for (const { url } of appAssets) {
        expect(url, `app-origin asset URL must use built bundle: ${url}`).toContain('/build/');
    }
}
