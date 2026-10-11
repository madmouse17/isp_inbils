<?php

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_testing_database_configuration_and_connection_are_isolated(): void
    {
        $environment = $this->app->environment();

        $this->assertSame('testing', $environment);
        $this->assertFalse($this->app->configurationIsCached());

        $defaultConnection = config('database.default');
        $this->assertSame('mysql', $defaultConnection);

        $connection = config('database.connections.'.$defaultConnection);
        $this->assertIsArray($connection);
        $this->assertSame('mysql', $connection['driver'] ?? null);

        $host = $connection['host'] ?? null;
        $port = (string) ($connection['port'] ?? '');
        $configuredSchema = $connection['database'] ?? null;
        $username = $connection['username'] ?? null;
        $url = $connection['url'] ?? null;
        $unixSocket = $connection['unix_socket'] ?? null;

        $this->assertSame('mysql', $host);
        $this->assertSame('3306', $port);
        $this->assertSame('inbils_test', $configuredSchema);
        $this->assertSame('inbils', $username);
        $this->assertTrue($url === null || $url === '');
        $this->assertTrue($unixSocket === null || $unixSocket === '');

        $identity = DB::selectOne(
            'SELECT DATABASE() AS selected_schema, CURRENT_USER() AS service_user'
        );
        $selectedSchema = (string) $identity->selected_schema;
        $serviceUser = (string) $identity->service_user;

        $this->assertSame('inbils_test', $selectedSchema);
        $this->assertMatchesRegularExpression('/^inbils@[^@]+$/', $serviceUser);

        fwrite(
            STDOUT,
            sprintf(
                "environment=%s host=%s port=%s configured_schema=%s selected_schema=%s service_user=%s\n",
                $environment,
                $host,
                $port,
                $configuredSchema,
                $selectedSchema,
                $serviceUser,
            ),
        );
    }
}
