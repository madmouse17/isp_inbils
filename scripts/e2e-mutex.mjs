import { spawnSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import {
    closeSync,
    existsSync,
    fstatSync,
    fsyncSync,
    ftruncateSync,
    mkdirSync,
    openSync,
    readFileSync,
    readSync,
    renameSync,
    rmSync,
    statSync,
    unlinkSync,
    writeSync,
    writeFileSync,
} from 'node:fs';
import { dirname } from 'node:path';

import { listenerPidOnPort, probePortListener } from './e2e-server-reap.mjs';

export const defaultLockPath = 'tests/e2e/.runtime/canonical-e2e.lock';

const withRuntimePlatform = (options = {}) => ({
    ...options,
    _platform: options._platform ?? process.platform,
});

const isWindowsPlatform = (options = {}) =>
    (options._platform ?? process.platform) === 'win32';

const WINDOWS_PROCESS_TABLE_TOKEN_RE = /^\d{14}\.\d{6}[+-]\d{3}$/;

function normalizeWindowsProcessToken(value) {
    if (typeof value !== 'string' || !value.trim()) return null;
    const raw = value
        .trim()
        .replace(/^\/Date\(/, '')
        .replace(/\)\/$/, '');
    if (WINDOWS_PROCESS_TABLE_TOKEN_RE.test(raw)) return raw;
    const parsed = /^-?\d+$/.test(raw) ? Number(raw) : Date.parse(raw);
    if (!Number.isFinite(parsed)) return null;
    const date = new Date(parsed);
    if (!Number.isFinite(date.getTime())) return null;
    const pad = (number, width) => String(number).padStart(width, '0');
    return `${date.getUTCFullYear()}${pad(date.getUTCMonth() + 1, 2)}${pad(date.getUTCDate(), 2)}${pad(date.getUTCHours(), 2)}${pad(date.getUTCMinutes(), 2)}${pad(date.getUTCSeconds(), 2)}.${pad(date.getUTCMilliseconds() * 1000, 6)}+000`;
}

// WMIC exposes six fractional digits while CIM JSON /Date(epoch)/ is only
// millisecond precise. Compare the precision common to both representations.
export function normalizeProcessStartTokenForComparison(value) {
    if (typeof value !== 'string') return null;
    const token = value.trim();
    if (!token) return null;
    const match = /^(\d{14})\.(\d{6})([+-]\d{3})$/.exec(token);
    return match ? `${match[1]}.${match[2].slice(0, 3)}${match[3]}` : token;
}

// OS mutation paths require strict canonical process identity. Keep permissive
// comparison helper above for legacy deterministic consumers only.
export function normalizeStrictProcessStartTokenForComparison(value) {
    if (typeof value !== 'string') return null;
    const token = value.trim();
    if (!/^\d{14}\.\d{6}[+-]\d{3}$/.test(token)) return null;
    return normalizeProcessStartTokenForComparison(token);
}

export function processStartTokensMatch(left, right) {
    const normalizedLeft = normalizeProcessStartTokenForComparison(left);
    const normalizedRight = normalizeProcessStartTokenForComparison(right);
    return normalizedLeft !== null && normalizedLeft === normalizedRight;
}

function unknownWindowsProcessTable(reason) {
    return {
        state: 'unknown',
        reason,
        byPid: new Map(),
        childrenByParent: new Map(),
        rows: [],
    };
}

/**
 * Observe the complete Windows process table once per bounded observation cycle.
 * This is deliberately one CIM snapshot, not a per-PID tasklist/WMI fan-out.
 */
export function observeWindowsProcessTable({ timeout, deadline, _spawnSync } = {}) {
    const runner = _spawnSync || spawnSync;
    const cap = Number.isFinite(timeout) && timeout >= 0 ? timeout : 1000;
    const remaining = Number.isFinite(deadline) ? deadline - Date.now() : cap;
    const budget = Math.min(cap, remaining);
    if (budget <= 0) return unknownWindowsProcessTable('observation-deadline-exhausted');

    let result;
    try {
        result = runner(
            'powershell.exe',
            [
                '-NoProfile',
                '-NonInteractive',
                '-Command',
                'Get-CimInstance Win32_Process | Select ProcessId,ParentProcessId,CreationDate,Name | ConvertTo-Json -Compress',
            ],
            { encoding: 'utf8', timeout: budget, windowsHide: true },
        );
    } catch (error) {
        return unknownWindowsProcessTable(`observation-error: ${error?.message ?? String(error)}`);
    }
    if (result?.error)
        return unknownWindowsProcessTable(`observation-error: ${result.error.message}`);
    if (result?.status !== 0)
        return unknownWindowsProcessTable(`observation-nonzero-exit: ${result?.status}`);
    if (String(result?.stderr ?? '').trim())
        return unknownWindowsProcessTable('observation-stderr');

    let decoded;
    try {
        decoded = JSON.parse(String(result?.stdout ?? '').trim());
    } catch {
        return unknownWindowsProcessTable('observation-invalid-json');
    }
    const rows = Array.isArray(decoded)
        ? decoded
        : decoded && typeof decoded === 'object'
          ? [decoded]
          : null;
    if (!rows) return unknownWindowsProcessTable('observation-invalid-json-shape');

    const byPid = new Map();
    const childrenByParent = new Map();
    for (const row of rows) {
        const pid = Number(row?.ProcessId);
        const parentPid = Number(row?.ParentProcessId);
        const token = normalizeWindowsProcessToken(
            typeof row?.CreationDate === 'string'
                ? row.CreationDate
                : String(row?.CreationDate ?? ''),
        );
        const name = typeof row?.Name === 'string' ? row.Name.trim() : '';
        if (
            !Number.isSafeInteger(pid) ||
            pid < 0 ||
            !Number.isSafeInteger(parentPid) ||
            parentPid < 0 ||
            !token ||
            !name
        ) {
            return unknownWindowsProcessTable('observation-invalid-row');
        }
        const normalized = { pid, parentPid, startToken: token, imageName: name };
        const previous = byPid.get(pid);
        if (previous && JSON.stringify(previous) !== JSON.stringify(normalized)) {
            return unknownWindowsProcessTable('observation-duplicate-pid-conflict');
        }
        if (previous) continue;
        byPid.set(pid, normalized);
        const children = childrenByParent.get(parentPid) ?? [];
        children.push(pid);
        childrenByParent.set(parentPid, children);
    }
    return {
        state: rows.length === 0 ? 'known-empty' : 'known',
        reason: null,
        byPid,
        childrenByParent,
        rows,
    };
}

function restoreIsolatedLockWithoutOverwrite(tempPath, lockPath, beforeRestore) {
    let restoreFd;
    try {
        const raw = readFileSync(tempPath);
        if (typeof beforeRestore === 'function') beforeRestore();
        // `wx` is the cross-platform no-replace primitive. Never use rename for
        // restore: POSIX rename would overwrite a contender that acquired the
        // canonical pathname while the inspected inode was isolated.
        restoreFd = openSync(lockPath, 'wx');
        writeFileSync(restoreFd, raw);
        fsyncSync(restoreFd);
        closeSync(restoreFd);
        restoreFd = undefined;
        rmSync(tempPath, { force: true });
        return true;
    } catch {
        return false;
    } finally {
        if (restoreFd !== undefined) {
            try {
                closeSync(restoreFd);
            } catch {
                // best effort
            }
        }
    }
}

// Linux: the boot id and the start time of the process (field 22 of /proc/<pid>/stat, clock ticks since boot). The pair
// belongs to one process of one boot, so a PID that was recycled, or that survived a reboot, is told from the process
// that wrote a lock. The same pattern is what makes a recorded token a Linux one.
const LINUX_START_TOKEN_RE = /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}:\d+$/;

