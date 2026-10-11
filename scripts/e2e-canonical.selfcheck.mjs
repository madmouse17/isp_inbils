import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import test from 'node:test';

import {
    assertCanonicalDatabaseName,
    bindOwnedDescendantIdentities,
    buildCanonicalChildEnv,
    ensureCanonicalSchema,
    killProcessTree,
    main,
    parseEnvFileCredentials,
    reconcileCurrentRunPidFiles,
    resolveCanonicalExecutionMode,
    resolvePosixIdentitySafeOwned,
    spawnOwnedChild,
    snapshotCurrentRunPidFileContents,
    resolveCanonicalDatabase,
    snapshotRuntimePidFiles,
    startOwnedDescendantSupervisor,
    teardownProbeWebserver,
    verifyOwnedRegistryTerminal,
} from './e2e-canonical.mjs';
import { probePortListener } from './e2e-server-reap.mjs';
import {
    defaultLockPath,
    getDescendantPids,
    getProcessStartToken,
    globalOwnedRegistry,
    OwnedProcessRegistry,
    probeProcessLiveness,
    readLockPayload,
} from './e2e-mutex.mjs';
import { buildE2EServerEnv } from './e2e-server-env.mjs';
const poisonedValue = 'canonical-selfcheck-poison-not-a-credential';
const legacyDisposableNames = [
    'hermes_qa_t_0bd1699e_1a2b3c',
    'hermes_qa_t_0bd1699e_1270',
    'hermes_qa_t_0bd1699e_caller_choice',
];

test('actual probe-webserver consumer returns structured terminal result', async (t) => {
    const makeChild = () => ({ pid: 3210, exitCode: null });
    const freeListener = async () => ({ state: 'free' });
    const killOwned = async (child) => {
        child.exitCode = 0;
        return true;
    };

    await t.test(
        'exact-owned root and descendant dead with TIME_WAIT/free authority succeeds',
        async () => {
            const result = await teardownProbeWebserver(makeChild(), {
                port: 43121,
                expectedStartToken: 'owned-root-token',
                _killProcessTree: killOwned,
                _probePortListener: freeListener,
                emitFailure: false,
            });
            assert.equal(result.ok, true);
            assert.equal(result.phase, 'terminal');
            assert.equal(result.reason, 'terminal-proof-complete');
            assert.equal(result.root.state, 'dead');
            assert.equal(result.root.handleExitObserved, true);
            assert.equal(result.descendants.state, 'terminal');
            assert.equal(result.listener.state, 'free');
            assert.equal(result.supervisor.verified, true);
            assert.equal(result.deadlineExhausted, false);
        },
    );

    await t.test(
        'post-signal terminal re-observation converges after bounded killer failure',
        async () => {
            const child = makeChild();
            const result = await teardownProbeWebserver(child, {
                // The killer consumes the budget it receives, reproducing a bounded
                // signal failure at the deadline. Teardown must reserve time for its
                // own current-run terminal re-observation instead of inheriting zero.
                timeoutMs: 400,
                port: 43125,
                expectedStartToken: 'owned-root-token',
                _killProcessTree: async (_child, budget) => {
                    await new Promise((resolve) => setTimeout(resolve, budget));
                    // Model the observed Windows path: the bounded signal helper
                    // reports failure while the exact owned PID is positively dead.
                    // The listener probe reports free for a TIME_WAIT/non-listening
                    // port, so convergence must come from post-signal observation.
                    return false;
                },
                _probeProcessLiveness: () => ({ state: 'dead', reason: 'positive-dead-proof' }),
                _probePortListener: freeListener,
                emitFailure: false,
            });
            assert.equal(result.ok, true);
            assert.equal(result.phase, 'terminal');
            assert.equal(result.reason, 'terminal-proof-complete');
            assert.equal(result.root.state, 'dead');
            assert.equal(result.root.handleExitObserved, false);
            assert.equal(result.descendants.state, 'terminal');
            assert.equal(result.listener.state, 'free');
            assert.deepEqual(result.signalAttempts, [
                { target: 'owned-tree', ownership: 'exact-owned', outcome: 'attempted' },
            ]);
        },
    );

    await t.test('sticky supervisor unknown fails with exact phase', async () => {
        const result = await teardownProbeWebserver(makeChild(), {
            port: 43122,
            expectedStartToken: 'owned-root-token',
            stopSupervisor: () => ({ teardownVerified: false }),
            _killProcessTree: killOwned,
            _probePortListener: freeListener,
            emitFailure: false,
        });
        assert.equal(result.ok, false);
        assert.equal(result.phase, 'supervisor');
        assert.equal(result.reason, 'supervisor-unverified');
    });

    await t.test('listener unknown fails closed without foreign signal', async () => {
        let killCalls = 0;
        const result = await teardownProbeWebserver(makeChild(), {
            port: 43123,
            expectedStartToken: 'owned-root-token',
            _killProcessTree: async (child) => {
                killCalls += 1;
                child.exitCode = 0;
                return true;
            },
            _probePortListener: async () => ({
                state: 'unknown',
                reason: 'TIME_WAIT probe unavailable',
            }),
            emitFailure: false,
        });
        assert.equal(result.ok, false);
        assert.equal(result.phase, 'listener');
        assert.equal(result.reason, 'listener-unknown');
        assert.equal(killCalls, 1);
        assert.deepEqual(result.signalAttempts, [
            { target: 'owned-tree', ownership: 'exact-owned', outcome: 'attempted' },
        ]);
    });

    await t.test('tokenless/foreign failure remains fail-closed and sanitized', async () => {
        let foreignSignal = false;
        const child = makeChild();
        globalOwnedRegistry.register(child.pid, {
            startToken: 'owned-root-token',
            skipStartTokenDiscovery: true,
            skipProcessDiscovery: true,
        });
        globalOwnedRegistry.items.get(child.pid).identityMismatch = true;
        try {
            const result = await teardownProbeWebserver(child, {
                port: 43124,
                expectedStartToken: 'owned-root-token',
                _killProcessTree: async () => {
                    foreignSignal = true;
                    return false;
                },
                _probePortListener: freeListener,
                emitFailure: false,
            });
            assert.equal(result.ok, false);
            assert.equal(result.phase, 'signal');
            assert.equal(foreignSignal, false);
            const serialized = JSON.stringify(result);
            for (const secret of ['start-token', 'command', 'credential', 'nonce', 'environment']) {
                assert.equal(serialized.includes(secret), false, `result must not leak ${secret}`);
            }
        } finally {
            globalOwnedRegistry.clear();
        }
    });
});

test('Windows actual teardown recovery uses one snapshot and never falls back to tasklist after exact-root PID reuse', async () => {
    globalOwnedRegistry.clear();
    const rootPid = 36152;
    const expectedToken = '20260912000000.000000+000';
    const replacementToken = '20260912000000.000000+001';
    const child = { pid: rootPid, exitCode: null };
    let observerCalls = 0;
    let tasklistCalls = 0;
    const startedAt = Date.now();

    globalOwnedRegistry.register(rootPid, {
        startToken: expectedToken,
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });

    try {
        const result = await teardownProbeWebserver(child, {
            _platform: 'win32',
            timeoutMs: 500,
            port: 43126,
            expectedStartToken: expectedToken,
            _killProcessTree: async () => false,
            _observeWindowsProcessTable: () => {
                observerCalls += 1;
                return {
                    state: 'known',
                    reason: null,
                    byPid: new Map([
                        [
                            rootPid,
                            {
                                pid: rootPid,
                                parentPid: 1,
                                startToken: replacementToken,
                                imageName: 'foreign.exe',
                            },
                        ],
                    ]),
                    childrenByParent: new Map(),
                    rows: [],
                };
            },
            _spawnSync: (command) => {
                if (command === 'tasklist') {
                    tasklistCalls += 1;
                    return {
                        error: Object.assign(new Error('spawn ETIMEDOUT'), { code: 'ETIMEDOUT' }),
                    };
                }
                return { status: 1, stdout: '', stderr: '' };
            },
            _probePortListener: async () => ({ state: 'free' }),
            emitFailure: false,
        });

        assert.equal(observerCalls, 1, 'recovery cycle must use one fresh CIM snapshot');
        assert.equal(tasklistCalls, 0, 'recovery must not fall back to tasklist');
        assert.equal(result.ok, true);
        assert.equal(result.root.state, 'dead');
        assert.equal(result.root.identity, 'exact');
        assert.equal(result.descendants.state, 'terminal');
        assert.equal(result.listener.state, 'free');
        assert.ok(Date.now() - startedAt <= 700, 'teardown must remain within bounded timeout');
        assert.equal(globalOwnedRegistry.items.get(rootPid).identityMismatch, false);
    } finally {
        globalOwnedRegistry.clear();
    }
});

test('canonical database is exactly inbils_test', () => {
    assert.equal(resolveCanonicalDatabase({}), 'inbils_test');

    assert.doesNotThrow(() => assertCanonicalDatabaseName('inbils_test'));

    for (const hostile of [
        'inbils',
        'inbils_testing',
        'inbils_e2e',
        'mysql',
        'hermes_qa_t_0bd1699e_',
        '`inbils`; DROP DATABASE inbils',
        '',
        ...legacyDisposableNames,
    ]) {
        assert.throws(
            () => assertCanonicalDatabaseName(hostile),
            undefined,
            `expected refusal: ${hostile}`,
        );
    }
});

test('E2E_DATABASE is refused outright, including legacy disposable names', () => {
    for (const value of ['', ...legacyDisposableNames, 'inbils_test']) {
        if (value === '') {
            // Empty string behaves like an unset variable.
            assert.equal(resolveCanonicalDatabase({ E2E_DATABASE: '' }), 'inbils_test');
            continue;
        }

        assert.throws(
            () => resolveCanonicalDatabase({ E2E_DATABASE: value }),
            /E2E_DATABASE is no longer supported/,
            `expected refusal: ${value}`,
        );
    }
});

test('canonical child env drops polluted parent shell without leaking values', () => {
    const { childEnv, schema, droppedSensitiveNames } = buildCanonicalChildEnv({
        APP_ENV: 'production',
        APP_DEBUG: 'true',
        DB_CONNECTION: 'mysql',
        DB_HOST: '10.9.8.7',
        DB_PORT: '3306',
        DB_DATABASE: 'hostile_shared',
        DB_USERNAME: 'polluted_user',
        DB_PASSWORD: poisonedValue,
        SESSION_DRIVER: 'database',
    });

    assert.equal(childEnv.APP_ENV, 'e2e');
    assert.equal(schema, 'inbils_test');

    // Canonical contract: the disposable E2E_DATABASE mechanism is gone — the
    // child must never receive E2E_DATABASE; only DB_DATABASE is pinned to the
    // canonical schema.
    assert.equal(childEnv.E2E_DATABASE, undefined, 'E2E_DATABASE must not reach the child');
    assert.equal(childEnv.DB_DATABASE, 'inbils_test');

    // APP_DEBUG is intentionally dropped rather than pinned, like every other
    // polluted APP_/DB_/SESSION_ key. DB_DATABASE is the only database key
    // that reaches the child, pinned to the canonical schema.
    for (const key of [
        'APP_DEBUG',
        'DB_HOST',
        'DB_PORT',
        'DB_USERNAME',
        'DB_PASSWORD',
        'SESSION_DRIVER',
    ]) {
        assert.equal(childEnv[key], undefined, `${key} must not reach the child`);
    }

    // Names only: the report may name the dropped keys but must never carry
    // any dropped value.
    const report = droppedSensitiveNames.join(', ');
    assert.ok(droppedSensitiveNames.includes('DB_PASSWORD'));
    assert.ok(!report.includes(poisonedValue));

    const serializedValues = JSON.stringify(Object.values(childEnv));
    assert.ok(!serializedValues.includes(poisonedValue));
    assert.ok(!serializedValues.includes('polluted_user'));
    assert.ok(!serializedValues.includes('10.9.8.7'));
});

test('parseEnvFileCredentials reads .env.e2e-style files without echoing them', () => {
    const dir = mkdtempSync(join(tmpdir(), 'canon-selfcheck-'));
    const filePath = join(dir, '.env.e2e');

    try {
        writeFileSync(
            filePath,
            [
                '# comment',
                'APP_ENV=e2e',
                'DB_HOST=127.0.0.1',
                'DB_PORT=3306',
                'DB_DATABASE=inbils_test',
                'DB_USERNAME="unit_test_user"',
                "DB_PASSWORD='unit_test_pass'",
                '',
            ].join('\n'),
        );

        const credentials = parseEnvFileCredentials(filePath);

        assert.equal(credentials.host, '127.0.0.1');
        assert.equal(credentials.port, '3306');
        assert.equal(credentials.database, 'inbils_test');
        assert.equal(credentials.username, 'unit_test_user');
        assert.equal(credentials.password, 'unit_test_pass');
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }
});

