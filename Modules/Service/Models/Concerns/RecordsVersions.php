<?php

namespace Modules\Service\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Service\Models\ServicePackageVersion;

/**
 * A package keeps its current terms in place, so every catalog reader is unchanged, and appends an immutable version
 * whenever one of the terms a subscription is sold under changes (spec .scratch/master-lifecycle D3). Renaming or
 * re-describing a package is no new version. Only model saves record versions: a query builder update or a raw insert
 * skips the hook, and ServicePackageVersionBackfill repairs the packages that miss one.
 *
 * @property int $id
 * @property int $company_id
 */
trait RecordsVersions
{
    /** The columns of a version: the terms a subscription keeps when the package moves on. */
    public const VERSIONED_TERMS = [
        'price_mrc',
        'price_otc',
        'bandwidth_profile_id',
        'speed_profile_id',
        'sla_tier_id',
        'service_package_category_id',
    ];

    public static function bootRecordsVersions(): void
    {
        static::created(function (self $package): void {
            $package->recordVersion();
        });

        static::updated(function (self $package): void {
            if ($package->wasChanged(self::VERSIONED_TERMS)) {
                $package->recordVersion();
            }
        });
    }

    /** @return HasMany<ServicePackageVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ServicePackageVersion::class, 'service_package_id')->orderBy('version');
    }

    /** @return HasOne<ServicePackageVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(ServicePackageVersion::class, 'service_package_id')->latestOfMany('version');
    }

    /**
     * Appends the next version from the current terms. The company is written explicitly, so seeders and jobs without
     * a tenant context record versions too. Two concurrent edits are serialised by the controller's row lock, with
     * unique(service_package_id, version) as the backstop.
     */
    public function recordVersion(): ServicePackageVersion
    {
        $latest = ServicePackageVersion::withoutCompany()->where('service_package_id', $this->getKey())->max('version');

        return $this->versions()->create([
            'company_id' => $this->company_id,
            'version' => (int) $latest + 1,
            'valid_from' => now(),
            ...$this->only(self::VERSIONED_TERMS),
        ]);
    }
}
