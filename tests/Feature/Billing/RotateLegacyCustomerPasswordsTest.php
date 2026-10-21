<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Notifications\CustomerAccountCreated;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class RotateLegacyCustomerPasswordsTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private Company $companyA;

    private Company $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->createCompanyUser();
        $this->companyA = Company::query()->findOrFail($admin->company_id);
        $this->companyB = Company::factory()->create(['is_active' => true]);
    }

    /** A customer login created before random passwords: the phone number is the password and the account is flagged. */
    private function legacyCustomer(Company $company, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'company_id' => $company->id,
            'is_active' => true,
            'password' => Hash::make('0800005195'),
            'must_change_password' => true,
            'remember_token' => 'stale-remember-token',
        ], $attributes));
        $this->grantRole($user, 'customer');
        Customer::factory()->create(['company_id' => $company->id, 'user_id' => $user->id, 'phone' => '0800005195']);

        return $user;
    }

    public function test_every_flagged_customer_login_gets_an_unknown_password_and_a_set_password_link(): void
    {
        Notification::fake();
        $a = $this->legacyCustomer($this->companyA);
        $b = $this->legacyCustomer($this->companyB);

        $this->artisan('customers:rotate-legacy-passwords')->assertExitCode(0);

        foreach ([$a, $b] as $user) {
            $fresh = $user->fresh();
            $this->assertFalse(Hash::check('0800005195', $fresh->password), 'the phone number must stop working');
            $this->assertTrue((bool) $fresh->getAttribute('must_change_password'), 'only a completed reset opens the portal');
            $this->assertNotSame('stale-remember-token', $fresh->remember_token);
            Notification::assertSentTo($user, CustomerAccountCreated::class);
        }
        Notification::assertCount(2);
    }

    public function test_unflagged_customers_and_staff_are_left_alone(): void
    {
        Notification::fake();
        $unflagged = $this->legacyCustomer($this->companyA, ['must_change_password' => false, 'password' => Hash::make('chosen-by-customer')]);
        $staff = User::factory()->create(['company_id' => $this->companyA->id, 'must_change_password' => true, 'password' => Hash::make('staff-password')]);
        $this->grantRole($staff, 'staff');

        $this->artisan('customers:rotate-legacy-passwords')->assertExitCode(0);

        $this->assertTrue(Hash::check('chosen-by-customer', $unflagged->fresh()->password));
        $this->assertTrue(Hash::check('staff-password', $staff->fresh()->password));
        Notification::assertNothingSent();
    }

    public function test_a_dry_run_reports_and_changes_nothing(): void
    {
        Notification::fake();
        $user = $this->legacyCustomer($this->companyA);

        $this->artisan('customers:rotate-legacy-passwords', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('0800005195', $fresh->password));
        $this->assertSame('stale-remember-token', $fresh->remember_token);
        Notification::assertNothingSent();
    }

    public function test_an_inactive_login_is_rotated_but_gets_no_link(): void
    {
        Notification::fake();
        $inactive = $this->legacyCustomer($this->companyA, ['is_active' => false]);

        $this->artisan('customers:rotate-legacy-passwords')->assertExitCode(0);

        $this->assertFalse(Hash::check('0800005195', $inactive->fresh()->password));
        Notification::assertNothingSent();
    }

    public function test_logins_of_an_inactive_company_are_rotated_too(): void
    {
        Notification::fake();
        $dormant = $this->legacyCustomer(Company::factory()->create(['is_active' => false]));

        $this->artisan('customers:rotate-legacy-passwords')->assertExitCode(0);

        $this->assertFalse(Hash::check('0800005195', $dormant->fresh()->password));
    }

    public function test_database_sessions_opened_with_the_old_password_are_ended(): void
    {
        Notification::fake();
        config(['session.driver' => 'database']);
        $user = $this->legacyCustomer($this->companyA);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'phpunit', 'payload' => '', 'last_activity' => time()]);

        $this->artisan('customers:rotate-legacy-passwords')->assertExitCode(0);

        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_a_second_run_is_safe_and_skips_accounts_that_finished_their_reset(): void
    {
        Notification::fake();
        $pending = $this->legacyCustomer($this->companyA);
        $done = $this->legacyCustomer($this->companyA);
        $this->artisan('customers:rotate-legacy-passwords')->assertExitCode(0);
        event(new PasswordReset($done->fresh()));
        $afterReset = $done->fresh();
        $this->assertFalse((bool) $afterReset->getAttribute('must_change_password'));

        $this->artisan('customers:rotate-legacy-passwords')->assertExitCode(0);

        $this->assertSame($afterReset->password, $done->fresh()->password);
        $this->assertFalse(Hash::check('0800005195', $pending->fresh()->password));
        $this->assertTrue((bool) $pending->fresh()->getAttribute('must_change_password'));
        Notification::assertSentToTimes($done, CustomerAccountCreated::class, 1);
        Notification::assertSentToTimes($pending, CustomerAccountCreated::class, 2);
    }
}
