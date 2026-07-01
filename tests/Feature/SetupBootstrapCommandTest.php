<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_bootstrap_user(): void
    {
        $this->artisan('inbils:setup')
            ->expectsQuestion('Name', 'Bootstrap Admin')
            ->expectsQuestion('Email', 'admin@example.test')
            ->expectsQuestion('Password', 'password')
            ->expectsOutput('Bootstrap user created. Login at /login → complete Setup Wizard.')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $user = User::query()->firstOrFail();
        $this->assertSame('Bootstrap Admin', $user->name);
        $this->assertSame('admin@example.test', $user->email);
        $this->assertNull($user->company_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->roles()->doesntExist());
    }

    public function test_command_is_idempotent(): void
    {
        User::factory()->create();

        $this->artisan('inbils:setup')
            ->expectsOutput('Already bootstrapped.')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count());
    }
}