function readLinuxProcessStartToken(pid, options) {
    const proc = options._procRoot ?? '/proc';

    try {
        const bootId = readFileSync(`${proc}/sys/kernel/random/boot_id`, 'utf8').trim();
        const stat = readFileSync(`${proc}/${pid}/stat`, 'utf8');
        // The command name (field 2) is free text that may hold spaces and ')': count the fields after the last ')'.
        const startTime = stat
            .slice(stat.lastIndexOf(')') + 1)
            .trim()
            .split(/\s+/)[19];
        const token = `${bootId}:${startTime}`;

        return LINUX_START_TOKEN_RE.test(token) ? token : null;
    } catch {
        return null;
    }
}

/**
 * Get process creation / start token for process identity validation.
 * Returns string timestamp or null if process cannot be inspected.
 */
export function getProcessStartToken(pid, options = {}) {
    options = withRuntimePlatform(options);
    if (typeof pid !== 'number' || !Number.isInteger(pid) || pid <= 0) {
        return null;
    }

    const runner = options._spawnSync || options.spawnSync || spawnSync;
    const useWindows = isWindowsPlatform(options);

    if (!useWindows) return options._platform === 'linux' ? readLinuxProcessStartToken(pid, options) : null;

    if (options._processObservation) {
        if (options._processObservation.state === 'unknown') return null;
        return options._processObservation.byPid?.get(pid)?.startToken ?? null;
    }

    const parseToken = (stdout, stderr = '') => {
        if (String(stderr ?? '').trim()) return null;

        const lines = String(stdout ?? '')
            .split(/\r?\n/)
            .map((line) => line.trim().replace(/^\uFEFF/, ''))
            .filter(Boolean);
        if (lines.length !== 2 || lines[0] !== 'Node,CreationDate') return null;

        const parts = lines[1].split(',');
        if (parts.length !== 2 || !parts[0] || !/^\d{14}\.\d{6}[+-]\d{3}$/.test(parts[1])) {
            return null;
        }
        return parts[1];
    };

    // WMIC was removed from supported Windows installations. Keep it as the
    // first probe for compatibility with older hosts and deterministic mocks,
    // then use the supported CIM PowerShell API when it is unavailable.
    try {
        const result = runner(
            'wmic',
            ['process', 'where', `ProcessId=${pid}`, 'get', 'CreationDate', '/format:csv'],
            {
                encoding: 'utf8',
                timeout: 5000,
                windowsHide: true,
            },
        );
        const token =
            result.error || result.status !== 0 ? null : parseToken(result.stdout, result.stderr);
        if (token) return token;
    } catch {
        // Fall through to the supported PowerShell CIM query.
    }

    try {
        const result = runner(
            'powershell.exe',
            [
                '-NoProfile',
                '-NonInteractive',
                '-ExecutionPolicy',
                'Bypass',
                '-Command',
                `$process = Get-CimInstance -ClassName Win32_Process -Filter "ProcessId = ${pid}"; if ($null -ne $process) { $process.CreationDate.ToUniversalTime().ToString("yyyyMMddHHmmss.ffffff+000") }`,
            ],
            {
                encoding: 'utf8',
                timeout: 5000,
                windowsHide: true,
            },
        );
        if (result.error || result.status !== 0 || String(result.stderr ?? '').trim()) return null;

        const token = String(result.stdout ?? '').trim();
        return /^\d{14}\.\d{6}[+-]\d{3}$/.test(token) ? token : null;
    } catch {
        return null;
    }
}

/**
 * Registry immutable owned descendants/processes/ports/start-tokens for durable exclusion.
 * Survives intermediate and owner exit so OS-reparented or detached descendant trees
 * remain discoverable, boundedly terminable, and prevent false fast-path / premature mutex reclaim.
 */
export class OwnedProcessRegistry {
    constructor() {
        this.items = new Map(); // pid -> { pid, startToken, parentPid, port, addedAt }
    }

