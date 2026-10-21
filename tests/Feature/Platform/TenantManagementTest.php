<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\PublicId;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\EnrollsTwoFactor;
use Tests\Traits\InteractsWithPlatform;

/**
 * The platform's tenant list (GET /platform): one row per top-level company with its subsidiaries rolled in, the
 * metrics of two tenants kept apart, bounded queries. The Inertia page is a React file a later ticket adds, so the
 * assertions pass `false` to `component()`.
 */
class TenantManagementTest extends TestCase
{
    use EnrollsTwoFactor;
    use InteractsWithPlatform;
    use RefreshDatabase;

    public function test_only_a_platform_admin_with_a_verified_second_factor_reaches_the_list(): void
    {
        $this->get(route('platform.dashboard'))->assertRedirect(route('platform.login'));

        $this->actingAs(User::factory()->create())->get(route('platform.dashboard'))->assertRedirect(route('platform.login'));

        ['admin' => $admin] = $this->enrolledPlatformAdmin();
        $this->signInPlatformAdmin($admin, ['platform.two_factor_verified_at' => null])->get(route('platform.dashboard'))->assertRedirect(route('platform.login'));

        $this->signInPlatformAdmin($admin)->get(route('platform.dashboard'))->assertOk();
    }

    public function test_each_tenant_row_counts_only_its_own_data_and_rolls_its_subsidiaries_in(): void
    {
        [$alpha, $alphaChild, $beta] = $this->twoTenants();
        $this->signInPlatformAdmin($this->enrolledPlatformAdmin()['admin']);

        $this->get(route('platform.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Dashboard')
                ->where('filters', ['search' => null, 'status' => null, 'sort' => 'name', 'direction' => 'asc', 'per_page' => 10])
                ->where('summary', ['active' => 1, 'suspended' => 1, 'deletion_requested' => 0, 'unattributed_failed_jobs_7d' => 1])
                ->has('tenants.data', 2, fn (Assert $row) => $row
                    ->where('id', $alpha->public_id)
                    ->where('name', 'Alpha Net')
                    ->where('code', 'ALP')
                    ->where('status', 'active')
                    ->where('subsidiaries_count', 1)
                    ->where('users_count', 3)
                    ->where('customers_count', 2)
                    ->where('last_login_at', '2026-10-18T09:30:00+00:00')
                    ->where('storage_bytes', 3000)
                    ->where('failed_jobs_7d', 2)
                    ->where('failed_whatsapp_7d', 1))
                ->has('tenants.data.1', fn (Assert $row) => $row
                    ->where('id', $beta->public_id)
                    ->where('name', 'Beta Link')
                    ->where('code', 'BET')
                    ->where('status', 'suspended')
                    ->where('subsidiaries_count', 0)
                    ->where('users_count', 1)
                    ->where('customers_count', 0)
                    ->where('last_login_at', null)
                    ->where('storage_bytes', 700)
                    ->where('failed_jobs_7d', 1)
                    ->where('failed_whatsapp_7d', 0)));

        $this->assertNotNull($alphaChild);
    }

