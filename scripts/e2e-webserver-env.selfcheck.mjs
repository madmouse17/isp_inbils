import assert from 'node:assert/strict';
import test from 'node:test';

import {
    buildE2EServerEnv,
    isConfigSensitiveName,
    radiusLabServerNames,
    resolvePhpBinary,
} from './e2e-server-env.mjs';

test('strips polluted parent-shell APP_ENV/DB_* and pins APP_ENV=e2e and CACHE_STORE=database', () => {
    const source = {
        APP_ENV: 'local',
        APP_DEBUG: 'true',
        DB_CONNECTION: 'mysql',
        DB_DATABASE: 'inbils',
        DB_USERNAME: 'root',
        CACHE_STORE: 'file',
        SESSION_DRIVER: 'database',
        PATH: '/usr/bin:/bin',
        SystemRoot: 'C:\\Windows',
    };

    const { env, droppedSensitiveNames } = buildE2EServerEnv(source);

    assert.equal(env.APP_ENV, 'e2e');
    // The shell said `file`; the served app always gets a cache that outlives a request.
    assert.equal(env.CACHE_STORE, 'database');
    assert.equal(env.PATH, '/usr/bin:/bin');
    assert.equal(env.SystemRoot, 'C:\\Windows');
    // Canonical contract: no DB_ keys are forwarded; .env.e2e decides.
    assert.equal(env.DB_DATABASE, undefined);

    const pinned = ['APP_ENV', 'CACHE_STORE'];
    const forwardedNames = Object.keys(env).filter((name) => !pinned.includes(name.toUpperCase()));

    for (const name of forwardedNames) {
        assert.equal(
            isConfigSensitiveName(name),
            false,
            `config-sensitive var leaked into child env: ${name}`,
        );
    }

    for (const name of [
        'APP_ENV',
        'APP_DEBUG',
        'DB_CONNECTION',
        'DB_DATABASE',
        'CACHE_STORE',
        'SESSION_DRIVER',
    ]) {
        assert.ok(droppedSensitiveNames.includes(name), `expected drop report for ${name}`);
    }
});

test('dropped-sensitive report carries names only, never values', () => {
    const secret = 'rahasia-1045-jangan-bocor';
    const { droppedSensitiveNames } = buildE2EServerEnv({
        DB_PASSWORD: secret,
        DB_DATABASE: 'inbils',
        PATH: '/usr/bin',
    });

    assert.ok(droppedSensitiveNames.includes('DB_PASSWORD'));

    const serialized = JSON.stringify(droppedSensitiveNames);

    assert.equal(serialized.includes(secret), false);
    for (const entry of droppedSensitiveNames) {
        assert.equal(entry.includes('='), false);
    }
});

test('OS allowlist passthrough is case-insensitive and preserves original casing', () => {
    const { env } = buildE2EServerEnv({ Path: 'C:\\Tools', TEMP: 'C:\\Temp' });

    assert.equal(env.Path, 'C:\\Tools');
    assert.equal(env.TEMP, 'C:\\Temp');
});

test('harness-internal vars are not forwarded to the php child', () => {
    const { env, droppedSensitiveNames } = buildE2EServerEnv({
        E2E_HOT_BACKUP: 'public/hot.e2e-123.bak',
        PATH: '/usr/bin',
    });

    assert.equal(env.E2E_HOT_BACKUP, undefined);
    assert.equal(droppedSensitiveNames.includes('E2E_HOT_BACKUP'), false);
});

test('FreeRADIUS lab vars pass through; unlisted LAB_ and RADIUS_ vars stay dropped', () => {
    const { env, droppedSensitiveNames } = buildE2EServerEnv({
        E2E_RADIUS_LAB: '1',
        LAB_RADIUS_SECRET: 'lab-shared',
        LAB_USER_SECRET: 'lab-user',
        LAB_OTHER: 'x',
        RADIUS_DB_DRIVER: 'sqlite',
        PATH: '/usr/bin',
    });

    assert.equal(env.E2E_RADIUS_LAB, '1');
    assert.equal(env.LAB_RADIUS_SECRET, 'lab-shared');
    assert.equal(env.LAB_USER_SECRET, 'lab-user');
    assert.equal(env.LAB_OTHER, undefined);
    assert.equal(env.RADIUS_DB_DRIVER, undefined);
    assert.equal(isConfigSensitiveName('E2E_RADIUS_LAB'), false);
    assert.equal(JSON.stringify(droppedSensitiveNames).includes('lab-shared'), false);
});

test('lab mode forwards every RADIUS_* var the served app needs (dropping one breaks the lab silently)', () => {
    // Literal list on purpose: removing a name from radiusLabServerNames must fail here, not at lab run time.
    const required = [
        'RADIUS_ENABLED',
        'RADIUS_DB_HOST',
        'RADIUS_DB_PORT',
        'RADIUS_DB_DATABASE',
        'RADIUS_DB_USERNAME',
        'RADIUS_DB_PASSWORD',
    ];
    const source = { E2E_RADIUS_LAB: '1', PATH: '/usr/bin' };
    for (const name of required) source[name] = `value-of-${name}`;

    assert.deepEqual([...radiusLabServerNames].sort(), [...required].sort());

    const { env, droppedSensitiveNames } = buildE2EServerEnv(source);

    for (const name of required) assert.equal(env[name], `value-of-${name}`, `${name} was dropped`);
    assert.deepEqual(droppedSensitiveNames, []);
});

