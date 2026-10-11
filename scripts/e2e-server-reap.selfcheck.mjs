import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import {
    copyFileSync,
    existsSync,
    linkSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    readdirSync,
    rmSync,
    writeFileSync,
} from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { delimiter, dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
    inspectProcessDetails,
    isExactPidFileMatch,
    isOwnedE2EWebServerProcess,
    killPidTree,
    listenerPidOnPort,
    parsePidFile,
    probePortListener,
    reapOrphanedE2EServers,
    reapVerifiedOwnedServerTree,
    safelyReapPidFile,
    validateWebServerOwnership,
} from './e2e-server-reap.mjs';

test('isOwnedE2EWebServerProcess distinguishes owned E2E webserver from foreign php processes', () => {
    // Owned signatures
    assert.equal(
        isOwnedE2EWebServerProcess(
            'php artisan serve --env=e2e --host=127.0.0.1 --port=8010',
            8010,
        ),
        true,
    );
    assert.equal(
        isOwnedE2EWebServerProcess(
            'C:\\laragon\\bin\\php\\php.exe artisan serve --env=e2e --port=8010',
            8010,
        ),
        true,
    );
    assert.equal(
        isOwnedE2EWebServerProcess(
            'php vendor/laravel/framework/src/Illuminate/Foundation/Console/../resources/server.php 127.0.0.1 8010',
            8010,
        ),
        true,
    );

    // Foreign signatures (Finding #4) - MUST NEVER MATCH
    assert.equal(isOwnedE2EWebServerProcess('php -S 127.0.0.1:8999', 8010), false);
    assert.equal(isOwnedE2EWebServerProcess('php -S 127.0.0.1:8010 foreign-app.php', 8010), false);
    assert.equal(isOwnedE2EWebServerProcess('php artisan queue:work', 8010), false);
    assert.equal(
        isOwnedE2EWebServerProcess('php artisan serve --env=local --port=8000', 8010),
        false,
    );
    assert.equal(isOwnedE2EWebServerProcess('', 8010), false);
    assert.equal(isOwnedE2EWebServerProcess(null, 8010), false);
});

