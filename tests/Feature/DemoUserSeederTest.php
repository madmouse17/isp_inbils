<?php

namespace Tests\Feature;

use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_demo_users_with_roles_company_and_required_profiles(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoUserSeeder::class);

        $expected = [
            'admin' => 'admin@demo.inbils.test',
            'manager' => 'manager@demo.inbils.test',
            'staff' => 'staff@demo.inbils.test',
            'technician' => 'technician@demo.inbils.test',
            'customer' => 'customer@demo.inbils.test',
        ];

        foreach ($expected as $role => $email) {
            $user = User::query()->where('email', $email)->firstOrFail();

            $this->assertTrue($user->hasRole($role));
            $this->assertNotNull($user->company_id);
            $this->assertTrue(Hash::check('password', $user->password));
        }

        foreach (['manager', 'staff', 'technician'] as $role) {
            $this->assertDatabaseHas(EmployeeProfile::class, [
                'user_id' => User::query()->where('email', $expected[$role])->value('id'),
                'company_id' => User::query()->where('email', $expected[$role])->value('company_id'),
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
