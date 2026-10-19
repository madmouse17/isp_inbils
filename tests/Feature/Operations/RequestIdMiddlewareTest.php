<?php

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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

    public function test_rejects_an_id_outside_the_token_alphabet_or_over_128_characters(): void
    {
        foreach (['has space', 'semi;colon', '<script>', 'quo"te', str_repeat('a', 129)] as $unsafe) {
            $id = $this->get('/ready', ['X-Request-Id' => $unsafe])->headers->get('X-Request-Id');

            $this->assertNotSame($unsafe, $id);
            $this->assertTrue(Str::isUuid($id));
        }

        $this->get('/ready', ['X-Request-Id' => 'Svc.a_b:c-1'])->assertHeader('X-Request-Id', 'Svc.a_b:c-1');
    }

    public function test_request_id_is_put_in_the_context(): void
    {
        // The Context reaches every log line (extra.request_id) and the jobs the request dispatches.
        Route::get('/__request-id-probe', fn (): string => (string) Context::get('request_id'));

        $this->get('/__request-id-probe', ['X-Request-Id' => 'req-log-bind'])->assertContent('req-log-bind');
    }
}
