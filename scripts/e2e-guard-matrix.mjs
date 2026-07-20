import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';

const php = process.env.PHP_BINARY || 'php';
const setup = 'tests/e2e/setup.php';
const configCache = 'bootstrap/cache/config.php';
const runtimeDir = 'tests/e2e/.runtime';
const appConfigCache = `${runtimeDir}/app-config-cache.php`;
const keepEnv = [
    'PATH',
    'Path',
    'SystemRoot',
    'WINDIR',
    'TEMP',
    'TMP',
    'COMSPEC',
    'PATHEXT',
    'ProgramFiles',
    'ProgramFiles(x86)',
    'ProgramW6432',
];

const baseEnv = Object.fromEntries(
    keepEnv.filter((key) => process.env[key]).map((key) => [key, process.env[key]]),
);

const safeEnv = (env) => ({
    ...baseEnv,
    APP_ENV: 'e2e',
    DB_CONNECTION: 'mysql',
    DB_HOST: '127.0.0.1',
    DB_PORT: '3306',
    DB_USERNAME: 'root',
    DB_PASSWORD: '',
    CACHE_STORE: 'array',
    QUEUE_CONNECTION: 'sync',
    SESSION_DRIVER: 'array',
    ...env,
});

const run = (name, env, wantCode) => {
    const result = spawnSync(php, [setup, '--check'], {
        env: safeEnv(env),
        encoding: 'utf8',
        windowsHide: true,
    });
    const output = `${result.stdout}${result.stderr}`.trim();

    if (result.status !== wantCode) {
        throw new Error(
            `${name}: expected exit ${wantCode}, got ${result.status ?? 'null'}\n${output}`,
        );
    }

    console.log(`${name}: exit ${result.status} ${firstLine(output)}`);
};

const firstLine = (output) => output.split(/\r?\n/).find(Boolean) ?? '(no output)';

run('valid e2e database', { APP_ENV: 'e2e', DB_DATABASE: 'inbils_e2e' }, 0);
run('dev database rejected', { APP_ENV: 'e2e', DB_DATABASE: 'inbils' }, 1);
run('testing database rejected', { APP_ENV: 'e2e', DB_DATABASE: 'inbils_testing' }, 1);
run(
    'DB_URL override rejected',
    {
        APP_ENV: 'e2e',
        DB_DATABASE: 'inbils_e2e',
        DB_URL: 'mysql://root@127.0.0.1:3306/inbils',
    },
    1,
);
run('non-e2e app env rejected', { APP_ENV: 'local', DB_DATABASE: 'inbils_e2e' }, 1);

mkdirSync(runtimeDir, { recursive: true });
writeFileSync(appConfigCache, '<?php return [];\n');
try {
    run(
        'APP_CONFIG_CACHE override rejected',
        { APP_ENV: 'e2e', DB_DATABASE: 'inbils_e2e', APP_CONFIG_CACHE: appConfigCache },
        1,
    );
} finally {
    rmSync(appConfigCache, { force: true });
}

if (existsSync(configCache)) {
    run('cached config rejected', { APP_ENV: 'e2e', DB_DATABASE: 'inbils_e2e' }, 1);
} else {
    writeFileSync(configCache, '<?php return [];\n', { flag: 'wx' });
    try {
        run('cached config rejected', { APP_ENV: 'e2e', DB_DATABASE: 'inbils_e2e' }, 1);
    } finally {
        rmSync(configCache, { force: true });
    }
}