test('canonical DB source mismatch, missing key, duplicate key, and ambiguous host fail closed', () => {
    const dir = mkdtempSync(join(tmpdir(), 'canon-source-negative-'));
    try {
        const cases = [
            [
                'mismatch',
                'DB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=foreign_db\nDB_USERNAME=root\n',
            ],
            ['missing-db', 'DB_HOST=127.0.0.1\nDB_PORT=3306\nDB_USERNAME=root\n'],
            [
                'duplicate-db',
                'DB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=inbils_test\nDB_DATABASE=inbils_test\nDB_USERNAME=root\n',
            ],
            ['missing-host', 'DB_PORT=3306\nDB_DATABASE=inbils_test\nDB_USERNAME=root\n'],
            [
                'duplicate-host',
                'DB_HOST=127.0.0.1\nDB_HOST=localhost\nDB_PORT=3306\nDB_DATABASE=inbils_test\nDB_USERNAME=root\n',
            ],
        ];
        for (const [name, contents] of cases) {
            const path = join(dir, `${name}.env`);
            writeFileSync(path, contents);
            assert.throws(() => parseEnvFileCredentials(path), /Refusing E2E database source/);
        }
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }
});

test('canonical runner owns zero schema lifecycle statements', () => {
    const source = readFileSync(new URL('./e2e-canonical.mjs', import.meta.url), 'utf8');

    // The runner must not create, drop, alter, rename, or truncate any schema
    // or table. Only the read-only existence probe may touch the database.
    for (const ddl of [
        /CREATE\s+DATABASE/i,
        /DROP\s+DATABASE/i,
        /ALTER\s+DATABASE/i,
        /RENAME\s+DATABASE/i,
        /CREATE\s+TABLE/i,
        /DROP\s+TABLE/i,
        /TRUNCATE/i,
    ]) {
        assert.doesNotMatch(source, ddl, `runner must not contain ${ddl}`);
    }

    // The probe must select the canonical schema by name (dbname in the DSN),
    // which fails when the schema does not exist — it never creates it.
    assert.match(source, /dbname=%s/);

    // The exported probe is a connect-only existence check: it selects `1`
    // from the schema and exits non-zero on failure. It never issues DDL.
    const exported = ensureCanonicalSchema.toString();
    assert.match(exported, /SELECT 1/);
    assert.match(exported, /dbname=%s/);
    assert.doesNotMatch(exported, /CREATE|DROP|ALTER|RENAME|TRUNCATE/i);
});

test('canonical main snapshots runtime PID files before Playwright and reconciles only new files after terminal proof', () => {
    const source = readFileSync(new URL('./e2e-canonical.mjs', import.meta.url), 'utf8');
    assert.match(source, /snapshotRuntimePidFiles\(/);
    assert.match(source, /reconcileCurrentRunPidFiles\(runtimePidBaseline\)/);
    assert.match(source, /e2eRuntimeDir/);
    assert.match(source, /safelyReapPidFile/);
});

test('canonical runner uses e2e-mutex for process isolation and lock cleanup', () => {
    const source = readFileSync(new URL('./e2e-canonical.mjs', import.meta.url), 'utf8');
    assert.ok(
        source.includes('acquireCanonicalMutex'),
        'e2e-canonical.mjs must acquire canonical mutex',
    );
    assert.ok(
        source.includes('mutex.release()'),
        'e2e-canonical.mjs must release mutex in finally',
    );
});

test('Finding #3: e2e-canonical registers SIGINT/SIGTERM/SIGHUP signal handlers before acquire for bounded child tree shutdown', () => {
    const source = readFileSync(new URL('./e2e-canonical.mjs', import.meta.url), 'utf8');
    for (const sig of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
        assert.ok(source.includes(sig), `e2e-canonical.mjs must register listener for ${sig}`);
    }
    assert.ok(
        source.includes('killProcessTree') || source.includes('killer('),
        'e2e-canonical.mjs must call process tree killer during signal teardown',
    );
    // Defect exact (1): Signal handlers must be registered before acquire without gap
    const signalIndex = source.indexOf("for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP'])");
    const acquireIndex = source.indexOf('acquirer({');
    assert.ok(
        signalIndex !== -1 && acquireIndex !== -1 && signalIndex < acquireIndex,
        'Signal handlers must be attached before mutex acquisition to eliminate signal gaps',
    );
});

test('canonical validates the exact DB source before mutex or process lifecycle', async () => {
    const source = readFileSync(new URL('./e2e-canonical.mjs', import.meta.url), 'utf8');
    const credentialsIndex = source.indexOf('credentials = credentialReader();');
    const acquireIndex = source.indexOf('mutex = acquirer({');
    assert.ok(credentialsIndex !== -1 && credentialsIndex < acquireIndex);

    let acquireCalls = 0;
    await assert.rejects(
        main({
            skipPreflight: true,
            steps: [],
            runPlaywrightStep: false,
            _parseEnvFileCredentials: () => ({
                host: 'foreign-db.internal',
                port: '3306',
                username: 'foreign_user',
                password: 'not-logged',
                database: 'foreign_db',
            }),
            _acquireCanonicalMutex: () => {
                acquireCalls += 1;
                return { release: () => true };
            },
        }),
        /requires exactly inbils_test|database source/i,
    );
    assert.equal(acquireCalls, 0, 'DB source mismatch must reject before mutex ownership');

    await assert.rejects(
        main({
            sourceEnv: { E2E_DATABASE: 'legacy-target' },
            _parseEnvFileCredentials: () => ({
                host: '127.0.0.1',
                port: '3306',
                username: 'root',
                password: '',
                database: 'inbils_test',
            }),
            _acquireCanonicalMutex: () => {
                acquireCalls += 1;
                return { release: () => true };
            },
        }),
        /E2E_DATABASE is no longer supported/,
    );
    assert.equal(acquireCalls, 0, 'ambiguous parent source must reject before mutex ownership');
});

test('canonical finally clears ownership evidence only after mutex release succeeds', async () => {
    globalOwnedRegistry.clear();
    globalOwnedRegistry.register(424241, {
        parentPid: process.pid,
        startToken: '20260827150259.000000+000',
        skipProcessDiscovery: true,
    });
    let releaseCalls = 0;

    try {
        const code = await main({
            skipPreflight: true,
            steps: [],
            runPlaywrightStep: false,
            _acquireCanonicalMutex: () => ({
                release: () => {
                    releaseCalls += 1;
                    return true;
                },
            }),
            _verifyOwnedRegistryTerminal: async () => ({
                ok: true,
                alivePids: [],
                boundPorts: [],
            }),
        });

        assert.equal(code, 0);
        assert.equal(releaseCalls, 1);
        assert.equal(process.exitCode, undefined);
        assert.equal(globalOwnedRegistry.items.size, 0);
    } finally {
        process.exitCode = undefined;
        globalOwnedRegistry.clear();
    }
});

test('canonical finally treats mutex release false as cleanup failure and retains ownership evidence', async () => {
    globalOwnedRegistry.clear();
    globalOwnedRegistry.register(424242, {
        parentPid: process.pid,
        startToken: '20260827150300.000000+000',
        skipProcessDiscovery: true,
    });
    let releaseCalls = 0;

    try {
        const code = await main({
            skipPreflight: true,
            steps: [],
            runPlaywrightStep: false,
            _acquireCanonicalMutex: () => ({
                release: () => {
                    releaseCalls += 1;
                    return false;
                },
            }),
            _verifyOwnedRegistryTerminal: async () => ({
                ok: true,
                alivePids: [],
                boundPorts: [],
            }),
        });

        assert.equal(code, 0, 'business result remains observable to the caller');
        assert.equal(releaseCalls, 1, 'release must be attempted exactly once');
        assert.equal(process.exitCode, 1, 'release false must convert cleanup to fail-closed exit');
        assert.equal(
            globalOwnedRegistry.items.has(424242),
            true,
            'ownership evidence must survive a failed mutex release',
        );
    } finally {
        process.exitCode = undefined;
        globalOwnedRegistry.clear();
    }
});

test('canonical signal path exits fail-closed when mutex release returns false', async () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'canonical-release-false-signal-'));
    const runnerEntry = join(tempDir, 'runner.mjs');
    const releaseMarker = join(tempDir, 'release-attempted.txt');
    const canonicalModuleUrl = pathToFileURL(join(process.cwd(), 'scripts/e2e-canonical.mjs')).href;

    writeFileSync(
        runnerEntry,
        `
        import { writeFileSync } from 'node:fs';
        import { main } from ${JSON.stringify(canonicalModuleUrl)};

        await main({
            skipPreflight: true,
            steps: [['held-step']],
            _acquireCanonicalMutex: () => ({
                release: () => {
                    writeFileSync(${JSON.stringify(releaseMarker)}, 'attempted');
                    return false;
                },
            }),
            _runChainStep: async () => {
                if (process.send) process.send('ready');
                return new Promise(() => {});
            },
            _verifyOwnedRegistryTerminal: async () => ({
                ok: true,
                alivePids: [],
                boundPorts: [],
            }),
        });
        `,
    );

    try {
        const proc = spawn(process.execPath, [runnerEntry], {
            stdio: ['ignore', 'ignore', 'ignore', 'ipc'],
        });
        proc.once('message', (message) => {
            if (message === 'ready') proc.send('SIGTERM');
        });
        const exitCode = await new Promise((resolve) => proc.once('exit', resolve));

        assert.equal(exitCode, 1, 'release false on SIGTERM must override the nominal 143 exit');
        assert.equal(existsSync(releaseMarker), true, 'signal teardown must attempt mutex release');
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('canonical execution path terminates dummy/owned child tree and proves process exit', async () => {
    // Defect exact (2): execute canonical killProcessTree directly on live child process and prove it dies
    const dummyChild =
        process.platform === 'win32'
            ? spawn(process.execPath, ['-e', 'setInterval(() => {}, 1000);'], {
                  stdio: 'ignore',
                  windowsHide: true,
              })
            : spawnOwnedChild(process.execPath, ['-e', 'setInterval(() => {}, 1000);'], {
                  stdio: 'ignore',
                  windowsHide: true,
                  detached: true,
              });
    const childPid = dummyChild.pid;
    assert.ok(childPid && childPid > 0, 'Spawned dummy child must have valid PID');
    const expectedStartToken =
        process.platform === 'win32' ? getProcessStartToken(childPid) : null;
    if (process.platform === 'win32') {
        assert.ok(expectedStartToken, 'Dummy child must expose a start token before teardown');
    } else {
        assert.equal(expectedStartToken, null, 'POSIX teardown must not request a start token');
    }

    // Prove child is initially alive
    const initialLiveness = probeProcessLiveness(childPid, { timeout: 2000 });
    assert.equal(initialLiveness.state, 'alive', 'Dummy child must be alive prior to teardown');

    // Execute canonical killProcessTree with exact identity proof on Windows
    const killed = await killProcessTree(dummyChild, 5000, {
        expectedStartToken,
    });
    assert.equal(killed, true, 'killProcessTree must report true on bounded termination');

    // Prove child process is dead
    const postLiveness = probeProcessLiveness(childPid, { timeout: 2000 });
    assert.equal(postLiveness.state, 'dead', 'Dummy child must be confirmed dead after teardown');
});

test('POSIX raw spawn remains fail-closed without signal authority', async () => {
    if (process.platform === 'win32') return;

    const rawChild = spawn(process.execPath, ['-e', 'setTimeout(() => process.exit(0), 1000);'], {
        stdio: 'ignore',
        windowsHide: true,
    });
    const signaled = [];
    globalOwnedRegistry.clear();

    try {
        const killed = await killProcessTree(rawChild, 250, {
            _platform: 'linux',
            _getDescendantPids: () => ({ state: 'known-empty', pids: [] }),
            _signal: (...args) => signaled.push(args),
        });

        assert.equal(killed, false, 'raw spawn must remain unowned and fail closed');
        assert.deepEqual(signaled, [], 'raw spawn must not receive a teardown signal');

        if (rawChild.exitCode === null && rawChild.signalCode === null) {
            await new Promise((resolve, reject) => {
                const timer = setTimeout(
                    () => reject(new Error('raw child did not exit naturally after refusal')),
                    2000,
                );
                rawChild.once('exit', () => {
                    clearTimeout(timer);
                    resolve();
                });
            });
        }
        assert.equal(rawChild.exitCode, 0, 'raw child must exit naturally after fail-closed refusal');
    } finally {
        globalOwnedRegistry.clear();
    }
});

test('Windows exact-owned identity mismatch is terminal without probing or signaling the replacement PID', async () => {
    if (process.platform !== 'win32') return;

    globalOwnedRegistry.clear();
    const child = { pid: 9471, exitCode: null };
    const expectedStartToken = '20260912000000.000000+000';
    const sleepSync = (milliseconds) => {
        Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, milliseconds);
    };
    let tasklistCalls = 0;

    globalOwnedRegistry.register(child.pid, {
        startToken: expectedStartToken,
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });

    try {
        const result = await teardownProbeWebserver(child, {
            timeoutMs: 500,
            port: 43971,
            expectedStartToken,
            killOptions: {
                _getDescendantPids: () => ({ state: 'known-empty', pids: [] }),
                _getProcessStartToken: () => expectedStartToken,
                _probeProcessLiveness: () => ({ state: 'alive' }),
                _spawnSync: (command) => {
                    if (command === 'wmic') {
                        // The exact token no longer exists: the owned incarnation
                        // is gone and a current PID, if any, is foreign.
                        sleepSync(100);
                        return {
                            status: 1,
                            stdout: 'Process identity mismatch: WMIC found no matching instance with expected CreationDate token',
                            stderr: '',
                        };
                    }
                    if (command === 'tasklist') {
                        tasklistCalls += 1;
                        sleepSync(450);
                        return { status: 1, stdout: '', stderr: '' };
                    }
                    return { status: 1, stdout: '', stderr: '' };
                },
                _probePortListener: async () => ({ state: 'free' }),
            },
            _probePortListener: async () => ({ state: 'free' }),
            emitFailure: false,
        });

        assert.equal(
            result.ok,
            true,
            'an exact-token identity mismatch must terminalize the owned incarnation',
        );
        assert.equal(result.deadlineExhausted, false);
        assert.equal(result.root.state, 'dead');
        assert.equal(result.listener.state, 'free');
        assert.equal(
            tasklistCalls,
            0,
            'mismatch proof must not probe or signal the replacement PID',
        );
    } finally {
        globalOwnedRegistry.clear();
    }
});

