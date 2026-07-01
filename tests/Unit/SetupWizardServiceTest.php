<?php

namespace Tests\Unit;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\SetupWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SetupWizardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_sets_company_admin_user_and_audit_logs(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['company_id' => null]);

        $this->actingAs($user);

        $company = app(SetupWizardService::class)->create([
            'name' => 'PT Inbils Jaya',
            'code' => 'INBILS',
            'address' => null,
            'phone' => null,
            'email' => 'company@example.test',
            'website' => 'https://example.test',
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'date_format' => 'd M Y',
            'datetime_format' => 'd M Y H:i',
            'admin_name' => 'Owner Admin',
        ]);

        $this->assertSame(1, Company::query()->count());
        $this->assertSame('PT Inbils Jaya', $company->name);
        $this->assertSame('Rp', $company->settings['currency_symbol']);
        $this->assertSame($company->id, $user->refresh()->company_id);
        $this->assertSame('Owner Admin', $user->name);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertGreaterThanOrEqual(2, DB::table('activity_log')->count());
    }
}
