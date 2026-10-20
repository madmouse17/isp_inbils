<?php

namespace Database\Seeders;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    public const PASSWORD = 'password';

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
        // Demo logins use a known password and add a second root company: never on production.
        if (app()->environment('production')) {
            throw new \RuntimeException('DemoUserSeeder refuses to run in production.');
        }

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

        // A seeder has no user: it names the company it seeds, so scoped writes act in it.
        CompanyService::runAs((int) $company->id, function () use ($company): void {
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
                app(CompanyMembershipService::class)->grant($user, $company, true);

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
        });

        // The demo data sits in Kota Bandung, so demo maps open there; an owner's own choice is never overwritten.
        $company->settings = ($company->settings ?? []) + [
            'map_city_code' => '3273',
            'map_lat' => -6.919241381150077,
            'map_lng' => 107.6366006270422,
            'map_zoom' => 12,
        ];
        $company->save();

        // The demo company keeps the stock rows and sample assets that the sample data and the E2E fixtures stand on.
        (new CompanySeeder())->runFor($company, CompanySeeder::CATALOG_DEMO);
    }
}
