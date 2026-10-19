<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\User;
use App\Notifications\Platform\TenantAdminInvitation;
use App\Services\Core\CompanyMembershipService;
use App\Services\Platform\SupportToolsService;
use App\Support\PublicId;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * What a platform operator does for one tenant user when support asks: reset a lost second factor, resend the
 * invitation, lift the shared login-challenge lock; and the full failed-job and failed-notification lists of one
 * tenant. Every action reaches only users homed in that tenant, and leaves a platform row and a support row.
 */
class SupportActionsTest extends TestCase
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
    }

    private function resetTwoFactor(Company $root, User $user, bool $confirmed = true): TestResponse
    {
        return $this->asOperator($confirmed)->post(route('platform.companies.users.two-factor.reset', [$root, $user]));
    }

    private function resend(Company $root, User $user): TestResponse
    {
        return $this->asOperator(false)->post(route('platform.companies.users.invitation.store', [$root, $user]));
    }

    private function unlock(Company $root, User $user): TestResponse
    {
        return $this->asOperator(false)->post(route('platform.companies.users.unlock', [$root, $user]));
    }

    private function sessionRow(User $user): string
    {
        DB::table('sessions')->insert([
            'id' => $id = Str::random(40),
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        return $id;
    }

    /** A second admin of Alpha, who was invited and never signed in. */
    private function invitee(): User
    {
        return $this->tenantUser($this->alpha['root'], 'admin');
    }

    // ---------------------------------------------------------------------------------------------
    // Access and reach
    // ---------------------------------------------------------------------------------------------

    public function test_every_support_route_is_closed_to_anyone_but_a_platform_admin_with_a_verified_second_factor(): void
    {
        $root = $this->alpha['root'];
        $user = $this->alpha['staff'];
        $this->enrollTwoFactor($user);
        $requests = [
            fn () => $this->post(route('platform.companies.users.two-factor.reset', [$root, $user])),
            fn () => $this->post(route('platform.companies.users.invitation.store', [$root, $user])),
            fn () => $this->post(route('platform.companies.users.unlock', [$root, $user])),
            fn () => $this->get(route('platform.companies.failed-jobs', $root)),
            fn () => $this->get(route('platform.companies.failed-notifications', $root)),
        ];

        foreach ($requests as $request) {
            $this->asTenantVisitor();
            $request()->assertRedirect(route('platform.login'));

            $this->actingAs($this->alpha['admin']);
            $request()->assertRedirect(route('platform.login'));

            $this->signInPlatformAdmin($this->operator, ['platform.two_factor_verified_at' => null] + $this->platformPasswordConfirmed());
            $request()->assertRedirect(route('platform.login'));
        }

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertSame(0, Activity::query()->whereIn('log_name', ['platform', 'support'])->where('description', 'like', 'user.%')->count());
    }

    public function test_only_users_homed_in_the_tenant_are_reachable_a_stranger_a_member_from_elsewhere_and_a_subsidiary_url_are_404(): void
    {
        Notification::fake();
        $root = $this->alpha['root'];
        $stranger = $this->beta['admin'];
        $this->enrollTwoFactor($stranger);
        $lock = 'two-factor-user|'.$stranger->id;
        RateLimiter::hit($lock, 900);
        // Their login is Beta's; an extra membership in Alpha does not make the person Alpha's to reset.
        app(CompanyMembershipService::class)->grant($stranger, $root);
        $inviteeOfBeta = $this->tenantUser($this->beta['root'], 'admin');

        foreach ([$stranger, $inviteeOfBeta] as $user) {
            $this->resetTwoFactor($root, $user)->assertNotFound();
            $this->resend($root, $user)->assertNotFound();
            $this->unlock($root, $user)->assertNotFound();
        }

        $this->resetTwoFactor($this->alpha['child'], $this->alpha['branch'])->assertNotFound();
        $this->resend($this->alpha['child'], $this->alpha['branch'])->assertNotFound();
        $this->unlock($this->alpha['child'], $this->alpha['branch'])->assertNotFound();
        $this->asOperator()->post('/platform/companies/'.$root->public_id.'/users/'.PublicId::new().'/unlock')->assertNotFound();

        $this->assertTrue($stranger->fresh()->hasTwoFactorEnabled(), 'another tenant\'s second factor is untouched');
        $this->assertSame(1, RateLimiter::attempts($lock), 'and so is its lock');
        Notification::assertNothingSent();
        $this->assertSame(0, Activity::query()->whereIn('log_name', ['platform', 'support'])->where('description', 'like', 'user.%')->count());
    }

    public function test_a_user_homed_in_a_subsidiary_belongs_to_the_tenant(): void
    {
        $branch = $this->alpha['branch'];
        $this->enrollTwoFactor($branch);

        $this->resetTwoFactor($this->alpha['root'], $branch)->assertRedirect(route('platform.companies.show', $this->alpha['root']));

        $this->assertFalse($branch->fresh()->hasTwoFactorEnabled());
        $row = Activity::query()->where('log_name', 'platform')->where('description', 'user.two_factor_reset')->sole();
        $this->assertSame($this->alpha['child']->id, (int) $row->company_id, 'the row is about the company the user is homed in');
    }

    // ---------------------------------------------------------------------------------------------
    // Reset the second factor
    // ---------------------------------------------------------------------------------------------

    public function test_a_reset_needs_a_fresh_platform_password_confirmation(): void
    {
        $user = $this->alpha['staff'];
        $this->enrollTwoFactor($user);

        $this->resetTwoFactor($this->alpha['root'], $user, confirmed: false)->assertRedirect(route('platform.password.confirm'));

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertSame(0, Activity::query()->where('description', 'user.two_factor_reset')->count());
    }

    public function test_a_confirmed_reset_clears_the_second_factor_ends_the_other_logins_and_audits_both_sides(): void
    {
        $user = $this->alpha['staff'];
        $bystander = $this->alpha['admin'];
        $this->enrollTwoFactor($user);
        $user->createToken('phone', ['customer']);
        $user->createToken('tablet', ['customer']);
        $bystander->createToken('phone', ['customer']);
        $rememberBefore = $user->fresh()->remember_token;

        $this->resetTwoFactor($this->alpha['root'], $user)
            ->assertRedirect(route('platform.companies.show', $this->alpha['root']))
            ->assertSessionHas('success');

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasTwoFactorEnabled());
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertSame(0, $fresh->tokens()->count(), 'the app tokens go with the second factor');
        $this->assertNotSame($rememberBefore, $fresh->remember_token, 'every remember-me cookie is dead');
        $this->assertSame(1, $bystander->tokens()->count(), 'a colleague keeps theirs');

        $security = Activity::query()->where('log_name', 'security')->where('description', 'two_factor.admin_reset')->sole();
        $this->assertSame([$this->operator->id, 'platform_admin'], [$security->properties['actor_id'], $security->properties['actor_type']]);

        $platform = Activity::query()->where('log_name', 'platform')->where('description', 'user.two_factor_reset')->sole();
        $this->assertSame([$this->operator::class, $this->operator->id], [$platform->causer_type, (int) $platform->causer_id]);
        $this->assertSame([User::class, $user->id], [$platform->subject_type, (int) $platform->subject_id]);
        $this->assertSame($this->alpha['root']->id, (int) $platform->company_id);
        $this->assertSame($user->id, $platform->properties['user_id']);

        $support = Activity::query()->where('log_name', 'support')->where('description', 'user.two_factor_reset')->sole();
        $this->assertSame([User::class, $user->id], [$support->subject_type, (int) $support->subject_id]);
        $this->assertSame($this->alpha['root']->id, (int) $support->company_id);
        $this->assertSame(['user_id' => $user->id], $support->properties->all(), 'ids only: the company lives in its column');
        $this->assertStringNotContainsString($this->operator->email, json_encode($support->properties));
    }

    public function test_a_reset_deletes_the_users_database_sessions_and_only_theirs(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->alpha['staff'];
        $bystander = $this->alpha['admin'];
        $this->enrollTwoFactor($user);
        $mine = [$this->sessionRow($user), $this->sessionRow($user)];
        $theirs = $this->sessionRow($bystander);

        app(SupportToolsService::class)->resetTwoFactor($this->operator, $user);

        $this->assertSame(0, DB::table('sessions')->whereIn('id', $mine)->count());
        $this->assertTrue(DB::table('sessions')->where('id', $theirs)->exists());
        $this->assertSame(2, Activity::query()->where('description', 'user.two_factor_reset')->count(), 'a platform row and a support row');
    }

    // ---------------------------------------------------------------------------------------------
    // Resend the invitation
    // ---------------------------------------------------------------------------------------------

    public function test_a_resend_queues_one_invitation_for_that_user_without_a_confirmation(): void
    {
        Notification::fake();
        $invitee = $this->invitee();

        $this->resend($this->alpha['root'], $invitee)
            ->assertRedirect(route('platform.companies.show', $this->alpha['root']))
            ->assertSessionHas('success');

        Notification::assertSentTo($invitee, TenantAdminInvitation::class, fn (TenantAdminInvitation $mail, array $channels): bool => $channels === ['mail'] && $mail->companyId === $this->alpha['root']->id);
        Notification::assertCount(1);
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, new TenantAdminInvitation($this->alpha['root']->id));
    }

    public function test_the_resent_mail_is_indonesian_and_its_link_sets_a_password_with_a_token_minted_now(): void
    {
        $invitee = $this->invitee();
        $old = Password::broker()->createToken($invitee);

        $this->resend($this->alpha['root'], $invitee)->assertSessionHas('success');

        $message = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('Undangan admin Alpha Net di Inbils', $message->getSubject());
        $this->assertSame($invitee->email, $message->getTo()[0]->getAddress());
        $this->assertSame(1, preg_match('#/reset-password/([^?"]+)\?email=#', (string) $message->getHtmlBody(), $match));
        $this->assertStringContainsString('berlaku 60 menit', (string) $message->getHtmlBody());
        $this->assertTrue(Password::broker()->tokenExists($invitee, $match[1]), 'the link works');
        $this->assertFalse(Password::broker()->tokenExists($invitee, $old), 'and the link of the first invitation no longer does');
    }

    public function test_a_resend_is_audited_for_the_platform_and_the_tenant_without_the_operators_identity(): void
    {
        Notification::fake();
        $invitee = $this->invitee();

        $this->resend($this->alpha['root'], $invitee)->assertSessionHas('success');

        $platform = Activity::query()->where('log_name', 'platform')->where('description', 'user.invitation_resent')->sole();
        $this->assertSame([$this->operator::class, $this->operator->id], [$platform->causer_type, (int) $platform->causer_id]);
        $this->assertSame([User::class, $invitee->id], [$platform->subject_type, (int) $platform->subject_id]);

        $support = Activity::query()->where('log_name', 'support')->where('description', 'user.invitation_resent')->sole();
        $this->assertSame($this->alpha['root']->id, (int) $support->company_id);
        $this->assertSame(['user_id' => $invitee->id], $support->properties->all());
    }

    public function test_only_an_active_admin_who_never_signed_in_is_invited(): void
    {
        Notification::fake();
        $root = $this->alpha['root'];
        $signedIn = $this->invitee();
        $signedIn->forceFill(['last_login_at' => now()->subDay()])->save();
        $inactive = $this->invitee();
        $inactive->forceFill(['is_active' => false])->save();

        // The copy of the mail says "admin": a staff member never receives it.
        foreach ([$this->alpha['staff'], $signedIn, $inactive] as $user) {
            $this->resend($root, $user)->assertSessionHasErrors('invitation');
        }

        Notification::assertNothingSent();
        $this->assertSame(0, Activity::query()->where('description', 'user.invitation_resent')->count());
        $this->assertSame(0, RateLimiter::attempts('platform-invitation|'.$this->alpha['staff']->id), 'a refusal does not spend the budget');
    }

    public function test_a_resend_is_throttled_to_six_a_minute_per_target_user(): void
    {
        Notification::fake();
        $root = $this->alpha['root'];
        $invitee = $this->invitee();
        $other = $this->invitee();

        for ($sent = 1; $sent <= 6; $sent++) {
            $this->resend($root, $invitee)->assertSessionHas('success');
        }

        $this->resend($root, $invitee)->assertTooManyRequests()->assertHeader('Retry-After');
        Notification::assertSentToTimes($invitee, TenantAdminInvitation::class, 6);

        $this->resend($root, $other)->assertSessionHas('success');
        Notification::assertSentTo($other, TenantAdminInvitation::class);

        $this->travel(61)->seconds();
        $this->resend($root, $invitee)->assertSessionHas('success');
        Notification::assertSentToTimes($invitee, TenantAdminInvitation::class, 7);
    }

    // ---------------------------------------------------------------------------------------------
    // Unlock the login challenge
    // ---------------------------------------------------------------------------------------------

    public function test_an_unlock_clears_the_shared_fifteen_minute_key_of_that_user_alone_and_needs_no_confirmation(): void
    {
        $user = $this->alpha['staff'];
        $colleague = $this->alpha['admin'];

        for ($attempt = 1; $attempt <= 11; $attempt++) {
            RateLimiter::hit('two-factor-user|'.$user->id, 900);
            RateLimiter::hit('two-factor-user|'.$colleague->id, 900);
        }

        $this->assertTrue(RateLimiter::tooManyAttempts('two-factor-user|'.$user->id, 10));

        $this->unlock($this->alpha['root'], $user)
            ->assertRedirect(route('platform.companies.show', $this->alpha['root']))
            ->assertSessionHas('success');

        $this->assertSame(0, RateLimiter::attempts('two-factor-user|'.$user->id));
        $this->assertFalse(RateLimiter::tooManyAttempts('two-factor-user|'.$user->id, 10));
        $this->assertSame(11, RateLimiter::attempts('two-factor-user|'.$colleague->id), 'the colleague stays locked');

        $platform = Activity::query()->where('log_name', 'platform')->where('description', 'user.login_unlocked')->sole();
        $this->assertSame([$this->operator::class, $this->operator->id], [$platform->causer_type, (int) $platform->causer_id]);
        $support = Activity::query()->where('log_name', 'support')->where('description', 'user.login_unlocked')->sole();
        $this->assertSame($this->alpha['root']->id, (int) $support->company_id);
        $this->assertSame(['user_id' => $user->id], $support->properties->all());
    }

    public function test_after_an_unlock_the_real_login_challenge_accepts_the_users_code_again(): void
    {
        $user = $this->alpha['staff'];
        ['secret' => $secret] = $this->enrollTwoFactor($user);
        $this->travel(30)->seconds();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            RateLimiter::hit('two-factor-user|'.$user->id, 900);
        }

        $this->asTenantVisitor()->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->post(route('two-factor.login'), ['code' => $this->totp($secret)])->assertSessionHasErrors('code');
        $this->assertStringContainsString('Terlalu banyak', session('errors')->first('code'));
        $this->assertGuest();

        $this->unlock($this->alpha['root'], $user)->assertSessionHas('success');

        $this->asTenantVisitor()->post(route('two-factor.login'), ['code' => $this->totp($secret)])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    // ---------------------------------------------------------------------------------------------
    // Failed jobs and failed notifications
    // ---------------------------------------------------------------------------------------------

    public function test_the_failed_job_list_holds_this_tenants_jobs_alone_newest_first_and_is_paginated(): void
    {
        $mine = [];

        foreach (range(1, 12) as $minutesAgo) {
            $mine[] = $this->failedJob($minutesAgo % 2 === 0 ? $this->alpha['root']->id : $this->alpha['child']->id, now()->subMinutes($minutesAgo), "RuntimeException: boom {$minutesAgo}\n#0 /app/Jobs/Secret.php(12): trace");
        }

        $this->failedJob($this->beta['root']->id);
        $this->failedJob(null);
        $this->failedJob($this->alpha['root']->id, now()->subDays(31));

        $first = $this->asOperator(false)->getJson(route('platform.companies.failed-jobs', [$this->alpha['root'], 'per_page' => 10]))
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('window_days', 30);

        $uuids = DB::table('failed_jobs')->whereIn('id', $mine)->pluck('uuid', 'id')->all();
        $mine = array_map(fn (int $id): string => $uuids[$id], $mine);

        $this->assertSame(array_slice($mine, 0, 10), array_column($first->json('data'), 'uuid'), 'newest first');
        $this->assertSame([
            'uuid' => $mine[0],
            'queue' => 'default',
            'job' => 'App\\Jobs\\ExampleJob',
            'exception' => 'RuntimeException: boom 1',
            'company' => ['id' => $this->alpha['child']->public_id, 'code' => 'ALP-C'],
            'failed_at' => '2026-10-20T11:59:00+00:00',
        ], $first->json('data.0'));
        $this->assertStringNotContainsString('payload', json_encode($first->json('data')));
        $this->assertStringNotContainsString('trace', json_encode($first->json('data')));

        $second = $this->asOperator(false)->getJson(route('platform.companies.failed-jobs', [$this->alpha['root'], 'per_page' => 10, 'page' => 2]))->assertOk();
        $this->assertSame(array_slice($mine, 10), array_column($second->json('data'), 'uuid'));
    }

    public function test_the_failed_notification_list_holds_this_tenants_failed_messages_alone_newest_first_and_is_paginated(): void
    {
        $mine = [];

        foreach (range(1, 12) as $daysAgo) {
            $mine[] = $this->failedWhatsApp($daysAgo % 2 === 0 ? $this->alpha['root']->id : $this->alpha['child']->id, 'failed', now()->subDays($daysAgo));
        }

        $this->failedWhatsApp($this->alpha['root']->id, 'sent', now()->subHour());
        $this->failedWhatsApp($this->beta['root']->id, 'failed', now()->subHour());

        $first = $this->asOperator(false)->getJson(route('platform.companies.failed-notifications', [$this->alpha['root'], 'per_page' => 10]))
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 2);

        $publicIds = DB::table('whatsapp_messages')->whereIn('id', $mine)->pluck('public_id', 'id')->all();
        $mine = array_map(fn (int $id): string => $publicIds[$id], $mine);

        $this->assertSame(array_slice($mine, 0, 10), array_column($first->json('data'), 'id'));
        $this->assertSame([
            'id' => $mine[0],
            'event' => 'access_link_notice',
            'template' => 't',
            'error_code' => '131026',
            'recipient_last4' => '7890',
            'company' => ['id' => $this->alpha['child']->public_id, 'code' => 'ALP-C'],
            'failed_at' => '2026-10-19T12:00:00+00:00',
        ], $first->json('data.0'));

        $second = $this->asOperator(false)->getJson(route('platform.companies.failed-notifications', [$this->alpha['root'], 'per_page' => 10, 'page' => 2]))->assertOk();
        $this->assertSame(array_slice($mine, 10), array_column($second->json('data'), 'id'));
    }

    public function test_the_lists_validate_their_page_size_and_are_empty_for_a_tenant_with_no_failures(): void
    {
        $this->failedJob($this->beta['root']->id);
        $this->failedWhatsApp($this->beta['root']->id, 'failed', now());

        foreach (['platform.companies.failed-jobs', 'platform.companies.failed-notifications'] as $name) {
            $this->asOperator(false)->getJson(route($name, [$this->alpha['root'], 'per_page' => 1000]))->assertUnprocessable()->assertJsonValidationErrors('per_page');
            $this->asOperator(false)->getJson(route($name, [$this->alpha['root'], 'page' => 0]))->assertUnprocessable()->assertJsonValidationErrors('page');
            $this->asOperator(false)->getJson(route($name, $this->alpha['root']))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
            $this->asOperator(false)->getJson(route($name, $this->alpha['child']))->assertNotFound();
        }
    }

    public function test_the_failed_lists_cost_the_same_queries_whatever_they_hold(): void
    {
        $this->failedJob($this->alpha['root']->id);
        $this->failedWhatsApp($this->alpha['root']->id, 'failed', now());

        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->asOperator(false)->getJson(route('platform.companies.failed-jobs', [$this->alpha['root'], 'per_page' => 50]))->assertOk();
            $this->asOperator(false)->getJson(route('platform.companies.failed-notifications', [$this->alpha['root'], 'per_page' => 50]))->assertOk();

            return count(DB::getQueryLog());
        };

        $few = $queries();

        foreach (range(1, 15) as $n) {
            $this->failedJob($n % 2 === 0 ? $this->alpha['root']->id : $this->alpha['child']->id);
            $this->failedWhatsApp($n % 2 === 0 ? $this->alpha['root']->id : $this->alpha['child']->id, 'failed', now());
        }

        $this->assertSame($few, $queries(), 'no query per row');
    }
}
