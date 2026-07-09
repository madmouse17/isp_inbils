<?php

namespace Database\Seeders;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /** @var array<string, array{name: string, email: string, employee: bool}> */
    private const USERS = [
        'admin' => ['name' => 'Demo Admin', 'email' => 'admin@demo.inbils.test', 'employee' => false],
        'manager' => ['name' => 'Demo Manager', 'email' => 'manager@demo.inbils.test', 'employee' => true],
        'staff' => ['name' => 'Demo Staff', 'email' => 'staff@demo.inbils.test', 'employee' => true],
        'technician' => ['name' => 'Demo Technician', 'email' => 'technician@demo.inbils.test', 'employee' => true],
        'customer' => ['name' => 'Demo Customer', 'email' => 'customer@demo.inbils.test', 'employee' => false],
    ];

    public function run(): void
    {
        $company = Company::query()->firstOrCreate(
            ['code' => 'DEMO'],
            [
                'name' => 'Inbils Demo Company',
                'timezone' => 'Asia/Jakarta',
                'currency' => 'IDR',
                'is_active' => true,
                'settings' => [],
            ],
        );

        (new CompanySeeder())->runFor($company);

        foreach (self::USERS as $role => $demoUser) {
            $user = User::query()->updateOrCreate(
                ['email' => $demoUser['email']],
                [
                    'name' => $demoUser['name'],
                    'company_id' => $company->id,
                    'password' => Hash::make(self::PASSWORD),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ],
            );

            $user->syncRoles([$role]);

            if ($demoUser['employee']) {
                EmployeeProfile::query()->firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'company_id' => $company->id,
                        'employee_number' => 'DEMO-'.strtoupper($role),
                        'status' => 'active',
                    ],
                );
            }
        }
    }
}