    public function test_subsidiaries_are_not_rows_of_their_own_and_a_deletion_request_is_its_own_status(): void
    {
        [$alpha] = $this->twoTenants();
        Company::query()->whereKey($alpha->id)->update(['deletion_requested_at' => now()]);
        $this->signInPlatformAdmin($this->enrolledPlatformAdmin()['admin']);

        $this->get(route('platform.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->has('tenants.data', 2)
            ->where('tenants.data.0.status', 'deletion_requested')
            ->where('summary.deletion_requested', 1)
            ->where('summary.active', 0));
    }

    public function test_search_status_sort_and_page_size_are_applied_on_the_server(): void
    {
        $this->twoTenants();
        $this->signInPlatformAdmin($this->enrolledPlatformAdmin()['admin']);

        $this->get(route('platform.dashboard', ['search' => 'bet']))->assertInertia(fn (Assert $page) => $page->has('tenants.data', 1)->where('tenants.data.0.code', 'BET'));
        $this->get(route('platform.dashboard', ['search' => 'ALP']))->assertInertia(fn (Assert $page) => $page->has('tenants.data', 1)->where('tenants.data.0.code', 'ALP'));
        $this->get(route('platform.dashboard', ['status' => 'suspended']))->assertInertia(fn (Assert $page) => $page->has('tenants.data', 1)->where('tenants.data.0.code', 'BET'));
        $this->get(route('platform.dashboard', ['sort' => 'code', 'direction' => 'desc']))->assertInertia(fn (Assert $page) => $page->where('tenants.data.0.code', 'BET'));
        $this->get(route('platform.dashboard', ['per_page' => 25]))->assertInertia(fn (Assert $page) => $page->where('tenants.meta.per_page', 25)->where('filters.per_page', 25));

        foreach (['status' => 'gone', 'sort' => 'users_count', 'direction' => 'sideways', 'per_page' => 1000] as $key => $value) {
            $this->get(route('platform.dashboard', [$key => $value]))->assertSessionHasErrors($key);
        }
    }

    public function test_the_list_is_paginated_and_the_query_count_does_not_grow_with_the_tenants(): void
    {
        $this->twoTenants();
        $this->signInPlatformAdmin($this->enrolledPlatformAdmin()['admin']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('platform.dashboard'))->assertOk();
        $few = count(DB::getQueryLog());

        Company::factory()->count(12)->create();
        $this->signInPlatformAdmin(PlatformAdmin::query()->firstOrFail());
        DB::flushQueryLog();
        $this->get(route('platform.dashboard', ['per_page' => 50]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('tenants.data', 14));
        $many = count(DB::getQueryLog());

        $this->assertSame($few, $many, 'one grouped query per metric, however many tenants the page lists');
        $this->get(route('platform.dashboard', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('tenants.data', 4)->where('tenants.meta.last_page', 2));
    }

    public function test_a_tenant_admin_cannot_change_the_company_code(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $company = Company::factory()->create(['code' => 'KEEP']);
        $admin = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->grantRole($admin, 'admin');
        CompanyService::resetCache();

        $this->actingAs($admin)->put(route('admin.company.profile.update'), ['name' => 'Renamed', 'code' => 'HACK'])->assertRedirect();

        $this->assertSame('KEEP', $company->fresh()->code, 'only platform provisioning sets the code');
        $this->assertSame('Renamed', $company->fresh()->name);
    }

    /**
     * Alpha (with one subsidiary, active) and Beta (suspended), each with its own users, customers, media, failed
     * jobs and failed WhatsApp messages, so a metric that leaked across tenants would show.
     *
     * @return array{0: Company, 1: Company, 2: Company}
     */
    private function twoTenants(): array
    {
        $alpha = Company::factory()->create(['name' => 'Alpha Net', 'code' => 'ALP']);
        $alphaChild = Company::factory()->create(['name' => 'Alpha Cabang', 'code' => 'ALC']);
        $alphaChild->forceFill(['parent_company_id' => $alpha->id])->save();
        $beta = Company::factory()->create(['name' => 'Beta Link', 'code' => 'BET', 'is_active' => false]);

        User::factory()->create(['company_id' => $alpha->id, 'last_login_at' => '2026-10-17 08:00:00']);
        User::factory()->create(['company_id' => $alpha->id, 'last_login_at' => '2026-10-18 09:30:00']);
        User::factory()->create(['company_id' => $alphaChild->id, 'last_login_at' => '2026-10-01 08:00:00']);
        User::factory()->create(['company_id' => $beta->id]);
        User::factory()->create(['company_id' => null]);

        Customer::factory()->count(2)->create(['company_id' => $alpha->id]);
        Customer::factory()->create(['company_id' => $alpha->id])->delete();

        $this->media($alpha->id, 1000);
        $this->media($alphaChild->id, 2000);
        $this->media($beta->id, 700);
        $this->media(null, 9999);

        $this->failedJob($alpha->id);
        $this->failedJob($alphaChild->id);
        $this->failedJob($beta->id);
        $this->failedJob(null);
        $this->failedJob($alpha->id, failedAt: now()->subDays(8));

        $this->failedWhatsApp($alphaChild->id, 'failed', now()->subDay());
        $this->failedWhatsApp($alpha->id, 'sent', now()->subDay());
        $this->failedWhatsApp($beta->id, 'failed', now()->subDays(9));

        return [$alpha, $alphaChild, $beta];
    }

    private function media(?int $companyId, int $size): void
    {
        DB::table('media')->insert([
            'public_id' => PublicId::new(),
            'model_type' => Company::class,
            'model_id' => $companyId ?? 0,
            'uuid' => (string) fake()->uuid(),
            'collection_name' => 'logo',
            'name' => 'file',
            'file_name' => 'file.webp',
            'mime_type' => 'image/webp',
            'disk' => 'local',
            'conversions_disk' => 'local',
            'size' => $size,
            'manipulations' => '[]',
            'custom_properties' => json_encode($companyId === null ? [] : ['company_id' => $companyId]),
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function failedJob(?int $companyId, mixed $failedAt = null): void
    {
        $command = $companyId === null ? 'O:8:"stdClass":0:{}' : 'O:8:"stdClass":1:{s:9:"companyId";i:'.$companyId.';}';

        DB::table('failed_jobs')->insert([
            'uuid' => (string) fake()->uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['data' => ['command' => $command]]),
            'exception' => 'boom',
            'failed_at' => $failedAt ?? now(),
        ]);
    }

    private function failedWhatsApp(int $companyId, string $status, mixed $failedAt): void
    {
        DB::table('whatsapp_messages')->insert([
            'public_id' => PublicId::new(),
            'company_id' => $companyId,
            'recipient_type' => 'user',
            'recipient_id' => 1,
            'event' => 'access_link_notice',
            'template' => 't',
            'language' => 'id',
            'dedupe_key' => fake()->unique()->uuid(),
            'status' => $status,
            'failed_at' => $failedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
