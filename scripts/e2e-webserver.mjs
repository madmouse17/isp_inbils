import { spawn, spawnSync } from 'node:child_process';
import { existsSync, lstatSync, readdirSync } from 'node:fs';
import { createServer } from 'node:net';
import { setTimeout as delay } from 'node:timers/promises';

const host = '127.0.0.1';
const port = 8010;
const hotPath = 'public/hot';
const manifestPath = 'public/build/manifest.json';
const isWindows = process.platform === 'win32';
let child;
let childExited = false;
let childExitCode = 0;
let childExitResolve = () => {};
let finalizing = false;
let shutdownStarted = false;

const childExitPromise = new Promise((resolve) => {
    childExitResolve = resolve;
});

const die = (message) => {
    console.error(message);
    process.exit(1);
};

const staleHotBackups = () =>
    readdirSync('public', { withFileTypes: true })
        .filter((entry) => /^hot\.e2e-\d+\.bak$/.test(entry.name))
        .map((entry) => `public/${entry.name}`);

const assertPortFree = () =>
    new Promise((resolve, reject) => {
        const server = createServer();

        server.once('error', reject);
        server.listen(port, host, () =>
            server.close((error) => (error ? reject(error) : resolve())),
        );
    });

const waitForChildExit = async (timeoutMs) => {
    if (childExited) return true;

    return Promise.race([childExitPromise.then(() => true), delay(timeoutMs).then(() => false)]);
};

const killWindowsTree = async () => {
    if (!child?.pid || childExited) return true;

    const result = spawnSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], {
        stdio: 'inherit',
        windowsHide: true,
    });

    if (result.error) {
        console.error(result.error);
    }

    return waitForChildExit(10_000);
};

const killPosixGroup = async () => {
    if (!child?.pid || childExited) return true;

    try {
        process.kill(-child.pid, 'SIGTERM');
    } catch (error) {
        if (error.code !== 'ESRCH') console.error(error);
    }

    if (await waitForChildExit(5_000)) return true;

    try {
        process.kill(-child.pid, 'SIGKILL');
    } catch (error) {
        if (error.code !== 'ESRCH') console.error(error);
    }

    return waitForChildExit(2_000);
};

const finalize = (code) => {
    if (finalizing) return;
    finalizing = true;
    process.exit(process.exitCode ?? code);
};

const shutdown = async (code) => {
    if (shutdownStarted) return;
    shutdownStarted = true;

    const stopped = isWindows ? await killWindowsTree() : await killPosixGroup();

    if (!stopped) {
        console.error('E2E server shutdown timed out before owned process tree exited.');
        process.exitCode = 1;
    }

    finalize(process.exitCode ?? code);
};

for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
    process.once(signal, () => void shutdown(signal === 'SIGINT' ? 130 : 1));
}

process.once('uncaughtException', (error) => {
    console.error(error);
    void shutdown(1);
});
process.once('unhandledRejection', (error) => {
    console.error(error);
    void shutdown(1);
});

if (!existsSync(manifestPath) || !lstatSync(manifestPath).isFile()) {
    die(`E2E requires built assets: ${manifestPath} missing. Run npm run build.`);
}

const ownedHotBackup = process.env.E2E_HOT_BACKUP?.trim();
const expectedHotBackup = /^public\/hot\.e2e-\d+\.bak$/;
const stale = staleHotBackups();

if (existsSync(hotPath)) {
    die(`E2E refuses ${hotPath}; run npm script so scripts/e2e-run.mjs can neutralize it safely.`);
}

if (ownedHotBackup) {
    if (!expectedHotBackup.test(ownedHotBackup)) {
        die(`E2E refuses invalid hot backup path: ${ownedHotBackup}`);
    }

    if (!stale.includes(ownedHotBackup)) {
        die(`E2E refuses unowned hot backup: ${ownedHotBackup}`);
    }

    if (!existsSync(ownedHotBackup) || !lstatSync(ownedHotBackup).isFile()) {
        die(`E2E refuses ambiguous ${ownedHotBackup}; expected regular file.`);
    }

    if (stale.length !== 1) {
        die(`E2E refuses stale hot backups: ${stale.join(', ')}`);
    }
} else if (stale.length > 0) {
    die(`E2E refuses stale hot backup: ${stale.join(', ')}`);
}

await assertPortFree().catch((error) => {
    if (error.code === 'EADDRINUSE') {
        die(`E2E port ${host}:${port} is already in use; refusing stale server.`);
    }

    throw error;
});

child = spawn('php', ['artisan', 'serve', '--env=e2e', `--host=${host}`, `--port=${port}`], {
    detached: !isWindows,
    stdio: 'inherit',
    windowsHide: true,
});

child.once('error', (error) => {
    childExited = true;
    childExitCode = 1;
    childExitResolve(childExitCode);
    console.error(error);
    void shutdown(1);
});

child.once('exit', (code, signal) => {
    childExited = true;
    childExitCode = code ?? (signal ? 1 : 0);
    childExitResolve(childExitCode);

    if (!shutdownStarted) {
        finalize(childExitCode);
    }
});
