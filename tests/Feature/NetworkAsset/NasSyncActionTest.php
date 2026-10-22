<?php

namespace Tests\Feature\NetworkAsset;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\NetworkAsset\Jobs\WriteNasDeviceJob;
use Modules\NetworkAsset\Services\NetworkAccountProvisioner;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\InteractsWithRadius;

/** F7-D03 and F7-U04: "Sinkronkan ke RADIUS" writes one router now, through a queued job, and is audited. */
class NasSyncActionTest extends TestCase
{
    use BuildsNetworkFixtures;
    use CreatesCompanyUser;
    use InteractsWithRadius;
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = $this->createCompanyUser();
        $this->company = $this->admin->company;
        $this->grantNetworkPermissionsToAdminRole();
        $this->fakeRadius();
        $this->actingAs($this->admin);
    }

    public function test_the_button_queues_one_write_of_that_router_and_audits_it(): void
    {
        Queue::fake();
        $nas = $this->nasClient($this->company);

        $this->post(route('admin.nas-devices.sync', $nas))
            ->assertRedirect()
            ->assertSessionHas('success', 'Sinkronisasi ke RADIUS dijadwalkan.');

        Queue::assertPushed(WriteNasDeviceJob::class, 1);
        Queue::assertPushed(WriteNasDeviceJob::class, fn (WriteNasDeviceJob $job): bool => $job->companyId === $this->company->id && $job->nasDeviceId === $nas->id);

        $audit = Activity::query()->where('log_name', 'nas_device')->where('description', 'sync_requested')->sole();
        $this->assertSame($this->admin->id, $audit->causer_id);
        $this->assertSame($nas->networkAsset->code, $audit->properties['asset_code']);
        $this->assertStringNotContainsString('secret', (string) json_encode($audit->properties));
    }

    public function test_the_queued_job_writes_the_router_into_radius_and_stamps_the_row(): void
    {
        $nas = $this->nasClient($this->company, '198.51.100.30');
        $this->assertNull($nas->radius_synced_at);

        $this->post(route('admin.nas-devices.sync', $nas))->assertRedirect();

        $nas->refresh();
        $this->assertNotNull($nas->radius_synced_at);
        $this->assertNull($nas->last_error);
        $this->assertSame('198.51.100.30', $nas->radius_nasname);
        $this->assertSame([$this->realm()], array_column($this->radiusRows('nas', ['nasname' => '198.51.100.30']), 'shortname'));
    }

    public function test_the_job_leaves_a_removed_router_and_a_switched_off_company_alone(): void
    {
        $nas = $this->nasClient($this->company, '198.51.100.31');
        $provisioner = app(NetworkAccountProvisioner::class);

        (new WriteNasDeviceJob($this->company->id, 999_999))->handle($provisioner);
        $this->disableRadius();
        (new WriteNasDeviceJob($this->company->id, $nas->id))->handle($provisioner);

        $this->assertSame([], $this->radiusRows('nas'));
        $nas->refresh();
        $this->assertNull($nas->radius_synced_at);
        $this->assertNull($nas->last_error, 'a company without RADIUS is no failed write');
    }

    public function test_without_radius_nothing_is_queued_and_the_page_is_told(): void
    {
        Queue::fake();
        $this->disableRadius();

        $this->post(route('admin.nas-devices.sync', $this->nasClient($this->company)))
            ->assertRedirect()
            ->assertSessionHas('warning', 'Belum dikirim ke RADIUS karena RADIUS belum dikonfigurasi untuk perusahaan ini.')
            ->assertSessionMissing('success');

        Queue::assertNothingPushed();
        $this->assertSame(0, Activity::query()->where('description', 'sync_requested')->count());
    }

    public function test_it_needs_the_manage_permission(): void
    {
        Queue::fake();
        $nas = $this->nasClient($this->company);

        foreach ([$this->userWithPermissions($this->company), $this->userWithPermissions($this->company, 'network_asset.nas.view')] as $user) {
            $this->actingAs($user);
            $this->postJson(route('admin.nas-devices.sync', $nas))->assertForbidden();
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, Activity::query()->where('description', 'sync_requested')->count());
    }

    public function test_another_companys_router_is_a_404(): void
    {
        Queue::fake();
        $foreign = $this->nasClient(Company::factory()->create(['is_active' => true]));

        $this->post(route('admin.nas-devices.sync', $foreign))->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_the_route_is_throttled(): void
    {
        Queue::fake();
        $nas = $this->nasClient($this->company);

        foreach (range(1, 6) as $press) {
            $this->post(route('admin.nas-devices.sync', $nas))->assertRedirect();
        }

        $this->post(route('admin.nas-devices.sync', $nas))->assertStatus(429);
    }

    private function realm(): string
    {
        return $this->realmOf($this->company);
    }
}
