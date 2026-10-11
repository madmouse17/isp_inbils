import { spawn, spawnSync } from 'node:child_process';
import {
    existsSync,
    lstatSync,
    mkdirSync,
    readFileSync,
    readdirSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { createServer } from 'node:net';
import { setTimeout as delay } from 'node:timers/promises';

import { buildE2EServerEnv, resolvePhpBinary } from './e2e-server-env.mjs';
import { randomUUID } from 'node:crypto';
import {
    e2eRuntimeDir,
    e2eServerPidFile,
    killPidTree,
    probePortListener,
    reapOrphanedE2EServers,
    safelyReapPidFile,
} from './e2e-server-reap.mjs';
import { getDescendantPids, getProcessStartToken, probeProcessLiveness } from './e2e-mutex.mjs';

const host = '127.0.0.1';
const port = 8010;
const configCachePath = 'bootstrap/cache/config.php';
const hotPath = 'public/hot';
const manifestPath = 'public/build/manifest.json';
const runtimeDir = e2eRuntimeDir;
const pidFile = e2eServerPidFile(process.pid);
const isWindows = process.platform === 'win32';
const currentNonce = randomUUID();
let child;
let childExited = false;
let childExitCode = 0;
let childExitResolve = () => {};
let finalizing = false;
let shutdownStarted = false;
const teardownTimeoutMs = 15_000;
const ownedDescendants = new Map();
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

let currentPayload = null;

const releasePidFile = () => {
    try {
        if (!existsSync(pidFile)) return true;
        if (!currentPayload) return false;
        const removed = safelyReapPidFile(pidFile, currentPayload);
        return removed === true && !existsSync(pidFile);
    } catch (error) {
        console.error(`[e2e-webserver] PID-file release failed: ${error.message}`);
        return false;
    }
};

const collectOwnedDescendants = () => {
    if (!child?.pid) return { state: 'known-empty', pids: [] };
    let discovery;
    try {
        discovery = getDescendantPids(child.pid);
    } catch (error) {
        descendantDiscoveryVerified = false;
        return { state: 'unknown', pids: [], reason: error.message };
    }
    if (!['known', 'known-empty'].includes(discovery?.state)) {
        descendantDiscoveryVerified = false;
        return { state: 'unknown', pids: [], reason: discovery?.reason ?? 'malformed-discovery' };
    }
    for (const descendantPid of discovery.pids ?? []) {
        if (descendantPid === child.pid) continue;
        const existingToken = ownedDescendants.get(descendantPid);
        const refreshedToken = getProcessStartToken(descendantPid) ?? existingToken ?? null;
        if (!ownedDescendants.has(descendantPid) || (!existingToken && refreshedToken)) {
            ownedDescendants.set(descendantPid, refreshedToken);
        }
    }
    return discovery;
};

const recordPreExitDiscovery = () => {
    if (!child?.pid || childExited) return false;
    const discovery = collectOwnedDescendants();
    preExitDiscoveryRecorded = ['known', 'known-empty'].includes(discovery.state);
    return preExitDiscoveryRecorded;
};

const collectPostExitDescendants = (rootPid) => {
    if (isWindows) return { state: 'known-empty', pids: [] };

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
        if (Number(match[2]) === rootPid && Number(match[1]) !== rootPid) {
            pids.push(Number(match[1]));
        }
    }

    for (const descendantPid of pids) {
        if (!ownedDescendants.has(descendantPid)) {
            ownedDescendants.set(descendantPid, getProcessStartToken(descendantPid) ?? null);
        }
    }
    return { state: pids.length > 0 ? 'known' : 'known-empty', pids };
};

const killOwnedDescendants = (deadline) => {
    for (const [descendantPid, startToken] of ownedDescendants) {
        const remaining = deadline - Date.now();
        if (remaining <= 0) return false;
        if (!startToken) {
            // The descendant may exit between enumeration and token capture.
            // A tokenless PID is never signalable on Windows; only a positive
            // dead probe allows it to leave this invocation's teardown set.
            const liveness = probeProcessLiveness(descendantPid, {
                timeout: Math.min(1000, remaining),
            });
            if (liveness.state === 'dead') {
                ownedDescendants.delete(descendantPid);
                continue;
            }
            console.error(
                `[e2e-webserver] Refusing unverified kill: start token unknown for descendant PID ${descendantPid} ` +
                    `(${liveness.state}${liveness.reason ? `: ${liveness.reason}` : ''}).`,
            );
            return false;
        }
        const result = killPidTree(descendantPid, remaining, {
            expectedStartToken: startToken,
        });
        if (result.status !== 0) return false;
    }
    return true;
};

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

