<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * A flagged (legacy phone-number) customer login is opened by one thing only: a completed password
 * reset, which proves the customer controls the mailbox. These tests drive the real reset route.
 */
class PortalCredentialsTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $customerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $admin = $this->createCompanyUser();
        Role::findOrCreate('customer', 'web')->givePermissionTo(
            Permission::findOrCreate('portal.invoice.view', 'web'),
            Permission::findOrCreate('portal.invoice.pay', 'web'),
        );
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->customerUser = User::factory()->create([
            'company_id' => $admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
            'password' => Hash::make('0800005195'),
            'must_change_password' => true,
            'remember_token' => 'stale-remember-token',
        ]);
        $this->customerUser->assignRole('customer');
        Customer::factory()->create(['company_id' => $admin->company_id, 'user_id' => $this->customerUser->id, 'phone' => '0800005195']);
    }

    private function insertSession(string $id, int $userId): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'phpunit', 'payload' => '', 'last_activity' => time()]);
    }

    public function test_a_completed_password_reset_clears_the_flag_and_retires_the_phone_number(): void
    {
        $token = Password::broker()->createToken($this->customerUser);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $this->customerUser->email,
            'password' => 'N3w-Passw0rd-xyz',
            'password_confirmation' => 'N3w-Passw0rd-xyz',
        ])->assertRedirect(route('login'));

        $fresh = $this->customerUser->fresh();
        $this->assertFalse((bool) $fresh->getAttribute('must_change_password'));
        $this->assertTrue(Hash::check('N3w-Passw0rd-xyz', $fresh->password));
        $this->assertFalse(Hash::check('0800005195', $fresh->password));
    }

    public function test_a_failed_reset_leaves_the_flag_set(): void
    {
        $this->post(route('password.store'), [
            'token' => 'not-a-real-token',
            'email' => $this->customerUser->email,
            'password' => 'N3w-Passw0rd-xyz',
            'password_confirmation' => 'N3w-Passw0rd-xyz',
        ])->assertSessionHasErrors('email');

        $fresh = $this->customerUser->fresh();
        $this->assertTrue((bool) $fresh->getAttribute('must_change_password'));
        $this->assertTrue(Hash::check('0800005195', $fresh->password));
    }

    public function test_the_reset_event_clears_the_flag_and_rotates_the_remember_token(): void
    {
        event(new PasswordReset($this->customerUser));

        $fresh = $this->customerUser->fresh();
        $this->assertFalse((bool) $fresh->getAttribute('must_change_password'));
        $this->assertNotSame('stale-remember-token', $fresh->remember_token);
    }

    public function test_database_sessions_of_the_account_end_when_the_flag_clears(): void
    {
        config(['session.driver' => 'database']);
        $other = User::factory()->create();
        $this->insertSession('attacker-session', $this->customerUser->id);
        $this->insertSession('older-session', $this->customerUser->id);
        $this->insertSession('unrelated-session', $other->id);

        event(new PasswordReset($this->customerUser));

        $this->assertSame(['unrelated-session'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_a_reset_for_an_account_that_was_never_flagged_changes_nothing_here(): void
    {
        $this->customerUser->forceFill(['must_change_password' => false])->save();
        config(['session.driver' => 'database']);
        $this->insertSession('live-session', $this->customerUser->id);

        event(new PasswordReset($this->customerUser));

        $this->assertSame('stale-remember-token', $this->customerUser->fresh()->remember_token);
        $this->assertSame(['live-session'], DB::table('sessions')->pluck('id')->all());
    }
}