    register(pid, options = {}) {
        options = withRuntimePlatform(options);
        if (typeof pid !== 'number' || !Number.isInteger(pid) || pid <= 0) return;
        const existing = this.items.get(pid);
        const existingStartToken =
            typeof existing?.startToken === 'string' && existing.startToken.trim()
                ? existing.startToken.trim()
                : null;
        const incomingStartToken =
            typeof options.startToken === 'string' && options.startToken.trim()
                ? options.startToken.trim()
                : typeof options.processStartToken === 'string' && options.processStartToken.trim()
                  ? options.processStartToken.trim()
                  : null;
        const discoveredStartToken = options.skipStartTokenDiscovery
            ? null
            : getProcessStartToken(pid, options);
        const resolvedStartToken =
            existingStartToken ?? incomingStartToken ?? discoveredStartToken ?? null;
        const port =
            typeof options.port === 'number' && Number.isInteger(options.port) && options.port > 0
                ? (existing?.port ?? options.port)
                : (existing?.port ?? null);
        const parentPid =
            typeof options.parentPid === 'number' &&
            Number.isInteger(options.parentPid) &&
            options.parentPid > 0
                ? (existing?.parentPid ?? options.parentPid)
                : (existing?.parentPid ?? null);
        this.items.set(pid, {
            pid,
            startToken: resolvedStartToken || null,
            parentPid,
            port,
            // A PID proven foreign/reused stays foreign for the lifetime of the
            // registry: re-registering it must never clear the refusal flag,
            // otherwise teardown could adopt and kill a foreign process.
            identityMismatch: existing?.identityMismatch === true,
            addedAt: existing?.addedAt ?? new Date().toISOString(),
        });
        if (existing?.port && existing.port !== port) {
            this.items.delete(`port:${existing.port}`);
        }
        if (typeof port === 'number' && port > 0) {
            this.registerPort(port);
        }
        if (options.skipProcessDiscovery) return;

        // Auto-discover child tree if process is running
        try {
            const descendants = getDescendantPids(pid, options);
            for (const dPid of descendants) {
                if (!this.items.has(dPid)) {
                    const dToken = getProcessStartToken(dPid, options);
                    this.items.set(dPid, {
                        pid: dPid,
                        startToken: dToken || null,
                        parentPid: pid,
                        port: null,
                        addedAt: new Date().toISOString(),
                    });
                }
            }
        } catch {
            // best effort
        }

        // Auto-discover listening ports for registered process and all descendants
        try {
            const allPids = this.getAllPids();
            const runner = options._spawnSync || options.spawnSync || spawnSync;
            const useWindows = isWindowsPlatform(options);
            if (useWindows) {
                const result = runner('netstat', ['-ano'], {
                    encoding: 'utf8',
                    timeout: options.timeout ?? 5000,
                    windowsHide: true,
                });
                if (!result.error && result.status === 0) {
                    const lines = (result.stdout ?? '').split(/\r?\n/);
                    for (const line of lines) {
                        const match = line.match(
                            /^\s*TCP\s+\S+:(\d+)\s+\S+\s+LISTENING\s+(\d+)\s*$/,
                        );
                        if (match) {
                            const foundPort = Number(match[1]);
                            const listenerPid = Number(match[2]);
                            if (allPids.includes(listenerPid)) {
                                this.registerPort(foundPort);
                                const item = this.items.get(listenerPid);
                                if (item && !item.port) {
                                    item.port = foundPort;
                                }
                            }
                        }
                    }
                }
            }
        } catch {
            // best effort
        }
    }

    registerPort(port) {
        if (typeof port === 'number' && Number.isInteger(port) && port > 0) {
            this.items.set(`port:${port}`, { port, addedAt: new Date().toISOString() });
        }
    }

    unregister(pid) {
        if (typeof pid === 'number') {
            const item = this.items.get(pid);
            if (item && typeof item.port === 'number' && item.port > 0) {
                this.items.delete(`port:${item.port}`);
            }
            this.items.delete(pid);
        } else if (typeof pid === 'string') {
            this.items.delete(pid);
        }
    }

    clear() {
        for (const key of Array.from(this.items.keys())) {
            this.unregister(key);
        }
    }

    getAllPorts() {
        const ports = [];
        for (const item of this.items.values()) {
            if (typeof item.port === 'number' && item.port > 0) {
                ports.push(item.port);
            }
        }
        return Array.from(new Set(ports));
    }

    getAllPids() {
        const pids = [];
        for (const [key, item] of this.items.entries()) {
            if (typeof item.pid === 'number') {
                pids.push(item.pid);
            }
        }
        return pids;
    }

    getAllItems() {
        return Array.from(this.items.values());
    }

    toJSON() {
        return this.getAllItems();
    }
}

export const globalOwnedRegistry = new OwnedProcessRegistry();

/**
 * Accept only WMIC's exact successful empty-result shape. Prefixes, suffixes,
 * additional rows, stderr, errors and timeouts remain ambiguous/fail-closed.
 */
export function isExactWmicNoInstanceOutput(stdout, stderr = '') {
    const normalizeLines = (value) =>
        String(value ?? '')
            .split(/\r?\n/)
            .map((line) => line.trim().replace(/^\uFEFF/, ''))
            .filter(Boolean);
    const stdoutLines = normalizeLines(stdout);
    const stderrLines = normalizeLines(stderr);
    const status = 'No Instance(s) Available.';

    // Actual Windows WMIC writes the status to stderr and the empty CSV header
    // to stdout. Some versions/wrappers combine both on stdout, so accept only
    // these complete exact shapes.
    if (stderrLines.length === 1 && stderrLines[0] === status) {
        return stdoutLines.length === 1 && stdoutLines[0] === 'Node,';
    }
    if (stderrLines.length !== 0) return false;

    return stdoutLines.length === 2 && stdoutLines[0] === status && stdoutLines[1] === 'Node,';
}

/**
 * Recursively find all descendant PIDs for a given parent PID.
 * Bounded timeout per query and cycle-safe. The structured result is iterable
 * for compatibility with teardown callers, while stale recovery can distinguish
 * positive discovery from an observability failure.
 */
