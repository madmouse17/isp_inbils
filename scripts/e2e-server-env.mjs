/**
 * Explicit, sanitized environment builder for the `php artisan serve --env=e2e`
 * child process spawned by scripts/e2e-webserver.mjs.
 *
 * Why this exists: phpdotenv loads .env.e2e WITHOUT overriding variables that
 * already exist in the real process environment. When the parent shell exports
 * APP_ENV/DB_*, those values silently win over .env.e2e inside the served
 * requests and POST /login dies with MySQL [1045] Access denied -> HTTP 500.
 * The allowlist below guarantees the child only receives OS-level variables
 * required to launch PHP and the downstream Node/Playwright/browser children
 * (profile/temp paths, not Laravel config), plus one pinned APP_ENV=e2e
 * consistent with the `--env=e2e` flag.
 *
 * Canonical database contract: every E2E run targets the single shared MySQL
 * schema `inbils_test` declared in .env.e2e (DB_DATABASE). The disposable
 * E2E_DATABASE mechanism is gone: a parent-shell E2E_DATABASE is REFUSED
 * instead of honored, so no child can be re-targeted to a different schema.
 * The builder therefore needs no schema forwarding — the child environment
 * simply carries no DB_* keys, and phpdotenv inside the served app falls back
 * to .env.e2e's DB_DATABASE=inbils_test naturally.
 *
 * The builder is pure: no filesystem access, no logging, and it never copies
 * the VALUES of dropped keys. `droppedSensitiveNames` reports NAMES ONLY so
 * callers can log what was sanitized without leaking credentials.
 */

const canonicalDatabase = 'inbils_test';

const osAllowlist = [
    'APPDATA',
    'COMSPEC',
    'HOMEDRIVE',
    'HOMEPATH',
    'HOME',
    'PATH',
    'PATHEXT',
    'PROGRAMFILES',
    'PROGRAMFILES(X86)',
    'PROGRAMW6432',
    'SYSTEMDRIVE',
    'SYSTEMROOT',
    'TEMP',
    'TMP',
    'TMPDIR',
    'USERPROFILE',
    'WINDIR',
    'PHP_BINARY',
];

// Opt-in FreeRADIUS lab switches read by tests/e2e/radius-provisioning.spec.ts. Not Laravel
// config; .env.e2e / the lab .env remain the fallback when the shell does not export them.
const labAllowlist = ['E2E_RADIUS_LAB', 'LAB_RADIUS_SECRET', 'LAB_USER_SECRET'];

// Laravel RADIUS config the served app needs to write the lab database (config/services.php,
// config/database.php). Forwarded ONLY while E2E_RADIUS_LAB=1, so a stray shell export never
// re-targets the default (switch-off) run; scripts/e2e-radius-lab.sh exports them from the lab .env.
export const radiusLabServerNames = [
    'RADIUS_ENABLED',
    'RADIUS_DB_HOST',
    'RADIUS_DB_PORT',
    'RADIUS_DB_DATABASE',
    'RADIUS_DB_USERNAME',
    'RADIUS_DB_PASSWORD',
];

const allowedNames = new Set([...osAllowlist, ...labAllowlist]);
const radiusLabNames = new Set(radiusLabServerNames);

export function resolvePhpBinary(value) {
    if (value === undefined) return 'php';

    if (
        typeof value !== 'string' ||
        value.length === 0 ||
        value.trim() === '' ||
        value.includes('\0') ||
        /[\0-\x1f\x7f]/.test(value)
    ) {
        throw new Error(
            'Refusing E2E PHP executable: PHP_BINARY must be a non-empty executable name or path.',
        );
    }

    return value;
}

const sensitiveNamePatterns = [
    // APPDATA is the Windows profile directory, not Laravel config.
    /^APP_(?!DATA$)/i,
    /^DB_/i,
    /^(CACHE|SESSION|QUEUE|MAIL|REDIS|FILESYSTEM|BROADCAST|LOG|PULSE|SANCTUM|AWS)_/i,
    /^XDEBUG/i,
];

export const isConfigSensitiveName = (name) =>
    sensitiveNamePatterns.some((pattern) => pattern.test(name));

export function buildE2EServerEnv(sourceEnv = process.env) {
    const env = {};
    const dropped = [];
    const radiusLab = sourceEnv.E2E_RADIUS_LAB === '1';

    for (const [key, value] of Object.entries(sourceEnv)) {
        if (value === undefined || value === null) continue;

        const name = key.toUpperCase();

        if (allowedNames.has(name) || (radiusLab && radiusLabNames.has(name))) {
            env[key] = value;
        } else {
            dropped.push(key);
        }
    }

    // Pinned explicitly so the runtime environment always matches --env=e2e,
    // regardless of anything inherited from the invoking shell.
    env.APP_ENV = 'e2e';

    // `artisan serve` drops PHP process state after every request, so the `array` cache of .env.e2e would make every
    // cache-backed guard (duplicate submit, throttles) inert: the served app gets a store that outlives a request.
    env.CACHE_STORE = 'database';

    // The E2E run keeps its own storage tree (config/filesystems.php): its database reuses company ids of the dev and
    // PHPUnit ones, so files at `companies/{id}` would mix. Forced like the cache store, so a stale shell or a .env.e2e
    // copied before this key existed cannot send the run into another environment's folders.
    env.STORAGE_ENV_SUFFIX = '/e2e';

    // Canonical contract: the disposable E2E_DATABASE mechanism is gone.
    // A non-empty E2E_DATABASE in the parent shell is refused outright — it
    // can never re-target this (or any) child to a different schema. Without
    // it, the child carries no DB_* keys, and phpdotenv inside the served app
    // loads .env.e2e's DB_DATABASE=inbils_test naturally (see header note).
    const requestedDatabase = sourceEnv.E2E_DATABASE;

    if (typeof requestedDatabase === 'string' && requestedDatabase !== '') {
        throw new Error(
            `Refusing E2E child process: E2E_DATABASE is no longer supported; all E2E runs use ${canonicalDatabase}.`,
        );
    }

    return { env, droppedSensitiveNames: dropped.filter(isConfigSensitiveName) };
}
