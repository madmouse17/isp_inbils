<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\EnrollsTwoFactor;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * An administrator restoring access for a colleague who lost both authenticator and recovery codes:
 * permission `users.two_factor.reset`, never your own, only for a user you may change, always audited.
 * The tests grant the permission themselves, so they hold whether or not the seeded database has it yet.
 */
class UserTwoFactorResetTest extends TestCase
{
    use BuildsCompanyGroup;
    use EnrollsTwoFactor;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::create(2026, 10, 12, 10, 0, 0, 'UTC'));
        $this->buildGroup();
        $this->grantTwoFactorResetToAdmin();
    }

    public function test_an_admin_clears_a_colleagues_second_factor_and_the_reset_is_audited(): void
    {
        $admin = $this->groupUser($this->head);
        $colleague = $this->groupUser($this->head, 'staff');
        $bystander = $this->groupUser($this->head, 'staff');
        ['secret' => $secret, 'codes' => $codes] = $this->enrollTwoFactor($colleague);
        $this->enrollTwoFactor($bystander);

        $this->actingAs($admin)
            ->post(route('admin.users.two-factor.reset', $colleague))
            ->assertRedirect()
            ->assertSessionHas('success');

        $row = DB::table('users')->where('id', $colleague->id)->first();
        $this->assertNull($row->two_factor_secret);
        $this->assertNull($row->two_factor_recovery_codes);
        $this->assertNull($row->two_factor_confirmed_at);
        $this->assertNull($row->two_factor_last_used_step);
        $this->assertTrue($bystander->fresh()->hasTwoFactorEnabled(), 'only the target is reset');

        $audit = Activity::query()->where('description', 'two_factor.admin_reset')->sole();
        $this->assertSame('security', $audit->log_name);
        $this->assertSame($admin->id, $audit->causer_id);
        $this->assertSame($colleague->id, $audit->subject_id);
        $this->assertSame($this->head->id, (int) $audit->company_id, 'the company lives in its column, not in the properties');
        $this->assertEqualsCanonicalizing(
            ['user_id' => $colleague->id, 'actor_id' => $admin->id, 'actor_type' => 'user'],
            $audit->properties->all(),
        );
        foreach ([$secret, ...$codes] as $material) {
            $this->assertStringNotContainsString($material, json_encode($audit->properties));
        }
    }

    public function test_an_admin_who_shares_every_membership_of_the_target_may_reset_it(): void
    {
        $both = $this->groupUser($this->head, 'admin', $this->subA);
        $shared = $this->groupUser($this->head, 'staff', $this->subA);
        $this->enrollTwoFactor($shared);

        $this->actingAs($both)->post(route('admin.users.two-factor.reset', $shared))->assertRedirect();

        $this->assertFalse($shared->fresh()->hasTwoFactorEnabled());
    }

    public function test_the_user_can_log_in_with_the_password_alone_after_the_reset(): void
    {
        $admin = $this->groupUser($this->head);
        $colleague = $this->groupUser($this->head, 'staff');
        $this->enrollTwoFactor($colleague);
        $this->actingAs($admin)->post(route('admin.users.two-factor.reset', $colleague));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post('/login', ['email' => $colleague->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard', absolute: false));

        $this->assertAuthenticatedAs($colleague);
    }

    public function test_nobody_resets_their_own_second_factor_here(): void
    {
        $admin = $this->groupUser($this->head);
        $this->enrollTwoFactor($admin);

        $this->actingAs($admin)->post(route('admin.users.two-factor.reset', $admin))->assertForbidden();

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
        $this->assertSame(0, Activity::query()->where('description', 'two_factor.admin_reset')->count());
    }

    public function test_a_role_without_the_permission_is_refused(): void
    {
        $target = $this->groupUser($this->head, 'staff');
        $this->enrollTwoFactor($target);

        foreach (['manager', 'staff', 'technician'] as $role) {
            $actor = $this->groupUser($this->head, $role);

            $this->actingAs($actor)->post(route('admin.users.two-factor.reset', $target))->assertForbidden();
        }

        $this->assertTrue($target->fresh()->hasTwoFactorEnabled());
    }

    public function test_an_admin_who_does_not_answer_for_every_company_of_the_target_is_refused(): void
    {
        $headAdmin = $this->groupUser($this->head);
        $shared = $this->groupUser($this->head, 'staff', $this->subA);
        $this->enrollTwoFactor($shared);

        // A user the actor does not administer is a record the actor cannot see: the same 404 as an unknown id.
        $this->actingAs($headAdmin)->post(route('admin.users.two-factor.reset', $shared))->assertNotFound();

        $this->assertTrue($shared->fresh()->hasTwoFactorEnabled());
    }

    public function test_a_non_admin_holding_the_permission_may_reset_others_but_never_an_admin(): void
    {
        Role::findByName('manager')->givePermissionTo('users.two_factor.reset');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $manager = $this->groupUser($this->head, 'manager');
        $staff = $this->groupUser($this->head, 'staff');
        $admin = $this->groupUser($this->head);
        $this->enrollTwoFactor($staff);
        $this->enrollTwoFactor($admin);

        $this->actingAs($manager)->post(route('admin.users.two-factor.reset', $admin))->assertForbidden();
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());

        $this->post(route('admin.users.two-factor.reset', $staff))->assertRedirect();
        $this->assertFalse($staff->fresh()->hasTwoFactorEnabled());
    }

    public function test_a_user_homed_in_another_company_is_not_found(): void
    {
        $admin = $this->groupUser($this->head);
        $stranger = $this->groupUser($this->outsider, 'staff');
        $this->enrollTwoFactor($stranger);

        $this->actingAs($admin)->post(route('admin.users.two-factor.reset', $stranger))->assertNotFound();

        $this->assertTrue($stranger->fresh()->hasTwoFactorEnabled());
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $target = $this->groupUser($this->head, 'staff');

        $this->post(route('admin.users.two-factor.reset', $target))->assertRedirect(route('login'));
    }

    public function test_the_user_export_carries_no_two_factor_material(): void
    {
        $admin = $this->groupUser($this->head);
        $enrolled = $this->groupUser($this->head, 'staff');
        ['secret' => $secret, 'codes' => $codes] = $this->enrollTwoFactor($enrolled);

        $csv = $this->actingAs($admin)->get(route('admin.users.export', ['format' => 'csv']))->assertOk()->streamedContent();

        $this->assertStringContainsString($enrolled->email, $csv);
        foreach ([$secret, ...$codes, 'two_factor'] as $material) {
            $this->assertStringNotContainsString($material, $csv);
        }
    }

    public function test_the_user_pages_say_when_to_offer_the_reset_and_never_carry_secrets(): void
    {
        $admin = $this->groupUser($this->head);
        $enrolled = $this->groupUser($this->head, 'staff');
        $without = $this->groupUser($this->head, 'staff');
        ['secret' => $secret, 'codes' => $codes] = $this->enrollTwoFactor($enrolled);
        $this->enrollTwoFactor($admin);
        $this->actingAs($admin);

        $this->get(route('admin.users.show', $enrolled))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Show')
                ->where('user.data.two_factor_enabled', true)
                ->where('user.data.can.reset_two_factor', true))
            ->assertDontSee($secret, false)
            ->assertDontSee($codes[0], false);

        $this->get(route('admin.users.show', $without))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('user.data.two_factor_enabled', false)
                ->where('user.data.can.reset_two_factor', false));

        $this->get(route('admin.users.show', $admin))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('user.data.two_factor_enabled', true)
                ->where('user.data.can.reset_two_factor', false));

        $users = $this->get(route('admin.users.index'))->assertOk()->inertiaProps('users.data');
        $this->assertSame([true, true, false], collect($users)->sortBy('id')->pluck('two_factor_enabled')->values()->all());
        $this->assertStringNotContainsString('two_factor_secret', json_encode($users));
    }
}
