<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerAccountCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_create_also_creates_customer_user(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $admin->assignRole(Role::where('name', 'admin')->firstOrFail());

        $this->actingAs($admin)->post(route('admin.customers.store'), [
            'code' => 'CUS-ACCOUNT',
            'name' => 'Customer Account',
            'type' => 'Individual',
            'email' => 'customer.account@example.test',
            'phone' => '0800000001',
            'is_active' => true,
        ])->assertRedirect();

        $customer = Customer::query()->where('code', 'CUS-ACCOUNT')->firstOrFail();
        $user = User::query()->where('email', 'customer.account@example.test')->firstOrFail();

        $this->assertSame($company->id, $customer->company_id);
        $this->assertSame($company->id, $user->company_id);
        $this->assertTrue(Hash::check('0800000001', $user->password));
        $this->assertTrue($user->hasRole('customer'));
    }

    public function test_phone_is_required_when_creating_customer_user(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $admin->assignRole(Role::where('name', 'admin')->firstOrFail());

        $this->actingAs($admin)->post(route('admin.customers.store'), [
            'code' => 'CUS-NO-PHONE',
            'name' => 'Customer No Phone',
            'type' => 'Individual',
            'email' => 'customer.no-phone@example.test',
            'is_active' => true,
        ])->assertInvalid(['phone']);
    }

    public function test_individual_customer_does_not_require_tax_id(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $admin->assignRole(Role::where('name', 'admin')->firstOrFail());

        $this->actingAs($admin)->post(route('admin.customers.store'), [
            'code' => 'CUS-NO-TAX',
            'name' => 'Customer No Tax',
            'type' => 'Individual',
            'email' => 'customer.no-tax@example.test',
            'phone' => '0800000002',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertModelExists(Customer::query()->where('code', 'CUS-NO-TAX')->firstOrFail());
    }
}
