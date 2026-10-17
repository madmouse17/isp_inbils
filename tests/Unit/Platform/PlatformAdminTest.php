<?php

namespace Tests\Unit\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Services\Platform\PlatformAuditService;
use Database\Factories\PlatformAdminFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Tests\Traits\EnrollsTwoFactor;

class PlatformAdminTest extends TestCase
{
    use EnrollsTwoFactor;
    use RefreshDatabase;

    public function test_the_table_holds_the_identity_and_the_two_factor_columns_and_no_tenant_or_remember_column(): void
    {
        $columns = Schema::getColumnListing('platform_admins');

        foreach (['id', 'name', 'email', 'password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_used_step', 'is_active', 'last_login_at', 'created_at', 'updated_at'] as $column) {
            $this->assertContains($column, $columns);
        }
        foreach (['company_id', 'remember_token', 'email_verified_at'] as $column) {
            $this->assertNotContains($column, $columns);
        }
    }

    public function test_only_name_email_and_password_are_mass_assignable_and_the_password_is_hashed(): void
    {
        $admin = PlatformAdmin::query()->create([
            'name' => 'Operator',
            'email' => 'operator@inbils.test',
            'password' => 'a-long-secret-1234',
            'is_active' => false,
            'two_factor_confirmed_at' => now(),
            'last_login_at' => now(),
        ])->refresh();

        $this->assertTrue($admin->is_active, 'is_active is not fillable: the column default applies');
        $this->assertFalse($admin->hasTwoFactorEnabled());
        $this->assertNull($admin->last_login_at);
        $this->assertNotSame('a-long-secret-1234', $admin->getAuthPassword());
        $this->assertTrue(Hash::check('a-long-secret-1234', $admin->getAuthPassword()));
    }

    public function test_the_email_is_unique(): void
    {
        PlatformAdmin::factory()->create(['email' => 'operator@inbils.test']);

        $this->expectException(UniqueConstraintViolationException::class);

        PlatformAdmin::factory()->create(['email' => 'operator@inbils.test']);
    }

    public function test_there_is_no_remember_me(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->assertSame('', $admin->getRememberTokenName());
        $admin->setRememberToken('abc');
        $this->assertNull($admin->getRememberToken());
        $this->assertSame([], array_filter(array_keys($admin->getAttributes()), fn (string $key): bool => str_contains($key, 'remember')));
        $this->assertFalse(method_exists($admin, 'tokens'), 'no Sanctum token');
        $this->assertFalse(method_exists($admin, 'assignRole'), 'no tenant role');
        $this->assertFalse(method_exists($admin, 'companies'), 'no membership');
    }

    public function test_the_provider_and_guard_are_wired_to_this_model_only(): void
    {
        $this->assertSame('platform_admins', config('auth.guards.platform.provider'));
        $this->assertSame('session', config('auth.guards.platform.driver'));
        $this->assertSame(PlatformAdmin::class, config('auth.providers.platform_admins.model'));
        $this->assertSame('web', config('auth.defaults.guard'), 'the default guard stays the tenant one');

        $admin = PlatformAdmin::factory()->create();
        $this->assertTrue(Auth::guard('platform')->validate(['email' => $admin->email, 'password' => PlatformAdminFactory::PASSWORD]));
        $this->assertFalse(Auth::guard('web')->validate(['email' => $admin->email, 'password' => PlatformAdminFactory::PASSWORD]));
    }

    public function test_the_audit_service_writes_platform_and_support_rows_with_the_given_causer_and_no_ambient_company(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $audit = app(PlatformAuditService::class);

        $platform = $audit->platform('demo.event', ['note' => 'x'], $admin, $admin);

        $row = Activity::query()->findOrFail($platform->getKey());
        $this->assertSame('platform', $row->log_name);
        $this->assertSame('demo.event', $row->description);
        $this->assertSame(PlatformAdmin::class, $row->causer_type);
        $this->assertSame($admin->id, (int) $row->causer_id);
        $this->assertNull($row->company_id);
        $this->assertSame(['note' => 'x'], $row->properties->all());
    }

    public function test_a_support_row_is_about_a_tenant_subject_and_carries_its_company(): void
    {
        $admin = PlatformAdmin::factory()->create();
        $company = Company::factory()->create();

        $support = app(PlatformAuditService::class)->support('impersonation.started', ['reason' => 'Tiket #1234'], $company, $admin);
        $platform = app(PlatformAuditService::class)->platform('company.viewed', [], $company, $admin);

        foreach ([[$support, 'support'], [$platform, 'platform']] as [$activity, $log]) {
            $row = Activity::query()->findOrFail($activity->getKey());
            $this->assertSame($log, $row->log_name);
            $this->assertSame($company->id, (int) $row->company_id);
            $this->assertSame($company->id, $row->properties['company_id']);
            $this->assertSame(PlatformAdmin::class, $row->causer_type);
        }
    }
}
