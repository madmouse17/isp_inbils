<?php

namespace Tests\Feature\Service;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Services\Core\CompanyService;
use App\Support\PublicId;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Service\Http\Resources\ServicePackageVersionResource;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Service\Models\ServicePackageVersion;
use Modules\Service\Models\SLATier;
use Modules\Service\Models\SpeedProfile;
use Modules\Service\Services\ServicePackageVersionBackfill;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Package versions (spec .scratch/master-lifecycle D3): a change to a term a subscription is sold under appends a
 * version and leaves the subscriptions on theirs, a rename changes nothing but the package, and the backfill that
 * versions what predates the table can be run again. The test of the subscription migration changes the schema, and
 * MySQL commits on DDL: it removes every row it wrote itself.
 */
class ServicePackageVersionTest extends TestCase
{
    use RefreshDatabase;

    private const VERSIONS_MIGRATION = 'Modules/Service/database/migrations/2026_10_23_100000_create_service_package_versions_table.php';

    private const SUBSCRIPTION_MIGRATION = 'database/migrations/2026_10_23_100100_add_service_package_version_id_to_service_subscriptions_table.php';

    private Company $company;

    /** @var array<string, int> the profiles, tier and category every package of the test starts with */
    private array $terms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['is_active' => true]);
        CompanyService::useCompanyForTests((int) $this->company->id);

        $companyId = $this->company->id;
        $this->terms = [
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['company_id' => $companyId, 'name' => 'BW base'])->id,
            'speed_profile_id' => SpeedProfile::factory()->create(['company_id' => $companyId, 'name' => 'SP base'])->id,
            'sla_tier_id' => SLATier::factory()->create(['company_id' => $companyId, 'name' => 'SLA base'])->id,
            'service_package_category_id' => ServicePackageCategory::factory()->create(['company_id' => $companyId])->id,
        ];
    }

    /** @return array<string, array{string}> */
    public static function versionedTerms(): array
    {
        return array_combine(ServicePackage::VERSIONED_TERMS, array_map(fn (string $term): array => [$term], ServicePackage::VERSIONED_TERMS));
    }

    /** @return array<string, array{class-string, string}> the relations of master-lifecycle design 4.8 that this slice owns */
    public static function historyRelations(): array
    {
        $relations = [
            ServicePackage::class => ['bandwidthProfile', 'speedProfile', 'slaTier'],
            ServiceSubscription::class => ['customer', 'servicePackage', 'installationAddress', 'servingPop', 'ontAsset'],
            ServicePackageVersion::class => ['package', 'bandwidthProfile', 'speedProfile', 'slaTier'],
        ];
        $cases = [];

        foreach ($relations as $model => $names) {
            foreach ($names as $name) {
                $cases[class_basename($model).'::'.$name] = [$model, $name];
            }
        }

        return $cases;
    }

    public function test_creating_a_package_records_version_one(): void
    {
        $this->travelTo('2026-03-01 10:00:00');

        $package = $this->package(['price_mrc' => 250000, 'price_otc' => 150000]);
        $versions = $package->versions;

        $this->assertCount(1, $versions);
        $this->assertSame(1, $versions[0]->version);
        $this->assertSame($this->company->id, $versions[0]->company_id);
        $this->assertSame('250000.00', $versions[0]->price_mrc);
        $this->assertSame('150000.00', $versions[0]->price_otc);
        $this->assertSame('2026-03-01 10:00:00', $versions[0]->valid_from->toDateTimeString());

        foreach ($this->terms as $column => $id) {
            $this->assertSame($id, $versions[0]->{$column}, $column);
        }
    }

    public function test_a_package_saved_without_an_installation_fee_records_a_zero_fee(): void
    {
        $package = ServicePackage::query()->create([
            'code' => 'PKG-NOFEE',
            'name' => 'Without installation fee',
            ...$this->terms,
            'price_mrc' => 99000,
        ]);

        $this->assertSame('0.00', $package->versions()->firstOrFail()->price_otc);
        $this->assertSame('0.00', $package->fresh()->price_otc);
    }

    #[DataProvider('versionedTerms')]
    public function test_changing_a_versioned_term_appends_a_version(string $term): void
    {
        $package = $this->package(['price_mrc' => 250000, 'price_otc' => 150000]);
        $before = $package->versions->first()->getAttribute($term);
        $after = $this->otherValue($term);

        $package->update([$term => $after]);

        $versions = $package->versions()->get();
        $this->assertSame([1, 2], $versions->pluck('version')->all());
        $this->assertEquals($before, $versions[0]->getAttribute($term));
        $this->assertEquals($after, $versions[1]->getAttribute($term));
        $this->assertEquals($after, $package->fresh()->getAttribute($term), 'the package row keeps the current terms');
    }

    public function test_several_changed_terms_make_one_version(): void
    {
        $package = $this->package(['price_mrc' => 250000]);

        $package->update([
            'price_mrc' => '300000.00',
            'speed_profile_id' => $this->otherValue('speed_profile_id'),
            'name' => 'Renamed in the same edit',
        ]);

        $this->assertSame([1, 2], $package->versions()->pluck('version')->all());
    }

    public function test_a_rename_or_another_change_in_place_does_not_version(): void
    {
        $package = $this->package();

        $package->update([
            'code' => 'PKG-RENAMED',
            'name' => 'Renamed',
            'description' => 'Another text',
            'contract_min_months' => 24,
            'is_active' => false,
        ]);

        $this->assertSame([1], $package->versions()->pluck('version')->all());
        $this->assertSame('Renamed', $package->fresh()->name);
    }

    public function test_saving_the_same_terms_in_another_notation_does_not_version(): void
    {
        $package = $this->package(['price_mrc' => 250000, 'price_otc' => 150000])->fresh();

        // A form posts every field back, and the numbers arrive as strings.
        $package->update([
            'price_mrc' => '250000',
            'price_otc' => 150000.0,
            'bandwidth_profile_id' => (string) $this->terms['bandwidth_profile_id'],
            'speed_profile_id' => $this->terms['speed_profile_id'],
            'sla_tier_id' => (string) $this->terms['sla_tier_id'],
            'service_package_category_id' => $this->terms['service_package_category_id'],
        ]);

        $this->assertSame([1], $package->versions()->pluck('version')->all());
    }

    public function test_deleting_and_restoring_a_package_does_not_version(): void
    {
        $package = $this->package();

        $package->delete();
        $package->restore();

        $this->assertSame([1], ServicePackageVersion::query()->where('service_package_id', $package->id)->pluck('version')->all());
    }

    public function test_a_subscription_keeps_its_version_when_the_package_moves_on(): void
    {
        $package = $this->package(['price_mrc' => 250000]);
        $subscription = $this->subscription($package);
        $first = $package->versions()->firstOrFail();

        $package->update(['price_mrc' => '300000.00']);

        $subscription = $subscription->fresh();
        $this->assertSame($first->id, $subscription->service_package_version_id);
        $this->assertSame(1, $subscription->packageVersion->version);
        $this->assertSame('250000.00', $subscription->packageVersion->price_mrc);
        $this->assertSame('300000.00', $subscription->servicePackage->price_mrc);
        $this->assertSame(2, $subscription->servicePackage->latestVersion->version);
    }

    public function test_a_new_subscription_gets_the_latest_version_and_an_old_one_keeps_its_own(): void
    {
        $package = $this->package(['price_mrc' => 250000]);
        $old = $this->subscription($package);
        $package->update(['price_mrc' => '300000.00']);

        $new = $this->subscription($package);

        $this->assertSame(2, $new->packageVersion->version);
        $this->assertSame(1, $old->fresh()->packageVersion->version);
    }

    public function test_a_subscription_created_without_a_tenant_context_still_gets_a_version(): void
    {
        $package = $this->package();
        $package->update(['price_mrc' => '300000.00']);
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $address = CustomerAddress::factory()->create(['company_id' => $this->company->id, 'customer_id' => $customer->id]);
        CompanyService::useCompanyForTests(null);

        $subscription = ServiceSubscription::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => $address->id,
        ]);

        $this->assertSame(2, ServicePackageVersion::withoutCompany()->findOrFail($subscription->service_package_version_id)->version);
    }

    public function test_an_explicit_version_is_kept(): void
    {
        $package = $this->package(['price_mrc' => 250000]);
        $first = $package->versions()->firstOrFail();
        $package->update(['price_mrc' => '300000.00']);

        $subscription = $this->subscription($package, ['service_package_version_id' => $first->id]);

        $this->assertSame($first->id, $subscription->service_package_version_id);
    }

    public function test_versions_are_immutable(): void
    {
        $version = $this->package(['price_mrc' => 250000])->versions()->firstOrFail();

        try {
            $version->update(['price_mrc' => '1.00']);
            $this->fail('A version must not be edited.');
        } catch (LogicException $exception) {
            $this->assertSame('Package versions are immutable.', $exception->getMessage());
        }

        $this->assertSame('250000.00', $version->fresh()->price_mrc);
    }

    public function test_a_version_number_is_unique_within_its_package(): void
    {
        $package = $this->package();

        $this->expectException(QueryException::class);

        DB::table('service_package_versions')->insert([
            'company_id' => $this->company->id,
            'service_package_id' => $package->id,
            'version' => 1,
            ...$this->terms,
            'price_mrc' => 1,
            'valid_from' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_version_a_subscription_was_sold_under_cannot_be_deleted(): void
    {
        $subscription = $this->subscription($this->package());

        $this->expectException(QueryException::class);

        DB::table('service_package_versions')->where('id', $subscription->service_package_version_id)->delete();
    }

    public function test_deleting_an_unreferenced_package_takes_its_versions_with_it(): void
    {
        $package = $this->package();
        $package->update(['price_mrc' => '300000.00']);

        $package->forceDelete();

        $this->assertSame(0, DB::table('service_package_versions')->where('service_package_id', $package->id)->count());
    }

    public function test_versions_are_scoped_to_their_company(): void
    {
        $package = $this->package();
        CompanyService::useCompanyForTests((int) Company::factory()->create()->id);

        $this->assertSame(0, ServicePackageVersion::query()->count());
        $this->assertSame(1, ServicePackageVersion::withoutCompany()->where('service_package_id', $package->id)->count());
    }

    public function test_latest_version_picks_the_highest_version_even_when_eager_loaded(): void
    {
        $moved = $this->package(['code' => 'PKG-A', 'price_mrc' => 100000]);
        $moved->update(['price_mrc' => '110000.00']);
        $moved->update(['price_otc' => '5000.00']);
        $this->package(['code' => 'PKG-B']);

        $packages = ServicePackage::query()->with('latestVersion')->orderBy('code')->get();

        $this->assertSame(['PKG-A', 'PKG-B'], $packages->pluck('code')->all());
        $this->assertSame([3, 1], $packages->map(fn (ServicePackage $package): int => $package->latestVersion->version)->all());
        $this->assertSame([1, 2, 3], $moved->versions->pluck('version')->all());
    }

    #[DataProvider('historyRelations')]
    public function test_history_relations_include_soft_deleted_parents(string $model, string $relation): void
    {
        $removed = (new $model())->{$relation}()->getQuery()->removedScopes();

        $this->assertContains(SoftDeletingScope::class, $removed, "{$model}::{$relation}() must keep a trashed parent");
    }

    public function test_the_version_resource_names_the_terms_without_an_id_and_survives_a_deleted_tier(): void
    {
        $package = $this->package(['price_mrc' => 250000, 'price_otc' => 150000]);
        $package->slaTier->delete();
        $version = $package->versions()->with(['bandwidthProfile', 'speedProfile', 'slaTier', 'category'])->withCount('subscriptions')->firstOrFail();

        $payload = (new ServicePackageVersionResource($version))->toArray(request());

        $this->assertSame(
            ['version', 'valid_from', 'price_mrc', 'price_otc', 'bandwidth_profile', 'speed_profile', 'sla_tier', 'category', 'subscriptions_count'],
            array_keys($payload),
        );
        $this->assertSame(1, $payload['version']);
        $this->assertSame('250000.00', $payload['price_mrc']);
        $this->assertSame('150000.00', $payload['price_otc']);
        $this->assertSame(['name', 'download_mbps', 'upload_mbps'], array_keys($payload['bandwidth_profile']));
        $this->assertSame(['name', 'download_max_mbps', 'upload_max_mbps'], array_keys($payload['speed_profile']));
        $this->assertSame(['name', 'response_time_hours', 'resolution_time_hours'], array_keys($payload['sla_tier']));
        $this->assertSame('SLA base', $payload['sla_tier']['name']);
        $this->assertSame(['name'], array_keys($payload['category']));
        $this->assertSame(0, $payload['subscriptions_count']);
    }

    public function test_package_lifecycle_declares_active_filters_and_counts_only_subscriptions(): void
    {
        $package = $this->package();

        $this->assertSame('Paket layanan', ServicePackage::lifecycleNoun());
        $this->assertSame(['aktif', 'nonaktif'], array_keys(ServicePackage::lifecycleFilters()));
        $this->assertTrue(ServicePackage::isDeactivatable());
        $this->assertSame([['label' => 'langganan', 'count' => 0]], $package->countReferences(), 'the package own versions never keep it from a delete');

        $this->subscription($package);

        $this->assertSame([['label' => 'langganan', 'count' => 1]], $package->countReferences());
    }

    public function test_backfill_creates_missing_versions_once(): void
    {
        $live = $this->rawPackage('RAW-LIVE', '2025-01-15 08:30:00');
        $trashed = $this->rawPackage('RAW-TRASHED', '2025-02-01 09:00:00', trashed: true);
        $undated = $this->rawPackage('RAW-UNDATED', null);
        $versioned = $this->package(['code' => 'MODEL-1']);
        $versioned->update(['price_mrc' => '300000.00']);
        $this->travelTo('2026-03-01 10:00:00');

        ServicePackageVersionBackfill::versions();
        ServicePackageVersionBackfill::versions();

        $rows = DB::table('service_package_versions')->whereIn('service_package_id', [$live, $trashed, $undated])->orderBy('service_package_id')->get();
        $this->assertCount(3, $rows, 'one version per package, trashed packages included, nothing doubled by the second run');
        $this->assertSame([1, 1, 1], $rows->pluck('version')->map(fn (mixed $version): int => (int) $version)->all());
        $this->assertSame(['2025-01-15 08:30:00', '2025-02-01 09:00:00', '2026-03-01 10:00:00'], $rows->pluck('valid_from')->all());

        foreach ($rows as $row) {
            $this->assertSame($this->company->id, (int) $row->company_id);
            $this->assertSame('125000.00', $row->price_mrc);
            $this->assertSame('50000.00', $row->price_otc);

            foreach ($this->terms as $column => $id) {
                $this->assertSame($id, (int) $row->{$column}, $column);
            }
        }

        $this->assertSame(2, DB::table('service_package_versions')->where('service_package_id', $versioned->id)->count(), 'a package that has versions is not touched');
    }

    public function test_running_the_versions_migration_again_adds_nothing(): void
    {
        $this->package();
        $before = DB::table('service_package_versions')->count();

        (require base_path(self::VERSIONS_MIGRATION))->up();

        $this->assertSame($before, DB::table('service_package_versions')->count());
    }

    public function test_the_subscription_migration_points_every_subscription_at_the_latest_version_and_can_run_again(): void
    {
        $migration = require base_path(self::SUBSCRIPTION_MIGRATION);
        $moved = $this->package(['code' => 'PKG-MOVED', 'price_mrc' => 250000]);
        $kept = $this->package(['code' => 'PKG-KEPT']);
        $sold = $this->subscription($moved);
        $soldKept = $this->subscription($kept);
        $trashedSale = $this->subscription($moved);
        $trashedSale->delete();
        $moved->update(['price_mrc' => '300000.00']);
        $latest = fn (ServicePackage $package): int => (int) ServicePackageVersion::query()->where('service_package_id', $package->id)->orderByDesc('version')->value('id');

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('service_subscriptions', 'service_package_version_id'));

            $migration->up();
            $migration->up();

            $versionOf = fn (ServiceSubscription $subscription): int => (int) DB::table('service_subscriptions')->where('id', $subscription->id)->value('service_package_version_id');
            $this->assertSame($latest($moved), $versionOf($sold), 'sold under version 1, the backfill joins it to the latest version');
            $this->assertSame($latest($moved), $versionOf($trashedSale), 'a trashed subscription is backfilled too');
            $this->assertSame($latest($kept), $versionOf($soldKept));

            $column = collect(Schema::getColumns('service_subscriptions'))->firstWhere('name', 'service_package_version_id');
            $this->assertFalse($column['nullable']);
            $foreignKeys = collect(Schema::getForeignKeys('service_subscriptions'))->filter(fn (array $key): bool => $key['columns'] === ['service_package_version_id']);
            $this->assertCount(1, $foreignKeys, 'the second run adds no second constraint');
            $this->assertSame('service_package_versions', $foreignKeys->first()['foreign_table']);
            $this->assertSame('restrict', strtolower($foreignKeys->first()['on_delete']));
        } finally {
            if (! Schema::hasColumn('service_subscriptions', 'service_package_version_id')) {
                $migration->up();
            }

            $this->deleteWhatTheTestWrote();
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function package(array $attributes = []): ServicePackage
    {
        return ServicePackage::factory()->create([
            'company_id' => $this->company->id,
            ...$this->terms,
            'price_mrc' => 250000,
            'price_otc' => 150000,
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function subscription(ServicePackage $package, array $attributes = []): ServiceSubscription
    {
        $customer = Customer::factory()->create(['company_id' => $this->company->id]);

        return ServiceSubscription::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'installation_address_id' => CustomerAddress::factory()->create(['company_id' => $this->company->id, 'customer_id' => $customer->id])->id,
            ...$attributes,
        ]);
    }

    /** A package row written around the model hook, as the seeders and old code did. */
    private function rawPackage(string $code, ?string $createdAt, bool $trashed = false): int
    {
        return DB::table('service_packages')->insertGetId([
            'public_id' => PublicId::new(),
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => 'Raw '.$code,
            ...$this->terms,
            'price_mrc' => 125000,
            'price_otc' => 50000,
            'is_active' => true,
            'deleted_at' => $trashed ? now() : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** A value for the term that differs from what `package()` starts with. */
    private function otherValue(string $term): string|int
    {
        $companyId = $this->company->id;

        return match ($term) {
            'price_mrc' => '999999.00',
            'price_otc' => '12345.00',
            'bandwidth_profile_id' => BandwidthProfile::factory()->create(['company_id' => $companyId, 'name' => 'BW other'])->id,
            'speed_profile_id' => SpeedProfile::factory()->create(['company_id' => $companyId, 'name' => 'SP other'])->id,
            'sla_tier_id' => SLATier::factory()->create(['company_id' => $companyId, 'name' => 'SLA other'])->id,
            'service_package_category_id' => ServicePackageCategory::factory()->create(['company_id' => $companyId])->id,
        };
    }

    /** Children first; the versions go with their packages. */
    private function deleteWhatTheTestWrote(): void
    {
        $tables = [
            'service_subscriptions', 'customer_addresses', 'customers', 'service_packages', 'service_package_categories',
            'bandwidth_profiles', 'speed_profiles', 'sla_tiers', 'activity_log',
        ];

        foreach ($tables as $table) {
            DB::table($table)->where('company_id', $this->company->id)->delete();
        }

        DB::table('companies')->where('id', $this->company->id)->delete();
    }
}
