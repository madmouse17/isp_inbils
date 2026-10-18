import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';
import type { Page } from '@playwright/test';
import { expect, type ExpectedHttpError } from './fixtures';
import { totp } from './totp';

const source = readFileSync('database/seeders/E2ePlatformSeeder.php', 'utf8');
const constant = (name: string) =>
    new RegExp(`public const ${name} = '([^']+)';`).exec(source)?.[1];

export const platformFixture = {
    operatorEmail: constant('OPERATOR_EMAIL'),
    enrolEmail: constant('ENROL_EMAIL'),
    password: constant('PASSWORD'),
    secret: constant('TOTP_SECRET'),
    tenantCode: constant('TENANT_CODE'),
    tenantAdminEmail: constant('TENANT_ADMIN_EMAIL'),
    tenantAdminPassword: constant('TENANT_ADMIN_PASSWORD'),
};

if (Object.values(platformFixture).some((value) => !value)) {
    throw new Error('Could not derive E2ePlatformSeeder credentials.');
}

const STEP_MS = 30_000;
const LAST_STEP_FILE = 'tests/e2e/.runtime/platform-operator-totp-step';

/**
 * The server takes each code once, from the previous, current or next 30 s step, and only a step newer than the last one
 * spent. Logins closer together than a step walk forward one step each and wait for the clock when the next step is
 * spent too. The last step lives in a file so a restarted worker keeps counting.
 */
async function nextOperatorCode(secret: string): Promise<string> {
    mkdirSync(dirname(LAST_STEP_FILE), { recursive: true });
    const last = existsSync(LAST_STEP_FILE) ? Number(readFileSync(LAST_STEP_FILE, 'utf8')) : 0;
    const step = Math.max(Math.floor(Date.now() / STEP_MS), last + 1);
    const wait = (step - 1) * STEP_MS - Date.now();

    if (wait > 0) await sleep(wait);
    writeFileSync(LAST_STEP_FILE, String(step));

    return totp(secret, step * STEP_MS);
}

export async function loginAsPlatformOperator(page: Page) {
    await page.goto('/platform/login');
    await page.getByLabel('Email').fill(platformFixture.operatorEmail!);
    await page.getByLabel(/^Kata sandi( \*)?$/).fill(platformFixture.password!);
    await page.getByRole('button', { name: 'Masuk' }).click();
    await expect(page).toHaveURL(/\/platform\/two-factor-challenge$/);
    await page.getByLabel('Kode autentikasi').fill(await nextOperatorCode(platformFixture.secret!));
    await page.getByRole('button', { name: 'Masuk' }).click();
    await expect(page).toHaveURL(/\/platform\/?$/);
    await expect(page.getByRole('main').getByRole('heading', { name: 'Dasbor' })).toBeVisible();
}

/**
 * Starting and stopping support access answer 409 with X-Inertia-Location (Inertia::location): the browser then loads
 * the tenant app or the company page as a full page. Register it before the spec starts or stops support access.
 */
export function expectSupportAccessRedirects(
    expectHttpError: (expectation: ExpectedHttpError) => void,
) {
    expectHttpError({
        url: /\/platform\/companies\/[0-9A-HJKMNP-TV-Z]{26}\/users\/[0-9A-HJKMNP-TV-Z]{26}\/impersonate/i,
        method: 'POST',
        status: 409,
    });
    expectHttpError({ url: '/platform/impersonation/stop', method: 'POST', status: 409 });
}
