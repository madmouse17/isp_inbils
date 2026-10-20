<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnforceCompanyTwoFactor;
use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use App\Support\OptimisticLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Platform\TenantFixtures;
use Tests\TestCase;

/**
 * F1-U06 (K24): the company policy "Wajib 2FA untuk admin". While it is on, an admin (by permission, never by role
 * name) without a confirmed second factor is sent to the Profile page from every admin page, in every module, and
 * can still enrol, log out and reach the account pages. Turning it on needs the actor's own second factor. The
 * nudge for the companies that have not required it yet is the shared `twoFactorPrompt` prop.
 */
class CompanyTwoFactorPolicyTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const SETTING = EnforceCompanyTwoFactor::SETTING;

    /** The middleware of a module's admin route file (routes/web.php of a module): what a page declared there passes through. */
    private const MODULE_ADMIN_GROUP = ['web', 'auth', 'verified', 'redirect.app.only', 'require.has.company'];

    /** @var array{root: Company, child: Company, admin: User, staff: User, branch: User} */
    private array $tenant;

    /** How often the handler of an `adminProbe()` ran: a page the lock stopped leaves it at 0. */
    private int $reached = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 20, 9, 0, 0, 'UTC'));
        $this->tenant = $this->buildTenant('ALP', 'Alpha Net');
    }

    private function setPolicy(Company $company, bool $on = true): void
    {
        $company->forceFill(['settings' => array_merge($company->settings ?? [], [self::SETTING => $on])])->save();
        CompanyService::resetCache();
    }

    /**
     * A route declared the way a module declares its admin pages, with no name unless `$name` is given: the lock then
     * has only the path to go by.
     *
     * @param  list<string>  $methods
     */
    private function adminProbe(string $uri, array $methods = ['GET'], ?string $name = null): void
    {
        $route = Route::middleware(self::MODULE_ADMIN_GROUP)->match($methods, $uri, function (): string {
            $this->reached++;

            return 'reachable';
        });

        if ($name !== null) {
            $route->name($name);
        }
    }

    /** One admin page of the app and of each module that keeps its own route file. @return array<string, array{0: string}> */
    public static function adminPages(): array
    {
        return [
            'dashboard' => ['admin.dashboard'],
            'company settings' => ['admin.company.settings.edit'],
            'customers' => ['admin.customers.index'],
            'invoices (Billing module)' => ['admin.invoices.index'],
            'tickets (Ticketing module)' => ['admin.tickets.index'],
            'SPK (SPK module)' => ['admin.spk.index'],
            'products (Inventory module)' => ['admin.products.index'],
            'audit log (Reporting module)' => ['admin.reports.audit-log'],
        ];
    }

    #[DataProvider('adminPages')]
    public function test_policy_redirects_privileged_user_without_2fa(string $route): void
    {
        $this->setPolicy($this->tenant['root']);

        $this->actingAs($this->tenant['admin'])->get(route($route))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('warning', 'Perusahaan mewajibkan 2FA untuk admin. Aktifkan 2FA untuk melanjutkan.');
    }

    public function test_a_write_is_refused_with_a_303_and_nothing_is_saved(): void
    {
        $this->setPolicy($this->tenant['root']);

        $this->actingAs($this->tenant['admin'])->withHeader('X-Inertia', 'true')
            ->put(route('admin.company.profile.update'), ['name' => 'Diganti'])
            ->assertStatus(303)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('warning', EnforceCompanyTwoFactor::MESSAGE);

        $this->assertSame('Alpha Net', $this->tenant['root']->fresh()->name);
    }

    public function test_a_json_client_gets_a_403_with_the_reason(): void
    {
        $this->setPolicy($this->tenant['root']);

        $this->actingAs($this->tenant['admin'])->getJson(route('admin.customers.index'))
            ->assertForbidden()
            ->assertExactJson(['message' => EnforceCompanyTwoFactor::MESSAGE, 'error' => 'two_factor_required']);
    }

    /** @return array<string, array{0: string}> ways to write the path of the two unnamed probe routes, which the router resolves to them all */
    public static function unnamedAdminPaths(): array
    {
        return [
            'plain' => ['/admin/__probe/unnamed'],
            'with a query' => ['/admin/__probe/unnamed?x=1'],
            'with an encoded slash' => ['/admin%2F__probe%2Funnamed'],
            'with an encoded letter' => ['/%61dmin/__probe/unnamed'],
            'the bare admin path' => ['/admin'],
        ];
    }

    #[DataProvider('unnamedAdminPaths')]
    public function test_an_unnamed_admin_route_cannot_skip_the_lock(string $uri): void
    {
        $this->adminProbe('admin/__probe/unnamed');
        $this->adminProbe('admin');
        $this->setPolicy($this->tenant['root']);

        $this->actingAs($this->tenant['admin'])->get($uri)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('warning', EnforceCompanyTwoFactor::MESSAGE);

        $this->assertSame(0, $this->reached, 'the handler of a locked page never runs');
    }

    public function test_an_unnamed_admin_write_is_refused_before_its_handler_runs(): void
    {
        $this->adminProbe('admin/__probe/unnamed', ['GET', 'POST']);
        $this->setPolicy($this->tenant['root']);

        $this->actingAs($this->tenant['admin'])->post('/admin/__probe/unnamed')
            ->assertStatus(303)
            ->assertRedirect(route('profile.edit'));
        $this->postJson('/admin/__probe/unnamed')
            ->assertForbidden()
            ->assertJsonPath('error', 'two_factor_required');
        $this->assertSame(0, $this->reached);

        // The route is live: a user the policy does not lock reaches it, so only the lock kept the admin out.
        $this->actingAs($this->tenant['staff'])->get('/admin/__probe/unnamed')->assertOk()->assertContent('reachable');
        $this->assertSame(1, $this->reached);
    }

    public function test_a_named_admin_route_is_locked_whatever_its_path(): void
    {
        $this->adminProbe('__probe/named-elsewhere', name: 'admin.probe.elsewhere');
        $this->setPolicy($this->tenant['root']);

        $this->actingAs($this->tenant['admin'])->get('/__probe/named-elsewhere')->assertRedirect(route('profile.edit'));
        $this->assertSame(0, $this->reached);
    }

    public function test_a_path_that_only_starts_with_admin_is_not_an_admin_page(): void
    {
        $this->adminProbe('administrator/__probe');
        $this->adminProbe('admin-guide/__probe');
        $this->setPolicy($this->tenant['root']);

        $this->actingAs($this->tenant['admin'])->get('/administrator/__probe')->assertOk()->assertContent('reachable');
        $this->get('/admin-guide/__probe')->assertOk()->assertContent('reachable');
        $this->assertSame(2, $this->reached);
    }

    public function test_the_policy_is_off_until_a_company_switches_it_on(): void
    {
        $this->actingAs($this->tenant['admin'])->get(route('admin.dashboard'))->assertOk();

        $this->setPolicy($this->tenant['root'], false);
        $this->actingAs($this->tenant['admin'])->get(route('admin.dashboard'))->assertOk();
    }

    public function test_a_hand_written_one_counts_as_on(): void
    {
        DB::table('companies')->where('id', $this->tenant['root']->id)->update(['settings' => json_encode([self::SETTING => 1])]);
        CompanyService::resetCache();

        $this->actingAs($this->tenant['admin'])->get(route('admin.dashboard'))->assertRedirect(route('profile.edit'));
    }

    public function test_profile_and_logout_stay_reachable(): void
    {
        $this->setPolicy($this->tenant['root']);

        $this->actingAs($this->tenant['admin'])->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Profile/Edit')->where('twoFactor.enabled', false));

        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_enrolling_lifts_the_lock(): void
    {
        $this->setPolicy($this->tenant['root']);
        $admin = $this->tenant['admin'];

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
        $this->get(route('admin.dashboard'))->assertRedirect(route('profile.edit'));

        $this->post(route('two-factor.enable'))->assertRedirect(route('profile.edit'));
        $key = $this->get(route('profile.edit'))->inertiaPage()['flash']['two_factor']['setup']['setup_key'];
        $this->post(route('two-factor.confirm'), ['code' => $this->totp($key)])->assertRedirect(route('profile.edit'));

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_non_privileged_user_is_not_redirected(): void
    {
        $this->setPolicy($this->tenant['root']);

        $this->actingAs($this->tenant['staff'])->get(route('admin.dashboard'))->assertOk();

        $viewer = $this->userWith('penilik', ['customer.view']);
        $this->actingAs($viewer)->get(route('admin.customers.index'))->assertOk();
    }

    public function test_an_admin_with_a_second_factor_is_not_redirected(): void
    {
        $this->setPolicy($this->tenant['root']);
        $this->enrollTwoFactor($this->tenant['admin']);

        $this->actingAs($this->tenant['admin']->fresh())->get(route('admin.dashboard'))->assertOk();
    }

    public function test_admin_means_a_permission_not_a_role_name(): void
    {
        $this->setPolicy($this->tenant['root']);

        foreach (['company.manage', 'users.manage', 'roles.manage'] as $permission) {
            $user = $this->userWith('custom-'.$permission, [$permission]);

            $this->actingAs($user)->get(route('admin.customers.index'))->assertRedirect(route('profile.edit'));
        }
    }

    public function test_the_policy_of_the_company_the_user_acts_in_applies(): void
    {
        $admin = $this->tenant['admin'];
        app(CompanyMembershipService::class)->grant($admin, $this->tenant['child']);
        $this->setPolicy($this->tenant['child']);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $this->withSession([CompanyService::SESSION_KEY => $this->tenant['child']->id]);
        $this->get(route('admin.dashboard'))->assertRedirect(route('profile.edit'));
    }

    public function test_support_staff_acting_as_the_admin_are_not_locked(): void
    {
        $this->setPolicy($this->tenant['root']);

        $this->asOperator()->post(route('platform.impersonation.start', [$this->tenant['root'], $this->tenant['admin']]), [
            'reason' => 'Komplain INV-1042',
        ])->assertRedirect(route('admin.dashboard'));

        $this->asTenantVisitor()->get(route('admin.dashboard'))->assertOk();
    }

    public function test_turning_policy_on_needs_own_2fa(): void
    {
        $admin = $this->tenant['admin'];

        $this->saveSettings($admin, [self::SETTING => true])
            ->assertSessionHasErrors([('settings.'.self::SETTING) => 'Aktifkan 2FA di profil Anda dulu sebelum mewajibkannya.']);
        $this->assertFalse($this->stored());
        $this->assertNull($this->tenant['root']->fresh()->settings['billing_defaults_confirmed_at'] ?? null, 'a refused save stamps nothing');

        $this->enrollTwoFactor($admin);

        $this->saveSettings($admin->fresh(), [self::SETTING => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($this->stored());
    }

    public function test_the_stored_policy_is_a_real_boolean_and_the_change_is_audited(): void
    {
        $admin = $this->tenant['admin'];
        $this->enrollTwoFactor($admin);

        $this->saveSettings($admin->fresh(), [self::SETTING => '1'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($this->stored());
        $change = Activity::query()->where('log_name', 'company')->where('subject_id', $this->tenant['root']->id)->latest('id')->firstOrFail();
        $this->assertTrue($change->properties['attributes']['settings'][self::SETTING]);
        $this->assertSame($admin->id, $change->causer_id);
    }

    public function test_an_unchanged_policy_never_blocks_a_save(): void
    {
        $this->setPolicy($this->tenant['root']);

        // The admin has no second factor, so the middleware would stop this request: the rule itself is what is under test.
        $this->withoutMiddleware(EnforceCompanyTwoFactor::class);
        $this->saveSettings($this->tenant['admin'], [self::SETTING => true, 'tax_ppn_rate' => 12])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(12, $this->tenant['root']->fresh()->settings['tax_ppn_rate']);
    }

    public function test_the_policy_must_be_a_boolean(): void
    {
        $this->enrollTwoFactor($this->tenant['admin']);

        $this->saveSettings($this->tenant['admin']->fresh(), [self::SETTING => 'kadang'])->assertSessionHasErrors('settings.'.self::SETTING);
        $this->saveSettings($this->tenant['admin']->fresh(), [self::SETTING => null])->assertSessionHasErrors('settings.'.self::SETTING);
    }

    public function test_prompt_prop_follows_permission_2fa_and_policy(): void
    {
        $prompt = fn (User $user, bool $expected) => $this->actingAs($user)->get(route('admin.company.profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorPrompt', $expected));

        // An admin without a second factor, policy off: nudged.
        $prompt($this->tenant['admin'], true);

        // Not an admin: left alone.
        $this->actingAs($this->tenant['staff'])->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorPrompt', false));

        // Policy on: the middleware locks, so the nudge is off; the Profile page that opens carries none.
        $this->setPolicy($this->tenant['root']);
        $this->actingAs($this->tenant['admin'])->get(route('profile.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorPrompt', false));

        // With a second factor: nothing to ask.
        $this->setPolicy($this->tenant['root'], false);
        $this->enrollTwoFactor($this->tenant['admin']);
        $prompt($this->tenant['admin']->fresh(), false);
    }

    public function test_the_prompt_is_off_for_support_staff_acting_as_the_admin(): void
    {
        $this->asOperator()->post(route('platform.impersonation.start', [$this->tenant['root'], $this->tenant['admin']]), [
            'reason' => 'Komplain INV-1042',
        ])->assertRedirect(route('admin.dashboard'));

        $this->asTenantVisitor()->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorPrompt', false)->where('auth.user.id', $this->tenant['admin']->public_id));
    }

    /** @param array<string, mixed> $settings */
    private function saveSettings(User $user, array $settings): TestResponse
    {
        return $this->actingAs($user)->put(route('admin.company.settings.update'), [
            'settings' => $settings,
            OptimisticLock::FIELD => OptimisticLock::version($this->tenant['root']->fresh()),
        ]);
    }

    private function stored(): bool
    {
        return $this->tenant['root']->fresh()->settings[self::SETTING] ?? false;
    }

    /** @param list<string> $permissions A user of the tenant whose only role is a custom one holding exactly these. */
    private function userWith(string $roleName, array $permissions): User
    {
        $role = Role::findOrCreate($roleName, 'web');
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = $this->tenantUser($this->tenant['root'], 'staff');
        $user->syncRoles([$role]);

        return $user;
    }
}
