<?php

namespace Tests\Feature\Operations;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * log-audit LA-AUDIT-CORE, D7: the request id leaves the app (outbound calls, API error bodies) so a report from a
 * client or a vendor finds its log lines, and a slow query leaves a line without the values it ran with.
 */
class RequestCorrelationTest extends TestCase
{
    public function test_outbound_http_carries_the_context_request_id(): void
    {
        Context::add('request_id', 'ctx-req-42');
        Http::fake();

        Http::get('https://vendor.example.test/ping');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Request-Id', 'ctx-req-42'));
    }

    public function test_outbound_http_without_a_request_id_adds_no_header(): void
    {
        Http::fake();

        Http::get('https://vendor.example.test/ping');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Request-Id'));
    }

    public function test_a_query_over_500_ms_logs_db_slow_query_without_bindings(): void
    {
        $records = [];
        Log::listen(function (MessageLogged $log) use (&$records): void {
            $records[] = $log;
        });
        $connection = DB::connection();

        event(new QueryExecuted('select * from customers where phone = ?', ['081200000000'], 600.0, $connection));
        event(new QueryExecuted('select * from customers where id = ?', [7], 500.0, $connection));

        $slow = collect($records)->filter(fn (MessageLogged $log): bool => $log->message === 'db.slow_query')->values();
        $this->assertCount(1, $slow, 'exactly 500 ms is not slow');
        $this->assertSame('warning', $slow[0]->level);
        $this->assertEquals(
            ['ms' => 600.0, 'sql' => 'select * from customers where phone = ?', 'connection' => $connection->getName()],
            $slow[0]->context,
        );
        $this->assertStringNotContainsString('081200000000', json_encode($slow[0]->context));
    }

    public function test_api_errors_carry_the_request_id_of_the_response_header(): void
    {
        foreach (['/api/v1/nothing-here' => 404, '/api/v1/me' => 401] as $uri => $status) {
            $generated = $this->getJson($uri)->assertStatus($status);
            $this->assertNotEmpty($generated->headers->get('X-Request-Id'));
            $this->assertSame($generated->headers->get('X-Request-Id'), $generated->json('request_id'), $uri);

            $supplied = $this->getJson($uri, ['X-Request-Id' => 'client-req-9'])->assertStatus($status);
            $supplied->assertHeader('X-Request-Id', 'client-req-9')->assertJsonPath('request_id', 'client-req-9');
        }
    }
}