export function getDescendantPids(parentPid, options = {}) {
    options = withRuntimePlatform(options);
    const result = (state, pids = [], reason = null) => ({
        state,
        pids,
        ...(reason ? { reason } : {}),
        *[Symbol.iterator]() {
            yield* pids;
        },
    });

    if (typeof parentPid !== 'number' || !Number.isInteger(parentPid) || parentPid <= 0) {
        return result('unknown', [], 'invalid-parent-pid');
    }

    if (options._processObservation) {
        const observation = options._processObservation;
        if (observation.state === 'unknown') return result('unknown', [], observation.reason);
        const descendants = [];
        const queue = [...(observation.childrenByParent?.get(parentPid) ?? [])];
        const visited = new Set();
        while (queue.length) {
            const childPid = queue.shift();
            if (visited.has(childPid)) continue;
            visited.add(childPid);
            descendants.push(childPid);
            queue.push(...(observation.childrenByParent?.get(childPid) ?? []));
        }
        return result(descendants.length ? 'known' : 'known-empty', descendants);
    }

    const runner = options._spawnSync || options.spawnSync || spawnSync;
    const timeout = options.timeout ?? 5000;
    const useWindowsDiscovery = isWindowsPlatform(options);
    const descendants = [];
    const queue = [parentPid];
    const visited = new Set([parentPid]);

    if (useWindowsDiscovery) {
        while (queue.length > 0) {
            const current = queue.shift();
            let query;
            try {
                query = runner(
                    'wmic',
                    [
                        'process',
                        'where',
                        `ParentProcessId=${current}`,
                        'get',
                        'ProcessId',
                        '/format:csv',
                    ],
                    {
                        encoding: 'utf8',
                        timeout,
                        windowsHide: true,
                    },
                );
            } catch (error) {
                return result('unknown', descendants, `wmic-spawn-error: ${error.message}`);
            }

            if (query.error) {
                // WMIC is optional on current Windows. Fall back only when the
                // executable is unavailable; explicit command failures remain
                // unknown so observability still fails closed.
                if (query.error.code !== 'ENOENT') {
                    return result('unknown', descendants, `wmic-error: ${query.error.message}`);
                }

                let powershellQuery;
                try {
                    powershellQuery = runner(
                        'powershell.exe',
                        [
                            '-NoProfile',
                            '-NonInteractive',
                            '-ExecutionPolicy',
                            'Bypass',
                            '-Command',
                            `Get-CimInstance -ClassName Win32_Process | Where-Object { $_.ParentProcessId -eq ${current} } | Select-Object -ExpandProperty ProcessId`,
                        ],
                        {
                            encoding: 'utf8',
                            timeout,
                            windowsHide: true,
                        },
                    );
                } catch (error) {
                    return result(
                        'unknown',
                        descendants,
                        `powershell-spawn-error: ${error.message}`,
                    );
                }
                if (powershellQuery.error) {
                    return result(
                        'unknown',
                        descendants,
                        `powershell-error: ${powershellQuery.error.message}`,
                    );
                }
                if (powershellQuery.status !== 0 || String(powershellQuery.stderr ?? '').trim()) {
                    return result(
                        'unknown',
                        descendants,
                        `powershell-nonzero-exit: ${powershellQuery.status}`,
                    );
                }

                const powershellLines = String(powershellQuery.stdout ?? '')
                    .split(/\r?\n/)
                    .map((line) => line.trim())
                    .filter(Boolean);
                for (const line of powershellLines) {
                    if (!/^\d+$/.test(line)) {
                        return result('unknown', descendants, 'malformed-powershell-pid');
                    }
                    const childPid = Number(line);
                    if (!Number.isSafeInteger(childPid) || childPid <= 0) {
                        return result('unknown', descendants, 'malformed-powershell-pid');
                    }
                    if (!visited.has(childPid)) {
                        visited.add(childPid);
                        descendants.push(childPid);
                        queue.push(childPid);
                    }
                }
                continue;
            }
            if (query.status !== 0) {
                return result('unknown', descendants, `wmic-nonzero-exit: ${query.status}`);
            }
            const stderr = (query.stderr ?? '').trim();
            if (isExactWmicNoInstanceOutput(query.stdout, query.stderr)) {
                // This queried node is a proven leaf. Continue traversing any
                // other already-discovered descendants.
                continue;
            }
            if (stderr) {
                return result('unknown', descendants, `ambiguous-wmic-output: ${stderr}`);
            }

            const lines = (query.stdout ?? '')
                .split(/\r?\n/)
                .map((line) => line.trim())
                .filter(Boolean);
            if (lines.length === 0) {
                return result('unknown', descendants, 'empty-wmic-output');
            }
            if (lines[0].replace(/^\uFEFF/, '') !== 'Node,ProcessId') {
                return result('unknown', descendants, 'malformed-wmic-header');
            }

            for (const line of lines.slice(1)) {
                const parts = line.split(',');
                if (parts.length !== 2 || !parts[0] || !/^\d+$/.test(parts[1])) {
                    return result('unknown', descendants, 'malformed-wmic-row');
                }
                const childPid = Number(parts[1]);
                if (!Number.isSafeInteger(childPid) || childPid <= 0) {
                    return result('unknown', descendants, 'malformed-wmic-pid');
                }
                if (!visited.has(childPid)) {
                    visited.add(childPid);
                    descendants.push(childPid);
                    queue.push(childPid);
                }
            }
        }
        return result(descendants.length === 0 ? 'known-empty' : 'known', descendants);
    }

    // POSIX fallback
    while (queue.length > 0) {
        const current = queue.shift();
        let query;
        try {
            query = runner('pgrep', ['-P', String(current)], {
                encoding: 'utf8',
                timeout,
            });
        } catch (error) {
            return result('unknown', descendants, `pgrep-spawn-error: ${error.message}`);
        }

        if (query.error) {
            return result('unknown', descendants, `pgrep-error: ${query.error.message}`);
        }
        const stdout = (query.stdout ?? '').trim();
        const stderr = (query.stderr ?? '').trim();
        if (query.status === 1 && !stdout && !stderr) {
            continue;
        }
        if (query.status !== 0) {
            return result('unknown', descendants, `pgrep-nonzero-exit: ${query.status}`);
        }
        if (!stdout || stderr) {
            return result('unknown', descendants, 'ambiguous-pgrep-output');
        }

        for (const line of stdout.split(/\r?\n/)) {
            const normalized = line.trim();
            if (!/^\d+$/.test(normalized)) {
                return result('unknown', descendants, 'malformed-pgrep-output');
            }
            const childPid = Number(normalized);
            if (!Number.isSafeInteger(childPid) || childPid <= 0) {
                return result('unknown', descendants, 'malformed-pgrep-pid');
            }
            if (!visited.has(childPid)) {
                visited.add(childPid);
                descendants.push(childPid);
                queue.push(childPid);
            }
        }
    }
    return result(descendants.length === 0 ? 'known-empty' : 'known', descendants);
}

/**
 * Tri-state probe to check if a PID is alive, dead, or in an unknown state.
 * Returns: { state: 'alive' | 'dead' | 'unknown', reason?: string, imageName?: string }
 *
 * Requirements:
 * - Deadline / bounded timeout (default 5000ms)
 * - Tri-state alive / dead / unknown
 * - Non-zero exit, timeout, or malformed observability output MUST yield 'unknown' (not 'dead')
 * - When expectedStartToken is provided, verifies process identity to guard against PID reuse.
 */
