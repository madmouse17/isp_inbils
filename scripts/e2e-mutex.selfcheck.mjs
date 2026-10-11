import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import {
    existsSync,
    mkdtempSync,
    readFileSync,
    readdirSync,
    rmSync,
    unlinkSync,
    writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import {
    acquireCanonicalMutex,
    defaultLockPath,
    getDescendantPids,
    getProcessStartToken,
    globalOwnedRegistry,
    inspectLockPayload,
    isExactWmicNoInstanceOutput,
    isProcessCreatedAfterRegistration,
    isProcessAlive,
    OwnedProcessRegistry,
    probeProcessLiveness,
    observeWindowsProcessTable,
    readLockPayload,
} from './e2e-mutex.mjs';

if (process.env.INBILS_MUTEX_CONTENDER_LOCK) {
    const contenderLockPath = process.env.INBILS_MUTEX_CONTENDER_LOCK;
    const deadline = Date.now() + 1500;
    let acquired = false;
    while (Date.now() < deadline && !acquired) {
        try {
            const contender = acquireCanonicalMutex({
                lockPath: contenderLockPath,
                schema: 'inbils_test',
                pid: process.pid,
                runner: 'metadata-race-contender',
            });
            acquired = true;
            contender.release();
        } catch {
            // Owner masih aktif: seluruh percobaan contender wajib ditolak.
        }
    }
    process.stdout.write(acquired ? 'ACQUIRED\n' : 'BLOCKED\n');
    process.exit(0);
}

test('tokenless PID reuse is only foreign when the current start token is after registration', () => {
    assert.equal(
        isProcessCreatedAfterRegistration('20260908232600.000000+000', '2026-09-08T15:25:36.704Z'),
        true,
    );
    assert.equal(
        isProcessCreatedAfterRegistration('20260908152536.704000+000', '2026-09-08T15:25:36.704Z'),
        false,
    );
    assert.equal(
        isProcessCreatedAfterRegistration('not-a-start-token', '2026-09-08T15:25:36.704Z'),
        false,
    );
});

test('isProcessAlive returns true for current process and false for non-existent pid', () => {
    assert.equal(isProcessAlive(process.pid), true);
    assert.equal(isProcessAlive(999999), false);
    assert.equal(isProcessAlive(0), false);
    assert.equal(isProcessAlive(-1), false);
    assert.equal(isProcessAlive(null), false);
});

test('probeProcessLiveness tri-state: alive, dead, and unknown with bounded probe', () => {
    const alive = probeProcessLiveness(process.pid);
    assert.equal(alive.state, 'alive');

    const dead = probeProcessLiveness(999999);
    assert.equal(dead.state, 'dead');

    const invalid = probeProcessLiveness(-10);
    assert.equal(invalid.state, 'dead');
});

test('probeProcessLiveness tri-state: handles nonzero exit, timeout/error, and malformed outputs as unknown', () => {
    // Non-zero exit
    const mockNonzero = () => ({ status: 1, stdout: '', stderr: 'error' });
    const resNonzero = probeProcessLiveness(1234, {
        _platform: 'win32',
        _spawnSync: mockNonzero,
    });
    assert.equal(resNonzero.state, 'unknown');
    assert.match(resNonzero.reason, /tasklist-nonzero-exit/);

    // Empty stdout on status 0
    const mockEmpty = () => ({ status: 0, stdout: '' });
    const resEmpty = probeProcessLiveness(1234, {
        _platform: 'win32',
        _spawnSync: mockEmpty,
    });
    assert.equal(resEmpty.state, 'unknown');
    assert.match(resEmpty.reason, /empty-tasklist-output/);

    // Timeout / error
    const mockError = () => ({ error: new Error('ETIMEDOUT') });
    const resError = probeProcessLiveness(1234, {
        _platform: 'win32',
        _spawnSync: mockError,
    });
    assert.equal(resError.state, 'unknown');
    assert.match(resError.reason, /tasklist-error/);

    // Malformed output
    const mockMalformed = () => ({ status: 0, stdout: 'GARBAGE_WITHOUT_CSV_FORMAT' });
    const resMalformed = probeProcessLiveness(1234, {
        _platform: 'win32',
        _spawnSync: mockMalformed,
    });
    assert.equal(resMalformed.state, 'unknown');
    assert.match(resMalformed.reason, /malformed-tasklist-output/);
});

test('observeWindowsProcessTable takes one bounded snapshot and exposes immutable identities/linkage', () => {
    let calls = 0;
    const observation = observeWindowsProcessTable({
        timeout: 1000,
        _spawnSync: () => {
            calls += 1;
            return {
                status: 0,
                stderr: '',
                stdout: JSON.stringify([
                    {
                        ProcessId: 100,
                        ParentProcessId: 1,
                        CreationDate: '20260912120000.000000+000',
                        Name: 'root.exe',
                    },
                    {
                        ProcessId: 200,
                        ParentProcessId: 100,
                        CreationDate: '20260912120001.000000+000',
                        Name: 'child.exe',
                    },
                ]),
            };
        },
    });
    assert.equal(calls, 1);
    assert.equal(observation.state, 'known');
    assert.equal(observation.byPid.get(100).startToken, '20260912120000.000000+000');

    const escapedDate = observeWindowsProcessTable({
        timeout: 1000,
        _spawnSync: () => ({
            status: 0,
            stderr: '',
            stdout: JSON.stringify([
                {
                    ProcessId: 300,
                    ParentProcessId: 1,
                    CreationDate: '/Date(1789142874076)/',
                    Name: 'escaped.exe',
                },
            ]),
        }),
    });
    assert.equal(escapedDate.state, 'known');
    assert.equal(escapedDate.byPid.get(300).startToken, '20260911160754.076000+000');
    assert.equal(observation.byPid.get(200).startToken, '20260912120001.000000+000');
    assert.deepEqual(observation.childrenByParent.get(100), [200]);
    assert.equal(
        getProcessStartToken(200, { _platform: 'win32', _processObservation: observation }),
        '20260912120001.000000+000',
    );
    assert.deepEqual([...getDescendantPids(100, { _processObservation: observation })], [200]);
});

test('review blocker: malformed WMIC CreationDate never proves PID reuse or death', () => {
    const expectedStartToken = '20260829120000.000000+000';
    const tasklistAlive = '"node.exe","1234","Console","1","50,000 K"\r\n';
    const malformedWmicOutputs = [
        'garbage,20260829130000.000000+000\r\n',
        'Node,WrongColumn\r\nNODE,20260829130000.000000+000\r\n',
        'Node,CreationDate\r\nNODE,not-a-token\r\n',
        'Node,CreationDate\r\nNODE,20260829130000.000000+000,extra\r\n',
        'Node,CreationDate\r\nNODE,20260829130000.000000+000\r\nEXTRA,row\r\n',
    ];

    for (const stdout of malformedWmicOutputs) {
        const runner = (cmd) =>
            cmd === 'tasklist'
                ? { status: 0, stdout: tasklistAlive, stderr: '' }
                : { status: 0, stdout, stderr: '' };
        assert.equal(
            getProcessStartToken(1234, { _platform: 'win32', _spawnSync: runner }),
            null,
            stdout,
        );
        const liveness = probeProcessLiveness(1234, {
            _platform: 'win32',
            expectedStartToken,
            _spawnSync: runner,
        });
        assert.equal(liveness.state, 'alive', stdout);
        assert.equal(liveness.identityVerified, false, stdout);
    }

    assert.equal(
        getProcessStartToken(1234, {
            _spawnSync: () => ({
                status: 0,
                stdout: 'Node,CreationDate\r\nNODE,20260829130000.000000+000\r\n',
                stderr: 'access denied',
            }),
        }),
        null,
    );
});

test('acquireCanonicalMutex acquires lock with nonce, stores metadata, and releases cleanly', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-mutex-test-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        const lock = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: process.pid,
            runner: 'test-runner',
        });

        assert.equal(existsSync(lockPath), true);
        const payload = readLockPayload(lockPath);
        assert.equal(payload.ownerPid, process.pid);
        assert.equal(payload.schema, 'inbils_test');
        assert.equal(payload.runner, 'test-runner');
        assert.ok(payload.nonce);
        assert.ok(payload.acquiredAt);

        // Releasing removes lock file
        const released = lock.release();
        assert.equal(released, true);
        assert.equal(existsSync(lockPath), false);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('acquireCanonicalMutex refuses concurrent acquisition when owner process is alive', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-mutex-test-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        const lock1 = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: process.pid,
        });

        assert.throws(
            () => {
                acquireCanonicalMutex({
                    lockPath,
                    schema: 'inbils_test',
                    pid: 12345,
                });
            },
            {
                name: 'Error',
                message:
                    /Refusing concurrent canonical E2E run: lock is currently held by active process/,
            },
        );

        lock1.release();
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('metadata refresh keeps canonical pathname continuously exclusive across two processes', async () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-meta-race-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        const owner = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: process.pid,
            runner: 'metadata-race-owner',
        });
        const contender = spawn(process.execPath, [process.argv[1]], {
            env: { ...process.env, INBILS_MUTEX_CONTENDER_LOCK: lockPath },
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        let stdout = '';
        let stderr = '';
        contender.stdout.on('data', (chunk) => {
            stdout += chunk;
        });
        contender.stderr.on('data', (chunk) => {
            stderr += chunk;
        });

        const refreshDeadline = Date.now() + 2000;
        let refreshCount = 0;
        while (Date.now() < refreshDeadline) {
            owner.updateOwnedMetadata(
                [{ pid: process.pid, startToken: owner.payload.processStartToken }],
                [],
            );
            assert.equal(existsSync(lockPath), true, 'pathname lock canonical tidak boleh hilang');
            refreshCount += 1;
        }

        const exitCode = await new Promise((resolve) => contender.once('exit', resolve));
        assert.equal(exitCode, 0, stderr);
        assert.equal(stdout.trim(), 'BLOCKED');
        assert.ok(refreshCount > 1, 'owner harus melakukan refresh metadata berulang');
        assert.deepEqual(
            readdirSync(tempDir).filter((name) => name.includes('.meta-')),
            [],
            'refresh tidak boleh membuat residual .meta-*',
        );
        assert.equal(owner.release(), true);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('acquireCanonicalMutex reclaims stale lock when owner process is dead and payload is valid', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-mutex-test-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        // Write a valid stale lock for dead pid 999999 (with the immutable
        // process-start identity that stale reclaim now requires).
        const stalePayload = {
            nonce: 'stale-nonce-12345',
            ownerPid: 999999,
            processStartToken: 'stale-token-dead',
            schema: 'inbils_test',
            runner: 'stale-runner',
            acquiredAt: new Date(Date.now() - 3600000).toISOString(),
        };
        writeFileSync(lockPath, JSON.stringify(stalePayload, null, 2));

        // Attempting to acquire should detect stale owner and reclaim
        const lock = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: process.pid,
            runner: 'active-runner',
            _platform: 'win32',
            _spawnSync: (cmd) =>
                cmd === 'tasklist'
                    ? {
                          status: 0,
                          stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                          stderr: '',
                      }
                    : { status: 0, stdout: 'Node,ProcessId\r\n', stderr: '' },
        });

        assert.equal(existsSync(lockPath), true);
        const payload = readLockPayload(lockPath);
        assert.equal(payload.ownerPid, process.pid);
        assert.equal(payload.runner, 'active-runner');
        assert.notEqual(payload.nonce, 'stale-nonce-12345');

        lock.release();
        assert.equal(existsSync(lockPath), false);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('Finding #1: malformed/partial/empty lock payload FAILS CLOSED without automatic reap', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-mutex-test-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        for (const malformedContent of [
            '', // empty
            '   \n', // whitespace
            'not-json', // corrupt string
            '{"ownerPid": 123}', // partial: missing nonce, schema
            '{"nonce": "abc", "schema": "inbils_test"}', // partial: missing ownerPid
            '{"ownerPid": "not-a-number", "nonce": "abc", "schema": "inbils_test"}', // invalid ownerPid
            '[]', // array instead of object
            'null', // null
            JSON.stringify({
                ownerPid: 999999,
                nonce: 'bad-processes-type',
                schema: 'inbils_test',
                ownedProcesses: 'corrupt',
            }),
            JSON.stringify({
                ownerPid: 999999,
                nonce: 'bad-process-entry',
                schema: 'inbils_test',
                ownedProcesses: [{ pid: '9999', port: 8010 }],
            }),
            JSON.stringify({
                ownerPid: 999999,
                nonce: 'bad-port-marker-process-identity',
                schema: 'inbils_test',
                ownedProcesses: [{ port: 8010, startToken: 'must-not-exist' }],
            }),
            JSON.stringify({
                ownerPid: 999999,
                nonce: 'bad-port-marker-parent-identity',
                schema: 'inbils_test',
                ownedProcesses: [{ port: 8010, parentPid: 1234 }],
            }),
            JSON.stringify({
                ownerPid: 999999,
                nonce: 'bad-port-marker-port',
                schema: 'inbils_test',
                ownedProcesses: [{ port: '8010' }],
            }),
            JSON.stringify({
                ownerPid: 999999,
                nonce: 'bad-ports-type',
                schema: 'inbils_test',
                ownedPorts: ['8010'],
            }),
        ]) {
            writeFileSync(lockPath, malformedContent);

            assert.throws(
                () => {
                    acquireCanonicalMutex({
                        lockPath,
                        schema: 'inbils_test',
                        pid: process.pid,
                    });
                },
                {
                    name: 'Error',
                    message: /lock file at .* exists but has invalid or ambiguous payload/,
                },
                `Expected fail-closed for: ${malformedContent}`,
            );

            // Lock file MUST NOT be deleted by the failed acquisition
            assert.equal(
                existsSync(lockPath),
                true,
                'Malformed lock file must remain untouched for investigation',
            );
        }
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('Finding #2: conditional release protects foreign replacement locks and nonce mismatches', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-mutex-test-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        const lock1 = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: process.pid,
        });

        const initialNonce = lock1.nonce;

        // Foreign process overwrites the lock file with its own valid lock
        const foreignPayload = {
            nonce: 'foreign-nonce-777',
            ownerPid: 8888,
            schema: 'inbils_test',
            runner: 'foreign-runner',
            acquiredAt: new Date().toISOString(),
        };
        writeFileSync(lockPath, JSON.stringify(foreignPayload, null, 2));

        // lock1 attempts release() -> MUST NOT delete foreign lock!
        const released = lock1.release();
        assert.equal(released, false, 'Release must return false when lock was replaced');
        assert.equal(
            existsSync(lockPath),
            true,
            'Foreign replacement lock must survive unowned release',
        );

        const currentPayload = readLockPayload(lockPath);
        assert.equal(currentPayload.nonce, 'foreign-nonce-777');

        // Test with malformed replacement: release() must NOT unlink malformed replacement
        writeFileSync(lockPath, 'corrupted-by-foreign-writer');
        const releasedOnCorrupt = lock1.release();
        assert.equal(releasedOnCorrupt, false);
        assert.equal(existsSync(lockPath), true);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('durable child-tree exclusion: lock cannot be reclaimed if owner dead but descendant alive', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-durable-exclusion-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        const deadOwnerPid = 999999;
        const liveDescendantPid = process.pid; // Current test process is alive!

        const mockSpawnSync = (cmd, args) => {
            if (cmd === 'wmic') {
                if (args.includes(`ParentProcessId=${deadOwnerPid}`)) {
                    return {
                        status: 0,
                        stdout: `Node,ProcessId\r\nNODE,${liveDescendantPid}\r\n`,
                        stderr: '',
                    };
                }
                if (args.includes(`ParentProcessId=${liveDescendantPid}`)) {
                    return {
                        status: 0,
                        stdout: `Node,ProcessId\r\n`,
                        stderr: '',
                    };
                }
            }
            if (cmd === 'tasklist') {
                if (args.find((a) => a.includes(`PID eq ${deadOwnerPid}`))) {
                    return {
                        status: 0,
                        stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                        stderr: '',
                    };
                }
                if (args.find((a) => a.includes(`PID eq ${liveDescendantPid}`))) {
                    return {
                        status: 0,
                        stdout: `"node.exe","${liveDescendantPid}","Console","1","50,000 K"\r\n`,
                        stderr: '',
                    };
                }
            }
            return { status: 0, stdout: '', stderr: '' };
        };

        const stalePayload = {
            nonce: 'owner-dead-child-alive-nonce',
            ownerPid: deadOwnerPid,
            processStartToken: 'stale-token-dead',
            schema: 'inbils_test',
            runner: 'scripts/e2e-canonical.mjs',
            acquiredAt: new Date(Date.now() - 3600000).toISOString(),
            nodeVersion: process.version,
        };
        writeFileSync(lockPath, JSON.stringify(stalePayload, null, 2));

        // Attempting to acquire must fail-closed because descendant is alive!
        assert.throws(
            () => {
                acquireCanonicalMutex({
                    lockPath,
                    schema: 'inbils_test',
                    pid: 12345,
                    _platform: 'win32',
                    _spawnSync: mockSpawnSync,
                });
            },
            /owned descendant process PID .* is still active/,
            'Acquisition must be refused when owned descendant is still active',
        );

        // Lock file must remain intact
        assert.equal(existsSync(lockPath), true);
        const payload = readLockPayload(lockPath);
        assert.equal(payload.nonce, 'owner-dead-child-alive-nonce');
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('durable bound-port exclusion: lock cannot be reclaimed if owner dead but bound port is still active', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-port-exclusion-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        const deadOwnerPid = 999999;
        const activePort = 8011;
        const foreignListenerPid = 44444;

        const mockSpawnSync = (cmd, args) => {
            if (cmd === 'wmic') {
                return { status: 0, stdout: `Node,ProcessId\r\n`, stderr: '' };
            }
            if (cmd === 'tasklist') {
                return {
                    status: 0,
                    stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                    stderr: '',
                };
            }
            if (cmd === 'netstat') {
                return {
                    status: 0,
                    stdout: `  TCP    127.0.0.1:${activePort}         0.0.0.0:0              LISTENING       ${foreignListenerPid}\r\n`,
                    stderr: '',
                };
            }
            return { status: 0, stdout: '', stderr: '' };
        };

        const stalePayload = {
            nonce: 'owner-dead-port-bound-nonce',
            ownerPid: deadOwnerPid,
            processStartToken: 'stale-token-dead',
            schema: 'inbils_test',
            runner: 'scripts/e2e-canonical.mjs',
            ownedPorts: [activePort],
            acquiredAt: new Date(Date.now() - 3600000).toISOString(),
            nodeVersion: process.version,
        };
        writeFileSync(lockPath, JSON.stringify(stalePayload, null, 2));

        // Attempting to acquire must fail-closed because recorded port is still bound!
        assert.throws(
            () => {
                acquireCanonicalMutex({
                    lockPath,
                    schema: 'inbils_test',
                    pid: 12345,
                    _platform: 'win32',
                    _spawnSync: mockSpawnSync,
                    _listenerPidOnPort: (port) => (port === activePort ? foreignListenerPid : null),
                });
            },
            /port 8011 is still bound by PID 44444/,
            'Acquisition must be refused when owned port is still bound',
        );

        assert.equal(existsSync(lockPath), true);
        const payload = readLockPayload(lockPath);
        assert.equal(payload.nonce, 'owner-dead-port-bound-nonce');
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('Finding #2 & #5: PID reuse detection with processStartToken', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-mutex-test-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        // Create lock with current PID but fake/stale creation token
        const fakeOldStartToken = '20000101000000.000000+000';
        const stalePayload = {
            nonce: 'reused-pid-nonce',
            ownerPid: process.pid,
            processStartToken: fakeOldStartToken,
            schema: 'inbils_test',
            runner: 'stale-runner',
            acquiredAt: new Date(Date.now() - 3600000).toISOString(),
        };
        writeFileSync(lockPath, JSON.stringify(stalePayload, null, 2));

        // When current process attempts acquire with its actual current token,
        // it should detect that the old token was from a different process instance (reused PID),
        // treating the old lock as stale and successfully reclaiming.
        const lock = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: process.pid,
            runner: 'current-runner',
            _platform: 'win32',
            _spawnSync: (cmd, args) => {
                if (cmd === 'tasklist') {
                    return {
                        status: 0,
                        stdout: `"node.exe","${process.pid}","Console","1","50,000 K"\r\n`,
                        stderr: '',
                    };
                }
                if (cmd === 'wmic' && args.includes('CreationDate')) {
                    return {
                        status: 0,
                        stdout: 'Node,CreationDate\r\nNODE,20260921120000.000000+000\r\n',
                        stderr: '',
                    };
                }
                return { status: 0, stdout: 'Node,ProcessId\r\n', stderr: '' };
            },
        });

        assert.equal(existsSync(lockPath), true);
        const payload = readLockPayload(lockPath);
        assert.equal(payload.runner, 'current-runner');
        assert.notEqual(payload.nonce, 'reused-pid-nonce');

        lock.release();
        assert.equal(existsSync(lockPath), false);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('Finding #6: stale registry entry with PID reuse is rejected before descendant discovery and the lock still reclaims', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-mutex-stale-entry-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');
    const reusedOwnerPid = 9001;
    const foreignChildPid = 9002;
    const staleToken = '20260827100000.000000+000';
    const liveToken = '20260828100000.000000+000';
    let ownerDescendantDiscoveryAttempted = false;
    let foreignChildDiscoveryAttempted = false;

    try {
        const mockSpawnSync = (cmd, args) => {
            if (cmd === 'wmic') {
                if (args.includes(`ProcessId=${reusedOwnerPid}`) && args.includes('CreationDate')) {
                    return {
                        status: 0,
                        stdout: `Node,CreationDate\r\nNODE,${liveToken}\r\n`,
                        stderr: '',
                    };
                }
                if (
                    args.includes(`ProcessId=${foreignChildPid}`) &&
                    args.includes('CreationDate')
                ) {
                    return {
                        status: 0,
                        stdout: `Node,CreationDate\r\nNODE,${liveToken}\r\n`,
                        stderr: '',
                    };
                }
                if (args.includes(`ParentProcessId=${reusedOwnerPid}`)) {
                    ownerDescendantDiscoveryAttempted = true;
                    throw new Error(
                        'getDescendantPids must not run for PID-reused registry entries',
                    );
                }
                if (args.includes(`ParentProcessId=${foreignChildPid}`)) {
                    foreignChildDiscoveryAttempted = true;
                    throw new Error(
                        'nested descendant discovery must not occur for PID-reused registry entries',
                    );
                }
                if (args.includes('ParentProcessId=999999')) {
                    return {
                        status: 0,
                        stdout: 'Node,ProcessId\r\n',
                        stderr: '',
                    };
                }
                return {
                    status: 0,
                    stdout: 'Node,CreationDate\r\n',
                    stderr: '',
                };
            }

            if (cmd === 'tasklist') {
                if (args.find((a) => a.includes(`PID eq ${reusedOwnerPid}`))) {
                    return {
                        status: 0,
                        stdout: '"node.exe","9001","Console","1","50,000 K"\r\n',
                        stderr: '',
                    };
                }
                if (args.find((a) => a.includes(`PID eq ${foreignChildPid}`))) {
                    return {
                        status: 0,
                        stdout: '"node.exe","9002","Console","1","50,000 K"\r\n',
                        stderr: '',
                    };
                }
                if (args.find((a) => a.includes('PID eq 999999'))) {
                    return {
                        status: 0,
                        stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                        stderr: '',
                    };
                }
            }

            return { status: 0, stdout: '', stderr: '' };
        };

        const stalePayload = {
            nonce: 'stale-registry-entry-nonce',
            ownerPid: reusedOwnerPid,
            processStartToken: staleToken,
            schema: 'inbils_test',
            runner: 'stale-runner',
            ownedProcesses: [
                {
                    pid: reusedOwnerPid,
                    startToken: staleToken,
                    parentPid: 999999,
                    port: 8019,
                    addedAt: new Date(Date.now() - 3600000).toISOString(),
                },
            ],
            ownedPorts: [8019],
            acquiredAt: new Date(Date.now() - 3600000).toISOString(),
            nodeVersion: process.version,
        };
        writeFileSync(lockPath, JSON.stringify(stalePayload, null, 2));

        const lock = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: 12345,
            _platform: 'win32',
            _spawnSync: mockSpawnSync,
            _probePortListener: () => ({ state: 'free' }),
        });

        assert.equal(
            ownerDescendantDiscoveryAttempted,
            false,
            'reused owner PID must fail closed before descendant discovery',
        );
        assert.equal(
            foreignChildDiscoveryAttempted,
            false,
            'foreign replacement child must survive without being adopted into discovery',
        );
        assert.equal(existsSync(lockPath), true);
        const payload = readLockPayload(lockPath);
        assert.equal(payload.ownerPid, 12345);
        assert.equal(payload.runner, 'scripts/e2e-canonical.mjs');

        lock.release();
        assert.equal(existsSync(lockPath), false);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('globalOwnedRegistry unregister and clear methods', () => {
    const registry = new OwnedProcessRegistry();
    registry.register(1234, { startToken: 't1', port: 8010 });
    assert.equal(registry.getAllPids().includes(1234), true);
    assert.equal(registry.getAllPorts().includes(8010), true);

    registry.unregister(1234);
    assert.equal(registry.getAllPids().includes(1234), false);

    registry.clear();
    assert.equal(registry.getAllPids().length, 0);
    assert.equal(registry.getAllPorts().length, 0);
});

test('OwnedProcessRegistry preserves existing start token and ports on same-PID re-registration', () => {
    const registry = new OwnedProcessRegistry();
    registry.register(1234, { startToken: 'trusted-token', port: 8010, parentPid: 111 });
    registry.register(1234, { startToken: 'conflicting-token', port: 9999, parentPid: 222 });

    const item = registry.items.get(1234);
    assert.ok(item);
    assert.equal(item.startToken, 'trusted-token');
    assert.equal(item.port, 8010);
    assert.equal(item.parentPid, 111);
});

test('OwnedProcessRegistry.clear snapshots keys before unregistering so teardown stays bounded', () => {
    const registry = new OwnedProcessRegistry();
    registry.register(1234, { startToken: 't1', port: 8010 });

    let injected = false;
    const originalUnregister = registry.unregister.bind(registry);
    registry.unregister = (key) => {
        if (!injected) {
            injected = true;
            registry.register(5678, { startToken: 't2', port: 8011 });
        }
        return originalUnregister(key);
    };

    registry.clear();

    assert.equal(registry.getAllPids().includes(5678), true);
    assert.equal(registry.getAllPorts().includes(8011), true);
});

test('bound-port fail-closed on unknown probe state', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-port-unknown-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        const deadOwnerPid = 999999;
        const activePort = 8012;

        const mockSpawnSync = (cmd) => {
            if (cmd === 'wmic') {
                return { status: 0, stdout: 'Node,ProcessId\r\n', stderr: '' };
            }
            if (cmd === 'tasklist') {
                return {
                    status: 0,
                    stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                    stderr: '',
                };
            }
            return { status: 0, stdout: '', stderr: '' };
        };

        const stalePayload = {
            nonce: 'owner-dead-port-unknown-nonce',
            ownerPid: deadOwnerPid,
            processStartToken: 'stale-token-dead',
            schema: 'inbils_test',
            runner: 'scripts/e2e-canonical.mjs',
            ownedPorts: [activePort],
            acquiredAt: new Date(Date.now() - 3600000).toISOString(),
            nodeVersion: process.version,
        };
        writeFileSync(lockPath, JSON.stringify(stalePayload, null, 2));

        assert.throws(() => {
            acquireCanonicalMutex({
                lockPath,
                schema: 'inbils_test',
                pid: 12345,
                _platform: 'win32',
                _spawnSync: mockSpawnSync,
                _probePortListener: () => ({ state: 'unknown', reason: 'mock-error' }),
            });
        }, /unable to verify state of owned port 8012/);

        assert.equal(existsSync(lockPath), true);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('Finding #3: hanya pesan no-match tasklist ternormalisasi lengkap yang membuktikan dead', () => {
    const ambiguousOutputs = [
        { stdout: 'INFO:', expectedState: 'unknown' },
        { stdout: 'INFO: some unrelated message\r\n', expectedState: 'unknown' },
        { stdout: 'INFO: No tasks are running\r\n', expectedState: 'unknown' },
        {
            stdout: 'garbage INFO: No tasks are running which match the specified criteria.',
            expectedState: 'unknown',
        },
        {
            stdout: 'INFO: No tasks are running which match the specified criteria. garbage',
            expectedState: 'unknown',
        },
        {
            stdout: 'INFO: No tasks are running which match the specified criteria.\r\nextra line',
            expectedState: 'unknown',
        },
        {
            stdout: '"node.exe","1234","Console","1","50,000 K"\r\nINFO: No tasks are running which match the specified criteria.',
            expectedState: 'alive',
        },
        {
            stdout: 'INFO: No tasks are running which match the specified criterion.',
            expectedState: 'unknown',
        },
        {
            stdout: 'INFORMACIÓN: No hay tareas en ejecución que coincidan con los criterios especificados.',
            expectedState: 'unknown',
        },
    ];

    for (const { stdout, expectedState } of ambiguousOutputs) {
        const result = probeProcessLiveness(1234, {
            _platform: 'win32',
            _spawnSync: () => ({ status: 0, stdout }),
        });
        assert.equal(result.state, expectedState, `state tidak sesuai untuk output: ${stdout}`);
    }

    const exactNoMatch = probeProcessLiveness(1234, {
        _platform: 'win32',
        _spawnSync: () => ({
            status: 0,
            stdout: '\r\nINFO: No tasks are running which match the specified criteria.\r\n',
        }),
    });
    assert.equal(exactNoMatch.state, 'dead');
    assert.equal(exactNoMatch.reason, 'no-tasks-matched');
});

test('ambiguous tasklist no-match output cannot reclaim an existing canonical mutex', () => {
    const ambiguousOutputs = [
        'garbage INFO: No tasks are running which match the specified criteria.',
        'INFO: No tasks are running which match the specified criteria. garbage',
        'INFO: No tasks are running which match the specified criteria.\r\nextra line',
        '"node.exe","999999","Console","1","50,000 K"\r\nINFO: No tasks are running which match the specified criteria.',
        'INFO: No tasks are running which match the specified criterion.',
        'INFORMACIÓN: No hay tareas en ejecución que coincidan con los criterios especificados.',
    ];

    for (const [index, stdout] of ambiguousOutputs.entries()) {
        const tempDir = mkdtempSync(join(tmpdir(), 'inbils-tasklist-ambiguous-'));
        const lockPath = join(tempDir, 'canonical-e2e.lock');
        const stalePayload = {
            nonce: `ambiguous-owner-${index}`,
            ownerPid: 999999,
            processStartToken: 'stale-owner-token',
            schema: 'inbils_test',
            runner: 'scripts/e2e-canonical.mjs',
            acquiredAt: new Date(Date.now() - 3600000).toISOString(),
        };

        try {
            writeFileSync(lockPath, JSON.stringify(stalePayload, null, 2));

            assert.throws(
                () =>
                    acquireCanonicalMutex({
                        lockPath,
                        schema: 'inbils_test',
                        pid: 12345,
                        _platform: 'win32',
                        _spawnSync: (cmd) =>
                            cmd === 'tasklist'
                                ? { status: 0, stdout, stderr: '' }
                                : { status: 0, stdout: '', stderr: '' },
                    }),
                /Refusing (?:canonical E2E run: unable to verify liveness|concurrent canonical E2E run: lock is currently held by active process)/,
            );

            assert.equal(existsSync(lockPath), true);
            assert.equal(readLockPayload(lockPath).nonce, stalePayload.nonce);
        } finally {
            rmSync(tempDir, { recursive: true, force: true });
        }
    }
});

test('Finding #1: stale owner with no process-start identity fails closed', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-tokenless-owner-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        const stalePayload = {
            nonce: 'tokenless-owner-nonce',
            ownerPid: 999999,
            schema: 'inbils_test',
            runner: 'stale-runner',
            acquiredAt: new Date(Date.now() - 3600000).toISOString(),
        };
        writeFileSync(lockPath, JSON.stringify(stalePayload, null, 2));

        assert.throws(
            () =>
                acquireCanonicalMutex({
                    lockPath,
                    schema: 'inbils_test',
                    pid: process.pid,
                    runner: 'active-runner',
                }),
            /recorded no immutable process-start identity/,
        );

        assert.equal(existsSync(lockPath), true);
        assert.equal(readLockPayload(lockPath).nonce, 'tokenless-owner-nonce');
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('stale owner with positively dead tokenless historical entries reclaims after empty descendant proof', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-tokenless-dead-entry-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        const deadOwnerPid = 999999;
        const deadEntryPid = 5555;
        const mockSpawnSync = (cmd, args) => {
            const joined = args.join(' ');
            if (cmd === 'tasklist') {
                const pid = joined.match(/PID eq (\d+)/)?.[1];
                if (pid === String(deadOwnerPid) || pid === String(deadEntryPid)) {
                    return {
                        status: 0,
                        stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                        stderr: '',
                    };
                }
                return {
                    status: 0,
                    stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                    stderr: '',
                };
            }
            if (cmd === 'wmic') {
                return { status: 0, stdout: 'Node,ProcessId\r\n', stderr: '' };
            }
            return { status: 0, stdout: '', stderr: '' };
        };

        const stalePayload = {
            nonce: 'tokenless-dead-entry-nonce',
            ownerPid: deadOwnerPid,
            processStartToken: 'stale-owner-token',
            schema: 'inbils_test',
            runner: 'scripts/e2e-canonical.mjs',
            ownedProcesses: [{ pid: deadEntryPid, parentPid: deadOwnerPid, port: null }],
            ownedPorts: [],
            acquiredAt: new Date(Date.now() - 3600000).toISOString(),
            nodeVersion: process.version,
        };
        writeFileSync(lockPath, JSON.stringify(stalePayload, null, 2));

        const lock = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: 12345,
            _platform: 'win32',
            _spawnSync: mockSpawnSync,
        });

        assert.equal(existsSync(lockPath), true);
        assert.equal(readLockPayload(lockPath).ownerPid, 12345);
        assert.notEqual(readLockPayload(lockPath).nonce, stalePayload.nonce);
        lock.release();
        assert.equal(existsSync(lockPath), false);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('live tokenless recorded owned process fails closed on stale reclaim', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-tokenless-child-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        const mockSpawnSync = (cmd, args) => {
            const joined = args.join(' ');
            if (cmd === 'tasklist') {
                if (joined.includes('PID eq 999999')) {
                    return {
                        status: 0,
                        stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                        stderr: '',
                    };
                }
                if (joined.includes('PID eq 5555')) {
                    return {
                        status: 0,
                        stdout: '"node.exe","5555","Console","1","50,000 K"\r\n',
                        stderr: '',
                    };
                }
                return { status: 0, stdout: '', stderr: '' };
            }
            if (cmd === 'wmic') {
                // No descendants for the dead owner.
                return { status: 0, stdout: 'Node,ProcessId\r\n', stderr: '' };
            }
            return { status: 0, stdout: '', stderr: '' };
        };

        const stalePayload = {
            nonce: 'tokenless-child-nonce',
            ownerPid: 999999,
            processStartToken: 'stale-owner-token',
            schema: 'inbils_test',
            runner: 'scripts/e2e-canonical.mjs',
            ownedProcesses: [{ pid: 5555, parentPid: 999999, port: 8020 }],
            ownedPorts: [],
            acquiredAt: new Date(Date.now() - 3600000).toISOString(),
            nodeVersion: process.version,
        };
        writeFileSync(lockPath, JSON.stringify(stalePayload, null, 2));

        assert.throws(
            () =>
                acquireCanonicalMutex({
                    lockPath,
                    schema: 'inbils_test',
                    pid: 12345,
                    _platform: 'win32',
                    _spawnSync: mockSpawnSync,
                }),
            /has no immutable identity token/,
        );

        assert.equal(existsSync(lockPath), true);
        assert.equal(readLockPayload(lockPath).nonce, 'tokenless-child-nonce');
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('Finding #1: alive-but-unverified owned process fails closed on stale reclaim', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-alive-unverified-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        const mockSpawnSync = (cmd, args) => {
            const joined = args.join(' ');
            if (cmd === 'tasklist') {
                if (joined.includes('PID eq 999999')) {
                    return {
                        status: 0,
                        stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                        stderr: '',
                    };
                }
                if (joined.includes('PID eq 5555')) {
                    return {
                        status: 0,
                        stdout: '"node.exe","5555","Console","1","50,000 K"\r\n',
                        stderr: '',
                    };
                }
                return { status: 0, stdout: '', stderr: '' };
            }
            if (cmd === 'wmic') {
                if (joined.includes('ParentProcessId')) {
                    // No descendants for the dead owner.
                    return { status: 0, stdout: 'Node,ProcessId\r\n', stderr: '' };
                }
                // CreationDate for the owned child is unretrievable -> identity
                // verification fails (alive but identityVerified=false).
                return { status: 0, stdout: '', stderr: '' };
            }
            return { status: 0, stdout: '', stderr: '' };
        };

        const stalePayload = {
            nonce: 'alive-unverified-nonce',
            ownerPid: 999999,
            processStartToken: 'stale-owner-token',
            schema: 'inbils_test',
            runner: 'scripts/e2e-canonical.mjs',
            ownedProcesses: [
                { pid: 5555, startToken: 'stale-child-token', parentPid: 999999, port: 8020 },
            ],
            ownedPorts: [],
            acquiredAt: new Date(Date.now() - 3600000).toISOString(),
            nodeVersion: process.version,
        };
        writeFileSync(lockPath, JSON.stringify(stalePayload, null, 2));

        assert.throws(
            () =>
                acquireCanonicalMutex({
                    lockPath,
                    schema: 'inbils_test',
                    pid: 12345,
                    _platform: 'win32',
                    _spawnSync: mockSpawnSync,
                }),
            /alive but its identity could not be verified/,
        );

        assert.equal(existsSync(lockPath), true);
        assert.equal(readLockPayload(lockPath).nonce, 'alive-unverified-nonce');
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('descendant discovery errors are unknown and preserve the stale lock byte-for-byte', () => {
    const scenarios = [
        ['error', () => ({ error: new Error('ETIMEDOUT') })],
        [
            'throw',
            () => {
                throw new Error('spawn timeout');
            },
        ],
        ['nonzero', () => ({ status: 7, stdout: '', stderr: 'wmic failed' })],
        ['empty', () => ({ status: 0, stdout: '', stderr: '' })],
        ['malformed-header', () => ({ status: 0, stdout: 'garbage\r\n', stderr: '' })],
        [
            'malformed-row',
            () => ({ status: 0, stdout: 'Node,ProcessId\r\nNODE,not-a-pid\r\n', stderr: '' }),
        ],
    ];

    for (const [name, descendantResult] of scenarios) {
        const discovery = getDescendantPids(999999, { _spawnSync: descendantResult });
        assert.equal(discovery.state, 'unknown', name);
        assert.deepEqual(discovery.pids, [], name);

        const tempDir = mkdtempSync(join(tmpdir(), `inbils-discovery-${name}-`));
        const lockPath = join(tempDir, 'canonical-e2e.lock');
        const stalePayload = {
            nonce: `discovery-${name}-nonce`,
            ownerPid: 999999,
            processStartToken: 'stale-owner-token',
            schema: 'inbils_test',
            runner: 'scripts/e2e-canonical.mjs',
            ownedProcesses: [],
            ownedPorts: [],
            acquiredAt: new Date(Date.now() - 3600000).toISOString(),
        };
        const originalBytes = JSON.stringify(stalePayload, null, 2);

        try {
            writeFileSync(lockPath, originalBytes);
            assert.throws(
                () =>
                    acquireCanonicalMutex({
                        lockPath,
                        schema: 'inbils_test',
                        pid: 12345,
                        _spawnSync: (cmd, args) => {
                            if (cmd === 'tasklist') {
                                return {
                                    status: 0,
                                    stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                                    stderr: '',
                                };
                            }
                            if (args.includes('ParentProcessId=999999')) {
                                return descendantResult();
                            }
                            return { status: 0, stdout: 'Node,CreationDate\r\n', stderr: '' };
                        },
                    }),
                /unable to discover descendants of lock owner PID 999999/,
                name,
            );
            assert.equal(readFileSync(lockPath, 'utf8'), originalBytes, name);
        } finally {
            rmSync(tempDir, { recursive: true, force: true });
        }
    }
});

test('Windows descendant discovery classifies errors and validated empty/header rows', () => {
    const cases = [
        [{ error: new Error('ETIMEDOUT') }, 'unknown'],
        [{ status: 2, stdout: '', stderr: 'wmic failed' }, 'unknown'],
        [{ status: 0, stdout: '', stderr: '' }, 'unknown'],
        [{ status: 0, stdout: 'Node,ProcessId\r\n', stderr: 'access denied' }, 'unknown'],
        [{ status: 0, stdout: 'Node,OtherColumn\r\n', stderr: '' }, 'unknown'],
        [{ status: 0, stdout: 'Node,ProcessId\r\nNODE,not-a-pid\r\n', stderr: '' }, 'unknown'],
        [{ status: 0, stdout: 'Node,ProcessId\r\n', stderr: '' }, 'known-empty'],
        [
            {
                status: 0,
                stdout: 'No Instance(s) Available.\r\n\r\nNode,\r\n',
                stderr: '',
            },
            'known-empty',
        ],
        [
            {
                status: 0,
                stdout: '\r\nNode,\r\n',
                stderr: 'No Instance(s) Available.\r\n',
            },
            'known-empty',
        ],
        [{ status: 0, stdout: 'Node,ProcessId\r\nNODE,1234\r\n', stderr: '' }, 'known'],
    ];
    for (const [response, expectedState] of cases) {
        const discovery = getDescendantPids(999999, {
            _platform: 'win32',
            _spawnSync: () => response,
        });
        assert.equal(discovery.state, expectedState);
    }
});

test('Windows WMIC no-instance normalization accepts only the exact empty-set proof', () => {
    for (const exact of [
        ['No Instance(s) Available.\r\n\r\nNode,\r\n', ''],
        ['\uFEFFNo Instance(s) Available.\nNode,\n', ''],
        ['\r\nNode,\r\n', 'No Instance(s) Available.\r\n'],
    ]) {
        assert.equal(isExactWmicNoInstanceOutput(...exact), true, exact.join(' | '));
    }

    for (const ambiguous of [
        '',
        'No Instance(s) Available',
        'No Instance(s) Available. trailing',
        'prefix No Instance(s) Available.',
        'No Instance(s) Available.\r\nNode,ProcessId\r\n',
        'No Instance(s) Available.\r\nNode,\r\nFOREIGN,4242\r\n',
    ]) {
        assert.equal(isExactWmicNoInstanceOutput(ambiguous), false, ambiguous);
        assert.equal(
            getDescendantPids(999999, {
                _platform: 'win32',
                _spawnSync: () => ({ status: 0, stdout: ambiguous, stderr: '' }),
            }).state,
            'unknown',
            ambiguous,
        );
    }
});

test('recursive Windows discovery preserves a nested tree whose listener is a WMIC no-instance leaf', () => {
    const responses = new Map([
        [1000, 'Node,ProcessId\r\nNODE,2000\r\n'],
        [2000, 'Node,ProcessId\r\nNODE,3000\r\n'],
        [3000, 'No Instance(s) Available.\r\n\r\nNode,\r\n'],
    ]);
    const discovery = getDescendantPids(1000, {
        _platform: 'win32',
        _spawnSync: (_cmd, args) => {
            const filter = args.find((arg) => arg.startsWith('ParentProcessId='));
            const parentPid = Number(filter.split('=')[1]);
            return { status: 0, stdout: responses.get(parentPid), stderr: '' };
        },
    });

    assert.equal(discovery.state, 'known');
    assert.deepEqual(discovery.pids, [2000, 3000]);
});

test('descendant discovery defaults to the host platform when no fixture override is provided', () => {
    const calls = [];
    const discovery = getDescendantPids(999999, {
        _spawnSync: (command) => {
            calls.push(command);
            return process.platform === 'win32'
                ? { status: 0, stdout: 'Node,ProcessId\\r\\n', stderr: '' }
                : { status: 1, stdout: '', stderr: '' };
        },
    });

    assert.equal(discovery.state, 'known-empty');
    assert.deepEqual(discovery.pids, []);
    assert.equal(calls[0], process.platform === 'win32' ? 'wmic' : 'pgrep');
});

test('POSIX descendant discovery distinguishes exact no-match from unknown output', () => {
    const cases = [
        [{ error: new Error('ETIMEDOUT') }, 'unknown'],
        [{ status: 2, stdout: '', stderr: 'pgrep failed' }, 'unknown'],
        [{ status: 0, stdout: '', stderr: '' }, 'unknown'],
        [{ status: 0, stdout: 'not-a-pid\n', stderr: '' }, 'unknown'],
        [{ status: 1, stdout: '', stderr: 'ambiguous' }, 'unknown'],
        [{ status: 1, stdout: '', stderr: '' }, 'known-empty'],
    ];
    for (const [response, expectedState] of cases) {
        const discovery = getDescendantPids(999999, {
            _platform: 'linux',
            _spawnSync: () => response,
        });
        assert.equal(discovery.state, expectedState);
    }
});

test('validated known-empty descendant discovery permits stale recovery', () => {
    const discovery = getDescendantPids(999999, {
        _platform: 'win32',
        _spawnSync: () => ({ status: 0, stdout: 'Node,ProcessId\r\n', stderr: '' }),
    });
    assert.equal(discovery.state, 'known-empty');
    assert.deepEqual(discovery.pids, []);

    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-discovery-known-empty-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');
    try {
        writeFileSync(
            lockPath,
            JSON.stringify({
                nonce: 'known-empty-stale-nonce',
                ownerPid: 999999,
                processStartToken: 'stale-owner-token',
                schema: 'inbils_test',
                runner: 'scripts/e2e-canonical.mjs',
                ownedProcesses: [],
                ownedPorts: [],
                acquiredAt: new Date(Date.now() - 3600000).toISOString(),
            }),
        );
        const lock = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: 12345,
            _platform: 'win32',
            _spawnSync: (cmd, args) => {
                if (cmd === 'tasklist') {
                    return {
                        status: 0,
                        stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                        stderr: '',
                    };
                }
                if (args.includes('ParentProcessId=999999')) {
                    return { status: 0, stdout: 'Node,ProcessId\r\n', stderr: '' };
                }
                return { status: 0, stdout: 'Node,CreationDate\r\n', stderr: '' };
            },
        });
        assert.equal(readLockPayload(lockPath).nonce, lock.nonce);
        assert.equal(lock.release(), true);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('Finding #2: updateOwnedMetadata never overwrites a foreign replacement lock', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-meta-foreign-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        const lock = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: process.pid,
            runner: 'active-runner',
        });

        // Simulate a foreign replacement landing between inspect and write.
        const foreignPayload = {
            nonce: 'foreign-nonce-777',
            ownerPid: 424242,
            processStartToken: 'foreign-token',
            schema: 'inbils_test',
            runner: 'foreign-runner',
            acquiredAt: new Date().toISOString(),
        };
        writeFileSync(lockPath, JSON.stringify(foreignPayload, null, 2));

        lock.updateOwnedMetadata([{ pid: 5555, startToken: 'x' }], [8021]);

        const current = readLockPayload(lockPath);
        assert.equal(current.nonce, 'foreign-nonce-777');
        assert.equal(current.ownerPid, 424242);

        const malformedReplacement = 'corrupted-by-foreign-writer';
        writeFileSync(lockPath, malformedReplacement);
        lock.updateOwnedMetadata([{ pid: 6666, startToken: 'y' }], [8023]);
        assert.equal(readFileSync(lockPath, 'utf8'), malformedReplacement);
        assert.equal(lock.release(), false);
        assert.equal(readFileSync(lockPath, 'utf8'), malformedReplacement);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('updateOwnedMetadata preserves a pathname replacement that lands before commit', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-meta-mid-commit-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');
    const foreignPayload = {
        nonce: 'mid-commit-foreign-nonce',
        ownerPid: 424243,
        processStartToken: 'mid-commit-foreign-token',
        schema: 'inbils_test',
        runner: 'mid-commit-foreign-runner',
        acquiredAt: new Date().toISOString(),
    };
    let replaced = false;

    try {
        const lock = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: process.pid,
            runner: 'active-runner',
            _beforeMetadataCommit: () => {
                if (replaced) return;
                replaced = true;
                unlinkSync(lockPath);
                writeFileSync(lockPath, JSON.stringify(foreignPayload, null, 2));
            },
        });

        lock.updateOwnedMetadata([{ pid: 7778, startToken: 't7778' }], [8024]);

        assert.equal(replaced, true);
        assert.deepEqual(readLockPayload(lockPath), foreignPayload);
        assert.equal(lock.release(), false);
        assert.deepEqual(readLockPayload(lockPath), foreignPayload);
        assert.deepEqual(
            readdirSync(tempDir).filter((name) => name.includes('.meta-')),
            [],
        );
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('review blocker: a legacy per-process port must be proven free before stale reclaim', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-legacy-entry-port-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');
    const deadOwnerPid = 999999;
    const deadEntryPid = 5555;
    const legacyPort = 8010;

    try {
        const stalePayload = {
            nonce: 'legacy-entry-port-nonce',
            ownerPid: deadOwnerPid,
            processStartToken: 'stale-owner-token',
            schema: 'inbils_test',
            runner: 'scripts/e2e-canonical.mjs',
            ownedProcesses: [{ pid: deadEntryPid, parentPid: deadOwnerPid, port: legacyPort }],
            ownedPorts: [],
            acquiredAt: new Date(Date.now() - 3600000).toISOString(),
        };
        writeFileSync(lockPath, JSON.stringify(stalePayload, null, 2));

        assert.throws(
            () =>
                acquireCanonicalMutex({
                    lockPath,
                    schema: 'inbils_test',
                    pid: 12345,
                    _platform: 'win32',
                    _spawnSync: (cmd) =>
                        cmd === 'tasklist'
                            ? {
                                  status: 0,
                                  stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                                  stderr: '',
                              }
                            : { status: 0, stdout: 'Node,ProcessId\r\n', stderr: '' },
                    _probePortListener: (port) => ({
                        state: 'listener',
                        pid: port === legacyPort ? 4242 : null,
                    }),
                }),
            /port 8010 is still bound by PID 4242/,
        );
        assert.equal(readLockPayload(lockPath).nonce, stalePayload.nonce);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('review blocker: stale restore never overwrites a contender lock', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-stale-restore-race-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');
    const stalePayload = {
        nonce: 'stale-restore-race-nonce',
        ownerPid: 999999,
        processStartToken: 'stale-owner-token',
        schema: 'inbils_test',
        runner: 'scripts/e2e-canonical.mjs',
        ownedProcesses: [],
        ownedPorts: [],
        acquiredAt: new Date(Date.now() - 3600000).toISOString(),
    };
    const contenderPayload = {
        nonce: 'stale-restore-contender',
        ownerPid: 424242,
        processStartToken: 'contender-token',
        schema: 'inbils_test',
        runner: 'contender-runner',
        ownedProcesses: [],
        ownedPorts: [],
        acquiredAt: new Date().toISOString(),
    };

    try {
        writeFileSync(lockPath, JSON.stringify(stalePayload, null, 2));
        assert.throws(
            () =>
                acquireCanonicalMutex({
                    lockPath,
                    schema: 'inbils_test',
                    pid: 12345,
                    _platform: 'win32',
                    _spawnSync: (cmd) =>
                        cmd === 'tasklist'
                            ? {
                                  status: 0,
                                  stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                                  stderr: '',
                              }
                            : { status: 0, stdout: 'Node,ProcessId\r\n', stderr: '' },
                    _afterStaleIsolation: (tempPath) => {
                        writeFileSync(
                            tempPath,
                            JSON.stringify({ ...stalePayload, nonce: 'modified-stale-nonce' }),
                        );
                    },
                    _beforeStaleRestore: () => {
                        writeFileSync(lockPath, JSON.stringify(contenderPayload, null, 2));
                    },
                }),
            /replaced or modified during reclamation/,
        );
        assert.deepEqual(readLockPayload(lockPath), contenderPayload);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('review blocker: release restore never overwrites a contender lock', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-release-restore-race-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');
    const contenderPayload = {
        nonce: 'release-restore-contender',
        ownerPid: 424243,
        processStartToken: 'contender-token',
        schema: 'inbils_test',
        runner: 'contender-runner',
        ownedProcesses: [],
        ownedPorts: [],
        acquiredAt: new Date().toISOString(),
    };

    try {
        const lock = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: process.pid,
            runner: 'release-race-owner',
            _beforeReleaseRestore: () => {
                writeFileSync(lockPath, JSON.stringify(contenderPayload, null, 2));
            },
        });
        writeFileSync(
            lockPath,
            JSON.stringify({ ...lock.payload, nonce: 'foreign-isolated-nonce' }, null, 2),
        );

        assert.equal(lock.release(), false);
        assert.deepEqual(readLockPayload(lockPath), contenderPayload);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('Finding #2: updateOwnedMetadata persists owned metadata when lock identity matches', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-meta-persist-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');

    try {
        const lock = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: process.pid,
            runner: 'active-runner',
        });

        lock.updateOwnedMetadata([{ pid: 7777, startToken: 't7777' }], [8022]);

        const current = readLockPayload(lockPath);
        assert.ok(Array.isArray(current.ownedProcesses));
        assert.ok(current.ownedProcesses.some((e) => e && e.pid === 7777));
        assert.ok(Array.isArray(current.ownedPorts));
        assert.ok(current.ownedPorts.includes(8022));

        lock.release();
        assert.equal(existsSync(lockPath), false);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('review blocker: persisted port-only registry marker stays valid and bound-port stale reclaim fails closed', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-port-marker-contract-'));
    const lockPath = join(tempDir, 'canonical-e2e.lock');
    const staleOwnerPid = 999999;
    const registeredPort = 65432;

    try {
        globalOwnedRegistry.clear();
        globalOwnedRegistry.registerPort(registeredPort);
        const staleLock = acquireCanonicalMutex({
            lockPath,
            schema: 'inbils_test',
            pid: staleOwnerPid,
            processStartToken: 'stale-owner-token',
            runner: 'port-marker-owner',
        });
        staleLock.updateOwnedMetadata();

        const inspection = inspectLockPayload(lockPath);
        assert.equal(inspection.valid, true, inspection.reason);
        assert.ok(
            inspection.payload.ownedProcesses.some(
                (entry) => entry && entry.pid === undefined && entry.port === registeredPort,
            ),
        );

        assert.throws(
            () =>
                acquireCanonicalMutex({
                    lockPath,
                    schema: 'inbils_test',
                    pid: process.pid,
                    _platform: 'win32',
                    _spawnSync: (cmd) =>
                        cmd === 'tasklist'
                            ? {
                                  status: 0,
                                  stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                                  stderr: '',
                              }
                            : { status: 0, stdout: 'Node,ProcessId\r\n', stderr: '' },
                    _probePortListener: (port) =>
                        port === registeredPort
                            ? { state: 'listener', pid: 44444 }
                            : { state: 'free' },
                }),
            /port 65432 is still bound by PID 44444/,
        );
        assert.equal(staleLock.release(), true);
    } finally {
        globalOwnedRegistry.clear();
        rmSync(tempDir, { recursive: true, force: true });
    }
});
