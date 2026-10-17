<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use Database\Seeders\DemoTeamSeeder;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoTeamSeederTest extends TestCase
{
    use RefreshDatabase;

    private const UNIT_CODES = ['INB-HQ', 'INB-OPS', 'INB-NOC', 'INB-FLD', 'INB-FLD-UTR', 'INB-SLS', 'INB-WH', 'INB-FIN'];

    private function seedDemoBase(): Company
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoUserSeeder::class);
        $company = Company::query()->where('code', 'DEMO')->firstOrFail();

        CompanyService::runAs((int) $company->id, function (): void {
            foreach (self::UNIT_CODES as $code) {
                OrganizationUnit::query()->create(['code' => $code, 'name' => $code, 'type' => 'unit', 'is_active' => true]);
            }
        });

        return $company;
    }

    /** @return array<string, int> role => active users with an active profile */
    private function activeEmployeesPerRole(Company $company): array
    {
        return CompanyService::runAs((int) $company->id, fn (): array => collect(['admin', 'manager', 'staff', 'technician'])
            ->mapWithKeys(fn (string $role): array => [$role => User::query()
                ->role($role)
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->whereHas('employeeProfile', fn ($q) => $q->where('status', 'active'))
                ->count()])
            ->all());
    }

    public function test_admins_and_managers_reach_five_and_a_second_run_changes_nothing(): void
    {
        $company = $this->seedDemoBase();

        $this->seed(DemoTeamSeeder::class);
        $afterFirst = [User::query()->count(), EmployeeProfile::withoutGlobalScopes()->count()];
        $perRole = $this->activeEmployeesPerRole($company);

        $this->assertSame(5, $perRole['admin']);
        $this->assertSame(5, $perRole['manager']);
        // This base has no DemoDataSeeder people: demo staff/technician + the new 2 staff, 1 technician.
        $this->assertSame(3, $perRole['staff']);
        $this->assertSame(2, $perRole['technician']);

        $this->seed(DemoTeamSeeder::class);

        $this->assertSame($afterFirst, [User::query()->count(), EmployeeProfile::withoutGlobalScopes()->count()]);
        $this->assertSame($perRole, $this->activeEmployeesPerRole($company));
    }

    public function test_people_get_unique_numbers_units_the_demo_password_and_a_membership(): void
    {
        $company = $this->seedDemoBase();
        $this->seed(DemoTeamSeeder::class);

        $user = User::query()->where('email', 'hendra.gunawan@demo.inbils.test')->firstOrFail();
        $profile = EmployeeProfile::withoutGlobalScopes()->where('user_id', $user->id)->firstOrFail();

        $this->assertTrue(Hash::check(DemoUserSeeder::PASSWORD, $user->getAuthPassword()));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('INB-0301', $profile->employee_number);
        $this->assertSame('INB-HQ', OrganizationUnit::withoutGlobalScopes()->findOrFail($profile->organization_id)->code);
        $this->assertTrue(app(CompanyMembershipService::class)->companiesOf($user)->contains(fn ($c) => $c['id'] === $company->public_id));

        $numbers = EmployeeProfile::withoutGlobalScopes()->pluck('employee_number');
        $this->assertSame($numbers->count(), $numbers->unique()->count());
    }

    public function test_the_demo_admin_becomes_an_employee_in_headquarters(): void
    {
        $this->seedDemoBase();
        $admin = User::query()->where('email', 'admin@demo.inbils.test')->firstOrFail();
        $this->assertNull(EmployeeProfile::withoutGlobalScopes()->where('user_id', $admin->id)->first());

        $this->seed(DemoTeamSeeder::class);

        $profile = EmployeeProfile::withoutGlobalScopes()->where('user_id', $admin->id)->firstOrFail();
        $this->assertSame('INB-HQ', OrganizationUnit::withoutGlobalScopes()->findOrFail($profile->organization_id)->code);
        $this->assertSame('active', $profile->status);
    }

    public function test_it_skips_quietly_without_the_demo_company_or_units(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoTeamSeeder::class);
        $this->assertSame(0, User::query()->count());

        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoTeamSeeder::class);
        $this->assertSame(5, User::query()->count());
    }
}
