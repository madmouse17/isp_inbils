<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use Illuminate\Support\Facades\Auth;

class CompanyService
{
    private static ?int $cachedCompanyId = null;

    private static ?Company $cachedCompany = null;

    public static function currentId(): ?int
    {
        return Auth::user()?->company_id;
    }

    public static function current(): ?Company
    {
        $companyId = self::currentId();

        if ($companyId === null) {
            self::$cachedCompanyId = null;
            self::$cachedCompany = null;

            return null;
        }

        if (self::$cachedCompanyId === $companyId && self::$cachedCompany !== null) {
            return self::$cachedCompany;
        }

        self::$cachedCompanyId = $companyId;
        self::$cachedCompany = Company::find($companyId);

        return self::$cachedCompany;
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        $settings = self::current()?->settings ?? [];

        if (array_key_exists($key, $settings) && $settings[$key] !== null) {
            return $settings[$key];
        }

        return SettingService::get('default_'.$key, $default);
    }

    public static function updateProfile(array $data): Company
    {
        $company = self::current() ?? Company::query()->findOrFail(self::currentId());
        $company->update($data);
        self::$cachedCompany = $company->refresh();

        return self::$cachedCompany;
    }

    public static function updateSettings(array $settings): Company
    {
        $company = self::current() ?? Company::query()->findOrFail(self::currentId());
        $company->settings = array_merge($company->settings ?? [], $settings);
        $company->save();
        self::$cachedCompany = $company->refresh();

        return self::$cachedCompany;
    }

    public static function resetCache(): void
    {
        self::$cachedCompanyId = null;
        self::$cachedCompany = null;
    }
}
