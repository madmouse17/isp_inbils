<?php

namespace Tests\Feature\Operations;

use App\Services\Core\OpenStreetMapGeocoder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Service\Jobs\SendAnnouncementNotifications;
use Modules\Ticketing\Jobs\CheckTicketSlaBreachesJob;
use RuntimeException;
use Tests\TestCase;

/**
 * Failures that used to vanish (log-audit LA-OPS, contract C5) now leave one warning that names ids and exception
 * classes, never the address a customer typed or the host an exception message carries.
 */
class SwallowedFailureLoggingTest extends TestCase
{
    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml logs to stderr: capture the warnings instead of printing them.
        config(['logging.default' => 'null']);
        Log::listen(function (MessageLogged $event): void {
            $this->logged[] = $event;
        });
    }

    public function test_a_geocoder_failure_logs_a_warning_without_the_query(): void
    {
        // Laravel's connection error names the whole URL, and the URL holds the address that was searched.
        Http::fake(['nominatim.openstreetmap.org/*' => Http::failedConnection()]);

        $this->assertSame([], app(OpenStreetMapGeocoder::class)->search('Jalan Mawar Nomor 12 Bandung'));

        $warnings = $this->warnings('geocode.failed');

        $this->assertCount(1, $warnings);
        $this->assertSame(['error' => ConnectionException::class], $warnings[0]->context);
        $this->assertStringNotContainsString('Mawar', json_encode($warnings[0]->context, JSON_THROW_ON_ERROR));
    }

    public function test_the_sla_and_announcement_jobs_log_their_final_failure(): void
    {
        $secretHost = 'db-internal.example.test';

        (new CheckTicketSlaBreachesJob(12))->failed(new RuntimeException("Connection refused by {$secretHost}"));
        (new SendAnnouncementNotifications(34))->failed(new RuntimeException("Connection refused by {$secretHost}"));

        $sla = $this->warnings('tickets.sla_check_failed');
        $announcement = $this->warnings('announcement.notify_failed');

        $this->assertCount(1, $sla);
        $this->assertSame(['company_id' => 12], $sla[0]->context);
        $this->assertCount(1, $announcement);
        $this->assertSame(['announcement_id' => 34], $announcement[0]->context);
        $this->assertStringNotContainsString($secretHost, json_encode([$sla[0]->context, $announcement[0]->context], JSON_THROW_ON_ERROR));
    }

    /** @return list<MessageLogged> */
    private function warnings(string $message): array
    {
        return array_values(array_filter(
            $this->logged,
            fn (MessageLogged $event): bool => $event->level === 'warning' && $event->message === $message,
        ));
    }
}
