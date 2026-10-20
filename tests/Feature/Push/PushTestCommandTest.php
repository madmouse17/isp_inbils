<?php

namespace Tests\Feature\Push;

use App\Models\Core\DeviceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\FakesFirebaseMessaging;
use Tests\Traits\InteractsWithMobileApi;

/** inbils:push:test: the smoke test of a device, from the command line. */
class PushTestCommandTest extends TestCase
{
    use FakesFirebaseMessaging;
    use InteractsWithMobileApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeFirebase();
    }

    public function test_it_sends_a_test_push_to_every_install_of_the_user_by_id_or_email(): void
    {
        $technician = $this->technicianLogin();
        DeviceToken::factory()->create(['user_id' => $technician->id, 'app' => 'technician', 'token' => 'phone-install']);
        DeviceToken::factory()->create(['user_id' => $technician->id, 'app' => 'technician', 'token' => 'tablet-install']);

        $this->artisan('inbils:push:test', ['user' => (string) $technician->id])
            ->expectsOutput('technician: 2 accepted, 0 stale (deleted), 0 failed.')
            ->assertSuccessful();
        $this->artisan('inbils:push:test', ['user' => $technician->email])->assertSuccessful();

        $this->assertCount(2, $this->multicasts);
        $this->assertEqualsCanonicalizing(['phone-install', 'tablet-install'], $this->multicasts[0]['tokens']);
        $push = $this->multicasts[0]['message'];
        $this->assertArrayNotHasKey('notification', $push, 'data-only: the app draws the push itself');
        $this->assertSame('Inbils', $push['data']['title']);
        $this->assertSame('push_test', $push['data']['type']);
        $this->assertSame(['subject_type' => '', 'subject_id' => ''], array_intersect_key($push['data'], array_flip(['subject_type', 'subject_id'])));
        $this->assertFalse($this->multicasts[0]['validate_only']);
    }

    public function test_the_app_option_picks_one_app_of_a_user_who_has_both(): void
    {
        [$user] = $this->customerLogin();
        DeviceToken::factory()->forApp('customer')->create(['user_id' => $user->id, 'token' => 'customer-install']);
        DeviceToken::factory()->create(['user_id' => $user->id, 'app' => 'technician', 'token' => 'technician-install']);

        $this->artisan('inbils:push:test', ['user' => $user->email, '--app' => 'customer'])->assertSuccessful();

        $this->assertSame(['customer-install'], $this->pushedTokens());
        $this->artisan('inbils:push:test', ['user' => $user->email, '--app' => 'admin'])->expectsOutput('--app must be customer or technician.')->assertFailed();
    }

    public function test_a_dry_run_only_asks_fcm_to_validate_and_keeps_every_token(): void
    {
        $technician = $this->technicianLogin();
        DeviceToken::factory()->create(['user_id' => $technician->id, 'app' => 'technician', 'token' => 'garbage-install']);
        $this->fcmInvalid('garbage-install');

        $this->artisan('inbils:push:test', ['user' => $technician->email, '--dry-run' => true])
            ->expectsOutput('technician: 0 accepted, 1 stale (kept, dry run), 0 failed.')
            ->assertFailed();

        $this->assertTrue($this->multicasts[0]['validate_only']);
        $this->assertSame(1, DeviceToken::query()->count());
    }

    public function test_a_stale_token_is_deleted_and_the_command_says_so(): void
    {
        $technician = $this->technicianLogin();
        DeviceToken::factory()->create(['user_id' => $technician->id, 'app' => 'technician', 'token' => 'uninstalled-install']);
        $this->fcmUnregistered('uninstalled-install');

        $this->artisan('inbils:push:test', ['user' => $technician->email])
            ->expectsOutput('technician: 0 accepted, 1 stale (deleted), 0 failed.')
            ->assertFailed();

        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_it_refuses_without_credentials_without_a_user_and_without_an_install(): void
    {
        $technician = $this->technicianLogin();

        $this->artisan('inbils:push:test', ['user' => $technician->email])
            ->expectsOutputToContain('has no install a push can reach')
            ->assertFailed();
        $this->artisan('inbils:push:test', ['user' => 'nobody@example.test'])->expectsOutput('User not found.')->assertFailed();

        config(['firebase.projects.app.credentials' => '']);
        $this->artisan('inbils:push:test', ['user' => $technician->email])->expectsOutputToContain('Push is off')->assertFailed();
        $this->assertSame([], $this->multicasts);
    }
}
