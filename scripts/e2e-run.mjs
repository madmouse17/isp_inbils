import { spawn, spawnSync } from 'node:child_process';
import { existsSync, lstatSync, readdirSync, renameSync } from 'node:fs';
import { createServer } from 'node:net';
import { setTimeout as delay } from 'node:timers/promises';

import { settleE2EArtifacts } from './e2e-artifacts.mjs';
import { getDescendantPids, getProcessStartToken, probeProcessLiveness } from './e2e-mutex.mjs';
import { killPidTree } from './e2e-server-reap.mjs';

const hotPath = 'public/hot';
const hotBackup = `public/hot.e2e-${process.pid}.bak`;
const playwrightCli = 'node_modules/@playwright/test/cli.js';
const isWindows = process.platform === 'win32';
// Single absolute proof deadline for the descendant tree/port terminal check
// that must pass before the hot rollback is allowed to proceed.
const teardownProofTimeoutMs = 15_000;
let child;
let childStartToken = null;
let childExited = false;
let childExitCode = 0;
let childExitResolve = () => {};
let finalizing = false;
let restored = false;
let shutdownStarted = false;
// Descendants of the Playwright child (the E2E webserver tree) tracked while the
// root is alive so teardown can prove them terminal before rolling hot back.
const ownedDescendants = new Map(); // pid -> startToken|null
const ownedPorts = new Set([8010]);
let descendantDiscoveryVerified = true;
let preExitDiscoveryRecorded = false;
let descendantPoll = null;

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

// Discover the descendant tree while parent linkage is intact. Structured
// observability is mandatory: an error/unknown state is durable teardown doubt,
// never equivalent to a proven empty tree.
const collectDescendants = (rootPid) => {
    let discovery;
    try {
        discovery = getDescendantPids(rootPid);
    } catch (error) {
        descendantDiscoveryVerified = false;
        return { state: 'unknown', pids: [], reason: error.message };
    }

    const state = discovery?.state;
    const pids = Array.isArray(discovery?.pids) ? discovery.pids : [];
    if (!['known', 'known-empty'].includes(state)) {
        descendantDiscoveryVerified = false;
        return { state: 'unknown', pids, reason: discovery?.reason ?? 'malformed-discovery' };
    }

    for (const dPid of pids) {
        if (dPid === rootPid) continue;
        const existingToken = ownedDescendants.get(dPid);
        const refreshedToken = getProcessStartToken(dPid) ?? existingToken ?? null;
        if (!ownedDescendants.has(dPid) || (!existingToken && refreshedToken)) {
            ownedDescendants.set(dPid, refreshedToken);
        }
    }
    return { state, pids };
};

const recordPreExitDiscovery = () => {
    if (!child?.pid || childExited) return false;
    if (!childStartToken) childStartToken = getProcessStartToken(child.pid) ?? null;
    const discovery = collectDescendants(child.pid);
    preExitDiscoveryRecorded = true;
    if (!['known', 'known-empty'].includes(discovery.state)) {
        preExitDiscoveryRecorded = false;
    }
    return preExitDiscoveryRecorded;
};

const collectPostExitDescendants = (rootPid) => {
    if (isWindows) return collectDescendants(rootPid);

    const query = spawnSync('ps', ['-eo', 'pid=,pgid='], {
        encoding: 'utf8',
        timeout: 1000,
        windowsHide: true,
    });
    if (query.error || query.status !== 0 || String(query.stderr ?? '').trim()) {
        descendantDiscoveryVerified = false;
        return { state: 'unknown', pids: [], reason: 'process-group discovery failed' };
    }

    const pids = [];
    for (const line of String(query.stdout ?? '').split(/\r?\n/)) {
        if (!line.trim()) continue;
        const match = /^\s*(\d+)\s+(\d+)\s*$/.exec(line);
        if (!match) {
            descendantDiscoveryVerified = false;
            return { state: 'unknown', pids, reason: 'malformed process-group output' };
        }
        const pid = Number(match[1]);
        const groupId = Number(match[2]);
        if (groupId === rootPid && pid !== rootPid) pids.push(pid);
    }

    for (const pid of pids) {
        if (!ownedDescendants.has(pid)) {
            ownedDescendants.set(pid, getProcessStartToken(pid) ?? null);
        }
    }
    return { state: pids.length > 0 ? 'known' : 'known-empty', pids };
};

const isPortFree = (port, timeoutMs) =>
    new Promise((resolve) => {
        const probe = createServer();
        let settled = false;
        const finish = (value) => {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            resolve(value);
        };
        const timer = setTimeout(
            () => {
                probe.close(() => finish(false));
                finish(false);
            },
            Math.max(1, timeoutMs),
        );
        timer.unref?.();
        probe.unref();
        probe.once('error', () => finish(false));
        probe.listen(port, '127.0.0.1', () => probe.close(() => finish(true)));
    });

// Terminal proof covers every tracked immutable PID and the durable webserver
// port, all under the caller's single absolute deadline.
const terminalProof = async (deadline) => {
    if (!descendantDiscoveryVerified || Date.now() >= deadline) return false;
    const rootTerminal =
        childExited ||
        (child?.pid &&
            probeProcessLiveness(child.pid, {
                timeout: Math.min(1000, deadline - Date.now()),
                expectedStartToken: childStartToken ?? undefined,
            }).state === 'dead');
    if (!rootTerminal) return false;
    for (const [dPid, token] of ownedDescendants) {
        const remaining = deadline - Date.now();
        if (remaining <= 0) return false;
        const liveness = probeProcessLiveness(dPid, {
            timeout: Math.min(1000, remaining),
            expectedStartToken: token ?? undefined,
        });
        if (liveness.state !== 'dead') return false;
    }
    for (const port of ownedPorts) {
        const remaining = deadline - Date.now();
        if (remaining <= 0 || !(await isPortFree(port, remaining))) return false;
    }
    return true;
};

