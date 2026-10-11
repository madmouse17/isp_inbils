/**
 * Canonical runner contract for `npm run test:e2e`.
 *
 * Enforced contract (AGENTS.md Test Database Rule): every automated
 * test/QA/browser/E2E run MUST use the single shared MySQL schema `inbils_test`.
 * No disposable/random schema is created and no schema is dropped — the
 * effective database is resolved from .env.e2e (DB_DATABASE=inbils_test), the
 * guard matrix and setup.php fail closed if the effective database is anything
 * else, and this runner pins the same schema into every child environment.
 *
 * Why this exists: the previous canonical chain relied on the caller
 * exporting a pre-created E2E_DATABASE pointing at a disposable schema. That
 * lifecycle is gone: schema creation/drop, `randomBytes`, and the guarded
 * temporary namespace no longer belong to the contract. The fail-closed
 * behavior is kept and strengthened:
 *
 * 1. Sanitize the parent shell once via buildE2EServerEnv() — parent
 *    APP_ENV, DB_ and SESSION_ pollution never reaches any child; dropped keys
 *    are reported NAMES ONLY, values are never copied or logged.
 * 2. Resolve the single target schema from .env.e2e (DB_DATABASE=inbils_test):
 *    an E2E_DATABASE in the parent shell is refused, not honored.
 * 3. Run the guard matrix and setup.php in --check mode first so the shared
 *    `inbils_test` schema is proven migrated and seeded before any HTTP proof.
 *    --check is read-only: it never migrates, seeds, creates, or drops anything.
 * 4. Prove POST /login is not HTTP 500 against a throwaway sanitized server
 *    bound to the SAME schema before Playwright starts: a failed-credentials
 *    attempt must reach the auth pipeline (302), which requires a healthy app,
 *    reachable database AND migrated tables. A 500 means environment
 *    sanitation broke.
 * 5. Start Playwright via scripts/e2e-run.mjs, forwarding all arguments
 *    unchanged.
 *
 * No schema lifecycle is owned here: the database is never created, never
 * renamed, and never dropped by this runner.
 */