const killWindowsTree = async (deadline) => {
    if (!child?.pid || childExited) return true;
    if (!killOwnedDescendants(deadline)) return false;

    // Fail-closed: exact identity validation before and during kill.
    // If token is unknown/unverified, do not fall back to PID-only kill.
    const token = currentPayload?.processStartToken;
    if (token) {
        const remaining = deadline - Date.now();
        if (remaining <= 0) return false;
        const killRes = killPidTree(child.pid, remaining, {
            expectedStartToken: token,
        });
        if (killRes.status !== 0) {
            console.error(
                '[e2e-webserver] identity-safe kill error for PID ' + child.pid + ':',
                killRes.output,
            );
        }
    } else {
        console.error(
            '[e2e-webserver] Refusing unverified kill: start token unknown for PID ' +
                child.pid +
                '.',
        );
    }

    const remaining = deadline - Date.now();
    return remaining > 0 ? waitForChildExit(remaining) : false;
};

const killPosixGroup = async (deadline) => {
    if (!child?.pid || childExited) return true;

    // POSIX exposes no immutable start token. Do not signal individually cached
    // descendant PIDs: historical linkage cannot rule out PID reuse. The
    // detached root's process group is the only live ownership authority.
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

/**
 * Terminal teardown proof for the owned PID file.
 *
 * The PID file may only be released after the owned child identity is proven
 * dead AND the E2E port is proven free. Every other state (child exit
 * unobserved, child still alive, liveness/port unverified, listener still
 * bound) fails closed: the file survives for audit / later reap.
 */
const proveOwnedTeardownTerminal = (deadline) => {
    if (!currentPayload) return { terminal: true, reason: 'no-owned-pid-payload' };

    if (child?.pid && !childExited) {
        return { terminal: false, reason: 'owned-child-exit-unobserved' };
    }

    const recordedPid = currentPayload.pid;
    const rootBudget = deadline - Date.now();
    if (rootBudget <= 0) return { terminal: false, reason: 'teardown deadline exhausted' };
    const liveness = probeProcessLiveness(recordedPid, {
        timeout: Math.min(1000, rootBudget),
        expectedStartToken: currentPayload.processStartToken,
    });

    if (liveness.state === 'alive') {
        return { terminal: false, reason: `owned child PID ${recordedPid} is still alive` };
    }

    if (liveness.state !== 'dead') {
        return {
            terminal: false,
            reason: `owned child PID ${recordedPid} liveness unverified (${liveness.reason ?? liveness.state})`,
        };
    }

    if (!preExitDiscoveryRecorded || !descendantDiscoveryVerified) {
        return {
            terminal: false,
            reason: preExitDiscoveryRecorded
                ? 'owned descendant discovery unverified'
                : 'normal root exit without pre-exit descendant discovery proof',
        };
    }
    for (const [descendantPid, startToken] of ownedDescendants) {
        const remaining = deadline - Date.now();
        if (remaining <= 0) return { terminal: false, reason: 'teardown deadline exhausted' };
        const descendantLiveness = probeProcessLiveness(descendantPid, {
            timeout: Math.min(1000, remaining),
            expectedStartToken: startToken ?? undefined,
        });
        if (descendantLiveness.state !== 'dead') {
            return {
                terminal: false,
                reason: `owned descendant PID ${descendantPid} not terminal`,
            };
        }
    }

    const remaining = deadline - Date.now();
    if (remaining <= 0) return { terminal: false, reason: 'teardown deadline exhausted' };
    const probe = probePortListener(port, Math.min(3000, remaining), {
        expectedListenerPid: recordedPid,
        _probeProcessLiveness: probeProcessLiveness,
    });

    if (probe.state === 'listener') {
        return { terminal: false, reason: `port ${port} still bound by PID ${probe.pid}` };
    }

    if (probe.state !== 'free') {
        return { terminal: false, reason: `port ${port} status unverified (${probe.reason})` };
    }

    return { terminal: true, reason: 'owned child tree dead and port free' };
};

const waitForOwnedTeardownTerminal = async (deadline, intervalMs = 100) => {
    let proof = proveOwnedTeardownTerminal(deadline);

    while (!proof.terminal && Date.now() < deadline) {
        await delay(Math.min(intervalMs, deadline - Date.now()));
        proof = proveOwnedTeardownTerminal(deadline);
    }

    return proof;
};

const finalize = async (code, deadline = Date.now() + teardownTimeoutMs) => {
    if (finalizing) return;
    finalizing = true;
    if (descendantPoll) clearInterval(descendantPoll);
    if (child?.pid && !childExited) recordPreExitDiscovery();
    if (child?.pid && childExited) {
        const postExitDiscovery = collectPostExitDescendants(child.pid);
        if (!['known', 'known-empty'].includes(postExitDiscovery.state)) {
            descendantDiscoveryVerified = false;
            console.error(
                '[e2e-webserver] post-exit descendant discovery unverified; retaining PID file.',
            );
        }
    }

    // Conditional release regardless of the child exit status (0 or nonzero):
    // the gate is the terminal teardown proof, never the pending exit code.
    const proof = await waitForOwnedTeardownTerminal(deadline);

    if (proof.terminal) {
        if (!releasePidFile()) {
            console.error(
                `[e2e-webserver] PID-file release failed for ${pidFile}; retaining failure.`,
            );
            if (!process.exitCode) process.exitCode = code || 1;
        }
    } else {
        console.error(
            `[e2e-webserver] Refusing PID-file release before terminal teardown proof: ${proof.reason}. ` +
                `Leaving ${pidFile} for audit/reap.`,
        );
        // Fail closed with a nonzero status, preserving the child's own code when it failed.
        if (!process.exitCode) process.exitCode = code || 1;
    }

    // Failure status is never washed into success.
    process.exit(process.exitCode ?? code);
};

const shutdown = async (code) => {
    if (shutdownStarted) return;
    shutdownStarted = true;
    const deadline = Date.now() + teardownTimeoutMs;
    if (child?.pid && !childExited) recordPreExitDiscovery();

    const stopped = isWindows ? await killWindowsTree(deadline) : await killPosixGroup(deadline);

    if (!stopped && !process.exitCode) {
        console.error('E2E server shutdown timed out before owned process tree exited.');
        process.exitCode = 1;
    }

    await finalize(process.exitCode ?? code, deadline);
};

const signalHandlers = new Map();
for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
    // External termination (Playwright stopping the webServer after a healthy
    // suite, or an operator signal) is a NORMAL lifecycle stop: the exit status
    // is decided by the terminal teardown proof, not by the signal itself.
    // Terminal proof satisfied => clean exit 0; unverified => fail closed nonzero.
    const handler = () => void shutdown(0);
    signalHandlers.set(signal, handler);
    process.on(signal, handler);
}