export function probeProcessLiveness(pid, options = {}) {
    options = withRuntimePlatform(options);
    if (typeof pid !== 'number' || !Number.isInteger(pid) || pid <= 0) {
        return { state: 'dead', reason: 'invalid-pid' };
    }

    const timeout = options.timeout ?? 5000;
    const expectedStartToken = options.expectedStartToken ?? null;
    const runner = options._spawnSync || options.spawnSync || spawnSync;

    if (options._processObservation) {
        const observation = options._processObservation;
        if (observation.state === 'unknown') {
            return {
                state: 'unknown',
                reason: observation.reason ?? 'process-observation-unknown',
            };
        }
        const current = observation.byPid?.get(pid);
        if (!current) return { state: 'dead', reason: 'snapshot-pid-absent' };
        if (
            expectedStartToken &&
            !processStartTokensMatch(current.startToken, expectedStartToken)
        ) {
            return {
                state: 'dead',
                reason: 'pid-reused-by-another-process',
                identityMismatch: true,
            };
        }
        return {
            state: 'alive',
            imageName: current.imageName,
            identityVerified: expectedStartToken ? true : false,
            startToken: current.startToken,
        };
    }

    const useWindows = isWindowsPlatform(options);

    if (useWindows) {
        let result;
        try {
            result = runner('tasklist', ['/FI', `PID eq ${pid}`, '/NH', '/FO', 'CSV'], {
                encoding: 'utf8',
                timeout,
                windowsHide: true,
            });
        } catch (err) {
            return { state: 'unknown', reason: `spawn-error: ${err.message}` };
        }

        if (result.error) {
            return { state: 'unknown', reason: `tasklist-error: ${result.error.message}` };
        }

        if (result.status !== 0) {
            return { state: 'unknown', reason: `tasklist-nonzero-exit: ${result.status}` };
        }

        const stdout = (result.stdout ?? '').trim();
        if (!stdout) {
            return { state: 'unknown', reason: 'empty-tasklist-output' };
        }
        // Hanya pesan no-match tasklist ternormalisasi yang lengkap yang boleh membuktikan PID tidak ada.
        // Prefix, suffix, baris ekstra, output CSV/status campuran, dan near-match bersifat ambigu;
        // observability wajib fail-closed (unknown/alive), tidak pernah dead.
        if (stdout === 'INFO: No tasks are running which match the specified criteria.') {
            return { state: 'dead', reason: 'no-tasks-matched' };
        }
        if (stdout.toUpperCase().startsWith('INFO')) {
            return { state: 'unknown', reason: `ambiguous-tasklist-output: ${stdout}` };
        }

        const match = stdout.match(/^"([^"]+)","?(\d+)"?/);
        if (!match) {
            return { state: 'unknown', reason: `malformed-tasklist-output: ${stdout}` };
        }

        const imageName = match[1];

        // If startToken was recorded, verify against PID reuse
        if (expectedStartToken) {
            const currentToken = getProcessStartToken(pid, options);
            if (currentToken === null) {
                // If wmic fails but tasklist saw it alive, we cannot be certain of identity
                return { state: 'alive', imageName, identityVerified: false };
            }
            if (!processStartTokensMatch(currentToken, expectedStartToken)) {
                // Process is alive but start time doesn't match: PID was reused!
                return { state: 'dead', reason: 'pid-reused-by-another-process' };
            }
        }

        return { state: 'alive', imageName, identityVerified: true };
    }

    try {
        process.kill(pid, 0);
    } catch (error) {
        if (!error || error.code !== 'EPERM') {
            if (error && error.code === 'ESRCH') {
                return { state: 'dead', reason: 'esrch' };
            }
            return { state: 'unknown', reason: error?.message ?? 'kill-0-error' };
        }
        // EPERM: the process exists and belongs to another user.
    }

    // Only a Linux token can be checked here. Any other recorded token keeps the earlier answer: the PID is alive.
    if (options._platform !== 'linux' || !LINUX_START_TOKEN_RE.test(expectedStartToken ?? '')) {
        return { state: 'alive', identityVerified: true };
    }

    const currentToken = getProcessStartToken(pid, options);
    if (currentToken === null) {
        // The identity cannot be read now: never proof that the PID is another process, never proof that it is the same.
        return { state: 'alive', identityVerified: false };
    }
    if (currentToken !== expectedStartToken) {
        return { state: 'dead', reason: 'pid-reused-by-another-process' };
    }

    return { state: 'alive', identityVerified: true };
}

/**
 * Audit-safe check if a PID is alive.
 * Returns true ONLY if proven alive. Returns false if dead or unknown.
 */
export function isProcessAlive(pid, options = {}) {
    options = withRuntimePlatform(options);
    const probe = probeProcessLiveness(pid, options);
    return probe.state === 'alive';
}

/**
 * Safely parse lock payload.
 * Returns:
 * - { valid: true, payload: Object } when valid JSON object with required schema
 * - { valid: false, reason: string, raw?: string } when missing, malformed, or partial
 */
export function inspectLockPayload(lockPath = defaultLockPath) {
    try {
        if (!existsSync(lockPath)) {
            return { valid: false, reason: 'absent' };
        }
        const raw = readFileSync(lockPath, 'utf8');
        if (!raw || !raw.trim()) {
            return { valid: false, reason: 'empty', raw };
        }
        let parsed;
        try {
            parsed = JSON.parse(raw);
        } catch (parseErr) {
            return { valid: false, reason: `malformed-json: ${parseErr.message}`, raw };
        }

        if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
            return { valid: false, reason: 'not-an-object', raw };
        }

        // Validate required fields for valid ownership
        if (
            typeof parsed.ownerPid !== 'number' ||
            !Number.isInteger(parsed.ownerPid) ||
            parsed.ownerPid <= 0 ||
            typeof parsed.nonce !== 'string' ||
            !parsed.nonce.trim() ||
            typeof parsed.schema !== 'string' ||
            !parsed.schema.trim()
        ) {
            return { valid: false, reason: 'partial-or-invalid-fields', raw, parsed };
        }

        const isPositiveInteger = (value) => Number.isInteger(value) && value > 0;
        if (
            parsed.ownedProcesses !== undefined &&
            (!Array.isArray(parsed.ownedProcesses) ||
                parsed.ownedProcesses.some((entry) => {
                    if (!entry || typeof entry !== 'object' || Array.isArray(entry)) return true;
                    const hasPid = entry.pid !== undefined;
                    const hasPort = entry.port !== undefined && entry.port !== null;
                    if (!hasPid) {
                        return (
                            !hasPort ||
                            !isPositiveInteger(entry.port) ||
                            entry.startToken !== undefined ||
                            entry.parentPid !== undefined
                        );
                    }
                    if (!isPositiveInteger(entry.pid)) return true;
                    if (hasPort && !isPositiveInteger(entry.port)) return true;
                    if (
                        entry.startToken !== undefined &&
                        entry.startToken !== null &&
                        (typeof entry.startToken !== 'string' || !entry.startToken.trim())
                    ) {
                        return true;
                    }
                    if (
                        entry.parentPid !== undefined &&
                        entry.parentPid !== null &&
                        !isPositiveInteger(entry.parentPid)
                    ) {
                        return true;
                    }
                    return false;
                }))
        ) {
            return { valid: false, reason: 'invalid-owned-processes', raw, parsed };
        }
        if (
            parsed.ownedPorts !== undefined &&
            (!Array.isArray(parsed.ownedPorts) ||
                parsed.ownedPorts.some((port) => !isPositiveInteger(port)))
        ) {
            return { valid: false, reason: 'invalid-owned-ports', raw, parsed };
        }

        return { valid: true, payload: parsed, raw };
    } catch (err) {
        return { valid: false, reason: `read-error: ${err.message}` };
    }
}

