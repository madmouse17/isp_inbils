/**
 * Self-check for scripts/e2e-setup.mjs — the sanitized setup boundary of the
 * canonical `npm run test:e2e` chain.
 *
 * Proves, against the real wrapper process:
 * 1. A deliberately polluted parent shell (APP_ENV, APP_DEBUG, DB_* and
 *    SESSION_* variables set) no longer gets rejected by setup.php's guards
 *    — the pollution is discarded and the reset proceeds on the canonical
 *    database (--check mode; no database connection or migration happens).
 * 2. E2E_DATABASE is refused outright: the disposable-schema mechanism is
 *    gone, so even the legacy temporary hermes_qa_t_0bd1699e_* namespace no
 *    longer re-targets the run — the wrapper exits 1 before any PHP process.
 * 3. Values of dropped keys never reach the child's visible output.
 * 4. The reset refuses to start while a canonical suite holds the e2e mutex.
 * 5. SIGTERM reaches PHP and the mutex stays held until PHP has exited (stub
 *    PHP binary, so no database is touched).
 */

import { spawn, spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';
import { chmodSync, existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import { fileURLToPath } from 'node:url';

import { acquireCanonicalMutex, defaultLockPath, readLockPayload } from './e2e-mutex.mjs';

const wrapper = fileURLToPath(new URL('./e2e-setup.mjs', import.meta.url));

const osFloor = Object.fromEntries(
    ['PATH', 'Path', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'COMSPEC', 'PATHEXT']
        .map((key) => [key, process.env[key]])
        .filter(([, value]) => value !== undefined),
);

const poisonedValue = 'e2e-selfcheck-poison-not-a-credential';

const runWrapper = (extraEnv, args = ['--check']) =>
    spawnSync(process.execPath, [wrapper, ...args], {
        env: { ...osFloor, ...extraEnv },
        encoding: 'utf8',
        windowsHide: true,
    });

test('setup boundary discards polluted parent shell and targets the canonical database', () => {
    const result = runWrapper({
        APP_ENV: 'production',
        APP_DEBUG: 'true',
        APP_URL: 'http://hostile.example',
        DB_CONNECTION: 'mysql',
        DB_HOST: '10.9.8.7',
        DB_PORT: '3306',
        DB_DATABASE: 'hostile_shared',
        DB_USERNAME: 'polluted_user',
        DB_PASSWORD: poisonedValue,
        SESSION_DRIVER: 'database',
    });
    const output = `${result.stdout}${result.stderr}`;

    assert.equal(
        result.status,
        0,
        `expected exit 0 under polluted parent shell, got ${result.status}\n${output}`,
    );
    assert.match(output, /dropped from parent shell \(names only\): /);
    // Without E2E_DATABASE, the canonical contract resolves .env.e2e's
    // DB_DATABASE; the wrapper only prints it when non-empty.
    assert.match(output, /target database \(from \.env\.e2e DB_DATABASE\): inbils_test/);
    assert.match(output, /E2E database guard passed for /);
    assert.equal(
        output.includes(poisonedValue),
        false,
        'dropped DB_PASSWORD value must never appear in child output',
    );
});

test('setup boundary refuses E2E_DATABASE outright, including the legacy temporary namespace', () => {
    for (const candidate of [
        'inbils_e2e',
        'inbils',
        'hostile_shared',
        'hermes_qa_t_0bd1699e_setupselfcheck',
    ]) {
        const result = runWrapper({ E2E_DATABASE: candidate });
        const output = `${result.stdout}${result.stderr}`;

        assert.equal(result.status, 1, `expected exit 1 for ${candidate}\n${output}`);
        assert.match(
            output,
            /E2E_DATABASE is no longer supported/,
            `expected E2E_DATABASE refusal for ${candidate}`,
        );
    }
});

test('reset refuses to run while a canonical suite holds the e2e mutex', () => {
    const mutex = acquireCanonicalMutex({ runner: 'scripts/e2e-setup.selfcheck.mjs' });

    try {
        const result = runWrapper({}, []);
        const output = `${result.stdout}${result.stderr}`;

        assert.equal(result.status, 1, `expected exit 1 while the mutex is held\n${output}`);
        assert.match(output, /Refusing concurrent canonical E2E run/);
        assert.doesNotMatch(output, /E2E database guard passed/, 'setup.php must not start');
    } finally {
        mutex.release();
    }
});

test(
    'SIGTERM keeps the mutex until the PHP child has exited',
    { skip: process.platform === 'win32', timeout: 10_000 },
    async () => {
        const dir = mkdtempSync(join(tmpdir(), 'inbils-e2e-setup-'));
        const stub = join(dir, 'php');
        // The stub exits by itself after ~5 s, so a failing run never leaves it behind and no stale PID is killed.
        writeFileSync(
            stub,
            "#!/bin/sh\ntrap 'echo stopping; sleep 0.5; echo stopped; exit 143' TERM\necho ready\ni=0\nwhile [ $i -lt 50 ]; do sleep 0.1; i=$((i + 1)); done\n",
        );
        chmodSync(stub, 0o755);

        const wrapperProcess = spawn(process.execPath, [wrapper], {
            env: { ...osFloor, PHP_BINARY: stub },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        let output = '';
        wrapperProcess.stdout.on('data', (chunk) => {
            output += chunk;
        });
        const waitFor = (marker) =>
            new Promise((resolve, reject) => {
                const deadline = Date.now() + 3000;
                const check = () => {
                    if (output.includes(marker)) return resolve();
                    if (Date.now() > deadline)
                        return reject(new Error(`no "${marker}" in:\n${output}`));
                    setTimeout(check, 20);
                };
                check();
            });
        const exited = new Promise((resolve) => wrapperProcess.once('exit', resolve));

        let wrapperLock = null;

        try {
            await waitFor('ready');
            wrapperLock = readLockPayload();
            assert.equal(
                wrapperLock?.ownerPid,
                wrapperProcess.pid,
                'mutex must be held by the wrapper during the reset',
            );

            wrapperProcess.kill('SIGTERM');
            await waitFor('stopping');
            assert.equal(
                existsSync(defaultLockPath),
                true,
                'mutex must stay held while PHP is still stopping',
            );

            const exitCode = await exited;
            assert.match(output, /stopped/);
            assert.equal(exitCode, 143);
            assert.equal(
                existsSync(defaultLockPath),
                false,
                'mutex must be released after PHP exits',
            );
        } finally {
            // A failing run must not leave this wrapper's lock behind to block real e2e runs.
            if (wrapperProcess.exitCode === null && wrapperProcess.signalCode === null) {
                wrapperProcess.kill('SIGKILL');
            }
            await exited;
            // Only this wrapper's own lock (same nonce and owner), never one a later run took over.
            const leftover = readLockPayload();
            if (
                wrapperLock !== null &&
                leftover?.nonce === wrapperLock.nonce &&
                leftover.ownerPid === wrapperProcess.pid
            ) {
                rmSync(defaultLockPath, { force: true });
            }
            rmSync(dir, { recursive: true, force: true });
        }
    },
);
