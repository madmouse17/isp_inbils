<?php

namespace Tests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use InvalidArgumentException;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const REFUSAL = 'PHPUnit bootstrap safety guard refused to bootstrap Laravel.';

    /** The one schema of every serial run, and the base of a worker's own: Laravel's parallel runner names that one `{base}_test_{token}`. */
    private const BASE_SCHEMA = 'inbils_test';

    /** What phpunit.xml pins as STORAGE_ENV_SUFFIX; a parallel worker adds `_{token}`. */
    private const BASE_STORAGE_SUFFIX = '/testing';

    public function createApplication(): Application
    {
        self::guardPreBootstrapEnvironment();
        self::pinStorageSuffix();

        return parent::createApplication();
    }

    public static function guardPreBootstrapEnvironment(?string $rootPath = null): void
    {
        $forbiddenOverrides = ['APP_CONFIG_CACHE', 'LARAVEL_CONFIG_CACHE', 'DB_URL'];
        foreach ($forbiddenOverrides as $key) {
            foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
                if ($value !== false && $value !== null && $value !== '') {
                    throw new RuntimeException(self::REFUSAL);
                }
            }
        }

        foreach ($forbiddenOverrides as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $required = [
            'APP_ENV' => ['testing'],
            'DB_CONNECTION' => ['mysql'],
            'DB_HOST' => ['mysql'],
            'DB_PORT' => ['3306'],
            'DB_DATABASE' => self::permittedSchemas(),
            'DB_USERNAME' => ['inbils'],
        ];

        foreach ($required as $key => $allowed) {
            if (! in_array(getenv($key), $allowed, true)) {
                throw new RuntimeException(self::REFUSAL);
            }

            foreach ([$_ENV, $_SERVER] as $source) {
                if (array_key_exists($key, $source) && ! in_array($source[$key], $allowed, true)) {
                    throw new RuntimeException(self::REFUSAL);
                }
            }
        }

        $rootPath ??= dirname(__DIR__);
        if (is_file(rtrim($rootPath, DIRECTORY_SEPARATOR).'/bootstrap/cache/config.php')) {
            throw new RuntimeException(self::REFUSAL);
        }
    }

    /**
     * The environment of a child process (a race worker): the same pinned target as this test, so it joins the schema
     * and the storage tree of the test that spawned it, and the parallel worker number the guard needs to accept them.
     */
    public static function isolatedProcessEnvironment(array $additional = [], ?array $source = null): array
    {
        $environment = [];
        foreach (['PATH', 'HOME', 'TMPDIR', 'TMP', 'TEMP', 'APP_KEY'] as $key) {
            $value = $source === null ? getenv($key) : ($source[$key] ?? false);
            if (is_string($value)) {
                $environment[$key] = $value;
            }
        }

        $token = self::parallelToken();
        $pinned = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'mysql',
            'DB_PORT' => '3306',
            'DB_DATABASE' => self::activeSchema(),
            'DB_USERNAME' => 'inbils',
            'DB_PASSWORD' => 'inbils_local_change_me',
            'DB_URL' => '',
            'STORAGE_ENV_SUFFIX' => self::storageSuffix(),
            'LOG_CHANNEL' => 'stderr',
            'CACHE_STORE' => 'array',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
            ...($token === null ? [] : ['TEST_TOKEN' => $token, 'LARAVEL_PARALLEL_TESTING' => '1']),
        ];

        foreach ($additional as $key => $value) {
            if (array_key_exists($key, $pinned) || in_array($key, ['APP_CONFIG_CACHE', 'LARAVEL_CONFIG_CACHE'], true)) {
                throw new InvalidArgumentException('Pinned PHPUnit subprocess environment values cannot be overridden.');
            }
        }

        return array_merge($environment, $pinned, $additional);
    }

    /**
     * The worker number Laravel's parallel runner gave this process (TEST_TOKEN), or null in a serial run. A TEST_TOKEN
     * without the runner's own flag is plain paratest, whose workers would all share the base schema: refused.
     */
    private static function parallelToken(): ?string
    {
        $token = getenv('TEST_TOKEN');

        if ($token === false) {
            return null;
        }

        if (preg_match('/^[1-9][0-9]{0,2}$/D', $token) !== 1 || ! getenv('LARAVEL_PARALLEL_TESTING')) {
            throw new RuntimeException(self::REFUSAL);
        }

        return $token;
    }

    /**
     * Laravel switches a worker's default connection to its own schema through config() only, so the process
     * environment keeps the base name; a child spawned by a worker may carry either.
     *
     * @return list<string>
     */
    private static function permittedSchemas(): array
    {
        $token = self::parallelToken();

        return $token === null ? [self::BASE_SCHEMA] : [self::BASE_SCHEMA, self::BASE_SCHEMA.'_test_'.$token];
    }

    /** The schema this test talks to: the base one, or its worker's own once the runner switched its connection to it. */
    private static function activeSchema(): string
    {
        $schema = config('database.connections.'.config('database.default').'.database');

        if (! in_array($schema, self::permittedSchemas(), true)) {
            throw new RuntimeException(self::REFUSAL);
        }

        return $schema;
    }

    /**
     * One storage tree per worker. Every worker database hands out the same company and media ids again, so workers on
     * one tree would overwrite and delete each other's files. config/filesystems.php reads the key at boot, so it is
     * set before the application is created.
     */
    private static function pinStorageSuffix(): void
    {
        $suffix = self::storageSuffix();

        putenv("STORAGE_ENV_SUFFIX={$suffix}");
        $_ENV['STORAGE_ENV_SUFFIX'] = $_SERVER['STORAGE_ENV_SUFFIX'] = $suffix;
    }

    private static function storageSuffix(): string
    {
        $token = self::parallelToken();

        return self::BASE_STORAGE_SUFFIX.($token === null ? '' : "_{$token}");
    }

    protected function tearDown(): void
    {
        CompanyService::resetCache();

        parent::tearDown();
    }
}
