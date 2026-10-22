<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The package form's "+ Baru" dialogs post to the store routes with X-Inline-Create and select the new row; any other client gets the form redirect. */
class InlineCreateJsonTest extends TestCase
{
    use RefreshDatabase;

    private const INLINE = ['X-Inline-Create' => '1'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantRole($this->admin, 'admin');
    }

    /** @return array<string, array{string, class-string, array<string, mixed>, string}> */
    public static function stores(): array
    {
        return [
            'bandwidth profile' => ['admin.bandwidth-profiles.store', BandwidthProfile::class, [
                'name' => 'Home 30Mbps', 'download_mbps' => 30, 'upload_mbps' => 10, 'type' => 'shared', 'contention_ratio' => 4, 'is_active' => true,
            ], 'admin.bandwidth-profiles.index'],
            'speed profile' => ['admin.speed-profiles.store', SpeedProfile::class, [
                'name' => 'Cap 30Mbps', 'download_max_mbps' => 30, 'upload_max_mbps' => 10, 'is_active' => true,
            ], 'admin.speed-profiles.index'],
            'SLA tier' => ['admin.sla-tiers.store', SLATier::class, [
                'name' => 'Platinum', 'response_time_hours' => 2, 'resolution_time_hours' => 8, 'is_active' => true,
            ], 'admin.sla-tiers.index'],
        ];
    }

    /**
     * @param  class-string  $model
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('stores')]
    public function test_the_inline_header_gets_201_with_the_public_id_and_the_name(string $route, string $model, array $payload): void
    {
        $response = $this->actingAs($this->admin)->postJson(route($route), $payload, self::INLINE)->assertCreated();

        $row = $model::query()->where('name', $payload['name'])->sole();

        $this->assertSame($this->admin->company_id, $row->company_id);
        $response->assertExactJson(['data' => ['id' => $row->public_id, 'label' => $payload['name']]]);
    }

    /**
     * @param  class-string  $model
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('stores')]
    public function test_a_form_post_still_redirects_to_the_list(string $route, string $model, array $payload, string $index): void
    {
        $this->actingAs($this->admin)->post(route($route), $payload)->assertRedirect(route($index));

        $this->assertSame(1, $model::query()->where('name', $payload['name'])->count());
    }

    /**
     * @param  class-string  $model
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('stores')]
    public function test_a_client_that_only_accepts_json_still_gets_the_redirect(string $route, string $model, array $payload, string $index): void
    {
        $this->actingAs($this->admin)->postJson(route($route), $payload)->assertRedirect(route($index));

        $this->assertSame(1, $model::query()->where('name', $payload['name'])->count());
    }

    /** @param  array<string, mixed>  $payload */
    #[DataProvider('stores')]
    public function test_a_json_refusal_is_a_422_with_the_field_and_writes_nothing(string $route, string $model, array $payload): void
    {
        $this->actingAs($this->admin)->postJson(route($route), $payload, self::INLINE)->assertCreated();

        $this->postJson(route($route), $payload, self::INLINE)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, $model::query()->where('name', $payload['name'])->count());
    }

    /** @param  array<string, mixed>  $payload */
    #[DataProvider('stores')]
    public function test_a_user_without_the_service_permission_cannot_create(string $route, string $model, array $payload): void
    {
        $viewer = $this->grantPermissions(User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]), ['app.web']);

        $this->actingAs($viewer)->postJson(route($route), $payload)->assertForbidden();

        $this->assertSame(0, $model::query()->where('name', $payload['name'])->count());
    }

    public function test_the_speed_profile_form_offers_the_active_bandwidth_profiles_to_copy_speeds_from(): void
    {
        BandwidthProfile::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Active one', 'download_mbps' => 20, 'upload_mbps' => 5, 'is_active' => true]);
        BandwidthProfile::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Inactive one', 'is_active' => false]);
        BandwidthProfile::factory()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Foreign one', 'is_active' => true]);

        $this->actingAs($this->admin)->get(route('admin.speed-profiles.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Service/SpeedProfiles/Create')
                ->has('bandwidthProfiles.data', 1)
                ->where('bandwidthProfiles.data.0.name', 'Active one')
                ->where('bandwidthProfiles.data.0.download_mbps', 20)
                ->where('bandwidthProfiles.data.0.upload_mbps', 5));
    }
}
