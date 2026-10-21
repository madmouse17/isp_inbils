<?php

namespace Database\Seeders;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyRoleService;
use App\Services\Core\CompanyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Tops the DEMO company up to five active, employed people per role (admin, manager, staff,
 * technician) so demo lists, pickers and dispatch screens have a team to show. Needs the units of
 * DemoDataSeeder. Re-running changes nothing. Numbers are written directly, as the other demo seeders do.
 */
class DemoTeamSeeder extends Seeder
{
    /** The demo admin predates this seeder and has no profile: [unit, employee number, phone, hire date, skills]. */
    private const DEMO_ADMIN = ['INB-HQ', 'INB-0300', '081322003300', '2022-01-10', ['Administrasi sistem', 'Manajemen pengguna']];

    /** [name, email local part, role, unit, employee number, phone, hire date, skills] */
    private const PEOPLE = [
        ['Hendra Gunawan', 'hendra.gunawan', 'admin', 'INB-HQ', 'INB-0301', '081322003301', '2022-02-14', ['Administrasi sistem', 'Hak akses']],
        ['Ratna Sari', 'ratna.sari', 'admin', 'INB-FIN', 'INB-0302', '081322003302', '2022-09-05', ['Penagihan', 'Rekonsiliasi pembayaran']],
        ['Bayu Nugroho', 'bayu.nugroho', 'admin', 'INB-NOC', 'INB-0303', '081322003303', '2023-04-17', ['Monitoring jaringan', 'Manajemen perangkat']],
        ['Lestari Wulandari', 'lestari.wulandari', 'admin', 'INB-HQ', 'INB-0304', '081322003304', '2024-01-08', ['Administrasi sistem', 'Pelaporan']],
        ['Budi Santoso', 'budi.santoso', 'manager', 'INB-FLD', 'INB-0401', '081322004401', '2021-11-01', ['Penjadwalan teknisi', 'Persetujuan SPK']],
        ['Siti Rahayu', 'siti.rahayu', 'manager', 'INB-SLS', 'INB-0402', '081322004402', '2022-05-23', ['Manajemen penjualan', 'Layanan pelanggan']],
        ['Eko Prasetyo', 'eko.prasetyo', 'manager', 'INB-WH', 'INB-0403', '081322004403', '2022-12-12', ['Manajemen stok', 'Pengadaan']],
        ['Maya Puspita', 'maya.puspita', 'manager', 'INB-OPS', 'INB-0404', '081322004404', '2023-07-31', ['Manajemen operasional', 'Kontrol SLA']],
        ['Nina Marlina', 'nina.marlina', 'staff', 'INB-FIN', 'INB-0203', '081322002203', '2024-03-18', ['Penagihan', 'Penerbitan faktur']],
        ['Fajar Ramadhan', 'fajar.ramadhan', 'staff', 'INB-WH', 'INB-0204', '081322002204', '2024-08-26', ['Administrasi gudang', 'Stok opname']],
        ['Iwan Hermawan', 'iwan.hermawan', 'technician', 'INB-FLD-UTR', 'INB-0104', '081322001104', '2025-03-10', ['Splicing FTTH', 'Instalasi ONT']],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('DemoTeamSeeder refuses to run in production.');
        }

        $company = Company::query()->where('code', 'DEMO')->first();

        if ($company === null) {
            $this->command?->warn('DemoTeamSeeder: the DEMO company is missing, run DemoUserSeeder first.');

            return;
        }

        $roles = app(CompanyRoleService::class)->copyTemplates($company);

        CompanyService::runAs((int) $company->id, function () use ($company, $roles): void {
            $units = OrganizationUnit::query()->pluck('id', 'code');

            if ($units->isEmpty()) {
                $this->command?->warn('DemoTeamSeeder: no organization units, run DemoDataSeeder first.');

                return;
            }

            [$unit, $number, $phone, $hired, $skills] = self::DEMO_ADMIN;
            $admin = User::query()->where('email', 'admin@demo.inbils.test')->first();

            if ($admin !== null) {
                $this->profile($admin, $units->get($unit), $number, $phone, $hired, $skills);
            }

            foreach (self::PEOPLE as [$name, $local, $role, $unit, $number, $phone, $hired, $skills]) {
                $user = User::query()->updateOrCreate(
                    ['email' => "{$local}@demo.inbils.test"],
                    [
                        'name' => $name,
                        'company_id' => $company->id,
                        'password' => Hash::make(DemoUserSeeder::PASSWORD),
                        'email_verified_at' => now(),
                        'is_active' => true,
                    ],
                );

                $user->unsetRelation('roles');
                $user->syncRoles([$roles[$role]]);
                app(CompanyMembershipService::class)->grant($user, $company, true);
                $this->profile($user, $units->get($unit), $number, $phone, $hired, $skills);
            }
        });
    }

    /** @param list<string> $skills */
    private function profile(User $user, ?int $unitId, string $number, string $phone, string $hired, array $skills): void
    {
        if ($unitId === null) {
            $this->command?->warn("DemoTeamSeeder: unit of {$user->email} is missing, profile skipped.");

            return;
        }

        EmployeeProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'organization_id' => $unitId,
                'employee_number' => $number,
                'phone' => $phone,
                'hire_date' => $hired,
                'status' => 'active',
                'skills' => $skills,
            ],
        );
    }
}
