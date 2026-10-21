<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Tests\TestCase;

/**
 * `artisan test --parallel` gives every worker a schema of its own, `inbils_test_test_<N>`. A database test must run on
 * it, never on the base `inbils_test` that the E2E suite, the trait-free tests and the other workers use.
 */
class ParallelTestDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_database_test_runs_on_the_schema_of_its_own_worker(): void
    {
        $token = ParallelTesting::token();
        $expected = $token === false ? 'inbils_test' : "inbils_test_test_{$token}";

        $this->assertSame($expected, config('database.connections.mysql.database'));
        $this->assertSame($expected, DB::scalar('SELECT DATABASE()'));
        $this->assertSame($expected, self::isolatedProcessEnvironment()['DB_DATABASE'], 'a race worker must join the schema of the test that starts it');
    }
}