/**
 * Legacy compatibility wrapper for reading lock payload.
 * Returns null on any parse / structure error or absent file.
 */
export function readLockPayload(lockPath = defaultLockPath) {
    const inspection = inspectLockPayload(lockPath);
    return inspection.valid ? inspection.payload : null;
}

/**
 * A tokenless process observed alive in a historical lock may be safely
 * classified as foreign only when its current immutable creation time is
 * strictly after the timestamp at which that PID was recorded. An
 * older/equal/unparseable pair remains ambiguous and must stay fail-closed.
 */
export function isProcessCreatedAfterRegistration(processStartToken, registeredAt) {
    if (typeof processStartToken !== 'string' || typeof registeredAt !== 'string') {
        return false;
    }

    const match = /^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\.(\d{6})([+-]\d{3})$/.exec(
        processStartToken.trim(),
    );
    if (!match) return false;

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const hour = Number(match[4]);
    const minute = Number(match[5]);
    const second = Number(match[6]);
    const milliseconds = Number(match[7].slice(0, 3));
    const offsetMinutes = Number(match[8]);
    const processTime =
        Date.UTC(year, month - 1, day, hour, minute, second, milliseconds) - offsetMinutes * 60_000;
    const registrationTime = Date.parse(registeredAt);

    return (
        Number.isFinite(processTime) &&
        Number.isFinite(registrationTime) &&
        processTime > registrationTime
    );
}

/**
 * Acquire process mutex for canonical E2E test execution.
 *
 * Implements atomic O_CREAT | O_EXCL file creation, token/nonce identity,
 * fail-closed handling on malformed/partial/unknown ownership, and stale-lock
 * reclamation ONLY when the owner PID is proven dead with tri-state probe.
 *
 * @param {Object} options
 * @param {string} [options.lockPath]
 * @param {string} [options.schema]
 * @param {number} [options.pid]
 * @param {string} [options.nonce]
 * @param {string} [options.runner]
 * @returns {{ lockPath: string, payload: Object, nonce: string, release: () => boolean }}
 */
