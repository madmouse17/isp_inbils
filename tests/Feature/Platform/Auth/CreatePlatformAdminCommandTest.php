<?php

namespace Tests\Feature\Platform\Auth;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CreatePlatformAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_admin_with_a_hashed_password_and_no_tenant_footprint(): void
    {
        $company = Company::factory()->create();
        $users = User::query()->count();

        $this->artisan('inbils:platform:admin-create')
            ->expectsQuestion('Name', 'Operator Satu')
            ->expectsQuestion('Email', 'operator@inbils.test')
            ->expectsQuestion('Password', 'a-long-secret-1234')
            ->expectsQuestion('Confirm password', 'a-long-secret-1234')
            ->expectsOutputToContain('Platform admin operator@inbils.test created')
            ->assertSuccessful();

        $admin = PlatformAdmin::query()->sole();
        $this->assertSame('Operator Satu', $admin->name);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('a-long-secret-1234', $admin->getAuthPassword()));
        $this->assertNotSame('a-long-secret-1234', $admin->getAuthPassword());
        $this->assertFalse($admin->hasTwoFactorEnabled(), 'two-factor is enrolled at the first login');
        $this->assertNull($admin->last_login_at);
        $this->assertSame($users, User::query()->count(), 'no tenant user is created');
        $this->assertDatabaseCount('company_user', 0);
        $this->assertNull($admin->getAttributes()['company_id'] ?? null);
        $this->assertSame(1, Company::query()->whereKey($company->id)->count());
    }

    public function test_the_creation_is_audited_without_the_password(): void
    {
        $this->artisan('inbils:platform:admin-create')
            ->expectsQuestion('Name', 'Operator Satu')
            ->expectsQuestion('Email', 'operator@inbils.test')
            ->expectsQuestion('Password', 'a-long-secret-1234')
            ->expectsQuestion('Confirm password', 'a-long-secret-1234')
            ->assertSuccessful();

        $admin = PlatformAdmin::query()->sole();
        $row = Activity::query()->where('description', 'admin.created')->sole();
        $this->assertSame('platform', $row->log_name);
        $this->assertSame(PlatformAdmin::class, $row->subject_type);
        $this->assertSame($admin->id, (int) $row->subject_id);
        $this->assertNull($row->company_id);
        $this->assertStringNotContainsString('a-long-secret-1234', json_encode($row->properties));
    }

    public function test_more_admins_can_be_added_and_a_duplicate_email_is_refused(): void
    {
        $this->createViaCommand('first@inbils.test');
        $this->createViaCommand('second@inbils.test');

        $this->assertSame(2, PlatformAdmin::query()->count());

        $this->artisan('inbils:platform:admin-create')
            ->expectsQuestion('Name', 'Copycat')
            ->expectsQuestion('Email', 'FIRST@inbils.test')
            ->expectsQuestion('Password', 'a-long-secret-1234')
            ->expectsQuestion('Confirm password', 'a-long-secret-1234')
            ->assertFailed();

        $this->assertSame(2, PlatformAdmin::query()->count());
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_creates_nothing(string $name, string $email, string $password, string $confirmation): void
    {
        $this->artisan('inbils:platform:admin-create')
            ->expectsQuestion('Name', $name)
            ->expectsQuestion('Email', $email)
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $confirmation)
            ->assertFailed();

        $this->assertDatabaseCount('platform_admins', 0);
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function invalidInputs(): array
    {
        return [
            'no name' => ['', 'operator@inbils.test', 'a-long-secret-1234', 'a-long-secret-1234'],
            'not an email' => ['Operator', 'operator', 'a-long-secret-1234', 'a-long-secret-1234'],
            'too short' => ['Operator', 'operator@inbils.test', 'short-1', 'short-1'],
            'letters only' => ['Operator', 'operator@inbils.test', 'only-letters-here', 'only-letters-here'],
            'digits only' => ['Operator', 'operator@inbils.test', '1234567890123', '1234567890123'],
            'not confirmed' => ['Operator', 'operator@inbils.test', 'a-long-secret-1234', 'a-long-secret-9999'],
            'too long a name' => [str_repeat('n', 256), 'operator@inbils.test', 'a-long-secret-1234', 'a-long-secret-1234'],
        ];
    }

    private function createViaCommand(string $email): void
    {
        $this->artisan('inbils:platform:admin-create')
            ->expectsQuestion('Name', 'Operator')
            ->expectsQuestion('Email', $email)
            ->expectsQuestion('Password', 'a-long-secret-1234')
            ->expectsQuestion('Confirm password', 'a-long-secret-1234')
            ->assertSuccessful();
    }
}