test('RADIUS_* vars are dropped unless E2E_RADIUS_LAB=1', () => {
    for (const lab of [undefined, '', '0', 'true']) {
        const source = { RADIUS_ENABLED: 'true', RADIUS_DB_PASSWORD: 'x', PATH: '/usr/bin' };
        if (lab !== undefined) source.E2E_RADIUS_LAB = lab;
        const { env } = buildE2EServerEnv(source);

        assert.equal(env.RADIUS_ENABLED, undefined);
        assert.equal(env.RADIUS_DB_PASSWORD, undefined);
    }
});

test('no lab vars in the shell: env stays minimal so .env.e2e fallback decides', () => {
    const { env } = buildE2EServerEnv({ PATH: '/usr/bin' });

    assert.equal(env.E2E_RADIUS_LAB, undefined);
    assert.deepEqual(env, {
        PATH: '/usr/bin',
        APP_ENV: 'e2e',
        CACHE_STORE: 'database',
        STORAGE_ENV_SUFFIX: '/e2e',
    });
});

test('empty source env still yields a minimal explicit e2e environment and PHP fallback', () => {
    const { env, droppedSensitiveNames } = buildE2EServerEnv({});

    assert.deepEqual(env, { APP_ENV: 'e2e', CACHE_STORE: 'database', STORAGE_ENV_SUFFIX: '/e2e' });
    assert.equal(resolvePhpBinary(env.PHP_BINARY), 'php');
    assert.deepEqual(droppedSensitiveNames, []);
});

test('validated PHP_BINARY is preserved for the webserver child', () => {
    const selected = '/custom/php-safe';
    const { env } = buildE2EServerEnv({ PHP_BINARY: selected, PATH: '/usr/bin' });

    assert.equal(resolvePhpBinary(env.PHP_BINARY), selected);
    assert.equal(env.PHP_BINARY, selected);
});

test('hostile or sensitive PHP_BINARY values fail closed without value logging', () => {
    for (const value of ['', '   ', '\0php', '\x01php']) {
        assert.throws(
            () => resolvePhpBinary(value),
            (error) => {
                assert.match(error.message, /PHP executable/);
                if (value !== '') assert.equal(error.message.includes(value), false);
                return true;
            },
        );
    }

    const executable = 'php-secret-path';
    assert.equal(resolvePhpBinary(executable), executable);

    const sensitive = {
        DB_PASSWORD: 'db-secret',
        APP_DEBUG: 'app-secret',
    };
    const { env, droppedSensitiveNames } = buildE2EServerEnv(sensitive);

    assert.equal(env.DB_PASSWORD, undefined);
    assert.equal(env.APP_DEBUG, undefined);
    assert.ok(droppedSensitiveNames.includes('DB_PASSWORD'));
    assert.ok(droppedSensitiveNames.includes('APP_DEBUG'));
    const serialized = JSON.stringify({ env, droppedSensitiveNames });
    assert.equal(serialized.includes(sensitive.DB_PASSWORD), false);
    assert.equal(serialized.includes(sensitive.APP_DEBUG), false);
});

test('builder output is deterministic for identical input', () => {
    const source = {
        APP_ENV: 'production',
        DB_PASSWORD: 'x',
        SystemRoot: 'C:\\Windows',
        COMSPEC: 'C:\\Windows\\system32\\cmd.exe',
    };

    assert.deepEqual(buildE2EServerEnv(source), buildE2EServerEnv(source));
});

test('hostile shell DB_* stay dropped and no E2E_DATABASE is forwarded', () => {
    const { env, droppedSensitiveNames } = buildE2EServerEnv({
        APP_ENV: 'production',
        APP_DEBUG: 'true',
        DB_CONNECTION: 'mysql',
        DB_DATABASE: 'hostile_shared',
        DB_USERNAME: 'polluted_user',
        PATH: '/usr/bin',
    });

    assert.equal(env.APP_ENV, 'e2e');
    assert.equal(env.E2E_DATABASE, undefined);
    assert.equal(env.DB_DATABASE, undefined);
    assert.equal(env.DB_USERNAME, undefined);
    assert.equal(env.DB_CONNECTION, undefined);
    assert.ok(droppedSensitiveNames.includes('DB_DATABASE'));
    assert.ok(droppedSensitiveNames.includes('DB_USERNAME'));
    assert.ok(droppedSensitiveNames.includes('APP_DEBUG'));
});

test('polluted shell keeps only APP_ENV; no disposable schema is ever forwarded', () => {
    const { env } = buildE2EServerEnv({
        APP_ENV: 'local',
        DB_CONNECTION: 'mysql',
        DB_HOST: '10.9.8.7',
        DB_PORT: '3306',
        DB_DATABASE: 'inbils',
        DB_USERNAME: 'poison',
        SESSION_DRIVER: 'database',
        PATH: '/usr/bin',
    });

    assert.equal(env.APP_ENV, 'e2e');
    for (const name of ['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_DATABASE', 'SESSION_DRIVER']) {
        assert.equal(env[name], undefined, `${name} must stay dropped`);
    }
});

test('empty E2E_DATABASE is ignored so .env.e2e keeps deciding', () => {
    const { env } = buildE2EServerEnv({ E2E_DATABASE: '', PATH: '/usr/bin' });

    assert.equal(env.DB_DATABASE, undefined);
    assert.equal(env.APP_ENV, 'e2e');
});

test('any non-empty E2E_DATABASE is refused, including the legacy temporary namespace', () => {
    for (const candidate of [
        'inbils_e2e',
        'inbils',
        'hermes_qa_t_other_1',
        '../etc',
        'hermes_qa_t_0bd1699e_1311',
    ]) {
        assert.throws(
            () => buildE2EServerEnv({ E2E_DATABASE: candidate }),
            /E2E_DATABASE is no longer supported/,
            `expected refusal for ${candidate}`,
        );
    }
});
