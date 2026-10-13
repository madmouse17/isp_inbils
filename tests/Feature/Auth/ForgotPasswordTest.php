<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Asking for a reset link never says whether the address belongs to an account, and is throttled per address.
 */
class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const STATUS = 'Jika ada akun dengan alamat email tersebut, kami telah mengirim tautan pengaturan ulang kata sandi.';

    public function test_a_known_and_an_unknown_address_get_exactly_the_same_answer(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.test']);

        foreach ([$known, $unknown] as $response) {
            $response->assertRedirect('/forgot-password')
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', self::STATUS);
        }
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        Notification::assertCount(1);
    }

    public function test_asking_again_inside_the_brokers_wait_time_gets_the_same_answer_too(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);
        $again = $this->post('/forgot-password', ['email' => $user->email]);

        $again->assertSessionHasNoErrors()->assertSessionHas('status', self::STATUS);
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_the_route_is_throttled_per_address(): void
    {
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->post('/forgot-password', ['email' => "nobody{$attempt}@example.test"])->assertSessionHasNoErrors();
        }

        $this->post('/forgot-password', ['email' => 'nobody7@example.test'])->assertStatus(429);
    }

    public function test_a_malformed_address_is_still_a_validation_error(): void
    {
        $this->post('/forgot-password', ['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->post('/forgot-password', [])->assertSessionHasErrors('email');
    }
}