import { spawn, spawnSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { createServer } from 'node:net';
import { setTimeout as delay } from 'node:timers/promises';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

import { buildE2EServerEnv, resolvePhpBinary } from './e2e-server-env.mjs';
import {
    acquireCanonicalMutex,
    getDescendantPids,
    getProcessStartToken,
    globalOwnedRegistry,
    observeWindowsProcessTable,
    probeProcessLiveness,
    processStartTokensMatch,
} from './e2e-mutex.mjs';
import {
    e2eRuntimeDir,
    killPidTree,
    parsePidFile,
    probePortListener,
    safelyReapPidFile,
} from './e2e-server-reap.mjs';

const host = '127.0.0.1';
const envFilePath = '.env.e2e';
const canonicalDatabase = 'inbils_test';

// Single absolute teardown deadline: discovery → kill → terminal proof → port
// release must all complete within ONE budget. Sub-steps (bounded tasklist/wmic
// probes, bounded port checks) may not restart the clock — only the time
// remaining until this deadline is ever granted to the next phase.
const TEARDOWN_TOTAL_TIMEOUT_MS = 15000;
// Reserve part of the existing deadline for final listener observation. This
// consumes budget already granted to teardown; it does not add timeout/retries.
const FINAL_OBSERVATION_RESERVE_MS = 250;
const remainingBudgetMs = (deadline) => Math.max(0, deadline - Date.now());

const isWindowsProcessObservation = (options = {}) =>
    (options._platform ?? process.platform) === 'win32';

/**
 * Take one bounded process-table observation for an observation cycle.  Callers
 * pass the returned snapshot through all identity/discovery probes in that
 * cycle; a later cycle must call this again rather than reusing the snapshot.
 */
const observeProcessCycle = (options = {}, deadline) => {
    if (!isWindowsProcessObservation(options)) return null;
    if (options._processObservation) return options._processObservation;
    // Injectable per-process seams in deterministic self-checks own the
    // observation contract; do not unexpectedly replace them with a real CIM
    // query. Production calls use the default helpers and therefore take the
    // single-snapshot path below.
    if (
        options._probeProcessLiveness ||
        options._getProcessStartToken ||
        options._getDescendantPids
    ) {
        return null;
    }

    const observe = options._observeWindowsProcessTable ?? observeWindowsProcessTable;
    const remaining =
        deadline === undefined ? (options.timeout ?? 5000) : remainingBudgetMs(deadline);
    return observe({
        timeout: Math.min(options.timeout ?? 5000, Math.max(0, remaining)),
        deadline,
        _spawnSync: options._spawnSync,
    });
};

const IDENTITY_MISMATCH_OUTPUT =
    'Process identity mismatch: WMIC found no matching instance with expected CreationDate token';

const isIdentityMismatchKillResult = (result, rawResult = null) => {
    if (!result || typeof result.status !== 'number' || result.status === 0) return false;
    if (result.error) return false;
    // e2e-server-reap normalizes malformed/status-zero runner output into a
    // nonzero result. Preserve the raw command status here so status-zero
    // mismatch text can never become terminal identity proof.
    if (rawResult && (rawResult.error || rawResult.status === 0)) return false;
    if (rawResult && typeof rawResult.status !== 'number') return false;
    if (
        rawResult &&
        String(rawResult.stdout ?? rawResult.output ?? '').trim() !== IDENTITY_MISMATCH_OUTPUT
    ) {
        return false;
    }

    return String(result.output ?? '').trim() === IDENTITY_MISMATCH_OUTPUT;
};

const runKillPidTreeWithRawResult = (pid, budget, options) => {
    let rawResult = null;
    const runner = options._spawnSync || options.spawnSync || spawnSync;
    const result = killPidTree(pid, budget, {
        ...options,
        _spawnSync: (...args) => {
            rawResult = runner(...args);
            return rawResult;
        },
    });
    return { result, rawResult };
};

const isContradictoryIdentityMismatchSuccess = (result) =>
    Boolean(
        result &&
        result.status === 0 &&
        String(result.output ?? '').trim() === IDENTITY_MISMATCH_OUTPUT,
    );

const isDirectInvocation =
    Boolean(process.argv[1]) && import.meta.url === pathToFileURL(process.argv[1]).href;

export function assertCanonicalDatabaseName(name) {
    if (typeof name !== 'string' || name !== canonicalDatabase) {
        throw new Error(
            `Refusing E2E database ${JSON.stringify(name)}: contract requires exactly ${canonicalDatabase}.`,
        );
    }
}

export function resolveCanonicalDatabase(sourceEnv = process.env) {
    if (nonEmptyString(sourceEnv.E2E_DATABASE) !== null) {
        throw new Error(
            `Refusing E2E run: E2E_DATABASE is no longer supported; all E2E runs use ${canonicalDatabase}.`,
        );
    }

    return canonicalDatabase;
}

export function parseEnvFileCredentials(filePath = envFilePath) {
    let contents;

    try {
        contents = readFileSync(filePath, 'utf8');
    } catch {
        throw new Error(
            `E2E canonical runner requires ${filePath} (copied from ${filePath}.example).`,
        );
    }

    const values = new Map();
    const duplicates = new Set();

    for (const line of contents.split(/\r?\n/)) {
        const match = /^\s*([A-Z0-9_]+)\s*=\s*(.*)$/.exec(line);

        if (!match) continue;

        const raw = match[2].trim();
        const quoted = /^"(.*)"$|^'(.*)'$/.exec(raw);
        if (values.has(match[1])) duplicates.add(match[1]);
        values.set(match[1], quoted ? (quoted[1] ?? quoted[2]) : raw);
    }

    const required = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'];
    const missing = required.filter((name) => !values.has(name) || !values.get(name)?.trim());
    const ambiguous = required.filter((name) => duplicates.has(name));
    const database = values.get('DB_DATABASE');
    const portValue = values.get('DB_PORT');

    if (
        missing.length > 0 ||
        ambiguous.length > 0 ||
        database !== canonicalDatabase ||
        !/^\d+$/.test(portValue ?? '') ||
        Number(portValue) <= 0 ||
        Number(portValue) > 65535
    ) {
        const reasons = [];
        if (missing.length > 0) reasons.push(`missing ${missing.join(', ')}`);
        if (ambiguous.length > 0) reasons.push(`duplicate ${ambiguous.join(', ')}`);
        if (database !== canonicalDatabase) reasons.push('DB_DATABASE is not canonical');
        if (!/^\d+$/.test(portValue ?? '') || Number(portValue) <= 0 || Number(portValue) > 65535) {
            reasons.push('DB_PORT is invalid');
        }
        throw new Error(
            `Refusing E2E database source from ${filePath}: ${reasons.join('; ')}. ` +
                `Expected one unambiguous DB_HOST/DB_PORT/DB_USERNAME and DB_DATABASE=${canonicalDatabase}.`,
        );
    }

    return {
        host: values.get('DB_HOST'),
        port: portValue,
        username: values.get('DB_USERNAME'),
        password: values.get('DB_PASSWORD') ?? '',
        database,
    };
}

export function buildCanonicalChildEnv(sourceEnv = process.env) {
    const sanitized = buildE2EServerEnv(sourceEnv);
    const schema = resolveCanonicalDatabase(sourceEnv);

    return {
        childEnv: {
            ...sanitized.env,
            // DB_DATABASE is pinned so every child resolves the same canonical
            // schema even if .env.e2e were missing/renamed; E2E_DATABASE is
            // deliberately NOT forwarded — the disposable-schema mechanism is
            // gone and both setup.php and buildE2EServerEnv refuse it outright.
            DB_DATABASE: schema,
        },
        schema,
        droppedSensitiveNames: sanitized.droppedSensitiveNames,
    };
}

function nonEmptyString(value) {
    if (typeof value !== 'string' || value === '') return null;

    return value;
}

export function resolveCanonicalExecutionMode({ customSteps, runPlaywrightStep, runnerArgs }) {
    if (!runPlaywrightStep) return 'custom-steps';
    if (runnerArgs.includes('--list')) return 'playwright-discovery';
    if (runnerArgs.some((arg) => ['--help', '-h', '--version', '-V'].includes(arg))) {
        return 'playwright-metadata';
    }

    return 'playwright-fixture-mutating';
}

export const listRuntimePidFiles = (runtimeDir = e2eRuntimeDir) => {
    if (!existsSync(runtimeDir)) return [];

    return readdirSync(runtimeDir)
        .filter((name) => /^webserver-\d+\.pid$/.test(name))
        .map((name) => join(runtimeDir, name));
};

export const snapshotRuntimePidFiles = (runtimeDir = e2eRuntimeDir) =>
    new Set(listRuntimePidFiles(runtimeDir));

const runtimePidSnapshotCache = new WeakMap();

export const snapshotCurrentRunPidFileContents = (
    baselinePidFiles,
    runtimeDir = e2eRuntimeDir,
    priorSnapshots = new Map(),
) => {
    const baseline =
        baselinePidFiles instanceof Set ? baselinePidFiles : new Set(baselinePidFiles ?? []);
    const snapshots = priorSnapshots instanceof Map ? new Map(priorSnapshots) : new Map();

    if (snapshots.size > 0) {
        runtimePidSnapshotCache.set(baseline, new Map(snapshots));
        return snapshots;
    }

    const cachedSnapshots = runtimePidSnapshotCache.get(baseline);
    if (cachedSnapshots instanceof Map && cachedSnapshots.size > 0) {
        return new Map(cachedSnapshots);
    }

    for (const pidFile of listRuntimePidFiles(runtimeDir)) {
        if (baseline.has(pidFile) || snapshots.has(pidFile)) continue;
        snapshots.set(pidFile, readFileSync(pidFile, 'utf8'));
    }

    runtimePidSnapshotCache.set(baseline, new Map(snapshots));
    return snapshots;
};

export const reconcileCurrentRunPidFiles = (
    baselinePidFiles,
    runtimeDir = e2eRuntimeDir,
    capturedContents,
) => {
    const baseline =
        baselinePidFiles instanceof Set ? baselinePidFiles : new Set(baselinePidFiles ?? []);
    const snapshots =
        capturedContents instanceof Map
            ? new Map(capturedContents)
            : runtimePidSnapshotCache.get(baseline) instanceof Map
              ? new Map(runtimePidSnapshotCache.get(baseline))
              : new Map();
    const currentFiles = listRuntimePidFiles(runtimeDir).filter(
        (pidFile) => !baseline.has(pidFile),
    );
    const cleanedFiles = [];
    const retainedFiles = [];
    let cleanupFailed = false;

    for (const pidFile of currentFiles) {
        if (!existsSync(pidFile)) continue;

        const expectedRaw = snapshots.get(pidFile);
        if (typeof expectedRaw !== 'string') {
            console.error(
                `[e2e-canonical] refusing to reconcile untracked current-run PID file ${pidFile}; leaving it untouched.`,
            );
            retainedFiles.push(pidFile);
            cleanupFailed = true;
            continue;
        }

        const currentRaw = readFileSync(pidFile, 'utf8');
        if (currentRaw !== expectedRaw) {
            console.error(
                `[e2e-canonical] refusing to reconcile replaced current-run PID file ${pidFile}; leaving it untouched.`,
            );
            retainedFiles.push(pidFile);
            cleanupFailed = true;
            continue;
        }

        const parsed = parsePidFile(pidFile);
        if (!parsed || typeof parsed !== 'object') {
            console.error(
                `[e2e-canonical] refusing to reconcile malformed current-run PID file ${pidFile}; leaving it untouched.`,
            );
            retainedFiles.push(pidFile);
            cleanupFailed = true;
            continue;
        }

        const removed = safelyReapPidFile(pidFile, parsed);
        if (removed && !existsSync(pidFile)) {
            cleanedFiles.push(pidFile);
            continue;
        }

        console.error(
            `[e2e-canonical] refusing to reconcile current-run PID file ${pidFile}; leaving it untouched.`,
        );
        retainedFiles.push(pidFile);
        cleanupFailed = true;
    }

    return { cleanupFailed, cleanedFiles, retainedFiles, newFiles: currentFiles };
};

/**
 * Fail-closed preflight: prove the canonical schema exists and is reachable
 * WITHOUT any lifecycle statement. This is a read-only existence probe — it
 * never creates, alters, renames, or drops a schema. The contract requires the
 * shared `inbils_test` schema to be provisioned OUTSIDE this runner; if it is
 * missing or unreachable the run stops before any child process starts.
 *
 * Credentials reach PHP exclusively through environment variables so no
 * secret ever appears in the command line, argv listings, or logs.
 */
export function ensureCanonicalSchema(credentials, schema, phpBinary = resolvePhpBinary(process.env.PHP_BINARY)) {
    assertCanonicalDatabaseName(schema);
    const result = spawnSync(
        phpBinary,
        [
            '-r',
            [
                `$p=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s',getenv('CANON_DB_HOST'),getenv('CANON_DB_PORT'),getenv('CANON_DB_NAME')),getenv('CANON_DB_USER'),getenv('CANON_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);`,
                `$ok=$p->query('SELECT 1')->fetchColumn();`,
                `exit($ok===1?0:1);`,
            ].join(''),
        ],
        {
            env: {
                PATH: process.env.PATH,
                SystemRoot: process.env.SystemRoot,
                SYSTEMROOT: process.env.SYSTEMROOT,
                CANON_DB_HOST: credentials.host,
                CANON_DB_PORT: credentials.port,
                CANON_DB_NAME: schema,
                CANON_DB_USER: credentials.username,
                CANON_DB_PASS: credentials.password,
            },
            windowsHide: true,
            timeout: 10_000,
        },
    );

    if (result.status !== 0) {
        // Deliberately no stderr passthrough: driver messages can embed the
        // DB username/host pair. Summarize without echoing them.
        throw new Error(
            `Canonical schema ${schema} is not reachable (probe exit ${result.status ?? 'null'}); ` +
                'the shared E2E schema must be provisioned outside this runner. ' +
                'Check MySQL availability and ' +
                `${envFilePath} credentials.`,
        );
    }
}

const findFreePort = () =>
    new Promise((resolve, reject) => {
        const probe = createServer();

        probe.once('error', reject);
        probe.listen(0, host, () => {
            const { port } = probe.address();

            probe.close((error) => (error ? reject(error) : resolve(port)));
        });
    });

const normalizeDescendantDiscovery = (discovery) => {
    // Compatibility for injected/self-check callers that predate the structured
    // discovery contract. Production discovery always returns a structured result.
    if (Array.isArray(discovery)) {
        return {
            state: discovery.length === 0 ? 'known-empty' : 'known',
            pids: discovery,
            reason: null,
        };
    }

    if (!discovery || typeof discovery !== 'object') {
        return { state: 'unknown', pids: [], reason: 'malformed-discovery-result' };
    }

    const pids = Array.isArray(discovery.pids) ? discovery.pids : null;
    const state = discovery.state;
    const stateProvesDiscovery = ['known', 'known-empty', 'ok'].includes(state);
    const pidsAreValid =
        pids !== null &&
        pids.every((pid) => typeof pid === 'number' && Number.isSafeInteger(pid) && pid > 0);
    const stateMatchesPids =
        state === 'ok' ||
        (state === 'known' && pids?.length > 0) ||
        (state === 'known-empty' && pids?.length === 0);

    if (!stateProvesDiscovery || !pidsAreValid || !stateMatchesPids) {
        return {
            state: 'unknown',
            pids: [],
            reason:
                typeof discovery.reason === 'string' && discovery.reason.trim()
                    ? discovery.reason.trim()
                    : 'malformed-discovery-result',
        };
    }

    return { state, pids, reason: null };
};

/**
 * Pre-teardown identity binder for LATE-SPAWNED owned descendants.
 *
 * Why this exists: an owned root (e.g. `php artisan serve`) frequently spawns
 * its real listener (`php -S`) AFTER the root was registered, so registration
 * time discovery cannot see it. Teardown is forbidden from discovering fresh
 * process identity (a PID observed for the first time during shutdown may
 * already be a reused/foreign PID), which previously left the late-spawned
 * descendant token-less: the identity-safe kill refused it and the bound port
 * survived the owner, failing the canonical launcher before Playwright/DB.
 *
 * This binder runs WHILE the owner is still alive, i.e. strictly before
 * teardown, and captures an immutable start-token for every owned descendant:
 *
 * - the root must already be owned AND already carry a bound start-token;
 * - the root's CURRENT token must match its bound token exactly, otherwise the
 *   PID was reused and its child tree belongs to a foreign process: nothing is
 *   adopted (fail-closed);
 * - descendants are enumerated from the verified owned root only, so parent
 *   linkage is exact by construction;
 * - an already bound descendant is never re-bound: a token mismatch marks the
 *   entry as foreign/reused and it is neither adopted nor killed;
 * - a descendant whose token cannot be read is NOT adopted (unknown identity is
 *   never promoted to ownership).
 */
export function bindOwnedDescendantIdentities(rootPid, options = {}) {
    const result = {
        rootPid,
        reason: null,
        discoveryState: null,
        teardownVerified: true,
        bound: [],
        alreadyBound: [],
        unknownIdentity: [],
        refusedForeign: [],
    };

    if (typeof rootPid !== 'number' || !Number.isInteger(rootPid) || rootPid <= 0) {
        result.reason = 'invalid-root-pid';
        return result;
    }

    const registry = options._registry ?? globalOwnedRegistry;
    const readToken = options._getProcessStartToken ?? getProcessStartToken;
    const readDescendants = options._getDescendantPids ?? getDescendantPids;
    const probe = options._probeProcessLiveness ?? probeProcessLiveness;
    // Start-tokens are only observable on Windows (wmic CreationDate). On POSIX
    // the platform exposes no immutable token here, so identity is established by
    // process-group semantics instead: adopt by exact parent linkage from a root
    // that is PROVEN alive, and leave startToken null so the Windows
    // identity-safe kill path still refuses these PIDs.
    const linkageOnly = (options._platform ?? process.platform) !== 'win32';

    const spawnOwnedRoot =
        options._spawnOwnedRoot === true || isSpawnOwnedChildHandle(options._spawnOwnedRoot, rootPid, rootPid);

    if (linkageOnly && options._requireSpawnOwnership && !spawnOwnedRoot) {
        result.reason = 'root-identity-unverifiable';
        return result;
    }

    const discoverDescendants = () => {
        let discovery;
        try {
            discovery = normalizeDescendantDiscovery(readDescendants(rootPid, cycleOptions));
        } catch (error) {
            discovery = {
                state: 'unknown',
                pids: [],
                reason: `discovery-error: ${error instanceof Error ? error.message : String(error)}`,
            };
        }

        result.discoveryState = discovery.state;
        if (discovery.state === 'unknown') {
            result.teardownVerified = false;
            result.reason = `descendant-discovery-unknown: ${discovery.reason}`;
        }
        return discovery;
    };

    const rootItem = registry.items.get(rootPid);
    const processObservation =
        (options._platform ?? process.platform) === 'win32'
            ? (options._processObservation ??
              (options._observeWindowsProcessTable
                  ? options._observeWindowsProcessTable({
                        timeout: options.timeout ?? 5000,
                        deadline: options.deadline,
                        _spawnSync: options._spawnSync,
                    })
                  : null))
            : null;
    const cycleOptions = processObservation
        ? { ...options, _processObservation: processObservation }
        : options;

    if (!rootItem) {
        result.reason = 'root-not-owned';
        return result;
    }

    const boundRootToken =
        typeof rootItem.startToken === 'string' && rootItem.startToken.trim()
            ? rootItem.startToken.trim()
            : null;

    if (linkageOnly) {
        if (probe(rootPid, { timeout: 1000, ...options }).state !== 'alive') {
            // Root not proven alive: its PID may already belong to a foreign
            // process, so its child tree must not be adopted.
            result.reason = 'root-identity-unverifiable';
            return result;
        }

        const discovery = discoverDescendants();
        if (!result.teardownVerified) return result;

        for (const dPid of discovery.pids) {
            if (dPid === rootPid) continue;

            const existing = registry.items.get(dPid);

            if (existing?.identityMismatch) {
                result.refusedForeign.push(dPid);
                continue;
            }

            if (existing) {
                result.alreadyBound.push(dPid);
                continue;
            }

            registry.register(dPid, {
                _spawnSync: options._spawnSync,
                spawnSync: options.spawnSync,
                parentPid: rootPid,
                skipStartTokenDiscovery: true,
                skipProcessDiscovery: true,
            });
            result.bound.push(dPid);
        }

        result.reason = 'linkage-only-platform';
        return result;
    }

    if (!boundRootToken) {
        result.reason = 'root-identity-unbound';
        return result;
    }

    const currentRootToken = readToken(rootPid, cycleOptions);

    if (currentRootToken === null) {
        // Root exited or identity is unreadable: refuse to enumerate a tree we
        // can no longer attribute to the owned root.
        result.reason = 'root-identity-unverifiable';
        return result;
    }

    if (!processStartTokensMatch(currentRootToken, boundRootToken)) {
        rootItem.identityMismatch = true;
        result.reason = 'root-identity-mismatch';
        result.refusedForeign.push(rootPid);
        return result;
    }

    const discovery = discoverDescendants();
    if (!result.teardownVerified) return result;

    for (const dPid of discovery.pids) {
        if (dPid === rootPid) continue;

        const existing = registry.items.get(dPid);
        const existingToken =
            typeof existing?.startToken === 'string' && existing.startToken.trim()
                ? existing.startToken.trim()
                : null;
        const currentToken = readToken(dPid, cycleOptions);

        if (existingToken !== null) {
            if (currentToken === null) {
                result.unknownIdentity.push(dPid);
                continue;
            }

            if (!processStartTokensMatch(currentToken, existingToken)) {
                existing.identityMismatch = true;
                result.refusedForeign.push(dPid);
                continue;
            }

            result.alreadyBound.push(dPid);
            continue;
        }

        if (currentToken === null) {
            result.unknownIdentity.push(dPid);
            continue;
        }

        registry.register(dPid, {
            _spawnSync: options._spawnSync,
            spawnSync: options.spawnSync,
            parentPid: rootPid,
            startToken: currentToken,
            skipStartTokenDiscovery: true,
            skipProcessDiscovery: true,
        });
        result.bound.push(dPid);
    }

    return result;
}

/**
 * Bounded supervisor that keeps binding immutable start-tokens for owned
 * descendants while the owner is alive. `stop()` performs one final binding
 * pass BEFORE teardown begins, which is the pass that captures a descendant
 * spawned in the last interval window.
 */
export function startOwnedDescendantSupervisor(rootPid, options = {}) {
    const intervalMs = Math.max(50, options.supervisorIntervalMs ?? 500);
    const binder = options._bindOwnedDescendantIdentities ?? bindOwnedDescendantIdentities;
    const registry = options._registry ?? globalOwnedRegistry;
    const readToken = options._getProcessStartToken ?? getProcessStartToken;
    const observe = options._observeWindowsProcessTable ?? observeWindowsProcessTable;
    const passes = [];
    let stopped = false;
    let teardownVerified = true;

    const bindRootIdentityWhenAvailable = () => {
        const rootItem = registry.items.get(rootPid);
        if (!rootItem || rootItem.identityMismatch === true) return;
        const existingToken =
            typeof rootItem.startToken === 'string' && rootItem.startToken.trim()
                ? rootItem.startToken.trim()
                : null;
        if (existingToken) return;

        // A process-start query may race process creation on Windows. Retry while
        // the spawn-owned root is alive; a null/failed lookup is not permission to
        // adopt descendants, and no token is invented when the query is unknown.
        let currentToken = null;
        try {
            currentToken = readToken(rootPid, options);
        } catch {
            return;
        }
        if (!currentToken) return;

        registry.register(rootPid, {
            parentPid: rootItem.parentPid,
            startToken: currentToken,
            skipStartTokenDiscovery: true,
            skipProcessDiscovery: true,
        });
    };

    const runPass = () => {
        try {
            const processObservation =
                (options._platform ?? process.platform) === 'win32'
                    ? (options._observeWindowsProcessTable ?? observeWindowsProcessTable)({
                          timeout: options.timeout ?? 5000,
                          _spawnSync: options._spawnSync,
                      })
                    : null;
            const passOptions = processObservation
                ? { ...options, _processObservation: processObservation }
                : options;
            bindRootIdentityWhenAvailable(passOptions);
            const pass = binder(rootPid, passOptions);
            passes.push(pass);
            if (pass?.teardownVerified === false) teardownVerified = false;
        } catch (error) {
            teardownVerified = false;
            passes.push({
                rootPid,
                reason: `binder-error: ${error instanceof Error ? error.message : String(error)}`,
                discoveryState: 'unknown',
                teardownVerified: false,
                bound: [],
                alreadyBound: [],
                unknownIdentity: [],
                refusedForeign: [],
            });
        }
    };

    runPass();

    const timer = setInterval(runPass, intervalMs);
    if (typeof timer.unref === 'function') timer.unref();

    return {
        rootPid,
        passes,
        get teardownVerified() {
            return teardownVerified;
        },
        stop() {
            if (stopped) return passes;
            stopped = true;
            clearInterval(timer);
            // Final pre-teardown bind: the owner is still alive here.
            runPass();
            return passes;
        },
    };
}

/**
 * Terminal proof of the exact owned child-tree and owned ports before the
 * registry is cleared or the mutex is released. Identity-aware: a registry
 * entry whose PID was reused by a foreign process is proven dead through its
 * bound start-token instead of being mistaken for a live owned process, and it
 * is never killed here (this function only proves, it never terminates).
 */
export async function verifyOwnedRegistryTerminal(options = {}) {
    const timeoutMs = options.timeoutMs ?? 3000;
    const registry = options._registry ?? options.registry ?? globalOwnedRegistry;
    const probe = options._probeProcessLiveness ?? probeProcessLiveness;
    const portProbe =
        options._probePortListener ??
        ((port, timeoutMs) => probePortListener(port, timeoutMs, options));
    if (!Number.isFinite(timeoutMs) || timeoutMs <= 0) {
        return { ok: false, alivePids: [], boundPorts: registry.getAllPorts() };
    }
    const deadline = Date.now() + timeoutMs;

    let alivePids = [];
    let boundPorts = [];

    for (;;) {
        // Preserve the last complete observation when the deadline expires;
        // never erase evidence just before returning the fail-closed result.
        if (Date.now() >= deadline) return { ok: false, alivePids, boundPorts };
        alivePids = [];
        const processObservation = observeProcessCycle(options, deadline);
        const iterationOptions = processObservation
            ? { ...options, _processObservation: processObservation }
            : options;

        for (const item of registry.getAllItems()) {
            if (typeof item.pid !== 'number') continue;
            const remaining = deadline - Date.now();
            if (remaining <= 0) return { ok: false, alivePids, boundPorts };

            const liveness = probe(item.pid, {
                ...iterationOptions,
                timeout: Math.min(1000, remaining),
                expectedStartToken: item.startToken ?? undefined,
            });

            if (liveness.state !== 'dead') {
                alivePids.push({ pid: item.pid, state: liveness.state });
            }
        }

        boundPorts = [];

        for (const port of registry.getAllPorts()) {
            const remaining = deadline - Date.now();
            if (remaining <= 0) return { ok: false, alivePids, boundPorts };
            const portState = await portProbe(port, remaining);
            if (portState?.state !== 'free') {
                boundPorts.push({
                    port,
                    state: portState?.state ?? 'unknown',
                    ...(portState?.reason ? { reason: portState.reason } : {}),
                });
            }
        }

        if (alivePids.length === 0 && boundPorts.length === 0) {
            return { ok: true, alivePids, boundPorts };
        }

        const pause = Math.min(100, deadline - Date.now());
        if (pause <= 0) return { ok: false, alivePids, boundPorts };
        await delay(pause);
    }
}

/**
 * POSIX identity-safe ownership resolution. POSIX exposes no immutable
 * process-start token, so a PID may only be signaled during teardown if it is
 * either the owned root (owned by construction through the spawn handle) or a
 * descendant whose ownership was established by exact live parent linkage —
 * discovered during this pass via pgrep or previously adopted by the linkage
 * binder from a root proven alive. A historical/tokenless registry PID with no
 * live linkage must never be signaled. Returns the Set of signalable PIDs.
 */
const spawnOwnedChildHandles = new WeakMap();
const spawnOwnedChildIssuer = Symbol('spawnOwnedChildIssuer');
const markSpawnOwnedChild = (child, spawnOptions, issuer) => {
    const rootPid = child?.pid;
    if (
        issuer === spawnOwnedChildIssuer &&
        spawnOptions?.detached === true &&
        typeof child === 'object' &&
        child !== null &&
        Number.isSafeInteger(rootPid) &&
        rootPid > 0
    ) {
        spawnOwnedChildHandles.set(child, Object.freeze({ rootPid, pgid: rootPid }));
    }
    return child;
};

export const spawnOwnedChild = (...args) => {
    const spawnArgs = [...args];
    const child = spawn(...spawnArgs);
    return process.platform !== 'win32'
        ? markSpawnOwnedChild(child, spawnArgs[2], spawnOwnedChildIssuer)
        : child;
};

const isSpawnOwnedChildHandle = (child, pid, pgid = undefined) => {
    const capability =
        typeof child === 'object' && child !== null ? spawnOwnedChildHandles.get(child) : undefined;
    return (
        capability !== undefined &&
        capability.rootPid === pid &&
        capability.pgid > 0 &&
        child.pid === capability.rootPid &&
        typeof child.kill === 'function' &&
        (pgid === undefined || capability.pgid === pgid)
    );
};

export function resolvePosixIdentitySafeOwned(
    pid,
    knownDescendants,
    liveLinkedDescendants,
    registry = globalOwnedRegistry,
    { rootOwnershipProven = false } = {},
) {
    const safe = new Set(rootOwnershipProven === true ? [pid] : []);
    if (rootOwnershipProven !== true) return safe;
    void registry;
    for (const dPid of knownDescendants) {
        if (dPid === pid) continue;
        // POSIX has no immutable creation token. Historical registry linkage is
        // evidence for audit/recovery only, never current signal authority: a PID
        // can exit and be reused by a foreign process after it was registered.
        // Signal only descendants enumerated under the live owned root in this
        // teardown pass.
        if (liveLinkedDescendants.has(dPid)) safe.add(dPid);
    }
    return safe;
}

export const killProcessTree = async (child, timeoutMs = 5000, options = {}) => {
    if (!child) return true;

    const pid = typeof child === 'number' ? child : child.pid;
    if (!pid || typeof pid !== 'number' || pid <= 0) return true;
    if (!Number.isFinite(timeoutMs) || timeoutMs <= 0) return false;
    const deadline = Date.now() + timeoutMs;
    const remaining = () => Math.max(0, deadline - Date.now());
    const terminalObservationReserveMs =
        options.finalObservationReserveMs ?? FINAL_OBSERVATION_RESERVE_MS;

    const platform = options._platform ?? process.platform;
    const port = options.port;
    const portProbe =
        options._probePortListener ??
        ((targetPort, budget) => probePortListener(targetPort, budget, options));
    const runner = options._spawnSync || options.spawnSync || spawnSync;
    const processLiveness =
        options._probeProcessLiveness ?? options.probeProcessLiveness ?? probeProcessLiveness;
    const readDescendants = options._getDescendantPids ?? getDescendantPids;
    const rootOwnershipProven =
        platform === 'win32' || isSpawnOwnedChildHandle(child, pid, pid);
    const signalProcess =
        options._signal ?? ((targetPid, signal) => process.kill(targetPid, signal));
    const ownedHandle =
        platform !== 'win32' && typeof child === 'object' && child !== null
            ? spawnOwnedChildHandles.get(child)
            : undefined;
    const hasOwnedProcessGroupCapability =
        platform !== 'win32' &&
        ownedHandle?.rootPid === pid &&
        ownedHandle?.pgid === pid &&
        child.pid === ownedHandle.rootPid &&
        typeof child.kill === 'function';
    const signalOwnedProcessGroup = (signal) => {
        if (!hasOwnedProcessGroupCapability) return false;
        try {
            signalProcess(-ownedHandle.pgid, signal);
            return true;
        } catch (error) {
            return error?.code === 'ESRCH';
        }
    };
    const probeOwnedProcessGroup = () => {
        if (!hasOwnedProcessGroupCapability) {
            return { state: 'unknown', reason: 'owned-process-group-capability-unavailable' };
        }

        try {
            signalProcess(-ownedHandle.pgid, 0);
            return { state: 'alive' };
        } catch (error) {
            if (error?.code === 'ESRCH') return { state: 'dead' };
            return { state: 'unknown', reason: error?.message ?? 'process-group-probe-failed' };
        }
    };
    const childHasExited = typeof child === 'object' && child.exitCode !== null;
    const registryItem = globalOwnedRegistry.items.get(pid);
    const expectedStartToken =
        options.expectedStartToken ?? options.startToken ?? registryItem?.startToken ?? null;

    // Register root and any specified port in the durable owned registry
    // without discovering fresh process identity during teardown. Teardown may
    // only use tokens that were already captured before shutdown began.
    globalOwnedRegistry.register(pid, {
        ...options,
        startToken: expectedStartToken,
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });
    if (typeof port === 'number' && port > 0) {
        globalOwnedRegistry.registerPort(port);
    }

    // PRE-TEARDOWN IDENTITY BIND (late-spawned descendants).
    // The root has NOT been terminated yet at this point, so this is the last
    // moment where a descendant that was spawned after registration (e.g. the
    // `php -S` listener spawned by `php artisan serve`) can still be attributed
    // to the owned root and given an immutable start-token. Teardown below only
    // ever kills PIDs whose token was bound here or earlier — it never discovers
    // fresh identity for an unknown PID, so a reused/foreign PID is never killed.
    // If the spawn handle already observed root exit, parent linkage is no longer
    // authoritative on Windows: do not rediscover or adopt a reparented PID.
    let teardownVerified = true;
    const rootExitedBeforeTeardown = typeof child === 'object' && child.exitCode !== null;
    const preSignalObservation = observeProcessCycle(options, deadline);
    const preSignalOptions = preSignalObservation
        ? { ...options, _processObservation: preSignalObservation }
        : options;
    if (!rootExitedBeforeTeardown) {
        const identityBinding = bindOwnedDescendantIdentities(pid, preSignalOptions);
        if (identityBinding.teardownVerified === false) teardownVerified = false;
        if (identityBinding.refusedForeign.length > 0) {
            console.error(
                `[e2e-canonical] Refusing foreign adoption during pre-teardown bind: ` +
                    `PID(s) ${identityBinding.refusedForeign.join(', ')} (${identityBinding.reason ?? 'identity-mismatch'}).`,
            );
        }
        if (identityBinding.unknownIdentity.length > 0) {
            console.error(
                `[e2e-canonical] Unknown identity, not adopted: PID(s) ${identityBinding.unknownIdentity.join(', ')}.`,
            );
        }
    }

    // Discover all descendants prior to root termination, combined with durable registry.
    // Live-linked descendants (actual parent linkage observed during this pass) are captured
    // separately so POSIX can distinguish identity-safe-owned PIDs from historical registry PIDs.
    let liveDiscovery;
    try {
        const observedDiscovery = normalizeDescendantDiscovery(
            readDescendants(pid, preSignalOptions),
        );
        // Once the root has exited, parent linkage is no longer authoritative on
        // Windows. Keep the observation only for its tri-state health: never adopt
        // PIDs returned by a post-exit query, but still fail closed on unknown.
        liveDiscovery = rootExitedBeforeTeardown
            ? {
                  state: observedDiscovery.state,
                  pids: [],
                  ...(observedDiscovery.reason ? { reason: observedDiscovery.reason } : {}),
              }
            : observedDiscovery;
    } catch (error) {
        liveDiscovery = {
            state: 'unknown',
            pids: [],
            reason: `discovery-error: ${error instanceof Error ? error.message : String(error)}`,
        };
    }
    if (liveDiscovery.state === 'unknown' && !options.exitedNormally) {
        teardownVerified = false;
        console.error(
            `[e2e-canonical] Descendant discovery remained unknown for PID ${pid}: ${liveDiscovery.reason}.`,
        );
    }
    const liveLinkedDescendants = new Set(liveDiscovery.pids);
    const knownDescendants = new Set([...liveLinkedDescendants, ...(options.additionalPids || [])]);
    if (options.includeGlobalRegistry !== false) {
        for (const d of globalOwnedRegistry.getAllPids()) {
            if (d !== pid) knownDescendants.add(d);
        }
    }

    // On POSIX, descendant PIDs are observation evidence only. The detached
    // child handle's immutable process-group capability is the sole signal
    // authority; no historical or individually discovered descendant PID may
    // authorize a signal.
    const identitySafeOwned =
        platform === 'win32'
            ? new Set([pid, ...knownDescendants])
            : new Set(rootOwnershipProven ? [pid] : []);

    // Discover any additional ports from durable registry.
    const portsToCheck = new Set();
    if (typeof port === 'number' && port > 0) {
        portsToCheck.add(port);
    }
    for (const p of globalOwnedRegistry.getAllPorts()) {
        portsToCheck.add(p);
    }

    // Terminate descendants leaf-first while their immutable identities are
    // still attributable to the live root. Killing the root first creates a race:
    // its child may disappear between identity bind and WMIC Terminate, turning a
    // safe tree shutdown into an apparent identity mismatch.
    let rootIdentityMismatch = false;
    for (const dPid of [...knownDescendants].reverse()) {
        if (platform !== 'win32') break;
        const dItem = globalOwnedRegistry.items.get(dPid);
        const dToken = dItem?.startToken ?? null;
        if (dItem?.identityMismatch) {
            // Foreign/reused PID: never adopted, never killed.
            console.error(
                `[e2e-canonical] Refusing unverified kill: PID ${dPid} identity mismatched (reused/foreign).`,
            );
            continue;
        }
        if (platform === 'win32') {
            if (dToken) {
                const budget = Math.max(0, remaining() - terminalObservationReserveMs);
                if (budget <= 0) return false;
                const { result: killResult, rawResult } = runKillPidTreeWithRawResult(
                    dPid,
                    budget,
                    {
                        ...options,
                        expectedStartToken: dToken,
                    },
                );
                if (killResult.status !== 0) {
                    if (isIdentityMismatchKillResult(killResult, rawResult)) {
                        // The expected incarnation is absent. This is terminal
                        // evidence for the owned PID, not authority to probe or
                        // signal the current process occupying the numeric PID.
                        globalOwnedRegistry.items.get(dPid).identityMismatch = true;
                        knownDescendants.delete(dPid);
                        continue;
                    }
                    const proofBudget = Math.max(0, remaining() - terminalObservationReserveMs);
                    if (proofBudget <= 0) return false;
                    const liveness = processLiveness(dPid, {
                        ...options,
                        timeout: Math.min(1000, proofBudget),
                        expectedStartToken: dToken,
                    });
                    if (liveness.state !== 'dead') return false;
                }
            } else {
                // A descendant can legitimately exit between the final live-tree
                // enumeration and its identity query (Windows process trees often
                // include short-lived command/intermediate nodes).  A tokenless
                // PID is never signalable, but a positive dead proof is enough to
                // remove that exited node from the teardown set without adopting
                // or killing a potentially reused PID.  Alive/unknown remains
                // fail-closed and blocks teardown.
                const proofBudget = Math.max(0, remaining() - terminalObservationReserveMs);
                if (proofBudget <= 0) return false;
                const liveness = processLiveness(dPid, {
                    ...options,
                    timeout: Math.min(1000, proofBudget),
                });
                if (liveness.state === 'dead') {
                    // Remove a positively dead tokenless node from the current
                    // teardown set. If its numeric PID is reused later, it is
                    // no longer part of this invocation and will never be
                    // signaled or re-probed as owned.
                    knownDescendants.delete(dPid);
                    const knownItem = globalOwnedRegistry.items.get(dPid);
                    if (knownItem && !knownItem.startToken) knownItem.identityMismatch = true;
                    continue;
                }
                console.error(
                    `[e2e-canonical] Refusing unverified kill: start token unknown for descendant PID ${dPid} ` +
                        `(${liveness.state}${liveness.reason ? `: ${liveness.reason}` : ''}).`,
                );
                return false;
            }
        }
    }

    // POSIX teardown has one authority: the exact detached child handle's
    // process-group capability. Never signal historical or individually
    // discovered descendant PIDs, including after root exit/reparent.
    if (platform !== 'win32' && rootOwnershipProven) {
        signalOwnedProcessGroup('SIGTERM');
    }

    // Terminate the root last, after all exact-owned descendants have either
    // accepted termination or been proven already dead under the same deadline.
    if (!childHasExited) {
        if (platform === 'win32') {
            if (expectedStartToken) {
                const budget = Math.max(0, remaining() - terminalObservationReserveMs);
                if (budget <= 0) return false;
                const { result: killRes, rawResult } = runKillPidTreeWithRawResult(pid, budget, {
                    ...options,
                    expectedStartToken,
                });
                if (killRes.status !== 0) {
                    if (isIdentityMismatchKillResult(killRes, rawResult)) {
                        // The expected root incarnation is absent. Treat that
                        // incarnation as terminal, but never probe or signal the
                        // current process occupying the numeric PID.
                        rootIdentityMismatch = true;
                        globalOwnedRegistry.items.get(pid).identityMismatch = true;
                    } else {
                        const proofBudget = Math.max(0, remaining() - terminalObservationReserveMs);
                        if (proofBudget <= 0) return false;
                        const liveness = processLiveness(pid, {
                            ...options,
                            timeout: Math.min(1000, proofBudget),
                            expectedStartToken,
                        });
                        if (liveness.state !== 'dead') {
                            console.error(
                                `[e2e-canonical] identity-safe kill error for PID ${pid}:`,
                                killRes.output,
                            );
                        }
                    }
                }
            } else {
                console.error(
                    `[e2e-canonical] Refusing unverified kill: start token unknown for root PID ${pid}.`,
                );
            }
        } else if (rootOwnershipProven) {
            const ownedHandle =
                typeof child === 'object' && child !== null
                    ? spawnOwnedChildHandles.get(child)
                    : undefined;
            const hasExactCapability =
                ownedHandle?.rootPid === pid &&
                ownedHandle?.pgid === pid &&
                child.pid === ownedHandle.rootPid &&
                typeof child.kill === 'function';
            if (hasExactCapability) {
                try {
                    signalProcess(-ownedHandle.pgid, 'SIGTERM');
                } catch {}
            }
        }
    }

    // Await bounded exit of root and ALL descendants (including registry entries)
    // using the same deadline established before discovery/kill.
    let allDead = false;

    if (rootIdentityMismatch) {
        // The expected root incarnation is already proven absent. Do not probe the
        // numeric root PID, which may now refer to a foreign replacement process;
        // still prove every exact-owned descendant before terminalizing the tree.
        while (Date.now() < deadline) {
            const pidsToCheck = [...knownDescendants];
            let anyAlive = false;

            for (const targetPid of pidsToCheck) {
                const targetItem = globalOwnedRegistry.items.get(targetPid);
                if (targetItem?.identityMismatch) continue;
                const budget = Math.max(0, remaining() - terminalObservationReserveMs);
                if (budget <= 0) return false;
                const liveness = processLiveness(targetPid, {
                    timeout: Math.min(1000, budget),
                    ...options,
                    expectedStartToken: targetItem?.startToken ?? undefined,
                });
                if (liveness.state !== 'dead') {
                    anyAlive = true;
                    break;
                }
            }

            if (!anyAlive) {
                allDead = true;
                break;
            }

            await delay(100);
        }
    } else {
        while (Date.now() < deadline) {
            const postSignalObservation = observeProcessCycle(options, deadline);
            const iterationOptions = postSignalObservation
                ? { ...options, _processObservation: postSignalObservation }
                : options;
            // Identity-exact ownership refresh: re-bind ONLY through the verified
            // owned root while it is still alive. Once the root has exited, a
            // descendant may be reparented and the binder can no longer distinguish
            // an expected dead tree from a PID-reused foreign process; do not turn
            // that normal post-kill state into an unverified teardown failure.
            const rootExitedByHandle = typeof child === 'object' && child.exitCode !== null;
            let rootLiveness = rootExitedByHandle
                ? { state: 'dead', reason: 'child-exit-observed' }
                : processLiveness(pid, {
                      timeout: Math.min(
                          1000,
                          Math.max(0, remaining() - terminalObservationReserveMs),
                      ),
                      ...iterationOptions,
                      expectedStartToken:
                          globalOwnedRegistry.items.get(pid)?.startToken ??
                          options.expectedStartToken ??
                          undefined,
                  });
            if (rootLiveness.state === 'alive') {
                const refresh = bindOwnedDescendantIdentities(pid, options);
                if (refresh.teardownVerified === false) teardownVerified = false;
                for (const d of [...refresh.bound, ...refresh.alreadyBound]) {
                    knownDescendants.add(d);
                }
            } else if (rootLiveness.state === 'unknown') {
                teardownVerified = false;
            }

            const pidsToCheck = [pid, ...knownDescendants];
            let anyAlive = false;

            for (const targetPid of pidsToCheck) {
                // Note: even if child has exited, if targetPid was passed or is descendant, we check liveness!
                if (typeof child === 'object' && targetPid === pid && child.exitCode !== null) {
                    continue;
                }
                const targetItem = globalOwnedRegistry.items.get(targetPid);
                if (targetItem?.identityMismatch) {
                    // PID was reused by a foreign process: the owned process itself
                    // is gone, so it must not keep teardown alive nor be killed.
                    continue;
                }
                const budget = Math.max(0, remaining() - terminalObservationReserveMs);
                if (budget <= 0) return false;
                const liveness = processLiveness(targetPid, {
                    timeout: Math.min(1000, budget),
                    ...iterationOptions,
                    expectedStartToken:
                        targetItem?.startToken ?? options.expectedStartToken ?? undefined,
                });
                if (liveness.state !== 'dead') {
                    anyAlive = true;
                    break;
                }
            }

            if (!anyAlive) {
                allDead = true;
                break;
            }

            await delay(100);
        }
    }

    // POSIX escalation is authorized only for the exact detached owned process
    // group. Never signal a positive root/descendant PID: a root-exit race can
    // leave those numeric PIDs reused by foreign processes.
    if (!allDead && platform !== 'win32') {
        const groupState = probeOwnedProcessGroup();
        if (groupState.state === 'unknown') return false;
        if (groupState.state === 'alive') {
            const budget = Math.max(0, remaining() - terminalObservationReserveMs);
            if (budget <= 0) return false;
            if (!signalOwnedProcessGroup('SIGKILL')) return false;
        }

        const escalationDelay = Math.min(100, remaining());
        if (escalationDelay <= 0) return false;
        await delay(escalationDelay);

        const terminalGroup = probeOwnedProcessGroup();
        if (terminalGroup.state !== 'dead') return false;

        let anyAlive = false;
        for (const targetPid of [pid, ...knownDescendants]) {
            const targetItem = globalOwnedRegistry.items.get(targetPid);
            if (targetItem?.identityMismatch) continue;
            const budget = Math.max(0, remaining() - terminalObservationReserveMs);
            if (budget <= 0) return false;
            const liveness = processLiveness(targetPid, {
                timeout: Math.min(1000, budget),
                expectedStartToken: targetItem?.startToken ?? undefined,
            });
            if (liveness.state !== 'dead') {
                anyAlive = true;
                break;
            }
        }
        if (!anyAlive) allDead = true;
    }

    if (!allDead) {
        console.error(
            `[e2e-canonical] killProcessTree timed out waiting for PID ${pid} or its descendants to exit.`,
        );
        return false;
    }

    if (!teardownVerified) {
        console.error(
            `[e2e-canonical] Teardown remained unverified because descendant discovery was unknown for PID ${pid}.`,
        );
        return false;
    }

    // Defect exact (1): Proof port/process mati secara bounded sebelum return/mutex release
    if (portsToCheck.size > 0) {
        for (const p of portsToCheck) {
            let portTerminal = false;
            while (Date.now() < deadline) {
                const portState = await portProbe(p, remaining());
                if (portState?.state === 'free') {
                    portTerminal = true;
                    break;
                }
                if (portState?.state === 'unknown') {
                    console.error(
                        `[e2e-canonical] Port ${p} listener state remained unknown after PID ${pid} termination (${portState.reason ?? 'unknown'}).`,
                    );
                    return false;
                }
                const portDelay = Math.min(100, remaining());
                if (portDelay <= 0) break;
                await delay(portDelay);
            }
            if (!portTerminal) {
                console.error(
                    `[e2e-canonical] Port ${p} still has an active listener after PID ${pid} termination.`,
                );
                return false;
            }
        }
        return true;
    }

    return true;
};

const PROBE_TERMINAL_PHASES = Object.freeze({
    supervisor: 'supervisor',
    signal: 'signal',
    root: 'root',
    descendants: 'descendants',
    listener: 'listener',
    deadline: 'deadline',
    terminal: 'terminal',
});

const safeProbeReason = (value, fallback) => {
    if (typeof value !== 'string' || !/^[a-z][a-z0-9-]{0,63}$/.test(value)) return fallback;
    return value;
};

const reduceProbeTeardownResult = (observed) => {
    const deadlineExhausted = observed.deadlineExhausted === true;
    const supervisorVerified = observed.supervisor.verified === true;
    const rootTerminal = observed.root.state === 'dead' && observed.root.identity === 'exact';
    const descendantsTerminal = observed.descendants.state === 'terminal';
    const listenerFree = observed.listener.state === 'free';
    const ok =
        !deadlineExhausted &&
        supervisorVerified &&
        observed.signal.outcome === 'completed' &&
        rootTerminal &&
        descendantsTerminal &&
        listenerFree;

    let phase = PROBE_TERMINAL_PHASES.terminal;
    let reason = 'terminal-proof-complete';
    if (deadlineExhausted) {
        phase = PROBE_TERMINAL_PHASES.deadline;
        reason = 'absolute-deadline-exhausted';
    } else if (!supervisorVerified) {
        phase = PROBE_TERMINAL_PHASES.supervisor;
        reason = safeProbeReason(observed.supervisor.reason, 'supervisor-unverified');
    } else if (observed.signal.outcome !== 'completed') {
        phase = PROBE_TERMINAL_PHASES.signal;
        reason = safeProbeReason(observed.signal.reason, 'signal-outcome-unverified');
    } else if (!rootTerminal) {
        phase = PROBE_TERMINAL_PHASES.root;
        reason = safeProbeReason(observed.root.reason, 'root-not-terminal');
    } else if (!descendantsTerminal) {
        phase = PROBE_TERMINAL_PHASES.descendants;
        reason = safeProbeReason(observed.descendants.reason, 'descendants-not-terminal');
    } else if (!listenerFree) {
        phase = PROBE_TERMINAL_PHASES.listener;
        reason = safeProbeReason(observed.listener.reason, 'listener-not-free');
    }

    return {
        ok,
        phase,
        reason,
        root: observed.root,
        descendants: observed.descendants,
        listener: observed.listener,
        supervisor: observed.supervisor,
        deadlineExhausted,
        signalAttempts: observed.signalAttempts,
    };
};

const emitProbeTeardownFailure = (result, logger = console.error) => {
    if (result.ok) return;
    logger(
        JSON.stringify({
            ok: false,
            phase: result.phase,
            reason: result.reason,
            root: result.root,
            descendants: result.descendants,
            listener: result.listener,
            supervisor: result.supervisor,
            deadlineExhausted: result.deadlineExhausted,
            signalAttempts: result.signalAttempts,
        }),
    );
};

/** Actual probe-webserver teardown consumer with one structured terminal reducer. */
export async function teardownProbeWebserver(child, options = {}) {
    const timeoutMs = options.timeoutMs ?? 5000;
    const platform = options._platform ?? process.platform;
    const deadline = Date.now() + timeoutMs;
    const port = typeof options.port === 'number' ? options.port : null;
    const killer = options._killProcessTree ?? options.killProcessTree ?? killProcessTree;
    const portProbe =
        options._probePortListener ??
        ((targetPort, budget) => probePortListener(targetPort, budget, options));
    const supervisor = { verified: true, lastPass: null, reason: null };
    const signalAttempts = [];
    const ownedRootToken =
        options.expectedStartToken ??
        (child?.pid ? globalOwnedRegistry.items.get(child.pid)?.startToken : null);
    const exactRootIdentity =
        Boolean(ownedRootToken) ||
        (platform !== 'win32' && isSpawnOwnedChildHandle(child, child?.pid));
    const observed = {
        root: {
            state: child && child.exitCode !== null ? 'dead' : 'alive',
            identity: exactRootIdentity ? 'exact' : 'unknown',
            handleExitObserved: child?.exitCode !== null,
            reason: null,
        },
        descendants: { state: 'unknown', identity: 'exact-owned', owned: [] },
        listener: { port, state: port === null ? 'free' : 'unknown', reason: null },
        supervisor,
        signal: { outcome: 'not-started', reason: null },
        signalAttempts,
        deadlineExhausted: false,
    };

    try {
        if (typeof options.stopSupervisor === 'function') {
            const stopped = options.stopSupervisor();
            supervisor.verified = stopped !== false && stopped?.teardownVerified !== false;
            supervisor.lastPass = stopped?.lastPass ?? null;
            if (!supervisor.verified) supervisor.reason = 'supervisor-unverified';
        }

        const budget = Math.max(0, deadline - Date.now());
        if (budget <= 0) {
            observed.deadlineExhausted = true;
        } else if (!child) {
            observed.signal.outcome = 'completed';
            observed.root.state = 'dead';
            observed.descendants.state = 'terminal';
        } else if (platform === 'win32' && !ownedRootToken) {
            observed.signal.outcome = 'failed';
            observed.signal.reason = 'root-identity-unverifiable';
            observed.root.reason = 'root-identity-unverifiable';
        } else if (
            child?.pid &&
            globalOwnedRegistry.items.get(child.pid)?.identityMismatch === true
        ) {
            observed.signal.outcome = 'failed';
            observed.signal.reason = 'root-identity-mismatch';
            observed.root.reason = 'root-identity-mismatch';
        } else {
            signalAttempts.push({
                target: 'owned-tree',
                ownership: 'exact-owned',
                outcome: 'attempted',
            });
            // Keep the existing absolute deadline bounded while reserving time
            // for the current-run terminal re-observation below. A bounded killer
            // may consume its full budget and still report failure after the owned
            // PID is terminal; passing the entire remaining budget would make that
            // positive proof impossible and incorrectly leave teardown at signal.
            const signalBudget = Math.max(0, budget - FINAL_OBSERVATION_RESERVE_MS);
            if (signalBudget <= 0) {
                observed.deadlineExhausted = true;
            }
            const killed =
                signalBudget > 0
                    ? await killer(child, signalBudget, {
                          port,
                          includeGlobalRegistry: false,
                          _requireSpawnOwnership: platform !== 'win32',
                          _spawnOwnedRoot: child,
                          ...(options.killOptions ?? {}),
                          // The consumer already reserves the post-signal proof
                          // window; avoid subtracting that reserve again inside the
                          // bounded tree killer.
                          finalObservationReserveMs: 0,
                      })
                    : false;
            observed.signal.outcome = killed === true ? 'completed' : 'failed';
            if (killed !== true) {
                observed.signal.reason = 'bounded-killer-failed';

                // A bounded signal helper can report failure after the owned
                // process has already become terminal (the Windows path can
                // observe this through the handle or an identity-bound probe).
                // Re-observe within the existing deadline; never signal, adopt,
                // or treat an alive/unknown PID as terminal proof.
                const processProbe =
                    options._probeProcessLiveness ??
                    options.probeProcessLiveness ??
                    options.killOptions?._probeProcessLiveness ??
                    options.killOptions?.probeProcessLiveness ??
                    probeProcessLiveness;
                const ownedDescendants = () => {
                    const items = [...globalOwnedRegistry.items.values()];
                    const ownedPids = new Set([child.pid]);
                    let changed = true;
                    while (changed) {
                        changed = false;
                        for (const item of items) {
                            if (
                                item.identityMismatch !== true &&
                                ownedPids.has(item.parentPid) &&
                                !ownedPids.has(item.pid)
                            ) {
                                ownedPids.add(item.pid);
                                changed = true;
                            }
                        }
                    }
                    return items.filter(
                        (item) =>
                            ownedPids.has(item.pid) &&
                            item.pid !== child.pid &&
                            item.identityMismatch !== true,
                    );
                };
                let postSignalTerminal = false;
                while (remainingBudgetMs(deadline) > 0) {
                    // Recovery is a fresh observation cycle. Reuse this one CIM
                    // snapshot for root and descendant proofs in this cycle so a
                    // reused PID cannot fall back to tasklist between probes.
                    const processObservation = observeProcessCycle(options, deadline);
                    const iterationOptions = processObservation
                        ? { ...options, _processObservation: processObservation }
                        : options;
                    const rootTerminal =
                        child.exitCode !== null
                            ? { state: 'dead', reason: 'child-exit-observed' }
                            : child.pid && ownedRootToken
                              ? processProbe(child.pid, {
                                    ...iterationOptions,
                                    ...(options.killOptions ?? {}),
                                    timeout: Math.min(1000, remainingBudgetMs(deadline)),
                                    expectedStartToken: ownedRootToken,
                                })
                              : { state: 'unknown', reason: 'root-identity-unverifiable' };
                    if (rootTerminal.state === 'unknown') {
                        observed.root.reason = rootTerminal.reason ?? 'root-terminal-proof-unknown';
                        break;
                    }
                    if (rootTerminal.state === 'alive') {
                        await delay(Math.min(25, remainingBudgetMs(deadline)));
                        continue;
                    }

                    const descendants = ownedDescendants();
                    let descendantsTerminal = true;
                    for (const descendant of descendants) {
                        const descendantProof = processProbe(descendant.pid, {
                            ...iterationOptions,
                            ...(options.killOptions ?? {}),
                            timeout: Math.min(1000, remainingBudgetMs(deadline)),
                            expectedStartToken: descendant.startToken ?? undefined,
                        });
                        if (descendantProof.state !== 'dead') {
                            descendantsTerminal = false;
                            observed.descendants.reason =
                                descendantProof.reason ?? 'descendant-terminal-proof-unverified';
                            break;
                        }
                    }
                    if (!descendantsTerminal) break;

                    observed.root.state = 'dead';
                    observed.root.handleExitObserved = child.exitCode !== null;
                    observed.descendants.state = 'terminal';
                    observed.signal.outcome = 'completed';
                    observed.signal.reason = 'post-signal-terminal-reobserved';
                    postSignalTerminal = true;
                    break;
                }
                if (!postSignalTerminal) {
                    observed.root.state = 'unknown';
                    observed.descendants.state = 'unknown';
                }
            } else {
                observed.root.handleExitObserved = child.exitCode !== null;
                observed.root.state = 'dead';
                observed.descendants.state = supervisor.verified ? 'terminal' : 'unknown';
            }
        }

        const remaining = Math.max(0, deadline - Date.now());
        if (remaining <= 0) {
            observed.deadlineExhausted = true;
        } else if (port !== null && observed.signal.outcome === 'completed') {
            const listener = await portProbe(port, remaining);
            observed.listener = {
                port,
                state:
                    listener?.state === 'free'
                        ? 'free'
                        : listener?.state === 'listener'
                          ? 'listener'
                          : 'unknown',
                ...(listener?.reason
                    ? { reason: safeProbeReason(listener.reason, 'listener-unknown') }
                    : {}),
            };
        }
    } catch (error) {
        observed.signal.outcome = 'failed';
        observed.signal.reason = safeProbeReason(
            error instanceof Error ? error.message : String(error),
            'teardown-error',
        );
        observed.root.state = 'unknown';
        observed.descendants.state = 'unknown';
    }

    const result = reduceProbeTeardownResult(observed);
    if (!result.ok && options.emitFailure !== false)
        emitProbeTeardownFailure(result, options.logger);
    return result;
}

/**
 * Proves POST /login is not 500 on the sanitized environment: a bogus
 * credential attempt must traverse the full auth pipeline (users table query)
 * and come back as an auth redirect (3xx). CSRF failures (419) are treated as
 * a broken proof because they short-circuit before touching the database.
 */
export async function probeLoginIsNotServerError(port, timeoutMs = 20_000) {
    const baseUrl = `http://${host}:${port}`;
    const deadline = Date.now() + timeoutMs;
    let loginPage;

    while (true) {
        try {
            loginPage = await fetch(`${baseUrl}/login`, { redirect: 'manual' });
            break;
        } catch {
            if (Date.now() > deadline) {
                throw new Error(`Probe server did not become reachable at ${baseUrl}/login.`);
            }

            await delay(300);
        }
    }

    if (loginPage.status >= 500) {
        throw new Error(`GET /login returned ${loginPage.status} on the sanitized environment.`);
    }

    const cookies = loginPage.headers
        .getSetCookie()
        .map((cookie) => cookie.split(';')[0])
        .filter(Boolean);
    const xsrfCookiePrefix = 'XSRF-TOKEN=';
    const xsrfCookie = cookies.find((cookie) => cookie.startsWith(xsrfCookiePrefix));
    const headers = {
        Cookie: cookies.join('; '),
        'Content-Type': 'application/x-www-form-urlencoded',
    };

    if (xsrfCookie) {
        headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrfCookie.slice(xsrfCookiePrefix.length));
    }

    const response = await fetch(`${baseUrl}/login`, {
        method: 'POST',
        headers,
        body: new URLSearchParams({
            email: 'no-such-e2e-probe@example.test',
            password: 'wrong-password-e2e-probe',
        }),
        redirect: 'manual',
    });

    if (response.status >= 500) {
        throw new Error(
            `POST /login returned ${response.status}; sanitized environment is broken.`,
        );
    }

    if (response.status < 300 || response.status >= 400) {
        throw new Error(
            `POST /login returned ${response.status}; expected an auth redirect (3xx) proving ` +
                'the login pipeline and database are reachable.',
        );
    }

    console.log(`[e2e-canonical] probe POST /login -> ${response.status} (non-500 proven).`);
}

