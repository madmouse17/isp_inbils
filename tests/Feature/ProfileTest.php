<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class ProfileTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = $this->createCompanyUser();
        $response = $this->actingAs($user)->get('/profile');
        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = $this->createCompanyUser();
        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect('/profile');
        $user->refresh();
        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = $this->createCompanyUser();
        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_a_login_still_on_its_legacy_password_cannot_change_its_email(): void
    {
        $user = $this->createCompanyUser();
        $user->forceFill(['must_change_password' => true])->save();

        $this->actingAs($user)->patch('/profile', ['name' => 'Renamed', 'email' => 'attacker@example.com'])
            ->assertSessionHasErrors('email');
        $this->assertNotSame('attacker@example.com', $user->refresh()->email);

        // Renaming without touching the email still works.
        $this->actingAs($user)->patch('/profile', ['name' => 'Renamed', 'email' => $user->email])
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $user->refresh()->name);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = $this->createCompanyUser();
        // The last administrator of a company cannot leave (UserRoleAssignmentTest): this company keeps another one.
        $this->grantRole(User::factory()->create(['company_id' => $user->company_id, 'email_verified_at' => now(), 'is_active' => true]), 'admin');
        $response = $this->actingAs($user)->delete('/profile', [
            'password' => 'password',
        ]);
        $response->assertSessionHasNoErrors()->assertRedirect('/');
        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = $this->createCompanyUser();
        $response = $this->actingAs($user)->from('/profile')->delete('/profile', [
            'password' => 'wrong-password',
        ]);
        $response->assertSessionHasErrors('password')->assertRedirect('/profile');
        $this->assertNotNull($user->fresh());
    }

    public function test_notification_preference_is_saved(): void
    {
        $user = $this->createCompanyUser();
        $this->assertFalse($user->fresh()->inventory_digest, 'the digest is opt-in');

        $this->actingAs($user)->from('/profile')->patch(route('profile.notifications.update'), ['inventory_digest' => true])
            ->assertStatus(303)
            ->assertRedirect('/profile')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Preferensi notifikasi disimpan.');
        $this->assertTrue($user->fresh()->inventory_digest);

        $this->actingAs($user)->patch(route('profile.notifications.update'), ['inventory_digest' => false])->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->inventory_digest);
    }

    public function test_the_preference_must_be_a_yes_or_no(): void
    {
        $user = $this->createCompanyUser();

        $this->actingAs($user)->patch(route('profile.notifications.update'), ['inventory_digest' => 'maybe'])->assertSessionHasErrors('inventory_digest');
        $this->actingAs($user)->patch(route('profile.notifications.update'))->assertSessionHasErrors('inventory_digest');
        $this->assertFalse($user->fresh()->inventory_digest);
    }

    public function test_the_profile_form_cannot_set_the_preference(): void
    {
        $user = $this->createCompanyUser();

        $this->actingAs($user)->patch('/profile', ['name' => 'Test User', 'email' => $user->email, 'inventory_digest' => true])->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->inventory_digest);
    }

    public function test_preference_hidden_when_not_eligible(): void
    {
        $admin = $this->createCompanyUser();
        $shows = fn (bool $eligible, bool $enabled) => fn (Assert $page) => $page
            ->component('Profile/Edit')
            ->where('notificationPreferences', ['inventory_digest' => $enabled, 'eligible' => $eligible]);

        $bystander = User::factory()->create(['company_id' => $admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $this->actingAs($bystander)->get('/profile')->assertOk()->assertInertia($shows(false, false));

        // Any one of the five permissions of the digest is enough, and the switch shows its saved state.
        foreach (User::INVENTORY_DIGEST_PERMISSIONS as $permission) {
            $holder = User::factory()->create(['company_id' => $admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
            $this->grantPermissions($holder, [$permission]);
            $this->actingAs($holder)->get('/profile')->assertOk()->assertInertia($shows(true, false));
        }

        $admin->forceFill(['inventory_digest' => true])->save();
        $this->actingAs($admin)->get('/profile')->assertOk()->assertInertia($shows(true, true));
    }
}
