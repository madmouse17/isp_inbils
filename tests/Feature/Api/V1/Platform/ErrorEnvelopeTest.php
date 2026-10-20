<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Modules\Inventory\Exceptions\InsufficientStockException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Every error under api/* is JSON {message, code, errors?, request_id} whatever the Accept header says, with the status
 * mapped to a stable snake_case code and nothing of the exception (class, SQL, trace) in the body.
 */
class ErrorEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/_probe')->group(function (): void {
            Route::get('conflict', fn () => throw new ConflictHttpException('Item changed by someone else.'));
            Route::get('forbidden', fn () => abort(403, 'You may not.'));
            Route::get('validation', fn (Request $request) => $request->validate(['name' => ['required'], 'age' => ['integer']]));
            Route::get('missing-model', fn () => User::query()->findOrFail(0));
            Route::get('stock', fn () => throw InsufficientStockException::forShortfall(5, 2, 'Kabel Drop', 'm', 'Gudang A'));
            Route::get('domain', fn () => throw new ApiException(403, 'app_not_allowed', 'Not in this app.', ['X-Probe' => 'yes']));
            Route::get('payload', fn () => abort(413));
            Route::get('gone', fn () => abort(410, 'Retired.'));
            Route::get('boom', fn () => throw new RuntimeException('SQLSTATE[HY000]: select * from users where secret = 1'));
            Route::get('limited', fn () => 'ok')->middleware('throttle:1,1');
            Route::get('guarded', fn () => 'ok')->middleware('auth:sanctum');
        });
    }

    public function test_an_unknown_route_is_json_even_when_the_client_asks_for_html(): void
    {
        $response = $this->get('/api/v1/nothing-here', ['Accept' => 'text/html']);

        $this->assertEnvelope($response, 404, 'not_found');
    }

    public function test_a_wrong_method_is_a_405_with_the_allow_header(): void
    {
        $response = $this->postJson('/api/v1/me');

        $this->assertEnvelope($response, 405, 'method_not_allowed');
        $this->assertNotEmpty($response->headers->get('Allow'));
    }

    public function test_a_missing_token_is_a_401(): void
    {
        $this->assertEnvelope($this->getJson('/api/v1/_probe/guarded'), 401, 'unauthenticated');
        $this->assertEnvelope($this->get('/api/v1/_probe/guarded', ['Accept' => 'text/html']), 401, 'unauthenticated');
    }

    public function test_an_explicit_abort_keeps_its_message_under_the_default_code_of_its_status(): void
    {
        $response = $this->getJson('/api/v1/_probe/forbidden');

        $this->assertEnvelope($response, 403, 'forbidden');
        $response->assertJsonPath('message', 'You may not.');
    }

    public function test_a_missing_model_is_a_404_that_names_no_class(): void
    {
        $response = $this->getJson('/api/v1/_probe/missing-model');

        $this->assertEnvelope($response, 404, 'not_found');
        $this->assertStringNotContainsString('App\\Models', $response->getContent());
        $this->assertStringNotContainsString('No query results', $response->getContent());
    }

    public function test_a_conflict_answers_code_conflict_not_the_web_error_key(): void
    {
        $response = $this->getJson('/api/v1/_probe/conflict');

        $this->assertEnvelope($response, 409, 'conflict');
        $response->assertJsonPath('message', 'Item changed by someone else.')->assertJsonMissingPath('error');
    }

    public function test_validation_failures_carry_the_errors_object(): void
    {
        $response = $this->getJson('/api/v1/_probe/validation?age=abc');

        $this->assertEnvelope($response, 422, 'validation_failed');
        $response->assertJsonValidationErrors(['name', 'age']);
    }

    public function test_an_insufficient_stock_refusal_is_a_422_unprocessable(): void
    {
        $this->app->make(ExceptionHandler::class)->dontReport(InsufficientStockException::class);

        $response = $this->getJson('/api/v1/_probe/stock');

        $this->assertEnvelope($response, 422, 'unprocessable');
        $response->assertJsonPath('message', 'Stok Kabel Drop di Gudang A tersisa 2 m, dibutuhkan 5 m.');
    }

    public function test_a_domain_exception_carries_its_own_code_and_headers(): void
    {
        $response = $this->getJson('/api/v1/_probe/domain');

        $this->assertEnvelope($response, 403, 'app_not_allowed');
        $response->assertJsonPath('message', 'Not in this app.')->assertHeader('X-Probe', 'yes');
    }

    public function test_the_statuses_the_apps_meet_have_fixed_codes_and_the_rest_fall_back_by_class(): void
    {
        $this->assertEnvelope($this->getJson('/api/v1/_probe/payload'), 413, 'payload_too_large');
        $this->assertEnvelope($this->getJson('/api/v1/_probe/gone'), 410, 'client_error');
    }

    public function test_a_throttled_call_is_a_429_with_retry_after(): void
    {
        $this->getJson('/api/v1/_probe/limited')->assertOk();

        $response = $this->getJson('/api/v1/_probe/limited');

        $this->assertEnvelope($response, 429, 'too_many_requests');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_a_server_error_leaks_nothing_with_debug_off(): void
    {
        config(['app.debug' => false]);
        $this->app->make(ExceptionHandler::class)->dontReport(RuntimeException::class);

        $response = $this->getJson('/api/v1/_probe/boom');

        $this->assertEnvelope($response, 500, 'server_error');
        $response->assertJsonPath('message', 'Server Error');
        foreach (['SQLSTATE', 'select * from users', 'RuntimeException', 'trace', 'file', 'line', 'exception'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }

    public function test_web_routes_keep_their_own_error_pages(): void
    {
        $response = $this->get('/definitely-not-a-page', ['Accept' => 'text/html']);

        $response->assertNotFound();
        $this->assertNotSame('application/json', $response->headers->get('Content-Type'));
    }

    private function assertEnvelope(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertHeader('Content-Type', 'application/json');
        $response->assertJsonPath('code', $code);
        $this->assertIsString($response->json('message'));
        $this->assertNotSame('', $response->json('message'));
        $this->assertSame(
            $status === 422 && $code === 'validation_failed' ? ['code', 'errors', 'message', 'request_id'] : ['code', 'message', 'request_id'],
            collect($response->json())->keys()->sort()->values()->all(),
            'the envelope is exactly {message, code, request_id} plus errors for a validation failure',
        );
        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('request_id'));
    }
}
