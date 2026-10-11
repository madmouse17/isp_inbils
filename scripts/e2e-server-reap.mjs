import { spawnSync } from 'node:child_process';
import { existsSync, linkSync, readdirSync, readFileSync, renameSync, rmSync } from 'node:fs';
import { randomUUID } from 'node:crypto';

import {
    getDescendantPids,
    getProcessStartToken,
    normalizeStrictProcessStartTokenForComparison,
    probeProcessLiveness,
} from './e2e-mutex.mjs';

const withRuntimePlatform = (options = {}) => ({
    ...options,
    _platform: options._platform ?? process.platform,
});

const isWindowsPlatform = (options = {}) =>
    (options._platform ?? process.platform) === 'win32';

export const e2eRuntimeDir = 'tests/e2e/.runtime';

export const e2eServerPidFile = (pid) => `${e2eRuntimeDir}/webserver-${pid}.pid`;

/**
 * Reads stale pid files in runtime directory excluding current process.
 */
export const staleServerPidFiles = (runtimeDir = e2eRuntimeDir) => {
    if (!existsSync(runtimeDir)) return [];

    return readdirSync(runtimeDir)
        .filter((name) => /^webserver-\d+\.pid$/.test(name))
        .filter((name) => Number(name.match(/^webserver-(\d+)\.pid$/)?.[1]) !== process.pid)
        .map((name) => `${runtimeDir}/${name}`);
};

/**
 * Kill process tree with bounded timeout and process start token identity verification (Defect exact 4: closes inspect->kill race).
 */
export const killPidTree = (pid, timeoutMs = 5000, options = {}) => {
    options = withRuntimePlatform(options);
    if (typeof pid !== 'number' || !Number.isInteger(pid) || pid <= 0) {
        return { status: 1, output: 'invalid pid' };
    }
    if (!Number.isFinite(timeoutMs) || timeoutMs <= 0) {
        return { status: 1, output: 'teardown deadline exhausted before kill' };
    }

    const runner = options._spawnSync || options.spawnSync || spawnSync;
    const expectedStartToken =
        typeof options.expectedStartToken === 'string' && options.expectedStartToken.trim()
            ? options.expectedStartToken.trim()
            : null;

    const expectedComparisonToken =
        normalizeStrictProcessStartTokenForComparison(expectedStartToken);

    const useWindows = isWindowsPlatform(options);

    if (useWindows && !expectedComparisonToken) {
        return {
            status: 1,
            output: expectedStartToken
                ? 'Refusing unverified kill: malformed process start token'
                : 'Refusing unverified kill: missing process start token',
        };
    }

    if (useWindows) {
        // Prefer WMIC on older hosts. Current Windows installations may not
        // ship it, so use the supported CIM PowerShell API only when missing.
        let result = runner(
            'wmic',
            [
                'process',
                'where',
                `ProcessId=${pid} and CreationDate="${expectedStartToken}"`,
                'call',
                'Terminate',
            ],
            {
                encoding: 'utf8',
                timeout: timeoutMs,
                windowsHide: true,
            },
        );

        if (result.error?.code === 'ENOENT') {
            result = runner(
                'powershell.exe',
                [
                    '-NoProfile',
                    '-NonInteractive',
                    '-ExecutionPolicy',
                    'Bypass',
                    '-Command',
                    `$process = Get-CimInstance -ClassName Win32_Process -Filter "ProcessId = ${pid}"; $actualStartToken = if ($null -ne $process) { $process.CreationDate.ToUniversalTime().ToString("yyyyMMddHHmmss.ffffff+000") } else { $null }; if ($null -eq $process -or $actualStartToken.Substring(0, 18) -ne "${expectedComparisonToken.slice(0, 18)}") { exit 2 }; $result = Invoke-CimMethod -InputObject $process -MethodName Terminate; if ($result.ReturnValue -eq 0) { Write-Output "ReturnValue = 0;" } else { exit ([int]$result.ReturnValue) }`,
                ],
                {
                    encoding: 'utf8',
                    timeout: timeoutMs,
                    windowsHide: true,
                },
            );
        }

        const stdout = result.stdout ?? '';
        const stderr = result.stderr ?? '';
        const output = `${stdout}${stderr}`;

        if (result.error || result.status !== 0) {
            return { status: result.status ?? 1, output };
        }

        // If the token mismatched (process replaced / PID reused), WMIC outputs "No Instance(s) Available."
        if (stdout.includes('No Instance(s) Available.')) {
            return {
                status: 1,
                output: 'Process identity mismatch: WMIC found no matching instance with expected CreationDate token',
            };
        }

        if (stdout.includes('ReturnValue = 0;')) {
            return { status: 0, output };
        }

        return { status: 1, output };
    }

    // POSIX fallback: identity-safe ownership is mandatory. POSIX exposes no
    // immutable process-start token, so a bare/historical registry PID must
    // never be signaled. Only a caller that has independently proven ownership
    // (options.identitySafe) may terminate; otherwise fail-closed.
    if (!options.identitySafe) {
        return {
            status: 1,
            output: 'Refusing unverified kill: identity-safe ownership not proven',
        };
    }

    try {
        process.kill(pid, 'SIGKILL');
        return { status: 0, output: '' };
    } catch (err) {
        return { status: 1, output: err.message };
    }
};

