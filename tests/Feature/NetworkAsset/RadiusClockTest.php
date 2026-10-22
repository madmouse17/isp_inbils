<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Services\Core\CompanyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\NetworkAsset\Services\Radius\RadiusDirectory;
use Tests\TestCase;
use Tests\Traits\InteractsWithRadius;

/**
 * F7-D20 and F7-D23. FreeRADIUS writes `radacct` datetimes in the zone of its own database server, which is not the
 * application's, so every age is measured inside the RADIUS statement on the server's clock and the times that leave the directory are
 * moments on the application's clock. The stand-in server runs `$radiusClockOffsetMinutes` ahead of the application, the
 * way a Jakarta server runs ahead of a UTC application.
 */
class RadiusClockTest extends TestCase
{
    use InteractsWithRadius;
    use RefreshDatabase;

    private RadiusDirectory $radius;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeRadius();
        CompanyService::useCompanyForTests((int) Company::factory()->create(['is_active' => true])->id);
        $this->radius = new RadiusDirectory();
        // One frozen instant: the two clocks differ by the offset a test sets, and by nothing else.
        $this->travelTo(CarbonImmutable::create(2026, 10, 22, 12, 0, 0, 'UTC'));
    }

    /** The datetime text radacct holds for a moment this many minutes before now on the RADIUS clock. */
    private function radacct(int $minutesAgo): string
    {
        return $this->radiusNow()->subMinutes($minutesAgo)->format('Y-m-d H:i:s');
    }

    private function ago(int $minutes): string
    {
        return now()->subMinutes($minutes)->toIso8601String();
    }

    public function test_a_server_zone_ahead_of_the_application_still_lists_its_live_sessions_with_application_times(): void
    {
        $this->radiusClockOffsetMinutes = 7 * 60;
        $this->seedRadiusSession(['acctstarttime' => $this->radacct(2), 'acctupdatetime' => $this->radacct(1)]);

        $sessions = $this->radius->openSessions('budi@acme');

        $this->assertCount(1, $sessions);
        $this->assertSame($this->ago(2), $sessions[0]['started_at'], 'the start is the application moment, not the text of the RADIUS zone');
        $this->assertSame($this->ago(1), $sessions[0]['last_seen_at']);
        $this->assertFalse($sessions[0]['stale']);
        $this->assertSame(['budi@acme'], $this->radius->onlineAmong(['budi@acme']));
    }

    public function test_a_server_zone_behind_the_application_does_not_make_live_sessions_stale(): void
    {
        // Read against the application clock, this row is seven hours old: the page showed "Offline" for a subscriber who is online.
        $this->radiusClockOffsetMinutes = -7 * 60;
        $this->seedRadiusSession(['acctstarttime' => $this->radacct(2), 'acctupdatetime' => $this->radacct(1)]);

        $this->assertCount(1, $this->radius->openSessions('budi@acme'));
        $this->assertSame(['budi@acme'], $this->radius->onlineAmong(['budi@acme']));
    }

    public function test_a_leftover_session_is_stale_by_the_server_clock_not_by_the_application_clock(): void
    {
        // Seven hours ahead, a row 20 minutes old on the server looks like the future to the application: it was never called stale.
        $this->radiusClockOffsetMinutes = 7 * 60;
        $this->seedRadiusSession(['acctsessionid' => 'zombie', 'acctstarttime' => $this->radacct(120), 'acctupdatetime' => $this->radacct(20)]);

        $this->assertSame([], $this->radius->openSessions('budi@acme'));
        $this->assertSame([], $this->radius->sessionsOnNas('192.0.2.10'));
        $this->assertSame([], $this->radius->onlineAmong(['budi@acme']));

        $withStale = $this->radius->openSessions('budi@acme', withStale: true);
        $this->assertSame(['zombie'], array_column($withStale, 'session_id'));
        $this->assertTrue($withStale[0]['stale']);
        $this->assertSame($this->ago(20), $withStale[0]['last_seen_at']);
        $this->assertSame($this->ago(120), $withStale[0]['started_at']);
    }

    public function test_the_fifteen_minute_edge_is_the_server_clocks(): void
    {
        $this->radiusClockOffsetMinutes = 7 * 60;
        $this->seedRadiusSession(['acctsessionid' => 'edge', 'acctstarttime' => $this->radacct(60), 'acctupdatetime' => $this->radacct(15)]);
        $this->seedRadiusSession(['acctsessionid' => 'over', 'username' => 'ani@acme', 'acctstarttime' => $this->radacct(60), 'acctupdatetime' => $this->radiusNow()->subMinutes(15)->subSecond()->format('Y-m-d H:i:s')]);

        $this->assertSame(['edge'], array_column($this->radius->openSessions('budi@acme'), 'session_id'));
        $this->assertSame([], $this->radius->openSessions('ani@acme'));
        $this->assertFalse($this->radius->openSessions('budi@acme')[0]['stale']);
        $this->assertTrue($this->radius->openSessions('ani@acme', withStale: true)[0]['stale']);
    }

    public function test_the_live_session_stands_for_the_account_even_when_a_leftover_row_started_later(): void
    {
        $this->radiusClockOffsetMinutes = 7 * 60;
        $this->seedRadiusSession(['acctsessionid' => 'live', 'acctstarttime' => $this->radacct(180), 'acctupdatetime' => $this->radacct(1)]);
        $this->seedRadiusSession(['acctsessionid' => 'leftover', 'acctstarttime' => $this->radacct(30), 'acctupdatetime' => $this->radacct(25)]);

        $sessions = $this->radius->openSessions('budi@acme', withStale: true);

        $this->assertSame(['live', 'leftover'], array_column($sessions, 'session_id'));
        $this->assertSame([false, true], array_column($sessions, 'stale'));
    }

    public function test_a_session_that_never_sent_an_update_has_said_nothing_since_it_started(): void
    {
        $this->radiusClockOffsetMinutes = 7 * 60;
        $this->seedRadiusSession(['acctstarttime' => $this->radacct(5), 'acctupdatetime' => null]);

        $session = $this->radius->openSessions('budi@acme')[0];

        $this->assertSame($this->ago(5), $session['last_seen_at']);
        $this->assertSame($session['started_at'], $session['last_seen_at']);
    }

    public function test_a_packet_stamped_ahead_of_the_server_clock_reads_as_just_now(): void
    {
        $this->seedRadiusSession(['acctstarttime' => $this->radacct(2), 'acctupdatetime' => $this->radacct(-3)]);

        $session = $this->radius->openSessions('budi@acme')[0];

        $this->assertSame(now()->toIso8601String(), $session['last_seen_at']);
        $this->assertFalse($session['stale']);
    }

    public function test_the_last_stop_and_the_last_packet_of_a_router_are_application_moments(): void
    {
        $this->radiusClockOffsetMinutes = 5 * 60 + 30;
        $this->seedRadiusSession(['acctstarttime' => $this->radacct(300), 'acctupdatetime' => $this->radacct(50), 'acctstoptime' => $this->radacct(35)]);
        $this->seedRadiusSession(['username' => 'ani@acme', 'acctstarttime' => $this->radacct(90), 'acctupdatetime' => $this->radacct(40)]);
        $this->seedRadiusSession(['username' => 'elsewhere@acme', 'nasipaddress' => '203.0.113.9', 'acctstarttime' => $this->radacct(5), 'acctupdatetime' => $this->radacct(1)]);

        $this->assertSame($this->ago(35), $this->radius->lastSeen('budi@acme'));
        $this->assertSame($this->ago(35), $this->radius->lastAccountingAt('192.0.2.10'), 'a stop is a packet too, and the newest of the router wins');
        $this->assertSame($this->ago(1), $this->radius->lastAccountingAt('203.0.113.9'));
        $this->assertNull($this->radius->lastAccountingAt('198.51.100.1'), 'a router that never sent one has none');
        $this->assertNull($this->radius->lastSeen('ani@acme'), 'a session that has not ended has no stop');
    }

    public function test_the_age_is_measured_in_the_radius_statement_itself_with_no_round_trip_for_a_clock(): void
    {
        $this->radiusClockOffsetMinutes = 7 * 60;
        $this->seedRadiusSession();
        $this->radiusConnection()->enableQueryLog();

        $this->radius->openSessions('budi@acme');

        $log = $this->radiusConnection()->getQueryLog();
        $this->assertCount(1, $log, 'one statement per call, as before');
        $this->assertStringContainsString('UNIX_TIMESTAMP(NOW())', $log[0]['query']);
        $this->assertSame(['budi@acme', 900], $log[0]['bindings'], 'a limit in seconds, never a moment of the application clock');
    }
}
