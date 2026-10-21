<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Support\OptimisticLock;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Models\ServicePackageVersion;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The catalog masters (spec .scratch/master-lifecycle): a profile, tier or package that something points at is
 * deactivated, never deleted, and a hard delete happens only when nothing ever pointed at it (flow audit F2-D04 soft
 * deleted profiles that packages still used, so the package showed "-"). The status filter, Pulihkan, the version
 * history and the report of the SLA tier follow the same rule.
 */
class ServiceMasterLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    private BandwidthProfile $bandwidth;

    private SpeedProfile $speed;

    private SLATier $tier;

    private ServicePackageCategory $category;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = $this->user('admin');
        $this->bandwidth = BandwidthProfile::factory()->create(['company_id' => $this->company->id, 'name' => 'BW 20']);
        $this->speed = SpeedProfile::factory()->create(['company_id' => $this->company->id, 'name' => 'SP 20']);
        $this->tier = SLATier::factory()->create(['company_id' => $this->company->id, 'name' => 'Gold']);
        $this->category = ServicePackageCategory::factory()->create(['company_id' => $this->company->id, 'code' => 'RUMAHAN', 'name' => 'Rumahan']);
    }

    /** @return array<string, array{string, string, string}> the master, its route base and the refusal of a delete */
    public static function catalogMasters(): array
    {
        return [
            'bandwidth profile' => ['bandwidth', 'bandwidth-profiles', 'Profil bandwidth masih dipakai 1 paket, 1 versi paket. Nonaktifkan saja.'],
            'speed profile' => ['speed', 'speed-profiles', 'Profil kecepatan masih dipakai 1 paket, 1 versi paket. Nonaktifkan saja.'],
            'SLA tier' => ['tier', 'sla-tiers', 'Tingkat SLA masih dipakai 1 paket, 1 versi paket. Nonaktifkan saja.'],
        ];
    }

    /** @return array<string, array{string, class-string, string}> */
    public static function pickedTerms(): array
    {
        return [
            'bandwidth profile' => ['bandwidth_profile_id', BandwidthProfile::class, 'bandwidthProfiles'],
            'speed profile' => ['speed_profile_id', SpeedProfile::class, 'speedProfiles'],
            'SLA tier' => ['sla_tier_id', SLATier::class, 'slaTiers'],
        ];
    }

    /** @return array<string, array{string, string}> route base => the property that holds a record of it */
    public static function allMasters(): array
    {
        return [
            'bandwidth profile' => ['bandwidth-profiles', 'bandwidth'],
            'speed profile' => ['speed-profiles', 'speed'],
            'SLA tier' => ['sla-tiers', 'tier'],
            'service package' => ['service-packages', 'package'],
        ];
    }

    public function test_referenced_bandwidth_profile_cannot_be_hard_deleted_and_can_be_deactivated(): void
    {
        $package = $this->package(['name' => 'Home 20']);
        $this->actingAs($this->admin);

        $this->deleteJson(route('admin.bandwidth-profiles.destroy', $this->bandwidth))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Profil bandwidth masih dipakai 1 paket, 1 versi paket. Nonaktifkan saja.']);
        $this->assertDatabaseHas('bandwidth_profiles', ['id' => $this->bandwidth->id, 'deleted_at' => null]);

        $this->from('/back')->post(route('admin.bandwidth-profiles.deactivate', $this->bandwidth->public_id))
            ->assertRedirect('/back')->assertSessionHas('success', 'Profil bandwidth dinonaktifkan.');
        $this->assertFalse($this->bandwidth->fresh()->is_active);

        $this->assertSame([], $this->formOptions('admin.service-packages.create', 'bandwidthProfiles'), 'a new package cannot pick it');
        $this->get(route('admin.service-packages.show', $package))->assertOk()
            ->assertInertia(fn ($page) => $page->where('servicePackage.data.bandwidth_profile.name', 'BW 20'));
        $this->assertSame('BW 20', $this->props($this->get(route('admin.service-packages.index')))['servicePackages']['data'][0]['bandwidth_profile']['name'], 'the package still shows it');

        $this->from('/back')->post(route('admin.bandwidth-profiles.activate', $this->bandwidth->public_id))
            ->assertSessionHas('success', 'Profil bandwidth diaktifkan.');
        $this->assertSame(['BW 20'], $this->formOptions('admin.service-packages.create', 'bandwidthProfiles'));
    }

    public function test_unreferenced_speed_profile_is_hard_deleted(): void
    {
        $this->actingAs($this->admin)->delete(route('admin.speed-profiles.destroy', $this->speed))
            ->assertSessionHas('success', 'Profil kecepatan dihapus permanen.');

        $this->assertDatabaseMissing('speed_profiles', ['id' => $this->speed->id]);
    }

    #[DataProvider('catalogMasters')]
    public function test_a_master_is_still_referenced_when_only_a_trashed_package_uses_it(string $property, string $routeBase, string $refusal): void
    {
        $this->package()->delete();

        $this->actingAs($this->admin)->deleteJson(route("admin.{$routeBase}.destroy", $this->{$property}))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => $refusal]);
        $this->assertDatabaseHas($this->{$property}->getTable(), ['id' => $this->{$property}->id, 'deleted_at' => null]);
    }

    public function test_sla_tier_restore_of_legacy_trashed_row(): void
    {
        // The audit found rows like this one: deleted while a package still used it.
        $this->package();
        $this->tier->delete();

        $this->assertSame(['Gold'], $this->listed('admin.sla-tiers.index', 'slaTiers', 'dihapus'));
        $this->assertSame([], $this->listed('admin.sla-tiers.index', 'slaTiers'));

        $this->from('/back')->post(route('admin.sla-tiers.restore', $this->tier->public_id))
            ->assertRedirect('/back')->assertSessionHas('success', 'Tingkat SLA dipulihkan.');

        $this->assertNull($this->tier->fresh()->deleted_at);
        $this->assertSame(['Gold'], $this->listed('admin.sla-tiers.index', 'slaTiers'));
        $this->assertSame([], $this->listed('admin.sla-tiers.index', 'slaTiers', 'dihapus'));
    }

    #[DataProvider('allMasters')]
    public function test_deactivate_needs_the_update_permission_and_restore_the_delete_permission(string $routeBase, string $property): void
    {
        $record = $property === 'package' ? $this->package() : $this->{$property};
        $record->delete();
        $updater = $this->user('catalog-updater', ['service.view', 'service.update']);
        $deleter = $this->user('catalog-deleter', ['service.view', 'service.delete']);
        $restore = route("admin.{$routeBase}.restore", $record->public_id);
        $deactivate = route("admin.{$routeBase}.deactivate", $record->public_id);

        $this->actingAs($updater)->postJson($restore)->assertForbidden();
        $this->assertSoftDeleted($record);

        $this->actingAs($deleter)->postJson($restore)->assertRedirect();
        $this->assertNotSoftDeleted($record);

        $this->actingAs($deleter)->postJson($deactivate)->assertForbidden();
        $this->actingAs($updater)->postJson($deactivate)->assertRedirect();
        $this->assertFalse($record->fresh()->is_active);
    }

    #[DataProvider('allMasters')]
    public function test_another_company_record_is_not_reachable_through_the_lifecycle_routes(string $routeBase, string $property): void
    {
        $other = Company::factory()->create(['is_active' => true]);
        $foreign = match ($property) {
            'bandwidth' => BandwidthProfile::factory()->create(['company_id' => $other->id]),
            'speed' => SpeedProfile::factory()->create(['company_id' => $other->id]),
            'tier' => SLATier::factory()->create(['company_id' => $other->id]),
            'package' => ServicePackage::factory()->create(['company_id' => $other->id]),
        };
        $this->actingAs($this->admin);

        foreach (['deactivate', 'activate', 'restore'] as $action) {
            $this->postJson(route("admin.{$routeBase}.{$action}", $foreign->public_id))->assertNotFound();
        }
        $this->deleteJson(route("admin.{$routeBase}.destroy", $foreign->public_id))->assertNotFound();
        $this->assertTrue($foreign->fresh()->is_active);
    }

    public function test_bandwidth_index_status_filter_values_and_the_references_payload(): void
    {
        $this->package();
        BandwidthProfile::factory()->create(['company_id' => $this->company->id, 'name' => 'BW off', 'is_active' => false]);
        BandwidthProfile::factory()->create(['company_id' => $this->company->id, 'name' => 'BW gone'])->delete();
        $route = 'admin.bandwidth-profiles.index';

        $this->assertSame(['BW 20', 'BW off'], $this->listed($route, 'bandwidthProfiles'));
        $this->assertSame(['BW 20', 'BW off'], $this->listed($route, 'bandwidthProfiles', 'bogus'), 'an unknown value is the default view');
        $this->assertSame(['BW 20'], $this->listed($route, 'bandwidthProfiles', 'aktif'));
        $this->assertSame(['BW off'], $this->listed($route, 'bandwidthProfiles', 'nonaktif'));
        $this->assertSame(['BW gone'], $this->listed($route, 'bandwidthProfiles', 'dihapus'));
        $this->assertSame(['BW 20', 'BW gone', 'BW off'], $this->listed($route, 'bandwidthProfiles', 'semua'));

        $props = $this->props($this->actingAs($this->admin)->get(route($route, ['status' => 'semua'])));
        $rows = collect($props['bandwidthProfiles']['data'])->keyBy('name');

        $this->assertSame('semua', $props['filters']['status']);
        $this->assertSame([['label' => 'paket', 'count' => 1], ['label' => 'versi paket', 'count' => 1]], $rows['BW 20']['references']);
        $this->assertSame([['label' => 'paket', 'count' => 0], ['label' => 'versi paket', 'count' => 0]], $rows['BW off']['references']);
        $this->assertNull($rows['BW 20']['deleted_at']);
        $this->assertNotNull($rows['BW gone']['deleted_at']);
        $this->assertSame(['create' => true, 'update' => true, 'delete' => true, 'export' => true], $props['can']);

        $csv = $this->get(route('admin.bandwidth-profiles.export', ['format' => 'csv', 'status' => 'nonaktif']))->assertOk()->streamedContent();
        $this->assertStringContainsString('BW off', $csv);
        $this->assertStringNotContainsString('BW 20', $csv);
    }

    public function test_speed_profile_and_tier_indexes_carry_status_references_and_can(): void
    {
        $this->package();
        SpeedProfile::factory()->create(['company_id' => $this->company->id, 'name' => 'SP off', 'is_active' => false]);
        SLATier::factory()->create(['company_id' => $this->company->id, 'name' => 'Bronze', 'is_active' => false]);

        $speeds = $this->props($this->actingAs($this->admin)->get(route('admin.speed-profiles.index', ['status' => 'nonaktif'])));
        $tiers = $this->props($this->get(route('admin.sla-tiers.index', ['status' => 'aktif'])));

        $this->assertSame(['SP off'], collect($speeds['speedProfiles']['data'])->pluck('name')->all());
        $this->assertSame('nonaktif', $speeds['filters']['status']);
        $this->assertSame([['label' => 'paket', 'count' => 0], ['label' => 'versi paket', 'count' => 0]], $speeds['speedProfiles']['data'][0]['references']);
        $this->assertSame(['create' => true, 'update' => true, 'delete' => true, 'export' => true], $speeds['can']);
        $this->assertSame(['Gold'], collect($tiers['slaTiers']['data'])->pluck('name')->all());
        $this->assertSame([['label' => 'paket', 'count' => 1], ['label' => 'versi paket', 'count' => 1]], $tiers['slaTiers']['data'][0]['references']);
        $this->assertSame(['create' => true, 'update' => true, 'delete' => true, 'export' => true], $tiers['can']);
    }

    public function test_package_index_status_replaces_is_active(): void
    {
        $live = $this->package(['code' => 'ON-1', 'name' => 'Aktif']);
        $this->package(['code' => 'OFF-1', 'name' => 'Mati', 'is_active' => false]);
        $this->package(['code' => 'GONE-1', 'name' => 'Hilang'])->delete();
        $this->subscription($live);
        $route = 'admin.service-packages.index';

        $this->assertSame(['Aktif', 'Mati'], $this->listed($route, 'servicePackages'));
        $this->assertSame(['Aktif'], $this->listed($route, 'servicePackages', 'aktif'));
        $this->assertSame(['Mati'], $this->listed($route, 'servicePackages', 'nonaktif'));
        $this->assertSame(['Hilang'], $this->listed($route, 'servicePackages', 'dihapus'));
        $this->assertSame(['Aktif', 'Hilang', 'Mati'], $this->listed($route, 'servicePackages', 'semua'));

        $props = $this->props($this->actingAs($this->admin)->get(route($route, ['is_active' => '0', 'status' => 'semua'])));
        $rows = collect($props['servicePackages']['data'])->keyBy('name');

        $this->assertSame([['label' => 'langganan', 'count' => 1]], $rows['Aktif']['references']);
        $this->assertSame([['label' => 'langganan', 'count' => 0]], $rows['Mati']['references']);
        $this->assertNotNull($rows['Hilang']['deleted_at']);
        $this->assertSame(['create' => true, 'update' => true, 'delete' => true, 'export' => true], $props['can']);
        $this->assertArrayHasKey('status', $props['filters']);
        $this->assertArrayNotHasKey('is_active', $props['filters']);

        $this->assertSame(['Aktif', 'Mati'], $this->listed($route, 'servicePackages', null, ['is_active' => '0']), 'the old parameter filters nothing');
    }

    public function test_package_with_terminated_subscription_cannot_be_hard_deleted(): void
    {
        $package = $this->package(['code' => 'USED-1']);
        $this->subscription($package, 'terminated');
        $unused = $this->package(['code' => 'FREE-1']);
        $this->actingAs($this->admin);

        $this->deleteJson(route('admin.service-packages.destroy', $package))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lifecycle' => 'Paket layanan masih dipakai 1 langganan. Nonaktifkan saja.']);
        $this->assertDatabaseHas('service_packages', ['id' => $package->id, 'deleted_at' => null]);

        $this->from('/back')->post(route('admin.service-packages.deactivate', $package->public_id))
            ->assertSessionHas('success', 'Paket layanan dinonaktifkan.');
        $this->assertFalse($package->fresh()->is_active);

        $this->delete(route('admin.service-packages.destroy', $unused))->assertSessionHas('success', 'Paket layanan dihapus permanen.');
        $this->assertDatabaseMissing('service_packages', ['id' => $unused->id]);
        $this->assertDatabaseMissing('service_package_versions', ['service_package_id' => $unused->id]);
    }

    #[DataProvider('pickedTerms')]
    public function test_store_and_update_package_reject_inactive_profiles_but_keep_current(string $field, string $model, string $options): void
    {
        $retired = $model::factory()->create(['company_id' => $this->company->id, 'name' => 'Pensiun', 'is_active' => false]);
        $otherRetired = $model::factory()->create(['company_id' => $this->company->id, 'name' => 'Pensiun 2', 'is_active' => false]);
        $held = $this->package([$field => $retired->id, 'code' => 'HELD-1']);
        $update = fn (array $overrides) => $this->putJson(route('admin.service-packages.update', $held), $this->payload([
            'code' => 'HELD-1', $field => $retired->public_id, OptimisticLock::FIELD => OptimisticLock::version($held->fresh()), ...$overrides,
        ]));
        $this->actingAs($this->admin);

        $this->postJson(route('admin.service-packages.store'), $this->payload([$field => $retired->public_id]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseMissing('service_packages', ['code' => 'NEW-1']);

        $update(['name' => 'Renamed'])->assertRedirect(route('admin.service-packages.index'));
        $this->assertSame('Renamed', $held->fresh()->name);

        $update([$field => $otherRetired->public_id])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($retired->id, $held->fresh()->{$field});

        $this->assertNotContains('Pensiun', $this->formOptions('admin.service-packages.create', $options), 'a new package cannot pick it');
        $this->assertContains('Pensiun', $this->formOptions('admin.service-packages.edit', $options, $held), 'its own package keeps it');
        $this->assertNotContains('Pensiun 2', $this->formOptions('admin.service-packages.edit', $options, $held));
    }

    public function test_package_show_lists_versions_with_subscription_counts(): void
    {
        $package = $this->package(['name' => 'Home 20', 'price_mrc' => 250000]);
        $this->subscription($package);
        $package->update(['price_mrc' => '300000.00']);
        $this->subscription($package);
        $this->subscription($package);

        $versions = $this->props($this->actingAs($this->admin)->get(route('admin.service-packages.show', $package))->assertOk())['versions']['data'];

        $this->assertSame([2, 1], array_column($versions, 'version'), 'the current version comes first');
        $this->assertSame([2, 1], array_column($versions, 'subscriptions_count'));
        $this->assertSame(['300000.00', '250000.00'], array_column($versions, 'price_mrc'));
        $this->assertSame('BW 20', $versions[1]['bandwidth_profile']['name']);
        $this->assertSame('SP 20', $versions[1]['speed_profile']['name']);
        $this->assertSame('Gold', $versions[1]['sla_tier']['name']);
        $this->assertSame(['name' => 'Rumahan'], $versions[1]['category']);
    }

    public function test_category_guard_counts_packages_through_versions(): void
    {
        $moved = ServicePackageCategory::factory()->create(['company_id' => $this->company->id, 'code' => 'BISNIS', 'name' => 'Bisnis']);
        $this->package()->update(['service_package_category_id' => $moved->id]);
        $this->actingAs($this->admin);

        // No package is in Rumahan any more, but its first version was sold there and still points at it.
        $this->deleteJson(route('admin.service-package-categories.destroy', $this->category))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category' => 'Kategori masih dipakai 1 paket. Nonaktifkan saja.']);
        $this->assertDatabaseHas('service_package_categories', ['id' => $this->category->id]);

        $this->deleteJson(route('admin.service-package-categories.destroy', $moved))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category' => 'Kategori masih dipakai 1 paket. Nonaktifkan saja.']);
    }

    public function test_sla_tier_compliance_follows_the_version_tier(): void
    {
        $silver = SLATier::factory()->create(['company_id' => $this->company->id, 'name' => 'Silver']);
        $package = $this->package();
        $this->ticket($this->subscription($package));

        // The package moves on to Silver; the subscription sold under Gold keeps the version, and so its tickets.
        $package->update(['sla_tier_id' => $silver->id]);
        $sold = $this->subscription($package);
        $this->ticket($sold);
        $this->ticket($sold);

        $rows = collect($this->props($this->actingAs($this->admin)->get(route('admin.sla-tiers.index'))->assertOk())['slaTiers']['data'])->keyBy('name');

        $this->assertSame(1, $rows['Gold']['tickets_30d']);
        $this->assertSame(2, $rows['Silver']['tickets_30d']);
    }

    public function test_a_package_is_not_saved_when_its_first_version_cannot_be_recorded(): void
    {
        ServicePackageVersion::creating(function (): void {
            throw new RuntimeException('version insert failed');
        });
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->admin)->post(route('admin.service-packages.store'), $this->payload(['code' => 'ATOMIC-1']));
            $this->fail('The version failure must reach the caller.');
        } catch (RuntimeException $e) {
            $this->assertSame('version insert failed', $e->getMessage());
        }

        $this->assertDatabaseMissing('service_packages', ['code' => 'ATOMIC-1']);
    }

    public function test_store_creates_the_package_with_its_first_version(): void
    {
        $this->actingAs($this->admin)->post(route('admin.service-packages.store'), $this->payload(['code' => 'FRESH-1']))
            ->assertRedirect(route('admin.service-packages.index'))->assertSessionHas('success', 'Paket layanan dibuat.');

        $package = ServicePackage::query()->where('code', 'FRESH-1')->sole();
        $this->assertSame([1], $package->versions()->pluck('version')->all());
    }

    /** @param  list<string>  $permissions  none: the role of the same name as the given one is used as it is */
    private function user(string $role, array $permissions = []): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id, 'email_verified_at' => now(), 'is_active' => true]);

        return $this->grantRole(
            $user,
            $permissions === [] ? $role : $this->companyRole($this->company, $role, ['app.web', ...$permissions]),
            $this->company,
        );
    }

    /** @param  array<string, mixed>  $attributes */
    private function package(array $attributes = []): ServicePackage
    {
        return ServicePackage::factory()->create([
            'company_id' => $this->company->id,
            'bandwidth_profile_id' => $this->bandwidth->id,
            'speed_profile_id' => $this->speed->id,
            'sla_tier_id' => $this->tier->id,
            'service_package_category_id' => $this->category->id,
            ...$attributes,
        ]);
    }

    private function subscription(ServicePackage $package, string $status = 'active'): ServiceSubscription
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        return ServiceSubscription::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => CustomerAddress::factory()->create(['customer_id' => $customer->id])->id,
            'status' => $status,
        ]);
    }

    private function ticket(ServiceSubscription $subscription): Ticket
    {
        $category = TicketCategory::withoutCompany()->where('company_id', $this->company->id)->first()
            ?? TicketCategory::query()->forceCreate([
                'company_id' => $this->company->id, 'name' => 'Gangguan', 'code' => 'gangguan', 'default_sla_hours' => 8,
                'default_response_hours' => 4, 'default_priority' => 'medium', 'is_active' => true,
            ]);

        return Ticket::query()->forceCreate([
            'company_id' => $this->company->id,
            'code' => 'TCK-'.++$this->sequence,
            'title' => 'Lifecycle fixture '.$this->sequence,
            'source' => 'internal',
            'category_id' => $category->id,
            'subscription_id' => $subscription->id,
            'status' => 'open',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
            'created_at' => now()->subDay(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'code' => 'NEW-1',
            'name' => 'Paket Baru',
            'bandwidth_profile_id' => $this->bandwidth->public_id,
            'speed_profile_id' => $this->speed->public_id,
            'sla_tier_id' => $this->tier->public_id,
            'service_package_category_id' => $this->category->public_id,
            'price_mrc' => 200000,
            'is_active' => true,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string> the names an index lists for a status, sorted
     */
    private function listed(string $route, string $prop, ?string $status = null, array $query = []): array
    {
        $response = $this->actingAs($this->admin)->get(route($route, [...$query, ...($status === null ? [] : ['status' => $status])]))->assertOk();

        return $this->names($this->props($response)[$prop]['data']);
    }

    /** @return list<string> the names a package form offers in a picker, sorted */
    private function formOptions(string $route, string $prop, ?ServicePackage $package = null): array
    {
        $response = $this->actingAs($this->admin)->get(route($route, $package === null ? [] : [$package]))->assertOk();

        return $this->names($this->props($response)[$prop]['data']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function names(array $rows): array
    {
        return collect($rows)->pluck('name')->sort()->values()->all();
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }
}
