<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/**
 * confirm-password guards password changes and, since two-factor authentication, its management: it
 * must not be a free place to guess the password with a stolen session.
 */
class PasswordConfirmationThrottleTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_five_wrong_passwords_lock_the_step_up_even_for_the_right_one(): void
    {
        $this->actingAs($this->createCompanyUser());

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/confirm-password', ['password' => 'wrong-password'])
                ->assertSessionHasErrors(['password' => __('auth.password')]);
        }

        $this->post('/confirm-password', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertStringContainsString('Too many', session('errors')->first('password'));
        $this->assertNull(session('auth.password_confirmed_at'));

        $this->travel(61)->seconds();
        $this->post('/confirm-password', ['password' => 'password'])->assertSessionHasNoErrors();
        $this->assertNotNull(session('auth.password_confirmed_at'));
    }

    public function test_the_right_password_clears_the_count(): void
    {
        $this->actingAs($this->createCompanyUser());

        for ($round = 1; $round <= 3; $round++) {
            for ($attempt = 1; $attempt <= 4; $attempt++) {
                $this->post('/confirm-password', ['password' => 'wrong-password'])->assertSessionHasErrors('password');
            }

            $this->post('/confirm-password', ['password' => 'password'])->assertSessionHasNoErrors();
        }
    }

    public function test_the_count_is_per_address(): void
    {
        $this->actingAs($this->createCompanyUser());
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/confirm-password', ['password' => 'wrong-password']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->post('/confirm-password', ['password' => 'password'])
            ->assertSessionHasNoErrors();
    }
}
