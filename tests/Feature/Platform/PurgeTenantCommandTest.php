<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Platform\TenantExport;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Platform\PlatformAuditService;
use App\Support\Platform\TenantTableRegistry;
use App\Support\PublicId;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * inbils:platform:purge-company deletes a suspended tenant and nothing else. The happy path runs the whole sample ISP
 * of two tenants through it, so every table, pivot, morph and file of a real tenant is on the line, and the second
 * tenant has to come out row for row the same.
 */
class PurgeTenantCommandTest extends PurgeTestCase
{
    private const PIVOTS = [
        'announcement_location' => ['announcements', 'announcement_id'],
        'announcement_service_package_category' => ['announcements', 'announcement_id'],
        'location_promo_banner' => ['promo_banners', 'promo_banner_id'],
        'promo_banner_service_package_category' => ['promo_banners', 'promo_banner_id'],
    ];

    private const GLOBAL_TABLES = ['permissions', 'role_templates', 'role_template_has_permissions', 'settings', 'indonesia_provinces', 'indonesia_cities', 'indonesia_districts', 'indonesia_villages', 'platform_admins', 'failed_jobs', 'jobs', 'migrations'];

    public function test_a_purge_deletes_the_tenant_its_subsidiary_and_files_and_leaves_the_other_tenant_untouched(): void
    {
        [$alpha, $alphaAdmin] = $this->newTenant('ALP');
        [$beta, $betaAdmin] = $this->newTenant('BET');
        $child = $this->subsidiary($alpha, 'ALC');
        $seeder = app(DemoDataSeeder::class);
        $seeder->seedFor($alpha, $alphaAdmin);
        $seeder->seedFor($beta, $betaAdmin);

        $emails = [...$this->addTenantExtras($alpha), ...$this->addTenantExtras($child)];
        $this->addTenantExtras($beta);
        $operator = $this->makePurgeable($alpha);
        app(CompanyMembershipService::class)->grant($betaAdmin, $alpha);
        $this->platformHistory($alpha, $operator);
        $this->platformHistory($beta, $operator);

        $ids = [$alpha->id, $child->id];
        $alphaBefore = $this->everythingOf($ids);
        $betaBefore = $this->everythingOf([$beta->id]);
        $globalBefore = $this->globalRows();
        $alphaRoleIds = DB::table('roles')->whereIn('company_id', $ids)->pluck('id')->all();
        $this->assertNotEmpty($alphaRoleIds, 'provisioning copied the role templates into the tenant');
        $betaFiles = Storage::disk('local')->allFiles("companies/{$beta->id}");
        $betaArchive = (string) TenantExport::query()->where('company_id', $beta->id)->value('file_path');
        $this->assertCount(2, Storage::disk('local')->allFiles('platform/exports'), 'one archive per Head on disk');

        $this->assertNotEmpty(Storage::disk('local')->allFiles("companies/{$alpha->id}"), 'the sample data left files to purge');
        $this->assertNotEmpty($betaFiles);
        $this->assertGreaterThan(500, $alphaBefore['rows']);
        $this->assertGreaterThan(40, count($alphaBefore['users']));
        $this->assertNotEmpty($alphaBefore['media']);
        $this->assertNotEmpty(array_filter($alphaBefore['pivots']));

        $this->artisan('inbils:platform:purge-company', ['code' => 'ALP'])
            ->expectsOutputToContain('company ids '.$alpha->id.', '.$child->id)
            ->expectsQuestion('Type the company code [ALP] to delete the tenant for good', 'ALP')
            ->expectsOutputToContain('Tenant [ALP] was deleted.')
            ->assertExitCode(0);

        $after = $this->everythingOf($ids);
        $this->assertSame([], array_filter($after['tables']), 'nothing of the tenant is left in any tenant table');
        $this->assertSame(0, $after['rows']);
        $this->assertSame(0, Company::query()->whereIn('id', $ids)->count());
        $this->assertSame([$beta->id], DB::table('company_user')->where('user_id', $betaAdmin->id)->pluck('company_id')->all(), 'a user of another tenant who was a member here keeps their own company and loses only this membership');
        $this->assertSame(0, User::query()->whereIn('id', $alphaBefore['users'])->count());
        $this->assertSame(0, DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $alphaBefore['users'])->count());
        $this->assertSame(0, DB::table('roles')->whereIn('company_id', $ids)->count(), 'roles carry a company id: they are tenant rows and go with it');
        $this->assertSame(0, DB::table('role_has_permissions')->whereIn('role_id', $alphaRoleIds)->count(), 'and so do what they grant');
        $this->assertSame(0, DB::table('model_has_roles')->whereIn('role_id', $alphaRoleIds)->count(), 'and who holds them, members homed elsewhere included');
        $this->assertSame(0, DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $alphaBefore['users'])->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_type', Customer::class)->whereIn('notifiable_id', $alphaBefore['customers'])->count());
        $this->assertSame(0, DB::table('sessions')->whereIn('user_id', $alphaBefore['users'])->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $alphaBefore['users'])->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->whereIn('email', $emails)->count());
        $this->assertSame(0, DB::table('media')->whereIn('id', $alphaBefore['media'])->count());
        $this->assertSame(array_sum($betaBefore['pivots']), array_sum(array_map(fn (string $pivot): int => DB::table($pivot)->count(), array_keys(self::PIVOTS))), 'only the pivot rows of the other tenant remain');

        $this->assertSame([], Storage::disk('local')->allFiles("companies/{$alpha->id}"));
        $this->assertSame([], Storage::disk('public')->allFiles("companies/{$alpha->id}"));
        $this->assertSame([], Storage::disk('local')->allFiles("companies/{$child->id}"));
        $this->assertSame([], Storage::disk('public')->allFiles("companies/{$child->id}"));
        $this->assertSame([$betaArchive], Storage::disk('local')->allFiles('platform/exports'), 'the export archive of the purged tenant went with it, the other one stays');
        $this->assertSame($betaFiles, Storage::disk('local')->allFiles("companies/{$beta->id}"), 'the other tenant keeps its files');

        $this->assertEquals($betaBefore, $this->everythingOf([$beta->id]), 'the other tenant is untouched, row for row');
        $this->assertSame($globalBefore, $this->globalRows(), 'permissions, role templates, settings, regions and operators are not tenant data');
        $this->assertPlatformAuditSurvives($alpha, $child, $beta);
    }

    public function test_it_refuses_until_the_tenant_is_suspended_long_enough_has_a_deletion_request_and_a_fresh_export(): void
    {
        [$company] = $this->newTenant('ALP');

        $this->assertRefused('ALP', ['still active', 'not been suspended for 30 days (no suspension is recorded)', 'Nobody requested'], ['No export']);

        DB::table('companies')->where('id', $company->id)->update(['is_active' => false, 'suspended_at' => now()->subDays(29)]);
        $this->assertRefused('ALP', ['not been suspended for 30 days (suspended ', 'Nobody requested', 'No export'], ['still active']);

        DB::table('companies')->where('id', $company->id)->update(['suspended_at' => now()->subDays(31)]);
        $this->assertRefused('ALP', ['Nobody requested', 'No export'], ['still active', 'not been suspended']);

        DB::table('companies')->where('id', $company->id)->update(['deletion_requested_at' => now()->subDays(30)]);
        $this->assertRefused('ALP', ['No export'], ['Nobody requested']);

        $own = ['requested_by_platform_admin_id' => PlatformAdmin::factory()->create()->id, 'company_id' => $company->id];
        TenantExport::factory()->ready(now()->subDays(32)->toDateTimeString())->create($own);
        $this->assertRefused('ALP', ['No export'], [], 'an export that finished before the suspension does not count');

        TenantExport::factory()->failed()->create($own);
        TenantExport::factory()->create($own);
        TenantExport::factory()->running()->create($own);
        $this->assertRefused('ALP', ['No export'], [], 'a queued, running or failed export is no export');

        TenantExport::factory()->expired(now()->subDays(20)->toDateTimeString())->create($own);

        $this->artisan('inbils:platform:purge-company', ['code' => 'ALP'])
            ->expectsQuestion('Type the company code [ALP] to delete the tenant for good', 'ALP')
            ->assertExitCode(0);

        $this->assertSame(0, Company::query()->where('code', 'ALP')->count(), 'an expired archive still proves that an export was made');
    }

    public function test_it_refuses_a_subsidiary_a_wrong_case_code_and_an_unknown_code(): void
    {
        [$head] = $this->newTenant('ALP');
        $child = $this->subsidiary($head, 'ALC');
        $this->makePurgeable($head);
        DB::table('companies')->where('id', $child->id)->update(['deletion_requested_at' => now()->subDays(30)]);

        $this->artisan('inbils:platform:purge-company', ['code' => 'ALC'])->expectsOutputToContain('is a subsidiary')->assertExitCode(1);
        $this->artisan('inbils:platform:purge-company', ['code' => 'alp'])->expectsOutputToContain('No company has the code [alp]')->assertExitCode(1);
        $this->artisan('inbils:platform:purge-company', ['code' => 'NOPE'])->expectsOutputToContain('No company has the code [NOPE]')->assertExitCode(1);

        $this->assertSame(2, Company::query()->whereIn('code', ['ALP', 'ALC'])->count());
    }

    public function test_a_subsidiary_that_is_still_active_blocks_the_head(): void
    {
        [$head] = $this->newTenant('ALP');
        $child = $this->subsidiary($head, 'ALC');
        $this->makePurgeable($head);
        DB::table('companies')->where('id', $child->id)->update(['is_active' => true]);

        $this->assertRefused('ALP', ['one of its subsidiaries, is still active']);
    }

    public function test_it_needs_the_code_typed_again_and_never_runs_without_a_person_at_the_terminal(): void
    {
        [$company] = $this->newTenant('ALP');
        $this->makePurgeable($company);

        $this->artisan('inbils:platform:purge-company', ['code' => 'ALP', '--no-interaction' => true])
            ->expectsOutputToContain('cannot run with --no-interaction')
            ->assertExitCode(1);

        foreach (['alp', 'ALP ', '', 'ALPX'] as $typed) {
            $this->artisan('inbils:platform:purge-company', ['code' => 'ALP'])
                ->expectsQuestion('Type the company code [ALP] to delete the tenant for good', $typed)
                ->expectsOutputToContain('The code does not match. Nothing was deleted.')
                ->assertExitCode(1);
        }

        $this->assertSame(1, Company::query()->where('code', 'ALP')->count());
        $this->assertSame(0, DB::table('activity_log')->where('description', 'tenant.purge_started')->count(), 'a refusal before the confirmation writes no audit row');
    }

    public function test_it_refuses_users_who_are_also_members_of_another_company(): void
    {
        [$alpha, $alphaAdmin] = $this->newTenant('ALP');
        [$beta] = $this->newTenant('BET');
        $this->makePurgeable($alpha);
        app(CompanyMembershipService::class)->grant($alphaAdmin, $beta);

        $this->assertRefused('ALP', ['also members of another company (user ids '.$alphaAdmin->id]);
        $this->assertSame(1, DB::table('company_user')->where('user_id', $alphaAdmin->id)->where('company_id', $beta->id)->count());
    }

    public function test_a_database_failure_rolls_the_whole_purge_back_and_keeps_every_file(): void
    {
        [$alpha, $alphaAdmin] = $this->newTenant('ALP');
        [$beta] = $this->newTenant('BET');
        $this->addTenantExtras($alpha);
        $this->makePurgeable($alpha);

        // A row of another tenant points at one of this tenant's users: the database refuses the delete of the users.
        $betaCustomer = Customer::factory()->create(['company_id' => $beta->id, 'code' => 'B-1']);
        DB::table('customers')->where('id', $betaCustomer->id)->update(['acquired_by_user_id' => $alphaAdmin->id]);

        $files = Storage::disk('local')->allFiles("companies/{$alpha->id}");
        $before = $this->everythingOf([$alpha->id]);
        $this->assertNotEmpty($files);

        $this->artisan('inbils:platform:purge-company', ['code' => 'ALP'])
            ->expectsQuestion('Type the company code [ALP] to delete the tenant for good', 'ALP')
            ->expectsOutputToContain('database was left unchanged')
            ->assertExitCode(1);

        $this->assertEquals($before, $this->everythingOf([$alpha->id]), 'the purge is all or nothing');
        $this->assertSame($files, Storage::disk('local')->allFiles("companies/{$alpha->id}"), 'no file is deleted when the database refused');
        $this->assertSame(1, DB::table('activity_log')->where('log_name', 'platform')->where('description', 'tenant.purge_started')->count());
        $this->assertSame(1, DB::table('activity_log')->where('log_name', 'platform')->where('description', 'tenant.purge_failed')->count());
        $this->assertSame(0, DB::table('activity_log')->where('description', 'tenant.purge_completed')->count());
    }

    public function test_files_that_cannot_be_deleted_are_listed_and_the_exit_code_says_so(): void
    {
        [$company] = $this->newTenant('ALP');
        $this->makePurgeable($company);

        // The database part works; the disk cannot even create its root.
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => '/proc/inbils-no-such-root']]);
        Storage::forgetDisk('local');

        $this->artisan('inbils:platform:purge-company', ['code' => 'ALP'])
            ->expectsQuestion('Type the company code [ALP] to delete the tenant for good', 'ALP')
            ->expectsOutputToContain('Tenant [ALP] was deleted.')
            ->expectsOutputToContain('could not be deleted')
            ->expectsOutputToContain("local:companies/{$company->id}")
            ->assertExitCode(1);

        $this->assertSame(0, Company::query()->where('code', 'ALP')->count(), 'the database is purged even when a file stays');
        $this->assertSame(1, DB::table('activity_log')->where('description', 'tenant.purge_completed')->count());
    }

    /**
     * @param  list<string>  $present  lines the refusal prints
     * @param  list<string>  $absent  lines it must not print
     */
    private function assertRefused(string $code, array $present, array $absent = [], string $why = 'a refused purge deletes nothing'): void
    {
        $command = $this->artisan('inbils:platform:purge-company', ['code' => $code])->expectsOutputToContain("Company {$code} may not be purged");

        foreach ($present as $text) {
            $command->expectsOutputToContain($text);
        }

        foreach ($absent as $text) {
            $command->doesntExpectOutputToContain($text);
        }

        $command->assertExitCode(1);

        $this->assertSame(1, Company::query()->where('code', $code)->count(), $why);
        $this->assertSame(0, DB::table('activity_log')->where('description', 'tenant.purge_started')->count());
    }

    /**
     * A little of everything the registry does not name: a user with a token, a session, a reset token and a
     * notification, an announcement and a promo banner with their pivots, a logo on the public disk, a customer file,
     * and for a Head an export archive.
     *
     * @return list<string> the addresses that got a reset token
     */
    private function addTenantExtras(Company $company): array
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grantRole($user, 'manager', $company);
        app(CompanyMembershipService::class)->grant($user, $company, true);
        $user->createToken('mobile');
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => now()->timestamp]);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'hashed', 'created_at' => now()]);
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $location = DB::table('locations')->where('company_id', $company->id)->orderBy('id')->value('id');
        $category = DB::table('service_package_categories')->where('company_id', $company->id)->orderBy('id')->value('id');
        $announcement = DB::table('announcements')->insertGetId(['public_id' => PublicId::new(), 'company_id' => $company->id, 'title' => 'Info', 'body' => 'b', 'kind' => 'info', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $banner = DB::table('promo_banners')->insertGetId(['public_id' => PublicId::new(), 'company_id' => $company->id, 'title' => 'Promo', 'alt_text' => 'a', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('announcement_location')->insert(['announcement_id' => $announcement, 'location_id' => $location]);
        DB::table('announcement_service_package_category')->insert(['announcement_id' => $announcement, 'service_package_category_id' => $category]);
        DB::table('location_promo_banner')->insert(['location_id' => $location, 'promo_banner_id' => $banner]);
        DB::table('promo_banner_service_package_category')->insert(['promo_banner_id' => $banner, 'service_package_category_id' => $category]);

        $this->media(Company::class, $company->id, $company->id, 'public', "companies/{$company->id}/company-logo/".Str::random(4).'/logo.webp', 'logo');
        $customerId = DB::table('customers')->where('company_id', $company->id)->value('id') ?? Customer::factory()->create(['company_id' => $company->id, 'code' => "X-{$company->id}"])->id;
        $this->media(Customer::class, $customerId, $company->id, 'local', "companies/{$company->id}/customers/{$customerId}/documents/".Str::random(4).'/ktp.pdf', 'documents');
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => Customer::class, 'notifiable_id' => $customerId, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        if ($company->parent_company_id === null) {
            $export = TenantExport::factory()->ready()->create(['company_id' => $company->id, 'requested_by_platform_admin_id' => PlatformAdmin::factory()->create()->id]);
            Storage::disk('local')->put((string) $export->file_path, 'zip');
        }

        return [$user->email];
    }

    /** A media row and its file; the uploader's company id travels in custom_properties like the real ones. */
    private function media(string $modelType, int $modelId, int $companyId, string $disk, string $path, string $collection): void
    {
        Storage::disk($disk)->put($path, 'bytes');
        DB::table('media')->insert([
            'public_id' => PublicId::new(),
            'model_type' => $modelType,
            'model_id' => $modelId,
            'uuid' => (string) Str::uuid(),
            'collection_name' => $collection,
            'name' => 'f',
            'file_name' => basename($path),
            'mime_type' => 'application/octet-stream',
            'disk' => $disk,
            'conversions_disk' => $disk,
            'size' => 5,
            'manipulations' => '[]',
            'custom_properties' => json_encode(['company_id' => $companyId]),
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Platform and support audit rows for a tenant, like the lifecycle and impersonation lanes write them. */
    private function platformHistory(Company $company, PlatformAdmin $operator): void
    {
        $audit = app(PlatformAuditService::class);
        $audit->platform('tenant.suspended', ['reason' => 'complaint #1'], $company, $operator);
        $audit->platform('tenant.export_requested', ['export' => 1], $company, $operator);
        $audit->support('impersonation.started', ['reason' => 'complaint #1'], $company, $operator);
    }

    /**
     * Everything that belongs to the tenant: its rows by table (the platform audit is not its data), what it owns
     * through morphs and pivots, and the ids needed to look for leftovers.
     *
     * @param  list<int>  $ids
     * @return array{rows: int, tables: array<string, list<array<string, mixed>>>, users: list<int>, customers: list<int>, media: list<int>, pivots: array<string, int>}
     */
    private function everythingOf(array $ids): array
    {
        $tables = [];

        foreach (app(TenantTableRegistry::class)->tables() as $table) {
            $query = DB::table($table)->whereIn('company_id', $ids);

            if ($table === 'activity_log') {
                $query->where('log_name', '<>', 'platform');
            }

            $tables[$table] = $query->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        $media = DB::table('media')
            ->where(fn ($owned) => $owned->where('model_type', Company::class)->whereIn('model_id', $ids)
                ->orWhereIn(DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(custom_properties, '$.company_id')) AS UNSIGNED)"), $ids))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $pivots = [];

        foreach (self::PIVOTS as $pivot => [$parent, $column]) {
            $pivots[$pivot] = DB::table($pivot)->whereIn($column, DB::table($parent)->whereIn('company_id', $ids)->select('id'))->count();
        }

        return [
            'rows' => array_sum(array_map('count', $tables)) + count($media) + array_sum($pivots),
            'tables' => $tables,
            'users' => array_column($tables['users'], 'id'),
            'customers' => array_column($tables['customers'], 'id'),
            'media' => $media,
            'pivots' => $pivots,
        ];
    }

    /** @return array<string, int> */
    private function globalRows(): array
    {
        return collect(self::GLOBAL_TABLES)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }

    private function assertPlatformAuditSurvives(Company $alpha, Company $child, Company $beta): void
    {
        $purged = DB::table('activity_log')->where('log_name', 'platform')->orderBy('id')->get()
            ->filter(fn (object $row): bool => (json_decode((string) $row->properties, true)['tenant_code'] ?? null) === 'ALP');

        foreach (['tenant.suspended', 'tenant.export_requested', 'tenant.purge_started', 'tenant.purge_completed'] as $event) {
            $this->assertContains($event, $purged->pluck('description')->all(), "the history of the purged tenant keeps {$event}");
        }

        foreach ($purged as $row) {
            $this->assertNull($row->company_id, 'no link to the deleted company is left');
            $this->assertSame('Tenant ALP', json_decode((string) $row->properties, true)['tenant_name']);
        }

        $started = $purged->firstWhere('description', 'tenant.purge_started');
        $properties = json_decode((string) $started->properties, true);
        $this->assertSame([$alpha->id, $child->id], $properties['company_ids']);
        $this->assertGreaterThan(40, $properties['counts']['customers']);
        $this->assertGreaterThan(40, $properties['users']);
        $this->assertNull($started->causer_id, 'the command is system-caused');

        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'support')->whereIn('company_id', [$alpha->id, $child->id])->count(), 'the tenant-visible audit goes with the tenant');
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'support')->whereNull('company_id')->count(), 'and leaves no orphan behind');
        $this->assertSame(1, DB::table('activity_log')->where('log_name', 'support')->where('company_id', $beta->id)->count(), 'the other tenant keeps its support rows');
        $this->assertSame(2, DB::table('activity_log')->where('log_name', 'platform')->where('company_id', $beta->id)->count(), 'and its platform rows, still linked');
    }
}
