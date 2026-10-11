<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * The only authority on which company a request, job or command acts in.
 *
 * The effective company of a user is the one they switched into (session, validated against their
 * memberships on every read), else their home company (users.company_id). Code with no user must
 * name its company with runAs(); with no company at all every BelongsToCompany scope fails closed.
 */
class CompanyService
{
    /** Session key of the company the authenticated user switched into. */
    public const SESSION_KEY = 'active_company_id';

    /** Per-request memo of membership checks, kept on the request so it never outlives it. */
    private const MEMBERSHIP_MEMO = 'company.membership.';

    private static ?int $cachedCompanyId = null;

    private static ?Company $cachedCompany = null;

    private static ?int $testingCompanyId = null;

    /** @var list<int> explicit system scopes of runAs(), innermost last */
    private static array $systemScopes = [];

    public static function currentId(): ?int
    {
        if (self::$systemScopes !== []) {
            return self::$systemScopes[array_key_last(self::$systemScopes)];
        }

        $user = Auth::user();

        return ($user instanceof User ? self::idFor($user) : null)
            ?? (app()->environment('testing') ? self::$testingCompanyId : null);
    }

    /**
     * The company a given user acts in. The session belongs to the authenticated user, so any other
     * user object resolves to its home company.
     */
    public static function idFor(User $user): ?int
    {
        return self::switchedIdFor($user) ?? ($user->company_id !== null ? (int) $user->company_id : null);
    }

    /**
     * May the user act in this company? The home company always, any other one only through a
     * membership row and only while the company is active.
     */
    public static function canActIn(User $user, int $companyId): bool
    {
        if ($user->company_id !== null && (int) $user->company_id === $companyId) {
            return true;
        }

        $bag = request()->attributes;
        $key = self::MEMBERSHIP_MEMO.$user->getKey().'.'.$companyId;

        if (! $bag->has($key)) {
            $bag->set($key, Company::query()
                ->whereKey($companyId)
                ->where('is_active', true)
                ->whereHas('members', fn ($members) => $members->whereKey($user->getKey()))
                ->exists());
        }

        return (bool) $bag->get($key);
    }

    /**
     * Explicit system scope for code that has no user (Artisan, queue jobs, seeders): everything
     * inside the callback acts in this company. Nests; always restores the previous scope.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function runAs(int $companyId, Closure $callback): mixed
    {
        self::$systemScopes[] = $companyId;

        try {
            return $callback();
        } finally {
            array_pop(self::$systemScopes);
        }
    }

    /** Drops the membership memo; call after memberships or companies change within a request. */
    public static function forgetResolution(): void
    {
        $bag = request()->attributes;

        foreach (array_keys($bag->all()) as $key) {
            if (str_starts_with((string) $key, self::MEMBERSHIP_MEMO)) {
                $bag->remove($key);
            }
        }
    }

    public static function useCompanyForTests(?int $companyId): void
    {
        abort_unless(app()->environment('testing'), 403);

        self::$testingCompanyId = $companyId;
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
        self::$testingCompanyId = null;
        self::$systemScopes = [];
        self::forgetResolution();
    }

    /** The company the authenticated user switched into, while they may still act in it. */
    private static function switchedIdFor(User $user): ?int
    {
        if ((int) Auth::id() !== (int) $user->getKey()) {
            return null;
        }

        $switched = session(self::SESSION_KEY);

        return is_numeric($switched) && self::canActIn($user, (int) $switched) ? (int) $switched : null;
    }
}
