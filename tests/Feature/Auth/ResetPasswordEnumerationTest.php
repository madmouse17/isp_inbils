<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Setting a new password from a link never says whether the address belongs to an account (the forgot-password page already
 * keeps that secret), and a guess at the token is limited, on a bucket of its own.
 */
class ResetPasswordEnumerationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_MESSAGE = 'Token pengaturan ulang kata sandi tidak valid.';

    private const NEW_SECRET = 'Sandi-Baru-4821-Panjang';

    /** @return array<string, string> */
    private function body(string $email): array
    {
        return ['token' => 'token-yang-salah', 'email' => $email, 'password' => self::NEW_SECRET, 'password_confirmation' => self::NEW_SECRET];
    }

    public function test_an_unknown_address_is_answered_like_a_known_one_with_a_wrong_token(): void
    {
        $user = User::factory()->create();

        $known = $this->post('/reset-password', $this->body($user->email));
        $unknown = $this->post('/reset-password', $this->body('nobody@example.test'));

        $known->assertSessionHasErrors(['email' => self::TOKEN_MESSAGE]);
        $unknown->assertSessionHasErrors(['email' => self::TOKEN_MESSAGE]);
    }

    public function test_the_wrong_token_of_a_known_address_does_not_change_the_secret(): void
    {
        $user = User::factory()->create();
        $before = $user->password;

        $this->post('/reset-password', $this->body($user->email))->assertSessionHasErrors('email');

        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_the_seventh_guess_in_a_minute_is_refused(): void
    {
        for ($guess = 1; $guess <= 6; $guess++) {
            $this->post('/reset-password', $this->body("nobody{$guess}@example.test"))->assertSessionHasErrors('email');
        }

        $this->post('/reset-password', $this->body('nobody7@example.test'))->assertStatus(429);
    }

    public function test_the_reset_has_an_allowance_apart_from_the_forgot_password_page(): void
    {
        for ($ask = 1; $ask <= 6; $ask++) {
            $this->post('/forgot-password', ['email' => "nobody{$ask}@example.test"])->assertSessionHasNoErrors();
        }
        $this->post('/forgot-password', ['email' => 'nobody7@example.test'])->assertStatus(429);

        $this->post('/reset-password', $this->body('nobody@example.test'))->assertSessionHasErrors('email');
    }
}
