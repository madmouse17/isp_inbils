<?php

namespace Tests\Feature\Platform;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Models\Core\Company;
use App\Models\Core\CompanyIntegration;
use App\Models\Core\Customer;
use App\Models\Platform\TenantExport;
use App\Models\User;
use App\Notifications\Platform\TenantAdminInvitation;
use App\Services\Core\AuditService;
use App\Services\Platform\PlatformAuditService;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->assertTrue($this->alpha['root']->is((new Company())->resolveRouteBinding($this->alpha['root']->public_id)));
        $this->assertNull((new Company())->resolveRouteBinding($this->alpha['child']->public_id), 'a subsidiary is no tenant');
        $this->assertNull((new Company())->resolveRouteBinding((string) $this->alpha['root']->id), 'an integer id names nothing');
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
                    'id' => $this->alpha['root']->public_id,
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
                'id' => $this->alpha['admin']->public_id,
                'name' => 'Admin ALP',
                'email' => 'admin.alp@example.test',
                'company' => ['id' => $this->alpha['root']->public_id, 'name' => 'Alpha Net', 'code' => 'ALP'],
                'roles' => ['admin'],
                'is_active' => true,
                'last_login_at' => '2026-10-19T08:00:00+00:00',
                'two_factor_enabled' => true,
                'can_impersonate' => true,
                'login_lock' => null,
                'invitation' => null,
                'can_resend_invitation' => false,
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
                'id' => $inactive->public_id,
                'name' => 'Alpha Lama',
                'code' => 'ALL',
                'is_active' => false,
                'currency' => 'USD',
                'timezone' => 'Asia/Jakarta',
                'users_count' => 0,
            ])
            ->where('subsidiaries.1', [
                'id' => $this->alpha['child']->public_id,
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
                'id' => $this->alpha['admin']->public_id,
                'name' => 'Admin ALP',
                'email' => 'admin.alp@example.test',
                'company' => ['id' => $this->alpha['root']->public_id, 'name' => 'Alpha Net', 'code' => 'ALP'],
                'roles' => ['admin'],
                'is_active' => true,
                'last_login_at' => '2026-10-19T08:00:00+00:00',
                'two_factor_enabled' => false,
                'can_impersonate' => true,
                'login_lock' => null,
                'invitation' => null,
                'can_resend_invitation' => false,
            ])
            ->where('users.data.1.name', 'Manager ALP-C')
            ->where('users.data.1.company.code', 'ALP-C')
            ->where('users.data.1.two_factor_enabled', true)
            ->where('users.data.2.name', 'Staff ALP')
            ->where('users.data.2.is_active', false)
            ->where('users.data.2.can_impersonate', false)
            ->where('users.data.2.invitation.state', 'sent')
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

    /** A person of the Alpha tenant who never signed in, found on the page by the name given here. */
    private function invitee(string $name, string $role = 'admin'): User
    {
        $user = $this->tenantUser($this->alpha['root'], $role);
        $user->forceFill(['name' => $name, 'email' => strtolower($name).'@invite.test'])->save();

        return $user;
    }

    /** One field of every person the invitee search lists, by name. @return array<string, mixed> */
    private function inviteeField(string $field): array
    {
        $rows = $this->show($this->alpha['root'], ['search' => '@invite.test', 'per_page' => 50])->inertiaProps('users.data');

        return collect($rows)->pluck($field, 'name')->all();
    }

    /** Runs $write as if it were $time of the frozen day, then puts the clock back to noon. */
    private function at(string $time, callable $write): void
    {
        $this->travelTo("2026-10-20 {$time}");
        $write();
        $this->travelTo('2026-10-20 12:00:00');
    }

    private function failedInvitation(User $user, string $time): void
    {
        $this->at($time, fn () => (new TenantAdminInvitation($user->company_id, $user->id))->failed(new \RuntimeException('Connection refused: smtp.secret-host.test')));
    }

    public function test_invitation_state_queued_sent_expired_failed(): void
    {
        $audit = app(PlatformAuditService::class);
        $queued = $this->invitee('Queued');
        $queued->forceFill(['created_at' => '2026-10-20 09:00:00'])->save();
        $resent = $this->invitee('Resent');
        $resent->forceFill(['created_at' => '2026-10-20 08:00:00'])->save();
        $this->at('11:00:00', fn () => $audit->platform('user.invitation_resent', ['user_id' => $resent->id], $resent, $this->operator));
        $this->resetToken($this->invitee('Fresh'), minutesAgo: 59);
        $this->resetToken($this->invitee('Stale'), minutesAgo: 61);
        $lost = $this->invitee('Lost');
        $this->resetToken($lost, minutesAgo: 10);
        $this->failedInvitation($lost, '11:55:00');
        $retried = $this->invitee('Retried');
        $this->resetToken($retried, minutesAgo: 3);
        $this->failedInvitation($retried, '11:50:00');
        $this->failedInvitation($this->invitee('Nomail'), '11:30:00');
        $this->invitee('Staffer', 'staff');
        $this->resetToken($this->invitee('Stafftoken', 'staff'), minutesAgo: 5);
        $returning = $this->invitee('Returning');
        $returning->forceFill(['last_login_at' => '2026-10-10 08:00:00'])->save();
        $this->resetToken($returning, minutesAgo: 5);
        $this->resetToken($this->tenantUser($this->beta['root'], 'admin'), minutesAgo: 5);

        $this->assertEquals([
            'Queued' => ['queued', '2026-10-20T09:00:00+00:00'],
            'Resent' => ['queued', '2026-10-20T11:00:00+00:00'],
            'Fresh' => ['sent', '2026-10-20T11:01:00+00:00'],
            'Stale' => ['expired', '2026-10-20T10:59:00+00:00'],
            'Lost' => ['failed', '2026-10-20T11:55:00+00:00'],
            'Retried' => ['sent', '2026-10-20T11:57:00+00:00'],
            'Nomail' => ['failed', '2026-10-20T11:30:00+00:00'],
            'Staffer' => null,
            'Stafftoken' => ['sent', '2026-10-20T11:55:00+00:00'],
            'Returning' => null,
        ], array_map(fn (?array $invitation): ?array => $invitation === null ? null : [$invitation['state'], $invitation['at']], $this->inviteeField('invitation')));
    }

    public function test_resend_offered_without_token(): void
    {
        $this->invitee('Fresh');
        $this->invitee('Inactive')->forceFill(['is_active' => false])->save();
        $this->invitee('Signedin')->forceFill(['last_login_at' => now()->subDay()])->save();
        $this->invitee('Staffer', 'staff');

        $this->assertEquals(
            ['Fresh' => true, 'Inactive' => false, 'Signedin' => false, 'Staffer' => false],
            $this->inviteeField('can_resend_invitation'),
            'an active admin who never signed in can be invited again with no token on file; nobody else',
        );
    }

    public function test_login_lock_reports_remaining_seconds(): void
    {
        $locked = $this->invitee('Locked', 'staff');
        $this->invitee('Free', 'staff');
        $this->trailRow($locked, '10.1.1.1', 'login.failed');

        foreach (range(1, LoginRequest::ATTEMPTS_PER_ACCOUNT) as $attempt) {
            RateLimiter::hit(LoginRequest::throttleKeyFor($locked->email, '10.1.1.1'));
        }

        $this->assertEquals(['Locked' => ['reason' => 'password', 'seconds' => 60], 'Free' => null], $this->inviteeField('login_lock'));

        $this->travel(20)->seconds();
        $this->assertSame(40, $this->inviteeField('login_lock')['Locked']['seconds'], 'the seconds count down');

        $this->travel(41)->seconds();
        $this->assertNull($this->inviteeField('login_lock')['Locked'], 'and the lock is gone once they have run out');
    }

    public function test_the_longest_lock_is_the_one_reported(): void
    {
        $user = $this->invitee('Locked', 'staff');
        $this->trailRow($user, '10.1.1.1', 'login.failed');
        $this->trailRow($user, '10.2.2.2', 'two_factor.challenge_failed');

        foreach (range(1, LoginRequest::ATTEMPTS_PER_ACCOUNT) as $attempt) {
            RateLimiter::hit(LoginRequest::throttleKeyFor($user->email, '10.1.1.1'));
        }

        $this->assertSame(['reason' => 'password', 'seconds' => 60], $this->inviteeField('login_lock')['Locked']);

        foreach (range(1, TwoFactorChallengeRequest::ATTEMPTS_PER_ADDRESS) as $attempt) {
            RateLimiter::hit(TwoFactorChallengeRequest::addressKeyFor($user, '10.2.2.2'), 120);
        }

        $this->assertSame(['reason' => 'two_factor', 'seconds' => 120], $this->inviteeField('login_lock')['Locked'], 'a second-factor budget of another address that lasts longer is the one reported');

        foreach (range(1, TwoFactorChallengeRequest::ATTEMPTS_PER_ACCOUNT) as $attempt) {
            RateLimiter::hit(TwoFactorChallengeRequest::accountKeyFor($user), 900);
        }

        $this->assertSame(['reason' => 'two_factor', 'seconds' => 900], $this->inviteeField('login_lock')['Locked'], 'the budget of the account lasts fifteen minutes and outranks them');
    }

    public function test_unlock_is_offered_without_two_factor(): void
    {
        $user = $this->invitee('Locked', 'staff');
        $this->trailRow($user, '10.1.1.1', 'login.failed');

        foreach (range(1, LoginRequest::ATTEMPTS_PER_ACCOUNT) as $attempt) {
            RateLimiter::hit(LoginRequest::throttleKeyFor($user->email, '10.1.1.1'));
        }

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertSame(['reason' => 'password', 'seconds' => 60], $this->inviteeField('login_lock')['Locked'], 'a person with no second factor is locked and shown as locked');

        $this->asOperator(false)->post(route('platform.companies.users.unlock', [$this->alpha['root'], $user]))->assertSessionHas('success');

        $this->assertNull($this->inviteeField('login_lock')['Locked']);
    }

    public function test_the_admin_list_carries_the_same_lock_and_invitation_fields(): void
    {
        $admin = $this->invitee('Lockedadmin');
        $this->trailRow($admin, '10.1.1.1', 'login.failed');

        foreach (range(1, LoginRequest::ATTEMPTS_PER_ACCOUNT) as $attempt) {
            RateLimiter::hit(LoginRequest::throttleKeyFor($admin->email, '10.1.1.1'));
        }

        $this->show($this->alpha['root'])->assertInertia(fn (Assert $page) => $page
            ->where('admins', fn ($rows): bool => collect($rows)->firstWhere('name', 'Lockedadmin')['login_lock'] === ['reason' => 'password', 'seconds' => 60]
                && collect($rows)->firstWhere('name', 'Lockedadmin')['invitation']['state'] === 'queued'
                && collect($rows)->firstWhere('name', 'Lockedadmin')['can_resend_invitation'] === true));
    }

    /** A row of the security trail as production writes it: a failed sign-in or second-factor code of $user from $ip. */
    private function trailRow(User $user, string $ip, string $description): void
    {
        DB::table('activity_log')->insert([
            'public_id' => PublicId::new(),
            'log_name' => 'security',
            'description' => $description,
            'event' => $description,
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'company_id' => $user->company_id,
            'ip_address' => $ip,
            'properties' => json_encode(['failures' => 5]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

        $uuids = DB::table('failed_jobs')->whereIn('id', [$mine, $child])->pluck('uuid', 'id');
        $this->show($this->alpha['root'])->assertInertia(fn (Assert $page) => $page
            ->has('failedJobs', 2)
            ->where('failedJobs.0.uuid', $uuids[$child])
            ->where('failedJobs.1.uuid', $uuids[$mine])
            ->missing('failedJobs.0.id')
            ->where('failedJobs.1.queue', 'default')
            ->where('failedJobs.1.job', 'App\\Jobs\\ExampleJob')
            ->where('failedJobs.1.exception', 'RuntimeException: Mail gagal')
            ->where('failedJobs.1.failed_at', '2026-10-20T09:00:00+00:00')
            ->missing('failedJobs.1.payload')
            ->has('failedWhatsApp', 1)
            ->where('failedWhatsApp.0', [
                'id' => DB::table('whatsapp_messages')->where('id', $failed)->value('public_id'),
                'event' => 'access_link_notice',
                'template' => 't',
                'error_code' => '131026',
                'recipient_last4' => '7890',
                'company' => ['id' => $this->alpha['child']->public_id, 'code' => 'ALP-C'],
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
            ->where('exports.0.id', $failed->public_id)
            ->where('exports.0.status', 'failed')
            ->where('exports.0.error_message', 'ExportFailed: boom')
            ->where('exports.1', [
                'id' => $mine->public_id,
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

    public function test_the_tenant_page_lists_integration_states_without_values(): void
    {
        $row = fn (Company $company, string $provider, array $attributes = []) => CompanyIntegration::withoutCompany()->forceCreate([
            'company_id' => $company->id,
            'provider' => $provider,
            'credentials' => ['server_key' => 'SB-Mid-server-zq9Xk7Lmn4Pw', 'access_token' => 'hunter2-zq9Xk7Lmn4Pw'],
            'settings' => ['host' => 'smtp.secret-host.test'],
            'external_id' => 'phone-id-zq9Xk7',
            ...$attributes,
        ]);
        $row($this->alpha['root'], 'midtrans', ['is_enabled' => true]);
        $row($this->alpha['root'], 'whatsapp', ['is_enabled' => true, 'verified_at' => '2026-10-19 09:00:00', 'last_checked_at' => '2026-10-19 09:00:00']);
        $row($this->alpha['root'], 'mail', ['last_error' => 'Login SMTP ditolak. zq9Xk7', 'last_checked_at' => '2026-10-19 10:30:00']);
        $row($this->alpha['child'], 'radius', ['is_enabled' => true]);
        $row($this->beta['root'], 'midtrans', ['verified_at' => '2026-10-19 09:00:00']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->show($this->alpha['root'])->assertOk();
        $integrationQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'company_integrations'));
        $this->assertCount(1, $integrationQueries, 'one grouped query for the tenant');
        $this->assertStringNotContainsString('credentials', $integrationQueries->first());
        $this->assertStringNotContainsString('settings', $integrationQueries->first());
        $this->assertStringNotContainsString('external_id', $integrationQueries->first());

        $response->assertInertia(function (Assert $page): void {
            $page->has('integrations', 8);
            $states = collect($page->toArray()['props']['integrations'])->mapWithKeys(fn (array $item): array => [$item['company']['code'].':'.$item['provider'] => $item]);

            $this->assertSame(
                ['ALP:midtrans' => 'filled', 'ALP:whatsapp' => 'verified', 'ALP:mail' => 'failed', 'ALP:radius' => 'empty',
                    'ALP-C:midtrans' => 'empty', 'ALP-C:whatsapp' => 'empty', 'ALP-C:mail' => 'empty', 'ALP-C:radius' => 'filled'],
                $states->map(fn (array $item): string => $item['state'])->all(),
            );
            $this->assertSame(
                [
                    'company' => ['id' => $this->alpha['root']->public_id, 'code' => 'ALP', 'name' => 'Alpha Net'],
                    'provider' => 'whatsapp',
                    'state' => 'verified',
                    'is_enabled' => true,
                    'last_checked_at' => '2026-10-19T09:00:00+00:00',
                ],
                $states['ALP:whatsapp'],
            );
            $this->assertSame('2026-10-19T10:30:00+00:00', $states['ALP:mail']['last_checked_at']);
            $this->assertNull($states['ALP:radius']['last_checked_at']);
            $this->assertFalse($states['ALP:radius']['is_enabled']);

            $json = json_encode($page->toArray()['props'], JSON_THROW_ON_ERROR);
            foreach (['credentials', 'tersimpan', 'zq9Xk7', 'Lmn4Pw', 'hunter2', 'smtp.secret-host.test', 'Login SMTP ditolak'] as $leak) {
                $this->assertStringNotContainsString($leak, $json);
            }
        });
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
            ->has('integrations', 4)
            ->where('integrations.0.state', 'empty')
            ->where('metrics.users_count', 0)
            ->where('metrics.last_login_at', null));
    }

    /** One more of every kind of row the page lists, so that a query skipped for an empty list is still exercised. */
    private function addRowsOfEveryKind(int $count): void
    {
        $audit = app(PlatformAuditService::class);

        foreach (range(1, $count) as $i) {
            $user = $this->tenantUser($i % 2 === 0 ? $this->alpha['root'] : $this->alpha['child'], $i % 2 === 0 ? 'admin' : 'staff');
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