export function acquireCanonicalMutex(options = {}) {
    options = withRuntimePlatform(options);
    const lockPath = options.lockPath || defaultLockPath;
    const schema = options.schema || 'inbils_test';
    const pid = options.pid || process.pid;
    const nonce = options.nonce || randomUUID();
    const runner = options.runner || 'scripts/e2e-canonical.mjs';
    const processStartToken = options.processStartToken ?? getProcessStartToken(pid, options);

    mkdirSync(dirname(lockPath), { recursive: true });

    const payload = {
        nonce,
        ownerPid: pid,
        processStartToken,
        schema,
        runner,
        ownedProcesses: options.ownedProcesses ?? globalOwnedRegistry.getAllItems(),
        ownedPorts: options.ownedPorts ?? globalOwnedRegistry.getAllPorts(),
        acquiredAt: new Date().toISOString(),
        nodeVersion: process.version,
    };

    const payloadJson = JSON.stringify(payload, null, 2);

    let fd;
    try {
        // Atomic create with O_CREAT | O_EXCL ('wx')
        fd = openSync(lockPath, 'wx');
        writeFileSync(fd, payloadJson);
        closeSync(fd);
    } catch (err) {
        if (err.code === 'EEXIST') {
            // Lock exists. Inspect lock owner.
            const inspection = inspectLockPayload(lockPath);

            if (!inspection.valid) {
                // Malformed / partial / unknown payload: FAIL-CLOSED!
                // An active process might be writing the file or the lock state is ambiguous.
                throw new Error(
                    `[e2e-mutex] Refusing canonical E2E run: lock file at ${lockPath} exists but has invalid or ambiguous payload (${inspection.reason}). ` +
                        `Fail-closed policy prevents automatic reclamation of unverified locks. ` +
                        `Investigate and remove manually if confirmed abandoned.`,
                );
            }

            const existing = inspection.payload;
            const ownerPid = existing.ownerPid;
            const ownerStartToken =
                typeof existing.processStartToken === 'string' && existing.processStartToken.trim()
                    ? existing.processStartToken.trim()
                    : null;

            // Probe liveness with tri-state
            const liveness = probeProcessLiveness(ownerPid, {
                ...options,
                expectedStartToken: existing.processStartToken,
            });

            if (liveness.state === 'alive') {
                throw new Error(
                    `[e2e-mutex] Refusing concurrent canonical E2E run: lock is currently held by active process PID ${ownerPid} ` +
                        `(nonce: ${existing.nonce}, acquiredAt: ${existing.acquiredAt ?? 'unknown'}, schema: ${existing.schema ?? 'unknown'}). ` +
                        `Only one canonical E2E run may execute against shared ${schema} at a time.`,
                );
            }

            if (liveness.state === 'unknown') {
                throw new Error(
                    `[e2e-mutex] Refusing canonical E2E run: unable to verify liveness of lock owner PID ${ownerPid} (${liveness.reason}). ` +
                        `Fail-closed policy requires positive proof of process termination before reclaiming stale locks.`,
                );
            }

            // Defect exact (1): a stale owner with no immutable process-start identity
            // cannot have PID reuse ruled out, so reclaim is unsafe. Fail closed.
            if (liveness.state === 'dead' && ownerStartToken === null) {
                throw new Error(
                    `[e2e-mutex] Refusing canonical E2E run: lock owner PID ${ownerPid} recorded no immutable process-start identity; ` +
                        `PID reuse cannot be ruled out, so reclaiming is unsafe. Fail-closed policy refuses automatic reclamation.`,
                );
            }

            // Defect exact (1) & (2): Only discover descendants from recorded entries whose
            // immutable identity was already verified. Never seed discovery from the numeric
            // ownerPid alone because it may have been reused by a foreign process tree.
            const descendantSet = new Set();
            if (liveness.state === 'dead' && liveness.reason !== 'pid-reused-by-another-process') {
                const discovery = getDescendantPids(ownerPid, {
                    ...options,
                    _platform: options._platform,
                });
                if (discovery.state === 'unknown') {
                    throw new Error(
                        `[e2e-mutex] Refusing canonical E2E run: unable to discover descendants of lock owner PID ${ownerPid} (${discovery.reason}). ` +
                            `Fail-closed policy requires positive proof of the entire process tree before stale reclaim.`,
                    );
                }
                for (const childPid of discovery) {
                    descendantSet.add(childPid);
                }
            }
            if (Array.isArray(existing.ownedProcesses)) {
                for (const entry of existing.ownedProcesses) {
                    if (entry && typeof entry.pid === 'number' && entry.pid > 0) {
                        const entryStartToken =
                            typeof entry.startToken === 'string' && entry.startToken.trim()
                                ? entry.startToken.trim()
                                : null;
                        const entryLiveness = probeProcessLiveness(entry.pid, {
                            ...options,
                            _platform: options._platform,
                            expectedStartToken: entryStartToken,
                        });

                        if (entryLiveness.state === 'unknown') {
                            throw new Error(
                                `[e2e-mutex] Refusing canonical E2E run: unable to verify liveness of recorded owned process PID ${entry.pid} (${entryLiveness.reason}). ` +
                                    `Fail-closed policy requires positive proof before descendant discovery.`,
                            );
                        }

                        // A live tokenless entry is ambiguous unless a fresh immutable
                        // creation token proves that the current process started after the
                        // historical lock was acquired. In that case it is necessarily a
                        // foreign PID reuse: never adopt or discover its children.
                        if (entryLiveness.state === 'alive' && !entryStartToken) {
                            const currentStartToken = getProcessStartToken(entry.pid, options);
                            if (
                                isProcessCreatedAfterRegistration(currentStartToken, entry.addedAt)
                            ) {
                                continue;
                            }
                            throw new Error(
                                `[e2e-mutex] Refusing canonical E2E run: recorded owned process PID ${entry.pid} is alive but has no immutable identity token; ` +
                                    `PID reuse cannot be ruled out. Fail-closed policy refuses stale reclaim.`,
                            );
                        }

                        // An owned process observed alive with a recorded token that cannot
                        // be verified must likewise fail closed, never be adopted.
                        if (
                            entryLiveness.state === 'alive' &&
                            entryLiveness.identityVerified === false
                        ) {
                            throw new Error(
                                `[e2e-mutex] Refusing canonical E2E run: recorded owned process PID ${entry.pid} is alive but its identity could not be verified; ` +
                                    `PID reuse cannot be ruled out. Fail-closed policy refuses stale reclaim.`,
                            );
                        }

                        if (
                            entryLiveness.state === 'dead' &&
                            entryLiveness.reason === 'pid-reused-by-another-process'
                        ) {
                            // The numeric PID now belongs to a foreign process. Do not
                            // discover its children or include it in the stale tree.
                            continue;
                        }

                        if (entryLiveness.state === 'alive') {
                            descendantSet.add(entry.pid);
                        }

                        // Discover recursively for exact-identity entries. For a legacy
                        // tokenless entry, a child discovered under its numeric PID is only
                        // attributable to the historical run when its immutable start token
                        // is not newer than that entry's registration. A newer child is a
                        // foreign PID-reuse tree and must never enter terminal ownership.
                        const discovery = getDescendantPids(entry.pid, {
                            ...options,
                            _platform: options._platform,
                        });
                        if (discovery.state === 'unknown') {
                            throw new Error(
                                `[e2e-mutex] Refusing canonical E2E run: unable to discover descendants of recorded owned process PID ${entry.pid} (${discovery.reason}). ` +
                                    `Fail-closed policy requires positive proof of the entire process tree before stale reclaim.`,
                            );
                        }
                        for (const childPid of discovery) {
                            if (!entryStartToken) {
                                const childStartToken = getProcessStartToken(childPid, options);
                                if (!childStartToken) {
                                    throw new Error(
                                        `[e2e-mutex] Refusing canonical E2E run: discovered child PID ${childPid} under tokenless historical process PID ${entry.pid} has no immutable identity token. ` +
                                            `Fail-closed policy cannot distinguish an owned descendant from PID reuse.`,
                                    );
                                }
                                if (
                                    isProcessCreatedAfterRegistration(
                                        childStartToken,
                                        entry.addedAt,
                                    )
                                ) {
                                    continue;
                                }
                            }
                            descendantSet.add(childPid);
                        }
                    }
                }
            }

            for (const dPid of descendantSet) {
                const ownedEntry = Array.isArray(existing.ownedProcesses)
                    ? existing.ownedProcesses.find((e) => e && e.pid === dPid)
                    : null;
                const ownedStartToken =
                    typeof ownedEntry?.startToken === 'string' && ownedEntry.startToken.trim()
                        ? ownedEntry.startToken.trim()
                        : null;
                const dLiveness = probeProcessLiveness(dPid, {
                    ...options,
                    expectedStartToken: ownedStartToken,
                });
                if (dLiveness.state === 'alive') {
                    throw new Error(
                        `[e2e-mutex] Refusing canonical E2E run: owner PID ${ownerPid} exited but owned descendant process PID ${dPid} is still active. ` +
                            `Fail-closed policy requires entire owned process tree to be terminated before reclaiming stale locks.`,
                    );
                }
                if (dLiveness.state === 'unknown') {
                    throw new Error(
                        `[e2e-mutex] Refusing canonical E2E run: unable to verify liveness of owner PID ${ownerPid}'s descendant process PID ${dPid} (${dLiveness.reason}). ` +
                            `Fail-closed policy requires positive proof of descendant termination before reclaiming stale locks.`,
                    );
                }
            }

            // Check the union of top-level ports and legacy per-process ports.
            // A historical payload may predate ownedPorts while still recording
            // its listener on ownedProcesses[].port; neither representation may
            // bypass terminal port proof.
            const recordedPorts = new Set(existing.ownedPorts ?? []);
            for (const entry of existing.ownedProcesses ?? []) {
                if (typeof entry.port === 'number') recordedPorts.add(entry.port);
            }
            for (const p of recordedPorts) {
                const portProbe = options._probePortListener
                    ? options._probePortListener(p, 2000, options)
                    : probePortListener(p, 2000, options);
                if (portProbe.state === 'listener') {
                    throw new Error(
                        `[e2e-mutex] Refusing canonical E2E run: owner PID ${ownerPid} exited but port ${p} is still bound by PID ${portProbe.pid}.`,
                    );
                }
                if (portProbe.state === 'unknown') {
                    throw new Error(
                        `[e2e-mutex] Refusing canonical E2E run: unable to verify state of owned port ${p} (${portProbe.reason ?? 'unknown'}). ` +
                            `Fail-closed policy requires positive proof that all owned ports are free before reclaiming stale locks.`,
                    );
                }
            }

            // Stale lock: owner and all descendants are proven dead (state === 'dead').
            // Reclaim stale lock safely with atomic replace check.
            // Move inspected stale file to a unique temp path, re-verify all immutable fields,
            // and only delete if identical to inspected stale lock.
            const tempReclaimPath = `${lockPath}.stale-${randomUUID()}`;
            try {
                renameSync(lockPath, tempReclaimPath);
            } catch (unlinkErr) {
                throw new Error(
                    `[e2e-mutex] Failed to isolate stale lock at ${lockPath}: ${unlinkErr.message}`,
                );
            }
            if (typeof options._afterStaleIsolation === 'function') {
                options._afterStaleIsolation(tempReclaimPath);
            }

            let staleInspected;
            try {
                const staleRaw = readFileSync(tempReclaimPath, 'utf8');
                staleInspected = JSON.parse(staleRaw);
            } catch {
                restoreIsolatedLockWithoutOverwrite(
                    tempReclaimPath,
                    lockPath,
                    options._beforeStaleRestore,
                );
                throw new Error(
                    `[e2e-mutex] Stale lock payload at ${lockPath} became invalid during reclamation.`,
                );
            }

            const matchesStale =
                staleInspected &&
                staleInspected.nonce === existing.nonce &&
                staleInspected.ownerPid === existing.ownerPid &&
                staleInspected.processStartToken === existing.processStartToken &&
                staleInspected.schema === existing.schema &&
                staleInspected.runner === existing.runner;

            if (!matchesStale) {
                restoreIsolatedLockWithoutOverwrite(
                    tempReclaimPath,
                    lockPath,
                    options._beforeStaleRestore,
                );
                throw new Error(
                    `[e2e-mutex] Stale lock at ${lockPath} was replaced or modified during reclamation; aborting reclaim.`,
                );
            }

            rmSync(tempReclaimPath, { force: true });

            try {
                fd = openSync(lockPath, 'wx');
                writeFileSync(fd, payloadJson);
                closeSync(fd);
            } catch (retryErr) {
                throw new Error(
                    `[e2e-mutex] Failed to acquire canonical E2E lock after stale cleanup: ${retryErr.message}`,
                );
            }
        } else {
            throw new Error(
                `[e2e-mutex] Unable to create lock file at ${lockPath}: ${err.message}`,
            );
        }
    }

    let released = false;
    const updateOwnedMetadata = (processes = [], ports = []) => {
        let metadataFd;
        try {
            // Keep the canonical pathname present for the complete refresh. Opening
            // the inode before inspection also means a concurrent pathname replacement
            // is never overwritten through a later path-based write.
            metadataFd = openSync(lockPath, 'r+');
            const raw = readFileSync(metadataFd, 'utf8');
            let parsed;
            try {
                parsed = JSON.parse(raw);
            } catch {
                return;
            }

            const matchesIdentity =
                parsed &&
                parsed.nonce === payload.nonce &&
                parsed.ownerPid === payload.ownerPid &&
                parsed.processStartToken === payload.processStartToken &&
                parsed.schema === payload.schema &&
                parsed.runner === payload.runner;

            if (!matchesIdentity) {
                return;
            }

            const updated = {
                ...parsed,
                ownedProcesses: Array.from(
                    new Map(
                        [
                            ...(parsed.ownedProcesses || []),
                            ...processes,
                            ...globalOwnedRegistry.getAllItems(),
                        ].map((p) => [p.pid ?? p, p]),
                    ).values(),
                ),
                ownedPorts: Array.from(
                    new Set([
                        ...(parsed.ownedPorts || []),
                        ...ports,
                        ...globalOwnedRegistry.getAllPorts(),
                    ]),
                ),
            };

            if (typeof options._beforeMetadataCommit === 'function') {
                options._beforeMetadataCommit();
            }

            // Revalidate both immutable bytes and pathname-to-inode binding directly
            // before mutation. If another writer replaced or modified the lock, leave
            // its bytes untouched. The pathname is never renamed or unlinked here.
            const rawBytes = Buffer.from(raw);
            const currentBytes = Buffer.alloc(rawBytes.length);
            let bytesRead = 0;
            while (bytesRead < currentBytes.length) {
                const count = readSync(
                    metadataFd,
                    currentBytes,
                    bytesRead,
                    currentBytes.length - bytesRead,
                    bytesRead,
                );
                if (count === 0) break;
                bytesRead += count;
            }
            if (
                bytesRead !== currentBytes.length ||
                fstatSync(metadataFd).size !== rawBytes.length ||
                !currentBytes.equals(rawBytes)
            ) {
                return;
            }
            const fdStat = fstatSync(metadataFd);
            const pathStat = statSync(lockPath);
            if (fdStat.dev !== pathStat.dev || fdStat.ino !== pathStat.ino) {
                return;
            }

            const updatedBytes = Buffer.from(JSON.stringify(updated, null, 2));
            let offset = 0;
            while (offset < updatedBytes.length) {
                offset += writeSync(
                    metadataFd,
                    updatedBytes,
                    offset,
                    updatedBytes.length - offset,
                    offset,
                );
            }
            ftruncateSync(metadataFd, updatedBytes.length);
            fsyncSync(metadataFd);
        } catch {
            // best effort — metadata persistence must never throw during teardown
        } finally {
            if (metadataFd !== undefined) {
                try {
                    closeSync(metadataFd);
                } catch {
                    // best effort
                }
            }
        }
    };

    const release = () => {
        if (released) return false;
        try {
            // Conditional release: ONLY release if the file exists, is valid JSON,
            // and ALL immutable identity fields (nonce, ownerPid, processStartToken, schema, runner)
            // EXACTLY match this acquisition.
            // If the file was replaced, corrupted, or replaced by another process, DO NOT unlink.
            if (!existsSync(lockPath)) return false;

            const tempRelPath = `${lockPath}.rel-${randomUUID()}`;
            try {
                renameSync(lockPath, tempRelPath);
            } catch {
                return false;
            }

            let inspected;
            try {
                const raw = readFileSync(tempRelPath, 'utf8');
                inspected = JSON.parse(raw);
            } catch {
                restoreIsolatedLockWithoutOverwrite(
                    tempRelPath,
                    lockPath,
                    options._beforeReleaseRestore,
                );
                return false;
            }

            const matchesIdentity =
                inspected &&
                inspected.nonce === payload.nonce &&
                inspected.ownerPid === payload.ownerPid &&
                inspected.processStartToken === payload.processStartToken &&
                inspected.schema === payload.schema &&
                inspected.runner === payload.runner;

            if (matchesIdentity) {
                released = true;
                rmSync(tempRelPath, { force: true });
                return true;
            }

            restoreIsolatedLockWithoutOverwrite(
                tempRelPath,
                lockPath,
                options._beforeReleaseRestore,
            );
            return false;
        } catch {
            return false;
        }
    };

    return {
        lockPath,
        payload,
        nonce,
        updateOwnedMetadata,
        release,
    };
}