test('Windows identity mismatch classifier is exact and fail-closed across malformed kill results', async () => {
    if (process.platform !== 'win32') return;

    const exactMismatch =
        'Process identity mismatch: WMIC found no matching instance with expected CreationDate token';
    const cases = [
        // Both nonzero statuses used by the WMIC and PowerShell termination
        // paths are accepted only with the complete canonical marker.
        [
            'exact canonical WMIC marker (exit 1)',
            { status: 1, stdout: exactMismatch, stderr: '' },
            true,
        ],
        ['exact canonical marker (exit 2)', { status: 2, stdout: exactMismatch, stderr: '' }, true],
        // Every malformed or ambiguous shape must remain fail-closed.
        [
            'raw WMIC marker is not canonical public proof',
            { status: 1, stdout: 'No Instance(s) Available.', stderr: '' },
            false,
        ],
        ['partial marker', { status: 1, stdout: exactMismatch.slice(0, -1), stderr: '' }, false],
        ['suffix drift', { status: 1, stdout: `${exactMismatch} extra`, stderr: '' }, false],
        ['prefix drift', { status: 1, stdout: `prefix ${exactMismatch}`, stderr: '' }, false],
        ['case drift', { status: 1, stdout: exactMismatch.toLowerCase(), stderr: '' }, false],
        ['stderr marker', { status: 1, stdout: '', stderr: 'No Instance(s) Available.' }, false],
        ['access denied', { status: 1, stdout: 'Access is denied.', stderr: '' }, false],
        ['empty nonzero', { status: 1, stdout: '', stderr: '' }, false],
        [
            'timeout/result.error',
            { status: null, stdout: '', stderr: '', error: { code: 'ETIMEDOUT' } },
            false,
        ],
        ['status 0 with mismatch text', { status: 0, stdout: exactMismatch, stderr: '' }, false],
        ['PowerShell exit 2 with empty output', { status: 2, stdout: '', stderr: '' }, false],
    ];

    for (const [name, killOutput, shouldSucceed] of cases) {
        globalOwnedRegistry.clear();
        const child = { pid: 9480, exitCode: null };
        let livenessCalls = 0;
        let listenerBudget = null;
        globalOwnedRegistry.register(child.pid, {
            startToken: '20260912000001.000000+000',
            skipStartTokenDiscovery: true,
            skipProcessDiscovery: true,
        });

        try {
            const result = await teardownProbeWebserver(child, {
                timeoutMs: 500,
                port: 43980,
                expectedStartToken: '20260912000001.000000+000',
                killOptions: {
                    _getDescendantPids: () => ({ state: 'known-empty', pids: [] }),
                    _getProcessStartToken: () => '20260912000001.000000+000',
                    _probeProcessLiveness: () => {
                        livenessCalls += 1;
                        return { state: 'alive' };
                    },
                    _spawnSync: () => killOutput,
                    _probePortListener: async () => ({ state: 'free' }),
                },
                _probePortListener: async (_port, budget) => {
                    listenerBudget = budget;
                    return { state: 'free' };
                },
                emitFailure: false,
            });

            assert.equal(result.ok, shouldSucceed, `${name} classification`);
            if (shouldSucceed) {
                assert.equal(result.root.state, 'dead');
                assert.equal(result.listener.state, 'free');
                assert.equal(result.deadlineExhausted, false);
                assert.equal(livenessCalls, 0, `${name} must not probe the replacement PID`);
                assert.ok(listenerBudget > 0, `${name} must retain final listener budget`);
            } else {
                assert.equal(result.ok, false);
                assert.ok(livenessCalls >= 1, `${name} must use bounded positive/unknown proof`);
                assert.equal(result.listener.state, 'unknown');
            }
        } finally {
            globalOwnedRegistry.clear();
        }
    }
});

test('Windows root identity mismatch still proves exact-owned descendants before listener terminalization', async () => {
    if (process.platform !== 'win32') return;

    for (const [name, descendantState, expected] of [
        ['descendant dead', 'dead', true],
        ['descendant alive', 'alive', false],
        ['descendant unknown', 'unknown', false],
    ]) {
        globalOwnedRegistry.clear();
        const rootPid = 9490;
        const descendantPid = 9491;
        globalOwnedRegistry.register(rootPid, {
            startToken: '20260912000002.000000+000',
            skipStartTokenDiscovery: true,
            skipProcessDiscovery: true,
        });
        globalOwnedRegistry.register(descendantPid, {
            parentPid: rootPid,
            startToken: '20260912000003.000000+000',
            skipStartTokenDiscovery: true,
            skipProcessDiscovery: true,
        });
        let probed = [];
        let signaled = [];

        try {
            const result = await killProcessTree({ pid: rootPid, exitCode: null }, 500, {
                _platform: 'win32',
                expectedStartToken: '20260912000002.000000+000',
                additionalPids: [descendantPid],
                includeGlobalRegistry: false,
                _getDescendantPids: () => ({ state: 'known-empty', pids: [] }),
                _getProcessStartToken: () => null,
                _probeProcessLiveness: (pid) => {
                    probed.push(pid);
                    return { state: pid === descendantPid ? descendantState : 'alive' };
                },
                _spawnSync: (_command, args) =>
                    args.some((arg) => String(arg).includes('ProcessId=9490'))
                        ? {
                              status: 1,
                              stdout: 'Process identity mismatch: WMIC found no matching instance with expected CreationDate token',
                              stderr: '',
                          }
                        : { status: 0, stdout: 'ReturnValue = 0;', stderr: '' },
                _signal: (pid) => signaled.push(pid),
            });

            assert.equal(result, expected, name);
            assert.deepEqual(
                signaled,
                [],
                'identity mismatch must never signal a numeric replacement',
            );
            assert.ok(
                probed.length >= 1 && probed.every((pid) => pid === descendantPid),
                `${name} must prove descendant, not root`,
            );
        } finally {
            globalOwnedRegistry.clear();
        }
    }
});

