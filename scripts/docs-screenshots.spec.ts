import { mkdirSync, readFileSync } from 'node:fs';

import { expect, test, type Page } from '@playwright/test';

import { demoPassword, userFor } from '../tests/e2e/support/demo';

type Action = { click: string } | { fill: [string, string] } | { waitFor: string };
type Screen = { slug: string; url: string; auth?: boolean; actions?: Action[]; fullPage?: boolean };
type Manifest = { viewport: { width: number; height: number }; screens: Screen[] };

const manifest: Manifest = JSON.parse(readFileSync('scripts/docs-screenshots.json', 'utf8'));
const outDir = 'docs/user-guide/images/web';

test.use({ viewport: manifest.viewport, colorScheme: 'light', locale: 'id-ID' });

async function runAction(page: Page, action: Action) {
    if ('click' in action) await page.locator(action.click).click();
    else if ('fill' in action) await page.locator(action.fill[0]).fill(action.fill[1]);
    else await page.locator(action.waitFor).waitFor();
}

async function shoot(page: Page, screen: Screen) {
    await page.goto(screen.url);
    for (const action of screen.actions ?? []) await runAction(page, action);
    await page.waitForLoadState('networkidle');
    await page.screenshot({
        path: `${outDir}/${screen.slug}.png`,
        fullPage: screen.fullPage ?? false,
    });
}

test('docs screenshots', async ({ page }) => {
    mkdirSync(outDir, { recursive: true });

    // One bad selector must not hide the rest: every failure is listed at the end.
    const failures: string[] = [];
    const shootAll = async (screens: Screen[]) => {
        for (const screen of screens) {
            try {
                await shoot(page, screen);
            } catch (error) {
                failures.push(`${screen.slug}: ${String(error).split('\n')[0]}`);
            }
        }
    };

    // Guest screens first: logging in redirects /login away.
    await shootAll(manifest.screens.filter((s) => s.auth === false));

    await page.goto('/login');
    await page.getByLabel('Email').fill(userFor('admin').email);
    await page.getByLabel('Kata sandi').fill(demoPassword);
    await page.getByRole('button', { name: 'Masuk' }).click();
    await expect(page.getByRole('main').getByRole('heading', { name: 'Dasbor' })).toBeVisible({
        timeout: 15_000,
    });

    await shootAll(manifest.screens.filter((s) => s.auth !== false));

    expect(failures, 'screens that did not render').toEqual([]);
});