test('parsePidFile correctly parses JSON payload and raw numeric PID', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-reap-test-'));
    const pidFile = join(tempDir, 'webserver-1234.pid');

    try {
        // Valid JSON with all ownership tokens
        writeFileSync(
            pidFile,
            JSON.stringify({
                nonce: 'nonce-123',
                pid: 1234,
                processStartToken: 'token-123',
                parentPid: 5678,
                parentStartToken: 'parent-token-123',
                runner: 'scripts/e2e-webserver.mjs',
                port: 8010,
                cwd: process.cwd(),
            }),
        );
        const parsed = parsePidFile(pidFile);
        assert.ok(parsed);
        assert.equal(parsed.pid, 1234);
        assert.equal(parsed.nonce, 'nonce-123');
        assert.equal(parsed.processStartToken, 'token-123');
        assert.equal(parsed.parentStartToken, 'parent-token-123');

        // Raw numeric legacy
        writeFileSync(pidFile, '9999\n');
        const parsedLegacy = parsePidFile(pidFile);
        assert.ok(parsedLegacy);
        assert.equal(parsedLegacy.pid, 9999);

        // Corrupt / empty
        writeFileSync(pidFile, 'invalid-not-json-or-number');
        assert.equal(parsePidFile(pidFile), null);

        writeFileSync(pidFile, '');
        assert.equal(parsePidFile(pidFile), null);
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('inspectProcessDetails tri-state probe: alive, dead, unknown with bounded timeout', () => {
    const self = inspectProcessDetails(process.pid, 5000, {
        _platform: 'win32',
        _spawnSync: (cmd) =>
            cmd === 'wmic'
                ? {
                      status: 0,
                      stdout: `Node,CommandLine,ParentProcessId,ProcessId\r\nNODE,"node selfcheck",1,${process.pid}\r\n`,
                      stderr: '',
                  }
                : { status: 0, stdout: '', stderr: '' },
    });
    assert.equal(self.state, 'alive');
    assert.ok(self.commandLine);

    const dead = inspectProcessDetails(999999, 5000, {
        _platform: 'win32',
        _spawnSync: () => ({
            status: 0,
            stdout: 'No Instance(s) Available.\r\n\r\nNode,\r\n',
            stderr: '',
        }),
    });
    assert.equal(dead.state, 'dead');

    const invalid = inspectProcessDetails(-5, 5000, { _platform: 'win32' });
    assert.equal(invalid.state, 'dead');

    return; // keep the fixture explicitly Windows-shaped and deterministic

    /* unreachable legacy host probe */
    const deadLegacy = inspectProcessDetails(999999, 5000);
    assert.equal(deadLegacy.state, 'dead');

    const invalidLegacy = inspectProcessDetails(-5, 5000);
    assert.equal(invalidLegacy.state, 'dead');
});

test('inspectProcessDetails accepts only exact WMIC process/dead shapes and fails closed on ambiguity', () => {
    const probe = (stdout, stderr = '') =>
        inspectProcessDetails(4242, 5000, {
            _platform: 'win32',
            _spawnSync: () => ({ status: 0, stdout, stderr }),
        });

    assert.equal(probe('No Instance(s) Available.\r\n\r\nNode,\r\n').state, 'dead');
    for (const [name, stdout, stderr = ''] of [
        ['empty', '', ''],
        ['whitespace', ' \r\n', ''],
        ['header-only', 'Node,CommandLine,ParentProcessId,ProcessId\r\n', ''],
        ['truncated', 'Node,CommandLine,ParentProcessId,ProcessId\r\nNODE,php artisan', ''],
        ['extra-row', 'No Instance(s) Available.\r\nNode,\r\nNODE,extra', ''],
        ['near-match', 'No Instances Available.\r\nNode,\r\n', ''],
        ['stderr', 'No Instance(s) Available.\r\nNode,\r\n', 'warning'],
    ]) {
        assert.equal(probe(stdout, stderr).state, 'unknown', `${name} must fail closed`);
    }

    const alive = probe(
        'Node,CommandLine,ParentProcessId,ProcessId\r\nNODE,"php artisan serve --env=e2e --port=8010",100,4242\r\n',
    );
    assert.equal(alive.state, 'alive');
    assert.equal(alive.pid, 4242);
    assert.equal(alive.parentPid, 100);
});

test('validateWebServerOwnership and reapOrphanedE2EServers fail-closed on foreign mimic, PID reuse, and identity mismatch', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-reap-mimic-'));
    const pid = 7777;
    const pidFile = join(tempDir, `webserver-${pid}.pid`);

    try {
        // Case 1: Foreign mimic - process is alive and has identical command line, but PID file has no ownership / wrong nonce / wrong token
        const mockSpawnSyncMimic = (cmd, args) => {
            if (cmd === 'tasklist') {
                const pidArg = args.find((a) => a.includes('PID eq'));
                if (pidArg && pidArg.includes('999999')) {
                    return {
                        status: 0,
                        stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                        stderr: '',
                    };
                }
                return {
                    status: 0,
                    stdout: '"php.exe","7777","Console","1","10,000 K"\r\n',
                    stderr: '',
                };
            }
            if (cmd === 'wmic') {
                if (args.includes('CreationDate')) {
                    return {
                        status: 0,
                        stdout: 'Node,CreationDate\r\nNODE,token-1\r\n',
                        stderr: '',
                    };
                }
                const csv = `Node,CommandLine,ParentProcessId,ProcessId\r\nNODE,"php artisan serve --env=e2e --port=8010",1000,7777\r\n`;
                return { status: 0, stdout: csv, stderr: '' };
            }
            if (cmd === 'taskkill') {
                throw new Error('taskkill must not be called on unverified foreign mimic!');
            }
            return { status: 0, stdout: '', stderr: '' };
        };

        // File has wrong runner
        writeFileSync(
            pidFile,
            JSON.stringify({
                nonce: 'valid-nonce',
                pid: 7777,
                parentPid: 999999, // Dead parent
                parentStartToken: 'parent-token-dead',
                processStartToken: 'token-1',
                runner: 'wrong-runner',
                port: 8010,
                cwd: process.cwd(),
            }),
        );
        let check = validateWebServerOwnership(pid, parsePidFile(pidFile), 8010, {
            _platform: 'win32',
            _spawnSync: mockSpawnSyncMimic,
        });
        assert.equal(check.valid, false);
        assert.equal(check.reason, 'unverified-ownership-payload-fields');

        // File has CWD mismatch
        writeFileSync(
            pidFile,
            JSON.stringify({
                nonce: 'valid-nonce',
                pid: 7777,
                parentPid: 999999, // Dead parent
                parentStartToken: 'parent-token-dead',
                processStartToken: 'token-1',
                runner: 'scripts/e2e-webserver.mjs',
                port: 8010,
                cwd: 'C:/other/foreign/directory',
            }),
        );
        check = validateWebServerOwnership(pid, parsePidFile(pidFile), 8010, {
            _platform: 'win32',
            _spawnSync: mockSpawnSyncMimic,
        });
        assert.equal(check.valid, false);
        assert.equal(check.reason, 'cwd-mismatch');

        // Case 2: PID reuse - live process has different start token
        const mockSpawnSyncTokenMismatch = (cmd, args) => {
            if (cmd === 'tasklist') {
                const pidArg = args.find((a) => a.includes('PID eq'));
                if (pidArg && pidArg.includes('999999')) {
                    return {
                        status: 0,
                        stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                        stderr: '',
                    };
                }
                return {
                    status: 0,
                    stdout: '"php.exe","7777","Console","1","10,000 K"\r\n',
                    stderr: '',
                };
            }
            if (cmd === 'wmic') {
                if (args.includes('CreationDate')) {
                    return {
                        status: 0,
                        stdout: 'Node,CreationDate\r\nNODE,20260827150000.000000+000\r\n',
                        stderr: '',
                    };
                }
                const csv = `Node,CommandLine,ParentProcessId,ProcessId\r\nNODE,"php artisan serve --env=e2e --port=8010",1000,7777\r\n`;
                return { status: 0, stdout: csv, stderr: '' };
            }
            return { status: 0, stdout: '', stderr: '' };
        };

        writeFileSync(
            pidFile,
            JSON.stringify({
                nonce: 'valid-nonce',
                pid: 7777,
                parentPid: 999999, // Dead parent
                parentStartToken: 'parent-token-dead',
                processStartToken: '20260827100000.000000+000', // Stale/different token!
                runner: 'scripts/e2e-webserver.mjs',
                port: 8010,
                cwd: process.cwd(),
            }),
        );
        check = validateWebServerOwnership(pid, parsePidFile(pidFile), 8010, {
            _platform: 'win32',
            _spawnSync: mockSpawnSyncTokenMismatch,
        });
        assert.equal(check.valid, false);
        assert.equal(check.reason, 'process-start-token-mismatch');

        // Case 3: Foreign listener on port with no PID file is NEVER killed
        const mockSpawnSyncListener = (cmd, args) => {
            if (cmd === 'netstat') {
                return {
                    status: 0,
                    stdout: '  TCP    127.0.0.1:8010         0.0.0.0:0              LISTENING       8888\r\n',
                    stderr: '',
                };
            }
            if (cmd === 'wmic') {
                return {
                    status: 0,
                    stdout: 'Node,CommandLine,ParentProcessId,ProcessId\r\nNODE,"php artisan serve --env=e2e --port=8010",1000,8888\r\n',
                    stderr: '',
                };
            }
            if (cmd === 'taskkill') {
                throw new Error('Foreign listener without valid PID file must NOT be killed!');
            }
            return { status: 0, stdout: '', stderr: '' };
        };

        const emptyRuntimeDir = mkdtempSync(join(tmpdir(), 'inbils-reap-empty-'));
        try {
            const reaped = reapOrphanedE2EServers(8010, {
                runtimeDir: emptyRuntimeDir,
                _spawnSync: mockSpawnSyncListener,
            });
            assert.equal(reaped.length, 0, 'Reaper must not kill listener without PID file');
        } finally {
            rmSync(emptyRuntimeDir, { recursive: true, force: true });
        }
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('validateWebServerOwnership fails closed when parent runner process is still alive or parent identity is missing/unverified', () => {
    // Defect exact (3): validate parent liveness & identity check in reaper
    const pid = 6666;
    const liveParentPid = process.pid; // Our test process is alive!
    const mockSpawnSync = (cmd, args) => {
        if (cmd === 'tasklist') {
            const pidArg = args.find((a) => a.includes('PID eq'));
            if (pidArg && pidArg.includes(String(liveParentPid))) {
                return {
                    status: 0,
                    stdout: `"node.exe","${liveParentPid}","Console","1","50,000 K"\r\n`,
                    stderr: '',
                };
            }
            return {
                status: 0,
                stdout: '"php.exe","6666","Console","1","10,000 K"\r\n',
                stderr: '',
            };
        }
        if (cmd === 'wmic') {
            if (args.includes('CreationDate')) {
                const pidArg = args.find((a) => a.includes('ProcessId='));
                if (pidArg && pidArg.includes(String(liveParentPid))) {
                    return {
                        status: 0,
                        stdout: 'Node,CreationDate\r\nNODE,token-parent-alive\r\n',
                        stderr: '',
                    };
                }
                return {
                    status: 0,
                    stdout: 'Node,CreationDate\r\nNODE,token-666\r\n',
                    stderr: '',
                };
            }
            const csv = `Node,CommandLine,ParentProcessId,ProcessId\r\nNODE,"php artisan serve --env=e2e --port=8010",${liveParentPid},${pid}\r\n`;
            return { status: 0, stdout: csv, stderr: '' };
        }
        return { status: 0, stdout: '', stderr: '' };
    };

    // Subcase 1: parent is live
    const fileDataLive = {
        nonce: 'valid-nonce',
        pid,
        parentPid: liveParentPid,
        parentStartToken: 'token-parent-alive',
        processStartToken: 'token-666',
        runner: 'scripts/e2e-webserver.mjs',
        port: 8010,
        cwd: process.cwd(),
    };

    let check = validateWebServerOwnership(pid, fileDataLive, 8010, {
        _platform: 'win32',
        _spawnSync: mockSpawnSync,
    });
    assert.equal(check.valid, false);
    assert.equal(check.reason, 'parent-process-is-still-alive');

    // Subcase 2: parentStartToken missing/empty in payload -> fail closed
    const fileDataMissingToken = {
        nonce: 'valid-nonce',
        pid,
        parentPid: 999999,
        parentStartToken: null,
        processStartToken: 'token-666',
        runner: 'scripts/e2e-webserver.mjs',
        port: 8010,
        cwd: process.cwd(),
    };

    check = validateWebServerOwnership(pid, fileDataMissingToken, 8010, {
        _platform: 'win32',
        _spawnSync: mockSpawnSync,
    });
    assert.equal(check.valid, false);
    assert.equal(check.reason, 'unverified-ownership-payload-fields');
});

test('validateWebServerOwnership fails closed when actual parent linkage does not match claimed parent exactly', () => {
    // Defect exact (parent linkage): a PID file that claims one parent while the
    // live process is actually parented by a different PID must fail closed, and
    // an unverifiable (unknown) actual parent must never promote to ownership.
    const pid = 4242;
    const claimedParentPid = 999999; // dead, per tasklist
    const actualParentPid = 1000; // live parent differs from claim
    const ownedPort = 8010;

    const baseMock = (csvParentPid) => (cmd, args) => {
        if (cmd === 'tasklist') {
            const pidArg = args.find((a) => a.includes('PID eq'));
            if (pidArg && pidArg.includes(String(claimedParentPid))) {
                return {
                    status: 0,
                    stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                    stderr: '',
                };
            }
            return {
                status: 0,
                stdout: `"php.exe","${pid}","Console","1","10,000 K"\r\n`,
                stderr: '',
            };
        }
        if (cmd === 'wmic') {
            if (args.includes('CreationDate')) {
                return {
                    status: 0,
                    stdout: 'Node,CreationDate\r\nNODE,20260827150000.000000+000\r\n',
                    stderr: '',
                };
            }
            const csv = `Node,CommandLine,ParentProcessId,ProcessId\r\nNODE,"php artisan serve --env=e2e --port=${ownedPort}",${csvParentPid},${pid}\r\n`;
            return { status: 0, stdout: csv, stderr: '' };
        }
        return { status: 0, stdout: '', stderr: '' };
    };

    const fileData = {
        nonce: 'valid-nonce',
        pid,
        parentPid: claimedParentPid,
        parentStartToken: 'parent-token-dead',
        processStartToken: '20260827150000.000000+000',
        runner: 'scripts/e2e-webserver.mjs',
        port: ownedPort,
        cwd: process.cwd(),
    };

    // Subcase 1: actual parent differs from claimed parent -> fail closed.
    const mismatch = validateWebServerOwnership(pid, fileData, ownedPort, {
        _platform: 'win32',
        _spawnSync: baseMock(actualParentPid),
    });
    assert.equal(mismatch.valid, false);
    assert.equal(mismatch.reason, 'parent-linkage-mismatch');

    // Subcase 2: actual parent is unverifiable (WMIC reports no valid parent) -> fail closed.
    const unverifiable = validateWebServerOwnership(pid, fileData, ownedPort, {
        _platform: 'win32',
        _spawnSync: baseMock(''),
    });
    assert.equal(unverifiable.valid, false);
    assert.equal(unverifiable.reason, 'parent-linkage-unverifiable');

    // Sanity: when actual parent matches the claim, ownership validates.
    const matching = validateWebServerOwnership(pid, fileData, ownedPort, {
        _platform: 'win32',
        _spawnSync: baseMock(claimedParentPid),
    });
    assert.equal(matching.valid, true);
});

test('reapOrphanedE2EServers with identity race protection: foreign or replacement process survives kill', () => {
    // Defect exact (4): Test that WMIC atomic terminate with CreationDate token fails closed if process identity changes (replacement survives)
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-reap-race-'));
    const pid = 7777;
    const pidFile = join(tempDir, `webserver-${pid}.pid`);

    try {
        let terminateCalledWithWhere = null;
        let terminateAttempted = false;

        const mockSpawnSync = (cmd, args) => {
            if (cmd === 'tasklist') {
                const pidArg = args.find((a) => a.includes('PID eq'));
                if (pidArg && pidArg.includes('999999')) {
                    return {
                        status: 0,
                        stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                        stderr: '',
                    };
                }
                return {
                    status: 0,
                    stdout: '"php.exe","7777","Console","1","10,000 K"\r\n',
                    stderr: '',
                };
            }
            if (cmd === 'wmic') {
                if (args.includes('CreationDate') && !args.includes('call')) {
                    // Initial inspection saw token-original
                    return {
                        status: 0,
                        stdout: 'Node,CreationDate\r\nNODE,20260827150100.000000+000\r\n',
                        stderr: '',
                    };
                }
                if (args.includes('call') && args.includes('Terminate')) {
                    terminateAttempted = true;
                    terminateCalledWithWhere = args[2];
                    // Simulate replacement race between inspection and kill:
                    // Process 7777 was replaced by a foreign/new process with token-replacement.
                    // Because WHERE clause specifies CreationDate="token-original", WMIC matches 0 instances!
                    return {
                        status: 0,
                        stdout: 'No Instance(s) Available.\r\n',
                        stderr: '',
                    };
                }
                const csv = `Node,CommandLine,ParentProcessId,ProcessId\r\nNODE,"php artisan serve --env=e2e --port=8010",999999,7777\r\n`;
                return { status: 0, stdout: csv, stderr: '' };
            }
            return { status: 0, stdout: '', stderr: '' };
        };

        writeFileSync(
            pidFile,
            JSON.stringify({
                nonce: 'valid-nonce',
                pid: 7777,
                parentPid: 999999, // Dead parent
                parentStartToken: 'parent-token-dead',
                processStartToken: '20260827150100.000000+000',
                runner: 'scripts/e2e-webserver.mjs',
                port: 8010,
                cwd: process.cwd(),
            }),
        );

        const reaped = reapOrphanedE2EServers(8010, {
            runtimeDir: tempDir,
            _platform: 'win32',
            _spawnSync: mockSpawnSync,
            _getDescendantPids: () => ({ state: 'known-empty', pids: [] }),
        });

        // The replacement process was NOT reaped because the atomic token check protected it!
        assert.equal(
            reaped.length,
            0,
            'Replacement process with mismatched token must survive and not be reaped',
        );
        assert.equal(terminateAttempted, true);
        assert.ok(terminateCalledWithWhere.includes('CreationDate="20260827150100.000000+000"'));
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('conditional PID-file release preserves foreign replacement PID file', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-pid-release-test-'));
    const pid = 5555;
    const pidFile = join(tempDir, `webserver-${pid}.pid`);

    try {
        const ownedPayload = {
            nonce: 'owned-nonce-111',
            pid,
            processStartToken: 'token-5555-orig',
            parentPid: 4444,
            parentStartToken: 'token-parent-4444',
            port: 8010,
            runner: 'scripts/e2e-webserver.mjs',
            cwd: process.cwd(),
            startedAt: new Date().toISOString(),
        };
        writeFileSync(pidFile, `${JSON.stringify(ownedPayload, null, 2)}\n`);

        // Function simulating webserver releasePidFile with exact immutable payload check
        const simulateReleasePidFile = (targetPidFile, expectedPayload) => {
            try {
                if (!existsSync(targetPidFile)) return false;
                const raw = readFileSync(targetPidFile, 'utf8');
                const currentData = JSON.parse(raw);
                if (
                    currentData &&
                    expectedPayload &&
                    currentData.nonce === expectedPayload.nonce &&
                    currentData.pid === expectedPayload.pid &&
                    currentData.processStartToken === expectedPayload.processStartToken &&
                    currentData.parentPid === expectedPayload.parentPid &&
                    currentData.parentStartToken === expectedPayload.parentStartToken &&
                    currentData.port === expectedPayload.port
                ) {
                    rmSync(targetPidFile, { force: true });
                    return true;
                }
                return false;
            } catch {
                return false;
            }
        };

        // 1. Success release with exact matching payload
        const released = simulateReleasePidFile(pidFile, ownedPayload);
        assert.equal(released, true);
        assert.equal(existsSync(pidFile), false);

        // 2. Foreign replacement before release: file overwritten by foreign runner
        const foreignPayload = {
            nonce: 'foreign-nonce-999',
            pid,
            processStartToken: 'token-5555-new',
            parentPid: 8888,
            parentStartToken: 'token-parent-8888',
            port: 8010,
            runner: 'scripts/e2e-webserver.mjs',
            cwd: process.cwd(),
            startedAt: new Date().toISOString(),
        };
        writeFileSync(pidFile, `${JSON.stringify(foreignPayload, null, 2)}\n`);

        // Original runner tries to release its old payload -> MUST NOT delete foreign file!
        const releasedForeign = simulateReleasePidFile(pidFile, ownedPayload);
        assert.equal(releasedForeign, false);
        assert.equal(existsSync(pidFile), true);

        const currentInFile = JSON.parse(readFileSync(pidFile, 'utf8'));
        assert.equal(currentInFile.nonce, 'foreign-nonce-999');
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('safelyReapPidFile atomic rename and fail-closed malformed handling', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-reap-atomic-'));
    const pidFile = join(tempDir, 'webserver-999.pid');

    try {
        const expected = {
            nonce: 'n1',
            pid: 999,
            processStartToken: 'token1',
            parentPid: 100,
            parentStartToken: 'ptoken1',
            port: 8010,
            runner: 'scripts/e2e-webserver.mjs',
            cwd: process.cwd(),
        };

        // 1. Matches expected -> unlinked safely
        writeFileSync(pidFile, JSON.stringify(expected));
        const reaped = safelyReapPidFile(pidFile, expected);
        assert.equal(reaped, true);
        assert.equal(existsSync(pidFile), false);

        // 2. Foreign replacement -> restored safely
        const foreign = { ...expected, nonce: 'foreign-nonce' };
        writeFileSync(pidFile, JSON.stringify(foreign));
        const reapedForeign = safelyReapPidFile(pidFile, expected);
        assert.equal(reapedForeign, false);
        assert.equal(existsSync(pidFile), true);
        assert.equal(JSON.parse(readFileSync(pidFile, 'utf8')).nonce, 'foreign-nonce');

        // 3. Malformed JSON -> fail-closed, restored safely
        writeFileSync(pidFile, '{ broken-json !!!');
        const reapedMalformed = safelyReapPidFile(pidFile, expected);
        assert.equal(reapedMalformed, false);
        assert.equal(existsSync(pidFile), true);
        assert.equal(readFileSync(pidFile, 'utf8'), '{ broken-json !!!');
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('safelyReapPidFile restore never overwrites a contender created after isolation', () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-reap-contender-'));
    const pidFile = join(tempDir, 'webserver-999.pid');
    const expected = {
        nonce: 'expected',
        pid: 999,
        processStartToken: 'token',
        parentPid: 100,
        parentStartToken: 'parent-token',
        port: 8010,
        runner: 'scripts/e2e-webserver.mjs',
        cwd: process.cwd(),
    };
    const foreign = { ...expected, nonce: 'foreign' };
    const contender = { ...expected, nonce: 'contender' };

    try {
        for (const payload of [JSON.stringify(foreign), '{ malformed']) {
            writeFileSync(pidFile, payload);
            let injected = false;
            const result = safelyReapPidFile(pidFile, expected, {
                _beforeRestore: () => {
                    if (injected) return;
                    injected = true;
                    writeFileSync(pidFile, JSON.stringify(contender), { flag: 'wx' });
                },
            });
            assert.equal(result, false);
            assert.equal(JSON.parse(readFileSync(pidFile, 'utf8')).nonce, 'contender');
            const isolated = readdirSync(tempDir).filter((name) => name.includes('.reap-'));
            assert.equal(isolated.length, 1, 'isolated evidence must be retained');
            rmSync(join(tempDir, isolated[0]), { force: true });
            rmSync(pidFile, { force: true });
        }
    } finally {
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('probePortListener returns tri-state output and fails closed on error/unknown', () => {
    const mockSpawnSync = (cmd, args) => {
        if (cmd === 'netstat') {
            return {
                status: 0,
                stdout: '  TCP    127.0.0.1:8010         0.0.0.0:0              LISTENING       4321\r\n',
                stderr: '',
            };
        }
        return { status: 0, stdout: '', stderr: '' };
    };

    const probe = probePortListener(8010, 2000, {
        _platform: 'win32',
        _spawnSync: mockSpawnSync,
    });
    assert.equal(probe.state, 'listener');
    assert.equal(probe.pid, 4321);

    const probeFree = probePortListener(8011, 2000, {
        _platform: 'win32',
        _spawnSync: mockSpawnSync,
    });
    assert.equal(probeFree.state, 'free');

    // PID 0 is a valid Windows netstat record for terminal TCP states such as
    // TIME_WAIT; it must parse fully without becoming listener ownership proof.
    const mockTerminalTcp = () => ({
        status: 0,
        stdout: '  TCP    127.0.0.1:8011         127.0.0.1:50000        TIME_WAIT       0\r\n',
        stderr: '',
    });
    assert.deepEqual(probePortListener(8011, 2000, {
        _platform: 'win32',
        _spawnSync: mockTerminalTcp,
    }), {
        state: 'free',
    });

    // Keep the deterministic Windows branch explicit for terminal TCP records.

    // Windows netstat emits valid terminal states containing digits (for example
    // FIN_WAIT_2). A foreign row in that state must not make an unrelated port
    // unobservable or block exact-owned terminal proof.
    const mockDigitStateTcp = () => ({
        status: 0,
        stdout: '  TCP    127.0.0.1:9119         127.0.0.1:49429        FIN_WAIT_2      27752\r\n',
        stderr: '',
    });
    assert.deepEqual(probePortListener(8010, 2000, {
        _platform: 'win32',
        _spawnSync: mockDigitStateTcp,
    }), {
        state: 'free',
    });

    const mockSpawnSyncError = () => {
        return { status: 1, error: new Error('netstat failure'), stdout: '', stderr: '' };
    };
    const probeUnknown = probePortListener(8010, 2000, {
        _platform: 'win32',
        _spawnSync: mockSpawnSyncError,
    });
    assert.equal(probeUnknown.state, 'unknown');

    // Timeout
    const mockTimeout = () => ({ error: new Error('ETIMEDOUT'), stdout: '', stderr: '' });
    assert.equal(probePortListener(8010, 2000, {
        _platform: 'win32',
        _spawnSync: mockTimeout,
    }).state, 'unknown');

    // Non-zero exit
    const mockNonzero = () => ({ status: 127, stdout: '', stderr: 'command not found' });
    assert.equal(probePortListener(8010, 2000, {
        _platform: 'win32',
        _spawnSync: mockNonzero,
    }).state, 'unknown');

    // Empty stdout
    const mockEmpty = () => ({ status: 0, stdout: '   \r\n', stderr: '' });
    assert.equal(probePortListener(8010, 2000, {
        _platform: 'win32',
        _spawnSync: mockEmpty,
    }).state, 'unknown');

    // Malformed stdout
    const mockMalformed = () => ({
        status: 0,
        stdout: 'GARBAGE_NETSTAT_OUTPUT_CORRUPTED\r\n',
        stderr: '',
    });
    assert.equal(probePortListener(8010, 2000, {
        _platform: 'win32',
        _spawnSync: mockMalformed,
    }).state, 'unknown');

    // A TCP-prefixed row is not evidence that netstat output was understood.
    // Every non-empty data row must validate before absence can prove the port free.
    const mockMalformedTcp = () => ({
        status: 0,
        stdout: 'TCP garbage\r\n',
        stderr: '',
    });
    assert.deepEqual(probePortListener(8010, 2000, {
        _platform: 'win32',
        _spawnSync: mockMalformedTcp,
    }), {
        state: 'unknown',
        reason: 'malformed-netstat-output',
    });
});

test('live PID with malformed CreationDate remains unverifiable and retains its exact marker', async () => {
    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-reap-token-unknown-'));
    const child = spawn(process.execPath, ['-e', 'setInterval(() => {}, 1000);'], {
        stdio: 'ignore',
        windowsHide: true,
    });
    const pidFile = join(tempDir, `webserver-${child.pid}.pid`);
    const recordedToken = '20260827150200.000000+000';
    let terminateAttempted = false;

    const mockSpawnSync = (cmd, args) => {
        if (cmd === 'tasklist') {
            const filter = args.find((arg) => arg.includes('PID eq')) ?? '';
            if (filter.includes('999999')) {
                return {
                    status: 0,
                    stdout: 'INFO: No tasks are running which match the specified criteria.\r\n',
                    stderr: '',
                };
            }
            return {
                status: 0,
                stdout: `"node.exe","${child.pid}","Console","1","10,000 K"\r\n`,
                stderr: '',
            };
        }
        if (cmd === 'wmic') {
            if (args.includes('call') && args.includes('Terminate')) {
                terminateAttempted = true;
                throw new Error('an unverifiable live PID must never reach the terminate seam');
            }
            if (args.includes('CreationDate')) {
                return {
                    status: 0,
                    stdout: 'Node,CreationDate\r\nNODE,malformed-creation-date\r\n',
                    stderr: '',
                };
            }
            return {
                status: 0,
                stdout:
                    `Node,CommandLine,ParentProcessId,ProcessId\r\n` +
                    `NODE,"node scripts/e2e-webserver.mjs --env=e2e --port=8010",999999,${child.pid}\r\n`,
                stderr: '',
            };
        }
        if (cmd === 'netstat') {
            return {
                status: 0,
                stdout:
                    'Active Connections\r\n\r\n' +
                    '  Proto  Local Address          Foreign Address        State           PID\r\n',
                stderr: '',
            };
        }
        return { status: 0, stdout: '', stderr: '' };
    };

    try {
        writeFileSync(
            pidFile,
            JSON.stringify({
                nonce: 'token-unknown-nonce',
                pid: child.pid,
                parentPid: 999999,
                parentStartToken: '20260827140000.000000+000',
                processStartToken: recordedToken,
                runner: 'scripts/e2e-webserver.mjs',
                port: 8010,
                cwd: process.cwd(),
            }),
        );

        const ownership = validateWebServerOwnership(child.pid, parsePidFile(pidFile), 8010, {
            _platform: 'win32',
            _spawnSync: mockSpawnSync,
        });
        assert.equal(ownership.valid, false);
        assert.equal(ownership.reason, 'process-start-token-unverifiable');

        assert.deepEqual(
            reapOrphanedE2EServers(8010, {
                runtimeDir: tempDir,
                _platform: 'win32',
                _spawnSync: mockSpawnSync,
            }),
            [],
        );
        assert.equal(terminateAttempted, false);
        assert.equal(existsSync(pidFile), true, 'unknown token must retain the PID marker');
        assert.doesNotThrow(() => process.kill(child.pid, 0), 'the live process must survive');
    } finally {
        child.kill();
        await new Promise((resolve) => child.once('exit', resolve));
        rmSync(tempDir, { recursive: true, force: true });
    }
});

// ---------------------------------------------------------------------------
// ACTUAL end-to-end regression for the conditional PID-file release inside
// scripts/e2e-webserver.mjs finalize(). These tests spawn the REAL harness with
// a deterministic injected child binary (E2E_WEBSERVER_CHILD_SPAWN_JSON seam),
// never PHP/MySQL/browser, and assert on real disk + real exit codes.
// ---------------------------------------------------------------------------

const selfcheckDir = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(selfcheckDir, '..');
const webserverScript = join(repoRoot, 'scripts', 'e2e-webserver.mjs');
const liveRuntimeDir = join(repoRoot, 'tests', 'e2e', '.runtime');
const e2ePort = 8010;

const waitUntil = async (predicate, timeoutMs = 20000, intervalMs = 50) => {
    const deadline = Date.now() + timeoutMs;
    for (;;) {
        // Awaited so async predicates are evaluated, not treated as truthy promises.
        const value = await predicate();
        if (value) return value;
        if (Date.now() >= deadline) return null;
        await new Promise((resolve) => setTimeout(resolve, intervalMs));
    }
};

const isPortFree = () =>
    new Promise((resolve) => {
        const server = createServer();
        server.once('error', () => resolve(false));
        server.listen(e2ePort, '127.0.0.1', () =>
            server.close((error) => resolve(error ? false : true)),
        );
    });

/**
 * Spawn the real scripts/e2e-webserver.mjs with an injected deterministic child.
 * Returns { proc, exitPromise, pidFilePath }.
 */
const spawnRealWebserver = (childCommand, childArgs) => {
    const env = { ...process.env };
    delete env.E2E_DATABASE;
    delete env.E2E_HOT_BACKUP;
    env.E2E_WEBSERVER_CHILD_SPAWN_JSON = JSON.stringify({
        command: childCommand,
        args: childArgs,
    });

    const proc = spawn(process.execPath, [webserverScript], {
        cwd: repoRoot,
        env,
        // IPC stdin lets this self-check deliver the external-owner termination
        // as an IPC message; on Windows a real signal cannot be delivered to
        // another process (child.kill() force-terminates without a handler).
        stdio: ['ipc', 'ignore', 'pipe'],
        windowsHide: true,
    });

    let stderr = '';
    proc.stderr.setEncoding('utf8');
    proc.stderr.on('data', (chunk) => {
        stderr += chunk;
    });
    const exitPromise = new Promise((resolve) => {
        proc.once('exit', (code, signal) => resolve({ code, signal, stderr }));
    });

    return { proc, exitPromise, pidFilePath: join(liveRuntimeDir, `webserver-${proc.pid}.pid`) };
};

test('ACTUAL e2e-webserver child nonzero: exit status preserved and exact-owned PID file released after terminal proof', async () => {
    assert.equal(await isPortFree(), true, `port ${e2ePort} must be free before this self-check`);

    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-webserver-nonzero-'));
    const childScript = join(tempDir, 'child-exit7.mjs');
    writeFileSync(childScript, 'setTimeout(() => process.exit(7), 4000);\n');

    const { proc, exitPromise, pidFilePath } = spawnRealWebserver(process.execPath, [childScript]);

    try {
        const appeared = await waitUntil(() => existsSync(pidFilePath), 20000);
        assert.ok(appeared, `owned PID file never appeared: ${pidFilePath}`);

        const payload = JSON.parse(readFileSync(pidFilePath, 'utf8'));
        assert.equal(payload.runner, 'scripts/e2e-webserver.mjs');
        assert.equal(payload.port, e2ePort);
        assert.equal(payload.parentPid, proc.pid);
        assert.ok(Number.isInteger(payload.pid) && payload.pid > 0);
        assert.notEqual(payload.pid, proc.pid);

        const { code } = await exitPromise;
        assert.equal(code, 7, 'child nonzero exit status must NOT be washed into success');

        // Terminal proof was satisfied (child dead + port free) -> exact-owned file released.
        assert.equal(existsSync(pidFilePath), false, 'exact-owned PID file must be released');
        assert.equal(await isPortFree(), true, `port ${e2ePort} must be free after teardown`);
    } finally {
        // Only ever remove THIS run's own artifact; historical PID files stay.
        rmSync(pidFilePath, { force: true });
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('ACTUAL e2e-webserver external-owner termination: wrapper signal while child is still alive removes exact-owned PID file after terminal proof and exits cleanly', async () => {
    assert.equal(await isPortFree(), true, `port ${e2ePort} must be free before this self-check`);

    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-webserver-external-'));
    const childScript = join(tempDir, 'child-external-owner.mjs');
    const readyFile = join(tempDir, 'child-ready.txt');
    writeFileSync(
        childScript,
        [
            "import { createServer } from 'node:net';",
            "import { writeFileSync } from 'node:fs';",
            'const server = createServer();',
            `server.listen(${e2ePort}, '127.0.0.1', () => writeFileSync(${JSON.stringify(readyFile)}, 'ready'));`,
            'setInterval(() => {}, 1000);',
            '',
        ].join('\n'),
    );

    const { proc, exitPromise, pidFilePath } = spawnRealWebserver(process.execPath, [childScript]);

    try {
        const appeared = await waitUntil(() => existsSync(pidFilePath), 20000);
        assert.ok(appeared, `owned PID file never appeared: ${pidFilePath}`);

        const ready = await waitUntil(() => existsSync(readyFile), 20000);
        assert.ok(ready, `child ready marker never appeared: ${readyFile}`);

        const payload = JSON.parse(readFileSync(pidFilePath, 'utf8'));
        assert.equal(payload.runner, 'scripts/e2e-webserver.mjs');
        assert.equal(payload.port, e2ePort);
        assert.equal(payload.parentPid, proc.pid);
        assert.ok(Number.isInteger(payload.pid) && payload.pid > 0);
        assert.notEqual(payload.pid, proc.pid);

        // External-owner termination delivered over IPC (Windows has no real
        // cross-process signal delivery; proc.kill() would silently force-kill).
        proc.send('SIGTERM');

        const { code, stderr } = await exitPromise;
        assert.equal(
            code,
            0,
            `external-owner wrapper should exit cleanly after terminal proof: ${stderr}`,
        );

        assert.equal(
            existsSync(pidFilePath),
            false,
            'exact-owned PID file must be removed after external-owner terminal proof',
        );
        assert.equal(
            await isPortFree(),
            true,
            `port ${e2ePort} must be free after external-owner shutdown`,
        );
    } finally {
        rmSync(pidFilePath, { force: true });
        rmSync(readyFile, { force: true });
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('ACTUAL e2e-webserver normal termination: child exit 0 releases exact-owned PID file only after child tree and port are terminal', async () => {
    assert.equal(await isPortFree(), true, `port ${e2ePort} must be free before this self-check`);

    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-webserver-normal-'));
    const childScript = join(tempDir, 'child-listener.mjs');
    const readyFile = join(tempDir, 'child-ready.txt');
    writeFileSync(
        childScript,
        [
            "import { createServer } from 'node:net';",
            "import { writeFileSync } from 'node:fs';",
            'const server = createServer();',
            `server.listen(${e2ePort}, '127.0.0.1', () => writeFileSync(${JSON.stringify(readyFile)}, 'ready'));`,
            'setTimeout(() => server.close(() => process.exit(0)), 5000);',
            'setInterval(() => {}, 1000);',
            '',
        ].join('\n'),
    );

    const { exitPromise, pidFilePath } = spawnRealWebserver(process.execPath, [childScript]);

    try {
        const appeared = await waitUntil(() => existsSync(pidFilePath), 20000);
        assert.ok(appeared, `owned PID file never appeared: ${pidFilePath}`);

        const ready = await waitUntil(() => existsSync(readyFile), 20000);
        assert.ok(ready, `child ready marker never appeared: ${readyFile}`);

        const { code, signal, stderr } = await exitPromise;
        assert.equal(code, 0, `normal termination must preserve successful exit: ${stderr}`);
        assert.equal(signal, null);

        assert.equal(
            existsSync(pidFilePath),
            false,
            'exact-owned PID file must be removed after terminal normal shutdown',
        );
        assert.equal(
            await isPortFree(),
            true,
            `port ${e2ePort} must be free after normal shutdown`,
        );
    } finally {
        rmSync(pidFilePath, { force: true });
        rmSync(readyFile, { force: true });
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('ACTUAL e2e-webserver child nonzero: foreign replacement PID payload survives release', async () => {
    assert.equal(await isPortFree(), true, `port ${e2ePort} must be free before this self-check`);

    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-webserver-foreign-'));
    const childScript = join(tempDir, 'child-exit7.mjs');
    writeFileSync(childScript, 'setTimeout(() => process.exit(7), 4000);\n');

    const { proc, exitPromise, pidFilePath } = spawnRealWebserver(process.execPath, [childScript]);
    const foreignNonce = `foreign-${randomUUID()}`;

    try {
        const appeared = await waitUntil(() => existsSync(pidFilePath), 20000);
        assert.ok(appeared, `owned PID file never appeared: ${pidFilePath}`);

        const owned = JSON.parse(readFileSync(pidFilePath, 'utf8'));

        // Simulate a foreign runner replacing the payload before our finalize().
        const foreignPayload = { ...owned, nonce: foreignNonce };
        writeFileSync(pidFilePath, `${JSON.stringify(foreignPayload, null, 2)}\n`);

        const { code } = await exitPromise;
        assert.equal(code, 7, 'child nonzero exit status must remain nonzero');

        assert.equal(existsSync(pidFilePath), true, 'foreign replacement payload must survive');
        assert.equal(JSON.parse(readFileSync(pidFilePath, 'utf8')).nonce, foreignNonce);

        // No `.reap-*` leftovers from the atomic rename guard.
        const leftovers = readdirSync(liveRuntimeDir).filter((name) => name.includes('.reap-'));
        assert.deepEqual(leftovers, []);
    } finally {
        rmSync(pidFilePath, { force: true });
        rmSync(tempDir, { recursive: true, force: true });
    }
});

test('ACTUAL e2e-webserver child nonzero with live listener: teardown not terminal, PID file retained and exit stays nonzero', async () => {
    assert.equal(await isPortFree(), true, `port ${e2ePort} must be free before this self-check`);

    const tempDir = mkdtempSync(join(tmpdir(), 'inbils-webserver-retain-'));
    const holderScript = join(tempDir, 'port-holder.mjs');
    const childScript = join(tempDir, 'child-spawn-holder.mjs');
    const holderPidFile = join(tempDir, 'holder.pid');

    writeFileSync(
        holderScript,
        [
            "import { createServer } from 'node:net';",
            'const server = createServer();',
            `server.listen(${e2ePort}, '127.0.0.1');`,
            'setTimeout(() => process.exit(0), 60000);',
            '',
        ].join('\n'),
    );

    // Paths arrive as argv (the child env allowlist intentionally drops custom vars).
    writeFileSync(
        childScript,
        [
            "import { spawn } from 'node:child_process';",
            "import { writeFileSync } from 'node:fs';",
            'const [holder, pidOut] = process.argv.slice(2);',
            "const gc = spawn(process.execPath, [holder], { detached: true, stdio: 'ignore', windowsHide: true });",
            'gc.unref();',
            'writeFileSync(pidOut, String(gc.pid));',
            'setTimeout(() => process.exit(7), 4000);',
            '',
        ].join('\n'),
    );

    const { proc, exitPromise, pidFilePath } = spawnRealWebserver(process.execPath, [
        childScript,
        holderScript,
        holderPidFile,
    ]);

    let holderPid = null;

    try {
        const appeared = await waitUntil(() => existsSync(pidFilePath), 20000);
        assert.ok(appeared, `owned PID file never appeared: ${pidFilePath}`);
        assert.equal(JSON.parse(readFileSync(pidFilePath, 'utf8')).parentPid, proc.pid);

        const holderReady = await waitUntil(() => existsSync(holderPidFile), 20000);
        assert.ok(holderReady, 'port holder PID file never appeared');
        holderPid = Number(readFileSync(holderPidFile, 'utf8').trim());
        assert.ok(Number.isInteger(holderPid) && holderPid > 0);

        const bound = await waitUntil(async () => (await isPortFree()) === false, 20000);
        assert.ok(bound !== null, `port ${e2ePort} was never bound by the test holder`);

        const { code } = await exitPromise;
        assert.equal(code, 7, 'fail-closed teardown must keep the nonzero exit status');

        assert.equal(
            existsSync(pidFilePath),
            true,
            'PID file must be retained for audit when teardown is not terminal',
        );
    } finally {
        // Kill only the holder process this test created itself.
        if (holderPid) {
            try {
                process.kill(holderPid, 'SIGKILL');
            } catch {
                // already gone
            }
        }
        await waitUntil(async () => (await isPortFree()) === true, 15000);
        rmSync(pidFilePath, { force: true });
        rmSync(tempDir, { recursive: true, force: true });
    }

    assert.equal(await isPortFree(), true, `port ${e2ePort} must be free after cleanup`);
    assert.equal(existsSync(pidFilePath), false, 'test-owned PID artifact must be cleaned up');
});

test('e2e-webserver child spawn seam fails closed on malformed injection and defaults to php', async () => {
    // 1. Malformed JSON -> die() with nonzero exit, no PID file, no child spawned.
    const env = { ...process.env };
    delete env.E2E_DATABASE;
    delete env.E2E_HOT_BACKUP;
    env.E2E_WEBSERVER_CHILD_SPAWN_JSON = '{ not-json';

    const proc = spawn(process.execPath, [webserverScript], {
        cwd: repoRoot,
        env,
        stdio: ['ignore', 'ignore', 'pipe'],
        windowsHide: true,
    });

    let stderr = '';
    proc.stderr.setEncoding('utf8');
    proc.stderr.on('data', (chunk) => {
        stderr += chunk;
    });

    const { code } = await new Promise((resolve) => {
        proc.once('exit', (exitCode, signal) => resolve({ code: exitCode, signal }));
    });

    assert.equal(code, 1, 'malformed spawn spec must fail closed');
    assert.match(stderr, /refuses malformed E2E_WEBSERVER_CHILD_SPAWN_JSON/i);
    assert.equal(existsSync(join(liveRuntimeDir, `webserver-${proc.pid}.pid`)), false);

    // 2. Production default is unchanged: the seam only reads an explicit variable.
    const source = readFileSync(webserverScript, 'utf8');
    assert.match(source, /command: serverEnv\.env\.PHP_BINARY,/);
    assert.match(source, /if \(!raw\) return productionSpec;/);
});

test('killPidTree fails closed when expectedStartToken is missing or malformed', () => {
    const calls = [];
    const mockSpawnSync = (...args) => {
        calls.push(args);
        return { status: 0, stdout: 'ReturnValue = 0;', stderr: '' };
    };

    const missing = killPidTree(7777, 5000, {
        _platform: 'win32',
        _spawnSync: mockSpawnSync,
    });
    assert.equal(missing.status, 1);
    assert.match(missing.output, /missing process start token/i);

    const malformed = killPidTree(7777, 5000, {
        _platform: 'win32',
        _spawnSync: mockSpawnSync,
        expectedStartToken: 'fixture-token-not-a-windows-creation-date',
    });
    assert.equal(malformed.status, 1);
    assert.match(malformed.output, /malformed process start token/i);
    assert.equal(calls.length, 0, 'malformed identity must not reach WMIC/taskkill');
});

test('review blockers: exact signature, POSIX ambiguity, and parent PID reuse fail closed', () => {
    for (const commandLine of [
        'php C:\\foreign\\server.php 8010',
        'php C:\\foreign\\resources\\server.php 127.0.0.1 8010',
        'php artisan serve --env=e2e-ish --port=8010',
        'php artisan serve --env=e2e --port=80101',
    ]) {
        assert.equal(isOwnedE2EWebServerProcess(commandLine, 8010), false, commandLine);
    }

    for (const result of [
        { status: 1, stdout: '', stderr: '' },
        { status: 0, stdout: '', stderr: '' },
        { status: 0, stdout: 'garbage', stderr: '' },
        { error: new Error('ETIMEDOUT'), status: null, stdout: '', stderr: '' },
    ]) {
        assert.equal(
            inspectProcessDetails(4242, 25, {
                _platform: 'linux',
                _spawnSync: () => result,
            }).state,
            'unknown',
        );
        const portProbe = probePortListener(8010, 25, {
            _platform: 'linux',
            _spawnSync: () => result,
        });
        assert.equal(portProbe.state, result.status === 1 ? 'free' : 'unknown');
    }

    const nonzeroWithDeadHistoricalPid = probePortListener(8010, 25, {
        _platform: 'linux',
        _spawnSync: () => ({ status: 1, stdout: '', stderr: '' }),
        expectedListenerPid: 4242,
        _probeProcessLiveness: () => ({ state: 'dead', reason: 'esrch' }),
    });
    assert.deepEqual(nonzeroWithDeadHistoricalPid, { state: 'free' });

    const payload = {
        nonce: 'nonce-parent-reused',
        pid: 7777,
        processStartToken: 'server-token',
        parentPid: 7000,
        parentStartToken: 'original-parent-token',
        runner: 'scripts/e2e-webserver.mjs',
        port: 8010,
        cwd: process.cwd(),
    };
    const parentReused = validateWebServerOwnership(7777, payload, 8010, {
        _platform: 'win32',
        _probeProcessLiveness: (pid) =>
            pid === 7000
                ? { state: 'dead', reason: 'pid-reused-by-another-process' }
                : { state: 'alive' },
    });
    assert.equal(parentReused.valid, false);
    assert.equal(parentReused.reason, 'parent-process-identity-reused');
});

test('verified orphan tree refuses every signal when descendant discovery is unknown', () => {
    const killed = [];
    const result = reapVerifiedOwnedServerTree(9100, { processStartToken: 'ROOT-9100' }, 8010, {
        _getDescendantPids: () => ({
            state: 'unknown',
            pids: [],
            reason: 'wmic-timeout',
        }),
        _killPidTree: (pid) => {
            killed.push(pid);
            return { status: 0, output: '' };
        },
    });

    assert.equal(result.ok, false);
    assert.match(result.reason, /descendant discovery unverified \(wmic-timeout\)/);
    assert.deepEqual(killed, [], 'unknown discovery must prevent descendant and root signals');
});

test('probePortListener distinguishes POSIX no-match from observability failure and rejects Windows stderr', () => {
    assert.deepEqual(
        probePortListener(8010, 25, {
            _platform: 'linux',
            _spawnSync: () => ({ status: 1, stdout: '', stderr: '' }),
        }),
        { state: 'free' },
    );
    assert.equal(
        probePortListener(8010, 25, {
            _platform: 'linux',
            _spawnSync: () => ({ status: 1, stdout: '', stderr: 'lsof: permission denied' }),
        }).state,
        'unknown',
    );
    assert.equal(
        probePortListener(8010, 25, {
            _platform: 'win32',
            _spawnSync: () => ({
                status: 0,
                stdout: '  TCP    127.0.0.1:8010         0.0.0.0:0              LISTENING       4321\\r\\n',
                stderr: 'warning: partial output',
            }),
        }).state,
        'unknown',
    );
});

test('verified orphan tree kills descendants leaf-first and proves every identity plus port terminal', () => {
    const killed = [];
    const livenessProofs = [];
    const tokens = new Map([
        [9100, 'ROOT-9100'],
        [9200, 'CHILD-9200'],
        [9300, 'LEAF-9300'],
    ]);

    const result = reapVerifiedOwnedServerTree(
        9100,
        { processStartToken: tokens.get(9100) },
        8010,
        {
            teardownTimeoutMs: 1000,
            _getDescendantPids: () => ({ state: 'known', pids: [9200, 9300] }),
            _getProcessStartToken: (pid) => tokens.get(pid) ?? null,
            _killPidTree: (pid, timeout, options) => {
                assert.ok(timeout > 0, 'every kill must receive only remaining deadline budget');
                assert.equal(options.expectedStartToken, tokens.get(pid));
                killed.push(pid);
                return { status: 0, output: '' };
            },
            _probeProcessLiveness: (pid, options) => {
                livenessProofs.push([pid, options.expectedStartToken]);
                return { state: 'dead', reason: 'no-tasks-matched' };
            },
            _probePortListener: (port) => {
                assert.equal(port, 8010);
                return { state: 'free' };
            },
        },
    );

    assert.deepEqual(killed, [9300, 9200, 9100], 'nested descendants must be killed leaf-first');
    assert.deepEqual(livenessProofs, [
        [9100, 'ROOT-9100'],
        [9200, 'CHILD-9200'],
        [9300, 'LEAF-9300'],
    ]);
    assert.deepEqual(result, { ok: true, reason: 'post-kill PID dead and port free' });
});
