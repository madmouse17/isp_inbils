<?php

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RequestIdMiddlewareTest extends TestCase
{
    public function test_accepts_incoming_x_request_id_and_echoes_on_response(): void
    {
        $response = $this->get('/ready', [
            'X-Request-Id' => 'req-fixed-123',
        ]);

        $response->assertHeader('X-Request-Id', 'req-fixed-123');
    }

    public function test_accepts_x_correlation_id_when_request_id_absent(): void
    {
        $response = $this->get('/ready', [
            'X-Correlation-Id' => 'corr-456',
        ]);

        $response->assertHeader('X-Request-Id', 'corr-456');
    }

    public function test_generates_uuid_when_no_incoming_id(): void
    {
        $response = $this->get('/ready');

        $id = $response->headers->get('X-Request-Id');
        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id
        );
    }

    public function test_rejects_unsafe_incoming_id_and_generates_new(): void
    {
        $response = $this->get('/ready', [
            'X-Request-Id' => "bad\ninjection",
        ]);

        $id = $response->headers->get('X-Request-Id');
        $this->assertNotSame("bad\ninjection", $id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id
        );
    }

    public function test_binds_request_id_into_log_context(): void
    {
        Log::shouldReceive('shareContext')
            ->once()
            ->withArgs(function (array $context): bool {
                return ($context['request_id'] ?? null) === 'req-log-bind';
            });

        // Allow any other Log calls during request
        Log::shouldReceive('withContext')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('channel')->zeroOrMoreTimes()->andReturnSelf();

        $this->get('/ready', ['X-Request-Id' => 'req-log-bind']);
    }
}