test('Windows production lifecycle captures and reaps a late nested listener with immutable identity', async () => {
    if (process.platform !== 'win32') return;

    const tempDir = mkdtempSync(join(tmpdir(), 'canonical-nested-listener-'));
    const listenerScript = join(tempDir, 'listener.mjs');
    const intermediateScript = join(tempDir, 'intermediate.mjs');
    const rootScript = join(tempDir, 'root.mjs');
    const listenerMarker = join(tempDir, 'listener.json');
    const intermediateMarker = join(tempDir, 'intermediate.txt');
    let root = null;
    let supervisor = null;
    let rootToken = null;
    let listener = null;

    writeFileSync(
        listenerScript,
        `
        import { writeFileSync } from 'node:fs';
        import { createServer } from 'node:net';
        const server = createServer();
        server.listen(0, '127.0.0.1', () => {
            writeFileSync(process.argv[2], JSON.stringify({ pid: process.pid, port: server.address().port }));
        });
        setInterval(() => {}, 1000);
        `,
    );
    writeFileSync(
        intermediateScript,
        `
        import { spawn } from 'node:child_process';
        import { writeFileSync } from 'node:fs';
        const child = spawn(process.execPath, [process.argv[2], process.argv[3]], {
            stdio: 'ignore', windowsHide: true,
        });
        writeFileSync(process.argv[4], String(child.pid));
        setInterval(() => {}, 1000);
        `,
    );
    writeFileSync(
        rootScript,
        `
        import { spawn } from 'node:child_process';
        spawn(process.execPath, [process.argv[2], process.argv[3], process.argv[4], process.argv[5]], {
            stdio: 'ignore', windowsHide: true,
        });
        setInterval(() => {}, 1000);
        `,
    );

    try {
        globalOwnedRegistry.clear();
        root = spawn(
            process.execPath,
            [rootScript, intermediateScript, listenerScript, listenerMarker, intermediateMarker],
            { stdio: 'ignore', windowsHide: true },
        );
        rootToken = getProcessStartToken(root.pid);
        assert.ok(rootToken, 'owned root must expose an immutable start token');
        globalOwnedRegistry.register(root.pid, {
            parentPid: process.pid,
            startToken: rootToken,
            skipProcessDiscovery: true,
        });
        supervisor = startOwnedDescendantSupervisor(root.pid, { supervisorIntervalMs: 50 });

        const deadline = Date.now() + 10_000;
        while (!existsSync(listenerMarker) && Date.now() < deadline) {
            await new Promise((resolve) => setTimeout(resolve, 50));
        }
        assert.equal(existsSync(listenerMarker), true, 'nested listener marker must be created');
        listener = JSON.parse(readFileSync(listenerMarker, 'utf8'));

        while (!globalOwnedRegistry.items.get(listener.pid)?.startToken && Date.now() < deadline) {
            await new Promise((resolve) => setTimeout(resolve, 50));
        }
        supervisor.stop();
        supervisor = null;

        const listenerEntry = globalOwnedRegistry.items.get(listener.pid);
        assert.ok(
            listenerEntry?.startToken,
            'late nested listener must be identity-bound pre-teardown',
        );
        assert.equal(listenerEntry.identityMismatch, false);

        const killed = await killProcessTree(root, 10_000, {
            expectedStartToken: rootToken,
            port: listener.port,
        });
        assert.equal(
            killed,
            true,
            'exact-owned root and complete nested listener tree must terminate',
        );
        assert.equal(probeProcessLiveness(listener.pid).state, 'dead');

        const portIsFree = await new Promise((resolve) => {
            const probe = createServer();
            probe.once('error', () => resolve(false));
            probe.listen(listener.port, '127.0.0.1', () => probe.close(() => resolve(true)));
        });
        assert.equal(portIsFree, true, `nested listener port ${listener.port} must be free`);
    } finally {
        supervisor?.stop();
        if (root?.pid && rootToken) {
            await killProcessTree(root, 5000, {
                expectedStartToken: rootToken,
                port: listener?.port,
            });
        }
        globalOwnedRegistry.clear();
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('Finding #6: e2e-canonical explicitly logs honest source-audit read-only vs fixture-mutating banner', () => {
    const source = readFileSync(new URL('./e2e-canonical.mjs', import.meta.url), 'utf8');
    assert.ok(
        source.includes('[SOURCE-AUDIT: READ-ONLY]'),
        'e2e-canonical must log explicit read-only source-audit banner before preflight',
    );
    assert.ok(
        source.includes('[BROWSER LIFECYCLE: FIXTURE-MUTATING MODE]'),
        'e2e-canonical must log explicit fixture mutation banner before Playwright execution',
    );
    // Defect exact (4): the fixture-mutation banner must describe inbils_test as a
    // SHARED, mutex-serialized schema — never as an isolated/disposable schema.
    assert.ok(
        source.includes('shared serialized schema') && source.includes('SHARED inbils_test schema'),
        'fixture mutation banner must lock the shared/serialized wording for inbils_test',
    );
    assert.ok(
        !source.includes('against isolated schema'),
        'fixture mutation banner must NOT describe inbils_test as an isolated schema',
    );

    const mutatingBranchIndex = source.indexOf(
        "if (executionMode === 'playwright-fixture-mutating')",
    );
    const mutationBannerIndex = source.indexOf('[BROWSER LIFECYCLE: FIXTURE-MUTATING MODE]');
    const playwrightRunIndex = source.indexOf("stepRunner(['scripts/e2e-run.mjs'");
    assert.ok(
        mutatingBranchIndex !== -1 &&
            mutationBannerIndex > mutatingBranchIndex &&
            playwrightRunIndex > mutationBannerIndex,
        'fixture mutation banner must be inside the mutating branch immediately before Playwright',
    );
});

test('canonical execution labels custom steps and Playwright discovery without claiming fixture mutation', async () => {
    const cases = [
        {
            name: 'custom steps',
            customSteps: [['temp-only-step.mjs']],
            runPlaywrightStep: false,
            runnerArgs: ['--list'],
            expectedMode: 'custom-steps',
            expectedLabel: '[CUSTOM STEPS: NON-BROWSER]',
        },
        {
            name: 'Playwright discovery',
            customSteps: null,
            runPlaywrightStep: true,
            runnerArgs: ['--list'],
            expectedMode: 'playwright-discovery',
            expectedLabel: '[PLAYWRIGHT DISCOVERY: NON-MUTATING]',
        },
        ...['--help', '-h', '--version', '-V'].map((metadataFlag) => ({
            name: `Playwright metadata ${metadataFlag}`,
            customSteps: null,
            runPlaywrightStep: true,
            runnerArgs: [metadataFlag],
            expectedMode: 'playwright-metadata',
            expectedLabel: '[PLAYWRIGHT METADATA: NON-MUTATING]',
        })),
        {
            name: 'Playwright fixture mutation',
            customSteps: null,
            runPlaywrightStep: true,
            runnerArgs: ['tests/e2e/login.spec.ts'],
            expectedMode: 'playwright-fixture-mutating',
            expectedLabel: '[BROWSER LIFECYCLE: FIXTURE-MUTATING MODE]',
        },
    ];

    for (const item of cases) {
        assert.equal(
            resolveCanonicalExecutionMode({
                customSteps: item.customSteps,
                runPlaywrightStep: item.runPlaywrightStep,
                runnerArgs: item.runnerArgs,
            }),
            item.expectedMode,
        );

        const logs = [];
        const originalLog = console.log;
        console.log = (...args) => logs.push(args.join(' '));
        try {
            const exitCode = await main({
                sourceEnv: {},
                skipPreflight: true,
                lockPath: join(
                    tmpdir(),
                    `canonical-banner-${process.pid}-${item.expectedMode}.lock`,
                ),
                steps: item.customSteps ?? [],
                runPlaywrightStep: item.runPlaywrightStep,
                argv: ['node', 'scripts/e2e-canonical.mjs', ...item.runnerArgs],
                _parseEnvFileCredentials: () => ({
                    host: '127.0.0.1',
                    port: '3306',
                    username: 'unused',
                    password: '',
                    database: 'inbils_test',
                }),
                _runChainStep: async () => 0,
            });
            assert.equal(exitCode, 0, `${item.name} must complete through temp-only injections`);
        } finally {
            console.log = originalLog;
        }

        const output = logs.join('\n');
        assert.match(output, new RegExp(item.expectedLabel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
        if (item.expectedMode !== 'playwright-fixture-mutating') {
            assert.doesNotMatch(output, /\[BROWSER LIFECYCLE: FIXTURE-MUTATING MODE\]/);
        }
    }
});

test('Finding #5: production env can no longer skip canonical preflight (test-only injection only)', () => {
    const source = readFileSync(new URL('./e2e-canonical.mjs', import.meta.url), 'utf8');
    assert.ok(
        !source.includes('E2E_CANONICAL_SKIP_PREFLIGHT'),
        'e2e-canonical must not honor an E2E_CANONICAL_SKIP_PREFLIGHT environment backdoor',
    );
    assert.ok(
        source.includes('const skipPreflight = options.skipPreflight === true;'),
        'skipPreflight must derive exclusively from an explicit test-only option injection',
    );
});

test('canonical runtime PID reconciliation touches only new current-run files and leaves baseline historical files untouched', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'canon-runtime-pid-'));
    const baselineFile = join(tempDir, 'webserver-1111.pid');
    const exactFile = join(tempDir, 'webserver-2222.pid');
    const foreignFile = join(tempDir, 'webserver-3333.pid');
    const malformedFile = join(tempDir, 'webserver-4444.pid');

    try {
        writeFileSync(
            baselineFile,
            JSON.stringify(
                {
                    nonce: 'baseline-nonce',
                    pid: 1111,
                    processStartToken: 'baseline-token',
                    parentPid: 9001,
                    parentStartToken: 'baseline-parent-token',
                    runner: 'scripts/e2e-webserver.mjs',
                    port: 8010,
                    cwd: process.cwd(),
                },
                null,
                2,
            ) + '\n',
        );

        const baseline = snapshotRuntimePidFiles(tempDir);
        assert.equal(baseline.has(baselineFile), true);
        assert.equal(baseline.has(exactFile), false);

        writeFileSync(
            exactFile,
            JSON.stringify(
                {
                    nonce: 'exact-nonce',
                    pid: 2222,
                    processStartToken: 'exact-token',
                    parentPid: 9002,
                    parentStartToken: 'exact-parent-token',
                    runner: 'scripts/e2e-webserver.mjs',
                    port: 8010,
                    cwd: process.cwd(),
                },
                null,
                2,
            ) + '\n',
        );

        const captured = snapshotCurrentRunPidFileContents(baseline, tempDir);
        const exactResult = reconcileCurrentRunPidFiles(baseline, tempDir, captured);
        assert.equal(exactResult.cleanupFailed, false);
        assert.equal(existsSync(exactFile), false);
        assert.equal(existsSync(baselineFile), true);
        assert.ok(exactResult.cleanedFiles.includes(exactFile));

        writeFileSync(
            foreignFile,
            JSON.stringify(
                {
                    nonce: 'foreign-nonce',
                    pid: 3333,
                    processStartToken: 'foreign-token',
                    parentPid: 9003,
                    parentStartToken: 'foreign-parent-token',
                    runner: 'scripts/e2e-webserver.mjs',
                    port: 8010,
                    cwd: process.cwd(),
                },
                null,
                2,
            ) + '\n',
        );
        writeFileSync(malformedFile, 'not-json');

        const currentCaptured = snapshotCurrentRunPidFileContents(baseline, tempDir, captured);
        const foreignResult = reconcileCurrentRunPidFiles(baseline, tempDir, currentCaptured);
        assert.equal(foreignResult.cleanupFailed, true);
        assert.equal(existsSync(foreignFile), true);
        assert.equal(existsSync(malformedFile), true);
        assert.equal(existsSync(baselineFile), true);
        assert.ok(foreignResult.retainedFiles.includes(foreignFile));
        assert.ok(foreignResult.retainedFiles.includes(malformedFile));
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('canonical runner executes real main entry and teardown path cleanly with dummy/owned child termination and bound port', async () => {
    // Execute canonical script entry in separate child process with mock/fast args
    // to prove main() executes, manages mutex, awaits kill, and exits cleanly.
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-canonical-main-'));
    const dummyScript = join(tempDir, 'dummy-step.mjs');
    const portMarker = join(tempDir, 'port.txt');
    const childPidMarker = join(tempDir, 'child_pid.txt');

    const runnerEntry = join(tempDir, 'run-main.mjs');
    const canonicalModuleUrl = pathToFileURL(join(process.cwd(), 'scripts/e2e-canonical.mjs')).href;

    writeFileSync(
        dummyScript,
        `
        import { spawn } from 'node:child_process';
        import { createServer } from 'node:net';
        import { writeFileSync } from 'node:fs';
        import { spawnOwnedChild } from ${JSON.stringify(canonicalModuleUrl)};

        const server = createServer();
        server.listen(0, '127.0.0.1', () => {
            const port = server.address().port;
            writeFileSync(${JSON.stringify(portMarker)}, String(port));
            const child = process.platform === 'win32'
                ? spawn(process.execPath, ['-e', 'setInterval(() => {}, 1000);'], {
                      stdio: 'ignore',
                      windowsHide: true,
                  })
                : spawnOwnedChild(process.execPath, ['-e', 'setInterval(() => {}, 1000);'], {
                      stdio: 'ignore',
                      windowsHide: true,
                  });
            writeFileSync(${JSON.stringify(childPidMarker)}, String(child.pid));
            console.log('[dummy-step] BOUND_PORT ' + port);
            console.log('[dummy-step] CHILD_PID ' + child.pid);
            setTimeout(() => {
                server.close();
                process.exit(0);
            }, 500);
        });
    `,
    );

    const lockPath = join(tempDir, 'canonical-main.lock');

    writeFileSync(
        runnerEntry,
        `
        import { main } from ${JSON.stringify(canonicalModuleUrl)};
        await main({
            skipPreflight: true,
            lockPath: ${JSON.stringify(lockPath)},
            steps: [[${JSON.stringify(dummyScript)}]],
            argv: ['node', 'scripts/e2e-canonical.mjs'],
        });
    `,
    );

    try {
        const child = spawn(process.execPath, [runnerEntry], {
            stdio: ['pipe', 'pipe', 'pipe'],
        });

        let stdout = '';
        let stderr = '';
        child.stdout.on('data', (d) => {
            stdout += d.toString();
        });
        child.stderr.on('data', (d) => {
            stderr += d.toString();
        });

        const exitCode = await new Promise((resolve) => child.once('exit', resolve));
        assert.equal(exitCode, 0, 'Canonical main() must exit with 0');

        // Read written child PID and bound port unconditionally
        assert.ok(existsSync(childPidMarker), 'Child PID marker must exist unconditionally');
        assert.ok(existsSync(portMarker), 'Port marker must exist unconditionally');

        const stepChildPid = parseInt(readFileSync(childPidMarker, 'utf8').trim(), 10);
        const boundPort = parseInt(readFileSync(portMarker, 'utf8').trim(), 10);

        assert.ok(stepChildPid > 0, 'Step child PID must be a valid positive integer');
        assert.ok(boundPort > 0, 'Bound port must be a valid positive integer');

        // Unconditional assertions: PID dead and port freed
        const liveness = probeProcessLiveness(stepChildPid, { timeout: 2000 });
        assert.equal(
            liveness.state,
            'dead',
            `Subprocess step child PID ${stepChildPid} must be terminated and confirmed dead`,
        );

        // Check port is free
        const isPortFree = await new Promise((resolve) => {
            const probe = createServer();
            probe.once('error', () => resolve(false));
            probe.listen(boundPort, '127.0.0.1', () => {
                probe.close(() => resolve(true));
            });
        });
        assert.equal(isPortFree, true, `Bound port ${boundPort} must be free after teardown`);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('executable cleanup matrix: canonical main() cleans up lock on success, failure, exception, SIGINT, SIGTERM, and SIGHUP with real process tree termination, bound-port verification, and replacement lock protection', async () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-cleanup-matrix-'));
    const lockPath = join(tempDir, 'matrix.lock');

    // Helper script that starts an ephemeral server on a dynamic port and spawns a dummy child process
    const stepScript = join(tempDir, 'matrix-step.mjs');
    const portMarker = join(tempDir, 'step-port.txt');
    const pidMarker = join(tempDir, 'step-pid.txt');
    const ranMarker = join(tempDir, 'step-ran.txt');
    const canonicalModuleUrl = pathToFileURL(join(process.cwd(), 'scripts/e2e-canonical.mjs')).href;

    writeFileSync(
        stepScript,
        `
        import { createServer } from 'node:net';
        import { writeFileSync } from 'node:fs';
        import { spawnOwnedChild } from ${JSON.stringify(canonicalModuleUrl)};

        const mode = process.argv[2];
        const portMarker = ${JSON.stringify(portMarker)};
        const pidMarker = ${JSON.stringify(pidMarker)};
        const ranMarker = ${JSON.stringify(ranMarker)};

        const server = createServer();
        server.listen(0, '127.0.0.1', () => {
            const port = server.address().port;
            writeFileSync(portMarker, String(port));

            const child = spawnOwnedChild(process.execPath, ['-e', 'setInterval(() => {}, 1000);'], {
                stdio: 'ignore',
                windowsHide: true,
            });
            writeFileSync(pidMarker, String(child.pid));
            writeFileSync(ranMarker, mode);

            console.log('[matrix-step] READY port=' + port + ' pid=' + child.pid + ' mode=' + mode);

            if (mode === 'success') {
                setTimeout(() => {
                    server.close();
                    process.exit(0);
                }, 100);
            } else if (mode === 'failure') {
                setTimeout(() => {
                    server.close();
                    process.exit(42);
                }, 100);
            } else if (mode === 'exception') {
                setTimeout(() => {
                    server.close();
                    throw new Error('matrix-step-exception');
                }, 100);
            } else {
                // Signal modes: remain active so signal teardown handles cleanup
            }
        });
    `,
    );

    const checkChildAndPortDead = async (pid, port) => {
        assert.ok(pid > 0, 'Step child PID must be a valid positive integer');
        assert.ok(port > 0, 'Bound port must be a valid positive integer');

        // Unconditional assertion: PID is dead
        const liveness = probeProcessLiveness(pid, { timeout: 2000 });
        assert.equal(
            liveness.state,
            'dead',
            `Child process PID ${pid} must be terminated and confirmed dead`,
        );

        // Unconditional assertion: Port is free
        const isFree = await new Promise((resolve) => {
            const probe = createServer();
            probe.once('error', () => resolve(false));
            probe.listen(port, '127.0.0.1', () => {
                probe.close(() => resolve(true));
            });
        });
        assert.equal(isFree, true, `Bound port ${port} must be free after teardown`);
    };

    try {
        // Helper to invoke real canonical runner via child process that runs canonical main()
        const runRunnerMode = async (mode, options = {}) => {
            if (existsSync(portMarker)) rmSync(portMarker, { force: true });
            if (existsSync(pidMarker)) rmSync(pidMarker, { force: true });
            if (existsSync(ranMarker)) rmSync(ranMarker, { force: true });

            const runnerEntry = join(tempDir, `runner-${mode}.mjs`);
            const canonicalModuleUrl = pathToFileURL(
                join(process.cwd(), 'scripts/e2e-canonical.mjs'),
            ).href;

            writeFileSync(
                runnerEntry,
                `
                import { main } from ${JSON.stringify(canonicalModuleUrl)};
                const mode = ${JSON.stringify(mode)};
                const lockPath = ${JSON.stringify(lockPath)};
                const stepScript = ${JSON.stringify(stepScript)};

                await main({
                    skipPreflight: true,
                    lockPath,
                    steps: [[stepScript, mode]],
                    argv: ['node', 'scripts/e2e-canonical.mjs', '--list'],
                });
            `,
            );

            let stdout = '';
            let stderr = '';
            const proc = spawn(process.execPath, [runnerEntry], {
                stdio: ['pipe', 'pipe', 'pipe', 'ipc'],
            });

            let signaled = false;
            proc.stdout.on('data', (d) => {
                stdout += d.toString();
                if (
                    ['SIGINT', 'SIGTERM', 'SIGHUP'].includes(mode) &&
                    !signaled &&
                    stdout.includes('[matrix-step] READY')
                ) {
                    signaled = true;
                    if (options.injectReplacementLock) {
                        writeFileSync(
                            lockPath,
                            JSON.stringify(
                                {
                                    nonce: 'matrix-replacement-nonce-888',
                                    ownerPid: 88888,
                                    processStartToken: '2026-08-27-replacement-token',
                                    schema: 'inbils_test',
                                    runner: 'scripts/e2e-canonical.mjs',
                                    acquiredAt: new Date().toISOString(),
                                    nodeVersion: process.version,
                                },
                                null,
                                2,
                            ),
                        );
                    }
                    proc.send(mode);
                    if (options.repeatedSignal) {
                        setTimeout(() => {
                            try {
                                proc.send(mode);
                            } catch {}
                        }, 50);
                    }
                }
            });
            proc.stderr.on('data', (d) => {
                stderr += d.toString();
            });

            const exitCode = await new Promise((resolve) => proc.once('exit', resolve));
            return { exitCode, stdout, stderr };
        };

        // 1. Success mode: real main() execution
        {
            const { exitCode } = await runRunnerMode('success');
            assert.equal(exitCode, 0, 'Success mode must exit with code 0');
            assert.equal(
                existsSync(ranMarker),
                true,
                'Step marker must be created in success mode',
            );
            assert.equal(
                existsSync(lockPath),
                false,
                'Lock must be released on success in real main()',
            );
            const pid = parseInt(readFileSync(pidMarker, 'utf8').trim(), 10);
            const port = parseInt(readFileSync(portMarker, 'utf8').trim(), 10);
            await checkChildAndPortDead(pid, port);
        }

        // 2. Failure mode: real main() execution
        {
            const { exitCode } = await runRunnerMode('failure');
            assert.equal(exitCode, 42, 'Failure mode must exit with code 42');
            assert.equal(
                existsSync(ranMarker),
                true,
                'Step marker must be created in failure mode',
            );
            assert.equal(
                existsSync(lockPath),
                false,
                'Lock must be released on failure exit in real main()',
            );
            const pid = parseInt(readFileSync(pidMarker, 'utf8').trim(), 10);
            const port = parseInt(readFileSync(portMarker, 'utf8').trim(), 10);
            await checkChildAndPortDead(pid, port);
        }

        // 3. Exception mode: real main() execution
        {
            const { exitCode } = await runRunnerMode('exception');
            assert.equal(exitCode, 1, 'Exception mode must exit with non-zero code 1');
            assert.equal(
                existsSync(ranMarker),
                true,
                'Step marker must be created in exception mode',
            );
            assert.equal(
                existsSync(lockPath),
                false,
                'Lock must be released on exception in real main()',
            );
            const pid = parseInt(readFileSync(pidMarker, 'utf8').trim(), 10);
            const port = parseInt(readFileSync(portMarker, 'utf8').trim(), 10);
            await checkChildAndPortDead(pid, port);
        }

        // 4. Signal modes: SIGINT, SIGTERM, SIGHUP against real main() with repeated signal and signal-during-finally safety
        for (const sig of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
            const { exitCode, stdout } = await runRunnerMode(sig, { repeatedSignal: true });
            const expectedExit = sig === 'SIGINT' ? 130 : sig === 'SIGTERM' ? 143 : 129;
            assert.equal(
                exitCode,
                expectedExit,
                `Expected exit code ${expectedExit} for signal ${sig}, got ${exitCode}; stdout=${stdout}`,
            );
            assert.equal(existsSync(ranMarker), true, `Step marker must be created in ${sig} mode`);
            assert.equal(
                existsSync(lockPath),
                false,
                `Lock must be released upon signal ${sig} teardown in real main()`,
            );
            const pid = parseInt(readFileSync(pidMarker, 'utf8').trim(), 10);
            const port = parseInt(readFileSync(portMarker, 'utf8').trim(), 10);
            await checkChildAndPortDead(pid, port);
        }

        // 5. Replacement Lock Protection on Signal Teardown with real main()
        {
            const { exitCode } = await runRunnerMode('SIGINT', { injectReplacementLock: true });
            assert.equal(
                exitCode,
                1,
                'Replacement ownership must make mutex release fail closed instead of reporting nominal SIGINT success',
            );
            assert.ok(
                existsSync(lockPath),
                'Replacement lock must survive conditional release in real main()',
            );

            const pid = parseInt(readFileSync(pidMarker, 'utf8').trim(), 10);
            const port = parseInt(readFileSync(portMarker, 'utf8').trim(), 10);
            await checkChildAndPortDead(pid, port);

            const payloadRaw = readFileSync(lockPath, 'utf8');
            const parsedPayload = JSON.parse(payloadRaw);
            assert.equal(
                parsedPayload.nonce,
                'matrix-replacement-nonce-888',
                'Replacement lock payload must remain completely intact',
            );
        }
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('teardown failure/unknown must hold mutex and fail-closed preventing premature lock release in canonical main()', async () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-teardown-failclosed-'));
    const lockPath = join(tempDir, 'failclosed.lock');
    const runnerEntry = join(tempDir, 'runner-failclosed.mjs');
    const canonicalModuleUrl = pathToFileURL(join(process.cwd(), 'scripts/e2e-canonical.mjs')).href;

    writeFileSync(
        runnerEntry,
        `
        import { main } from ${JSON.stringify(canonicalModuleUrl)};
        const lockPath = ${JSON.stringify(lockPath)};

        // Real main() invoked with injected killer that fails teardown
        await main({
            skipPreflight: true,
            lockPath,
            steps: [['node', '-e', 'process.exit(0);']],
            _killProcessTree: async () => false, // Injected teardown failure
        });
    `,
    );

    try {
        const proc = spawn(process.execPath, [runnerEntry], {
            stdio: ['pipe', 'pipe', 'pipe'],
        });
        const exitCode = await new Promise((resolve) => proc.once('exit', resolve));
        assert.equal(exitCode, 1, 'Process must exit with code 1 on failed teardown');

        // Mutex lock file MUST STILL EXIST because lock.release() was withheld due to failed teardown!
        assert.equal(
            existsSync(lockPath),
            true,
            'Lock must remain on disk when teardown is unverified or fails in real main()',
        );
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('platform signal capabilities: explicit fail-closed and supported runtime dispatch', () => {
    // Windows runtime platform contract:
    // On Windows, process.kill(pid, 'SIGHUP') throws ENOSYS because Windows lacks POSIX signals.
    if (process.platform === 'win32') {
        assert.throws(
            () => {
                process.kill(process.pid, 'SIGHUP');
            },
            /ENOSYS/,
            'Windows kernel must reject POSIX kill with ENOSYS',
        );
    }

    // In-process and IPC signal dispatch works deterministically on all platforms
    let sigintHandled = false;
    const testHandler = () => {
        sigintHandled = true;
    };
    process.once('SIGINT', testHandler);
    process.emit('SIGINT');
    assert.equal(
        sigintHandled,
        true,
        'process.emit(SIGINT) must invoke registered signal listener',
    );
});

test('canonical runner: main() accepts options and respects custom steps / argv / skipPreflight', async () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-canonical-opts-'));
    const lockPath = join(tempDir, 'canonical-opts.lock');
    const markerPath = join(tempDir, 'step-ran.txt');
    const dummyStepPath = join(tempDir, 'dummy-step.mjs');
    writeFileSync(
        dummyStepPath,
        `
        import { writeFileSync } from 'node:fs';
        writeFileSync(${JSON.stringify(markerPath)}, 'step-ok');
        process.exit(0);
    `,
    );

    try {
        const exitCode = await main({
            skipPreflight: true,
            lockPath,
            steps: [[dummyStepPath]],
            argv: ['node', 'scripts/e2e-canonical.mjs', '--list'],
        });
        assert.equal(exitCode, 0, 'main() must complete with 0 when step passes');
        assert.equal(existsSync(markerPath), true, 'Custom step must have executed');
        assert.equal(existsSync(lockPath), false, 'Lock must be released upon clean exit');
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('POSIX production main probe owns a detached process group and tears it down exactly', async () => {
    if (process.platform === 'win32') return;

    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-canonical-probe-detached-'));
    const fakePhp = join(tempDir, 'fake-php.mjs');
    const lockPath = join(tempDir, 'probe-detached.lock');
    const signalCalls = [];

    writeFileSync(
        fakePhp,
        `#!/usr/bin/env node
import { createServer } from 'node:http';

if (process.argv.includes('-r')) process.exit(0);

const port = Number(process.argv.find((arg) => arg.startsWith('--port='))?.slice(7));
const server = createServer((request, response) => {
    if (request.method === 'GET') {
        response.writeHead(200, { 'Set-Cookie': 'XSRF-TOKEN=probe' });
    } else {
        response.writeHead(302, { Location: '/login' });
    }
    response.end();
});
server.listen(port, '127.0.0.1');
`,
        { mode: 0o755 },
    );

    const previousPhpBinary = process.env.PHP_BINARY;
    process.env.PHP_BINARY = fakePhp;

    try {
        const code = await main({
            lockPath,
            steps: [],
            runPlaywrightStep: false,
            phpBinary: fakePhp,
            _parseEnvFileCredentials: () => ({
                host: '127.0.0.1',
                port: '3306',
                username: 'selfcheck',
                password: '',
                database: 'inbils_test',
            }),
            _teardownProbeWebserver: async (child, options) => {
                const killed = await killProcessTree(child, 5000, {
                    port: options.port,
                    _signal: (pid, signal) => {
                        signalCalls.push([pid, signal]);
                        process.kill(pid, signal);
                    },
                });
                return {
                    ok: killed,
                    phase: killed ? 'terminal' : 'signal',
                    reason: killed ? 'terminal-proof-complete' : 'bounded-killer-failed',
                };
            },
        });

        assert.equal(code, 0);
        assert.ok(
            signalCalls.some(([pid, signal]) => pid < 0 && signal === 'SIGTERM'),
            'production POSIX probe teardown must signal the exact detached process group',
        );
        assert.equal(existsSync(lockPath), false, 'probe teardown must release the mutex');
    } finally {
        if (previousPhpBinary === undefined) delete process.env.PHP_BINARY;
        else process.env.PHP_BINARY = previousPhpBinary;
        globalOwnedRegistry.clear();
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('canonical runner: signal handling is idempotent and handlers remain attached', () => {
    const source = readFileSync(new URL('./e2e-canonical.mjs', import.meta.url), 'utf8');
    assert.ok(
        source.includes('process.on(signal, handler)') ||
            source.includes("process.on('message', messageHandler)"),
        'e2e-canonical must register signal/message listeners without premature one-off removal',
    );
    assert.ok(
        source.includes('if (isShuttingDown) return;'),
        'e2e-canonical must gate shutdown with isShuttingDown check for idempotency',
    );
});

test('canonical teardown never re-discoveries fresh identity during registry re-register', () => {
    const source = readFileSync(new URL('./e2e-canonical.mjs', import.meta.url), 'utf8');
    assert.ok(
        source.includes('skipStartTokenDiscovery: true'),
        'e2e-canonical must avoid fresh start-token discovery during teardown registry updates',
    );
    assert.ok(
        source.includes('skipProcessDiscovery: true'),
        'e2e-canonical must avoid fresh process discovery during teardown registry updates',
    );
    assert.ok(
        source.includes('getProcessStartToken(child.pid)'),
        'e2e-canonical must bind descendant start-token before registry re-register during teardown',
    );
    assert.ok(
        source.includes('getProcessStartToken(probeServer.pid, childEnv)'),
        'e2e-canonical must bind probe-server start-token before registry re-register during teardown',
    );
});

// ---------------------------------------------------------------------------
// Executable coverage for the late-spawned owned descendant identity contract.
//
// These checks call bindOwnedDescendantIdentities / startOwnedDescendantSupervisor
// / verifyOwnedRegistryTerminal directly with injected fakes: no real database,
// no browser, no server, no process is spawned or killed here. The fakes model
// the exact production hazard: `php artisan serve` (owned root) spawning its
// `php -S` listener AFTER registration, plus PID reuse by a foreign process.
// ---------------------------------------------------------------------------

const makeOwnedRoot = (registry, rootPid, startToken) => {
    registry.register(rootPid, {
        parentPid: process.pid,
        startToken,
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });

    return registry.items.get(rootPid);
};

test('late-spawned owned descendant receives an immutable start-token before teardown', () => {
    const registry = new OwnedProcessRegistry();
    makeOwnedRoot(registry, 1000, 'ROOT-TOKEN');

    // Pass 1 sees only the root's first child; the real listener is spawned
    // afterwards and only becomes visible on pass 2 (late-spawned descendant).
    const trees = [[2000], [2000, 3000]];
    let treeIndex = 0;

    const options = {
        _registry: registry,
        _platform: 'win32',
        _getDescendantPids: () => trees[Math.min(treeIndex, trees.length - 1)],
        _getProcessStartToken: (pid) =>
            ({ 1000: 'ROOT-TOKEN', 2000: 'CHILD-2000', 3000: 'CHILD-3000' })[pid] ?? null,
    };

    const first = bindOwnedDescendantIdentities(1000, options);
    assert.deepEqual(first.bound, [2000], 'first pass must bind the already visible descendant');
    assert.deepEqual(first.refusedForeign, []);
    assert.deepEqual(first.unknownIdentity, []);
    assert.equal(registry.items.get(2000).startToken, 'CHILD-2000');
    assert.equal(registry.items.get(2000).parentPid, 1000);
    assert.equal(registry.items.has(3000), false, 'late-spawned PID must not exist yet');

    treeIndex = 1;
    const second = bindOwnedDescendantIdentities(1000, options);
    assert.deepEqual(second.bound, [3000], 'late-spawned descendant must be bound on a later pass');
    assert.deepEqual(second.alreadyBound, [2000], 'already bound descendant must not be re-bound');
    assert.equal(
        registry.items.get(3000).startToken,
        'CHILD-3000',
        'late-spawned descendant must carry an immutable start-token before teardown',
    );
    assert.equal(registry.items.get(3000).identityMismatch, false);
});

test('foreign replacement of an owned descendant PID is refused, never re-bound, never adopted', () => {
    const registry = new OwnedProcessRegistry();
    makeOwnedRoot(registry, 1000, 'ROOT-TOKEN');

    let descendantToken = 'CHILD-ORIGINAL';
    const options = {
        _registry: registry,
        _platform: 'win32',
        _getDescendantPids: () => [2000],
        _getProcessStartToken: (pid) =>
            pid === 1000 ? 'ROOT-TOKEN' : pid === 2000 ? descendantToken : null,
    };

    const bind = bindOwnedDescendantIdentities(1000, options);
    assert.deepEqual(bind.bound, [2000]);
    assert.equal(registry.items.get(2000).startToken, 'CHILD-ORIGINAL');

    // The owned descendant exits and a foreign process reuses its PID.
    descendantToken = 'FOREIGN-REPLACEMENT';
    const refresh = bindOwnedDescendantIdentities(1000, options);

    assert.deepEqual(refresh.refusedForeign, [2000], 'reused PID must be refused as foreign');
    assert.deepEqual(refresh.bound, [], 'foreign PID must never be adopted');
    assert.deepEqual(refresh.alreadyBound, []);
    assert.equal(
        registry.items.get(2000).identityMismatch,
        true,
        'foreign/reused PID must be flagged so teardown never kills it',
    );
    assert.equal(
        registry.items.get(2000).startToken,
        'CHILD-ORIGINAL',
        'bound start-token is immutable: a foreign token must never overwrite it',
    );

    // The refusal is sticky across re-registration attempts.
    registry.register(2000, {
        parentPid: 1000,
        startToken: 'FOREIGN-REPLACEMENT',
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });
    assert.equal(
        registry.items.get(2000).identityMismatch,
        true,
        're-registering a proven foreign PID must not clear the refusal flag',
    );

    const again = bindOwnedDescendantIdentities(1000, options);
    assert.deepEqual(again.refusedForeign, [2000]);
    assert.deepEqual(again.bound, []);
});

test('owned root whose PID was reused adopts nothing and is refused fail-closed', () => {
    const registry = new OwnedProcessRegistry();
    makeOwnedRoot(registry, 1000, 'ROOT-TOKEN');

    const result = bindOwnedDescendantIdentities(1000, {
        _registry: registry,
        _platform: 'win32',
        _getDescendantPids: () => {
            throw new Error('descendants must not be enumerated from a reused root PID');
        },
        _getProcessStartToken: () => 'FOREIGN-ROOT-TOKEN',
    });

    assert.equal(result.reason, 'root-identity-mismatch');
    assert.deepEqual(result.refusedForeign, [1000]);
    assert.deepEqual(result.bound, []);
    assert.equal(registry.items.get(1000).identityMismatch, true);
});

test('unknown / unowned / unbound identities are never promoted to ownership', () => {
    const registry = new OwnedProcessRegistry();

    // Root not owned at all.
    assert.equal(
        bindOwnedDescendantIdentities(4242, { _registry: registry, _platform: 'win32' }).reason,
        'root-not-owned',
    );

    // Root owned but token-less: teardown may not discover it now.
    registry.register(5000, {
        parentPid: process.pid,
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });
    assert.equal(
        bindOwnedDescendantIdentities(5000, {
            _registry: registry,
            _platform: 'win32',
            _getDescendantPids: () => [5001],
            _getProcessStartToken: () => 'ANY',
        }).reason,
        'root-identity-unbound',
    );
    assert.equal(registry.items.has(5001), false);

    // Invalid PID shapes are refused without touching the registry.
    for (const badPid of [0, -1, 1.5, null, undefined, '1000']) {
        assert.equal(
            bindOwnedDescendantIdentities(badPid, { _registry: registry, _platform: 'win32' })
                .reason,
            'invalid-root-pid',
        );
    }

    // Root alive and matching, but a descendant identity cannot be read.
    makeOwnedRoot(registry, 6000, 'ROOT-6000');
    const unknown = bindOwnedDescendantIdentities(6000, {
        _registry: registry,
        _platform: 'win32',
        _getDescendantPids: () => [6001],
        _getProcessStartToken: (pid) => (pid === 6000 ? 'ROOT-6000' : null),
    });
    assert.deepEqual(unknown.unknownIdentity, [6001]);
    assert.deepEqual(unknown.bound, []);
    assert.equal(
        registry.items.has(6001),
        false,
        'a descendant with unreadable identity must not be adopted',
    );

    // Root identity unreadable: the whole tree stays unowned.
    const unverifiable = bindOwnedDescendantIdentities(6000, {
        _registry: registry,
        _platform: 'win32',
        _getDescendantPids: () => [6002],
        _getProcessStartToken: () => null,
    });
    assert.equal(unverifiable.reason, 'root-identity-unverifiable');
    assert.equal(registry.items.has(6002), false);
});

test('structured descendant discovery unknown is fail-closed while proven positive and empty stay verified', () => {
    const unknownReasons = [
        'wmic-error: injected',
        'wmic-nonzero-exit: 1',
        'empty-wmic-output',
        'malformed-wmic-header',
    ];

    for (const reason of unknownReasons) {
        const registry = new OwnedProcessRegistry();
        makeOwnedRoot(registry, 6100, 'ROOT-6100');
        const binding = bindOwnedDescendantIdentities(6100, {
            _registry: registry,
            _platform: 'win32',
            _getDescendantPids: () => ({ state: 'unknown', pids: [], reason }),
            _getProcessStartToken: () => 'ROOT-6100',
        });

        assert.equal(binding.teardownVerified, false, `${reason} must remain unverified`);
        assert.equal(binding.discoveryState, 'unknown');
        assert.equal(binding.reason, `descendant-discovery-unknown: ${reason}`);
        assert.deepEqual(binding.bound, []);

        const supervisor = startOwnedDescendantSupervisor(6100, {
            supervisorIntervalMs: 10_000,
            _bindOwnedDescendantIdentities: () => binding,
        });
        supervisor.stop();
        assert.equal(
            supervisor.teardownVerified,
            false,
            `${reason} must remain sticky-unverified through supervisor stop`,
        );
    }

    const thrownRegistry = new OwnedProcessRegistry();
    makeOwnedRoot(thrownRegistry, 6150, 'ROOT-6150');
    const thrown = bindOwnedDescendantIdentities(6150, {
        _registry: thrownRegistry,
        _platform: 'win32',
        _getDescendantPids: () => {
            throw new Error('wmic injected failure');
        },
        _getProcessStartToken: () => 'ROOT-6150',
    });
    assert.equal(thrown.teardownVerified, false);
    assert.equal(thrown.discoveryState, 'unknown');
    assert.match(thrown.reason, /discovery-error: wmic injected failure/);

    const positiveRegistry = new OwnedProcessRegistry();
    makeOwnedRoot(positiveRegistry, 6200, 'ROOT-6200');
    const positive = bindOwnedDescendantIdentities(6200, {
        _registry: positiveRegistry,
        _platform: 'win32',
        _getDescendantPids: () => ({ state: 'known', pids: [6201] }),
        _getProcessStartToken: (pid) => (pid === 6200 ? 'ROOT-6200' : 'CHILD-6201'),
    });
    assert.equal(positive.teardownVerified, true);
    assert.equal(positive.discoveryState, 'known');
    assert.deepEqual(positive.bound, [6201]);

    const okRegistry = new OwnedProcessRegistry();
    makeOwnedRoot(okRegistry, 6250, 'ROOT-6250');
    const okPositive = bindOwnedDescendantIdentities(6250, {
        _registry: okRegistry,
        _platform: 'win32',
        _getDescendantPids: () => ({ state: 'ok', pids: [6251] }),
        _getProcessStartToken: (pid) => (pid === 6250 ? 'ROOT-6250' : 'CHILD-6251'),
    });
    assert.equal(okPositive.teardownVerified, true);
    assert.equal(okPositive.discoveryState, 'ok');
    assert.deepEqual(okPositive.bound, [6251]);

    const emptyRegistry = new OwnedProcessRegistry();
    makeOwnedRoot(emptyRegistry, 6300, 'ROOT-6300');
    const empty = bindOwnedDescendantIdentities(6300, {
        _registry: emptyRegistry,
        _platform: 'win32',
        _getDescendantPids: () => ({ state: 'known-empty', pids: [] }),
        _getProcessStartToken: () => 'ROOT-6300',
    });
    assert.equal(empty.teardownVerified, true);
    assert.equal(empty.discoveryState, 'known-empty');
    assert.deepEqual(empty.bound, []);

    const okEmptyRegistry = new OwnedProcessRegistry();
    makeOwnedRoot(okEmptyRegistry, 6350, 'ROOT-6350');
    const okEmpty = bindOwnedDescendantIdentities(6350, {
        _registry: okEmptyRegistry,
        _platform: 'win32',
        _getDescendantPids: () => ({ state: 'ok', pids: [] }),
        _getProcessStartToken: () => 'ROOT-6350',
    });
    assert.equal(okEmpty.teardownVerified, true);
    assert.equal(okEmpty.discoveryState, 'ok');
    assert.deepEqual(okEmpty.bound, []);
});

test('killProcessTree returns false when descendant discovery is unknown and canonical main withholds mutex release', async () => {
    const registry = globalOwnedRegistry;
    registry.clear();
    registry.register(6400, {
        parentPid: process.pid,
        startToken: 'ROOT-6400',
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });

    try {
        const killed = await killProcessTree({ pid: 6400, exitCode: 0 }, 120, {
            _platform: 'win32',
            expectedStartToken: 'ROOT-6400',
            _getDescendantPids: () => ({
                state: 'unknown',
                pids: [],
                reason: 'wmic-nonzero-exit: 1',
            }),
            _getProcessStartToken: () => 'ROOT-6400',
            _probeProcessLiveness: () => ({ state: 'dead' }),
        });
        assert.equal(killed, false, 'unknown discovery must make killProcessTree fail closed');
    } finally {
        registry.clear();
    }

    let releaseCalls = 0;
    process.exitCode = undefined;
    globalOwnedRegistry.clear();

    try {
        const code = await main({
            skipPreflight: true,
            steps: [['-e', 'process.exit(0);']],
            _acquireCanonicalMutex: () => ({
                updateOwnedMetadata: () => {},
                release: () => {
                    releaseCalls += 1;
                    return true;
                },
            }),
            _killProcessTree: async (child) => {
                const expectedStartToken = globalOwnedRegistry.items.get(child.pid)?.startToken;
                return killProcessTree({ pid: child.pid, exitCode: 0 }, 120, {
                    _platform: 'win32',
                    expectedStartToken,
                    _getDescendantPids: () => ({
                        state: 'unknown',
                        pids: [],
                        reason: 'malformed-wmic-header',
                    }),
                    _getProcessStartToken: () => expectedStartToken,
                    _probeProcessLiveness: () => ({ state: 'dead' }),
                });
            },
        });

        assert.equal(code, 0, 'business result remains observable to the caller');
        assert.equal(
            process.exitCode,
            1,
            'main() must fail closed on unknown descendant discovery',
        );
        assert.equal(
            releaseCalls,
            0,
            'mutex.release() must not be called while discovery is unknown',
        );
    } finally {
        process.exitCode = undefined;
        globalOwnedRegistry.clear();
    }
});

test('linkage-only platform adopts descendants only from a root proven alive, without inventing tokens', () => {
    const registry = new OwnedProcessRegistry();
    makeOwnedRoot(registry, 7000, 'ROOT-7000');

    const adopted = bindOwnedDescendantIdentities(7000, {
        _registry: registry,
        _platform: 'linux',
        _probeProcessLiveness: () => ({ state: 'alive' }),
        _getDescendantPids: () => [7001],
        _getProcessStartToken: () => {
            throw new Error('POSIX path must not read start tokens');
        },
    });
    assert.equal(adopted.reason, 'linkage-only-platform');
    assert.deepEqual(adopted.bound, [7001]);
    assert.equal(registry.items.get(7001).parentPid, 7000);
    assert.equal(
        registry.items.get(7001).startToken,
        null,
        'POSIX adoption must leave startToken null so identity-safe kill still refuses it',
    );

    // Root not proven alive: no adoption at all.
    const refused = bindOwnedDescendantIdentities(7000, {
        _registry: registry,
        _platform: 'linux',
        _probeProcessLiveness: () => ({ state: 'unknown' }),
        _getDescendantPids: () => [7002],
    });
    assert.equal(refused.reason, 'root-identity-unverifiable');
    assert.equal(registry.items.has(7002), false);
});

test('POSIX forged child handle cannot mint spawn-owned process group capability', async () => {
    if (process.platform === 'win32') return;

    const rootPid = 7099;
    const child = { pid: rootPid, exitCode: null, kill() {} };
    const signaled = [];
    globalOwnedRegistry.clear();
    globalOwnedRegistry.register(rootPid, {
        parentPid: process.pid,
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });

    try {
        const result = await killProcessTree(child, 250, {
            _platform: 'linux',
            _spawnOwnedRoot: child,
            _getDescendantPids: () => ({ state: 'known-empty', pids: [] }),
            _probeProcessLiveness: () =>
                signaled.length === 0 ? ({ state: 'alive' }) : ({ state: 'dead' }),
            _signal: (...args) => signaled.push(args),
        });

        assert.deepEqual(signaled, [], 'forged handle must never receive signal authority');
        assert.equal(result, false, 'forged live handle must fail closed');
    } finally {
        globalOwnedRegistry.clear();
    }
});

test('POSIX root token null still tears down only the spawn-owned root', async () => {
    if (process.platform === 'win32') return;

    const child = spawnOwnedChild(process.execPath, ['-e', 'setInterval(() => {}, 1000);'], {
        stdio: 'ignore',
        detached: true,
    });
    const rootPid = child.pid;
    const signaled = [];
    globalOwnedRegistry.clear();
    globalOwnedRegistry.register(rootPid, {
        parentPid: process.pid,
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });

    try {
        const result = await teardownProbeWebserver(child, {
            _platform: 'linux',
            expectedStartToken: null,
            timeoutMs: 500,
            _killProcessTree: async (ownedChild) => {
                signaled.push(ownedChild.pid);
                if (ownedChild.exitCode === null) {
                    try {
                        ownedChild.kill('SIGKILL');
                    } catch {}
                    await Promise.race([
                        new Promise((resolve) => ownedChild.once('exit', resolve)),
                        new Promise((resolve) => setTimeout(resolve, 250)),
                    ]);
                }
                return ownedChild.exitCode !== null || ownedChild.signalCode !== null;
            },
            _probePortListener: async () => ({ state: 'free' }),
            emitFailure: false,
        });

        assert.equal(result.ok, true, 'POSIX spawn-owned root may tear down without token');
        assert.deepEqual(signaled, [rootPid]);
    } finally {
        if (child.exitCode === null && child.signalCode === null) {
            try {
                process.kill(-rootPid, 'SIGKILL');
            } catch {}
            await Promise.race([
                new Promise((resolve) => child.once('exit', resolve)),
                new Promise((resolve) => setTimeout(resolve, 250)),
            ]);
        }
        globalOwnedRegistry.clear();
    }
});

test('POSIX foreign, reused, and unverified PIDs are never signaled', async () => {
    if (process.platform === 'win32') return;

    const rootPid = 7200;
    const foreignPid = 7201;
    const historicalPid = 7202;
    const child = { pid: rootPid, exitCode: null, kill() {} };
    const signaled = [];
    globalOwnedRegistry.clear();
    globalOwnedRegistry.register(rootPid, {
        parentPid: process.pid,
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });
    globalOwnedRegistry.register(foreignPid, {
        parentPid: rootPid,
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });
    globalOwnedRegistry.register(historicalPid, {
        parentPid: null,
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });

    try {
        const result = await killProcessTree(child, 250, {
            finalObservationReserveMs: 25,
            _platform: 'linux',
            includeGlobalRegistry: true,
            _getDescendantPids: () => ({ state: 'known-empty', pids: [] }),
            _probeProcessLiveness: () => ({ state: 'dead' }),
            _probePortListener: async () => ({ state: 'free' }),
            _signal: (pid) => signaled.push(pid),
        });

        assert.equal(result, true);
        assert.deepEqual(signaled, [], 'unverified numeric PIDs must never be signaled');
    } finally {
        globalOwnedRegistry.clear();
    }
});

test('resolvePosixIdentitySafeOwned only marks descendants verified by current live linkage signalable', () => {
    // Defect exact (POSIX identity-safe): POSIX has no immutable start-token,
    // so teardown may signal only the owned root, descendants discovered by
    // exact live parent linkage this pass, or descendants previously adopted by
    // the linkage binder. A historical/tokenless registry PID must never be
    // signalable (fail-closed), preventing signal to a PID reused by a foreign
    // process tree after a prior run recorded it.
    const registry = new OwnedProcessRegistry();
    const rootPid = 7000;
    makeOwnedRoot(registry, rootPid, 'ROOT-7000');

    const liveLinked = 7001; // discovered this pass via pgrep linkage
    const adopted = 7002; // previously adopted by the linkage binder
    registry.register(adopted, { parentPid: rootPid, startToken: null });
    const historical = 7003; // tokenless registry entry, no parent linkage
    registry.register(historical, { parentPid: null, startToken: null });

    const known = new Set([liveLinked, adopted, historical]);
    const live = new Set([liveLinked]);

    const safe = resolvePosixIdentitySafeOwned(rootPid, known, live, registry, {
        rootOwnershipProven: true,
    });
    assert.deepEqual(
        resolvePosixIdentitySafeOwned(rootPid, known, live, registry),
        new Set(),
        'root ownership capability is required',
    );
    assert.ok(safe.has(rootPid), 'owned root is always signalable');
    assert.ok(safe.has(liveLinked), 'live-linked descendant is signalable');
    assert.equal(
        safe.has(adopted),
        false,
        'historical linkage-adopted descendant must be reverified live before signal',
    );
    assert.equal(
        safe.has(historical),
        false,
        'historical tokenless registry PID must never be signalable',
    );
});

test('root-exit/reparent terminal proof does not adopt or signal a late descendant', async () => {
    const registry = new OwnedProcessRegistry();
    registry.register(8300, {
        parentPid: process.pid,
        startToken: 'ROOT-8300',
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });

    const discovered = [];
    const killed = [];
    try {
        const terminal = await killProcessTree({ pid: 8300, exitCode: 0 }, 500, {
            _platform: 'win32',
            expectedStartToken: 'ROOT-8300',
            _getDescendantPids: () => {
                discovered.push('late-reparented-descendant');
                return { state: 'known', pids: [8301] };
            },
            // Simulate the post-exit observation already retained by the caller;
            // it must be terminally proven dead without being adopted/signaled.
            additionalPids: [8301],
            port: 52371,
            _getProcessStartToken: (pid) => (pid === 8300 ? 'ROOT-8300' : null),
            _probePortListener: async () => ({ state: 'free' }),
            _killPidTree: (pid) => {
                killed.push(pid);
                return { status: 0, output: '' };
            },
            _probeProcessLiveness: () => ({ state: 'dead', reason: 'positive-dead-proof' }),
        });

        assert.equal(terminal, true, 'an exited root with terminal dead proof must succeed');
        assert.equal(
            discovered.length,
            1,
            'post-exit discovery may observe state but must not authorize ownership',
        );
        assert.equal(
            registry.items.has(8301),
            false,
            'a reparented late descendant must never be adopted after root exit',
        );
        assert.deepEqual(killed, [], 'terminal proof must never signal a late numeric PID');
    } finally {
        registry.clear();
    }
});

test('late root identity lookup is retried before descendant binding and teardown', async () => {
    const registry = new OwnedProcessRegistry();
    registry.register(8200, {
        parentPid: process.pid,
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });

    let rootLookups = 0;
    const supervisor = startOwnedDescendantSupervisor(8200, {
        _registry: registry,
        _platform: 'win32',
        supervisorIntervalMs: 50,
        _getDescendantPids: () => [8201],
        _getProcessStartToken: (pid) => {
            if (pid === 8200) return rootLookups++ === 0 ? null : 'ROOT-8200';
            if (pid === 8201) return 'CHILD-8201';
            return null;
        },
    });

    // The first Windows identity lookup is intentionally unavailable. The
    // supervisor must retry while the owner is alive, then bind the child with
    // its immutable token; teardown must never invent identity after exit.
    await new Promise((resolve) => setTimeout(resolve, 90));
    supervisor.stop();

    assert.equal(registry.items.get(8200).startToken, 'ROOT-8200');
    assert.equal(registry.items.get(8201).startToken, 'CHILD-8201');
    assert.equal(registry.items.get(8201).parentPid, 8200);
    assert.equal(supervisor.teardownVerified, true);
});

test('owned descendant supervisor performs a final pre-teardown bind and survives binder errors', async () => {
    const calls = [];
    const supervisor = startOwnedDescendantSupervisor(8000, {
        supervisorIntervalMs: 10,
        _bindOwnedDescendantIdentities: (rootPid) => {
            calls.push(rootPid);
            return {
                rootPid,
                reason: null,
                bound: [8000 + calls.length],
                alreadyBound: [],
                unknownIdentity: [],
                refusedForeign: [],
            };
        },
    });

    assert.equal(calls.length, 1, 'supervisor must bind immediately on start');

    await new Promise((resolve) => setTimeout(resolve, 60));
    const passesWhileAlive = calls.length;
    assert.ok(passesWhileAlive >= 2, 'supervisor must keep binding while the owner is alive');

    const passes = supervisor.stop();
    assert.equal(
        calls.length,
        passesWhileAlive + 1,
        'stop() must run exactly one final pre-teardown bind',
    );
    assert.equal(passes.length, calls.length);
    assert.deepEqual(passes.at(-1).bound, [8000 + calls.length]);

    // stop() is idempotent: no extra binds, no throw.
    const again = supervisor.stop();
    assert.equal(calls.length, passesWhileAlive + 1);
    assert.equal(again.length, passes.length);

    // A throwing binder must never escape the supervisor.
    const failing = startOwnedDescendantSupervisor(8100, {
        supervisorIntervalMs: 10,
        _bindOwnedDescendantIdentities: () => {
            throw new Error('wmic exploded');
        },
    });
    const failingPasses = failing.stop();
    assert.ok(failingPasses.length >= 2);
    assert.match(failingPasses[0].reason, /^binder-error: wmic exploded$/);
    assert.deepEqual(failingPasses[0].bound, []);
    assert.equal(failing.teardownVerified, false, 'binder errors must be sticky and unverified');
});

test('verifyOwnedRegistryTerminal proves the exact child-tree and port terminal state fail-closed', async () => {
    const registry = new OwnedProcessRegistry();
    makeOwnedRoot(registry, 9000, 'ROOT-9000');
    registry.register(9001, {
        parentPid: 9000,
        startToken: 'CHILD-9001',
        skipStartTokenDiscovery: true,
        skipProcessDiscovery: true,
    });
    registry.registerPort(63418);

    // Identity-aware probe: an entry whose bound token no longer matches the
    // live process is proven DEAD (its PID was reused), not mistaken for alive.
    const liveTokens = { 9000: 'ROOT-9000', 9001: 'FOREIGN-REPLACEMENT' };
    const identityProbe = (pid, options = {}) => {
        const current = liveTokens[pid] ?? null;
        if (current === null) return { state: 'dead', reason: 'no-tasks-matched' };
        if (options.expectedStartToken && options.expectedStartToken !== current) {
            return { state: 'dead', reason: 'pid-reused-by-another-process' };
        }
        return { state: 'alive', identityVerified: true };
    };

    const stillAlive = await verifyOwnedRegistryTerminal({
        timeoutMs: 120,
        _registry: registry,
        _probeProcessLiveness: identityProbe,
        _probePortListener: async () => ({ state: 'free' }),
    });
    assert.equal(stillAlive.ok, false, 'a live owned PID must fail the terminal proof');
    assert.deepEqual(
        stillAlive.alivePids.map((entry) => entry.pid),
        [9000],
        'the reused PID 9001 must be proven dead through its bound start-token, not reported alive',
    );

    // Owner dies; only the reused/foreign PID remains on the box.
    delete liveTokens[9000];
    const portBound = await verifyOwnedRegistryTerminal({
        timeoutMs: 120,
        _registry: registry,
        _probeProcessLiveness: identityProbe,
        _probePortListener: async (port) =>
            port === 63418 ? { state: 'listener', pid: 9911 } : { state: 'free' },
    });
    assert.equal(portBound.ok, false, 'an owned port still bound must fail the terminal proof');
    assert.deepEqual(portBound.alivePids, []);
    assert.deepEqual(portBound.boundPorts, [{ port: 63418, state: 'listener' }]);

    const terminal = await verifyOwnedRegistryTerminal({
        timeoutMs: 120,
        _registry: registry,
        _probeProcessLiveness: identityProbe,
        _probePortListener: async () => ({ state: 'free' }),
    });
    assert.equal(terminal.ok, true, 'dead tree + free ports must prove terminal state');
    assert.deepEqual(terminal.alivePids, []);
    assert.deepEqual(terminal.boundPorts, []);

    // An unknown liveness state is NOT terminal (fail-closed).
    const unknown = await verifyOwnedRegistryTerminal({
        timeoutMs: 120,
        _registry: registry,
        _probeProcessLiveness: () => ({ state: 'unknown', reason: 'tasklist-nonzero-exit: 1' }),
        _probePortListener: async () => ({ state: 'free' }),
    });
    assert.equal(unknown.ok, false, 'unknown identity must never be treated as terminal');
    assert.deepEqual(
        unknown.alivePids.map((entry) => entry.state),
        ['unknown', 'unknown'],
    );
});

test('canonical main() proves owned registry terminal before releasing the mutex and clearing the registry', async () => {
    const source = readFileSync(new URL('./e2e-canonical.mjs', import.meta.url), 'utf8');

    for (const marker of [
        'const TEARDOWN_TOTAL_TIMEOUT_MS = 15000;',
        'const teardownDeadline = Date.now() + TEARDOWN_TOTAL_TIMEOUT_MS;',
        'const terminalProof = await verifier({',
        'timeoutMs: remainingBudgetMs(teardownDeadline),',
        'const killed = await killer(probeServerChild, remainingBudgetMs(teardownDeadline),',
        'probeServerSupervisor = startOwnedDescendantSupervisor(probeServer.pid',
        'const stopProbeServerSupervisor = () => {',
        'activeMutex.updateOwnedMetadata();',
        'stopProbeServerSupervisor();',
    ]) {
        assert.ok(source.includes(marker), `e2e-canonical must contain: ${marker}`);
    }

    const proofIndex = source.indexOf('const terminalProof = await verifier({');
    const releaseIndex = source.indexOf('mutex.release()');
    const clearIndex = source.indexOf('globalOwnedRegistry.clear()');
    assert.ok(
        proofIndex !== -1 && releaseIndex !== -1 && clearIndex !== -1,
        'terminal proof, mutex release and registry clear must all exist',
    );
    assert.ok(
        proofIndex < releaseIndex && proofIndex < clearIndex,
        'terminal proof must run before mutex release and registry clear',
    );

    // Executable proof through real main(): an unverified terminal state must
    // hold the mutex, and the supervisor must be stopped before teardown.
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-terminal-proof-'));
    const lockPath = join(tempDir, 'terminal-proof.lock');
    const runnerEntry = join(tempDir, 'runner-terminal-proof.mjs');
    const canonicalModuleUrl = pathToFileURL(join(process.cwd(), 'scripts/e2e-canonical.mjs')).href;

    writeFileSync(
        runnerEntry,
        `
        import { main } from ${JSON.stringify(canonicalModuleUrl)};

        await main({
            skipPreflight: true,
            lockPath: ${JSON.stringify(lockPath)},
            steps: [['node', '-e', 'process.exit(0);']],
            // Teardown itself succeeds; the terminal proof refuses to confirm.
            _killProcessTree: async () => true,
            _verifyOwnedRegistryTerminal: async () => ({
                ok: false,
                alivePids: [{ pid: 424242, state: 'unknown' }],
                boundPorts: [63418],
            }),
        });
    `,
    );

    try {
        const proc = spawn(process.execPath, [runnerEntry], { stdio: ['pipe', 'pipe', 'pipe'] });
        let stderr = '';
        proc.stderr.on('data', (chunk) => {
            stderr += String(chunk);
        });
        const exitCode = await new Promise((resolve) => proc.once('exit', resolve));

        assert.equal(
            exitCode,
            1,
            'main() must fail closed when the owned registry is not terminal',
        );
        assert.match(stderr, /Owned registry not terminal/);
        assert.match(stderr, /63418/);
        assert.equal(
            existsSync(lockPath),
            true,
            'mutex must be held when the terminal proof is unverified',
        );
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('single absolute teardown deadline: kill and terminal proof share one budget, no phase restarts the clock', async () => {
    // Defect exact (teardown deadline): discovery → kill → terminal proof → port
    // release must finish inside ONE absolute deadline. Each later phase is
    // granted only the time REMAINING until that deadline — never a fresh fixed
    // budget (the pre-fix shape chained kill=5000ms, kill=5000ms, proof=3000ms
    // independently and therefore had no single upper bound).
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-teardown-deadline-'));
    const lockPath = join(tempDir, 'deadline.lock');
    const runnerEntry = join(tempDir, 'runner-deadline.mjs');
    const recordedPath = join(tempDir, 'recorded.json');
    const canonicalModuleUrl = pathToFileURL(join(process.cwd(), 'scripts/e2e-canonical.mjs')).href;

    writeFileSync(
        runnerEntry,
        `
        import { writeFileSync } from 'node:fs';
        import { main } from ${JSON.stringify(canonicalModuleUrl)};

        const recorded = { killBudgets: [], verifyTimeoutMs: [] };
        try {
            await main({
                skipPreflight: true,
                lockPath: ${JSON.stringify(lockPath)},
                steps: [['-e', 'process.exit(0);']],
                _killProcessTree: async (_child, timeoutMs) => {
                    recorded.killBudgets.push(timeoutMs);
                    return true;
                },
                _verifyOwnedRegistryTerminal: async (opts) => {
                    recorded.verifyTimeoutMs.push(typeof opts.timeoutMs === 'number' ? opts.timeoutMs : null);
                    return { ok: true, alivePids: [], boundPorts: [] };
                },
            });
        } finally {
            writeFileSync(${JSON.stringify(recordedPath)}, JSON.stringify(recorded));
        }
    `,
    );

    const proc = spawn(process.execPath, [runnerEntry], { stdio: ['pipe', 'pipe', 'pipe'] });
    const exitCode = await new Promise((resolve) => proc.once('exit', resolve));
    assert.equal(exitCode, 0, 'deadline-bounded teardown must exit cleanly');

    const recorded = JSON.parse(readFileSync(recordedPath, 'utf8'));
    assert.equal(recorded.killBudgets.length, 1, 'exactly one kill phase expected (probe skipped)');
    assert.equal(recorded.verifyTimeoutMs.length, 1, 'exactly one terminal proof expected');

    const [killBudget] = recorded.killBudgets;
    const [verifyBudget] = recorded.verifyTimeoutMs;

    assert.ok(
        killBudget > 5000 && killBudget <= 15000 + 1000,
        `kill budget ${killBudget} must come from the single deadline (not the old fixed 5000)`,
    );
    assert.ok(
        verifyBudget > 3000 && verifyBudget <= 15000 + 1000,
        `terminal proof budget ${verifyBudget} must come from the remaining deadline (not the old fixed 3000)`,
    );
    assert.ok(
        verifyBudget <= killBudget,
        `terminal proof budget ${verifyBudget} must not exceed the kill budget ${killBudget} (single clock)`,
    );

    rmSync(tempDir, { recursive: true, force: true });
});

test('e2e-run normal exit fails closed unless pre-exit discovery evidence is terminal', () => {
    const source = readFileSync(new URL('./e2e-run.mjs', import.meta.url), 'utf8');

    for (const marker of [
        'const ownedPorts = new Set([8010]);',
        'let descendantDiscoveryVerified = true;',
        'const recordPreExitDiscovery = () => {',
        'preExitDiscoveryRecorded = true;',
        'normal root exit without pre-exit discovery proof',
        'await terminalProof(deadline)',
        'Descendant tree/owned port not terminal or discovery unverified; refusing hot rollback',
    ]) {
        assert.ok(source.includes(marker), `e2e-run must contain: ${marker}`);
    }

    const preExitIndex = source.indexOf('const recordPreExitDiscovery = () => {');
    const proofIndex = source.indexOf('await terminalProof(deadline)');
    const rollbackIndex = source.indexOf('restoreHot();');
    assert.ok(preExitIndex < proofIndex && proofIndex < rollbackIndex);
});

test('deadline and late-descendant source guards prevent timeout zero and post-exit discovery claims', () => {
    const canonicalSource = readFileSync(new URL('./e2e-canonical.mjs', import.meta.url), 'utf8');
    const reapSource = readFileSync(new URL('./e2e-server-reap.mjs', import.meta.url), 'utf8');
    const runSource = readFileSync(new URL('./e2e-run.mjs', import.meta.url), 'utf8');
    const webserverSource = readFileSync(new URL('./e2e-webserver.mjs', import.meta.url), 'utf8');

    assert.doesNotMatch(canonicalSource, /timeout:\s*Math\.min\(1000,\s*remaining\(\)\)/);
    assert.doesNotMatch(reapSource, /timeout:\s*Math\.min\(1000,\s*remaining\(\)\)/);
    assert.doesNotMatch(webserverSource, /Math\.max\(1,\s*deadline - Date\.now\(\)\)/);
    assert.match(webserverSource, /normal root exit without pre-exit descendant discovery proof/);
    assert.match(runSource, /normal root exit without pre-exit discovery proof/);
});

test('ops-smoke shared-schema PHP step is inside canonical mutex before browser mutation', async () => {
    const packageJson = JSON.parse(
        readFileSync(new URL('../package.json', import.meta.url), 'utf8'),
    );
    assert.equal(
        packageJson.scripts['test:e2e:ops-smoke'],
        'node scripts/e2e-canonical.mjs --ops-smoke tests/e2e/ops-api-smoke.spec.ts',
    );

    const events = [];
    const mutex = {
        release: () => {
            events.push('release');
            return true;
        },
        updateOwnedMetadata: () => {},
    };
    await main({
        argv: ['node', 'scripts/e2e-canonical.mjs', '--ops-smoke', 'focused.spec.ts'],
        skipPreflight: true,
        _acquireCanonicalMutex: () => {
            events.push('acquire');
            return mutex;
        },
        _parseEnvFileCredentials: () => ({
            host: '127.0.0.1',
            port: '3306',
            username: 'root',
            password: '',
            database: 'inbils_test',
        }),
        _runExternalStep: async (command, args) => {
            events.push(`${command}:${args.join(' ')}`);
            return 0;
        },
        _runChainStep: async (args) => {
            events.push(`node:${args.join(' ')}`);
            return 0;
        },
        _verifyOwnedRegistryTerminal: async () => ({ ok: true, alivePids: [], boundPorts: [] }),
    });

    const acquire = events.indexOf('acquire');
    const php = events.findIndex((event) => event.startsWith('php:artisan test --compact'));
    const browser = events.findIndex((event) =>
        event.includes('scripts/e2e-run.mjs focused.spec.ts'),
    );
    const release = events.indexOf('release');
    assert.ok(acquire !== -1 && php !== -1 && browser !== -1 && release !== -1);
    assert.ok(acquire < php && php < browser && browser < release, JSON.stringify(events));
});

test('review lifecycle blockers are represented in root/tree/signal cleanup source', () => {
    const canonicalSource = readFileSync(new URL('./e2e-canonical.mjs', import.meta.url), 'utf8');
    const shutdownIndex = canonicalSource.indexOf('const performShutdown = async');
    const reconcileIndex = canonicalSource.indexOf(
        'reconcileCurrentRunPidFiles(runtimePidBaseline',
        shutdownIndex,
    );
    const releaseIndex = canonicalSource.indexOf('mutex.release()', shutdownIndex);
    assert.ok(
        reconcileIndex > shutdownIndex,
        'signal shutdown must reconcile current-run PID files',
    );
    assert.ok(
        reconcileIndex < releaseIndex,
        'signal PID-file reconcile must precede mutex release',
    );

    const runSource = readFileSync(new URL('./e2e-run.mjs', import.meta.url), 'utf8');
    assert.match(runSource, /const rootTerminal =/);
    assert.match(runSource, /if \(!rootTerminal\) return false/);

    const webserverSource = readFileSync(new URL('./e2e-webserver.mjs', import.meta.url), 'utf8');
    assert.match(webserverSource, /const ownedDescendants = new Map/);
    assert.match(webserverSource, /collectOwnedDescendants/);
    assert.match(
        webserverSource,
        /for \(const \[descendantPid, startToken\] of ownedDescendants\)/,
    );
    assert.match(webserverSource, /PID-file release failed/);
});
