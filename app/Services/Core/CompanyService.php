<?php

namespace App\Services\Core;

use App\Models\Core\Company;
use App\Models\Core\Setting;
use App\Models\User;
use App\Support\OptimisticLock;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravolt\Indonesia\Models\City;

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

    /** What a user is told when every company they could act in is suspended. */
    public const SUSPENDED_MESSAGE = 'Akun perusahaan Anda sedang ditangguhkan. Hubungi dukungan.';

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
     * user object resolves to its home company. Null when the home company is suspended and no
     * switched-into company is left.
     */
    public static function idFor(User $user): ?int
    {
        if (($switched = self::switchedIdFor($user)) !== null) {
            return $switched;
        }

        return $user->company_id !== null && self::canActIn($user, (int) $user->company_id) ? (int) $user->company_id : null;
    }

    /**
     * May the user act in this company? Only while it is active, and then the home company always,
     * any other one only through a membership row.
     */
    public static function canActIn(User $user, int $companyId): bool
    {
        $bag = request()->attributes;
        $key = self::MEMBERSHIP_MEMO.$user->getKey().'.'.$companyId;

        if (! $bag->has($key)) {
            $isHome = $user->company_id !== null && (int) $user->company_id === $companyId;

            $bag->set($key, Company::query()
                ->whereKey($companyId)
                ->where('is_active', true)
                ->when(! $isHome, fn ($companies) => $companies->whereHas('members', fn ($members) => $members->whereKey($user->getKey())))
                ->exists());
        }

        return (bool) $bag->get($key);
    }

    /** The first active company the user is a member of, for when their effective company is suspended. */
    public static function fallbackIdFor(User $user): ?int
    {
        $id = Company::query()
            ->where('is_active', true)
            ->whereHas('members', fn ($members) => $members->whereKey($user->getKey()))
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** A user with a home company who has no company to act in: it is suspended and nothing was switched into. */
    public static function isSuspendedFor(User $user): bool
    {
        return $user->company_id !== null && self::idFor($user) === null;
    }

    /** May the user be let in at all: their home is active, or some other active membership remains. */
    public static function canEnter(User $user): bool
    {
        return ! self::isSuspendedFor($user) || self::fallbackIdFor($user) !== null;
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

    /**
     * Where maps open for a company: its own point (company settings), else the system default, else all of Indonesia.
     * lat and lng only count as a pair; the city code is the label of that pair.
     *
     * @return array{lat: float, lng: float, zoom: int, city_code: string|null, city_name: string|null}
     */
    public static function mapDefault(?Company $company): array
    {
        $settings = $company->settings ?? [];
        $ownPoint = isset($settings['map_lat'], $settings['map_lng']);
        $ownZoom = isset($settings['map_zoom']);
        $cityCode = $ownPoint ? ($settings['map_city_code'] ?? null) : null;

        // One query for all three system defaults, and none when the company set its own point and zoom.
        $defaults = $ownPoint && $ownZoom
            ? []
            : Setting::query()->whereIn('key', ['default_map_lat', 'default_map_lng', 'default_map_zoom'])->pluck('value', 'key')->all();

        return [
            'lat' => (float) ($ownPoint ? $settings['map_lat'] : ($defaults['default_map_lat'] ?? -2.5489)),
            'lng' => (float) ($ownPoint ? $settings['map_lng'] : ($defaults['default_map_lng'] ?? 118.0149)),
            'zoom' => (int) ($ownZoom ? $settings['map_zoom'] : ($defaults['default_map_zoom'] ?? 5)),
            'city_code' => $cityCode ?: null,
            'city_name' => $cityCode ? IndonesiaRegionService::displayName(City::query()->where('code', $cityCode)->value('name')) : null,
        ];
    }

    public static function updateProfile(array $data): Company
    {
        $company = self::current() ?? Company::query()->findOrFail(self::currentId());
        $company->update($data);
        self::$cachedCompany = $company->refresh();

        return self::$cachedCompany;
    }

    /**
     * Merges into the settings of the row read under lock, so a concurrent writer's keys survive. With a version, a row
     * changed since the form was rendered is refused (OptimisticLock); callers without a form (seeders, tests) skip it.
     */
    public static function updateSettings(array $settings, ?string $expectedUpdatedAt = null): Company
    {
        $company = DB::transaction(function () use ($settings, $expectedUpdatedAt): Company {
            $locked = Company::query()->lockForUpdate()->findOrFail(self::currentId());

            if ($expectedUpdatedAt !== null) {
                OptimisticLock::assert($locked, $expectedUpdatedAt);
            }

            $locked->settings = array_merge($locked->settings ?? [], $settings);
            $locked->save();

            return $locked;
        });
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
