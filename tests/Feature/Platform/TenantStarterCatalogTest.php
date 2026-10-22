<?php

namespace Tests\Feature\Platform;

use App\Jobs\Platform\SeedTenantDemoDataJob;
use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyHierarchyService;
use App\Services\Core\CompanyService;
use App\Services\Core\SetupWizardService;
use Database\Seeders\CompanySeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery\MockInterface;
use Modules\NetworkAsset\Services\NetworkAssetService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * "Isi awal" of a new company: the starter catalog (everything an ISP edits, no stock rows, no assets), an empty one
 * (units and categories only), or the demo catalog that sample data and the dev and E2E fixtures stand on. One company
 * is seeded once, by the CompanyCreated listener, and a run never adds a second copy of a row.
 */
class TenantStarterCatalogTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /** @return array<string, int> rows a company holds per catalog table, for a catalog */
    private static function rows(string $catalog): array
    {
        $empty = ['units' => 4, 'ticket_categories' => 5, 'service_package_categories' => 3];
        $starter = [
            'sla_tiers' => 3,
            'locations' => 3,
            'bandwidth_profiles' => 5,
            'speed_profiles' => 5,
            'service_packages' => 8,
            'categories' => 5,
            'products' => 10,
        ];
        $demo = ['stocks' => 10, 'network_assets' => 10];
        $none = fn (array $tables): array => array_fill_keys(array_keys($tables), 0);

        return match ($catalog) {
            CompanySeeder::CATALOG_EMPTY => [...$empty, ...$none($starter), ...$none($demo)],
            CompanySeeder::CATALOG_STARTER => [...$empty, ...$starter, ...$none($demo)],
            CompanySeeder::CATALOG_DEMO => [...$empty, ...$starter, ...$demo],
        };
    }

    /** @return array<string, array{string}> */
    public static function catalogs(): array
    {
        return array_combine(CompanySeeder::CATALOGS, array_map(fn (string $catalog): array => [$catalog], CompanySeeder::CATALOGS));
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'PT Nusa Net',
            'code' => 'NUSA',
            'timezone' => 'Asia/Jakarta',
            'currency' => 'IDR',
            'admin_name' => 'Rina Pratama',
            'admin_email' => 'rina@nusanet.test',
            'catalog' => CompanySeeder::CATALOG_STARTER,
            ...$overrides,
        ];
    }

    private function provision(array $overrides = []): Company
    {
        $this->asOperator(false)->post(route('platform.companies.store'), $this->payload($overrides))->assertSessionHasNoErrors()->assertRedirect();

        return Company::query()->where('code', $overrides['code'] ?? 'NUSA')->firstOrFail();
    }

    /** @return array{0: Company, 1: User} a company with its first admin, made the way the setup wizard makes one */
    private function tenant(string $code, string $catalog = CompanySeeder::CATALOG_STARTER): array
    {
        $admin = User::query()->create(['name' => "Admin {$code}", 'email' => strtolower($code).'-admin@tenant.test', 'password' => Str::random(32)]);
        $admin->forceFill(['email_verified_at' => now()])->save();

        $company = app(SetupWizardService::class)->createFor($admin, [
            'name' => "Tenant {$code}",
            'code' => $code,
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'date_format' => 'd M Y',
            'datetime_format' => 'd M Y H:i',
            'admin_name' => "Admin {$code}",
        ], $catalog);

        return [$company, $admin->refresh()];
    }

    /** @return array<string, int> */
    private function counts(Company $company): array
    {
        return array_map(fn (array $ids): int => count($ids), $this->ids($company));
    }

    /** @return array<string, list<int>> the ids of the catalog rows of a company, per table */
    private function ids(Company $company): array
    {
        return collect(array_keys(self::rows(CompanySeeder::CATALOG_DEMO)))->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->where('company_id', $company->id)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        ])->all();
    }

    public function test_starter_seeds_catalog_without_stock_or_assets(): void
    {
        Notification::fake();

        $company = $this->provision(['catalog' => CompanySeeder::CATALOG_STARTER]);

        $this->assertSame(self::rows(CompanySeeder::CATALOG_STARTER), $this->counts($company));
        $this->assertSame(
            ['Wilayah Utama', 'Area Utama', 'POP Utama'],
            DB::table('locations')->where('company_id', $company->id)->orderBy('id')->pluck('name')->all(),
        );
        $this->assertTrue(DB::table('service_packages')->where(['company_id' => $company->id, 'name' => 'Home 10Mbps'])->exists());
        $this->assertSame('starter', Activity::query()->where('description', 'tenant.provisioned')->sole()->properties['catalog']);
    }

    public function test_every_company_gets_indonesian_ticket_categories_with_a_customer_hint(): void
    {
        Notification::fake();

        $company = $this->provision(['catalog' => CompanySeeder::CATALOG_EMPTY]);

        $categories = DB::table('ticket_categories')->where('company_id', $company->id)->orderBy('id')->pluck('name', 'code')->all();

        $this->assertSame([
            'no_internet' => 'Internet mati',
            'slow_connection' => 'Koneksi lambat',
            'packet_loss' => 'Koneksi putus-sambung',
            'device_issue' => 'Masalah perangkat',
            'fiber_issue' => 'Kabel fiber bermasalah',
        ], $categories);
        $this->assertSame(0, DB::table('ticket_categories')->where('company_id', $company->id)->where(fn ($query) => $query->whereNull('customer_hint')->orWhere('customer_hint', ''))->count());
        $this->assertSame(
            'Periksa lampu modem. Cabut adaptor 10 detik lalu pasang lagi. Bila lampu LOS merah, kirim laporan ini.',
            DB::table('ticket_categories')->where(['company_id' => $company->id, 'code' => 'no_internet'])->value('customer_hint'),
        );
    }

    public function test_empty_seeds_units_and_categories_only(): void
    {
        Notification::fake();

        $company = $this->provision(['catalog' => CompanySeeder::CATALOG_EMPTY]);

        $this->assertSame(self::rows(CompanySeeder::CATALOG_EMPTY), $this->counts($company));
        $this->assertSame('empty', Activity::query()->where('description', 'tenant.provisioned')->sole()->properties['catalog']);
    }

    public function test_demo_data_needs_starter(): void
    {
        Notification::fake();
        Queue::fake();

        $this->asOperator(false)->post(route('platform.companies.store'), $this->payload(['demo_data' => true, 'catalog' => CompanySeeder::CATALOG_EMPTY]))
            ->assertSessionHasErrors(['catalog' => 'Data contoh butuh Katalog awal.']);

        $this->assertDatabaseMissing('companies', ['code' => 'NUSA']);
        Queue::assertNothingPushed();

        $this->provision(['demo_data' => true, 'catalog' => CompanySeeder::CATALOG_STARTER]);

        Queue::assertPushed(SeedTenantDemoDataJob::class, 1);
    }

    public function test_company_is_seeded_once(): void
    {
        $this->partialMock(CompanySeeder::class, function (MockInterface $seeder): void {
            $seeder->shouldReceive('runFor')->once()
                ->withArgs(fn (Company $_, string $catalog): bool => $catalog === CompanySeeder::CATALOG_STARTER)
                ->passthru();
        });

        $this->tenant('WIZ');
    }

    public function test_provisioning_hands_the_chosen_catalog_to_the_seeder_once(): void
    {
        Notification::fake();
        $this->partialMock(CompanySeeder::class, function (MockInterface $seeder): void {
            $seeder->shouldReceive('runFor')->once()
                ->withArgs(fn (Company $_, string $catalog): bool => $catalog === CompanySeeder::CATALOG_EMPTY)
                ->passthru();
        });

        $this->provision(['catalog' => CompanySeeder::CATALOG_EMPTY]);
    }

    public function test_demo_assets_use_the_numbering_prefix(): void
    {
        [$company] = $this->tenant('ASSETS');

        app(CompanySeeder::class)->runFor($company, CompanySeeder::CATALOG_DEMO);

        $year = now($company->timezone)->format('Y');
        $codes = DB::table('network_assets')->where('company_id', $company->id)->orderBy('id')->pluck('code')->all();

        $this->assertSame(array_map(fn (int $number): string => sprintf('NA-%s-%05d', $year, $number), range(1, 10)), $codes);
        $this->assertSame(0, DB::table('network_assets')->where('company_id', $company->id)->where('code', 'like', 'AST-%')->count());
        $this->assertSame(
            sprintf('NA-%s-00011', $year),
            CompanyService::runAs($company->id, fn (): string => NetworkAssetService::generateCode()),
            'the next asset an admin adds goes on from the sample ones, in the same sequence',
        );
    }

    public function test_subsidiary_gets_starter(): void
    {
        [$head] = $this->tenant('HEAD');

        $subsidiary = app(CompanyHierarchyService::class)->createSubsidiary($head, ['name' => 'Cabang Satu', 'code' => 'CAB1']);

        $this->assertSame(self::rows(CompanySeeder::CATALOG_STARTER), $this->counts($subsidiary));
    }

    #[DataProvider('catalogs')]
    public function test_running_a_mode_twice_changes_nothing(string $catalog): void
    {
        [$company] = $this->tenant('TWICE', $catalog);
        $first = $this->ids($company);

        $this->assertSame(self::rows($catalog), $this->counts($company));

        app(CompanySeeder::class)->runFor($company, $catalog);

        $this->assertSame($first, $this->ids($company));
    }

    public function test_a_larger_catalog_only_adds_to_the_smaller_one(): void
    {
        [$company] = $this->tenant('GROW', CompanySeeder::CATALOG_EMPTY);
        $before = $this->ids($company);

        foreach ([CompanySeeder::CATALOG_STARTER, CompanySeeder::CATALOG_DEMO] as $catalog) {
            app(CompanySeeder::class)->runFor($company, $catalog);

            $after = $this->ids($company);

            $this->assertSame(self::rows($catalog), $this->counts($company));

            foreach ($before as $table => $ids) {
                $this->assertSame([], array_diff($ids, $after[$table]), "{$table}: a row of the smaller catalog kept its id");
            }

            $before = $after;
        }
    }

    public function test_seed_for_runs_the_demo_catalog_before_anything_of_the_story(): void
    {
        [$company, $admin] = $this->tenant('STORY');

        $this->partialMock(CompanySeeder::class, function (MockInterface $seeder) use ($company): void {
            $seeder->shouldReceive('runFor')->once()
                ->withArgs(fn (Company $given, string $catalog): bool => $given->is($company) && $catalog === CompanySeeder::CATALOG_DEMO)
                ->andThrow(new RuntimeException('the catalog comes first'));
        });

        try {
            app(DemoDataSeeder::class)->seedFor($company, $admin);
            $this->fail('the stand-in must stop the story at its first step');
        } catch (RuntimeException $e) {
            $this->assertSame('the catalog comes first', $e->getMessage());
        }

        $this->assertSame(0, DB::table('customers')->where('company_id', $company->id)->count(), 'nothing of the story ran');
        $this->assertSame(self::rows(CompanySeeder::CATALOG_STARTER), $this->counts($company), 'and the company is still the starter catalog it was');
    }

    public function test_the_demo_company_of_dev_and_e2e_keeps_its_fixture_rows(): void
    {
        $this->seed(DemoUserSeeder::class);

        $company = Company::query()->where('code', 'DEMO')->firstOrFail();
        $serials = DB::table('network_assets')->where('company_id', $company->id)->pluck('serial_number')->all();

        $this->assertSame(self::rows(CompanySeeder::CATALOG_DEMO), $this->counts($company));
        $this->assertContains('HW-MA5800-001', $serials, 'NetworkDemo installs the legacy gear by its serial');
        $this->assertTrue(DB::table('locations')->where(['company_id' => $company->id, 'name' => 'POP Utama'])->exists());
        $this->assertTrue(DB::table('service_packages')->where(['company_id' => $company->id, 'name' => 'Home 10Mbps'])->exists());
    }

    public function test_an_unknown_catalog_writes_nothing(): void
    {
        $company = Company::factory()->create();

        try {
            app(CompanySeeder::class)->runFor($company, 'full');
            $this->fail('only empty, starter and demo exist');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('full', $e->getMessage());
        }

        $this->assertSame(0, array_sum($this->counts($company)));
        $this->assertDatabaseMissing('users', ['email' => "system-seed-{$company->id}@inbils.internal"]);
    }
}