/**
 * Inspect process details with bounded timeout and tri-state result.
 * Returns:
 * - { state: 'alive', commandLine: string, imageName?: string, parentPid?: number, pid: number }
 * - { state: 'dead' }
 * - { state: 'unknown', reason: string }
 */
export const inspectProcessDetails = (pid, timeoutMs = 5000, options = {}) => {
    options = withRuntimePlatform(options);
    if (typeof pid !== 'number' || !Number.isInteger(pid) || pid <= 0) {
        return { state: 'dead', reason: 'invalid-pid' };
    }

    const runner = options._spawnSync || options.spawnSync || spawnSync;
    const useWindows = isWindowsPlatform(options);

    if (useWindows) {
        try {
            const result = runner(
                'wmic',
                [
                    'process',
                    'where',
                    `ProcessId=${pid}`,
                    'get',
                    'CommandLine,ParentProcessId,ProcessId',
                    '/format:csv',
                ],
                {
                    encoding: 'utf8',
                    timeout: timeoutMs,
                    windowsHide: true,
                },
            );

            if (result.error?.code === 'ENOENT') {
                const fallback = runner(
                    'powershell.exe',
                    [
                        '-NoProfile',
                        '-NonInteractive',
                        '-ExecutionPolicy',
                        'Bypass',
                        '-Command',
                        `$process = Get-CimInstance -ClassName Win32_Process -Filter "ProcessId = ${pid}"; if ($null -ne $process) { $process | Select-Object ProcessId,ParentProcessId,CommandLine | ConvertTo-Json -Compress }`,
                    ],
                    { encoding: 'utf8', timeout: timeoutMs, windowsHide: true },
                );
                if (fallback.error) {
                    return {
                        state: 'unknown',
                        reason: `powershell-error: ${fallback.error.message}`,
                    };
                }
                if (fallback.status !== 0 || String(fallback.stderr ?? '').trim()) {
                    return {
                        state: 'unknown',
                        reason: `powershell-nonzero-exit: ${fallback.status}`,
                    };
                }
                const raw = String(fallback.stdout ?? '').trim();
                if (!raw) return { state: 'dead', reason: 'powershell-no-instance' };
                let parsed;
                try {
                    parsed = JSON.parse(raw);
                } catch {
                    return { state: 'unknown', reason: 'malformed-powershell-process-json' };
                }
                if (
                    !parsed ||
                    Number(parsed.ProcessId) !== pid ||
                    !Number.isSafeInteger(Number(parsed.ParentProcessId)) ||
                    typeof parsed.CommandLine !== 'string'
                ) {
                    return { state: 'unknown', reason: 'malformed-powershell-process-identity' };
                }
                return {
                    state: 'alive',
                    commandLine: parsed.CommandLine,
                    parentPid: Number(parsed.ParentProcessId),
                    pid,
                };
            }
            if (result.error) {
                return { state: 'unknown', reason: `wmic-error: ${result.error.message}` };
            }
            if (result.status !== 0) {
                return { state: 'unknown', reason: `wmic-nonzero-exit: ${result.status}` };
            }
            const stderr = (result.stderr ?? '').trim();
            const lines = (result.stdout ?? '')
                .split(/\r?\n/)
                .map((line) => line.trim())
                .filter(Boolean);
            const exactNoInstanceInStdout =
                !stderr &&
                lines.length === 2 &&
                lines[0] === 'No Instance(s) Available.' &&
                lines[1] === 'Node,';
            const exactNoInstanceInStderr =
                stderr === 'No Instance(s) Available.' &&
                lines.length === 1 &&
                lines[0] === 'Node,';
            if (exactNoInstanceInStdout || exactNoInstanceInStderr) {
                return { state: 'dead', reason: 'exact-wmic-no-instance' };
            }
            if (stderr) {
                return { state: 'unknown', reason: `ambiguous-wmic-stderr: ${stderr}` };
            }
            if (lines.length !== 2 || lines[0] !== 'Node,CommandLine,ParentProcessId,ProcessId') {
                return { state: 'unknown', reason: 'malformed-wmic-process-shape' };
            }

            // CSV format: Node,CommandLine,ParentProcessId,ProcessId. Parse from
            // the right because a quoted command line may itself contain commas.
            const rawLine = lines[1];
            const lastComma = rawLine.lastIndexOf(',');
            if (lastComma === -1)
                return { state: 'unknown', reason: `malformed-wmic-csv: ${rawLine}` };
            const procIdStr = rawLine.slice(lastComma + 1).trim();

            const secondLastComma = rawLine.lastIndexOf(',', lastComma - 1);
            if (secondLastComma === -1)
                return { state: 'unknown', reason: `malformed-wmic-csv: ${rawLine}` };
            const parentPidStr = rawLine.slice(secondLastComma + 1, lastComma).trim();

            const firstComma = rawLine.indexOf(',');
            if (
                firstComma === -1 ||
                !rawLine.slice(0, firstComma).trim() ||
                !/^\d+$/.test(procIdStr) ||
                Number(procIdStr) !== pid ||
                (parentPidStr !== '' && !/^\d+$/.test(parentPidStr))
            ) {
                return { state: 'unknown', reason: `malformed-wmic-identity: ${rawLine}` };
            }
            const commandLine = rawLine.slice(firstComma + 1, secondLastComma).trim();

            return {
                state: 'alive',
                commandLine,
                ...(parentPidStr === '' ? {} : { parentPid: Number(parentPidStr) }),
                pid: Number(procIdStr),
            };
        } catch (err) {
            return { state: 'unknown', reason: err.message };
        }
    }

    // POSIX fallback. A non-zero `ps` exit is not proof of death: it can also
    // mean permission failure, timeout, unsupported flags, or transient
    // observability loss. Only a complete, exact row is promoted to alive;
    // every other shape remains unknown/fail-closed.
    try {
        const result = runner(
            'ps',
            ['-p', String(pid), '-o', 'pid=', '-o', 'ppid=', '-o', 'args='],
            {
                encoding: 'utf8',
                timeout: timeoutMs,
            },
        );
        if (result.error) return { state: 'unknown', reason: `ps-error: ${result.error.message}` };
        if (result.status !== 0) {
            return { state: 'unknown', reason: `ps-nonzero-exit: ${result.status}` };
        }
        if (String(result.stderr ?? '').trim()) {
            return { state: 'unknown', reason: 'ambiguous-ps-stderr' };
        }
        const lines = String(result.stdout ?? '')
            .split(/\r?\n/)
            .map((line) => line.trim())
            .filter(Boolean);
        if (lines.length !== 1) return { state: 'unknown', reason: 'malformed-ps-shape' };
        const match = /^(\d+)\s+(\d+)\s+(.+)$/.exec(lines[0]);
        if (!match || Number(match[1]) !== pid || !Number.isSafeInteger(Number(match[2]))) {
            return { state: 'unknown', reason: 'malformed-ps-row' };
        }
        return {
            state: 'alive',
            commandLine: match[3],
            parentPid: Number(match[2]),
            pid,
        };
    } catch (err) {
        return { state: 'unknown', reason: `ps-spawn-error: ${err.message}` };
    }
};

