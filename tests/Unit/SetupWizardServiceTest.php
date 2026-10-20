<?php

namespace Tests\Unit;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\SetupWizardService;
use Database\Seeders\CompanySeeder;
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
        $this->assertSame(8, DB::table('service_packages')->where('company_id', $company->id)->count(), 'the wizard gives the starter catalog');
        $this->assertSame(0, DB::table('stocks')->where('company_id', $company->id)->count(), 'without stock rows');
        $this->assertSame(0, DB::table('network_assets')->where('company_id', $company->id)->count(), 'and without sample assets');
    }

    public function test_create_for_homes_the_given_admin_and_leaves_the_signed_in_user_alone(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);
        $signedIn = User::factory()->create(['company_id' => null]);
        $admin = User::factory()->create(['company_id' => null]);
        $this->actingAs($signedIn);

        $company = app(SetupWizardService::class)->createFor($admin, [
            'name' => 'PT Tenant Baru',
            'code' => 'TNB',
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'date_format' => 'd M Y',
            'datetime_format' => 'd M Y H:i',
            'admin_name' => 'First Admin',
        ]);

        $this->assertSame($company->id, $admin->refresh()->company_id);
        $this->assertSame('First Admin', $admin->name);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertNull($signedIn->refresh()->company_id);
        $this->assertFalse($signedIn->hasRole('admin'));
        $this->assertSame(1, DB::table('company_user')->where(['user_id' => $admin->id, 'company_id' => $company->id, 'is_default' => 1])->count());
        $this->assertDatabaseMissing('users', ['email' => "system-seed-{$company->id}@inbils.internal"]);
    }

    public function test_create_for_gives_the_company_the_catalog_it_is_asked_for(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['company_id' => null]);

        $company = app(SetupWizardService::class)->createFor($admin, [
            'name' => 'PT Tenant Kosong',
            'code' => 'TNK',
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'date_format' => 'd M Y',
            'datetime_format' => 'd M Y H:i',
            'admin_name' => 'Empty Admin',
        ], CompanySeeder::CATALOG_EMPTY);

        $this->assertSame(4, DB::table('units')->where('company_id', $company->id)->count());
        $this->assertSame(5, DB::table('ticket_categories')->where('company_id', $company->id)->count());
        $this->assertSame(3, DB::table('service_package_categories')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('service_packages')->where('company_id', $company->id)->count());
        $this->assertSame(0, DB::table('locations')->where('company_id', $company->id)->count());
    }
}
