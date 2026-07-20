import { spawn, spawnSync } from 'node:child_process';
import { existsSync, lstatSync, readdirSync, renameSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';

const hotPath = 'public/hot';
const hotBackup = `public/hot.e2e-${process.pid}.bak`;
const playwrightCli = 'node_modules/@playwright/test/cli.js';
const isWindows = process.platform === 'win32';
let child;
let childExited = false;
let childExitCode = 0;
let childExitResolve = () => {};
let finalizing = false;
let restored = false;
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

const restoreHot = () => {
    if (restored) return;
    restored = true;

    if (!existsSync(hotBackup)) return;

    if (existsSync(hotPath)) {
        console.error(`E2E hot restore refused: ${hotPath} appeared during run.`);
        process.exitCode = 1;
        return;
    }

    renameSync(hotBackup, hotPath);
};

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
    restoreHot();
    process.exit(process.exitCode ?? code);
};

const shutdown = async (code) => {
    if (shutdownStarted) return;
    shutdownStarted = true;

    const stopped = isWindows ? await killWindowsTree() : await killPosixGroup();

    if (!stopped) {
        console.error('E2E runner shutdown timed out before owned process tree exited.');
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

if (!existsSync(playwrightCli)) {
    die(`E2E requires local Playwright CLI: ${playwrightCli} missing. Run npm install.`);
}

const stale = staleHotBackups();
if (stale.length > 0) {
    die(`E2E refuses stale hot backup: ${stale.join(', ')}`);
}

if (existsSync(hotPath)) {
    if (!lstatSync(hotPath).isFile()) {
        die(`E2E refuses ambiguous ${hotPath}; expected regular file.`);
    }

    renameSync(hotPath, hotBackup);
}

child = spawn(process.execPath, [playwrightCli, 'test', ...process.argv.slice(2)], {
    detached: !isWindows,
    env: { ...process.env, E2E_HOT_BACKUP: existsSync(hotBackup) ? hotBackup : '' },
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
