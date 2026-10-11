/**
 * Sanitized launcher for `php tests/e2e/setup.php` — the reset step of the
 * canonical `npm run test:e2e` chain.
 *
 * Why this exists: setup.php inherits the invoking shell verbatim and
 * phpdotenv does not override variables that already exist in the process
 * environment. A parent shell exporting APP_ENV/DB_* therefore made the E2E
 * guards reject the canonical command before the sanitized webserver boundary
 * (scripts/e2e-webserver.mjs) could ever prove itself. This wrapper reuses
 * buildE2EServerEnv() — the exact allowlist used for `artisan serve` — so the
 * whole canonical chain discards parent-shell APP_ENV/DB_* pollution while
 * setup.php's own guards still enforce the canonical database contract
 * (`.env.e2e` DB_DATABASE=inbils_test; E2E_DATABASE is refused).
 *
 * The child receives a names-only sanitation report on stdout; values of
 * dropped keys are never copied or logged.
 */

import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';

import { buildE2EServerEnv } from './e2e-server-env.mjs';

const php = process.env.PHP_BINARY || 'php';
const args = ['tests/e2e/setup.php', ...process.argv.slice(2)];
const envFilePath = '.env.e2e';

let sanitized;

try {
    sanitized = buildE2EServerEnv();
} catch (error) {
    console.error(error instanceof Error ? error.message : String(error));
    process.exit(1);
}

if (sanitized.droppedSensitiveNames.length > 0) {
    console.log(
        `[e2e-setup] setup.php environment sanitized; dropped from parent shell ` +
            `(names only): ${sanitized.droppedSensitiveNames.join(', ')}`,
    );
}

let declaredDatabase = sanitized.env.DB_DATABASE;
if (!declaredDatabase) {
    // Canonical contract: resolve the target schema from .env.e2e when no
    // E2E_DATABASE/DB_DATABASE reaches the child. Schema names are not
    // credentials; setup.php prints the same value.
    try {
        const contents = readFileSync(envFilePath, 'utf8');
        const match = /^\s*DB_DATABASE\s*=\s*(.*)$/m.exec(contents);

        if (match) {
            declaredDatabase = match[1].trim().replace(/^["']|["']$/g, '');
        }
    } catch {
        // No .env.e2e: setup.php will raise its own missing-file error.
    }
}

if (declaredDatabase) {
    console.log(`[e2e-setup] target database (from .env.e2e DB_DATABASE): ${declaredDatabase}`);
}

const child = spawn(php, args, {
    env: sanitized.env,
    stdio: 'inherit',
    windowsHide: true,
});

child.once('error', (error) => {
    console.error(error);
    process.exit(1);
});

child.once('close', (code, signal) => {
    const exitCode = code ?? (signal ? 1 : 0);
    process.exit(exitCode);
});
