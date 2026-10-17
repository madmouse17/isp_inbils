<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\Platform\TenantExport;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * GET /platform/companies/{company}: one tenant (a top-level company with its subsidiaries) as the operator sees it
 * when a complaint comes in. Every list is cut to that tenant, paginated or capped, and read in a constant number
 * of queries.
 */
class TenantDetailTest extends TestCase
{
    use BuildsTenantMetrics;
    use RefreshDatabase;
    use TenantFixtures;

    /** @var array{root: Company, child: Company, admin: User, staff: User, branch: User} */
    private array $alpha;

    /** @var array{root: Company, child: Company, admin: User, staff: User, branch: User} */
    private array $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-20 12:00:00');
        $this->alpha = $this->buildTenant('ALP', 'Alpha Net');
        $this->beta = $this->buildTenant('BET', 'Beta Link');
        $this->alpha['root']->forceFill(['email' => 'cs@alpha.test', 'phone' => '+62 21 555 0100', 'website' => 'https://alpha.test', 'address' => 'Jl. Merdeka 1, Jakarta'])->save();
        $this->alpha['admin']->forceFill(['last_login_at' => '2026-10-19 08:00:00'])->save();
        $this->alpha['branch']->forceFill(['last_login_at' => '2026-10-18 08:00:00'])->save();
    }

    private function show(Company $root, array $query = []): TestResponse
    {
        return $this->asOperator(false)->get(route('platform.companies.show', [$root, ...$query]));
    }

    public function test_company_binding_by_id_resolves_a_top_level_company_only_and_other_keys_keep_every_company(): void
    {
        $this->assertTrue($this->alpha['root']->is((new Company())->resolveRouteBinding($this->alpha['root']->id)));
        $this->assertNull((new Company())->resolveRouteBinding($this->alpha['child']->id), 'a subsidiary is no tenant');
        $this->assertNull((new Company())->resolveRouteBinding('999999'));
        // isolir/{company:code} is a public page of any company, a subsidiary included.
        $this->assertTrue($this->alpha['child']->is((new Company())->resolveRouteBinding('ALP-C', 'code')));
    }

    public function test_only_a_platform_admin_with_a_verified_second_factor_reaches_the_detail(): void
    {
        $url = route('platform.companies.show', $this->alpha['root']);

        $this->asTenantVisitor()->get($url)->assertRedirect(route('platform.login'));
        $this->actingAs($this->alpha['admin'])->get($url)->assertRedirect(route('platform.login'));
        $this->signInPlatformAdmin($this->operator, ['platform.two_factor_verified_at' => null])->get($url)->assertRedirect(route('platform.login'));

        $this->show($this->alpha['root'])->assertOk();
    }

    public function test_the_profile_and_the_metrics_are_this_tenants_alone(): void
    {
        $this->media($this->alpha['root']->id, 1000);
        $this->media($this->alpha['child']->id, 2000);
        $this->media($this->beta['root']->id, 700);
        Customer::factory()->count(2)->create(['company_id' => $this->alpha['child']->id]);
        Customer::factory()->count(5)->create(['company_id' => $this->beta['root']->id]);
        $this->failedJob($this->alpha['root']->id);
        $this->failedJob($this->alpha['child']->id);
        $this->failedJob($this->alpha['root']->id, now()->subDays(8));
        $this->failedJob($this->beta['root']->id);
        $this->failedJob(null);
        $this->failedWhatsApp($this->alpha['child']->id, 'failed', now()->subDay());
        $this->failedWhatsApp($this->alpha['root']->id, 'sent', now()->subDay());
        $this->failedWhatsApp($this->beta['root']->id, 'failed', now()->subDay());

        $this->show($this->alpha['root'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Companies/Show')
                ->where('tenant', [
                    'id' => $this->alpha['root']->id,
                    'name' => 'Alpha Net',
                    'code' => 'ALP',
                    'status' => 'active',
                    'email' => 'cs@alpha.test',
                    'phone' => '+62 21 555 0100',
                    'website' => 'https://alpha.test',
                    'address' => 'Jl. Merdeka 1, Jakarta',
                    'timezone' => 'Asia/Jakarta',
                    'currency' => 'IDR',
                    'suspended_at' => null,
                    'deletion_requested_at' => null,
                    'created_at' => '2026-10-20T12:00:00+00:00',
                ])
                ->where('metrics', [
                    'subsidiaries_count' => 1,
                    'users_count' => 3,
                    'customers_count' => 2,
                    'last_login_at' => '2026-10-19T08:00:00+00:00',
                    'storage_bytes' => 3000,
                    'failed_jobs_7d' => 2,
                    'failed_whatsapp_7d' => 1,
                ]));
    }

    public function test_the_status_follows_suspension_and_deletion_request(): void
    {
        $this->beta['root']->forceFill(['is_active' => false, 'suspended_at' => '2026-09-01 00:00:00'])->save();
        $this->show($this->beta['root'])->assertInertia(fn (Assert $page) => $page
            ->where('tenant.status', 'suspended')
            ->where('tenant.suspended_at', '2026-09-01T00:00:00+00:00'));

        $this->beta['root']->forceFill(['deletion_requested_at' => '2026-10-19 00:00:00'])->save();
        $this->show($this->beta['root'])->assertInertia(fn (Assert $page) => $page
            ->where('tenant.status', 'deletion_requested')
            ->where('tenant.deletion_requested_at', '2026-10-19T00:00:00+00:00'));
    }

    public function test_admins_are_the_users_with_the_admin_role_in_the_tenant_and_nobody_elses(): void
    {
        $this->tenantUser($this->alpha['child'], 'admin')->forceFill(['name' => 'Admin Cabang', 'is_active' => false])->save();
        $this->alpha['admin']->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->tenantUser($this->beta['root'], 'staff');

        $this->show($this->alpha['root'])->assertInertia(fn (Assert $page) => $page
            ->has('admins', 2)
            ->where('admins.0', [
                'id' => $this->alpha['admin']->id,
                'name' => 'Admin ALP',
                'email' => 'admin.alp@example.test',
                'company' => ['id' => $this->alpha['root']->id, 'name' => 'Alpha Net', 'code' => 'ALP'],
                'roles' => ['admin'],
                'is_active' => true,
                'last_login_at' => '2026-10-19T08:00:00+00:00',
                'two_factor_enabled' => true,
                'can_impersonate' => true,
                'invitation_pending' => false,
            ])
            ->where('admins.1.name', 'Admin Cabang')
            ->where('admins.1.is_active', false)
            ->where('admins.1.can_impersonate', false)
            ->where('admins.1.company.code', 'ALP-C'));
    }

    public function test_subsidiaries_are_listed_with_their_users_and_status(): void
    {
        $inactive = Company::factory()->create(['name' => 'Alpha Lama', 'code' => 'ALL', 'is_active' => false, 'currency' => 'USD']);
        $inactive->forceFill(['parent_company_id' => $this->alpha['root']->id])->save();

        $this->show($this->alpha['root'])->assertInertia(fn (Assert $page) => $page
            ->has('subsidiaries', 2)
            ->where('subsidiaries.0', [
                'id' => $inactive->id,
                'name' => 'Alpha Lama',
                'code' => 'ALL',
                'is_active' => false,
                'currency' => 'USD',
                'timezone' => 'Asia/Jakarta',
                'users_count' => 0,
            ])
            ->where('subsidiaries.1', [
                'id' => $this->alpha['child']->id,
                'name' => 'Alpha Net Cabang',
                'code' => 'ALP-C',
                'is_active' => true,
                'currency' => 'IDR',
                'timezone' => 'Asia/Jakarta',
                'users_count' => 1,
            ]));

        $this->show($this->beta['root'])->assertInertia(fn (Assert $page) => $page->has('subsidiaries', 1)->where('subsidiaries.0.code', 'BET-C'));
    }

    public function test_the_users_are_paginated_filtered_and_never_another_tenants(): void
    {
        $this->alpha['staff']->forceFill(['is_active' => false])->save();
        $this->alpha['branch']->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->resetToken($this->alpha['staff'], minutesAgo: 10);

        $this->show($this->alpha['root'])->assertInertia(fn (Assert $page) => $page
            ->where('userFilters', ['search' => null, 'status' => null, 'per_page' => 10])
            ->has('users.data', 3)
            ->where('users.data.0', [
                'id' => $this->alpha['admin']->id,
                'name' => 'Admin ALP',
                'email' => 'admin.alp@example.test',
                'company' => ['id' => $this->alpha['root']->id, 'name' => 'Alpha Net', 'code' => 'ALP'],
                'roles' => ['admin'],
                'is_active' => true,
                'last_login_at' => '2026-10-19T08:00:00+00:00',
                'two_factor_enabled' => false,
                'can_impersonate' => true,
                'invitation_pending' => false,
            ])
            ->where('users.data.1.name', 'Manager ALP-C')
            ->where('users.data.1.company.code', 'ALP-C')
            ->where('users.data.1.two_factor_enabled', true)
            ->where('users.data.2.name', 'Staff ALP')
            ->where('users.data.2.is_active', false)
            ->where('users.data.2.can_impersonate', false)
            ->where('users.data.2.invitation_pending', true)
            ->where('users.meta.total', 3));

        $this->show($this->alpha['root'], ['search' => 'manager'])->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Manager ALP-C')->where('userFilters.search', 'manager'));
        $this->show($this->alpha['root'], ['search' => 'admin.alp@'])->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Admin ALP'));
        $this->show($this->alpha['root'], ['search' => 'bet'])->assertInertia(fn (Assert $page) => $page->has('users.data', 0));
        $this->show($this->alpha['root'], ['status' => 'inactive'])->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Staff ALP'));
        $this->show($this->alpha['root'], ['status' => 'active'])->assertInertia(fn (Assert $page) => $page->has('users.data', 2));
        $this->show($this->alpha['root'], ['per_page' => 25])->assertInertia(fn (Assert $page) => $page->where('users.meta.per_page', 25)->where('userFilters.per_page', 25));

        foreach (['status' => 'gone', 'per_page' => 1000, 'search' => str_repeat('x', 101)] as $key => $value) {
            $this->show($this->alpha['root'], [$key => $value])->assertSessionHasErrors($key);
        }
    }

    private function resetToken(User $user, int $minutesAgo): void
    {
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => Hash::make('t'.$user->id), 'created_at' => now()->subMinutes($minutesAgo)]);
    }

    public function test_an_invitation_is_pending_for_a_user_who_never_signed_in_and_was_sent_a_set_password_link_even_an_expired_one(): void
    {
        $fresh = $this->tenantUser($this->alpha['root'], 'staff');
        $fresh->forceFill(['email' => 'fresh@example.test', 'name' => 'Fresh'])->save();
        $stale = $this->tenantUser($this->alpha['root'], 'staff');
        $stale->forceFill(['email' => 'stale@example.test', 'name' => 'Stale'])->save();
        $returning = $this->tenantUser($this->alpha['root'], 'staff');
        $returning->forceFill(['email' => 'returning@example.test', 'name' => 'Returning', 'last_login_at' => '2026-10-10 08:00:00'])->save();
        $nobody = $this->tenantUser($this->alpha['root'], 'staff');
        $nobody->forceFill(['email' => 'nobody@example.test', 'name' => 'Nobody'])->save();
        $otherTenant = $this->tenantUser($this->beta['root'], 'staff');
        $this->resetToken($fresh, minutesAgo: 59);
        $this->resetToken($stale, minutesAgo: 61);
        $this->resetToken($returning, minutesAgo: 5);
        $this->resetToken($otherTenant, minutesAgo: 5);

        $this->show($this->alpha['root'], ['search' => '@example.test', 'per_page' => 50])->assertInertia(fn (Assert $page) => $page
            ->where('users.data', fn ($rows): bool => collect($rows)->pluck('invitation_pending', 'name')->only(['Fresh', 'Stale', 'Returning', 'Nobody'])->all()
                == ['Fresh' => true, 'Stale' => true, 'Returning' => false, 'Nobody' => false]));
    }

    public function test_a_user_of_a_suspended_tenant_cannot_be_impersonated(): void
    {
        $this->alpha['root']->forceFill(['is_active' => false, 'suspended_at' => now()])->save();

        $this->show($this->alpha['root'])->assertInertia(fn (Assert $page) => $page
            ->where('users.data.0.is_active', true)
            ->where('users.data.0.can_impersonate', false));
    }

    public function test_the_recent_audit_is_this_tenants_rows_newest_first_including_platform_and_support_entries(): void
    {
        Activity::query()->delete();
        AuditService::log('user', 'created', ['x' => 1], $this->alpha['staff']);
        AuditService::log('company', 'updated', [], $this->beta['root']);
        $audit = app(PlatformAuditService::class);
        $audit->platform('tenant.suspended', ['reason' => 'Tagihan menunggak'], $this->alpha['root'], $this->operator);
        $audit->support('tenant.suspended', ['reason' => 'Tagihan menunggak'], $this->alpha['child'], $this->operator);
        $audit->platform('auth.login', ['platform_admin_id' => $this->operator->id], $this->operator, $this->operator);

        $this->show($this->alpha['root'])->assertInertia(fn (Assert $page) => $page
            ->where('recentAudit', fn ($rows): bool => collect($rows)->pluck('log_name')->all() === ['support', 'platform', 'user'])
            ->where('recentAudit.0.description', 'tenant.suspended')
            ->where('recentAudit.0.reason', 'Tagihan menunggak')
            ->where('recentAudit.0.causer', ['type' => 'platform_admin', 'name' => $this->operator->name])
            ->where('recentAudit.1.log_name', 'platform')
            ->where('recentAudit.2.causer', null)
            ->where('recentAudit.2.reason', null)
            ->where('recentAudit.2.subject_type', 'User')
            ->has('recentAudit.0.created_at')
            ->missing('recentAudit.0.properties'));
    }

    public function test_the_recent_audit_is_capped_at_ten_rows(): void
    {
        foreach (range(1, 12) as $i) {
            AuditService::log('user', 'event.'.$i, [], $this->alpha['staff']);
        }

        $this->show($this->alpha['root'])->assertInertia(fn (Assert $page) => $page
            ->has('recentAudit', 10)
            ->where('recentAudit.0.description', 'event.12'));
    }

    public function test_failed_jobs_and_failed_whatsapp_messages_are_the_last_seven_days_of_this_tenant(): void
    {
        $mine = $this->failedJob($this->alpha['root']->id, now()->subHours(3), "RuntimeException: Mail gagal\n#0 /var/www/vendor/x.php(1)");
        $child = $this->failedJob($this->alpha['child']->id, now()->subHours(2));
        $this->failedJob($this->alpha['root']->id, now()->subDays(8));
        $this->failedJob($this->beta['root']->id, now()->subHour());
        $this->failedJob(null, now()->subHour());
        $failed = $this->failedWhatsApp($this->alpha['child']->id, 'failed', now()->subDay());
        $this->failedWhatsApp($this->alpha['root']->id, 'sent', now()->subDay());
        $this->failedWhatsApp($this->alpha['root']->id, 'failed', now()->subDays(8));
        $this->failedWhatsApp($this->beta['root']->id, 'failed', now()->subDay());

        $this->show($this->alpha['root'])->assertInertia(fn (Assert $page) => $page
            ->has('failedJobs', 2)
            ->where('failedJobs.0.id', $child)
            ->where('failedJobs.1.id', $mine)
            ->where('failedJobs.1.queue', 'default')
            ->where('failedJobs.1.job', 'App\\Jobs\\ExampleJob')
            ->where('failedJobs.1.exception', 'RuntimeException: Mail gagal')
            ->where('failedJobs.1.failed_at', '2026-10-20T09:00:00+00:00')
            ->missing('failedJobs.1.payload')
            ->has('failedWhatsApp', 1)
            ->where('failedWhatsApp.0', [
                'id' => $failed,
                'event' => 'access_link_notice',
                'template' => 't',
                'error_code' => '131026',
                'recipient_last4' => '7890',
                'company' => ['id' => $this->alpha['child']->id, 'code' => 'ALP-C'],
                'failed_at' => '2026-10-19T12:00:00+00:00',
            ]));
    }

    public function test_the_exports_are_this_tenants_latest_first_without_a_download_link(): void
    {
        $mine = TenantExport::factory()->ready('2026-10-19 10:00:00')->create(['company_id' => $this->alpha['root']->id, 'requested_by_platform_admin_id' => $this->operator->id]);
        $failed = TenantExport::factory()->failed()->create(['company_id' => $this->alpha['root']->id, 'requested_by_platform_admin_id' => $this->operator->id]);
        TenantExport::factory()->ready()->create(['company_id' => $this->beta['root']->id, 'requested_by_platform_admin_id' => $this->operator->id]);

        $this->show($this->alpha['root'])->assertInertia(fn (Assert $page) => $page
            ->has('exports', 2)
            ->where('exports.0.id', $failed->id)
            ->where('exports.0.status', 'failed')
            ->where('exports.0.error_message', 'ExportFailed: boom')
            ->where('exports.1', [
                'id' => $mine->id,
                'uuid' => $mine->uuid,
                'status' => 'ready',
                'file_size' => 2048,
                'row_counts' => ['users' => 2, 'customers' => 3],
                'error_message' => null,
                'requested_by' => $this->operator->name,
                'created_at' => $mine->created_at->toIso8601String(),
                'completed_at' => '2026-10-19T10:00:00+00:00',
                'expires_at' => '2026-10-20T10:00:00+00:00',
            ])
            ->missing('exports.1.file_path')
            ->missing('exports.1.download_url'));
    }

    public function test_an_empty_tenant_renders_with_empty_lists(): void
    {
        $lonely = Company::factory()->create(['name' => 'Lonely', 'code' => 'LON']);
        Activity::query()->delete();

        $this->show($lonely)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('admins', [])
            ->where('subsidiaries', [])
            ->where('users.data', [])
            ->where('recentAudit', [])
            ->where('failedJobs', [])
            ->where('failedWhatsApp', [])
            ->where('exports', [])
            ->where('metrics.users_count', 0)
            ->where('metrics.last_login_at', null));
    }

    /** One more of every kind of row the page lists, so that a query skipped for an empty list is still exercised. */
    private function addRowsOfEveryKind(int $count): void
    {
        $audit = app(PlatformAuditService::class);

        foreach (range(1, $count) as $i) {
            $user = $this->tenantUser($i % 2 === 0 ? $this->alpha['root'] : $this->alpha['child'], $i % 3 === 0 ? 'admin' : 'staff');
            $user->forceFill(['email' => "extra{$i}-".fake()->unique()->numerify('###').'@example.test'])->save();
            DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => now()]);
            $this->failedJob($this->alpha['root']->id);
            $this->failedWhatsApp($this->alpha['child']->id, 'failed', now()->subHour());
            AuditService::log('user', 'event.'.$i, [], $this->alpha['staff'], causer: $user);
            $audit->platform('tenant.noted', ['reason' => 'catatan'], $this->alpha['root'], $this->operator);
            TenantExport::factory()->ready()->create(['company_id' => $this->alpha['root']->id, 'requested_by_platform_admin_id' => $this->operator->id]);
        }
    }

    public function test_the_query_count_does_not_grow_with_the_rows_of_the_page(): void
    {
        $this->addRowsOfEveryKind(2);
        $this->show($this->alpha['root'], ['per_page' => 50])->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->show($this->alpha['root'], ['per_page' => 50])->assertOk();
        $few = count(DB::getQueryLog());

        $this->addRowsOfEveryKind(15);
        DB::flushQueryLog();
        $this->show($this->alpha['root'], ['per_page' => 50])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 20)
            ->has('recentAudit', 10)
            ->has('failedJobs', 10)
            ->has('failedWhatsApp', 10)
            ->has('exports', 10));
        $many = count(DB::getQueryLog());

        $this->assertSame($few, $many, 'no query per user, admin, audit row, failed row or export');
    }
}