// Active child process registry for bounded signal shutdown
let activeChild = null;
let activeChildState = 'none';
let activeMutex = null;

const runExternalStep = (command, args, childEnv) =>
    new Promise((resolve) => {
        const child = spawnOwnedChild(command, args, {
            env: childEnv,
            stdio: 'inherit',
            windowsHide: true,
            ...(process.platform !== 'win32' ? { detached: true } : {}),
        });

        activeChild = child;
        activeChildState = 'running';
        if (child.pid) {
            const childStartToken = getProcessStartToken(child.pid);
            globalOwnedRegistry.register(child.pid, {
                parentPid: process.pid,
                startToken: childStartToken ?? undefined,
            });
            // Also update any live mutex metadata with newly spawned child
            if (activeMutex && typeof activeMutex.updateOwnedMetadata === 'function') {
                activeMutex.updateOwnedMetadata();
            }
        }

        // Poll frequently enough to bind short-lived step-spawned descendants
        // before a fast step exits; after exit, parent linkage is no longer a
        // safe ownership proof on Windows.
        const pollInterval = setInterval(() => {
            if (child.pid && activeMutex) {
                const childStartToken = getProcessStartToken(child.pid);
                globalOwnedRegistry.register(child.pid, {
                    parentPid: process.pid,
                    startToken: childStartToken ?? undefined,
                });
                // Bind immutable identity of late-spawned descendants WHILE the
                // step is still alive, so teardown never has to discover them.
                bindOwnedDescendantIdentities(child.pid);
                activeMutex.updateOwnedMetadata();
            }
        }, 25);

        child.once('error', (error) => {
            clearInterval(pollInterval);
            activeChildState = 'exited-error';
            // Retain completed/errored handle until outer teardown proves it
            // terminal.  Clearing activeChild here skips the canonical kill phase,
            // so teardown failures/unknown discovery can release mutex early and
            // the single absolute deadline loses its required kill observation.
            console.error(error);
            resolve(1);
        });
        child.once('exit', (code, signal) => {
            clearInterval(pollInterval);
            activeChildState = code === 0 && signal === null ? 'exited-normal' : 'exited-failure';
            if (child.pid && activeMutex) {
                const childStartToken = getProcessStartToken(child.pid);
                globalOwnedRegistry.register(child.pid, {
                    parentPid: process.pid,
                    startToken: childStartToken ?? undefined,
                });
                activeMutex.updateOwnedMetadata();
            }
            // Keep exited handle in activeChild.  The owning main() finally or
            // signal shutdown must still execute one bounded kill/terminal phase;
            // killProcessTree handles an already-exited child without signaling it.
            resolve(code ?? (signal ? 1 : 0));
        });
    });

