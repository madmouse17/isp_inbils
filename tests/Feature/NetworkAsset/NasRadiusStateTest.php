<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\NetworkAsset\Models\NasDevice;
use Tests\TestCase;
use Tests\Traits\AsksInertiaForProps;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithRadius;

/**
 * F7-U21: a router written to RADIUS is not yet a router FreeRADIUS serves, because it reads its clients only when it
 * starts. The router page tells the three apart from the accounting packets the router sent after the write.
 */
class NasRadiusStateTest extends TestCase
{
    use AsksInertiaForProps;
    use BuildsNetworkFixtures;
    use CreatesCompanyUser;
    use InteractsWithRadius;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private NasDevice $nas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->grantNetworkPermissionsToAdminRole();
        $this->fakeRadius();
        $this->actingAs($this->admin);
        $this->travelTo(CarbonImmutable::create(2026, 10, 22, 12, 0, 0, 'UTC'));
        $this->nas = $this->nasClient($this->company, '198.51.100.40');
    }

    private function writtenMinutesAgo(int $minutes): void
    {
        $this->nas->forceFill(['radius_synced_at' => now()->subMinutes($minutes), 'radius_nasname' => $this->nas->nasname])->save();
    }

    private function packet(int $minutesAgo, string $column = 'acctupdatetime'): void
    {
        $at = now()->subMinutes($minutesAgo)->format('Y-m-d H:i:s');
        $this->seedRadiusSession([
            'nasipaddress' => '198.51.100.40',
            'acctstarttime' => now()->subMinutes($minutesAgo + 60)->format('Y-m-d H:i:s'),
            'acctupdatetime' => $column === 'acctupdatetime' ? $at : now()->subMinutes(240)->format('Y-m-d H:i:s'),
            'acctstoptime' => $column === 'acctstoptime' ? $at : null,
        ]);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function stateOnThePage(): array
    {
        $props = $this->get(route('admin.nas-devices.show', $this->nas))->assertOk()->inertiaProps();

        return [$props['nasDevice']['radius_state'], $props['nasDevice']['last_accounting_at']];
    }

    public function test_a_router_that_was_never_written_is_not_synced(): void
    {
        $this->assertSame(['not_synced', null], $this->stateOnThePage());
    }

    public function test_a_written_router_that_sent_nothing_waits_for_freeradius_to_restart(): void
    {
        $this->writtenMinutesAgo(10);

        $this->assertSame(['awaiting_restart', null], $this->stateOnThePage());
    }

    public function test_a_packet_after_the_write_makes_the_router_live(): void
    {
        $this->writtenMinutesAgo(10);
        $this->packet(3);

        $this->assertSame(['live', now()->subMinutes(3)->toIso8601String()], $this->stateOnThePage());
    }

    public function test_a_packet_from_before_the_write_proves_nothing(): void
    {
        $this->writtenMinutesAgo(10);
        $this->packet(30);

        $this->assertSame(['awaiting_restart', now()->subMinutes(30)->toIso8601String()], $this->stateOnThePage());
    }

    public function test_a_stop_is_a_packet_too(): void
    {
        $this->writtenMinutesAgo(10);
        $this->packet(3, 'acctstoptime');

        $this->assertSame(['live', now()->subMinutes(3)->toIso8601String()], $this->stateOnThePage());
    }

    public function test_the_state_follows_the_radius_clock_not_the_application_clock(): void
    {
        $this->radiusClockOffsetMinutes = 7 * 60;
        $this->writtenMinutesAgo(10);
        $this->seedRadiusSession([
            'nasipaddress' => '198.51.100.40',
            'acctstarttime' => $this->radiusNow()->subMinutes(60)->format('Y-m-d H:i:s'),
            'acctupdatetime' => $this->radiusNow()->subMinutes(3)->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame(['live', now()->subMinutes(3)->toIso8601String()], $this->stateOnThePage());
    }

    public function test_the_state_is_unknown_while_the_switch_is_off_or_radius_is_down(): void
    {
        $this->writtenMinutesAgo(10);
        $this->packet(3);

        Schema::connection('radius')->drop('radacct');
        $this->assertSame([null, null], $this->stateOnThePage());

        $this->disableRadius();
        $this->assertSame([null, null], $this->stateOnThePage());
    }

    public function test_a_list_asks_radius_nothing_and_leaves_the_state_null(): void
    {
        $this->writtenMinutesAgo(10);
        $this->packet(3);
        $this->radiusConnection()->enableQueryLog();

        $this->get(route('admin.nas-devices.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('nasDevices.data.0.radius_state', null)
            ->where('nasDevices.data.0.last_accounting_at', null));

        $this->assertSame([], $this->radiusConnection()->getQueryLog());
    }

    public function test_the_connection_test_reloads_the_router_without_the_rest_of_the_page(): void
    {
        $this->writtenMinutesAgo(10);
        $this->packet(3);
        $this->radiusConnection()->enableQueryLog();

        $this->get(route('admin.nas-devices.show', $this->nas), $this->partialReload('Admin/NasDevices/Show', 'nasDevice'))
            ->assertOk()
            ->assertJsonPath('props.nasDevice.radius_state', 'live')
            ->assertJsonMissingPath('props.sessions');

        $statements = array_column($this->radiusConnection()->getQueryLog(), 'query');
        $this->assertNotEmpty($statements);
        foreach ($statements as $statement) {
            $this->assertStringNotContainsString('order by', $statement, 'the session list is not read for this reload');
        }
    }
}
