<?php

namespace Tests\Traits;

use App\Models\Core\Company;
use App\Models\Core\Setting;
use App\Models\User;
use App\Services\Core\CompanyService;
use Database\Seeders\SystemSettingSeeder;

trait CreatesCompanyUser
{
    /** A company and its Admin: the permission catalogue and the role templates come with the migrations. */
    protected function createCompanyUser(): User
    {
        if (! Setting::query()->where('key', 'app.name')->exists()) {
            $this->seed(SystemSettingSeeder::class);
        }

        $company = Company::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantRole($user, 'admin', $company);
        CompanyService::resetCache();
        CompanyService::useCompanyForTests((int) $company->id);

        return $user;
    }
}
