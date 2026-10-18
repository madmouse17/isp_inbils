<?php

namespace Modules\Service\Models;

use App\Models\Core\ServiceSubscription;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The terms a package was sold under from `valid_from` until the next version (spec .scratch/master-lifecycle D3).
 * Rows are only ever appended (RecordsVersions) and never edited. No `public_id`: a version is reached through its
 * package or its subscription, and the payloads carry the version number, never an id.
 *
 * @property int $id
 * @property int $company_id
 * @property int $service_package_id
 * @property int $version
 * @property string $price_mrc
 * @property string $price_otc
 * @property int $bandwidth_profile_id
 * @property int $speed_profile_id
 * @property int $sla_tier_id
 * @property int $service_package_category_id
 * @property Carbon $valid_from
 */
class ServicePackageVersion extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'service_package_id',
        'version',
        'price_mrc',
        'price_otc',
        'bandwidth_profile_id',
        'speed_profile_id',
        'sla_tier_id',
        'service_package_category_id',
        'valid_from',
    ];

    protected $casts = [
        'version' => 'integer',
        'price_mrc' => 'decimal:2',
        'price_otc' => 'decimal:2',
        'valid_from' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Package versions are immutable.');
        });
    }

    /** @return BelongsTo<ServicePackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id')->withTrashed();
    }

    /** @return BelongsTo<BandwidthProfile, $this> */
    public function bandwidthProfile(): BelongsTo
    {
        return $this->belongsTo(BandwidthProfile::class)->withTrashed();
    }

    /** @return BelongsTo<SpeedProfile, $this> */
    public function speedProfile(): BelongsTo
    {
        return $this->belongsTo(SpeedProfile::class)->withTrashed();
    }

    /** @return BelongsTo<SLATier, $this> */
    public function slaTier(): BelongsTo
    {
        return $this->belongsTo(SLATier::class, 'sla_tier_id')->withTrashed();
    }

    /** @return BelongsTo<ServicePackageCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServicePackageCategory::class, 'service_package_category_id');
    }

    /** @return HasMany<ServiceSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(ServiceSubscription::class, 'service_package_version_id');
    }
}
