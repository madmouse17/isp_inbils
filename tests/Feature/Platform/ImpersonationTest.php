<?php

namespace Tests\Feature\Platform;

use App\Http\Middleware\EnforceImpersonation;
use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Platform\ImpersonationService;
use App\Support\PlatformSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\BuildsNetworkFixtures;
use Tests\Traits\BuildsSubscriptionBilling;

class ImpersonationTest extends TestCase
{
    use BuildsNetworkFixtures;
    use BuildsSubscriptionBilling;
    use RefreshDatabase;
    use TenantFixtures;

    /** @var array{root: Company, child: Company, admin: User, staff: User, branch: User} */
    private array $alpha;

    /** @var array{root: Company, child: Company, admin: User, staff: User, branch: User} */
    private array $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 21, 9, 0, 0, 'UTC'));
        $this->alpha = $this->buildTenant('ALP', 'Alpha Net');
        $this->beta = $this->buildTenant('BET', 'Beta Link');
    }

    public function test_platform_admin_starts_read_only_impersonation_without_changing_target_login_metadata(): void
    {
        $lastLogin = Carbon::create(2026, 10, 20, 8, 0, 0, 'UTC');
        $this->alpha['admin']->forceFill([
            'last_login_at' => $lastLogin,
            'remember_token' => 'target-remember-token',
        ])->save();

        $this->asOperator()->post("/platform/companies/{$this->alpha['root']->id}/users/{$this->alpha['admin']->id}/impersonate", [
            'reason' => 'Komplain INV-1042',
        ])->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas(ImpersonationService::MODE, ImpersonationService::READ);

        $this->assertAuthenticatedAs($this->alpha['admin'], 'web');
        $this->assertSame($lastLogin->toISOString(), $this->alpha['admin']->fresh()->last_login_at?->toISOString());
        $this->assertSame('target-remember-token', $this->alpha['admin']->fresh()->getRememberToken());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_start_requires_recent_platform_password_confirmation_and_valid_reason(): void
    {
        $url = route('platform.impersonation.start', [$this->alpha['root'], $this->alpha['admin']]);

        $this->asOperator(false)->post($url, ['reason' => 'Komplain INV-1042'])
            ->assertRedirect(route('platform.password.confirm'));

        $this->asOperator()->post($url, ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->assertGuest('web');
    }

    public function test_forbidden_targets_are_refused(): void
    {
        $start = fn (Company $company, int $userId) => $this->asOperator()->post(
            "/platform/companies/{$company->id}/users/{$userId}/impersonate",
            ['reason' => 'Komplain INV-1042'],
        );

        $start($this->alpha['root'], $this->beta['admin']->id)->assertNotFound();

        $this->alpha['admin']->forceFill(['is_active' => false])->save();
        $start($this->alpha['root'], $this->alpha['admin']->id)->assertSessionHasErrors('impersonation');

        $this->alpha['admin']->forceFill(['is_active' => true])->save();
        $this->alpha['root']->forceFill(['is_active' => false])->save();
        $start($this->alpha['root'], $this->alpha['admin']->id)->assertSessionHasErrors('impersonation');

        do {
            $platformOnlyId = PlatformAdmin::factory()->create()->id;
        } while (User::query()->whereKey($platformOnlyId)->exists());
        $this->alpha['root']->forceFill(['is_active' => true])->save();
        $start($this->alpha['root'], $platformOnlyId)->assertNotFound();

        $withoutCompany = User::factory()->create(['company_id' => null, 'is_active' => true]);
        $start($this->alpha['root'], $withoutCompany->id)->assertNotFound();
        $this->assertGuest('web');
    }

    public function test_nested_impersonation_and_existing_web_login_return_conflict(): void
    {
        $url = route('platform.impersonation.start', [$this->alpha['root'], $this->alpha['admin']]);
        $this->asOperator()->post($url, ['reason' => 'Komplain INV-1042'])->assertRedirect(route('admin.dashboard'));

        $this->asOperator()->postJson($url, ['reason' => 'Komplain lain'])
            ->assertConflict()
            ->assertJsonPath('error', 'conflict');

        $this->asTenantVisitor()->post(route('logout'));
        $this->asOperator();
        Auth::guard('web')->login($this->beta['admin']);
        $this->postJson($url, ['reason' => 'Komplain baru'])->assertConflict();
    }

    public function test_read_mode_blocks_post_put_and_delete_with_plain_and_inertia_responses(): void
    {
        $this->startImpersonation();

        foreach ([
            ['POST', route('admin.locations.store')],
            ['PUT', route('admin.company.profile.update')],
        ] as [$method, $url]) {
            $this->asTenantVisitor()->json($method, $url)->assertForbidden()
                ->assertJsonPath('message', EnforceImpersonation::READ_ONLY_MESSAGE);
        }

        $this->asTenantVisitor()->deleteJson(route('profile.destroy'))->assertForbidden()
            ->assertJsonPath('message', EnforceImpersonation::SECURITY_MESSAGE);

        $this->asTenantVisitor()->withHeaders(['X-Inertia' => 'true'])
            ->post(route('admin.locations.store'))
            ->assertStatus(303)
            ->assertSessionHas('error', EnforceImpersonation::READ_ONLY_MESSAGE);
    }

    public function test_read_mode_allows_in_tree_company_switch_and_blocks_an_outside_membership(): void
    {
        app(CompanyMembershipService::class)->grant($this->alpha['admin'], $this->alpha['child']);
        app(CompanyMembershipService::class)->grant($this->alpha['admin'], $this->beta['root']);
        $this->startImpersonation();

        $this->asTenantVisitor()->post(route('admin.company.switch'), ['company_id' => $this->alpha['child']->id])
            ->assertRedirect(route('admin.dashboard'));

        $this->asTenantVisitor()->postJson(route('admin.company.switch'), ['company_id' => $this->beta['root']->id])
            ->assertForbidden()
            ->assertJsonPath('message', EnforceImpersonation::COMPANY_SWITCH_MESSAGE);
    }

    public function test_security_pages_and_self_user_credential_actions_stay_forbidden(): void
    {
        $this->startImpersonation();

        foreach ([
            ['GET', route('profile.edit')],
            ['GET', route('password.confirm')],
            ['POST', route('two-factor.enable')],
        ] as [$method, $url]) {
            $this->asTenantVisitor()->json($method, $url)->assertForbidden()
                ->assertJsonPath('message', EnforceImpersonation::SECURITY_MESSAGE);
        }

        $this->enableWriteMode();

        $this->asTenantVisitor()->putJson(route('admin.users.update', $this->alpha['admin']), [
            'name' => $this->alpha['admin']->name,
            'email' => $this->alpha['admin']->email,
        ])->assertForbidden()->assertJsonPath('message', EnforceImpersonation::SECURITY_MESSAGE);

        $this->asTenantVisitor()->postJson(route('admin.users.store'), [
            'name' => 'New User',
            'email' => 'new@example.test',
            'password' => 'secret-pass',
        ])->assertForbidden()->assertJsonPath('message', EnforceImpersonation::SECURITY_MESSAGE);
    }

    public function test_write_mode_requires_exact_company_code_allows_writes_and_audits_real_actor(): void
    {
        $this->startImpersonation();
        $writeUrl = route('platform.impersonation.write');

        $this->asOperator()->post($writeUrl, ['confirmation_code' => strtolower($this->alpha['root']->code)])
            ->assertSessionHasErrors('confirmation_code');

        $this->enableWriteMode();
        $this->asOperator(false)->post(route('platform.impersonation.read'))->assertStatus(303)
            ->assertSessionHas(ImpersonationService::MODE, ImpersonationService::READ);
        $this->enableWriteMode();
        $this->assertSame(3, Activity::query()->where('log_name', 'support')->where('description', 'impersonation.mode_changed')->count());
        $location = Location::factory()->create(['company_id' => $this->alpha['root']->id]);
        $this->asTenantVisitor()->post(route('admin.locations.store'), [
            'code' => 'REG-SUPPORT',
            'name' => 'Support Region',
            'type' => 'region',
            'is_active' => true,
        ])->assertRedirect();
        $this->asTenantVisitor()->put(route('admin.company.profile.update'), [
            'name' => 'Alpha Net Updated',
        ])->assertRedirect();
        $this->asTenantVisitor()->delete(route('admin.locations.destroy', $location))->assertRedirect();

        $this->assertDatabaseHas('locations', [
            'company_id' => $this->alpha['root']->id,
            'code' => 'REG-SUPPORT',
        ]);

        $writes = Activity::query()->where('description', 'impersonation.write')->where('log_name', 'support')->latest('id')->get();
        $this->assertEqualsCanonicalizing(['POST', 'PUT', 'DELETE'], $writes->pluck('properties.method')->all());
        $write = $writes->firstWhere('properties.method', 'POST');
        $this->assertNotNull($write);
        $this->assertSame(PlatformAdmin::class, $write->causer_type);
        $this->assertSame($this->operator->id, (int) $write->causer_id);
        $this->assertSame('admin.locations.store', $write->properties['route']);
        $this->assertArrayNotHasKey('request', $write->properties->toArray());

        $modelActivity = Activity::query()
            ->where('subject_type', Location::class)
            ->where('description', 'created')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(PlatformAdmin::class, $modelActivity->causer_type);
        $this->assertSame($write->batch_uuid, $modelActivity->batch_uuid);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'platform',
            'description' => 'impersonation.write',
            'batch_uuid' => $write->batch_uuid,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'support',
            'description' => 'impersonation.write',
            'batch_uuid' => $write->batch_uuid,
        ]);
    }

    public function test_policies_still_apply_as_the_impersonated_target(): void
    {
        $this->startImpersonation($this->alpha['staff']);

        $this->asTenantVisitor()->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_expiry_ends_impersonation_after_thirty_minutes(): void
    {
        $this->startImpersonation();
        $this->travel(ImpersonationService::MAX_SECONDS)->seconds();

        $this->asTenantVisitor()->get(route('admin.dashboard'))
            ->assertRedirect(route('platform.companies.show', $this->alpha['root']))
            ->assertSessionMissing(ImpersonationService::ID);
        $this->assertGuest('web');

        $ended = Activity::query()->where('log_name', 'support')->where('description', 'impersonation.ended')->latest('id')->firstOrFail();
        $this->assertSame('expired', $ended->properties['end_reason']);
        $this->assertSame(ImpersonationService::MAX_SECONDS, $ended->properties['duration_seconds']);
    }

    public function test_tenant_logout_platform_logout_and_manual_stop_end_impersonation(): void
    {
        $this->startImpersonation();
        $this->asTenantVisitor()->post(route('logout'))
            ->assertRedirect(route('platform.companies.show', $this->alpha['root']))
            ->assertSessionMissing(ImpersonationService::ID);
        $this->assertGuest('web');

        $this->startImpersonation();
        $this->asOperator(false)->post(route('platform.logout'))->assertRedirect(route('platform.login'));
        $this->assertGuest('platform');
        $this->assertGuest('web');

        $this->startImpersonation();
        $this->asOperator(false)->post(route('platform.impersonation.stop'))
            ->assertRedirect(route('platform.companies.show', $this->alpha['root']));
        $this->asOperator(false)->post(route('platform.impersonation.stop'))
            ->assertRedirect(route('platform.dashboard'));
        $this->assertSame(3, Activity::query()->where('log_name', 'support')->where('description', 'impersonation.ended')->count());
    }

    public function test_tenant_suspension_and_target_deactivation_end_impersonation(): void
    {
        $this->startImpersonation();
        $this->alpha['root']->forceFill(['is_active' => false])->save();
        $this->asTenantVisitor()->get(route('admin.dashboard'))->assertRedirect(route('platform.companies.show', $this->alpha['root']));
        $this->assertSame('tenant_suspended', Activity::query()->where('log_name', 'support')->where('description', 'impersonation.ended')->latest('id')->firstOrFail()->properties['end_reason']);

        $this->alpha['root']->forceFill(['is_active' => true])->save();
        $this->startImpersonation();
        $this->alpha['admin']->forceFill(['is_active' => false])->save();
        $this->asTenantVisitor()->get(route('admin.dashboard'))->assertRedirect(route('platform.companies.show', $this->alpha['root']));
        $this->assertSame('target_inactive', Activity::query()->where('log_name', 'support')->where('description', 'impersonation.ended')->latest('id')->firstOrFail()->properties['end_reason']);
    }

    public function test_banner_prop_is_present_only_during_impersonation(): void
    {
        $this->startImpersonation();

        $this->asTenantVisitor()->get(route('admin.dashboard'))->assertInertia(fn ($page) => $page
            ->has('impersonation', fn ($banner) => $banner
                ->where('admin_name', $this->operator->name)
                ->where('user_name', $this->alpha['admin']->name)
                ->where('company_name', $this->alpha['root']->name)
                ->where('company_code', $this->alpha['root']->code)
                ->where('mode', ImpersonationService::READ)
                ->where('reason', 'Komplain INV-1042')
                ->where('expires_at', now()->addSeconds(ImpersonationService::MAX_SECONDS)->toIso8601String())
                ->where('write_url', route('platform.impersonation.write'))
                ->where('read_url', route('platform.impersonation.read'))
                ->where('stop_url', route('platform.impersonation.stop'))
                ->where('confirm_url', route('platform.password.confirm', ['return_to' => '/admin/dashboard']))));

        $this->asOperator(false)->post(route('platform.impersonation.stop'));
        $this->actingAs($this->alpha['admin'])->get(route('admin.dashboard'))
            ->assertInertia(fn ($page) => $page->where('impersonation', null));
    }

    public function test_the_banner_prop_names_the_page_after_a_partial_reload_that_did_not_ask_for_it(): void
    {
        $this->startImpersonation();
        $page = '/admin/dashboard?tab=aktivitas';

        $this->asTenantVisitor()->get($page)->assertInertia(fn ($inertia) => $inertia
            ->reloadOnly('notifications', fn ($reloaded) => $reloaded
                ->missing('app')
                ->where('impersonation.confirm_url', route('platform.password.confirm', ['return_to' => $page]))));
    }

    public function test_write_mode_step_up_returns_the_operator_to_the_tenant_page_they_came_from(): void
    {
        $this->startImpersonation();
        $page = '/admin/dashboard?tab=aktivitas';

        // The banner is handed the confirm page, which names the page it is on ...
        $confirmUrl = $this->asTenantVisitor()->get($page)->inertiaProps('impersonation.confirm_url');
        $this->assertSame(route('platform.password.confirm', ['return_to' => $page]), $confirmUrl);
        // A test shares one application instance across requests: the tenant shell's shared props (closures over its
        // request and user) must not outlive their request into the platform page that follows.
        Inertia::flushShared();

        // ... posts the code and is told to confirm first (the confirmation that opened the session is stale) ...
        $this->signInPlatformAdmin($this->operator, [PlatformSession::PASSWORD_CONFIRMED => null])
            ->postJson(route('platform.impersonation.write'), ['confirmation_code' => $this->alpha['root']->code])
            ->assertStatus(423);

        // ... opens the confirm page. A browser may send no Referer, so the page the session remembers must do; a
        // mistyped password keeps the way back ...
        $this->get($confirmUrl)->assertOk();
        $this->post(route('platform.password.confirm.store'), ['password' => 'wrong-password-1'])
            ->assertSessionHasErrors('password');

        // ... and the right one answers with a full page load of that page, not an Inertia visit inside the panel shell.
        $this->post(route('platform.password.confirm.store'), ['password' => $this->platformPassword()], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url($page));

        // Write mode is now one request away.
        $this->post(route('platform.impersonation.write'), ['confirmation_code' => $this->alpha['root']->code])
            ->assertStatus(303)
            ->assertSessionHas(ImpersonationService::MODE, ImpersonationService::WRITE);
    }

    public function test_the_step_up_goes_back_only_to_a_tenant_page_the_confirm_page_names_and_only_during_a_support_session(): void
    {
        $confirm = route('platform.password.confirm');
        $panel = route('platform.dashboard');
        $company = route('platform.companies.show', $this->alpha['root']);
        $confirmWith = fn (string $returnTo): string => $confirm.'?return_to='.urlencode($returnTo);
        $answer = fn (string $referer) => $this->asOperator(false)->post(
            route('platform.password.confirm.store'),
            ['password' => $this->platformPassword()],
            ['Referer' => $referer],
        );

        $answer($confirmWith('/admin/dashboard'))->assertRedirect($panel);

        $this->startImpersonation();

        foreach ([
            '/admin/customers?page=2&search=budi',
            '/admin/reports/audit-log?filter[log_name]=support',
            '/admin/dashboard',
            '/admin',
            '/app-only',
        ] as $page) {
            $answer($confirmWith($page))->assertRedirect(url($page));
        }

        foreach ([
            'an absolute URL of this host' => 'http://localhost/admin/customers',
            'another host' => 'https://evil.test/admin/customers',
            'user info naming this host' => 'http://localhost@evil.test/admin',
            'a scheme-relative URL' => '//evil.test/admin',
            'a backslash after the root slash' => '/\\evil.test',
            'a backslash a browser reads as a slash' => '/admin/\\evil.test',
            'a tab' => "/admin/a\tb",
            'a line break' => "/admin/a\r\nLocation: http://evil.test",
            'a space' => '/admin/a b',
            'a fragment' => '/admin#x',
            'a script' => 'javascript:alert(1)',
            'a relative path' => 'admin/customers',
            'a platform page' => '/platform/companies/1',
            'a path outside the tenant app' => '/storage/secret',
            'a lookalike of the prefix' => '/administrator',
            'the host in the prefix' => '/admin@evil.test',
            'a dot segment' => '/admin/../platform/companies/1',
            'an encoded dot segment' => '/admin/%2e%2e/platform',
            'an encoded slash' => '/admin/%2fevil.test',
            'an encoded backslash' => '/admin/%5cevil.test',
            'a path too long to be a page' => '/admin/'.str_repeat('a', 2100),
        ] as $case => $returnTo) {
            $answer($confirmWith($returnTo))->assertRedirect($panel);
        }

        $answer($confirm.'?return_to[]='.urlencode('/admin/dashboard'))->assertRedirect($panel);

        // The page must be the confirm page itself: the same query on any other page does not count.
        $other = $company.'?return_to='.urlencode('/admin/dashboard');
        $answer($other)->assertRedirect($other);
    }

    public function test_a_platform_dialog_step_up_still_returns_to_its_own_page_during_a_support_session(): void
    {
        $this->startImpersonation();
        $company = route('platform.companies.show', $this->alpha['root']);

        $this->asOperator(false)
            ->post(route('platform.password.confirm.store'), ['password' => $this->platformPassword()], ['Referer' => $company, 'X-Inertia' => 'true'])
            ->assertRedirect($company);
    }

    public function test_ending_a_support_session_destroys_the_session_record_it_ran_under(): void
    {
        $handler = app('session.store')->getHandler();

        foreach ([
            'a manual stop' => fn () => $this->asOperator(false)->post(route('platform.impersonation.stop')),
            'a tenant logout' => fn () => $this->asTenantVisitor()->post(route('logout')),
            'the expiry' => function (): void {
                $this->travel(ImpersonationService::MAX_SECONDS)->seconds();
                $this->asTenantVisitor()->get(route('admin.dashboard'));
            },
        ] as $way => $end) {
            $this->startImpersonation();

            // A browser holding this session cookie: once the support session is over, that id must open nothing.
            $old = Str::random(40);
            $this->withCookie(config('session.cookie'), $old);
            $this->asTenantVisitor()->get(route('admin.dashboard'))->assertOk();
            $this->assertNotSame('', $handler->read($old), "{$way}: the page request saved a session under the cookie id");

            $end();

            $this->assertNotSame($old, session()->getId(), "{$way}: new session id");
            $this->assertSame('', $handler->read($old), "{$way}: the old id no longer opens a session");
        }
    }

    public function test_the_network_secret_reveal_stays_forbidden_in_both_modes(): void
    {
        $subscription = $this->subscriptionIn($this->alpha['root']);
        $this->networkAccountFor($subscription);
        $this->startImpersonation();
        $reveal = fn () => $this->asTenantVisitor()->postJson(route('admin.subscriptions.network-account.reveal', $subscription));

        $reveal()->assertForbidden()->assertJsonPath('message', EnforceImpersonation::SECURITY_MESSAGE);

        $this->enableWriteMode();
        $reveal()->assertForbidden()->assertJsonPath('message', EnforceImpersonation::SECURITY_MESSAGE);

        $this->assertSame(0, Activity::query()->where('description', 'secret_revealed')->count());
    }

    public function test_an_impersonated_two_factor_reset_is_audited_as_the_platform_admin(): void
    {
        $this->grantTwoFactorResetToAdmin();
        $this->enrollTwoFactor($this->alpha['staff']);
        $this->startImpersonation();
        $this->enableWriteMode();

        $this->asTenantVisitor()->post(route('admin.users.two-factor.reset', $this->alpha['staff']))->assertRedirect();

        $this->assertFalse($this->alpha['staff']->fresh()->hasTwoFactorEnabled());
        $reset = Activity::query()->where('log_name', 'security')->where('description', 'two_factor.admin_reset')->sole();
        $this->assertSame([$this->operator->id, 'platform_admin'], [$reset->properties['actor_id'], $reset->properties['actor_type']]);
        $this->assertSame([PlatformAdmin::class, $this->operator->id], [$reset->causer_type, (int) $reset->causer_id]);
    }

    private function startImpersonation(?User $user = null): void
    {
        $user ??= $this->alpha['admin'];
        $this->asOperator()->post(route('platform.impersonation.start', [$this->alpha['root'], $user]), [
            'reason' => 'Komplain INV-1042',
        ])->assertRedirect(route('admin.dashboard'));
    }

    private function enableWriteMode(): void
    {
        $this->asOperator()->post(route('platform.impersonation.write'), [
            'confirmation_code' => $this->alpha['root']->code,
        ])->assertStatus(303)->assertSessionHas(ImpersonationService::MODE, ImpersonationService::WRITE);
    }
}
