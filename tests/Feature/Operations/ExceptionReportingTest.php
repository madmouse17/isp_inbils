<?php

namespace Tests\Feature\Operations;

use App\Support\Observability\ExceptionReporter;
use App\Support\Observability\LogRedactor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ExceptionReportingTest extends TestCase
{
    public function test_reporter_redacts_context_and_includes_request_id(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'boom')
                    && ($context['request_id'] ?? null) === 'rid-1'
                    && ($context['password'] ?? null) === '[REDACTED]'
                    && ($context['safe'] ?? null) === 'ok';
            });

        $reporter = new ExceptionReporter();
        $reporter->report(new RuntimeException('boom'), [
            'request_id' => 'rid-1',
            'password' => 'super-secret',
            'safe' => 'ok',
        ]);
    }

    public function test_http_error_response_does_not_leak_exception_message(): void
    {
        Route::get('/__ops_test_explode', function () {
            throw new RuntimeException('TOP_SECRET_DB_DSN=mysql://root:hunter2@db/app');
        })->middleware('web');

        $response = $this->get('/__ops_test_explode');

        $response->assertStatus(500);
        $body = $response->getContent();
        $this->assertStringNotContainsString('TOP_SECRET_DB_DSN', $body);
        $this->assertStringNotContainsString('hunter2', $body);
    }

    public function test_log_redactor_used_by_reporter_is_same_contract(): void
    {
        $this->assertSame('[REDACTED]', LogRedactor::redact(['token' => 'x'])['token']);
    }
}
