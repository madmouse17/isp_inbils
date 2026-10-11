<?php

namespace Tests;

use App\Services\Core\CompanyService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use InvalidArgumentException;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        self::guardPreBootstrapEnvironment();

        return parent::createApplication();
    }

    public static function guardPreBootstrapEnvironment(?string $rootPath = null): void
    {
        $forbiddenOverrides = ['APP_CONFIG_CACHE', 'LARAVEL_CONFIG_CACHE', 'DB_URL'];
        foreach ($forbiddenOverrides as $key) {
            foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
                if ($value !== false && $value !== null && $value !== '') {
                    throw new RuntimeException('PHPUnit bootstrap safety guard refused to bootstrap Laravel.');
                }
            }
        }

        foreach ($forbiddenOverrides as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $required = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'mysql',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'inbils_test',
            'DB_USERNAME' => 'inbils',
        ];

        foreach ($required as $key => $expected) {
            if (getenv($key) !== $expected) {
                throw new RuntimeException('PHPUnit bootstrap safety guard refused to bootstrap Laravel.');
            }

            foreach ([$_ENV, $_SERVER] as $source) {
                if (array_key_exists($key, $source) && $source[$key] !== $expected) {
                    throw new RuntimeException('PHPUnit bootstrap safety guard refused to bootstrap Laravel.');
                }
            }
        }

        $rootPath ??= dirname(__DIR__);
        if (is_file(rtrim($rootPath, DIRECTORY_SEPARATOR).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('PHPUnit bootstrap safety guard refused to bootstrap Laravel.');
        }
    }

    public static function isolatedProcessEnvironment(array $additional = [], ?array $source = null): array
    {
        $environment = [];
        foreach (['PATH', 'HOME', 'TMPDIR', 'TMP', 'TEMP', 'APP_KEY'] as $key) {
            $value = $source === null ? getenv($key) : ($source[$key] ?? false);
            if (is_string($value)) {
                $environment[$key] = $value;
            }
        }

        $pinned = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'mysql',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'inbils_test',
            'DB_USERNAME' => 'inbils',
            'DB_PASSWORD' => 'inbils_local_change_me',
            'DB_URL' => '',
            'LOG_CHANNEL' => 'stderr',
            'CACHE_STORE' => 'array',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ];

        foreach ($additional as $key => $value) {
            if (array_key_exists($key, $pinned) || in_array($key, ['APP_CONFIG_CACHE', 'LARAVEL_CONFIG_CACHE'], true)) {
                throw new InvalidArgumentException('Pinned PHPUnit subprocess environment values cannot be overridden.');
            }
        }

        return array_merge($environment, $pinned, $additional);
    }

    protected function tearDown(): void
    {
        CompanyService::resetCache();

        parent::tearDown();
    }
}