// Identity-safe kill of every tracked descendant before the root is signaled,
// mirroring the canonical runner's token-guarded (Windows) / ownership-guarded
// (POSIX) teardown so a historical/tokenless PID is never signaled.
const killOwnedDescendants = (deadline) => {
    for (const [dPid, token] of ownedDescendants) {
        const remaining = deadline - Date.now();
        if (remaining <= 0) return false;
        const res = killPidTree(
            dPid,
            remaining,
            token ? { expectedStartToken: token } : { identitySafe: true },
        );
        if (res.status !== 0) {
            console.error(
                '[e2e-run] identity-safe descendant kill refused for PID ' + dPid + ':',
                res.output,
            );
            return false;
        }
    }
    return true;
};

const killWindowsTree = async (deadline) => {
    if (!child?.pid || childExited) return true;

    // Kill the descendant tree first, then the root, all within one deadline.
    if (ownedDescendants.size > 0 && !killOwnedDescendants(deadline)) return false;

    // Fail-closed: exact identity validation before and during kill.
    // If token is unknown/unverified, do not fall back to PID-only kill.
    const token = childStartToken;
    if (token) {
        const remaining = deadline - Date.now();
        if (remaining <= 0) return false;
        const killRes = killPidTree(child.pid, remaining, {
            expectedStartToken: token,
        });
        if (killRes.status !== 0) {
            console.error(
                '[e2e-run] identity-safe kill error for PID ' + child.pid + ':',
                killRes.output,
            );
        }
    } else {
        console.error(
            '[e2e-run] Refusing unverified kill: start token unknown for PID ' + child.pid + '.',
        );
    }

    const remaining = deadline - Date.now();
    return remaining > 0 ? waitForChildExit(remaining) : false;
};

const killPosixGroup = async (deadline) => {
    if (!child?.pid || childExited) return true;

    // POSIX has no immutable process-start token. Never signal individually
    // tracked descendant PIDs here: a descendant may have exited and its numeric
    // PID may already belong to a foreign process. The detached root's process
    // group is the only live ownership primitive established by spawn().
    try {
        process.kill(-child.pid, 'SIGTERM');
    } catch (error) {
        if (error.code !== 'ESRCH') console.error(error);
    }

    let remaining = deadline - Date.now();
    if (remaining <= 0) return false;
    if (await waitForChildExit(remaining)) return true;

    try {
        process.kill(-child.pid, 'SIGKILL');
    } catch (error) {
        if (error.code !== 'ESRCH') console.error(error);
    }

    remaining = deadline - Date.now();
    return remaining > 0 ? waitForChildExit(remaining) : false;
};

const finalize = async (code, deadline = Date.now() + teardownProofTimeoutMs) => {
    if (finalizing) return;
    finalizing = true;
    if (descendantPoll) clearInterval(descendantPoll);

    // Discovery after root exit cannot prove that a child was not reparented in
    // the exit race. Require a successful pre-exit snapshot; terminal proof then
    // covers every identity captured by that snapshot and the owned port.
    if (child?.pid) {
        const postExitDiscovery = collectPostExitDescendants(child.pid);
        if (!['known', 'known-empty'].includes(postExitDiscovery.state)) {
            console.error(
                '[e2e-run] post-exit descendant discovery unverified; refusing hot rollback.',
            );
            descendantDiscoveryVerified = false;
        }
        if (!preExitDiscoveryRecorded) {
            console.error(
                '[e2e-run] normal root exit without pre-exit discovery proof; refusing hot rollback.',
            );
            descendantDiscoveryVerified = false;
        }
    }
    if (!(await terminalProof(deadline))) {
        console.error(
            '[e2e-run] Descendant tree/owned port not terminal or discovery unverified; refusing hot rollback (backup retained).',
        );
        process.exitCode = 1;
    } else {
        restoreHot();
    }

    settleE2EArtifacts(process.exitCode ?? code);
    process.exit(process.exitCode ?? code);
};

const shutdown = async (code) => {
    if (shutdownStarted) return;
    shutdownStarted = true;

    // One absolute deadline shared by discovery, kill and proof.
    const deadline = Date.now() + teardownProofTimeoutMs;

    // Final discovery while the root is still alive (parent linkage intact).
    if (child?.pid && !childExited) recordPreExitDiscovery();

    const stopped = isWindows ? await killWindowsTree(deadline) : await killPosixGroup(deadline);

    if (!stopped) {
        console.error('E2E runner shutdown timed out before owned process tree exited.');
        process.exitCode = 1;
    }

    await finalize(process.exitCode ?? code, deadline);
};

const signalHandlers = new Map();
for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
    const handler = () => void shutdown(signal === 'SIGINT' ? 130 : 1);
    signalHandlers.set(signal, handler);
    process.on(signal, handler);
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

if (child.pid) {
    childStartToken = getProcessStartToken(child.pid);
}

// Track the descendant tree (the E2E webserver spawned by Playwright's
// webServer config) while the root is alive, so teardown can prove it terminal
// before rolling hot back. Parent linkage is only reliable pre-exit.
descendantPoll = setInterval(() => {
    if (!child?.pid || childExited) return;
    recordPreExitDiscovery();
}, 1000);

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
        void finalize(childExitCode);
    }
});

// Capture ownership before a fast child exit can beat first poll tick.
recordPreExitDiscovery();