const runChainStep = (args, childEnv) => runExternalStep(process.execPath, args, childEnv);

export async function main(options = {}) {
    // Mutex bootstrap is the only option resolution allowed before ownership.
    // Its schema is the immutable canonical contract, not external configuration.
    const lockPath = options.lockPath ?? undefined;
    const acquirer =
        options._acquireCanonicalMutex || options.acquireCanonicalMutex || acquireCanonicalMutex;

    // Resolve and validate all database-affecting input before taking mutex
    // ownership or starting any child/process lifecycle. The validation is
    // read-only; logging and every mutation remain serialized by the mutex.
    const sourceEnv = options.sourceEnv || process.env;
    const phpBinaryHint = resolvePhpBinary(options.phpBinary ?? sourceEnv.PHP_BINARY);
    let runnerArgs = options.argv ? options.argv.slice(2) : process.argv.slice(2);
    const customSteps = options.steps ?? null;
    const runPlaywrightStep = options.runPlaywrightStep ?? customSteps === null;
    const executionMode = resolveCanonicalExecutionMode({
        customSteps,
        runPlaywrightStep,
        runnerArgs,
    });
    const killer = options._killProcessTree || options.killProcessTree || killProcessTree;
    const probeTeardown =
        options._teardownProbeWebserver || options.teardownProbeWebserver || teardownProbeWebserver;
    const verifier =
        options._verifyOwnedRegistryTerminal ||
        options.verifyOwnedRegistryTerminal ||
        verifyOwnedRegistryTerminal;
    const credentialReader = options._parseEnvFileCredentials || parseEnvFileCredentials;
    const stepRunner = options._runChainStep || options.runChainStep || runChainStep;
    const externalStepRunner =
        options._runExternalStep || options.runExternalStep || runExternalStep;
    const canonical = buildCanonicalChildEnv(sourceEnv);
    const schema = canonical.schema;
    const childEnv = { ...canonical.childEnv, PHP_BINARY: phpBinaryHint };
    const credentials = credentialReader();
    assertCanonicalDatabaseName(credentials.database);
    let probeServerChild = null;
    let probePortBound = null;
    let probeServerSupervisor = null;
    let probeServerTeardownVerified = true;
    let isShuttingDown = false;
    let mutex = null;
    let runtimePidBaseline = null;

    const stopProbeServerSupervisor = () => {
        if (!probeServerSupervisor) return true;

        // stop() performs the final identity bind while the root is still alive.
        // Persist that final registry state before kill can detach/reparent the
        // actual listener or a failed teardown leaves the durable lock behind.
        const supervisor = probeServerSupervisor;
        supervisor.stop();
        const teardownVerified = supervisor.teardownVerified !== false;
        if (!teardownVerified) probeServerTeardownVerified = false;
        probeServerSupervisor = null;
        if (activeMutex && typeof activeMutex.updateOwnedMetadata === 'function') {
            activeMutex.updateOwnedMetadata();
        }
        return teardownVerified;
    };

    // Signal and exit cleanup handler
    const performShutdown = async (exitCode = 1) => {
        if (isShuttingDown) return;
        isShuttingDown = true;

        // Single absolute teardown deadline shared across kill and terminal proof.
        const teardownDeadline = Date.now() + TEARDOWN_TOTAL_TIMEOUT_MS;

        let teardownFailed = !probeServerTeardownVerified;

        if (probeServerSupervisor) {
            // Final identity bind before anything is terminated.
            if (!stopProbeServerSupervisor()) teardownFailed = true;
        }

        if (probeServerChild) {
            const killed = await killer(probeServerChild, remainingBudgetMs(teardownDeadline), {
                port: probePortBound,
            });
            if (!killed) teardownFailed = true;
            probeServerChild = null;
            probePortBound = null;
        }

        if (activeChild) {
            const killed = await killer(activeChild, remainingBudgetMs(teardownDeadline), {
                exitedNormally: activeChildState === 'exited-normal',
            });
            if (!killed) teardownFailed = true;
        }
        activeChild = null;
        activeChildState = 'none';

        await delay(100);

        // Terminal proof of the exact owned child-tree and owned ports BEFORE the
        // registry is cleared or the mutex is released.
        if (!teardownFailed) {
            const terminalProof = await verifier({
                timeoutMs: remainingBudgetMs(teardownDeadline),
            });
            if (!terminalProof.ok) {
                console.error(
                    `[e2e-canonical] Owned registry not terminal: ` +
                        `alive PID(s) ${JSON.stringify(terminalProof.alivePids)}, ` +
                        `bound port(s) ${JSON.stringify(terminalProof.boundPorts)}.`,
                );
                teardownFailed = true;
            }
        }

        // Signal shutdown must reconcile the exact current-run PID-file snapshot
        // before releasing the mutex, just like normal finally cleanup. This uses
        // the same absolute deadline and retains evidence on ambiguity/failure.
        if (!teardownFailed && runtimePidBaseline) {
            if (remainingBudgetMs(teardownDeadline) <= 0) {
                teardownFailed = true;
            } else {
                snapshotCurrentRunPidFileContents(runtimePidBaseline, e2eRuntimeDir);
                if (remainingBudgetMs(teardownDeadline) <= 0) {
                    teardownFailed = true;
                } else {
                    const runtimeCleanup = reconcileCurrentRunPidFiles(runtimePidBaseline);
                    if (runtimeCleanup.cleanupFailed) teardownFailed = true;
                }
            }
        }

        // Defect exact (1): teardown gagal/unknown harus fail-closed, mutex HANYA dilepas jika teardown sukses!
        if (teardownFailed) {
            console.error(
                '[e2e-canonical] Teardown failed or remained unverified: holding mutex and exiting fail-closed.',
            );
            if (options.throwOnExit) {
                const err = new Error('Teardown failed fail-closed');
                err.exitCode = 1;
                throw err;
            }
            process.exit(1);
        }

        let mutexReleased = true;
        if (mutex) {
            try {
                mutexReleased = mutex.release() === true;
            } catch {
                mutexReleased = false;
            }
        }

        if (!mutexReleased) {
            console.error(
                '[e2e-canonical] Mutex release failed or ownership changed: retaining registry evidence and exiting fail-closed.',
            );
            if (options.throwOnExit) {
                const err = new Error('Mutex release failed fail-closed');
                err.exitCode = 1;
                throw err;
            }
            process.exit(1);
        }

        // Unregister/clear registry on confirmed terminal teardown
        globalOwnedRegistry.clear();

        if (options.throwOnExit) {
            const err = new Error(`Terminated with exit code ${exitCode}`);
            err.exitCode = exitCode;
            throw err;
        }
        process.exit(exitCode);
    };

    // Defect exact (1): handler signal harus terpasang sebelum acquire tanpa gap
    const signalHandlers = new Map();
    for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
        const handler = () => {
            console.error(`[e2e-canonical] Received ${signal}; performing bounded shutdown...`);
            const code = signal === 'SIGINT' ? 130 : signal === 'SIGTERM' ? 143 : 129;
            void performShutdown(code);
        };
        signalHandlers.set(signal, handler);
        process.on(signal, handler);
    }

    const messageHandler = (msg) => {
        const sig = typeof msg === 'string' ? msg : msg?.signal;
        if (['SIGINT', 'SIGTERM', 'SIGHUP'].includes(sig)) {
            console.error(
                `[e2e-canonical] Received IPC signal message ${sig}; performing bounded shutdown...`,
            );
            process.emit(sig);
        }
    };
    process.on('message', messageHandler);

    try {
        // Acquire atomic cross-process mutex before canonical configuration is
        // resolved or reported. The lock schema is the immutable contract value;
        // no .env, DB, server, browser, or child-process lifecycle runs first.
        mutex = acquirer({ schema: canonicalDatabase, pid: process.pid, lockPath });
        activeMutex = mutex;

        // Defect exact (5): only an explicit test-only injection may skip canonical
        // preflight. No production environment variable can bypass the schema
        // reachability / HTTP-login contract proof anymore.
        const skipPreflight = options.skipPreflight === true;
        const opsSmokeIndex = runnerArgs.indexOf('--ops-smoke');
        const runOpsSmoke = opsSmokeIndex !== -1;
        if (runOpsSmoke) runnerArgs.splice(opsSmokeIndex, 1);

        if (canonical.droppedSensitiveNames.length > 0) {
            console.log(
                `[e2e-canonical] parent shell sanitized; dropped from children (names only): ` +
                    `${canonical.droppedSensitiveNames.join(', ')}`,
            );
        }

        const explicitSchema = credentials.database !== canonicalDatabase;
        const schemaLabel = explicitSchema ? 'explicit' : 'canonical';
        console.log(
            `[e2e-canonical] target database: ${schema} (${schemaLabel} from .env.e2e; canonical shared schema).`,
        );

        // Canonical schema preflight: prove the shared schema exists and is
        // reachable (fail-closed, read-only — never creates or drops anything).
        // The schema is intentionally NOT dropped at the end: it is shared across
        // runs (AGENTS.md Test Database Rule), so subsequent runs migrate:fresh it.
        console.log(
            `[e2e-canonical] [SOURCE-AUDIT: READ-ONLY] running non-destructive preflight existence probe...`,
        );
        if (!skipPreflight) {
            ensureCanonicalSchema(credentials, schema, phpBinaryHint);
        }

        // Non-destructive preflight chain: the shared inbils_test schema is
        // provisioned OUTSIDE this runner (see ensureCanonicalSchema); setup.php is
        // invoked in --check mode only so no migrate:fresh / db:seed / schema
        // lifecycle statement ever runs against the shared schema from this chain.
        const steps = customSteps ?? [
            ['scripts/e2e-guard-matrix.mjs'],
            ['scripts/e2e-setup.mjs', '--check'],
        ];

        for (const args of steps) {
            const code = await stepRunner(args, childEnv);

            if (code !== 0) {
                process.exitCode = code;
                return code;
            }
        }

        if (runOpsSmoke) {
            console.log(
                '[e2e-canonical] [OPS PHP: FIXTURE-MUTATING MODE] running shared-schema PHP checks inside canonical mutex.',
            );
            const code = await externalStepRunner(
                phpBinaryHint,
                [
                    'artisan',
                    'test',
                    '--compact',
                    'tests/Feature/ApiExposureTest.php',
                    'tests/Feature/Operations/ReadinessEndpointTest.php',
                    'tests/Feature/Operations/SchedulerLockTest.php',
                ],
                childEnv,
            );
            if (code !== 0) {
                process.exitCode = code;
                return code;
            }
        }

        if (!skipPreflight) {
            // Contract step: prove the sanitized environment serves a healthy login
            // pipeline on THIS schema before paying for the browser run.
            const probePort = await findFreePort();
            probePortBound = probePort;
            globalOwnedRegistry.registerPort(probePort);
            const probeServer = spawnOwnedChild(
                phpBinaryHint,
                ['artisan', 'serve', '--env=e2e', `--host=${host}`, `--port=${String(probePort)}`],
                {
                    env: childEnv,
                    stdio: 'ignore',
                    windowsHide: true,
                    ...(process.platform !== 'win32' ? { detached: true } : {}),
                },
            );
            probeServerChild = probeServer;
            if (probeServer.pid) {
                const probeServerStartToken = getProcessStartToken(probeServer.pid, childEnv);
                globalOwnedRegistry.register(probeServer.pid, {
                    parentPid: process.pid,
                    port: probePort,
                    startToken: probeServerStartToken ?? undefined,
                });
                // `php artisan serve` spawns its real `php -S` listener AFTER the
                // root is registered. Supervise while the owner is alive so the
                // late-spawned listener gets an immutable start-token; stop()
                // performs the final bind immediately before teardown.
                probeServerSupervisor = startOwnedDescendantSupervisor(probeServer.pid, {
                    ...childEnv,
                    supervisorIntervalMs: options.supervisorIntervalMs ?? 250,
                    _bindOwnedDescendantIdentities: (rootPid, supervisorOptions) => {
                        const binding = bindOwnedDescendantIdentities(rootPid, supervisorOptions);
                        if (activeMutex && typeof activeMutex.updateOwnedMetadata === 'function') {
                            activeMutex.updateOwnedMetadata();
                        }
                        return binding;
                    },
                });
                if (activeMutex && typeof activeMutex.updateOwnedMetadata === 'function') {
                    activeMutex.updateOwnedMetadata();
                }
            }

            try {
                await probeLoginIsNotServerError(probePort);
            } finally {
                let supervisorVerified = true;
                if (probeServerSupervisor) {
                    // Final pre-teardown identity bind (owner still alive here).
                    supervisorVerified = stopProbeServerSupervisor();
                }
                const teardown = await probeTeardown(probeServer, {
                    port: probePort,
                    expectedStartToken: probeServer.pid
                        ? globalOwnedRegistry.items.get(probeServer.pid)?.startToken
                        : null,
                    _killProcessTree: killer,
                    stopSupervisor: () => ({ teardownVerified: supervisorVerified }),
                    emitFailure: false,
                });
                if (!teardown.ok) {
                    emitProbeTeardownFailure(teardown);
                    throw new Error(
                        `[e2e-canonical] Probe webserver teardown failed at ${teardown.phase}: ${teardown.reason}.`,
                    );
                }
                probeServerChild = null;
                probePortBound = null;
                await delay(200);
            }
        }

        if (executionMode === 'playwright-fixture-mutating') {
            // EXPLICIT MUTATION SEMANTICS BANNER (Finding #6)
            // Announce mutation only on the execution branch that immediately
            // invokes the Playwright fixture-mutating suite.
            console.log(
                `\n================================================================================\n` +
                    `[e2e-canonical] [BROWSER LIFECYCLE: FIXTURE-MUTATING MODE]\n` +
                    `Executing Playwright browser suite against shared serialized schema: ${schema}\n` +
                    `Notice: ${schema} is the SHARED inbils_test schema, serialized by the canonical mutex (one run at a time).\n` +
                    `Browser test specs will mutate and clean fixture data (users, entities).\n` +
                    `This is NOT a read-only audit step.\n` +
                    `================================================================================\n`,
            );
        } else if (executionMode === 'playwright-discovery') {
            console.log(
                '[e2e-canonical] [PLAYWRIGHT DISCOVERY: NON-MUTATING] listing tests; fixture mutation is not executed.',
            );
        } else if (executionMode === 'playwright-metadata') {
            console.log(
                '[e2e-canonical] [PLAYWRIGHT METADATA: NON-MUTATING] showing CLI metadata; fixture mutation is not executed.',
            );
        } else {
            console.log(
                '[e2e-canonical] [CUSTOM STEPS: NON-BROWSER] custom validation path; Playwright fixture mutation is not executed.',
            );
        }

        if (runPlaywrightStep) {
            // Baseline captured BEFORE Playwright: only PID files created by this
            // invocation may ever be considered for the outer reconcile.
            runtimePidBaseline = snapshotRuntimePidFiles(e2eRuntimeDir);
            const code = await stepRunner(['scripts/e2e-run.mjs', ...runnerArgs], childEnv);

            if (code !== 0) {
                process.exitCode = code;
            }
            return code;
        }

        return 0;
    } finally {
        // A signal handler owns the complete teardown once it has started. Killing
        // the active step resolves runChainStep(), so main() can reach this block
        // while performShutdown() is still proving terminal state. Do not race it
        // with a second registry verification or mutex release.
        if (isShuttingDown) return;

        let cleanupFailed = !probeServerTeardownVerified;

        // Single absolute teardown deadline shared across kill and terminal proof.
        const teardownDeadline = Date.now() + TEARDOWN_TOTAL_TIMEOUT_MS;

        if (probeServerSupervisor) {
            // Final identity bind before anything is terminated.
            if (!stopProbeServerSupervisor()) cleanupFailed = true;
        }

        if (probeServerChild) {
            const killed = await killer(probeServerChild, remainingBudgetMs(teardownDeadline), {
                port: probePortBound,
            });
            if (!killed) cleanupFailed = true;
            probeServerChild = null;
            probePortBound = null;
        }

        if (activeChild) {
            const killed = await killer(activeChild, remainingBudgetMs(teardownDeadline), {
                exitedNormally: activeChildState === 'exited-normal',
            });
            if (!killed) cleanupFailed = true;
        }
        activeChild = null;
        activeChildState = 'none';

        // Terminal proof of the exact owned child-tree and owned ports BEFORE the
        // registry is cleared or the mutex is released. Fail-closed: an owned PID
        // still alive or an owned port still bound holds the mutex.
        if (!cleanupFailed) {
            const terminalProof = await verifier({
                timeoutMs: remainingBudgetMs(teardownDeadline),
            });
            if (!terminalProof.ok) {
                console.error(
                    `[e2e-canonical] Owned registry not terminal: ` +
                        `alive PID(s) ${JSON.stringify(terminalProof.alivePids)}, ` +
                        `bound port(s) ${JSON.stringify(terminalProof.boundPorts)}.`,
                );
                cleanupFailed = true;
            }
        }

        if (!cleanupFailed && runtimePidBaseline) {
            // Capture the exact raw contents of current-run PID files AFTER the
            // teardown proved terminal, then reconcile only those newly-created
            // files through their cached snapshot (exact-payload proof). Any
            // untracked/replaced/malformed file fails closed and is retained.
            snapshotCurrentRunPidFileContents(runtimePidBaseline, e2eRuntimeDir);
            const runtimeCleanup = reconcileCurrentRunPidFiles(runtimePidBaseline);
            if (runtimeCleanup.cleanupFailed) {
                cleanupFailed = true;
            }
        }

        if (cleanupFailed) {
            console.error(
                '[e2e-canonical] Cleanup failed or remained unverified: holding mutex and failing closed.',
            );
            process.exitCode = 1;
        } else {
            let mutexReleased = true;
            if (mutex) {
                try {
                    mutexReleased = mutex.release() === true;
                } catch {
                    mutexReleased = false;
                }
            }

            if (!mutexReleased) {
                console.error(
                    '[e2e-canonical] Mutex release failed or ownership changed: retaining registry evidence and failing closed.',
                );
                process.exitCode = 1;
            } else {
                // Unregister only after both terminal proof and exact mutex release.
                globalOwnedRegistry.clear();
            }
        }

        process.removeListener('message', messageHandler);

        for (const [signal, handler] of signalHandlers.entries()) {
            process.removeListener(signal, handler);
        }
    }
}

if (isDirectInvocation) {
    try {
        await main();
    } catch (error) {
        console.error(error instanceof Error ? error.message : String(error));
        process.exit(1);
    }
}
