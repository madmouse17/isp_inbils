<?php

namespace Tests\Feature\Operations;

use App\Support\Observability\ReadinessChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadinessEndpointTest extends TestCase
{
    public function test_up_returns_successful_liveness(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
    }

    public function test_ready_returns_json_ready_when_core_deps_up(): void
    {
        Cache::put(ReadinessChecker::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String(), 120);

        $response = $this->getJson('/ready');

        $response->assertOk();
        $json = $response->json();
        $this->assertSame('ready', $json['status']);
        $this->assertArrayHasKey('checks', $json);
        $this->assertSame('ok', $json['checks']['database']);
        $this->assertSame('ok', $json['checks']['cache']);

        $body = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase('password', $body);
        $this->assertStringNotContainsStringIgnoringCase('127.0.0.1', $body);
        $this->assertStringNotContainsStringIgnoringCase('mysql', $body);
        $this->assertStringNotContainsStringIgnoringCase('PDO', $body);
        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $body);
    }

    public function test_scheduler_heartbeat_command_makes_ready_probe_report_ok(): void
    {
        // The public API cannot establish scheduler state without adding an unsafe mutation route.
        Cache::forget(ReadinessChecker::SCHEDULER_HEARTBEAT_KEY);

        $this->artisan('ops:scheduler-heartbeat')->assertSuccessful();

        $this->getJson('/ready')
            ->assertOk()
            ->assertJsonPath('checks.scheduler', 'ok');
    }

    public function test_ready_body_is_public_safe_shape(): void
    {
        Cache::put(ReadinessChecker::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String(), 120);

        $response = $this->getJson('/ready');

        $response->assertOk();
        $json = $response->json();
        $this->assertSame(['status', 'checks'], array_keys($json));
        $this->assertContains($json['status'], ['ready', 'degraded', 'not_ready']);
        foreach ($json['checks'] as $value) {
            $this->assertContains($value, ['ok', 'fail', 'unknown']);
        }
    }

    public function test_ready_returns_not_ready_without_leaking_internals_when_db_down(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->andThrow(new \RuntimeException('SQLSTATE[HY000] Connection refused host=127.0.0.1 password=secret'));

        // Allow other DB facade calls if any (connection name etc.) — only select is required path
        DB::shouldReceive('getDefaultConnection')->zeroOrMoreTimes();
        DB::shouldReceive('connection')->zeroOrMoreTimes();

        $response = $this->getJson('/ready');

        $response->assertStatus(503);
        $json = $response->json();
        $this->assertSame('not_ready', $json['status']);
        $this->assertSame('fail', $json['checks']['database'] ?? null);

        $body = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase('password', $body);
        $this->assertStringNotContainsStringIgnoringCase('127.0.0.1', $body);
        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $body);
        $this->assertStringNotContainsStringIgnoringCase('Connection refused', $body);
        $this->assertStringNotContainsStringIgnoringCase('secret', $body);
    }

    public function test_ready_degraded_when_cache_fails_but_db_ok(): void
    {
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('cache write failed host=redis'));
        Cache::shouldReceive('get')->andReturn(null);

        $response = $this->getJson('/ready');

        $response->assertOk();
        $json = $response->json();
        $this->assertSame('degraded', $json['status']);
        $this->assertSame('ok', $json['checks']['database']);
        $this->assertSame('fail', $json['checks']['cache']);

        $body = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase('redis', $body);
        $this->assertStringNotContainsStringIgnoringCase('cache write failed', $body);
    }
}
