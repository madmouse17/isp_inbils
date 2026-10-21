<?php

namespace Tests\Feature\Platform;

use App\Jobs\Platform\BuildTenantExportJob;
use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use App\Models\Core\Customer;
use App\Models\Platform\TenantExport;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Platform\PlatformAuditService;
use App\Support\Platform\TenantTableRegistry;
use App\Support\PublicId;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use Mockery;
use Modules\Billing\Models\PaymentAttempt;
use Modules\NetworkAsset\Models\NasDevice;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;
use ZipArchive;

/**
 * Exporting a tenant: the request (double confirmation, one at a time), the job that builds one ZIP of CSV files plus
 * the tenant's files on the private disk, the signed 24-hour download, the page that lists exports, and the cleanup
 * that deletes the archive and keeps the record. The two-tenant test is the proof that an archive holds its own
 * tenant's rows and files and nobody else's.
 */
class TenantExportTest extends PurgeTestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 12:00:00');
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    private function requestExport(Company $root, array $overrides = [], bool $confirmed = true): TestResponse
    {
        return $this->asOperator($confirmed)->post(route('platform.companies.exports.store', $root), ['confirmation_code' => $root->code, ...$overrides]);
    }

    private function export(Company $root, string $state = 'queued', ?string $completedAt = null): TenantExport
    {
        $factory = TenantExport::factory();
        $factory = match ($state) {
            'ready' => $factory->ready($completedAt),
            'expired' => $factory->expired($completedAt),
            'failed' => $factory->failed(),
            'running' => $factory->running(),
            default => $factory,
        };

        return $factory->create(['company_id' => $root->id, 'requested_by_platform_admin_id' => $this->operator->id]);
    }

    /** A finished export with a file on the private disk. */
    private function readyExport(Company $root, ?string $completedAt = null): TenantExport
    {
        $export = $this->export($root, 'ready', $completedAt);
        Storage::disk('local')->put((string) $export->file_path, 'zip-bytes');

        return $export;
    }

    private function build(Company $root): TenantExport
    {
        $export = $this->export($root);

        BuildTenantExportJob::dispatchSync($export->id, $root->id);

        return $export->refresh();
    }

    private function link(TenantExport $export, mixed $expiration = null): string
    {
        return URL::temporarySignedRoute('platform.exports.download', $expiration ?? $export->expires_at ?? now()->addHour(), ['tenantExport' => $export->public_id]);
    }

    /** @return array<string, string> entry name => contents */
    private function entries(TenantExport $export): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path((string) $export->file_path)) === true, 'the archive opens as a ZIP');
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entries[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
        }

        $zip->close();

        return $entries;
    }

    /** @return list<array<string, string>> */
    private function csv(string $contents): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents, 'UTF-8 with a byte order mark, as the exports of the app');
        $handle = fopen('php://memory', 'w+b');
        fwrite($handle, substr($contents, 3));
        rewind($handle);
        $header = fgetcsv($handle, 0, ',', '"', '');
        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $row);
        }

        fclose($handle);

        return $rows;
    }

    /** @return list<string> */
    private function header(string $contents): array
    {
        $line = strtok(substr($contents, 3), "\n");

        return str_getcsv((string) $line, ',', '"', '');
    }

    /** @return array{export: TenantExport, root: Company, child: Company} */
    private function lightTenant(string $code = 'ALP', string $name = 'Alpha Net'): array
    {
        $tenant = $this->buildTenant($code, $name);

        return ['export' => $this->export($tenant['root']), 'root' => $tenant['root'], 'child' => $tenant['child']];
    }

    /**
     * A file the way the app stores one: through the media library, in the company context a request has, so the row and
     * the path are the ones the path generator gives it. The oldest rows carry no company id of their own
     * (`$stamped = false`): their company is the one of the row they hang on.
     */
    private function upload(Model $owner, string $contents, string $name, string $disk = 'local', string $collection = 'documents', bool $stamped = true): Media
    {
        $companyId = (int) ($owner instanceof Company ? $owner->id : $owner->getAttribute('company_id'));
        $media = CompanyService::runAs($companyId, function () use ($owner, $contents, $name, $disk, $collection, $stamped, $companyId): Media {
            $adder = $owner->addMediaFromString($contents)->usingFileName($name);

            return ($stamped ? $adder->withCustomProperties(['company_id' => $companyId]) : $adder)->toMediaCollection($collection, $disk);
        });

        // Later calls of the path generator find the owner here, outside any company context.
        return $media->setRelation('model', $owner);
    }

    /** @return array<string, string> archive entry => contents, for the files of an archive */
    private function archivedFiles(TenantExport $export): array
    {
        return array_filter($this->entries($export), fn (string $name): bool => str_starts_with($name, 'files/'), ARRAY_FILTER_USE_KEY);
    }

    /** A media row on a disk the test controls, with no file behind it: the way a remote disk is told apart from a local one. */
    private function mediaRow(Company $owner, string $disk, string $fileName): Media
    {
        $id = DB::table('media')->insertGetId([
            'public_id' => PublicId::new(),
            'model_type' => Company::class,
            'model_id' => $owner->id,
            'uuid' => (string) Str::uuid(),
            'collection_name' => 'documents',
            'name' => pathinfo($fileName, PATHINFO_FILENAME),
            'file_name' => $fileName,
            'mime_type' => 'application/pdf',
            'disk' => $disk,
            'conversions_disk' => $disk,
            'size' => 5,
            'manipulations' => '[]',
            'custom_properties' => json_encode(['company_id' => $owner->id]),
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Media::query()->findOrFail($id)->setRelation('model', $owner);
    }

    // ---------------------------------------------------------------------------------------------
    // Access and the request
    // ---------------------------------------------------------------------------------------------

    public function test_every_export_route_is_closed_to_anyone_but_a_platform_admin_with_a_verified_second_factor(): void
    {
        Queue::fake();
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $export = $this->readyExport($tenant['root']);
        $requests = [
            fn () => $this->post(route('platform.companies.exports.store', $tenant['root']), ['confirmation_code' => 'ALP']),
            fn () => $this->get(route('platform.exports.index')),
            fn () => $this->get($this->link($export)),
        ];

        foreach ($requests as $request) {
            $this->asTenantVisitor();
            $request()->assertRedirect(route('platform.login'));

            $this->actingAs($tenant['admin']);
            $request()->assertRedirect(route('platform.login'));

            $this->signInPlatformAdmin($this->operator, ['platform.two_factor_verified_at' => null] + $this->platformPasswordConfirmed());
            $request()->assertRedirect(route('platform.login'));
        }

        $this->assertSame(1, TenantExport::query()->count(), 'no export was requested');
        Queue::assertNothingPushed();
        $this->assertSame(0, Activity::query()->where('description', 'tenant.export_downloaded')->count());
    }

    public function test_a_request_needs_a_fresh_platform_password_confirmation_and_the_exact_company_code(): void
    {
        Queue::fake();
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $this->buildTenant('BET', 'Beta Link');

        $this->requestExport($root, confirmed: false)->assertRedirect(route('platform.password.confirm'));

        foreach (['alp', 'ALP.', '', 'ALP-C', 'BET', 'ALPX'] as $typed) {
            $this->requestExport($root, ['confirmation_code' => $typed])->assertSessionHasErrors('confirmation_code');
        }

        $this->asOperator()->post(route('platform.companies.exports.store', $root))->assertSessionHasErrors('confirmation_code');

        $this->assertSame(0, TenantExport::query()->count());
        Queue::assertNothingPushed();
        $this->assertSame(0, Activity::query()->where('description', 'tenant.export_requested')->count());
    }

    public function test_a_confirmed_request_queues_one_job_on_the_platform_queue_and_audits_both_sides(): void
    {
        Queue::fake();
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];

        $this->requestExport($root)->assertRedirect(route('platform.companies.show', $root))->assertSessionHas('success');

        $export = TenantExport::query()->sole();
        $this->assertSame([$root->id, $this->operator->id, TenantExport::QUEUED], [$export->company_id, $export->requested_by_platform_admin_id, $export->status]);
        $this->assertTrue(Str::isUuid($export->uuid));
        $this->assertNull($export->file_path);

        Queue::assertPushed(BuildTenantExportJob::class, 1);
        Queue::assertPushed(BuildTenantExportJob::class, function (BuildTenantExportJob $job) use ($export, $root): bool {
            $this->assertSame(['platform', 'platform'], [$job->connection, $job->queue]);
            $this->assertSame([$export->id, $root->id], [$job->exportId, $job->companyId]);

            return true;
        });

        $platform = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.export_requested')->sole();
        $this->assertSame([$this->operator::class, $this->operator->id], [$platform->causer_type, (int) $platform->causer_id]);
        $this->assertSame([TenantExport::class, $export->id], [$platform->subject_type, (int) $platform->subject_id]);
        $this->assertSame($root->id, (int) $platform->company_id);
        $this->assertEquals(['export_id' => $export->id, 'tenant_code' => 'ALP'], $platform->properties->all());

        $support = Activity::query()->where('log_name', 'support')->where('description', 'tenant.export_requested')->sole();
        $this->assertSame($root->id, (int) $support->company_id);
        $this->assertSame(['export_id' => $export->id], $support->properties->all(), 'ids only: no operator identity');
    }

    public function test_only_a_tenant_can_be_exported_a_subsidiary_id_is_a_404(): void
    {
        Queue::fake();
        $tenant = $this->buildTenant('ALP', 'Alpha Net');

        $this->requestExport($tenant['child'], ['confirmation_code' => $tenant['child']->code])->assertNotFound();

        $this->assertSame(0, TenantExport::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_suspended_tenant_can_be_exported_which_is_the_handover_before_its_deletion(): void
    {
        Queue::fake();
        $root = $this->buildTenant('OLD', 'PT Lama')['root'];
        $root->forceFill(['is_active' => false, 'suspended_at' => now()->subDays(40)])->save();

        $this->requestExport($root)->assertSessionHas('success');

        $this->assertSame(1, TenantExport::query()->where('company_id', $root->id)->count());
    }

    public function test_a_tenant_has_one_active_export_at_a_time(): void
    {
        Queue::fake();
        $alpha = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $beta = $this->buildTenant('BET', 'Beta Link')['root'];

        $this->requestExport($alpha)->assertSessionHas('success');
        $this->requestExport($alpha)->assertSessionHasErrors('export');
        $this->assertSame(1, TenantExport::query()->where('company_id', $alpha->id)->count());

        TenantExport::query()->where('company_id', $alpha->id)->update(['status' => TenantExport::RUNNING]);
        $this->requestExport($alpha)->assertSessionHasErrors('export');

        $this->requestExport($beta)->assertSessionHas('success');
        $this->assertSame(1, TenantExport::query()->where('company_id', $beta->id)->count(), 'another tenant is not held up');

        foreach ([TenantExport::READY, TenantExport::FAILED, TenantExport::EXPIRED] as $finished) {
            TenantExport::query()->where('company_id', $alpha->id)->update(['status' => $finished]);
            $this->requestExport($alpha)->assertSessionHas('success');
        }

        $this->assertSame(4, TenantExport::query()->where('company_id', $alpha->id)->count(), 'a finished export, good or bad, does not block the next one');
    }

    public function test_a_build_that_never_finished_stops_blocking_the_tenant_after_two_hours(): void
    {
        Queue::fake();
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $stuck = $this->export($root, 'running');
        $stuck->forceFill(['created_at' => now()->subMinutes(119)])->save();

        $this->requestExport($root)->assertSessionHasErrors('export');

        $stuck->forceFill(['created_at' => now()->subHours(2)->subMinute()])->save();

        $this->requestExport($root)->assertSessionHas('success');
    }

    public function test_the_job_goes_to_the_platform_queue_with_one_try_and_one_hour_and_is_unique_per_tenant(): void
    {
        Queue::fake();
        $job = new BuildTenantExportJob(7, 3);

        $this->assertSame(['platform', 'platform'], [$job->connection, $job->queue]);
        $this->assertSame([1, 3600, true], [$job->tries, $job->timeout, $job->failOnTimeout]);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('3', $job->uniqueId());
        $this->assertLessThan(BuildTenantExportJob::STALE_AFTER_HOURS * 3600, $job->uniqueFor, 'the lock of a dead worker is gone before its record stops counting as active');

        BuildTenantExportJob::dispatch(7, 3);
        BuildTenantExportJob::dispatch(8, 3);
        BuildTenantExportJob::dispatch(9, 4);

        Queue::assertPushed(BuildTenantExportJob::class, 2);
    }

    // ---------------------------------------------------------------------------------------------
    // The archive
    // ---------------------------------------------------------------------------------------------

    public function test_two_tenants_export_to_archives_that_hold_only_their_own_rows_and_files(): void
    {
        [$alpha, $alphaAdmin] = $this->newTenant('ALP');
        [$beta, $betaAdmin] = $this->newTenant('BET');
        $child = $this->subsidiary($alpha, 'ALC');
        $seeder = app(DemoDataSeeder::class);
        $seeder->seedFor($alpha, $alphaAdmin);
        $seeder->seedFor($beta, $betaAdmin);

        foreach ([$alpha, $child, $beta] as $company) {
            $this->targeting($company);
        }

        $audit = app(PlatformAuditService::class);

        foreach ([$alpha, $beta] as $company) {
            $audit->platform('tenant.suspended', ['reason' => 'complaint'], $company, $this->operator);
            $audit->support('impersonation.started', ['reason' => 'complaint'], $company, $this->operator);
        }

        $alphaDoc = $this->upload($alpha, 'alpha-private-bytes', 'ktp.pdf');
        $childDoc = $this->upload($child, 'child-private-bytes', 'foto.jpg');
        $alphaLogo = $this->upload($alpha, 'alpha-logo-bytes', 'logo.webp', 'public', 'logo');
        $this->upload($beta, 'beta-private-bytes', 'ktp.pdf');
        $this->upload($beta, 'beta-logo-bytes', 'logo.webp', 'public', 'logo');

        $alphaIds = [$alpha->id, $child->id];
        $alphaTruth = $this->truthOf($alphaIds);
        $betaTruth = $this->truthOf([$beta->id]);

        $alphaExport = $this->build($alpha);
        $betaExport = $this->build($beta);

        $this->assertHoldsOnly($alphaExport, $alphaIds, $alphaTruth, ['bet-admin@tenant.test', 'Tenant BET', 'beta-private-bytes', 'beta-logo-bytes']);
        $this->assertHoldsOnly($betaExport, [$beta->id], $betaTruth, ['alp-admin@tenant.test', 'Tenant ALP', 'alpha-private-bytes', 'child-private-bytes', 'alpha-logo-bytes']);

        $entries = $this->entries($alphaExport);
        $this->assertSame('alpha-private-bytes', $entries["files/local/{$alphaDoc->getPathRelativeToRoot()}"]);
        $this->assertSame('child-private-bytes', $entries["files/local/{$childDoc->getPathRelativeToRoot()}"]);
        $this->assertSame('alpha-logo-bytes', $entries["files/public/{$alphaLogo->getPathRelativeToRoot()}"]);
        $this->assertSame('id', $this->header($entries['data/companies.csv'])[0], 'the companies file starts with the id');
        $this->assertSame([$alpha->code, $child->code], array_column($this->csv($entries['data/companies.csv']), 'code'));
        $links = fn (array $companyIds): int => DB::table('announcement_location')->whereIn('announcement_id', DB::table('announcements')->whereIn('company_id', $companyIds)->select('id'))->count();
        $this->assertGreaterThan(0, $links($alphaIds));
        $this->assertSame(
            [$links($alphaIds), $links([$beta->id])],
            [count($this->csv($entries['data/announcement_location.csv'])), count($this->csv($this->entries($betaExport)['data/announcement_location.csv']))],
            'a pivot holds the links of its own tenant, the head and its subsidiary together',
        );
    }

    public function test_cross_tenant_pivot_rows_are_not_exported(): void
    {
        $alpha = $this->buildTenant('ALP', 'Alpha Net');
        $beta = $this->buildTenant('BET', 'Beta Link');
        $alphaAnnouncement = DB::table('announcements')->insertGetId([
            'public_id' => PublicId::new(),
            'company_id' => $alpha['root']->id,
            'title' => 'Alpha',
            'body' => 'b',
            'kind' => 'info',
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $betaLocation = DB::table('locations')->insertGetId([
            'public_id' => PublicId::new(),
            'company_id' => $beta['root']->id,
            'code' => 'BET-ONLY',
            'name' => 'Beta only',
            'type' => 'site',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('announcement_location')->insert([
            'announcement_id' => $alphaAnnouncement,
            'location_id' => $betaLocation,
        ]);

        $rows = $this->csv($this->entries($this->build($alpha['root']))['data/announcement_location.csv']);

        $this->assertSame([], array_values(array_filter($rows, fn (array $row): bool => (int) $row['announcement_id'] === $alphaAnnouncement)));
    }

    /**
     * Rows of a tenant table that belong to the companies, by id: what the archive must hold. The platform audit is
     * not the tenant's data; the support rows are.
     *
     * @param  list<int>  $ids
     * @return array<string, list<int>>
     */
    private function truthOf(array $ids): array
    {
        $truth = [];

        foreach (app(TenantTableRegistry::class)->tables() as $table) {
            if ($table === 'tenant_exports') {
                continue;
            }

            $truth[$table] = DB::table($table)
                ->whereIn('company_id', $ids)
                ->when($table === 'activity_log', fn ($rows) => $rows->where(fn ($log) => $log->whereNull('log_name')->orWhere('log_name', '<>', 'platform')))
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return $truth;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, list<int>>  $truth
     * @param  list<string>  $foreign  text that belongs to another tenant and must not appear anywhere
     */
    private function assertHoldsOnly(TenantExport $export, array $ids, array $truth, array $foreign): void
    {
        $entries = $this->entries($export);

        $this->assertSame(TenantExport::READY, $export->status);
        $this->assertSame(strlen((string) file_get_contents(Storage::disk('local')->path((string) $export->file_path))), $export->file_size);
        $this->assertArrayNotHasKey('data/tenant_exports.csv', $entries, 'the export records are not tenant data');

        foreach ($truth as $table => $expected) {
            $this->assertArrayHasKey("data/{$table}.csv", $entries, "{$table} is missing");
            $this->assertSame($expected, array_map('intval', array_column($this->csv($entries["data/{$table}.csv"]), 'id')), "the rows of {$table} are not exactly this tenant's");
        }

        foreach (['customers', 'invoices'] as $table) {
            $rows = DB::table($table)->whereIn('company_id', $ids)->orderBy('id')->get()->map(fn (object $row): array => array_map(fn (mixed $value): string => (string) $value, (array) $row))->all();
            $exported = $this->csv($entries["data/{$table}.csv"]);

            $this->assertNotEmpty($rows, "the fixture has no {$table}");
            $this->assertSame($rows, $exported, "every column of every {$table} row is carried over unchanged");
        }

        $this->assertSame($ids, array_map('intval', array_column($this->csv($entries['data/companies.csv']), 'id')));

        $everything = implode("\n", $entries);

        foreach ($foreign as $text) {
            $this->assertStringNotContainsString($text, $everything, "{$text} belongs to another tenant");
        }

        $files = [];

        foreach (['local', 'public'] as $disk) {
            foreach ($ids as $id) {
                foreach (Storage::disk($disk)->allFiles("companies/{$id}") as $path) {
                    $files["files/{$disk}/{$path}"] = Storage::disk($disk)->get($path);
                }
            }
        }

        $archived = array_filter($entries, fn (string $name): bool => str_starts_with($name, 'files/'), ARRAY_FILTER_USE_KEY);
        ksort($files);
        ksort($archived);

        $this->assertNotEmpty($files);
        $this->assertSame($files, $archived, 'exactly the files of the tenant companies, byte for byte');

        $manifest = json_decode($entries['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(count(array_filter($archived, fn (string $_, string $name): bool => str_starts_with($name, 'files/local/'), ARRAY_FILTER_USE_BOTH)), $manifest['media']['local']);
        $counted = array_filter($export->row_counts, fn (int $count, string $table): bool => ! in_array($table, ['announcement_location', 'announcement_service_package_category', 'location_promo_banner', 'promo_banner_service_package_category', 'media', 'user_roles'], true), ARRAY_FILTER_USE_BOTH);
        $expectedCounts = array_filter(array_map('count', $truth)) + ['companies' => count($ids)];
        $this->assertSame(count($this->csv($entries['data/media.csv'])), $export->row_counts['media'], 'the media rows of the tenant are counted like its tables');
        $this->assertSame(count($this->csv($entries['data/user_roles.csv'])), $export->row_counts['user_roles']);

        // The links of a role stay out: user_roles.csv names the people and the roles, the raw ids would mean nothing to the tenant.
        foreach (['model_has_roles', 'role_has_permissions'] as $link) {
            $this->assertArrayNotHasKey("data/{$link}.csv", $entries, $link);
            $this->assertArrayNotHasKey($link, $export->row_counts, $link);
        }

        ksort($counted);
        ksort($expectedCounts);
        $this->assertSame($expectedCounts, array_filter($counted), 'the record carries the count of every table that has rows');
        $this->assertSame($export->started_at->toDateTimeString(), '2026-10-20 12:00:00');
        $this->assertSame('2026-10-20 12:00:00', $export->completed_at->toDateTimeString());
        $this->assertSame('2026-10-21 12:00:00', $export->expires_at->toDateTimeString());
        $this->assertSame('platform/exports/'.$export->uuid.'.zip', $export->file_path);
        $this->assertSame([], Storage::disk('local')->allFiles('platform/exports/tmp'), 'the working files are gone');
    }

    /** An announcement and a promo banner of the company with their four pivot rows. */
    private function targeting(Company $company): void
    {
        $location = DB::table('locations')->where('company_id', $company->id)->orderBy('id')->value('id');
        $category = DB::table('service_package_categories')->where('company_id', $company->id)->orderBy('id')->value('id');
        $announcement = DB::table('announcements')->insertGetId(['public_id' => PublicId::new(), 'company_id' => $company->id, 'title' => 'Info', 'body' => 'b', 'kind' => 'info', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $banner = DB::table('promo_banners')->insertGetId(['public_id' => PublicId::new(), 'company_id' => $company->id, 'title' => 'Promo', 'alt_text' => 'a', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('announcement_location')->insert(['announcement_id' => $announcement, 'location_id' => $location]);
        DB::table('announcement_service_package_category')->insert(['announcement_id' => $announcement, 'service_package_category_id' => $category]);
        DB::table('location_promo_banner')->insert(['location_id' => $location, 'promo_banner_id' => $banner]);
        DB::table('promo_banner_service_package_category')->insert(['promo_banner_id' => $banner, 'service_package_category_id' => $category]);
    }

    public function test_the_archive_has_a_file_for_every_registry_table_the_pivots_the_companies_and_a_manifest(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $root = $tenant['root'];
        $export = $this->build($root);
        $entries = $this->entries($export);
        $registry = app(TenantTableRegistry::class);

        $expected = ['manifest.json', 'data/companies.csv', 'data/media.csv', 'data/user_roles.csv'];

        foreach (array_diff($registry->tables(), ['tenant_exports']) as $table) {
            $expected[] = "data/{$table}.csv";
        }

        foreach ($registry->pivots() as $pivot) {
            // The links of a role are read through roles.csv and user_roles.csv, never written raw.
            if (! in_array($pivot['table'], ['model_has_roles', 'role_has_permissions'], true)) {
                $expected[] = "data/{$pivot['table']}.csv";
            }
        }

        $this->assertEqualsCanonicalizing($expected, array_keys($entries));
        $this->assertGreaterThan(50, count($registry->tables()));

        $manifest = json_decode($entries['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['export_uuid', 'generated_at', 'timezone', 'tenant', 'companies', 'csv', 'row_counts', 'omitted_columns', 'media'], array_keys($manifest), 'ids, codes, counts and omissions: no configuration, no operator, no secret');
        $this->assertSame($export->uuid, $manifest['export_uuid']);
        $this->assertSame('2026-10-20T12:00:00+00:00', $manifest['generated_at']);
        $this->assertSame('UTC', $manifest['timezone']);
        $this->assertSame(['id' => $root->id, 'code' => 'ALP', 'name' => 'Alpha Net'], $manifest['tenant']);
        $this->assertSame([
            ['id' => $root->id, 'code' => 'ALP', 'name' => 'Alpha Net', 'parent_company_id' => null],
            ['id' => $tenant['child']->id, 'code' => 'ALP-C', 'name' => 'Alpha Net Cabang', 'parent_company_id' => $root->id],
        ], $manifest['companies']);
        $this->assertSame(3, $manifest['row_counts']['users']);
        $this->assertSame(0, $manifest['row_counts']['customers'], 'a table with no rows is still listed');
        $this->assertSame(3, $manifest['row_counts']['company_user'], 'memberships');
        $this->assertSame(0, $manifest['row_counts']['media'], 'no file, no media row');
        $this->assertSame(3, $manifest['row_counts']['user_roles'], 'the admin, the staff user and the manager each hold one role');
        $this->assertSame(['local' => 0, 'public' => 0], $manifest['media']);
    }

    public function test_secrets_never_enter_the_archive_and_the_manifest_names_what_was_left_out(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $root = $tenant['root'];
        $user = $tenant['staff'];
        ['secret' => $secret, 'codes' => $codes] = $this->enrollTwoFactor($user);
        $user->forceFill(['remember_token' => 'remember-sentinel-0123456789'])->save();
        $subscription = $this->subscriptionIn($root);
        $nas = NasDevice::factory()->create(['company_id' => $root->id, 'radius_secret' => 'radius-sentinel-secret']);
        $account = $this->networkAccountFor($subscription, ['password' => 'ppp-sentinel-secret']);
        DB::table('network_accounts')->where('id', $account->id)->update(['provisioned_password_hash' => str_repeat('ab', 32)]);
        $attempt = PaymentAttempt::factory()->forInvoice($this->invoiceFor($subscription, 3))->create();

        $secrets = [
            (string) $user->getRawOriginal('password'),
            $secret,
            ...$codes,
            (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret'),
            (string) DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes'),
            'remember-sentinel-0123456789',
            'radius-sentinel-secret',
            (string) DB::table('nas_devices')->where('id', $nas->id)->value('radius_secret'),
            'ppp-sentinel-secret',
            (string) DB::table('network_accounts')->where('id', $account->id)->value('password'),
            str_repeat('ab', 32),
            $attempt->snap_token,
            $attempt->redirect_url,
        ];
        $export = $this->build($root);
        $entries = $this->entries($export);
        $everything = implode("\n", $entries);

        foreach ($secrets as $secretValue) {
            $this->assertNotSame('', $secretValue);
            $this->assertStringNotContainsString($secretValue, $everything);
        }

        $this->assertSame([$user->email], array_column(array_filter($this->csv($entries['data/users.csv']), fn (array $row): bool => (int) $row['id'] === $user->id), 'email'), 'the rest of the user is there');
        $this->assertNotEmpty($this->csv($entries['data/payment_attempts.csv']));
        $this->assertContains('order_id', $this->header($entries['data/payment_attempts.csv']));

        $omitted = json_decode($entries['manifest.json'], true, flags: JSON_THROW_ON_ERROR)['omitted_columns'];

        $this->assertEqualsCanonicalizing(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_used_step', 'must_change_password'], $omitted['users']);
        $this->assertSame(['radius_secret'], $omitted['nas_devices']);
        $this->assertEqualsCanonicalizing(['password', 'provisioned_password_hash'], $omitted['network_accounts']);
        $this->assertEqualsCanonicalizing(['snap_token', 'redirect_url', 'last_notification'], $omitted['payment_attempts']);
        $this->assertEqualsCanonicalizing(['ip_address', 'user_agent'], $omitted['activity_log'], 'a support row keeps the operator\'s address and agent in the platform database only');
        $this->assertArrayNotHasKey('customers', $omitted, 'a table with nothing to hide is not listed');

        foreach ($omitted as $table => $columns) {
            $this->assertSame([], array_intersect($columns, $this->header($entries["data/{$table}.csv"])), "{$table} still has an omitted column");
        }
    }

    public function test_integration_credentials_are_omitted(): void
    {
        $alpha = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $beta = $this->buildTenant('BET', 'Beta Link')['root'];
        $row = CompanyIntegration::query()->create([
            'company_id' => $alpha->id,
            'provider' => 'whatsapp',
            'external_id' => '109876543210987',
            'is_enabled' => true,
            'credentials' => ['access_token' => 'EAAG-token-sentinel-BBBB2222', 'app_secret' => 'app-secret-sentinel-CCCC3333', 'webhook_verify_token' => 'verify-sentinel-DDDD4444'],
            'settings' => ['note' => 'plain-setting-sentinel'],
        ]);
        $foreign = CompanyIntegration::query()->create([
            'company_id' => $beta->id,
            'provider' => 'midtrans',
            'credentials' => ['server_key' => 'SB-Mid-server-beta-sentinel-0000'],
            'settings' => ['is_production' => false],
        ]);

        $entries = $this->entries($this->build($alpha));
        $everything = implode("\n", $entries);

        foreach (['EAAG-token-sentinel-BBBB2222', 'app-secret-sentinel-CCCC3333', 'verify-sentinel-DDDD4444', 'SB-Mid-server-beta-sentinel-0000'] as $secretValue) {
            $this->assertStringNotContainsString($secretValue, $everything);
        }
        foreach ([$row, $foreign] as $stored) {
            $ciphertext = (string) DB::table('company_integrations')->where('id', $stored->id)->value('credentials');

            $this->assertNotSame('', $ciphertext);
            $this->assertStringNotContainsString($ciphertext, $everything, 'not even the ciphertext leaves');
        }

        $header = $this->header($entries['data/company_integrations.csv']);
        $this->assertNotContains('credentials', $header);
        $this->assertSame([], array_diff(['id', 'company_id', 'provider', 'settings', 'external_id', 'is_enabled', 'verified_at', 'last_error', 'last_checked_at'], $header), 'the rest of the row is there');

        $rows = $this->csv($entries['data/company_integrations.csv']);
        $this->assertSame([$row->id], array_map('intval', array_column($rows, 'id')), 'only the rows of this tenant');
        $this->assertSame('109876543210987', $rows[0]['external_id']);
        $this->assertStringContainsString('plain-setting-sentinel', $rows[0]['settings']);

        $manifest = json_decode($entries['manifest.json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['credentials'], $manifest['omitted_columns']['company_integrations']);
    }

    public function test_a_table_larger_than_one_chunk_is_exported_whole_and_in_id_order(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $root = $tenant['root'];
        $rows = [];

        for ($n = 1; $n <= 2150; $n++) {
            $rows[] = ['public_id' => PublicId::new(), 'log_name' => 'user', 'description' => "bulk {$n}", 'company_id' => $root->id, 'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($rows, 500) as $batch) {
            DB::table('activity_log')->insert($batch);
        }

        $companyIds = [$root->id, $tenant['child']->id];
        $expected = DB::table('activity_log')->whereIn('company_id', $companyIds)->where('log_name', '<>', 'platform')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $export = $this->export($root);
        DB::flushQueryLog();
        DB::enableQueryLog();

        BuildTenantExportJob::dispatchSync($export->id, $root->id);

        $reads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from `activity_log`') && ($query['bindings'][0] ?? null) === $root->id)->count();
        $csv = $this->csv($this->entries($export->refresh())['data/activity_log.csv']);

        $this->assertGreaterThan(2150, count($expected));
        $this->assertSame($expected, array_map('intval', array_column($csv, 'id')), 'no row twice, none missed, ordered by id');
        $this->assertGreaterThanOrEqual(3, $reads, 'read in chunks, not in one query');
        $this->assertSame(count($expected), $export->row_counts['activity_log'] ?? 0);
    }

    public function test_the_private_files_the_public_logo_and_the_files_on_any_other_disk_of_the_tenant_are_archived(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $other = $this->buildTenant('BET', 'Beta Link');
        $root = $tenant['root'];
        $child = $tenant['child'];

        // A disk the tenant's media rows name (a local one here), and a remote one that is only readable as a stream.
        config(['filesystems.disks.archive' => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/archive')], 'filesystems.disks.cloud' => ['driver' => 's3']]);
        Storage::fake('archive');
        $old = $this->upload($root, 'archive-bytes', 'old.pdf', 'archive');
        $this->upload($other['root'], 'foreign-archive-bytes', 'old.pdf', 'archive');

        $private = $this->upload($root, 'private-bytes', 'ktp.pdf');
        $branch = $this->upload($child, 'child-bytes', 'foto.jpg');
        $this->upload($other['root'], 'foreign-bytes', 'ktp.pdf');
        $logo = $this->upload($root, 'logo-bytes', 'logo.webp', 'public', 'logo');
        $this->upload($other['root'], 'foreign-logo-bytes', 'logo.webp', 'public', 'logo');
        Storage::disk('local')->put('platform/exports/previous.zip', 'another archive');

        $scanPath = $this->mediaRow($root, 'cloud', 'scan.pdf')->getPathRelativeToRoot();
        $cloud = Mockery::mock(FilesystemAdapter::class);
        $cloud->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
        $cloud->shouldReceive('exists')->with($scanPath)->andReturn(true);
        $cloud->shouldReceive('readStream')->with($scanPath)->andReturnUsing(function () {
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, 'cloud-bytes');
            rewind($stream);

            return $stream;
        });
        Storage::set('cloud', $cloud);

        $entries = $this->entries($this->build($root));
        $files = array_filter($entries, fn (string $name): bool => str_starts_with($name, 'files/'), ARRAY_FILTER_USE_KEY);

        $this->assertEquals([
            "files/archive/{$old->getPathRelativeToRoot()}" => 'archive-bytes',
            "files/cloud/{$scanPath}" => 'cloud-bytes',
            "files/local/{$branch->getPathRelativeToRoot()}" => 'child-bytes',
            "files/local/{$private->getPathRelativeToRoot()}" => 'private-bytes',
            "files/public/{$logo->getPathRelativeToRoot()}" => 'logo-bytes',
        ], $files);
        $this->assertStringNotContainsString('foreign', implode("\n", $entries));
        $this->assertStringNotContainsString('another archive', implode("\n", $entries), 'an archive never carries the archives');
        $this->assertSame(['archive' => 1, 'cloud' => 1, 'local' => 2, 'public' => 1], json_decode($entries['manifest.json'], true)['media']);
    }

    // ---------------------------------------------------------------------------------------------
    // Files and roles come from the tenant's rows (K21)
    // ---------------------------------------------------------------------------------------------

    public function test_files_come_from_media_rows_not_folders(): void
    {
        $alpha = $this->buildTenant('ALP', 'Alpha Net');
        $beta = $this->buildTenant('BET', 'Beta Link');
        $root = $alpha['root'];
        $child = $alpha['child'];
        $customer = Customer::factory()->create(['company_id' => $child->id]);

        $own = $this->upload($root, 'own-bytes', 'ktp.pdf');
        $logo = $this->upload($root, 'logo-bytes', 'logo.webp', 'public', 'logo');
        $branch = $this->upload($child, 'branch-bytes', 'foto.jpg');
        // The oldest rows carry no company id of their own: the company is the one of the row they hang on.
        $legacy = $this->upload($customer, 'legacy-bytes', 'scan.pdf', stamped: false);
        $this->upload($beta['root'], 'foreign-bytes', 'ktp.pdf');

        $this->assertStringStartsWith("companies/{$child->id}/customers/{$customer->id}/documents/", $legacy->getPathRelativeToRoot());

        $export = $this->build($root);

        $this->assertEquals([
            "files/local/{$own->getPathRelativeToRoot()}" => 'own-bytes',
            "files/public/{$logo->getPathRelativeToRoot()}" => 'logo-bytes',
            "files/local/{$branch->getPathRelativeToRoot()}" => 'branch-bytes',
            "files/local/{$legacy->getPathRelativeToRoot()}" => 'legacy-bytes',
        ], $this->archivedFiles($export));
        $this->assertStringNotContainsString('foreign-bytes', implode("\n", $this->entries($export)));
    }

    public function test_stray_file_in_tenant_folder_is_not_exported(): void
    {
        $tenant = $this->buildTenant('ALP', 'Alpha Net');
        $root = $tenant['root'];
        $kept = $this->upload($root, 'kept-bytes', 'ktp.pdf');

        // What a reused company id, a restored backup or a test run leaves behind: files with no row of this tenant.
        Storage::disk('local')->put("companies/{$root->id}/leftover.txt", 'stray-bytes');
        Storage::disk('local')->put("companies/{$root->id}/customers/9/documents/99/old.pdf", 'orphan-bytes');
        Storage::disk('local')->put("companies/{$tenant['child']->id}/tickets/2/attachments/6/foto.jpg", 'child-stray-bytes');
        Storage::disk('public')->put("companies/{$root->id}/company-logo/98/logo.webp", 'stray-logo-bytes');

        $export = $this->build($root);
        $entries = $this->entries($export);

        $this->assertSame(["files/local/{$kept->getPathRelativeToRoot()}"], array_keys($this->archivedFiles($export)));

        foreach (['stray-bytes', 'orphan-bytes', 'child-stray-bytes', 'stray-logo-bytes'] as $stray) {
            $this->assertStringNotContainsString($stray, implode("\n", $entries));
        }

        $this->assertSame(['local' => 1, 'public' => 0], json_decode($entries['manifest.json'], true)['media']);
    }

    public function test_media_csv_and_user_roles_csv_are_written(): void
    {
        $alpha = $this->buildTenant('ALP', 'Alpha Net');
        $beta = $this->buildTenant('BET', 'Beta Link');
        $first = $this->upload($alpha['root'], 'first-bytes', 'ktp.pdf');
        $second = $this->upload($alpha['child'], 'second-bytes', 'foto.jpg', 'public');
        $this->upload($beta['root'], 'third-bytes', 'ktp.pdf');

        $export = $this->build($alpha['root']);
        $entries = $this->entries($export);

        // media.csv: the tenant's rows, every column, so a file can be tied back to its record.
        $this->assertSame(Schema::getColumnListing('media'), $this->header($entries['data/media.csv']));
        $media = $this->csv($entries['data/media.csv']);
        $this->assertSame([$first->id, $second->id], array_map('intval', array_column($media, 'id')));
        $this->assertSame(['ktp.pdf', 'foto.jpg'], array_column($media, 'file_name'));
        $this->assertSame(['local', 'public'], array_column($media, 'disk'));
        $this->assertSame([Company::class, Company::class], array_column($media, 'model_type'));
        $this->assertSame([(string) $alpha['root']->id, (string) $alpha['child']->id], array_column($media, 'model_id'));
        $this->assertSame([$first->public_id, $second->public_id], array_column($media, 'public_id'));

        // user_roles.csv: who holds which role of the tenant's companies (public id, company code, role name, template key), nobody else's.
        $this->assertSame(['user_public_id', 'company_code', 'role_name', 'template_key'], $this->header($entries['data/user_roles.csv']));
        $expected = [
            ['user_public_id' => $alpha['admin']->public_id, 'company_code' => 'ALP', 'role_name' => $this->companyRoles($alpha['root'])['admin']->name, 'template_key' => 'admin'],
            ['user_public_id' => $alpha['staff']->public_id, 'company_code' => 'ALP', 'role_name' => $this->companyRoles($alpha['root'])['staff']->name, 'template_key' => 'staff'],
            ['user_public_id' => $alpha['branch']->public_id, 'company_code' => 'ALP-C', 'role_name' => $this->companyRoles($alpha['child'])['manager']->name, 'template_key' => 'manager'],
        ];
        usort($expected, fn (array $a, array $b): int => strcmp($a['user_public_id'], $b['user_public_id']));

        $this->assertSame($expected, $this->csv($entries['data/user_roles.csv']));
        $this->assertSame(2, $export->row_counts['media']);
        $this->assertSame(3, $export->row_counts['user_roles']);
        $this->assertSame([], array_intersect(array_column($this->csv($entries['data/user_roles.csv']), 'user_public_id'), [$beta['admin']->public_id, $beta['staff']->public_id, $beta['branch']->public_id]));
        $this->assertSame([], array_diff(array_column($this->csv($entries['data/user_roles.csv']), 'company_code'), ['ALP', 'ALP-C']));

        // roles.csv comes with the tenant tables: only the roles of the tenant's companies.
        $this->assertEqualsCanonicalizing([$alpha['root']->id, $alpha['child']->id], array_unique(array_map('intval', array_column($this->csv($entries['data/roles.csv']), 'company_id'))));
    }

    public function test_generated_conversions_of_a_media_row_are_archived(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $media = $this->upload($root, 'original-bytes', 'ktp.jpg');
        $conversions = PathGeneratorFactory::create($media)->getPathForConversions($media);
        Storage::disk('local')->put($conversions.'ktp-thumb.webp', 'thumb-bytes');
        $media->markAsConversionGenerated('thumb');

        // A row that generated nothing owns nothing in its conversions folder.
        $plain = $this->upload($root, 'plain-bytes', 'plain.jpg');
        Storage::disk('local')->put(PathGeneratorFactory::create($plain)->getPathForConversions($plain).'plain-thumb.webp', 'ghost-bytes');

        $this->assertEquals([
            "files/local/{$media->getPathRelativeToRoot()}" => 'original-bytes',
            "files/local/{$conversions}ktp-thumb.webp" => 'thumb-bytes',
            "files/local/{$plain->getPathRelativeToRoot()}" => 'plain-bytes',
        ], $this->archivedFiles($this->build($root)));
    }

    public function test_a_media_row_whose_file_is_gone_is_left_out_without_stopping_the_export(): void
    {
        // phpunit.xml logs to stderr: capture the warning instead of printing it.
        config(['logging.default' => 'null']);
        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $kept = $this->upload($root, 'kept-bytes', 'ktp.pdf');
        $gone = $this->upload($root, 'gone-bytes', 'lost.pdf');
        Storage::disk('local')->delete($gone->getPathRelativeToRoot());

        $export = $this->build($root);
        $entries = $this->entries($export);

        $this->assertSame(TenantExport::READY, $export->status);
        $this->assertSame(["files/local/{$kept->getPathRelativeToRoot()}"], array_keys($this->archivedFiles($export)));
        $this->assertSame([$kept->id, $gone->id], array_map('intval', array_column($this->csv($entries['data/media.csv']), 'id')), 'the row stays in media.csv');
        $this->assertSame(['local' => 1, 'public' => 0], json_decode($entries['manifest.json'], true)['media']);

        $warnings = array_values(array_filter($logged, fn (MessageLogged $event): bool => $event->message === 'tenant_export.media_file_missing'));
        $this->assertCount(1, $warnings);
        $this->assertSame(['media_id' => $gone->id, 'disk' => 'local'], $warnings[0]->context, 'ids only: a file name can hold a customer\'s name');
    }

    public function test_a_media_row_that_points_into_another_tenants_folder_stops_the_export(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $beta = $this->buildTenant('BET', 'Beta Link')['root'];
        $this->upload($root, 'own-bytes', 'ktp.pdf');

        // The row hangs on the tenant's company but is stamped with another company, so its file lies in that company's folder.
        CompanyService::runAs($root->id, fn () => $root->addMediaFromString('foreign-bytes')
            ->usingFileName('x.pdf')
            ->withCustomProperties(['company_id' => $beta->id])
            ->toMediaCollection('documents', 'local'));

        $export = $this->export($root);

        try {
            app()->call([new BuildTenantExportJob($export->id, $root->id), 'handle']);
            $this->fail('a file outside the tenant folders must stop the export');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outside the folders of the tenant', $e->getMessage());
        }

        $this->assertFalse(Storage::disk('local')->exists("platform/exports/{$export->uuid}.zip"));
    }

    // ---------------------------------------------------------------------------------------------
    // The job's life
    // ---------------------------------------------------------------------------------------------

    public function test_a_build_runs_the_record_from_queued_to_running_to_ready_and_audits_both_sides(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $export = $this->export($root);
        $seen = [];
        TenantExport::saved(function (TenantExport $saved) use (&$seen): void {
            $seen[] = $saved->status;
        });

        BuildTenantExportJob::dispatchSync($export->id, $root->id);

        $export->refresh();
        $this->assertSame([TenantExport::RUNNING, TenantExport::READY], $seen);
        $this->assertSame('2026-10-20 12:00:00', $export->started_at->toDateTimeString());
        $this->assertNull($export->error_message);

        $platform = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.export_completed')->sole();
        $this->assertSame([$this->operator::class, $this->operator->id], [$platform->causer_type, (int) $platform->causer_id]);
        $this->assertSame([TenantExport::class, $export->id], [$platform->subject_type, (int) $platform->subject_id]);
        $this->assertSame($root->id, (int) $platform->company_id);
        $this->assertSame($export->file_size, $platform->properties['file_size']);

        $support = Activity::query()->where('log_name', 'support')->where('description', 'tenant.export_completed')->sole();
        $this->assertSame(['export_id' => $export->id], $support->properties->all());
        $this->assertStringNotContainsString($this->operator->email, json_encode([$platform->properties, $support->properties]));
    }

    public function test_a_build_that_already_ran_is_not_built_twice(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $export = $this->readyExport($root);
        $before = $export->only(['status', 'file_path', 'file_size', 'completed_at']);

        BuildTenantExportJob::dispatchSync($export->id, $root->id);

        $this->assertEquals($before, $export->refresh()->only(['status', 'file_path', 'file_size', 'completed_at']));
        $this->assertSame('zip-bytes', Storage::disk('local')->get((string) $export->file_path));
        $this->assertSame(0, Activity::query()->where('description', 'tenant.export_completed')->count());
    }

    public function test_a_failed_build_removes_every_partial_file_and_records_a_short_message_without_a_trace(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $export = $this->export($root);

        // The storage the tenant's files sit on goes down after the tables were written.
        config(['filesystems.disks.broken' => ['driver' => 's3']]);
        $broken = Mockery::mock(FilesystemAdapter::class);
        $broken->shouldReceive('getAdapter')->andReturn(Mockery::mock(FlysystemAdapter::class));
        $broken->shouldReceive('exists')->andThrow(new RuntimeException('S3 is down: '.str_repeat('x', 400)));
        Storage::set('broken', $broken);
        DB::table('media')->insert([
            'public_id' => PublicId::new(),
            'model_type' => Company::class, 'model_id' => $root->id, 'uuid' => (string) Str::uuid(), 'collection_name' => 'documents', 'name' => 'f', 'file_name' => 'f.pdf',
            'mime_type' => 'application/pdf', 'disk' => 'broken', 'conversions_disk' => 'broken', 'size' => 5, 'manipulations' => '[]',
            'custom_properties' => json_encode(['company_id' => $root->id]), 'generated_conversions' => '[]', 'responsive_images' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $job = new BuildTenantExportJob($export->id, $root->id);

        try {
            app()->call([$job, 'handle']);
            $this->fail('the storage failure must reach the queue');
        } catch (RuntimeException $e) {
            $this->assertSame(TenantExport::RUNNING, $export->refresh()->status);
            $this->assertNotEmpty(Storage::disk('local')->allFiles("platform/exports/tmp/{$export->uuid}"), 'the failure left working files behind for failed() to remove');

            $job->failed($e);
        }

        $export->refresh();
        $this->assertSame(TenantExport::FAILED, $export->status);
        $this->assertNull($export->file_path);
        $this->assertNull($export->file_size);
        $this->assertNull($export->completed_at);
        $this->assertSame([], Storage::disk('local')->allFiles('platform/exports'), 'no archive and no working file is left');
        $this->assertSame([], Storage::disk('local')->allDirectories('platform/exports'));
        $this->assertStringStartsWith('RuntimeException: S3 is down: ', $export->error_message);
        $this->assertLessThanOrEqual(300, mb_strlen($export->error_message));
        $this->assertStringNotContainsString('#0', $export->error_message, 'no stack trace');

        $row = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.export_failed')->sole();
        $this->assertSame([$this->operator::class, $this->operator->id], [$row->causer_type, (int) $row->causer_id]);
        $this->assertSame($root->id, (int) $row->company_id);
        $this->assertSame('RuntimeException', $row->properties['error']);
        $this->assertLessThanOrEqual(300, mb_strlen($row->properties['message']));
        $this->assertStringNotContainsString('#0', json_encode($row->properties, JSON_THROW_ON_ERROR));
        $this->assertSame(0, Activity::query()->where('log_name', 'support')->where('description', 'tenant.export_failed')->count(), 'a failure is the platform\'s business');
    }

    public function test_a_failure_is_still_recorded_when_the_export_row_or_the_operator_is_gone(): void
    {
        (new BuildTenantExportJob(999999, 888888))->failed(new RuntimeException('late'));

        $this->assertSame(0, Activity::query()->where('description', 'tenant.export_failed')->count(), 'there is no record to fail and no tenant to name');
    }

    // ---------------------------------------------------------------------------------------------
    // Download
    // ---------------------------------------------------------------------------------------------

    public function test_a_ready_export_downloads_through_its_signed_link_with_private_headers_and_audits_both_sides(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $export = $this->build($root);

        $response = $this->asOperator(false)->get($this->link($export))->assertOk();

        $this->assertSame(Storage::disk('local')->get((string) $export->file_path), $response->streamedContent());
        $this->assertStringContainsString('application/zip', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('alp', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $platform = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.export_downloaded')->sole();
        $this->assertSame([$this->operator::class, $this->operator->id], [$platform->causer_type, (int) $platform->causer_id]);
        $this->assertSame([TenantExport::class, $export->id], [$platform->subject_type, (int) $platform->subject_id]);
        $this->assertSame($root->id, (int) $platform->company_id);

        $support = Activity::query()->where('log_name', 'support')->where('description', 'tenant.export_downloaded')->sole();
        $this->assertSame(['export_id' => $export->id], $support->properties->all());
        $this->assertStringNotContainsString($this->operator->email, json_encode([$platform->properties, $support->properties]));
    }

    public function test_the_link_lasts_until_the_export_expires_and_a_forged_or_late_link_is_refused(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $export = $this->readyExport($root);
        $other = $this->readyExport($root);
        $url = $this->link($export);

        $this->assertSame(now()->addDay()->getTimestamp(), (int) Str::after(Str::before($url, '&signature='), 'expires='), 'the link is good for 24 hours');

        $this->asOperator(false)->get($url)->assertOk();

        $this->asOperator(false)->get(route('platform.exports.download', $export))->assertForbidden();
        $this->asOperator(false)->get(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url))->assertForbidden();
        $this->asOperator(false)->get(str_replace("/exports/{$export->public_id}/", "/exports/{$other->public_id}/", $url))->assertForbidden();
        $this->asOperator(false)->get(preg_replace('/expires=\d+/', 'expires='.(now()->getTimestamp() + 999999), $url))->assertForbidden();

        $this->travel(24)->hours();
        $this->travel(1)->minutes();
        $this->asOperator(false)->get($url)->assertForbidden();
        $this->assertSame(0, Activity::query()->where('description', 'tenant.export_downloaded')->where('subject_id', $other->id)->count());
    }

    public function test_a_record_that_is_not_ready_or_already_past_its_window_has_nothing_to_download(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $expiredToday = $this->readyExport($root, now()->subHours(25)->toDateTimeString());

        foreach (['queued', 'running', 'failed', 'expired'] as $state) {
            $export = $this->export($root, $state);
            $this->asOperator(false)->get($this->link($export, now()->addHour()))->assertNotFound();
        }

        $this->asOperator(false)->get($this->link($expiredToday, now()->addHour()))->assertNotFound();
        $this->assertSame('zip-bytes', Storage::disk('local')->get((string) $expiredToday->file_path), 'a download does not delete anything');
        $this->assertSame(0, Activity::query()->where('description', 'tenant.export_downloaded')->count());
    }

    public function test_a_ready_record_whose_file_is_gone_is_a_404_and_is_marked_expired(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $export = $this->export($root, 'ready');

        $this->asOperator(false)->get($this->link($export))->assertNotFound();

        $export->refresh();
        $this->assertSame(TenantExport::EXPIRED, $export->status);
        $this->assertNull($export->file_path);
        $row = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.export_expired')->sole();
        $this->assertSame('file_missing', $row->properties['reason']);
        $this->assertSame(0, Activity::query()->where('description', 'tenant.export_downloaded')->count());
    }

    // ---------------------------------------------------------------------------------------------
    // The page
    // ---------------------------------------------------------------------------------------------

    public function test_the_exports_page_lists_every_export_newest_first_with_a_link_only_for_a_downloadable_one(): void
    {
        $alpha = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $beta = $this->buildTenant('BET', 'Beta Link')['root'];
        $ready = $this->readyExport($alpha);
        $this->export($alpha, 'expired', now()->subDays(3)->toDateTimeString());
        $this->export($alpha, 'failed');
        $running = $this->export($beta, 'running');
        $late = $this->readyExport($beta, now()->subHours(25)->toDateTimeString());

        $this->asOperator(false)->get(route('platform.exports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Exports/Index')
                ->has('exports.data', 5)
                ->where('exports.meta.total', 5)
                ->where('filters', ['company_id' => null, 'status' => null, 'page' => 1, 'per_page' => 10])
                ->where('exports.data.0.id', $late->public_id)
                ->where('exports.data.0.download_url', null)
                ->where('exports.data.1.id', $running->public_id)
                ->where('exports.data.1.status', 'running')
                ->where('exports.data.1.download_url', null)
                ->where('exports.data.4', fn ($row): bool => $row['id'] === $ready->public_id
                    && $row['uuid'] === $ready->uuid
                    && $row['tenant'] === ['id' => $alpha->public_id, 'name' => 'Alpha Net', 'code' => 'ALP']
                    && $row['status'] === 'ready'
                    && $row['file_size'] === 2048
                    && $row['row_counts'] === ['users' => 2, 'customers' => 3]
                    && $row['error_message'] === null
                    && $row['requested_by'] === $this->operator->name
                    && $row['created_at'] === '2026-10-20T12:00:00+00:00'
                    && $row['completed_at'] === '2026-10-20T12:00:00+00:00'
                    && $row['expires_at'] === '2026-10-21T12:00:00+00:00'
                    && is_string($row['download_url'])
                    && str_contains($row['download_url'], "/platform/exports/{$ready->public_id}/download?")
                    && str_contains($row['download_url'], 'signature=')));

        $url = $this->asOperator(false)->get(route('platform.exports.index'))->inertiaProps('exports.data.4.download_url');
        $this->asOperator(false)->get($url)->assertOk();
    }

    public function test_the_exports_page_filters_by_tenant_and_status_and_validates_them(): void
    {
        $alpha = $this->buildTenant('ALP', 'Alpha Net');
        $beta = $this->buildTenant('BET', 'Beta Link')['root'];
        $this->export($alpha['root'], 'ready');
        $this->export($alpha['root'], 'failed');
        $this->export($beta, 'failed');

        $page = fn (array $query) => $this->asOperator(false)->get(route('platform.exports.index', $query));

        $page(['company_id' => $alpha['root']->public_id])->assertInertia(fn (Assert $p) => $p->has('exports.data', 2)->where('filters.company_id', $alpha['root']->public_id));
        $page(['status' => 'failed'])->assertInertia(fn (Assert $p) => $p->has('exports.data', 2)->where('filters.status', 'failed'));
        $page(['company_id' => $alpha['root']->public_id, 'status' => 'failed'])->assertInertia(fn (Assert $p) => $p->has('exports.data', 1));
        $page(['per_page' => 25])->assertInertia(fn (Assert $p) => $p->where('exports.meta.per_page', 25)->where('filters.per_page', 25));

        foreach (['status' => 'gone', 'company_id' => $alpha['child']->public_id, 'per_page' => 1000, 'page' => 0] as $key => $value) {
            $page([$key => $value])->assertSessionHasErrors($key);
        }
    }

    public function test_the_exports_page_costs_the_same_queries_whatever_it_lists(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $this->readyExport($root);

        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->asOperator(false)->get(route('platform.exports.index', ['per_page' => 50]))->assertOk();

            return count(DB::getQueryLog());
        };

        $few = $queries();

        foreach (range(1, 12) as $n) {
            $this->readyExport($root);
            $this->export($root, 'failed');
        }

        $this->assertSame($few, $queries(), 'no query per export');
    }

    // ---------------------------------------------------------------------------------------------
    // The cleanup
    // ---------------------------------------------------------------------------------------------

    public function test_the_cleanup_deletes_archives_past_their_24_hours_keeps_the_record_and_leaves_the_rest(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $old = $this->readyExport($root, now()->subHours(25)->toDateTimeString());
        Storage::disk('local')->put("platform/exports/tmp/{$old->uuid}/data/users.csv", 'leftover');
        $fresh = $this->readyExport($root, now()->subHours(23)->toDateTimeString());
        $failed = $this->export($root, 'failed');
        $queued = $this->export($root);
        $expired = $this->export($root, 'expired', now()->subDays(3)->toDateTimeString());
        $keptCounts = $old->row_counts;

        $this->artisan('inbils:platform:exports:prune')->expectsOutputToContain('Expired 1 export archive(s)')->assertExitCode(0);

        $old->refresh();
        $this->assertSame(TenantExport::EXPIRED, $old->status);
        $this->assertNull($old->file_path);
        $this->assertNull($old->file_size);
        $this->assertSame($keptCounts, $old->row_counts, 'the record of what was handed over stays');
        $this->assertNotNull($old->completed_at);
        $this->assertNotNull($old->expires_at);
        $this->assertFalse(Storage::disk('local')->exists("platform/exports/{$old->uuid}.zip"));
        $this->assertFalse(Storage::disk('local')->exists("platform/exports/tmp/{$old->uuid}"));
        $this->assertSame(1, TenantExport::query()->where('company_id', $root->id)->where('status', 'expired')->where('id', $old->id)->count());

        $this->assertSame(TenantExport::READY, $fresh->refresh()->status);
        $this->assertTrue(Storage::disk('local')->exists((string) $fresh->file_path));
        $this->assertSame([TenantExport::FAILED, TenantExport::QUEUED, TenantExport::EXPIRED], [$failed->refresh()->status, $queued->refresh()->status, $expired->refresh()->status]);

        $row = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.export_expired')->sole();
        $this->assertNull($row->causer_id, 'the cleanup is the system');
        $this->assertSame([TenantExport::class, $old->id], [$row->subject_type, (int) $row->subject_id]);
        $this->assertSame($root->id, (int) $row->company_id);
        $this->assertSame('window_elapsed', $row->properties['reason']);

        $this->assertTrue(TenantExport::query()->where('company_id', $root->id)->completedSince(now()->subDays(2))->whereKey($old->id)->exists(), 'an expired export still proves that the data was handed over');

        $this->artisan('inbils:platform:exports:prune')->expectsOutputToContain('Expired 0 export archive(s)')->assertExitCode(0);
        $this->assertSame(1, Activity::query()->where('description', 'tenant.export_expired')->count(), 'a second run does nothing');
    }

    public function test_the_window_is_exactly_24_hours_from_completion(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $export = $this->readyExport($root);

        $this->travelTo('2026-10-21 11:59:59');
        $this->artisan('inbils:platform:exports:prune')->assertExitCode(0);
        $this->assertSame(TenantExport::READY, $export->refresh()->status);

        $this->travelTo('2026-10-21 12:00:00');
        $this->artisan('inbils:platform:exports:prune')->assertExitCode(0);
        $this->assertSame(TenantExport::EXPIRED, $export->refresh()->status);
    }

    public function test_the_cleanup_expires_a_record_whose_archive_is_already_gone(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $export = $this->export($root, 'ready', now()->subHours(30)->toDateTimeString());

        $this->artisan('inbils:platform:exports:prune')->assertExitCode(0);

        $this->assertSame(TenantExport::EXPIRED, $export->refresh()->status);
    }

    public function test_a_build_whose_worker_died_is_failed_by_the_cleanup_after_two_hours_and_its_working_files_go(): void
    {
        Queue::fake();
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $dead = $this->export($root, 'running');
        $dead->forceFill(['created_at' => now()->subHours(3)])->save();
        Storage::disk('local')->put("platform/exports/tmp/{$dead->uuid}/data/users.csv", 'half');
        $waiting = $this->export($this->buildTenant('BET', 'Beta Link')['root']);
        $waiting->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('inbils:platform:exports:prune')->expectsOutputToContain('failed 1 stale build(s)')->assertExitCode(0);

        $dead->refresh();
        $this->assertSame(TenantExport::FAILED, $dead->status);
        $this->assertNotNull($dead->error_message);
        $this->assertStringNotContainsString('#0', $dead->error_message);
        $this->assertFalse(Storage::disk('local')->exists("platform/exports/tmp/{$dead->uuid}"));
        $this->assertSame(TenantExport::QUEUED, $waiting->refresh()->status, 'a build that is merely waiting is left alone');

        $row = Activity::query()->where('log_name', 'platform')->where('description', 'tenant.export_failed')->sole();
        $this->assertNull($row->causer_id);
        $this->assertSame('stale', $row->properties['reason']);

        $this->requestExport($root)->assertSessionHas('success');
    }

    public function test_the_cleanup_runs_hourly_and_never_twice_at_once(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('inbils:platform:exports:prune')->assertExitCode(0);

        $event = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'inbils:platform:exports:prune'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertNotNull($event->mutexName());
        $this->assertNotNull($event->expiresAt, 'withoutOverlapping()');
    }

    public function test_a_download_touches_nothing_of_another_tenants_export(): void
    {
        $root = $this->buildTenant('ALP', 'Alpha Net')['root'];
        $other = $this->buildTenant('BET', 'Beta Link');
        $theirs = $this->readyExport($other['root']);
        $mine = $this->readyExport($root);

        $this->asOperator(false)->get($this->link($mine))->assertOk();

        $this->assertTrue(Storage::disk('local')->exists((string) $theirs->file_path));
        $this->assertSame(0, Activity::query()->where('subject_id', $theirs->id)->where('description', 'tenant.export_downloaded')->count());
        $this->assertInstanceOf(User::class, $other['admin']);
    }
}
