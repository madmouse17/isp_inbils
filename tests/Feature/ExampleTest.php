<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_sends_a_guest_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_root_sends_a_signed_in_user_to_the_dashboard(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['company_id' => $company->id, 'email_verified_at' => now(), 'is_active' => true]);

        $this->actingAs($user)->get('/')->assertRedirect(route('admin.dashboard'));
    }
}
