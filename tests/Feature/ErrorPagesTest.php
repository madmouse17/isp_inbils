<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Exceptions\InsufficientStockException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    private const FORBIDDEN_MESSAGE = 'Anda tidak punya izin untuk tindakan ini.';

    private const GONE_MESSAGE = 'Data tidak ditemukan atau sudah berubah.';

    private const STOCK_MESSAGE = 'Stok Kabel Fiber Optic Single di AUDIT POP Bandung Timur tersisa 0 m, dibutuhkan 18 m.';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/__error/{status}', fn (int $status) => abort($status));
        Route::middleware('api')->get('/api/v1/__error/{status}', fn (int $status) => abort($status));
        Route::get('/webhooks/__error/{status}', fn (int $status) => abort($status));

        // Writes that refuse with a worded abort and with a bare one, the way the services do.
        Route::middleware('web')->match(['POST', 'PUT', 'PATCH', 'DELETE'], '/__action/{status}', fn (int $status) => abort($status, 'Alasan dari aturan status.'));
        Route::middleware('web')->match(['POST', 'PUT', 'PATCH', 'DELETE'], '/__action-bare/{status}', fn (int $status) => abort($status));
        Route::middleware('web')->post('/__policy', fn () => Gate::authorize('__never'));
        Route::middleware('web')->get('/__refuse', fn () => abort(422, 'SPK hanya bisa diubah saat berstatus draf atau menunggu penugasan.'));
        Route::middleware('web')->get('/__refuse-bare', fn () => abort(422));
        Route::middleware('web')->get('/__forbid', fn () => abort(403, 'Maker-checker: teks kebijakan yang tidak boleh tampil.'));
        Route::middleware('web')->post('/__stock', fn () => throw $this->stockShortage());
        Route::middleware('web')->post('/admin/__stock', fn () => throw $this->stockShortage());
    }

    /** @return array<string, array{int}> */
    public static function statuses(): array
    {
        return [403 => [403], 404 => [404], 419 => [419], 500 => [500], 503 => [503]];
    }

    /** @return array<string, array{string, int}> the verb and the status its redirect carries */
    public static function writes(): array
    {
        return ['post' => ['post', 302], 'put' => ['put', 303], 'patch' => ['patch', 303], 'delete' => ['delete', 303]];
    }

    #[DataProvider('statuses')]
    public function test_a_browser_gets_the_inertia_error_page_with_its_status(int $status): void
    {
        config(['app.debug' => false]);

        $this->get("/__error/{$status}")
            ->assertStatus($status)
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', $status));
    }

    public function test_an_unknown_url_renders_the_error_page_for_a_guest(): void
    {
        $this->get('/tidak-ada-halaman')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
    }

    public function test_the_error_page_carries_the_request_id(): void
    {
        config(['app.debug' => false]);

        $this->get('/__error/500', ['X-Request-Id' => 'req-error-page-1'])
            ->assertStatus(500)
            ->assertHeader('X-Request-Id', 'req-error-page-1')
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('requestId', 'req-error-page-1'));

        $generated = $this->get('/__error/404')->assertNotFound();

        $generated->assertInertia(fn (Assert $page) => $page->where('requestId', $generated->headers->get('X-Request-Id')));
    }

    public function test_a_maintenance_503_carries_a_request_id(): void
    {
        // The cache driver on the array store keeps maintenance mode inside this test: the file driver would take the whole dev site down.
        config(['app.debug' => false, 'app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->maintenanceMode()->activate(['retry' => 60]);

        $this->get('/tidak-ada-halaman', ['X-Request-Id' => 'req-maintenance-1'])
            ->assertStatus(503)
            ->assertHeader('X-Request-Id', 'req-maintenance-1')
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 503)->where('requestId', 'req-maintenance-1'));
    }

    public function test_a_500_keeps_laravels_debug_page_while_debug_is_on(): void
    {
        config(['app.debug' => true]);

        $response = $this->get('/__error/500')->assertStatus(500);

        $this->assertStringNotContainsString('data-page', (string) $response->getContent());
    }

    public function test_json_clients_the_api_and_webhooks_keep_plain_answers(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/__error/404')->assertNotFound()->assertJsonStructure(['message']);
        $this->getJson('/api/v1/__error/404')->assertNotFound()->assertJsonStructure(['message']);
        $this->get('/api/v1/__error/403', ['Accept' => 'text/html'])->assertForbidden()->assertJsonStructure(['message']);

        $webhook = $this->get('/webhooks/__error/403', ['Accept' => 'text/html'])->assertForbidden();
        $this->assertStringNotContainsString('data-page', (string) $webhook->getContent());
    }

    #[DataProvider('writes')]
    public function test_inertia_action_403_becomes_flash_error(string $verb, int $redirect): void
    {
        $this->from('/halaman-asal')->withHeader('X-Inertia', 'true')->{$verb}('/__action/403')
            ->assertStatus($redirect)
            ->assertRedirect('/halaman-asal')
            ->assertSessionHas('error', self::FORBIDDEN_MESSAGE)
            ->assertSessionHasNoErrors();
    }

    public function test_a_policy_refusal_shows_the_same_sentence_and_never_the_frameworks_english(): void
    {
        Gate::define('__never', fn () => false);

        $this->from('/halaman-asal')->withHeader('X-Inertia', 'true')->post('/__policy')
            ->assertRedirect('/halaman-asal')
            ->assertSessionHas('error', self::FORBIDDEN_MESSAGE);
    }

    #[DataProvider('writes')]
    public function test_inertia_action_404_becomes_flash_error(string $verb, int $redirect): void
    {
        $this->from('/halaman-asal')->withHeader('X-Inertia', 'true')->{$verb}('/__action/404')
            ->assertStatus($redirect)
            ->assertRedirect('/halaman-asal')
            ->assertSessionHas('error', self::GONE_MESSAGE);
    }

    #[DataProvider('writes')]
    public function test_inertia_action_abort_422_becomes_flash_error(string $verb, int $redirect): void
    {
        $this->from('/halaman-asal')->withHeader('X-Inertia', 'true')->{$verb}('/__action/422')
            ->assertStatus($redirect)
            ->assertRedirect('/halaman-asal')
            ->assertSessionHas('error', 'Alasan dari aturan status.');
    }

    public function test_an_abort_422_without_words_still_says_something(): void
    {
        $this->from('/halaman-asal')->withHeader('X-Inertia', 'true')->post('/__action-bare/422')
            ->assertRedirect('/halaman-asal')
            ->assertSessionHas('error', 'Permintaan tidak dapat diproses.');
    }

    public function test_only_an_inertia_write_is_turned_into_a_redirect(): void
    {
        config(['app.debug' => false]);

        // A plain write and a JSON write keep their status, and a page visit keeps its error page.
        $this->post('/__action/403')->assertForbidden();
        $this->post('/__action/422')->assertStatus(422);
        $this->postJson('/__action/422')->assertStatus(422)->assertJsonPath('message', 'Alasan dari aturan status.');
        $this->withHeaders($this->inertiaVisit())->get('/__error/403')
            ->assertForbidden()
            ->assertJsonPath('component', 'Error');

        // A session that ran out (419) keeps its error page: the toast could not help there.
        $this->from('/halaman-asal')->withHeader('X-Inertia', 'true')->post('/__action/419')->assertStatus(419);
    }

    public function test_get_422_renders_error_page_with_message(): void
    {
        $this->get('/__refuse')
            ->assertStatus(422)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 422)
                ->where('message', 'SPK hanya bisa diubah saat berstatus draf atau menunggu penugasan.'));

        // The Inertia client asks for the same page as JSON and gets the page object, not a redirect.
        $this->withHeaders($this->inertiaVisit())->get('/__refuse')
            ->assertStatus(422)
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Error')
            ->assertJsonPath('props.message', 'SPK hanya bisa diubah saat berstatus draf atau menunggu penugasan.');
    }

    public function test_a_422_without_words_and_a_403_never_carry_a_message(): void
    {
        $this->get('/__refuse-bare')
            ->assertStatus(422)
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 422)->missing('message'));

        $this->get('/__forbid')
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403)->missing('message'));
    }

    public function test_insufficient_stock_flashes_message(): void
    {
        $this->app->make(ExceptionHandler::class)->dontReport(InsufficientStockException::class);

        $this->from('/form-asal')->post('/__stock', ['quantity' => '18'])
            ->assertRedirect('/form-asal')
            ->assertSessionHasErrors(['quantity' => self::STOCK_MESSAGE])
            ->assertSessionHas('error', self::STOCK_MESSAGE)
            ->assertSessionHasInput('quantity', '18');

        // The JSON answer of an admin route is unchanged.
        $this->postJson('/admin/__stock', ['quantity' => '18'])
            ->assertStatus(422)
            ->assertExactJson(['message' => self::STOCK_MESSAGE]);
    }

    /** The headers of a page visit of the Inertia client: GET answers a stale asset version with a reload otherwise. */
    private function inertiaVisit(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
    }

    /** The factories of the exception change with its message; the renderer reads nothing but the text. */
    private function stockShortage(): InsufficientStockException
    {
        $exception = (new ReflectionClass(InsufficientStockException::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(\Exception::class, 'message'))->setValue($exception, self::STOCK_MESSAGE);

        return $exception;
    }
}