/**
 * Audit-safe check if a process command line matches our owned Laravel E2E webserver.
 * Must match:
 * 1. `artisan serve` or `server.php` with `--env=e2e` or port specification
 * 2. Must NOT match arbitrary user PHP servers (e.g. `php -S`, `php worker`, foreign apps)
 */
export const isOwnedE2EWebServerProcess = (commandLine, port) => {
    if (
        typeof commandLine !== 'string' ||
        !commandLine.trim() ||
        typeof port !== 'number' ||
        !Number.isInteger(port) ||
        port <= 0
    ) {
        return false;
    }

    const normalized = commandLine.replace(/\\/g, '/').replace(/^\s*["']|["']\s*$/g, '');
    const escapedPort = String(port).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const exactPortOption = new RegExp(`(?:^|\\s)--port(?:=|\\s+)${escapedPort}(?=\\s|$)`, 'i');
    const exactEnvOption = /(?:^|\s)--env(?:=|\s+)e2e(?=\s|$)/i;
    const exactArtisanServe =
        /(?:^|[\s"'])artisan(?:["']|\s)+serve(?=\s|$)/i.test(normalized) &&
        exactEnvOption.test(normalized) &&
        exactPortOption.test(normalized);

    // Laravel's `artisan serve` listener command points at this exact framework
    // resource path. Reject generic/foreign `server.php` paths even when they
    // happen to mention the owned port.
    const exactFrameworkServer =
        /(?:^|[\s"'])(?:[^\s"']*\/)?vendor\/laravel\/framework\/src\/Illuminate\/Foundation\/Console\/\.\.\/resources\/server\.php(?=["']|\s|$)/i.test(
            normalized,
        );
    const exactListenerArgs = new RegExp(
        `(?:^|\\s)(?:127\\.0\\.0\\.1|localhost)(?:\\s+|:)${escapedPort}(?=\\s|$)`,
        'i',
    ).test(normalized);

    return exactArtisanServe || (exactFrameworkServer && exactListenerArgs);
};

/**
 * Inspect listener PID on port with bounded timeout and tri-state result.
 * Returns:
 * - { state: 'listener', pid: number } when active listener found
 * - { state: 'free' } when port is definitely not in LISTENING state
 * - { state: 'unknown', reason: string } on error/timeout/nonzero/malformed/empty output
 */
export const probePortListener = (port, timeoutMs = 5000, options = {}) => {
    options = withRuntimePlatform(options);
    if (typeof port !== 'number' || !Number.isInteger(port) || port <= 0) {
        return { state: 'unknown', reason: 'invalid-port' };
    }

    const runner = options._spawnSync || options.spawnSync || spawnSync;
    const useWindows = isWindowsPlatform(options);

    if (useWindows) {
        let result;
        try {
            result = runner('netstat', ['-ano'], {
                encoding: 'utf8',
                timeout: timeoutMs,
                windowsHide: true,
            });
        } catch (err) {
            return { state: 'unknown', reason: `spawn-error: ${err.message}` };
        }

        if (result.error) {
            return { state: 'unknown', reason: `netstat-error: ${result.error.message}` };
        }

        if (result.status !== 0) {
            return { state: 'unknown', reason: `netstat-nonzero-exit: ${result.status}` };
        }
        if (String(result.stderr ?? '').trim()) {
            return { state: 'unknown', reason: 'ambiguous-netstat-stderr' };
        }

        const stdout = result.stdout ?? '';
        if (!stdout.trim()) {
            return { state: 'unknown', reason: 'empty-netstat-output' };
        }

        const lines = stdout.split(/\r?\n/);
        let listenerPid = null;
        for (const line of lines) {
            const normalized = line.trim();
            if (
                !normalized ||
                normalized === 'Active Connections' ||
                /^Proto\s+Local Address\s+Foreign Address\s+State\s+PID$/i.test(normalized)
            ) {
                continue;
            }

            const tcpMatch = line.match(
                /^\s*TCP\s+\S+:(\d+)\s+\S+\s+([A-Z][A-Z0-9_]*)\s+(\d+)\s*$/,
            );
            if (tcpMatch) {
                const localPort = Number(tcpMatch[1]);
                const state = tcpMatch[2];
                const pid = Number(tcpMatch[3]);
                if (!Number.isInteger(localPort) || !Number.isInteger(pid) || pid < 0) {
                    return { state: 'unknown', reason: 'malformed-netstat-output' };
                }
                if (state === 'LISTENING' && localPort === port) {
                    if (pid === 0) {
                        return { state: 'unknown', reason: 'invalid-listener-pid' };
                    }
                    listenerPid = pid;
                }
                continue;
            }

            // UDP rows are valid netstat records even though they cannot be the
            // requested TCP listener. Validate their complete shape as well.
            const udpMatch = line.match(/^\s*UDP\s+\S+:(\d+)\s+\S+\s+(\d+)\s*$/);
            if (udpMatch) {
                const localPort = Number(udpMatch[1]);
                const pid = Number(udpMatch[2]);
                if (Number.isInteger(localPort) && Number.isInteger(pid) && pid >= 0) continue;
            }

            return { state: 'unknown', reason: 'malformed-netstat-output' };
        }
        if (listenerPid !== null) return { state: 'listener', pid: listenerPid };
        return { state: 'free' };
    }

    // POSIX fallback. A non-zero/empty result is not promoted to `free`:
    // absent tooling, permissions and timeout are indistinguishable from a true
    // no-match here. Callers that require terminal proof must fail closed.
    try {
        const result = runner('lsof', ['-i', `:${port}`, '-sTCP:LISTEN', '-t'], {
            encoding: 'utf8',
            timeout: timeoutMs,
        });
        if (result.error)
            return { state: 'unknown', reason: `lsof-error: ${result.error.message}` };
        const stderr = String(result.stderr ?? '').trim();
        if (result.status === 1 && !stderr && !String(result.stdout ?? '').trim()) {
            return { state: 'free' };
        }
        if (result.status !== 0) {
            // lsof exit 1 with empty stdout/stderr is its official no-match
            // result. Every other non-zero result remains fail-closed.
            return {
                state: 'unknown',
                reason: `lsof-nonzero-exit: ${result.status}${stderr ? `: ${stderr}` : ''}`,
            };
        }
        if (stderr) {
            return { state: 'unknown', reason: 'ambiguous-lsof-stderr' };
        }
        const lines = String(result.stdout ?? '')
            .split(/\r?\n/)
            .map((line) => line.trim())
            .filter(Boolean);
        if (lines.length !== 1 || !/^\d+$/.test(lines[0])) {
            return { state: 'unknown', reason: 'malformed-lsof-output' };
        }
        const pid = Number(lines[0]);
        if (!Number.isSafeInteger(pid) || pid <= 0) {
            return { state: 'unknown', reason: 'invalid-lsof-listener-pid' };
        }
        return { state: 'listener', pid };
    } catch (err) {
        return { state: 'unknown', reason: `lsof-spawn-error: ${err.message}` };
    }
};

/**
 * Legacy compatibility wrapper.
 * Returns numeric PID only if positive listener found; returns null if free or unknown.
 */
export const listenerPidOnPort = (port, timeoutMs = 5000, options = {}) => {
    options = withRuntimePlatform(options);
    const probe = probePortListener(port, timeoutMs, options);
    return probe.state === 'listener' ? probe.pid : null;
};

/**
 * Safely compare full immutable identity payload before unlinking a PID file.
 * Guards against inspect->kill race where PID file was replaced by another process with the same nonce.
 */
export const isExactPidFileMatch = (currentData, expectedData) => {
    if (!currentData || !expectedData) return false;
    if (typeof currentData !== 'object' || typeof expectedData !== 'object') return false;

    // Compare all immutable identity fields
    if (currentData.nonce !== expectedData.nonce) return false;
    if (currentData.pid !== expectedData.pid) return false;
    if (currentData.runner !== expectedData.runner) return false;
    if (currentData.port !== expectedData.port) return false;
    if (currentData.processStartToken !== expectedData.processStartToken) return false;
    if (currentData.parentPid !== expectedData.parentPid) return false;
    if (currentData.parentStartToken !== expectedData.parentStartToken) return false;
    if (currentData.cwd !== expectedData.cwd) return false;

    return true;
};

/**
 * Atomic rename-based PID file cleanup.
 * Prevents inspect->unlink race by renaming PID file to unique temp file first,
 * verifying the full payload matches expected, unlinking if matched, or restoring if mismatched/replaced.
 * If file is malformed during parse, it is restored to original path.
 */
export const safelyReapPidFile = (pidFilePath, expectedData, options = {}) => {
    if (!existsSync(pidFilePath)) return false;

    const tempReapPath = `${pidFilePath}.reap-${randomUUID()}`;
    try {
        renameSync(pidFilePath, tempReapPath);
    } catch {
        return false;
    }

    const restoreWithoutOverwrite = () => {
        options._beforeRestore?.(tempReapPath, pidFilePath);
        try {
            // Hard-link creation is an atomic no-replace operation on NTFS and
            // POSIX. If a contender recreated the canonical pathname, EEXIST
            // preserves both that contender and the isolated audit evidence.
            linkSync(tempReapPath, pidFilePath);
            rmSync(tempReapPath, { force: true });
            return true;
        } catch {
            return false;
        }
    };

    try {
        const raw = readFileSync(tempReapPath, 'utf8').trim();
        if (!raw) {
            restoreWithoutOverwrite();
            return false;
        }

        let parsed;
        try {
            parsed = JSON.parse(raw);
        } catch {
            restoreWithoutOverwrite();
            return false;
        }

        if (isExactPidFileMatch(parsed, expectedData)) {
            rmSync(tempReapPath, { force: true });
            return true;
        }

        restoreWithoutOverwrite();
        return false;
    } catch {
        if (existsSync(tempReapPath)) restoreWithoutOverwrite();
        return false;
    }
};
export const parsePidFile = (pidFilePath) => {
    try {
        if (!existsSync(pidFilePath)) return null;
        const raw = readFileSync(pidFilePath, 'utf8').trim();
        if (!raw) return null;

        try {
            const parsed = JSON.parse(raw);
            if (typeof parsed === 'object' && parsed !== null && Number.isInteger(parsed.pid)) {
                return parsed;
            }
            if (typeof parsed === 'number' && Number.isInteger(parsed) && parsed > 0) {
                return { pid: parsed };
            }
        } catch {
            // Raw PID fallback
            const numeric = Number(raw);
            if (Number.isInteger(numeric) && numeric > 0) {
                return { pid: numeric };
            }
        }
        return null;
    } catch {
        return null;
    }
};

/**
 * Validate strong ownership proof for an E2E webserver process.
 * Verifies:
 * 1. Payload format & required immutable identity fields (pid, nonce, processStartToken, runner, port, cwd).
 * 2. CWD provenance matches current working directory.
 * 3. Live process liveness state === 'alive'.
 * 4. Live process start token matches payload (PID reuse guard).
 * 5. Command line matches owned E2E server signature.
 */
export const validateWebServerOwnership = (pid, fileData, port, options = {}) => {
    options = withRuntimePlatform(options);
    if (!fileData || typeof fileData !== 'object') {
        return { valid: false, reason: 'missing-or-invalid-file-data' };
    }

    if (
        typeof fileData.pid !== 'number' ||
        fileData.pid !== pid ||
        typeof fileData.nonce !== 'string' ||
        !fileData.nonce.trim() ||
        typeof fileData.processStartToken !== 'string' ||
        !fileData.processStartToken.trim() ||
        typeof fileData.runner !== 'string' ||
        fileData.runner !== 'scripts/e2e-webserver.mjs' ||
        typeof fileData.port !== 'number' ||
        fileData.port !== port ||
        typeof fileData.cwd !== 'string' ||
        !fileData.cwd.trim() ||
        typeof fileData.parentPid !== 'number' ||
        fileData.parentPid <= 0 ||
        typeof fileData.parentStartToken !== 'string' ||
        !fileData.parentStartToken.trim()
    ) {
        return { valid: false, reason: 'unverified-ownership-payload-fields' };
    }

    // Defect exact (3): parentPid & parentStartToken verification
    // Server is ONLY an orphan if its parent runner is proven DEAD with matching parent identity.
    // If parent is live or unknown/unverified, do NOT reap!
    const livenessProbe = options._probeProcessLiveness ?? probeProcessLiveness;
    const parentLiveness = livenessProbe(fileData.parentPid, {
        ...options,
        expectedStartToken: fileData.parentStartToken,
    });

    if (parentLiveness.state === 'alive') {
        return { valid: false, reason: 'parent-process-is-still-alive' };
    }
    if (parentLiveness.state === 'unknown') {
        return {
            valid: false,
            reason: `parent-process-liveness-unknown: ${parentLiveness.reason ?? 'unknown'}`,
        };
    }
    if (parentLiveness.reason === 'pid-reused-by-another-process') {
        // The numeric parent PID is currently owned by a different process. It
        // is not a proven-dead parent and can never authorize orphan adoption.
        return { valid: false, reason: 'parent-process-identity-reused' };
    }

    // Verify cwd matches current working directory
    const normalizedPayloadCwd = fileData.cwd.replace(/[\\/]+/g, '/').toLowerCase();
    const normalizedCurrentCwd = process
        .cwd()
        .replace(/[\\/]+/g, '/')
        .toLowerCase();
    if (normalizedPayloadCwd !== normalizedCurrentCwd) {
        return { valid: false, reason: 'cwd-mismatch' };
    }

    // Verify live process details
    const processDetails = inspectProcessDetails(pid, 5000, options);
    if (processDetails.state !== 'alive') {
        return { valid: false, reason: `process-not-alive: ${processDetails.state}` };
    }

    // Verify live process start token (PID reuse guard)
    const liveStartToken = getProcessStartToken(pid, options);
    if (!liveStartToken) {
        return { valid: false, reason: 'process-start-token-unverifiable' };
    }
    if (liveStartToken !== fileData.processStartToken) {
        return { valid: false, reason: 'process-start-token-mismatch' };
    }

    // Defect exact (parent linkage): the server's ACTUAL live parent must match
    // the parent linkage claimed in the PID file exactly. A forged or stale PID
    // file that names a different (or unknown) parent than the process we just
    // inspected fails closed — never reaped, never adopted.
    const actualParentPid =
        typeof processDetails.parentPid === 'number' && processDetails.parentPid > 0
            ? processDetails.parentPid
            : null;
    if (actualParentPid === null) {
        return { valid: false, reason: 'parent-linkage-unverifiable' };
    }
    if (actualParentPid !== fileData.parentPid) {
        return { valid: false, reason: 'parent-linkage-mismatch' };
    }

    // Verify command line signature
    if (!isOwnedE2EWebServerProcess(processDetails.commandLine, port)) {
        return { valid: false, reason: 'command-line-signature-mismatch' };
    }

    return { valid: true, processDetails };
};

/**
 * Post-kill terminal proof for a reclaimed E2E server. A PID may only be
 * declared reclaimed and its PID file released after the exact PID is proven
 * dead AND the owned port is proven free. Any other state (still alive, liveness
 * or port unverified, listener still bound) fails closed.
 */
export const proveOwnedServerReaped = (pid, port, options = {}) => {
    options = withRuntimePlatform(options);
    const livenessProbe = options._probeProcessLiveness ?? probeProcessLiveness;
    const portListenerProbe = options._probePortListener ?? probePortListener;
    const liveness = livenessProbe(pid, {
        ...options,
        expectedStartToken:
            typeof options.expectedStartToken === 'string' && options.expectedStartToken.trim()
                ? options.expectedStartToken.trim()
                : undefined,
    });

    if (liveness.state === 'alive') {
        return { ok: false, reason: `PID ${pid} still alive post-kill` };
    }
    if (liveness.state !== 'dead') {
        return {
            ok: false,
            reason: `PID ${pid} post-kill liveness unverified (${liveness.reason ?? liveness.state})`,
        };
    }

    for (const descendant of options.descendantIdentities ?? []) {
        const descendantLiveness = livenessProbe(descendant.pid, {
            ...options,
            expectedStartToken: descendant.startToken,
        });
        if (descendantLiveness.state !== 'dead') {
            return {
                ok: false,
                reason:
                    `descendant PID ${descendant.pid} post-kill liveness unverified ` +
                    `(${descendantLiveness.reason ?? descendantLiveness.state})`,
            };
        }
    }

    if (typeof port === 'number' && port > 0) {
        const portProbe = portListenerProbe(port, Math.min(3000, options.timeout ?? 3000), options);
        if (portProbe.state === 'listener') {
            return {
                ok: false,
                reason: `port ${port} still bound post-kill by PID ${portProbe.pid}`,
            };
        }
        if (portProbe.state !== 'free') {
            return {
                ok: false,
                reason: `port ${port} post-kill status unverified (${portProbe.reason})`,
            };
        }
    }

    return { ok: true, reason: 'post-kill PID dead and port free' };
};

export const reapVerifiedOwnedServerTree = (pid, fileData, port, options = {}) => {
    options = withRuntimePlatform(options);
    const deadline = Date.now() + (options.teardownTimeoutMs ?? 5000);
    const remaining = () => Math.max(0, deadline - Date.now());
    const discoverDescendants = options._getDescendantPids ?? getDescendantPids;
    const readStartToken = options._getProcessStartToken ?? getProcessStartToken;
    const killTree = options._killPidTree ?? killPidTree;
    const livenessProbe = options._probeProcessLiveness ?? probeProcessLiveness;

    let discovery;
    try {
        discovery = discoverDescendants(pid, {
            ...options,
            timeout: Math.min(5000, remaining()),
        });
    } catch (error) {
        return { ok: false, reason: `descendant discovery threw: ${error.message}` };
    }

    if (
        !discovery ||
        !['known', 'known-empty'].includes(discovery.state) ||
        !Array.isArray(discovery.pids)
    ) {
        return {
            ok: false,
            reason: `descendant discovery unverified (${discovery?.reason ?? 'malformed result'})`,
        };
    }

    const descendantIdentities = [];
    for (const descendantPid of discovery.pids) {
        const budget = remaining();
        if (budget <= 0) {
            return { ok: false, reason: 'teardown deadline exhausted while binding descendants' };
        }

        const startToken = readStartToken(descendantPid, {
            ...options,
            timeout: Math.min(5000, budget),
        });
        if (!startToken) {
            const liveness = livenessProbe(descendantPid, {
                ...options,
                _platform: options._platform,
                timeout: Math.min(1000, budget),
            });
            if (liveness.state === 'dead') continue;
            return {
                ok: false,
                reason: `descendant PID ${descendantPid} immutable identity unverified`,
            };
        }
        descendantIdentities.push({ pid: descendantPid, startToken });
    }

    for (const descendant of [...descendantIdentities].reverse()) {
        const budget = remaining();
        if (budget <= 0) {
            return { ok: false, reason: 'teardown deadline exhausted before descendant kill' };
        }
        const kill = killTree(descendant.pid, budget, {
            ...options,
            expectedStartToken: descendant.startToken,
        });
        if (kill.status !== 0) {
            const proofBudget = remaining();
            if (proofBudget <= 0) {
                return { ok: false, reason: 'teardown deadline exhausted before liveness proof' };
            }
            const liveness = livenessProbe(descendant.pid, {
                ...options,
                _platform: options._platform,
                timeout: Math.min(1000, proofBudget),
                expectedStartToken: descendant.startToken,
            });
            if (liveness.state !== 'dead') {
                return {
                    ok: false,
                    reason: `identity-safe descendant kill failed for PID ${descendant.pid}`,
                };
            }
        }
    }

    const rootBudget = remaining();
    if (rootBudget <= 0) {
        return { ok: false, reason: 'teardown deadline exhausted before root kill' };
    }
    const rootKill = killTree(pid, rootBudget, {
        ...options,
        expectedStartToken: fileData.processStartToken,
    });
    if (rootKill.status !== 0) {
        return { ok: false, reason: rootKill.output || 'identity-safe root kill failed' };
    }

    const proofBudget = remaining();
    if (proofBudget <= 0) {
        return { ok: false, reason: 'teardown deadline exhausted before terminal proof' };
    }
    return proveOwnedServerReaped(pid, port, {
        ...options,
        timeout: proofBudget,
        expectedStartToken: fileData.processStartToken,
        descendantIdentities,
    });
};

/**
 * Reclaim port `port` from php servers orphaned by externally killed E2E runners.
 *
 * CRITICAL SAFETY RULES (Finding #4 & #5):
 * 1. NO FOREIGN PROCESS REAP: Never kill a process based solely on port/image name or command-line signature.
 * 2. Strong ownership proof: Process MUST have verified PID file containing nonce, processStartToken, cwd, runner, and port.
 * 3. Process start token MUST match live process token (PID reuse / identity mismatch guard).
 * 4. Tri-state and bounded timeout: wmic/tasklist/netstat/taskkill must not hang indefinitely; unknown state fails closed.
 * 5. Stale PID-file cleanup only if process is dead or confirmed orphaned from a dead run.
 * 6. Post-kill exact PID/tree/port proof required before a PID is declared reclaimed or its PID file released.
 *
 * Returns the list of reclaimed pids.
 */
export const reapOrphanedE2EServers = (port, options = {}) => {
    options = withRuntimePlatform(options);
    const reclaimed = [];
    const useWindows = isWindowsPlatform(options);

    if (!useWindows) return reclaimed;

    const runtimeDir = options.runtimeDir || e2eRuntimeDir;

    for (const pidFile of staleServerPidFiles(runtimeDir)) {
        const fileData = parsePidFile(pidFile);

        if (!fileData || !fileData.pid) {
            // Malformed/empty PID file: FAIL-CLOSED. Never kill any process and
            // never delete unconditionally. An untracked PID file is retained for
            // audit; cleanup only happens via isolation + exact immutable match.
            console.error(
                `[e2e-server-reap] Refusing to touch malformed/empty PID file ${pidFile}; leaving for audit.`,
            );
            continue;
        }

        const pid = fileData.pid;
        const processDetails = inspectProcessDetails(pid, 5000, options);

        if (processDetails.state === 'dead') {
            // Owner is dead, clean up stale pid file safely with conditional check
            safelyReapPidFile(pidFile, fileData);
            continue;
        }

        if (processDetails.state === 'unknown') {
            // Observability failed: FAIL-CLOSED! Do not kill process, leave pid file for investigation.
            console.error(
                `[e2e-server-reap] Refusing to touch PID ${pid}: observability probe returned unknown (${processDetails.reason}).`,
            );
            continue;
        }

        if (processDetails.state === 'alive') {
            const ownership = validateWebServerOwnership(pid, fileData, port, options);

            if (ownership.valid) {
                const reaped = reapVerifiedOwnedServerTree(pid, fileData, port, options);
                if (!reaped.ok) {
                    console.error(
                        `[e2e-server-reap] Refusing to reclaim PID ${pid}: ${reaped.reason}. PID file retained.`,
                    );
                } else {
                    reclaimed.push(pid);
                    console.error(
                        `[e2e-server-reap] Reclaimed verified orphaned E2E server PID ${pid} (port ${port}).`,
                    );
                    safelyReapPidFile(pidFile, fileData);
                }
            } else {
                // Process is alive but fails strong ownership proof (foreign mimic, PID reuse, unverified token, different cwd, etc.)
                // FAIL-CLOSED: DO NOT KILL!
                console.error(
                    `[e2e-server-reap] Refusing to kill PID ${pid}: process is alive but fails strong ownership proof (${ownership.reason}).`,
                );
                // Only a positively proven immutable-token mismatch permits stale
                // marker cleanup. Unknown identity and provenance mismatches retain
                // evidence fail-closed.
                if (ownership.reason === 'process-start-token-mismatch') {
                    safelyReapPidFile(pidFile, fileData);
                }
            }
            continue;
        }
    }

    // Fallback: check current listener on port, but ONLY kill if verified owned E2E server with valid PID file!
    const listener = listenerPidOnPort(port, 5000, options);

    if (listener && !reclaimed.includes(listener)) {
        const listenerPidFile = `${runtimeDir}/webserver-${listener}.pid`;
        const fileData = parsePidFile(listenerPidFile);

        if (!fileData) {
            // Listener has NO valid ownership PID file: FAIL-CLOSED! Never kill foreign process/listener!
            console.error(
                `[e2e-server-reap] Port ${port} listener PID ${listener} has no verified ownership PID file. Refusing to kill foreign/unowned process.`,
            );
            return reclaimed;
        }

        const ownership = validateWebServerOwnership(listener, fileData, port, options);

        if (ownership.valid) {
            const reaped = reapVerifiedOwnedServerTree(listener, fileData, port, options);
            if (!reaped.ok) {
                console.error(
                    `[e2e-server-reap] Refusing to reclaim port listener PID ${listener}: ${reaped.reason}. PID file retained.`,
                );
            } else {
                reclaimed.push(listener);
                console.error(
                    `[e2e-server-reap] Reclaimed orphaned verified E2E port ${port} listener PID ${listener}.`,
                );
                safelyReapPidFile(listenerPidFile, fileData);
            }
        } else {
            console.error(
                `[e2e-server-reap] Port ${port} listener PID ${listener} fails strong ownership proof (${ownership.reason}). Refusing to kill process.`,
            );
            // Only a positively proven immutable-token mismatch permits stale
            // marker cleanup; unknown identity retains evidence fail-closed.
            if (ownership.reason === 'process-start-token-mismatch') {
                safelyReapPidFile(listenerPidFile, fileData);
            }
        }
    }

    return reclaimed;
};
