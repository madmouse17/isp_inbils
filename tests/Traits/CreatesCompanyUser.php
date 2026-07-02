<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Spatie\Permission\Models\Role;

trait CreatesCompanyUser
{
    protected function createCompanyUser(): User
    {
        if (!Role::where('name', 'admin')->exists()) {
            $this->seed(RolePermissionSeeder::class);
            $this->seed(SystemSettingSeeder::class);
            app()['cache']->forget('spatie.permission.cache');
        }

        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        \App\Services\Core\CompanyService::resetCache();
        return $user;
    }
}
