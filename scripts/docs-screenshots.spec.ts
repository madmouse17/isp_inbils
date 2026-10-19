import { mkdirSync, readFileSync } from 'node:fs';

import { expect, test, type Page } from '@playwright/test';

import { demoPassword, userFor } from '../tests/e2e/support/demo';

type Action =
    | { click: string }
    | { hover: string }
    | { fill: [string, string] }
    | { waitFor: string };
type Viewport = { width: number; height: number };
// `phone` screens are shot in a touch context (coarse pointer, 2x pixels) of the manifest's `phone` size.
type Screen = {
    slug: string;
    url: string;
    auth?: boolean;
    actions?: Action[];
    fullPage?: boolean;
    phone?: boolean;
};
type Manifest = { viewport: Viewport; phone: Viewport; screens: Screen[] };

const manifest: Manifest = JSON.parse(readFileSync('scripts/docs-screenshots.json', 'utf8'));
const outDir = 'docs/user-guide/images/web';

// The default action timeout is unlimited: without a bound, one selector that no longer matches would hold the run
// until the test timeout instead of being listed with the other failures.
test.use({
    viewport: manifest.viewport,
    colorScheme: 'light',
    locale: 'id-ID',
    actionTimeout: 30_000,
});

async function runAction(page: Page, action: Action) {
    if ('click' in action) await page.locator(action.click).click();
    else if ('hover' in action) await page.locator(action.hover).hover();
    else if ('fill' in action) await page.locator(action.fill[0]).fill(action.fill[1]);
    else await page.locator(action.waitFor).waitFor();
}

async function shoot(page: Page, screen: Screen) {
    await page.goto(screen.url);
    for (const action of screen.actions ?? []) await runAction(page, action);
    // The click that opened the screen leaves the pointer over it, and a port under the pointer shows its tooltip:
    // park the pointer in the corner unless the screen hovers on purpose.
    if (!screen.actions?.some((action) => 'hover' in action)) await page.mouse.move(0, 0);
    await page.waitForLoadState('networkidle');
    await page.screenshot({
        path: `${outDir}/${screen.slug}.png`,
        fullPage: screen.fullPage ?? false,
        // Tooltips and dialogs fade for a moment: take the picture after the fade, not in the middle of it.
        animations: 'disabled',
    });
}

async function login(page: Page) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(userFor('admin').email);
    await page.getByLabel(/^Kata sandi( \*)?$/).fill(demoPassword);
    await page.getByRole('button', { name: 'Masuk' }).click();
    await expect(page.getByRole('main').getByRole('heading', { name: 'Dasbor' })).toBeVisible({
        timeout: 15_000,
    });
}

// One bad screen must not hide the rest: the failures of a run are listed together at the end.
async function shootAll(page: Page, screens: Screen[]): Promise<string[]> {
    const failures: string[] = [];
    for (const screen of screens) {
        try {
            await shoot(page, screen);
        } catch (error) {
            failures.push(`${screen.slug}: ${String(error).split('\n')[0]}`);
        }
    }

    return failures;
}

test('docs screenshots', async ({ page }) => {
    mkdirSync(outDir, { recursive: true });

    // Guest screens first: logging in redirects /login away.
    const guest = await shootAll(page, manifest.screens.filter((s) => s.auth === false));

    await login(page);

    const member = await shootAll(
        page,
        manifest.screens.filter((s) => s.auth !== false && !s.phone),
    );

    expect([...guest, ...member], 'screens that did not render').toEqual([]);
});

test.describe('phone', () => {
    test.use({ viewport: manifest.phone, hasTouch: true, isMobile: true, deviceScaleFactor: 2 });

    test('docs screenshots (phone)', async ({ page }) => {
        mkdirSync(outDir, { recursive: true });

        await login(page);

        expect(
            await shootAll(page, manifest.screens.filter((s) => s.phone)),
            'phone screens that did not render',
        ).toEqual([]);
    });
});
