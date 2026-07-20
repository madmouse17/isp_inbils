<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConfigurationUrlParser;

const E2E_DATABASE = 'inbils_e2e';

$checkOnly = in_array('--check', $argv, true);
$root = dirname(__DIR__, 2);

rejectCachedConfig($root);
rejectProcessAppEnv();
rejectProcessDatabaseUrl();

$environmentFile = $root.'/.env.e2e';

if (! is_file($environmentFile)) {
    fwrite(STDERR, "E2E setup requires .env.e2e copied from .env.e2e.example.\n");
    exit(1);
}

$_SERVER['argv'][] = '--env=e2e';

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

guardE2eApp($app);
[$connectionName, $database] = guardE2eDatabase();

if ($checkOnly) {
    fwrite(STDOUT, "E2E database guard passed for {$connectionName}/{$database}.\n");
    exit(0);
}

$kernel = $app->make(Kernel::class);

$exitCode = $kernel->call('migrate:fresh', [
    '--seed' => true,
    '--seeder' => 'Database\Seeders\DatabaseSeeder',
    '--force' => true,
]);

if ($exitCode === 0) {
    $exitCode = $kernel->call('db:seed', [
        '--class' => 'Database\Seeders\DemoUserSeeder',
        '--force' => true,
    ]);
}

if ($exitCode === 0) {
    $exitCode = $kernel->call('db:seed', [
        '--class' => 'Database\Seeders\E2eFixtureSeeder',
        '--force' => true,
    ]);
}

exit($exitCode);

function rejectCachedConfig(string $root): void
{
    foreach (['APP_CONFIG_CACHE', 'LARAVEL_CONFIG_CACHE'] as $key) {
        if (nonEmptyEnvValue($key) !== null) {
            fwrite(STDERR, "Refusing E2E reset with custom Laravel config cache path; unset {$key}.\n");
            exit(1);
        }
    }

    if (is_file($root.'/bootstrap/cache/config.php')) {
        fwrite(STDERR, "Refusing E2E reset with cached Laravel config; run php artisan config:clear first.\n");
        exit(1);
    }
}

function rejectProcessAppEnv(): void
{
    $appEnv = nonEmptyEnvValue('APP_ENV');

    if ($appEnv !== null && $appEnv !== 'e2e') {
        fwrite(STDERR, "Refusing E2E reset: APP_ENV must be exactly e2e.\n");
        exit(1);
    }
}

function rejectProcessDatabaseUrl(): void
{
    if (nonEmptyEnvValue('DB_URL') !== null) {
        fwrite(STDERR, "Refusing E2E reset: DB_URL must be unset; use DB_DATABASE=inbils_e2e.\n");
        exit(1);
    }
}

function guardE2eApp($app): void
{
    if ($app->environment() !== 'e2e') {
        fwrite(STDERR, "Refusing E2E reset: APP_ENV must be exactly e2e.\n");
        exit(1);
    }
}

/** @return array{0: string, 1: string} */
function guardE2eDatabase(): array
{
    $connectionName = (string) config('database.default');
    $connection = config("database.connections.{$connectionName}");

    if (! is_array($connection)) {
        fwrite(STDERR, "Refusing E2E reset: default DB connection is invalid.\n");
        exit(1);
    }

    if (nonEmptyConfigValue($connection['url'] ?? null) !== null || nonEmptyEnvValue('DB_URL') !== null) {
        fwrite(STDERR, "Refusing E2E reset: DB_URL must be unset; use DB_DATABASE=inbils_e2e.\n");
        exit(1);
    }

    $effective = (new ConfigurationUrlParser())->parseConfiguration($connection);
    $database = (string) ($effective['database'] ?? '');

    if ($database !== E2E_DATABASE) {
        fwrite(STDERR, 'Refusing E2E reset: effective database must be '.E2E_DATABASE.".\n");
        exit(1);
    }

    return [$connectionName, $database];
}

function nonEmptyEnvValue(string $key): ?string
{
    foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
        if (nonEmptyConfigValue($value) !== null) {
            return (string) $value;
        }
    }

    return null;
}

function nonEmptyConfigValue(mixed $value): ?string
{
    if ($value === false || $value === null) {
        return null;
    }

    $value = trim((string) $value);

    return $value === '' ? null : $value;
}
