<?php

namespace Tests\Feature\Operations;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Platform\ActiveImpersonation;
use App\Support\Observability\RedactLogContext;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\NullHandler;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ExceptionHandlingTest extends TestCase
{
    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml logs to stderr: capture the handler's reports instead of printing them.
        config(['logging.default' => 'null']);
        Log::listen(function (MessageLogged $event): void {
            $this->logged[] = $event;
        });
    }

    public function test_the_same_exception_instance_is_reported_once(): void
    {
        $failure = new RuntimeException('caught and rethrown');

        report($failure);
        report($failure);

        $this->assertCount(1, $this->reports());
    }

    public function test_one_failure_site_is_reported_at_most_five_times_a_minute(): void
    {
        $raise = static fn () => report(new RuntimeException('same site'));

        foreach (range(1, 8) as $ignored) {
            $raise();
        }

        $this->assertCount(5, $this->reports());

        report(new RuntimeException('another line is another site'));

        $this->assertCount(6, $this->reports());

        $this->travel(61)->seconds();
        $raise();

        $this->assertCount(7, $this->reports(), 'the site reports again once the minute is over');
    }

    public function test_query_exceptions_are_throttled_by_their_statement_not_their_line(): void
    {
        $sql = 'select * from `invoices` where `id` = ?';

        // Six lines on purpose: keyed by file and line each would report; keyed by statement the sixth is dropped.
        report(new QueryException('mysql', $sql, [1], new Exception('first')));
        report(new QueryException('mysql', $sql, [2], new Exception('second')));
        report(new QueryException('mysql', $sql, [3], new Exception('third')));
        report(new QueryException('mysql', $sql, [4], new Exception('fourth')));
        report(new QueryException('mysql', $sql, [5], new Exception('fifth')));
        report(new QueryException('mysql', $sql, [6], new Exception('sixth')));

        $this->assertCount(5, $this->reports());
    }

    public function test_reports_carry_company_route_platform_admin_and_impersonation_context(): void
    {
        Route::get('/__ops/explode', function (Request $request) {
            $request->attributes->set('impersonation', new ActiveImpersonation('imp-uuid-1', new PlatformAdmin(), new User(), new Company(), 'support', 0, 'read'));

            throw new RuntimeException('boom');
        })->name('ops.explode');
        Auth::guard('platform')->setUser((new PlatformAdmin())->forceFill(['id' => 31]));

        CompanyService::runAs(7, fn () => $this->getJson('/__ops/explode')->assertStatus(500));

        $context = $this->reports()[0]->context;

        $this->assertSame(7, $context['company_id']);
        $this->assertSame('ops.explode', $context['route']);
        $this->assertSame(31, $context['platform_admin_id']);
        $this->assertSame('imp-uuid-1', $context['impersonation_id']);
    }

    public function test_a_report_outside_a_request_adds_no_empty_context_keys(): void
    {
        report(new RuntimeException('from a console command'));

        $context = $this->reports()[0]->context;

        foreach (['company_id', 'route', 'platform_admin_id', 'impersonation_id'] as $key) {
            $this->assertArrayNotHasKey($key, $context);
        }
    }

    public function test_every_handler_channel_applies_the_redaction_tap(): void
    {
        $channels = collect(config('logging.channels'))->filter(
            fn (array $channel): bool => isset($channel['driver']) && $channel['driver'] !== 'stack' && ($channel['handler'] ?? null) !== NullHandler::class,
        );

        $this->assertNotEmpty($channels);

        foreach ($channels as $name => $channel) {
            $this->assertContains(RedactLogContext::class, $channel['tap'] ?? [], "channel [{$name}] must redact its context");
        }
    }

    public function test_a_logged_secret_is_masked_in_the_written_line_through_a_stack(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'inbils-log-');
        config([
            'logging.channels.probe_file' => [...config('logging.channels.single'), 'path' => $path],
            'logging.channels.probe_stack' => ['driver' => 'stack', 'channels' => ['probe_file']],
        ]);

        Log::channel('probe_stack')->warning('probe', ['token' => 'tok-secret-123', 'status' => 'ok']);

        $written = (string) file_get_contents($path);
        unlink($path);

        $this->assertStringContainsString('"status":"ok"', $written);
        $this->assertStringContainsString('"token":"[REDACTED]"', $written);
        $this->assertStringNotContainsString('tok-secret-123', $written);
    }

    /** @return list<MessageLogged> the handler's reports: the only records that carry the Throwable */
    private function reports(): array
    {
        return array_values(array_filter(
            $this->logged,
            fn (MessageLogged $event): bool => ($event->context['exception'] ?? null) instanceof Throwable,
        ));
    }
}
