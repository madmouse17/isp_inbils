<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Core\CompanyService;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_demo_users_with_company_roles_and_required_profiles(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoUserSeeder::class);

        $expected = [
            'admin' => ['admin@demo.inbils.test', 'Admin'],
            'manager' => ['manager@demo.inbils.test', 'Manajer'],
            'staff' => ['staff@demo.inbils.test', 'Staf'],
            'technician' => ['technician@demo.inbils.test', 'Teknisi'],
            'customer' => ['customer@demo.inbils.test', 'Pelanggan'],
        ];

        $company = Company::query()->where('code', 'DEMO')->firstOrFail();
        $roles = CompanyService::runAs((int) $company->id, fn () => Role::query()->get());

        $this->assertCount(8, $roles);
        $this->assertSame(
            ['admin', 'customer', 'finance', 'manager', 'noc', 'staff', 'technician', 'warehouse'],
            $roles->pluck('template_key')->sort()->values()->all(),
        );

        foreach ($expected as $key => [$email, $name]) {
            $user = User::query()->where('email', $email)->firstOrFail();

            $this->assertSame([$key], CompanyService::runAs(
                (int) $company->id,
                fn () => $user->fresh()->roles()->pluck('template_key')->all(),
            ));
            $this->assertSame($key, $roles->firstWhere('name', $name)?->template_key);
            $this->assertSame($company->id, $user->company_id);
            $this->assertTrue(Hash::check(DemoUserSeeder::PASSWORD, $user->getAuthPassword()));
        }

        foreach (['manager', 'staff', 'technician'] as $role) {
            $this->assertDatabaseHas(EmployeeProfile::class, [
                'user_id' => User::query()->where('email', $expected[$role][0])->value('id'),
                'company_id' => $company->id,
                'status' => 'active',
            ]);
        }
    }

    public function test_demo_admin_can_login_and_access_admin_dashboard(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoUserSeeder::class);

        $this->post(route('login'), [
            'email' => 'admin@demo.inbils.test',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard', absolute: false));

        $this->get(route('admin.dashboard'))->assertOk();
    }
}