// Cross-process IPC termination: on Windows a real signal cannot be delivered
// between processes (child.kill() force-terminates the target), so the outer
// owner mimics Playwright external shutdown through the IPC message channel.
// This routes into the same bounded shutdown path as an in-process signal,
// preserving deterministic terminal proof + conditional PID-file release.
const messageHandler = (msg) => {
    const sig = typeof msg === 'string' ? msg : msg?.signal;
    if (['SIGINT', 'SIGTERM', 'SIGHUP'].includes(sig)) {
        process.emit(sig);
    }
};
process.on('message', messageHandler);

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

if (existsSync(configCachePath)) {
    die(
        `E2E refuses cached Laravel config: ${configCachePath} exists. ` +
            'A stale config cache causes APP_ENV to be frozen, which re-enables ' +
            'Vite::prefetch in the e2e environment and triggers ERR_NO_BUFFER_SPACE. ' +
            'Run: php artisan config:clear',
    );
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

await assertPortFree().catch(async (error) => {
    if (error.code !== 'EADDRINUSE') {
        throw error;
    }

    // A previous run may have been killed externally and left its php server
    // orphaned on the port. Reclaim it (pid files + port listener) before refusing.
    reapOrphanedE2EServers(port);
    await delay(500);
    await assertPortFree().catch((retryError) => {
        if (retryError.code === 'EADDRINUSE') {
            die(
                `E2E port ${host}:${port} is still in use after orphan reap; ` +
                    'free it manually (netstat -ano to find the listener) and retry.',
            );
        }

        throw retryError;
    });
});

const serverEnv = (() => {
    try {
        const sanitized = buildE2EServerEnv();
        const phpBinary = resolvePhpBinary(sanitized.env.PHP_BINARY);
        return { ...sanitized, env: { ...sanitized.env, PHP_BINARY: phpBinary } };
    } catch (error) {
        die(error instanceof Error ? error.message : String(error));
    }
})();

if (serverEnv.droppedSensitiveNames.length > 0) {
    console.log(
        `[e2e-webserver] artisan serve environment sanitized; dropped from parent shell ` +
            `(names only): ${serverEnv.droppedSensitiveNames.join(', ')}`,
    );
}

if (serverEnv.env.DB_DATABASE) {
    // Schema names are not credentials; setup.php prints the same value.
    console.log(`[e2e-webserver] serving canonical shared database: ${serverEnv.env.DB_DATABASE}`);
}

/**
 * Child spawn specification.
 *
 * Production default is always `php artisan serve --env=e2e ...`. The optional
 * `E2E_WEBSERVER_CHILD_SPAWN_JSON` seam exists ONLY so the focused self-check
 * (scripts/e2e-server-reap.selfcheck.mjs) can drive this exact finalize path
 * with a deterministic binary instead of PHP/MySQL. Properties:
 * - absent variable => byte-identical production behaviour (php);
 * - malformed/empty spec fails closed via die() instead of guessing;
 * - the variable is never forwarded to the child: buildE2EServerEnv()'s
 *   allowlist drops it, so a served app can never inherit or re-inject it;
 * - no port/identity/PID-file/teardown rule is relaxed by this seam.
 */
const resolveChildSpawnSpec = () => {
    const productionSpec = {
        command: serverEnv.env.PHP_BINARY,
        args: ['artisan', 'serve', '--env=e2e', `--host=${host}`, `--port=${port}`],
    };

    const raw = process.env.E2E_WEBSERVER_CHILD_SPAWN_JSON?.trim();
    if (!raw) return productionSpec;

    let parsed;
    try {
        parsed = JSON.parse(raw);
    } catch (error) {
        die(
            `E2E refuses malformed E2E_WEBSERVER_CHILD_SPAWN_JSON: ${
                error instanceof Error ? error.message : String(error)
            }`,
        );
    }

    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
        die('E2E refuses E2E_WEBSERVER_CHILD_SPAWN_JSON: expected a JSON object.');
    }

    if (typeof parsed.command !== 'string' || !parsed.command.trim()) {
        die('E2E refuses E2E_WEBSERVER_CHILD_SPAWN_JSON: `command` must be a non-empty string.');
    }

    const args = parsed.args ?? [];
    if (!Array.isArray(args) || args.some((arg) => typeof arg !== 'string')) {
        die('E2E refuses E2E_WEBSERVER_CHILD_SPAWN_JSON: `args` must be an array of strings.');
    }

    console.error(
        '[e2e-webserver] TEST SEAM ACTIVE: child binary injected via ' +
            `E2E_WEBSERVER_CHILD_SPAWN_JSON (${parsed.command.trim()}). ` +
            'Not a production configuration.',
    );

    return { command: parsed.command.trim(), args };
};

const childSpawnSpec = resolveChildSpawnSpec();

child = spawn(childSpawnSpec.command, childSpawnSpec.args, {
    detached: !isWindows,
    env: serverEnv.env,
    stdio: 'inherit',
    windowsHide: true,
});

descendantPoll = setInterval(() => recordPreExitDiscovery(), 250);

mkdirSync(runtimeDir, { recursive: true });

if (child.pid) {
    const nonce = currentNonce;
    const processStartToken = getProcessStartToken(child.pid);
    const pidPayload = {
        nonce,
        pid: child.pid,
        processStartToken,
        parentPid: process.pid,
        parentStartToken: getProcessStartToken(process.pid),
        port,
        runner: 'scripts/e2e-webserver.mjs',
        cwd: process.cwd(),
        startedAt: new Date().toISOString(),
    };
    currentPayload = pidPayload;
    writeFileSync(pidFile, `${JSON.stringify(pidPayload, null, 2)}\n`);
}

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
        void finalize(childExitCode, Date.now() + teardownTimeoutMs);
    }
});

// Capture ownership before a fast child exit can beat first poll tick.
recordPreExitDiscovery();
