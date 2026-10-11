<?php

namespace Tests\Unit;

use Tests\TestCase;

class PhpUnitTopologyTest extends TestCase
{
    public function test_phpunit_configuration_discovers_root_and_module_test_trees(): void
    {
        $configuration = simplexml_load_file(base_path('phpunit.xml'));

        $this->assertNotFalse($configuration);

        $directories = [];
        foreach ($configuration->testsuites->testsuite as $suite) {
            foreach ($suite->directory as $directory) {
                $directories[] = (string) $directory;
            }
        }

        $this->assertContains('tests/Unit', $directories);
        $this->assertContains('tests/Feature', $directories);
        $this->assertContains('Modules', $directories);
    }

    public function test_phpunit_configuration_forces_the_test_target_before_bootstrap(): void
    {
        $configuration = simplexml_load_file(base_path('phpunit.xml'));

        $this->assertNotFalse($configuration);

        $expected = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'mysql',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'inbils_test',
            'DB_USERNAME' => 'inbils',
            'DB_PASSWORD' => 'inbils_local_change_me',
            'DB_URL' => '',
        ];

        foreach (['env', 'server'] as $element) {
            $entries = [];
            foreach ($configuration->php->{$element} as $entry) {
                $entries[(string) $entry['name']] = [
                    'force' => (string) $entry['force'],
                    'value' => (string) $entry['value'],
                ];
            }

            foreach ($expected as $name => $value) {
                $this->assertArrayHasKey($name, $entries, "Missing PHPUnit {$element} entry for {$name}.");
                $this->assertSame('true', $entries[$name]['force'], "PHPUnit {$element} entry {$name} must be forced.");
                $this->assertSame($value, $entries[$name]['value'], "PHPUnit {$element} entry {$name} has the wrong value.");
            }

            $this->assertArrayNotHasKey('APP_CONFIG_CACHE', $entries);
            $this->assertArrayNotHasKey('LARAVEL_CONFIG_CACHE', $entries);
        }
    }

    public function test_isolated_process_environment_discards_hostile_source_values(): void
    {
        $environment = self::isolatedProcessEnvironment([], [
            'PATH' => '/safe/bin',
            'APP_ENV' => 'production',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'production.example',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'production',
            'DB_USERNAME' => 'root',
            'DB_URL' => 'mysql://root@production/production',
            'APP_CONFIG_CACHE' => '/hostile/config.php',
            'LARAVEL_CONFIG_CACHE' => '/hostile/laravel.php',
            'HOSTILE_UNRELATED_KEY' => 'must-not-pass',
        ]);

        $this->assertSame('testing', $environment['APP_ENV']);
        $this->assertSame('mysql', $environment['DB_CONNECTION']);
        $this->assertSame('mysql', $environment['DB_HOST']);
        $this->assertSame('3306', $environment['DB_PORT']);
        $this->assertSame('inbils_test', $environment['DB_DATABASE']);
        $this->assertSame('inbils', $environment['DB_USERNAME']);
        $this->assertSame('', $environment['DB_URL']);
        $this->assertArrayNotHasKey('APP_CONFIG_CACHE', $environment);
        $this->assertArrayNotHasKey('LARAVEL_CONFIG_CACHE', $environment);
        $this->assertArrayNotHasKey('HOSTILE_UNRELATED_KEY', $environment);
    }

    public function test_sanitized_subprocess_passes_the_pre_bootstrap_guard(): void
    {
        $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
Tests\TestCase::guardPreBootstrapEnvironment();
$app = require getcwd().'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$connection = $app['config']->get('database.connections.'.$app['config']->get('database.default'));
$identity = $app['db']->selectOne('SELECT DATABASE() AS selected_schema, CURRENT_USER() AS service_user');
fwrite(STDOUT, json_encode([
    'environment' => $app->environment(),
    'host' => $connection['host'] ?? null,
    'port' => (string) ($connection['port'] ?? ''),
    'database' => $connection['database'] ?? null,
    'username' => $connection['username'] ?? null,
    'url' => $connection['url'] ?? null,
    'configuration_cached' => $app->configurationIsCached(),
    'selected_schema' => $identity->selected_schema,
    'service_user' => $identity->service_user,
], JSON_THROW_ON_ERROR));
PHP;
        $environment = self::isolatedProcessEnvironment([], [
            'PATH' => (string) getenv('PATH'),
            'APP_ENV' => 'production',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'wrong.invalid',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'inbils',
            'DB_USERNAME' => 'root',
            'DB_URL' => 'mysql://root@wrong.invalid/inbils_e2e',
            'APP_CONFIG_CACHE' => '/tmp/phpunit-inert-config-cache.php',
            'LARAVEL_CONFIG_CACHE' => '/tmp/phpunit-inert-laravel-config-cache.php',
            'HOSTILE_UNRELATED_KEY' => 'must-not-pass',
        ]);

        $result = $this->runPhpSubprocess($script, $environment);

        $this->assertSame(0, $result['exit_code'], $result['output']);
        $values = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('testing', $values['environment']);
        $this->assertSame('mysql', $values['host']);
        $this->assertSame('3306', $values['port']);
        $this->assertSame('inbils_test', $values['database']);
        $this->assertSame('inbils', $values['username']);
        $this->assertTrue($values['url'] === null || $values['url'] === '');
        $this->assertFalse($values['configuration_cached']);
        $this->assertSame('inbils_test', $values['selected_schema']);
        $this->assertMatchesRegularExpression('/^inbils@[^@]+$/', $values['service_user']);
    }

    public function test_pre_bootstrap_guard_refuses_a_custom_config_cache_pointer(): void
    {
        $cacheFile = sys_get_temp_dir().'/phpunit-custom-config-cache-'.bin2hex(random_bytes(8)).'.php';
        $this->assertNotFalse(file_put_contents($cacheFile, "<?php throw new RuntimeException('Fake config cache must not be loaded.');"));

        try {
            $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
Tests\TestCase::guardPreBootstrapEnvironment();
fwrite(STDOUT, 'BOOTSTRAP_REACHED');
PHP;
            $environment = self::isolatedProcessEnvironment([], [
                'PATH' => (string) getenv('PATH'),
                'APP_ENV' => 'production',
                'DB_DATABASE' => 'production',
            ]);
            $environment['APP_CONFIG_CACHE'] = $cacheFile;

            $result = $this->runPhpSubprocess($script, $environment);

            $this->assertNotSame(0, $result['exit_code']);
            $this->assertStringContainsString('PHPUnit bootstrap safety guard refused to bootstrap Laravel.', $result['output']);
            $this->assertStringNotContainsString('BOOTSTRAP_REACHED', $result['output']);
        } finally {
            @unlink($cacheFile);
        }
    }

    public function test_pre_bootstrap_guard_refuses_a_fake_compiled_config_sentinel(): void
    {
        $root = sys_get_temp_dir().'/phpunit-bootstrap-guard-'.bin2hex(random_bytes(8));
        $cacheDirectory = $root.'/bootstrap/cache';
        $this->assertTrue(mkdir($cacheDirectory, 0700, true));
        $this->assertNotFalse(file_put_contents($cacheDirectory.'/config.php', '<?php return [];'));

        try {
            $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
Tests\TestCase::guardPreBootstrapEnvironment(getenv('PHPUNIT_FAKE_ROOT'));
fwrite(STDOUT, 'BOOTSTRAP_REACHED');
PHP;
            $environment = self::isolatedProcessEnvironment([
                'PHPUNIT_FAKE_ROOT' => $root,
            ], [
                'PATH' => (string) getenv('PATH'),
                'APP_ENV' => 'production',
                'DB_DATABASE' => 'production',
            ]);

            $result = $this->runPhpSubprocess($script, $environment);

            $this->assertNotSame(0, $result['exit_code']);
            $this->assertStringContainsString('PHPUnit bootstrap safety guard refused to bootstrap Laravel.', $result['output']);
            $this->assertStringNotContainsString('BOOTSTRAP_REACHED', $result['output']);
        } finally {
            @unlink($cacheDirectory.'/config.php');
            @rmdir($cacheDirectory);
            @rmdir($root.'/bootstrap');
            @rmdir($root);
        }
    }

    /** @return array{exit_code: int, stdout: string, output: string} */
    private function runPhpSubprocess(string $script, array $environment): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment,
        );

        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit_code' => proc_close($process),
            'stdout' => $stdout,
            'output' => $stdout.$stderr,
        ];
    }
}
